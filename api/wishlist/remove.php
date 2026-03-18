<?php
// api/wishlist/remove.php
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit; }

$user  = requireAuth();
$input = json_decode(file_get_contents('php://input'), true);
$productId = (int)($input['product_id'] ?? 0);

if (!$productId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'product_id is required']);
    exit;
}

$db = Database::getConnection();
$db->prepare('DELETE FROM wishlist WHERE user_id = ? AND product_id = ?')
   ->execute([$user['user_id'], $productId]);

echo json_encode(['success' => true, 'message' => 'Removed from wishlist']);
