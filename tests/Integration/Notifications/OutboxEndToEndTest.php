<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration\Notifications;

use DateTimeImmutable;
use NwsCad\Config;
use NwsCad\Database;
use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\ChannelFactory;
use NwsCad\Notifications\ChannelFactoryInterface;
use NwsCad\Notifications\ChannelRegistry;
use NwsCad\Notifications\ChannelRepository;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationChannel;
use NwsCad\Notifications\NotificationContext;
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
#[UsesClass(OutboxWriter::class)]
#[UsesClass(OutboxProcessor::class)]
#[UsesClass(OutboxRepository::class)]
#[UsesClass(ChannelRepository::class)]
#[UsesClass(ChannelFactory::class)]
#[UsesClass(ChannelFactoryInterface::class)]
#[UsesClass(ChannelRegistry::class)]
#[UsesClass(ChannelDescriptor::class)]
#[UsesClass(TopicResolver::class)]
#[UsesClass(IncidentDto::class)]
#[UsesClass(NotificationContext::class)]
#[UsesClass(SendResult::class)]
#[UsesClass(Intent::class)]
#[UsesClass(CallProcessedEvent::class)]
#[UsesClass(Config::class)]
final class OutboxEndToEndTest extends TestCase
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
        cleanTestDatabase();
        ChannelRegistry::clear();
        ChannelRegistry::register(new ChannelDescriptor(
            type: 'stub', label: 'stub', baseUrlEnv: 'STUB_URL',
            requiredEnvs: [], defaultConfig: [],
            factory: static function (array $row, Config $cfg): NotificationChannel {
                return new class implements NotificationChannel {
                    public static function descriptor(): ChannelDescriptor
                    {
                        return new ChannelDescriptor(
                            type: 'stub', label: 'stub', baseUrlEnv: 'STUB_URL',
                            requiredEnvs: [], defaultConfig: [],
                            factory: static fn (array $r, Config $c) => throw new \LogicException('test stub'),
                        );
                    }
                    public function send(IncidentDto $dto, NotificationContext $ctx): array
                    {
                        return [SendResult::ok(200, 5, $ctx->topicsToNotify[0] ?? null)];
                    }
                };
            },
        ));
    }

    protected function tearDown(): void
    {
        ChannelRegistry::clear();
    }

    public function testProducerThenConsumerDeliversAndMarksDone(): void
    {
        self::$db->exec("INSERT INTO calls (call_id, call_number, create_datetime) VALUES (1, 'C-1', '2026-05-07 12:00:00')");
        $callId = (int) self::$db->lastInsertId();
        self::$db->exec("INSERT INTO agency_contexts (call_id, agency_type, call_type) VALUES ({$callId}, 'Fire', 'STRUCT')");
        self::$db->exec("INSERT INTO incidents (call_id, incident_number, jurisdiction) VALUES ({$callId}, 'INC-1', 'IN048')");
        self::$db->exec("INSERT INTO units (call_id, unit_number) VALUES ({$callId}, 'E1')");

        self::$db->exec("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
                         VALUES ('stub_primary', 'stub', TRUE, 'https://stub.example', '{}')");
        $channelId = (int) self::$db->lastInsertId();

        $outboxRepo  = new OutboxRepository(self::$db);
        $channelRepo = new ChannelRepository(self::$db);
        $factory     = new ChannelFactory(Config::getInstance());

        $writer = new OutboxWriter($outboxRepo, $channelRepo, 900,
            static fn () => new DateTimeImmutable('2026-05-07 12:01:00'));
        $event = new CallProcessedEvent(
            dbCallId: $callId, intent: Intent::Created, changedFields: [],
            createDateTime: new DateTimeImmutable('2026-05-07 12:00:00'),
        );
        $writer->handle($event);

        $outboxRow = self::$db->query("SELECT * FROM notification_outbox")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($outboxRow);
        $this->assertSame('pending', $outboxRow['status']);
        $this->assertSame($channelId, (int) $outboxRow['channel_id']);

        $incidentLoader = static fn (int $id): IncidentDto => IncidentDto::fromRow([
            'id' => $id, 'call_id' => 1, 'call_number' => 'C-1',
            'agency_type' => 'Fire', 'jurisdiction' => 'IN048', 'units' => 'E1',
            'alarm_level' => 1, 'create_datetime' => '2026-05-07 12:00:00',
        ]);

        $processor = new OutboxProcessor(
            $outboxRepo, $factory, $channelRepo,
            $incidentLoader,
            batchSize: 10, maxAttempts: 5, workerId: 'test:1:1',
        );
        $processor->tick();

        $after = self::$db->query("SELECT status FROM notification_outbox")->fetchColumn();
        $this->assertSame('done', $after);

        $logCount = (int) self::$db->query("SELECT COUNT(*) FROM notification_send_log")->fetchColumn();
        $this->assertSame(1, $logCount);
    }
}
