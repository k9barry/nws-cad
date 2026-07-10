<?php

declare(strict_types=1);

namespace NwsCad\Api;

/**
 * HTTP Response Handler
 * Formats and sends API responses
 */
class Response
{
    /**
     * Set in testing mode after the first json() call so that any subsequent
     * Response::* call in the same request becomes a no-op. Production
     * behaviour (echo + exit) is unchanged: the first call halts the process,
     * so the flag is never read. Tests must call {@see resetForTesting()} in
     * setUp() to start each test with a clean slate.
     */
    private static bool $alreadySent = false;

    /**
     * Send JSON response
     */
    public static function json(mixed $data, int $statusCode = 200): void
    {
        // PHPUnit defines PHPUNIT_COMPOSER_INSTALL in its bootstrap, which is
        // a reliable signal we are running under tests regardless of how the
        // test happens to be playing with APP_ENV / $_ENV / putenv. (Some
        // tests set APP_ENV=production to exercise prod-only branches.) In
        // production neither this constant nor any phpunit code is loaded.
        $inTests = defined('PHPUNIT_COMPOSER_INSTALL');

        if ($inTests && self::$alreadySent) {
            // A controller already responded earlier in this request. In
            // production exit() would have prevented us reaching here; in
            // tests we silently drop the second body so a generic
            // catch (\Exception) -> Response::error() inside a controller
            // doesn't double-emit JSON onto the captured output.
            return;
        }
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! $inTests) {
            exit;
        }
        self::$alreadySent = true;
    }

    /**
     * Reset the testing-mode "already sent" flag. Tests must call this in
     * setUp() so each test starts with a fresh response state.
     */
    public static function resetForTesting(): void
    {
        self::$alreadySent = false;
    }

    /**
     * Send success response
     */
    public static function success(mixed $data, string $message = null, int $statusCode = 200): void
    {
        $response = [
            'success' => true,
            'data' => $data
        ];

        if ($message) {
            $response['message'] = $message;
        }

        self::json($response, $statusCode);
    }

    /**
     * Send error response
     */
    public static function error(string $message, int $statusCode = 400, array $errors = []): void
    {
        $response = [
            'success' => false,
            'error' => $message
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        self::json($response, $statusCode);
    }

    /**
     * Send 404 Not Found response
     */
    public static function notFound(array $data = []): void
    {
        $response = array_merge([
            'success' => false,
            'error' => 'Resource not found'
        ], $data);

        self::json($response, 404);
    }

    /**
     * Send 401 Unauthorized response
     */
    public static function unauthorized(string $message = 'Unauthorized'): void
    {
        self::error($message, 401);
    }

    /**
     * Send 403 Forbidden response
     */
    public static function forbidden(string $message = 'Forbidden'): void
    {
        self::error($message, 403);
    }

    /**
     * Send 500 Internal Server Error response
     */
    public static function serverError(string $message = 'Internal server error'): void
    {
        self::error($message, 500);
    }

    /**
     * Send a 500 response derived from a caught exception WITHOUT leaking the
     * exception detail to the client. The full exception (message, class,
     * origin) is written to the server error log; the client receives only
     * the generic $publicMessage. Use this instead of concatenating
     * $e->getMessage() into a Response::error(..., 500) call — driver errors,
     * SQL fragments, and file paths must never reach API clients.
     */
    public static function serverErrorFromException(\Throwable $e, string $publicMessage = 'Internal server error'): void
    {
        error_log(sprintf(
            '%s: %s [%s] at %s:%d',
            $publicMessage,
            $e->getMessage(),
            get_class($e),
            $e->getFile(),
            $e->getLine()
        ));

        self::error($publicMessage, 500);
    }

    /**
     * Send paginated response
     */
    public static function paginated(array $data, int $total, int $page, int $perPage): void
    {
        $totalPages = (int)ceil($total / $perPage);

        self::success([
            'items' => $data,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => $totalPages,
                'has_more' => $page < $totalPages
            ]
        ]);
    }
}
