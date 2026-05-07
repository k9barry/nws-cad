<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use NwsCad\Database;
use PDO;

class ChannelRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * @return array<int,array{id:int,name:string,type:string,enabled:bool,base_url:string,config_json:string}>
     */
    public function listEnabled(): array
    {
        $stmt = $this->db->query(
            "SELECT id, name, type, enabled, base_url, config_json
             FROM notification_channels WHERE enabled = TRUE ORDER BY name"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function recordSend(int $channelId, ?int $callId, ?string $intent, SendResult $result): void
    {
        $stmt = $this->db->prepare(
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
        $this->pruneSendLog($channelId, 100);
    }

    public function markFailure(int $channelId, string $message): void
    {
        $stmt = $this->db->prepare(
            "UPDATE notification_channels
             SET last_error_at = CURRENT_TIMESTAMP, last_error_message = ?
             WHERE id = ?"
        );
        $stmt->execute([$message, $channelId]);
    }

    private function pruneSendLog(int $channelId, int $keep): void
    {
        // Find the cutoff id for the channel (keep the most recent $keep rows).
        $stmt = $this->db->prepare(
            "SELECT id FROM notification_send_log
             WHERE channel_id = ?
             ORDER BY id DESC LIMIT 1 OFFSET ?"
        );
        $stmt->execute([$channelId, $keep]);
        $cutoff = $stmt->fetchColumn();
        if ($cutoff === false) {
            return;
        }
        $del = $this->db->prepare(
            "DELETE FROM notification_send_log WHERE channel_id = ? AND id <= ?"
        );
        $del->execute([$channelId, (int) $cutoff]);
    }
}
