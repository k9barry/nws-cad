<?php

declare(strict_types=1);

namespace NwsCad\Exceptions;

use Exception;

/**
 * Exception for validation errors
 * 
 * Thrown when input validation fails or data
 * does not meet required criteria.
 */
class ValidationException extends Exception
{
    private array $errors = [];

    /**
     * Create a new ValidationException with validation errors
     *
     * @param string $message The error message
     * @param array $errors Array of validation errors
     * @param int $code The error code
     * @param Exception|null $previous The previous exception
     */
    public function __construct(string $message, array $errors = [], int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    /**
     * Get validation errors
     *
     * @return array Array of validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Create a new ValidationException for invalid input
     *
     * @param string $field The field name
     * @param string $reason The reason for validation failure
     * @return self
     */
    public static function invalidInput(string $field, string $reason): self
    {
        return new self("Validation failed for '$field': $reason", [$field => $reason]);
    }

    /**
     * Create a new ValidationException for missing required field
     *
     * @param string $field The missing field name
     * @return self
     */
    public static function missingRequired(string $field): self
    {
        return new self("Required field is missing: $field", [$field => 'required']);
    }

    /**
     * Create a new ValidationException for multiple validation errors
     *
     * @param array $errors Array of field => error message pairs
     * @return self
     */
    public static function multiple(array $errors): self
    {
        $message = "Multiple validation errors: " . implode(', ', array_keys($errors));
        return new self($message, $errors);
    }
}
