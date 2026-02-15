<?php

declare(strict_types=1);

namespace NwsCad\Api;

use JsonException;

/**
 * HTTP Request Handler
 * 
 * Parses and validates incoming HTTP requests for the API.
 * Provides safe access to query parameters, POST data, and JSON bodies.
 * 
 * @package NwsCad\Api
 */
class Request
{
    /**
     * Get query parameters from GET request
     * 
     * @param string|null $key Specific key to retrieve, or null for all parameters
     * @param mixed $default Default value if key not found
     * @return mixed Query parameter value, all parameters, or default
     */
    public static function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_GET;
        }

        return $_GET[$key] ?? $default;
    }

    /**
     * Get POST data
     * 
     * @param string|null $key Specific key to retrieve, or null for all data
     * @param mixed $default Default value if key not found
     * @return mixed POST value, all POST data, or default
     */
    public static function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_POST;
        }

        return $_POST[$key] ?? $default;
    }

    /**
     * Get JSON body from request
     * 
     * Parses the raw request body as JSON. Returns null if body is empty
     * or not valid JSON.
     * 
     * @return array|null Parsed JSON as associative array, or null on failure
     * @throws JsonException If JSON parsing fails with JSON_THROW_ON_ERROR
     */
    public static function json(): ?array
    {
        $body = file_get_contents('php://input');
        
        if (empty($body)) {
            return null;
        }
        
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            
            // json_decode with true returns array for objects, but could return
            // other types for scalar JSON values - ensure we return array or null
            if (!is_array($decoded)) {
                return null;
            }
            
            return $decoded;
        } catch (JsonException $e) {
            // Log the error for debugging but don't expose details
            error_log('[Request] JSON parse error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get HTTP header value
     * 
     * @param string $name Header name (case-insensitive, dashes accepted)
     * @return string|null Header value or null if not present
     */
    public static function header(string $name): ?string
    {
        $name = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$name] ?? null;
    }

    /**
     * Get pagination parameters
     * 
     * Validates and normalizes pagination parameters:
     * - page: minimum 1
     * - per_page: minimum 1, maximum 100
     * 
     * @return array{page: int, per_page: int} Normalized pagination parameters
     */
    public static function pagination(): array
    {
        return [
            'page' => max(1, (int)self::query('page', 1)),
            'per_page' => min(100, max(1, (int)self::query('per_page', 30)))
        ];
    }

    /**
     * Get search query parameter
     * 
     * Checks both 'search' and 'q' parameters.
     * 
     * @return string|null Search query or null if not provided
     */
    public static function search(): ?string
    {
        $search = self::query('search') ?: self::query('q');
        return $search !== null ? (string)$search : null;
    }

    /**
     * Get filter parameters
     * 
     * Only returns values for explicitly allowed filter keys.
     * 
     * @param array<string> $allowed List of allowed filter parameter names
     * @return array<string, mixed> Associative array of filter key => value
     */
    public static function filters(array $allowed = []): array
    {
        $filters = [];
        
        foreach ($allowed as $key) {
            $value = self::query($key);
            if ($value !== null) {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * Get sorting parameters
     * 
     * Normalizes sort direction to uppercase ASC or DESC.
     * 
     * @param string $defaultSort Default sort field
     * @param string $defaultOrder Default sort direction ('asc' or 'desc')
     * @return array{sort: string, order: string} Sorting parameters
     */
    public static function sorting(string $defaultSort = 'id', string $defaultOrder = 'desc'): array
    {
        return [
            'sort' => self::query('sort', $defaultSort),
            'order' => strtolower((string)self::query('order', $defaultOrder)) === 'asc' ? 'ASC' : 'DESC'
        ];
    }
    
    /**
     * Get request method
     * 
     * @return string HTTP method (GET, POST, PUT, DELETE, etc.)
     */
    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }
    
    /**
     * Check if request is an AJAX/XHR request
     * 
     * @return bool True if X-Requested-With header is XMLHttpRequest
     */
    public static function isAjax(): bool
    {
        return strtolower(self::header('X-Requested-With') ?? '') === 'xmlhttprequest';
    }
}
