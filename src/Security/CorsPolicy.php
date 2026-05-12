<?php

declare(strict_types=1);

namespace NwsCad\Security;

use NwsCad\Config;

final class CorsPolicy
{
    /**
     * Apply CORS headers from configuration. On an OPTIONS preflight request,
     * emit 204 and exit.
     *
     * Ordering matters: this must run BEFORE TrustedProxy::guard() so that
     * preflights initiated from arbitrary browsers (which are not the proxy)
     * can complete the handshake. Preflights carry no body or identity, so
     * granting them 204 is safe.
     */
    public static function apply(Config $cfg): void
    {
        $allowed = $cfg->get('cors.allowed_origins', []);
        SecurityHeaders::setCorsHeaders(
            allowedOrigins:   $allowed === [] ? [] : $allowed,
            allowedMethods:   ['GET', 'POST', 'DELETE', 'OPTIONS'],
            allowedHeaders:   ['Content-Type', 'X-Auth-User'],
            allowCredentials: false,
            maxAge:           86400,
        );

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
