<?php

namespace App;

use App\Services\ConfigSchema;

/**
 * Class Config
 * Quản lý cấu hình, biến môi trường và phiên làm việc (session).
 */
class Config {
    public const DEFAULT_TIMEZONE = 'Asia/Ho_Chi_Minh';

    private function __construct() {}

    public const CONFIG_FILE = __DIR__ . '/../config/hr_config.json';
    public const ENV_FILE    = __DIR__ . '/../.env';

    /**
     * Thư mục gốc của project (chứa index.php, admin.php, …).
     */
    public static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Thư mục uploads — có thể ở ngoài web root (UPLOADS_DIR trong .env).
     *
     * Trên FashPanel/shared hosting:
     *   - Set UPLOADS_DIR=/home/<user>/uploads_hrm   (ngoài public_html)
     * Không set → mặc định là <project>/uploads/ (cần bảo vệ bằng .htaccess)
     */
    public static function uploadsDir(): string
    {
        $env = self::getEnvValue('UPLOADS_DIR');
        if (is_string($env) && trim($env) !== '') {
            return rtrim(trim($env), '/\\') . DIRECTORY_SEPARATOR;
        }
        return self::projectRoot() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    }

    private static $defaultConfig = [
        'periods'       => [],
        'auth_sheet_id' => '',
        'auth_gid'      => '0',
        'admin_password'=> '',
        'auth_source_type' => 'google',
        'auth_local_file' => '',
        'check_enabled' => true,
        'check_api_url' => 'http://webapi.thepvinhthanh.com/mitaco-api.aspx',
        'check_available_from' => '',
        'check_available_until' => '',
        'check_month_days' => '',
        'payroll_share_ttl_hours' => 2,
        'payroll_share_enabled' => true,
        'employee_session_timeout_minutes' => 30,
        'employee_notice' => '',
        // === Giao diện / Thương hiệu ===
        'site_company'  => 'VINH THANH STEEL',
        'site_logo_text'=> 'HR',
        'site_subtitle' => 'Employee Self-Service',
        'site_hero_title'=> 'Tra Cứu Bảng Công<br>& Thu Nhập',
        'site_hero_desc'=> 'Nhập mã nhân viên và mật khẩu để xem chi tiết phiếu lương theo từng kỳ.',
        'site_footer'   => '© 2026 Thép Vĩnh Thành — Hệ thống quản lý nội bộ',
        // === Cột dữ liệu ===
        'col_emp_id'    => 'MÃ NV',
        'col_password'  => 'MẬT KHẨU',
        'col_emp_name'  => 'HỌ TÊN NHÂN VIÊN',
        'col_department'=> 'BỘ PHẬN',
        // === Cột Summary Stats ===
        'stat_cols'     => 'NGÀY CÔNG, GIỜ TĂNG CA, THỰC LÃNH, TỔNG TIỀN LƯƠNG, BỘ PHẬN',
    ];

