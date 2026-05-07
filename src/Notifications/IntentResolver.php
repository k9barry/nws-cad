<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use NwsCad\Notifications\Events\Intent;

final class IntentResolver
{
    private const FIELD_MAP = [
        'call_type'    => 'call_type',
        'full_address' => 'full_address',
        'alarm_level'  => 'alarm_level',
        'units'        => 'assigned_units',
        'jurisdictions'=> 'jurisdictions',
        'agencies'     => 'agencies',
    ];

    /**
     * @param array<string,mixed>|null $existing  Snapshot of the row prior to this XML, or null if call_id is new.
     * @param array<string,mixed>      $incoming  Snapshot built from the parsed XML.
     * @return array{0:?Intent,1:string[]}
     */
    public static function resolve(?array $existing, array $incoming): array
    {
        if ($existing === null) {
            return [Intent::Created, []];
        }
        if (($incoming['closed_flag'] ?? false) === true) {
            return [Intent::Closed, []];
        }

        $changed = [];
        foreach (self::FIELD_MAP as $key => $reportedAs) {
            $a = $existing[$key] ?? null;
            $b = $incoming[$key] ?? null;
            if ((string) $a !== (string) $b) {
                $changed[] = $reportedAs;
            }
        }

        if ($changed === []) {
            return [null, []];
        }
        return [Intent::Updated, $changed];
    }
}
