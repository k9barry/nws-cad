<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

use NwsCad\Notifications\Events\Intent;

final class NotificationContext
{
    /**
     * @param string[] $topicsToNotify  Already-sanitized ntfy topic segments (no '|' separators).
     * @param array<string,mixed> $channelConfig
     */
    public function __construct(
        public readonly Intent $intent,
        public readonly bool $resendAll,
        public readonly array $topicsToNotify,
        public readonly array $channelConfig,
    ) {
    }
}
