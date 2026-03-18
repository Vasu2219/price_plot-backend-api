<?php
// api/products/price_history.php  — GET /api/products/price_history.php?product_id=X
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

requireAuth();

$productId = (int)($_GET['product_id'] ?? 0);
if (!$productId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'product_id is required']);
    exit;
}

$db = Database::getConnection();

$prodStmt = $db->prepare('SELECT product_id, product_name FROM products WHERE product_id = ?');
$prodStmt->execute([$productId]);
$product = $prodStmt->fetch();

if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Product not found']);
    exit;
}

$histStmt = $db->prepare(
    'SELECT platform, price, currency, scraped_at
     FROM prices WHERE product_id = ?
     ORDER BY scraped_at DESC LIMIT 200'
);
$histStmt->execute([$productId]);
$history = $histStmt->fetchAll();

echo json_encode([
    'success' => true,
    'data' => [
        'productId'    => (int)$product['product_id'],
        'productName'  => $product['product_name'],
        'priceHistory' => $history,
    ]
]);
