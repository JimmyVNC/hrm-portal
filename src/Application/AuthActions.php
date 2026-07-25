<?php

namespace App\Application;

use App\Config;
use App\Security;
use App\Services\SpreadsheetReader;
use Throwable;
use Error;

class AuthActions {
    private function __construct() {}

    public static function normalizeHeaderValue($value): string {
        $value = is_string($value) ? $value : (string) $value;
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        $value = str_replace("\xC2\xA0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return strtoupper($value);
    }

    public static function resolveUploadFilePath($relativePath) {
        if (!is_string($relativePath) || trim($relativePath) === '') return false;
        if (strpos($relativePath, '..') !== false || strpos($relativePath, "\0") !== false) return false;

        $baseDir = realpath(\App\Config::uploadsDir());
        if ($baseDir === false) return false;

        // Hỗ trợ cả path tuyệt đối (khi UPLOADS_DIR được set ra ngoài web root)
        // và path tương đối dạng "uploads/filename.xlsx"
        $relativePart = preg_replace('#^uploads[/\\\\]#i', '', ltrim($relativePath, '/\\'));
        $candidate = realpath($baseDir . DIRECTORY_SEPARATOR . $relativePart);
        if ($candidate === false || !is_file($candidate)) return false;
        if (strpos($candidate, $baseDir . DIRECTORY_SEPARATOR) !== 0) return false;
        return $candidate;
    }

    public static function verifyUser(array $config, string $empId, string $password): array {
        if (empty($empId)) return ['success' => false, 'message' => 'Vui lòng nhập Mã NV.'];

        $sourceType = $config['auth_source_type'] ?? 'google';
        $rows = [];
        $maxRows = (int) Config::getEnvValue('AUTH_MAX_ROWS', 50000);
        $maxCols = (int) Config::getEnvValue('AUTH_MAX_COLS', 500);

        try {
            if ($sourceType === 'local') {
                $localFile = $config['auth_local_file'] ?? '';
                if (empty($localFile)) throw new Error('Chưa cấu hình file xác thực local.');
                $fullPath = self::resolveUploadFilePath($localFile);
                if ($fullPath === false) throw new Error('File xác thực không hợp lệ hoặc không tồn tại.');
                $rows = SpreadsheetReader::fromLocalFile($fullPath, 0, $maxRows, $maxCols);
            } else {
                $sheetId = $config['auth_sheet_id'] ?? '';
                $gid = $config['auth_gid'] ?? '0';
                if (empty($sheetId)) throw new Error('Chưa cấu hình Google Sheet ID cho xác thực.');
                $rows = SpreadsheetReader::fromGoogleCsv(
                    $sheetId,
                    $gid,
                    (int) Config::getEnvValue('GOOGLE_CACHE_TTL', 60),
                    $maxRows,
                    $maxCols
                );
            }

            if (empty($rows)) throw new Error('Dữ liệu xác thực trống.');

            $hIdx = -1;
            $colEmpId = isset($config['col_emp_id']) ? self::normalizeHeaderValue($config['col_emp_id']) : 'MÃ NV';
            $colPass  = isset($config['col_password']) ? self::normalizeHeaderValue($config['col_password']) : 'MẬT KHẨU';
            $colName  = isset($config['col_emp_name']) ? self::normalizeHeaderValue($config['col_emp_name']) : 'HỌ TÊN';
            $colDept  = isset($config['col_department']) ? self::normalizeHeaderValue($config['col_department']) : 'BỘ PHẬN';
            $colRole  = isset($config['col_role']) ? self::normalizeHeaderValue($config['col_role']) : 'VAI TRÒ';
            $header = [];

            foreach ($rows as $i => $row) {
                $rMatch = array_map(function ($v) { return self::normalizeHeaderValue($v); }, $row);
                if (in_array($colEmpId, $rMatch, true) && in_array($colPass, $rMatch, true)) {
                    $hIdx = $i;
                    $header = $rMatch;
                    break;
                }
            }

            if ($hIdx === -1) throw new Error("Không tìm thấy tiêu đề cột '{$colEmpId}' và '{$colPass}'.");

            $uCol = array_search($colEmpId, $header, true);
            $pCol = array_search($colPass, $header, true);
            $nCol = array_search($colName, $header, true);
            $dCol = array_search($colDept, $header, true);
            $rCol = array_search($colRole, $header, true);
            // File xác thực thực tế thường dùng "HỌ TÊN" thay vì tên cột cấu hình đầy đủ.
            if ($nCol === false) {
                foreach (['HỌ TÊN', 'HỌ VÀ TÊN', 'TÊN NHÂN VIÊN', 'HỌ TÊN NV'] as $fallbackName) {
                    $candidate = array_search($fallbackName, $header, true);
                    if ($candidate !== false) { $nCol = $candidate; break; }
                }
            }
            $cleanInputId = ltrim(trim($empId), '0');
            $cleanInputId = $cleanInputId === '' ? '0' : $cleanInputId;

            for ($j = $hIdx + 1; $j < count($rows); $j++) {
                $row = $rows[$j];
                if (!isset($row[$uCol])) continue;
                $rawRowId = trim((string) $row[$uCol], " \t\n\r\0\x0B'");
                if ($rawRowId === '') continue;
                $rowId = ltrim($rawRowId, '0');
                $rowId = $rowId === '' ? '0' : $rowId;
                if ($rowId !== $cleanInputId) continue;

                $storedPass = trim($row[$pCol] ?? '');
                $inputPassword = trim($password);
                $passInfo = password_get_info($storedPass);
                $isHash = isset($passInfo['algo']) && (int) $passInfo['algo'] !== 0;
                $isValidPassword = $isHash ? password_verify($inputPassword, $storedPass) : ($storedPass === $inputPassword);
                if ($isValidPassword) {
                    return [
                        'success' => true,
                        'user' => [
                            'id' => $rowId,
                            'name' => ($nCol !== false && isset($row[$nCol])) ? trim($row[$nCol]) : $rowId,
                            'department' => ($dCol !== false && isset($row[$dCol])) ? trim($row[$dCol]) : '',
                            'role' => ($rCol !== false && isset($row[$rCol])) ? strtolower(trim($row[$rCol])) : 'employee',
                        ]
                    ];
                }
                return ['success' => false, 'message' => 'Mật khẩu không chính xác.'];
            }

            return ['success' => false, 'message' => 'Không tìm thấy Mã nhân viên này.'];
        } catch (Throwable $e) {
            Security::appLog('error', 'verify_user_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Hệ thống xác thực chưa sẵn sàng. Vui lòng báo bộ phận nhân sự kiểm tra.'];
        }
    }

    /** Lấy lại tên/bộ phận theo Mã NV cho các phiên đăng nhập cũ. */
    public static function getUserProfile(array $config, string $empId): ?array {
        $empId = trim($empId);
        if ($empId === '') return null;
        try {
            $maxRows = (int) Config::getEnvValue('AUTH_MAX_ROWS', 50000); $maxCols = (int) Config::getEnvValue('AUTH_MAX_COLS', 500);
            if (($config['auth_source_type'] ?? 'google') === 'local') {
                $path = self::resolveUploadFilePath($config['auth_local_file'] ?? '');
                if ($path === false) return null;
                $rows = SpreadsheetReader::fromLocalFile($path, 0, $maxRows, $maxCols);
            } else {
                $sheetId = (string) ($config['auth_sheet_id'] ?? '');
                if ($sheetId === '') return null;
                $rows = SpreadsheetReader::fromGoogleCsv($sheetId, (string) ($config['auth_gid'] ?? '0'), (int) Config::getEnvValue('GOOGLE_CACHE_TTL', 60), $maxRows, $maxCols);
            }
            $idHeader = self::normalizeHeaderValue($config['col_emp_id'] ?? 'MÃ NV');
            $nameHeader = self::normalizeHeaderValue($config['col_emp_name'] ?? 'HỌ TÊN');
            $deptHeader = self::normalizeHeaderValue($config['col_department'] ?? 'BỘ PHẬN');
            $wanted = ltrim($empId, '0'); $wanted = $wanted === '' ? '0' : $wanted;
            foreach ($rows as $i => $row) {
                $header = array_map(static fn($v) => self::normalizeHeaderValue($v), $row);
                $idCol = array_search($idHeader, $header, true);
                if ($idCol === false) continue;
                $nameCol = array_search($nameHeader, $header, true);
                if ($nameCol === false) foreach (['HỌ TÊN', 'HỌ VÀ TÊN', 'TÊN NHÂN VIÊN', 'HỌ TÊN NV'] as $fallback) { $candidate = array_search($fallback, $header, true); if ($candidate !== false) { $nameCol = $candidate; break; } }
                $deptCol = array_search($deptHeader, $header, true);
                foreach (array_slice($rows, $i + 1) as $data) {
                    $rawId = trim((string)($data[$idCol] ?? ''), " \t\n\r\0\x0B'");
                    $cleanId = ltrim($rawId, '0'); $cleanId = $cleanId === '' ? '0' : $cleanId;
                    if ($cleanId !== $wanted) continue;
                    return ['id' => $cleanId, 'name' => $nameCol !== false ? trim((string)($data[$nameCol] ?? '')) : '', 'department' => $deptCol !== false ? trim((string)($data[$deptCol] ?? '')) : ''];
                }
                break;
            }
        } catch (Throwable $e) { Security::appLog('warning', 'get_user_profile_failed', ['error' => $e->getMessage()]); }
        return null;
    }
}
