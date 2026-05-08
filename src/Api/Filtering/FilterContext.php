<?php
declare(strict_types=1);

namespace NwsCad\Api\Filtering;

final class FilterContext
{
    /**
     * @param string[] $alreadyJoined Tables the controller's base SELECT already references
     */
    public function __construct(
        public readonly string $baseTable,
        public readonly array $alreadyJoined = [],
    ) {}

    public function isJoined(string $table): bool
    {
        return in_array($table, $this->alreadyJoined, true);
    }
}
