<?php
// api/cart/add.php
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }

$user      = requireAuth();
$input     = json_decode(file_get_contents('php://input'), true);
$productId = (int)($input['product_id'] ?? 0);
$quantity  = max(1, (int)($input['quantity'] ?? 1));

if (!$productId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'product_id is required']);
    exit;
}

$db = Database::getConnection();
$db->prepare(
    'INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
)->execute([$user['user_id'], $productId, $quantity]);

echo json_encode(['success' => true, 'message' => 'Added to cart']);
