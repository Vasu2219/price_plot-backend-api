<?php
// api/cart/add.php
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }

$user      = requireAuth();
$input     = json_decode(file_get_contents('php://input'), true);
$productId = (int)($input['product_id'] ?? 0);
$quantity  = max(1, (int)($input['quantity'] ?? 1));

if (!$productId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'product_id is required']);
    exit;
}

$products = Database::collection('products');
$cart = Database::collection('cart');

$product = $products->findOne(['product_id' => $productId]);
if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Product not found']);
    exit;
}

$existing = $cart->findOne([
    'user_id' => (int)$user['user_id'],
    'product_id' => $productId,
]);

if ($existing) {
    $cart->updateOne(
        ['user_id' => (int)$user['user_id'], 'product_id' => $productId],
        ['$inc' => ['quantity' => $quantity]]
    );
} else {
    $cart->insertOne([
        'cart_id' => Database::nextId('cart'),
        'user_id' => (int)$user['user_id'],
        'product_id' => $productId,
        'quantity' => $quantity,
        'added_at' => Database::now(),
    ]);
}

echo json_encode(['success' => true, 'message' => 'Added to cart']);
