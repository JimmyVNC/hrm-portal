<?php

/**
 * decrypt_uploads_for_vps.php
 *
 * Chạy script này trên máy LOCAL (nơi có APP_FILE_ENCRYPTION_KEY trong .env)
 * để giải mã tất cả file Excel trong uploads/ về dạng plaintext.
 *
 * Sau đó upload toàn bộ thư mục lên VPS FashPanel mà KHÔNG cần APP_FILE_ENCRYPTION_KEY.
 *
 * CÁCH DÙNG (chạy trên máy local):
 *   php scripts/decrypt_uploads_for_vps.php
 *
 * Sau khi chạy xong, upload các file trong uploads/ lên VPS.
 * Trên VPS: file .env KHÔNG cần có APP_FILE_ENCRYPTION_KEY.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden — chỉ chạy qua CLI.';
    exit(1);
}

require_once __DIR__ . '/../src/Infrastructure/bootstrap.php';

use App\Services\FileCrypto;

if (!FileCrypto::isEnabled()) {
    echo "[ERROR] APP_FILE_ENCRYPTION_KEY chưa được cấu hình. Không có gì để giải mã.\n";
    echo "        File trong uploads/ đang là plaintext — có thể upload thẳng lên VPS.\n";
    exit(0);
}

$root       = dirname(__DIR__);
$uploadsDir = $root . '/uploads/';
$backupsDir = $uploadsDir . 'backups/';

$patterns = [
    $uploadsDir . 'auth_*.xlsx',
    $uploadsDir . 'up_*.xlsx',
    $uploadsDir . 'up_*.csv',
    $backupsDir . 'auth_backup_*.xlsx',
];

$stats = ['checked' => 0, 'decrypted' => 0, 'already_plain' => 0, 'failed' => 0];

foreach ($patterns as $pattern) {
    foreach (glob($pattern) ?: [] as $path) {
        $stats['checked']++;
        $basename = basename($path);

        if (!FileCrypto::isEncryptedBinaryFile($path)) {
            echo "[SKIP]    {$basename} — đã là plaintext\n";
            $stats['already_plain']++;
            continue;
        }

        // Đọc và giải mã nội dung
        $plaintext = FileCrypto::readFileContents($path);
        if (!is_string($plaintext) || $plaintext === '') {
            echo "[FAILED]  {$basename} — không giải mã được\n";
            $stats['failed']++;
            continue;
        }

        // Ghi lại dưới dạng plaintext (overwrite trực tiếp)
        $written = @file_put_contents($path, $plaintext);
        if ($written === false || $written !== strlen($plaintext)) {
            echo "[FAILED]  {$basename} — ghi file thất bại\n";
            $stats['failed']++;
            continue;
        }

        echo "[OK]      {$basename} — đã giải mã ({$written} bytes)\n";
        $stats['decrypted']++;
    }
}

echo "\n--- Kết quả ---\n";
echo "Kiểm tra : {$stats['checked']} file\n";
echo "Đã giải mã: {$stats['decrypted']} file\n";
echo "Plaintext : {$stats['already_plain']} file\n";
echo "Thất bại  : {$stats['failed']} file\n";

if ($stats['failed'] > 0) {
    echo "\n[WARNING] Có file giải mã thất bại! Kiểm tra APP_FILE_ENCRYPTION_KEY.\n";
    exit(1);
}

echo "\n[DONE] Upload thư mục uploads/ lên VPS.\n";
echo "       Trên VPS, file .env KHÔNG cần có APP_FILE_ENCRYPTION_KEY.\n";
exit(0);
