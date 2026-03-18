<?php
// api/auth/login.php
require_once '../../config/cors.php';
require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input    = json_decode(file_get_contents('php://input'), true);
$email    = strtolower(trim($input['email']    ?? ''));
$password = $input['password'] ?? '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email and password are required']);
    exit;
}

$users = Database::collection('users');
$user = $users->findOne([
    'email' => $email,
    'is_active' => 1,
]);
$user = Database::docToArray($user);

if (empty($user) || !password_verify($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid email or password']);
    exit;
}

// Rotate auth token on each login
$authToken = bin2hex(random_bytes(32));
$users->updateOne(
    ['user_id' => (int)$user['user_id']],
    ['$set' => ['auth_token' => $authToken, 'last_login' => Database::now()]]
);

echo json_encode([
    'success' => true,
    'data' => [
        'user_id'    => (int)$user['user_id'],
        'username'   => $user['username'],
        'email'      => $user['email'],
        'auth_token' => $authToken,
    ]
]);
