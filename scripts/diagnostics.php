<?php

/**
 * Diagnostics Panel — diagnostics.php
 * Refactored to use PSR-4 Autoloader and Static Classes.
 */

require_once __DIR__ . '/../src/Infrastructure/bootstrap.php';

// 2. Sử dụng các Class từ namespace App
use App\AppMetadata;
use App\Config;
use App\Security;
use App\Services\FileCrypto;

// 3. Khởi tạo môi trường
Config::startSecureSession();
Security::applySecurityHeaders();

// Kiểm tra quyền Admin
if (empty($_SESSION['hr_admin'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$config = Config::loadConfig();
$uploadDir = Config::uploadsDir();
$shareDir = Config::projectRoot() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'share';
$uploadFiles = array_merge(
    glob(rtrim($uploadDir, DIRECTORY_SEPARATOR) . '/auth_*.xlsx') ?: [],
    glob(rtrim($uploadDir, DIRECTORY_SEPARATOR) . '/up_*.xlsx') ?: [],
    glob(rtrim($uploadDir, DIRECTORY_SEPARATOR) . '/up_*.csv') ?: [],
    glob(rtrim($uploadDir, DIRECTORY_SEPARATOR) . '/backups/auth_backup_*.xlsx') ?: []
);
$encryptedUploads = 0;
foreach ($uploadFiles as $path) {
    if (FileCrypto::isEncryptedBinaryFile($path)) {
        $encryptedUploads++;
    }
}
$shareFiles = glob($shareDir . '/payroll_*.json') ?: [];
$encryptedShares = 0;
foreach ($shareFiles as $path) {
    $raw = @file_get_contents($path);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($json) && !empty($json['__hrm_enc'])) {
        $encryptedShares++;
    }
}
$runtime = [
    'app' => AppMetadata::NAME,
    'app_version' => AppMetadata::VERSION,
    'php_version' => PHP_VERSION,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    'request_id' => Security::getRequestId(),
    'auth_max_rows' => (int) Config::getEnvValue('AUTH_MAX_ROWS', 50000),
    'auth_max_cols' => (int) Config::getEnvValue('AUTH_MAX_COLS', 500),
    'period_max_rows' => (int) Config::getEnvValue('PERIOD_MAX_ROWS', 50000),
    'period_max_cols' => (int) Config::getEnvValue('PERIOD_MAX_COLS', 1000),
    'google_cache_ttl' => (int) Config::getEnvValue('GOOGLE_CACHE_TTL', 60),
    'upload_writable' => is_dir(Config::uploadsDir()) && is_writable(Config::uploadsDir()),
    'period_count' => count($config['periods'] ?? []),
    'file_encryption' => [
        'enabled' => FileCrypto::isEnabled(),
        'uploads_encrypted' => $encryptedUploads,
        'uploads_total' => count($uploadFiles),
        'share_encrypted' => $encryptedShares,
        'share_total' => count($shareFiles),
    ],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Diagnostics</title>
  <style>body{font-family:Inter,system-ui,sans-serif;padding:32px;background:#f8fafc;color:#1e293b}pre{background:#fff;padding:24px;border:1px solid #e2e8f0;border-radius:12px;overflow:auto;box-shadow:0 4px 6px -1px rgb(0 0 0 / 0.1)}h1{font-weight:800;letter-spacing:-0.025em}</style>
</head>
<body>
  <h1>System Diagnostics</h1>
  <p>Chỉ hiển thị các thông số vận hành không nhạy cảm.</p>
  <pre><?= htmlspecialchars(json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
  <p><a href="admin.php" style="color:#2563eb;text-decoration:none;font-weight:600">← Quay lại Admin</a></p>
</body>
</html>
