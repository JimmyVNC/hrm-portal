<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

require __DIR__ . '/../src/Infrastructure/bootstrap.php';

use App\Config;
use App\Application\AdminFileManager;

$config = Config::loadConfig();
$result = AdminFileManager::migrateCurrentAuthPasswordsToHash($config);

if (!($result['ok'] ?? false)) {
    fwrite(STDERR, "[MIGRATE][ERROR] " . ($result['message'] ?? 'Unknown error') . PHP_EOL);
    exit(1);
}

echo "[MIGRATE][OK] " . ($result['message'] ?? 'Done') . PHP_EOL;
if (isset($result['hashed_count'])) {
    echo "[MIGRATE] hashed_count=" . (int) $result['hashed_count'] . PHP_EOL;
}
if (!empty($result['filename'])) {
    echo "[MIGRATE] new_file=" . $result['filename'] . PHP_EOL;
}
if (!empty($result['backup'])) {
    echo "[MIGRATE] backup_file=" . $result['backup'] . PHP_EOL;
}
