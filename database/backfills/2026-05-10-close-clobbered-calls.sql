-- Backfill: close 6 calls clobbered by Bug A before commit 68c4a46.
--
-- Context:
--   See docs/superpowers/specs/2026-05-09-call-status-correctness-design.md
--   for the close-status correctness design. Bug A (out-of-order XML arrival)
--   was prevented going forward by the filename-staleness check in
--   AegisXmlParser, but pre-existing affected calls were never backfilled —
--   the spec assumed only call 163 (id 682) was affected and it turned out
--   to be a legitimate multi-agency-still-open case. Subsequent operator
--   review identified 6 calls that were closed in CAD but show as open in
--   the dashboard because an older XML overwrote the close before the fix
--   shipped.
--
-- Affected IDs (with call_number for cross-reference):
--   531=988, 546=5, 609=78, 682=163, 680=160, 647=121
--
-- Run once via:
--   docker compose exec mysql mysql -u nws_user -p nws_cad < database/backfills/2026-05-10-close-clobbered-calls.sql
-- or paste into CloudBeaver (port 8978).
--
-- Safety: WHERE close_datetime IS NULL / closed_datetime IS NULL guards
-- prevent overwriting any close that legitimately landed between writing
-- this script and running it.

START TRANSACTION;

-- Parent calls table: set close_datetime + closed_flag.
UPDATE calls
SET close_datetime = NOW(),
    closed_flag = 1
WHERE id IN (531, 546, 609, 682, 680, 647)
  AND close_datetime IS NULL;

-- Agency contexts: reconcile rows still showing as not-closed.
-- Skips call 163's already-closed agency (closed at 12:13).
UPDATE agency_contexts
SET closed_datetime = NOW(),
    closed_flag = 1,
    status = 'Not In Progress'
WHERE call_id IN (531, 546, 609, 682, 680, 647)
  AND closed_datetime IS NULL;

-- Verify parent rows
SELECT id, call_number, close_datetime, closed_flag, reopened_flag, canceled_flag
FROM calls
WHERE id IN (531, 546, 609, 682, 680, 647)
ORDER BY id;

-- Verify agency_contexts
SELECT call_id, agency_type, status, closed_datetime, closed_flag
FROM agency_contexts
WHERE call_id IN (531, 546, 609, 682, 680, 647)
ORDER BY call_id, id;

COMMIT;
