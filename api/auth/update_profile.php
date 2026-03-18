<?php
// api/auth/update_profile.php
require_once '../../config/cors.php';
require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
if (empty($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$users = Database::collection('users');
$user = $users->findOne([
    'auth_token' => $token,
    'is_active' => 1,
]);
$user = Database::docToArray($user);

if (empty($user)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired token']);
    exit;
}

$input    = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$email    = strtolower(trim($input['email'] ?? ''));

if (empty($username) || empty($email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Username and email are required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email format']);
    exit;
}

// Email remains unique; usernames can repeat.
$dup = $users->findOne([
    'email' => $email,
    'user_id' => ['$ne' => (int)$user['user_id']],
]);
if ($dup) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Email already in use']);
    exit;
}

$users->updateOne(
    ['user_id' => (int)$user['user_id']],
    ['$set' => ['username' => $username, 'email' => $email]]
);

echo json_encode([
    'success'  => true,
    'message'  => 'Profile updated successfully',
    'username' => $username,
    'email'    => $email
]);
