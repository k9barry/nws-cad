<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use NwsCad\Config;
use NwsCad\Security\CorsPolicy;
use NwsCad\Security\Identity;
use NwsCad\Security\RateLimiter;
use NwsCad\Security\SameOriginGuard;
use NwsCad\Security\SecurityHeaders;
use NwsCad\Security\TrustedProxy;

(static function (): void {
    $config = Config::getInstance();
    require_once __DIR__ . '/Notifications/registerChannels.php';

    $isHttps = ($_SERVER['HTTPS'] ?? '') === 'on'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    SecurityHeaders::setAll(includeHsts: $isHttps);

    CorsPolicy::apply($config);
    TrustedProxy::guard($config);
    SameOriginGuard::guard($config);

    $GLOBALS['__identity'] = Identity::extract($config);

    // Throttle per identity/IP now that the client is resolved. Fails open if
    // APCu is unavailable; /api/health is exempt.
    RateLimiter::enforce($config);
})();
