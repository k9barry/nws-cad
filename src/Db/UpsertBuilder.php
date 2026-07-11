<?php

declare(strict_types=1);

namespace NwsCad\Db;

use NwsCad\Api\DbHelper;

/**
 * Builds the dialect-specific UPSERT / INSERT-IGNORE SQL that the importer
 * needs, so the MySQL-vs-PostgreSQL branching lives in one place instead of
 * being duplicated across six AegisXmlParser insert methods (#49).
 *
 * All identifiers are validated against DbHelper's allowlist before
 * interpolation. Value placeholders are the named form `:column`. The MySQL
 * row alias is standardized to `new_row` (the alias name has no effect on the
 * resulting rows — behavior is preserved, verified by the importer
 * characterization + reprocess suites).
 */
final class UpsertBuilder
{
    /**
     * INSERT ... ON DUPLICATE KEY UPDATE (MySQL) / ON CONFLICT (...) DO UPDATE
     * SET (PostgreSQL). `updated_at = CURRENT_TIMESTAMP` is always appended to
     * the update set, matching the extracted call sites.
     *
     * @param 'mysql'|'pgsql'|string $dbType
     * @param list<string> $columns       Insert columns (also the :named placeholders).
     * @param list<string> $conflictKeys  Unique-key columns (PostgreSQL ON CONFLICT target).
     * @param list<string> $updateColumns Columns overwritten on conflict.
     * @param bool $returningId            Append `RETURNING id` (PostgreSQL only).
     */
    public static function upsert(
        string $dbType,
        string $table,
        array $columns,
        array $conflictKeys,
        array $updateColumns,
        bool $returningId = false
    ): string {
        self::validate($table, $columns, $conflictKeys, $updateColumns);

        $colList = implode(', ', $columns);
        $placeholders = implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns));

        if ($dbType === 'pgsql') {
            $set = implode(', ', array_map(static fn(string $c): string => "{$c} = EXCLUDED.{$c}", $updateColumns));
            $sql = "INSERT INTO {$table} ({$colList}) VALUES ({$placeholders}) "
                . "ON CONFLICT (" . implode(', ', $conflictKeys) . ") DO UPDATE SET "
                . $set . ", updated_at = CURRENT_TIMESTAMP";
            return $returningId ? $sql . " RETURNING id" : $sql;
        }

        $set = implode(', ', array_map(static fn(string $c): string => "{$c} = new_row.{$c}", $updateColumns));
        return "INSERT INTO {$table} ({$colList}) VALUES ({$placeholders}) AS new_row "
            . "ON DUPLICATE KEY UPDATE " . $set . ", updated_at = CURRENT_TIMESTAMP";
    }

    /**
     * INSERT IGNORE (MySQL) / INSERT ... ON CONFLICT (...) DO NOTHING (PostgreSQL).
     *
     * @param 'mysql'|'pgsql'|string $dbType
     * @param list<string> $columns
     * @param list<string> $conflictKeys PostgreSQL ON CONFLICT target (ignored by MySQL).
     */
    public static function insertIgnore(
        string $dbType,
        string $table,
        array $columns,
        array $conflictKeys
    ): string {
        self::validate($table, $columns, $conflictKeys, []);

        $colList = implode(', ', $columns);
        $placeholders = implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns));

        if ($dbType === 'pgsql') {
            return "INSERT INTO {$table} ({$colList}) VALUES ({$placeholders}) "
                . "ON CONFLICT (" . implode(', ', $conflictKeys) . ") DO NOTHING";
        }

        return "INSERT IGNORE INTO {$table} ({$colList}) VALUES ({$placeholders})";
    }

    /**
     * @param list<string> $columns
     * @param list<string> $conflictKeys
     * @param list<string> $updateColumns
     */
    private static function validate(string $table, array $columns, array $conflictKeys, array $updateColumns): void
    {
        DbHelper::validateIdentifier($table, 'table');
        foreach ([...$columns, ...$conflictKeys, ...$updateColumns] as $identifier) {
            DbHelper::validateIdentifier($identifier, 'column');
        }
    }
}
