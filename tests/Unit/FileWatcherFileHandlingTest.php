<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\FileWatcher;
use NwsCad\ParserInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit coverage for FileWatcher's file-discovery and file-movement logic,
 * exercised through the watcher's optional constructor-injection seams:
 * a fake parser, an in-memory config, real temp directories, and a no-op
 * sleep callable (so isFileStable() does not block for a real second).
 *
 * No database is touched — the injected-config path bypasses waitForDatabase()
 * and the real AegisXmlParser entirely.
 *
 * @covers \NwsCad\FileWatcher
 * @uses \NwsCad\FilenameParser
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 */
class FileWatcherFileHandlingTest extends TestCase
{
    private string $watchDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->watchDir = sys_get_temp_dir() . '/fw_test_' . uniqid('', true);
        mkdir($this->watchDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->watchDir);
        parent::tearDown();
    }

    /**
     * Build a watcher wired to a fake parser and this test's temp watch dir,
     * with sleeps neutralized so file-stability checks are instant.
     */
    private function makeWatcher(RecordingParser $parser, string $pattern = '*.xml'): FileWatcher
    {
        return new FileWatcher(
            $parser,
            [
                'folder' => $this->watchDir,
                'interval' => 0,
                'file_pattern' => $pattern,
                'heartbeat_path' => $this->watchDir . '/.hb',
            ],
            static function (int $seconds): void {
                // no-op: do not actually sleep during tests
            }
        );
    }

    private function writeFile(string $name, string $contents = "<xml/>"): string
    {
        $path = $this->watchDir . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    private static function invoke(FileWatcher $w, string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(FileWatcher::class, $method);
        $m->setAccessible(true);
        return $m->invoke($w, ...$args);
    }

    public function testConvertGlobToRegexMatchesExtensionCaseInsensitively(): void
    {
        $w = $this->makeWatcher(new RecordingParser());
        $regex = self::invoke($w, 'convertGlobToRegex', '*.xml');

        $this->assertSame(1, preg_match($regex, 'call.xml'));
        $this->assertSame(1, preg_match($regex, 'CALL.XML'));
        $this->assertSame(0, preg_match($regex, 'call.txt'));
    }

    public function testScanDirectoryReturnsOnlyMatchingRootFiles(): void
    {
        $this->writeFile('a.xml');
        $this->writeFile('b.xml');
        $this->writeFile('ignore.txt');
        mkdir($this->watchDir . '/processed');
        $this->writeFile('processed/nested.xml');

        $w = $this->makeWatcher(new RecordingParser());
        /** @var array<int,string> $found */
        $found = self::invoke($w, 'scanDirectory', $this->watchDir);

        $names = array_map('basename', $found);
        sort($names);
        $this->assertSame(['a.xml', 'b.xml'], $names, 'subdirectories and non-matching files must be excluded');
    }

    public function testIsFileStableTrueForStaticFileAndFalseForMissing(): void
    {
        $path = $this->writeFile('stable.xml', 'content');
        $w = $this->makeWatcher(new RecordingParser());

        $this->assertTrue(self::invoke($w, 'isFileStable', $path, 0));
        $this->assertFalse(self::invoke($w, 'isFileStable', $this->watchDir . '/nope.xml', 0));
    }

    public function testShouldProcessFileFalseOnceKeyIsAlreadyTracked(): void
    {
        $path = $this->writeFile('once.xml', 'content');
        $w = $this->makeWatcher(new RecordingParser());

        $this->assertTrue(self::invoke($w, 'shouldProcessFile', $path));

        // Simulate the file having been processed this session.
        $key = md5($path . filesize($path) . filemtime($path));
        $prop = new ReflectionProperty(FileWatcher::class, 'processedFiles');
        $prop->setAccessible(true);
        $prop->setValue($w, [$key => time()]);

        $this->assertFalse(self::invoke($w, 'shouldProcessFile', $path));
    }

    public function testSuccessfulParseMovesFileToProcessed(): void
    {
        $this->writeFile('100_2026010112000000.xml', '<xml/>');
        $parser = new RecordingParser(true);
        $w = $this->makeWatcher($parser);

        self::invoke($w, 'checkForNewFiles');

        $this->assertCount(1, $parser->processed, 'parser should have been invoked once');
        $this->assertFileDoesNotExist($this->watchDir . '/100_2026010112000000.xml');
        $this->assertFileExists($this->watchDir . '/processed/100_2026010112000000.xml');
    }

    public function testFailedParseMovesFileToFailed(): void
    {
        $this->writeFile('101_2026010112000001.xml', '<xml/>');
        $parser = new RecordingParser(false);
        $w = $this->makeWatcher($parser);

        self::invoke($w, 'checkForNewFiles');

        $this->assertCount(1, $parser->processed, 'parser must be invoked before the failure routing');
        $this->assertFileDoesNotExist($this->watchDir . '/101_2026010112000001.xml');
        $this->assertFileExists($this->watchDir . '/failed/101_2026010112000001.xml');
    }

    public function testUnparseableFilenameMovedToFailedWithoutParsing(): void
    {
        // FilenameParser treats names containing '~' as unparseable.
        $this->writeFile('bad~name.xml', '<xml/>');
        $parser = new RecordingParser(true);
        $w = $this->makeWatcher($parser);

        self::invoke($w, 'checkForNewFiles');

        $this->assertSame([], $parser->processed, 'unparseable files must not reach the parser');
        $this->assertFileExists($this->watchDir . '/failed/bad~name.xml');
    }

    public function testGetUniqueFilenameSuffixesOnCollision(): void
    {
        mkdir($this->watchDir . '/processed');
        file_put_contents($this->watchDir . '/processed/x.xml', 'a');
        $w = $this->makeWatcher(new RecordingParser());

        $unique = self::invoke($w, 'getUniqueFilename', $this->watchDir . '/processed/x.xml');
        $this->assertSame($this->watchDir . '/processed/x_1.xml', $unique);
    }

    public function testHandleSignalStopsTheWatcher(): void
    {
        $w = $this->makeWatcher(new RecordingParser());
        $running = new ReflectionProperty(FileWatcher::class, 'running');
        $running->setAccessible(true);

        $this->assertTrue($running->getValue($w));
        $w->handleSignal(15);
        $this->assertFalse($running->getValue($w), 'a shutdown signal must clear the running flag');
    }

    public function testProcessedFileMemoryIsPrunedToOneThousand(): void
    {
        $this->writeFile('200_2026010112000000.xml', '<xml/>');
        $parser = new RecordingParser(true);
        $w = $this->makeWatcher($parser);

        // Pre-load 1500 stale tracking keys; checkForNewFiles() prunes to 1000.
        $prop = new ReflectionProperty(FileWatcher::class, 'processedFiles');
        $prop->setAccessible(true);
        $seed = [];
        for ($i = 0; $i < 1500; $i++) {
            $seed['k' . $i] = $i;
        }
        $prop->setValue($w, $seed);

        self::invoke($w, 'checkForNewFiles');

        $this->assertLessThanOrEqual(1000, count($prop->getValue($w)),
            'in-memory processed-file tracking must be capped');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

/**
 * Minimal ParserInterface test double: records the files it is asked to
 * process and returns a fixed success/failure result.
 */
final class RecordingParser implements ParserInterface
{
    /** @var array<int,string> */
    public array $processed = [];

    public function __construct(private bool $result = true)
    {
    }

    public function processFile(string $filePath): bool
    {
        $this->processed[] = $filePath;
        return $this->result;
    }
}
