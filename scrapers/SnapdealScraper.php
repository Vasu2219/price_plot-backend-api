<?php
// scrapers/SnapdealScraper.php
require_once __DIR__ . '/BaseScraper.php';

class SnapdealScraper extends BaseScraper {

    public function scrape($url) {
        error_log("[SnapdealScraper] Scraping: $url");

        $response = $this->fetchHTML($url, 'https://www.google.com/');
        if (!$response) {
            return ['success' => false, 'error' => 'Failed to load Snapdeal page'];
        }
        $html     = $response['html'];
        $finalUrl = $response['finalUrl'] ?? $url;

        $xpath = $this->createXPath($html);

        $priceText = $this->xpathFirst($xpath, [
            "//span[@class='payBlkBig']",
            "//span[contains(@class,'payBlkBig')]",
            "//span[@id='selling-price']",
            "//div[contains(@class,'price')]//span",
        ]);
        $price = $this->cleanPrice($priceText);

        if ($price <= 0 && preg_match('/"finalPrice"\s*:\s*"?([\d\.]+)"?/i', $html, $m)) {
            $price = floatval($m[1]);
        }

        $productName = $this->cleanText($this->xpathFirst($xpath, [
            "//h1[@class='pdp-e-i-head']",
            "//h1[contains(@class,'pdp-e-i-head')]",
            "//h1[@itemprop='name']",
        ]));

        $productImage = $this->xpathAttr($xpath, [
            "//img[@class='cloudzoom']/@src",
            "//img[contains(@class,'product-img')]/@src",
        ]);

        $success = $price > 0;
        error_log("[SnapdealScraper] success=$success price=$price name=" . substr($productName, 0, 40));

        if (!$success) {
            return ['success' => false, 'error' => 'Could not extract price from Snapdeal page'];
        }
        return [
            'success'      => true,
            'platform'     => 'Snapdeal',
            'price'        => $price,
            'currency'     => 'INR',
            'availability' => 'In Stock',
            'productName'  => $productName ?: 'Snapdeal Product',
            'productImage' => $productImage ?: null,
            'link'         => $finalUrl,
        ];
    }
}
?>