<?php
// api/notifications/mark_read.php
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }

$user  = requireAuth();
$input = json_decode(file_get_contents('php://input'), true);
$notifications = Database::collection('notifications');

if (isset($input['notification_id'])) {
    // Mark single
    $notifications->updateOne(
        [
            'notification_id' => (int)$input['notification_id'],
            'user_id' => (int)$user['user_id'],
        ],
        ['$set' => ['is_read' => 1]]
    );
} else {
    // Mark all
    $notifications->updateMany(
        ['user_id' => (int)$user['user_id']],
        ['$set' => ['is_read' => 1]]
    );
}

echo json_encode(['success' => true, 'message' => 'Marked as read']);
