<?php

namespace App\Application;

use App\Config;
use App\Security;
use App\Services\FileCrypto;
use App\Services\SpreadsheetReader;
use App\Services\SpreadsheetSchemaValidator;

class AdminActions {
    private function __construct() {}
    private const STALE_FILE_RETENTION_DAYS = 15;

    public static function normalizePeriodEnabled($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    private static function normalizePublishDate(?string $publishDate): string {
        $publishDate = trim((string) $publishDate);
        if ($publishDate === '') {
            return '';
        }

        $normalized = str_replace('T', ' ', $publishDate);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized)) {
            return $normalized . ':00';
        }
        return $normalized;
    }

    public static function isPasswordHashString($value): bool {
        if (!is_string($value) || $value === '') return false;
        $info = password_get_info($value);
        return isset($info['algo']) && $info['algo'] !== 0;
    }

    public static function isSafeRelativeUploadPath($relativePath): bool {
        if (!is_string($relativePath) || trim($relativePath) === '') return false;
        if (strpos($relativePath, '..') !== false || strpos($relativePath, "\0") !== false) return false;
        return (bool) preg_match('/^uploads\/[a-zA-Z0-9._-]+$/', $relativePath);
    }

    public static function ensureUploadDirectory($uploadDir): bool {
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0700, true)) return false;
        @chmod($uploadDir, 0700);
        return is_writable($uploadDir);
    }

    public static function validateUploadedSpreadsheetLimits($tmpName, $ext, $sheetIndex, $maxRows, $maxCols): array {
        $rows = [];
        if ($ext === 'xls') {
            return ['ok' => false, 'message' => 'File .xls chưa được hỗ trợ. Vui lòng chuyển sang .xlsx hoặc .csv.'];
        }
        if ($ext === 'xlsx') {
            try {
                $meta = SpreadsheetReader::getLocalSheetMetadata($tmpName, 0, (string) $ext);
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => $e->getMessage()];
            }
            $sheetCount = count($meta['sheets'] ?? []);
            if ($sheetIndex < 0 || $sheetIndex >= $sheetCount) return ['ok' => false, 'message' => 'Sheet index không hợp lệ. File hiện có ' . $sheetCount . ' sheet.'];
            $selected = $meta['sheets'][$sheetIndex] ?? ['rows' => 0, 'cols' => 0];
            $numCols = (int) ($selected['cols'] ?? 0);
            $numRows = (int) ($selected['rows'] ?? 0);
            if ($numCols > $maxCols) return ['ok' => false, 'message' => 'Vượt giới hạn ' . $maxCols . ' cột (theo khai báo sheet).'];
            if ($numRows > $maxRows) return ['ok' => false, 'message' => 'Vượt giới hạn ' . $maxRows . ' dòng (theo khai báo sheet).'];
            try {
                $rows = SpreadsheetReader::fromLocalFile($tmpName, (int) $sheetIndex, (int) $maxRows, (int) $maxCols, (string) $ext);
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => $e->getMessage()];
            }
        } else {
            $handle = fopen($tmpName, 'r');
            if ($handle === false) return ['ok' => false, 'message' => 'Không thể đọc file CSV.'];
            while (($data = fgetcsv($handle, 0, ',')) !== false) {
                $rows[] = $data;
                if (count($rows) > $maxRows) { fclose($handle); return ['ok' => false, 'message' => 'Vượt giới hạn ' . $maxRows . ' dòng.']; }
            }
            fclose($handle);
        }
        if (count($rows) > $maxRows) return ['ok' => false, 'message' => 'Vượt giới hạn ' . $maxRows . ' dòng.'];
        foreach ($rows as $row) if (is_array($row) && count($row) > $maxCols) return ['ok' => false, 'message' => 'Vượt giới hạn ' . $maxCols . ' cột.'];
        return ['ok' => true];
    }

    public static function storeUploadedSpreadsheet($fileField, $index = null, $sheetIndex = 0, $maxRows = 50000, $maxCols = 1000): array {
        $allowedExtensions = ['csv', 'xlsx'];
        $maxSizeBytes = 10 * 1024 * 1024;
        $uploadDir = Config::uploadsDir();
        if (!self::ensureUploadDirectory($uploadDir)) return ['ok' => false, 'message' => 'Thư mục uploads không thể ghi.'];

        if ($index === null) {
            $error = $_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE;
            $tmpName = $_FILES[$fileField]['tmp_name'] ?? '';
            $size = (int) ($_FILES[$fileField]['size'] ?? 0);
            $name = $_FILES[$fileField]['name'] ?? '';
        } else {
            $error = $_FILES[$fileField]['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            $tmpName = $_FILES[$fileField]['tmp_name'][$index] ?? '';
            $size = (int) ($_FILES[$fileField]['size'][$index] ?? 0);
            $name = $_FILES[$fileField]['name'][$index] ?? '';
        }

        if ($error !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) return ['ok' => false, 'message' => 'Lỗi tải tệp.'];
        if ($size <= 0 || $size > $maxSizeBytes) return ['ok' => false, 'message' => 'Kích thước tệp quá lớn (Max 10MB).'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) return ['ok' => false, 'message' => 'Chỉ hỗ trợ CSV/XLSX.'];

        $limitsCheck = self::validateUploadedSpreadsheetLimits($tmpName, $ext, (int) $sheetIndex, (int) $maxRows, (int) $maxCols);
        if (!$limitsCheck['ok']) return $limitsCheck;

        $filename = 'up_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        if (!move_uploaded_file($tmpName, $targetPath)) return ['ok' => false, 'message' => 'Lưu tệp thất bại.'];
        if (!FileCrypto::encryptFileInPlace($targetPath)) {
            @unlink($targetPath);
            return ['ok' => false, 'message' => 'Không thể bảo vệ tệp đã tải lên.'];
        }
        return ['ok' => true, 'path' => 'uploads/' . $filename];
    }

    public static function normalizeSheetName($value): string {
        return trim((string) $value);
    }

    public static function getFileUsageMap(array $config): array {
        $usageMap = [];
        foreach (($config['periods'] ?? []) as $period) {
            $localFile = $period['local_file'] ?? '';
            if (!is_string($localFile) || $localFile === '') {
                continue;
            }
            $name = basename($localFile);
            if (!isset($usageMap[$name])) {
                $usageMap[$name] = [];
            }
            $sheetLabel = self::normalizeSheetName($period['sheet_name'] ?? '');
            $usageLine = 'Kỳ lương: ' . ($period['label'] ?? 'Không tên');
            if ($sheetLabel !== '') {
                $usageLine .= ' (' . $sheetLabel . ')';
            } else {
                $usageLine .= ' (sheet ' . (int) ($period['sheet_index'] ?? 0) . ')';
            }
            $usageMap[$name][] = $usageLine;
        }

        $authLocalFile = $config['auth_local_file'] ?? '';
        if (is_string($authLocalFile) && $authLocalFile !== '') {
            $name = basename($authLocalFile);
            if (!isset($usageMap[$name])) {
                $usageMap[$name] = [];
            }
            $usageMap[$name][] = 'Xác thực nhân sự';
        }

        return $usageMap;
    }

    public static function cleanupStaleUploadedFiles(array $config, int $retentionDays = self::STALE_FILE_RETENTION_DAYS): array {
        $deleted = [];
        $errors = [];
        $retentionDays = max(1, $retentionDays);
        $cutoffTs = time() - ($retentionDays * 86400);

        $uploadDir = Config::uploadsDir();
        $backupDir = $uploadDir . 'backups' . DIRECTORY_SEPARATOR;

        $usageMap = self::getFileUsageMap($config);
        $activeBasenames = array_fill_keys(array_keys($usageMap), true);

        $candidates = glob($uploadDir . '*');
        if ($candidates !== false) {
            foreach ($candidates as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $name = basename($path);
                $isManagedUpload = preg_match('/^(auth_[a-f0-9]{16}\.xlsx|up_[a-f0-9]{16}\.(xlsx|csv))$/i', $name) === 1;
                if (!$isManagedUpload) {
                    continue;
                }
                if (isset($activeBasenames[$name])) {
                    continue;
                }
                $mtime = @filemtime($path);
                if ($mtime === false || $mtime > $cutoffTs) {
                    continue;
                }
                if (@unlink($path)) {
                    $deleted[] = 'uploads/' . $name;
                } else {
                    $errors[] = 'Không thể xóa file cũ: uploads/' . $name;
                }
            }
        }

        $backups = glob($backupDir . 'auth_backup_*.xlsx');
        if ($backups !== false) {
            foreach ($backups as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $mtime = @filemtime($path);
                if ($mtime === false || $mtime > $cutoffTs) {
                    continue;
                }
                if (@unlink($path)) {
                    $deleted[] = 'uploads/backups/' . basename($path);
                } else {
                    $errors[] = 'Không thể xóa backup cũ: uploads/backups/' . basename($path);
                }
            }
        }

        $summary = [
            'retention_days' => $retentionDays,
            'deleted_count' => count($deleted),
            'error_count' => count($errors),
            'deleted' => $deleted,
            'errors' => $errors,
        ];

        if ($summary['deleted_count'] > 0 || $summary['error_count'] > 0) {
            Security::auditLog('uploaded_files_cleanup', $summary);
        } else {
            Security::appLog('info', 'uploaded_files_cleanup_noop', [
                'retention_days' => $retentionDays,
            ]);
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    public static function isUploadedFileInUse(array $config, string $filename): bool {
        $usageMap = self::getFileUsageMap($config);
        return isset($usageMap[basename($filename)]);
    }

    public static function inspectSpreadsheetSheets(string $fileField = 'period_file', ?string $existingRelativePath = null): array {
        $tmpPath = '';
        $displayName = '';
        $ext = '';

        $uploadError = $_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE;
        $uploadTmp = $_FILES[$fileField]['tmp_name'] ?? '';
        $uploadName = $_FILES[$fileField]['name'] ?? '';

        if ($uploadError === UPLOAD_ERR_OK && $uploadTmp !== '' && is_uploaded_file($uploadTmp)) {
            $tmpPath = $uploadTmp;
            $displayName = $uploadName;
            $ext = strtolower(pathinfo($uploadName, PATHINFO_EXTENSION));
        } elseif (is_string($existingRelativePath) && trim($existingRelativePath) !== '') {
            $resolved = AuthActions::resolveUploadFilePath($existingRelativePath);
            if ($resolved === false) {
                return ['ok' => false, 'message' => 'File dữ liệu hiện có không hợp lệ hoặc không tồn tại.'];
            }
            $tmpPath = $resolved;
            $displayName = basename($resolved);
            $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        } else {
            return ['ok' => false, 'message' => 'Vui lòng chọn file để đọc danh sách sheet.'];
        }

        if ($ext === 'csv') {
            try {
                $meta = SpreadsheetReader::getLocalSheetMetadata($tmpPath, (int) Config::getEnvValue('LOCAL_META_CACHE_TTL', 300), (string) $ext);
                $csvSheet = $meta['sheets'][0] ?? ['rows' => 0, 'cols' => 0];
            } catch (\Throwable $e) {
                $csvSheet = ['rows' => 0, 'cols' => 0];
            }
            return [
                'ok' => true,
                'file_label' => $displayName,
                'sheets' => [
                    [
                        'index' => 0,
                        'name' => 'CSV (sheet duy nhất)',
                        'rows' => (int) ($csvSheet['rows'] ?? 0),
                        'cols' => (int) ($csvSheet['cols'] ?? 0),
                    ],
                ],
            ];
        }

        if ($ext === 'xls') {
            return ['ok' => false, 'message' => 'File .xls chưa được hỗ trợ. Vui lòng chuyển sang .xlsx hoặc .csv.'];
        }

        if ($ext !== 'xlsx') {
            return ['ok' => false, 'message' => 'Chỉ hỗ trợ đọc sheet từ file XLSX/CSV.'];
        }

        try {
            $meta = SpreadsheetReader::getLocalSheetMetadata($tmpPath, (int) Config::getEnvValue('LOCAL_META_CACHE_TTL', 300), (string) $ext);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $sheets = [];
        foreach (($meta['sheets'] ?? []) as $sheet) {
            $sheets[] = [
                'index' => (int) ($sheet['index'] ?? 0),
                'name' => (string) ($sheet['name'] ?? 'Sheet 1'),
                'rows' => (int) ($sheet['rows'] ?? 0),
                'cols' => (int) ($sheet['cols'] ?? 0),
            ];
        }

        if ($sheets === []) {
            $sheets[] = ['index' => 0, 'name' => 'Sheet 1'];
        }

        return [
            'ok' => true,
            'file_label' => $displayName,
            'sheets' => $sheets,
        ];
    }

    public static function handle(&$config): array {
        $action = $_POST['action'] ?? '';
        if ($action === 'login') {
            $pass = $_POST['admin_pass'] ?? '';
            $storedPass = $config['admin_password'] ?? '';
            $isStoredHash = self::isPasswordHashString($storedPass);
            $isValid = $isStoredHash ? password_verify($pass, $storedPass) : ($pass === $storedPass);
            if ($isValid) {
                session_regenerate_id(true);
                $_SESSION['hr_admin'] = true;
                $_SESSION['admin_last_activity'] = time();
                if (!$isStoredHash && $pass !== '') {
                    $config['admin_password'] = password_hash($pass, PASSWORD_DEFAULT);
                    if (Config::saveConfig($config)) {
                        Security::auditLog('admin_password_auto_migrated');
                    } else {
                        Security::appLog('warning', 'admin_password_auto_migrate_failed');
                    }
                }
                Security::auditLog('admin_login_success');
                return ['msg' => 'Đăng nhập thành công!', 'type' => 'success'];
            }
            Security::auditLog('admin_login_failed');
            return ['msg' => 'Mật khẩu không đúng.', 'type' => 'error'];
        }

        if ($action === 'logout') {
            unset($_SESSION['hr_admin']);
            return ['msg' => 'Đã đăng xuất.', 'type' => 'success'];
        }

        if (empty($_SESSION['hr_admin'])) return ['msg' => 'Unauthorized', 'type' => 'error'];

        if ($action === 'save_config_all') {
            foreach (['site_company','site_logo_text','site_hero_title','site_hero_desc','site_footer','employee_notice'] as $key) {
                if (isset($_POST[$key])) $config[$key] = trim($_POST[$key]);
            }
            foreach (['col_emp_id','col_password','col_emp_name','col_department','stat_cols'] as $key) {
                if (isset($_POST[$key])) $config[$key] = trim($_POST[$key]);
            }
            $config['check_api_url'] = trim((string) ($_POST['check_api_url'] ?? $config['check_api_url'] ?? ''));
            $config['check_enabled'] = self::normalizePeriodEnabled($_POST['check_enabled'] ?? ($config['check_enabled'] ?? true));
            $config['check_available_from'] = self::normalizePublishDate($_POST['check_available_from'] ?? ($config['check_available_from'] ?? ''));
            $config['check_available_until'] = self::normalizePublishDate($_POST['check_available_until'] ?? ($config['check_available_until'] ?? ''));
            $config['check_month_days'] = trim((string) ($_POST['check_month_days'] ?? ($config['check_month_days'] ?? '')));
            $shareTtlHours = (int) ($_POST['payroll_share_ttl_hours'] ?? ($config['payroll_share_ttl_hours'] ?? 2));
            $config['payroll_share_ttl_hours'] = max(1, min(2, $shareTtlHours));
            $config['payroll_share_enabled'] = self::normalizePeriodEnabled($_POST['payroll_share_enabled'] ?? ($config['payroll_share_enabled'] ?? true));
            $sessionTimeout = (int) ($_POST['employee_session_timeout_minutes'] ?? ($config['employee_session_timeout_minutes'] ?? 30));
            $config['employee_session_timeout_minutes'] = max(5, min(120, $sessionTimeout));

            $config['auth_source_type'] = $_POST['auth_source_type'] ?? $config['auth_source_type'] ?? 'google';
            $config['auth_sheet_id'] = trim($_POST['auth_sheet_id'] ?? $config['auth_sheet_id'] ?? '');
            $config['auth_gid'] = trim($_POST['auth_gid'] ?? $config['auth_gid'] ?? '0');
            if ($config['auth_source_type'] === 'local' && !empty($_FILES['auth_file']['name'])) {
                $up = self::storeUploadedSpreadsheet('auth_file');
                if ($up['ok']) $config['auth_local_file'] = $up['path'];
                else return ['msg' => 'Lỗi file xác thực: ' . $up['message'], 'type' => 'error'];
            }

            $periods = [];
            if (isset($_POST['period_labels']) && is_array($_POST['period_labels'])) {
                foreach ($_POST['period_labels'] as $idx => $label) {
                    if (trim($label) === '') continue;
                    $sourceType = $_POST['period_source_types'][$idx] ?? 'google';
                    $localFile  = $_POST['period_local_files'][$idx] ?? '';
                    $sheetIndex = (int) ($_POST['period_sheet_indexes'][$idx] ?? 0);
                    $sheetName = self::normalizeSheetName($_POST['period_sheet_names'][$idx] ?? '');
                    if ($sourceType === 'local' && !empty($_FILES['period_files']['name'][$idx])) {
                        $up = self::storeUploadedSpreadsheet('period_files', $idx, $sheetIndex);
                        if ($up['ok']) $localFile = $up['path'];
                        else return ['msg' => 'Lỗi file kỳ lương [' . $label . ']: ' . $up['message'], 'type' => 'error'];
                    }
                    if ($sourceType === 'local') {
                        $resolvedLocalFile = AuthActions::resolveUploadFilePath($localFile);
                        if (!$resolvedLocalFile) {
                            return ['msg' => 'File local của kỳ [' . $label . '] không hợp lệ hoặc không tồn tại.', 'type' => 'error'];
                        }

                        try {
                            $sheetIndex = SpreadsheetReader::resolveSheetIndex(
                                (string) $resolvedLocalFile,
                                $sheetName,
                                $sheetIndex,
                                (int) Config::getEnvValue('LOCAL_META_CACHE_TTL', 300)
                            );
                            $meta = SpreadsheetReader::getLocalSheetMetadata((string) $resolvedLocalFile, (int) Config::getEnvValue('LOCAL_META_CACHE_TTL', 300));
                            $sheetInfo = $meta['sheets'][$sheetIndex] ?? null;
                            if (is_array($sheetInfo) && isset($sheetInfo['name'])) {
                                $sheetName = self::normalizeSheetName((string) $sheetInfo['name']);
                            }
                            $periodRows = SpreadsheetReader::fromLocalFile(
                                (string) $resolvedLocalFile,
                                $sheetIndex,
                                (int) Config::getEnvValue('PERIOD_MAX_ROWS', 50000),
                                (int) Config::getEnvValue('PERIOD_MAX_COLS', 1000)
                            );
                            $validation = SpreadsheetSchemaValidator::validatePeriodDataset(
                                $periodRows,
                                $config,
                                [
                                    'cols' => trim($_POST['period_cols'][$idx] ?? ''),
                                    'highlight_cols' => trim($_POST['period_highlight_cols'][$idx] ?? ''),
                                    'money_cols' => trim($_POST['period_money_cols'][$idx] ?? ''),
                                ]
                            );
                            if (!$validation['ok']) {
                                return ['msg' => 'Lỗi schema kỳ [' . $label . ']: ' . ($validation['message'] ?? 'Không rõ nguyên nhân.'), 'type' => 'error'];
                            }
                        } catch (\Throwable $e) {
                            return ['msg' => 'Không thể đọc dữ liệu kỳ [' . $label . ']: ' . $e->getMessage(), 'type' => 'error'];
                        }
                    } else {
                        $localFile = '';
                        $sheetName = '';
                    }
                    $periods[] = [
                        'label' => trim($label),
                        'source_type' => $sourceType,
                        'local_file' => $localFile,
                        'sheet_id' => trim($_POST['period_sheet_ids'][$idx] ?? ''),
                        'gid' => trim($_POST['period_gids'][$idx] ?? '0'),
                        'cols' => trim($_POST['period_cols'][$idx] ?? ''),
                        'highlight_cols' => trim($_POST['period_highlight_cols'][$idx] ?? ''),
                        'money_cols' => trim($_POST['period_money_cols'][$idx] ?? ''),
                        'sheet_index' => $sheetIndex,
                        'sheet_name' => $sheetName,
                        'publish_date' => self::normalizePublishDate($_POST['period_publish_dates'][$idx] ?? ''),
                        'enabled' => self::normalizePeriodEnabled($_POST['period_enableds'][$idx] ?? '1'),
                    ];
                }
            }
            $config['periods'] = $periods;

            $newPass = trim($_POST['new_admin_pass'] ?? '');
            if ($newPass !== '') {
                $config['admin_password'] = password_hash($newPass, PASSWORD_DEFAULT);
                Security::auditLog('admin_change_password');
            }

            if (Config::saveConfig($config)) {
                self::cleanupStaleUploadedFiles($config);
                Security::auditLog('admin_save_all_config');
                return ['msg' => 'Đã lưu toàn bộ cấu hình hệ thống!', 'type' => 'success'];
            }
            return ['msg' => 'Lỗi khi lưu cấu hình file.', 'type' => 'error'];
        }

        return ['msg' => 'Unknown Action', 'type' => 'error'];
    }
}
