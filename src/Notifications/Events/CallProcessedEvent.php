<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Events;

use DateTimeImmutable;

final class CallProcessedEvent
{
    /**
     * @param string[] $changedFields
     */
    public function __construct(
        public readonly int $dbCallId,
        public readonly Intent $intent,
        public readonly array $changedFields,
        public readonly DateTimeImmutable $createDateTime,
    ) {
    }
}
