<?php
// api/search_history/save.php
// Records that the authenticated user searched for a product.
// Called after fetch_product returns a successful result.
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$user      = requireAuth();
$input     = json_decode(file_get_contents('php://input'), true);
$productId = (int)($input['product_id'] ?? 0);

if (!$productId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'product_id is required']);
    exit;
}

$products = Database::collection('products');
$searchHistory = Database::collection('search_history');

$product = $products->findOne(['product_id' => $productId]);
if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Product not found']);
    exit;
}

$searchHistory->insertOne([
    'history_id' => Database::nextId('search_history'),
    'user_id' => (int)$user['user_id'],
    'product_id' => $productId,
    'searched_at' => Database::now(),
]);

echo json_encode(['success' => true]);
