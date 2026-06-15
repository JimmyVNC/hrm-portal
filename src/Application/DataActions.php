<?php

namespace App\Application;

use App\Config;
use App\Security;
use App\Services\SpreadsheetReader;
use Throwable;
use Error;

class DataActions {
    private function __construct() {}

    public static function normalizeEmployeeId($value): string {
        $value = trim((string) $value);
        $value = ltrim($value, "'");
        $value = preg_replace('/\s+/u', '', $value);
        $value = ltrim((string) $value, '0');
        return $value === '' ? '0' : strtoupper($value);
    }

    private static function filterRowsForEmployee(array $rows, string $searchCol, string $employeeId): array {
        $hIdx = -1;
        $header = [];

        foreach ($rows as $i => $row) {
            $normalized = array_map([self::class, 'normalizeHeaderValueData'], (array) $row);
            if (in_array($searchCol, $normalized, true)) {
                $hIdx = (int) $i;
                $header = (array) $row;
                break;
            }
        }

        if ($hIdx === -1) {
            return ['header' => [], 'data' => [], 'hIdx' => -1];
        }

        $headerNormalized = array_map([self::class, 'normalizeHeaderValueData'], $header);
        $idxUser = array_search($searchCol, $headerNormalized, true);
        if ($idxUser === false) {
            return ['header' => $header, 'data' => [], 'hIdx' => $hIdx];
        }

        $wantedId = self::normalizeEmployeeId($employeeId);
        $filteredData = [];
        for ($j = $hIdx + 1; $j < count($rows); $j++) {
            if (self::normalizeEmployeeId($rows[$j][$idxUser] ?? '') === $wantedId) {
                $filteredData[] = $rows[$j];
            }
        }

        return ['header' => $header, 'data' => $filteredData, 'hIdx' => $hIdx];
    }

