<?php
// scrapers/BaseScraper.php
abstract class BaseScraper {

    protected $userAgent =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' .
        'AppleWebKit/537.36 (KHTML, like Gecko) ' .
        'Chrome/124.0.6367.202 Safari/537.36';

    // Returns ['html' => string, 'finalUrl' => string] or false on failure.
    protected function fetchHTML($url, $referer = 'https://www.google.com/') {
        $cookieFile = tempnam(sys_get_temp_dir(), 'scraper_cookie_');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_ENCODING       => '',
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: en-IN,en-GB;q=0.9,en;q=0.8',
                'Accept-Encoding: gzip, deflate, br',
                'Cache-Control: max-age=0',
                'Connection: keep-alive',
                'Referer: ' . $referer,
                'Sec-Ch-Ua: "Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
                'Sec-Ch-Ua-Mobile: ?0',
                'Sec-Ch-Ua-Platform: "Windows"',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-User: ?1',
                'Upgrade-Insecure-Requests: 1',
                'DNT: 1',
            ],
        ]);

        $html     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error    = curl_error($ch);
        curl_close($ch);

        if (file_exists($cookieFile)) { @unlink($cookieFile); }

        if ($error) {
            error_log("[BaseScraper] cURL error for $url: $error");
            return false;
        }
        if ($httpCode < 200 || $httpCode >= 400) {
            error_log("[BaseScraper] HTTP $httpCode for $url");
            return false;
        }

        return ['html' => $html, 'finalUrl' => $finalUrl];
    }

    protected function createXPath($html) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
        return new DOMXPath($dom);
    }

    // Try a list of XPath expressions; return textContent of first match
    protected function xpathFirst(DOMXPath $xpath, array $selectors) {
        foreach ($selectors as $sel) {
            $nodes = $xpath->query($sel);
            if ($nodes && $nodes->length > 0) {
                return $nodes->item(0)->textContent;
            }
        }
        return '';
    }

    // Same as xpathFirst but for attribute-node selectors (e.g. //img/@src)
    protected function xpathAttr(DOMXPath $xpath, array $selectors) {
        foreach ($selectors as $sel) {
            $nodes = $xpath->query($sel);
            if ($nodes && $nodes->length > 0) {
                return $nodes->item(0)->textContent;
            }
        }
        return '';
    }

    protected function cleanPrice($text) {
        $cleaned = preg_replace('/[^\d.]/', '', $text);
        return floatval($cleaned);
    }

    protected function cleanText($text) {
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    abstract public function scrape($url);
}
?>