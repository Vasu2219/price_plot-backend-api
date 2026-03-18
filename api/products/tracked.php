<?php
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

$user = requireAuth();
$db = Database::getConnection();

$stmt = $db->prepare(
    'SELECT
        p.product_id,
        p.product_name,
        p.product_image_url,
        p.original_url,
        MAX(t.tracked_at) AS tracked_at,
        MAX(t.in_cart) AS in_cart,
        MAX(t.in_wishlist) AS in_wishlist,
        w.target_price,
        (SELECT MIN(pr.price) FROM prices pr WHERE pr.product_id = p.product_id) AS current_price,
        (SELECT pr2.platform FROM prices pr2 WHERE pr2.product_id = p.product_id ORDER BY pr2.price ASC, pr2.scraped_at DESC LIMIT 1) AS best_platform,
        (SELECT MIN(pr3.price) FROM prices pr3 WHERE pr3.product_id = p.product_id) AS historical_min_price,
        (
          SELECT MIN(pr4.price)
          FROM prices pr4
          WHERE pr4.product_id = p.product_id
            AND pr4.scraped_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ) AS min_price_7d,
        (
          SELECT MIN(pr5.price)
          FROM prices pr5
          WHERE pr5.product_id = p.product_id
            AND pr5.scraped_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ) AS min_price_before_7d
     FROM (
       SELECT c.product_id, c.added_at AS tracked_at, 1 AS in_cart, 0 AS in_wishlist
       FROM cart c
       WHERE c.user_id = ?
       UNION ALL
       SELECT w1.product_id, w1.added_at AS tracked_at, 0 AS in_cart, 1 AS in_wishlist
       FROM wishlist w1
       WHERE w1.user_id = ?
     ) t
     JOIN products p ON p.product_id = t.product_id
     LEFT JOIN wishlist w ON w.user_id = ? AND w.product_id = p.product_id
     GROUP BY p.product_id, p.product_name, p.product_image_url, p.original_url, w.target_price
     ORDER BY tracked_at DESC'
);

$stmt->execute([$user['user_id'], $user['user_id'], $user['user_id']]);
$rows = $stmt->fetchAll();

$products = [];
foreach ($rows as $row) {
    $currentPrice = $row['current_price'] !== null ? (float)$row['current_price'] : null;
    $historicalMinPrice = $row['historical_min_price'] !== null ? (float)$row['historical_min_price'] : null;
    $targetPrice = $row['target_price'] !== null ? (float)$row['target_price'] : null;
    $minPrice7d = $row['min_price_7d'] !== null ? (float)$row['min_price_7d'] : null;
    $minPriceBefore7d = $row['min_price_before_7d'] !== null ? (float)$row['min_price_before_7d'] : null;

    $priceChange7d = null;
    if ($minPrice7d !== null && $minPriceBefore7d !== null && $minPriceBefore7d > 0) {
        $priceChange7d = round((($minPrice7d - $minPriceBefore7d) / $minPriceBefore7d) * 100, 2);
    }

    $isAtLowestPrice = false;
    if ($currentPrice !== null && $historicalMinPrice !== null) {
        $isAtLowestPrice = $currentPrice <= ($historicalMinPrice + 0.01);
    }

    $isAtTargetPrice = false;
    if ($targetPrice !== null && $currentPrice !== null) {
        $isAtTargetPrice = $currentPrice <= $targetPrice;
    }

    $products[] = [
        'product_id' => (int)$row['product_id'],
        'product_name' => $row['product_name'],
        'product_image_url' => $row['product_image_url'],
        'original_url' => $row['original_url'],
        'tracked_at' => $row['tracked_at'],
        'in_cart' => (bool)$row['in_cart'],
        'in_wishlist' => (bool)$row['in_wishlist'],
        'best_platform' => $row['best_platform'],
        'current_price' => $currentPrice,
        'historical_min_price' => $historicalMinPrice,
        'target_price' => $targetPrice,
        'is_at_lowest_price' => $isAtLowestPrice,
        'is_at_target_price' => $isAtTargetPrice,
        'price_change_7d' => $priceChange7d,
    ];
}

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