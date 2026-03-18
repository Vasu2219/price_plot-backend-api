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
$userId = (int)$user['user_id'];

$wishlistCollection = Database::collection('wishlist');
$cartCollection = Database::collection('cart');
$productsCollection = Database::collection('products');
$pricesCollection = Database::collection('prices');
$notificationsCollection = Database::collection('notifications');

// Collect all distinct product_ids this user is tracking (wishlist + cart)
$trackedMap = [];

$wishlistDocs = $wishlistCollection->find([
    'user_id' => $userId,
    'alert_enabled' => 1,
]);
foreach ($wishlistDocs as $wishDoc) {
    $wish = Database::docToArray($wishDoc);
    $trackedMap[(int)$wish['product_id']] = true;
}

$cartDocs = $cartCollection->find(['user_id' => $userId]);
foreach ($cartDocs as $cartDoc) {
    $cart = Database::docToArray($cartDoc);
    $trackedMap[(int)$cart['product_id']] = true;
}

$tracked = array_keys($trackedMap);

if (empty($tracked)) {
    echo json_encode(['success' => true, 'data' => ['drops' => [], 'checked' => 0]]);
    exit;
}

$drops   = [];
$checked = 0;
$inserted = 0;

foreach ($tracked as $productId) {
    // Get the product URL
    $product = $productsCollection->findOne(['product_id' => (int)$productId]);
    $product = Database::docToArray($product);
    if (empty($product)) continue;

    // Fetch yesterday's best price (min price across platforms)
    $now = new DateTimeImmutable();
    $from = new MongoDB\BSON\UTCDateTime((int)$now->modify('-2 day')->format('Uv'));
    $to = new MongoDB\BSON\UTCDateTime((int)$now->modify('-1 day')->format('Uv'));

    $yesterday = null;
    $yesterdayCursor = $pricesCollection->find([
        'product_id' => (int)$productId,
        'scraped_at' => ['$gte' => $from, '$lte' => $to],
        'price' => ['$gt' => 0],
    ]);
    foreach ($yesterdayCursor as $priceDoc) {
        $priceData = Database::docToArray($priceDoc);
        $priceVal = isset($priceData['price']) ? (float)$priceData['price'] : null;
        if ($priceVal === null) {
            continue;
        }
        if ($yesterday === null || $priceVal < $yesterday) {
            $yesterday = $priceVal;
        }
    }

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
    if ($yesterday !== null && (float)$yesterday > 0) {
        $oldPrice = (float)$yesterday;
        $newPrice = (float)$todayBest;

        if ($newPrice < $oldPrice) {
            $savings = round($oldPrice - $newPrice, 2);
            $message = "Price dropped for {$product['product_name']}! "
                     . "₹" . number_format($oldPrice, 2) . " → ₹" . number_format($newPrice, 2)
                     . " (Save ₹" . number_format($savings, 2) . ")";

            // Insert notification
            $notificationsCollection->insertOne([
                'notification_id' => Database::nextId('notifications'),
                'user_id' => $userId,
                'product_id' => (int)$productId,
                'message' => $message,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'is_read' => 0,
                'created_at' => Database::now(),
            ]);

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
