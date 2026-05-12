<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use Closure;

/**
 * Immutable registration record for a notification channel type.
 *
 * Each channel class exposes a static `descriptor()` returning one of these;
 * `registerChannels.php` registers them all at boot, and ChannelRegistry holds
 * them for the rest of the request/process lifetime.
 */
final class ChannelDescriptor
{
    /**
     * @param string[]            $requiredEnvs Env-var names that must be present when the channel is enabled.
     * @param array<string,mixed> $defaultConfig Becomes notification_channels.config_json on first enable.
     * @param Closure(array<string,mixed>, \NwsCad\Config): NotificationChannel $factory
     *        Builds an instance of the channel from a DB row and the current Config.
     */
    public function __construct(
        public readonly string  $type,
        public readonly string  $label,
        public readonly string  $baseUrlEnv,
        public readonly array   $requiredEnvs,
        public readonly array   $defaultConfig,
        public readonly Closure $factory,
    ) {
    }
}
