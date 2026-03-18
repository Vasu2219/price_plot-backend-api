<?php
// scrapers/CrossPlatformScraper.php
// Searches Flipkart and Snapdeal for a given product name and returns price entries.
// Used by fetch_product.php after the primary platform scraper runs.
require_once __DIR__ . '/BaseScraper.php';

class CrossPlatformScraper extends BaseScraper {

    // We never use this directly, but BaseScraper needs it.
    public function scrape($url) { return ['success' => false]; }

    // ------------------------------------------------------------------
    // Public entry point
    // $skipPlatform: platform already scraped by the primary scraper
    // ------------------------------------------------------------------
    public function searchAll($productName, $skipPlatform = '') {
        $query   = $this->simplifyName($productName);
        $results = [];
        error_log("[CrossPlatform] Searching for: $query (skip: $skipPlatform)");

        if ($skipPlatform !== 'flipkart') {
            $fk = $this->searchFlipkart($query);
            if ($fk) $results[] = $fk;
        }
        if ($skipPlatform !== 'snapdeal') {
            $sd = $this->searchSnapdeal($query);
            if ($sd) $results[] = $sd;
        }
        if (!in_array($skipPlatform, ['amazon', 'amzn'])) {
            $az = $this->searchAmazon($query);
            if ($az) $results[] = $az;
        }
        if ($skipPlatform !== 'myntra') {
            $mn = $this->searchMyntra($query);
            if ($mn) $results[] = $mn;
        }

        error_log("[CrossPlatform] Found " . count($results) . " additional results");
        return $results;
    }

