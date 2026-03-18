<?php
chdir(__DIR__);
require_once 'BaseScraper.php';
require_once 'FlashCoScraper.php';

$s = new FlashCoScraper();
$r = $s->scrape('https://amzn.in/d/008aTv3c');
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>