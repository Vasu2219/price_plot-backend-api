<?php
// api/test-scraper.php
// Test scraper directly with a URL

require_once '../config/cors.php';
require_once '../helpers/auth.php';
require_once '../scrapers/BaseScraper.php';
require_once '../scrapers/AmazonScraper.php';
require_once '../scrapers/FlipkartScraper.php';
require_once '../scrapers/SnapdealScraper.php';
require_once '../scrapers/MyntraScraper.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = requireAuth();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$url = trim($input['url'] ?? '');

if (empty($url)) {
    http_response_code(400);
    echo json_encode(['error' => 'URL is required']);
    exit;
}

$response = [
    'url' => $url,
    'test_time' => date('Y-m-d H:i:s'),
];

// Detect platform
$host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
$response['detected_host'] = $host;

$platform = null;
if (strpos($host, 'amazon') !== false) $platform = 'amazon';
elseif (strpos($host, 'flipkart') !== false) $platform = 'flipkart';
elseif (strpos($host, 'snapdeal') !== false) $platform = 'snapdeal';
elseif (strpos($host, 'myntra') !== false) $platform = 'myntra';

if (!$platform) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported platform: ' . $host]);
    exit;
}

$response['detected_platform'] = $platform;

// Try to scrape
try {
    switch ($platform) {
        case 'amazon':   $scraper = new AmazonScraper();   break;
        case 'flipkart': $scraper = new FlipkartScraper(); break;
        case 'snapdeal': $scraper = new SnapdealScraper(); break;
        case 'myntra':   $scraper = new MyntraScraper();   break;
    }
    
    error_log('[TEST_SCRAPER] Starting scrape for: ' . $url);
    $result = $scraper->scrape($url);
    error_log('[TEST_SCRAPER] Result: ' . json_encode($result));
    
    if ($result['success']) {
        $response['status'] = 'SUCCESS ✓';
        $response['product'] = [
            'name' => $result['productName'] ?? 'Unknown',
            'price' => $result['price'] ?? null,
            'currency' => $result['currency'] ?? 'INR',
            'availability' => $result['availability'] ?? 'Unknown',
            'image' => $result['productImage'] ?? null,
        ];
    } else {
        $response['status'] = 'FAILED ✗';
        $response['error'] = $result['error'] ?? 'Unknown error';
    }
} catch (Exception $e) {
    error_log('[TEST_SCRAPER] Exception: ' . $e->getMessage());
    $response['status'] = 'ERROR ✗';
    $response['error'] = $e->getMessage();
    $response['trace'] = $e->getTraceAsString();
}

http_response_code(isset($response['error']) ? 422 : 200);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
