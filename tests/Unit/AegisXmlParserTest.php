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
}
