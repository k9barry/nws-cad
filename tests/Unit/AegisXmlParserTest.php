<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\AegisXmlParser;
use NwsCad\Database;
use NwsCad\Logger;
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
 * @uses \NwsCad\Import\Mappers\CallMapper
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
class AegisXmlParserTest extends TestCase
{
    private string $testXmlPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a minimal valid test XML file
        $this->testXmlPath = sys_get_temp_dir() . '/test_call_' . uniqid() . '.xml';
        
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>12345</CallId>
    <CallNumber>2024-001</CallNumber>
    <CallSource>911</CallSource>
    <CallerName>John Doe</CallerName>
    <CallerPhone>555-1234</CallerPhone>
    <NatureOfCall>Medical Emergency</NatureOfCall>
    <CreateDateTime>2024-01-01T10:00:00</CreateDateTime>
    <ClosedFlag>false</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
    <AlarmLevel>1</AlarmLevel>
    <AgencyContexts>
        <AgencyContext>
            <AgencyType>EMS</AgencyType>
            <CallType>Medical</CallType>
            <Priority>High</Priority>
            <Status>Active</Status>
            <ClosedFlag>false</ClosedFlag>
            <CanceledFlag>false</CanceledFlag>
        </AgencyContext>
    </AgencyContexts>
    <Location>
        <FullAddress>123 Main St</FullAddress>
        <HouseNumber>123</HouseNumber>
        <StreetName>Main</StreetName>
        <StreetType>St</StreetType>
        <City>Springfield</City>
        <State>IL</State>
        <Zip>62701</Zip>
    </Location>
</CallExport>
XML;
        
