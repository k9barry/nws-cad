<?php

declare(strict_types=1);

namespace NwsCad\Security;

/**
 * Input Validator
 * 
 * Provides comprehensive input validation and sanitization
 * to prevent security vulnerabilities.
 */
class InputValidator
{
    /**
     * Validate and sanitize a string input
     *
     * @param mixed $input The input to validate
     * @param int $minLength Minimum length (default: 0)
     * @param int $maxLength Maximum length (default: 1000)
     * @param bool $allowHtml Whether to allow HTML tags (default: false)
     * @return string|null Sanitized string or null if invalid
     */
    public static function validateString($input, int $minLength = 0, int $maxLength = 1000, bool $allowHtml = false): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }

        $input = (string)$input;
        
        // Remove NULL bytes
        $input = str_replace("\0", '', $input);
        
        // Strip HTML tags if not allowed
        if (!$allowHtml) {
            $input = strip_tags($input);
        }
        
        // Trim whitespace
        $input = trim($input);
        
        // Check length
        $length = mb_strlen($input);
        if ($length < $minLength || $length > $maxLength) {
            return null;
        }
        
        return $input;
    }

    /**
     * Validate an integer input
     *
     * @param mixed $input The input to validate
     * @param int|null $min Minimum value (default: null)
     * @param int|null $max Maximum value (default: null)
     * @return int|null Validated integer or null if invalid
     */
    public static function validateInteger($input, ?int $min = null, ?int $max = null): ?int
    {
        if ($input === null || $input === '') {
            return null;
        }

        if (!is_numeric($input)) {
            return null;
        }

        $value = (int)$input;

        if ($min !== null && $value < $min) {
            return null;
        }

        if ($max !== null && $value > $max) {
            return null;
        }

        return $value;
    }

    /**
     * Validate a float input
     *
     * @param mixed $input The input to validate
     * @param float|null $min Minimum value (default: null)
     * @param float|null $max Maximum value (default: null)
     * @return float|null Validated float or null if invalid
     */
    public static function validateFloat($input, ?float $min = null, ?float $max = null): ?float
    {
        if ($input === null || $input === '') {
            return null;
        }

        if (!is_numeric($input)) {
            return null;
        }

        $value = (float)$input;

        if ($min !== null && $value < $min) {
            return null;
        }

        if ($max !== null && $value > $max) {
            return null;
        }

        return $value;
    }

    /**
     * Validate an email address
     *
     * @param mixed $input The input to validate
     * @return string|null Validated email or null if invalid
     */
    public static function validateEmail($input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }

        $email = filter_var($input, FILTER_VALIDATE_EMAIL);
        return $email !== false ? $email : null;
    }

    /**
     * Validate a URL
     *
     * @param mixed $input The input to validate
     * @return string|null Validated URL or null if invalid
     */
    public static function validateUrl($input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }

        $url = filter_var($input, FILTER_VALIDATE_URL);
        return $url !== false ? $url : null;
    }

    /**
     * Validate a date string
     *
     * @param mixed $input The input to validate
     * @param string $format Expected date format (default: 'Y-m-d')
     * @return string|null Validated date or null if invalid
     */
    public static function validateDate($input, string $format = 'Y-m-d'): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }

        $date = \DateTime::createFromFormat($format, (string)$input);
        if ($date && $date->format($format) === (string)$input) {
            return (string)$input;
        }

        return null;
    }

    /**
     * Validate latitude and longitude coordinates
     *
     * @param mixed $lat Latitude value
     * @param mixed $lng Longitude value
     * @return array|null Array with validated [lat, lng] or null if invalid
     */
    public static function validateCoordinates($lat, $lng): ?array
    {
        $latitude = self::validateFloat($lat, -90, 90);
        $longitude = self::validateFloat($lng, -180, 180);

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [$latitude, $longitude];
    }

    /**
     * Sanitize output for HTML display (prevent XSS)
     *
     * @param mixed $input The input to sanitize
     * @return string Sanitized string safe for HTML output
     */
    public static function sanitizeOutput($input): string
    {
        if ($input === null) {
            return '';
        }

        return htmlspecialchars((string)$input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Validate a phone number (flexible format)
     *
     * @param mixed $input The input to validate
     * @return string|null Validated phone number or null if invalid
     */
    public static function validatePhone($input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }

        // Remove all non-digit characters except + for international
        $phone = preg_replace('/[^0-9+]/', '', (string)$input);

        // Check if we have at least 10 digits (US standard)
        if (strlen(str_replace('+', '', $phone)) < 10) {
            return null;
        }

        return $phone;
    }

    /**
     * Validate an array of allowed values
     *
     * @param mixed $input The input to validate
     * @param array $allowedValues Array of allowed values
     * @return mixed Validated value or null if not in allowed list
     */
    public static function validateEnum($input, array $allowedValues)
    {
        if ($input === null || !in_array($input, $allowedValues, true)) {
            return null;
        }

        return $input;
    }
}
