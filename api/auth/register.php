<?php
// api/auth/register.php
require_once '../../config/cors.php';
require_once '../../config/database.php';

function register_should_expose_error_details(): bool {
    $value = getenv('API_DEBUG_ERRORS');
    if ($value === false) {
        return false;
    }
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function register_duplicate_field_from_error(string $message): ?string {
    $message = strtolower($message);
    if (strpos($message, 'email') !== false) {
        return 'email';
    }
    if (strpos($message, 'username') !== false) {
        return 'username';
    }
    if (strpos($message, 'user_id') !== false || strpos($message, 'userid') !== false || strpos($message, 'users.$') !== false) {
        return 'user_id';
    }
    return null;
}

function register_is_duplicate_error(Throwable $error): bool {
    $message = strtolower($error->getMessage());
    return strpos($message, 'duplicate key') !== false || strpos($message, 'e11000') !== false || (int)$error->getCode() === 11000;
}

function register_is_permission_error(string $message): bool {
    return strpos($message, 'not authorized') !== false
        || strpos($message, 'unauthorized') !== false
        || strpos($message, 'auth failed') !== false
        || strpos($message, 'requires authentication') !== false
        || strpos($message, 'command insert requires authentication') !== false
        || strpos($message, 'permission') !== false;
}

function register_is_read_only_error(string $message): bool {
    return strpos($message, 'not primary') !== false
        || strpos($message, 'read only') !== false
        || strpos($message, 'readonly') !== false
        || strpos($message, 'primary stepped down') !== false;
}

function register_is_validation_error(string $message): bool {
    return strpos($message, 'document failed validation') !== false || strpos($message, 'schema') !== false || strpos($message, 'validation') !== false || strpos($message, 'wrong type') !== false;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$username = trim($input['username'] ?? '');
$email    = strtolower(trim($input['email'] ?? ''));
$password = $input['password'] ?? '';

// Validation
if (empty($username) || empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'All fields are required']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email address']);
    exit;
}
if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters']);
    exit;
}

$users = Database::collection('users');

// Email is the only unique login identifier.
$existing = $users->findOne(['email' => $email]);
if ($existing) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Email already in use']);
    exit;
}

$passwordHash = password_hash($password, PASSWORD_BCRYPT);
$authToken    = bin2hex(random_bytes(32));
$userId = null;
$registered = false;
$maxAttempts = 8;
$lastErrorMessage = '';
$exposeErrorDetails = register_should_expose_error_details();

