<?php
// api/wishlist/get.php
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

$user = requireAuth();
$db   = Database::getConnection();

$stmt = $db->prepare(
    'SELECT w.wishlist_id, w.product_id, w.target_price, w.alert_enabled, w.added_at,
            p.product_name, p.product_image_url, p.original_url,
            (SELECT MIN(price) FROM prices WHERE product_id = w.product_id) AS current_price,
            CASE
              WHEN LOWER(p.original_url) LIKE \'%amazon%\' OR LOWER(p.original_url) LIKE \'%amzn%\' THEN \'Amazon\'
              WHEN LOWER(p.original_url) LIKE \'%flipkart%\'  THEN \'Flipkart\'
              WHEN LOWER(p.original_url) LIKE \'%snapdeal%\'  THEN \'Snapdeal\'
              WHEN LOWER(p.original_url) LIKE \'%myntra%\'    THEN \'Myntra\'
              WHEN LOWER(p.original_url) LIKE \'%croma%\'     THEN \'Croma\'
              ELSE (SELECT platform FROM prices WHERE product_id = w.product_id ORDER BY price ASC LIMIT 1)
            END AS platform
     FROM wishlist w
     JOIN products p ON p.product_id = w.product_id
     WHERE w.user_id = ?
     ORDER BY w.added_at DESC'
);
$stmt->execute([$user['user_id']]);
$items = $stmt->fetchAll();

echo json_encode(['success' => true, 'data' => $items]);
