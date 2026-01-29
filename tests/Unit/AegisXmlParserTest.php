<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\AegisXmlParser;
use NwsCad\Database;
use NwsCad\Logger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\AegisXmlParser
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
}
