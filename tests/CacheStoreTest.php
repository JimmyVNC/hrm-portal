<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\CacheStore;

final class CacheStoreTest extends TestCase {
    private string $cacheDir = '';

    protected function setUp(): void {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/hrm-cache-test-' . uniqid('', true);
        mkdir($this->cacheDir, 0755, true);
        putenv('RUNTIME_CACHE_DIR=' . $this->cacheDir);
        $_ENV['RUNTIME_CACHE_DIR'] = $this->cacheDir;
    }

    protected function tearDown(): void {
        putenv('RUNTIME_CACHE_DIR');
        unset($_ENV['RUNTIME_CACHE_DIR']);
        if (is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($this->cacheDir);
        }
        parent::tearDown();
    }

    public function testPutGetRespectsTtl(): void {
        CacheStore::put('k1', 'hello');
        $this->assertSame('hello', CacheStore::get('k1', 86_400));
        $keyFile = $this->cacheDir . DIRECTORY_SEPARATOR . hash('sha256', 'k1') . '.json';
        $this->assertFileExists($keyFile);
        file_put_contents($keyFile, json_encode(['ts' => time() - 10_000, 'value' => 'stale']));
        $this->assertNull(CacheStore::get('k1', 60));
    }

    public function testPruneByAge(): void {
        $oldFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'old.json';
        file_put_contents($oldFile, json_encode(['ts' => time() - 200_000, 'value' => 'x']));
        touch($oldFile, time() - 200_000);

        $stats = CacheStore::prune(86_400, 0);
        $this->assertGreaterThanOrEqual(1, $stats['deleted_files']);
    }

    public function testPruneByTotalBudget(): void {
        $a = $this->cacheDir . DIRECTORY_SEPARATOR . 'a.json';
        $b = $this->cacheDir . DIRECTORY_SEPARATOR . 'b.json';
        file_put_contents($a, str_repeat('x', 5000));
        file_put_contents($b, str_repeat('y', 5000));
        touch($a, time() - 3600);
        touch($b, time());

        $stats = CacheStore::prune(0, 6000);
        $this->assertGreaterThanOrEqual(1, $stats['deleted_files']);
        $this->assertLessThanOrEqual(6000, $stats['total_bytes_after']);
    }
}
