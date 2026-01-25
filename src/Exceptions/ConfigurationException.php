<?php

declare(strict_types=1);

namespace NwsCad\Exceptions;

use Exception;

/**
 * Exception for configuration-related errors
 * 
 * Thrown when configuration is invalid, missing,
 * or contains unsupported values.
 */
class ConfigurationException extends Exception
{
    /**
     * Create a new ConfigurationException for missing configuration
     *
     * @param string $key The missing configuration key
     * @return self
     */
    public static function missingKey(string $key): self
    {
        return new self("Required configuration key is missing: $key");
    }

    /**
     * Create a new ConfigurationException for invalid configuration
     *
     * @param string $key The invalid configuration key
     * @param string $reason The reason the value is invalid
     * @return self
     */
    public static function invalidValue(string $key, string $reason): self
    {
        return new self("Configuration value for '$key' is invalid: $reason");
    }

    /**
     * Create a new ConfigurationException for unsupported options
     *
     * @param string $option The unsupported option
     * @param array $supported List of supported options
     * @return self
     */
    public static function unsupportedOption(string $option, array $supported): self
    {
        $supportedList = implode(', ', $supported);
        return new self("Unsupported option '$option'. Supported options are: $supportedList");
    }
}
