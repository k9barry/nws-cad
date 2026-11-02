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

        // PHP folds both `X-Auth-User` and `X_Auth_User` into the same
        // $_SERVER key, last one wins. A reverse proxy that strips/sets only
        // the dashed form would let a client smuggle `X_Auth_User: admin`
        // through. When the raw header list is available, require the exact
        // configured name and reject requests carrying an aliased variant.
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                $exact = null;
                foreach ($headers as $name => $value) {
                    $name = (string) $name;
                    if (strcasecmp($name, $headerName) === 0) {
                        $exact = (string) $value;
                    } elseif (strcasecmp(str_replace('_', '-', $name), str_replace('_', '-', $headerName)) === 0) {
                        return new self(null);
                    }
                }
                $raw = $exact;
            }
        }
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
