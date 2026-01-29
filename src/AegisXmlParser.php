<?php

declare(strict_types=1);

namespace NwsCad;

use SimpleXMLElement;
use Exception;
use PDO;

/**
 * NWS Aegis CAD XML Parser
 * Parses New World Systems Aegis CAD export XML and stores in database
 *
 * @package NwsCad
 */
class AegisXmlParser
{
    private PDO $db;
    private $logger;
    private array $namespaces = [
        '' => 'http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02',
        'i' => 'http://www.w3.org/2001/XMLSchema-instance'
    ];

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->logger = Logger::getInstance();
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
            $this->logger->info("Processing Aegis CAD XML file: " . basename($filePath));
            
            // Check if already processed
            if ($this->isFileProcessed(basename($filePath), $filePath)) {
                $this->logger->info("File already processed, skipping: " . basename($filePath));
                return true;
            }
            
            // Load XML with security measures
            $xml = $this->loadXmlFile($filePath);
            
            if ($xml === false) {
                throw new Exception('Failed to load XML file');
            }

            // Register namespaces
            foreach ($this->namespaces as $prefix => $uri) {
                $xml->registerXPathNamespace($prefix, $uri);
            }

            // Begin transaction
            $this->db->beginTransaction();

            // Parse and insert call data
            $callId = $this->insertCall($xml, $filePath);

            // Mark file as processed
            $this->markFileAsProcessed(basename($filePath), $filePath, 1);

            // Commit transaction
            $this->db->commit();

