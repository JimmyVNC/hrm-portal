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
}
