<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Events;

use DateTimeImmutable;

final class CallProcessedEvent
{
    /**
     * @param string[] $changedFields  Logical field names that materially differ
     *   between the prior row and the incoming XML (e.g. 'call_type',
     *   'full_address', 'alarm_level', 'assigned_units', 'jurisdictions',
     *   'agencies'). Empty when intent is Created or Closed.
     * @param string[] $addedTopics    Newly-introduced agency/jurisdiction/unit
     *   identifiers (computed as incoming \ existing). Used by the dispatcher
     *   when intent is Updated and no resend-all trigger fired, so only the
     *   newly-dispatched units get paged. Empty for Created/Closed.
     */
    public function __construct(
        public readonly int $dbCallId,
        public readonly Intent $intent,
        public readonly array $changedFields,
        public readonly DateTimeImmutable $createDateTime,
        public readonly array $addedTopics = [],
    ) {
    }
}
