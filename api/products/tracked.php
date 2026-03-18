<?php
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

$user = requireAuth();
$userId = (int)$user['user_id'];

$cartCollection = Database::collection('cart');
$wishlistCollection = Database::collection('wishlist');
$productsCollection = Database::collection('products');
$pricesCollection = Database::collection('prices');

$cartDocs = $cartCollection->find(['user_id' => $userId]);
$wishDocs = $wishlistCollection->find(['user_id' => $userId]);

$trackedMap = [];

foreach ($cartDocs as $doc) {
    $item = Database::docToArray($doc);
    $productId = (int)$item['product_id'];
    if (!isset($trackedMap[$productId])) {
        $trackedMap[$productId] = [
            'product_id' => $productId,
            'tracked_at' => $item['added_at'] ?? null,
            'in_cart' => true,
            'in_wishlist' => false,
            'target_price' => null,
        ];
    } else {
        $trackedMap[$productId]['in_cart'] = true;
        $trackedMap[$productId]['tracked_at'] = $trackedMap[$productId]['tracked_at'] ?: ($item['added_at'] ?? null);
    }
}

foreach ($wishDocs as $doc) {
    $item = Database::docToArray($doc);
    $productId = (int)$item['product_id'];
    if (!isset($trackedMap[$productId])) {
        $trackedMap[$productId] = [
            'product_id' => $productId,
            'tracked_at' => $item['added_at'] ?? null,
            'in_cart' => false,
            'in_wishlist' => true,
            'target_price' => isset($item['target_price']) ? (float)$item['target_price'] : null,
        ];
    } else {
        $trackedMap[$productId]['in_wishlist'] = true;
        if (isset($item['target_price'])) {
            $trackedMap[$productId]['target_price'] = (float)$item['target_price'];
        }
        $trackedMap[$productId]['tracked_at'] = $trackedMap[$productId]['tracked_at'] ?: ($item['added_at'] ?? null);
    }
}

$products = [];
$sevenDaysAgo = new DateTimeImmutable('-7 days');

foreach ($trackedMap as $tracked) {
    $productId = (int)$tracked['product_id'];
    $productDoc = $productsCollection->findOne(['product_id' => $productId]);
    $product = Database::docToArray($productDoc);

    if (empty($product)) {
        continue;
    }

    $priceDocs = $pricesCollection->find(['product_id' => $productId]);

    $currentPrice = null;
    $historicalMinPrice = null;
    $bestPlatform = null;
    $minPrice7d = null;
    $minPriceBefore7d = null;

    foreach ($priceDocs as $priceDoc) {
        $price = Database::docToArray($priceDoc);
        $priceValue = isset($price['price']) ? (float)$price['price'] : null;
        if ($priceValue === null || $priceValue <= 0) {
            continue;
        }

        if ($currentPrice === null || $priceValue < $currentPrice) {
            $currentPrice = $priceValue;
            $bestPlatform = $price['platform'] ?? null;
        }

        if ($historicalMinPrice === null || $priceValue < $historicalMinPrice) {
            $historicalMinPrice = $priceValue;
        }

        $scrapedAtIso = Database::toIsoString($price['scraped_at'] ?? null);
        if ($scrapedAtIso) {
            $scrapedAt = new DateTimeImmutable($scrapedAtIso);
            if ($scrapedAt >= $sevenDaysAgo) {
                if ($minPrice7d === null || $priceValue < $minPrice7d) {
                    $minPrice7d = $priceValue;
                }
            } else {
                if ($minPriceBefore7d === null || $priceValue < $minPriceBefore7d) {
                    $minPriceBefore7d = $priceValue;
                }
            }
        }
    }

    $priceChange7d = null;
    if ($minPrice7d !== null && $minPriceBefore7d !== null && $minPriceBefore7d > 0) {
        $priceChange7d = round((($minPrice7d - $minPriceBefore7d) / $minPriceBefore7d) * 100, 2);
    }

    $targetPrice = $tracked['target_price'];
    $isAtLowestPrice = $currentPrice !== null && $historicalMinPrice !== null ? $currentPrice <= ($historicalMinPrice + 0.01) : false;
    $isAtTargetPrice = $targetPrice !== null && $currentPrice !== null ? $currentPrice <= $targetPrice : false;

    $products[] = [
        'product_id' => $productId,
        'product_name' => $product['product_name'] ?? 'Product',
        'product_image_url' => $product['product_image_url'] ?? null,
        'original_url' => $product['original_url'] ?? '',
        'tracked_at' => Database::toIsoString($tracked['tracked_at'] ?? null),
        'in_cart' => (bool)$tracked['in_cart'],
        'in_wishlist' => (bool)$tracked['in_wishlist'],
        'best_platform' => $bestPlatform,
        'current_price' => $currentPrice,
        'historical_min_price' => $historicalMinPrice,
        'target_price' => $targetPrice,
        'is_at_lowest_price' => $isAtLowestPrice,
        'is_at_target_price' => $isAtTargetPrice,
        'price_change_7d' => $priceChange7d,
    ];
}

usort($products, function ($a, $b) {
    return strcmp((string)$b['tracked_at'], (string)$a['tracked_at']);
});

$totalProducts = count($products);
$atLowestCount = count(array_filter($products, fn($product) => $product['is_at_lowest_price']));
$atTargetCount = count(array_filter($products, fn($product) => $product['is_at_target_price']));

$dropCandidates = array_values(array_filter(
    $products,
    fn($product) => $product['price_change_7d'] !== null
));

usort($dropCandidates, fn($a, $b) => $a['price_change_7d'] <=> $b['price_change_7d']);
$biggestDrop = $dropCandidates[0] ?? null;

echo json_encode([
    'success' => true,
    'data' => [
        'summary' => [
            'total_products' => $totalProducts,
            'at_lowest_price' => $atLowestCount,
            'at_target_price' => $atTargetCount,
            'biggest_drop_7d' => $biggestDrop ? [
                'product_name' => $biggestDrop['product_name'],
                'price_change_7d' => $biggestDrop['price_change_7d'],
            ] : null,
        ],
        'products' => $products,
    ],
]);
