<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\Import\ReopenDetector;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Unit coverage for the pure ReopenDetector methods — detectReopen() and
 * snapshotIncoming(). snapshotExisting() is DB-backed and exercised through the
 * importer's reprocess/characterization integration tests.
 *
 * @covers \NwsCad\Import\ReopenDetector
 * @uses \NwsCad\Import\ValueCaster
 */
class ReopenDetectorTest extends TestCase
{
    private const NS = 'http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02';

    private function xml(string $body): SimpleXMLElement
    {
        return new SimpleXMLElement('<CallExport xmlns="' . self::NS . '">' . $body . '</CallExport>');
    }

    private function unit(string $assigned, string $clear = ''): string
    {
        $clearNode = $clear === '' ? '<ClearDateTime/>' : "<ClearDateTime>{$clear}</ClearDateTime>";
        return "<AssignedUnits><Unit><UnitNumber>E1</UnitNumber>"
            . "<AssignedDateTime>{$assigned}</AssignedDateTime>{$clearNode}</Unit></AssignedUnits>";
    }

    public function testNoReopenWhenNoExistingSnapshot(): void
    {
        $detector = new ReopenDetector();
        $this->assertFalse($detector->detectReopen($this->xml($this->unit('2026-02-01T10:00:00')), null));
    }

    public function testNoReopenWhenExistingCallWasNeverClosed(): void
    {
        $detector = new ReopenDetector();
        $snapshot = ['close_datetime' => ''];
        $this->assertFalse($detector->detectReopen($this->xml($this->unit('2026-02-01T10:00:00')), $snapshot));
    }

    public function testReopenWhenUnitAssignedAfterPriorCloseWithNoClear(): void
    {
        $detector = new ReopenDetector();
        $snapshot = ['close_datetime' => '2026-02-01 09:00:00'];
        $this->assertTrue(
            $detector->detectReopen($this->xml($this->unit('2026-02-01T09:30:00')), $snapshot),
            'a fresh unit assigned after the prior close, still active, is a reopen'
        );
    }

    public function testZSuffixOnAssignedDateTimeIsHandled(): void
    {
        $detector = new ReopenDetector();
        $snapshot = ['close_datetime' => '2026-02-01 09:00:00'];
        $this->assertTrue(
            $detector->detectReopen($this->xml($this->unit('2026-02-01T09:30:00Z')), $snapshot)
        );
    }

    public function testNoReopenWhenUnitAssignedBeforeClose(): void
    {
        $detector = new ReopenDetector();
        $snapshot = ['close_datetime' => '2026-02-01 09:00:00'];
        $this->assertFalse(
            $detector->detectReopen($this->xml($this->unit('2026-02-01T08:30:00')), $snapshot)
        );
    }

    public function testNoReopenWhenUnitAlreadyCleared(): void
    {
        $detector = new ReopenDetector();
        $snapshot = ['close_datetime' => '2026-02-01 09:00:00'];
        $this->assertFalse(
            $detector->detectReopen($this->xml($this->unit('2026-02-01T09:30:00', '2026-02-01T09:45:00')), $snapshot),
            'a unit that already cleared is not fresh activity'
        );
    }

    public function testSnapshotIncomingCapturesSalientFields(): void
    {
        $detector = new ReopenDetector();
        $xml = $this->xml(
            '<AlarmLevel>2</AlarmLevel>'
            . '<ClosedFlag>true</ClosedFlag>'
            . '<CreateDateTime>2026-02-01T08:00:00</CreateDateTime>'
            . '<Location><FullAddress>1 Main St</FullAddress></Location>'
            . '<AgencyContexts><AgencyContext><AgencyType>Fire</AgencyType><CallType>Structure Fire</CallType></AgencyContext></AgencyContexts>'
            . '<Incidents><Incident><Jurisdiction>City</Jurisdiction></Incident></Incidents>'
            . '<AssignedUnits><Unit><UnitNumber>E1</UnitNumber></Unit><Unit><UnitNumber>E1</UnitNumber></Unit></AssignedUnits>'
        );

        $snapshot = $detector->snapshotIncoming($xml);

        $this->assertSame('Structure Fire', $snapshot['call_type']);
        $this->assertSame('1 Main St', $snapshot['full_address']);
        $this->assertSame(2, $snapshot['alarm_level']);
        $this->assertSame('E1', $snapshot['units'], 'duplicate unit numbers collapse');
        $this->assertSame('City', $snapshot['jurisdictions']);
        $this->assertSame('Fire', $snapshot['agencies']);
        $this->assertTrue($snapshot['closed_flag']);
        $this->assertSame('2026-02-01T08:00:00', $snapshot['create_datetime']);
    }
}
