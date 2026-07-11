-- database/migrations/2026-07-11-perf-composite-indexes.sql
-- Perf (#50): composite indexes for hot read paths. Idempotent: safe to re-run.
--   narratives(call_id, create_datetime)          — call narratives endpoint
--   unit_logs(unit_id, log_datetime)              — unit logs endpoint
--   notification_send_log(call_id, created_at)    — per-call send-log lookups

DELIMITER $$
DROP PROCEDURE IF EXISTS ensure_index $$
CREATE PROCEDURE ensure_index(IN tbl VARCHAR(64), IN idx VARCHAR(64), IN cols VARCHAR(255))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND INDEX_NAME = idx) THEN
        SET @s := CONCAT('CREATE INDEX ', idx, ' ON ', tbl, ' (', cols, ')');
        PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END $$
DELIMITER ;

CALL ensure_index('narratives', 'idx_narratives_call_created', 'call_id, create_datetime');
CALL ensure_index('unit_logs', 'idx_unit_logs_unit_log', 'unit_id, log_datetime');
CALL ensure_index('notification_send_log', 'idx_send_log_call_created', 'call_id, created_at');

DROP PROCEDURE ensure_index;