    // ------------------------------------------------------------------
    // Flipkart search
    // ------------------------------------------------------------------
    private function searchFlipkart($query) {
        $url = 'https://www.flipkart.com/search?sort=popularity&q=' . rawurlencode($query);
        $r   = $this->fetchHTML($url, 'https://www.google.com/search?q=' . rawurlencode("flipkart $query"));
        if (!$r) return null;

        $html  = $r['html'];
        $xpath = $this->createXPath($html);
        $price = 0.0;
        $link  = $url;
        $title = '';

        // JSON-LD on search page (sometimes present for first result)
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $lms)) {
            foreach ($lms[1] as $lm) {
                $d = @json_decode(trim($lm), true);
                if (is_array($d)) {
                    $items = $d['@type'] === 'ItemList' ? ($d['itemListElement'] ?? []) : [$d];
                    foreach ($items as $item) {
                        $p = isset($item['item']) ? $item['item'] : $item;
                        if (isset($p['offers']['price'])) {
                            $price = floatval($p['offers']['price']);
                            $title = $p['name']  ?? '';
                            $link  = $p['offers']['url'] ?? ($p['url'] ?? $url);
                            break 2;
                        }
                    }
                }
            }
        }

        // XPath: product price in search list
        if ($price <= 0) {
            $priceText = $this->xpathFirst($xpath, [
                "//div[contains(@class,'_30jeq3') and contains(@class,'_16Jk6d')]",
                "//div[contains(@class,'Nx9bqj')]",
                "//div[contains(@class,'_30jeq3')]",
                "//div[contains(@class,'_1vC4OE')]",
            ]);
            $price = $this->cleanPrice($priceText);
        }

        // Product title
        if (empty($title)) {
            $title = $this->cleanText($this->xpathFirst($xpath, [
                "//div[contains(@class,'_4rR01T')]",
                "//a[contains(@class,'s1Q9rs')]",
                "//a[contains(@class,'KzDlHZ')]",
                "//a[contains(@class,'WKTcLC')]",
            ]));
        }

        // Product link
        if ($link === $url) {
            $hrefText = $this->xpathAttr($xpath, [
                "//a[contains(@class,'s1Q9rs')]/@href",
                "//a[contains(@class,'KzDlHZ')]/@href",
                "//a[contains(@class,'WKTcLC')]/@href",
            ]);
            if (!empty($hrefText)) {
                $link = (strpos($hrefText, 'http') === 0) ? $hrefText : 'https://www.flipkart.com' . $hrefText;
            }
        }

        // Regex fallback
        if ($price < 10 && preg_match('/"finalPrice"\s*:\s*([\d]+)/i', $html, $m)) {
            $price = floatval($m[1]);
        }

        // Reject obviously bogus prices (min ₹10)
        if ($price < 10) return null;
        error_log("[CrossPlatform/Flipkart] price=$price title=" . substr($title, 0, 40));
        return [
            'platform'     => 'Flipkart',
            'price'        => $price,
            'currency'     => 'INR',
            'availability' => 'In Stock',
            'link'         => $link,
        ];
    }

    // ------------------------------------------------------------------
    // Amazon India search
    // ------------------------------------------------------------------
    private function searchAmazon($query) {
        $url = 'https://www.amazon.in/s?k=' . rawurlencode($query);
        $r   = $this->fetchHTML($url, 'https://www.google.com/search?q=' . rawurlencode("amazon.in $query"));
        if (!$r) return null;

        $html  = $r['html'];
        $xpath = $this->createXPath($html);
        $price = 0.0;
        $link  = $url;

        // XPath: first search result price
        $priceText = $this->xpathFirst($xpath, [
            "//span[contains(@class,'a-price-whole')]",
            "//span[contains(@class,'a-offscreen')]",
        ]);
        $price = $this->cleanPrice($priceText);

        // Try to get link
        $hrefText = $this->xpathAttr($xpath, [
            "//h2[contains(@class,'a-text-normal')]//a/@href",
            "//h2/a/@href",
        ]);
        if (!empty($hrefText)) {
            $link = (strpos($hrefText, 'http') === 0) ? $hrefText : 'https://www.amazon.in' . $hrefText;
        }

        // Regex fallback
        if ($price < 10 && preg_match('/"priceAmount"\s*:\s*"([\d,]+)"/i', $html, $m)) {
            $price = floatval(str_replace(',', '', $m[1]));
        }

        // Reject obviously bogus prices (min ₹10)
        if ($price < 10) return null;
        error_log("[CrossPlatform/Amazon] price=$price");
        return [
            'platform'     => 'Amazon',
            'price'        => $price,
            'currency'     => 'INR',
            'availability' => 'In Stock',
            'link'         => $link,
        ];
    }

    // ------------------------------------------------------------------
    // Snapdeal search
    // ------------------------------------------------------------------
    private function searchSnapdeal($query) {
        $url = 'https://www.snapdeal.com/search?keyword=' . rawurlencode($query) . '&sort=rlvncy';
        $r   = $this->fetchHTML($url, 'https://www.google.com/search?q=' . rawurlencode("snapdeal $query"));
        if (!$r) return null;

        $html  = $r['html'];
        $xpath = $this->createXPath($html);
        $price = 0.0;
        $link  = $url;

        $priceText = $this->xpathFirst($xpath, [
            "//span[contains(@class,'product-price')]",
            "//p[contains(@class,'product-price')]",
            "//span[@class='payBlkBig']",
            "//span[contains(@class,'lfloat') and contains(@class,'product-price')]",
        ]);
        $price = $this->cleanPrice($priceText);

        $hrefText = $this->xpathAttr($xpath, [
            "//a[contains(@class,'dp-widget-link')]/@href",
            "//li[contains(@class,'product-tuple-listing')]//a/@href",
        ]);
        if (!empty($hrefText) && strpos($hrefText, 'http') === 0) {
            $link = $hrefText;
        }

        if ($price <= 0 && preg_match('/["\']\s*sellingPrice\s*["\']\s*:\s*["\']([\d,]+)["\']/', $html, $m)) {
            $price = floatval(str_replace(',', '', $m[1]));
        }

        if ($price <= 0) return null;
        error_log("[CrossPlatform/Snapdeal] price=$price");
        return [
            'platform'     => 'Snapdeal',
            'price'        => $price,
            'currency'     => 'INR',
            'availability' => 'In Stock',
            'link'         => $link,
        ];
    }

    // ------------------------------------------------------------------
    // Myntra search (fashion only – skip if product looks non-fashion)
    // ------------------------------------------------------------------
    private function searchMyntra($query) {
        // Myntra is fashion/lifestyle only; skip electronics, appliances and home goods
        $nonFashionWords = [
            // Electronics
            'phone', 'laptop', 'tablet', 'tv', 'camera', 'monitor',
            'processor', 'gpu', 'router', 'speaker', 'headphone',
            'samsung galaxy', 'iphone', 'oneplus', 'pixel', 'ipad', 'macbook',
            '5g', 'snapdragon', 'mediatek', 'charger', 'powerbank', 'earphone',
            // Home appliances
            'refrigerator', 'washing machine', 'air conditioner', 'microwave',
            'ac ', 'iron', 'dry iron', 'steam iron', 'press', 'mixer', 'grinder',
            'juicer', 'blender', 'toaster', 'kettle', 'coffee maker',
            'ceiling fan', 'exhaust fan', 'room heater', 'water purifier',
            'vacuum cleaner', 'air purifier', 'dishwasher', 'induction',
            // Brands not on Myntra
            'havells', 'philips', 'bosch', 'bajaj', 'prestige', 'butterfly',
            'crompton', 'orient', 'usha', 'whirlpool', 'lg ', 'samsung ',
            'godrej', 'voltas', 'hitachi', 'panasonic', 'sony ',
        ];
        $lower = strtolower($query);
        foreach ($nonFashionWords as $w) {
            if (strpos($lower, $w) !== false) return null;
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($query));
        $url  = 'https://www.myntra.com/' . trim($slug, '-');
        $r    = $this->fetchHTML($url, 'https://www.google.com/search?q=' . rawurlencode("myntra $query"));
        if (!$r) return null;

        $html  = $r['html'];
        $xpath = $this->createXPath($html);
        $price = 0.0;

        $priceText = $this->xpathFirst($xpath, [
            "//span[contains(@class,'product-discountedPrice')]",
            "//span[contains(@class,'pdp-price')]",
            "//div[contains(@class,'pdp-price')]",
        ]);
        $price = $this->cleanPrice($priceText);

        if ($price <= 0 && preg_match('/"discountedPrice"\s*:\s*([\d]+)/i', $html, $m)) {
            $price = floatval($m[1]);
        }
        // NOTE: broad ₹-regex fallback intentionally removed — it matched unrelated
        // page elements (e.g. ₹599 in promotional banners) for non-Myntra products.

        if ($price < 10) return null;
        error_log("[CrossPlatform/Myntra] price=$price");
        return [
            'platform'     => 'Myntra',
            'price'        => $price,
            'currency'     => 'INR',
            'availability' => 'In Stock',
            'link'         => $url,
        ];
    }

    // ------------------------------------------------------------------
    // Shorten a product name to the first 4-5 meaningful words
    // ------------------------------------------------------------------
    private function simplifyName($name) {
        // Strip parenthetical specs: (256GB, Cobalt Violet, ...)
        $name = preg_replace('/\s*\([^)]*\)/', '', $name);
        // Strip trailing words after "with"
        $name = preg_replace('/\s+with\s+.*/i', '', $name);
        // Take first 5 words
        $words = preg_split('/\s+/', trim($name));
        return implode(' ', array_slice($words, 0, 5));
    }
}
?>