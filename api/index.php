<?php
/**
 * Sunwise API Entry Point
 * Vanilla PHP router for shared hosting compatibility
 */

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load configuration
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Autoload classes
spl_autoload_register(function ($class) {
    $paths = [
        __DIR__ . '/controllers/',
        __DIR__ . '/services/',
        __DIR__ . '/middleware/',
        __DIR__ . '/models/'
    ];

    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Get request info
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove /api prefix if present
$uri = preg_replace('#^/api#', '', $uri);
$uri = '/' . trim($uri, '/');

// Route definitions
$routes = [
    // Auth routes
    'POST /auth/register' => ['AuthController', 'register'],
    'POST /auth/login' => ['AuthController', 'login'],
    'POST /auth/magic-link' => ['AuthController', 'requestMagicLink'],
    'GET /auth/verify/{token}' => ['AuthController', 'verifyMagicLink'],
    'GET /auth/me' => ['AuthController', 'me', true],
    'POST /auth/logout' => ['AuthController', 'logout', true],

    // Plant routes
    'GET /plants' => ['PlantController', 'index', true],
    'POST /plants' => ['PlantController', 'store', true],
    'GET /plants/{id}' => ['PlantController', 'show', true],
    'PUT /plants/{id}' => ['PlantController', 'update', true],
    'DELETE /plants/{id}' => ['PlantController', 'destroy', true],
    'POST /plants/{id}/photo' => ['PhotoController', 'store', true],
    'GET /plants/{id}/photos' => ['PhotoController', 'index', true],
    'GET /plants/{id}/care-plan' => ['CarePlanController', 'show', true],
    'POST /plants/{id}/care-plan/regenerate' => ['CarePlanController', 'regenerate', true],
    'GET /plants/{id}/care-log' => ['CarePlanController', 'careLog', true],

    // Task routes
    'GET /tasks/today' => ['TaskController', 'today', true],
    'GET /tasks/upcoming' => ['TaskController', 'upcoming', true],
    'GET /tasks/plant/{id}' => ['TaskController', 'forPlant', true],
    'POST /tasks/{id}/complete' => ['TaskController', 'complete', true],
    'POST /tasks/{id}/skip' => ['TaskController', 'skip', true],

    // Sitter routes
    'POST /sitter/create' => ['SitterController', 'create', true],
    'GET /sitter/{token}' => ['SitterController', 'show'],
    'POST /sitter/{token}/task/{id}' => ['SitterController', 'completeTask'],

    // AI routes
    'POST /ai/identify' => ['AIController', 'identify', true],
    'POST /ai/health-check' => ['AIController', 'healthCheck', true],

    // Notification routes
    'POST /notifications/subscribe' => ['NotificationController', 'subscribe', true],
    'DELETE /notifications/unsubscribe' => ['NotificationController', 'unsubscribe', true],
];

// Match route
$matchedRoute = null;
$params = [];

foreach ($routes as $pattern => $config) {
    list($routeMethod, $routePath) = explode(' ', $pattern, 2);

    if ($method !== $routeMethod) {
        continue;
    }

    // Convert route pattern to regex
    $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $routePath);
    $regex = '#^' . $regex . '$#';

    if (preg_match($regex, $uri, $matches)) {
        $matchedRoute = $config;
        // Extract named parameters
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }
        break;
    }
}

// 404 if no route matched
if (!$matchedRoute) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

// Extract route config
$controllerName = $matchedRoute[0];
$methodName = $matchedRoute[1];
$requiresAuth = $matchedRoute[2] ?? false;

// Auth check
$userId = null;
if ($requiresAuth) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $token = $matches[1];
    $auth = new AuthMiddleware();
    $userId = $auth->validateToken($token);

    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token']);
        exit;
    }
}

// Get request body
$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Merge with form data and files for multipart requests
if (!empty($_POST)) {
    $body = array_merge($body, $_POST);
}

// Create controller and call method
try {
    $controller = new $controllerName();
    $response = $controller->$methodName($params, $body, $userId);

    http_response_code($response['status'] ?? 200);
    echo json_encode($response['data'] ?? $response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
