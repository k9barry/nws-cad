<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

interface NotificationChannel
{
    public static function descriptor(): ChannelDescriptor;

    /**
     * @return SendResult[]  One result per attempt that produced a permanent
     *                       outcome (one per topic for ntfy, one per send
     *                       for pushover/webhook).
     */
    public function send(IncidentDto $incident, NotificationContext $context): array;
}
