<?php
// api/cart/get.php
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

$user = requireAuth();
$cartCollection = Database::collection('cart');
$productsCollection = Database::collection('products');
$pricesCollection = Database::collection('prices');

$cursor = $cartCollection->find(
  ['user_id' => (int)$user['user_id']],
  ['sort' => ['added_at' => -1]]
);

$items = [];
foreach ($cursor as $cartDoc) {
  $cartItem = Database::docToArray($cartDoc);
  $product = $productsCollection->findOne(['product_id' => (int)$cartItem['product_id']]);
  $product = Database::docToArray($product);

  if (empty($product)) {
    continue;
  }

  $priceCursor = $pricesCollection->find(
    ['product_id' => (int)$cartItem['product_id'], 'price' => ['$gt' => 0]],
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
    'cart_id' => (int)($cartItem['cart_id'] ?? 0),
    'product_id' => (int)$cartItem['product_id'],
    'quantity' => (int)($cartItem['quantity'] ?? 1),
    'added_at' => Database::toIsoString($cartItem['added_at'] ?? null),
    'product_name' => $product['product_name'] ?? 'Product',
    'product_image_url' => $product['product_image_url'] ?? '',
    'current_price' => $lowestPrice,
    'platform' => $platform,
  ];
}

echo json_encode(['success' => true, 'data' => $items]);
