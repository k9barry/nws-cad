# Notification Outbox + Async Worker — Design

**Date:** 2026-05-12
**Status:** Draft — pending user review
**Scope:** Phase 1 of decoupling notification fan-out from XML ingest.

## Problem

`AegisXmlParser::processFile()` commits its DB transaction and then dispatches a `CallProcessedEvent` (`src/AegisXmlParser.php:118`). The `NotificationDispatcher` subscriber (registered in `src/watcher.php:73`) handles the event synchronously: it loads the `IncidentDto`, resolves topics, iterates every enabled channel, and calls `$channel->send()` for each. Each channel runs its own retry cycle (1s/3s/9s backoff) inline.

Consequences:

1. **The watcher blocks on slow channels.** A single XML can hold the file-scan loop for tens of seconds when a channel is timing out (3 attempts × 10s timeout each, times the number of channels). Backlog builds up.
2. **Notifications are lost on crash.** If the watcher dies between commit and dispatch, or mid-dispatch, that notification never fires — the call is in the DB, but no one is paged.
3. **No cross-restart retry.** A 5xx blip or DNS flap during dispatch loses the notification permanently; the next call gets a fresh attempt but the failed one doesn't.

## Goals

- Decouple the parser's commit from channel delivery.
- Survive process crashes: a notification queued before a crash should still be delivered after restart.
- Retry per-channel transparently across worker restarts, with bounded attempts and exponential backoff.
- Provide a clear DB-level audit trail of pending and failed notifications.

## Non-goals

- Multi-process worker scale-out (single watcher process remains the only consumer).
- A separate daemon container — the existing watcher process drives the outbox between file scans.
- Dashboard or API surfaces for outbox visibility. Operators inspect the table directly. Surfacing in the dashboard is Phase 2.

## Architecture

```
AegisXmlParser::processFile()
  ↓ (transaction commits)
  EventDispatcher::dispatch(CallProcessedEvent)
    ↓
  OutboxWriter (new EventDispatcher subscriber, replaces direct NotificationDispatcher subscription)
    - Closed intent → return (no work)
    - delta-time gate: if create_datetime is older than NOTIFICATION_DELTA_SECONDS → return
    - resolve topic mode (resendAll vs addedTopics) and store both on every row
    - listEnabled() channels
    - INSERT one notification_outbox row per (event, enabled_channel)

FileWatcher::start() loop (per tick, after file scan)
  ↓
  OutboxProcessor::tick()
    - prune notification_outbox where status='done' AND updated_at < now - 7d (one DELETE)
    - reset orphaned rows: status='in_flight' AND claimed_by != $thisWorkerId → status='pending'
    - claim up to OUTBOX_BATCH_SIZE rows: UPDATE ... SET status='in_flight', claimed_by=$id
      WHERE status='pending' AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
      ORDER BY id ASC LIMIT N
    - SELECT the claimed rows
    - per row: processRow($row)
        - load IncidentDto via $incidentLoader($row.db_call_id)
        - factory.create(channel_row_for($row.channel_id)) → channel
        - build NotificationContext from row's stored intent + topics
        - $channel->send($dto, $ctx) → SendResult[]
        - record each SendResult in notification_send_log via channelRepo->recordSend
        - on success: mark outbox row status='done'
        - on throw / channel returns all-failed SendResults:
          attempts++
          if attempts >= OUTBOX_MAX_ATTEMPTS: status='failed', last_error=<msg>
          else: status='pending', next_attempt_at=NOW() + backoff(attempts)
```

### Topic resolution at write time

The existing logic in `NotificationDispatcher::handle()` decides whether to fan out to *all* derived topics (Created, or Updated with `call_type`/`full_address`/`alarm_level` change) or *only* `addedTopics` (Updated with just-new units). This decision must happen **once** at write time so the per-channel rows all carry the same topic list.

The decision inputs are `intent` + `changedFields` + `addedTopics` — all already on `CallProcessedEvent`. We extract the resolution into a static helper `TopicResolver::shouldResendAll(Intent, string[]): bool` so the writer and any future use site share one implementation.

