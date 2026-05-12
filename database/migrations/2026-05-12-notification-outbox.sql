-- Migration: notification_outbox table (MySQL)
-- Added v1.4.0 — async outbox worker
-- Apply to: nws_cad (and nws_cad_test if applicable)

-- Notification outbox: per-channel delivery intents queued by the parser,
-- consumed by FileWatcher's outbox tick. One row per (CallProcessedEvent,
-- enabled_channel). See docs/superpowers/specs/2026-05-12-outbox-async-worker-design.md.
CREATE TABLE IF NOT EXISTS notification_outbox (
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
);
