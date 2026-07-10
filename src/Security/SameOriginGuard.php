<?php

declare(strict_types=1);

namespace NwsCad\Security;

use NwsCad\Api\Response;
use NwsCad\Config;
use NwsCad\Logger;

/**
 * Lightweight CSRF / cross-origin defense for state-changing requests.
 *
 * State-changing HTTP methods (POST/PUT/PATCH/DELETE) are rejected when the
 * browser signals the request originated cross-origin. Two signals are used,
 * in order of preference:
 *
 *   1. `Sec-Fetch-Site` — sent by modern browsers. `same-origin`/`same-site`/
 *      `none` are allowed; `cross-site` is rejected.
 *   2. `Origin` vs the request authority (host + port) — used when
 *      `Sec-Fetch-Site` is absent. Origins in the configured CORS allowlist
 *      are permitted.
 *
 * Non-browser clients (the file watcher, server-to-server integrations, curl)
 * send neither header and are allowed — CSRF is a browser-only attack. No
 * token or session infrastructure is required.
 */
final class SameOriginGuard
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public static function guard(Config $cfg): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, self::SAFE_METHODS, true)) {
            return;
        }

        // Prefer the browser-supplied Sec-Fetch-Site signal when present.
        $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if ($fetchSite !== null && $fetchSite !== '') {
            if (in_array($fetchSite, ['same-origin', 'same-site', 'none'], true)) {
                return;
            }
            self::reject($method, 'sec-fetch-site=' . $fetchSite);
            return;
        }

        // No Sec-Fetch-Site: fall back to Origin vs request-authority.
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin === null || $origin === '') {
            // Non-browser client; nothing to enforce.
            return;
        }

        $allowed = $cfg->get('cors.allowed_origins', []);
        if (is_array($allowed) && in_array($origin, $allowed, true)) {
            return;
        }

        if (self::originMatchesHost($origin)) {
            return;
        }

        self::reject($method, 'origin=' . $origin);
    }

    /**
     * True when the Origin's host and port match the request's own authority.
     * Default ports are resolved from the scheme so `https://h` and
     * `https://h:443` compare equal.
     */
    private static function originMatchesHost(string $origin): bool
    {
        $originHost = parse_url($origin, PHP_URL_HOST);
        if ($originHost === null || $originHost === false) {
            return false;
        }
        $originScheme = strtolower((string) (parse_url($origin, PHP_URL_SCHEME) ?: 'http'));
        $originPort = parse_url($origin, PHP_URL_PORT);
        $originPort = $originPort !== null ? (int) $originPort : self::defaultPort($originScheme);

        $requestHostHeader = $_SERVER['HTTP_HOST'] ?? '';
        if ($requestHostHeader === '') {
            return false;
        }
        $requestScheme = self::requestScheme();
        [$requestHost, $requestPort] = self::splitAuthority($requestHostHeader, $requestScheme);

        return strcasecmp($originHost, $requestHost) === 0 && $originPort === $requestPort;
    }

    /**
     * @return array{0:string,1:int} host and resolved port
     */
    private static function splitAuthority(string $authority, string $scheme): array
    {
        // Bracketed IPv6 literal, optionally with a port: [::1]:8080
        if (str_starts_with($authority, '[')) {
            $close = strpos($authority, ']');
            $host = $close !== false ? substr($authority, 1, $close - 1) : $authority;
            $rest = $close !== false ? substr($authority, $close + 1) : '';
            $port = str_starts_with($rest, ':') ? (int) substr($rest, 1) : self::defaultPort($scheme);
            return [$host, $port];
        }

        $colon = strrpos($authority, ':');
        if ($colon !== false) {
            return [substr($authority, 0, $colon), (int) substr($authority, $colon + 1)];
        }

        return [$authority, self::defaultPort($scheme)];
    }

    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }

    private static function requestScheme(): string
    {
        $isHttps = ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        return $isHttps ? 'https' : 'http';
    }

    private static function reject(string $method, string $reason): void
    {
        Logger::getInstance()->warning('SameOriginGuard: rejecting cross-origin state-changing request', [
            'method' => $method,
            'reason' => $reason,
        ]);
        Response::forbidden('Cross-origin request not permitted');
    }
}
