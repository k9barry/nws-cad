<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

interface NotificationChannel
{
    /**
     * Channel type identifier matching notification_channels.type.
     */
    public static function type(): string;

    /**
     * @return SendResult[]  One result per attempt that produced a permanent
     *                       outcome (one per topic for ntfy, one for pushover).
     */
    public function send(IncidentDto $incident, NotificationContext $context): array;
}
