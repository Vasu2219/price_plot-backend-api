<?php
// api/wishlist/add.php
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }

$user  = requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

$productId   = (int)($input['product_id']   ?? 0);
$targetPrice = isset($input['target_price']) ? (float)$input['target_price'] : null;

if (!$productId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'product_id is required']);
    exit;
}

$db = Database::getConnection();

// Verify product exists
$check = $db->prepare('SELECT product_id FROM products WHERE product_id = ?');
$check->execute([$productId]);
if (!$check->fetch()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Product not found']);
    exit;
}

$stmt = $db->prepare(
    'INSERT IGNORE INTO wishlist (user_id, product_id, target_price, alert_enabled) VALUES (?, ?, ?, 1)'
);
$stmt->execute([$user['user_id'], $productId, $targetPrice]);

echo json_encode(['success' => true, 'message' => 'Added to wishlist']);