We store both `resend_all` (bool) and `added_topics_json` on the outbox row. The actual topic *list* is recomputed by the worker from the freshly-loaded `IncidentDto` when `resend_all=1` (because the DTO state may have shifted between write and process — e.g., new units added — but the *decision* about which mode to use is frozen at write time). When `resend_all=0`, the stored `added_topics_json` is used directly.

### Per-channel rows — why

The "approved with per-channel rows" decision (Phase 1 path B in brainstorming) means an outbox row's lifecycle is bound to a single channel. If pushover succeeds and ntfy fails, only the ntfy row retries; pushover doesn't get re-notified. This costs one extra INSERT per channel per event (cheap) and one extra column (`channel_id`) on the outbox row, but buys clean per-channel retry isolation. It also means `NotificationDispatcher::handle()` — which iterates channels — disappears; its logic gets distributed between `OutboxWriter` (write-time, channel fan-out) and `OutboxProcessor::processRow()` (one-channel-at-a-time send).

## Schema

### `notification_outbox`

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` (mysql) / `BIGSERIAL` (pg) | PK |
| `db_call_id` | `INT NOT NULL` | FK → `calls(id)` ON DELETE CASCADE |
| `channel_id` | `INT NOT NULL` | FK → `notification_channels(id)` ON DELETE CASCADE |
| `intent` | `VARCHAR(16) NOT NULL` | `Created` or `Updated` (Closed never reaches here) |
| `resend_all` | `TINYINT NOT NULL` (mysql) / `BOOLEAN NOT NULL` (pg) | Topic-mode flag, frozen at write time |
| `added_topics_json` | `TEXT NOT NULL` | JSON array; used when `resend_all=0` |
| `create_datetime` | `DATETIME NOT NULL` (mysql) / `TIMESTAMP NOT NULL` (pg) | From `CallProcessedEvent` |
| `status` | `VARCHAR(16) NOT NULL DEFAULT 'pending'` | `pending` / `in_flight` / `done` / `failed` |
| `attempts` | `INT NOT NULL DEFAULT 0` | Per-row attempt counter |
| `next_attempt_at` | `DATETIME NULL` / `TIMESTAMP NULL` | Earliest time worker may retry |
| `claimed_at` | `DATETIME NULL` / `TIMESTAMP NULL` | Set when worker transitions row to `in_flight` |
| `claimed_by` | `VARCHAR(64) NULL` | `worker_id` of claiming process |
| `last_error` | `TEXT NULL` | Most recent error from `$channel->send()` or `processRow()` |
| `created_at` | `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` | |
| `updated_at` | `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` (mysql); trigger in pg | |

**Indexes:**
- `idx_status_next_attempt` on `(status, next_attempt_at)` — drives the claim query
- `idx_call_id` on `(db_call_id)` — operator inspection by call

**Three schema files must be updated in lockstep** (per CLAUDE.md):
1. `database/mysql/init.sql`
2. `database/postgres/init.sql`
3. `database/schema.sql` (used by CI)

A migration file is also added: `database/migrations/2026-05-12-notification-outbox.sql` (mysql) and `database/migrations/2026-05-12-notification-outbox.pgsql.sql` (pgsql) for operators to apply to existing deployments.

### `worker_id` format

`gethostname() + ':' + getmypid() + ':' + processStartTimestamp`, e.g., `nws-cad-app:42:1715520000`. `processStartTimestamp` is captured by `WorkerId` on its first call (memoized for the rest of the process lifetime) — it's the unix timestamp of when this PHP process first asked for its identifier, which is functionally "boot time" from the worker's perspective. This distinguishes successive PIDs that happen to reuse the same number after a quick restart. Stored as a string up to 64 chars.

## New classes

| Class | Responsibility | Depends on |
|---|---|---|
| `NwsCad\Notifications\Outbox\OutboxWriter` | Subscriber to `CallProcessedEvent`. Applies Closed + delta-time gates, then INSERTs one row per `(event, enabled_channel)`. | `ChannelRepositoryInterface`, PDO, clock |
| `NwsCad\Notifications\Outbox\OutboxProcessor` | Called from `FileWatcher` loop. Prunes, resets orphans, claims, processes, retries. | PDO, `ChannelFactoryInterface`, `ChannelRepositoryInterface`, `$incidentLoader` callable, clock, `$workerId` string |
| `NwsCad\Notifications\Outbox\OutboxRepository` | Thin DB-access layer: `prune()`, `resetOrphans()`, `claim()`, `markDone()`, `markRetry()`, `markFailed()`. Used by both writer (`insert()`) and processor. | PDO, `DbHelper` for date math |
| `NwsCad\Notifications\TopicResolver` | Static helper: `shouldResendAll(Intent, string[] $changedFields): bool` and `resolveTopics(IncidentDto $dto, bool $resendAll, string[] $addedTopics): string[]`. | Pure logic |
| `NwsCad\Notifications\Outbox\WorkerId` | Static helper: `current(): string` produces deterministic `host:pid:boot` value for the lifetime of the process (memoized). | `gethostname`, `getmypid` |

`NotificationDispatcher` is **removed** — its responsibilities are split between `OutboxWriter` and `OutboxProcessor::processRow()`. The class file is deleted; all callers (currently only `src/watcher.php`) are updated.

## Configuration

Two new env vars (with sensible defaults in `Config`):

| Var | Default | Purpose |
|---|---|---|
| `OUTBOX_BATCH_SIZE` | `10` | Max rows claimed per `tick()` |
| `OUTBOX_MAX_ATTEMPTS` | `5` | Permanent-failure threshold |

The existing `NOTIFICATION_DELTA_SECONDS` (default 900) continues to apply at write time.

Backoff schedule (hard-coded constant in `OutboxProcessor`): `[30s, 2min, 10min, 30min, 2h]` — indexed by `attempts - 1`. After 5 attempts (~3 hours total), the row is marked `failed`.

Pruning threshold: 7 days for `status='done'`. Hard-coded constant; if operators need to tune, that's a Phase-2 follow-up.

## Watcher integration

`src/watcher.php` wires the new pieces:

```php
$outboxRepo = new OutboxRepository($db);
$outboxWriter = new OutboxWriter($outboxRepo, new ChannelRepository(), $deltaSeconds);
$outboxProcessor = new OutboxProcessor(
    $outboxRepo,
    $channelFactoryInstance,
    new ChannelRepository(),
    $incidentLoader,
    WorkerId::current(),
);

