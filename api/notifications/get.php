<?php
// api/notifications/get.php
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

$user = requireAuth();
$notificationsCollection = Database::collection('notifications');
$productsCollection = Database::collection('products');

$cursor = $notificationsCollection->find(
    ['user_id' => (int)$user['user_id']],
    ['sort' => ['created_at' => -1], 'limit' => 50]
);

$rows = [];
foreach ($cursor as $notificationDoc) {
    $notification = Database::docToArray($notificationDoc);
    $product = $productsCollection->findOne(['product_id' => (int)$notification['product_id']]);
    $product = Database::docToArray($product);

    $rows[] = [
        'notification_id' => (int)($notification['notification_id'] ?? 0),
        'product_id' => (int)($notification['product_id'] ?? 0),
        'message' => $notification['message'] ?? '',
        'old_price' => isset($notification['old_price']) ? (float)$notification['old_price'] : null,
        'new_price' => isset($notification['new_price']) ? (float)$notification['new_price'] : null,
        'is_read' => (bool)($notification['is_read'] ?? false),
        'created_at' => Database::toIsoString($notification['created_at'] ?? null),
        'product_name' => $product['product_name'] ?? 'Price Alert',
        'product_image_url' => $product['product_image_url'] ?? '',
    ];
}

echo json_encode(['success' => true, 'data' => $rows]);
