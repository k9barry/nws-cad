<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications;

use NwsCad\Notifications\IncidentDto;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\IncidentDto
 */
class IncidentDtoTest extends TestCase
{
    public function testFromRowMapsKnownFields(): void
    {
        $dto = IncidentDto::fromRow([
            'id' => 100,
            'call_id' => 12345,
            'call_number' => 'C-001',
            'call_type' => 'Structure Fire',
            'agency_type' => 'Fire',
            'jurisdiction' => 'MCFD',
            'units' => 'ENGINE1|TRUCK1',
            'common_name' => 'Main St Hydrant',
            'full_address' => '123 Main St',
            'nearest_cross_streets' => 'Elm / Oak',
            'police_beat' => null,
            'fire_quadrant' => 'Q1',
            'nature_of_call' => 'Smoke from second floor',
            'narrative' => 'Caller reports flames',
            'alarm_level' => '2',
            'create_datetime' => '2026-05-07 12:34:56',
            'latitude' => 39.7,
            'longitude' => -86.1,
        ]);

        $this->assertSame(100, $dto->dbCallId);
        $this->assertSame(12345, $dto->callId);
        $this->assertSame('C-001', $dto->callNumber);
        $this->assertSame('Structure Fire', $dto->callType);
        $this->assertSame('Fire', $dto->agencyType);
        $this->assertSame('MCFD', $dto->jurisdiction);
        $this->assertSame('ENGINE1|TRUCK1', $dto->units);
        $this->assertSame('Main St Hydrant', $dto->commonName);
        $this->assertSame('123 Main St', $dto->fullAddress);
        $this->assertSame('Elm / Oak', $dto->nearestCrossStreets);
        $this->assertNull($dto->policeBeat);
        $this->assertSame('Q1', $dto->fireQuadrant);
        $this->assertSame('Smoke from second floor', $dto->natureOfCall);
        $this->assertSame('Caller reports flames', $dto->narrative);
        $this->assertSame(2, $dto->alarmLevel);
        $this->assertSame('2026-05-07 12:34:56', $dto->createDateTime);
        $this->assertSame(39.7, $dto->latitude);
        $this->assertSame(-86.1, $dto->longitude);
    }

    public function testFromRowToleratesMissingOptionalFields(): void
    {
        $dto = IncidentDto::fromRow([
            'id' => 1,
            'call_id' => 2,
            'call_number' => 'X',
            'create_datetime' => '2026-01-01 00:00:00',
        ]);

        $this->assertNull($dto->callType);
        $this->assertNull($dto->latitude);
        $this->assertSame(0, $dto->alarmLevel);
        $this->assertSame('', $dto->units);
    }

    public function testGoogleMapsUrlIsBuiltOnlyWhenCoordinatesPresent(): void
    {
        $a = IncidentDto::fromRow([
            'id' => 1, 'call_id' => 2, 'call_number' => 'A',
            'create_datetime' => '2026-01-01 00:00:00',
            'latitude' => 39.7, 'longitude' => -86.1,
        ]);
        $b = IncidentDto::fromRow([
            'id' => 1, 'call_id' => 2, 'call_number' => 'A',
            'create_datetime' => '2026-01-01 00:00:00',
        ]);

        $this->assertSame('https://www.google.com/maps/dir/?api=1&destination=39.7,-86.1', $a->mapUrl());
        $this->assertNull($b->mapUrl());
    }
}
