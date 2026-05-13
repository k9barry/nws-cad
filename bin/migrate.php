#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Schema migration runner.
 *
 * Applies all pending schema changes to the active database in an idempotent
 * way. Safe to run on every startup — already-applied migrations are silently
 * skipped. Supports both MySQL and PostgreSQL (DB_TYPE env var).
 *
 * Usage:
 *   php bin/migrate.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use NwsCad\Config;
use NwsCad\Database;

try {
    $db     = Database::getConnection();
    $config = Config::getInstance();
    $dbType = $config->getDbConfig()['type']; // 'mysql' or 'pgsql'
} catch (Throwable $e) {
    fwrite(STDERR, "[migrate] Cannot connect to database: " . $e->getMessage() . "\n");
    exit(1);
}

$applied = 0;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Returns true when the given column exists in the given table.
 */
function columnExists(PDO $db, string $dbType, string $table, string $column): bool
{
    if ($dbType === 'pgsql') {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name   = ?
               AND column_name  = ?"
        );
    } else {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name   = ?
               AND column_name  = ?"
        );
    }
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Returns true when the given table exists.
 */
function tableExists(PDO $db, string $dbType, string $table): bool
{
    if ($dbType === 'pgsql') {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = 'public'
               AND table_name   = ?"
        );
    } else {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name   = ?"
        );
    }
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Returns true when the given index exists (MySQL only; PostgreSQL always
 * returns true to skip the guard — CREATE INDEX IF NOT EXISTS handles it).
 */
function indexExists(PDO $db, string $dbType, string $table, string $index): bool
{
    if ($dbType === 'pgsql') {
        return false; // let PostgreSQL handle IF NOT EXISTS
    }
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name   = ?
           AND index_name   = ?"
    );
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

// ---------------------------------------------------------------------------
// Migration 1 — identity-audit columns (security hardening, v1.x)
//   notification_channels.last_updated_actor
//   notification_send_log.actor
// ---------------------------------------------------------------------------

if (tableExists($db, $dbType, 'notification_channels')
    && !columnExists($db, $dbType, 'notification_channels', 'last_updated_actor')
) {
    if ($dbType === 'pgsql') {
        $db->exec("ALTER TABLE notification_channels ADD COLUMN last_updated_actor VARCHAR(64) NULL");
    } else {
        $db->exec("ALTER TABLE notification_channels ADD COLUMN last_updated_actor VARCHAR(64) NULL AFTER last_error_message");
    }
    echo "[migrate] Applied: notification_channels.last_updated_actor column\n";
    $applied++;
}

if (tableExists($db, $dbType, 'notification_send_log')
    && !columnExists($db, $dbType, 'notification_send_log', 'actor')
) {
    if ($dbType === 'pgsql') {
        $db->exec("ALTER TABLE notification_send_log ADD COLUMN actor VARCHAR(64) NULL");
    } else {
        $db->exec("ALTER TABLE notification_send_log ADD COLUMN actor VARCHAR(64) NULL AFTER error");
    }
    echo "[migrate] Applied: notification_send_log.actor column\n";
    $applied++;
}

// ---------------------------------------------------------------------------
// Migration 2 — notification_outbox table (async outbox worker, v1.4.0)
// ---------------------------------------------------------------------------

