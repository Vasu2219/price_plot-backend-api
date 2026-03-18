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

$products = Database::collection('products');
$wishlist = Database::collection('wishlist');

// Verify product exists
$product = $products->findOne(['product_id' => $productId]);
if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Product not found']);
    exit;
}

$existing = $wishlist->findOne([
    'user_id' => (int)$user['user_id'],
    'product_id' => $productId,
]);

if (!$existing) {
    $wishlist->insertOne([
        'wishlist_id' => Database::nextId('wishlist'),
        'user_id' => (int)$user['user_id'],
        'product_id' => $productId,
        'target_price' => $targetPrice,
        'alert_enabled' => 1,
        'added_at' => Database::now(),
    ]);
}

echo json_encode(['success' => true, 'message' => 'Added to wishlist']);
