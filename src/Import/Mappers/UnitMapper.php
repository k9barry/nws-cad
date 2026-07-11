<?php

declare(strict_types=1);

namespace NwsCad\Import\Mappers;

use NwsCad\Database;
use NwsCad\Db\UpsertBuilder;
use NwsCad\Import\DateTimeParser;
use NwsCad\Import\ValueCaster;
use PDO;
use SimpleXMLElement;

/**
 * Writes the unit family for a call (#49, extracted from AegisXmlParser): the
 * `units` rows plus each unit's personnel, logs, and dispositions.
 *
 * Write strategies (deliberately different per sub-table):
 *  - units: UPSERT on (call_id, unit_number); RETURNING id on pgsql, re-SELECT
 *    on mysql (which cannot RETURN from ON DUPLICATE KEY UPDATE).
 *  - unit_personnel: delete-then-insert (full snapshot; handles removals).
 *  - unit_logs: additive INSERT-IGNORE (append-only, de-duped).
 *  - unit_dispositions: delete-then-insert (handles updates/removals).
 */
final class UnitMapper
{
    public function __construct(private PDO $db)
    {
    }

    public function map(SimpleXMLElement $xml, int $callId): void
    {
        if (!isset($xml->AssignedUnits->Unit)) {
            return;
        }

        $dbType = Database::getDbType();

        $unitColumns = [
            'call_id', 'unit_number', 'unit_type', 'is_primary', 'jurisdiction',
            'assigned_datetime', 'dispatch_datetime', 'enroute_datetime', 'arrive_datetime',
            'staged_datetime', 'at_patient_datetime', 'transport_datetime',
            'at_hospital_datetime', 'depart_hospital_datetime', 'clear_datetime',
        ];
        $sql = UpsertBuilder::upsert(
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

        foreach ($xml->AssignedUnits->Unit as $unit) {
            $data = [
                'call_id' => $callId,
                'unit_number' => (string)$unit->UnitNumber ?: null,
                'unit_type' => (string)$unit->Type ?: null,
                'is_primary' => ValueCaster::toBool((string)$unit->IsPrimary),
                'jurisdiction' => (string)$unit->Jurisdiction ?: null,
                'assigned_datetime' => DateTimeParser::parse((string)$unit->AssignedDateTime),
                'dispatch_datetime' => DateTimeParser::parse((string)$unit->DispatchDateTime),
                'enroute_datetime' => DateTimeParser::parse((string)$unit->EnrouteDateTime),
                'arrive_datetime' => DateTimeParser::parse((string)$unit->ArriveDateTime),
                'staged_datetime' => DateTimeParser::parse((string)$unit->StagedDateTime),
                'at_patient_datetime' => DateTimeParser::parse((string)$unit->AtPatientDateTime),
                'transport_datetime' => DateTimeParser::parse((string)$unit->TransportDateTime),
                'at_hospital_datetime' => DateTimeParser::parse((string)$unit->AtHospitalDateTime),
                'depart_hospital_datetime' => DateTimeParser::parse((string)$unit->DepartHospitalDateTime),
                'clear_datetime' => DateTimeParser::parse((string)$unit->ClearDateTime)
            ];

            $stmt = $this->db->prepare($sql);
            $stmt->execute($data);

            // Get the unit_id
            if ($dbType === 'mysql') {
                // MySQL does not support RETURNING with INSERT ... ON DUPLICATE KEY UPDATE,
                // so we need to query the id after the upsert.
                $idStmt = $this->db->prepare("SELECT id FROM units WHERE call_id = ? AND unit_number = ?");
                $idStmt->execute([$callId, $data['unit_number']]);
                $unitId = (int)$idStmt->fetchColumn();
            } else {
                // PostgreSQL: use the id returned by INSERT ... ON CONFLICT ... DO UPDATE RETURNING id
                $unitId = (int)$stmt->fetchColumn();
            }

            $this->mapPersonnel($unit, $unitId);
            $this->mapLogs($unit, $unitId);
            $this->mapDispositions($unit, $unitId);
        }
    }

    private function mapPersonnel(SimpleXMLElement $unit, int $unitId): void
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
                'is_primary_officer' => ValueCaster::toBool((string)$personnel->IsPrimaryOfficer),
                'jurisdiction' => (string)$personnel->Jurisdiction ?: null
            ];
            $stmt->execute($data);
        }
    }

    private function mapLogs(SimpleXMLElement $unit, int $unitId): void
    {
        if (!isset($unit->UnitLogs->UnitLog)) {
            return;
        }

        $sql = UpsertBuilder::insertIgnore(
            Database::getDbType(),
            'unit_logs',
            ['unit_id', 'log_datetime', 'status', 'location'],
            ['unit_id', 'log_datetime', 'status', 'location']
        );

        $stmt = $this->db->prepare($sql);

        foreach ($unit->UnitLogs->UnitLog as $log) {
            $data = [
                'unit_id' => $unitId,
                'log_datetime' => DateTimeParser::parse((string)$log->DateTime),
                'status' => (string)$log->Status ?: null,
                'location' => (string)$log->Location ?: '' // Empty string instead of null for unique constraint
            ];
            $stmt->execute($data);
        }
    }

    private function mapDispositions(SimpleXMLElement $unit, int $unitId): void
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
                'count' => ValueCaster::toInt((string)$disp->Count),
                'disposition_datetime' => DateTimeParser::parse((string)$disp->DateTime)
            ];
            $stmt->execute($data);
        }
    }
}
