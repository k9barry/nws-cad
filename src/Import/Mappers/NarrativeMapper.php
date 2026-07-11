<?php

declare(strict_types=1);

namespace NwsCad\Import\Mappers;

use NwsCad\Database;
use NwsCad\Db\UpsertBuilder;
use NwsCad\Import\DateTimeParser;
use PDO;
use SimpleXMLElement;

/**
 * Writes the `narratives` rows for a call (#49, extracted from AegisXmlParser).
 *
 * Write strategy: additive INSERT-IGNORE (ON CONFLICT DO NOTHING). Narratives
 * are append-only; a de-dupe key of (call_id, create_datetime, create_user, text)
 * skips rows already recorded on reprocess. create_user defaults to '' rather
 * than null so the unique constraint behaves.
 */
final class NarrativeMapper
{
    public function __construct(private PDO $db)
    {
    }

    public function map(SimpleXMLElement $xml, int $callId): void
    {
        if (!isset($xml->Narratives->Narrative)) {
            return;
        }

        $sql = UpsertBuilder::insertIgnore(
            Database::getDbType(),
            'narratives',
            ['call_id', 'create_datetime', 'create_user', 'narrative_type', 'text', 'restriction'],
            ['call_id', 'create_datetime', 'create_user', 'text']
        );

        $stmt = $this->db->prepare($sql);

        foreach ($xml->Narratives->Narrative as $narrative) {
            $data = [
                'call_id' => $callId,
                'create_datetime' => DateTimeParser::parse((string)$narrative->CreateDateTime),
                'create_user' => (string)$narrative->CreateUser ?: '', // Empty string instead of null for unique constraint
                'narrative_type' => (string)$narrative->Type ?: null,
                'text' => (string)$narrative->Text ?: null,
                'restriction' => (string)$narrative->Restriction ?: null
            ];
            $stmt->execute($data);
        }
    }
}
