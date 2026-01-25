<?php

namespace NwsCad\Api;

/**
 * HTTP Request Handler
 * Parses and validates incoming requests
 */
class Request
{
    /**
     * Get query parameters
     */
    public static function query(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_GET;
        }

        return $_GET[$key] ?? $default;
    }

    /**
     * Get POST data
     */
    public static function post(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_POST;
        }

        return $_POST[$key] ?? $default;
    }

    /**
     * Get JSON body
     */
    public static function json(): ?array
    {
        $body = file_get_contents('php://input');
        return json_decode($body, true);
    }

    /**
     * Get header
     */
    public static function header(string $name): ?string
    {
        $name = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$name] ?? null;
    }

    /**
     * Get pagination parameters
     */
    public static function pagination(): array
    {
        return [
            'page' => max(1, (int)self::query('page', 1)),
            'per_page' => min(100, max(1, (int)self::query('per_page', 30)))
        ];
    }

    /**
     * Get search parameters
     */
    public static function search(): ?string
    {
        return self::query('search') ?: self::query('q');
    }

    /**
     * Get filter parameters
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
     */
    public static function sorting(string $defaultSort = 'id', string $defaultOrder = 'desc'): array
    {
        return [
            'sort' => self::query('sort', $defaultSort),
            'order' => strtolower(self::query('order', $defaultOrder)) === 'asc' ? 'ASC' : 'DESC'
        ];
    }
}
