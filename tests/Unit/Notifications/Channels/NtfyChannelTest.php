<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Channels;

use Mockery;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\Channels\NtfyChannel
 */
class NtfyChannelTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    private function dto(): IncidentDto
    {
        return IncidentDto::fromRow([
            'id' => 1, 'call_id' => 100, 'call_number' => 'C-100',
            'call_type' => 'Structure Fire', 'agency_type' => 'Fire',
            'jurisdiction' => 'MCFD', 'units' => 'ENGINE1',
            'full_address' => '123 Main', 'alarm_level' => 2,
            'create_datetime' => '2026-05-07 12:00:00',
            'latitude' => 39.7, 'longitude' => -86.1,
        ]);
    }

    public function testSuccessfulSendReturnsOkPerTopic(): void
    {
        $http = Mockery::mock(\NwsCad\Notifications\Channels\HttpPut::class);
        $http->shouldReceive('put')->twice()->andReturn(['status' => 200, 'body' => '']);

        $channel = new NtfyChannel(
            baseUrl: 'https://ntfy.example',
            authToken: 'Bearer tok',
            config: [],
            http: $http,
            sleeper: fn (int $ms) => null,
        );

        $results = $channel->send(
            $this->dto(),
            new NotificationContext(Intent::Created, false, ['Fire', 'MCFD'], []),
        );

        $this->assertCount(2, $results);
        foreach ($results as $r) {
            $this->assertTrue($r->ok);
            $this->assertSame(200, $r->httpStatus);
        }
    }

    public function testTopicIsSanitizedAndUrlEncoded(): void
    {
        $http = Mockery::mock(\NwsCad\Notifications\Channels\HttpPut::class);
        $capturedUrl = null;
        $http->shouldReceive('put')
            ->once()
            ->with(Mockery::on(function (string $url) use (&$capturedUrl): bool {
                $capturedUrl = $url;
                return true;
            }), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(['status' => 200, 'body' => '']);

        $channel = new NtfyChannel(
            baseUrl: 'https://ntfy.example',
            authToken: 'Bearer tok',
            config: [],
            http: $http,
        );

        $channel->send(
            $this->dto(),
            new NotificationContext(Intent::Created, false, ['Fire/MCFD?x'], []),
        );

        $this->assertSame('https://ntfy.example/Fire_MCFD_x', $capturedUrl);
    }

    public function testRetriesOn5xxAndEventuallySucceeds(): void
    {
        $http = Mockery::mock(\NwsCad\Notifications\Channels\HttpPut::class);
        $http->shouldReceive('put')->times(3)->andReturn(
            ['status' => 502, 'body' => 'bad gateway'],
            ['status' => 503, 'body' => 'unavailable'],
            ['status' => 200, 'body' => 'ok'],
        );

        $channel = new NtfyChannel(
            baseUrl: 'https://ntfy.example',
            authToken: 'Bearer tok',
            config: [],
            http: $http,
            sleeper: fn (int $ms) => null,
        );

        $results = $channel->send(
            $this->dto(),
            new NotificationContext(Intent::Created, false, ['T'], []),
        );

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->ok);
    }

    public function testDoesNotRetryOn4xx(): void
    {
        $http = Mockery::mock(\NwsCad\Notifications\Channels\HttpPut::class);
        $http->shouldReceive('put')->once()->andReturn(['status' => 401, 'body' => 'unauth']);

        $channel = new NtfyChannel(
            baseUrl: 'https://ntfy.example',
            authToken: 'Bearer tok',
            config: [],
            http: $http,
            sleeper: fn (int $ms) => null,
        );

        $results = $channel->send(
            $this->dto(),
            new NotificationContext(Intent::Created, false, ['T'], []),
        );

        $this->assertFalse($results[0]->ok);
        $this->assertSame(401, $results[0]->httpStatus);
    }
}
