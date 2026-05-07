<?php

declare(strict_types=1);

namespace NwsCad\Api\Controllers;

use NwsCad\Api\Response;
use NwsCad\Database;
use PDO;
use Exception;

final class NotificationsController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /** GET /api/notifications/channels */
    public function channels(): void
    {
        try {
            $rows = $this->db->query(
                "SELECT id, name, type, enabled, base_url,
                        last_error_at, last_error_message,
                        created_at, updated_at
                 FROM notification_channels ORDER BY name"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];

            Response::success(['items' => $rows]);
        } catch (Exception $e) {
            Response::error('Failed to list channels: ' . $e->getMessage(), 500);
        }
    }

    /** GET /api/notifications/log?channel=<id|name>&limit=<n> */
    public function log(): void
    {
        try {
            $channel = $_GET['channel'] ?? null;
            $limit = max(1, min(100, (int) ($_GET['limit'] ?? 10)));

            if ($channel === null || $channel === '') {
                Response::error('channel parameter required', 400);
                return;
            }

            $channelId = $this->resolveChannelId($channel);
            if ($channelId === null) {
                Response::error('Unknown channel', 404);
                return;
            }

            $stmt = $this->db->prepare(
                "SELECT id, channel_id, call_id, intent, topic, ok,
                        http_status, duration_ms, error, created_at
                 FROM notification_send_log
                 WHERE channel_id = ?
                 ORDER BY id DESC LIMIT ?"
            );
            $stmt->bindValue(1, $channelId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            Response::success(['items' => $rows, 'channel_id' => $channelId, 'limit' => $limit]);
        } catch (Exception $e) {
            Response::error('Failed to read send log: ' . $e->getMessage(), 500);
        }
    }

    private function resolveChannelId(string $channel): ?int
    {
        if (ctype_digit($channel)) {
            return (int) $channel;
        }
        $stmt = $this->db->prepare("SELECT id FROM notification_channels WHERE name = ?");
        $stmt->execute([$channel]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
