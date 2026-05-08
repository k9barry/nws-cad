<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

final class IncidentDto
{
    private function __construct(
        public readonly int $dbCallId,
        public readonly int $callId,
        public readonly string $callNumber,
        public readonly ?string $callType,
        public readonly ?string $agencyType,
        public readonly ?string $jurisdiction,
        public readonly string $units,
        public readonly ?string $commonName,
        public readonly ?string $fullAddress,
        public readonly ?string $nearestCrossStreets,
        public readonly ?string $policeBeat,
        public readonly ?string $fireQuadrant,
        public readonly ?string $natureOfCall,
        public readonly ?string $narrative,
        public readonly int $alarmLevel,
        public readonly string $createDateTime,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $strOrNull = static fn (string $k): ?string =>
            isset($row[$k]) && $row[$k] !== '' ? (string) $row[$k] : null;
        $floatOrNull = static fn (string $k): ?float =>
            isset($row[$k]) && $row[$k] !== '' ? (float) $row[$k] : null;

        return new self(
            dbCallId: (int) ($row['id'] ?? 0),
            callId: (int) ($row['call_id'] ?? 0),
            callNumber: (string) ($row['call_number'] ?? ''),
            callType: $strOrNull('call_type'),
            agencyType: $strOrNull('agency_type'),
            jurisdiction: $strOrNull('jurisdiction'),
            units: (string) ($row['units'] ?? ''),
            commonName: $strOrNull('common_name'),
            fullAddress: $strOrNull('full_address'),
            nearestCrossStreets: $strOrNull('nearest_cross_streets'),
            policeBeat: $strOrNull('police_beat'),
            fireQuadrant: $strOrNull('fire_quadrant'),
            natureOfCall: $strOrNull('nature_of_call'),
            narrative: $strOrNull('narrative'),
            alarmLevel: (int) ($row['alarm_level'] ?? 0),
            createDateTime: (string) ($row['create_datetime'] ?? ''),
            latitude: $floatOrNull('latitude'),
            longitude: $floatOrNull('longitude'),
        );
    }

    public function mapUrl(): ?string
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }
        return sprintf(
            'https://www.google.com/maps/dir/?api=1&destination=%s,%s',
            $this->latitude,
            $this->longitude,
        );
    }
}
