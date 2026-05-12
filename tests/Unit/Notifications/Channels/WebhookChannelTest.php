<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Channels;

use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\Channels\HttpPost;
use NwsCad\Notifications\Channels\WebhookChannel;
use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationContext;
use NwsCad\Notifications\SendResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookChannel::class)]
#[UsesClass(ChannelDescriptor::class)]
#[UsesClass(IncidentDto::class)]
#[UsesClass(NotificationContext::class)]
#[UsesClass(SendResult::class)]
#[UsesClass(Intent::class)]
#[UsesClass(HttpPost::class)]
final class WebhookChannelTest extends TestCase
{
    private function dto(): IncidentDto
    {
        return IncidentDto::fromRow([
            'id'              => 1,
            'call_id'         => 42,
            'call_number'     => 'CN-100',
            'call_type'       => 'STRUCT FIRE',
            'agency_type'     => 'FIRE',
            'jurisdiction'    => 'CityA|CityB',
            'units'           => 'E1|L2',
            'common_name'     => null,
            'full_address'    => '123 Main St',
            'nearest_cross_streets' => null,
            'police_beat'     => null,
            'fire_quadrant'   => null,
            'nature_of_call'  => null,
            'narrative'       => 'large flames visible',
            'alarm_level'     => 2,
            'create_datetime' => '2026-05-12T10:00:00Z',
            'latitude'        => null,
            'longitude'       => null,
        ]);
    }

    private function context(): NotificationContext
    {
        return new NotificationContext(
            intent: Intent::Created,
            resendAll: true,
            topicsToNotify: ['FIRE', 'E1', 'L2'],
            channelConfig: [],
        );
    }

    public function testStringPlaceholderSubstitution(): void
    {
        $payload = WebhookChannel::buildPayload(
            template: ['text' => '{intent}: {call_type} at {full_address}'],
            dto: $this->dto(),
            context: $this->context(),
        );
        $this->assertSame(
            '{"text":"Created: STRUCT FIRE at 123 Main St"}',
            $payload,
        );
    }

    public function testRawArrayPlaceholderSubstitution(): void
    {
        $payload = WebhookChannel::buildPayload(
            template: ['topics' => '${topics}'],
            dto: $this->dto(),
            context: $this->context(),
        );
        $this->assertSame(
            '{"topics":["FIRE","E1","L2"]}',
            $payload,
        );
    }

    public function testUnknownPlaceholderPassesThrough(): void
    {
        $payload = WebhookChannel::buildPayload(
            template: ['text' => 'hi {unknown_thing}'],
            dto: $this->dto(),
            context: $this->context(),
        );
        $this->assertStringContainsString('{unknown_thing}', $payload);
    }

    public function testRawUnitsAndJurisdictionSplit(): void
    {
        $payload = WebhookChannel::buildPayload(
            template: ['u' => '${units}', 'j' => '${jurisdiction}'],
            dto: $this->dto(),
            context: $this->context(),
        );
        $decoded = json_decode($payload, true);
        $this->assertSame(['E1', 'L2'], $decoded['u']);
        $this->assertSame(['CityA', 'CityB'], $decoded['j']);
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('webhook: template required');

        new WebhookChannel(
            baseUrl: 'https://example.test/hook',
            config: ['template' => null],
        );
    }

    public function testCrLfInAuthTokenRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CR/LF');

        new WebhookChannel(
            baseUrl: 'https://example.test/hook',
            config: ['template' => ['text' => 'x']],
            authToken: "ok\r\nInjected: header",
        );
    }
}
