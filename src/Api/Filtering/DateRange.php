<?php
declare(strict_types=1);

namespace NwsCad\Api\Filtering;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class DateRange
{
    public function __construct(
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
    ) {}

    public static function fromPreset(string $preset, DateTimeZone $tz): self
    {
        $now = new DateTimeImmutable('now', $tz);
        return match ($preset) {
            'today'        => new self($now->setTime(0, 0, 0), $now->setTime(23, 59, 59)),
            'yesterday'    => new self(
                $now->modify('-1 day')->setTime(0, 0, 0),
                $now->modify('-1 day')->setTime(23, 59, 59),
            ),
            'last_7_days'  => new self(
                $now->modify('-6 days')->setTime(0, 0, 0),
                $now->setTime(23, 59, 59),
            ),
            'last_30_days' => new self(
                $now->modify('-29 days')->setTime(0, 0, 0),
                $now->setTime(23, 59, 59),
            ),
            'this_month'   => new self(
                $now->modify('first day of this month')->setTime(0, 0, 0),
                $now->modify('last day of this month')->setTime(23, 59, 59),
            ),
            'last_month'   => new self(
                $now->modify('first day of last month')->setTime(0, 0, 0),
                $now->modify('last day of last month')->setTime(23, 59, 59),
            ),
            default => throw new InvalidArgumentException("Unknown preset: {$preset}"),
        };
    }

    public static function fromExplicit(?string $from, ?string $to, DateTimeZone $tz): self
    {
        $parse = static function (string $value, bool $isEnd) use ($tz): DateTimeImmutable {
            // Date-only → expand to start/end of day. Datetime → use as-is.
            $isDateOnly = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
            $dt = new DateTimeImmutable($value, $tz);
            if ($isDateOnly) {
                $dt = $isEnd ? $dt->setTime(23, 59, 59) : $dt->setTime(0, 0, 0);
            }
            return $dt;
        };
        return new self(
            $parse($from ?? '1970-01-01', false),
            $parse($to ?? (new DateTimeImmutable('now', $tz))->format('Y-m-d'), true),
        );
    }
}
