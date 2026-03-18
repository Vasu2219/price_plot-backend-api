<?php
// api/search_history/get.php
// Returns the authenticated user's recent searches with full price list.
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

$user  = requireAuth();
$limit = min((int)($_GET['limit'] ?? 50), 100);

$searchHistoryCollection = Database::collection('search_history');
$productsCollection = Database::collection('products');
$pricesCollection = Database::collection('prices');

$rows = [];
$historyCursor = $searchHistoryCollection->find(
    ['user_id' => (int)$user['user_id']],
    ['sort' => ['searched_at' => -1], 'limit' => $limit]
);

foreach ($historyCursor as $historyDoc) {
    $history = Database::docToArray($historyDoc);
    $product = $productsCollection->findOne(['product_id' => (int)$history['product_id']]);
    $product = Database::docToArray($product);

    if (empty($product)) {
        continue;
    }

    $rows[] = [
        'history_id' => (int)($history['history_id'] ?? 0),
        'searched_at' => Database::toIsoString($history['searched_at'] ?? null),
        'product_id' => (int)$product['product_id'],
        'product_name' => $product['product_name'] ?? 'Product',
        'product_image_url' => $product['product_image_url'] ?? '',
        'original_url' => $product['original_url'] ?? '',
    ];
}

$results = [];
foreach ($rows as $row) {
    $platformMap = [];
    $priceCursor = $pricesCollection->find(['product_id' => (int)$row['product_id']]);
    foreach ($priceCursor as $priceDoc) {
        $priceData = Database::docToArray($priceDoc);
        $platform = $priceData['platform'] ?? 'Unknown';
        $key = strtolower($platform);
        $value = isset($priceData['price']) ? (float)$priceData['price'] : null;

        if (!isset($platformMap[$key])) {
            $platformMap[$key] = [
                'platform' => $platform,
                'price' => $value,
                'currency' => $priceData['currency'] ?? 'INR',
                'availability' => $priceData['availability'] ?? null,
                'product_link' => $priceData['product_link'] ?? null,
            ];
        } else {
            $existing = $platformMap[$key]['price'];
            if ($value !== null && ($existing === null || $value < $existing)) {
                $platformMap[$key] = [
                    'platform' => $platform,
                    'price' => $value,
                    'currency' => $priceData['currency'] ?? 'INR',
                    'availability' => $priceData['availability'] ?? null,
                    'product_link' => $priceData['product_link'] ?? null,
                ];
            }
        }
    }

    $prices = array_values($platformMap);
    usort($prices, function ($a, $b) {
        $ap = $a['price'];
        $bp = $b['price'];
        if ($ap === null) {
            return 1;
        }
        if ($bp === null) {
            return -1;
        }
        return $ap <=> $bp;
    });

    $searchQuery = $row['product_name'];
    $searchLinks = [
        ['platform' => 'Amazon', 'url' => 'https://www.amazon.in/s?k=' . rawurlencode($searchQuery)],
        ['platform' => 'Flipkart', 'url' => 'https://www.flipkart.com/search?q=' . rawurlencode($searchQuery)],
        ['platform' => 'Snapdeal', 'url' => 'https://www.snapdeal.com/search?keyword=' . rawurlencode($searchQuery)],
        ['platform' => 'Myntra', 'url' => 'https://www.myntra.com/' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($searchQuery)), '-')],
        ['platform' => 'Croma', 'url' => 'https://www.croma.com/searchB?q=' . rawurlencode($searchQuery)],
        ['platform' => 'Reliance Digital', 'url' => 'https://www.reliancedigital.in/search?q=' . rawurlencode($searchQuery)],
    ];

    $results[] = [
        'history_id'        => (int)$row['history_id'],
        'searched_at'       => $row['searched_at'],
        'product_id'        => (int)$row['product_id'],
        'product_name'      => $row['product_name'],
        'product_image_url' => $row['product_image_url'],
        'original_url'      => $row['original_url'],
        'prices'            => $prices,
        'search_links'      => $searchLinks,
        'best_price'        => !empty($prices) && isset($prices[0]['price']) && $prices[0]['price'] > 0 ? (float)$prices[0]['price'] : null,
        'best_platform'     => !empty($prices) && isset($prices[0]['price']) && $prices[0]['price'] > 0 ? $prices[0]['platform'] : null,
    ];
}

echo json_encode(['success' => true, 'data' => $results]);
