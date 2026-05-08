<?php

declare(strict_types=1);

namespace NwsCad\Api\Controllers;

use NwsCad\Api\Response;
use NwsCad\Config;
use NwsCad\Database;
use NwsCad\Notifications\ChannelFactory;
use NwsCad\Notifications\ChannelRepository;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\NotificationContext;
use DateTimeImmutable;
use PDO;
use Exception;

final class NotificationsController
{
    private PDO $db;
    private ChannelFactory $factory;
    private ChannelRepository $repo;

    public function __construct(?ChannelFactory $factory = null, ?ChannelRepository $repo = null)
    {
        $this->db = Database::getConnection();
        $this->factory = $factory ?? new ChannelFactory(Config::getInstance());
        $this->repo = $repo ?? new ChannelRepository($this->db);
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

    /** POST /api/notifications/channels/{type}/enable */
    public function enable(string $type): void
    {
        try {
            if (! $this->validateType($type)) {
                Response::error("Unknown channel type: {$type}", 404);
                return;
            }

            $envKey  = strtoupper($type) . '_BASE_URL';
            $baseUrl = $_ENV[$envKey] ?? getenv($envKey) ?: '';

            $name = "{$type}_primary";

            $stmt = $this->db->prepare("SELECT id, base_url FROM notification_channels WHERE name = ?");
            $stmt->execute([$name]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing === false) {
                if ($baseUrl === '') {
                    Response::error("Missing env var: {$envKey}", 422);
                    return;
                }
                $defaultConfig = $type === 'ntfy'
                    ? '{"auth_token_env":"NTFY_AUTH_TOKEN","alarm_priority_map":{"1":3,"2":4,"3":5}}'
                    : '{"token_env":"PUSHOVER_TOKEN","user_env":"PUSHOVER_USER"}';

                $ins = $this->db->prepare(
                    "INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
                     VALUES (?, ?, 1, ?, ?)"
                );
                $ins->execute([$name, $type, $baseUrl, $defaultConfig]);
            } else {
                $upd = $this->db->prepare(
                    "UPDATE notification_channels
                     SET enabled = 1, updated_at = CURRENT_TIMESTAMP
                     WHERE name = ?"
                );
                $upd->execute([$name]);
            }

            $row = $this->db->prepare(
                "SELECT id, name, type, enabled, base_url,
                        last_error_at, last_error_message, created_at, updated_at
                 FROM notification_channels WHERE name = ?"
            );
            $row->execute([$name]);
            Response::success($row->fetch(\PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            Response::error('Failed to enable channel: ' . $e->getMessage(), 500);
        }
    }

    /** POST /api/notifications/channels/{type}/disable */
    public function disable(string $type): void
    {
        try {
            if (! $this->validateType($type)) {
                Response::error("Unknown channel type: {$type}", 404);
                return;
            }
            $stmt = $this->db->prepare(
                "UPDATE notification_channels
                 SET enabled = 0, updated_at = CURRENT_TIMESTAMP
                 WHERE type = ?"
            );
            $stmt->execute([$type]);
            Response::success(['updated' => $stmt->rowCount()]);
        } catch (Exception $e) {
            Response::error('Failed to disable channel: ' . $e->getMessage(), 500);
        }
    }

    /** POST /api/notifications/channels/{type}/test */
    public function test(string $type): void
    {
        try {
            if (! $this->validateType($type)) {
                Response::error("Unknown channel type: {$type}", 404);
                return;
            }

            $stmt = $this->db->prepare(
                "SELECT id, name, type, enabled, base_url, config_json
                 FROM notification_channels WHERE type = ? AND name = ?"
            );
            $stmt->execute([$type, "{$type}_primary"]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row === false) {
                Response::error('Channel not found. Enable it first.', 422);
                return;
            }
            if ((int) $row['enabled'] !== 1) {
                Response::error('Channel is disabled.', 422);
                return;
            }

            $dto = IncidentDto::fromRow([
                'id'              => 0,
                'call_id'         => 0,
                'call_number'     => 'TEST-' . date('YmdHis'),
                'call_type'       => 'TEST',
                'agency_type'     => 'TEST',
                'jurisdiction'    => null,
                'units'           => '',
                'nature_of_call'  => 'Notification test',
                'full_address'    => 'Dashboard self-test',
                'alarm_level'     => 1,
                'create_datetime' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            $ctx = new NotificationContext(
                intent: Intent::Created,
                resendAll: true,
                topicsToNotify: ['test'],
                channelConfig: [],
            );

            $channel = $this->factory->create($row);
            $results = $channel->send($dto, $ctx);

            if ($results === []) {
                Response::error('Channel returned no results', 500);
                return;
            }

            foreach ($results as $r) {
                $this->repo->recordSend((int) $row['id'], null, 'test', $r);
            }

            // Retrieve the ID of the most recently inserted log row for this channel
            $idStmt = $this->db->prepare(
                "SELECT id FROM notification_send_log WHERE channel_id = ? ORDER BY id DESC LIMIT 1"
            );
            $idStmt->execute([(int) $row['id']]);
            $idRow = $idStmt->fetch();
            $logId = $idRow ? (int) $idRow['id'] : 0;

            $first = $results[0];
            Response::success([
                'ok'          => $first->ok,
                'http_status' => $first->httpStatus,
                'duration_ms' => $first->durationMs,
                'error'       => $first->error,
                'log_id'      => $logId,
                'topic'       => $first->topic,
            ]);
        } catch (Exception $e) {
            Response::error('Failed to send test: ' . $e->getMessage(), 500);
        }
    }

    private function validateType(string $type): bool
    {
        return in_array($type, ['ntfy', 'pushover'], true);
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
