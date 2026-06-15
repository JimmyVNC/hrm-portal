<?php

namespace App\Application;

use App\Config;
use App\Security;
use App\Services\FileCrypto;

class CheckActions {
    private const DEFAULT_API_URL = 'http://webapi.thepvinhthanh.com/mitaco-api.aspx';
    private const SHARE_EXPIRE_MAX_DAYS = 30;

    private function __construct() {}

    public static function isModuleEnabled(array $config): bool {
        return !array_key_exists('check_enabled', $config) || $config['check_enabled'] !== false;
    }

    public static function getApiUrl(array $config): string {
        $apiUrl = trim((string) ($config['check_api_url'] ?? ''));
        return $apiUrl !== '' ? $apiUrl : self::DEFAULT_API_URL;
    }

    public static function parseWindowTimestamp(?string $value): ?int {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $timezone = new \DateTimeZone(\App\Config::getAppTimezone());
        $formats = ['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d\TH:i:s', '!Y-m-d\TH:i'];
        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw, $timezone);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->getTimestamp();
            }
        }

        try {
            return (new \DateTimeImmutable($raw, $timezone))->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function parseMonthDays(?string $value): array {
        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }

        $days = [];
        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $item) {
            if ($item === '' || !ctype_digit($item)) {
                continue;
            }
            $day = (int) $item;
            if ($day < 1 || $day > 31) {
                continue;
            }
            $days[$day] = true;
        }

        $result = array_keys($days);
        sort($result);
        return $result;
    }

    public static function getAvailabilityState(array $config, ?int $nowTs = null): array {
        $nowTs = $nowTs ?? time();
        $availableFromRaw = trim((string) ($config['check_available_from'] ?? ''));
        $availableUntilRaw = trim((string) ($config['check_available_until'] ?? ''));
        $availableFromTs = self::parseWindowTimestamp($availableFromRaw);
        $availableUntilTs = self::parseWindowTimestamp($availableUntilRaw);
        $monthDays = self::parseMonthDays($config['check_month_days'] ?? '');
        $currentDay = (int) (new \DateTimeImmutable('@' . $nowTs))
            ->setTimezone(new \DateTimeZone(\App\Config::getAppTimezone()))
            ->format('j');

        $isOpen = true;
        $reason = '';
        if ($availableFromTs !== null && $nowTs < $availableFromTs) {
            $isOpen = false;
            $reason = 'before_start';
        } elseif ($availableUntilTs !== null && $nowTs > $availableUntilTs) {
            $isOpen = false;
            $reason = 'after_end';
        } elseif ($availableFromTs === null && $availableUntilTs === null && !empty($monthDays) && !in_array($currentDay, $monthDays, true)) {
            $isOpen = false;
            $reason = 'month_day_closed';
        }

        return [
            'is_open' => $isOpen,
            'reason' => $reason,
            'available_from' => $availableFromRaw,
            'available_until' => $availableUntilRaw,
            'available_from_ts' => $availableFromTs,
            'available_until_ts' => $availableUntilTs,
            'month_days' => $monthDays,
            'month_days_raw' => trim((string) ($config['check_month_days'] ?? '')),
            'current_day' => $currentDay,
        ];
    }

    public static function buildAvailabilityMessage(array $availability): string {
        if (($availability['reason'] ?? '') === 'before_start' && !empty($availability['available_from'])) {
            return 'Tra cứu chấm công sẽ mở từ ' . $availability['available_from'] . '.';
        }
        if (($availability['reason'] ?? '') === 'after_end' && !empty($availability['available_until'])) {
            return 'Tra cứu chấm công đã khóa sau ' . $availability['available_until'] . '.';
        }
        if (($availability['reason'] ?? '') === 'month_day_closed' && !empty($availability['month_days'])) {
            return 'Tra cứu chấm công chỉ mở vào các ngày: ' . implode(', ', $availability['month_days']) . ' hàng tháng.';
        }
        return 'Tra cứu chấm công hiện không khả dụng.';
    }

    public static function buildViewState(array $config): array {
        $state = self::createBaseState($config, [
            'employee_id' => trim((string) ($_POST['m_ma_nv'] ?? '')),
            'from_date' => trim((string) ($_POST['m_tu_ngay'] ?? '')),
            'to_date' => trim((string) ($_POST['m_den_ngay'] ?? '')),
            'is_submit' => ($_POST['m_action'] ?? '') === 'view',
        ]);
        $employeePassword = (string) ($_POST['m_mat_khau'] ?? '');

        if (!$state['enabled'] && empty($_SESSION['hr_admin'])) {
            return $state;
        }

        if (!$state['availability']['is_open']) {
            $state['availability_message'] = self::buildAvailabilityMessage($state['availability']);
            if (empty($_SESSION['hr_admin'])) {
                return $state;
            }
        }

        if ($isSubmit) {
            if ($state['employee_id'] === '') {
                $state['api_error'] = 'Vui lòng nhập mã nhân viên.';
                return $state;
            }
            if (!self::isValidEmployeeId($state['employee_id'])) {
                $state['api_error'] = 'Chỉ được tra cứu theo mã nhân viên hợp lệ (không nhập họ tên).';
                return $state;
            }
            if (trim($employeePassword) === '') {
                $state['api_error'] = 'Vui lòng nhập mật khẩu để tra cứu chấm công.';
                return $state;
            }

            $auth = AuthActions::verifyUser($config, $state['employee_id'], $employeePassword);
            if (!($auth['success'] ?? false)) {
                $state['api_error'] = (string) ($auth['message'] ?? 'Xác thực thất bại.');
                return $state;
            }
            self::loadAttendanceData($state);
        } else {
            self::loadLatestUpdate($state);
        }

        if ($state['latest_update'] !== '') {
            $dt = \DateTimeImmutable::createFromFormat('d/m/Y H:i:s', $state['latest_update']);
            $state['formatted_update'] = $dt instanceof \DateTimeImmutable
                ? ($dt->format('H:i:s') . ' - Ngày ' . $dt->format('d/m/Y'))
                : $state['latest_update'];
        }

        return $state;
    }

    public static function buildAdminLookupState(array $config, string $employeeId, string $fromDate, string $toDate): array {
        $state = self::createBaseState($config, [
            'employee_id' => $employeeId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'is_submit' => true,
            'is_admin_lookup' => true,
        ]);

        if ($state['employee_id'] === '') {
            $state['api_error'] = 'Vui lòng nhập mã nhân viên.';
            return $state;
        }
        if (!self::isValidEmployeeId($state['employee_id'])) {
            $state['api_error'] = 'Mã nhân viên không hợp lệ.';
            return $state;
        }
        if (!self::isValidDate($state['from_date']) || !self::isValidDate($state['to_date'])) {
            $state['api_error'] = 'Khoảng ngày không hợp lệ.';
            return $state;
        }
        if ($state['from_date'] > $state['to_date']) {
            $state['api_error'] = 'Từ ngày không được lớn hơn đến ngày.';
            return $state;
        }

        self::loadAttendanceData($state);
        return $state;
    }

    public static function normalizeShareExpiryTimestamp(?string $value, ?int $nowTs = null): ?int {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $nowTs = $nowTs ?? time();
        $timezone = new \DateTimeZone(Config::getAppTimezone());
        $formats = ['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d\TH:i:s', '!Y-m-d\TH:i'];
        $expiresAt = null;
        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw, $timezone);
            if ($dt instanceof \DateTimeImmutable) {
                $expiresAt = $dt->getTimestamp();
                break;
            }
        }

        if ($expiresAt === null) {
            try {
                $expiresAt = (new \DateTimeImmutable($raw, $timezone))->getTimestamp();
            } catch (\Throwable $e) {
                return null;
            }
        }

        if ($expiresAt <= $nowTs) {
            return null;
        }

        $maxTs = $nowTs + (self::SHARE_EXPIRE_MAX_DAYS * 86400);
        if ($expiresAt > $maxTs) {
            return $maxTs;
        }

        return $expiresAt;
    }

    public static function createAttendanceShare(array $viewState, int $expiresAt): ?array {
        if (empty($viewState['has_data']) || empty($viewState['employees']) || empty($viewState['employee_id'])) {
            return null;
        }

        self::cleanupExpiredSharedAttendanceResults();
        $token = bin2hex(random_bytes(16));
        $payload = [
            'employee_id' => (string) ($viewState['employee_id'] ?? ''),
            'from_date' => (string) ($viewState['from_date'] ?? ''),
            'to_date' => (string) ($viewState['to_date'] ?? ''),
            'latest_update' => (string) ($viewState['latest_update'] ?? ''),
            'formatted_update' => (string) ($viewState['formatted_update'] ?? ''),
            'employees' => (array) ($viewState['employees'] ?? []),
            'has_data' => !empty($viewState['has_data']),
        ];
        $record = [
            'created_at' => time(),
            'expires_at' => $expiresAt,
            'payload' => $payload,
        ];

        $sharePath = self::ensureShareDir() . '/attendance_' . $token . '.json';
        if (!FileCrypto::writeJsonFile($sharePath, $record)) {
            Security::appLog('error', 'attendance_share_write_failed', [
                'share_token_prefix' => substr($token, 0, 8),
                'encrypted_storage' => FileCrypto::isEnabled(),
            ]);
            return null;
        }

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    public static function buildSharedViewState(array $config, string $token): array {
        $state = self::createBaseState($config, ['is_submit' => true]);
        $state['is_shared_view'] = true;
        $state['availability_message'] = '';

        $record = self::getSharedAttendanceRecord($token);
        if ($record === null) {
            $state['api_error'] = 'Liên kết chấm công đã hết hạn hoặc không hợp lệ.';
            return $state;
        }

        $payload = $record['payload'];
        $state['employee_id'] = (string) ($payload['employee_id'] ?? '');
        $state['from_date'] = (string) ($payload['from_date'] ?? $state['from_date']);
        $state['to_date'] = (string) ($payload['to_date'] ?? $state['to_date']);
        $state['latest_update'] = (string) ($payload['latest_update'] ?? '');
        $state['formatted_update'] = (string) ($payload['formatted_update'] ?? '');
        $state['employees'] = is_array($payload['employees'] ?? null) ? $payload['employees'] : [];
        $state['has_data'] = !empty($payload['has_data']) && !empty($state['employees']);
        $state['share_expires_at'] = (int) ($record['expires_at'] ?? 0);
        return $state;
    }

    private static function isValidEmployeeId(string $employeeId): bool {
        $value = trim($employeeId);
        if ($value === '') {
            return false;
        }

        // Chỉ cho phép mã nhân viên kiểu code: chữ/số và ký tự phân tách phổ biến.
        return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $value);
    }

    private static function isValidDate(string $value): bool {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if (!$dt instanceof \DateTimeImmutable) {
            return false;
        }
        $errors = \DateTimeImmutable::getLastErrors();
        return $errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0);
    }

    private static function createBaseState(array $config, array $overrides = []): array {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(Config::getAppTimezone()));
        $defaultFrom = $now->format('Y-m-01');
        $defaultTo = $now->format('Y-m-d');

        return array_merge([
            'enabled' => self::isModuleEnabled($config),
            'api_url' => self::getApiUrl($config),
            'availability' => self::getAvailabilityState($config, $now->getTimestamp()),
            'employee_id' => '',
            'employee_password' => '',
            'from_date' => $defaultFrom,
            'to_date' => $defaultTo,
            'is_submit' => false,
            'is_admin_lookup' => false,
            'is_shared_view' => false,
            'share_expires_at' => 0,
            'api_error' => '',
            'availability_message' => '',
            'latest_update' => '',
            'formatted_update' => '',
            'employees' => [],
            'has_data' => false,
        ], array_filter([
            'employee_id' => trim((string) ($overrides['employee_id'] ?? '')),
            'from_date' => trim((string) ($overrides['from_date'] ?? '')) ?: $defaultFrom,
            'to_date' => trim((string) ($overrides['to_date'] ?? '')) ?: $defaultTo,
            'is_submit' => (bool) ($overrides['is_submit'] ?? false),
            'is_admin_lookup' => (bool) ($overrides['is_admin_lookup'] ?? false),
            'is_shared_view' => (bool) ($overrides['is_shared_view'] ?? false),
        ], static fn($value) => $value !== null));
    }

    private static function ensureShareDir(): string {
        $dir = Config::projectRoot() . '/runtime/share';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @chmod($dir, 0700);
        return $dir;
    }

    private static function cleanupExpiredSharedAttendanceResults(): void {
        $files = glob(self::ensureShareDir() . '/attendance_*.json');
        if ($files === false) {
            return;
        }
        $now = time();
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $read = FileCrypto::readJsonFile($file);
            if (!$read['ok']) {
                if (($read['error'] ?? '') === 'key_unavailable') {
                    continue;
                }
                @unlink($file);
                continue;
            }
            $json = $read['data'] ?? [];
            $exp = (int) ($json['expires_at'] ?? 0);
            if ($exp > 0 && $exp < $now) {
                @unlink($file);
            }
        }
    }

    private static function getSharedAttendanceRecord(string $token): ?array {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        $file = self::ensureShareDir() . '/attendance_' . $token . '.json';
        if (!is_file($file)) {
            return null;
        }

        $read = FileCrypto::readJsonFile($file);
        if (!$read['ok']) {
            if (($read['error'] ?? '') !== 'key_unavailable') {
                Security::appLog('warning', 'attendance_share_read_failed', [
                    'share_token_prefix' => substr($token, 0, 8),
                    'error' => (string) ($read['error'] ?? 'unknown'),
                ]);
            }
            return null;
        }

        $json = $read['data'] ?? null;
        if (!is_array($json)) {
            return null;
        }

        $expiresAt = (int) ($json['expires_at'] ?? 0);
        if ($expiresAt <= 0 || $expiresAt < time()) {
            @unlink($file);
            return null;
        }

        $payload = $json['payload'] ?? null;
        if (!is_array($payload)) {
            return null;
        }

        return [
            'payload' => $payload,
            'expires_at' => $expiresAt,
            'created_at' => (int) ($json['created_at'] ?? 0),
        ];
    }

    private static function loadLatestUpdate(array &$state): void {
        $params = http_build_query([
            'ma_nv' => 'SYSTEM_INFO',
            'tu_ngay' => $state['to_date'],
            'den_ngay' => $state['to_date'],
        ]);

        $response = self::apiGet($state['api_url'] . '?' . $params, 5);
        if (isset($response['error'])) {
            return;
        }

        $json = json_decode((string) ($response['body'] ?? ''), true);
        if (($json['status'] ?? '') === 'success' && !empty($json['latest_update'])) {
            $state['latest_update'] = (string) $json['latest_update'];
        }
    }

    private static function loadAttendanceData(array &$state): void {
        $params = http_build_query([
            'ma_nv' => $state['employee_id'],
            'tu_ngay' => $state['from_date'],
            'den_ngay' => $state['to_date'],
        ]);

        $response = self::apiGet($state['api_url'] . '?' . $params);
        if (isset($response['error'])) {
            $state['api_error'] = 'Lỗi kết nối: ' . $response['error'];
            return;
        }

        $json = json_decode((string) ($response['body'] ?? ''), true);
        if (($json['status'] ?? '') !== 'success') {
            $state['api_error'] = (string) ($json['message'] ?? 'Lỗi không xác định từ API.');
            return;
        }

        if (!empty($json['latest_update'])) {
            $state['latest_update'] = (string) $json['latest_update'];
        }

        $rawRows = $json['data'] ?? [];
        if (!is_array($rawRows) || $rawRows === []) {
            return;
        }

        $employees = [];
        foreach ($rawRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['MaNhanVien'] ?? ''));
            if ($code === '') {
                continue;
            }

            if (!isset($employees[$code])) {
                $employees[$code] = [
                    'info' => [
                        'code' => $code,
                        'name' => trim((string) ($row['TenNhanVien'] ?? '')),
                    ],
                    'days' => [],
                ];
            }

            $dateKey = trim((string) ($row['NgayCham'] ?? ''));
            if ($dateKey === '') {
                continue;
            }
            if (!isset($employees[$code]['days'][$dateKey])) {
                $employees[$code]['days'][$dateKey] = [];
            }

            $timeValue = substr(trim((string) ($row['GioCham'] ?? '')), 0, 5);
            if ($timeValue !== '') {
                $employees[$code]['days'][$dateKey][] = $timeValue;
            }
        }

        $state['employees'] = $employees;
        $state['has_data'] = !empty($employees);
    }

    private static function apiGet(string $url, int $timeout = 15): array {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $result = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            if ($error) {
                Security::appLog('warning', 'attendance_api_curl_failed', ['error' => $error, 'url' => $url]);
                return ['error' => $error];
            }
            return ['body' => $result];
        }

        $context = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            Security::appLog('warning', 'attendance_api_stream_failed', ['url' => $url]);
            return ['error' => 'Không thể kết nối đến máy chủ.'];
        }
        return ['body' => $result];
    }
}
