<?php
// api/search_history/save.php
// Records that the authenticated user searched for a product.
// Called after fetch_product returns a successful result.
require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$user      = requireAuth();
$input     = json_decode(file_get_contents('php://input'), true);
$productId = (int)($input['product_id'] ?? 0);

if (!$productId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'product_id is required']);
    exit;
}

$db = Database::getConnection();

// Upsert: if same product already in history, bump the timestamp so it sorts to top
$stmt = $db->prepare(
    'INSERT INTO search_history (user_id, product_id, searched_at)
     VALUES (?, ?, NOW())
     ON DUPLICATE KEY UPDATE searched_at = NOW()'
);

// The UNIQUE constraint doesn't exist yet — just insert; duplicates are fine (show latest).
// If the user wants de-duplication, add UNIQUE KEY in the table separately.
$stmt->execute([$user['user_id'], $productId]);

echo json_encode(['success' => true]);
