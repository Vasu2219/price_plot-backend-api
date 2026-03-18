<?php
// api/test-ollama.php
// Diagnostic endpoint to test Ollama connectivity and chat functionality

require_once '../config/cors.php';

header('Content-Type: application/json');

$OLLAMA_BASE_URL = getenv('OLLAMA_BASE_URL') ?: 'http://localhost:11434';
$OLLAMA_MODEL = getenv('OLLAMA_MODEL') ?: 'qwen2.5:3b';

$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'ollama_base_url' => $OLLAMA_BASE_URL,
    'ollama_model' => $OLLAMA_MODEL,
];

// Test 1: Check basic connectivity
echo "TEST 1: Basic Connectivity\n";
echo "==========================\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $OLLAMA_BASE_URL . '/api/tags',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_CONNECTTIMEOUT => 5,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo "❌ FAILED: Cannot connect to Ollama\n";
    echo "   Error: " . $curlError . "\n";
    echo "   URL: " . $OLLAMA_BASE_URL . "\n";
    echo "\n   FIX: Make sure Ollama is running: ollama serve\n";
    http_response_code(503);
    exit;
}

if ($httpCode !== 200) {
    echo "❌ FAILED: Ollama HTTP " . $httpCode . "\n";
    echo "   Response: " . $response . "\n";
    http_response_code(503);
    exit;
}

echo "✅ PASSED: Ollama is running\n";
$tagsData = json_decode($response, true);
echo "Available Models:\n";
if (isset($tagsData['models']) && is_array($tagsData['models'])) {
    $modelNames = [];
    foreach ($tagsData['models'] as $model) {
        echo "  - " . $model['name'] . "\n";
        $modelNames[] = $model['name'];
    }
    
    // Check if the configured model exists
    if (!in_array($OLLAMA_MODEL, $modelNames)) {
        echo "\n⚠️  WARNING: Model '$OLLAMA_MODEL' is not installed\n";
        echo "   Available models: " . implode(', ', $modelNames) . "\n";
        echo "   To use a different model, set OLLAMA_MODEL env var\n";
    }
}

// Test 2: Test chat endpoint
echo "\n\nTEST 2: Chat Functionality\n";
echo "============================\n";

$testMessage = [
    'model' => $OLLAMA_MODEL,
    'messages' => [
        [
            'role' => 'system',
            'content' => 'You are a helpful AI assistant for PricePlot price comparison platform.'
        ],
        [
            'role' => 'user',
            'content' => 'Hello'
        ]
    ],
    'stream' => false,
    'temperature' => 0.7,
];

echo "Sending test message to: " . $OLLAMA_BASE_URL . "/api/chat\n";
echo "Model: " . $OLLAMA_MODEL . "\n";
echo "Message: {\"role\": \"user\", \"content\": \"Hello\"}\n\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $OLLAMA_BASE_URL . '/api/chat',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($testMessage),
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo "❌ FAILED: " . $curlError . "\n";
    http_response_code(503);
    exit;
}

if ($httpCode !== 200) {
    echo "❌ FAILED: HTTP " . $httpCode . "\n";
    echo "Response: " . $response . "\n";
    http_response_code(503);
    exit;
}

$chatResponse = json_decode($response, true);
if (!isset($chatResponse['message']['content'])) {
    echo "❌ FAILED: Invalid response structure\n";
    echo "Response: " . json_encode($chatResponse, JSON_PRETTY_PRINT) . "\n";
    http_response_code(503);
    exit;
}

echo "✅ PASSED: Chat endpoint working!\n";
echo "Response: " . substr($chatResponse['message']['content'], 0, 100) . "...\n";

echo "\n\n✅ ALL TESTS PASSED - READY TO USE!\n";
