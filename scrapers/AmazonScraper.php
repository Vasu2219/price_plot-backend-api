<?php
// scrapers/AmazonScraper.php
require_once __DIR__ . '/BaseScraper.php';

class AmazonScraper extends BaseScraper {

    public function scrape($url) {
        error_log("[AmazonScraper] Scraping: $url");

        $response = $this->fetchHTML($url, 'https://www.google.com/search?q=amazon+india');
        if (!$response) {
            return ['success' => false, 'error' => 'Failed to load Amazon page'];
        }
        $html     = $response['html'];
        $finalUrl = $response['finalUrl'] ?? $url;

        $price        = 0;
        $productName  = '';
        $productImage = '';
        $availability = 'In Stock';

        // --- 1) JSON-LD structured data (most reliable) ---
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $ldMatches)) {
            foreach ($ldMatches[1] as $jsonRaw) {
                $jsonRaw = trim(ltrim($jsonRaw, "\xEF\xBB\xBF"));
                $data    = @json_decode($jsonRaw, true);
                if (!$data) continue;

                $items = isset($data['@graph']) ? $data['@graph'] : [$data];
                foreach ($items as $item) {
                    $type = $item['@type'] ?? '';
                    if (!in_array($type, ['Product', 'ItemPage'])) continue;

                    if (empty($productName) && !empty($item['name'])) {
                        $productName = $this->cleanText($item['name']);
                    }
                    if (empty($productImage)) {
                        $img = $item['image'] ?? '';
                        if (is_array($img)) $img = $img[0] ?? '';
                        if (is_array($img)) $img = $img['url'] ?? '';
                        $productImage = (string)$img;
                    }
                    if ($price <= 0 && isset($item['offers'])) {
                        $offers = $item['offers'];
                        if (isset($offers['price'])) {
                            $price = floatval($offers['price']);
                        } elseif (isset($offers['lowPrice'])) {
                            $price = floatval($offers['lowPrice']);
                        } elseif (is_array($offers)) {
                            foreach ($offers as $offer) {
                                if (!empty($offer['price'])) { $price = floatval($offer['price']); break; }
                            }
                        }
                        $avail = $offers['availability'] ?? ($offers[0]['availability'] ?? '');
                        if ($avail) {
                            $availability = str_replace(['https://schema.org/', 'http://schema.org/'], '', $avail);
                        }
                    }
                    if ($price > 0 && $productName) break;
                }
                if ($price > 0) break;
            }
        }

        // --- 2) XPath DOM selectors ---
        $xpath = $this->createXPath($html);

        if ($price <= 0) {
            $priceText = $this->xpathFirst($xpath, [
                "//span[@id='priceblock_ourprice']",
                "//span[@id='priceblock_dealprice']",
                "//span[@id='priceblock_saleprice']",
                "//div[@id='corePrice_feature_div']//span[contains(@class,'a-price-whole')]",
                "//div[@id='apex_desktop_ptformats_feature_div']//span[contains(@class,'a-price-whole')]",
                "//div[@id='apex_desktop']//span[contains(@class,'a-price-whole')]",
                "//span[@id='price_inside_buybox']",
                "//div[@id='buyBoxAccordion']//span[contains(@class,'a-price-whole')]",
                "//span[contains(@class,'a-price-whole')]",
            ]);
            $price = $this->cleanPrice($priceText);
        }

        // --- 3) Regex fallback ---
        if ($price <= 0 && preg_match('/"price"\s*:\s*"?([\d,\.]+)"?/i', $html, $m)) {
            $price = $this->cleanPrice($m[1]);
        }
        if ($price <= 0 && preg_match('/"priceAmount"\s*:\s*([\d\.]+)/i', $html, $m)) {
            $price = floatval($m[1]);
        }
        if ($price <= 0 && preg_match('/\x{20B9}\s*([\d,\.]+)/u', $html, $m)) {
            $price = $this->cleanPrice($m[1]);
        }

        // --- 4) Product name fallback ---
        if (empty($productName)) {
            $productName = $this->cleanText($this->xpathFirst($xpath, [
                "//span[@id='productTitle']",
                "//h1[@id='title']//span",
                "//h1[contains(@class,'product-title')]",
            ]));
        }

        // --- 5) Product image fallback ---
        if (empty($productImage)) {
            $imgNodes = $xpath->query("//img[@id='landingImage']");
            if ($imgNodes && $imgNodes->length > 0) {
                $imgEl = $imgNodes->item(0);
                $productImage = $imgEl->getAttribute('data-old-hires') ?: $imgEl->getAttribute('src');
            }
        }
        if (empty($productImage)) {
            $imgNodes = $xpath->query("//img[@id='imgBlkFront']");
            if ($imgNodes && $imgNodes->length > 0) {
                $productImage = $imgNodes->item(0)->getAttribute('src');
            }
        }
        if (empty($productImage) && preg_match('/"hiRes":"(https:[^"]+)"/i', $html, $m)) {
            $productImage = $m[1];
        }
        if (empty($productImage) && preg_match('/"large":"(https:[^"]+images-amazon[^"]+)"/i', $html, $m)) {
            $productImage = $m[1];
        }

        // --- 6) Availability ---
        $availNode = $this->cleanText($this->xpathFirst($xpath, [
            "//div[@id='availability']//span",
            "//span[@id='availability-brief']",
        ]));
        if (!empty($availNode)) { $availability = $availNode; }

        $success = $price > 0;
        error_log("[AmazonScraper] success=$success price=$price name=" . substr($productName, 0, 50));

        if (!$success) {
            return ['success' => false, 'error' => 'Could not extract price from Amazon page (possible bot-check)'];
        }
        return [
            'success'      => true,
            'platform'     => 'Amazon',
            'price'        => $price,
            'currency'     => 'INR',
            'availability' => $availability,
            'productName'  => $productName  ?: 'Amazon Product',
            'productImage' => $productImage ?: null,
            'link'         => $finalUrl,
        ];
    }
}
?>