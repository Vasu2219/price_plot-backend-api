<?php
// api/cart/get.php
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

$user = requireAuth();
$db   = Database::getConnection();

$stmt = $db->prepare(
    'SELECT c.cart_id, c.product_id, c.quantity, c.added_at,
            p.product_name, p.product_image_url,
            (SELECT MIN(price) FROM prices WHERE product_id = c.product_id) AS current_price,
            CASE
              WHEN LOWER(p.original_url) LIKE \'%amazon%\' OR LOWER(p.original_url) LIKE \'%amzn%\' THEN \'Amazon\'
              WHEN LOWER(p.original_url) LIKE \'%flipkart%\'  THEN \'Flipkart\'
              WHEN LOWER(p.original_url) LIKE \'%snapdeal%\'  THEN \'Snapdeal\'
              WHEN LOWER(p.original_url) LIKE \'%myntra%\'    THEN \'Myntra\'
              WHEN LOWER(p.original_url) LIKE \'%croma%\'     THEN \'Croma\'
              ELSE (SELECT platform FROM prices WHERE product_id = c.product_id ORDER BY price ASC LIMIT 1)
            END AS platform
     FROM cart c
     JOIN products p ON p.product_id = c.product_id
     WHERE c.user_id = ?
     ORDER BY c.added_at DESC'
);
$stmt->execute([$user['user_id']]);
$items = $stmt->fetchAll();

echo json_encode(['success' => true, 'data' => $items]);
