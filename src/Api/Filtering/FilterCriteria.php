<?php
declare(strict_types=1);

namespace NwsCad\Api\Filtering;

use DateTimeZone;

final class FilterCriteria
{
    private const MAX_VALUES_PER_FIELD = 50;
    private const MAX_VALUE_LENGTH = 256;
    private const VALID_STATUSES = ['open', 'closed', 'canceled'];
    private const VALID_DATE_FIELDS = ['created', 'closed'];

    /**
     * @param string[] $callType
     * @param string[] $incidentType
     * @param string[] $agency
     * @param string[] $ori
     * @param string[] $fdid
     * @param string[] $beat
     * @param string[] $area
     * @param string[] $city
     * @param string[] $callId
     * @param string[] $unit
     * @param string[] $status
     */
    public function __construct(
        public readonly ?DateRange $dateRange,
        public readonly string $dateField,
        public readonly array $callType,
        public readonly array $incidentType,
        public readonly array $agency,
        public readonly array $ori,
        public readonly array $fdid,
        public readonly array $beat,
        public readonly array $area,
        public readonly array $city,
        public readonly ?string $location,
        public readonly ?string $natureOfCall,
        public readonly array $callId,
        public readonly array $unit,
        public readonly array $status,
        public readonly ?string $search,
    ) {}

    /**
     * @param array<string, mixed> $query
     * @param string[] $allowed
     */
    public static function fromQuery(array $query, array $allowed): self
    {
        $get = static function (string $key) use ($query, $allowed): ?string {
            if (!in_array($key, $allowed, true)) {
                return null;
            }
            $v = $query[$key] ?? null;
            if (!is_string($v) || $v === '') {
                return null;
            }
            return $v;
        };

        $csv = static function (?string $raw, string $field): array {
            if ($raw === null) {
                return [];
            }
            $values = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== ''));
            if (count($values) > self::MAX_VALUES_PER_FIELD) {
                throw new InvalidFilterException("Too many values for filter {$field} (max " . self::MAX_VALUES_PER_FIELD . ")");
            }
            foreach ($values as $v) {
                if (strlen($v) > self::MAX_VALUE_LENGTH) {
                    throw new InvalidFilterException("Value too long for filter {$field} (max " . self::MAX_VALUE_LENGTH . " chars)");
                }
            }
            return $values;
        };

        $single = static function (?string $raw, string $field): ?string {
            if ($raw === null) return null;
            if (strlen($raw) > self::MAX_VALUE_LENGTH) {
                throw new InvalidFilterException("Value too long for filter {$field} (max " . self::MAX_VALUE_LENGTH . " chars)");
            }
            return $raw;
        };

        $tz = new DateTimeZone(getenv('APP_TZ') ?: 'America/Indiana/Indianapolis');

        // Date range: explicit from/to wins over preset
        $dateRange = null;
        $from = $get('from');
        $to   = $get('to');
        if ($from !== null || $to !== null) {
            $dateRange = DateRange::fromExplicit($from, $to, $tz);
        } elseif (($preset = $get('preset')) !== null) {
            try {
                $dateRange = DateRange::fromPreset($preset, $tz);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidFilterException($e->getMessage());
            }
        }

        $dateField = $get('date_field') ?? 'created';
        if (!in_array($dateField, self::VALID_DATE_FIELDS, true)) {
            throw new InvalidFilterException("Invalid date_field: {$dateField}");
        }

        $status = $csv($get('status'), 'status');
        foreach ($status as $s) {
            if (!in_array($s, self::VALID_STATUSES, true)) {
                throw new InvalidFilterException("Invalid status value: {$s}");
            }
        }

        return new self(
            dateRange:    $dateRange,
            dateField:    $dateField,
            callType:     $csv($get('call_type'), 'call_type'),
            incidentType: $csv($get('incident_type'), 'incident_type'),
            agency:       $csv($get('agency'), 'agency'),
            ori:          $csv($get('ori'), 'ori'),
            fdid:         $csv($get('fdid'), 'fdid'),
            beat:         $csv($get('beat'), 'beat'),
            area:         $csv($get('area'), 'area'),
            city:         $csv($get('city'), 'city'),
            location:     $single($get('location'), 'location'),
            natureOfCall: $single($get('nature_of_call'), 'nature_of_call'),
            callId:       $csv($get('call_id'), 'call_id'),
            unit:         $csv($get('unit'), 'unit'),
            status:       $status,
            search:       $single($get('q'), 'q'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date_field'     => $this->dateField,
            'from'           => $this->dateRange?->from->format('c'),
            'to'             => $this->dateRange?->to->format('c'),
            'call_type'      => $this->callType,
            'incident_type'  => $this->incidentType,
            'agency'         => $this->agency,
            'ori'            => $this->ori,
            'fdid'           => $this->fdid,
            'beat'           => $this->beat,
            'area'           => $this->area,
            'city'           => $this->city,
            'location'       => $this->location,
            'nature_of_call' => $this->natureOfCall,
            'call_id'        => $this->callId,
            'unit'           => $this->unit,
            'status'         => $this->status,
            'q'              => $this->search,
        ];
    }
}
