<?php
require_once __DIR__ . '/../config/database.php';

function normalizePriceValue($value): float {
    return round((float)$value, 2);
}

function hasPriceChanged(PDO $db, int $productId, string $platform, float $price, string $currency, string $availability): bool {
    $stmt = $db->prepare(
        'SELECT price, currency, availability
         FROM prices
         WHERE product_id = ? AND platform = ?
         ORDER BY scraped_at DESC
         LIMIT 1'
    );
    $stmt->execute([$productId, $platform]);
    $last = $stmt->fetch();

    if (!$last) {
        return true;
    }

    $lastPrice = normalizePriceValue($last['price']);
    $newPrice  = normalizePriceValue($price);

    return !(
        $lastPrice === $newPrice &&
        (string)$last['currency'] === (string)$currency &&
        (string)$last['availability'] === (string)$availability
    );
}

function insertPriceIfChanged(
    PDO $db,
    int $productId,
    string $platform,
    float $price,
    string $currency = 'INR',
    string $availability = 'In Stock',
    string $productLink = ''
): bool {
    $platform = trim($platform);
    if ($productId <= 0 || $platform === '' || $price <= 0) {
        return false;
    }

    $currency     = trim($currency) !== '' ? trim($currency) : 'INR';
    $availability = trim($availability) !== '' ? trim($availability) : 'In Stock';

    if (!hasPriceChanged($db, $productId, $platform, $price, $currency, $availability)) {
        return false;
    }

    $stmt = $db->prepare(
        'INSERT INTO prices (product_id, platform, price, currency, availability, product_link)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $productId,
        $platform,
        normalizePriceValue($price),
        $currency,
        $availability,
        trim($productLink),
    ]);

    return true;
}