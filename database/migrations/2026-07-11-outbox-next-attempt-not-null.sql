-- database/migrations/2026-07-11-outbox-next-attempt-not-null.sql
-- Perf (#50): make notification_outbox.next_attempt_at NOT NULL with a default,
-- so the claim query can drop its `next_attempt_at IS NULL OR ...` branch and
-- let the (status, next_attempt_at) index do a clean range seek.
-- Idempotent: safe to re-run.

-- A NULL next_attempt_at previously meant "eligible immediately"; backfill those
-- to now so they stay eligible under the new `next_attempt_at <= ?` predicate.
UPDATE notification_outbox SET next_attempt_at = CURRENT_TIMESTAMP WHERE next_attempt_at IS NULL;

ALTER TABLE notification_outbox
    MODIFY COLUMN next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
