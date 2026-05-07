<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Channels;

use Mockery;
use NwsCad\Notifications\Channels\HttpPost;
use NwsCad\Notifications\Channels\PushoverChannel;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\Channels\PushoverChannel
 * @uses \NwsCad\Config
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 * @uses \NwsCad\Notifications\IncidentDto
 * @uses \NwsCad\Notifications\NotificationContext
 * @uses \NwsCad\Notifications\SendResult
 */
class PushoverChannelTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    private function dto(): IncidentDto
    {
        return IncidentDto::fromRow([
            'id' => 1, 'call_id' => 100, 'call_number' => 'C-100',
            'call_type' => 'Structure Fire',
            'full_address' => '123 Main', 'alarm_level' => 1,
            'create_datetime' => '2026-05-07 12:00:00',
            'latitude' => 39.7, 'longitude' => -86.1,
        ]);
    }

    public function testSuccessReturnsOk(): void
    {
        $http = Mockery::mock(HttpPost::class);
        $http->shouldReceive('post')
            ->once()
            ->andReturn(['status' => 200, 'body' => '{"status":1}']);

        $channel = new PushoverChannel(
            baseUrl: 'https://api.pushover.net/1/messages.json',
            token: 'tok',
            user: 'usr',
            config: [],
            http: $http,
            sleeper: fn (int $ms) => null,
        );

        $results = $channel->send($this->dto(), new NotificationContext(Intent::Created, false, [], []));

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->ok);
    }

    public function testApiStatusZeroIsTreatedAsFailure(): void
    {
        $http = Mockery::mock(HttpPost::class);
        $http->shouldReceive('post')
            ->times(3)
            ->andReturn(['status' => 200, 'body' => '{"status":0,"errors":["bad"]}']);

        $channel = new PushoverChannel(
            baseUrl: 'u', token: 't', user: 'u',
            config: [],
            http: $http,
            sleeper: fn (int $ms) => null,
        );

        $results = $channel->send($this->dto(), new NotificationContext(Intent::Created, false, [], []));

        $this->assertFalse($results[0]->ok);
    }
}
