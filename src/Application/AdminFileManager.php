<?php

declare(strict_types=1);

namespace App\Application;

use App\Config;
use App\Security;
use App\Application\AuthActions;
use App\Services\SpreadsheetReader;
use App\Services\SpreadsheetWriter;
use App\Services\FileCrypto;
use Shuchkin\SimpleXLSX;
use Throwable;

/**
 * AdminFileManager — Xử lý Upload / Download / Preview / Get / Save cho auth file.
 *
 * Các action được route trong admin.php:
 *   POST ajax_action=upload_auth_file
 *   GET  action=download_auth_file&csrf_token=...
 *   POST ajax_action=preview_auth_file
 *   POST ajax_action=get_auth_data
 *   POST ajax_action=save_auth_data
 */
class AdminFileManager
{
    private function __construct() {}

    // ─── Constants ─────────────────────────────────────────────────────────────
    private const MAX_UPLOAD_BYTES  = 10 * 1024 * 1024; // 10 MB
    private const STALE_RETENTION_DAYS = 15;             // Giữ file cũ trong 15 ngày
    private const PENDING_UPLOAD_RETENTION_HOURS = 12;

    // Các cột bắt buộc phải có trong header file xác thực
    private const REQUIRED_HEADERS  = ['MÃ NV', 'MẬT KHẨU'];

    // Backup dir (relative to project root)
    private const BACKUP_SUBDIR     = 'uploads/backups/';

    // ─── Upload Auth File ───────────────────────────────────────────────────────

