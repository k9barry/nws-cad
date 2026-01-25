<?php

declare(strict_types=1);

namespace NwsCad\Security;

/**
 * Rate Limiter
 * 
 * Simple in-memory rate limiter for API endpoints.
 * For production use, consider Redis or Memcached for distributed systems.
 */
class RateLimiter
{
    private static array $requests = [];
    private static int $maxRequests = 60; // per window
    private static int $windowSeconds = 60; // 1 minute

    /**
     * Check if a request is allowed based on rate limiting
     *
     * @param string $identifier Unique identifier (e.g., IP address, API key)
     * @param int|null $maxRequests Maximum requests per window (default: 60)
     * @param int|null $windowSeconds Time window in seconds (default: 60)
     * @return bool True if request is allowed, false if rate limited
     */
    public static function isAllowed(string $identifier, ?int $maxRequests = null, ?int $windowSeconds = null): bool
    {
        $max = $maxRequests ?? self::$maxRequests;
        $window = $windowSeconds ?? self::$windowSeconds;
        $now = time();

        // Initialize or clean up old requests
        if (!isset(self::$requests[$identifier])) {
            self::$requests[$identifier] = [];
        }

        // Remove requests outside the current window
        self::$requests[$identifier] = array_filter(
            self::$requests[$identifier],
            fn($timestamp) => ($now - $timestamp) < $window
        );

        // Check if limit exceeded
        if (count(self::$requests[$identifier]) >= $max) {
            return false;
        }

        // Record this request
        self::$requests[$identifier][] = $now;

        return true;
    }

    /**
     * Get the number of remaining requests for an identifier
     *
     * @param string $identifier Unique identifier
     * @param int|null $maxRequests Maximum requests per window
     * @param int|null $windowSeconds Time window in seconds
     * @return int Number of remaining requests
     */
    public static function getRemainingRequests(string $identifier, ?int $maxRequests = null, ?int $windowSeconds = null): int
    {
        $max = $maxRequests ?? self::$maxRequests;
        $window = $windowSeconds ?? self::$windowSeconds;
        $now = time();

        if (!isset(self::$requests[$identifier])) {
            return $max;
        }

        // Count requests within the window
        $requestsInWindow = array_filter(
            self::$requests[$identifier],
            fn($timestamp) => ($now - $timestamp) < $window
        );

        return max(0, $max - count($requestsInWindow));
    }

    /**
     * Reset rate limit for an identifier
     *
     * @param string $identifier Unique identifier
     * @return void
     */
    public static function reset(string $identifier): void
    {
        unset(self::$requests[$identifier]);
    }

    /**
     * Set global rate limit defaults
     *
     * @param int $maxRequests Maximum requests per window
     * @param int $windowSeconds Time window in seconds
     * @return void
     */
    public static function setDefaults(int $maxRequests, int $windowSeconds): void
    {
        self::$maxRequests = $maxRequests;
        self::$windowSeconds = $windowSeconds;
    }
}
