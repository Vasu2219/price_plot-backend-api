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

$db = Database::getConnection();

// Get product info
$stmt = $db->prepare('SELECT product_id, product_name, product_image_url FROM products WHERE product_id = ?');
$stmt->execute([$productId]);
$product = $stmt->fetch();

if (!$product) {
    echo json_encode(['success' => false, 'error' => 'Product not found']);
    exit;
}

// Get all prices for this product
$stmt = $db->prepare('SELECT platform, price, currency, availability, product_link FROM prices WHERE product_id = ? ORDER BY price DESC');
$stmt->execute([$productId]);
$allPrices = $stmt->fetchAll();

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
