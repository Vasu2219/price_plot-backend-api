<?php
// api/products/update_prices.php
// Called by the Android app after the background scraper merges additional
// platform prices.  Inserts each price row into the prices table so the
// history and search-history GET endpoints always return up-to-date data.

require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';
require_once '../../helpers/price_history.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$user  = requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

$productId = (int)($input['product_id'] ?? 0);
$prices    = $input['prices'] ?? [];

if (!$productId || !is_array($prices) || empty($prices)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'product_id and prices[] are required']);
    exit;
}

$db = Database::getConnection();

// Verify product exists and belongs to a valid record
$check = $db->prepare('SELECT product_id FROM products WHERE product_id = ?');
$check->execute([$productId]);
if (!$check->fetch()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Product not found']);
    exit;
}

$inserted = 0;
foreach ($prices as $p) {
    $platform     = trim($p['platform']     ?? '');
    $price        = isset($p['price'])  ? (float)$p['price']  : null;
    $currency     = trim($p['currency']     ?? 'INR');
    $availability = trim($p['availability'] ?? 'In Stock');
    $link         = trim($p['link']         ?? '');

    if (empty($platform) || $price === null || $price < 1) continue;

    if (insertPriceIfChanged($db, $productId, $platform, $price, $currency, $availability, $link)) {
        $inserted++;
    }
}

// Also update the product's last_scraped_at timestamp
$db->prepare('UPDATE products SET last_scraped_at = NOW() WHERE product_id = ?')
   ->execute([$productId]);

// Invalidate the cache so the next fetch_product.php call returns fresh data
$db->prepare('DELETE FROM price_cache WHERE scraped_data LIKE ?')
   ->execute(['%"productId":' . $productId . '%']);

echo json_encode(['success' => true, 'inserted' => $inserted]);
