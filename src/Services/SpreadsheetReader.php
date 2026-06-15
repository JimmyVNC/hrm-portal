<?php

declare(strict_types=1);

namespace App\Services;

use Shuchkin\SimpleXLSX;
use App\Config;
use App\Services\FileCrypto;

class SpreadsheetReader {
    private function __construct() {}

    private const XLSX_MAGIC = "PK\x03\x04";
    private const XLS_MAGIC = "\xD0\xCF\x11\xE0";

    private static function normalizeCsvRow(array $data, bool $isFirstRow): array {
        if ($isFirstRow && isset($data[0]) && is_string($data[0])) {
            $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
        }
        return $data;
    }

    private static function assertLocalFileReadable(string $fullPath, int $maxBytes): void {
        if (!is_file($fullPath) || !is_readable($fullPath)) {
            throw new \Error('File local không tồn tại hoặc không đọc được.');
        }
        $size = (int) (@filesize($fullPath) ?: 0);
        if ($size <= 0) {
            throw new \Error('File local trống hoặc không hợp lệ.');
        }
        if ($maxBytes > 0 && $size > $maxBytes) {
            throw new \Error('File local vượt giới hạn kích thước cho phép.');
        }
    }

    private static function readMagicBytes(string $fullPath, int $numBytes = 4): string {
        $h = fopen($fullPath, 'rb');
        if ($h === false) {
            return '';
        }
        try {
            $magic = fread($h, $numBytes);
            return is_string($magic) ? $magic : '';
        } finally {
            fclose($h);
        }
    }

    private static function validateLocalFileSignature(string $fullPath, string $ext): void {
        $magic = self::readMagicBytes($fullPath, 4);
        if (($ext === 'xlsx') && $magic !== '' && strpos($magic, self::XLSX_MAGIC) !== 0) {
            throw new \Error('File .xlsx không đúng định dạng ZIP của Excel.');
        }
        if (($ext === 'xls') && $magic !== '' && strpos($magic, self::XLS_MAGIC) !== 0) {
            throw new \Error('File .xls không đúng định dạng OLE của Excel.');
        }
    }

    private static function metadataCacheKey(string $fullPath): string {
        $mtime = (int) (@filemtime($fullPath) ?: 0);
        $size = (int) (@filesize($fullPath) ?: 0);
        return 'local_sheet_meta_' . hash('sha256', $fullPath . '|' . $mtime . '|' . $size);
    }

    private static function resolveLocalExtension(string $fullPath, ?string $declaredExt = null): string {
        $declaredExt = is_string($declaredExt) ? strtolower(trim($declaredExt)) : '';
        if ($declaredExt !== '') {
            return $declaredExt;
        }
        return strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    }

