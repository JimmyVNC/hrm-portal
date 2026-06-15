<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\LogFormatter;

final class LogFormatterTest extends TestCase {
    public function testTruncatesLongStrings(): void {
        $long = str_repeat('a', 100);
        $out = LogFormatter::sanitizeContext(['k' => $long], 20);
        $this->assertStringContainsString('truncated', (string) $out['k']);
        $this->assertLessThanOrEqual(40, strlen((string) $out['k']));
    }

    public function testMaxDepth(): void {
        $nested = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'x']]]]]];
        $out = LogFormatter::sanitizeContext($nested, 4000, 3);
        $this->assertArrayHasKey('_truncated', $out['a']['b']['c']);
    }
}
