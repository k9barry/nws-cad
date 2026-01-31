<?php

declare(strict_types=1);

namespace NwsCad\Api;

/**
 * API Router
 * Routes HTTP requests to appropriate controllers
 */
class Router
{
    private array $routes = [];
    private string $basePath;

    public function __construct(string $basePath = '/api')
    {
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * Register a GET route
     */
    public function get(string $path, callable|array $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route
     */
    public function post(string $path, callable|array $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register a PUT route
     */
    public function put(string $path, callable|array $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register a DELETE route
     */
    public function delete(string $path, callable|array $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Add a route
     */
    private function addRoute(string $method, string $path, callable|array $handler): void
    {
        $pattern = $this->convertPathToPattern($path);
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'path' => $path,
            'handler' => $handler
        ];
    }

    /**
     * Convert path with parameters to regex pattern
     */
    private function convertPathToPattern(string $path): string
    {
        // Convert {id} to named capture group
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $this->basePath . $pattern . '$#';
    }

    /**
     * Dispatch request to appropriate handler
     */
    public function dispatch(string $method, string $uri): void
    {
        // Remove query string
        $uri = strtok($uri, '?');
        
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $uri, $matches)) {
                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                
                // Resolve handler
                $handler = $route['handler'];
                
                // If handler is array [ControllerClass, 'method'], instantiate controller
                if (is_array($handler) && count($handler) === 2) {
                    [$class, $method] = $handler;
                    if (is_string($class) && class_exists($class)) {
                        $controller = new $class();
                        $handler = [$controller, $method];
                    }
                }
                
                // Call handler with parameters
                // If params is empty, call without arguments
                // If params has values, pass them as individual arguments
                if (empty($params)) {
                    call_user_func($handler);
                } else {
                    call_user_func($handler, ...array_values($params));
                }
                return;
            }
        }

        // No route found
        Response::notFound(['error' => 'Endpoint not found']);
    }

    /**
     * Get current request method
     */
    public static function getMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * Get current request URI
     */
    public static function getUri(): string
    {
        return $_SERVER['REQUEST_URI'] ?? '/';
    }
}
