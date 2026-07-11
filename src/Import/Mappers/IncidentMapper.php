<?php

declare(strict_types=1);

namespace NwsCad\Import\Mappers;

use NwsCad\Database;
use NwsCad\Db\UpsertBuilder;
use NwsCad\Import\DateTimeParser;
use PDO;
use SimpleXMLElement;

/**
 * Writes the `incidents` rows for a call (#49, extracted from AegisXmlParser).
 *
 * Write strategy: UPSERT on (call_id, incident_number) — one row per
 * incident_number per call; CAD-side updates overwrite in place.
 */
final class IncidentMapper
{
    public function __construct(private PDO $db)
    {
    }

    public function map(SimpleXMLElement $xml, int $callId): void
    {
        if (!isset($xml->Incidents->Incident)) {
            return;
        }

        $sql = UpsertBuilder::upsert(
            Database::getDbType(),
            'incidents',
            ['call_id', 'incident_number', 'incident_type', 'type_description',
             'agency_type', 'case_number', 'jurisdiction', 'create_datetime'],
            ['call_id', 'incident_number'],
            ['incident_type', 'type_description', 'agency_type', 'case_number',
             'jurisdiction', 'create_datetime']
        );
        $stmt = $this->db->prepare($sql);

        foreach ($xml->Incidents->Incident as $incident) {
            $stmt->execute([
                'call_id' => $callId,
                'incident_number' => (string)$incident->Number ?: null,
                'incident_type' => (string)$incident->Type ?: null,
                'type_description' => (string)$incident->TypeDescription ?: null,
                'agency_type' => (string)$incident->AgencyType ?: null,
                'case_number' => (string)$incident->CaseNumber ?: null,
                'jurisdiction' => (string)$incident->Jurisdiction ?: null,
                'create_datetime' => DateTimeParser::parse((string)$incident->CreateDateTime),
            ]);
        }
    }
}
