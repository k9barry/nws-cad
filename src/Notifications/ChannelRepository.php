<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use NwsCad\Database;
use PDO;

final class ChannelRepository implements ChannelRepositoryInterface
{
    // When non-null, an explicitly injected PDO (used by tests and request-
    // scoped controllers, which manage their own connection lifecycle).
    // When null, every operation runs through Database::run(), which resolves
    // the current singleton and retries once on a lost connection — so this
    // repository self-heals across MySQL restarts in long-lived processes.
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

    /**
     * @return array<int,array{id:int,name:string,type:string,enabled:bool,base_url:string,config_json:string}>
     */
    public function listEnabled(): array
    {
        return $this->exec(function (PDO $db): array {
            $stmt = $db->query(
                "SELECT id, name, type, enabled, base_url, config_json
                 FROM notification_channels WHERE enabled = TRUE ORDER BY name"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        });
    }

    public function findById(int $id): ?array
    {
        return $this->exec(function (PDO $db) use ($id): ?array {
            $stmt = $db->prepare(
                "SELECT id, name, type, enabled, base_url, config_json
                 FROM notification_channels WHERE id = ?"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        });
    }

    public function recordSend(int $channelId, ?int $callId, ?string $intent, SendResult $result): void
    {
        $this->exec(function (PDO $db) use ($channelId, $callId, $intent, $result): void {
            $stmt = $db->prepare(
                "INSERT INTO notification_send_log
                 (channel_id, call_id, intent, topic, ok, http_status, duration_ms, error)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $channelId,
                $callId,
                $intent,
                $result->topic,
                $result->ok ? 1 : 0,
                $result->httpStatus,
                $result->durationMs,
                $result->error,
            ]);
        });
        $this->pruneSendLog($channelId, 100);
    }

    public function markFailure(int $channelId, string $message): void
    {
        $this->exec(function (PDO $db) use ($channelId, $message): void {
            $stmt = $db->prepare(
                "UPDATE notification_channels
                 SET last_error_at = CURRENT_TIMESTAMP, last_error_message = ?
                 WHERE id = ?"
            );
            $stmt->execute([$message, $channelId]);
        });
    }

    private function pruneSendLog(int $channelId, int $keep): void
    {
        $this->exec(function (PDO $db) use ($channelId, $keep): void {
            // Find the cutoff id for the channel (keep the most recent $keep rows).
            // LIMIT/OFFSET must be bound as integers under EMULATE_PREPARES=false.
            $stmt = $db->prepare(
                "SELECT id FROM notification_send_log
                 WHERE channel_id = ?
                 ORDER BY id DESC LIMIT 1 OFFSET ?"
            );
            $stmt->bindValue(1, $channelId, PDO::PARAM_INT);
            $stmt->bindValue(2, $keep, PDO::PARAM_INT);
            $stmt->execute();
            $cutoff = $stmt->fetchColumn();
            if ($cutoff === false) {
                return;
            }
            $del = $db->prepare(
                "DELETE FROM notification_send_log WHERE channel_id = ? AND id <= ?"
            );
            $del->bindValue(1, $channelId, PDO::PARAM_INT);
            $del->bindValue(2, (int) $cutoff, PDO::PARAM_INT);
            $del->execute();
        });
    }
}
