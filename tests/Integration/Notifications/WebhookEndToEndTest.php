<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration\Notifications;

use DateTimeImmutable;
use NwsCad\Database;
use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\ChannelFactory;
use NwsCad\Notifications\ChannelFactoryInterface;
use NwsCad\Notifications\ChannelRegistry;
use NwsCad\Notifications\ChannelRepository;
use NwsCad\Notifications\Channels\WebhookChannel;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\Outbox\OutboxProcessor;
use NwsCad\Notifications\Outbox\OutboxRepository;
use NwsCad\Notifications\Outbox\OutboxWriter;
use NwsCad\Notifications\SendResult;
use NwsCad\Notifications\TopicResolver;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[UsesClass(WebhookChannel::class)]
#[UsesClass(ChannelRegistry::class)]
#[UsesClass(ChannelDescriptor::class)]
#[UsesClass(ChannelFactory::class)]
#[UsesClass(ChannelFactoryInterface::class)]
#[UsesClass(ChannelRepository::class)]
#[UsesClass(OutboxWriter::class)]
#[UsesClass(OutboxProcessor::class)]
#[UsesClass(OutboxRepository::class)]
#[UsesClass(TopicResolver::class)]
#[UsesClass(IncidentDto::class)]
#[UsesClass(SendResult::class)]
final class WebhookEndToEndTest extends TestCase
{
    private static PDO $db;

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
        if (! function_exists('socket_create_listen')) {
            $this->markTestSkipped('ext-sockets not available — needed for capture-server fixture');
        }
        cleanTestDatabase();
        ChannelRegistry::clear();
    }

    protected function tearDown(): void
    {
        ChannelRegistry::clear();
    }

    public function testProducerThenConsumerDeliversToWebhookOnce(): void
    {
        ChannelRegistry::clear();
        ChannelRegistry::register(WebhookChannel::descriptor());

        $server = $this->startCaptureServer();
        try {
            self::$db->exec("INSERT INTO calls (call_id, call_number, create_datetime) VALUES (1, 'CN-1', '2026-05-12 11:00:00')");
            $callId = (int) self::$db->lastInsertId();

            $configJson = json_encode([
                'template' => [
                    'intent'  => '{intent}',
                    'address' => '{full_address}',
                    'topics'  => '${topics}',
                ],
            ]);
            $stmt = self::$db->prepare(
                "INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
                 VALUES ('test', 'webhook', 1, ?, ?)"
            );
            $stmt->execute(["http://127.0.0.1:{$server['port']}/", $configJson]);
            $channelId = (int) self::$db->lastInsertId();

            $outboxRepo  = new OutboxRepository(self::$db);
            $channelRepo = new ChannelRepository(self::$db);
            $factory     = new ChannelFactory(\NwsCad\Config::getInstance());

            $writer = new OutboxWriter($outboxRepo, $channelRepo, 9999);
            $event = new CallProcessedEvent(
                dbCallId: $callId,
                intent: Intent::Created,
                changedFields: [],
                createDateTime: new DateTimeImmutable(),
                addedTopics: [],
            );
            $writer->handle($event);

            $incidentLoader = static fn (int $id): IncidentDto => IncidentDto::fromRow([
                'id'                   => $id,
                'call_id'              => (string) $id,
                'call_number'          => 'CN-1',
                'call_type'            => 'EMS',
                'agency_type'          => 'EMS',
                'jurisdiction'         => 'CityA',
                'units'                => 'M1',
                'common_name'          => null,
                'full_address'         => '5 Oak Lane',
                'nearest_cross_streets'=> null,
                'police_beat'          => null,
                'fire_quadrant'        => null,
                'nature_of_call'       => null,
                'narrative'            => '',
                'alarm_level'          => 1,
                'create_datetime'      => '2026-05-12T11:00:00Z',
                'latitude'             => null,
                'longitude'            => null,
            ]);

            $processor = new OutboxProcessor(
                $outboxRepo,
                $factory,
                $channelRepo,
                $incidentLoader,
                batchSize: 10, maxAttempts: 5, workerId: 'test:1:1',
            );
            $processor->tick();

            $captured = $this->readCapture($server['capturePath']);
            $this->assertCount(1, $captured, 'webhook should be POSTed exactly once');
            $decoded = json_decode($captured[0], true);
            $this->assertSame('Created', $decoded['intent']);
            $this->assertSame('5 Oak Lane', $decoded['address']);
            $this->assertSame(['EMS', 'CityA', 'M1'], $decoded['topics']);

            $status = self::$db->query("SELECT status FROM notification_outbox WHERE channel_id = {$channelId}")->fetchColumn();
            $this->assertSame('done', $status);
        } finally {
            $this->stopCaptureServer($server);
            ChannelRegistry::clear();
        }
    }

    private function startCaptureServer(): array
    {
        $port        = $this->findFreePort();
        $capturePath = tempnam(sys_get_temp_dir(), 'capture');
        $script      = tempnam(sys_get_temp_dir(), 'cap_php') . '.php';
        file_put_contents($script, "<?php\nfile_put_contents("
            . var_export($capturePath, true)
            . ", file_get_contents('php://input') . \"\\n\", FILE_APPEND);\n"
            . "header('Content-Type: text/plain'); echo 'OK';\n");

        $proc = proc_open(
            sprintf('php -S 127.0.0.1:%d %s', $port, escapeshellarg($script)),
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']],
            $pipes,
        );

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($sock !== false) { fclose($sock); break; }
            usleep(50_000);
        }
        return ['proc' => $proc, 'port' => $port, 'capturePath' => $capturePath, 'scriptPath' => $script];
    }

    private function stopCaptureServer(array $s): void
    {
        if (is_resource($s['proc'] ?? null)) {
            proc_terminate($s['proc']);
            proc_close($s['proc']);
        }
        foreach (['capturePath', 'scriptPath'] as $k) {
            if (! empty($s[$k]) && file_exists($s[$k])) {
                @unlink($s[$k]);
            }
        }
    }

    /** @return string[] */
    private function readCapture(string $path): array
    {
        $raw = @file_get_contents($path) ?: '';
        return array_values(array_filter(explode("\n", $raw)));
    }

    private function findFreePort(): int
    {
        $sock = socket_create_listen(0);
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);
        return (int) $port;
    }
}
