<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Outbox;

use DateTimeImmutable;
use NwsCad\Database;
use NwsCad\Notifications\Events\Intent;
use PDO;

final class OutboxRepository implements OutboxRepositoryInterface
{
    private ?PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    /**
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    private function exec(callable $fn): mixed
    {
        if ($this->db !== null) {
            return $fn($this->db);
        }
        return Database::run($fn);
    }

    public function insert(
        int $callId,
        int $channelId,
        Intent $intent,
        bool $resendAll,
        array $addedTopics,
        DateTimeImmutable $createDateTime,
    ): int {
        return $this->exec(function (PDO $db) use ($callId, $channelId, $intent, $resendAll, $addedTopics, $createDateTime): int {
            $stmt = $db->prepare(
                "INSERT INTO notification_outbox
                 (db_call_id, channel_id, intent, resend_all, added_topics_json, create_datetime)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $callId,
                $channelId,
                $intent->value,
                $resendAll ? 1 : 0,
                json_encode(array_values($addedTopics), JSON_UNESCAPED_SLASHES),
                $createDateTime->format('Y-m-d H:i:s'),
            ]);
            return (int) $db->lastInsertId();
        });
    }

    public function prune(int $olderThanSeconds): int
    {
        return $this->exec(function (PDO $db) use ($olderThanSeconds): int {
            $cutoff = (new DateTimeImmutable())->modify("-{$olderThanSeconds} seconds")->format('Y-m-d H:i:s');
            $stmt = $db->prepare(
                "DELETE FROM notification_outbox
                 WHERE status = 'done' AND updated_at < ?"
            );
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        });
    }

    public function resetOrphans(string $currentWorkerId): int
    {
        return $this->exec(function (PDO $db) use ($currentWorkerId): int {
            $stmt = $db->prepare(
                "UPDATE notification_outbox
                 SET status = 'pending',
                     claimed_at = NULL,
                     claimed_by = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE status = 'in_flight' AND (claimed_by IS NULL OR claimed_by <> ?)"
            );
            $stmt->execute([$currentWorkerId]);
            return $stmt->rowCount();
        });
    }

    public function claim(string $workerId, int $batchSize, DateTimeImmutable $now): array
    {
        return $this->exec(function (PDO $db) use ($workerId, $batchSize, $now): array {
            $nowStr = $now->format('Y-m-d H:i:s');

            $sel = $db->prepare(
                "SELECT id FROM notification_outbox
                 WHERE status = 'pending'
                   AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
                 ORDER BY id ASC
                 LIMIT ?"
            );
            $sel->bindValue(1, $nowStr);
            $sel->bindValue(2, $batchSize, PDO::PARAM_INT);
            $sel->execute();
            $ids = array_map(static fn ($r): int => (int) $r['id'], $sel->fetchAll(PDO::FETCH_ASSOC));
            if ($ids === []) {
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $upd = $db->prepare(
                "UPDATE notification_outbox
                 SET status = 'in_flight',
                     claimed_at = ?,
                     claimed_by = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id IN ({$placeholders}) AND status = 'pending'"
            );
            $upd->execute([$nowStr, $workerId, ...$ids]);

            $reSel = $db->prepare(
                "SELECT * FROM notification_outbox
                 WHERE id IN ({$placeholders}) AND claimed_by = ? AND status = 'in_flight'
                 ORDER BY id ASC"
            );
            $reSel->execute([...$ids, $workerId]);
            return $reSel->fetchAll(PDO::FETCH_ASSOC) ?: [];
        });
    }

    public function markDone(int $rowId): void
    {
        $this->exec(function (PDO $db) use ($rowId): void {
            $stmt = $db->prepare(
                "UPDATE notification_outbox
                 SET status = 'done', last_error = NULL,
                     claimed_at = NULL, claimed_by = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([$rowId]);
        });
    }

    public function markRetry(
        int $rowId,
        int $attempts,
        DateTimeImmutable $nextAttemptAt,
        string $errorMessage,
    ): void {
        $this->exec(function (PDO $db) use ($rowId, $attempts, $nextAttemptAt, $errorMessage): void {
            $stmt = $db->prepare(
                "UPDATE notification_outbox
                 SET status = 'pending',
                     attempts = ?,
                     next_attempt_at = ?,
                     claimed_at = NULL,
                     claimed_by = NULL,
                     last_error = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([
                $attempts,
                $nextAttemptAt->format('Y-m-d H:i:s'),
                $errorMessage,
                $rowId,
            ]);
        });
    }

    public function markFailed(int $rowId, int $attempts, string $errorMessage): void
    {
        $this->exec(function (PDO $db) use ($rowId, $attempts, $errorMessage): void {
            $stmt = $db->prepare(
                "UPDATE notification_outbox
                 SET status = 'failed',
                     attempts = ?,
                     last_error = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([$attempts, $errorMessage, $rowId]);
        });
    }

    private const KNOWN_STATUSES = ['pending', 'in_flight', 'done', 'failed'];

    public function listByStatus(string $status, int $limit): array
    {
        return $this->exec(function (PDO $db) use ($status, $limit): array {
            $sql = "SELECT o.id, o.db_call_id, o.channel_id, o.intent, o.resend_all,
                           o.status, o.attempts, o.next_attempt_at, o.claimed_at,
                           o.claimed_by, o.last_error, o.created_at, o.updated_at,
                           nc.name AS channel_name, nc.type AS channel_type,
                           c.call_number
                    FROM notification_outbox o
                    LEFT JOIN notification_channels nc ON nc.id = o.channel_id
                    LEFT JOIN calls c ON c.id = o.db_call_id";
            $params = [];
            if ($status !== 'all') {
                if (! in_array($status, self::KNOWN_STATUSES, true)) {
                    return [];
                }
                $sql .= " WHERE o.status = ?";
                $params[] = $status;
            }
            $sql .= " ORDER BY o.id DESC LIMIT ?";
            $stmt = $db->prepare($sql);
            $i = 1;
            foreach ($params as $p) {
                $stmt->bindValue($i++, $p);
            }
            $stmt->bindValue($i, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        });
    }

    public function retry(int $rowId): bool
    {
        return $this->exec(function (PDO $db) use ($rowId): bool {
            $stmt = $db->prepare(
                "UPDATE notification_outbox
                 SET status = 'pending', attempts = 0,
                     next_attempt_at = NULL, last_error = NULL,
                     claimed_at = NULL, claimed_by = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([$rowId]);
            return $stmt->rowCount() > 0;
        });
    }

    public function delete(int $rowId): bool
    {
        return $this->exec(function (PDO $db) use ($rowId): bool {
            $stmt = $db->prepare("DELETE FROM notification_outbox WHERE id = ?");
            $stmt->execute([$rowId]);
            return $stmt->rowCount() > 0;
        });
    }

    public function deleteByStatus(string $status): int
    {
        if (! in_array($status, self::KNOWN_STATUSES, true)) {
            return 0;
        }
        return $this->exec(function (PDO $db) use ($status): int {
            $stmt = $db->prepare("DELETE FROM notification_outbox WHERE status = ?");
            $stmt->execute([$status]);
            return $stmt->rowCount();
        });
    }
}
