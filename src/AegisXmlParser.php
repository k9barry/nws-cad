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
        $this->logger->debug("Extracting call data from XML...");
        
        // Extract call data with proper type handling
        $callData = [
            'call_id' => $this->parseInt((string)$xml->CallId),
            'call_number' => (string)$xml->CallNumber ?: null,
            'call_source' => (string)$xml->CallSource ?: null,
            'caller_name' => (string)$xml->CallerName ?: null,
            'caller_phone' => (string)$xml->CallerPhone ?: null,
            'nature_of_call' => (string)$xml->NatureOfCall ?: null,
            'additional_info' => (string)$xml->AdditionalInfo ?: null,
            'create_datetime' => $this->parseDateTime((string)$xml->CreateDateTime) ?? date('Y-m-d H:i:s'),
            'close_datetime' => $this->parseDateTime((string)$xml->CloseDateTime),
            'created_by' => (string)$xml->CreatedBy ?: null,
            'closed_flag' => $this->parseBoolean((string)$xml->ClosedFlag),
            'canceled_flag' => $this->parseBoolean((string)$xml->CanceledFlag),
            'alarm_level' => $this->parseInt((string)$xml->AlarmLevel),
            'emd_code' => (string)$xml->EmdCode ?: null,
            'fire_controlled_time' => $this->parseDateTime((string)$xml->FireControlledTime),
            'xml_data' => json_encode($this->xmlToArray($xml))
        ];

        $this->logger->debug("Extracted call data: call_id={$callData['call_id']}, call_number={$callData['call_number']}, nature={$callData['nature_of_call']}");

        // Check if call already exists
        $this->logger->debug("Checking if call already exists in database...");
        $existingCallStmt = $this->db->prepare("SELECT id FROM calls WHERE call_id = ?");
        $existingCallStmt->execute([$callData['call_id']]);
        $existingCall = $existingCallStmt->fetch();

        if ($existingCall) {
            // Update existing call - child records will be upserted, not deleted
            $this->logger->debug("Call ID {$callData['call_id']} already exists in database, updating...");
            
            $dbCallId = (int)$existingCall['id'];
            
            // Update the call record. reopened_flag uses CASE so a fresh close
            // (incoming closed_flag = 1) trumps any prior reopen, a detected reopen
            // sets it to 1, and otherwise the existing value is preserved (so a
            // benign post-close edit doesn't accidentally clear the reopen state).
            $sql = "UPDATE calls SET
                call_number = :call_number,
                call_source = :call_source,
                caller_name = :caller_name,
                caller_phone = :caller_phone,
                nature_of_call = :nature_of_call,
                additional_info = :additional_info,
                create_datetime = :create_datetime,
                close_datetime = :close_datetime,
                created_by = :created_by,
                closed_flag = :closed_flag,
                canceled_flag = :canceled_flag,
                reopened_flag = CASE
                    WHEN :incoming_closed = 1 THEN 0
                    WHEN :detected_reopen = 1 THEN 1
                    ELSE reopened_flag
                END,
                alarm_level = :alarm_level,
                emd_code = :emd_code,
                fire_controlled_time = :fire_controlled_time,
                xml_data = :xml_data,
                updated_at = CURRENT_TIMESTAMP
                WHERE id = :id";

            $updateData = $callData;
            unset($updateData['call_id']); // Remove call_id as UPDATE SQL uses :id instead
            $updateData['id'] = $dbCallId;
            $updateData['incoming_closed'] = $callData['closed_flag'];
            $updateData['detected_reopen'] = $detectedReopen ? 1 : 0;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($updateData);
            $this->logger->debug("Call record updated successfully");
        } else {
            // Insert new call. reopened_flag is set from detectedReopen directly;
            // for a brand-new call this is effectively always 0 (no prior close to reopen),
            // but we honour the parameter for symmetry.
            $this->logger->debug("Call ID {$callData['call_id']} is new, inserting into database...");
            $sql = "INSERT INTO calls (
                call_id, call_number, call_source, caller_name, caller_phone,
                nature_of_call, additional_info, create_datetime, close_datetime,
                created_by, closed_flag, canceled_flag, reopened_flag, alarm_level, emd_code,
                fire_controlled_time, xml_data
            ) VALUES (
                :call_id, :call_number, :call_source, :caller_name, :caller_phone,
                :nature_of_call, :additional_info, :create_datetime, :close_datetime,
                :created_by, :closed_flag, :canceled_flag, :reopened_flag, :alarm_level, :emd_code,
                :fire_controlled_time, :xml_data
            )";

            $insertData = $callData;
            $insertData['reopened_flag'] = $detectedReopen ? 1 : 0;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($insertData);
            $dbCallId = (int)$this->db->lastInsertId();
            $this->logger->debug("New call inserted with database ID: {$dbCallId}");
        }

        // Upsert child records (insert new, update existing based on unique constraints)
        $this->logger->debug("Processing child records...");
        (new \NwsCad\Import\Mappers\AgencyContextMapper($this->db))->map($xml, $dbCallId);
        (new \NwsCad\Import\Mappers\LocationMapper($this->db))->map($xml, $dbCallId);
        (new \NwsCad\Import\Mappers\IncidentMapper($this->db))->map($xml, $dbCallId);
        $this->insertUnits($xml, $dbCallId);
        (new \NwsCad\Import\Mappers\NarrativeMapper($this->db))->map($xml, $dbCallId);
        (new \NwsCad\Import\Mappers\PersonMapper($this->db))->map($xml, $dbCallId);
        (new \NwsCad\Import\Mappers\VehicleMapper($this->db))->map($xml, $dbCallId);
        (new \NwsCad\Import\Mappers\CallDispositionMapper($this->db))->map($xml, $dbCallId);
        $this->logger->debug("All child records processed successfully");

        return $dbCallId;
    }


    /**
     * Insert or update units for a call
     * Uses UPSERT to add new units or update existing ones based on (call_id, unit_number)
     */
    private function insertUnits(SimpleXMLElement $xml, int $callId): void
    {
        if (!isset($xml->AssignedUnits->Unit)) {
            $this->logger->debug("  No assigned units found in XML");
            return;
        }

        $count = count($xml->AssignedUnits->Unit);
        $this->logger->debug("  Processing {$count} assigned unit(s)...");

        $dbType = Database::getDbType();
        
        foreach ($xml->AssignedUnits->Unit as $unit) {
            $data = [
                'call_id' => $callId,
                'unit_number' => (string)$unit->UnitNumber ?: null,
                'unit_type' => (string)$unit->Type ?: null,
                'is_primary' => $this->parseBoolean((string)$unit->IsPrimary),
                'jurisdiction' => (string)$unit->Jurisdiction ?: null,
                'assigned_datetime' => $this->parseDateTime((string)$unit->AssignedDateTime),
                'dispatch_datetime' => $this->parseDateTime((string)$unit->DispatchDateTime),
                'enroute_datetime' => $this->parseDateTime((string)$unit->EnrouteDateTime),
                'arrive_datetime' => $this->parseDateTime((string)$unit->ArriveDateTime),
                'staged_datetime' => $this->parseDateTime((string)$unit->StagedDateTime),
                'at_patient_datetime' => $this->parseDateTime((string)$unit->AtPatientDateTime),
                'transport_datetime' => $this->parseDateTime((string)$unit->TransportDateTime),
                'at_hospital_datetime' => $this->parseDateTime((string)$unit->AtHospitalDateTime),
                'depart_hospital_datetime' => $this->parseDateTime((string)$unit->DepartHospitalDateTime),
                'clear_datetime' => $this->parseDateTime((string)$unit->ClearDateTime)
            ];
            
            // Use UPSERT to insert or update unit (RETURNING id on pgsql).
            $unitColumns = [
                'call_id', 'unit_number', 'unit_type', 'is_primary', 'jurisdiction',
                'assigned_datetime', 'dispatch_datetime', 'enroute_datetime', 'arrive_datetime',
                'staged_datetime', 'at_patient_datetime', 'transport_datetime',
                'at_hospital_datetime', 'depart_hospital_datetime', 'clear_datetime',
            ];
            $sql = \NwsCad\Db\UpsertBuilder::upsert(
                $dbType,
                'units',
                $unitColumns,
                ['call_id', 'unit_number'],
                [
                    'unit_type', 'is_primary', 'jurisdiction',
                    'assigned_datetime', 'dispatch_datetime', 'enroute_datetime', 'arrive_datetime',
                    'staged_datetime', 'at_patient_datetime', 'transport_datetime',
                    'at_hospital_datetime', 'depart_hospital_datetime', 'clear_datetime',
                ],
                true
            );

            $stmt = $this->db->prepare($sql);
            $stmt->execute($data);
            
            // Get the unit_id
            if ($dbType === 'mysql') {
                // MySQL does not support RETURNING with INSERT ... ON DUPLICATE KEY UPDATE,
                // so we need to query the id after the upsert.
                $stmt = $this->db->prepare("SELECT id FROM units WHERE call_id = ? AND unit_number = ?");
                $stmt->execute([$callId, $data['unit_number']]);
                $unitId = (int)$stmt->fetchColumn();
            } else {
                // PostgreSQL: use the id returned by INSERT ... ON CONFLICT ... DO UPDATE RETURNING id
                $unitId = (int)$stmt->fetchColumn();
            }

            // Insert/update personnel for this unit (will delete and re-insert to handle removals)
            $this->insertUnitPersonnel($unit, $unitId);
            
            // Insert unit logs (additive - will use INSERT IGNORE)
            $this->insertUnitLogs($unit, $unitId);
            
            // Insert unit dispositions (will delete and re-insert to handle updates)
            $this->insertUnitDispositions($unit, $unitId);
        }
    }

    /**
     * Insert unit personnel
     */
    private function insertUnitPersonnel(SimpleXMLElement $unit, int $unitId): void
    {
        if (!isset($unit->Personnel->UnitPersonnel)) {
            return;
        }

        // Delete existing personnel for this unit to handle removals/updates
        $deleteStmt = $this->db->prepare("DELETE FROM unit_personnel WHERE unit_id = ?");
        $deleteStmt->execute([$unitId]);

        $sql = "INSERT INTO unit_personnel (
            unit_id, first_name, middle_name, last_name, id_number,
            shield_number, is_primary_officer, jurisdiction
        ) VALUES (
            :unit_id, :first_name, :middle_name, :last_name, :id_number,
            :shield_number, :is_primary_officer, :jurisdiction
        )";

        $stmt = $this->db->prepare($sql);

        foreach ($unit->Personnel->UnitPersonnel as $personnel) {
            $data = [
                'unit_id' => $unitId,
                'first_name' => (string)$personnel->FirstName ?: null,
                'middle_name' => (string)$personnel->MiddleName ?: null,
                'last_name' => (string)$personnel->LastName ?: null,
                'id_number' => (string)$personnel->IDNumber ?: null,
                'shield_number' => (string)$personnel->ShieldNumber ?: null,
                'is_primary_officer' => $this->parseBoolean((string)$personnel->IsPrimaryOfficer),
                'jurisdiction' => (string)$personnel->Jurisdiction ?: null
            ];
            $stmt->execute($data);
        }
    }

    /**
     * Insert unit logs (additive - uses INSERT IGNORE to skip duplicates)
     */
    private function insertUnitLogs(SimpleXMLElement $unit, int $unitId): void
    {
        if (!isset($unit->UnitLogs->UnitLog)) {
            return;
        }

        $sql = \NwsCad\Db\UpsertBuilder::insertIgnore(
            Database::getDbType(),
            'unit_logs',
            ['unit_id', 'log_datetime', 'status', 'location'],
            ['unit_id', 'log_datetime', 'status', 'location']
        );

        $stmt = $this->db->prepare($sql);

        foreach ($unit->UnitLogs->UnitLog as $log) {
            $data = [
                'unit_id' => $unitId,
                'log_datetime' => $this->parseDateTime((string)$log->DateTime),
                'status' => (string)$log->Status ?: null,
                'location' => (string)$log->Location ?: '' // Empty string instead of null for unique constraint
            ];
            $stmt->execute($data);
        }
    }

    /**
     * Insert unit dispositions
     *
     * @param SimpleXMLElement $unit Unit XML element
     * @param int $unitId Database unit ID
     * @return void
     */
    private function insertUnitDispositions(SimpleXMLElement $unit, int $unitId): void
    {
        if (!isset($unit->Dispositions->Disposition)) {
            return;
        }

        // Delete existing dispositions for this unit to handle removals/updates
        $deleteStmt = $this->db->prepare("DELETE FROM unit_dispositions WHERE unit_id = ?");
        $deleteStmt->execute([$unitId]);

        $sql = "INSERT INTO unit_dispositions (
            unit_id, disposition_name, description, count, disposition_datetime
        ) VALUES (
            :unit_id, :disposition_name, :description, :count, :disposition_datetime
        )";

        $stmt = $this->db->prepare($sql);

        foreach ($unit->Dispositions->Disposition as $disp) {
            $data = [
                'unit_id' => $unitId,
                'disposition_name' => (string)$disp->Name ?: null,
                'description' => (string)$disp->Description ?: null,
                'count' => $this->parseInt((string)$disp->Count),
                'disposition_datetime' => $this->parseDateTime((string)$disp->DateTime)
            ];
            $stmt->execute($data);
        }
    }



    /**
     * Helper methods
     */

    private function parseDateTime(?string $dateTime): ?string
    {
        // Delegated to the extracted, independently-tested normalizer (#49).
        // Kept as a thin private wrapper so the many internal call sites and the
        // parser's characterization tests continue to work unchanged.
        return \NwsCad\Import\DateTimeParser::parse($dateTime);
    }

    private function parseBoolean($value): int
    {
        return \NwsCad\Import\ValueCaster::toBool($value);
    }

    private function parseInt(?string $value): ?int
    {
        return \NwsCad\Import\ValueCaster::toInt($value);
    }

    private function xmlToArray(SimpleXMLElement $xml): array
    {
        $json = json_encode($xml);
        return json_decode($json, true) ?? [];
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
        // Resync our cached handle in case Database::run() reconnected.
        $this->db = Database::getConnection();
    }

    private function markFileAsFailed(string $filename, string $filePath, string $error): void
    {
        $this->processedFiles->markFailed($filename, $filePath, $error);
    }

}
