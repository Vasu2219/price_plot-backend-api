<?php
// config/cors.php — CORS + JSON headers for Android API calls and Web App
// Allow both mobile and web app requests

$default_allowed_origins = [
    'http://localhost:3000',
    'http://localhost:8080',
    'http://localhost:8081',
    'https://localhost:3000',
    'https://localhost:8080',
    'https://localhost:8081',
    'http://10.0.8.81:8080',
    'http://10.0.8.81:8081',
    'http://127.0.0.1:8080',
    'http://127.0.0.1:8081',
];

$allowedOriginsEnv = getenv('ALLOWED_ORIGINS') ?: '';
$allowed_origins = $default_allowed_origins;

if (!empty($allowedOriginsEnv)) {
    $allowed_origins = array_values(array_filter(array_map('trim', explode(',', $allowedOriginsEnv))));
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$is_allowed_origin = in_array($origin, $allowed_origins, true)
    || (bool)preg_match('/^https:\/\/[a-z0-9.-]+\.devtunnels\.ms$/i', $origin);

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($is_allowed_origin) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_PRIVATE_NETWORK'])
            && $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_PRIVATE_NETWORK'] === 'true') {
            header('Access-Control-Allow-Private-Network: true');
        }
        header('Vary: Origin');
        header('Access-Control-Max-Age: 3600');
    }
    http_response_code(200);
    exit;
}

// Handle actual requests
if ($is_allowed_origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');
    header('Vary: Origin');
} else if ($origin === '' || !isset($_SERVER['HTTP_ORIGIN'])) {
    // Mobile app or same-origin requests
    header('Access-Control-Allow-Origin: *');
}

header('Content-Type: application/json; charset=utf-8');
