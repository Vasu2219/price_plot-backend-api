<?php
// api/products/fetch_product.php
// Scrapes the submitted product URL with the native platform scraper, then searches
// Flipkart/Snapdeal/Amazon for the same product to build a multi-platform comparison.
// Returns prices + computed AI score + recommendation to the Android app.

require_once '../../config/cors.php';
require_once '../../config/database.php';
require_once '../../helpers/auth.php';
require_once '../../helpers/price_history.php';
require_once '../../scrapers/BaseScraper.php';
require_once '../../scrapers/AmazonScraper.php';
require_once '../../scrapers/FlipkartScraper.php';
require_once '../../scrapers/SnapdealScraper.php';
require_once '../../scrapers/MyntraScraper.php';
require_once '../../scrapers/PriceAggregatorScraper.php';
require_once '../../scrapers/CrossPlatformScraper.php';

set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');

// Log the request for debugging
error_log('[FETCH_PRODUCT] Method: ' . $_SERVER['REQUEST_METHOD']);
error_log('[FETCH_PRODUCT] Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
error_log('[FETCH_PRODUCT] Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? 'not set'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log('[FETCH_PRODUCT] ERROR: Method not POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. POST required.']);
    exit;
}

// Authenticate user (optional for public access)
// If no valid token, use guest user ID
try {
    $user = tryAuth();
    error_log('[FETCH_PRODUCT] Auth successful for user: ' . $user['user_id']);
} catch (Exception $e) {
    // Allow guest/public access
    error_log('[FETCH_PRODUCT] No auth token - using guest access: ' . $e->getMessage());
    $user = ['user_id' => 0]; // Guest user ID = 0
}

$rawInput = file_get_contents('php://input');
error_log('[FETCH_PRODUCT] Raw input: ' . substr($rawInput, 0, 200));

$input = json_decode($rawInput, true);
if ($input === null && $rawInput !== '') {
    error_log('[FETCH_PRODUCT] ERROR: Invalid JSON - ' . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON in request body']);
    exit;
}

$productUrl   = trim($input['productUrl'] ?? '');
$forceRefresh = (bool)($input['forceRefresh'] ?? false);

error_log('[FETCH_PRODUCT] ProductUrl: ' . substr($productUrl, 0, 100));
error_log('[FETCH_PRODUCT] ForceRefresh: ' . ($forceRefresh ? 'true' : 'false'));

if (empty($productUrl)) {
    error_log('[FETCH_PRODUCT] ERROR: Empty product URL');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Product URL is required'
    ]);
    exit;
}

if (!filter_var($productUrl, FILTER_VALIDATE_URL)) {
    error_log('[FETCH_PRODUCT] ERROR: Invalid URL format: ' . $productUrl);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid URL format. Example: https://amazon.in/dp/B0XXXXX'
    ]);
    exit;
}

// ----- Platform detection -----
$supported = [
    'amazon'   => 'amazon',
    'amzn'     => 'amazon',
    'flipkart' => 'flipkart',
    'snapdeal' => 'snapdeal',
    'myntra'   => 'myntra',
];

$host     = strtolower(parse_url($productUrl, PHP_URL_HOST) ?? '');
error_log('[FETCH_PRODUCT] Detected host: ' . $host);

$platform = null;
foreach ($supported as $fragment => $label) {
    if (strpos($host, $fragment) !== false) {
        $platform = $label;
        error_log('[FETCH_PRODUCT] Platform matched: ' . $platform);
        break;
    }
}

if ($platform === null) {
    error_log('[FETCH_PRODUCT] ERROR: Unsupported platform. Host: ' . $host);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Unsupported platform. Supported: Amazon (amazon.in), Flipkart (flipkart.com), Snapdeal (snapdeal.com), Myntra (myntra.com). Detected host: ' . $host,
    ]);
    exit;
}

$urlHash = hash('sha256', $productUrl);
$productsCollection = Database::collection('products');
$priceCacheCollection = Database::collection('price_cache');
$scrapeRequestsCollection = Database::collection('scrape_requests');

// ----- Cache check -----
if (!$forceRefresh) {
    $cached = $priceCacheCollection->findOne([
        'url_hash' => $urlHash,
        '$or' => [
            ['expires_at' => null],
            ['expires_at' => ['$gt' => Database::now()]],
        ],
    ]);
    $cached = Database::docToArray($cached);

    if (!empty($cached)) {
        $priceCacheCollection->updateOne(
            ['url_hash' => $urlHash],
            ['$inc' => ['hit_count' => 1]]
        );

        $data = $cached['scraped_data'] ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (!is_array($data)) {
            $data = [];
        }
        $data['fromCache'] = true;
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }
}

// ----- Primary platform scrape -----
$startTime = microtime(true);

