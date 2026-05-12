-- Migration: notification_outbox table (PostgreSQL)
-- Added v1.4.0 — async outbox worker
-- Apply to: nws_cad (and nws_cad_test if applicable)

-- Notification outbox: per-channel delivery intents queued by the parser,
-- consumed by FileWatcher's outbox tick.
CREATE TABLE IF NOT EXISTS notification_outbox (
    id                  BIGSERIAL PRIMARY KEY,
    db_call_id          INTEGER NOT NULL REFERENCES calls(id) ON DELETE CASCADE,
    channel_id          BIGINT NOT NULL REFERENCES notification_channels(id) ON DELETE CASCADE,
    intent              VARCHAR(16) NOT NULL,
    resend_all          BOOLEAN NOT NULL DEFAULT FALSE,
    added_topics_json   TEXT NOT NULL,
    create_datetime     TIMESTAMP NOT NULL,
    status              VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts            INTEGER NOT NULL DEFAULT 0,
    next_attempt_at     TIMESTAMP NULL,
    claimed_at          TIMESTAMP NULL,
    claimed_by          VARCHAR(64) NULL,
    last_error          TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_notification_outbox_status_next
    ON notification_outbox(status, next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_notification_outbox_call
    ON notification_outbox(db_call_id);
