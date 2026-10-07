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
 * @uses \NwsCad\Config
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
    private function makeWatcher(
        RecordingParser $parser,
        string $pattern = '*.xml',
        int $retentionDays = 15
    ): FileWatcher {
        return new FileWatcher(
            $parser,
            [
                'folder' => $this->watchDir,
                'interval' => 0,
                'file_pattern' => $pattern,
                'heartbeat_path' => $this->watchDir . '/.hb',
                'retention_days' => $retentionDays,
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

    public function testMidScanYieldFiresOnTickAndTouchesHeartbeatEveryNFiles(): void
    {
        // Write 10 parseable files with unique call numbers so none are
        // deduped by FilenameParser's version-select logic.
        for ($i = 0; $i < 10; $i++) {
            $callNum = 300 + $i;
            $this->writeFile("{$callNum}_2026010112000000.xml", '<xml/>');
        }

        $w = $this->makeWatcher(new RecordingParser(true));

        // Force yield-every-3 for the test — with 10 files we expect 3 yields
        // (after files 3, 6, and 9).
        $yieldProp = new ReflectionProperty(FileWatcher::class, 'yieldEveryFiles');
        $yieldProp->setAccessible(true);
        $yieldProp->setValue($w, 3);

        $tickCount = 0;
        $w->setOnTick(static function () use (&$tickCount): void { $tickCount++; });

        $hbPath = $this->watchDir . '/.hb';
        // Age the heartbeat so we can prove it was touched during the scan.
        touch($hbPath, time() - 3600);
        $before = filemtime($hbPath);

        self::invoke($w, 'checkForNewFiles');

        $this->assertSame(3, $tickCount, 'onTick should fire once per completed yield window');
        clearstatcache(true, $hbPath);
        $this->assertGreaterThan($before, filemtime($hbPath), 'heartbeat must be refreshed mid-scan');
    }

    public function testMidScanYieldDisabledWhenYieldEveryIsZero(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $callNum = 400 + $i;
            $this->writeFile("{$callNum}_2026010112000000.xml", '<xml/>');
        }

        $w = $this->makeWatcher(new RecordingParser(true));

        $yieldProp = new ReflectionProperty(FileWatcher::class, 'yieldEveryFiles');
        $yieldProp->setAccessible(true);
        $yieldProp->setValue($w, 0);

        $tickCount = 0;
        $w->setOnTick(static function () use (&$tickCount): void { $tickCount++; });

        self::invoke($w, 'checkForNewFiles');

        $this->assertSame(0, $tickCount, 'yield <= 0 must disable the mid-scan onTick');
    }

    public function testMidScanYieldCountsOlderVersionsTowardBoundary(): void
    {
        // 5 call numbers × 2 timestamped versions each = 10 files, where 5
        // are filtered out as older versions by FilenameParser. The yield
        // counter must still advance for skipped files so a backlog full of
        // historical versions can't bypass heartbeat + onTick.
        for ($i = 0; $i < 5; $i++) {
            $callNum = 500 + $i;
            $this->writeFile("{$callNum}_2026010112000000.xml", '<xml/>'); // older
            $this->writeFile("{$callNum}_2026010112000010.xml", '<xml/>'); // newer
        }

        $w = $this->makeWatcher(new RecordingParser(true));

        $yieldProp = new ReflectionProperty(FileWatcher::class, 'yieldEveryFiles');
        $yieldProp->setAccessible(true);
        $yieldProp->setValue($w, 3);

        $tickCount = 0;
        $w->setOnTick(static function () use (&$tickCount): void { $tickCount++; });

        self::invoke($w, 'checkForNewFiles');

        // 10 files iterated / yield-every-3 = 3 yields, regardless of how many
        // were skipped as older versions.
        $this->assertSame(3, $tickCount, 'skipped older-version files must still advance the yield counter');
    }

    public function testSupersededVersionsLeaveRootAfterLatestIsProcessed(): void
    {
        $this->writeFile('600_2026010112000000.xml'); // older
        $this->writeFile('600_2026010112000005.xml'); // older
        $this->writeFile('600_2026010112000010.xml'); // latest
        $parser = new RecordingParser(true);
        $w = $this->makeWatcher($parser);

        self::invoke($w, 'checkForNewFiles');

        $this->assertSame(
            [$this->watchDir . '/600_2026010112000010.xml'],
            $parser->processed,
            'only the latest version is parsed'
        );
        foreach (['600_2026010112000000.xml', '600_2026010112000005.xml', '600_2026010112000010.xml'] as $name) {
            $this->assertFileDoesNotExist($this->watchDir . '/' . $name, "{$name} must leave the watch root");
            $this->assertFileExists($this->watchDir . '/processed/' . $name);
        }
    }

    /**
     * Write $name under $sub/ (or the root when $sub is '') with an mtime
     * $ageDays days before $now.
     */
    private function writeAged(string $sub, string $name, int $now, int $ageDays): string
    {
        $dir = $sub === '' ? $this->watchDir : $this->watchDir . '/' . $sub;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $path = $dir . '/' . $name;
        file_put_contents($path, '<xml/>');
        touch($path, $now - $ageDays * 86400);
        return $path;
    }

    public function testRetentionSweepDeletesOnlyFilesPastTheWindow(): void
    {
        $now = 1_800_000_000;
        $oldProcessed = $this->writeAged('processed', '700_old.xml', $now, 16);
        $oldFailed    = $this->writeAged('failed', '701_old.xml', $now, 30);
        $freshProc    = $this->writeAged('processed', '702_new.xml', $now, 14);
        $freshFailed  = $this->writeAged('failed', '703_new.xml', $now, 1);
        $oldRoot      = $this->writeAged('', '704_old.xml', $now, 60);
        $oldNonXml    = $this->writeAged('processed', 'notes.txt', $now, 60);

        $w = $this->makeWatcher(new RecordingParser(true));

        $this->assertSame(2, $w->sweepRetention($now));
        $this->assertFileDoesNotExist($oldProcessed);
        $this->assertFileDoesNotExist($oldFailed);
        $this->assertFileExists($freshProc);
        $this->assertFileExists($freshFailed);
        $this->assertFileExists($oldRoot, 'the watch root is never swept');
        $this->assertFileExists($oldNonXml, 'only *.xml is swept');
    }

    public function testRetentionZeroDisablesSweep(): void
    {
        $now = 1_800_000_000;
        $old = $this->writeAged('processed', '710_old.xml', $now, 365);

        $w = $this->makeWatcher(new RecordingParser(true), '*.xml', 0);

        $this->assertSame(0, $w->sweepRetention($now));
        $this->assertFileExists($old);
    }

    public function testRetentionSweepRunsAtMostOncePerDay(): void
    {
        $w = $this->makeWatcher(new RecordingParser(true));
        $old = $this->writeAged('processed', '720_old.xml', time(), 20);

        self::invoke($w, 'maybeSweepRetention');
        $this->assertFileDoesNotExist($old, 'first call sweeps');

        $again = $this->writeAged('processed', '721_old.xml', time(), 20);
        self::invoke($w, 'maybeSweepRetention');
        $this->assertFileExists($again, 'a second call within 24h is a no-op');
    }

    /**
     * Set NOTIFICATION_YIELD_EVERY in both $_ENV and process env (getenv),
     * run $body, then restore. Prod code reads $_ENV ?? getenv() — leaving
     * either source populated would let a CI-inherited value leak in and
     * flip the assertion.
     */
    private function withYieldEnv(?string $value, callable $body): void
    {
        $envPrev  = array_key_exists('NOTIFICATION_YIELD_EVERY', $_ENV)
            ? $_ENV['NOTIFICATION_YIELD_EVERY']
            : '__ABSENT__';
        $procPrev = getenv('NOTIFICATION_YIELD_EVERY');

        if ($value === null) {
            unset($_ENV['NOTIFICATION_YIELD_EVERY']);
            putenv('NOTIFICATION_YIELD_EVERY');
        } else {
            $_ENV['NOTIFICATION_YIELD_EVERY'] = $value;
            putenv('NOTIFICATION_YIELD_EVERY=' . $value);
        }

        try {
            $body();
        } finally {
            if ($envPrev === '__ABSENT__') {
                unset($_ENV['NOTIFICATION_YIELD_EVERY']);
            } else {
                $_ENV['NOTIFICATION_YIELD_EVERY'] = $envPrev;
            }
            if ($procPrev === false) {
                putenv('NOTIFICATION_YIELD_EVERY');
            } else {
                putenv('NOTIFICATION_YIELD_EVERY=' . $procPrev);
            }
        }
    }

    private function assertYieldPropSame(FileWatcher $w, int $expected): void
    {
        $prop = new ReflectionProperty(FileWatcher::class, 'yieldEveryFiles');
        $prop->setAccessible(true);
        $this->assertSame($expected, $prop->getValue($w));
    }

    public function testConstructorParsesYieldZeroFromEnv(): void
    {
        // Regression: `?: 25` treated the string "0" as absent. An explicit
        // NOTIFICATION_YIELD_EVERY=0 must yield integer 0 (disable), not
        // fall through to the default.
        $this->withYieldEnv('0', function (): void {
            $w = $this->makeWatcher(new RecordingParser());
            $this->assertYieldPropSame($w, 0);
        });
    }

    public function testConstructorDefaultsYieldToTwentyFiveWhenEnvAbsent(): void
    {
        $this->withYieldEnv(null, function (): void {
            $w = $this->makeWatcher(new RecordingParser());
            $this->assertYieldPropSame($w, 25);
        });
    }

    public function testConstructorFallsBackToDefaultOnMalformedYieldEnv(): void
    {
        // `(int)"abc"` would silently yield 0 and disable the safety net.
        // Malformed values must instead fall back to the 25 default.
        $this->withYieldEnv('abc', function (): void {
            $w = $this->makeWatcher(new RecordingParser());
            $this->assertYieldPropSame($w, 25);
        });
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