try {
    switch ($platform) {
        case 'amazon':   $scraper = new AmazonScraper();   break;
        case 'flipkart': $scraper = new FlipkartScraper(); break;
        case 'snapdeal': $scraper = new SnapdealScraper(); break;
        case 'myntra':   $scraper = new MyntraScraper();   break;
    }
    
    error_log('[FETCH_PRODUCT] Starting scrape for platform: ' . $platform);
    $primary = $scraper->scrape($productUrl);
    error_log('[FETCH_PRODUCT] Scrape result: ' . json_encode($primary));
} catch (Exception $e) {
    error_log('[FETCH_PRODUCT] Scraper exception: ' . $e->getMessage());
    $primary = ['success' => false, 'error' => $e->getMessage()];
}

$responseTimeMs = (int)round((microtime(true) - $startTime) * 1000);

if (!$primary['success']) {
    $errMsg = $primary['error'] ?? 'Scraper returned no data';
    error_log('[FETCH_PRODUCT] Scraping failed: ' . $errMsg);
    
    try {
        $scrapeRequestsCollection->insertOne([
            'request_id' => Database::nextId('scrape_requests'),
            'user_id' => (int)$user['user_id'],
            'product_url' => $productUrl,
            'request_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'success' => 0,
            'response_time_ms' => $responseTimeMs,
            'error_message' => $errMsg,
            'requested_at' => Database::now(),
        ]);
    } catch (Exception $e) {
        error_log('[FETCH_PRODUCT] Could not log failure: ' . $e->getMessage());
    }
    
    http_response_code(422);
    echo json_encode([
        'success' => false, 
        'error' => 'Could not extract product data from this URL. Make sure it\'s a valid product page and the product is in stock. Error: ' . $errMsg
    ]);
    exit;
}

error_log('[FETCH_PRODUCT] Scrape successful, logging to database');
try {
    $scrapeRequestsCollection->insertOne([
        'request_id' => Database::nextId('scrape_requests'),
        'user_id' => (int)$user['user_id'],
        'product_url' => $productUrl,
        'request_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'success' => 1,
        'response_time_ms' => $responseTimeMs,
        'error_message' => null,
        'requested_at' => Database::now(),
    ]);
} catch (Exception $e) {
    error_log('[FETCH_PRODUCT] Could not log success: ' . $e->getMessage());
}

$productName  = $primary['productName']  ?? 'Unknown Product';
$productImage = $primary['productImage'] ?? null;

// Primary price entry
$prices = [[
    'platform'     => $primary['platform'],
    'price'        => (float)$primary['price'],
    'currency'     => $primary['currency']     ?? 'INR',
    'availability' => $primary['availability'] ?? 'In Stock',
    'link'         => $primary['link']         ?? $productUrl,
]];

// ----- Cross-platform search with fallback -----
// Strategy 1: Use PriceAggregatorScraper
// Strategy 2: Use CrossPlatformScraper
// Strategy 3: If both fail, add empty price placeholders for popular platforms
// (Frontend uses fallback search URLs)

try {
    $aggScraper = new PriceAggregatorScraper();
    $extras     = $aggScraper->searchAll($productName, $platform);

    // If aggregator found fewer than 2 stores, supplement with direct scraper
    if (count($extras) < 2) {
        error_log('[fetch_product] aggregator got ' . count($extras) . ' results, trying CrossPlatformScraper');
        $crossScraper  = new CrossPlatformScraper();
        $crossExtras   = $crossScraper->searchAll($productName, $platform);
        // Merge (keep lowest price per platform)
        $extrasMap = [];
        foreach ($extras      as $e) { $extrasMap[strtolower($e['platform'])] = $e; }
        foreach ($crossExtras  as $e) {
            $k = strtolower($e['platform']);
            if (!isset($extrasMap[$k]) || $e['price'] < $extrasMap[$k]['price']) {
                $extrasMap[$k] = $e;
            }
        }
        $extras = array_values($extrasMap);
    }

    foreach ($extras as $ex) {
        if (!empty($ex['price']) && $ex['price'] >= 10) {
            $prices[] = [
                'platform'     => $ex['platform'],
                'price'        => (float)$ex['price'],
                'currency'     => $ex['currency']     ?? 'INR',
                'availability' => $ex['availability'] ?? 'In Stock',
                'link'         => $ex['link']         ?? '',
            ];
        }
    }
    
    // If we got insufficient results from scrapers, add major platforms for comparison
    // (even without real prices - frontend will show "Check Site" and use fallback search links)
    $existingPlatforms = array_map(fn($p) => strtolower($p['platform']), $prices);
    $majorPlatforms = ['amazon', 'flipkart', 'snapdeal', 'myntra', 'croma', 'tatacliq', 'zabrs'];
    
    foreach ($majorPlatforms as $majorPlatform) {
        if (!in_array($majorPlatform, $existingPlatforms) && count($prices) < 6) {
            // Add platform with null price (frontend will show "Check Site")
            $prices[] = [
                'platform'     => ucfirst($majorPlatform),
                'price'        => null,
                'currency'     => 'INR',
                'availability' => 'Check Site',
                'link'         => '',
            ];
        }
    }
    
} catch (Throwable $e) {
    error_log('[fetch_product] cross-platform error: ' . $e->getMessage());
    
    // Emergency fallback: add major platforms even if all scraping failed
    $majorPlatforms = ['amazon', 'flipkart', 'snapdeal', 'myntra', 'croma'];
    $existingPlatforms = array_map(fn($p) => strtolower($p['platform']), $prices);
    
    foreach ($majorPlatforms as $majorPlatform) {
        if (!in_array($majorPlatform, $existingPlatforms) && count($prices) < 6) {
            $prices[] = [
                'platform'     => ucfirst($majorPlatform),
                'price'        => null,
                'currency'     => 'INR',
                'availability' => 'Check Site',
                'link'         => '',
            ];
        }
    }
}

