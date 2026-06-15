<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\SpreadsheetReader;

final class SpreadsheetReaderCsvTest extends TestCase {
    public function testParseCsvContentRespectsRowLimit(): void {
        $csv = "a,b\n1,2\n3,4\n";
        $this->expectException(\Error::class);
        SpreadsheetReader::parseCsvContent($csv, 2, 10);
    }

    public function testParseCsvContentRespectsColLimit(): void {
        $csv = "a,b,c\n";
        $this->expectException(\Error::class);
        SpreadsheetReader::parseCsvContent($csv, 100, 2);
    }

    public function testParseCsvContentStripsRowsWhenWithinLimits(): void {
        $csv = "\xEF\xBB\xBFh1,h2\nv1,v2\n";
        $rows = SpreadsheetReader::parseCsvContent($csv, 50, 10);
        $this->assertCount(2, $rows);
        $this->assertSame(['h1', 'h2'], $rows[0]);
    }

    public function testGetLocalSheetMetadataForCsv(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'csvmeta_');
        $this->assertIsString($tmp);
        file_put_contents($tmp, "h1,h2,h3\n1,2,3\n");

        $meta = SpreadsheetReader::getLocalSheetMetadata($tmp, 0, 'csv');
        $this->assertSame('csv', $meta['type']);
        $this->assertCount(1, $meta['sheets']);
        $this->assertSame(2, $meta['sheets'][0]['rows']);
        $this->assertSame(3, $meta['sheets'][0]['cols']);

        @unlink($tmp);
    }

    public function testResolveSheetIndexFallbackForCsv(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'csvsheet_');
        $this->assertIsString($tmp);
        file_put_contents($tmp, "h1,h2\n1,2\n");

        $index = SpreadsheetReader::resolveSheetIndex($tmp, 'Any Sheet', 0, 0, 'csv');
        $this->assertSame(0, $index);

        @unlink($tmp);
    }

    public function testFromLocalFileAcceptsDeclaredExtensionForTempUpload(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'csvupload_');
        $this->assertIsString($tmp);
        file_put_contents($tmp, "h1,h2\n1,2\n");

        $rows = SpreadsheetReader::fromLocalFile($tmp, 0, 50, 10, 'csv');
        $this->assertCount(2, $rows);
        $this->assertSame(['h1', 'h2'], $rows[0]);

        @unlink($tmp);
    }
}
