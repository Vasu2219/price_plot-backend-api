<?php
// helpers/auth.php — shared token validation helper
require_once __DIR__ . '/../config/database.php';

/**
 * Validate X-Auth-Token header and return the user row, or die with 401.
 */
function requireAuth(): array {
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (empty($token)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    $db   = Database::getConnection();
    $stmt = $db->prepare('SELECT * FROM users WHERE auth_token = ? AND is_active = 1');
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired token']);
        exit;
    }
    return $user;
}

/**
 * Try to authenticate, throw exception on failure (doesn't exit)
 * Used for endpoints that support guest access
 */
function tryAuth(): ?array {
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (empty($token)) {
        throw new Exception('No authentication token provided');
    }

    $db   = Database::getConnection();
    $stmt = $db->prepare('SELECT * FROM users WHERE auth_token = ? AND is_active = 1');
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception('Invalid or expired token');
    }
    return $user;
}