    /**
     * Fetch Google Sheet as CSV with optional row/column caps (memory and sanity).
     *
     * @throws \Error
     */
    public static function fromGoogleCsv(
        string $sheetId,
        string $gid,
        int $ttlSeconds = 60,
        int $maxRows = 50000,
        int $maxCols = 1000
    ): array {
        $cacheKey = 'google_csv_' . $sheetId . '_' . $gid;
        $url = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$gid}";
        $content = CacheStore::get($cacheKey, $ttlSeconds);
        if ($content === null) {
            $content = Config::fetchUrlHelper($url);
            if ($content === false || $content === '') {
                throw new \Error('Không thể tải dữ liệu từ Google Sheets.');
            }
            CacheStore::put($cacheKey, $content);
        }
        return self::parseCsvContent($content, $maxRows, $maxCols);
    }

    /**
     * Read local xlsx/xls/csv with dimension / streaming checks to respect limits.
     *
     * @throws \Error
     */
    public static function fromLocalFile(string $fullPath, int $sheetIndex = 0, int $maxRows = 50000, int $maxCols = 1000, ?string $declaredExt = null): array {
        $maxLocalBytes = (int) Config::getEnvValue('LOCAL_FILE_MAX_BYTES', 20 * 1024 * 1024);
        self::assertLocalFileReadable($fullPath, $maxLocalBytes);
        return FileCrypto::withReadableFile($fullPath, function (string $readPath) use ($fullPath, $declaredExt, $sheetIndex, $maxRows, $maxCols): array {
            $ext = self::resolveLocalExtension($fullPath, $declaredExt);
            if ($ext === 'xls') {
                throw new \Error('File .xls chưa được hỗ trợ. Vui lòng lưu lại dưới dạng .xlsx hoặc .csv.');
            }
            if ($ext === 'xlsx') {
                self::validateLocalFileSignature($readPath, $ext);
                $xlsx = SimpleXLSX::parse($readPath);
                if (!$xlsx) {
                    throw new \Error('Lỗi đọc file Excel: ' . SimpleXLSX::parseError());
                }
                if ($sheetIndex < 0 || $sheetIndex >= $xlsx->sheetsCount()) {
                    throw new \Error('Sheet index không hợp lệ.');
                }
                $dim = $xlsx->dimension($sheetIndex);
                $numCols = (int) ($dim[0] ?? 0);
                $numRows = (int) ($dim[1] ?? 0);
                if ($numCols > $maxCols) {
                    throw new \Error('File Excel vượt giới hạn ' . $maxCols . ' cột (theo khai báo sheet).');
                }
                if ($numRows > $maxRows) {
                    throw new \Error('File Excel vượt giới hạn ' . $maxRows . ' dòng (theo khai báo sheet).');
                }
                $rows = $xlsx->rows($sheetIndex, $maxRows > 0 ? $maxRows : 0);
                foreach ($rows as $row) {
                    if (is_array($row) && count($row) > $maxCols) {
                        throw new \Error('File Excel có hàng vượt giới hạn ' . $maxCols . ' cột.');
                    }
                }
                return $rows;
            }
            if ($ext !== 'csv') {
                throw new \Error('Định dạng file local không được hỗ trợ. Chỉ nhận CSV/XLSX.');
            }
            return self::parseCsvFile($readPath, $maxRows, $maxCols);
        });
    }

    /**
     * Returns lightweight metadata for local spreadsheet.
     *
     * @return array{type:string,file_size:int,sheets:array<int,array{index:int,name:string,rows:int,cols:int}>}
     * @throws \Error
     */
    public static function getLocalSheetMetadata(string $fullPath, int $cacheTtlSeconds = 300, ?string $declaredExt = null): array {
        $maxLocalBytes = (int) Config::getEnvValue('LOCAL_FILE_MAX_BYTES', 20 * 1024 * 1024);
        self::assertLocalFileReadable($fullPath, $maxLocalBytes);
        $ext = self::resolveLocalExtension($fullPath, $declaredExt);
        $fileSize = (int) (@filesize($fullPath) ?: 0);
        $cacheTtlSeconds = max(0, $cacheTtlSeconds);

        $cacheKey = self::metadataCacheKey($fullPath);
        if ($cacheTtlSeconds > 0) {
            $cached = CacheStore::get($cacheKey, $cacheTtlSeconds);
            if ($cached !== null) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) && isset($decoded['type'], $decoded['file_size'], $decoded['sheets']) && is_array($decoded['sheets'])) {
                    return $decoded;
                }
            }
        }

        $meta = FileCrypto::withReadableFile($fullPath, function (string $readPath) use ($ext, $fileSize): array {
            if ($ext === 'csv') {
                $rows = 0;
                $maxCols = 0;
                $h = fopen($readPath, 'r');
                if ($h === false) {
                    throw new \Error('Không thể đọc metadata của file CSV.');
                }
                try {
                    while (($data = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
                        $rows++;
                        $maxCols = max($maxCols, count($data));
                    }
                } finally {
                    fclose($h);
                }
                return [
                    'type' => 'csv',
                    'file_size' => $fileSize,
                    'sheets' => [
                        ['index' => 0, 'name' => 'CSV (sheet duy nhất)', 'rows' => $rows, 'cols' => $maxCols],
                    ],
                ];
            }
            if ($ext === 'xls') {
                throw new \Error('File .xls chưa được hỗ trợ. Vui lòng lưu lại dưới dạng .xlsx hoặc .csv.');
            }
            if ($ext === 'xlsx') {
                self::validateLocalFileSignature($readPath, $ext);
                $xlsx = SimpleXLSX::parse($readPath);
                if (!$xlsx) {
                    throw new \Error('Không đọc được file Excel: ' . SimpleXLSX::parseError());
                }
                $sheetNames = $xlsx->sheetNames();
                $sheets = [];
                foreach ($sheetNames as $idx => $name) {
                    $dim = $xlsx->dimension((int) $idx);
                    $sheets[] = [
                        'index' => (int) $idx,
                        'name' => (string) $name,
                        'cols' => (int) ($dim[0] ?? 0),
                        'rows' => (int) ($dim[1] ?? 0),
                    ];
                }
                return [
                    'type' => $ext,
                    'file_size' => $fileSize,
                    'sheets' => $sheets,
                ];
            }

            throw new \Error('Định dạng file local không được hỗ trợ.');
        });

        if ($cacheTtlSeconds > 0) {
            $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE);
            if (is_string($encoded) && $encoded !== '') {
                CacheStore::put($cacheKey, $encoded);
            }
        }
        return $meta;
    }

    /**
     * Resolve sheet index from sheet name with fallback.
     */
    public static function resolveSheetIndex(string $fullPath, ?string $sheetName, int $fallbackIndex = 0, int $cacheTtlSeconds = 300, ?string $declaredExt = null): int {
        $meta = self::getLocalSheetMetadata($fullPath, $cacheTtlSeconds, $declaredExt);
        $sheets = $meta['sheets'] ?? [];
        if (!is_array($sheets) || $sheets === []) {
            return 0;
        }

        $wanted = trim((string) $sheetName);
        if ($wanted === '') {
            return max(0, $fallbackIndex);
        }
        $fold = static function (string $value): string {
            return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        };
        $wantedFolded = $fold($wanted);
        foreach ($sheets as $sheet) {
            $candidate = trim((string) ($sheet['name'] ?? ''));
            if ($candidate === '') {
                continue;
            }
            if ($fold($candidate) === $wantedFolded) {
                return (int) ($sheet['index'] ?? 0);
            }
        }
        return max(0, $fallbackIndex);
    }

    /**
     * @throws \Error
     */
    public static function parseCsvFile(string $fullPath, int $maxRows = 50000, int $maxCols = 1000): array {
        $rows = [];
        $h = fopen($fullPath, 'r');
        if ($h === false) {
            return $rows;
        }
        try {
            while (($data = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
                $data = self::normalizeCsvRow($data, count($rows) === 0);
                if (count($data) > $maxCols) {
                    throw new \Error('File CSV vượt giới hạn ' . $maxCols . ' cột tại một dòng.');
                }
                $rows[] = $data;
                if (count($rows) > $maxRows) {
                    throw new \Error('File CSV vượt giới hạn ' . $maxRows . ' dòng.');
                }
            }
        } finally {
            fclose($h);
        }
        return $rows;
    }

    /**
     * @throws \Error
     */
    public static function parseCsvContent(string $content, int $maxRows = 50000, int $maxCols = 1000): array {
        $rows = [];
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return $rows;
        }
        try {
            fwrite($stream, $content);
            rewind($stream);
            while (($data = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
                $data = self::normalizeCsvRow($data, count($rows) === 0);
                if (count($data) > $maxCols) {
                    throw new \Error('CSV vượt giới hạn ' . $maxCols . ' cột tại một dòng.');
                }
                $rows[] = $data;
                if (count($rows) > $maxRows) {
                    throw new \Error('CSV vượt giới hạn ' . $maxRows . ' dòng.');
                }
            }
        } finally {
            fclose($stream);
        }
        return $rows;
    }
}
