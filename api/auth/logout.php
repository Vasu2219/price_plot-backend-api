<?php
// api/auth/logout.php
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
    echo json_encode(['success' => false, 'error' => 'Missing auth token']);
    exit;
}

$db = Database::getConnection();
$db->prepare('UPDATE users SET auth_token = NULL WHERE auth_token = ?')->execute([$token]);

echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
