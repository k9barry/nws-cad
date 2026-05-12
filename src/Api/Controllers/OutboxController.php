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
            Response::error('Failed to list outbox: ' . $e->getMessage(), 500);
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
            Response::error('Failed to retry outbox row: ' . $e->getMessage(), 500);
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
            Response::error('Failed to dismiss outbox row: ' . $e->getMessage(), 500);
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
            Response::error('Failed to clear outbox: ' . $e->getMessage(), 500);
        }
    }
}
