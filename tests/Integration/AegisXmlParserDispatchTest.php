<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use DateTimeImmutable;
use NwsCad\AegisXmlParser;
use NwsCad\Database;
use NwsCad\Notifications\EventDispatcher;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\Events\Intent;
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
class AegisXmlParserDispatchTest extends TestCase
{
    private static \PDO $db;
    private string $xmlPath;

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
        $this->xmlPath = sys_get_temp_dir() . '/test-' . uniqid() . '.xml';
    }

    protected function tearDown(): void
    {
        @unlink($this->xmlPath);
    }

    public function testFirstFileEmitsCreated(): void
    {
        $captured = null;
        EventDispatcher::subscribe(function (CallProcessedEvent $e) use (&$captured) {
            $captured = $e;
        });

        file_put_contents($this->xmlPath, $this->minimalXml(callId: 999, callType: 'Medical'));

        $parser = new AegisXmlParser();
        $this->assertTrue($parser->processFile($this->xmlPath));

        $this->assertNotNull($captured);
        $this->assertSame(Intent::Created, $captured->intent);
        $this->assertSame([], $captured->changedFields);
    }

    public function testSecondFileWithCallTypeChangeEmitsUpdated(): void
    {
        $events = [];
        EventDispatcher::subscribe(function (CallProcessedEvent $e) use (&$events) {
            $events[] = $e;
        });

        file_put_contents($this->xmlPath, $this->minimalXml(999, 'Medical'));
        (new AegisXmlParser())->processFile($this->xmlPath);

        $secondPath = $this->xmlPath . '.2';
        file_put_contents($secondPath, $this->minimalXml(999, 'Structure Fire'));
        (new AegisXmlParser())->processFile($secondPath);
        @unlink($secondPath);

        $this->assertCount(2, $events);
        $this->assertSame(Intent::Updated, $events[1]->intent);
        $this->assertContains('call_type', $events[1]->changedFields);
    }

    public function testClosedFlagEmitsClosed(): void
    {
        $events = [];
        EventDispatcher::subscribe(function (CallProcessedEvent $e) use (&$events) {
            $events[] = $e;
        });

        file_put_contents($this->xmlPath, $this->minimalXml(999, 'Medical'));
        (new AegisXmlParser())->processFile($this->xmlPath);

        $secondPath = $this->xmlPath . '.2';
        file_put_contents($secondPath, $this->minimalXml(999, 'Medical', closed: true));
        (new AegisXmlParser())->processFile($secondPath);
        @unlink($secondPath);

        $this->assertSame(Intent::Closed, $events[1]->intent);
    }

    private function minimalXml(int $callId, string $callType, bool $closed = false): string
    {
        $closedFlag = $closed ? 'true' : 'false';
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>{$callId}</CallId>
    <CallNumber>TEST-{$callId}</CallNumber>
    <CreateDateTime>2026-05-07T12:00:00</CreateDateTime>
    <ClosedFlag>{$closedFlag}</ClosedFlag>
    <AlarmLevel>1</AlarmLevel>
    <NatureOfCall>Test</NatureOfCall>
    <AgencyContexts>
        <AgencyContext>
            <AgencyType>Fire</AgencyType>
            <CallType>{$callType}</CallType>
        </AgencyContext>
    </AgencyContexts>
    <Location>
        <FullAddress>123 Main</FullAddress>
    </Location>
    <Incidents>
        <Incident>
            <Number>TEST-INC-{$callId}</Number>
            <Jurisdiction>MCFD</Jurisdiction>
        </Incident>
    </Incidents>
    <AssignedUnits>
        <Unit>
            <UnitNumber>ENGINE1</UnitNumber>
        </Unit>
    </AssignedUnits>
</CallExport>
XML;
    }
}
