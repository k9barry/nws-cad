<?php

declare(strict_types=1);

namespace NwsCad\Import;

use DateTime;

/**
 * Normalizes the datetime strings emitted by NWS Aegis CAD exports into the
 * canonical `Y-m-d H:i:s` form used throughout the schema.
 *
 * Extracted verbatim from AegisXmlParser::parseDateTime() (#49) so the single
 * fragile piece of the importer has one tested home. Behavior is locked by
 * {@see \NwsCad\Tests\Unit\DateTimeParserTest} and the importer characterization
 * suite; do not change the format list or fallback order without updating both.
 */
final class DateTimeParser
{
    /**
     * Formats tried in order before the strtotime() fallback. The first that
     * DateTime::createFromFormat() accepts wins. Timezone offsets are parsed
     * but not shifted — the wall-clock value is preserved.
     */
    private const FORMATS = [
        'Y-m-d\TH:i:s\Z',   // ISO 8601 with literal Z
        'Y-m-d\TH:i:s',     // ISO 8601 without timezone
        'Y-m-d\TH:i:s.u',   // ISO 8601 with microseconds
        'Y-m-d\TH:i:sP',    // ISO 8601 with timezone offset
        'Y-m-d\TH:i:s.uP',  // ISO 8601 with microseconds and offset
        'Y-m-d H:i:s',      // MySQL-style
        'm/d/Y H:i:s',      // US 24-hour
        'm/d/Y h:i:s A',    // US 12-hour with AM/PM
    ];

    /**
     * @param string|null $dateTime Raw value from the XML (may be empty or an xsi:nil marker).
     * @return string|null Normalized 'Y-m-d H:i:s', or null for empty/nil/unparseable input.
     */
    public static function parse(?string $dateTime): ?string
    {
        if (empty($dateTime) || $dateTime === 'nil' || strpos($dateTime, 'nil="true"') !== false) {
            return null;
        }

        foreach (self::FORMATS as $format) {
            $dt = DateTime::createFromFormat($format, $dateTime);
            if ($dt !== false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        // Best-effort fallback for anything the explicit formats miss.
        $timestamp = strtotime($dateTime);
        if ($timestamp !== false) {
            return date('Y-m-d H:i:s', $timestamp);
        }

        return null;
    }
}
