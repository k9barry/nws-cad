-- database/migrations/2026-05-12-identity-audit.pgsql.sql
-- PostgreSQL variant. Idempotent via IF NOT EXISTS.

ALTER TABLE notification_channels ADD COLUMN IF NOT EXISTS last_updated_actor VARCHAR(64) NULL;
ALTER TABLE notification_send_log ADD COLUMN IF NOT EXISTS actor VARCHAR(64) NULL;
