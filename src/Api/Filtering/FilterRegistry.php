<?php
declare(strict_types=1);

namespace NwsCad\Api\Filtering;

final class FilterRegistry
{
    /** @var array<string, string[]> */
    private const ALLOWLISTS = [
        'calls' => [
            'preset', 'from', 'to', 'date_field',
            'call_type', 'incident_type', 'nature_of_call',
            'agency', 'ori', 'fdid',
            'beat', 'area', 'city', 'location',
            'call_id', 'unit', 'status', 'q',
            'page', 'per_page', 'sort', 'order',
        ],
        'units' => [
            'preset', 'from', 'to', 'date_field',
            'agency', 'unit', 'status', 'call_id',
            'page', 'per_page', 'sort', 'order',
        ],
        'stats' => [
            'preset', 'from', 'to', 'date_field',
            'agency', 'ori', 'fdid', 'city', 'call_type',
        ],
    ];

    /** @return string[] */
    public static function for(string $controller): array
    {
        return self::ALLOWLISTS[$controller] ?? [];
    }
}
