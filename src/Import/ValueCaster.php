<?php

declare(strict_types=1);

namespace NwsCad\Import;

/**
 * Scalar coercions for values read out of Aegis CAD XML.
 *
 * Extracted verbatim from AegisXmlParser (#49) so the type handling — including
 * the shared empty / `nil` / `nil="true"` null rule — lives in one tested place.
 * Behavior is locked by {@see \NwsCad\Tests\Unit\ValueCasterTest} and the
 * importer characterization suite.
 */
final class ValueCaster
{
    /**
     * True-ish CAD flag → 1, everything else → 0. Case- and whitespace-insensitive.
     */
    public static function toBool(mixed $value): int
    {
        $stringValue = strtolower(trim((string) $value));
        return in_array($stringValue, ['true', '1', 'yes'], true) ? 1 : 0;
    }

    /**
     * Integer, or null for empty / nil markers.
     */
    public static function toInt(?string $value): ?int
    {
        if (self::isNullish($value)) {
            return null;
        }
        return (int) $value;
    }

    /**
     * Float, or null for empty / nil markers.
     */
    public static function toDecimal(?string $value): ?float
    {
        if (self::isNullish($value)) {
            return null;
        }
        return (float) $value;
    }

    /**
     * Shared empty / `nil` / `nil="true"` test used by the nullable casters.
     */
    private static function isNullish(?string $value): bool
    {
        return empty($value) || $value === 'nil' || strpos($value, 'nil="true"') !== false;
    }
}
