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
     * Topic-bearing snapshot keys whose new values feed `addedTopics` on
     * an Updated intent.
     */
    private const TOPIC_KEYS = ['agencies', 'jurisdictions', 'units'];

    /**
     * @param array<string,mixed>|null $existing  Snapshot of the row prior to this XML, or null if call_id is new.
     * @param array<string,mixed>      $incoming  Snapshot built from the parsed XML.
     * @return array{0:?Intent,1:string[],2:string[]}  Tuple of (Intent|null, changedFields, addedTopics).
     *   addedTopics is the set difference incoming \ existing across agencies/jurisdictions/units;
     *   empty for Created and Closed (Created naturally fans out to all derived topics).
     */
    public static function resolve(?array $existing, array $incoming): array
    {
        if ($existing === null) {
            return [Intent::Created, [], []];
        }
        if (($incoming['closed_flag'] ?? false) === true) {
            return [Intent::Closed, [], []];
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
            return [null, [], []];
        }

        $added = [];
        foreach (self::TOPIC_KEYS as $key) {
            $oldSet = self::splitPipe((string) ($existing[$key] ?? ''));
            $newSet = self::splitPipe((string) ($incoming[$key] ?? ''));
            foreach (array_diff($newSet, $oldSet) as $t) {
                $added[] = $t;
            }
        }

        return [Intent::Updated, $changed, array_values(array_unique($added))];
    }

    /** @return string[] */
    private static function splitPipe(string $value): array
    {
        if ($value === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode('|', $value)), static fn (string $v): bool => $v !== ''));
    }
}
