<?php

declare(strict_types=1);

namespace NwsCad\Security;

use NwsCad\Api\Response;
use NwsCad\Config;
use NwsCad\Logger;

/**
 * APCu-backed fixed-window rate limiter for the HTTP surface.
 *
 * Keyed per client identity (resolved after {@see TrustedProxy::guard()}) or
 * remote IP, counted in one-minute windows. On exceed it emits a 429 with
 * Retry-After and X-RateLimit-* headers. `/api/health` is exempt so container
 * healthchecks are never throttled. If APCu is unavailable it fails open (logs
 * once) rather than blocking traffic.
 */
final class RateLimiter
{
    private const DEFAULT_PER_MINUTE = 120;
    private static bool $warnedNoApcu = false;

    public static function enforce(Config $cfg): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($path === '/api/health') {
            return; // healthchecks must never be throttled
        }

        $limit = self::configuredLimit($cfg);
        if ($limit <= 0) {
            return; // disabled
        }

        if (! (function_exists('apcu_enabled') && apcu_enabled())) {
            if (! self::$warnedNoApcu) {
                self::$warnedNoApcu = true;
                Logger::getInstance()->warning('RateLimiter: APCu unavailable, failing open (no rate limiting)');
            }
            return;
        }

        $window = (int) floor(time() / 60);
        $key = 'rl:' . self::clientId() . ':' . $window;

        // Seed the counter with a 60s TTL on the first hit of the window, then
        // increment on subsequent hits.
        if (apcu_add($key, 1, 60)) {
            $count = 1;
        } else {
            $count = (int) apcu_inc($key);
        }

        $remaining = max(0, $limit - $count);
        $reset = ($window + 1) * 60;
        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Reset: ' . $reset);

        if ($count > $limit) {
            $retryAfter = max(1, $reset - time());
            header('Retry-After: ' . $retryAfter);
            Response::error('Rate limit exceeded. Try again later.', 429);
        }
    }

    private static function configuredLimit(Config $cfg): int
    {
        $configured = $cfg->get('rate_limit.per_minute');
        if ($configured !== null) {
            return (int) $configured;
        }
        $env = getenv('RATE_LIMIT_PER_MINUTE');
        if ($env !== false && $env !== '') {
            return (int) $env;
        }
        return self::DEFAULT_PER_MINUTE;
    }

    /**
     * Resolve a stable client key: the authenticated identity if TrustedProxy
     * accepted one, otherwise the (already trust-validated) remote address.
     */
    private static function clientId(): string
    {
        $identity = $GLOBALS['__identity'] ?? null;
        if ($identity instanceof Identity && $identity->user !== null && $identity->user !== '') {
            return 'id:' . $identity->user;
        }
        return 'ip:' . self::clientIp();
    }

    /**
     * Resolve the end-client IP for anonymous requests. REMOTE_ADDR is the
     * connecting peer — which, once {@see TrustedProxy::guard()} has passed, is
     * the reverse proxy, so bucketing on it would lump every user behind the
     * proxy into one bucket. Prefer the last hop the trusted proxy appended to
     * X-Forwarded-For (which the client cannot forge past the proxy).
     */
    private static function clientIp(): string
    {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $hops = array_map('trim', explode(',', $xff));
            $last = end($hops);
            if ($last !== false && filter_var($last, FILTER_VALIDATE_IP) !== false) {
                return $last;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