    public static function isHttpsRequest(): bool {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        );
    }

    public static function startSecureSession(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => self::isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function getCsrfToken(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::startSecureSession();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCsrfToken($token): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        if (!isset($_SESSION['csrf_token']) || !is_string($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function loadEnvFile(?string $filePath = null): void {
        $filePath = $filePath ?? self::ENV_FILE;
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key === '') {
                continue;
            }

            if (
                strlen($value) >= 2 &&
                (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    public static function getEnvValue(string $key, $default = null) {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        return $default;
    }

    public static function getAppTimezone(): string {
        $timezone = (string) self::getEnvValue('APP_TIMEZONE', self::DEFAULT_TIMEZONE);
        return trim($timezone) !== '' ? trim($timezone) : self::DEFAULT_TIMEZONE;
    }

    public static function applyDefaultTimezone(): void {
        $timezone = self::getAppTimezone();
        if (!@date_default_timezone_set($timezone)) {
            date_default_timezone_set(self::DEFAULT_TIMEZONE);
        }
    }

    public static function loadConfig(): array {
        self::loadEnvFile();

        $config = self::$defaultConfig;
        if (file_exists(self::CONFIG_FILE)) {
            $json = @file_get_contents(self::CONFIG_FILE);
            if ($json) {
                $data = json_decode($json, true);
                if (is_array($data)) {
                    $config = array_merge(self::$defaultConfig, $data);
                }
            }
        }

        $envMap = [
            'ADMIN_PASSWORD' => 'admin_password',
            'AUTH_SHEET_ID' => 'auth_sheet_id',
            'AUTH_GID' => 'auth_gid',
            'AUTH_SOURCE_TYPE' => 'auth_source_type',
            'AUTH_LOCAL_FILE' => 'auth_local_file',
            'CHECK_API_URL' => 'check_api_url',
            'SITE_COMPANY' => 'site_company',
            'SITE_LOGO_TEXT' => 'site_logo_text',
            'SITE_SUBTITLE' => 'site_subtitle',
            'SITE_HERO_TITLE' => 'site_hero_title',
            'SITE_HERO_DESC' => 'site_hero_desc',
            'SITE_FOOTER' => 'site_footer',
            'COL_EMP_ID' => 'col_emp_id',
            'COL_PASSWORD' => 'col_password',
            'COL_EMP_NAME' => 'col_emp_name',
            'COL_DEPARTMENT' => 'col_department',
            'STAT_COLS' => 'stat_cols',
            'PAYROLL_SHARE_TTL_HOURS' => 'payroll_share_ttl_hours',
            'EMPLOYEE_NOTICE' => 'employee_notice',
            'EMPLOYEE_SESSION_TIMEOUT_MINUTES' => 'employee_session_timeout_minutes',
        ];

        foreach ($envMap as $envKey => $configKey) {
            $envValue = self::getEnvValue($envKey);
            if ($envValue !== null) {
                $config[$configKey] = $envValue;
            }
        }

        $checkEnabled = self::getEnvValue('CHECK_ENABLED');
        if ($checkEnabled !== null) {
            $config['check_enabled'] = in_array(strtolower((string) $checkEnabled), ['1', 'true', 'on', 'yes'], true);
        }

        $shareEnabled = self::getEnvValue('PAYROLL_SHARE_ENABLED');
        if ($shareEnabled !== null) {
            $config['payroll_share_enabled'] = in_array(strtolower((string) $shareEnabled), ['1', 'true', 'on', 'yes'], true);
        }

        $config['payroll_share_ttl_hours'] = max(1, min(2, (int) ($config['payroll_share_ttl_hours'] ?? 2)));
        $config['employee_session_timeout_minutes'] = max(5, min(120, (int) ($config['employee_session_timeout_minutes'] ?? 30)));

        if (class_exists('\\App\\Services\\ConfigSchema')) {
            $schemaErrors = ConfigSchema::validate($config);
            if (!empty($schemaErrors)) {
                error_log('[HRM] Config schema warning: ' . implode(' | ', $schemaErrors));
            }
        }

        return $config;
    }

    public static function saveConfig(array $config): bool {
        if (class_exists('\\App\\Services\\ConfigSchema')) {
            $schemaErrors = ConfigSchema::validate($config);
            if (!empty($schemaErrors)) {
                error_log('[HRM] Refuse to save invalid config: ' . implode(' | ', $schemaErrors));
                return false;
            }
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $dir = dirname(self::CONFIG_FILE);
        if (!is_dir($dir)) {
            return false;
        }

        $tmpFile = tempnam($dir, 'cfg_');
        if ($tmpFile === false) {
            return false;
        }

        $fp = fopen($tmpFile, 'wb');
        if ($fp === false) {
            @unlink($tmpFile);
            return false;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            @unlink($tmpFile);
            return false;
        }

        $bytes = fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($bytes === false) {
            @unlink($tmpFile);
            return false;
        }

        if (!@rename($tmpFile, self::CONFIG_FILE)) {
            @unlink($tmpFile);
            return false;
        }

        return true;
    }

    public static function fetchUrlHelper(string $url) {
        if (function_exists('curl_version')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            $data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode >= 200 && $httpCode < 300) {
                return $data;
            }
        }
        $ctx = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'timeout' => 20,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        return $data !== false ? $data : false;
    }
}