            $this->logger->info("Successfully processed file: " . basename($filePath) . " (Call ID: $callId)");
            return true;

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            
            $this->logger->error("Error processing file " . basename($filePath) . ": " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            $this->markFileAsFailed(basename($filePath), $filePath, $e->getMessage());
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
        try {
            if (!file_exists($filePath)) {
                $this->logger->error("File does not exist: {$filePath}");
                return false;
            }
            
            // Read file content and strip BOM if present
            $content = file_get_contents($filePath);
            if ($content === false) {
                $this->logger->error("Failed to read file: {$filePath}");
                return false;
            }
            
            // Remove UTF-8 BOM (EF BB BF) if present
            // Many NWS CAD XML exports include BOM which can cause parsing issues
            $content = $this->stripBOM($content);
            
            // XXE Protection: In PHP 8.0+, external entity loading is disabled by default
            // LIBXML_NONET prevents network access during XML parsing
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                $errorMessages = array_map(fn($error) => trim($error->message), $errors);
                libxml_clear_errors();
                
                $this->logger->error("Failed to load XML: " . implode(', ', $errorMessages));
                return false;
            }
            
            libxml_clear_errors();
            return $xml;
            
        } catch (Exception $e) {
            $this->logger->error("Exception loading XML file: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Strip BOM (Byte Order Mark) from content
     * Handles UTF-8, UTF-16 BE, and UTF-16 LE BOMs
     *
     * @param string $content File content
     * @return string Content without BOM
     */
    private function stripBOM(string $content): string
    {
        // Check UTF-16 BOMs first (2 bytes) before UTF-8 (3 bytes)
        // UTF-16 BE BOM is FE FF
        if (strlen($content) >= 2 && substr($content, 0, 2) === "\xFE\xFF") {
            $this->logger->debug("Stripped UTF-16 BE BOM from XML file");
            return substr($content, 2);
        }
        
        // UTF-16 LE BOM is FF FE
        if (strlen($content) >= 2 && substr($content, 0, 2) === "\xFF\xFE") {
            $this->logger->debug("Stripped UTF-16 LE BOM from XML file");
            return substr($content, 2);
        }
        
        // UTF-8 BOM is EF BB BF (3 bytes)
        if (strlen($content) >= 3 && substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $this->logger->debug("Stripped UTF-8 BOM from XML file");
            return substr($content, 3);
        }
        
        return $content;
    }

    /**
     * Insert main call record and all child records
     *
     * @param SimpleXMLElement $xml XML root element
     * @param string $filePath Original file path
     * @return int Database call ID
     */
    private function insertCall(SimpleXMLElement $xml, string $filePath): int
    {
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

        // Check if call already exists
        $existingCallStmt = $this->db->prepare("SELECT id FROM calls WHERE call_id = ?");
        $existingCallStmt->execute([$callData['call_id']]);
        $existingCall = $existingCallStmt->fetch();

        if ($existingCall) {
            // Update existing call
            $this->logger->info("Call ID {$callData['call_id']} already exists, updating record");
            
            $dbCallId = (int)$existingCall['id'];
            
            // Delete existing child records to replace with new data
            $this->deleteChildRecords($dbCallId);
            
            // Update the call record
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
                alarm_level = :alarm_level,
                emd_code = :emd_code,
                fire_controlled_time = :fire_controlled_time,
                xml_data = :xml_data,
                updated_at = CURRENT_TIMESTAMP
                WHERE id = :id";
            
            $updateData = $callData;
            $updateData['id'] = $dbCallId;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($updateData);
        } else {
            // Insert new call
            $sql = "INSERT INTO calls (
                call_id, call_number, call_source, caller_name, caller_phone,
                nature_of_call, additional_info, create_datetime, close_datetime,
                created_by, closed_flag, canceled_flag, alarm_level, emd_code,
                fire_controlled_time, xml_data
            ) VALUES (
                :call_id, :call_number, :call_source, :caller_name, :caller_phone,
                :nature_of_call, :additional_info, :create_datetime, :close_datetime,
                :created_by, :closed_flag, :canceled_flag, :alarm_level, :emd_code,
                :fire_controlled_time, :xml_data
            )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($callData);
            $dbCallId = (int)$this->db->lastInsertId();
        }

        // Insert child records
        $this->insertAgencyContexts($xml, $dbCallId);
        $this->insertLocation($xml, $dbCallId);
        $this->insertIncidents($xml, $dbCallId);
        $this->insertUnits($xml, $dbCallId);
        $this->insertNarratives($xml, $dbCallId);
        $this->insertPersons($xml, $dbCallId);
        $this->insertVehicles($xml, $dbCallId);
        $this->insertCallDispositions($xml, $dbCallId);

        return $dbCallId;
    }

    /**
     * Delete all child records for a call
     * Used when updating an existing call to replace all child data
     * Relies on database ON DELETE CASCADE constraints for efficiency
     *
     * @param int $callId Database call ID
     * @return void
     */
    private function deleteChildRecords(int $callId): void
    {
        $this->logger->debug("Deleting existing child records for call ID: $callId");
        
        // Delete child records directly - ON DELETE CASCADE will handle nested relationships
        // The order matters: delete records without foreign keys first, then those that reference them
        $this->db->prepare("DELETE FROM call_dispositions WHERE call_id = ?")->execute([$callId]);
        $this->db->prepare("DELETE FROM vehicles WHERE call_id = ?")->execute([$callId]);
        $this->db->prepare("DELETE FROM persons WHERE call_id = ?")->execute([$callId]);
        $this->db->prepare("DELETE FROM narratives WHERE call_id = ?")->execute([$callId]);
        
        // Deleting units will cascade to unit_logs, unit_personnel, and unit_dispositions
        $this->db->prepare("DELETE FROM units WHERE call_id = ?")->execute([$callId]);
        
        $this->db->prepare("DELETE FROM incidents WHERE call_id = ?")->execute([$callId]);
        $this->db->prepare("DELETE FROM locations WHERE call_id = ?")->execute([$callId]);
        $this->db->prepare("DELETE FROM agency_contexts WHERE call_id = ?")->execute([$callId]);
    }

    /**
     * Insert agency contexts
     */
    private function insertAgencyContexts(SimpleXMLElement $xml, int $callId): void
    {
        if (!isset($xml->AgencyContexts->AgencyContext)) {
            return;
        }

        $sql = "INSERT INTO agency_contexts (
            call_id, agency_type, call_type, priority, status, dispatcher,
            created_datetime, closed_datetime, closed_flag, canceled_flag,
            radio_channel, emd_case_number, emd_code
        ) VALUES (
            :call_id, :agency_type, :call_type, :priority, :status, :dispatcher,
            :created_datetime, :closed_datetime, :closed_flag, :canceled_flag,
            :radio_channel, :emd_case_number, :emd_code
        )";

        $stmt = $this->db->prepare($sql);

        foreach ($xml->AgencyContexts->AgencyContext as $context) {
            $data = [
                'call_id' => $callId,
                'agency_type' => (string)$context->AgencyType ?: null,
                'call_type' => (string)$context->CallType ?: null,
                'priority' => (string)$context->Priority ?: null,
                'status' => (string)$context->Status ?: null,
                'dispatcher' => (string)$context->Dispatcher ?: null,
                'created_datetime' => $this->parseDateTime((string)$context->CreatedDateTime),
                'closed_datetime' => $this->parseDateTime((string)$context->ClosedDateTime),
                'closed_flag' => $this->parseBoolean((string)$context->ClosedFlag),
                'canceled_flag' => $this->parseBoolean((string)$context->CanceledFlag),
                'radio_channel' => (string)$context->RadioChannel ?: null,
                'emd_case_number' => (string)$context->EmdCaseNumber ?: null,
                'emd_code' => (string)$context->EmdCode ?: null
            ];
            $stmt->execute($data);
        }
    }