if (!tableExists($db, $dbType, 'notification_outbox')) {
    if ($dbType === 'pgsql') {
        $db->exec("
            CREATE TABLE notification_outbox (
                id                  BIGSERIAL PRIMARY KEY,
                db_call_id          BIGINT NOT NULL,
                channel_id          BIGINT NOT NULL,
                intent              VARCHAR(16) NOT NULL,
                resend_all          SMALLINT NOT NULL DEFAULT 0,
                added_topics_json   TEXT NOT NULL,
                create_datetime     TIMESTAMP NOT NULL,
                status              VARCHAR(16) NOT NULL DEFAULT 'pending',
                attempts            INT NOT NULL DEFAULT 0,
                next_attempt_at     TIMESTAMP NULL,
                claimed_at          TIMESTAMP NULL,
                claimed_by          VARCHAR(64) NULL,
                last_error          TEXT NULL,
                created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (db_call_id) REFERENCES calls(id) ON DELETE CASCADE,
                FOREIGN KEY (channel_id) REFERENCES notification_channels(id) ON DELETE CASCADE
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_notification_outbox_status_next ON notification_outbox (status, next_attempt_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_notification_outbox_call ON notification_outbox (db_call_id)");
    } else {
        $db->exec("
            CREATE TABLE notification_outbox (
                id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                db_call_id          BIGINT UNSIGNED NOT NULL,
                channel_id          BIGINT UNSIGNED NOT NULL,
                intent              VARCHAR(16) NOT NULL,
                resend_all          TINYINT NOT NULL DEFAULT 0,
                added_topics_json   TEXT NOT NULL,
                create_datetime     DATETIME NOT NULL,
                status              VARCHAR(16) NOT NULL DEFAULT 'pending',
                attempts            INT NOT NULL DEFAULT 0,
                next_attempt_at     DATETIME NULL,
                claimed_at          DATETIME NULL,
                claimed_by          VARCHAR(64) NULL,
                last_error          TEXT NULL,
                created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_notification_outbox_status_next (status, next_attempt_at),
                INDEX idx_notification_outbox_call (db_call_id),
                FOREIGN KEY (db_call_id) REFERENCES calls(id) ON DELETE CASCADE,
                FOREIGN KEY (channel_id) REFERENCES notification_channels(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    echo "[migrate] Applied: notification_outbox table\n";
    $applied++;
}

// ---------------------------------------------------------------------------
// Migration 3 — filter-refactor: reference tables + fdid column + indexes
//               (v1.3.0)
// ---------------------------------------------------------------------------

if ($dbType === 'pgsql') {
    // PostgreSQL supports IF NOT EXISTS natively for ADD COLUMN (v9.6+).
    $db->exec("ALTER TABLE agency_contexts ADD COLUMN IF NOT EXISTS fdid VARCHAR(10) NULL");
} elseif (tableExists($db, $dbType, 'agency_contexts')
    && !columnExists($db, $dbType, 'agency_contexts', 'fdid')
) {
    $db->exec("ALTER TABLE agency_contexts ADD COLUMN fdid VARCHAR(10) NULL");
    echo "[migrate] Applied: agency_contexts.fdid column\n";
    $applied++;
}

foreach (['ref_agencies', 'ref_oris', 'ref_fdids', 'ref_beats', 'ref_areas'] as $refTable) {
    if (!tableExists($db, $dbType, $refTable)) {
        if ($dbType === 'pgsql') {
            switch ($refTable) {
                case 'ref_agencies':
                    $db->exec("
                        CREATE TABLE ref_agencies (
                            id         SERIAL PRIMARY KEY,
                            code       VARCHAR(32) NOT NULL,
                            label      VARCHAR(128) NOT NULL,
                            kind       VARCHAR(16) NOT NULL,
                            ori        VARCHAR(16) DEFAULT NULL,
                            fdid       VARCHAR(10) DEFAULT NULL,
                            active     BOOLEAN NOT NULL DEFAULT TRUE,
                            sort_order SMALLINT NOT NULL DEFAULT 100,
                            CONSTRAINT uk_ref_agencies_code UNIQUE (code)
                        )
                    ");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_ref_agencies_kind ON ref_agencies (kind, active)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_ref_agencies_ori ON ref_agencies (ori)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_ref_agencies_fdid ON ref_agencies (fdid)");
                    break;
                case 'ref_oris':
                    $db->exec("
                        CREATE TABLE ref_oris (
                            ori       VARCHAR(16) NOT NULL PRIMARY KEY,
                            label     VARCHAR(128) NOT NULL,
                            kind      VARCHAR(16) NOT NULL,
                            agency_id INT DEFAULT NULL,
                            CONSTRAINT fk_ref_oris_agency FOREIGN KEY (agency_id) REFERENCES ref_agencies(id) ON DELETE SET NULL
                        )
                    ");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_ref_oris_agency ON ref_oris (agency_id)");
                    break;
                case 'ref_fdids':
                    $db->exec("
                        CREATE TABLE ref_fdids (
                            fdid      VARCHAR(10) NOT NULL PRIMARY KEY,
                            label     VARCHAR(128) NOT NULL,
                            agency_id INT DEFAULT NULL,
                            CONSTRAINT fk_ref_fdids_agency FOREIGN KEY (agency_id) REFERENCES ref_agencies(id) ON DELETE SET NULL
                        )
                    ");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_ref_fdids_agency ON ref_fdids (agency_id)");
                    break;
                case 'ref_beats':
                    $db->exec("
                        CREATE TABLE ref_beats (
                            id           SERIAL PRIMARY KEY,
                            code         VARCHAR(32) NOT NULL,
                            label        VARCHAR(128) NOT NULL,
                            kind         VARCHAR(32) NOT NULL,
                            jurisdiction VARCHAR(64) DEFAULT NULL,
                            active       BOOLEAN NOT NULL DEFAULT TRUE,
                            CONSTRAINT uk_ref_beats_code UNIQUE (code)
                        )
                    ");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_ref_beats_active ON ref_beats (active)");
                    break;
                case 'ref_areas':
                    $db->exec("
                        CREATE TABLE ref_areas (
                            id     SERIAL PRIMARY KEY,
                            code   VARCHAR(32) NOT NULL,
                            label  VARCHAR(128) NOT NULL,
                            kind   VARCHAR(32) NOT NULL,
                            active BOOLEAN NOT NULL DEFAULT TRUE,
                            CONSTRAINT uk_ref_areas_code UNIQUE (code)
                        )
                    ");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_ref_areas_kind ON ref_areas (kind, active)");
                    break;
            }
        } else {
            // MySQL
            switch ($refTable) {
                case 'ref_agencies':
                    $db->exec("
                        CREATE TABLE ref_agencies (
                            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            code       VARCHAR(32) NOT NULL,
                            label      VARCHAR(128) NOT NULL,
                            kind       ENUM('police','fire','ems') NOT NULL,
                            ori        VARCHAR(16) DEFAULT NULL,
                            fdid       VARCHAR(10) DEFAULT NULL,
                            active     BOOLEAN NOT NULL DEFAULT TRUE,
                            sort_order SMALLINT NOT NULL DEFAULT 100,
                            PRIMARY KEY (id),
                            UNIQUE KEY uk_ref_agencies_code (code),
                            KEY idx_ref_agencies_kind (kind, active),
                            KEY idx_ref_agencies_ori (ori),
                            KEY idx_ref_agencies_fdid (fdid)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    break;
                case 'ref_oris':
                    $db->exec("
                        CREATE TABLE ref_oris (
                            ori       VARCHAR(16) NOT NULL,
                            label     VARCHAR(128) NOT NULL,
                            kind      ENUM('police','fire','ems') NOT NULL,
                            agency_id INT UNSIGNED DEFAULT NULL,
                            PRIMARY KEY (ori),
                            KEY idx_ref_oris_agency (agency_id),
                            CONSTRAINT fk_ref_oris_agency FOREIGN KEY (agency_id) REFERENCES ref_agencies(id) ON DELETE SET NULL
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    break;
                case 'ref_fdids':
                    $db->exec("
                        CREATE TABLE ref_fdids (
                            fdid      VARCHAR(10) NOT NULL,
                            label     VARCHAR(128) NOT NULL,
                            agency_id INT UNSIGNED DEFAULT NULL,
                            PRIMARY KEY (fdid),
                            KEY idx_ref_fdids_agency (agency_id),
                            CONSTRAINT fk_ref_fdids_agency FOREIGN KEY (agency_id) REFERENCES ref_agencies(id) ON DELETE SET NULL
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    break;
                case 'ref_beats':
                    $db->exec("
                        CREATE TABLE ref_beats (
                            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            code         VARCHAR(32) NOT NULL,
                            label        VARCHAR(128) NOT NULL,
                            kind         VARCHAR(32) NOT NULL,
                            jurisdiction VARCHAR(64) DEFAULT NULL,
                            active       BOOLEAN NOT NULL DEFAULT TRUE,
                            PRIMARY KEY (id),
                            UNIQUE KEY uk_ref_beats_code (code),
                            KEY idx_ref_beats_active (active)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    break;
                case 'ref_areas':
                    $db->exec("
                        CREATE TABLE ref_areas (
                            id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            code   VARCHAR(32) NOT NULL,
                            label  VARCHAR(128) NOT NULL,
                            kind   ENUM('fire_quad','ems_district') NOT NULL,
                            active BOOLEAN NOT NULL DEFAULT TRUE,
                            PRIMARY KEY (id),
                            UNIQUE KEY uk_ref_areas_code (code),
                            KEY idx_ref_areas_kind (kind, active)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    break;
            }
        }
        echo "[migrate] Applied: {$refTable} table\n";
        $applied++;
    }
}

// Filter-refactor indexes
if ($dbType === 'pgsql') {
    // PostgreSQL supports CREATE INDEX IF NOT EXISTS natively.
    $pgIndexes = [
        ['calls',           'idx_calls_create_closed',  'create_datetime, closed_flag, canceled_flag'],
        ['agency_contexts', 'idx_ac_call_type',         'call_type'],
        ['agency_contexts', 'idx_ac_fdid',              'fdid'],
        ['locations',       'idx_loc_police_ori',       'police_ori'],
        ['locations',       'idx_loc_ems_ori',          'ems_ori'],
        ['locations',       'idx_loc_fire_ori',         'fire_ori'],
        ['locations',       'idx_loc_police_beat',      'police_beat'],
        ['locations',       'idx_loc_fire_quad',        'fire_quadrant'],
        ['locations',       'idx_loc_ems_district',     'ems_district'],
        ['locations',       'idx_loc_city',             'city'],
        ['units',           'idx_units_unit_number',    'unit_number'],
        ['incidents',       'idx_inc_incident_type',    'incident_type'],
    ];
    foreach ($pgIndexes as [$table, $index, $cols]) {
        $db->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table} ({$cols})");
    }
} else {
    // MySQL: guard with INFORMATION_SCHEMA to avoid duplicate-key errors.
    $indexes = [
        ['calls',           'idx_calls_create_closed', 'create_datetime, closed_flag, canceled_flag'],
        ['agency_contexts', 'idx_ac_call_type',        'call_type'],
        ['agency_contexts', 'idx_ac_fdid',             'fdid'],
        ['locations',       'idx_loc_police_ori',      'police_ori'],
        ['locations',       'idx_loc_ems_ori',         'ems_ori'],
        ['locations',       'idx_loc_fire_ori',        'fire_ori'],
        ['locations',       'idx_loc_police_beat',     'police_beat'],
        ['locations',       'idx_loc_fire_quad',       'fire_quadrant'],
        ['locations',       'idx_loc_ems_district',    'ems_district'],
        ['locations',       'idx_loc_city',            'city'],
        ['units',           'idx_units_unit_number',   'unit_number'],
        ['incidents',       'idx_inc_incident_type',   'incident_type'],
    ];
    foreach ($indexes as [$table, $index, $cols]) {
        if (!indexExists($db, $dbType, $table, $index)) {
            $db->exec("CREATE INDEX {$index} ON {$table} ({$cols})");
            echo "[migrate] Applied: index {$index} on {$table}\n";
            $applied++;
        }
    }
}

// ---------------------------------------------------------------------------
// Done
// ---------------------------------------------------------------------------

if ($applied === 0) {
    echo "[migrate] Schema is up to date — no migrations needed.\n";
} else {
    echo "[migrate] Done. Applied {$applied} migration(s).\n";
}

