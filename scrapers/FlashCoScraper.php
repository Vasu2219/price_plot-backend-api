<?php
// scrapers/FlashCoScraper.php
// Fetches multi-platform price comparisons from flash.co (AI aggregator).
// Flow for any product URL: keyword -> flash.co search -> /item page -> all store prices.
require_once __DIR__ . '/BaseScraper.php';

class FlashCoScraper extends BaseScraper {

    private static $PLATFORM_NAMES = [
        'amazon'          => 'Amazon',
        'amzn'            => 'Amazon',
        'flipkart'        => 'Flipkart',
        'snapdeal'        => 'Snapdeal',
        'myntra'          => 'Myntra',
        'meesho'          => 'Meesho',
        'nykaa'           => 'Nykaa',
        'ajio'            => 'Ajio',
        'tatacliq'        => 'Tata CLiQ',
        'jiomart'         => 'JioMart',
        'croma'           => 'Croma',
        'reliancedigital' => 'Reliance Digital',
        'vijaysales'      => 'Vijay Sales',
        'shopsy'          => 'Shopsy',
        'paytmmall'       => 'Paytm Mall',
        'bigbasket'       => 'BigBasket',
    ];

    // -----------------------------------------------------------------------
    // Entry point
    // -----------------------------------------------------------------------
    public function scrape($url) {
        error_log("[FlashCoScraper] Scraping: $url");
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        // If already a flash.co URL, parse directly
        if (strpos($host, 'flash.co') !== false) {
            return $this->fetchAndParse($url);
        }

        // Step 1: extract keywords from the product URL
        $query = $this->extractSearchQuery($url);
        error_log("[FlashCoScraper] Query: '$query'");

        // Step 2: search flash.co with the extracted keyword (or full URL as fallback)
        $searchTerm = !empty($query) ? $query : $url;
        $searchUrl  = 'https://flash.co/search?q=' . rawurlencode($searchTerm);
        $r = $this->fetchHTML($searchUrl, 'https://flash.co/');
        if (!$r) {
            return ['success' => false, 'error' => 'Flash.co unreachable'];
        }

        // Step 3: find /item/{id} links on the search results page
        $itemLinks = $this->findItemLinks($r['html']);
        error_log("[FlashCoScraper] Found " . count($itemLinks) . " item links");

        // Step 4: fetch the top item page — it has the full cross-platform comparison
        if (!empty($itemLinks)) {
            $itemUrl = 'https://flash.co' . $itemLinks[0];
            $res = $this->fetchAndParse($itemUrl);
            if ($res['success']) return $res;
        }

        // Step 5: fall back — parse whatever prices appear on the search results page
        return $this->parseMultiPrices($r['html'], $r['finalUrl'] ?? $searchUrl);
    }

    // -----------------------------------------------------------------------
    // Fetch a URL and run the multi-price parser on its HTML
    // -----------------------------------------------------------------------
    private function fetchAndParse($url) {
        $r = $this->fetchHTML($url, 'https://flash.co/');
        if (!$r) return ['success' => false, 'error' => 'Flash.co page unreachable'];
        return $this->parseMultiPrices($r['html'], $r['finalUrl'] ?? $url);
    }

