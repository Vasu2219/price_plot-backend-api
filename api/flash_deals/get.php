<?php
// api/flash_deals/get.php
require_once '../../config/cors.php';
require_once '../../config/database.php';

$db = Database::getConnection();

$latestPerPlatformSql = "
    SELECT p1.product_id, p1.platform, p1.price, p1.product_link, p1.scraped_at
    FROM prices p1
    INNER JOIN (
        SELECT product_id, platform, MAX(scraped_at) AS max_scraped
        FROM prices
        GROUP BY product_id, platform
    ) p2
      ON p2.product_id = p1.product_id
     AND p2.platform = p1.platform
     AND p2.max_scraped = p1.scraped_at
";

$dynamicDealsSql = "
    SELECT
        agg.product_id,
        products.product_name AS title,
        products.product_image_url,
        products.original_url,
        agg.deal_price AS price,
        agg.original_price,
        ROUND(((agg.original_price - agg.deal_price) / NULLIF(agg.original_price, 0)) * 100) AS discount_pct,
        (
            SELECT lpp.platform
            FROM (" . $latestPerPlatformSql . ") lpp
            WHERE lpp.product_id = agg.product_id
            ORDER BY lpp.price ASC, lpp.scraped_at DESC
            LIMIT 1
        ) AS platform,
        (
            SELECT lpp.product_link
            FROM (" . $latestPerPlatformSql . ") lpp
            WHERE lpp.product_id = agg.product_id
            ORDER BY lpp.price ASC, lpp.scraped_at DESC
            LIMIT 1
        ) AS deal_url
    FROM (
        SELECT
            lpp.product_id,
            MIN(lpp.price) AS deal_price,
            MAX(lpp.price) AS original_price,
            COUNT(*) AS platform_count
        FROM (" . $latestPerPlatformSql . ") lpp
        GROUP BY lpp.product_id
        HAVING COUNT(*) >= 2
           AND MAX(lpp.price) > MIN(lpp.price)
           AND MIN(lpp.price) > 0
           AND MIN(lpp.price) >= (MAX(lpp.price) * 0.10)
    ) agg
    INNER JOIN products ON products.product_id = agg.product_id
    WHERE products.is_active = 1
    ORDER BY discount_pct DESC, products.last_scraped_at DESC
    LIMIT 12
";

$stmt = $db->prepare($dynamicDealsSql);
$stmt->execute();
$deals = $stmt->fetchAll();

if (empty($deals)) {
    $fallbackStmt = $db->prepare('SELECT * FROM flash_deals WHERE is_active = 1 ORDER BY deal_id ASC LIMIT 12');
    $fallbackStmt->execute();
    $fallbackDeals = $fallbackStmt->fetchAll();

    foreach ($fallbackDeals as &$d) {
        $d = [
            'deal_id' => (int)$d['deal_id'],
            'product_id' => 0,
            'title' => $d['title'],
            'product_image_url' => null,
            'original_url' => null,
            'price' => (float)$d['price'],
            'original_price' => (float)$d['original_price'],
            'discount_pct' => (int)$d['discount_pct'],
            'platform' => $d['platform'],
            'deal_url' => $d['deal_url'] ?? '#',
            'badge' => $d['badge'] ?? 'NONE',
            'emoji' => '',
        ];
    }

    echo json_encode(['success' => true, 'data' => $fallbackDeals]);
    exit;
}

foreach ($deals as &$d) {
    $discount = (int)($d['discount_pct'] ?? 0);
    $badge = 'WAIT';
    if ($discount >= 45) {
        $badge = 'BUY_NOW';
    } elseif ($discount >= 30) {
        $badge = 'MONITOR';
    }

    $d['deal_id'] = (int)$d['product_id'];
    $d['product_id'] = (int)$d['product_id'];
    $d['price'] = (float)$d['price'];
    $d['original_price'] = (float)$d['original_price'];
    $d['discount_pct'] = $discount;
    $d['deal_url'] = $d['deal_url'] ?: ($d['original_url'] ?: '#');
    $d['badge'] = $badge;
    $d['emoji'] = '';
}

echo json_encode(['success' => true, 'data' => $deals]);
