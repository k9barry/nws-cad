<?php

declare(strict_types=1);

namespace NwsCad\Security;

/**
 * Rate Limiter
 * 
 * Simple in-memory rate limiter for API endpoints.
 * 
 * WARNING: This implementation uses static arrays which can grow indefinitely
 * in long-running processes. For production use, consider:
 * - Redis or Memcached for distributed systems
 * - Database-backed storage for persistence
 * - Regular cleanup via scheduled tasks
 * 
 * This implementation includes automatic cleanup to prevent memory leaks.
 */
class RateLimiter
{
    private static array $requests = [];
    private static int $maxRequests = 60; // per window
    private static int $windowSeconds = 60; // 1 minute
    private static int $maxStoredIdentifiers = 1000; // Limit to prevent memory issues

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

        // Periodic cleanup to prevent memory leaks
        self::cleanupOldEntries($window);

        // Initialize or clean up old requests for this identifier
        if (!isset(self::$requests[$identifier])) {
            self::$requests[$identifier] = [];
        }

        // Remove requests outside the current window
        self::$requests[$identifier] = array_filter(
            self::$requests[$identifier],
            fn($timestamp) => ($now - $timestamp) < $window
        );

        // Clean up empty identifier
        if (empty(self::$requests[$identifier])) {
            unset(self::$requests[$identifier]);
        }

        // Check if we've exceeded storage limit
        if (count(self::$requests) >= self::$maxStoredIdentifiers) {
            // Remove oldest identifier entries
            self::pruneOldestIdentifiers();
        }

        // Reinitialize if cleaned up
        if (!isset(self::$requests[$identifier])) {
            self::$requests[$identifier] = [];
        }

        // Check if limit exceeded
        if (count(self::$requests[$identifier]) >= $max) {
            return false;
        }

        // Record this request
        self::$requests[$identifier][] = $now;

        return true;
    }

    /**
     * Clean up old entries to prevent memory leaks
     *
     * @param int $window Time window in seconds
     * @return void
     */
    private static function cleanupOldEntries(int $window): void
    {
        $now = time();
        
        foreach (self::$requests as $identifier => $timestamps) {
            // Remove old timestamps
            self::$requests[$identifier] = array_filter(
                $timestamps,
                fn($timestamp) => ($now - $timestamp) < $window
            );
            
            // Remove empty identifiers
            if (empty(self::$requests[$identifier])) {
                unset(self::$requests[$identifier]);
            }
        }
    }

    /**
     * Prune oldest identifier entries when storage limit reached
     *
     * @return void
     */
    private static function pruneOldestIdentifiers(): void
    {
        if (empty(self::$requests)) {
            return;
        }

        // Sort by oldest timestamp
        $identifierAges = [];
        foreach (self::$requests as $identifier => $timestamps) {
            if (!empty($timestamps)) {
                $identifierAges[$identifier] = min($timestamps);
            }
        }

        asort($identifierAges);

        // Remove oldest 20% of identifiers
        $toRemove = (int)(self::$maxStoredIdentifiers * 0.2);
        $removed = 0;

        foreach (array_keys($identifierAges) as $identifier) {
            if ($removed >= $toRemove) {
                break;
            }
            unset(self::$requests[$identifier]);
            $removed++;
        }
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