    /**
     * Insert location
     */
    private function insertLocation(SimpleXMLElement $xml, int $callId): void
    {
        if (!isset($xml->Location)) {
            return;
        }

        $loc = $xml->Location;
        
        $data = [
            'call_id' => $callId,
            'full_address' => (string)$loc->FullAddress ?: null,
            'house_number' => (string)$loc->HouseNumber ?: null,
            'house_number_suffix' => (string)$loc->HouseNumberSuffix ?: null,
            'prefix_directional' => (string)$loc->PrefixDirectional ?: null,
            'prefix_type' => (string)$loc->PrefixType ?: null,
            'street_name' => (string)$loc->StreetName ?: null,
            'street_type' => (string)$loc->StreetType ?: null,
            'street_directional' => (string)$loc->StreetDirectional ?: null,
            'city' => (string)$loc->City ?: null,
            'state' => (string)$loc->State ?: null,
            'zip' => (string)$loc->Zip ?: null,
            'zip4' => (string)$loc->Zip4 ?: null,
            'venue' => (string)$loc->Venue ?: null,
            'latitude_y' => $this->parseDecimal((string)$loc->LatitudeY),
            'longitude_x' => $this->parseDecimal((string)$loc->LongitudeX),
            'common_name' => (string)$loc->CommonName ?: null,
            'police_beat' => (string)$loc->PoliceBeat ?: null,
            'ems_district' => (string)$loc->EmsDistrict ?: null,
            'fire_quadrant' => (string)$loc->FireQuadrant ?: null,
            'census_tract' => (string)$loc->CensusTract ?: null,
            'station_area' => (string)$loc->StationArea ?: null,
            'rural_grid' => (string)$loc->RuralGrid ?: null,
            'nearest_cross_streets' => (string)$loc->NearestCrossStreets ?: null,
            'additional_info' => (string)$loc->AdditionalInfo ?: null,
            'lat_lon_description' => (string)$loc->LatLonDescription ?: null,
            'qualifier' => (string)$loc->Qualifier ?: null,
            'custom_layer' => (string)$loc->CustomLayer ?: null,
            'police_ori' => (string)$loc->PoliceOri ?: null,
            'ems_ori' => (string)$loc->EmsOri ?: null,
            'fire_ori' => (string)$loc->FireOri ?: null,
            'x_street_name' => (string)$loc->XStreetName ?: null,
            'x_street_type' => (string)$loc->XStreetType ?: null,
            'x_prefix_directional' => (string)$loc->XPrefixDirectional ?: null,
            'x_street_directional' => (string)$loc->XStreetDirectional ?: null,
            'x_prefix_type' => (string)$loc->XPrefixType ?: null
        ];

        $sql = "INSERT INTO locations (
            call_id, full_address, house_number, house_number_suffix, prefix_directional,
            prefix_type, street_name, street_type, street_directional, city, state,
            zip, zip4, venue, latitude_y, longitude_x, common_name, police_beat,
            ems_district, fire_quadrant, census_tract, station_area, rural_grid,
            nearest_cross_streets, additional_info, lat_lon_description, qualifier,
            custom_layer, police_ori, ems_ori, fire_ori, x_street_name, x_street_type,
            x_prefix_directional, x_street_directional, x_prefix_type
        ) VALUES (
            :call_id, :full_address, :house_number, :house_number_suffix, :prefix_directional,
            :prefix_type, :street_name, :street_type, :street_directional, :city, :state,
            :zip, :zip4, :venue, :latitude_y, :longitude_x, :common_name, :police_beat,
            :ems_district, :fire_quadrant, :census_tract, :station_area, :rural_grid,
            :nearest_cross_streets, :additional_info, :lat_lon_description, :qualifier,
            :custom_layer, :police_ori, :ems_ori, :fire_ori, :x_street_name, :x_street_type,
            :x_prefix_directional, :x_street_directional, :x_prefix_type
        )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);
    }

    /**
     * Insert incidents
     */
    private function insertIncidents(SimpleXMLElement $xml, int $callId): void
    {
        if (!isset($xml->Incidents->Incident)) {
            return;
        }

        $sql = "INSERT INTO incidents (
            call_id, incident_number, incident_type, type_description,
            agency_type, case_number, jurisdiction, create_datetime
        ) VALUES (
            :call_id, :incident_number, :incident_type, :type_description,
            :agency_type, :case_number, :jurisdiction, :create_datetime
        )";

        $stmt = $this->db->prepare($sql);

        foreach ($xml->Incidents->Incident as $incident) {
            $data = [
                'call_id' => $callId,
                'incident_number' => (string)$incident->Number ?: null,
                'incident_type' => (string)$incident->Type ?: null,
                'type_description' => (string)$incident->TypeDescription ?: null,
                'agency_type' => (string)$incident->AgencyType ?: null,
                'case_number' => (string)$incident->CaseNumber ?: null,
                'jurisdiction' => (string)$incident->Jurisdiction ?: null,
                'create_datetime' => $this->parseDateTime((string)$incident->CreateDateTime)
            ];
            $stmt->execute($data);
        }
    }

    /**
     * Insert units and related data (personnel, logs, dispositions)
     */
    private function insertUnits(SimpleXMLElement $xml, int $callId): void
    {
        if (!isset($xml->AssignedUnits->Unit)) {
            return;
        }

        $sql = "INSERT INTO units (
            call_id, unit_number, unit_type, is_primary, jurisdiction,
            assigned_datetime, dispatch_datetime, enroute_datetime, arrive_datetime,
            staged_datetime, at_patient_datetime, transport_datetime,
            at_hospital_datetime, depart_hospital_datetime, clear_datetime
        ) VALUES (
            :call_id, :unit_number, :unit_type, :is_primary, :jurisdiction,
            :assigned_datetime, :dispatch_datetime, :enroute_datetime, :arrive_datetime,
            :staged_datetime, :at_patient_datetime, :transport_datetime,
            :at_hospital_datetime, :depart_hospital_datetime, :clear_datetime
        )";

        $stmt = $this->db->prepare($sql);

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
            
            $stmt->execute($data);
            $unitId = (int)$this->db->lastInsertId();

            // Insert personnel for this unit
            $this->insertUnitPersonnel($unit, $unitId);
            
            // Insert unit logs
            $this->insertUnitLogs($unit, $unitId);
            
            // Insert unit dispositions
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
     * Insert unit logs
     */
    private function insertUnitLogs(SimpleXMLElement $unit, int $unitId): void
    {
        if (!isset($unit->UnitLogs->UnitLog)) {
            return;
        }

        $sql = "INSERT INTO unit_logs (
            unit_id, log_datetime, status
        ) VALUES (
            :unit_id, :log_datetime, :status
        )";

        $stmt = $this->db->prepare($sql);

        foreach ($unit->UnitLogs->UnitLog as $log) {
            $data = [
                'unit_id' => $unitId,
                'log_datetime' => $this->parseDateTime((string)$log->DateTime),
                'status' => (string)$log->Status ?: null
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
     * Insert narratives
     */
    private function insertNarratives(SimpleXMLElement $xml, int $callId): void
    {
        if (!isset($xml->Narratives->Narrative)) {
            return;
        }

        $sql = "INSERT INTO narratives (
            call_id, create_datetime, create_user, narrative_type,
            text, restriction
        ) VALUES (
            :call_id, :create_datetime, :create_user, :narrative_type,
            :text, :restriction
        )";

        $stmt = $this->db->prepare($sql);

        foreach ($xml->Narratives->Narrative as $narrative) {
            $data = [
                'call_id' => $callId,
                'create_datetime' => $this->parseDateTime((string)$narrative->CreateDateTime),
                'create_user' => (string)$narrative->CreateUser ?: null,
                'narrative_type' => (string)$narrative->Type ?: null,
                'text' => (string)$narrative->Text ?: null,
                'restriction' => (string)$narrative->Restriction ?: null
            ];
            $stmt->execute($data);
        }
    }

    /**
     * Insert persons
     */
    private function insertPersons(SimpleXMLElement $xml, int $callId): void
    {
        if (!isset($xml->Persons->Person)) {
            return;
        }

        $sql = "INSERT INTO persons (
            call_id, first_name, middle_name, last_name, name_suffix,
            date_of_birth, sex, race, height_inches, weight,
            eye_color, hair_color, address, contact_phone, role,
            primary_caller_flag, license_number, license_state,
            ssn, global_subject_id
        ) VALUES (
            :call_id, :first_name, :middle_name, :last_name, :name_suffix,
            :date_of_birth, :sex, :race, :height_inches, :weight,
            :eye_color, :hair_color, :address, :contact_phone, :role,
            :primary_caller_flag, :license_number, :license_state,
            :ssn, :global_subject_id
        )";

        $stmt = $this->db->prepare($sql);

        foreach ($xml->Persons->Person as $person) {
            $data = [
                'call_id' => $callId,
                'first_name' => (string)$person->FirstName ?: null,
                'middle_name' => (string)$person->MiddleName ?: null,
                'last_name' => (string)$person->LastName ?: null,
                'name_suffix' => (string)$person->NameSuffix ?: null,
                'date_of_birth' => $this->parseDateTime((string)$person->DateOfBirth),
                'sex' => (string)$person->Sex ?: null,
                'race' => (string)$person->Race ?: null,
                'height_inches' => $this->parseDecimal((string)$person->HeightInches),
                'weight' => $this->parseDecimal((string)$person->Weight),
                'eye_color' => (string)$person->EyeColor ?: null,
                'hair_color' => (string)$person->HairColor ?: null,
                'address' => (string)$person->Address ?: null,
                'contact_phone' => (string)$person->ContactPhone ?: null,
                'role' => (string)$person->Role ?: null,
                'primary_caller_flag' => $this->parseBoolean((string)$person->PrimaryCallerFlag),
                'license_number' => (string)$person->LicenseNumber ?: null,
                'license_state' => (string)$person->LicenseState ?: null,
                'ssn' => (string)$person->SSN ?: null,
                'global_subject_id' => (string)$person->GlobalSubjectId ?: null
            ];
            $stmt->execute($data);
        }
    }

    /**
     * Insert vehicles
     */
    private function insertVehicles(SimpleXMLElement $xml, int $callId): void
    {
        if (!isset($xml->Vehicles->Vehicle)) {
            return;
        }

        $sql = "INSERT INTO vehicles (
            call_id, license_plate, license_state, make, model,
            year, color, vin, vehicle_type
        ) VALUES (
            :call_id, :license_plate, :license_state, :make, :model,
            :year, :color, :vin, :vehicle_type
        )";

        $stmt = $this->db->prepare($sql);

        foreach ($xml->Vehicles->Vehicle as $vehicle) {
            $data = [
                'call_id' => $callId,
                'license_plate' => (string)$vehicle->LicensePlate ?: null,
                'license_state' => (string)$vehicle->LicenseState ?: null,
                'make' => (string)$vehicle->Make ?: null,
                'model' => (string)$vehicle->Model ?: null,
                'year' => (int)$vehicle->Year ?: null,
                'color' => (string)$vehicle->Color ?: null,
                'vin' => (string)$vehicle->VIN ?: null,
                'vehicle_type' => (string)$vehicle->Type ?: null
            ];
            $stmt->execute($data);
        }
    }

    /**
     * Insert call dispositions
     *
     * @param SimpleXMLElement $xml XML root element
     * @param int $callId Database call ID
     * @return void
     */
    private function insertCallDispositions(SimpleXMLElement $xml, int $callId): void
    {
        if (!isset($xml->Dispositions->CallDisposition)) {
            return;
        }

        $sql = "INSERT INTO call_dispositions (
            call_id, disposition_name, description, count, disposition_datetime
        ) VALUES (
            :call_id, :disposition_name, :description, :count, :disposition_datetime
        )";

        $stmt = $this->db->prepare($sql);

        foreach ($xml->Dispositions->CallDisposition as $disp) {
            $data = [
                'call_id' => $callId,
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
        if (empty($dateTime) || $dateTime === 'nil' || strpos($dateTime, 'nil="true"') !== false) {
            return null;
        }
        
        // Common datetime formats from NWS Aegis
        $formats = [
            'Y-m-d\TH:i:s\Z',         // ISO 8601 with Z
            'Y-m-d\TH:i:s',           // ISO 8601 without timezone
            'Y-m-d\TH:i:s.u',         // ISO 8601 with microseconds
            'Y-m-d\TH:i:sP',          // ISO 8601 with timezone
            'Y-m-d\TH:i:s.uP',        // ISO 8601 with microseconds and timezone
            'Y-m-d H:i:s',            // Standard MySQL format
            'm/d/Y H:i:s',            // US format
            'm/d/Y h:i:s A',          // US format with AM/PM
        ];
        
        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $dateTime);
            if ($dt !== false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }
        
        // Try strtotime as fallback
        try {
            $timestamp = strtotime($dateTime);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        } catch (Exception $e) {
            $this->logger->warning("Could not parse datetime: '{$dateTime}'");
        }
        
        return null;
    }

    private function parseBoolean($value): int
    {
        $stringValue = strtolower(trim((string)$value));
        return in_array($stringValue, ['true', '1', 'yes'], true) ? 1 : 0;
    }

    private function parseInt(?string $value): ?int
    {
        if (empty($value) || $value === 'nil' || strpos($value, 'nil="true"') !== false) {
            return null;
        }
        return (int)$value;
    }

    private function parseDecimal(?string $value): ?float
    {
        if (empty($value) || $value === 'nil' || strpos($value, 'nil="true"') !== false) {
            return null;
        }
        return (float)$value;
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
        $hash = hash_file('sha256', $filePath);
        
        $stmt = $this->db->prepare(
            "SELECT id FROM processed_files WHERE filename = ? AND file_hash = ?"
        );
        $stmt->execute([$filename, $hash]);
        
        return $stmt->fetch() !== false;
    }

    private function markFileAsProcessed(string $filename, string $filePath, int $recordsProcessed): void
    {
        $hash = hash_file('sha256', $filePath);
        
        $stmt = $this->db->prepare(
            "INSERT INTO processed_files (filename, file_hash, status, records_processed)
             VALUES (?, ?, 'success', ?)"
        );
        $stmt->execute([$filename, $hash, $recordsProcessed]);
    }

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
}
