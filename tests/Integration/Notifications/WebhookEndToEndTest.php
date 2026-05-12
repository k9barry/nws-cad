<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration\Notifications;

use DateTimeImmutable;
use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\ChannelFactory;
use NwsCad\Notifications\ChannelRegistry;
use NwsCad\Notifications\Channels\WebhookChannel;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationDispatcher;
use NwsCad\Notifications\SendResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[UsesClass(WebhookChannel::class)]
#[UsesClass(ChannelRegistry::class)]
#[UsesClass(ChannelDescriptor::class)]
#[UsesClass(ChannelFactory::class)]
#[UsesClass(NotificationDispatcher::class)]
#[UsesClass(IncidentDto::class)]
#[UsesClass(SendResult::class)]
final class WebhookEndToEndTest extends TestCase
{
    public function testDispatcherDeliversToWebhookOnce(): void
    {
        ChannelRegistry::clear();
        ChannelRegistry::register(WebhookChannel::descriptor());

        $server = $this->startCaptureServer();
        try {
            $row = [
                'id'          => 1,
                'name'        => 'test',
                'type'        => 'webhook',
                'enabled'     => 1,
                'base_url'    => "http://127.0.0.1:{$server['port']}/",
                'config_json' => json_encode([
                    'template' => [
                        'intent'  => '{intent}',
                        'address' => '{full_address}',
                        'topics'  => '${topics}',
                    ],
                ]),
                'last_error'  => null,
            ];

            $repo = $this->channelRepoWithRows([$row]);
            $factory = new ChannelFactory(\NwsCad\Config::getInstance());

            $dispatcher = new NotificationDispatcher(
                channelRepo: $repo,
                incidentLoader: fn (int $id): IncidentDto => IncidentDto::fromRow([
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
                ]),
                channelFactory: fn (array $r) => $factory->create($r),
                deltaSeconds: 9999,
            );

            $event = new CallProcessedEvent(
                dbCallId: 1,
                intent: Intent::Created,
                changedFields: [],
                createDateTime: new DateTimeImmutable(),
                addedTopics: [],
            );
            $dispatcher->handle($event);

            $captured = $this->readCapture($server['capturePath']);
            $this->assertCount(1, $captured, 'webhook should be POSTed exactly once');
            $decoded = json_decode($captured[0], true);
            $this->assertSame('Created', $decoded['intent']);
            $this->assertSame('5 Oak Lane', $decoded['address']);
            $this->assertSame(['EMS', 'CityA', 'M1'], $decoded['topics']);
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

    private function channelRepoWithRows(array $rows): \NwsCad\Notifications\ChannelRepositoryInterface
    {
        return new class($rows) implements \NwsCad\Notifications\ChannelRepositoryInterface {
            public function __construct(private array $rows) {}
            public function listEnabled(): array { return $this->rows; }
            public function recordSend(int $channelId, ?int $callId, ?string $intent, SendResult $result): void {}
            public function markFailure(int $channelId, string $message): void {}
        };
    }
}
