<?php
chdir(__DIR__);
require_once 'BaseScraper.php';

class Probe2 extends BaseScraper {
    public function scrape($url) { return []; }
    
    protected function fetchJSON($url, $referer = 'https://flash.co/') {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/x-component, */*',
                'Accept-Language: en-IN,en;q=0.9',
                'Referer: ' . $referer,
                'Next-Router-State-Tree: %5B%22%22%2C%7B%22children%22%3A%5B%22__PAGE__%22%2C%7B%7D%5D%7D%2Cnull%2Cnull%2Ctrue%5D',
                'RSC: 1',
                'Next-Router-Prefetch: 1',
                'Sec-Fetch-Mode: cors',
                'Sec-Fetch-Site: same-origin',
                'Sec-Fetch-Dest: empty',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $furl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return ['body' => $body, 'code' => $code, 'url' => $furl];
    }

    public function run() {
        $query = rawurlencode('Samsung Galaxy S26 Ultra');
        
        // Try flash.co internal search API
        $endpoints = [
            "https://flash.co/api/search?q=$query",
            "https://flash.co/api/products/search?q=$query",
            "https://flash.co/search?q=$query",
            "https://flash.co/api/item/search?q=$query",
            "https://flash.co/api/compare?q=$query",
        ];
        
        foreach ($endpoints as $ep) {
            echo "\nTrying: $ep\n";
            $r = $this->fetchJSON($ep);
            echo "  Code: {$r['code']}, Length: " . strlen($r['body']) . "\n";
            if ($r['code'] == 200 && strlen($r['body']) > 100) {
                $j = @json_decode($r['body'], true);
                if ($j) {
                    echo "  JSON FOUND!\n";
                    echo substr(json_encode($j, JSON_PRETTY_PRINT), 0, 2000);
                    break;
                } else {
                    echo "  Not JSON. First 300: " . substr($r['body'], 0, 300) . "\n";
                }
            }
        }
        
        // Also check for __NEXT_DATA__ script in search page
        echo "\n\n--- Checking __NEXT_DATA__ ---\n";
        $r3 = $this->fetchHTML('https://flash.co/search?q=' . rawurlencode('Samsung Galaxy S26 Ultra'), 'https://google.com/');
        if ($r3) {
            $html = $r3['html'];
            if (preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $nd)) {
                $nd_data = @json_decode($nd[1], true);
                echo "NEXT_DATA keys: " . implode(', ', array_keys($nd_data ?? [])) . "\n";
                if (isset($nd_data['props']['pageProps'])) {
                    echo substr(json_encode($nd_data['props']['pageProps'], JSON_PRETTY_PRINT), 0, 3000);
                }
            } else {
                echo "No __NEXT_DATA__ found\n";
                // Try to find any JSON in script tags
                preg_match_all('/<script[^>]*>(\{[^<]{50,})\}<\/script>/i', $html, $scripts);
                echo "Script JSON blocks: " . count($scripts[1]) . "\n";
                foreach (array_slice($scripts[1], 0, 3) as $sc) {
                    echo "  " . substr($sc, 0, 200) . "...\n";
                }
            }
        }
    }
}
(new Probe2())->run();
?>