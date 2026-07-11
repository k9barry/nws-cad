<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\Import\XmlLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SimpleXMLElement;

/**
 * Tests XmlLoader's pre-parse guards — the XML_MAX_BYTES size cap, BOM
 * stripping, and the DOCTYPE/XXE rejection (#49, extracted from
 * AegisXmlParser::loadXmlFile). No database; a NullLogger stands in for the
 * PSR-3 logger and the oversized input is generated at runtime.
 *
 * @covers \NwsCad\Import\XmlLoader
 */
class XmlLoadGuardsTest extends TestCase
{
    private ?string $originalMaxBytes = null;
    /** @var array<int,string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalMaxBytes = getenv('XML_MAX_BYTES') ?: null;
    }

    protected function tearDown(): void
    {
        if ($this->originalMaxBytes === null) {
            putenv('XML_MAX_BYTES');
        } else {
            putenv('XML_MAX_BYTES=' . $this->originalMaxBytes);
        }
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function writeTmp(string $contents): string
    {
        $path = sys_get_temp_dir() . '/xmlguard_' . uniqid('', true) . '.xml';
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function load(string $path): SimpleXMLElement|false
    {
        return (new XmlLoader(new NullLogger()))->load($path);
    }

    public function testOversizedFileIsRejected(): void
    {
        putenv('XML_MAX_BYTES=1024');
        $body = '<?xml version="1.0" encoding="UTF-8"?><CallExport>'
            . str_repeat('<Pad>x</Pad>', 500) // well over 1 KiB
            . '</CallExport>';
        $path = $this->writeTmp($body);
        $this->assertGreaterThan(1024, strlen($body), 'precondition: input exceeds the cap');

        $this->assertFalse($this->load($path), 'file over XML_MAX_BYTES must be rejected');
    }

    public function testFileWithinCapLoads(): void
    {
        putenv('XML_MAX_BYTES=1048576');
        $path = $this->writeTmp(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">'
            . '<CallId>1</CallId></CallExport>'
        );
        $this->assertInstanceOf(SimpleXMLElement::class, $this->load($path));
    }

    public function testDoctypeDeclarationIsRejected(): void
    {
        $path = $this->writeTmp(
            "<?xml version=\"1.0\"?>\n"
            . "<!DOCTYPE foo [<!ENTITY xxe SYSTEM \"file:///etc/passwd\">]>\n"
            . '<CallExport><CallId>&xxe;</CallId></CallExport>'
        );
        $this->assertFalse($this->load($path), 'a DOCTYPE declaration must be rejected outright');
    }

    public function testMissingFileIsRejected(): void
    {
        $this->assertFalse($this->load(sys_get_temp_dir() . '/does_not_exist_' . uniqid() . '.xml'));
    }

    public function testUtf8BomIsStrippedAndParsed(): void
    {
        $path = $this->writeTmp(
            "\xEF\xBB\xBF"
            . '<?xml version="1.0" encoding="UTF-8"?>'
            . '<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">'
            . '<CallId>42</CallId></CallExport>'
        );
        $xml = $this->load($path);
        $this->assertInstanceOf(SimpleXMLElement::class, $xml);
        $this->assertSame('42', (string) $xml->CallId);
    }
}
