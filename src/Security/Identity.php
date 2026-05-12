<?php

declare(strict_types=1);

namespace NwsCad\Security;

use NwsCad\Config;

final class Identity
{
    private function __construct(public readonly ?string $user)
    {
    }

    /**
     * Reads the configured identity header from $_SERVER and validates it
     * against a strict allowlist (alphanumerics, dot, underscore, @, -).
     * Returns Identity(null) on missing or malformed values so the caller
     * can decide whether to log a warning.
     *
     * MUST run AFTER TrustedProxy::guard() — otherwise an attacker could
     * spoof this header from outside the trusted CIDR.
     */
    public static function extract(Config $cfg): self
    {
        $headerName = $cfg->get('proxy.identity_header', 'X-Auth-User');
        $serverKey  = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        $raw        = $_SERVER[$serverKey] ?? null;
        if ($raw === null) {
            return new self(null);
        }
        if (! preg_match('/^[A-Za-z0-9._@-]{1,64}$/', (string) $raw)) {
            return new self(null);
        }
        return new self((string) $raw);
    }

    public static function current(): self
    {
        return $GLOBALS['__identity'] ?? new self(null);
    }
}
