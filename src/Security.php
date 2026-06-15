<?php

declare(strict_types=1);

namespace App;

use App\Services\LogFormatter;

/**
 * Class Security
 * Đóng gói các tính năng xác thực, bảo mật và nhật ký (logging).
 */
class Security {

    /**
     * Chặn khởi tạo instance.
     */
    private function __construct() {}

    public static function getCsrfToken(): string {
        if (!isset($_SESSION)) return '';
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCsrfToken($token): bool {
        if (empty($_SESSION['csrf_token']) || empty($token)) return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function getRequestId(): string {
        if (!isset($_SESSION)) {
            return '';
        }
        if (empty($_SESSION['request_id'])) {
            $_SESSION['request_id'] = bin2hex(random_bytes(8));
        }
        return $_SESSION['request_id'];
    }

    public static function applySecurityHeaders(): void {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        
        // Gọi qua App\Config
        if (class_exists('\\App\\Config') && \App\Config::isHttpsRequest()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function appLog($level, $message, array $context = []): void {
        $maxStr = (int) Config::getEnvValue('LOG_CONTEXT_MAX_STRING', 4000);
        $payload = [
            'ts' => gmdate('c'),
            'level' => strtoupper((string) $level),
            'message' => (string) $message,
            'request_id' => isset($_SESSION) && isset($_SESSION['request_id']) ? $_SESSION['request_id'] : null,
            'app' => AppMetadata::NAME,
            'app_version' => AppMetadata::VERSION,
            'context' => LogFormatter::sanitizeContext($context, max(256, $maxStr)),
        ];
        error_log('[HRM] ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public static function auditLog($action, array $context = []): void {
        $base = [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'admin' => !empty($_SESSION['hr_admin']),
            'user_id' => $_SESSION['hr_user']['id'] ?? null,
        ];
        self::appLog('audit', $action, array_merge($base, $context));
    }
}
