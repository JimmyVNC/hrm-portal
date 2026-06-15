<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

/**
 * Operational maintenance (CLI): prune runtime/cache JSON blobs.
 * Suggested cron (weekly): php scripts/maintenance.php
 */

require_once __DIR__ . '/../src/Infrastructure/bootstrap.php';

use App\Config;
use App\Services\CacheStore;

Config::loadEnvFile();

$maxAge = (int) Config::getEnvValue('RUNTIME_CACHE_MAX_FILE_AGE_SECONDS', 604800);
$maxBytes = (int) Config::getEnvValue('RUNTIME_CACHE_MAX_TOTAL_BYTES', 52428800);

$stats = CacheStore::prune($maxAge, $maxBytes);

echo json_encode(
    [
        'ok' => true,
        'prune' => $stats,
        'limits' => [
            'max_file_age_seconds' => $maxAge,
            'max_total_bytes' => $maxBytes,
        ],
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
) . PHP_EOL;
