<?php

require_once __DIR__ . '/../vendor/autoload.php';

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database as MongoDatabase;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;

class Database {
    private static $instance = null;
    private Client $client;
    private MongoDatabase $db;

    private function env(string $key, string $default = ''): string {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }

    private function __construct() {
        try {
            $mongoUri = $this->env('MONGODB_URI', 'mongodb://localhost:27017/price_plot');
            $dbName = $this->env('MONGODB_DB', 'price_plot');

            $this->client = new Client($mongoUri);
            $this->db = $this->client->selectDatabase($dbName);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'DB connection failed: ' . $e->getMessage()]);
            exit;
        }
    }

    public static function getConnection(): MongoDatabase {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->db;
    }

    public static function collection(string $name): Collection {
        return self::getConnection()->selectCollection($name);
    }

    public static function now(): UTCDateTime {
        return new UTCDateTime((int)(microtime(true) * 1000));
    }

    public static function toIsoString($value): ?string {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        return is_string($value) ? $value : null;
    }

    public static function docToArray($document): array {
        if ($document === null) {
            return [];
        }
        return json_decode(json_encode($document), true) ?? [];
    }

    public static function nextId(string $counterName): int {
        try {
            $counters = self::collection('counters');
            $result = $counters->findOneAndUpdate(
                ['_id' => $counterName],
                ['$inc' => ['seq' => 1]],
                [
                    'upsert' => true,
                    'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                ]
            );
            $data = self::docToArray($result);
            if (!empty($data['seq'])) {
                return (int)$data['seq'];
            }
        } catch (Throwable $e) {
        }

        $collectionName = self::collectionNameForCounter($counterName);
        $idField = self::idFieldForCollection($collectionName);
        $collection = self::collection($collectionName);

        $last = $collection->findOne([], [
            'sort' => [$idField => -1],
            'projection' => [$idField => 1],
        ]);
        $lastData = self::docToArray($last);
        $lastId = (int)($lastData[$idField] ?? 0);

        return $lastId + 1;
    }

    private static function collectionNameForCounter(string $counterName): string {
        $map = [
            'users' => 'users',
            'products' => 'products',
            'prices' => 'prices',
            'cart' => 'cart',
            'wishlist' => 'wishlist',
            'notifications' => 'notifications',
            'price_cache' => 'price_cache',
            'scrape_requests' => 'scrape_requests',
            'search_history' => 'search_history',
            'chat_history' => 'chat_history',
        ];

        return $map[$counterName] ?? $counterName;
    }

    private static function idFieldForCollection(string $collectionName): string {
        $map = [
            'users' => 'user_id',
            'products' => 'product_id',
            'prices' => 'price_id',
            'cart' => 'cart_id',
            'wishlist' => 'wishlist_id',
            'notifications' => 'notification_id',
            'price_cache' => 'cache_id',
            'scrape_requests' => 'request_id',
            'search_history' => 'history_id',
            'chat_history' => 'chat_id',
        ];

        return $map[$collectionName] ?? 'id';
    }
}
