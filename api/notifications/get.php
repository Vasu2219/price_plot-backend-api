<?php
// api/notifications/get.php
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

$user = requireAuth();
$db   = Database::getConnection();

$stmt = $db->prepare(
    'SELECT n.notification_id, n.product_id, n.message, n.old_price, n.new_price,
            n.is_read, n.created_at, p.product_name, p.product_image_url
     FROM notifications n
     JOIN products p ON p.product_id = n.product_id
     WHERE n.user_id = ?
     ORDER BY n.created_at DESC
     LIMIT 50'
);
$stmt->execute([$user['user_id']]);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['notification_id'] = (int)$r['notification_id'];
    $r['product_id']      = (int)$r['product_id'];
    $r['is_read']         = (bool)$r['is_read'];
    $r['old_price']       = $r['old_price'] !== null ? (float)$r['old_price'] : null;
    $r['new_price']       = $r['new_price'] !== null ? (float)$r['new_price'] : null;
}

echo json_encode(['success' => true, 'data' => $rows]);
