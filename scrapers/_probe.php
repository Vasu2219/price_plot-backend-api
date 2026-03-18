<?php
chdir(__DIR__);
require_once 'BaseScraper.php';

class Probe extends BaseScraper {
    public function scrape($url) { return []; }
    public function run() {
        // Step 1: search flash.co for "Samsung Galaxy S26 Ultra"
        $r = $this->fetchHTML('https://flash.co/search?q=Samsung+Galaxy+S26+Ultra+5G', 'https://flash.co/');
        if (!$r) { echo "FETCH FAILED\n"; return; }
        $html = $r['html'];
        $finalUrl = $r['finalUrl'];
        echo "SEARCH URL: $finalUrl\n";
        echo "HTML length: " . strlen($html) . "\n";

        // Find item links
        preg_match_all('/href="(\/item\/[^"?#]+)"/i', $html, $m);
        $links = array_values(array_unique($m[1]));
        echo "Item links found: " . count($links) . "\n";
        foreach (array_slice($links, 0, 3) as $l) echo "  $l\n";

        if (empty($links)) {
            // Dump 4000 chars of body for diagnosis
            echo "\n--- SEARCH HTML SNIPPET ---\n";
            echo substr(strip_tags($html), 0, 3000);
            
            // Also look for RSC data
            preg_match_all('/self\.__next_f\.push\(\[1,"(.*?)"\]\)/s', $html, $rsm);
            $rsc = '';
            foreach ($rsm[1] as $chunk) $rsc .= stripcslashes($chunk);
            echo "\n--- RSC length: " . strlen($rsc) . " ---\n";
            // Print first 3000 chars of RSC
            echo substr($rsc, 0, 3000);
            return;
        }

        // Step 2: fetch the first item page
        $itemUrl = 'https://flash.co' . $links[0];
        echo "\nFetching item: $itemUrl\n";
        $r2 = $this->fetchHTML($itemUrl, 'https://flash.co/search?q=Samsung+Galaxy+S26+Ultra+5G');
        if (!$r2) { echo "ITEM FETCH FAILED\n"; return; }
        $html2 = $r2['html'];
        echo "Item HTML length: " . strlen($html2) . "\n";

        // Extract RSC
        preg_match_all('/self\.__next_f\.push\(\[1,"(.*?)"\]\)/s', $html2, $rsm2);
        $rsc2 = '';
        foreach ($rsm2[1] as $chunk) $rsc2 .= stripcslashes($chunk);
        echo "RSC length: " . strlen($rsc2) . "\n";

        // Look for price patterns
        preg_match_all('/"(?:price|salePrice|sellingPrice|finalPrice|offerPrice)"\s*:\s*[\d"]+/i', $rsc2, $pm);
        echo "\n--- Price patterns in RSC ---\n";
        foreach (array_slice($pm[0], 0, 20) as $p) echo "  $p\n";

        // Look for store name patterns
        preg_match_all('/"(?:storeName|seller|platform|source|site|merchant)"\s*:\s*"[^"]+"/i', $rsc2, $sm);
        echo "\n--- Store patterns in RSC ---\n";
        foreach (array_slice($sm[0], 0, 20) as $s) echo "  $s\n";

        // Look for aiScore / score
        preg_match_all('/"(?:aiScore|score|rating|ai_score|aiRating)"\s*:\s*[\d"]+/i', $rsc2, $aim);
        echo "\n--- AI/Score patterns ---\n";
        foreach ($aim[0] as $a) echo "  $a\n";

        // Dump interesting RSC chunk (500 chars around "price")
        $pos = strpos($rsc2, '"price"');
        if ($pos !== false) {
            echo "\n--- Context around first 'price' (RSC[" . $pos . "]) ---\n";
            echo substr($rsc2, max(0, $pos - 200), 1000);
        }
    }
}
(new Probe())->run();
?>