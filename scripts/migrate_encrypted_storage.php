<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

require_once __DIR__ . '/../src/Infrastructure/bootstrap.php';

use App\Services\FileCrypto;

$dryRun = in_array('--dry-run', $argv, true);

if (!FileCrypto::isEnabled()) {
    fwrite(STDERR, "APP_FILE_ENCRYPTION_KEY is missing or invalid.\n");
    exit(1);
}

$root = dirname(__DIR__);
$uploadsDir = $root . '/uploads';
$backupsDir = $uploadsDir . '/backups';
$shareDir = $root . '/runtime/share';

$stats = [
    'dry_run' => $dryRun,
    'uploads' => ['checked' => 0, 'encrypted' => 0, 'already_encrypted' => 0, 'skipped' => 0, 'failed' => 0],
    'share' => ['checked' => 0, 'encrypted' => 0, 'already_encrypted' => 0, 'skipped' => 0, 'failed' => 0],
    'errors' => [],
];

$uploadPatterns = [
    $uploadsDir . '/auth_*.xlsx',
    $uploadsDir . '/up_*.xlsx',
    $uploadsDir . '/up_*.csv',
    $backupsDir . '/auth_backup_*.xlsx',
];

foreach ($uploadPatterns as $pattern) {
    foreach (glob($pattern) ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $stats['uploads']['checked']++;
        if (FileCrypto::isEncryptedBinaryFile($path)) {
            $stats['uploads']['already_encrypted']++;
            continue;
        }
        if ($dryRun) {
            $stats['uploads']['encrypted']++;
            continue;
        }
        if (FileCrypto::encryptFileInPlace($path)) {
            $stats['uploads']['encrypted']++;
            continue;
        }
        $stats['uploads']['failed']++;
        $stats['errors'][] = 'upload:' . basename($path);
    }
}

foreach (glob($shareDir . '/payroll_*.json') ?: [] as $path) {
    if (!is_file($path)) {
        continue;
    }
    $stats['share']['checked']++;

    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded) && !empty($decoded['__hrm_enc'])) {
        $stats['share']['already_encrypted']++;
        continue;
    }

    $read = FileCrypto::readJsonFile($path);
    if (!$read['ok'] || !is_array($read['data'] ?? null)) {
        $stats['share']['failed']++;
        $stats['errors'][] = 'share:' . basename($path) . ':' . (string) ($read['error'] ?? 'read_failed');
        continue;
    }

    if ($dryRun) {
        $stats['share']['encrypted']++;
        continue;
    }

    if (FileCrypto::writeJsonFile($path, $read['data'])) {
        $stats['share']['encrypted']++;
        continue;
    }

    $stats['share']['failed']++;
    $stats['errors'][] = 'share:' . basename($path) . ':write_failed';
}

echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
