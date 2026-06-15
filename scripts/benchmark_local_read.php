<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

require_once __DIR__ . '/../src/Infrastructure/bootstrap.php';

use App\Services\SpreadsheetReader;

$opts = getopt('', ['file:', 'sheet::', 'rows::', 'cols::', 'meta-ttl::']);

$file = isset($opts['file']) ? (string) $opts['file'] : '';
if ($file === '') {
    fwrite(STDERR, "Usage: php scripts/benchmark_local_read.php --file=/abs/path/or/uploads/file.xlsx [--sheet=0] [--rows=50000] [--cols=1000] [--meta-ttl=300]\n");
    exit(2);
}

$sheet = isset($opts['sheet']) ? (int) $opts['sheet'] : 0;
$rows = isset($opts['rows']) ? (int) $opts['rows'] : 50000;
$cols = isset($opts['cols']) ? (int) $opts['cols'] : 1000;
$metaTtl = isset($opts['meta-ttl']) ? (int) $opts['meta-ttl'] : 300;

$filePath = $file;
if (!is_file($filePath) && is_file(__DIR__ . '/../' . ltrim($file, '/'))) {
    $filePath = __DIR__ . '/../' . ltrim($file, '/');
}

if (!is_file($filePath)) {
    fwrite(STDERR, "File not found: {$file}\n");
    exit(1);
}

try {
    $memStart = memory_get_usage(true);
    $peakStart = memory_get_peak_usage(true);

    $t1 = microtime(true);
    $meta = SpreadsheetReader::getLocalSheetMetadata($filePath, $metaTtl);
    $t2 = microtime(true);

    $t3 = microtime(true);
    $data = SpreadsheetReader::fromLocalFile($filePath, $sheet, $rows, $cols);
    $t4 = microtime(true);

    $memEnd = memory_get_usage(true);
    $peakEnd = memory_get_peak_usage(true);

    echo json_encode([
        'ok' => true,
        'file' => $filePath,
        'limits' => [
            'sheet' => $sheet,
            'max_rows' => $rows,
            'max_cols' => $cols,
            'meta_ttl' => $metaTtl,
        ],
        'meta' => [
            'type' => $meta['type'] ?? 'unknown',
            'sheet_count' => is_array($meta['sheets'] ?? null) ? count($meta['sheets']) : 0,
            'metadata_time_ms' => round(($t2 - $t1) * 1000, 2),
        ],
        'read' => [
            'rows_loaded' => count($data),
            'read_time_ms' => round(($t4 - $t3) * 1000, 2),
        ],
        'memory' => [
            'usage_start_mb' => round($memStart / 1024 / 1024, 2),
            'usage_end_mb' => round($memEnd / 1024 / 1024, 2),
            'peak_start_mb' => round($peakStart / 1024 / 1024, 2),
            'peak_end_mb' => round($peakEnd / 1024 / 1024, 2),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
