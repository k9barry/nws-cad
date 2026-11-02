<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Channels;

/**
 * Builds safe raw HTTP header lines for cURL.
 *
 * Header values in the notification channels are partly CAD-derived (call
 * number / call type in the ntfy Title). XML can carry encoded CR/LF
 * (`&#13;&#10;`), and libcurl sends CURLOPT_HTTPHEADER strings verbatim, so an
 * unsanitized value would let a crafted CAD export inject extra request
 * headers (e.g. override ntfy `Click`/`Actions`/`Attach`, or the auth header).
 */
final class HeaderSanitizer
{
    /** RFC 9110 token: valid header field-name characters. */
    private const NAME_PATTERN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Replace CR/LF/NUL and other control characters (except HTAB) with a
     * space, collapse the resulting whitespace runs, and trim.
     */
    public static function cleanValue(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0A-\x1F\x7F]+/', ' ', $value) ?? '';
        return trim($value);
    }

    /**
     * @param array<string,string|int> $headers
     * @return string[] "Name: value" lines; entries with invalid names are dropped.
     */
    public static function lines(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $name = (string) $k;
            if (! self::isValidName($name)) {
                continue;
            }
            $out[] = $name . ': ' . self::cleanValue((string) $v);
        }
        return $out;
    }
}
