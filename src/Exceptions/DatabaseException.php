<?php

declare(strict_types=1);

namespace NwsCad\Exceptions;

use Exception;

/**
 * Exception for database-related errors
 * 
 * Thrown when database connection fails, queries fail,
 * or other database-related issues occur.
 */
class DatabaseException extends Exception
{
    /**
     * Create a new DatabaseException for connection failures
     *
     * @param string $message The error message
     * @param int $code The error code
     * @param Exception|null $previous The previous exception
     * @return self
     */
    public static function connectionFailed(string $message, int $code = 0, ?Exception $previous = null): self
    {
        return new self("Database connection failed: $message", $code, $previous);
    }

    /**
     * Create a new DatabaseException for query failures
     *
     * @param string $message The error message
     * @param int $code The error code
     * @param Exception|null $previous The previous exception
     * @return self
     */
    public static function queryFailed(string $message, int $code = 0, ?Exception $previous = null): self
    {
        return new self("Database query failed: $message", $code, $previous);
    }

    /**
     * Create a new DatabaseException for transaction failures
     *
     * @param string $message The error message
     * @param int $code The error code
     * @param Exception|null $previous The previous exception
     * @return self
     */
    public static function transactionFailed(string $message, int $code = 0, ?Exception $previous = null): self
    {
        return new self("Database transaction failed: $message", $code, $previous);
    }
}