    public static function getLatestPayrollUpdateLabel(array $config): string {
        $latestTs = null;
        $latestFallback = null;

        $periods = $config['periods'] ?? [];
        if (!is_array($periods) || $periods === []) {
            return '';
        }

        foreach ($periods as $period) {
            if (!is_array($period)) continue;

            // Prefer local file modification time when available.
            $sourceType = (string) ($period['source_type'] ?? 'google');
            if ($sourceType === 'local') {
                $localFile = (string) ($period['local_file'] ?? '');
                $fullPath = self::resolveUploadDataFilePath($localFile);
                if (is_string($fullPath)) {
                    $mtime = @filemtime($fullPath);
                    if (is_int($mtime) && ($latestTs === null || $mtime > $latestTs)) {
                        $latestTs = $mtime;
                    }
                }
            }

            // Fallback: use publish_date as a rough "updated" signal when no file mtime.
            $publishDate = trim((string) ($period['publish_date'] ?? ''));
            if ($publishDate !== '') {
                $publishTs = self::parsePublishTimestamp($publishDate);
                if (is_int($publishTs) && ($latestFallback === null || $publishTs > $latestFallback)) {
                    $latestFallback = $publishTs;
                }
            }
        }

        $ts = $latestTs ?? $latestFallback;
        if ($ts === null) return '';

        $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(Config::getAppTimezone()));
        return $dt->format('H:i') . ' - Ngày ' . $dt->format('d/m/Y');
    }

    public static function isPeriodEnabled(array $period): bool {
        return !array_key_exists('enabled', $period) || $period['enabled'] !== false;
    }

    private static function parsePublishTimestamp(string $publishDate): ?int {
        $publishDate = trim($publishDate);
        if ($publishDate === '') {
            return null;
        }

        $timezone = new \DateTimeZone(Config::getAppTimezone());
        $formats = ['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d\TH:i:s', '!Y-m-d\TH:i'];

        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $publishDate, $timezone);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->getTimestamp();
            }
        }

        try {
            $dt = new \DateTimeImmutable($publishDate, $timezone);
            return $dt->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function normalizeHeaderValueData($value): string {
        $value = is_string($value) ? $value : (string) $value;
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        $value = str_replace("\xC2\xA0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return strtoupper($value);
    }

    public static function resolveUploadDataFilePath($relativePath) {
        if (!is_string($relativePath) || trim($relativePath) === '') return false;
        if (strpos($relativePath, '..') !== false || strpos($relativePath, "\0") !== false) return false;

        $baseDir = realpath(Config::uploadsDir());
        if ($baseDir === false) return false;

        $relativePart = preg_replace('#^uploads[/\\\\]#i', '', ltrim($relativePath, '/\\'));
        $candidate = realpath($baseDir . DIRECTORY_SEPARATOR . $relativePart);
        if ($candidate === false || !is_file($candidate)) return false;
        if (strpos($candidate, $baseDir . DIRECTORY_SEPARATOR) !== 0) return false;
        return $candidate;
    }

    public static function isPeriodPublished(array $period, bool $isAdmin, ?int $nowTs = null): bool {
        if ($isAdmin) {
            return true;
        }
        $publishDate = trim((string) ($period['publish_date'] ?? ''));
        if ($publishDate === '') {
            return true;
        }
        $publishTs = self::parsePublishTimestamp($publishDate);
        if ($publishTs === null) {
            return true;
        }
        $nowTs = $nowTs ?? time();
        return $publishTs <= $nowTs;
    }

    public static function buildPeriodUnavailableMessage(array $period): string {
        $label = trim((string) ($period['label'] ?? 'Kỳ phiếu lương này'));
        if (!self::isPeriodEnabled($period)) {
            return $label . ' đang tạm tắt.';
        }
        $publishDate = trim((string) ($period['publish_date'] ?? ''));
        if ($publishDate === '') {
            return $label . ' chưa được công bố.';
        }
        return $label . ' sẽ được mở từ ' . $publishDate . '.';
    }

    public static function handle(array $config, int $periodIndex): array {
        if (empty($_SESSION['hr_user']) && empty($_SESSION['hr_admin'])) {
            return ['success' => false, 'message' => 'Phiên làm việc hết hạn. Vui lòng đăng nhập lại.'];
        }
        if ($periodIndex < 0 || !isset($config['periods'][$periodIndex])) {
            return ['success' => false, 'message' => 'Kỳ phiếu lương không hợp lệ.'];
        }

        $period = $config['periods'][$periodIndex];
        $isAdmin = !empty($_SESSION['hr_admin']);
        $user = $_SESSION['hr_user'] ?? null;
        $maxRows = (int) Config::getEnvValue('PERIOD_MAX_ROWS', 50000);
        $maxCols = (int) Config::getEnvValue('PERIOD_MAX_COLS', 1000);

        if (!$isAdmin && !self::isPeriodEnabled($period)) {
            return ['success' => false, 'message' => self::buildPeriodUnavailableMessage($period)];
        }

        if (!self::isPeriodPublished($period, $isAdmin)) {
            return ['success' => false, 'message' => self::buildPeriodUnavailableMessage($period)];
        }

        try {
            $sourceType = $period['source_type'] ?? 'google';
            if ($sourceType === 'local') {
                $localFile = $period['local_file'] ?? '';
                if (empty($localFile)) throw new Error('Chưa cấu hình file dữ liệu cho kỳ này.');
                $fullPath = self::resolveUploadDataFilePath($localFile);
                if ($fullPath === false) throw new Error('File dữ liệu không hợp lệ hoặc không tồn tại.');
                $sheetIndex = SpreadsheetReader::resolveSheetIndex(
                    $fullPath,
                    (string) ($period['sheet_name'] ?? ''),
                    (int) ($period['sheet_index'] ?? 0),
                    (int) Config::getEnvValue('LOCAL_META_CACHE_TTL', 300)
                );
                $rows = SpreadsheetReader::fromLocalFile($fullPath, $sheetIndex, $maxRows, $maxCols);
            } else {
                $sheetId = $period['sheet_id'] ?? '';
                $gid = $period['gid'] ?? '0';
                if (empty($sheetId)) throw new Error('Chưa cấu hình Google Sheet ID.');
                $rows = SpreadsheetReader::fromGoogleCsv(
                    $sheetId,
                    $gid,
                    (int) Config::getEnvValue('GOOGLE_CACHE_TTL', 60),
                    $maxRows,
                    $maxCols
                );
            }

            if (empty($rows)) throw new Error('Phiếu lương trống.');

            $hIdx = -1;
            $searchCol = isset($config['col_emp_id']) ? self::normalizeHeaderValueData($config['col_emp_id']) : 'MÃ NV';
            $fallbackCol = 'STT';
            foreach ($rows as $i => $row) {
                $rMatch = array_map(function ($v) { return self::normalizeHeaderValueData($v); }, $row);
                if (in_array($searchCol, $rMatch, true)) { $hIdx = $i; break; }
            }
            if ($hIdx === -1) {
                foreach ($rows as $i => $row) {
                    $rMatch = array_map(function ($v) { return self::normalizeHeaderValueData($v); }, $row);
                    if (in_array($fallbackCol, $rMatch, true)) { $hIdx = $i; break; }
                }
            }
            if ($hIdx === -1) throw new Error("Không tìm thấy hàng tiêu đề (chứa '{$searchCol}') trong dữ liệu.");

            $header = $rows[$hIdx];
            $headerNormalized = array_map(function ($v) { return self::normalizeHeaderValueData($v); }, $header);
            $idxUser = array_search($searchCol, $headerNormalized, true);

            $filteredData = [];
            if ($isAdmin && empty($user)) {
                $filteredData = array_slice($rows, $hIdx + 1);
            } else {
                if ($idxUser === false) throw new Error("Dữ liệu không có cột '{$searchCol}'.");
                $match = self::filterRowsForEmployee($rows, $searchCol, (string) ($user['id'] ?? ''));
                $filteredData = $match['data'];

                if ($filteredData === [] && $sourceType === 'local') {
                    $meta = SpreadsheetReader::getLocalSheetMetadata(
                        $fullPath,
                        (int) Config::getEnvValue('LOCAL_META_CACHE_TTL', 300)
                    );
                    foreach (($meta['sheets'] ?? []) as $sheet) {
                        $candidateIndex = (int) ($sheet['index'] ?? -1);
                        if ($candidateIndex < 0 || $candidateIndex === $sheetIndex) continue;

                        $candidateRows = SpreadsheetReader::fromLocalFile($fullPath, $candidateIndex, $maxRows, $maxCols);
                        $candidateMatch = self::filterRowsForEmployee($candidateRows, $searchCol, (string) ($user['id'] ?? ''));
                        if ($candidateMatch['data'] !== []) {
                            $header = $candidateMatch['header'];
                            $hIdx = $candidateMatch['hIdx'];
                            $filteredData = $candidateMatch['data'];
                            break;
                        }
                    }
                }
            }

            return [
                'success' => true,
                'header' => $header,
                'data' => $filteredData,
                'hIdx' => $hIdx,
                'matched_rows' => count($filteredData),
                'employee_id' => self::normalizeEmployeeId((string) ($user['id'] ?? '')),
            ];
        } catch (Throwable $e) {
            Security::appLog('error', 'data_action_failed', ['error' => $e->getMessage(), 'period_index' => $periodIndex]);
            return ['success' => false, 'message' => 'Dữ liệu phiếu lương chưa sẵn sàng. Vui lòng báo bộ phận nhân sự kiểm tra.'];
        }
    }
}
