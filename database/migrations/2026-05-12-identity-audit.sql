-- database/migrations/2026-05-12-identity-audit.sql
-- Adds identity-aware audit columns for the security-hardening workstream.
-- Idempotent: the INFORMATION_SCHEMA check makes re-running a no-op.

SET @ddl := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'notification_channels'
     AND COLUMN_NAME = 'last_updated_actor') = 0,
    'ALTER TABLE notification_channels ADD COLUMN last_updated_actor VARCHAR(64) NULL AFTER last_error_message',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'notification_send_log'
     AND COLUMN_NAME = 'actor') = 0,
    'ALTER TABLE notification_send_log ADD COLUMN actor VARCHAR(64) NULL AFTER error',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