for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
    try {
        $candidateId = Database::nextId('users');
        $existingId = $users->findOne(['user_id' => $candidateId]);
        if ($existingId) {
            $candidateId++;
        }

        $nowUtc = Database::now();
        $nowIso = gmdate('c');
        $insertVariants = [
            [
                'user_id' => (int)$candidateId,
                'username' => $username,
                'email' => $email,
                'password' => $passwordHash,
                'password_hash' => $passwordHash,
                'auth_token' => $authToken,
                'created_at' => $nowUtc,
                'last_login' => $nowUtc,
                'updated_at' => $nowUtc,
                'is_active' => 1,
            ],
            [
                'user_id' => (int)$candidateId,
                'username' => $username,
                'email' => $email,
                'password' => $passwordHash,
                'password_hash' => $passwordHash,
                'auth_token' => $authToken,
                'created_at' => $nowIso,
                'last_login' => $nowIso,
                'updated_at' => $nowIso,
                'is_active' => 1,
            ],
            [
                'user_id' => (string)$candidateId,
                'username' => $username,
                'email' => $email,
                'password' => $passwordHash,
                'password_hash' => $passwordHash,
                'auth_token' => $authToken,
                'created_at' => $nowIso,
                'last_login' => $nowIso,
                'updated_at' => $nowIso,
                'is_active' => 1,
            ],
            [
                'user_id' => (int)$candidateId,
                'username' => $username,
                'email' => $email,
                'password' => $passwordHash,
                'password_hash' => $passwordHash,
                'auth_token' => $authToken,
                'created_at' => $nowIso,
                'last_login' => $nowIso,
                'updated_at' => $nowIso,
                'is_active' => true,
            ],
        ];

        $insertedThisAttempt = false;
        foreach ($insertVariants as $variant) {
            try {
                $users->insertOne($variant);
                $userId = (int)$candidateId;
                $registered = true;
                $insertedThisAttempt = true;
                break;
            } catch (Throwable $variantError) {
                $variantMessage = strtolower($variantError->getMessage());
                $lastErrorMessage = $variantError->getMessage();

                if (register_is_duplicate_error($variantError)) {
                    $dupField = register_duplicate_field_from_error($variantError->getMessage());
                    if ($dupField === 'email') {
                        http_response_code(409);
                        echo json_encode(['success' => false, 'error' => 'Email already in use']);
                        exit;
                    }
                    if ($dupField === 'username') {
                        http_response_code(409);
                        echo json_encode(['success' => false, 'error' => 'Username already in use']);
                        exit;
                    }
                    if ($dupField === 'user_id' || $dupField === null) {
                        continue 2;
                    }
                }

                if (register_is_validation_error($variantMessage)) {
                    continue;
                }

                if (register_is_permission_error($variantMessage)) {
                    error_log('[REGISTER_AUTHZ] ' . $variantError->getMessage());
                    $payload = ['success' => false, 'error' => 'Registration failed due to database permissions.'];
                    if ($exposeErrorDetails) {
                        $payload['details'] = $variantError->getMessage();
                    }
                    http_response_code(500);
                    echo json_encode($payload);
                    exit;
                }

                if (register_is_read_only_error($variantMessage)) {
                    error_log('[REGISTER_READONLY] ' . $variantError->getMessage());
                    $payload = ['success' => false, 'error' => 'Registration failed because the database is in read-only mode.'];
                    if ($exposeErrorDetails) {
                        $payload['details'] = $variantError->getMessage();
                    }
                    http_response_code(500);
                    echo json_encode($payload);
                    exit;
                }

                error_log('[REGISTER_VARIANT] ' . $variantError->getMessage());
                continue;
            }
        }

        if ($insertedThisAttempt) {
            break;
        }
    } catch (Throwable $e) {
        $lastErrorMessage = $e->getMessage();

        if (register_is_duplicate_error($e)) {
            $dupField = register_duplicate_field_from_error($e->getMessage());
            if ($dupField === 'email') {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Email already in use']);
                exit;
            }
            if ($dupField === 'username') {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Username already in use']);
                exit;
            }
            continue;
        }

        if (register_is_permission_error(strtolower($e->getMessage()))) {
            error_log('[REGISTER_AUTHZ] ' . $e->getMessage());
            $payload = ['success' => false, 'error' => 'Registration failed due to database permissions.'];
            if ($exposeErrorDetails) {
                $payload['details'] = $e->getMessage();
            }
            http_response_code(500);
            echo json_encode($payload);
            exit;
        }

        if (register_is_read_only_error(strtolower($e->getMessage()))) {
            error_log('[REGISTER_READONLY] ' . $e->getMessage());
            $payload = ['success' => false, 'error' => 'Registration failed because the database is in read-only mode.'];
            if ($exposeErrorDetails) {
                $payload['details'] = $e->getMessage();
            }
            http_response_code(500);
            echo json_encode($payload);
            exit;
        }

        error_log('[REGISTER] ' . $e->getMessage());
        continue;
    }
}

if (!$registered || $userId === null) {
    $payload = ['success' => false, 'error' => 'Registration failed. Please retry.'];
    if ($exposeErrorDetails && $lastErrorMessage !== '') {
        $payload['details'] = $lastErrorMessage;
    }
    http_response_code(500);
    echo json_encode($payload);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => [
        'user_id'    => (int)$userId,
        'username'   => $username,
        'email'      => $email,
        'auth_token' => $authToken,
    ]
]);
