<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use InvalidArgumentException;
use NwsCad\Config;

final class ChannelFactory
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array{type:string,base_url:string,config_json:string} $row
     */
    public function create(array $row): NotificationChannel
    {
        $type = $row['type'] ?? '';
        $d = ChannelRegistry::get($type)
            ?? throw new InvalidArgumentException("Unknown channel type: {$type}");

        return ($d->factory)($row, $this->config);
    }
}
