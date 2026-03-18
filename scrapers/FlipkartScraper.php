<?php
// scrapers/FlipkartScraper.php
require_once __DIR__ . '/BaseScraper.php';

class FlipkartScraper extends BaseScraper {

    public function scrape($url) {
        error_log("[FlipkartScraper] Scraping: $url");

        $response = $this->fetchHTML($url, 'https://www.google.com/search?q=flipkart');
        if (!$response) {
            return ['success' => false, 'error' => 'Failed to load Flipkart page'];
        }
        $html     = $response['html'];
        $finalUrl = $response['finalUrl'] ?? $url;

        $price        = 0;
        $productName  = '';
        $productImage = '';
        $availability = 'In Stock';

        // --- 1) JSON-LD ---
        if (preg_match('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $lm)) {
            $data = @json_decode(trim($lm[1]), true);
            if ($data) {
                $productName = $this->cleanText($data['name'] ?? '');
                if (isset($data['offers']['price'])) {
                    $price = floatval($data['offers']['price']);
                }
                $img = $data['image'] ?? '';
                $productImage = is_array($img) ? ($img[0] ?? '') : (string)$img;
            }
        }

        $xpath = $this->createXPath($html);

        // --- 2) XPath price ---
        if ($price <= 0) {
            $priceText = $this->xpathFirst($xpath, [
                "//div[contains(@class,'_30jeq3') and contains(@class,'_16Jk6d')]",
                "//div[contains(@class,'_30jeq3')]",
                "//div[contains(@class,'Nx9bqj')]",
                "//div[contains(@class,'_1vC4OE')]",
                "//div[contains(@class,'_4b5DiR')]",
                "//span[@class='_2-ut7t']",
            ]);
            $price = $this->cleanPrice($priceText);
        }

        // --- 3) Regex fallback ---
        if ($price <= 0 && preg_match('/"finalPrice"\s*:\s*([\d]+)/i', $html, $m)) {
            $price = floatval($m[1]);
        }

        // --- 4) Product name ---
        if (empty($productName)) {
            $productName = $this->cleanText($this->xpathFirst($xpath, [
                "//span[@class='B_NuCI']",
                "//h1[contains(@class,'yhB1nd')]",
                "//span[contains(@class,'VU-ZEz')]",
                "//h1[contains(@class,'G6XhRU')]",
            ]));
        }

        // --- 5) Product image ---
        if (empty($productImage)) {
            $productImage = $this->xpathAttr($xpath, [
                "//img[contains(@class,'_396cs4')]/@src",
                "//img[contains(@class,'DByuf4')]/@src",
                "//div[contains(@class,'_1AtVbE')]//img/@src",
            ]);
        }

        // --- 6) Availability ---
        $availText = $this->cleanText($this->xpathFirst($xpath, [
            "//div[contains(@class,'_16FRp0')]",
            "//div[contains(@class,'Z8JjpR')]",
        ]));
        if (!empty($availText)) { $availability = $availText; }

        $success = $price > 0;
        error_log("[FlipkartScraper] success=$success price=$price name=" . substr($productName, 0, 40));

        if (!$success) {
            return ['success' => false, 'error' => 'Could not extract price from Flipkart page'];
        }
        return [
            'success'      => true,
            'platform'     => 'Flipkart',
            'price'        => $price,
            'currency'     => 'INR',
            'availability' => $availability,
            'productName'  => $productName ?: 'Flipkart Product',
            'productImage' => $productImage ?: null,
            'link'         => $finalUrl,
        ];
    }
}
?>