    /**
     * Xử lý upload file xác thực (.xlsx).
     * Validate → Backup → Save → Cập nhật config → Audit log.
     *
     * @param array $config Config hiện tại (by reference để cập nhật auth_local_file)
     * @return array {ok, message, filename?, backup?}
     */
    public static function uploadAuthFile(array &$config): array
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);
        @ignore_user_abort(true);

        $uploadDir = self::getUploadDir();
        $backupDir = self::getBackupDir();
        self::prunePendingUploads();

        if (!self::ensureDir($uploadDir) || !self::ensureDir($backupDir)) {
            return ['ok' => false, 'message' => 'Thư mục uploads không thể ghi.'];
        }

        // Validate upload
        $error   = $_FILES['auth_file']['error']  ?? UPLOAD_ERR_NO_FILE;
        $tmpName = $_FILES['auth_file']['tmp_name'] ?? '';
        $size    = (int) ($_FILES['auth_file']['size']  ?? 0);
        $name    = $_FILES['auth_file']['name']   ?? '';

        if ($error !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['ok' => false, 'message' => 'Lỗi tải tệp lên (code: ' . $error . ').'];
        }
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            return ['ok' => false, 'message' => 'Kích thước tệp không hợp lệ (tối đa 10 MB).'];
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            return ['ok' => false, 'message' => 'Chỉ chấp nhận file .xlsx.'];
        }

        // Validate header
        $headerCheck = self::validateAuthFileHeader($tmpName);
        if (!$headerCheck['ok']) {
            return $headerCheck;
        }

        // Đọc dữ liệu file upload mới
        $incoming = self::readAuthDataset($tmpName);
        if (!$incoming['ok']) {
            return ['ok' => false, 'message' => (string) ($incoming['message'] ?? 'Không đọc được dữ liệu file mới.')];
        }

        $duplicateGroups = self::findDuplicateEmployeeIds(
            $incoming['dataset']['headers'],
            $incoming['dataset']['rows'],
            $config,
            $incoming['dataset']['source_row_numbers'] ?? []
        );
        if ($duplicateGroups !== []) {
            $pending = self::stagePendingUpload($tmpName);
            if ($pending === null) {
                return ['ok' => false, 'message' => 'Không thể lưu file tạm để xử lý trùng mã nhân viên.'];
            }

            return [
                'ok' => true,
                'requires_resolution' => true,
                'message' => 'Phát hiện mã nhân viên bị trùng trong file mới. Hãy chọn dòng cần giữ lại trước khi import.',
                'resolution_token' => $pending['token'],
                'headers' => $incoming['dataset']['headers'],
                'duplicate_groups' => $duplicateGroups,
                'duplicate_group_count' => count($duplicateGroups),
                'duplicate_employee_count' => array_sum(array_map(static fn(array $group): int => count($group['rows'] ?? []), $duplicateGroups)),
            ];
        }

        return self::finalizeAuthDatasetUpload($config, $incoming['dataset']);
    }

    public static function migrateCurrentAuthPasswordsToHash(array &$config): array
    {
        $currentFile = (string) ($config['auth_local_file'] ?? '');
        if ($currentFile === '') {
            return ['ok' => false, 'message' => 'Chưa có file xác thực local để migrate.'];
        }
        $currentAbs = self::resolveFilePath($currentFile);
        if ($currentAbs === false || !is_file($currentAbs)) {
            return ['ok' => false, 'message' => 'File xác thực hiện tại không tồn tại hoặc không hợp lệ.'];
        }

        $uploadDir = self::getUploadDir();
        $backupDir = self::getBackupDir();
        if (!self::ensureDir($uploadDir) || !self::ensureDir($backupDir)) {
            return ['ok' => false, 'message' => 'Thư mục uploads/backups không thể ghi.'];
        }

        $read = self::readAuthDataset($currentAbs);
        if (!$read['ok']) {
            return ['ok' => false, 'message' => (string) ($read['message'] ?? 'Không đọc được file xác thực hiện tại.')];
        }
        $dataset = $read['dataset'];
        $passHeader = AuthActions::normalizeHeaderValue((string) ($config['col_password'] ?? 'MẬT KHẨU'));
        $passIdx = array_search($passHeader, $dataset['normalized_headers'], true);
        if ($passIdx === false) {
            return ['ok' => false, 'message' => "Không tìm thấy cột mật khẩu '{$passHeader}'."];
        }

        $hashedCount = 0;
        foreach ($dataset['rows'] as &$row) {
            $raw = trim((string) ($row[$passIdx] ?? ''));
            if ($raw === '' || self::isPasswordHashString($raw)) {
                continue;
            }
            $row[$passIdx] = password_hash($raw, PASSWORD_BCRYPT, ['cost' => 4]);
            $hashedCount++;
        }
        unset($row);

        if ($hashedCount === 0) {
            return ['ok' => true, 'message' => 'Không có mật khẩu plaintext cần migrate.', 'hashed_count' => 0];
        }

        $backupPath = self::createBackup($currentAbs, $backupDir);
        self::pruneBackupsOlderThan($backupDir, self::STALE_RETENTION_DAYS);

        $allRows = array_merge([$dataset['headers']], $dataset['rows']);
        $newFilename = 'auth_' . bin2hex(random_bytes(8)) . '.xlsx';
        $newPath = $uploadDir . $newFilename;
        if (!SpreadsheetWriter::toXlsx($allRows, $newPath)) {
            return ['ok' => false, 'message' => 'Không thể ghi file mới sau migrate.'];
        }
        @chmod($newPath, 0600);

        $config['auth_local_file']  = 'uploads/' . $newFilename;
        $config['auth_source_type'] = 'local';
        if (!Config::saveConfig($config)) {
            @unlink($newPath);
            return ['ok' => false, 'message' => 'Migrate xong nhưng lưu config thất bại.'];
        }

        AdminActions::cleanupStaleUploadedFiles($config, self::STALE_RETENTION_DAYS);
        Security::auditLog('auth_passwords_migrated_to_hash', [
            'hashed_count' => $hashedCount,
            'filename' => $newFilename,
            'backup' => $backupPath ? basename($backupPath) : null,
        ]);

        return [
            'ok' => true,
            'message' => 'Đã migrate mật khẩu sang hash thành công.',
            'hashed_count' => $hashedCount,
            'filename' => $newFilename,
            'backup' => $backupPath ? basename($backupPath) : null,
        ];
    }

    public static function resolvePendingAuthUpload(array &$config, string $token, array $keepRowIndexes): array
    {
        self::prunePendingUploads();
        $pendingPath = self::resolvePendingUploadPath($token);
        if ($pendingPath === false || !is_file($pendingPath)) {
            return ['ok' => false, 'message' => 'Phiên xử lý file tạm đã hết hạn hoặc không tồn tại. Vui lòng tải lại file.'];
        }

        $incoming = self::readAuthDataset($pendingPath);
        if (!$incoming['ok']) {
            @unlink($pendingPath);
            return ['ok' => false, 'message' => (string) ($incoming['message'] ?? 'Không đọc được file tạm để xử lý trùng.')];
        }

        $dataset = $incoming['dataset'];
        $duplicateGroups = self::findDuplicateEmployeeIds(
            $dataset['headers'],
            $dataset['rows'],
            $config,
            $dataset['source_row_numbers'] ?? []
        );

        if ($duplicateGroups === []) {
            $result = self::finalizeAuthDatasetUpload($config, $dataset);
            @unlink($pendingPath);
            return $result;
        }

        $selectedIndexes = [];
        foreach ($keepRowIndexes as $rowIndex) {
            if (!is_scalar($rowIndex) || !preg_match('/^\d+$/', (string) $rowIndex)) {
                continue;
            }
            $selectedIndexes[(int) $rowIndex] = true;
        }

        $requiredSelections = [];
        foreach ($duplicateGroups as $group) {
            $groupRowIndexes = [];
            foreach (($group['rows'] ?? []) as $rowMeta) {
                $groupRowIndexes[] = (int) ($rowMeta['row_index'] ?? -1);
            }
            $matchedSelections = array_values(array_filter($groupRowIndexes, static fn(int $idx): bool => isset($selectedIndexes[$idx])));
            if (count($matchedSelections) !== 1) {
                return ['ok' => false, 'message' => 'Mỗi mã nhân viên trùng phải chọn đúng 1 dòng để giữ lại.'];
            }
            $requiredSelections[$matchedSelections[0]] = true;
        }

        $duplicateRowIndexSet = [];
        foreach ($duplicateGroups as $group) {
            foreach (($group['rows'] ?? []) as $rowMeta) {
                $duplicateRowIndexSet[(int) ($rowMeta['row_index'] ?? -1)] = true;
            }
        }

        $filteredRows = [];
        $filteredRowNumbers = [];
        foreach ($dataset['rows'] as $rowIdx => $row) {
            $rowIdx = (int) $rowIdx;
            if (isset($duplicateRowIndexSet[$rowIdx]) && !isset($requiredSelections[$rowIdx])) {
                continue;
            }
            $filteredRows[] = $row;
            $filteredRowNumbers[] = $dataset['source_row_numbers'][$rowIdx] ?? ($rowIdx + 2);
        }

        $dataset['rows'] = $filteredRows;
        $dataset['source_row_numbers'] = $filteredRowNumbers;
        $result = self::finalizeAuthDatasetUpload($config, $dataset);
        @unlink($pendingPath);
        return $result;
    }

    public static function discardPendingAuthUpload(string $token): array
    {
        self::prunePendingUploads();
        $pendingPath = self::resolvePendingUploadPath($token);
        if ($pendingPath !== false && is_file($pendingPath)) {
            @unlink($pendingPath);
        }
        return ['ok' => true];
    }

    // ─── Download Auth File ─────────────────────────────────────────────────────

    /**
     * Gửi file xác thực về trình duyệt (download).
     * Phải được gọi sớm trước khi output HTML.
     */
    public static function downloadAuthFile(array $config): void
    {
        $localFile = $config['auth_local_file'] ?? '';
        if ($localFile === '') {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Chưa có file xác thực nào được cấu hình.']);
            exit;
        }

        $filePath = self::resolveFilePath($localFile);
        if ($filePath === false || !is_file($filePath)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'File xác thực không tồn tại.']);
            exit;
        }

        $basename = 'auth_file.xlsx';
        $contents = FileCrypto::readFileContents($filePath);
        if (!is_string($contents)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Không thể đọc file xác thực để tải xuống.']);
            exit;
        }
        $size = strlen($contents);

        Security::auditLog('admin_download_auth_file', ['filename' => basename($filePath)]);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $basename . '"');
        header('Content-Length: ' . $size);
        header('Cache-Control: private, no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        echo $contents;
        exit;
    }

    // ─── Preview Auth File ──────────────────────────────────────────────────────

    /**
     * Trả về tối đa 5 dòng đầu của file xác thực dạng JSON.
     */
    public static function previewAuthFile(array $config): array
    {
        $localFile = $config['auth_local_file'] ?? '';
        if ($localFile === '') {
            return ['ok' => false, 'message' => 'Chưa có file xác thực.'];
        }

        $filePath = self::resolveFilePath($localFile);
        if ($filePath === false) {
            return ['ok' => false, 'message' => 'File không hợp lệ hoặc không tồn tại.'];
        }

        try {
            $allRows = SpreadsheetReader::fromLocalFile($filePath, 0, 20, 200, 'xlsx');
            $preview = array_slice($allRows, 0, 5);
            $headers = $preview[0] ?? [];
            $rows    = array_slice($preview, 1);

            return ['ok' => true, 'headers' => $headers, 'rows' => $rows];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Lỗi đọc file: ' . $e->getMessage()];
        }
    }

    // ─── Get Auth Data (Web Editor) ────────────────────────────────────────────

    /**
     * Trả toàn bộ dữ liệu auth file dạng JSON để hiển thị trong web editor.
     * Tối đa 2000 dòng; nếu vượt vẫn cho edit nhưng kèm cảnh báo.
     */
    public static function getAuthData(array $config): array
    {
        $localFile = $config['auth_local_file'] ?? '';
        if ($localFile === '') {
            return ['ok' => false, 'message' => 'Chưa có file xác thực. Vui lòng upload file trước.'];
        }

        $filePath = self::resolveFilePath($localFile);
        if ($filePath === false) {
            return ['ok' => false, 'message' => 'File không tồn tại hoặc đường dẫn không hợp lệ.'];
        }

        try {
            $rows = SpreadsheetReader::fromLocalFile($filePath, 0, 5000, 500);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Lỗi đọc file: ' . $e->getMessage()];
        }

        if (empty($rows)) {
            return ['ok' => false, 'message' => 'File xác thực không có dữ liệu.'];
        }

        $headers   = array_values((array) ($rows[0] ?? []));
        $dataRows  = array_slice($rows, 1);
        $totalRows = count($dataRows);
        $warning   = null;

        if ($totalRows > 2000) {
            $warning  = 'File có ' . $totalRows . ' dòng. Chỉ hiển thị 2000 dòng đầu trong editor.';
            $dataRows = array_slice($dataRows, 0, 2000);
        } elseif ($totalRows > 500) {
            $warning  = 'File có nhiều dòng (' . $totalRows . '). Web editor có thể hơi chậm.';
        }

        // Normalize: trả về mảng các mảng đã index lại
        $normalized = [];
        foreach ($dataRows as $row) {
            $normalized[] = array_values((array) $row);
        }

        return [
            'ok'         => true,
            'headers'    => $headers,
            'rows'       => $normalized,
            'total_rows' => $totalRows,
            'warning'    => $warning,
            'filename'   => basename($localFile),
        ];
    }

    // ─── Save Auth Data (Web Editor) ───────────────────────────────────────────

    /**
     * Nhận dữ liệu JSON từ web editor, validate, backup, ghi ra file .xlsx mới.
     *
     * @param array $config Config hiện tại (by reference)
     * @param array $payload Dữ liệu POST đã parse: {headers, rows}
     * @return array {ok, message, rows_saved?, backup?}
     */
    public static function saveAuthData(array &$config, array $payload): array
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);
        @ignore_user_abort(true);

        $headers = $payload['headers'] ?? [];
        $rows    = $payload['rows']    ?? [];

        if (!is_array($headers) || !is_array($rows)) {
            return ['ok' => false, 'message' => 'Dữ liệu không hợp lệ.'];
        }

        // Validate headers
        $normalizedHeaders = array_map([AuthActions::class, 'normalizeHeaderValue'], $headers);
        foreach (self::REQUIRED_HEADERS as $required) {
            if (!in_array($required, $normalizedHeaders, true)) {
                return ['ok' => false, 'message' => "Header bắt buộc '{$required}' bị thiếu."];
            }
        }

        // Tìm index cột MÃ NV
        $empIdColIdx = array_search('MÃ NV', $normalizedHeaders, true);
        $passColIdx = array_search('MẬT KHẨU', $normalizedHeaders, true);

        // Validate từng dòng
        foreach ($rows as $rIdx => &$row) {
            $rowNum = $rIdx + 2; // +2 vì dòng 1 là header
            if (!is_array($row)) {
                return ['ok' => false, 'message' => "Dòng {$rowNum} không hợp lệ."];
            }
            // Kiểm tra MÃ NV không được trống
            if ($empIdColIdx !== false) {
                $empId = trim((string) ($row[$empIdColIdx] ?? ''));
                if ($empId === '') {
                    return ['ok' => false, 'message' => "Dòng {$rowNum}: Mã NV không được để trống."];
                }
            }
            // Chống formula injection trong mọi ô
            foreach ($row as $cIdx => $cell) {
                $cellStr = (string) $cell;
                if ($cellStr !== '' && in_array($cellStr[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $colLetter = chr(65 + min((int) $cIdx, 25));
                    return ['ok' => false, 'message' => "Dòng {$rowNum}, cột {$colLetter}: Nội dung ô không được bắt đầu bằng ký tự đặc biệt (=, +, -, @)."];
                }
            }

            if ($passColIdx !== false) {
                $rawPassword = trim((string) ($row[$passColIdx] ?? ''));
                if ($rawPassword !== '') {
                    $row[$passColIdx] = $rawPassword;
                }
            }
        }
        unset($row);

        if (empty($rows)) {
            return ['ok' => false, 'message' => 'Không có dòng dữ liệu nào để lưu.'];
        }

        $duplicateGroups = self::findDuplicateEmployeeIds($headers, $rows, $config);
        if ($duplicateGroups !== []) {
            $duplicateIds = array_map(static fn(array $group): string => (string) ($group['emp_id'] ?? ''), $duplicateGroups);
            return [
                'ok' => false,
                'message' => 'Phát hiện Mã NV bị trùng trong bảng chỉnh sửa: ' . implode(', ', array_filter($duplicateIds)) . '. Vui lòng xóa bớt trước khi lưu.',
            ];
        }

        // Chuẩn bị dirs
        $uploadDir = self::getUploadDir();
        $backupDir = self::getBackupDir();
        if (!self::ensureDir($uploadDir) || !self::ensureDir($backupDir)) {
            return ['ok' => false, 'message' => 'Thư mục lưu file không thể ghi.'];
        }

        // Backup file hiện tại
        $backupBasename = null;
        $currentFile    = $config['auth_local_file'] ?? '';
        if ($currentFile !== '') {
            $currentAbs = self::resolveFilePath($currentFile);
            if ($currentAbs !== false && is_file($currentAbs)) {
                $bp = self::createBackup($currentAbs, $backupDir);
                if ($bp) {
                    $backupBasename = basename($bp);
                    self::pruneBackupsOlderThan($backupDir, self::STALE_RETENTION_DAYS);
                }
            }
        }

        // Build rows to write (header + data)
        $allRows = array_merge([$headers], $rows);

        // Lưu file mới
        $newFilename = 'auth_' . bin2hex(random_bytes(8)) . '.xlsx';
        $newPath     = $uploadDir . $newFilename;

        if (!SpreadsheetWriter::toXlsx($allRows, $newPath)) {
            return ['ok' => false, 'message' => 'Ghi file XLSX thất bại.'];
        }

        // Cập nhật config
        $config['auth_local_file']  = 'uploads/' . $newFilename;
        $config['auth_source_type'] = 'local';
        if (!Config::saveConfig($config)) {
            @unlink($newPath);
            return ['ok' => false, 'message' => 'Ghi file thành công nhưng lỗi lưu cấu hình.'];
        }
        AdminActions::cleanupStaleUploadedFiles($config, self::STALE_RETENTION_DAYS);

        Security::auditLog('admin_save_auth_data_web', [
            'filename'  => $newFilename,
            'rows_saved' => count($rows),
        ]);

        return [
            'ok'        => true,
            'message'   => 'Đã lưu ' . count($rows) . ' nhân viên vào file xác thực!',
            'rows_saved' => count($rows),
            'filename'  => $newFilename,
            'backup'    => $backupBasename,
        ];
    }

    public static function searchAuthEmployees(array $config, string $query, int $limit = 8): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['ok' => true, 'employees' => []];
        }

        $datasetResult = self::loadConfiguredAuthDataset($config);
        if (!$datasetResult['ok']) {
            return ['ok' => false, 'message' => (string) ($datasetResult['message'] ?? 'Không đọc được dữ liệu xác thực.')];
        }

        $dataset = $datasetResult['dataset'];
        $nameIdx = self::findConfiguredHeaderIndex($dataset['normalized_headers'], [
            (string) ($config['col_emp_name'] ?? 'HỌ TÊN'),
            'HỌ TÊN',
            'HỌ VÀ TÊN',
            'TÊN NHÂN VIÊN',
            'TÊN NV',
            'NHÂN VIÊN',
            'FULL NAME',
            'NAME',
        ]);
        $deptIdx = self::findConfiguredHeaderIndex($dataset['normalized_headers'], [
            (string) ($config['col_department'] ?? 'BỘ PHẬN'),
            'BỘ PHẬN',
            'PHÒNG BAN',
            'ĐƠN VỊ',
            'DEPARTMENT',
        ]);
        $passwordIdx = self::findConfiguredHeaderIndex($dataset['normalized_headers'], [
            (string) ($config['col_password'] ?? 'MẬT KHẨU'),
            'MẬT KHẨU',
        ]);

        $queryKey = self::normalizeSearchText($query);
        $matches = [];
        foreach ($dataset['rows'] as $rowIdx => $row) {
            $empId = trim((string) ($row[$dataset['emp_idx']] ?? ''));
            if ($empId === '') {
                continue;
            }

            $name = $nameIdx !== false ? trim((string) ($row[$nameIdx] ?? '')) : '';
            $department = $deptIdx !== false ? trim((string) ($row[$deptIdx] ?? '')) : '';
            $score = self::scoreEmployeeMatch($queryKey, $empId, $name, $department);
            if ($score <= 0) {
                continue;
            }

            $passwordValue = $passwordIdx !== false ? trim((string) ($row[$passwordIdx] ?? '')) : '';
            $matches[] = [
                'score' => $score,
                'employee' => self::buildEmployeeLookupPayload(
                    $empId,
                    $name,
                    $department,
                    $passwordValue,
                    (int) ($dataset['source_row_numbers'][$rowIdx] ?? ($rowIdx + 2))
                ),
            ];
        }

        usort($matches, static function (array $a, array $b): int {
            if (($a['score'] ?? 0) === ($b['score'] ?? 0)) {
                return strcmp(
                    (string) ($a['employee']['emp_id_display'] ?? ''),
                    (string) ($b['employee']['emp_id_display'] ?? '')
                );
            }

            return (int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0);
        });

        $employees = array_map(
            static fn(array $item): array => $item['employee'],
            array_slice($matches, 0, max(1, min(20, $limit)))
        );

        return ['ok' => true, 'employees' => $employees];
    }

    public static function getAuthEmployeeDetail(array $config, string $empId): array
    {
        $employee = self::findEmployeeByEmpId($config, $empId);
        if ($employee === null) {
            return ['ok' => false, 'message' => 'Không tìm thấy nhân viên theo Mã NV đã chọn.'];
        }

        return ['ok' => true, 'employee' => $employee];
    }

    public static function adminVerifyEmployeeLogin(array $config, string $empId, string $password): array
    {
        $empId = trim($empId);
        if ($empId === '') {
            return ['ok' => false, 'message' => 'Vui lòng chọn nhân viên trước khi kiểm tra đăng nhập.'];
        }
        if (trim($password) === '') {
            return ['ok' => false, 'message' => 'Vui lòng nhập mật khẩu cần kiểm tra.'];
        }

        $employee = self::findEmployeeByEmpId($config, $empId);
        $verify = AuthActions::verifyUser($config, $empId, $password);

        return [
            'ok' => (bool) ($verify['success'] ?? false),
            'message' => (string) (($verify['success'] ?? false)
                ? 'Đăng nhập hợp lệ. Mã NV và mật khẩu khớp với dữ liệu xác thực hiện tại.'
                : ($verify['message'] ?? 'Đăng nhập không hợp lệ.')),
            'employee' => $employee,
        ];
    }

    public static function lookupPayrollForEmployee(array $config, string $empId, int $periodIndex): array
    {
        $empId = trim($empId);
        if ($empId === '') {
            return ['ok' => false, 'message' => 'Vui lòng chọn nhân viên trước khi tra cứu phiếu lương.'];
        }
        if ($periodIndex < 0 || !isset($config['periods'][$periodIndex]) || !is_array($config['periods'][$periodIndex])) {
            return ['ok' => false, 'message' => 'Kỳ phiếu lương không hợp lệ.'];
        }

        $period = $config['periods'][$periodIndex];
        $maxRows = (int) Config::getEnvValue('PERIOD_MAX_ROWS', 50000);
        $maxCols = (int) Config::getEnvValue('PERIOD_MAX_COLS', 1000);
        $searchCol = DataActions::normalizeHeaderValueData((string) ($config['col_emp_id'] ?? 'MÃ NV'));

        try {
            $sourceType = (string) ($period['source_type'] ?? 'google');
            if ($sourceType === 'local') {
                $localFile = (string) ($period['local_file'] ?? '');
                if ($localFile === '') {
                    throw new Error('Chưa cấu hình file dữ liệu cho kỳ lương này.');
                }
                $fullPath = DataActions::resolveUploadDataFilePath($localFile);
                if ($fullPath === false) {
                    throw new Error('File dữ liệu kỳ lương không hợp lệ hoặc không tồn tại.');
                }
                $sheetIndex = SpreadsheetReader::resolveSheetIndex(
                    $fullPath,
                    (string) ($period['sheet_name'] ?? ''),
                    (int) ($period['sheet_index'] ?? 0),
                    (int) Config::getEnvValue('LOCAL_META_CACHE_TTL', 300)
                );
                $rows = SpreadsheetReader::fromLocalFile($fullPath, $sheetIndex, $maxRows, $maxCols);
            } else {
                $sheetId = (string) ($period['sheet_id'] ?? '');
                $gid = (string) ($period['gid'] ?? '0');
                if ($sheetId === '') {
                    throw new Error('Chưa cấu hình Google Sheet ID cho kỳ lương này.');
                }
                $rows = SpreadsheetReader::fromGoogleCsv(
                    $sheetId,
                    $gid,
                    (int) Config::getEnvValue('GOOGLE_CACHE_TTL', 60),
                    $maxRows,
                    $maxCols
                );
            }

            if ($rows === []) {
                throw new Error('Dữ liệu phiếu lương trống.');
            }

            $headerIndex = -1;
            $header = [];
            $headerNormalized = [];
            foreach ($rows as $rowIndex => $row) {
                $normalized = array_map([DataActions::class, 'normalizeHeaderValueData'], (array) $row);
                if (in_array($searchCol, $normalized, true)) {
                    $headerIndex = (int) $rowIndex;
                    $header = array_values((array) $row);
                    $headerNormalized = $normalized;
                    break;
                }
            }

            if ($headerIndex === -1) {
                throw new Error("Không tìm thấy cột '{$searchCol}' trong dữ liệu kỳ lương.");
            }

            $empIdx = array_search($searchCol, $headerNormalized, true);
            if ($empIdx === false) {
                throw new Error("Không thể xác định cột '{$searchCol}' trong dữ liệu kỳ lương.");
            }

            $targetEmpKey = DataActions::normalizeEmployeeId($empId);
            $matchedRows = [];
            for ($i = $headerIndex + 1; $i < count($rows); $i++) {
                $candidate = array_values((array) $rows[$i]);
                if (DataActions::normalizeEmployeeId((string) ($candidate[$empIdx] ?? '')) !== $targetEmpKey) {
                    continue;
                }
                $matchedRows[] = $candidate;
            }

            if ($matchedRows === []) {
                return [
                    'ok' => false,
                    'message' => 'Không tìm thấy phiếu lương của nhân viên này trong kỳ đã chọn.',
                    'period_label' => (string) ($period['label'] ?? ('Kỳ #' . $periodIndex)),
                ];
            }

            return [
                'ok' => true,
                'period_label' => (string) ($period['label'] ?? ('Kỳ #' . $periodIndex)),
                'header' => $header,
                'rows' => $matchedRows,
                'matched_rows' => count($matchedRows),
            ];
        } catch (Throwable $e) {
            Security::appLog('error', 'admin_payroll_lookup_failed', [
                'error' => $e->getMessage(),
                'employee_id' => $empId,
                'period_index' => $periodIndex,
            ]);
            return ['ok' => false, 'message' => 'Dữ liệu phiếu lương chưa sẵn sàng cho tra cứu nhanh.'];
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Validate header dòng đầu của file XLSX: phải có đủ các cột bắt buộc.
     */
    public static function validateAuthFileHeader(string $tmpPath): array
    {
        $xlsx = SimpleXLSX::parse($tmpPath);
        if (!$xlsx) {
            return ['ok' => false, 'message' => 'Không đọc được file XLSX: ' . SimpleXLSX::parseError()];
        }

        $rows = $xlsx->rows(0);
        if (empty($rows)) {
            return ['ok' => false, 'message' => 'File trống, không có dữ liệu.'];
        }

        // Tìm dòng header
        $headerFound = false;
        foreach ($rows as $row) {
            $normalized = array_map([AuthActions::class, 'normalizeHeaderValue'], (array) $row);
            $missing    = [];
            foreach (self::REQUIRED_HEADERS as $req) {
                if (!in_array($req, $normalized, true)) {
                    $missing[] = $req;
                }
            }
            if (empty($missing)) {
                $headerFound = true;
                break;
            }
        }

        if (!$headerFound) {
            return [
                'ok'      => false,
                'message' => 'File thiếu cột bắt buộc: ' . implode(', ', self::REQUIRED_HEADERS) . '. Vui lòng kiểm tra lại file.',
            ];
        }

        return ['ok' => true];
    }

    /**
     * Resolve đường dẫn an toàn của file trong uploads/.
     * @return string|false Đường dẫn tuyệt đối hoặc false nếu không hợp lệ.
     */
    public static function resolveFilePath(string $relativePath)
    {
        return AuthActions::resolveUploadFilePath($relativePath);
    }

    /**
     * @param array<int, mixed> $headers
     * @param array<int, array<int, mixed>> $rows
     * @param array<string, mixed> $config
     * @param array<int, int> $sourceRowNumbers
     * @return array<int, array{emp_id:string,duplicate_count:int,rows:array<int,array{row_index:int,source_row_number:int,values:array<int,string>}>}>
     */
    public static function findDuplicateEmployeeIds(array $headers, array $rows, array $config, array $sourceRowNumbers = []): array
    {
        $normalizedHeaders = array_map([AuthActions::class, 'normalizeHeaderValue'], array_values($headers));
        $empHeader = AuthActions::normalizeHeaderValue((string) ($config['col_emp_id'] ?? 'MÃ NV'));
        $empIdx = array_search($empHeader, $normalizedHeaders, true);
        if ($empIdx === false) {
            return [];
        }

        $groups = [];
        foreach ($rows as $rowIdx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = self::normalizeEmployeeIdKey((string) ($row[$empIdx] ?? ''));
            if ($key === '') {
                continue;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = [
                'row_index' => (int) $rowIdx,
                'source_row_number' => (int) ($sourceRowNumbers[$rowIdx] ?? ($rowIdx + 2)),
                'values' => array_map(static fn($value): string => trim((string) $value), array_values($row)),
            ];
        }

        $duplicates = [];
        foreach ($groups as $empId => $items) {
            if (count($items) <= 1) {
                continue;
            }
            $duplicates[] = [
                'emp_id' => (string) $empId,
                'duplicate_count' => count($items),
                'rows' => $items,
            ];
        }

        usort($duplicates, static function (array $a, array $b): int {
            return strcmp((string) ($a['emp_id'] ?? ''), (string) ($b['emp_id'] ?? ''));
        });

        return $duplicates;
    }

    /**
     * Tạo backup file, trả về đường dẫn backup hoặc null.
     */
    private static function createBackup(string $sourceAbs, string $backupDir): ?string
    {
        $ts  = date('Ymd_His');
        $rnd = bin2hex(random_bytes(4));
        $dest = $backupDir . 'auth_backup_' . $ts . '_' . $rnd . '.xlsx';
        if (FileCrypto::copyFile($sourceAbs, $dest)) {
            return $dest;
        }
        return null;
    }

    private static function pruneBackupsOlderThan(string $backupDir, int $retentionDays): void
    {
        $retentionDays = max(1, $retentionDays);
        $cutoffTs = time() - ($retentionDays * 86400);
        $deleted = [];
        $errors = [];
        $files = glob($backupDir . 'auth_backup_*.xlsx');
        if ($files === false || $files === []) {
            return;
        }
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime <= $cutoffTs) {
                if (@unlink($file)) {
                    $deleted[] = 'uploads/backups/' . basename($file);
                } else {
                    $errors[] = 'uploads/backups/' . basename($file);
                }
            }
        }

        if ($deleted !== [] || $errors !== []) {
            Security::auditLog('auth_backups_cleanup', [
                'retention_days' => $retentionDays,
                'deleted_count' => count($deleted),
                'error_count' => count($errors),
                'deleted' => $deleted,
                'errors' => $errors,
            ]);
        }
    }

    private static function getUploadDir(): string
    {
        return Config::uploadsDir();
    }

    private static function getPendingUploadDir(): string
    {
        return Config::projectRoot() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'pending-auth-uploads' . DIRECTORY_SEPARATOR;
    }

    private static function getBackupDir(): string
    {
        return Config::uploadsDir() . 'backups' . DIRECTORY_SEPARATOR;
    }

    private static function ensureDir(string $dir): bool
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            return false;
        }
        @chmod($dir, 0700);
        return is_writable($dir);
    }

    private static function loadConfiguredAuthDataset(array $config): array
    {
        $sourceType = (string) ($config['auth_source_type'] ?? 'google');
        $maxRows = (int) Config::getEnvValue('AUTH_MAX_ROWS', 50000);
        $maxCols = (int) Config::getEnvValue('AUTH_MAX_COLS', 500);

        try {
            if ($sourceType === 'local') {
                $localFile = (string) ($config['auth_local_file'] ?? '');
                if ($localFile === '') {
                    throw new Error('Chưa cấu hình file xác thực local.');
                }
                $fullPath = self::resolveFilePath($localFile);
                if ($fullPath === false) {
                    throw new Error('File xác thực không hợp lệ hoặc không tồn tại.');
                }
                $rows = SpreadsheetReader::fromLocalFile($fullPath, 0, $maxRows, $maxCols);
            } else {
                $sheetId = (string) ($config['auth_sheet_id'] ?? '');
                $gid = (string) ($config['auth_gid'] ?? '0');
                if ($sheetId === '') {
                    throw new Error('Chưa cấu hình Google Sheet ID cho xác thực.');
                }
                $rows = SpreadsheetReader::fromGoogleCsv(
                    $sheetId,
                    $gid,
                    (int) Config::getEnvValue('GOOGLE_CACHE_TTL', 60),
                    $maxRows,
                    $maxCols
                );
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Lỗi đọc dữ liệu xác thực: ' . $e->getMessage()];
        }

        return self::buildAuthDatasetFromRows($rows);
    }

    private static function readAuthDataset(string $path): array
    {
        try {
            $rows = SpreadsheetReader::fromLocalFile($path, 0, 50000, 500, 'xlsx');
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Lỗi đọc file xác thực: ' . $e->getMessage()];
        }

        return self::buildAuthDatasetFromRows($rows);
    }

    private static function buildAuthDatasetFromRows(array $rows): array
    {
        if (empty($rows)) {
            return ['ok' => false, 'message' => 'File xác thực trống hoặc không đúng định dạng.'];
        }

        $headerIndex = null;
        $headers = [];
        $normalizedHeaders = [];
        foreach ($rows as $idx => $candidateRow) {
            if (!is_array($candidateRow)) {
                continue;
            }
            $candidateHeaders = array_values((array) $candidateRow);
            $candidateNormalized = array_map([AuthActions::class, 'normalizeHeaderValue'], $candidateHeaders);
            $missing = [];
            foreach (self::REQUIRED_HEADERS as $required) {
                if (!in_array($required, $candidateNormalized, true)) {
                    $missing[] = $required;
                }
            }
            if ($missing === []) {
                $headerIndex = (int) $idx;
                $headers = $candidateHeaders;
                $normalizedHeaders = $candidateNormalized;
                break;
            }
        }

        if ($headerIndex === null) {
            return ['ok' => false, 'message' => 'File xác thực thiếu cột MÃ NV hoặc MẬT KHẨU.'];
        }

        $normalizedHeaders = array_map([AuthActions::class, 'normalizeHeaderValue'], $headers);
        $empIdx = array_search('MÃ NV', $normalizedHeaders, true);
        if ($empIdx === false) {
            return ['ok' => false, 'message' => 'File xác thực thiếu cột MÃ NV.'];
        }

        $dataRows = [];
        $sourceRowNumbers = [];
        for ($i = $headerIndex + 1; $i < count($rows); $i++) {
            $row = array_values((array) $rows[$i]);
            $empId = trim((string) ($row[$empIdx] ?? ''));
            if ($empId === '') {
                continue;
            }
            $dataRows[] = $row;
            $sourceRowNumbers[] = $i + 1;
        }

        return [
            'ok' => true,
            'dataset' => [
                'headers' => $headers,
                'normalized_headers' => $normalizedHeaders,
                'rows' => $dataRows,
                'emp_idx' => (int) $empIdx,
                'source_row_numbers' => $sourceRowNumbers,
            ],
        ];
    }

    private static function finalizeAuthDatasetUpload(array &$config, array $dataset): array
    {
        $uploadDir = self::getUploadDir();
        $backupDir = self::getBackupDir();

        if (!self::ensureDir($uploadDir) || !self::ensureDir($backupDir)) {
            return ['ok' => false, 'message' => 'Thư mục uploads không thể ghi.'];
        }

        $backupPath = null;
        $currentFile = $config['auth_local_file'] ?? '';
        $currentAbs = null;
        if ($currentFile !== '') {
            $currentAbs = self::resolveFilePath($currentFile);
            if ($currentAbs !== false && is_file($currentAbs)) {
                $backupPath = self::createBackup($currentAbs, $backupDir);
                self::pruneBackupsOlderThan($backupDir, self::STALE_RETENTION_DAYS);
            }
        }

        $mergedResult = self::mergeEmployeesById($currentAbs, $dataset, $config);
        if (!$mergedResult['ok']) {
            return ['ok' => false, 'message' => (string) ($mergedResult['message'] ?? 'Không thể đối chiếu dữ liệu nhân sự.')];
        }

        $allRows = $mergedResult['rows'];
        $newFilename = 'auth_' . bin2hex(random_bytes(8)) . '.xlsx';
        $newPath = $uploadDir . $newFilename;
        if (!SpreadsheetWriter::toXlsx($allRows, $newPath)) {
            return ['ok' => false, 'message' => 'Lưu tệp thất bại.'];
        }
        @chmod($newPath, 0600);

        $config['auth_local_file'] = 'uploads/' . $newFilename;
        $config['auth_source_type'] = 'local';
        if (!Config::saveConfig($config)) {
            @unlink($newPath);
            return ['ok' => false, 'message' => 'Lỗi khi lưu cấu hình.'];
        }
        AdminActions::cleanupStaleUploadedFiles($config, self::STALE_RETENTION_DAYS);

        Security::auditLog('admin_upload_auth_file', ['filename' => $newFilename]);
        return [
            'ok' => true,
            'message' => 'Tải lên và đối chiếu thành công theo MÃ NV.',
            'filename' => $newFilename,
            'backup' => $backupPath ? basename($backupPath) : null,
            'stats' => [
                'existing_updated' => (int) ($mergedResult['existing_updated'] ?? 0),
                'new_added' => (int) ($mergedResult['new_added'] ?? 0),
                'password_hashed' => (int) ($mergedResult['password_hashed'] ?? 0),
            ],
        ];
    }

    private static function stagePendingUpload(string $tmpName): ?array
    {
        $dir = self::getPendingUploadDir();
        if (!self::ensureDir($dir)) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $path = $dir . 'pending_auth_' . $token . '.xlsx';
        if (!move_uploaded_file($tmpName, $path)) {
            return null;
        }
        if (!FileCrypto::encryptFileInPlace($path)) {
            @unlink($path);
            return null;
        }
        return ['token' => $token, 'path' => $path];
    }

    private static function resolvePendingUploadPath(string $token)
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return false;
        }
        $path = self::getPendingUploadDir() . 'pending_auth_' . $token . '.xlsx';
        return is_file($path) ? $path : false;
    }

    private static function prunePendingUploads(): void
    {
        $dir = self::getPendingUploadDir();
        if (!is_dir($dir)) {
            return;
        }

        $cutoffTs = time() - (self::PENDING_UPLOAD_RETENTION_HOURS * 3600);
        foreach (glob($dir . 'pending_auth_*.xlsx') ?: [] as $path) {
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime <= $cutoffTs) {
                @unlink($path);
            }
        }
    }

    private static function mergeEmployeesById($currentAbs, array $incoming, array $config): array
    {
        $baseHeaders = $incoming['headers'];
        $baseNormalized = $incoming['normalized_headers'];
        $rowsByKey = [];

        if (is_string($currentAbs) && $currentAbs !== '' && is_file($currentAbs)) {
            $currentRead = self::readAuthDataset($currentAbs);
            if (!$currentRead['ok']) {
                error_log('[HRM] Warning: Không đọc được file xác thực cũ để đối chiếu. Tiến hành ghi đè. Lỗi: ' . ($currentRead['message'] ?? 'Không rõ'));
            } else {
                $current = $currentRead['dataset'];
                $baseHeaders = $current['headers'];
                $baseNormalized = $current['normalized_headers'];

                foreach ($current['rows'] as $row) {
                    $key = self::normalizeEmployeeIdKey((string) ($row[$current['emp_idx']] ?? ''));
                    if ($key === '') {
                        continue;
                    }
                    if (!isset($rowsByKey[$key])) {
                        $rowsByKey[$key] = self::mapRowToBaseHeaders($row, $current['normalized_headers'], $baseNormalized);
                    }
                }
            }
        }

        $baseEmpIdx = array_search('MÃ NV', $baseNormalized, true);
        if ($baseEmpIdx === false) {
            return ['ok' => false, 'message' => 'Không tìm thấy cột MÃ NV trong dữ liệu đối chiếu.'];
        }
        $passHeader = AuthActions::normalizeHeaderValue((string) ($config['col_password'] ?? 'MẬT KHẨU'));
        $basePassIdx = array_search($passHeader, $baseNormalized, true);
        if ($basePassIdx === false) {
            return ['ok' => false, 'message' => "Không tìm thấy cột mật khẩu '{$passHeader}' trong dữ liệu đối chiếu."];
        }

        $newAdded = 0;
        $updated = 0;
        $passwordHashed = 0;

        foreach ($incoming['rows'] as $row) {
            $key = self::normalizeEmployeeIdKey((string) ($row[$incoming['emp_idx']] ?? ''));
            if ($key === '') {
                continue;
            }
            $mapped = self::mapRowToBaseHeaders($row, $incoming['normalized_headers'], $baseNormalized);
            $plainPassword = trim((string) ($mapped[$basePassIdx] ?? ''));
            if (isset($rowsByKey[$key])) {
                $existing = $rowsByKey[$key];
                foreach ($mapped as $idx => $value) {
                    if ($idx === $basePassIdx) {
                        continue;
                    }
                    if (trim((string) $value) !== '') {
                        $existing[$idx] = (string) $value;
                    }
                }

                if ($plainPassword !== '') {
                    $existing[$basePassIdx] = $plainPassword;
                }

                $rowsByKey[$key] = $existing;
                $updated++;
                continue;
            }

            if ($plainPassword === '') {
                continue;
            }
            $mapped[$basePassIdx] = $plainPassword;
            $rowsByKey[$key] = $mapped;
            $newAdded++;
        }

        $mergedRows = array_values($rowsByKey);
        usort($mergedRows, function (array $a, array $b) use ($baseEmpIdx): int {
            return strcmp(
                self::normalizeEmployeeIdKey((string) ($a[$baseEmpIdx] ?? '')),
                self::normalizeEmployeeIdKey((string) ($b[$baseEmpIdx] ?? ''))
            );
        });

        array_unshift($mergedRows, $baseHeaders);
        return [
            'ok' => true,
            'rows' => $mergedRows,
            'existing_updated' => $updated,
            'new_added' => $newAdded,
            'password_hashed' => $passwordHashed,
        ];
    }

    private static function mapRowToBaseHeaders(array $row, array $sourceNormalizedHeaders, array $baseNormalizedHeaders): array
    {
        $sourceByHeader = [];
        foreach ($sourceNormalizedHeaders as $idx => $header) {
            $sourceByHeader[$header] = $idx;
        }

        $mapped = [];
        foreach ($baseNormalizedHeaders as $header) {
            $sourceIdx = $sourceByHeader[$header] ?? null;
            $mapped[] = ($sourceIdx !== null && array_key_exists($sourceIdx, $row)) ? (string) $row[$sourceIdx] : '';
        }
        return $mapped;
    }

    private static function findEmployeeByEmpId(array $config, string $empId): ?array
    {
        $targetKey = self::normalizeEmployeeIdKey($empId);
        if ($targetKey === '') {
            return null;
        }

        $datasetResult = self::loadConfiguredAuthDataset($config);
        if (!$datasetResult['ok']) {
            return null;
        }
        $dataset = $datasetResult['dataset'];

        $nameIdx = self::findConfiguredHeaderIndex($dataset['normalized_headers'], [
            (string) ($config['col_emp_name'] ?? 'HỌ TÊN'),
            'HỌ TÊN',
            'HỌ VÀ TÊN',
            'TÊN NHÂN VIÊN',
            'TÊN NV',
            'NHÂN VIÊN',
            'FULL NAME',
            'NAME',
        ]);
        $deptIdx = self::findConfiguredHeaderIndex($dataset['normalized_headers'], [
            (string) ($config['col_department'] ?? 'BỘ PHẬN'),
            'BỘ PHẬN',
            'PHÒNG BAN',
            'ĐƠN VỊ',
            'DEPARTMENT',
        ]);
        $passwordIdx = self::findConfiguredHeaderIndex($dataset['normalized_headers'], [
            (string) ($config['col_password'] ?? 'MẬT KHẨU'),
            'MẬT KHẨU',
        ]);

        foreach ($dataset['rows'] as $rowIdx => $row) {
            $rawEmpId = trim((string) ($row[$dataset['emp_idx']] ?? ''));
            if (self::normalizeEmployeeIdKey($rawEmpId) !== $targetKey) {
                continue;
            }

            return self::buildEmployeeLookupPayload(
                $rawEmpId,
                $nameIdx !== false ? trim((string) ($row[$nameIdx] ?? '')) : '',
                $deptIdx !== false ? trim((string) ($row[$deptIdx] ?? '')) : '',
                $passwordIdx !== false ? trim((string) ($row[$passwordIdx] ?? '')) : '',
                (int) ($dataset['source_row_numbers'][$rowIdx] ?? ($rowIdx + 2))
            );
        }

        return null;
    }

    private static function normalizeEmployeeIdKey(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '';
        }
        $v = ltrim($v, "'");
        $v = ltrim($v, '0');
        return $v === '' ? '0' : strtoupper($v);
    }

    private static function buildEmployeeLookupPayload(
        string $empId,
        string $name,
        string $department,
        string $passwordValue,
        int $sourceRowNumber
    ): array {
        $passwordMode = 'empty';
        if ($passwordValue !== '') {
            $passwordMode = self::isPasswordHashString($passwordValue) ? 'hashed' : 'plain';
        }

        return [
            'emp_id' => self::normalizeEmployeeIdKey($empId),
            'emp_id_display' => trim($empId),
            'name' => $name,
            'department' => $department,
            'password_mode' => $passwordMode,
            'source_row_number' => $sourceRowNumber,
        ];
    }

    private static function findConfiguredHeaderIndex(array $normalizedHeaders, array $candidates)
    {
        foreach ($candidates as $candidate) {
            $normalized = AuthActions::normalizeHeaderValue((string) $candidate);
            $idx = array_search($normalized, $normalizedHeaders, true);
            if ($idx !== false) {
                return $idx;
            }
        }

        foreach ($normalizedHeaders as $idx => $header) {
            foreach ($candidates as $candidate) {
                $needle = AuthActions::normalizeHeaderValue((string) $candidate);
                if ($needle !== '' && str_contains((string) $header, $needle)) {
                    return $idx;
                }
            }
        }

        return false;
    }

    private static function normalizeSearchText(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        if ($value === '') {
            return '';
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }

        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value) ?? $value;
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private static function scoreEmployeeMatch(string $query, string $empId, string $name, string $department): int
    {
        $queryEmpKey = self::normalizeEmployeeIdKey($query);
        $empIdRaw = trim($empId);
        $empKey = self::normalizeEmployeeIdKey($empIdRaw);
        $nameKey = self::normalizeSearchText($name);
        $deptKey = self::normalizeSearchText($department);

        if ($queryEmpKey !== '' && ($queryEmpKey === $empKey || $queryEmpKey === $empIdRaw)) {
            return 300;
        }
        if ($queryEmpKey !== '' && str_starts_with($empKey, $queryEmpKey)) {
            return 220;
        }
        if ($queryEmpKey !== '' && str_contains($empKey, $queryEmpKey)) {
            return 180;
        }
        if ($nameKey !== '' && str_starts_with($nameKey, $query)) {
            return 160;
        }
        if ($nameKey !== '' && str_contains($nameKey, $query)) {
            return 120;
        }
        if ($deptKey !== '' && str_contains($deptKey, $query)) {
            return 80;
        }

        return 0;
    }

    private static function isPasswordHashString(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $info = password_get_info($value);
        return isset($info['algo']) && (int) $info['algo'] !== 0;
    }
}
