<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

final class SendResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?int $httpStatus,
        public readonly int $durationMs,
        public readonly ?string $error,
        public readonly ?string $topic,
    ) {
    }

    public static function ok(?int $httpStatus, int $durationMs, ?string $topic = null): self
    {
        return new self(true, $httpStatus, $durationMs, null, $topic);
    }

    public static function fail(?int $httpStatus, int $durationMs, string $error, ?string $topic = null): self
    {
        return new self(false, $httpStatus, $durationMs, $error, $topic);
    }
}
