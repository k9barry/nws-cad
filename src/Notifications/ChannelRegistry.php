<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

/**
 * Static registry of channel descriptors. Populated by registerChannels.php
 * at boot of every entry point (watcher, HTTP, CLI). Queried by
 * ChannelFactory at dispatch time and by the API/CLI for validation and help.
 *
 * Static state is intentional given PHP's per-request lifecycle; tests MUST
 * call `clear()` in tearDown to prevent state leak between tests.
 */
final class ChannelRegistry
{
    /** @var array<string, ChannelDescriptor> */
    private static array $byType = [];

    public static function register(ChannelDescriptor $d): void
    {
        self::$byType[$d->type] = $d;
    }

    public static function get(string $type): ?ChannelDescriptor
    {
        return self::$byType[$type] ?? null;
    }

    public static function has(string $type): bool
    {
        return isset(self::$byType[$type]);
    }

    /** @return string[] */
    public static function types(): array
    {
        return array_keys(self::$byType);
    }

    /** @return array<string, ChannelDescriptor> */
    public static function all(): array
    {
        return self::$byType;
    }

    public static function clear(): void
    {
        self::$byType = [];
    }
}
