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
 * Writes the `agency_contexts` rows for a call (#49, extracted from AegisXmlParser).
 *
 * Write strategy: UPSERT on (call_id, agency_type) — one row per agency per call.
 * Mutating state (status, closed_flag, …) overwrites the prior snapshot rather
 * than accumulating. FDID comes from the XML when present, else a ref_agencies
 * lookup by label/code.
 */
final class AgencyContextMapper
{
    public function __construct(private PDO $db)
    {
    }

    public function map(SimpleXMLElement $xml, int $callId): void
    {
        if (!isset($xml->AgencyContexts->AgencyContext)) {
            return;
        }

        $sql = UpsertBuilder::upsert(
            Database::getDbType(),
            'agency_contexts',
            ['call_id', 'agency_type', 'fdid', 'call_type', 'priority', 'status', 'dispatcher',
             'created_datetime', 'closed_datetime', 'closed_flag', 'canceled_flag',
             'radio_channel', 'emd_case_number', 'emd_code'],
            ['call_id', 'agency_type'],
            ['fdid', 'call_type', 'priority', 'status', 'dispatcher',
             'created_datetime', 'closed_datetime', 'closed_flag', 'canceled_flag',
             'radio_channel', 'emd_case_number', 'emd_code']
        );
        $stmt = $this->db->prepare($sql);

        foreach ($xml->AgencyContexts->AgencyContext as $context) {
            $agencyType = (string)$context->AgencyType ?: null;

            // Extract FDID from XML; fall back to ref_agencies lookup if absent.
            $fdid = null;
            $fdidNode = $context->FDID ?? null;
            if ($fdidNode !== null && (string)$fdidNode !== '') {
                $fdid = (string)$fdidNode;
            }
            if ($fdid === null && $agencyType !== null && $agencyType !== '') {
                $lookup = $this->db->prepare(
                    'SELECT fdid FROM ref_agencies WHERE LOWER(label) = LOWER(:lbl) OR code = :code LIMIT 1'
                );
                $lookup->execute([':lbl' => $agencyType, ':code' => $agencyType]);
                $refRow = $lookup->fetch();
                if ($refRow && !empty($refRow['fdid'])) {
                    $fdid = (string)$refRow['fdid'];
                }
            }

            $stmt->execute([
                'call_id' => $callId,
                'agency_type' => $agencyType,
                'fdid' => $fdid,
                'call_type' => (string)$context->CallType ?: null,
                'priority' => (string)$context->Priority ?: null,
                'status' => (string)$context->Status ?: null,
                'dispatcher' => (string)$context->Dispatcher ?: null,
                'created_datetime' => DateTimeParser::parse((string)$context->CreatedDateTime),
                'closed_datetime' => DateTimeParser::parse((string)$context->ClosedDateTime),
                'closed_flag' => ValueCaster::toBool((string)$context->ClosedFlag),
                'canceled_flag' => ValueCaster::toBool((string)$context->CanceledFlag),
                'radio_channel' => (string)$context->RadioChannel ?: null,
                'emd_case_number' => (string)$context->EmdCaseNumber ?: null,
                'emd_code' => (string)$context->EmdCode ?: null,
            ]);
        }
    }
}
