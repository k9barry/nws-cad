<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use DateTimeImmutable;
use NwsCad\Api\Controllers\OutboxController;
use NwsCad\Api\Response;
use NwsCad\Database;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\Outbox\OutboxRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Api\Controllers\OutboxController
 * @uses \NwsCad\Api\Response
 * @uses \NwsCad\Database
 * @uses \NwsCad\Config
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 * @uses \NwsCad\Notifications\Outbox\OutboxRepository
 * @uses \NwsCad\Notifications\Events\Intent
 */
class OutboxControllerTest extends TestCase
{
    private static PDO $db;
    private OutboxRepository $repo;
    private int $callId;
    private int $channelId;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = Database::getConnection();
        } catch (\Throwable $e) {
            self::markTestSkipped('Database not available');
        }
    }

    protected function setUp(): void
    {
        cleanTestDatabase();
        Response::resetForTesting();
        $this->repo = new OutboxRepository(self::$db);

        self::$db->exec("INSERT INTO calls (call_id, call_number, create_datetime) VALUES (1, 'C-1', '2026-05-07 12:00:00')");
        $this->callId = (int) self::$db->lastInsertId();

        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json) VALUES ('ntfy_primary', 'ntfy', 1, 'https://x', '{}')");
        $this->channelId = (int) self::$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
    }

    private function insertPending(): int
    {
        return $this->repo->insert(
            callId:         $this->callId,
            channelId:      $this->channelId,
            intent:         Intent::Created,
            resendAll:      true,
            addedTopics:    [],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );
    }

    public function testIndexReturnsPendingByDefault(): void
    {
        $id = $this->insertPending();

        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->index();
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame('pending', $payload['data']['status']);
        $this->assertCount(1, $payload['data']['items']);
        $this->assertSame($id, (int) $payload['data']['items'][0]['id']);
        $this->assertSame('ntfy_primary', $payload['data']['items'][0]['channel_name']);
    }

    public function testIndexHonorsStatusFilter(): void
    {
        $pending = $this->insertPending();
        $failed  = $this->insertPending();
        $this->repo->markFailed($failed, 5, 'retries exhausted');

        $_GET['status'] = 'failed';
        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->index();
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success']);
        $this->assertCount(1, $payload['data']['items']);
        $this->assertSame($failed, (int) $payload['data']['items'][0]['id']);
    }

    public function testIndexRejectsUnknownStatus(): void
    {
        $_GET['status'] = 'bogus';
        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->index();
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertFalse($payload['success']);
        $this->assertSame(400, http_response_code());
        $this->assertContains('pending', $payload['errors']['allowed_statuses']);
    }

    public function testIndexClampsLimit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->insertPending();
        }
        $_GET['limit'] = '99999';
        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->index();
        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame(200, (int) $payload['data']['limit']);
    }

    public function testRetryResetsRowToPending(): void
    {
        $id = $this->insertPending();
        $this->repo->markFailed($id, 5, 'retries exhausted');

        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->retry((string) $id);
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame($id, (int) $payload['data']['id']);

        $row = self::$db->query("SELECT status, attempts FROM notification_outbox WHERE id={$id}")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
    }

    public function testRetryReturns400ForNonNumericId(): void
    {
        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->retry('not-a-number');
        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame(400, http_response_code());
    }

    public function testRetryReturns404ForMissingId(): void
    {
        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->retry('99999');
        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame(404, http_response_code());
    }

    public function testDismissDeletesRow(): void
    {
        $id = $this->insertPending();

        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->dismiss((string) $id);
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success']);
        $remaining = (int) self::$db->query("SELECT COUNT(*) FROM notification_outbox WHERE id={$id}")->fetchColumn();
        $this->assertSame(0, $remaining);
    }

    public function testDismissReturns404ForMissingId(): void
    {
        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->dismiss('99999');
        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame(404, http_response_code());
    }

    public function testClearDoneBulkDeletes(): void
    {
        $d1 = $this->insertPending();
        $d2 = $this->insertPending();
        $pending = $this->insertPending();
        $this->repo->markDone($d1);
        $this->repo->markDone($d2);

        $_GET['status'] = 'done';
        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->clear();
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame(2, $payload['data']['deleted']);
        $remaining = (int) self::$db->query("SELECT COUNT(*) FROM notification_outbox")->fetchColumn();
        $this->assertSame(1, $remaining);
    }

    public function testClearRejectsBulkClearForPendingStatus(): void
    {
        $_GET['status'] = 'pending';
        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->clear();
        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame(400, http_response_code());
    }

    public function testShowReturnsRowAndHistory(): void
    {
        $id = $this->insertPending();
        self::$db->exec("INSERT INTO notification_send_log (channel_id, call_id, intent, topic, ok, http_status, duration_ms, error) VALUES ({$this->channelId}, {$this->callId}, 'Created', 't1', 0, 503, 99, 'HTTP 503')");

        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->show((string) $id);
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame($id, (int) $payload['data']['row']['id']);
        $this->assertSame('ntfy_primary', $payload['data']['row']['channel_name']);
        $this->assertCount(1, $payload['data']['history']);
        $this->assertSame('HTTP 503', $payload['data']['history'][0]['error']);
    }

    public function testShowReturns400ForNonNumericId(): void
    {
        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->show('abc');
        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame(400, http_response_code());
    }

    public function testShowReturns404ForMissingId(): void
    {
        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->show('99999');
        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame(404, http_response_code());
    }

    public function testScheduleUpdatesNextAttemptForFailedRow(): void
    {
        $id = $this->insertPending();
        $this->repo->markFailed($id, 5, 'retries exhausted');

        $_POST['next_attempt_at'] = '2026-05-08 09:00:00';
        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->schedule((string) $id);
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame('2026-05-08 09:00:00', $payload['data']['next_attempt_at']);

        $row = self::$db->query("SELECT status, next_attempt_at, attempts FROM notification_outbox WHERE id={$id}")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('2026-05-08 09:00:00', $row['next_attempt_at']);
        $this->assertSame(5, (int) $row['attempts']);
    }

    public function testScheduleReturns400OnMissingBody(): void
    {
        $id = $this->insertPending();

        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->schedule((string) $id);
        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame(400, http_response_code());
    }

    public function testScheduleReturns400OnInvalidDateTime(): void
    {
        $id = $this->insertPending();
        $_POST['next_attempt_at'] = 'not a date';

        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->schedule((string) $id);
        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame(400, http_response_code());
    }

    public function testScheduleReturns400OnNonNumericId(): void
    {
        $_POST['next_attempt_at'] = '2026-05-08 09:00:00';
        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->schedule('abc');
        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame(400, http_response_code());
    }

    public function testScheduleReturns404OnInFlightRow(): void
    {
        $id = $this->insertPending();
        self::$db->exec("UPDATE notification_outbox SET status='in_flight', claimed_by='w:1', claimed_at=NOW() WHERE id={$id}");

        $_POST['next_attempt_at'] = '2026-05-08 09:00:00';
        $controller = new OutboxController($this->repo);
        ob_start();
        $controller->schedule((string) $id);
        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame(404, http_response_code());
    }
}
