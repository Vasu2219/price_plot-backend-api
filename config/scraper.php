<?php
// config/scraper.php — Node.js scraper endpoint config
// The Node.js Puppeteer scraper runs on localhost:3000
// Change SCRAPER_URL if the scraper is on a different host/port
define('SCRAPER_URL',     'http://localhost:3000/api/scrape');
define('SCRAPER_TIMEOUT', 90);   // seconds — Puppeteer scraping takes ~25-35s
define('CACHE_DURATION',  3600); // seconds — 1 hour cache
