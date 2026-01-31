<?php

declare(strict_types=1);

namespace NwsCad\Security;

/**
 * Security Headers Manager
 * 
 * Manages HTTP security headers to protect against common web vulnerabilities.
 */
class SecurityHeaders
{
    /**
     * Set all recommended security headers
     *
     * @param bool $includeHsts Whether to include HSTS header (default: false)
     * @return void
     */
    public static function setAll(bool $includeHsts = false): void
    {
        self::setContentSecurityPolicy();
        self::setXFrameOptions();
        self::setXContentTypeOptions();
        self::setXXssProtection();
        self::setReferrerPolicy();
        self::setPermissionsPolicy();

        if ($includeHsts) {
            self::setStrictTransportSecurity();
        }
    }

    /**
     * Set Content-Security-Policy header
     *
     * Note: Default policy includes 'unsafe-inline' and 'unsafe-eval' for compatibility
     * with libraries like Chart.js and Leaflet.js. In production, consider:
     * - Using nonce-based CSP for inline scripts
     * - Migrating to external script files
     * - Using strict-dynamic for modern browsers
     *
     * @param string|null $policy Custom CSP policy (default: permissive for dashboard compatibility)
     * @return void
     */
    public static function setContentSecurityPolicy(?string $policy = null): void
    {
        if ($policy === null) {
            // Permissive policy for dashboard with CDN libraries
            // TODO: Tighten in production with nonces or external scripts
            $policy = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com https://*.cloudflare.com",
                "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com https://fonts.googleapis.com",
                "img-src 'self' data: https://cdn.jsdelivr.net https://*.tile.openstreetmap.org",
                "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com",
                "connect-src 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'"
            ]);
        }

        if (!headers_sent()) {
            header("Content-Security-Policy: $policy");
        }
    }

    /**
     * Set X-Frame-Options header to prevent clickjacking
     *
     * @param string $option Options: 'DENY', 'SAMEORIGIN' (default: 'DENY')
     * @return void
     */
    public static function setXFrameOptions(string $option = 'DENY'): void
    {
        if (!headers_sent()) {
            header("X-Frame-Options: $option");
        }
    }

    /**
     * Set X-Content-Type-Options header to prevent MIME sniffing
     *
     * @return void
     */
    public static function setXContentTypeOptions(): void
    {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
        }
    }

    /**
     * Set X-XSS-Protection header
     *
     * @return void
     */
    public static function setXXssProtection(): void
    {
        if (!headers_sent()) {
            header('X-XSS-Protection: 1; mode=block');
        }
    }

    /**
     * Set Strict-Transport-Security header (HSTS)
     *
     * @param int $maxAge Max age in seconds (default: 31536000 = 1 year)
     * @param bool $includeSubdomains Include subdomains (default: true)
     * @param bool $preload Add to HSTS preload list (default: false)
     * @return void
     */
    public static function setStrictTransportSecurity(int $maxAge = 31536000, bool $includeSubdomains = true, bool $preload = false): void
    {
        $header = "max-age=$maxAge";

        if ($includeSubdomains) {
            $header .= '; includeSubDomains';
        }

        if ($preload) {
            $header .= '; preload';
        }

        if (!headers_sent()) {
            header("Strict-Transport-Security: $header");
        }
    }

    /**
     * Set Referrer-Policy header
     *
     * @param string $policy Policy value (default: 'strict-origin-when-cross-origin')
     * @return void
     */
    public static function setReferrerPolicy(string $policy = 'strict-origin-when-cross-origin'): void
    {
        if (!headers_sent()) {
            header("Referrer-Policy: $policy");
        }
    }

    /**
     * Set Permissions-Policy header
     *
     * @param string|null $policy Custom policy (default: restrictive policy)
     * @return void
     */
    public static function setPermissionsPolicy(?string $policy = null): void
    {
        if ($policy === null) {
            $policy = implode(', ', [
                'geolocation=(self)',
                'microphone=()',
                'camera=()',
                'payment=()',
                'usb=()',
                'magnetometer=()',
                'gyroscope=()',
                'accelerometer=()'
            ]);
        }

        if (!headers_sent()) {
            header("Permissions-Policy: $policy");
        }
    }

    /**
     * Set CORS headers for API endpoints
     *
     * @param string|array<string> $allowedOrigins Allowed origins ('*' or array of origins)
     * @param array<string> $allowedMethods Allowed HTTP methods
     * @param array<string> $allowedHeaders Allowed headers
     * @param bool $allowCredentials Allow credentials
     * @param int $maxAge Max age for preflight cache
     * @return void
     */
    public static function setCorsHeaders(
        string|array $allowedOrigins = '*',
        array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = ['Content-Type', 'Authorization'],
        bool $allowCredentials = false,
        int $maxAge = 86400
    ): void {
        if (headers_sent()) {
            return;
        }

        // Determine origin
        if (is_array($allowedOrigins)) {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            if (in_array($origin, $allowedOrigins)) {
                header("Access-Control-Allow-Origin: $origin");
            }
        } else {
            header("Access-Control-Allow-Origin: $allowedOrigins");
        }

        header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $allowedHeaders));
        header("Access-Control-Max-Age: $maxAge");

        if ($allowCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }
    }
}