// ----- Deduplicate by platform (keep lowest VALID price per platform) -----
$byPlatform = [];
foreach ($prices as $pe) {
    $key = strtolower($pe['platform']);
    $pePrice = (float)($pe['price'] ?? 0);
    
    if (!isset($byPlatform[$key])) {
        // First time seeing this platform
        $byPlatform[$key] = $pe;
    } else {
        // Platform exists, check if this is a better (valid) price
        $existingPrice = (float)($byPlatform[$key]['price'] ?? 0);
        $currentIsValid = $pePrice > 0;
        $existingIsValid = $existingPrice > 0;
        
        // Logic: prefer valid prices over invalid
        // If both valid or both invalid, prefer the lower price
        if ($currentIsValid && !$existingIsValid) {
            // Current is valid, existing is not - always replace
            $byPlatform[$key] = $pe;
        } elseif ($currentIsValid && $existingIsValid && $pePrice < $existingPrice) {
            // Both valid - use the lower price
            $byPlatform[$key] = $pe;
        }
        // Otherwise keep existing
    }
}
$prices = array_values($byPlatform);
error_log('[fetch_product] After dedup: ' . count($prices) . ' platforms, valid: ' . count(array_filter($prices, fn($p) => $p['price'] && $p['price'] > 0)));


// ----- Filter out prices with null or 0 values for best price calculation -----
$validPrices = array_filter($prices, fn($p) => $p['price'] && $p['price'] > 0);

// Initialize bestPrice variables
$minPrice = 0;
$maxPrice = 0;
$bestPlatform = 'Unknown';

// If we have valid prices, use them
if (!empty($validPrices)) {
    usort($validPrices, fn($a, $b) => $a['price'] <=> $b['price']);
    $minPrice = (float)$validPrices[0]['price'];
    $maxPrice = (float)$validPrices[count($validPrices) - 1]['price'];
    $bestPlatform = $validPrices[0]['platform'];
} else {
    // No valid prices found - this is an edge case
    // Try to find ANY platform with a price to use as reference
    error_log('[fetch_product] No valid prices found for product: ' . $productName);
    $pricesWithNumbers = array_filter($prices, fn($p) => is_numeric($p['price']) || $p['price']);
    if (!empty($pricesWithNumbers)) {
        usort($pricesWithNumbers, fn($a, $b) => (float)($a['price'] ?? 999999) <=> (float)($b['price'] ?? 999999));
        $minPrice = (float)($pricesWithNumbers[0]['price'] ?? 0);
        $maxPrice = (float)($pricesWithNumbers[count($pricesWithNumbers) - 1]['price'] ?? 0);
        $bestPlatform = $pricesWithNumbers[0]['platform'] ?? 'Unknown';
        // Skip showing bestPrice if it's still 0
        if ($minPrice == 0) {
            $minPrice = 0;
            $bestPlatform = 'Unknown';
        }
    }
}

// Only calculate average from valid prices
$avgPrice = !empty($validPrices) ? array_sum(array_column($validPrices, 'price')) / count($validPrices) : 0;
$savings = $maxPrice > 0 && $minPrice > 0 ? round($maxPrice - $minPrice, 2) : 0;

// DEBUG: Log the bestPrice calculation
error_log('[fetch_product] bestPrice calculation - minPrice: ' . $minPrice . ', bestPlatform: ' . $bestPlatform . ', validPrices count: ' . count($validPrices));

$bestPrice = [
    'platform' => $bestPlatform,
    'price'    => $minPrice,
    'savings'  => $savings,
];

