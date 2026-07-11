<?php

declare(strict_types=1);

namespace NwsCad\Import\Mappers;

use NwsCad\Import\DateTimeParser;
use NwsCad\Import\ValueCaster;
use PDO;
use SimpleXMLElement;

/**
 * Writes the `persons` rows for a call (#49, extracted from AegisXmlParser).
 *
 * Write strategy: delete-then-insert. CAD emits the full person snapshot each
 * time, so the latest XML is authoritative and prior rows are replaced wholesale.
 */
final class PersonMapper
{
    public function __construct(private PDO $db)
    {
    }

    public function map(SimpleXMLElement $xml, int $callId): void
    {
        $deleteStmt = $this->db->prepare("DELETE FROM persons WHERE call_id = ?");
        $deleteStmt->execute([$callId]);

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
            $stmt->execute([
                'call_id' => $callId,
                'first_name' => (string)$person->FirstName ?: null,
                'middle_name' => (string)$person->MiddleName ?: null,
                'last_name' => (string)$person->LastName ?: null,
                'name_suffix' => (string)$person->NameSuffix ?: null,
                'date_of_birth' => DateTimeParser::parse((string)$person->DateOfBirth),
                'sex' => (string)$person->Sex ?: null,
                'race' => (string)$person->Race ?: null,
                'height_inches' => ValueCaster::toInt((string)$person->HeightInches),
                'weight' => ValueCaster::toInt((string)$person->Weight),
                'eye_color' => (string)$person->EyeColor ?: null,
                'hair_color' => (string)$person->HairColor ?: null,
                'address' => (string)$person->Address ?: null,
                'contact_phone' => (string)$person->ContactPhone ?: null,
                'role' => (string)$person->Role ?: null,
                'primary_caller_flag' => ValueCaster::toBool((string)$person->PrimaryCallerFlag),
                'license_number' => (string)$person->LicenseNumber ?: null,
                'license_state' => (string)$person->LicenseState ?: null,
                'ssn' => (string)$person->SSN ?: null,
                'global_subject_id' => (string)$person->GlobalSubjectId ?: null,
            ]);
        }
    }
}
