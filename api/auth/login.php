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

$db   = Database::getConnection();
$stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid email or password']);
    exit;
}

// Rotate auth token on each login
$authToken = bin2hex(random_bytes(32));
$db->prepare('UPDATE users SET auth_token = ?, last_login = NOW() WHERE user_id = ?')
   ->execute([$authToken, $user['user_id']]);

echo json_encode([
    'success' => true,
    'data' => [
        'user_id'    => (int)$user['user_id'],
        'username'   => $user['username'],
        'email'      => $user['email'],
        'auth_token' => $authToken,
    ]
]);