// ----- Compute AI Score -----
$platformCount = count($validPrices) > 0 ? count($validPrices) : count($prices);
$variance      = ($maxPrice > 0 && $minPrice > 0) ? (($maxPrice - $minPrice) / $maxPrice) : 0;

$aiScore = 65;
$aiScore += min($platformCount * 5, 25);   // +5 per platform up to +25
if ($variance < 0.05)       $aiScore += 5; // consistent pricing  
elseif ($variance > 0.20)   $aiScore -= 5; // big price gap
if ($platformCount >= 3)    $aiScore += 5; // well-researched

$aiScore = max(60, min(95, $aiScore));

if      ($aiScore >= 85) $aiLabel = 'Highly Recommended';
elseif  ($aiScore >= 75) $aiLabel = 'Good Buy';
else                     $aiLabel = 'Fair Deal';

$recommendation = sprintf(
    'Buy on %s — cheapest at ₹%s. Compared across %d platform%s.',
    $bestPlatform,
    number_format($minPrice, 0, '.', ','),
    count($validPrices) > 0 ? count($validPrices) : count($prices),
    (count($validPrices) > 0 ? count($validPrices) : count($prices)) > 1 ? 's' : ''
);

// ----- Upsert product record -----
$existing = $productsCollection->findOne(['url_hash' => $urlHash]);
$existing = Database::docToArray($existing);

if (!empty($existing)) {
    $productId = (int)$existing['product_id'];
    $productsCollection->updateOne(
        ['product_id' => $productId],
        [
            '$set' => [
                'product_name' => $productName,
                'product_image_url' => $productImage,
                'last_scraped_at' => Database::now(),
            ],
            '$inc' => ['scrape_count' => 1],
        ]
    );
} else {
    $productId = Database::nextId('products');
    $productsCollection->insertOne([
        'product_id' => $productId,
        'original_url' => $productUrl,
        'url_hash' => $urlHash,
        'product_name' => $productName,
        'product_image_url' => $productImage,
        'category' => null,
        'first_scraped_at' => Database::now(),
        'last_scraped_at' => Database::now(),
        'scrape_count' => 1,
        'is_active' => 1,
    ]);
}

// ----- Insert price history only when changed (skip NULL prices) -----
$insertedPriceRows = 0;
foreach ($prices as $pe) {
    // Only insert prices that have actual values (not null/0)
    if (!empty($pe['price']) && $pe['price'] > 0) {
        if (insertPriceIfChanged(
            $productId,
            $pe['platform'],
            $pe['price'],
            $pe['currency'],
            $pe['availability'],
            $pe['link']
        )) {
            $insertedPriceRows++;
        }
    }
}

// ----- Ensure prices array is deduplicated and safe for production -----
// At this point $prices should already be deduplicated, but let's make absolutely sure
// for production stability
$finalPrices = [];
$seenPlatforms = [];
foreach ($prices as $p) {
    $key = strtolower($p['platform']);
    if (!isset($seenPlatforms[$key])) {
        $seenPlatforms[$key] = true;
        $finalPrices[] = $p;
    }
}

error_log('[fetch_product] Final prices count: ' . count($finalPrices) . ' (deduplicated from ' . count($prices) . ')');

// ----- Build response -----
$responseData = [
    'productId'      => $productId,
    'productName'    => $productName,
    'productImage'   => $productImage,
    'prices'         => $finalPrices,  // Use deduplicated array
    'bestPrice'      => $bestPrice,
    'averagePrice'   => round($avgPrice, 2),
    'totalResults'   => count($finalPrices),  // Count deduplicated prices
    'aiScore'        => $aiScore,
    'aiLabel'        => $aiLabel,
    'recommendation' => $recommendation,
    'flashSavings'   => $savings,
    'lastUpdated'    => date('c'),
    'fromCache'      => false,
    'historyRowsInserted' => $insertedPriceRows,
];

// ----- Cache (1 hour) -----
$expiresAt = new MongoDB\BSON\UTCDateTime((int)((microtime(true) + 3600) * 1000));
$priceCacheCollection->updateOne(
    ['url_hash' => $urlHash],
    [
        '$set' => [
            'scraped_data' => $responseData,
            'expires_at' => $expiresAt,
            'cached_at' => Database::now(),
            'hit_count' => 0,
        ],
        '$setOnInsert' => [
            'cache_id' => Database::nextId('price_cache'),
            'url_hash' => $urlHash,
        ],
    ],
    ['upsert' => true]
);

error_log('[fetch_product] Response ready for user. bestPrice: ' . $minPrice . ', platforms: ' . count($finalPrices));
echo json_encode(['success' => true, 'data' => $responseData]);