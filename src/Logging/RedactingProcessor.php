<?php

declare(strict_types=1);

namespace NwsCad\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class RedactingProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $secrets = SecretRegistry::getAll();
        if ($secrets === []) {
            return $record;
        }

        return $record->with(
            message: $this->scrubString($record->message, $secrets),
            context: $this->scrubArray($record->context, $secrets),
            extra: $this->scrubArray($record->extra, $secrets),
        );
    }

    /** @param string[] $secrets */
    private function scrubString(string $value, array $secrets): string
    {
        return str_replace($secrets, '***', $value);
    }

    /**
     * @param array<mixed> $value
     * @param string[] $secrets
     * @return array<mixed>
     */
    private function scrubArray(array $value, array $secrets): array
    {
        foreach ($value as $k => $v) {
            if (is_string($v)) {
                $value[$k] = $this->scrubString($v, $secrets);
            } elseif (is_array($v)) {
                $value[$k] = $this->scrubArray($v, $secrets);
            }
        }
        return $value;
    }
}
