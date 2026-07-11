<?php

declare(strict_types=1);

namespace NwsCad\Import;

use Exception;
use NwsCad\Database;
use NwsCad\FilenameParser;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Data-access for the `processed_files` table: sha256 dedupe, stale-filename
 * detection, and success/failure marking (#49, extracted from AegisXmlParser).
 *
 * Reconnect handling: the two write-adjacent reads/writes go through
 * Database::run() (which reconnects on a dropped connection). The caller
 * (AegisXmlParser) resyncs its own cached PDO handle after those calls, since a
 * reconnect there invalidates the handle it later uses for the transaction. The
 * direct reads use Database::getConnection() — always the current singleton.
 */
final class ProcessedFileRepository
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * True when this exact file (same name + sha256) was already recorded.
     */
    public function isProcessed(string $filename, string $filePath): bool
    {
        $hash = hash_file('sha256', $filePath);

        try {
            return Database::run(function (PDO $db) use ($filename, $hash): bool {
                $stmt = $db->prepare(
                    "SELECT id FROM processed_files WHERE filename = ? AND file_hash = ?"
                );
                $stmt->execute([$filename, $hash]);
                return $stmt->fetch() !== false;
            });
        } catch (PDOException $e) {
            $this->logger->error("Database error in isFileProcessed: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * True when a newer XML for the same call_number has already been processed.
     * Filenames look like "{call_number}_{YYYYMMDDhhmmss<centiseconds>}.xml" — the
     * timestamp portion is fixed-width and zero-padded, so lexicographic comparison
     * matches chronological order. Filenames that don't match the expected pattern
     * fall through (return false) so manual injects or future formats don't break ingest.
     */
    public function isFilenameStaleForCall(string $filename): bool
    {
        if (! preg_match('/^(\d+)_\d+\.xml$/', $filename, $m)) {
            return false;
        }
        $prefix = $m[1] . '_';
        $stmt = Database::getConnection()->prepare(
            "SELECT MAX(filename) FROM processed_files WHERE filename LIKE ?"
        );
        $stmt->execute([$prefix . '%']);
        $max = $stmt->fetchColumn();
        if ($max === false || $max === null || $max === '') {
            return false;
        }
        return strcmp($filename, (string) $max) <= 0;
    }

    public function markProcessed(string $filename, string $filePath, int $recordsProcessed): void
    {
        $hash = hash_file('sha256', $filePath);

        // Extract call metadata from filename
        $parsed = FilenameParser::parse($filename);

        // Log warning if filename cannot be parsed
        if ($parsed === null) {
            $this->logger->warning("Could not parse filename for metadata extraction: {$filename}");
        }

        $callNumber = $parsed['call_number'] ?? null;
        $fileTimestamp = $parsed['timestamp_int'] ?? null;

        try {
            Database::run(function (PDO $db) use ($filename, $hash, $callNumber, $fileTimestamp, $recordsProcessed): void {
                $stmt = $db->prepare(
                    "INSERT INTO processed_files (filename, file_hash, call_number, file_timestamp, status, records_processed)
                     VALUES (?, ?, ?, ?, 'success', ?)"
                );
                $stmt->execute([$filename, $hash, $callNumber, $fileTimestamp, $recordsProcessed]);
            });
            $this->logger->info("Marked file as processed: {$filename} ({$recordsProcessed} records)");
        } catch (PDOException $e) {
            $this->logger->error("Database error in markFileAsProcessed: {$e->getMessage()}");
            throw $e;
        }
    }

    public function markFailed(string $filename, string $filePath, string $error): void
    {
        try {
            $hash = hash_file('sha256', $filePath);

            // Extract call metadata from filename
            $parsed = FilenameParser::parse($filename);

            // Log warning if filename cannot be parsed
            if ($parsed === null) {
                $this->logger->warning("Could not parse filename for metadata extraction: {$filename}");
            }

            $callNumber = $parsed['call_number'] ?? null;
            $fileTimestamp = $parsed['timestamp_int'] ?? null;

            $stmt = Database::getConnection()->prepare(
                "INSERT INTO processed_files (filename, file_hash, call_number, file_timestamp, status, error_message)
                 VALUES (?, ?, ?, ?, 'failed', ?)"
            );
            $stmt->execute([$filename, $hash, $callNumber, $fileTimestamp, $error]);
        } catch (Exception $e) {
            $this->logger->error("Failed to mark file as failed: " . $e->getMessage());
        }
    }
}
