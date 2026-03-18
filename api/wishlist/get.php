<?php
// api/wishlist/get.php
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

$user = requireAuth();
$wishlistCollection = Database::collection('wishlist');
$productsCollection = Database::collection('products');
$pricesCollection = Database::collection('prices');

$cursor = $wishlistCollection->find(
  ['user_id' => (int)$user['user_id']],
  ['sort' => ['added_at' => -1]]
);

$items = [];
foreach ($cursor as $wishlistDoc) {
  $wish = Database::docToArray($wishlistDoc);
  $product = $productsCollection->findOne(['product_id' => (int)$wish['product_id']]);
  $product = Database::docToArray($product);
  if (empty($product)) {
    continue;
  }

  $priceCursor = $pricesCollection->find(
    ['product_id' => (int)$wish['product_id'], 'price' => ['$gt' => 0]],
    ['sort' => ['price' => 1, 'scraped_at' => -1]]
  );

  $lowestPrice = null;
  $bestPlatform = null;
  foreach ($priceCursor as $priceDoc) {
    $priceData = Database::docToArray($priceDoc);
    if ($lowestPrice === null || (float)$priceData['price'] < $lowestPrice) {
      $lowestPrice = (float)$priceData['price'];
      $bestPlatform = $priceData['platform'] ?? null;
    }
  }

  $url = strtolower((string)($product['original_url'] ?? ''));
  if (strpos($url, 'amazon') !== false || strpos($url, 'amzn') !== false) {
    $platform = 'Amazon';
  } elseif (strpos($url, 'flipkart') !== false) {
    $platform = 'Flipkart';
  } elseif (strpos($url, 'snapdeal') !== false) {
    $platform = 'Snapdeal';
  } elseif (strpos($url, 'myntra') !== false) {
    $platform = 'Myntra';
  } elseif (strpos($url, 'croma') !== false) {
    $platform = 'Croma';
  } else {
    $platform = $bestPlatform;
  }

  $items[] = [
    'wishlist_id' => (int)($wish['wishlist_id'] ?? 0),
    'product_id' => (int)$wish['product_id'],
    'target_price' => isset($wish['target_price']) ? (float)$wish['target_price'] : null,
    'alert_enabled' => (bool)($wish['alert_enabled'] ?? 1),
    'added_at' => Database::toIsoString($wish['added_at'] ?? null),
    'product_name' => $product['product_name'] ?? 'Product',
    'product_image_url' => $product['product_image_url'] ?? '',
    'original_url' => $product['original_url'] ?? '',
    'current_price' => $lowestPrice,
    'platform' => $platform,
  ];
}

echo json_encode(['success' => true, 'data' => $items]);
