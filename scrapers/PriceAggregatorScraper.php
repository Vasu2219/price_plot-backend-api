<?php
// scrapers/PriceAggregatorScraper.php
// Fetches multi-platform prices from Indian price-comparison aggregators:
// MySmartPrice (primary) and 91mobiles (fallback).
// Both sites aggregate Amazon, Flipkart, Croma, TataCliq, Vijay Sales,
// Reliance Digital, Snapdeal, etc. in one product page — readable by PHP cURL.
require_once __DIR__ . '/BaseScraper.php';

class PriceAggregatorScraper extends BaseScraper {

    public function scrape($url) { return ['success' => false]; }

    // -------------------------------------------------------------------------
    // Public entry point
    // -------------------------------------------------------------------------
    public function searchAll(string $productName, string $skipPlatform = ''): array {
        $query = $this->simplifyName($productName);
        error_log("[Aggregator] query='$query' skip='$skipPlatform'");

        // Primary: MySmartPrice
        $prices = $this->searchMySmartPrice($query, $skipPlatform);

        // If fewer than 2 stores found, supplement with 91mobiles
        if (count($prices) < 2) {
            $mob    = $this->search91Mobiles($query, $skipPlatform);
            $prices = $this->merge($prices, $mob);
        }

        error_log("[Aggregator] final count=" . count($prices));
        return $prices;
    }

    // =========================================================================
    // MySmartPrice
    // =========================================================================
    private function searchMySmartPrice(string $query, string $skip): array {
        $searchUrl = 'https://www.mysmartprice.com/gear/search/?s=' . rawurlencode($query);
        $sr = $this->fetchHTML(
            $searchUrl,
            'https://www.google.com/search?q=' . rawurlencode("$query price india site:mysmartprice.com")
        );
        if (!$sr) return [];

        $productUrl = $this->extractMspProductLink($sr['html']);
        if (!$productUrl) {
            error_log("[Aggregator/MSP] no product link in search results");
            return [];
        }
        error_log("[Aggregator/MSP] product page: $productUrl");

        $pr = $this->fetchHTML($productUrl, $searchUrl);
        if (!$pr) return [];

        return $this->parsePrices($pr['html'], $productUrl, $skip, 'MSP');
    }

    private function extractMspProductLink(string $html): ?string {
        $patterns = [
            // Preferred: URL with MSP numeric ID
            '~href=["\']((https://www\.mysmartprice\.com)?/gear/[a-z0-9][a-z0-9\-]+-msp\d+/?)["\']~i',
            // Generic: any /gear/ path at least 10 chars long
            '~href=["\']((https://www\.mysmartprice\.com)?/gear/[a-z0-9][a-z0-9\-]{9,}/?)["\']~i',
        ];
        foreach ($patterns as $pat) {
            if (preg_match($pat, $html, $m)) {
                $url = $m[1];
                return strpos($url, 'http') === 0 ? $url : 'https://www.mysmartprice.com' . $url;
            }
        }
        return null;
    }

    // =========================================================================
    // 91mobiles
    // =========================================================================
    private function search91Mobiles(string $query, string $skip): array {
        $searchUrl = 'https://www.91mobiles.com/search?q=' . rawurlencode($query);
        $sr = $this->fetchHTML(
            $searchUrl,
            'https://www.google.com/search?q=' . rawurlencode("$query price india site:91mobiles.com")
        );
        if (!$sr) return [];

        $productUrl = $this->extract91MobilesProductLink($sr['html']);
        if (!$productUrl) {
            error_log("[Aggregator/91M] no product link in search results");
            return [];
        }
        error_log("[Aggregator/91M] product page: $productUrl");

        $pr = $this->fetchHTML($productUrl, $searchUrl);
        if (!$pr) return [];

        return $this->parsePrices($pr['html'], $productUrl, $skip, '91M');
    }

    private function extract91MobilesProductLink(string $html): ?string {
        $patterns = [
            '~href=["\']((https://www\.91mobiles\.com)?/[a-z0-9\-]+/[a-z0-9\-]+-price-in-india[^"\']*)["\']~i',
            '~href=["\']((https://www\.91mobiles\.com)?/[^"\']+price-in-india[^"\']*)["\']~i',
        ];
        foreach ($patterns as $pat) {
            if (preg_match($pat, $html, $m)) {
                $url = $m[1];
                return strpos($url, 'http') === 0 ? $url : 'https://www.91mobiles.com' . $url;
            }
        }
        return null;
    }

    // =========================================================================
    // Shared price extraction (used by both MSP and 91M product pages)
    // =========================================================================
    private function parsePrices(string $html, string $sourceUrl, string $skip, string $tag): array {
        // Strategy 1: JSON-LD Product offers
        $prices = $this->parseJsonLd($html, $sourceUrl, $skip);
        if (!empty($prices)) {
            error_log("[Aggregator/$tag] JSON-LD: " . count($prices) . " prices");
            return $prices;
        }
        // Strategy 2: Regex pattern matching near known store names
        $prices = $this->parseRegex($html, $sourceUrl, $skip);
        error_log("[Aggregator/$tag] regex: " . count($prices) . " prices");
        return $prices;
    }