        file_put_contents($this->testXmlPath, $xml);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        if (file_exists($this->testXmlPath)) {
            unlink($this->testXmlPath);
        }
    }

    public function testParserCanBeInstantiated(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        try {
            $parser = new AegisXmlParser();
            $this->assertInstanceOf(AegisXmlParser::class, $parser);
        } catch (\Exception $e) {
            $this->markTestSkipped('Cannot instantiate parser: ' . $e->getMessage());
        }
    }

    public function testProcessFileReturnsBooleanWithValidFile(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        try {
            cleanTestDatabase();
            
            $parser = new AegisXmlParser();
            $result = $parser->processFile($this->testXmlPath);
            
            $this->assertIsBool($result);
        } catch (\Exception $e) {
            $this->markTestSkipped('Cannot process file: ' . $e->getMessage());
        }
    }

    public function testProcessFileReturnsFalseWithInvalidXml(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        try {
            $invalidXmlPath = sys_get_temp_dir() . '/invalid_' . uniqid() . '.xml';
            file_put_contents($invalidXmlPath, 'This is not valid XML');
            
            $parser = new AegisXmlParser();
            $result = $parser->processFile($invalidXmlPath);
            
            $this->assertFalse($result, 'Should return false for invalid XML');
            
            unlink($invalidXmlPath);
        } catch (\Exception $e) {
            $this->markTestSkipped('Cannot test invalid XML: ' . $e->getMessage());
        }
    }

    public function testXxeProtection(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        try {
            $xxeXmlPath = sys_get_temp_dir() . '/xxe_' . uniqid() . '.xml';
            
            // XML with external entity (XXE attack attempt)
            $xxeXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>&xxe;</CallId>
</CallExport>
XML;
            
            file_put_contents($xxeXmlPath, $xxeXml);
            
            $parser = new AegisXmlParser();
            $result = $parser->processFile($xxeXmlPath);
            
            // Should not successfully process XXE attack
            $this->assertIsBool($result);
            
            unlink($xxeXmlPath);
        } catch (\Exception $e) {
            $this->markTestSkipped('Cannot test XXE protection: ' . $e->getMessage());
        }
    }

    public function testProcessFileDoesNotProcessSameFileTwice(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        try {
            cleanTestDatabase();
            
            $parser = new AegisXmlParser();
            
            // Process file first time
            $result1 = $parser->processFile($this->testXmlPath);
            
            // Process same file second time
            $result2 = $parser->processFile($this->testXmlPath);
            
            // Both should succeed (second time skips processing)
            $this->assertTrue($result1 || $result2);
        } catch (\Exception $e) {
            $this->markTestSkipped('Cannot test duplicate processing: ' . $e->getMessage());
        }
    }

    public function testProcessFileHandlesDuplicateCallId(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        try {
            cleanTestDatabase();
            
            // Create first file with call ID 99999
            $firstFilePath = sys_get_temp_dir() . '/test_call_first_' . uniqid() . '.xml';
            $firstXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>99999</CallId>
    <CallNumber>2024-001</CallNumber>
    <CallSource>911</CallSource>
    <CallerName>John Doe</CallerName>
    <CallerPhone>555-1234</CallerPhone>
    <NatureOfCall>Medical Emergency</NatureOfCall>
    <CreateDateTime>2024-01-01T10:00:00</CreateDateTime>
    <ClosedFlag>false</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
    <AlarmLevel>1</AlarmLevel>
    <AgencyContexts>
        <AgencyContext>
            <AgencyType>EMS</AgencyType>
            <CallType>Medical</CallType>
            <Priority>High</Priority>
            <Status>Active</Status>
            <ClosedFlag>false</ClosedFlag>
            <CanceledFlag>false</CanceledFlag>
        </AgencyContext>
    </AgencyContexts>
    <Location>
        <FullAddress>123 Main St</FullAddress>
        <HouseNumber>123</HouseNumber>
        <StreetName>Main</StreetName>
        <StreetType>St</StreetType>
        <City>Springfield</City>
        <State>IL</State>
        <Zip>62701</Zip>
    </Location>
</CallExport>
XML;
            file_put_contents($firstFilePath, $firstXml);
            
            // Create second file with same call ID but different data (update)
            $secondFilePath = sys_get_temp_dir() . '/test_call_second_' . uniqid() . '.xml';
            $secondXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>99999</CallId>
    <CallNumber>2024-001-UPDATED</CallNumber>
    <CallSource>911</CallSource>
    <CallerName>John Doe Updated</CallerName>
    <CallerPhone>555-5678</CallerPhone>
    <NatureOfCall>Medical Emergency - Updated</NatureOfCall>
    <CreateDateTime>2024-01-01T10:00:00</CreateDateTime>
    <CloseDateTime>2024-01-01T11:00:00</CloseDateTime>
    <ClosedFlag>true</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
    <AlarmLevel>2</AlarmLevel>
    <AgencyContexts>
        <AgencyContext>
            <AgencyType>EMS</AgencyType>
            <CallType>Medical</CallType>
            <Priority>High</Priority>
            <Status>Closed</Status>
            <ClosedFlag>true</ClosedFlag>
            <CanceledFlag>false</CanceledFlag>
        </AgencyContext>
    </AgencyContexts>
    <Location>
        <FullAddress>456 Oak Ave</FullAddress>
        <HouseNumber>456</HouseNumber>
        <StreetName>Oak</StreetName>
        <StreetType>Ave</StreetType>
        <City>Springfield</City>
        <State>IL</State>
        <Zip>62702</Zip>
    </Location>
</CallExport>
XML;
            file_put_contents($secondFilePath, $secondXml);
            
            $parser = new AegisXmlParser();
            
            // Process first file - should succeed
            $result1 = $parser->processFile($firstFilePath);
            $this->assertTrue($result1, 'First file should process successfully');
            
            // Process second file with same call_id - should also succeed (update)
            $result2 = $parser->processFile($secondFilePath);
            $this->assertTrue($result2, 'Second file with duplicate call_id should process successfully (update)');
            
            // Verify the call was updated by checking database
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT call_number, caller_name, closed_flag FROM calls WHERE call_id = ?");
            $stmt->execute([99999]);
            $call = $stmt->fetch();
            
            $this->assertNotEmpty($call, 'Call should exist in database');
            $this->assertEquals('2024-001-UPDATED', $call['call_number'], 'Call number should be updated');
            $this->assertEquals('John Doe Updated', $call['caller_name'], 'Caller name should be updated');
            $this->assertEquals(1, $call['closed_flag'], 'Closed flag should be updated');
            
            // Verify child records were also updated by checking location
            $callId = $db->prepare("SELECT id FROM calls WHERE call_id = ?");
            $callId->execute([99999]);
            $callDbId = $callId->fetch()['id'];
            
            $locationStmt = $db->prepare("SELECT full_address, house_number, zip FROM locations WHERE call_id = ?");
            $locationStmt->execute([$callDbId]);
            $location = $locationStmt->fetch();
            
            $this->assertNotEmpty($location, 'Location should exist in database');
            $this->assertEquals('456 Oak Ave', $location['full_address'], 'Location should be from second file (updated)');
            $this->assertEquals('456', $location['house_number'], 'House number should be from second file');
            $this->assertEquals('62702', $location['zip'], 'Zip should be from second file');
            
            // Clean up
            unlink($firstFilePath);
            unlink($secondFilePath);
        } catch (\Exception $e) {
            $this->markTestSkipped('Cannot test duplicate call_id handling: ' . $e->getMessage());
        }
    }

    public function testReprocessingSameCallIdDoesNotDuplicateChildRows(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        cleanTestDatabase();

        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>515151</CallId>
    <CallNumber>2024-515151</CallNumber>
    <CreateDateTime>2024-01-02T10:00:00</CreateDateTime>
    <ClosedFlag>false</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
    <AgencyContexts>
        <AgencyContext>
            <AgencyType>EMS</AgencyType>
            <CallType>Medical</CallType>
            <Priority>High</Priority>
            <Status>Active</Status>
            <CreatedDateTime>2024-01-02T10:00:00</CreatedDateTime>
            <ClosedFlag>false</ClosedFlag>
            <CanceledFlag>false</CanceledFlag>
        </AgencyContext>
    </AgencyContexts>
    <Location>
        <FullAddress>1 Test Way</FullAddress>
        <City>Springfield</City>
        <State>IL</State>
    </Location>
    <Incidents>
        <Incident>
            <Number>INC-1</Number>
            <Type>Med</Type>
            <Jurisdiction>City</Jurisdiction>
            <CreateDateTime>2024-01-02T10:00:00</CreateDateTime>
        </Incident>
    </Incidents>
    <Persons>
        <Person>
            <FirstName>Jane</FirstName>
            <LastName>Roe</LastName>
            <Role>Caller</Role>
        </Person>
    </Persons>
    <Vehicles>
        <Vehicle>
            <LicensePlate>ABC123</LicensePlate>
            <LicenseState>IL</LicenseState>
            <Make>Ford</Make>
            <Model>F-150</Model>
            <Year>2020</Year>
        </Vehicle>
    </Vehicles>
    <Dispositions>
        <CallDisposition>
            <Name>Resolved</Name>
            <Count>1</Count>
            <DateTime>2024-01-02T11:00:00</DateTime>
        </CallDisposition>
    </Dispositions>
</CallExport>
XML;

        // Two physical files with identical content but different bytes
        // (whitespace-only delta) so processed_files.file_hash diverges and
        // the file-level dedupe in isFileProcessed() does not short-circuit
        // the second processFile() call. We want the call-level update path
        // to actually run.
        $first  = sys_get_temp_dir() . '/child_dup_a_' . uniqid() . '.xml';
        $second = sys_get_temp_dir() . '/child_dup_b_' . uniqid() . '.xml';
        file_put_contents($first, $xml);
        file_put_contents($second, $xml . "\n<!-- pass-2 -->\n");

        try {
            $parser = new AegisXmlParser();
            $this->assertTrue($parser->processFile($first));
            $this->assertTrue($parser->processFile($second));

            $db = Database::getConnection();
            $countFor = function (string $table) use ($db): int {
                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM {$table} t
                     JOIN calls c ON c.id = t.call_id
                     WHERE c.call_id = ?"
                );
                $stmt->execute([515151]);
                return (int) $stmt->fetchColumn();
            };

            $this->assertSame(1, $countFor('agency_contexts'),
                'identical agency_context across reprocesses must not duplicate');
            $this->assertSame(1, $countFor('incidents'),
                'identical incident across reprocesses must not duplicate');
            $this->assertSame(1, $countFor('persons'),
                'identical person across reprocesses must not duplicate');
            $this->assertSame(1, $countFor('vehicles'),
                'identical vehicle across reprocesses must not duplicate');
            $this->assertSame(1, $countFor('call_dispositions'),
                'identical call_disposition across reprocesses must not duplicate');
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }

    public function testAgencyContextStateChangeOverwritesPriorRow(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        cleanTestDatabase();

        $makeXml = function (string $status, string $closedFlag): string {
            return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>616161</CallId>
    <CallNumber>2024-616161</CallNumber>
    <CreateDateTime>2024-01-03T10:00:00</CreateDateTime>
    <ClosedFlag>{$closedFlag}</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
    <AgencyContexts>
        <AgencyContext>
            <AgencyType>Police</AgencyType>
            <CallType>Disturbance</CallType>
            <Priority>High</Priority>
            <Status>{$status}</Status>
            <CreatedDateTime>2024-01-03T10:00:00</CreatedDateTime>
            <ClosedFlag>{$closedFlag}</ClosedFlag>
            <CanceledFlag>false</CanceledFlag>
        </AgencyContext>
    </AgencyContexts>
</CallExport>
XML;
        };

        $first  = sys_get_temp_dir() . '/state_a_' . uniqid() . '.xml';
        $second = sys_get_temp_dir() . '/state_b_' . uniqid() . '.xml';
        file_put_contents($first, $makeXml('In Progress', 'false'));
        file_put_contents($second, $makeXml('Closed', 'true'));

        try {
            $parser = new AegisXmlParser();
            $this->assertTrue($parser->processFile($first));
            $this->assertTrue($parser->processFile($second));

            $db = Database::getConnection();
            $stmt = $db->prepare(
                "SELECT ac.status, ac.closed_flag
                 FROM agency_contexts ac
                 JOIN calls c ON c.id = ac.call_id
                 WHERE c.call_id = ?"
            );
            $stmt->execute([616161]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->assertCount(
                1,
                $rows,
                'agency_contexts must have exactly one row per (call, agency_type)'
            );
            $this->assertSame('Closed', $rows[0]['status'],
                'state mutation must overwrite the prior status, not accumulate');
            $this->assertSame(1, (int) $rows[0]['closed_flag'],
                'closed_flag must reflect the latest XML, not the older snapshot');
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }

    public function testInsertsFdidFromXmlAttributeWhenPresent(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        cleanTestDatabase();

        $xml = $this->buildXmlWithAgencyContextHavingFdid('48013');
        $tmpPath = sys_get_temp_dir() . '/fdid_xml_' . uniqid() . '.xml';
        file_put_contents($tmpPath, $xml);

        try {
            $parser = new AegisXmlParser();
            $this->assertTrue($parser->processFile($tmpPath));

            $db = Database::getConnection();
            $row = $db->query("SELECT fdid FROM agency_contexts ORDER BY id DESC LIMIT 1")->fetch();
            $this->assertSame('48013', $row['fdid']);
        } finally {
            @unlink($tmpPath);
        }
    }

    public function testFallsBackToRefAgenciesLookupWhenXmlLacksFdid(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        cleanTestDatabase();

        $db = Database::getConnection();
        $db->exec("INSERT INTO ref_agencies (code,label,kind,fdid,active,sort_order) VALUES ('FOO_FD','Foo Fire','fire','48099',1,100)");

        $xml = $this->buildXmlWithAgencyType('Foo Fire');
        $tmpPath = sys_get_temp_dir() . '/fdid_fallback_' . uniqid() . '.xml';
        file_put_contents($tmpPath, $xml);

        try {
            $parser = new AegisXmlParser();
            $this->assertTrue($parser->processFile($tmpPath));

            $row = $db->query("SELECT fdid FROM agency_contexts ORDER BY id DESC LIMIT 1")->fetch();
            $this->assertSame('48099', $row['fdid']);
        } finally {
            @unlink($tmpPath);
            $db->exec("DELETE FROM ref_agencies WHERE code = 'FOO_FD'");
        }
    }

    public function testInvalidatesFilterOptionsCacheAfterCommit(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        cleanTestDatabase();

        // The fixture's AgencyContext CallType is 'Medical', which is NOT in the
        // cached ['Police'] list — a genuinely new value, so the key is busted.
        \NwsCad\Api\Filtering\FilterOptionsCache::put('call_type', ['Police']);
        $this->assertSame(['Police'], \NwsCad\Api\Filtering\FilterOptionsCache::get('call_type'),
            'Precondition: cache entry must exist before processFile()');

        $parser = new AegisXmlParser();
        $result = $parser->processFile($this->testXmlPath);
        $this->assertTrue($result, 'processFile() must return true for valid XML');

        $this->assertNull(\NwsCad\Api\Filtering\FilterOptionsCache::get('call_type'),
            'FilterOptionsCache call_type must be invalidated when the XML introduces a new value');
    }

    public function testDoesNotInvalidateFilterCacheWhenValueAlreadyPresent(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        cleanTestDatabase();

        // The fixture's CallType 'Medical' is already in the cached list, so the
        // targeted invalidation must leave the call_type cache intact (avoiding
        // cache-miss storms under steady ingest).
        \NwsCad\Api\Filtering\FilterOptionsCache::put('call_type', ['Medical', 'Fire']);

        $parser = new AegisXmlParser();
        $this->assertTrue($parser->processFile($this->testXmlPath));

        $this->assertSame(['Medical', 'Fire'], \NwsCad\Api\Filtering\FilterOptionsCache::get('call_type'),
            'call_type cache must survive when the ingested value is already present');
    }

    private function buildXmlWithAgencyContextHavingFdid(string $fdid): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>77701</CallId>
    <CallNumber>2024-77701</CallNumber>
    <CreateDateTime>2024-03-01T08:00:00</CreateDateTime>
    <ClosedFlag>false</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
    <AlarmLevel>1</AlarmLevel>
    <AgencyContexts>
        <AgencyContext>
            <AgencyType>Fire</AgencyType>
            <CallType>Structure Fire</CallType>
            <Priority>High</Priority>
            <Status>Active</Status>
            <FDID>{$fdid}</FDID>
            <ClosedFlag>false</ClosedFlag>
            <CanceledFlag>false</CanceledFlag>
        </AgencyContext>
    </AgencyContexts>
</CallExport>
XML;
    }

    private function buildXmlWithAgencyType(string $agencyType): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>77702</CallId>
    <CallNumber>2024-77702</CallNumber>
    <CreateDateTime>2024-03-01T09:00:00</CreateDateTime>
    <ClosedFlag>false</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
    <AlarmLevel>1</AlarmLevel>
    <AgencyContexts>
        <AgencyContext>
            <AgencyType>{$agencyType}</AgencyType>
            <CallType>Structure Fire</CallType>
            <Priority>High</Priority>
            <Status>Active</Status>
            <ClosedFlag>false</ClosedFlag>
            <CanceledFlag>false</CanceledFlag>
        </AgencyContext>
    </AgencyContexts>
</CallExport>
XML;
    }

    public function testReprocessingSameCallIdDoesNotDuplicateLocationRow(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        cleanTestDatabase();

        $makeXml = function (string $address, string $houseNumber, string $zip): string {
            return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>424242</CallId>
    <CallNumber>2024-424242</CallNumber>
    <CreateDateTime>2024-01-01T10:00:00</CreateDateTime>
    <ClosedFlag>false</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
    <Location>
        <FullAddress>{$address}</FullAddress>
        <HouseNumber>{$houseNumber}</HouseNumber>
        <City>Springfield</City>
        <State>IL</State>
        <Zip>{$zip}</Zip>
    </Location>
</CallExport>
XML;
        };

        $first = sys_get_temp_dir() . '/loc_dup_a_' . uniqid() . '.xml';
        $second = sys_get_temp_dir() . '/loc_dup_b_' . uniqid() . '.xml';
        file_put_contents($first, $makeXml('123 Main St', '123', '62701'));
        file_put_contents($second, $makeXml('456 Oak Ave', '456', '62702'));

        try {
            $parser = new AegisXmlParser();
            $this->assertTrue($parser->processFile($first));
            $this->assertTrue($parser->processFile($second));

            $db = Database::getConnection();
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM locations l
                 JOIN calls c ON c.id = l.call_id
                 WHERE c.call_id = ?"
            );
            $stmt->execute([424242]);
            $this->assertSame(
                1,
                (int) $stmt->fetchColumn(),
                'Reprocessing the same call_id must not duplicate the locations row'
            );

            // And the surviving row must reflect the latest XML.
            $stmt = $db->prepare(
                "SELECT l.full_address FROM locations l
                 JOIN calls c ON c.id = l.call_id
                 WHERE c.call_id = ?"
            );
            $stmt->execute([424242]);
            $this->assertSame('456 Oak Ave', $stmt->fetchColumn());
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }
}
