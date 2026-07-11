<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\Import\ProcessedFileRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \NwsCad\Import\ProcessedFileRepository
 * @uses \NwsCad\Database
 * @uses \NwsCad\Config
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 * @uses \NwsCad\FilenameParser
 */
class ProcessedFileRepositoryTest extends TestCase
{
    /** @var array<int,string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $hostVar = (getenv('DB_TYPE') ?: 'mysql') === 'pgsql' ? 'POSTGRES_HOST' : 'MYSQL_HOST';
        if (!getenv($hostVar)) {
            $this->markTestSkipped('Database not configured for testing');
        }
        cleanTestDatabase();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function tmpFileWith(string $contents): string
    {
        $path = sys_get_temp_dir() . '/pfr_' . uniqid('', true) . '.xml';
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function repo(): ProcessedFileRepository
    {
        return new ProcessedFileRepository(new NullLogger());
    }

    public function testMarkProcessedThenIsProcessedRoundtrip(): void
    {
        $repo = $this->repo();
        $path = $this->tmpFileWith('<CallExport><CallId>1</CallId></CallExport>');
        $filename = '900_20260101120000.xml';

        $this->assertFalse($repo->isProcessed($filename, $path), 'unrecorded file must not be processed');

        $repo->markProcessed($filename, $path, 1);

        $this->assertTrue($repo->isProcessed($filename, $path), 'same name + content must be recognized');
    }

    public function testIsProcessedIsContentSensitive(): void
    {
        $repo = $this->repo();
        $filename = '901_20260101120000.xml';
        $original = $this->tmpFileWith('<CallExport><CallId>1</CallId></CallExport>');
        $repo->markProcessed($filename, $original, 1);

        // Same filename, different bytes → different hash → not a match.
        $edited = $this->tmpFileWith('<CallExport><CallId>1</CallId></CallExport><!-- v2 -->');
        $this->assertFalse($repo->isProcessed($filename, $edited));
    }

    public function testIsFilenameStaleForCall(): void
    {
        $repo = $this->repo();
        $path = $this->tmpFileWith('<CallExport><CallId>1</CallId></CallExport>');

        // Record a mid-sequence version for call 950.
        $repo->markProcessed('950_20260101120500.xml', $path, 1);

        $this->assertTrue(
            $repo->isFilenameStaleForCall('950_20260101120400.xml'),
            'an older same-call filename is stale'
        );
        $this->assertFalse(
            $repo->isFilenameStaleForCall('950_20260101120600.xml'),
            'a newer same-call filename is not stale'
        );
        $this->assertFalse(
            $repo->isFilenameStaleForCall('not-a-cad-name.xml'),
            'unparseable filenames fall through to not-stale'
        );
        $this->assertFalse(
            $repo->isFilenameStaleForCall('999_20260101120000.xml'),
            'a call with no recorded files is not stale'
        );
    }

    public function testMarkFailedRecordsFailure(): void
    {
        $repo = $this->repo();
        $path = $this->tmpFileWith('<broken/>');
        $filename = '960_20260101120000.xml';

        $repo->markFailed($filename, $path, 'boom');

        // A failed row is not a success row, so isProcessed (which requires the
        // hash match on any status) still recognizes the file was seen.
        $this->assertTrue($repo->isProcessed($filename, $path));
    }
}
