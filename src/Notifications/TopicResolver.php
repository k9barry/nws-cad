<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use NwsCad\Notifications\Events\Intent;

final class TopicResolver
{
    private const RESEND_ALL_TRIGGERS = ['call_type', 'full_address', 'alarm_level'];

    /** @param string[] $changedFields */
    public static function shouldResendAll(Intent $intent, array $changedFields): bool
    {
        if ($intent === Intent::Created) {
            return true;
        }
        if ($intent === Intent::Updated) {
            return count(array_intersect(self::RESEND_ALL_TRIGGERS, $changedFields)) > 0;
        }
        return false;
    }

    /**
     * @param string[] $addedTopics
     * @return string[]
     */
    public static function resolveTopics(IncidentDto $dto, bool $resendAll, array $addedTopics): array
    {
        if ($resendAll) {
            return self::buildAllTopics($dto);
        }
        return array_values(array_unique(array_filter(
            $addedTopics,
            static fn ($v): bool => $v !== null && $v !== '',
        )));
    }

    /** @return string[] */
    private static function buildAllTopics(IncidentDto $dto): array
    {
        $parts = [];
        if ($dto->agencyType !== null && $dto->agencyType !== '') {
            $parts[] = $dto->agencyType;
        }
        foreach (self::splitPipe($dto->jurisdiction ?? '') as $j) {
            $parts[] = $j;
        }
        foreach (self::splitPipe($dto->units) as $u) {
            $parts[] = $u;
        }
        return array_values(array_unique($parts));
    }

    /** @return string[] */
    private static function splitPipe(string $value): array
    {
        if ($value === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode('|', $value)),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
