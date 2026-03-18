<?php
// api/chat.php
// Chat endpoint using Ollama for AI responses
// Uses existing Ollama models (llama2, mistral, neural-chat, etc.)

require_once '../config/cors.php';
require_once '../config/database.php';
require_once '../helpers/auth.php';

header('Content-Type: application/json');
set_time_limit(120);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Authenticate user (optional for public/guest access)
try {
    $user = tryAuth();
} catch (Exception $e) {
    // Allow guest access for chatbot
    $user = ['user_id' => 0];
}

$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$conversationHistory = $input['conversationHistory'] ?? [];
$productData = $input['product'] ?? null;

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Message is required']);
    exit;
}

// Ollama configuration
$OLLAMA_BASE_URL = getenv('OLLAMA_BASE_URL') ?: 'http://localhost:11434';
// Check for OLLAMA_MODEL env var and validate against installed models
$configuredModel = getenv('OLLAMA_MODEL') ?: null;
$OLLAMA_MODEL = null;
$availableModels = [];

// Detect available models
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $OLLAMA_BASE_URL . '/api/tags',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
]);
$tagsResponse = curl_exec($ch);
curl_close($ch);

if ($tagsResponse) {
    $tagsData = json_decode($tagsResponse, true);
    if (!empty($tagsData['models']) && is_array($tagsData['models'])) {
        foreach ($tagsData['models'] as $modelInfo) {
            if (!empty($modelInfo['name'])) {
                $availableModels[] = $modelInfo['name'];
            }
        }
    }
}

if ($configuredModel && in_array($configuredModel, $availableModels, true)) {
    $OLLAMA_MODEL = $configuredModel;
} elseif (!empty($availableModels)) {
    // Prefer lightweight/stable chat models when available
    $preferredModels = ['qwen2.5:3b', 'phi', 'neural-chat', 'mistral', 'llama2'];
    foreach ($preferredModels as $preferredModel) {
        if (in_array($preferredModel, $availableModels, true)) {
            $OLLAMA_MODEL = $preferredModel;
            break;
        }
    }

    // If none matched preferred list, use first available
    if (!$OLLAMA_MODEL) {
        $OLLAMA_MODEL = $availableModels[0];
    }
}

// Final fallback if tags endpoint is unavailable
if (!$OLLAMA_MODEL) {
    $OLLAMA_MODEL = $configuredModel ?: 'qwen2.5:3b';
}

// Build conversation context with product information
$systemPrompt = "You are PricePlot AI Assistant, developed by PricePlot - a smart price comparison platform that helps users find the best deals across multiple e-commerce websites. You are designed to help users compare prices, analyze deals, and make informed shopping decisions.";

if ($productData && !empty($productData)) {
    // Generate product comparison analysis
    $productTitle = $productData['title'] ?? 'Product';
    $prices = $productData['prices'] ?? [];
    
    // Calculate price statistics
    $priceList = array_map(fn($p) => $p['price'], $prices);
    $minPrice = min($priceList);
    $maxPrice = max($priceList);
    $avgPrice = array_sum($priceList) / count($priceList);
    $savings = $maxPrice - $minPrice;
    $savingsPercent = round(($savings / $maxPrice) * 100, 1);
    
    // Find best and worst deals
    $bestDeal = null;
    $worstDeal = null;
    foreach ($prices as $p) {
        if ($p['price'] == $minPrice) $bestDeal = $p;
        if ($p['price'] == $maxPrice) $worstDeal = $p;
    }
    
    // Build product context
    $priceComparison = "Product: $productTitle\n";
    $priceComparison .= "Best Price: ₹" . round($minPrice) . " at " . ($bestDeal['platform'] ?? 'Unknown Platform') . "\n";
    $priceComparison .= "Average Price: ₹" . round($avgPrice) . "\n";
    $priceComparison .= "Worst Price: ₹" . round($maxPrice) . " at " . ($worstDeal['platform'] ?? 'Unknown Platform') . "\n";
    $priceComparison .= "Potential Savings: ₹" . round($savings) . " ($savingsPercent% difference)\n\n";
    $priceComparison .= "Prices across platforms:\n";
    
    foreach ($prices as $p) {
        $platform = $p['platform'] ?? 'Unknown';
        $price = round($p['price']);
        $stock = ($p['inStock'] ?? true) ? '✓ In Stock' : '✗ Out of Stock';
        $priceComparison .= "- $platform: ₹$price ($stock)\n";
    }
    
    $systemPrompt .= "\n\nCurrent Product Information:\n$priceComparison\n";
    $systemPrompt .= "When the user asks about this product:\n";
    $systemPrompt .= "1. Always mention the best price and where to buy it\n";
    $systemPrompt .= "2. Compare prices across platforms and highlight savings\n";
    $systemPrompt .= "3. Highlight any out-of-stock items\n";
    $systemPrompt .= "4. Provide specific recommendations based on the price comparison\n";
    $systemPrompt .= "5. Suggest when it's a good time to buy (if there's a significant discount)\n";
}

// Prepare messages for Ollama
$messages = [];
$messages[] = [
    'role' => 'system',
    'content' => $systemPrompt
];

// Add conversation history (limit to last 10 messages for context)
foreach (array_slice($conversationHistory, -10) as $msg) {
    $messages[] = [
        'role' => $msg['role'],
        'content' => $msg['content']
    ];
}

// Add current message
$messages[] = [
    'role' => 'user',
    'content' => $message
];

// Call Ollama API
try {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $OLLAMA_BASE_URL . '/api/chat',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $OLLAMA_MODEL,
            'messages' => $messages,
            'stream' => false,
            'temperature' => 0.7,
        ]),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log('[Chat] Curl error: ' . $curlError);
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => 'Ollama service unavailable. Is it running? (ollama serve)',
            'details' => $curlError
        ]);
        exit;
    }

    if ($httpCode !== 200) {
        error_log('[Chat] Ollama HTTP ' . $httpCode . ': ' . $response);
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => 'Ollama service error',
            'details' => 'HTTP ' . $httpCode
        ]);
        exit;
    }

    $ollamaResponse = json_decode($response, true);
    
    if (!$ollamaResponse || !isset($ollamaResponse['message']['content'])) {
        error_log('[Chat] Invalid Ollama response: ' . $response);
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid response from Ollama',
        ]);
        exit;
    }

    $assistantMessage = $ollamaResponse['message']['content'];

    // Save chat messages to database (optional)
    try {
        $db = Database::getConnection();
        $db->prepare(
            'INSERT INTO chat_history (user_id, user_message, assistant_message, model_used, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([
            $user['user_id'],
            $message,
            $assistantMessage,
            $OLLAMA_MODEL
        ]);
    } catch (Exception $e) {
        error_log('[Chat] Database error: ' . $e->getMessage());
        // Non-critical, continue
    }

    // Return response
    echo json_encode([
        'success' => true,
        'message' => $assistantMessage,
        'model' => $OLLAMA_MODEL,
    ]);

} catch (Exception $e) {
    error_log('[Chat] Exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>
