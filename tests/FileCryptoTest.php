<?php

declare(strict_types=1);

use App\Services\FileCrypto;
use PHPUnit\Framework\TestCase;

final class FileCryptoTest extends TestCase
{
    private string $tempDir = '';
    private string $tempFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/hrm-file-crypto-test-' . uniqid('', true);
        mkdir($this->tempDir, 0700, true);
        $this->tempFile = $this->tempDir . '/share.json';
        putenv('APP_FILE_ENCRYPTION_KEY');
        unset($_ENV['APP_FILE_ENCRYPTION_KEY'], $_SERVER['APP_FILE_ENCRYPTION_KEY']);
    }

    protected function tearDown(): void
    {
        putenv('APP_FILE_ENCRYPTION_KEY');
        unset($_ENV['APP_FILE_ENCRYPTION_KEY'], $_SERVER['APP_FILE_ENCRYPTION_KEY']);
        if (is_file($this->tempFile)) {
            @unlink($this->tempFile);
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testWriteAndReadPlainJsonWithoutKey(): void
    {
        $payload = ['employee_id' => 'E001', 'amount' => 12345];

        $this->assertTrue(FileCrypto::writeJsonFile($this->tempFile, $payload));
        $raw = file_get_contents($this->tempFile);
        $this->assertIsString($raw);
        $this->assertStringContainsString('"employee_id":"E001"', $raw);

        $read = FileCrypto::readJsonFile($this->tempFile);
        $this->assertTrue($read['ok']);
        $this->assertFalse($read['encrypted']);
        $this->assertSame($payload, $read['data']);
    }

    public function testWriteAndReadEncryptedJsonWithBase64Key(): void
    {
        $key = base64_encode(random_bytes(32));
        putenv('APP_FILE_ENCRYPTION_KEY=' . $key);
        $_ENV['APP_FILE_ENCRYPTION_KEY'] = $key;

        $payload = ['employee_id' => 'E002', 'amount' => 67890];

        $this->assertTrue(FileCrypto::writeJsonFile($this->tempFile, $payload));
        $raw = file_get_contents($this->tempFile);
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('"employee_id":"E002"', $raw);
        $this->assertStringContainsString('"__hrm_enc":true', $raw);

        $read = FileCrypto::readJsonFile($this->tempFile);
        $this->assertTrue($read['ok']);
        $this->assertTrue($read['encrypted']);
        $this->assertSame($payload, $read['data']);
    }

    public function testReadEncryptedJsonFailsWithoutKey(): void
    {
        $key = base64_encode(random_bytes(32));
        putenv('APP_FILE_ENCRYPTION_KEY=' . $key);
        $_ENV['APP_FILE_ENCRYPTION_KEY'] = $key;
        $payload = ['employee_id' => 'E003', 'amount' => 90000];
        $this->assertTrue(FileCrypto::writeJsonFile($this->tempFile, $payload));

        putenv('APP_FILE_ENCRYPTION_KEY');
        unset($_ENV['APP_FILE_ENCRYPTION_KEY'], $_SERVER['APP_FILE_ENCRYPTION_KEY']);

        $read = FileCrypto::readJsonFile($this->tempFile);
        $this->assertFalse($read['ok']);
        $this->assertTrue($read['encrypted']);
        $this->assertSame('key_unavailable', $read['error']);
    }

    public function testEncryptFileInPlaceAndReadBinaryContents(): void
    {
        $key = base64_encode(random_bytes(32));
        putenv('APP_FILE_ENCRYPTION_KEY=' . $key);
        $_ENV['APP_FILE_ENCRYPTION_KEY'] = $key;

        $binaryPath = $this->tempDir . '/sample.xlsx';
        $plaintext = "PK\x03\x04demo-xlsx-content";
        file_put_contents($binaryPath, $plaintext);

        $this->assertTrue(FileCrypto::encryptFileInPlace($binaryPath));
        $this->assertTrue(FileCrypto::isEncryptedBinaryFile($binaryPath));

        $raw = file_get_contents($binaryPath);
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('demo-xlsx-content', $raw);
        $this->assertSame($plaintext, FileCrypto::readFileContents($binaryPath));
    }

    public function testFallbackSha256ForArbitraryLengthKey(): void
    {
        $key = 'my-custom-key-of-arbitrary-length';
        putenv('APP_FILE_ENCRYPTION_KEY=' . $key);
        $_ENV['APP_FILE_ENCRYPTION_KEY'] = $key;

        $payload = ['employee_id' => 'E004', 'amount' => 11111];

        $this->assertTrue(FileCrypto::writeJsonFile($this->tempFile, $payload));

        $raw = file_get_contents($this->tempFile);
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('"employee_id":"E004"', $raw);
        $this->assertStringContainsString('"__hrm_enc":true', $raw);

        $read = FileCrypto::readJsonFile($this->tempFile);
        $this->assertTrue($read['ok']);
        $this->assertTrue($read['encrypted']);
        $this->assertSame($payload, $read['data']);
    }

    public function testSaveEnvEncryptionKeyWritesToDotEnv(): void
    {
        $backupEnv = @file_get_contents(\App\Config::ENV_FILE);
        
        $testKey = 'base64:testkeytestkeytestkeytestkeytestkeytestkey';
        $this->assertTrue(\App\Config::saveEnvEncryptionKey($testKey));
        
        $this->assertSame($testKey, \App\Config::getEnvValue('APP_FILE_ENCRYPTION_KEY'));
        
        $envContents = file_get_contents(\App\Config::ENV_FILE);
        $this->assertStringContainsString('APP_FILE_ENCRYPTION_KEY=' . $testKey, $envContents);
        
        if (is_string($backupEnv)) {
            file_put_contents(\App\Config::ENV_FILE, $backupEnv);
        } else {
            @unlink(\App\Config::ENV_FILE);
        }
    }

    public function testKeyRotation(): void
    {
        $mockUploadsDir = $this->tempDir . '/uploads_mock';
        mkdir($mockUploadsDir, 0700, true);
        mkdir($mockUploadsDir . '/backups', 0700, true);
        
        putenv('UPLOADS_DIR=' . $mockUploadsDir);
        $_ENV['UPLOADS_DIR'] = $mockUploadsDir;
        $_SERVER['UPLOADS_DIR'] = $mockUploadsDir;

        $oldKey = 'base64:5Mr/5KO32J5zMJHrA/u8VcTkrkN3pCrrU5Qs57a1mog=';
        $newKey = 'base64:F2Tgwcifeta/qoTB5HLbSkHaCCf7REl9fNZr84w091g=';

        // 1. Create an encrypted file using the old key
        $file1 = $mockUploadsDir . '/up_test.xlsx';
        $content1 = "PK\x03\x04file1_content";
        
        putenv('APP_FILE_ENCRYPTION_KEY=' . $oldKey);
        $_ENV['APP_FILE_ENCRYPTION_KEY'] = $oldKey;
        file_put_contents($file1, $content1);
        $this->assertTrue(FileCrypto::encryptFileInPlace($file1));
        $this->assertTrue(FileCrypto::isEncryptedBinaryFile($file1));

        // 2. Create a plaintext file (not encrypted)
        $file2 = $mockUploadsDir . '/auth_test.xlsx';
        $content2 = "PK\x03\x04auth_content_plain";
        file_put_contents($file2, $content2);
        $this->assertFalse(FileCrypto::isEncryptedBinaryFile($file2));

        // 3. Perform key rotation
        $res = FileCrypto::rotateKey($oldKey, $newKey);
        $this->assertTrue($res['success']);

        // 4. Verify results with new key
        putenv('APP_FILE_ENCRYPTION_KEY=' . $newKey);
        $_ENV['APP_FILE_ENCRYPTION_KEY'] = $newKey;

        // The first file should be successfully decrypted with new key
        $this->assertTrue(FileCrypto::isEncryptedBinaryFile($file1));
        $this->assertSame($content1, FileCrypto::readFileContents($file1));

        // The second file should also be encrypted with the new key now
        $this->assertTrue(FileCrypto::isEncryptedBinaryFile($file2));
        $this->assertSame($content2, FileCrypto::readFileContents($file2));

        // Clean up environment
        putenv('UPLOADS_DIR');
        unset($_ENV['UPLOADS_DIR'], $_SERVER['UPLOADS_DIR']);
    }
}
