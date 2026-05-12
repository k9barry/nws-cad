<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use NwsCad\Notifications\Events\Intent;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\TopicResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TopicResolver::class)]
#[UsesClass(IncidentDto::class)]
#[UsesClass(Intent::class)]
final class TopicResolverTest extends TestCase
{
    public function testShouldResendAllOnCreated(): void
    {
        $this->assertTrue(TopicResolver::shouldResendAll(Intent::Created, []));
        $this->assertTrue(TopicResolver::shouldResendAll(Intent::Created, ['anything']));
    }

    public function testShouldResendAllOnUpdatedWithTriggerField(): void
    {
        $this->assertTrue(TopicResolver::shouldResendAll(Intent::Updated, ['call_type']));
        $this->assertTrue(TopicResolver::shouldResendAll(Intent::Updated, ['full_address', 'nature_of_call']));
        $this->assertTrue(TopicResolver::shouldResendAll(Intent::Updated, ['alarm_level']));
    }

    public function testShouldNotResendAllOnUpdatedWithoutTrigger(): void
    {
        $this->assertFalse(TopicResolver::shouldResendAll(Intent::Updated, []));
        $this->assertFalse(TopicResolver::shouldResendAll(Intent::Updated, ['nature_of_call', 'narrative']));
    }

    public function testResolveTopicsReturnsAllDerivedWhenResendAll(): void
    {
        $dto = IncidentDto::fromRow([
            'id' => 1, 'call_id' => 100, 'call_number' => 'C-100',
            'agency_type' => 'Fire',
            'jurisdiction' => 'IN048|IN049|IN048',
            'units' => 'E1|L2|E1',
            'alarm_level' => 1, 'create_datetime' => '2026-05-07 12:00:00',
        ]);
        $topics = TopicResolver::resolveTopics($dto, true, ['ignored']);
        $this->assertSame(['Fire', 'IN048', 'IN049', 'E1', 'L2'], $topics);
    }

    public function testResolveTopicsReturnsAddedTopicsWhenNotResendAll(): void
    {
        $dto = IncidentDto::fromRow([
            'id' => 1, 'call_id' => 100, 'call_number' => 'C-100',
            'agency_type' => 'Fire', 'jurisdiction' => 'IN048',
            'units' => 'E1', 'alarm_level' => 1,
            'create_datetime' => '2026-05-07 12:00:00',
        ]);
        $topics = TopicResolver::resolveTopics($dto, false, ['E2', '', null, 'E2']);
        $this->assertSame(['E2'], $topics);
    }

    public function testResolveTopicsReturnsEmptyWhenAllInputsBlank(): void
    {
        $dto = IncidentDto::fromRow([
            'id' => 1, 'call_id' => 100, 'call_number' => 'C-100',
            'agency_type' => null, 'jurisdiction' => '',
            'units' => '', 'alarm_level' => 1,
            'create_datetime' => '2026-05-07 12:00:00',
        ]);
        $this->assertSame([], TopicResolver::resolveTopics($dto, true, []));
        $this->assertSame([], TopicResolver::resolveTopics($dto, false, []));
    }
}
