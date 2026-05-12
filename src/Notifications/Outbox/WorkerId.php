<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Outbox;

final class WorkerId
{
    private static ?string $cached = null;

    public static function current(): string
    {
        if (self::$cached === null) {
            $host = gethostname() ?: 'unknown';
            $pid  = getmypid() ?: 0;
            $ts   = time();
            self::$cached = "{$host}:{$pid}:{$ts}";
        }
        return self::$cached;
    }

    /** Test-only: clear the memoized value. */
    public static function reset(): void
    {
        self::$cached = null;
    }
}
