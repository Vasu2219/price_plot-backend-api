<?php
require_once __DIR__ . '/../config/database.php';

function normalizePriceValue($value): float {
    return round((float)$value, 2);
}

function hasPriceChanged(int $productId, string $platform, float $price, string $currency, string $availability): bool {
    $prices = Database::collection('prices');
    $last = $prices->findOne(
        [
            'product_id' => $productId,
            'platform' => $platform,
        ],
        [
            'sort' => ['scraped_at' => -1],
        ]
    );
    $last = Database::docToArray($last);

    if (empty($last)) {
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

    if (!hasPriceChanged($productId, $platform, $price, $currency, $availability)) {
        return false;
    }

    $prices = Database::collection('prices');
    $prices->insertOne([
        'price_id' => Database::nextId('prices'),
        'product_id' => $productId,
        'platform' => $platform,
        'price' => normalizePriceValue($price),
        'currency' => $currency,
        'availability' => $availability,
        'product_link' => trim($productLink),
        'scraped_at' => Database::now(),
    ]);

    return true;
}