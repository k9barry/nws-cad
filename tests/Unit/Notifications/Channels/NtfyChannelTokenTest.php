<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Channels;

use InvalidArgumentException;
use NwsCad\Notifications\Channels\HttpPut;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationContext;
use NwsCad\Notifications\Events\Intent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\Channels\NtfyChannel
 * @uses \NwsCad\Notifications\IncidentDto
 * @uses \NwsCad\Notifications\NotificationContext
 * @uses \NwsCad\Notifications\SendResult
 * @uses \NwsCad\Notifications\TopicSanitizer
 * @uses \NwsCad\Notifications\Events\Intent
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 * @uses \NwsCad\Config
 */
class NtfyChannelTokenTest extends TestCase
{
    public function testRejectsTokenContainingNewline(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NTFY auth token contains CR/LF');

        new NtfyChannel(
            baseUrl: 'https://ntfy.example',
            authToken: "tk_abc\nInjected: header",
            config: [],
        );
    }

    public function testRejectsTokenContainingCarriageReturn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NtfyChannel(
            baseUrl: 'https://ntfy.example',
            authToken: "tk_abc\rsomething",
            config: [],
        );
    }

    public function testAutoPrefixesBareToken(): void
    {
        $captured = [];
        $http = new class($captured) extends HttpPut {
            public function __construct(private array &$seen) {}
            public function put(string $url, array $headers, string $body, int $timeoutSec): array
            {
                $this->seen[] = $headers;
                return ['status' => 200, 'body' => ''];
            }
        };

        $channel = new NtfyChannel(
            baseUrl: 'https://ntfy.example',
            authToken: 'tk_abc',
            config: [],
            http: $http,
            sleeper: static fn (int $ms) => null,
        );

        $dto = IncidentDto::fromRow([
            'id' => 1, 'call_id' => 1, 'call_number' => 'X',
            'create_datetime' => '2026-05-12 12:00:00',
        ]);
        $ctx = new NotificationContext(Intent::Created, true, ['t'], []);
        $channel->send($dto, $ctx);

        $this->assertNotEmpty($captured);
        $this->assertSame('Bearer tk_abc', $captured[0]['Authorization']);
    }

    public function testPreservesBearerPrefix(): void
    {
        $captured = [];
        $http = new class($captured) extends HttpPut {
            public function __construct(private array &$seen) {}
            public function put(string $url, array $headers, string $body, int $timeoutSec): array
            {
                $this->seen[] = $headers;
                return ['status' => 200, 'body' => ''];
            }
        };

        $channel = new NtfyChannel(
            baseUrl: 'https://ntfy.example',
            authToken: 'Bearer tk_abc',
            config: [],
            http: $http,
            sleeper: static fn (int $ms) => null,
        );

        $dto = IncidentDto::fromRow([
            'id' => 1, 'call_id' => 1, 'call_number' => 'X',
            'create_datetime' => '2026-05-12 12:00:00',
        ]);
        $ctx = new NotificationContext(Intent::Created, true, ['t'], []);
        $channel->send($dto, $ctx);

        $this->assertSame('Bearer tk_abc', $captured[0]['Authorization']);
    }

    public function testPreservesBasicPrefix(): void
    {
        $captured = [];
        $http = new class($captured) extends HttpPut {
            public function __construct(private array &$seen) {}
            public function put(string $url, array $headers, string $body, int $timeoutSec): array
            {
                $this->seen[] = $headers;
                return ['status' => 200, 'body' => ''];
            }
        };

        $channel = new NtfyChannel(
            baseUrl: 'https://ntfy.example',
            authToken: 'Basic dXNlcjpwYXNz',
            config: [],
            http: $http,
            sleeper: static fn (int $ms) => null,
        );

        $dto = IncidentDto::fromRow([
            'id' => 1, 'call_id' => 1, 'call_number' => 'X',
            'create_datetime' => '2026-05-12 12:00:00',
        ]);
        $ctx = new NotificationContext(Intent::Created, true, ['t'], []);
        $channel->send($dto, $ctx);

        $this->assertSame('Basic dXNlcjpwYXNz', $captured[0]['Authorization']);
    }
}
