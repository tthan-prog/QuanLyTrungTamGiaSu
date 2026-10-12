<?php
require_once __DIR__ . '/../middleware/RoutePolicy.php';

class Router
{
    private static $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'DELETE' => []
    ];

    public static function get(string $path, callable|array $handler): void
    {
        self::$routes['GET'][$path] = $handler;
    }

    public static function post(string $path, callable|array $handler): void
    {
        self::$routes['POST'][$path] = $handler;
    }

    public static function put(string $path, callable|array $handler): void
    {
        self::$routes['PUT'][$path] = $handler;
    }

    public static function delete(string $path, callable|array $handler): void
    {
        self::$routes['DELETE'][$path] = $handler;
    }

    public static function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = self::getUri();

        if ($method === 'OPTIONS') {
            http_response_code(200);
            exit();
        }

        $routes = self::$routes[$method] ?? [];
        
        foreach ($routes as $pattern => $handler) {
            $params = self::matchRoute($pattern, $uri);
            
            if ($params !== false) {
                // Kiểm tra đăng nhập / vai trò theo RoutePolicy (401/403 sẽ dừng tại đây)
                RoutePolicy::enforce($method, $uri);

                try {
                    self::callHandler($handler, $params);
                } catch (Throwable $e) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Router error: ' . $e->getMessage()
                    ], JSON_UNESCAPED_UNICODE);
                }
                return;
            }
        }

        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'API endpoint không tồn tại',
            'path' => $uri,
            'method' => $method
        ], JSON_UNESCAPED_UNICODE);
    }

    private static function getUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        if (strpos($uri, '?') !== false) {
            $uri = strstr($uri, '?', true);
        }

        $basePaths = [
            '/QuanLyTrungTamGiaSu/backend/public/index.php',
            '/QuanLyTrungTamGiaSu/backend/public'
        ];
        
        foreach ($basePaths as $basePath) {
            if (strpos($uri, $basePath) === 0) {
                $uri = substr($uri, strlen($basePath));
                break;
            }
        }

        // Loại bỏ /api prefix nếu có
        if (strpos($uri, '/api') === 0) {
            $uri = substr($uri, 4); // Loại bỏ '/api'
        }

        return $uri ?: '/';
    }

    private static function matchRoute(string $pattern, string $uri): array|false
    {
        $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $uri, $matches)) {
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return false;
    }

    private static function callHandler(callable|array $handler, array $params): void
    {
        // Convert named params to positional (array_values)
        $positionalParams = array_values($params);
        
        if (is_array($handler)) {
            [$class, $method] = $handler;
            // Instantiate class nếu là class name string
            if (is_string($class)) {
                $resolvedClass = self::resolveControllerClass($class);
                if (!method_exists($resolvedClass, $method)) {
                    throw new Exception("Method {$method} not found in {$resolvedClass}");
                }

                $instance = new $resolvedClass();
                call_user_func_array([$instance, $method], $positionalParams);
            } else {
                call_user_func_array([$class, $method], $positionalParams);
            }
        } else {
            call_user_func_array($handler, $positionalParams);
        }
    }

    private static function resolveControllerClass(string $class): string
    {
        if (class_exists($class)) {
            return $class;
        }

        // Try loading controller file directly in case route/controller autoload order changes.
        $controllerPath = __DIR__ . '/../controllers/' . $class . '.php';
        if (file_exists($controllerPath)) {
            require_once $controllerPath;
            if (class_exists($class)) {
                return $class;
            }
        }

        // Fallback: resolve class name case-insensitively for Linux environments.
        foreach (get_declared_classes() as $declaredClass) {
            if (strcasecmp($declaredClass, $class) === 0) {
                return $declaredClass;
            }
        }

        throw new Exception("Class {$class} not found");
    }
}