    private function parseJsonLd(string $html, string $sourceUrl, string $skip): array {
        $prices = [];
        if (!preg_match_all(
            '~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is',
            $html, $ms
        )) {
            return [];
        }
        foreach ($ms[1] as $jsonStr) {
            $data = @json_decode(trim($jsonStr), true);
            if (!is_array($data)) continue;
            $items = isset($data[0]) ? $data : [$data];
            foreach ($items as $item) {
                if (($item['@type'] ?? '') !== 'Product') continue;
                $offers = $item['offers'] ?? [];
                // Normalise: could be AggregateOffer array or single Offer
                if (isset($offers['@type'])) {
                    if ($offers['@type'] === 'AggregateOffer') {
                        // Some pages embed only aggregate – use lowPrice as a hint
                        if (isset($offers['lowPrice']) && floatval($offers['lowPrice']) >= 10) {
                            // individual offers may be nested inside
                            $inner = $offers['offers'] ?? [];
                            if (empty($inner)) {
                                // No per-store data; return the single aggregate
                                $prices[] = [
                                    'platform'     => 'Online Store',
                                    'price'        => floatval($offers['lowPrice']),
                                    'currency'     => 'INR',
                                    'availability' => 'In Stock',
                                    'link'         => $sourceUrl,
                                ];
                                continue;
                            }
                            $offers = $inner;
                        } else {
                            continue;
                        }
                    } else {
                        $offers = [$offers]; // single Offer
                    }
                }
                foreach ((array)$offers as $offer) {
                    $store = trim($offer['seller']['name'] ?? ($offer['name'] ?? ''));
                    $price = floatval($offer['price'] ?? 0);
                    $link  = $offer['url'] ?? $sourceUrl;
                    $avail = isset($offer['availability']) && stripos($offer['availability'], 'InStock') !== false
                             ? 'In Stock' : 'Check Site';
                    if ($price >= 10 && $store && !$this->isPlatformSkipped($store, $skip)) {
                        $prices[] = [
                            'platform'     => $this->normalisePlatform($store),
                            'price'        => $price,
                            'currency'     => 'INR',
                            'availability' => $avail,
                            'link'         => $link,
                        ];
                    }
                }
            }
        }
        return $prices;
    }

    private function parseRegex(string $html, string $sourceUrl, string $skip): array {
        $prices  = [];
        $seen    = [];
        // Known Indian e-commerce stores to look for
        $storeRe = 'amazon|flipkart|croma|vijay\s*sales?|reliance\s*digital|snapdeal'
                 . '|tatacliq|tata\s*cliq|poorvika|sangeetha|paytm\s*mall|shopclues';

        // Pattern A: store name appears BEFORE the rupee price (within 400 chars)
        preg_match_all(
            '/(' . $storeRe . ')[^₹\r\n]{0,400}(?:₹|Rs\.?)\s*([\d,]{3,10})/iu',
            $html, $ma, PREG_SET_ORDER
        );
        // Pattern B: rupee price appears BEFORE the store name
        preg_match_all(
            '/(?:₹|Rs\.?)\s*([\d,]{3,10})[^₹\r\n]{0,400}(' . $storeRe . ')/iu',
            $html, $mb, PREG_SET_ORDER
        );

        foreach ($ma as $m) {
            $store = $this->normalisePlatform($m[1]);
            $price = floatval(str_replace(',', '', $m[2]));
            $key   = strtolower($store);
            if ($price >= 10 && !isset($seen[$key]) && !$this->isPlatformSkipped($store, $skip)) {
                $seen[$key] = true;
                $prices[] = ['platform' => $store, 'price' => $price,
                             'currency' => 'INR', 'availability' => 'In Stock', 'link' => $sourceUrl];
            }
        }
        foreach ($mb as $m) {
            $store = $this->normalisePlatform($m[2]);
            $price = floatval(str_replace(',', '', $m[1]));
            $key   = strtolower($store);
            if ($price >= 10 && !isset($seen[$key]) && !$this->isPlatformSkipped($store, $skip)) {
                $seen[$key] = true;
                $prices[] = ['platform' => $store, 'price' => $price,
                             'currency' => 'INR', 'availability' => 'In Stock', 'link' => $sourceUrl];
            }
        }
        return $prices;
    }

    // =========================================================================
    // Helpers
    // =========================================================================
    private function merge(array $base, array $incoming): array {
        $map = [];
        foreach ($base     as $e) { $map[strtolower($e['platform'])] = $e; }
        foreach ($incoming as $e) {
            $key = strtolower($e['platform']);
            if (!isset($map[$key]) || $e['price'] < $map[$key]['price']) {
                $map[$key] = $e;
            }
        }
        return array_values($map);
    }

    private function isPlatformSkipped(string $name, string $skip): bool {
        if (empty($skip)) return false;
        return stripos($name, $skip) !== false;
    }

    private function normalisePlatform(string $raw): string {
        $l = strtolower(trim($raw));
        if (strpos($l, 'amazon')    !== false) return 'Amazon';
        if (strpos($l, 'flipkart')  !== false) return 'Flipkart';
        if (strpos($l, 'croma')     !== false) return 'Croma';
        if (strpos($l, 'vijay')     !== false) return 'Vijay Sales';
        if (strpos($l, 'reliance')  !== false) return 'Reliance Digital';
        if (strpos($l, 'snapdeal')  !== false) return 'Snapdeal';
        if (strpos($l, 'tatacliq')  !== false) return 'TataCliq';
        if (strpos($l, 'tata')      !== false) return 'TataCliq';
        if (strpos($l, 'poorvika')  !== false) return 'Poorvika';
        if (strpos($l, 'sangeetha') !== false) return 'Sangeetha';
        if (strpos($l, 'paytm')     !== false) return 'PaytmMall';
        if (strpos($l, 'shopclues') !== false) return 'ShopClues';
        return ucwords($l);
    }

    private function simplifyName(string $name): string {
        $name = preg_replace('/\s*\([^)]*\)/', '', $name);
        $name = preg_replace('/\s+with\s+.*/i', '', $name);
        $words = preg_split('/\s+/', trim($name));
        return implode(' ', array_slice($words, 0, 5));
    }
}
?>