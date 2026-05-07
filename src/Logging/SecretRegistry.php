<?php

declare(strict_types=1);

namespace NwsCad\Logging;

/**
 * Static registry of secret literal values that must never appear in log output.
 * Populated by Config::secret() at the moment a secret is read; consumed by
 * RedactingProcessor on every log record.
 *
 * Values shorter than 3 characters are ignored — they would otherwise produce
 * spurious matches (e.g. a token of "ok" would redact every occurrence of "ok"
 * in normal log messages).
 */
final class SecretRegistry
{
    /** @var array<string,true> */
    private static array $values = [];

    public static function register(string $value): void
    {
        if (strlen($value) < 3) {
            return;
        }
        self::$values[$value] = true;
    }

    /** @return string[] */
    public static function getAll(): array
    {
        return array_keys(self::$values);
    }

    public static function reset(): void
    {
        self::$values = [];
    }
}
