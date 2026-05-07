<?php

declare(strict_types=1);

namespace NwsCad\Exceptions;

use RuntimeException;

class MissingSecretException extends RuntimeException
{
    private string $key;

    public static function forKey(string $key): self
    {
        $e = new self(sprintf('Required secret "%s" is not set in the environment.', $key));
        $e->key = $key;
        return $e;
    }

    public function getKey(): string
    {
        return $this->key;
    }
}
