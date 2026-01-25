<?php

namespace NwsCad;

use SimpleXMLElement;
use Exception;
use PDO;

/**
 * XML Parser
 * Parses XML files and stores data in the database
 */
class XmlParser
{
    private PDO $db;
    private $logger;

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->logger = Logger::getInstance();
    }

    /**
     * Parse and process an XML file
     * 
     * @param string $filePath Path to the XML file
     * @return bool Success status
     */
    public function processFile(string $filePath): bool
    {
        $filename = basename($filePath);
        $this->logger->info("Processing XML file: $filename");

        try {
            // Check if file already processed
            if ($this->isFileProcessed($filename, $filePath)) {
                $this->logger->info("File already processed, skipping: $filename");
                return true;
            }

            // Load and parse XML
            $xml = $this->loadXml($filePath);
            if (!$xml) {
                throw new Exception("Failed to load XML file");
            }

            // Begin transaction
            $this->db->beginTransaction();

            // Parse and insert events
            $recordsProcessed = $this->parseAndInsertEvents($xml, $filePath);

            // Mark file as processed
            $fileId = $this->markFileAsProcessed($filename, $filePath, $recordsProcessed);

            // Store metadata
            $this->storeMetadata($fileId, $xml);

            // Commit transaction
            $this->db->commit();

            $this->logger->info("Successfully processed file: $filename ($recordsProcessed records)");
            return true;

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            
            $this->logger->error("Error processing file $filename: " . $e->getMessage());
            $this->markFileAsFailed($filename, $filePath, $e->getMessage());
            return false;
        }
    }

    /**
     * Load XML file
     */
    private function loadXml(string $filePath): ?SimpleXMLElement
    {
        try {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($filePath);
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                $errorMsg = "XML parsing errors: " . implode(", ", array_map(
                    fn($e) => $e->message,
                    $errors
                ));
                libxml_clear_errors();
                throw new Exception($errorMsg);
            }

            return $xml;
        } catch (Exception $e) {
            $this->logger->error("Failed to load XML: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse XML and insert events into database
     * This is a placeholder implementation - customize based on your XML structure
     */
    private function parseAndInsertEvents(SimpleXMLElement $xml, string $filePath): int
    {
        $recordsProcessed = 0;

        // Example: Process events from XML
        // Customize this based on your actual XML structure
        foreach ($xml->xpath('//event') as $event) {
            try {
                $eventData = $this->extractEventData($event);
                $this->insertEvent($eventData);
                $recordsProcessed++;
            } catch (Exception $e) {
                $this->logger->warning("Failed to process event: " . $e->getMessage());
            }
        }

        // If no events found, try to process the entire XML as a single event
        if ($recordsProcessed === 0) {
            $this->logger->info("No specific events found, storing entire XML");
            $eventData = [
                'event_id' => md5($filePath . time()),
                'event_type' => 'xml_import',
                'event_time' => date('Y-m-d H:i:s'),
                'description' => 'Full XML import',
                'xml_data' => json_encode($this->xmlToArray($xml)),
            ];
            $this->insertEvent($eventData);
            $recordsProcessed = 1;
        }

        return $recordsProcessed;
    }

    /**
     * Extract event data from XML element
     * Customize this based on your XML structure
     */
    private function extractEventData(SimpleXMLElement $event): array
    {
        return [
            'event_id' => (string)($event->id ?? $event['id'] ?? md5((string)$event->asXML())),
            'event_type' => (string)($event->type ?? $event['type'] ?? 'unknown'),
            'event_time' => (string)($event->time ?? $event->timestamp ?? date('Y-m-d H:i:s')),
            'location' => (string)($event->location ?? ''),
            'description' => (string)($event->description ?? ''),
            'priority' => (string)($event->priority ?? ''),
            'status' => (string)($event->status ?? 'pending'),
            'xml_data' => json_encode($this->xmlToArray($event)),
        ];
    }

    /**
     * Convert XML to array
     */
    private function xmlToArray(SimpleXMLElement $xml): array
    {
        $json = json_encode($xml);
        return json_decode($json, true);
    }

    /**
     * Insert event into database
     */
    private function insertEvent(array $eventData): void
    {
        $dbType = Database::getDbType();
        
        // Handle duplicate key based on database type
        if ($dbType === 'mysql') {
            $sql = "INSERT INTO cad_events 
                    (event_id, event_type, event_time, location, description, priority, status, xml_data)
                    VALUES (:event_id, :event_type, :event_time, :location, :description, :priority, :status, :xml_data)
                    ON DUPLICATE KEY UPDATE 
                    event_type = VALUES(event_type),
                    event_time = VALUES(event_time),
                    location = VALUES(location),
                    description = VALUES(description),
                    priority = VALUES(priority),
                    status = VALUES(status),
                    xml_data = VALUES(xml_data),
                    updated_at = CURRENT_TIMESTAMP";
        } else { // pgsql
            $sql = "INSERT INTO cad_events 
                    (event_id, event_type, event_time, location, description, priority, status, xml_data)
                    VALUES (:event_id, :event_type, :event_time, :location, :description, :priority, :status, :xml_data::jsonb)
                    ON CONFLICT (event_id) DO UPDATE SET
                    event_type = EXCLUDED.event_type,
                    event_time = EXCLUDED.event_time,
                    location = EXCLUDED.location,
                    description = EXCLUDED.description,
                    priority = EXCLUDED.priority,
                    status = EXCLUDED.status,
                    xml_data = EXCLUDED.xml_data,
                    updated_at = CURRENT_TIMESTAMP";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($eventData);
    }

    /**
     * Check if file has already been processed
     */
    private function isFileProcessed(string $filename, string $filePath): bool
    {
        $hash = hash_file('sha256', $filePath);
        
        $stmt = $this->db->prepare(
            "SELECT id FROM processed_files WHERE filename = ? AND file_hash = ?"
        );
        $stmt->execute([$filename, $hash]);
        
        return $stmt->fetch() !== false;
    }

    /**
     * Mark file as successfully processed
     */
    private function markFileAsProcessed(string $filename, string $filePath, int $recordsProcessed): int
    {
        $hash = hash_file('sha256', $filePath);
        
        $stmt = $this->db->prepare(
            "INSERT INTO processed_files (filename, file_hash, status, records_processed)
             VALUES (?, ?, 'success', ?)"
        );
        $stmt->execute([$filename, $hash, $recordsProcessed]);
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * Mark file as failed
     */
    private function markFileAsFailed(string $filename, string $filePath, string $error): void
    {
        try {
            $hash = hash_file('sha256', $filePath);
            
            $stmt = $this->db->prepare(
                "INSERT INTO processed_files (filename, file_hash, status, error_message)
                 VALUES (?, ?, 'failed', ?)"
            );
            $stmt->execute([$filename, $hash, $error]);
        } catch (Exception $e) {
            $this->logger->error("Failed to mark file as failed: " . $e->getMessage());
        }
    }

    /**
     * Store XML metadata
     */
    private function storeMetadata(int $fileId, SimpleXMLElement $xml): void
    {
        try {
            // Extract and store attributes as metadata
            foreach ($xml->attributes() as $key => $value) {
                $this->insertMetadata($fileId, $key, (string)$value);
            }

            // Store namespace information if present
            $namespaces = $xml->getNamespaces(true);
            if (!empty($namespaces)) {
                $this->insertMetadata($fileId, 'namespaces', json_encode($namespaces));
            }
        } catch (Exception $e) {
            $this->logger->warning("Failed to store metadata: " . $e->getMessage());
        }
    }

    /**
     * Insert metadata record
     */
    private function insertMetadata(int $fileId, string $key, string $value): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO xml_metadata (file_id, metadata_key, metadata_value)
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$fileId, $key, $value]);
    }
}
