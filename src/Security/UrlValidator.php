<?php

declare(strict_types=1);

namespace NwsCad\Security;

use NwsCad\Config;

final class UrlValidator
{
    /**
     * Validate a notification-channel base URL. Returns `['ok' => true]` on
     * success or `['ok' => false, 'reason' => '<code>']` on rejection.
     *
     * Rules:
     *   - No CR/LF anywhere in the raw string.
     *   - filter_var(FILTER_VALIDATE_URL) must accept it.
     *   - Scheme must be `https`, unless `notifications.allow_http_for_private`
     *     is true AND the host is RFC1918 / loopback (NOT link-local).
     *   - If `notifications.base_url_allowlist` is non-empty, host must match
     *     one of its entries exactly.
     *
     * @return array{ok: true}|array{ok: false, reason: string}
     */
    public static function validateChannelBaseUrl(string $url, Config $cfg): array
    {
        if (preg_match('/[\r\n]/', $url) === 1) {
            return ['ok' => false, 'reason' => 'crlf'];
        }
        if (InputValidator::validateUrl($url) === null) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        $host   = strtolower($parts['host'] ?? '');

        if ($scheme !== 'https') {
            $allowPrivate = (bool) $cfg->get('notifications.allow_http_for_private', false);
            if ($scheme !== 'http' || ! $allowPrivate || ! self::hostIsPrivate($host)) {
                return ['ok' => false, 'reason' => 'scheme'];
            }
        }

        $allow = $cfg->get('notifications.base_url_allowlist', []);
        if ($allow !== [] && ! in_array($host, array_map('strtolower', $allow), true)) {
            return ['ok' => false, 'reason' => 'host'];
        }

        return ['ok' => true];
    }

    /**
     * RFC1918 / loopback ONLY. Link-local (169.254.0.0/16, fe80::/10) is
     * deliberately excluded — those are common SSRF targets (AWS metadata
     * service, etc.).
     */
    private static function hostIsPrivate(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }
        $packed = @inet_pton($host);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return TrustedProxy::inAny($host, [
                '127.0.0.0/8',
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
            ]);
        }
        if (strlen($packed) === 16) {
            return TrustedProxy::inAny($host, [
                '::1/128',
                'fc00::/7',     // unique local
            ]);
        }
        return false;
    }
}
