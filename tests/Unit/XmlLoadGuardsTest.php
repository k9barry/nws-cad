<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\AegisXmlParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use SimpleXMLElement;

/**
 * Characterizes AegisXmlParser::loadXmlFile()'s pre-parse guards — the
 * XML_MAX_BYTES size cap and the DOCTYPE (XXE) rejection — without a database.
 * loadXmlFile() only touches the filesystem, libxml, and the logger, so we run
 * it on a constructor-less instance with a NullLogger injected via reflection.
 *
 * Covers the "oversized file" and XXE fixture cases from #48 without committing
 * a multi-megabyte artifact to the repo (the oversized input is generated at
 * runtime against a lowered XML_MAX_BYTES cap).
 *
 * @covers \NwsCad\AegisXmlParser
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

    private function loadXml(string $path): SimpleXMLElement|false
    {
        $rc = new ReflectionClass(AegisXmlParser::class);
        $obj = $rc->newInstanceWithoutConstructor();
        $logger = $rc->getProperty('logger');
        $logger->setAccessible(true);
        $logger->setValue($obj, new NullLogger());

        $m = new ReflectionMethod(AegisXmlParser::class, 'loadXmlFile');
        $m->setAccessible(true);
        /** @var SimpleXMLElement|false $result */
        $result = $m->invoke($obj, $path);
        return $result;
    }

    public function testOversizedFileIsRejected(): void
    {
        putenv('XML_MAX_BYTES=1024');
        $body = '<?xml version="1.0" encoding="UTF-8"?><CallExport>'
            . str_repeat('<Pad>x</Pad>', 500) // well over 1 KiB
            . '</CallExport>';
        $path = $this->writeTmp($body);
        $this->assertGreaterThan(1024, strlen($body), 'precondition: input exceeds the cap');

        $this->assertFalse($this->loadXml($path), 'file over XML_MAX_BYTES must be rejected');
    }

    public function testFileWithinCapLoads(): void
    {
        putenv('XML_MAX_BYTES=1048576');
        $path = $this->writeTmp(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<CallExport xmlns="http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02">'
            . '<CallId>1</CallId></CallExport>'
        );
        $this->assertInstanceOf(SimpleXMLElement::class, $this->loadXml($path));
    }

    public function testDoctypeDeclarationIsRejected(): void
    {
        $path = $this->writeTmp(
            "<?xml version=\"1.0\"?>\n"
            . "<!DOCTYPE foo [<!ENTITY xxe SYSTEM \"file:///etc/passwd\">]>\n"
            . '<CallExport><CallId>&xxe;</CallId></CallExport>'
        );
        $this->assertFalse($this->loadXml($path), 'a DOCTYPE declaration must be rejected outright');
    }
}
