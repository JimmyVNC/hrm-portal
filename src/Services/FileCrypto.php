<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;

final class FileCrypto
{
    private const ENVELOPE_FLAG = '__hrm_enc';
    private const ENVELOPE_VERSION = 1;
    private const CIPHER = 'aes-256-gcm';
    private const KEY_ENV = 'APP_FILE_ENCRYPTION_KEY';
    private const BLOB_MAGIC = "HRMENC1\0";

    private function __construct()
    {
    }

    public static function isEnabled(): bool
    {
        return self::resolveKey() !== null;
    }

    public static function isEncryptedBinaryFile(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $header = self::readPrefix($path, strlen(self::BLOB_MAGIC));
        return $header === self::BLOB_MAGIC;
    }

    /**
     * @return array{ok: bool, encrypted: bool, error: string|null, data: array|null}
     */
    public static function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'encrypted' => false, 'error' => 'file_missing', 'data' => null];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return ['ok' => false, 'encrypted' => false, 'error' => 'read_failed', 'data' => null];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'encrypted' => false, 'error' => 'invalid_json', 'data' => null];
        }

        if (!self::isEncryptedEnvelope($decoded)) {
            return ['ok' => true, 'encrypted' => false, 'error' => null, 'data' => $decoded];
        }

        $key = self::resolveKey();
        if ($key === null) {
            return ['ok' => false, 'encrypted' => true, 'error' => 'key_unavailable', 'data' => null];
        }

        $iv = base64_decode((string) ($decoded['iv'] ?? ''), true);
        $tag = base64_decode((string) ($decoded['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($decoded['data'] ?? ''), true);
        if (!is_string($iv) || !is_string($tag) || !is_string($ciphertext) || $iv === '' || $tag === '') {
            return ['ok' => false, 'encrypted' => true, 'error' => 'invalid_envelope', 'data' => null];
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($plaintext) || $plaintext === '') {
            return ['ok' => false, 'encrypted' => true, 'error' => 'decrypt_failed', 'data' => null];
        }

        $payload = json_decode($plaintext, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'encrypted' => true, 'error' => 'invalid_payload', 'data' => null];
        }

        return ['ok' => true, 'encrypted' => true, 'error' => null, 'data' => $payload];
    }

    public static function writeJsonFile(string $path, array $payload): bool
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return false;
        }

        $key = self::resolveKey();
        if ($key !== null) {
            $ivLength = openssl_cipher_iv_length(self::CIPHER);
            if (!is_int($ivLength) || $ivLength <= 0) {
                return false;
            }

            $iv = random_bytes($ivLength);
            $tag = '';
            $ciphertext = openssl_encrypt($encoded, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
            if (!is_string($ciphertext) || $ciphertext === '' || $tag === '') {
                return false;
            }

            $encodedEnvelope = json_encode([
                self::ENVELOPE_FLAG => true,
                'v' => self::ENVELOPE_VERSION,
                'alg' => self::CIPHER,
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'data' => base64_encode($ciphertext),
            ], JSON_UNESCAPED_UNICODE);
            if (!is_string($encodedEnvelope)) {
                return false;
            }
            $encoded = $encodedEnvelope;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }

        $tmpFile = tempnam($dir, 'enc_');
        if ($tmpFile === false) {
            return false;
        }

        $result = false;
        $fp = @fopen($tmpFile, 'wb');
        if ($fp !== false) {
            if (@flock($fp, LOCK_EX)) {
                $written = @fwrite($fp, $encoded);
                if ($written === strlen($encoded)) {
                    @fflush($fp);
                    $result = true;
                }
                @flock($fp, LOCK_UN);
            }
            @fclose($fp);
        }

        if (!$result) {
            @unlink($tmpFile);
            return false;
        }

        @chmod($tmpFile, 0600);
        if (!@rename($tmpFile, $path)) {
            @unlink($tmpFile);
            return false;
        }

        @chmod($path, 0600);
        return true;
    }

    public static function encryptFileInPlace(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        if (self::isEncryptedBinaryFile($path)) {
            @chmod($path, 0600);
            return true;
        }

        $key = self::resolveKey();
        if ($key === null) {
            @chmod($path, 0600);
            return true;
        }

        $plaintext = @file_get_contents($path);
        if (!is_string($plaintext)) {
            return false;
        }

        $encrypted = self::encryptBinaryPayload($plaintext, $key);
        if ($encrypted === null) {
            return false;
        }

        return self::atomicWrite($path, $encrypted);
    }

    public static function storeUploadedFile(string $sourcePath, string $destinationPath): bool
    {
        if (!is_file($sourcePath)) {
            return false;
        }

        $dir = dirname($destinationPath);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }

        if (!@rename($sourcePath, $destinationPath)) {
            if (!@copy($sourcePath, $destinationPath)) {
                return false;
            }
            @unlink($sourcePath);
        }

        return self::encryptFileInPlace($destinationPath);
    }

    public static function copyFile(string $sourcePath, string $destinationPath): bool
    {
        $bytes = @file_get_contents($sourcePath);
        if (!is_string($bytes)) {
            return false;
        }
        return self::atomicWrite($destinationPath, $bytes);
    }

    public static function readFileContents(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        if (!self::isEncryptedBinaryFile($path)) {
            $raw = @file_get_contents($path);
            return is_string($raw) ? $raw : null;
        }

        $key = self::resolveKey();
        if ($key === null) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || strlen($raw) <= strlen(self::BLOB_MAGIC) + 28) {
            return null;
        }

        return self::decryptBinaryPayload($raw, $key);
    }

    /**
     * @template T
     * @param callable(string):T $callback
     * @return T
     */
    public static function withReadableFile(string $path, callable $callback)
    {
        if (!self::isEncryptedBinaryFile($path)) {
            return $callback($path);
        }

        $contents = self::readFileContents($path);
        if (!is_string($contents)) {
            throw new \Error('Không thể giải mã file local. (Sai khóa bảo vệ hoặc khóa bị thay đổi)');
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'hrmdec_');
        if ($tmpPath === false) {
            throw new \Error('Không thể tạo file tạm để giải mã.');
        }

        if (@file_put_contents($tmpPath, $contents) !== strlen($contents)) {
            @unlink($tmpPath);
            throw new \Error('Không thể ghi file tạm đã giải mã.');
        }

        @chmod($tmpPath, 0600);

        try {
            return $callback($tmpPath);
        } finally {
            @unlink($tmpPath);
        }
    }

    private static function isEncryptedEnvelope(array $decoded): bool
    {
        return !empty($decoded[self::ENVELOPE_FLAG])
            && (int) ($decoded['v'] ?? 0) === self::ENVELOPE_VERSION
            && (string) ($decoded['alg'] ?? '') === self::CIPHER;
    }

    private static function encryptBinaryPayload(string $plaintext, string $key): ?string
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if (!is_int($ivLength) || $ivLength <= 0) {
            return null;
        }

        $iv = random_bytes($ivLength);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($ciphertext) || $ciphertext === '' || !is_string($tag) || strlen($tag) !== 16) {
            return null;
        }

        return self::BLOB_MAGIC . $iv . $tag . $ciphertext;
    }

    private static function decryptBinaryPayload(string $payload, string $key): ?string
    {
        if (substr($payload, 0, strlen(self::BLOB_MAGIC)) !== self::BLOB_MAGIC) {
            return null;
        }

        $offset = strlen(self::BLOB_MAGIC);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if (!is_int($ivLength) || $ivLength <= 0) {
            return null;
        }

        $iv = substr($payload, $offset, $ivLength);
        $tag = substr($payload, $offset + $ivLength, 16);
        $ciphertext = substr($payload, $offset + $ivLength + 16);
        if ($iv === '' || $tag === '' || $ciphertext === '') {
            return null;
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        return is_string($plaintext) ? $plaintext : null;
    }

    private static function atomicWrite(string $path, string $contents): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }

        $tmpFile = tempnam($dir, 'enc_');
        if ($tmpFile === false) {
            return false;
        }

        $result = false;
        $fp = @fopen($tmpFile, 'wb');
        if ($fp !== false) {
            if (@flock($fp, LOCK_EX)) {
                $written = @fwrite($fp, $contents);
                if ($written === strlen($contents)) {
                    @fflush($fp);
                    $result = true;
                }
                @flock($fp, LOCK_UN);
            }
            @fclose($fp);
        }

        if (!$result) {
            @unlink($tmpFile);
            return false;
        }

        @chmod($tmpFile, 0600);
        if (!@rename($tmpFile, $path)) {
            @unlink($tmpFile);
            return false;
        }

        @chmod($path, 0600);
        return true;
    }

    private static function readPrefix(string $path, int $length): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            $prefix = fread($handle, $length);
            return is_string($prefix) ? $prefix : '';
        } finally {
            fclose($handle);
        }
    }

    public static function parseKey(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (strpos($raw, 'base64:') === 0) {
            $decoded = base64_decode(substr($raw, 7), true);
            if (is_string($decoded) && strlen($decoded) === 32) {
                return $decoded;
            }
            return hash('sha256', is_string($decoded) ? $decoded : $raw, true);
        }

        if (strpos($raw, 'hex:') === 0) {
            $decoded = @hex2bin(substr($raw, 4));
            if (is_string($decoded) && strlen($decoded) === 32) {
                return $decoded;
            }
            return hash('sha256', is_string($decoded) ? $decoded : $raw, true);
        }

        if (ctype_xdigit($raw) && strlen($raw) === 64) {
            $decoded = @hex2bin($raw);
            if (is_string($decoded) && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        $decoded = base64_decode($raw, true);
        if (is_string($decoded) && strlen($decoded) === 32) {
            return $decoded;
        }

        if (strlen($raw) === 32) {
            return $raw;
        }

        return hash('sha256', $raw, true);
    }

    public static function rotateKey(string $oldKeyStr, string $newKeyStr): array
    {
        $oldKey = self::parseKey($oldKeyStr);
        $newKey = self::parseKey($newKeyStr);

        $uploadsDir = Config::uploadsDir();
        $backupsDir = $uploadsDir . DIRECTORY_SEPARATOR . 'backups';
        
        $patterns = [
            $uploadsDir . DIRECTORY_SEPARATOR . 'auth_*.xlsx',
            $uploadsDir . DIRECTORY_SEPARATOR . 'up_*.xlsx',
            $uploadsDir . DIRECTORY_SEPARATOR . 'up_*.csv',
            $backupsDir . DIRECTORY_SEPARATOR . 'auth_backup_*.xlsx',
        ];
        
        $filesToMigrate = [];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                if (is_file($path)) {
                    $filesToMigrate[] = $path;
                }
            }
        }
        
        // Step 1: Read and decrypt/read all files into memory
        $decryptedData = [];
        foreach ($filesToMigrate as $path) {
            $isEncrypted = self::isEncryptedBinaryFile($path);
            if ($isEncrypted) {
                if ($oldKey === null) {
                    return [
                        'success' => false,
                        'error' => "Tệp tin " . basename($path) . " đã được mã hóa trước đó, nhưng khóa cũ không khả dụng để giải mã."
                    ];
                }
                $contents = self::decryptBinaryPayload(@file_get_contents($path), $oldKey);
                if ($contents === null) {
                    return [
                        'success' => false,
                        'error' => "Không thể giải mã tệp tin: " . basename($path) . " bằng khóa cũ. Quá trình xoay khóa bị hủy bỏ."
                    ];
                }
                $decryptedData[$path] = $contents;
            } else {
                $contents = @file_get_contents($path);
                if (!is_string($contents)) {
                    return [
                        'success' => false,
                        'error' => "Không thể đọc tệp tin: " . basename($path)
                    ];
                }
                $decryptedData[$path] = $contents;
            }
        }
        
        // Step 2: Encrypt with new key and write back
        foreach ($decryptedData as $path => $plaintext) {
            if ($newKey !== null) {
                $encrypted = self::encryptBinaryPayload($plaintext, $newKey);
                if ($encrypted === null) {
                    return [
                        'success' => false,
                        'error' => "Mã hóa thất bại cho tệp tin: " . basename($path) . " bằng khóa mới."
                    ];
                }
                if (!self::atomicWrite($path, $encrypted)) {
                    return [
                        'success' => false,
                        'error' => "Không thể ghi tệp tin đã mã hóa: " . basename($path)
                    ];
                }
            } else {
                if (!self::atomicWrite($path, $plaintext)) {
                    return [
                        'success' => false,
                        'error' => "Không thể ghi tệp tin giải mã: " . basename($path)
                    ];
                }
            }
        }
        
        return ['success' => true, 'error' => null];
    }

    private static function resolveKey(): ?string
    {
        $raw = Config::getEnvValue(self::KEY_ENV);
        if (!is_string($raw)) {
            return null;
        }

        return self::parseKey($raw);
    }
}
