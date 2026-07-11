<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\AegisXmlParser;
use NwsCad\Database;
use NwsCad\Notifications\EventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\AegisXmlParser
 * @uses \NwsCad\Import\DateTimeParser
 * @uses \NwsCad\Import\ValueCaster
 * @uses \NwsCad\Import\XmlLoader
 * @uses \NwsCad\Import\XmlValidator
 * @uses \NwsCad\Import\ProcessedFileRepository
 * @uses \NwsCad\Import\ReopenDetector
 * @uses \NwsCad\Db\UpsertBuilder
 * @uses \NwsCad\Api\DbHelper
 * @uses \NwsCad\Import\Mappers\LocationMapper
 * @uses \NwsCad\Import\Mappers\PersonMapper
 * @uses \NwsCad\Import\Mappers\VehicleMapper
 * @uses \NwsCad\Import\Mappers\CallDispositionMapper
 * @uses \NwsCad\Import\Mappers\AgencyContextMapper
 * @uses \NwsCad\Import\Mappers\IncidentMapper
 * @uses \NwsCad\Import\Mappers\NarrativeMapper
 * @uses \NwsCad\Import\Mappers\UnitMapper
 * @uses \NwsCad\Config
 * @uses \NwsCad\Database
 * @uses \NwsCad\FilenameParser
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 * @uses \NwsCad\Api\Filtering\FilterOptionsCache
 * @uses \NwsCad\Notifications\EventDispatcher
 * @uses \NwsCad\Notifications\Events\CallProcessedEvent
 * @uses \NwsCad\Notifications\Events\Intent
 * @uses \NwsCad\Notifications\IntentResolver
 */
