<?php
// api/auth/register.php
require_once '../../config/cors.php';
require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$username = trim($input['username'] ?? '');
$email    = strtolower(trim($input['email'] ?? ''));
$password = $input['password'] ?? '';

// Validation
if (empty($username) || empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'All fields are required']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email address']);
    exit;
}
if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters']);
    exit;
}

$users = Database::collection('users');

// Email is the only unique login identifier.
$existing = $users->findOne(['email' => $email]);
if ($existing) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Email already in use']);
    exit;
}

$passwordHash = password_hash($password, PASSWORD_BCRYPT);
$authToken    = bin2hex(random_bytes(32));
try {
    $userId       = Database::nextId('users');

    $users->insertOne([
        'user_id' => $userId,
        'username' => $username,
        'email' => $email,
        'password_hash' => $passwordHash,
        'auth_token' => $authToken,
        'created_at' => Database::now(),
        'last_login' => Database::now(),
        'is_active' => 1,
    ]);
} catch (Throwable $e) {
    $errorText = strtolower($e->getMessage());
    if (strpos($errorText, 'duplicate key') !== false || strpos($errorText, 'e11000') !== false) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Email already in use']);
        exit;
    }

    error_log('[REGISTER] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Registration failed. Please try again.']);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => [
        'user_id'    => (int)$userId,
        'username'   => $username,
        'email'      => $email,
        'auth_token' => $authToken,
    ]
]);
