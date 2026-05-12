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
    /** @var string|null Memoized per-request CSP nonce (base64) */
    private static ?string $nonce = null;

    /**
     * Return a per-request CSP nonce. Memoized so the same value appears in
     * both the Content-Security-Policy header and every `<script nonce="...">`
     * tag rendered by views during this request.
     *
     * Format: 22 chars of url-safe base64 (16 random bytes). Long enough that
     * an attacker can't guess it; short enough not to bloat every script tag.
     */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            // base64 of 16 random bytes = 24 chars including `==` padding;
            // strip padding for a shorter token. CSP accepts any non-whitespace
            // value here — base64 is just a convenient encoding.
            self::$nonce = rtrim(base64_encode(random_bytes(16)), '=');
        }
        return self::$nonce;
    }

    /** Test-only: reset the memoized nonce so the next call generates a new one. */
    public static function resetNonceForTesting(): void
    {
        self::$nonce = null;
    }

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
     * Both script-src and style-src use the same per-request nonce — no
     * 'unsafe-inline' in either directive. 'unsafe-eval' remains for
     * compatibility with libraries like Chart.js and Leaflet.js that compile
     * expressions at runtime. JS-driven CSSOM property writes
     * (e.g. element.style.display = 'none') are not gated by style-src per
     * CSP3, so those continue to work; only literal <style> blocks and
     * style="" attributes in served HTML are blocked.
     *
     * @param string|null $policy Custom CSP policy (default: nonce-based for dashboard compatibility)
     * @return void
     */
    public static function setContentSecurityPolicy(?string $policy = null): void
    {
        if ($policy === null) {
            $nonce  = self::nonce();
            $policy = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com https://*.cloudflare.com",
                "style-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://unpkg.com https://fonts.googleapis.com",
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
     * SECURITY NOTE: When using array-based allowed origins, the method validates
     * that the request origin exactly matches one of the allowed values. Empty or
     * 'null' origins are rejected.
     *
     * @param string|array<string> $allowedOrigins Allowed origins ('*' or array of origins)
     * @param array<string> $allowedMethods Allowed HTTP methods
     * @param array<string> $allowedHeaders Allowed headers
     * @param bool $allowCredentials Allow credentials (cannot be used with '*' origin)
     * @param int $maxAge Max age for preflight cache in seconds
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

        // Validate maxAge is positive
        $maxAge = max(0, $maxAge);

        // Determine origin
        if (is_array($allowedOrigins)) {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            
            // Security: Reject empty, null-string, or 'null' origins
            // 'null' is sent by sandboxed iframes, file:// URLs, etc.
            if (empty($origin) || $origin === 'null') {
                // Don't set any CORS header - request will be blocked
                return;
            }
            
            // Only allow exact matches from whitelist
            if (in_array($origin, $allowedOrigins, true)) {
                header("Access-Control-Allow-Origin: $origin");
                // Vary header required when origin depends on request
                header('Vary: Origin');
            } else {
                // Origin not in whitelist - don't set CORS headers
                return;
            }
        } else {
            // Wildcard or single origin
            header("Access-Control-Allow-Origin: $allowedOrigins");
        }

        header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $allowedHeaders));
        header("Access-Control-Max-Age: $maxAge");

        if ($allowCredentials) {
            // Note: Access-Control-Allow-Credentials can't be used with * origin
            if (!is_array($allowedOrigins) && $allowedOrigins === '*') {
                // Log warning - this is a configuration error
                error_log('SecurityHeaders: allowCredentials cannot be used with wildcard origin');
            } else {
                header('Access-Control-Allow-Credentials: true');
            }
        }
    }
}
