<?php
// scrapers/MyntraScraper.php
require_once __DIR__ . '/BaseScraper.php';

class MyntraScraper extends BaseScraper {

    public function scrape($url) {
        error_log("[MyntraScraper] Scraping: $url");

        $response = $this->fetchHTML($url, 'https://www.google.com/');
        if (!$response) {
            return ['success' => false, 'error' => 'Failed to load Myntra page'];
        }
        $html     = $response['html'];
        $finalUrl = $response['finalUrl'] ?? $url;

        $price        = 0;
        $productName  = '';
        $productImage = '';

        // --- 1) JSON-LD (Myntra includes structured data) ---
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $ldMs)) {
            foreach ($ldMs[1] as $jsonRaw) {
                $data = @json_decode(trim($jsonRaw), true);
                if (!$data) continue;
                $type = $data['@type'] ?? '';
                if (!in_array($type, ['Product', 'ItemPage'])) continue;
                if (empty($productName)) $productName = $data['name'] ?? '';
                if (isset($data['offers']['price'])) {
                    $price = floatval($data['offers']['price']);
                }
                $img = $data['image'] ?? '';
                if (empty($productImage)) {
                    $productImage = is_array($img) ? ($img[0] ?? '') : (string)$img;
                }
                if ($price > 0) break;
            }
        }

        // --- 2) XPath DOM ---
        if ($price <= 0) {
            $xpath     = $this->createXPath($html);
            $priceText = $this->xpathFirst($xpath, [
                "//span[contains(@class,'pdp-price')]//strong",
                "//span[contains(@class,'pdp-price')]",
                "//div[contains(@class,'pdp-price')]//strong",
            ]);
            $price = $this->cleanPrice($priceText);
        }

        // --- 3) Regex fallback ---
        if ($price <= 0 && preg_match('/"discountedPrice"\s*:\s*([\d]+)/i', $html, $m)) {
            $price = floatval($m[1]);
        }

        $success = $price > 0;
        error_log("[MyntraScraper] success=$success price=$price name=" . substr($productName, 0, 40));

        if (!$success) {
            return ['success' => false, 'error' => 'Could not extract price from Myntra page'];
        }
        return [
            'success'      => true,
            'platform'     => 'Myntra',
            'price'        => $price,
            'currency'     => 'INR',
            'availability' => 'In Stock',
            'productName'  => $this->cleanText($productName) ?: 'Myntra Product',
            'productImage' => $productImage ?: null,
            'link'         => $finalUrl,
        ];
    }
}
?>