    // -----------------------------------------------------------------------
    // Find all /item/{id} href paths in a flash.co HTML page
    // -----------------------------------------------------------------------
    private function findItemLinks($html) {
        preg_match_all('/href="(\/item\/[^"?#]+)"/i', $html, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    // -----------------------------------------------------------------------
    // Core price extractor — works on any flash.co page HTML
    // -----------------------------------------------------------------------
    private function parseMultiPrices($html, $pageUrl) {
        // Collect and decode all RSC (React Server Components) streaming chunks
        preg_match_all('/self\.__next_f\.push\(\[1,"(.*?)"\]\)/s', $html, $matches);
        $rsc = '';
        foreach ($matches[1] ?? [] as $chunk) {
            $rsc .= stripcslashes($chunk);
        }

        $productName  = '';
        $productImage = '';

        // ── Product name ─────────────────────────────────────────────────
        $src = !empty($rsc) ? $rsc : $html;
        if (preg_match('/"(?:productName|title|name)"\s*:\s*"([^"\\\\]{4,200})"/u', $src, $m)) {
            $productName = $this->cleanText(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        if (empty($productName) && preg_match('/<title[^>]*>([^<]{4,120})<\/title>/i', $html, $m)) {
            $productName = trim(preg_replace('/\s*[|\-\x{2013}\x{2014}]\s*Flash.*$/iu', '', $this->cleanText($m[1])));
        }

        // ── Product image ────────────────────────────────────────────────
        if (preg_match('/"(?:imageUrl|image|thumbnail|productImage)"\s*:\s*"(https?:[^"\\\\]+)"/u', $src, $m)) {
            $productImage = $m[1];
        }

        // ── Strategy A: named JSON arrays of store objects ───────────────
        $prices = [];
        foreach (['stores','sellers','offers','results','platforms','sites','comparisons','deals','prices','merchants'] as $key) {
            if (preg_match('/"' . $key . '"\s*:\s*(\[[\s\S]{2,}\])/uU', $rsc, $m)) {
                $arr = @json_decode($m[1], true);
                if (is_array($arr) && count($arr) > 0) {
                    $parsed = $this->parseOffersArray($arr, $pageUrl);
                    if (count($parsed) > 0) {
                        $prices = $parsed;
                        error_log("[FlashCoScraper] Strategy A: $key => " . count($prices) . " prices");
                        break;
                    }
                }
            }
        }

        // ── Strategy B: scan individual JSON blocks for store+price pairs ─
        if (empty($prices)) {
            $prices = $this->scanJsonBlocks($src, $pageUrl);
            if (!empty($prices)) {
                error_log("[FlashCoScraper] Strategy B: " . count($prices) . " prices");
            }
        }

        // ── Strategy C: single-price fallback ────────────────────────────
        if (empty($prices)) {
            $singlePrice = 0.0;
            if (preg_match('/"(?:price|salePrice|sellingPrice|displayPrice|finalPrice)"\s*:\s*"([^"]{1,40})"/u', $src, $m)) {
                $singlePrice = $this->cleanPrice($m[1]);
            }
            if ($singlePrice <= 0 && preg_match('/"(?:price|salePrice|sellingPrice)"\s*:\s*([\d.]+)/u', $src, $m)) {
                $singlePrice = floatval($m[1]);
            }
            if ($singlePrice <= 0 && preg_match('/[\x{20B9}\xe2\x82\xb9]\s*([\d,]+(?:\.\d{1,2})?)/u', $html, $m)) {
                $singlePrice = floatval(str_replace(',', '', $m[1]));
            }
            if ($singlePrice > 0) {
                $prices = [['platform' => 'Flash.co', 'price' => $singlePrice, 'currency' => 'INR', 'availability' => 'In Stock', 'link' => $pageUrl]];
                error_log("[FlashCoScraper] Strategy C single price: $singlePrice");
            }
        }

        error_log("[FlashCoScraper] Total: " . count($prices) . ", name=" . substr($productName, 0, 50));

        if (empty($prices)) {
            return ['success' => false, 'error' => 'Could not extract any prices from Flash.co'];
        }

        return [
            'success'      => true,
            'multi'        => true,
            'productName'  => $productName ?: 'Flash.co Product',
            'productImage' => $productImage ?: null,
            'prices'       => $this->deduplicatePrices($prices),
        ];
    }

    // -----------------------------------------------------------------------
    // Parse an array of store/seller objects decoded from RSC JSON
    // -----------------------------------------------------------------------
    private function parseOffersArray(array $offers, string $pageUrl): array {
        $results = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) continue;
            $platform = '';
            foreach (['storeName','seller','platform','name','source','site','store','merchant','vendorName'] as $k) {
                if (!empty($offer[$k]) && is_string($offer[$k])) {
                    $platform = $this->normalizePlatformName($offer[$k]);
                    break;
                }
            }
            if (empty($platform)) continue;
            $price = 0.0;
            foreach (['salePrice','price','sellingPrice','discountedPrice','finalPrice','offerPrice','currentPrice','displayPrice'] as $k) {
                if (isset($offer[$k]) && $offer[$k] !== '' && $offer[$k] !== null) {
                    $c = $this->cleanPrice((string)$offer[$k]);
                    if ($c > 0) { $price = $c; break; }
                }
            }
            if ($price <= 0) continue;
            $link = $pageUrl;
            foreach (['link','url','productUrl','href','affiliateUrl','redirectUrl','buyUrl','storeUrl'] as $k) {
                if (!empty($offer[$k]) && is_string($offer[$k])) {
                    $link = (strpos($offer[$k], 'http') === 0) ? $offer[$k] : 'https://flash.co' . $offer[$k];
                    break;
                }
            }
            $availability = 'In Stock';
            foreach (['availability','inStock','status','stock','stockStatus'] as $k) {
                if (isset($offer[$k])) {
                    $v = $offer[$k];
                    if (is_bool($v))        $availability = $v ? 'In Stock' : 'Out of Stock';
                    elseif (is_string($v))  $availability = $this->cleanText($v);
                    elseif (is_numeric($v)) $availability = ((int)$v > 0) ? 'In Stock' : 'Out of Stock';
                    break;
                }
            }
            $results[] = ['platform' => $platform, 'price' => $price, 'currency' => 'INR', 'availability' => $availability, 'link' => $link];
        }
        return $results;
    }

    // -----------------------------------------------------------------------
    // Scan all JSON-like {...} blocks in text for store+price pairs
    // -----------------------------------------------------------------------
    private function scanJsonBlocks($text, $pageUrl): array {
        $results = [];
        $seen    = [];
        preg_match_all('/\{[^{}]{10,600}\}/s', $text, $blocks);
        foreach ($blocks[0] ?? [] as $block) {
            $obj = @json_decode($block, true);
            if (is_array($obj)) {
                $rows = $this->parseOffersArray([$obj], $pageUrl);
                if (!empty($rows)) {
                    $key = strtolower($rows[0]['platform']);
                    if (!isset($seen[$key])) { $seen[$key] = true; $results[] = $rows[0]; }
                    continue;
                }
            }
            $platform = '';
            if (preg_match('/"(?:storeName|seller|platform|name|site|merchant)"\s*:\s*"([^"]+)"/i', $block, $m)) {
                $platform = $this->normalizePlatformName($m[1]);
            }
            if (empty($platform)) continue;
            $price = 0.0;
            if (preg_match('/"(?:price|salePrice|sellingPrice|finalPrice)"\s*:\s*"?([\d,]+(?:\.\d{1,2})?)"?/i', $block, $m)) {
                $price = $this->cleanPrice($m[1]);
            }
            if ($price <= 0) continue;
            $link = $pageUrl;
            if (preg_match('/"(?:link|url|href)"\s*:\s*"(https?:[^"]+)"/i', $block, $m)) {
                $link = $m[1];
            }
            $key = strtolower($platform);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $results[] = ['platform' => $platform, 'price' => $price, 'currency' => 'INR', 'availability' => 'In Stock', 'link' => $link];
            }
        }
        return $results;
    }

    // -----------------------------------------------------------------------
    // Keep only the lowest-priced entry per platform
    // -----------------------------------------------------------------------
    private function deduplicatePrices(array $prices): array {
        $byPlatform = [];
        foreach ($prices as $p) {
            $key = strtolower($p['platform']);
            if (!isset($byPlatform[$key]) || $p['price'] < $byPlatform[$key]['price']) {
                $byPlatform[$key] = $p;
            }
        }
        return array_values($byPlatform);
    }

    // -----------------------------------------------------------------------
    // Map raw store name strings to consistent display names
    // -----------------------------------------------------------------------
    private function normalizePlatformName($name): string {
        $lower = strtolower(trim($name));
        foreach (self::$PLATFORM_NAMES as $fragment => $display) {
            if (strpos($lower, $fragment) !== false) return $display;
        }
        return ucwords(str_replace(['-', '_'], ' ', $lower));
    }

    // -----------------------------------------------------------------------
    // Extract a clean search keyword from any e-commerce product URL
    // -----------------------------------------------------------------------
    private function extractSearchQuery($url): string {
        $path  = parse_url($url, PHP_URL_PATH) ?? '';
        $parts = array_filter(explode('/', trim($path, '/')));
        $words = [];
        foreach ($parts as $part) {
            if (ctype_digit($part)) break;
            if (strlen($part) < 3) continue;
            if (preg_match('/^(dp|gp|s|p|b|i|d|buy|shop|ref|item|store|product|portal)$/i', $part)) continue;
            if (preg_match('/^[A-Z0-9]{6,}$/i', $part) && strpos($part, '-') === false) continue;
            $decoded = trim(urldecode(str_replace(['-', '_', '+'], ' ', $part)));
            if (strlen($decoded) > 3) {
                $words[] = $decoded;
                if (count($words) >= 4) break;
            }
        }
        $query = implode(' ', $words);
        if (strlen($query) > 100) {
            $query = substr($query, 0, strrpos(substr($query, 0, 100), ' ') ?: 100);
        }
        return $query;
    }
}
?>