final class CallStatusCorrectnessTest extends TestCase
{
    private static \PDO $db;
    /** @var string[] */
    private array $tmpFiles = [];

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = Database::getConnection();
        } catch (\Exception $e) {
            self::markTestSkipped('Database not available');
        }
    }

    protected function setUp(): void
    {
        cleanTestDatabase();
        EventDispatcher::reset();
        $this->tmpFiles = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
    }

    /**
     * Bug A: an older XML arriving in a later scan must NOT clobber the newer
     * XML's closed state. The watcher's per-scan "older versions" check doesn't
     * cover cross-scan reverse-arrival, so the parser performs the comparison.
     */
    public function testStaleFilenameSkippedSoNewerCloseStatePersists(): void
    {
        // First, ingest the NEWER XML (later filename timestamp) — this XML closes the call.
        $newerName = '163_2026050912203674.xml';
        $this->writeFixture(
            $newerName,
            $this->minimalXml(callId: 8001, closedFlag: true, closeDateTime: '2026-05-09T12:20:36')
        );
        $this->assertTrue((new AegisXmlParser())->processFile($this->path($newerName)));

        $stmt = self::$db->prepare('SELECT closed_flag, close_datetime FROM calls WHERE call_id = ?');
        $stmt->execute([8001]);
        $afterNewer = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $afterNewer['closed_flag']);
        $this->assertNotEmpty($afterNewer['close_datetime']);

        // Now feed an OLDER filename for the same call with ClosedFlag=false.
        // Without the staleness check, the older XML would overwrite closed_flag → 0.
        $olderName = '163_2026050912203171.xml';
        $this->writeFixture(
            $olderName,
            $this->minimalXml(callId: 8001, closedFlag: false, closeDateTime: '')
        );
        $this->assertTrue((new AegisXmlParser())->processFile($this->path($olderName)));

        $stmt->execute([8001]);
        $afterOlder = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $afterOlder['closed_flag'], 'older XML must not flip closed_flag back to 0');
        $this->assertNotEmpty($afterOlder['close_datetime'], 'older XML must not clear close_datetime');

        // The skipped older file should still be recorded so the watcher does not loop on it.
        $rec = self::$db->prepare('SELECT records_processed FROM processed_files WHERE filename = ?');
        $rec->execute([$olderName]);
        $this->assertSame(0, (int) $rec->fetchColumn(), 'stale skip recorded with 0 records_processed');
    }

    /**
     * Bug A negative: a filename that doesn't match the {n}_{ts}.xml pattern
     * falls through to normal processing — staleness check is opt-in.
     */
    public function testNonStandardFilenameByPasssesStalenessCheck(): void
    {
        $name = 'manual-injection.xml';
        $this->writeFixture($name, $this->minimalXml(callId: 8002));
        $this->assertTrue((new AegisXmlParser())->processFile($this->path($name)));

        $stmt = self::$db->prepare('SELECT call_id FROM calls WHERE call_id = ?');
        $stmt->execute([8002]);
        $this->assertNotFalse($stmt->fetch(), 'non-standard filename should still be processed');
    }

    /**
     * Bug C: a call that was closed and now receives an XML with a unit
     * assigned AFTER the prior close and not yet cleared is a legitimate reopen.
     */
    public function testReopenDetectedWhenLaterUnitAssignedAfterClose(): void
    {
        // Initial close: ClosedFlag=true with one cleared unit.
        $closeName = '300_2026050912000000.xml';
        $this->writeFixture(
            $closeName,
            $this->minimalXml(
                callId: 8010,
                closedFlag: true,
                closeDateTime: '2026-05-09T12:00:00',
                unitNumber: 'P1',
                unitAssigned: '2026-05-09T11:30:00',
                unitClear: '2026-05-09T12:00:00'
            )
        );
        $this->assertTrue((new AegisXmlParser())->processFile($this->path($closeName)));

        // Later: same call, NEW unit assigned after the close, no clear time, ClosedFlag=false.
        $reopenName = '300_2026050913000000.xml';
        $this->writeFixture(
            $reopenName,
            $this->minimalXml(
                callId: 8010,
                closedFlag: false,
                closeDateTime: '2026-05-09T12:00:00',
                unitNumber: 'P2',
                unitAssigned: '2026-05-09T13:00:00',
                unitClear: ''
            )
        );
        $this->assertTrue((new AegisXmlParser())->processFile($this->path($reopenName)));

        $row = $this->fetchCall(8010);
        $this->assertSame(1, (int) $row['reopened_flag'], 'unit assigned after close with no clear → reopened');
    }

    /**
     * Bug B: A closed call that receives a CAD-source-inconsistent XML (no new
     * unit activity, just ClosedFlag flipping back to false) must NOT be
     * flagged as reopened. close_datetime stays set, reopened_flag stays 0.
     */
    public function testCadSourceInconsistencyDoesNotTriggerReopen(): void
    {
        $closeName = '301_2026050912000000.xml';
        $this->writeFixture(
            $closeName,
            $this->minimalXml(
                callId: 8011,
                closedFlag: true,
                closeDateTime: '2026-05-09T12:00:00',
                unitNumber: 'P1',
                unitAssigned: '2026-05-09T11:30:00',
                unitClear: '2026-05-09T12:00:00'
            )
        );
        $this->assertTrue((new AegisXmlParser())->processFile($this->path($closeName)));

        // Inconsistent post-close XML: same single cleared unit, no new activity,
        // but ClosedFlag flipped to false at the root (the Bug B fingerprint).
        $inconsistentName = '301_2026050916000000.xml';
        $this->writeFixture(
            $inconsistentName,
            $this->minimalXml(
                callId: 8011,
                closedFlag: false,
                closeDateTime: '2026-05-09T12:00:00',
                unitNumber: 'P1',
                unitAssigned: '2026-05-09T11:30:00',
                unitClear: '2026-05-09T12:00:00'
            )
        );
        $this->assertTrue((new AegisXmlParser())->processFile($this->path($inconsistentName)));

        $row = $this->fetchCall(8011);
        $this->assertSame(0, (int) $row['reopened_flag'], 'no fresh unit activity → not a reopen');
        $this->assertNotEmpty($row['close_datetime'], 'close_datetime preserved');
    }

    /**
     * Bug C interaction: a fresh close (ClosedFlag=true) trumps any prior reopen.
     */
    public function testFreshCloseClearsReopenedFlag(): void
    {
        // Close.
        $name1 = '302_2026050912000000.xml';
        $this->writeFixture($name1, $this->minimalXml(8012, true, '2026-05-09T12:00:00', 'P1', '2026-05-09T11:30:00', '2026-05-09T12:00:00'));
        (new AegisXmlParser())->processFile($this->path($name1));

        // Reopen via new unit activity.
        $name2 = '302_2026050913000000.xml';
        $this->writeFixture($name2, $this->minimalXml(8012, false, '2026-05-09T12:00:00', 'P2', '2026-05-09T13:00:00', ''));
        (new AegisXmlParser())->processFile($this->path($name2));
        $this->assertSame(1, (int) $this->fetchCall(8012)['reopened_flag']);

        // Re-close: ClosedFlag=true again. reopened_flag must be cleared.
        $name3 = '302_2026050914000000.xml';
        $this->writeFixture($name3, $this->minimalXml(8012, true, '2026-05-09T14:00:00', 'P2', '2026-05-09T13:00:00', '2026-05-09T14:00:00'));
        (new AegisXmlParser())->processFile($this->path($name3));
        $this->assertSame(0, (int) $this->fetchCall(8012)['reopened_flag'], 'fresh close trumps prior reopen');
    }

    private function fetchCall(int $callId): array
    {
        $stmt = self::$db->prepare('SELECT closed_flag, canceled_flag, reopened_flag, close_datetime FROM calls WHERE call_id = ?');
        $stmt->execute([$callId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, "no row for call_id {$callId}");
        return $row;
    }

    private function writeFixture(string $name, string $xml): void
    {
        $path = $this->path($name);
        file_put_contents($path, $xml);
        $this->tmpFiles[] = $path;
    }

    private function path(string $name): string
    {
        return sys_get_temp_dir() . '/' . $name;
    }

    private function minimalXml(
        int $callId,
        bool $closedFlag = false,
        string $closeDateTime = '',
        string $unitNumber = 'U1',
        string $unitAssigned = '2026-05-09T10:00:00',
        string $unitClear = '',
    ): string {
        $closedStr = $closedFlag ? 'true' : 'false';
        $closeXml = $closeDateTime !== ''
            ? "<CloseDateTime>{$closeDateTime}</CloseDateTime>"
            : '<CloseDateTime/>';
        $clearXml = $unitClear !== ''
            ? "<ClearDateTime>{$unitClear}</ClearDateTime>"
            : '<ClearDateTime/>';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>{$callId}</CallId>
    <CallNumber>STATUS-{$callId}</CallNumber>
    <CreateDateTime>2026-05-09T10:00:00</CreateDateTime>
    {$closeXml}
    <ClosedFlag>{$closedStr}</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
    <AlarmLevel>1</AlarmLevel>
    <NatureOfCall>Status correctness fixture</NatureOfCall>
    <AgencyContexts>
        <AgencyContext>
            <AgencyType>Police</AgencyType>
            <CallType>Test</CallType>
        </AgencyContext>
    </AgencyContexts>
    <Location>
        <FullAddress>1 Test Lane</FullAddress>
    </Location>
    <Incidents>
        <Incident>
            <Number>INC-{$callId}</Number>
            <Jurisdiction>TST</Jurisdiction>
        </Incident>
    </Incidents>
    <AssignedUnits>
        <Unit>
            <UnitNumber>{$unitNumber}</UnitNumber>
            <AssignedDateTime>{$unitAssigned}</AssignedDateTime>
            {$clearXml}
        </Unit>
    </AssignedUnits>
</CallExport>
XML;
    }
}
