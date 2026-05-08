<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use NwsCad\Logger;
use NwsCad\Notifications\Events\CallProcessedEvent;
use Throwable;

final class EventDispatcher
{
    /** @var array<int, callable(CallProcessedEvent):void> */
    private static array $subscribers = [];

    /** @param callable(CallProcessedEvent):void $subscriber */
    public static function subscribe(callable $subscriber): void
    {
        self::$subscribers[] = $subscriber;
    }

    public static function dispatch(CallProcessedEvent $event): void
    {
        foreach (self::$subscribers as $subscriber) {
            try {
                $subscriber($event);
            } catch (Throwable $t) {
                Logger::getInstance()->error('Event subscriber threw', [
                    'event' => CallProcessedEvent::class,
                    'dbCallId' => $event->dbCallId,
                    'intent' => $event->intent->value,
                    'error' => $t->getMessage(),
                ]);
            }
        }
    }

    public static function reset(): void
    {
        self::$subscribers = [];
    }
}
