<?php
declare(strict_types=1);

namespace NwsCad\Api\Filtering;

final class FilterContext
{
    public readonly bool $unitsBase;

    /**
     * @param string[] $alreadyJoined Tables the controller's base SELECT already references
     * @param bool     $unitsBase     When true, the base table is `units` and joins reach back
     *                                through `units.call_id` instead of forward from `calls.id`.
     */
    public function __construct(
        public readonly string $baseTable,
        public readonly array $alreadyJoined = [],
        bool $unitsBase = false,
    ) {
        $this->unitsBase = $unitsBase;
    }

    public function isJoined(string $table): bool
    {
        return in_array($table, $this->alreadyJoined, true);
    }
}
