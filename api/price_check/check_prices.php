<?php
// api/price_check/check_prices.php
// Called by Android WorkManager once a day.
// Re-fetches prices for all wishlist + cart items tracked by this user,
// compares to yesterday's price, creates notification rows for drops.
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../config/scraper.php';
require_once '../../helpers/auth.php';
require_once '../../helpers/price_history.php';

$user = requireAuth();
$db   = Database::getConnection();

// Collect all distinct product_ids this user is tracking (wishlist + cart)
$trackStmt = $db->prepare(
    'SELECT DISTINCT product_id FROM (
        SELECT product_id FROM wishlist WHERE user_id = ? AND alert_enabled = 1
        UNION
        SELECT product_id FROM cart WHERE user_id = ?
     ) AS tracked'
);
$trackStmt->execute([$user['user_id'], $user['user_id']]);
$tracked = $trackStmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($tracked)) {
    echo json_encode(['success' => true, 'data' => ['drops' => [], 'checked' => 0]]);
    exit;
}

$drops   = [];
$checked = 0;
$inserted = 0;

foreach ($tracked as $productId) {
    // Get the product URL
    $prodStmt = $db->prepare('SELECT original_url, product_name FROM products WHERE product_id = ?');
    $prodStmt->execute([$productId]);
    $product = $prodStmt->fetch();
    if (!$product) continue;

    // Fetch yesterday's best price (min price across platforms)
    $yestStmt = $db->prepare(
        'SELECT MIN(price) AS min_price FROM prices
         WHERE product_id = ?
           AND scraped_at BETWEEN DATE_SUB(NOW(), INTERVAL 2 DAY) AND DATE_SUB(NOW(), INTERVAL 1 DAY)'
    );
    $yestStmt->execute([$productId]);
    $yesterday = $yestStmt->fetchColumn();

    // Call the scraper for today's prices
    $ch      = curl_init(SCRAPER_URL);
    $payload = json_encode(['productUrl' => $product['original_url']]);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => SCRAPER_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) continue;
    $scraped = json_decode($response, true);
    if (!($scraped['success'] ?? false) || empty($scraped['prices'])) continue;

    // Insert today's prices only when changed
    foreach ($scraped['prices'] as $p) {
        if (insertPriceIfChanged(
            $db,
            $productId,
            $p['platform']     ?? 'Unknown',
            (float)($p['price'] ?? 0),
            $p['currency']     ?? 'INR',
            $p['availability'] ?? 'Unknown',
            $p['link']         ?? ''
        )) {
            $inserted++;
        }
    }

    $checked++;

    // Find today's best price
    $todayBest = min(array_column($scraped['prices'], 'price'));

    // Compare with yesterday
    if ($yesterday !== false && $yesterday !== null && (float)$yesterday > 0) {
        $oldPrice = (float)$yesterday;
        $newPrice = (float)$todayBest;

        if ($newPrice < $oldPrice) {
            $savings = round($oldPrice - $newPrice, 2);
            $message = "Price dropped for {$product['product_name']}! "
                     . "₹" . number_format($oldPrice, 2) . " → ₹" . number_format($newPrice, 2)
                     . " (Save ₹" . number_format($savings, 2) . ")";

            // Insert notification
            $notifStmt = $db->prepare(
                'INSERT INTO notifications (user_id, product_id, message, old_price, new_price)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $notifStmt->execute([$user['user_id'], $productId, $message, $oldPrice, $newPrice]);

            $drops[] = [
                'product_id'   => (int)$productId,
                'product_name' => $product['product_name'],
                'old_price'    => $oldPrice,
                'new_price'    => $newPrice,
                'savings'      => $savings,
                'message'      => $message,
            ];
        }
    }
}

echo json_encode([
    'success' => true,
    'data' => [
        'drops'   => $drops,
        'checked' => $checked,
        'inserted' => $inserted,
    ]
]);
