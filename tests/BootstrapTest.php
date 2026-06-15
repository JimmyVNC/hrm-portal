<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase {
    public function testCoreClassesAutoloadAfterBootstrap(): void {
        $this->assertTrue(class_exists(\App\AppMetadata::class));
        $this->assertTrue(class_exists(\App\Application\AuthActions::class));
        $this->assertTrue(class_exists(\App\Application\DataActions::class));
        $this->assertTrue(class_exists(\App\Application\AdminActions::class));
        $this->assertTrue(class_exists(\App\Services\SpreadsheetReader::class));
        $this->assertTrue(class_exists(\App\Services\CacheStore::class));
        $this->assertTrue(class_exists(\Shuchkin\SimpleXLSX::class));
    }

    public function testAppMetadataVersionShape(): void {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', \App\AppMetadata::VERSION);
    }
}
