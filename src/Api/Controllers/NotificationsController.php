<?php

declare(strict_types=1);

namespace NwsCad\Api\Controllers;

use NwsCad\Api\Response;
use NwsCad\Config;
use NwsCad\Database;
use NwsCad\Notifications\ChannelFactory;
use NwsCad\Notifications\ChannelFactoryInterface;
use NwsCad\Notifications\ChannelRegistry;
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
    private ChannelFactoryInterface $factory;
    private ChannelRepository $repo;

    public function __construct(?ChannelFactoryInterface $factory = null, ?ChannelRepository $repo = null)
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
            Response::serverErrorFromException($e, 'Failed to list channels');
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

            // Aggregate child rows so multi-agency / multi-location calls
            // don't inflate the send-log row count.
            $stmt = $this->db->prepare(
                "SELECT s.id, s.channel_id, s.call_id, s.intent, s.topic, s.ok,
                        s.http_status, s.duration_ms, s.error, s.created_at,
                        c.call_number, c.nature_of_call,
                        MAX(ac.call_type) AS call_type,
                        MAX(l.full_address) AS full_address,
                        MAX(l.common_name) AS common_name
                 FROM notification_send_log s
                 LEFT JOIN calls c ON c.id = s.call_id
                 LEFT JOIN agency_contexts ac ON ac.call_id = c.id
                 LEFT JOIN locations l ON l.call_id = c.id
                 WHERE s.channel_id = ?
                 GROUP BY s.id, s.channel_id, s.call_id, s.intent, s.topic, s.ok,
                          s.http_status, s.duration_ms, s.error, s.created_at,
                          c.call_number, c.nature_of_call
                 ORDER BY s.id DESC LIMIT ?"
            );
            $stmt->bindValue(1, $channelId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            Response::success(['items' => $rows, 'channel_id' => $channelId, 'limit' => $limit]);
        } catch (Exception $e) {
            Response::serverErrorFromException($e, 'Failed to read send log');
        }
    }

    /** POST /api/notifications/channels/{type}/enable */
    public function enable(string $type): void
    {
        try {
            if (! $this->validateType($type)) {
                Response::error("Unknown channel type: {$type}", 400, [
                    'available_types' => ChannelRegistry::types(),
                ]);
                return;
            }

            $envKey  = strtoupper($type) . '_BASE_URL';
            $baseUrl = $_ENV[$envKey] ?? getenv($envKey) ?: '';

            if ($baseUrl !== '') {
                $check = \NwsCad\Security\UrlValidator::validateChannelBaseUrl(
                    $baseUrl,
                    \NwsCad\Config::getInstance()
                );
                if (! $check['ok']) {
                    Response::error("Invalid base_url: {$check['reason']}", 422);
                    return;
                }
            }

            $actor = \NwsCad\Security\Identity::current()->user;
            $name  = "{$type}_primary";

            $stmt = $this->db->prepare("SELECT id, base_url FROM notification_channels WHERE name = ?");
            $stmt->execute([$name]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing === false) {
                if ($baseUrl === '') {
                    Response::error("Missing env var: {$envKey}", 422);
                    return;
                }
                $descriptor    = ChannelRegistry::get($type);
                $defaultConfig = json_encode($descriptor->defaultConfig);

                $ins = $this->db->prepare(
                    "INSERT INTO notification_channels (name, type, enabled, base_url, config_json, last_updated_actor)
                     VALUES (?, ?, 1, ?, ?, ?)"
                );
                $ins->execute([$name, $type, $baseUrl, $defaultConfig, $actor]);
            } else {
                $upd = $this->db->prepare(
                    "UPDATE notification_channels
                     SET enabled = TRUE, updated_at = CURRENT_TIMESTAMP, last_updated_actor = ?
                     WHERE name = ?"
                );
                $upd->execute([$actor, $name]);
            }

            $row = $this->db->prepare(
                "SELECT id, name, type, enabled, base_url,
                        last_error_at, last_error_message, last_updated_actor,
                        created_at, updated_at
                 FROM notification_channels WHERE name = ?"
            );
            $row->execute([$name]);
            Response::success($row->fetch(\PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            Response::serverErrorFromException($e, 'Failed to enable channel');
        }
    }

    /** POST /api/notifications/channels/{type}/disable */
    public function disable(string $type): void
    {
        try {
            if (! $this->validateType($type)) {
                Response::error("Unknown channel type: {$type}", 400, [
                    'available_types' => ChannelRegistry::types(),
                ]);
                return;
            }
            $actor = \NwsCad\Security\Identity::current()->user;
            $stmt = $this->db->prepare(
                "UPDATE notification_channels
                 SET enabled = FALSE, updated_at = CURRENT_TIMESTAMP, last_updated_actor = ?
                 WHERE type = ?"
            );
            $stmt->execute([$actor, $type]);
            Response::success(['updated' => $stmt->rowCount()]);
        } catch (Exception $e) {
            Response::serverErrorFromException($e, 'Failed to disable channel');
        }
    }

    /** POST /api/notifications/channels/{type}/test */
    public function test(string $type): void
    {
        try {
            if (! $this->validateType($type)) {
                Response::error("Unknown channel type: {$type}", 400, [
                    'available_types' => ChannelRegistry::types(),
                ]);
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

            $actor = \NwsCad\Security\Identity::current()->user;
            if ($actor !== null) {
                $count = count($results);
                $upd = $this->db->prepare(
                    "UPDATE notification_send_log SET actor = ?
                     WHERE channel_id = ? AND id IN (
                        SELECT id FROM (
                            SELECT id FROM notification_send_log
                            WHERE channel_id = ? ORDER BY id DESC LIMIT {$count}
                        ) recent
                     )"
                );
                $upd->execute([$actor, (int) $row['id'], (int) $row['id']]);
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
            Response::serverErrorFromException($e, 'Failed to send test');
        }
    }

    /** DELETE /api/notifications/log/{id} — dismiss a single send-log row */
    public function dismissLogEntry(string $id): void
    {
        try {
            if (! ctype_digit($id)) {
                Response::error('Invalid log id', 400);
                return;
            }
            $stmt = $this->db->prepare("DELETE FROM notification_send_log WHERE id = ?");
            $stmt->execute([(int) $id]);
            if ($stmt->rowCount() === 0) {
                Response::error('Log entry not found', 404);
                return;
            }
            Response::success(['deleted' => 1, 'id' => (int) $id]);
        } catch (Exception $e) {
            Response::serverErrorFromException($e, 'Failed to dismiss log entry');
        }
    }

    /** POST /api/notifications/channels/{type}/clear-error — clear sticky channel error banner */
    public function clearChannelError(string $type): void
    {
        try {
            if (! $this->validateType($type)) {
                Response::error("Unknown channel type: {$type}", 400, [
                    'available_types' => ChannelRegistry::types(),
                ]);
                return;
            }
            $actor = \NwsCad\Security\Identity::current()->user;
            $stmt = $this->db->prepare(
                "UPDATE notification_channels
                 SET last_error_at = NULL, last_error_message = NULL,
                     updated_at = CURRENT_TIMESTAMP, last_updated_actor = ?
                 WHERE type = ?"
            );
            $stmt->execute([$actor, $type]);
            Response::success(['cleared' => $stmt->rowCount()]);
        } catch (Exception $e) {
            Response::serverErrorFromException($e, 'Failed to clear channel error');
        }
    }

    /** POST /api/notifications/log/clear-failed?channel=<id|name> — dismiss all failed rows */
    public function clearFailed(): void
    {
        try {
            $channel = $_GET['channel'] ?? $_POST['channel'] ?? null;
            if ($channel === null || $channel === '') {
                Response::error('channel parameter required', 400);
                return;
            }
            $channelId = $this->resolveChannelId((string) $channel);
            if ($channelId === null) {
                Response::error('Unknown channel', 404);
                return;
            }
            $stmt = $this->db->prepare(
                "DELETE FROM notification_send_log WHERE channel_id = ? AND ok = FALSE"
            );
            $stmt->execute([$channelId]);
            Response::success(['deleted' => $stmt->rowCount(), 'channel_id' => $channelId]);
        } catch (Exception $e) {
            Response::serverErrorFromException($e, 'Failed to clear failed entries');
        }
    }

    private function validateType(string $type): bool
    {
        return ChannelRegistry::has($type);
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
