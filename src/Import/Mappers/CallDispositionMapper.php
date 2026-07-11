<?php

declare(strict_types=1);

namespace NwsCad\Import\Mappers;

use NwsCad\Import\DateTimeParser;
use NwsCad\Import\ValueCaster;
use PDO;
use SimpleXMLElement;

/**
 * Writes the `call_dispositions` rows for a call (#49, extracted from AegisXmlParser).
 *
 * Write strategy: delete-then-insert. CAD emits the full disposition snapshot
 * each time, so the latest XML is authoritative and prior rows are replaced.
 */
final class CallDispositionMapper
{
    public function __construct(private PDO $db)
    {
    }

    public function map(SimpleXMLElement $xml, int $callId): void
    {
        $deleteStmt = $this->db->prepare("DELETE FROM call_dispositions WHERE call_id = ?");
        $deleteStmt->execute([$callId]);

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
            $stmt->execute([
                'call_id' => $callId,
                'disposition_name' => (string)$disp->Name ?: null,
                'description' => (string)$disp->Description ?: null,
                'count' => ValueCaster::toInt((string)$disp->Count),
                'disposition_datetime' => DateTimeParser::parse((string)$disp->DateTime),
            ]);
        }
    }
}
