<?php

declare(strict_types=1);

namespace NwsCad\Import\Mappers;

use PDO;
use SimpleXMLElement;

/**
 * Writes the `vehicles` rows for a call (#49, extracted from AegisXmlParser).
 *
 * Write strategy: delete-then-insert. CAD emits the full vehicle snapshot each
 * time, so the latest XML is authoritative and prior rows are replaced wholesale.
 */
final class VehicleMapper
{
    public function __construct(private PDO $db)
    {
    }

    public function map(SimpleXMLElement $xml, int $callId): void
    {
        $deleteStmt = $this->db->prepare("DELETE FROM vehicles WHERE call_id = ?");
        $deleteStmt->execute([$callId]);

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
            $stmt->execute([
                'call_id' => $callId,
                'license_plate' => (string)$vehicle->LicensePlate ?: null,
                'license_state' => (string)$vehicle->LicenseState ?: null,
                'make' => (string)$vehicle->Make ?: null,
                'model' => (string)$vehicle->Model ?: null,
                'year' => (int)$vehicle->Year ?: null,
                'color' => (string)$vehicle->Color ?: null,
                'vin' => (string)$vehicle->VIN ?: null,
                'vehicle_type' => (string)$vehicle->Type ?: null,
            ]);
        }
    }
}
