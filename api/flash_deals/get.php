<?php
require_once '../../config/cors.php';
require_once '../../config/database.php';

$productsCollection = Database::collection('products');
$pricesCollection = Database::collection('prices');
$flashDealsCollection = Database::collection('flash_deals');

$products = [];
foreach ($productsCollection->find(['is_active' => ['$ne' => 0]]) as $productDoc) {
    $product = Database::docToArray($productDoc);
    $productId = (int)($product['product_id'] ?? 0);
    if ($productId <= 0) {
        continue;
    }

    $priceDocs = $pricesCollection->find(['product_id' => $productId]);

    $latestPerPlatform = [];
    foreach ($priceDocs as $priceDoc) {
        $price = Database::docToArray($priceDoc);
        $platform = strtolower((string)($price['platform'] ?? ''));
        if ($platform === '') {
            continue;
        }

        $existing = $latestPerPlatform[$platform] ?? null;
        $currentTs = strtotime((string)Database::toIsoString($price['scraped_at'] ?? null));
        $existingTs = $existing ? strtotime((string)Database::toIsoString($existing['scraped_at'] ?? null)) : null;

        if ($existing === null || $currentTs >= $existingTs) {
            $latestPerPlatform[$platform] = $price;
        }
    }

    if (count($latestPerPlatform) < 2) {
        continue;
    }

    $validPrices = array_values(array_filter($latestPerPlatform, function ($p) {
        return isset($p['price']) && (float)$p['price'] > 0;
    }));

    if (count($validPrices) < 2) {
        continue;
    }

    usort($validPrices, fn($a, $b) => ((float)$a['price']) <=> ((float)$b['price']));
    $dealPrice = (float)$validPrices[0]['price'];
    $originalPrice = (float)$validPrices[count($validPrices) - 1]['price'];

    if ($dealPrice <= 0 || $originalPrice <= $dealPrice || $dealPrice < ($originalPrice * 0.10)) {
        continue;
    }

    $discountPct = (int)round((($originalPrice - $dealPrice) / $originalPrice) * 100);

    $badge = 'WAIT';
    if ($discountPct >= 45) {
        $badge = 'BUY_NOW';
    } elseif ($discountPct >= 30) {
        $badge = 'MONITOR';
    }

    $products[] = [
        'deal_id' => $productId,
        'product_id' => $productId,
        'title' => $product['product_name'] ?? 'Product',
        'product_image_url' => $product['product_image_url'] ?? null,
        'original_url' => $product['original_url'] ?? null,
        'price' => $dealPrice,
        'original_price' => $originalPrice,
        'discount_pct' => $discountPct,
        'platform' => $validPrices[0]['platform'] ?? 'Unknown',
        'deal_url' => $validPrices[0]['product_link'] ?? ($product['original_url'] ?? '#'),
        'badge' => $badge,
        'emoji' => '',
        'last_scraped' => Database::toIsoString($product['last_scraped_at'] ?? null),
    ];
}

usort($products, function ($a, $b) {
    if ($a['discount_pct'] === $b['discount_pct']) {
        return strcmp((string)($b['last_scraped'] ?? ''), (string)($a['last_scraped'] ?? ''));
    }
    return $b['discount_pct'] <=> $a['discount_pct'];
});

$products = array_slice($products, 0, 12);

if (empty($products)) {
    $fallbackDeals = [];
    $cursor = $flashDealsCollection->find(
        ['is_active' => ['$ne' => 0]],
        ['sort' => ['deal_id' => 1], 'limit' => 12]
    );

    foreach ($cursor as $dealDoc) {
        $deal = Database::docToArray($dealDoc);
        $fallbackDeals[] = [
            'deal_id' => (int)($deal['deal_id'] ?? 0),
            'product_id' => 0,
            'title' => $deal['title'] ?? 'Deal',
            'product_image_url' => null,
            'original_url' => null,
            'price' => isset($deal['price']) ? (float)$deal['price'] : 0,
            'original_price' => isset($deal['original_price']) ? (float)$deal['original_price'] : 0,
            'discount_pct' => (int)($deal['discount_pct'] ?? 0),
            'platform' => $deal['platform'] ?? 'Unknown',
            'deal_url' => $deal['deal_url'] ?? '#',
            'badge' => $deal['badge'] ?? 'NONE',
            'emoji' => '',
        ];
    }

    echo json_encode(['success' => true, 'data' => $fallbackDeals]);
    exit;
}

echo json_encode(['success' => true, 'data' => $products]);
