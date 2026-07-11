-- database/migrations/2026-07-11-perf-composite-indexes.pgsql.sql
-- Perf (#50): composite indexes for hot read paths (PostgreSQL). Idempotent.
--   narratives(call_id, create_datetime)          — call narratives endpoint
--   unit_logs(unit_id, log_datetime)              — unit logs endpoint
--   notification_send_log(call_id, created_at)    — per-call send-log lookups

CREATE INDEX IF NOT EXISTS idx_narratives_call_created ON narratives(call_id, create_datetime);
CREATE INDEX IF NOT EXISTS idx_unit_logs_unit_log ON unit_logs(unit_id, log_datetime);
CREATE INDEX IF NOT EXISTS idx_send_log_call_created ON notification_send_log(call_id, created_at);
