<?php

declare(strict_types=1);

namespace NwsCad\Api\Controllers;

use NwsCad\Api\Response;
use NwsCad\Database;
use NwsCad\Notifications\Outbox\OutboxRepository;
use NwsCad\Notifications\Outbox\OutboxRepositoryInterface;
use Exception;

/**
 * Operator-facing admin controller for the notification outbox queue.
 * Read-only listing plus per-row retry/dismiss and bulk clear-by-status.
 */
final class OutboxController
{
    private const ALLOWED_STATUSES = ['pending', 'in_flight', 'done', 'failed', 'all'];
    private const ALLOWED_BULK_STATUSES = ['done', 'failed'];

    private OutboxRepositoryInterface $repo;

    public function __construct(?OutboxRepositoryInterface $repo = null)
    {
        $this->repo = $repo ?? new OutboxRepository(Database::getConnection());
    }

    /** GET /api/notifications/outbox?status=pending|in_flight|done|failed|all&limit=N */
    public function index(): void
    {
        try {
            $status = (string) ($_GET['status'] ?? 'pending');
            if (! in_array($status, self::ALLOWED_STATUSES, true)) {
                Response::error('Invalid status', 400, [
                    'allowed_statuses' => self::ALLOWED_STATUSES,
                ]);
                return;
            }
            $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
            $rows  = $this->repo->listByStatus($status, $limit);
            Response::success(['items' => $rows, 'status' => $status, 'limit' => $limit]);
        } catch (Exception $e) {
            Response::serverErrorFromException($e, 'Failed to list outbox');
        }
    }

    /** POST /api/notifications/outbox/{id}/retry */
    public function retry(string $id): void
    {
        try {
            if (! ctype_digit($id)) {
                Response::error('Invalid outbox id', 400);
                return;
            }
            $ok = $this->repo->retry((int) $id);
            if (! $ok) {
                Response::error('Outbox row not found', 404);
                return;
            }
            Response::success(['retried' => 1, 'id' => (int) $id]);
        } catch (Exception $e) {
            Response::serverErrorFromException($e, 'Failed to retry outbox row');
        }
    }

    private const HISTORY_LIMIT = 20;

    /** GET /api/notifications/outbox/{id} — row detail plus send-attempt history */
    public function show(string $id): void
    {
        try {
            if (! ctype_digit($id)) {
                Response::error('Invalid outbox id', 400);
                return;
            }
            $row = $this->repo->findById((int) $id);
            if ($row === null) {
                Response::error('Outbox row not found', 404);
                return;
            }
            $history = $this->repo->listSendHistory(
                (int) $row['channel_id'],
                (int) $row['db_call_id'],
                (string) $row['intent'],
                self::HISTORY_LIMIT,
            );
            Response::success(['row' => $row, 'history' => $history]);
        } catch (Exception $e) {
            Response::serverErrorFromException($e, 'Failed to load outbox row');
        }
    }

    /**
     * POST /api/notifications/outbox/{id}/schedule
     * Body (JSON or form): { "next_attempt_at": "YYYY-MM-DD HH:MM:SS" }
     *
     * Reschedules a pending or failed row to retry at the supplied time. Keeps
     * attempts/last_error intact; failed rows transition back to pending.
     */
    public function schedule(string $id): void
    {
        try {
            if (! ctype_digit($id)) {
                Response::error('Invalid outbox id', 400);
                return;
            }

            $body = json_decode((string) file_get_contents('php://input'), true);
            $when = is_array($body) ? ($body['next_attempt_at'] ?? null) : null;
            if (! is_string($when) || $when === '') {
                $when = (string) ($_POST['next_attempt_at'] ?? '');
            }
            if (! is_string($when) || $when === '') {
                Response::error('Missing next_attempt_at', 400);
                return;
            }

            // Accept both 'Y-m-d H:i:s' and ISO 8601 ('Y-m-d\TH:i:s' or with timezone).
            // DateTimeImmutable throws on invalid input → caught below.
            $parsed = null;
            try {
                $parsed = new \DateTimeImmutable((string) $when);
            } catch (\Throwable $e) {
                Response::error('Invalid next_attempt_at: ' . $e->getMessage(), 400);
                return;
            }

            $ok = $this->repo->reschedule((int) $id, $parsed);
            if (! $ok) {
                Response::error('Outbox row not found or not reschedulable (must be pending or failed)', 404);
                return;
            }
            Response::success([
                'id'              => (int) $id,
                'next_attempt_at' => $parsed->format('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            Response::serverErrorFromException($e, 'Failed to reschedule outbox row');
        }
    }

    /** DELETE /api/notifications/outbox/{id} */
    public function dismiss(string $id): void
    {
        try {
            if (! ctype_digit($id)) {
                Response::error('Invalid outbox id', 400);
                return;
            }
            $ok = $this->repo->delete((int) $id);
            if (! $ok) {
                Response::error('Outbox row not found', 404);
                return;
            }
            Response::success(['deleted' => 1, 'id' => (int) $id]);
        } catch (Exception $e) {
            Response::serverErrorFromException($e, 'Failed to dismiss outbox row');
        }
    }

    /** POST /api/notifications/outbox/clear?status=done|failed */
    public function clear(): void
    {
        try {
            $status = (string) ($_GET['status'] ?? $_POST['status'] ?? '');
            if (! in_array($status, self::ALLOWED_BULK_STATUSES, true)) {
                Response::error('Invalid status for bulk clear', 400, [
                    'allowed_statuses' => self::ALLOWED_BULK_STATUSES,
                ]);
                return;
            }
            $count = $this->repo->deleteByStatus($status);
            Response::success(['deleted' => $count, 'status' => $status]);
        } catch (Exception $e) {
            Response::serverErrorFromException($e, 'Failed to clear outbox');
        }
    }
}
