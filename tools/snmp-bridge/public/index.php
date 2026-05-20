<?php

declare(strict_types=1);

use SnmpBridge\Http\Controllers\Controller;

session_start();

$container = require dirname(__DIR__) . '/bootstrap/app.php';

if (!isset($_SESSION['_token'])) {
    $_SESSION['_token'] = bin2hex(random_bytes(32));
}

$routes = require SNMP_BRIDGE_ROOT . '/routes/web.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$routeMethod = $method === 'HEAD' ? 'GET' : $method;
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');

if ($basePath !== '' && $basePath !== '/' && str_starts_with($requestPath, $basePath)) {
    $requestPath = substr($requestPath, strlen($basePath)) ?: '/';
}

if ($requestPath === '/index.php') {
    $requestPath = '/';
}

if (str_starts_with($requestPath, '/index.php/')) {
    $requestPath = substr($requestPath, strlen('/index.php')) ?: '/';
}

if ($method === 'POST') {
    $token = (string) ($_POST['_token'] ?? '');

    if (!hash_equals((string) $_SESSION['_token'], $token)) {
        http_response_code(419);
        echo 'CSRF token mismatch.';
        exit;
    }
}

$routeKey = $routeMethod . ' ' . $requestPath;
$handler = $routes[$routeKey] ?? null;

if ($handler === null) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

[$controllerClass, $action] = $handler;
$controller = $container['controllers'][$controllerClass] ?? null;

if (!$controller instanceof Controller || !method_exists($controller, $action)) {
    http_response_code(500);
    echo 'Route handler is not available.';
    exit;
}

try {
    echo $controller->{$action}();
} catch (Throwable $throwable) {
    http_response_code(500);
    echo '<h1>Application error</h1><pre>' . e($throwable->getMessage()) . '</pre>';
}
