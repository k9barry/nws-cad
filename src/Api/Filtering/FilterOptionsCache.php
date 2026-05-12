<?php
declare(strict_types=1);

namespace NwsCad\Api\Filtering;

final class FilterOptionsCache
{
    private const TTL_SECONDS = 300;

    /** @var array<string, array{value: mixed, expires_at: int}> */
    private static array $store = [];

    public static function get(string $key): mixed
    {
        $entry = self::$store[$key] ?? null;
        if ($entry === null) return null;
        if ($entry['expires_at'] < time()) {
            unset(self::$store[$key]);
            return null;
        }
        return $entry['value'];
    }

    public static function put(string $key, mixed $value): void
    {
        self::$store[$key] = ['value' => $value, 'expires_at' => time() + self::TTL_SECONDS];
    }

    /** Internal/test helper: insert with explicit timestamp (used to simulate aging). */
    public static function putAt(string $key, mixed $value, int $createdAt): void
    {
        self::$store[$key] = ['value' => $value, 'expires_at' => $createdAt + self::TTL_SECONDS];
    }

    /** @param string[] $keys */
    public static function invalidate(array $keys): void
    {
        foreach ($keys as $k) {
            unset(self::$store[$k]);
        }
    }

    public static function clear(): void
    {
        self::$store = [];
    }
}
