<?php
chdir(__DIR__);
require_once 'BaseScraper.php';
class Probe3 extends BaseScraper { public function scrape($u){return [];} 
 public function run() {
  // Try ASIN-based item URL
  $tries = [
   'https://flash.co/item/B0GL8G49LV',
   'https://flash.co/item/samsung-galaxy-s26-ultra-5g',
   'https://flash.co/item/samsung-galaxy-s26-ultra',
  ];
  foreach ($tries as $url) {
   $r = $this->fetchHTML($url, 'https://flash.co/');
   if (!$r) { echo "FAIL: $url\n"; continue; }
   echo "OK ($url) len=" . strlen($r['html']) . " final=" . $r['finalUrl'] . "\n";
   preg_match_all('/self\.__next_f\.push\(\[1,"(.*?)"\]\)/s', $r['html'], $m);
   $rsc = '';
   foreach ($m[1] as $ch) $rsc .= stripcslashes($ch);
   echo "RSC len=" . strlen($rsc) . "\n";
   // Check if it has actual product data
   if (preg_match('/"(?:price|storeName|seller|aiScore|score)"/', $rsc)) {
    echo "HAS PRODUCT DATA!\n";
    // Find stores/prices
    preg_match_all('/"(?:storeName|seller|source)"\s*:\s*"([^"]+)"/i', $rsc, $sm);
    foreach (array_slice($sm[1],0,10) as $s) echo "  store: $s\n";
    preg_match_all('/"price"\s*:\s*([\d"]+)/i', $rsc, $pm);
    foreach (array_slice($pm[1],0,10) as $p) echo "  price: $p\n";
   } else {
    echo "No product data. RSC snippet: " . substr($rsc,0,500) . "\n";
   }
   echo "\n";
  }
  // Try flash.co's sitemap or robots to understand URL structure  
  echo "--- robots.txt ---\n";
  $r = $this->fetchHTML('https://flash.co/robots.txt', 'https://flash.co/');
  if ($r) echo substr($r['html'], 0, 500) . "\n";
  
  // Try flash.co's JS chunks to find API endpoints
  echo "\n--- _buildManifest ---\n";
  $r = $this->fetchHTML('https://flash.co/_next/static/chunks/pages/_buildManifest.js', 'https://flash.co/');
  if ($r && strlen($r['html']) < 5000) echo $r['html'];
  else echo "too big or missing\n";
 }
}
(new Probe3())->run();
?>