EventDispatcher::subscribe([$outboxWriter, 'handle']);

$watcher = new FileWatcher();
$watcher->setOnTick(fn () => $outboxProcessor->tick());
$watcher->start();
```

`FileWatcher` gets a small addition: `setOnTick(callable)` stores a callback, and `start()`'s loop calls it once per iteration after the file scan. This keeps `FileWatcher` itself ignorant of the outbox (single responsibility) and lets tests drive the loop without an outbox.

## Behavioral changes

**Latency.** A notification fires at the next watcher tick after the file is ingested. With `WATCHER_INTERVAL=5s` (default), worst-case added latency is ~5 seconds. Operators who care about sub-second notification (no one today) would need a shorter interval.

**Retry observability.** Each `processRow()` invocation that hits the channel produces a `notification_send_log` entry (as today). A row that retries 3 times before succeeding yields 3 log entries — that is intentional. The outbox row itself shows `attempts=3, status=done`.

**Closed intent.** Still dropped at write time — never written to the outbox.

**Delta-time gate.** Applied at write time only. If the worker is backlogged and a row sits pending past the delta window, it still gets processed when claimed. (The gate is about freshness of the trigger, not freshness of delivery.)

## Failure modes

| Failure | Detection | Recovery |
|---|---|---|
| Worker crashes mid-`processRow` | Boot scan finds `status='in_flight'` rows with non-current `claimed_by` | `OutboxProcessor::tick()` resets them to `pending` before claiming new work |
| Channel hard 4xx | `$channel->send()` returns `SendResult::fail` with 4xx status | Outbox row's `attempts++`; retried until `OUTBOX_MAX_ATTEMPTS`. (Phase-2 candidate: short-circuit on 4xx — a 4xx is unlikely to clear up.) |
| Channel network timeout | `$channel->send()` returns `SendResult::fail` with status 0 after its own internal retries | Outbox row retries on next eligible tick |
| `processRow` throws (DB issue, factory exception, etc.) | `Throwable` caught at the tick-level around each row | Outbox row marked retry; logged at `error` level |
| All rows for an event fail | Each row reaches `OUTBOX_MAX_ATTEMPTS`, becomes `status='failed'` | Operator sees failed rows in DB and via `channel.last_error_*` on `notification_channels`. Phase-2 candidate: dashboard surface, alerting. |
| Same XML reprocessed (e.g., file moved back) | Producer is idempotent at the *parser* level (filename uniqueness check), but if force-reprocessed, would write duplicate outbox rows. | Acceptable: delta-time gate filters most duplicates, and the operator who force-reprocesses is opting in. No unique constraint added. |

## Migration / rollout

Single PR adds the table, the writer, the processor, the watcher wire-up, and removes `NotificationDispatcher`. No staged rollout needed — the change is internal and the producer/consumer ship together. Existing `notification_send_log` rows are untouched.

Operators with an existing deployment apply the migration SQL once; new deployments get the table from `init.sql`.

## Testing

| Test class | Type | What it asserts |
|---|---|---|
| `OutboxWriterTest` | Unit | Closed → no insert; delta-time → no insert; Created with N enabled channels → N inserts with correct fields; `resend_all` flag set correctly for Created vs Updated-with-trigger-change vs Updated-add-only |
| `OutboxProcessorTickTest` | Unit | Prune deletes only `done` older than 7d; orphans reset; claim respects `next_attempt_at`; claim limit honored; processed rows transition correctly; throw → retry path; max-attempts → failed; backoff schedule indexed correctly |
| `TopicResolverTest` | Unit | `shouldResendAll` for all 3 cases (Created, Updated+trigger, Updated+other); `resolveTopics` returns all derived topics on resend-all, only addedTopics otherwise, with dedup |
| `OutboxEndToEndTest` | Integration | Real DB: parser-style INSERT → `EventDispatcher::dispatch` → outbox row exists → tick processes it → `notification_send_log` row exists → outbox row is `done`. Uses ntfy stub channel via test registry. |
| `WorkerIdTest` | Unit | Memoized; format `host:pid:boot`; stable within process |
| Existing `NotificationDispatcherTest` | **Deleted** | Logic migrates to `OutboxWriterTest` + `OutboxProcessorTickTest` |
| Existing `WebhookEndToEndTest` | Updated | Adapts to new wiring (drives `OutboxProcessor::tick()` instead of `NotificationDispatcher::handle()`) |

Per CLAUDE.md test conventions:
- All test classes annotated with `#[CoversClass]` or `#[CoversNothing]`
- All transitively executed classes annotated with `#[UsesClass]`
- Tests that hit the controller call `Response::resetForTesting()` in `setUp()`
- Registry-dependent tests clear + re-register in `setUp()`

## Risks and trade-offs

- **Single-process consumer is a SPOF.** If the watcher process is down, no notifications fire. This is unchanged from today — the watcher being down already meant no XML ingest. Acceptable for the deployment model.
- **In-process tick coupling.** `FileWatcher` calls into outbox via callback. If outbox processing throws, the file-scan still proceeds because the callback is wrapped in `try { ... } catch (Throwable) { log; continue; }`. Verified in `OutboxProcessorTickTest::testTickIsolatedFromFileScanFailures`.
- **No SKIP LOCKED.** Since there's one worker, `UPDATE ... WHERE status='pending' ... ORDER BY id ASC LIMIT N` is atomic enough. Multi-worker support would need to add `SELECT ... FOR UPDATE SKIP LOCKED` (mysql 8 / pg 9.5+). Both backends already support it if Phase 2 needs it.
- **Backoff schedule is hardcoded.** If operators need different cadence, that's a Phase-2 follow-up (env var + parsing).
- **Failed rows accumulate.** With no auto-cleanup of `failed` rows, the table grows. Acceptable: the volume is low (fail rate is exceptional, not steady-state). Phase 2 can add a CLI: `php bin/notifications.php outbox prune-failed --older-than=30d`.
