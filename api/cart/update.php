<?php
// api/cart/update.php  (change quantity or remove if qty = 0)
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }

$user      = requireAuth();
$input     = json_decode(file_get_contents('php://input'), true);
$productId = (int)($input['product_id'] ?? 0);
$quantity  = (int)($input['quantity']   ?? 1);

if (!$productId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'product_id is required']);
    exit;
}

$db = Database::getConnection();

if ($quantity <= 0) {
    $db->prepare('DELETE FROM cart WHERE user_id = ? AND product_id = ?')
       ->execute([$user['user_id'], $productId]);
    echo json_encode(['success' => true, 'message' => 'Item removed from cart']);
} else {
    $db->prepare('UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?')
       ->execute([$quantity, $user['user_id'], $productId]);
    echo json_encode(['success' => true, 'message' => 'Cart updated']);
}
