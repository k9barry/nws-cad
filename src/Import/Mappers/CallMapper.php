<?php

declare(strict_types=1);

namespace NwsCad\Import\Mappers;

use NwsCad\Import\DateTimeParser;
use NwsCad\Import\ValueCaster;
use PDO;
use SimpleXMLElement;

/**
 * Writes the top-level `calls` row and returns its database id (#49, extracted
 * from AegisXmlParser). The parser then dispatches the child mappers under that id.
 *
 * Write strategy: insert-or-update keyed on the CAD call_id. On update,
 * reopened_flag uses a CASE so a fresh close (incoming closed_flag = 1) clears
 * it, a detected reopen sets it, and otherwise the prior value is preserved.
 * The full document is stored as JSON in xml_data.
 */
final class CallMapper
{
    public function __construct(private PDO $db)
    {
    }

    public function map(SimpleXMLElement $xml, bool $detectedReopen): int
    {
        // Extract call data with proper type handling
        $callData = [
            'call_id' => ValueCaster::toInt((string)$xml->CallId),
            'call_number' => (string)$xml->CallNumber ?: null,
            'call_source' => (string)$xml->CallSource ?: null,
            'caller_name' => (string)$xml->CallerName ?: null,
            'caller_phone' => (string)$xml->CallerPhone ?: null,
            'nature_of_call' => (string)$xml->NatureOfCall ?: null,
            'additional_info' => (string)$xml->AdditionalInfo ?: null,
            'create_datetime' => DateTimeParser::parse((string)$xml->CreateDateTime) ?? date('Y-m-d H:i:s'),
            'close_datetime' => DateTimeParser::parse((string)$xml->CloseDateTime),
            'created_by' => (string)$xml->CreatedBy ?: null,
            'closed_flag' => ValueCaster::toBool((string)$xml->ClosedFlag),
            'canceled_flag' => ValueCaster::toBool((string)$xml->CanceledFlag),
            'alarm_level' => ValueCaster::toInt((string)$xml->AlarmLevel),
            'emd_code' => (string)$xml->EmdCode ?: null,
            'fire_controlled_time' => DateTimeParser::parse((string)$xml->FireControlledTime),
            'xml_data' => json_encode($this->xmlToArray($xml))
        ];

        // Check if call already exists
        $existingCallStmt = $this->db->prepare("SELECT id FROM calls WHERE call_id = ?");
        $existingCallStmt->execute([$callData['call_id']]);
        $existingCall = $existingCallStmt->fetch();

        if ($existingCall) {
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
        } else {
            // Insert new call. reopened_flag is set from detectedReopen directly;
            // for a brand-new call this is effectively always 0 (no prior close to reopen),
            // but we honour the parameter for symmetry.
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
        }

        return $dbCallId;
    }

    /**
     * @return array<string,mixed>
     */
    private function xmlToArray(SimpleXMLElement $xml): array
    {
        $json = json_encode($xml);
        return json_decode($json, true) ?? [];
    }
}
