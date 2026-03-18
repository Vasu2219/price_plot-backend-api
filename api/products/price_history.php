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

$products = Database::collection('products');
$pricesCollection = Database::collection('prices');

$product = $products->findOne(['product_id' => $productId]);
$product = Database::docToArray($product);

if (empty($product)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Product not found']);
    exit;
}

$history = [];
$cursor = $pricesCollection->find(
    ['product_id' => $productId],
    ['sort' => ['scraped_at' => -1], 'limit' => 200]
);

foreach ($cursor as $priceDoc) {
    $priceData = Database::docToArray($priceDoc);
    $history[] = [
        'platform' => $priceData['platform'] ?? 'Unknown',
        'price' => isset($priceData['price']) ? (float)$priceData['price'] : null,
        'currency' => $priceData['currency'] ?? 'INR',
        'scraped_at' => Database::toIsoString($priceData['scraped_at'] ?? null),
    ];
}

echo json_encode([
    'success' => true,
    'data' => [
        'productId'    => (int)$productId,
        'productName'  => $product['product_name'],
        'priceHistory' => $history,
    ]
]);
