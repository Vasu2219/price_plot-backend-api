<?php
// api/products/update_image.php
// Updates product_image_url for a product when flash.co finds an image
// that the primary PHP scraper missed.
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

requireAuth(); // must be authenticated
$input     = json_decode(file_get_contents('php://input'), true);
$productId = (int)($input['product_id'] ?? 0);
$imageUrl  = trim($input['image_url'] ?? '');

if (!$productId || empty($imageUrl)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'product_id and image_url are required']);
    exit;
}

// Validate image URL format
if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid image URL']);
    exit;
}

$products = Database::collection('products');

// Only update if the current image is NULL — don't overwrite a valid PHP-scraped image
$products->updateOne(
    [
        'product_id' => $productId,
        '$or' => [
            ['product_image_url' => null],
            ['product_image_url' => ''],
        ],
    ],
    ['$set' => ['product_image_url' => $imageUrl]]
);

echo json_encode(['success' => true]);
