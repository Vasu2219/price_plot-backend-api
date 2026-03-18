<?php
// api/products/debug-best-price.php
// Debug endpoint to check bestPrice calculation for a given product ID
require_once '../../config/cors.php';
require_once '../../config/database.php';

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;

if (!$productId) {
    echo json_encode(['success' => false, 'error' => 'product_id required']);
    exit;
}

$productsCollection = Database::collection('products');
$pricesCollection = Database::collection('prices');

// Get product info
$product = $productsCollection->findOne(['product_id' => $productId]);
$product = Database::docToArray($product);

if (empty($product)) {
    echo json_encode(['success' => false, 'error' => 'Product not found']);
    exit;
}

// Get all prices for this product
$allPrices = [];
$cursor = $pricesCollection->find(
    ['product_id' => $productId],
    ['sort' => ['price' => -1]]
);
foreach ($cursor as $priceDoc) {
    $price = Database::docToArray($priceDoc);
    $allPrices[] = [
        'platform' => $price['platform'] ?? 'Unknown',
        'price' => isset($price['price']) ? (float)$price['price'] : null,
        'currency' => $price['currency'] ?? 'INR',
        'availability' => $price['availability'] ?? null,
        'product_link' => $price['product_link'] ?? null,
    ];
}

// Apply the same filtering logic as fetch_product.php
$validPrices = array_filter($allPrices, fn($p) => $p['price'] && $p['price'] > 0);

$debugInfo = [
    'product_id' => $productId,
    'product_name' => $product['product_name'],
    'total_prices_in_db' => count($allPrices),
    'all_prices' => $allPrices,
    'valid_prices_count' => count($validPrices),
    'valid_prices' => array_values($validPrices),
    'calculation' => []
];

if (!empty($validPrices)) {
    usort($validPrices, fn($a, $b) => $a['price'] <=> $b['price']);
    $minPrice = $validPrices[0]['price'];
    $maxPrice = $validPrices[count($validPrices) - 1]['price'];
    $bestPlatform = $validPrices[0]['platform'];
    
    $debugInfo['calculation'] = [
        'min_price' => $minPrice,
        'max_price' => $maxPrice,
        'best_platform' => $bestPlatform,
        'best_price_object' => [
            'platform' => $bestPlatform,
            'price' => $minPrice,
            'savings' => round($maxPrice - $minPrice, 2)
        ]
    ];
} else {
    $debugInfo['calculation'] = [
        'status' => 'NO VALID PRICES FOUND',
        'would_fallback_to' => $allPrices[0] ?? null
    ];
}

echo json_encode(['success' => true, 'data' => $debugInfo]);
?>
