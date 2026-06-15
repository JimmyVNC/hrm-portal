<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;

final class SpreadsheetSchemaValidator {
    private function __construct() {}

    public static function normalizeHeader(string $value): string {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        $value = str_replace("\xC2\xA0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return function_exists('mb_strtoupper')
            ? mb_strtoupper($value, 'UTF-8')
            : strtoupper($value);
    }

    /**
     * @return string[]
     */
    public static function parseCsvList(string $raw): array {
        $parts = array_map('trim', explode(',', $raw));
        $parts = array_filter($parts, static fn(string $v): bool => $v !== '');
        return array_values(array_unique($parts));
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @return array{header_index:int,header_normalized:array<int,string>,header_raw:array<int,mixed>}|null
     */
    public static function findHeader(array $rows, string $requiredHeader, int $scanLimit = 20): ?array {
        $scanLimit = max(1, $scanLimit);
        $requiredHeader = self::normalizeHeader($requiredHeader);
        $maxIdx = min(count($rows), $scanLimit);
        for ($i = 0; $i < $maxIdx; $i++) {
            $raw = (array) ($rows[$i] ?? []);
            $normalized = array_map(static function ($value): string {
                return self::normalizeHeader((string) $value);
            }, $raw);
            if (in_array($requiredHeader, $normalized, true)) {
                return [
                    'header_index' => $i,
                    'header_normalized' => $normalized,
                    'header_raw' => $raw,
                ];
            }
        }
        return null;
    }

    /**
     * @param array<int, string> $headerNormalized
     * @param array<int, string> $requiredHeaders
     * @return string[]
     */
    public static function missingHeaders(array $headerNormalized, array $requiredHeaders): array {
        $missing = [];
        $set = array_values(array_unique(array_map([self::class, 'normalizeHeader'], $headerNormalized)));
        foreach ($requiredHeaders as $required) {
            $norm = self::normalizeHeader($required);
            if (!in_array($norm, $set, true)) {
                $missing[] = $required;
            }
        }
        return $missing;
    }

    private static function isValidNumberValue(string $value): bool {
        $value = trim($value);
        if ($value === '') {
            return true;
        }
        $value = str_replace(["\xC2\xA0", ' '], '', $value);
        $value = preg_replace('/[^0-9,\.\-]/u', '', $value);
        if (!is_string($value) || $value === '' || $value === '-' || $value === '.' || $value === ',') {
            return false;
        }
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $value) === 1) {
            $value = str_replace(',', '', $value);
            return is_numeric($value);
        }
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $value) === 1) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
            return is_numeric($value);
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($lastComma !== false) {
            $fractionLength = strlen($value) - $lastComma - 1;
            if ($fractionLength === 3 && preg_match('/^-?\d{1,3}(,\d{3})+$/', $value) === 1) {
                $value = str_replace(',', '', $value);
            } else {
                $value = str_replace(',', '.', $value);
            }
        } elseif ($lastDot !== false) {
            $fractionLength = strlen($value) - $lastDot - 1;
            if ($fractionLength === 3 && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $value) === 1) {
                $value = str_replace('.', '', $value);
            }
        }

        return is_numeric($value);
    }

    private static function isValidDateValue(string $value): bool {
        $value = trim($value);
        if ($value === '') {
            return true;
        }
        if (is_numeric($value)) {
            $num = (float) $value;
            if ($num > 0 && $num < 100000) {
                return true;
            }
        }
        return strtotime($value) !== false;
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @param array<int, string> $headerNormalized
     * @param array<string, string> $typedColumns map normalized header => type(number|date)
     * @return array{ok:bool,message?:string}
     */
    public static function validateTypedColumns(array $rows, int $headerIndex, array $headerNormalized, array $typedColumns, int $maxRowsToScan = 5000): array {
        if ($typedColumns === []) {
            return ['ok' => true];
        }

        $maxRowsToScan = max(1, $maxRowsToScan);
        $typeByCol = [];
        foreach ($typedColumns as $header => $type) {
            $col = array_search($header, $headerNormalized, true);
            if ($col !== false) {
                $typeByCol[(int) $col] = $type;
            }
        }
        if ($typeByCol === []) {
            return ['ok' => true];
        }

        $start = $headerIndex + 1;
        $end = min(count($rows), $start + $maxRowsToScan);
        for ($r = $start; $r < $end; $r++) {
            $row = (array) ($rows[$r] ?? []);
            foreach ($typeByCol as $col => $type) {
                $cell = isset($row[$col]) ? (string) $row[$col] : '';
                if ($type === 'number' && !self::isValidNumberValue($cell)) {
                    return [
                        'ok' => false,
                        'message' => "Lỗi kiểu dữ liệu tại dòng " . ($r + 1) . ", cột '" . ($headerNormalized[$col] ?? ('#' . $col)) . "': giá trị '" . trim($cell) . "' không phải số hợp lệ.",
                    ];
                }
                if ($type === 'date' && !self::isValidDateValue($cell)) {
                    return [
                        'ok' => false,
                        'message' => "Lỗi kiểu dữ liệu tại dòng " . ($r + 1) . ", cột '" . ($headerNormalized[$col] ?? ('#' . $col)) . "': giá trị '" . trim($cell) . "' không phải ngày hợp lệ.",
                    ];
                }
            }
        }
        return ['ok' => true];
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @param array<string, mixed> $config
     * @param array<string, mixed> $period
     * @return array{ok:bool,message?:string}
     */
    public static function validatePeriodDataset(array $rows, array $config, array $period): array {
        if ($rows === []) {
            return ['ok' => false, 'message' => 'File dữ liệu trống.'];
        }

        $searchCol = (string) ($config['col_emp_id'] ?? 'MÃ NV');
        $header = self::findHeader($rows, $searchCol, (int) (($config['header_scan_limit'] ?? 20)));
        if ($header === null) {
            return ['ok' => false, 'message' => "Không tìm thấy header chứa cột '{$searchCol}'."];
        }

        // Preserve the legacy behavior: only the employee-id header is mandatory.
        // Optional display/highlight/money columns may be absent and will be ignored by the UI layer.
        $missing = self::missingHeaders($header['header_normalized'], [$searchCol]);
        if ($missing !== []) {
            return ['ok' => false, 'message' => 'Thiếu cột trong file dữ liệu: ' . implode(', ', $missing)];
        }

        $typedColumns = [];
        foreach (self::parseCsvList((string) ($period['money_cols'] ?? '')) as $column) {
            $typedColumns[self::normalizeHeader($column)] = 'number';
        }
        foreach (self::parseCsvList((string) Config::getEnvValue('PERIOD_NUMERIC_COLS', '')) as $column) {
            $typedColumns[self::normalizeHeader($column)] = 'number';
        }
        foreach (self::parseCsvList((string) Config::getEnvValue('PERIOD_DATE_COLS', '')) as $column) {
            $typedColumns[self::normalizeHeader($column)] = 'date';
        }

        return self::validateTypedColumns(
            $rows,
            (int) $header['header_index'],
            $header['header_normalized'],
            $typedColumns,
            (int) Config::getEnvValue('SCHEMA_VALIDATE_MAX_ROWS', 5000)
        );
    }
}
