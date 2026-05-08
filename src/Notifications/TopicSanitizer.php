<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

final class TopicSanitizer
{
    public static function clean(string $segment): ?string
    {
        // Replace any character outside [A-Za-z0-9_-] with '_'.
        $cleaned = preg_replace('/[^A-Za-z0-9_-]/', '_', $segment) ?? '';
        // Collapse runs of '_' and trim leading/trailing '_'.
        $cleaned = trim((string) preg_replace('/_+/', '_', $cleaned), '_');
        return $cleaned === '' ? null : $cleaned;
    }
}
