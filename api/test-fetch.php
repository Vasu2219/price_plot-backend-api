<?php
// api/test-fetch.php
// Simple test endpoint to verify request format and backend connectivity

require_once '../config/cors.php';
require_once '../helpers/auth.php';

header('Content-Type: application/json; charset=utf-8');

$response = [
    'test_time' => date('Y-m-d H:i:s'),
    'test_passed' => [],
    'test_failed' => [],
];

// Test 1: Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response['test_passed'][] = 'Method is POST ✓';
} else {
    $response['test_failed'][] = 'Method is ' . $_SERVER['REQUEST_METHOD'] . ' (expected POST)';
}

// Test 2: Check Content-Type
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $response['test_passed'][] = 'Content-Type is JSON ✓';
} else {
    $response['test_failed'][] = 'Content-Type is "' . $contentType . '" (expected application/json)';
}

// Test 3: Check Authentication
try {
    $user = requireAuth();
    $response['test_passed'][] = 'Authentication successful ✓';
    $response['user_id'] = $user['user_id'] ?? null;
} catch (Exception $e) {
    $response['test_failed'][] = 'Authentication failed: ' . $e->getMessage();
}

// Test 4: Check JSON parsing
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (json_last_error() === JSON_ERROR_NONE) {
    $response['test_passed'][] = 'JSON parsing successful ✓';
    $response['received_data'] = $input;
} else {
    $response['test_failed'][] = 'JSON parsing failed: ' . json_last_error_msg();
    $response['raw_input'] = substr($rawInput, 0, 200);
}

// Test 5: Check if productUrl is present
if (isset($input['productUrl']) && !empty($input['productUrl'])) {
    $response['test_passed'][] = 'productUrl is present ✓';
    
    // Test 6: Validate URL format
    if (filter_var($input['productUrl'], FILTER_VALIDATE_URL)) {
        $response['test_passed'][] = 'Product URL is valid ✓';
        
        // Test 7: Check platform
        $host = strtolower(parse_url($input['productUrl'], PHP_URL_HOST) ?? '');
        if (strpos($host, 'amazon') !== false || strpos($host, 'flipkart') !== false || 
            strpos($host, 'snapdeal') !== false || strpos($host, 'myntra') !== false) {
            $response['test_passed'][] = 'Platform is supported ✓ (Host: ' . $host . ')';
        } else {
            $response['test_failed'][] = 'Unsupported platform (Host: ' . $host . ')';
        }
    } else {
        $response['test_failed'][] = 'Product URL is invalid format';
    }
} else {
    $response['test_failed'][] = 'productUrl is missing or empty';
}

// Summary
$response['summary'] = [
    'passed' => count($response['test_passed']),
    'failed' => count($response['test_failed']),
    'status' => count($response['test_failed']) === 0 ? 'ALL TESTS PASSED ✓' : 'SOME TESTS FAILED ✗'
];

http_response_code(count($response['test_failed']) === 0 ? 200 : 400);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
