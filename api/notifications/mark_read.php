<?php
// api/notifications/mark_read.php
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }

$user  = requireAuth();
$input = json_decode(file_get_contents('php://input'), true);
$db    = Database::getConnection();

if (isset($input['notification_id'])) {
    // Mark single
    $db->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?')
       ->execute([(int)$input['notification_id'], $user['user_id']]);
} else {
    // Mark all
    $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')
       ->execute([$user['user_id']]);
}

echo json_encode(['success' => true, 'message' => 'Marked as read']);
