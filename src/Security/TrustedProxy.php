<?php

declare(strict_types=1);

namespace NwsCad\Security;

use NwsCad\Api\Response;
use NwsCad\Config;
use NwsCad\Logger;

final class TrustedProxy
{
    /**
     * Refuses the request with 403 if the connecting peer is not within any
     * trusted-proxy CIDR. Reads `proxy.trusted_cidrs` from Config.
     *
     * Must run AFTER security headers and CORS preflight so error responses
     * still carry the standard headers and OPTIONS preflights succeed.
     */
    public static function guard(Config $cfg): void
    {
        $cidrs  = $cfg->get('proxy.trusted_cidrs', ['127.0.0.1/32', '::1/128']);
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if (! self::inAny($remote, $cidrs)) {
            Logger::getInstance()->warning('TrustedProxy: rejecting direct access', [
                'remote_addr' => $remote,
            ]);
            Response::forbidden('Direct access not permitted');
        }
    }

    /**
     * Pure CIDR-membership check. Returns false for malformed input rather
     * than throwing so a misconfigured CIDR list cannot crash the request
     * pipeline.
     *
     * @param string[] $cidrs
     */
    public static function inAny(string $ip, array $cidrs): bool
    {
        if ($ip === '') {
            return false;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($cidrs as $cidr) {
            if (self::matches($packed, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function matches(string $packedIp, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return false;
        }
        [$network, $bits] = explode('/', $cidr, 2);
        if (! ctype_digit($bits)) {
            return false;
        }
        $packedNet = @inet_pton($network);
        if ($packedNet === false) {
            return false;
        }
        if (strlen($packedIp) !== strlen($packedNet)) {
            return false;   // v4 vs v6 mismatch
        }
        $bits = (int) $bits;
        $maxBits = strlen($packedIp) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $bytesFull   = intdiv($bits, 8);
        $bitsPartial = $bits % 8;
        if ($bytesFull > 0 && substr($packedIp, 0, $bytesFull) !== substr($packedNet, 0, $bytesFull)) {
            return false;
        }
        if ($bitsPartial === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $bitsPartial)) & 0xFF);
        return (($packedIp[$bytesFull] & $mask) === ($packedNet[$bytesFull] & $mask));
    }
}
