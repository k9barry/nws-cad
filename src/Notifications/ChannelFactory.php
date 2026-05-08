<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use InvalidArgumentException;
use NwsCad\Config;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\Channels\PushoverChannel;

class ChannelFactory
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array{type:string,base_url:string,config_json:string} $row
     */
    public function create(array $row): NotificationChannel
    {
        $cfg = json_decode($row['config_json'] !== '' ? $row['config_json'] : '{}', true) ?: [];

        return match ($row['type']) {
            'ntfy' => new NtfyChannel(
                baseUrl: $row['base_url'],
                authToken: $this->config->secret($cfg['auth_token_env'] ?? 'NTFY_AUTH_TOKEN'),
                config: $cfg,
            ),
            'pushover' => new PushoverChannel(
                baseUrl: $row['base_url'],
                token: $this->config->secret($cfg['token_env'] ?? 'PUSHOVER_TOKEN'),
                user: $this->config->secret($cfg['user_env'] ?? 'PUSHOVER_USER'),
                config: $cfg,
            ),
            default => throw new InvalidArgumentException("Unknown channel type: {$row['type']}"),
        };
    }
}
