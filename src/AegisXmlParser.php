<?php

declare(strict_types=1);

namespace NwsCad;

use DateTimeImmutable;
use Exception;
use PDO;
use SimpleXMLElement;
use NwsCad\Notifications\EventDispatcher;
use NwsCad\Notifications\Events\CallProcessedEvent;
use NwsCad\Notifications\IntentResolver;

/**
 * NWS Aegis CAD XML Parser
 * Parses New World Systems Aegis CAD export XML and stores in database
 *
 * @package NwsCad
 */
class AegisXmlParser implements ParserInterface
{
    private PDO $db;
    private $logger;
    private \NwsCad\Import\ProcessedFileRepository $processedFiles;
    private \NwsCad\Import\ReopenDetector $reopenDetector;
    private array $namespaces = [
        '' => 'http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02',
        'i' => 'http://www.w3.org/2001/XMLSchema-instance'
    ];

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->logger = Logger::getInstance();
        $this->processedFiles = new \NwsCad\Import\ProcessedFileRepository($this->logger);
        $this->reopenDetector = new \NwsCad\Import\ReopenDetector();
        $this->logger->debug("AegisXmlParser initialized with database connection");
    }

    /**
     * Process an Aegis CAD XML file
     *
     * @param string $filePath Path to XML file
     * @return bool Success status
     */
    public function processFile(string $filePath): bool
    {
        try {
            $filename = basename($filePath);
            $this->logger->info("Processing XML file: {$filename}");
            $this->logger->debug("Full file path: {$filePath}");
            
            // Check if already processed
            $this->logger->debug("Checking if file was previously processed...");
            if ($this->isFileProcessed($filename, $filePath)) {
                $this->logger->info("File already processed, skipping: {$filename}");
                return true;
            }
            $this->logger->debug("File has not been processed before");

            // Skip if a newer XML for the same call has already been processed.
            // Filenames embed a sortable timestamp ({call_number}_{YYYYMMDDhhmmss<cs>}.xml);
            // CAD-source files occasionally arrive in reverse order, and without
            // this check an older XML can clobber the newer XML's closed state.
            if ($this->isFilenameStaleForCall($filename)) {
                $this->logger->info("Skipping stale XML (newer version already processed for this call): {$filename}");
                $this->markFileAsProcessed($filename, $filePath, 0);
                return true;
            }

            // Load XML with security measures
            $this->logger->debug("Loading XML file with XXE protection...");
            $xml = $this->loadXmlFile($filePath);
            
            if ($xml === false) {
                throw new Exception('Failed to load XML file');
            }
            $this->logger->debug("XML file loaded successfully");

            // Pre-transaction validation: reject a wrong-root or field-incomplete
            // document before opening a transaction. Throws InvalidXmlException,
            // which the catch below turns into markFileAsFailed()/return false —
            // the same outcome these documents produced at insert time before.
            (new \NwsCad\Import\XmlValidator())->validate($xml);

            // Register namespaces
            $namespaceCount = count($this->namespaces);
            foreach ($this->namespaces as $prefix => $uri) {
                $xml->registerXPathNamespace($prefix, $uri);
            }
            $this->logger->debug("Registered {$namespaceCount} XML namespace(s)");

            // Begin transaction
            $this->logger->debug("Starting database transaction...");
            $this->db->beginTransaction();

            // Capture snapshot of existing data before any changes
            $existingSnapshot = $this->reopenDetector->snapshotExisting((int) $xml->CallId);

            // Detect reopen: previously-closed call now showing fresh unit activity.
            // Computed pre-insert because the insert overwrites close_datetime/units.
            $detectedReopen = $this->reopenDetector->detectReopen($xml, $existingSnapshot);

            // Parse and insert call data
            $this->logger->debug("Parsing and inserting call data...");
            $callId = $this->insertCall($xml, $filePath, $detectedReopen);
            $this->logger->debug("Call data inserted with database ID: {$callId}");

            // Capture snapshot of incoming data from the XML
            $incomingSnapshot = $this->reopenDetector->snapshotIncoming($xml);

            // Mark file as processed
            $this->logger->debug("Marking file as processed in database...");
            $this->markFileAsProcessed($filename, $filePath, 1);

            // Commit transaction
            $this->logger->debug("Committing database transaction...");
            $this->db->commit();

            // Invalidate derived filter-option caches whose values may have grown
            // after ingesting this XML (call_type, incident_type, unit, city).
            // Curated ref_* lists are not touched by XML ingest, so they are omitted.
            \NwsCad\Api\Filtering\FilterOptionsCache::invalidate(['call_type', 'incident_type', 'unit', 'city']);

            $this->logger->info("File processed successfully: {$filename} (Call ID: {$callId})");

            [$intent, $changedFields, $addedTopics] = IntentResolver::resolve($existingSnapshot, $incomingSnapshot);
            if ($intent !== null) {
                EventDispatcher::dispatch(new CallProcessedEvent(
                    dbCallId: $callId,
                    intent: $intent,
                    changedFields: $changedFields,
                    createDateTime: new DateTimeImmutable($incomingSnapshot['create_datetime'] ?: 'now'),
                    addedTopics: $addedTopics,
                ));
            }

            return true;

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->logger->debug("Rolling back database transaction due to error");
                $this->db->rollBack();
            }
            
            $this->logger->error("Error processing file {$filename}: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            $this->markFileAsFailed($filename, $filePath, $e->getMessage());
            return false;
        }
    }

    /**
     * Load XML file with XXE protection
     *
     * @param string $filePath Path to XML file
     * @return SimpleXMLElement|false
     */
    private function loadXmlFile(string $filePath): SimpleXMLElement|false
    {
        // Delegated to the extracted loader (#49). Kept as a thin private
        // wrapper so processFile() and the loader characterization tests are
        // unchanged. $this->logger is PSR-3 (Monolog), which XmlLoader accepts.
        return (new \NwsCad\Import\XmlLoader($this->logger))->load($filePath);
    }

    /**
     * Insert main call record and all child records
     *
     * @param SimpleXMLElement $xml XML root element
     * @param string $filePath Original file path
     * @param bool $detectedReopen True if this XML's units indicate the call was reopened after a prior close.
     * @return int Database call ID
     */
    private function insertCall(SimpleXMLElement $xml, string $filePath, bool $detectedReopen = false): int
    {
        $dbCallId = (new \NwsCad\Import\Mappers\CallMapper($this->db))->map($xml, $detectedReopen);

        // Child records — each mapper owns one table family and its write strategy.
        (new \NwsCad\Import\Mappers\AgencyContextMapper($this->db))->map($xml, $dbCallId);
        (new \NwsCad\Import\Mappers\LocationMapper($this->db))->map($xml, $dbCallId);
        (new \NwsCad\Import\Mappers\IncidentMapper($this->db))->map($xml, $dbCallId);
        (new \NwsCad\Import\Mappers\UnitMapper($this->db))->map($xml, $dbCallId);
        (new \NwsCad\Import\Mappers\NarrativeMapper($this->db))->map($xml, $dbCallId);
        (new \NwsCad\Import\Mappers\PersonMapper($this->db))->map($xml, $dbCallId);
        (new \NwsCad\Import\Mappers\VehicleMapper($this->db))->map($xml, $dbCallId);
        (new \NwsCad\Import\Mappers\CallDispositionMapper($this->db))->map($xml, $dbCallId);

        return $dbCallId;
    }

    /**
     * File tracking methods
     */

    private function isFileProcessed(string $filename, string $filePath): bool
    {
        $found = $this->processedFiles->isProcessed($filename, $filePath);
        // Resync our cached handle in case Database::run() reconnected inside
        // the repository — the transaction below relies on a live $this->db.
        $this->db = Database::getConnection();
        return $found;
    }

    private function isFilenameStaleForCall(string $filename): bool
    {
        return $this->processedFiles->isFilenameStaleForCall($filename);
    }

    private function markFileAsProcessed(string $filename, string $filePath, int $recordsProcessed): void
    {
        $this->processedFiles->markProcessed($filename, $filePath, $recordsProcessed);
    }

    private function markFileAsFailed(string $filename, string $filePath, string $error): void
    {
        $this->processedFiles->markFailed($filename, $filePath, $error);
    }

}
