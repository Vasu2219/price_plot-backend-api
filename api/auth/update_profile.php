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

$db   = Database::getConnection();
$stmt = $db->prepare('SELECT user_id FROM users WHERE auth_token = ? AND is_active = 1');
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
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
$dup = $db->prepare('SELECT user_id FROM users WHERE email = ? AND user_id != ?');
$dup->execute([$email, $user['user_id']]);
if ($dup->fetch()) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Email already in use']);
    exit;
}

$upd = $db->prepare('UPDATE users SET username = ?, email = ? WHERE user_id = ?');
$upd->execute([$username, $email, $user['user_id']]);

echo json_encode([
    'success'  => true,
    'message'  => 'Profile updated successfully',
    'username' => $username,
    'email'    => $email
]);
