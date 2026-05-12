<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

interface ChannelFactoryInterface
{
    /**
     * @param array{type:string,base_url:string,config_json:string} $row
     */
    public function create(array $row): NotificationChannel;
}
