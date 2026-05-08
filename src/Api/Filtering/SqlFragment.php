<?php
declare(strict_types=1);

namespace NwsCad\Api\Filtering;

final class SqlFragment
{
    /**
     * @param string[] $joins
     * @param array<string,mixed> $params
     */
    public function __construct(
        public readonly string $whereClause,
        public readonly array $params,
        public readonly array $joins,
    ) {}

    public static function empty(): self
    {
        return new self('', [], []);
    }
}
