<?php

/**
 * Employee Search Portal — index.php
 * Refactored to use PSR-4 Autoloader and Static Classes.
 */

require_once __DIR__ . '/src/Infrastructure/bootstrap.php';

// 2. Sử dụng các Class từ namespace App
use App\Config;
use App\Security;
use App\Application\AuthActions;
use App\Application\DataActions;
use App\Application\CheckActions;
use App\Services\FileCrypto;

function ensureShareDir(): string {
    $dir = __DIR__ . '/runtime/share';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    @chmod($dir, 0700);
    return $dir;
}

function cleanupExpiredSharedPayrollResults(): void {
    $dir = ensureShareDir();
    $files = glob($dir . '/payroll_*.json');
    if ($files === false) return;
    $now = time();
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $read = FileCrypto::readJsonFile($file);
        if (!$read['ok']) {
            if (($read['error'] ?? '') === 'key_unavailable') {
                continue;
            }
            @unlink($file);
            continue;
        }
        $json = $read['data'] ?? [];
        $exp = (int) (($json['expires_at'] ?? 0));
        if ($exp > 0 && $exp < $now) {
            @unlink($file);
        }
    }
}

function getPayrollShareTtlSeconds(array $config): int {
    $hoursRaw = (int) ($config['payroll_share_ttl_hours'] ?? 2);
    $hours = max(1, min(2, $hoursRaw));
    return $hours * 3600;
}

function isPayrollShareEnabled(array $config): bool {
    return !array_key_exists('payroll_share_enabled', $config) || $config['payroll_share_enabled'] !== false;
}

function getEmployeeSessionTimeoutSeconds(array $config): int {
    $minutes = (int) ($config['employee_session_timeout_minutes'] ?? 30);
    return max(5, min(120, $minutes)) * 60;
}

function touchEmployeeSession(): void {
    if (!empty($_SESSION['hr_user'])) {
        $_SESSION['employee_last_activity'] = time();
    }
}

function expireInactiveEmployeeSession(array $config): void {
    if (empty($_SESSION['hr_user'])) {
        return;
    }
    $lastActivity = (int) ($_SESSION['employee_last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > getEmployeeSessionTimeoutSeconds($config)) {
        Security::auditLog('employee_session_expired', ['employee_id' => $_SESSION['hr_user']['id'] ?? null]);
        unset($_SESSION['hr_user'], $_SESSION['employee_last_activity'], $_SESSION['payroll_result_payload'], $_SESSION['payroll_share_token'], $_SESSION['payroll_share_expires_at']);
        return;
    }
    touchEmployeeSession();
}

function createSharedPayrollToken(array $payload, int $ttlSeconds): ?array {
    cleanupExpiredSharedPayrollResults();
    $token = bin2hex(random_bytes(16));
    $expiresAt = time() + max(3600, min(7200, $ttlSeconds));
    $record = [
        'created_at' => time(),
        'expires_at' => $expiresAt,
        'payload' => $payload,
    ];
    $sharePath = ensureShareDir() . '/payroll_' . $token . '.json';
    if (!FileCrypto::writeJsonFile($sharePath, $record)) {
        Security::appLog('error', 'payroll_share_write_failed', [
            'share_token_prefix' => substr($token, 0, 8),
            'encrypted_storage' => FileCrypto::isEnabled(),
        ]);
        return null;
    }
    return ['token' => $token, 'expires_at' => $expiresAt];
}

function getSharedPayrollRecord(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $file = ensureShareDir() . '/payroll_' . $token . '.json';
    if (!is_file($file)) {
        return null;
    }
    $read = FileCrypto::readJsonFile($file);
    if (!$read['ok']) {
        if (($read['error'] ?? '') !== 'key_unavailable') {
            Security::appLog('warning', 'payroll_share_read_failed', [
                'share_token_prefix' => substr($token, 0, 8),
                'error' => (string) ($read['error'] ?? 'unknown'),
            ]);
        }
        return null;
    }
    $json = $read['data'] ?? null;
    if (!is_array($json)) return null;
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

// 3. Khởi tạo môi trường
Config::startSecureSession();
Security::applySecurityHeaders();
Security::getRequestId();

$config = Config::loadConfig();
expireInactiveEmployeeSession($config);
$isAdmin = !empty($_SESSION['hr_admin']);
$safeHeroTitle = strip_tags((string) ($config['site_hero_title'] ?? ''), '<br>');
$requestedPage = (string) ($_GET['page'] ?? '');
$currentPage = in_array($requestedPage, ['attendance', 'payroll_result'], true) ? $requestedPage : 'payroll';
$showAttendanceModule = CheckActions::isModuleEnabled($config) || $isAdmin;
$latestPayrollUpdateLabel = DataActions::getLatestPayrollUpdateLabel($config);
$employeeNotice = trim((string) ($config['employee_notice'] ?? ''));

// Handle AJAX Login Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    header('Content-Type: application/json');
    $empId = $_POST['emp_id'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $result = AuthActions::verifyUser($config, $empId, $password);
    if ($result['success']) {
        session_regenerate_id(true);
        $_SESSION['hr_user'] = $result['user'];
        $_SESSION['employee_last_activity'] = time();
        Security::auditLog('employee_login_success', ['employee_id' => $result['user']['id']]);
    } else {
        Security::auditLog('employee_login_failed', ['employee_id' => $empId]);
    }
    echo json_encode($result);
    exit;
}

// Handle AJAX Data Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_data') {
    header('Content-Type: application/json');
    $periodIndex = isset($_POST['period_index']) ? (int)$_POST['period_index'] : -1;
    $result = DataActions::handle($config, $periodIndex);
    $period = $config['periods'][$periodIndex] ?? null;
    Security::auditLog(($result['success'] ?? false) ? 'payroll_lookup_success' : 'payroll_lookup_failed', [
        'period_index' => $periodIndex,
        'period_label' => is_array($period) ? (string) ($period['label'] ?? '') : '',
        'matched_rows' => (int) ($result['matched_rows'] ?? 0),
    ]);
    echo json_encode($result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_payroll_result') {
    header('Content-Type: application/json');
    if (empty($_SESSION['hr_user']) && empty($_SESSION['hr_admin'])) {
        echo json_encode(['success' => false, 'message' => 'Phiên hết hạn.']);
        exit;
    }

    $rawPayload = $_POST['payload'] ?? '';
    if (!is_string($rawPayload) || trim($rawPayload) === '') {
        echo json_encode(['success' => false, 'message' => 'Không thể lưu kết quả tra cứu. Vui lòng thử lại.']);
        exit;
    }

    $payload = json_decode($rawPayload, true);
    if (!is_array($payload)) {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu kết quả không hợp lệ. Vui lòng tra cứu lại.']);
        exit;
    }

    $_SESSION['payroll_result_payload'] = $payload;
    unset($_SESSION['payroll_share_token'], $_SESSION['payroll_share_expires_at']);
    $shareToken = '';
    $shareUrl = '';
    $shareExpiresAt = 0;
    if (isPayrollShareEnabled($config)) {
        $ttlSeconds = getPayrollShareTtlSeconds($config);
        $share = createSharedPayrollToken($payload, $ttlSeconds);
        if (is_array($share)) {
            $shareToken = $share['token'];
            $shareExpiresAt = (int) $share['expires_at'];
            $shareUrl = 'index.php?page=payroll_result&share=' . $shareToken;
            $_SESSION['payroll_share_token'] = $shareToken;
            $_SESSION['payroll_share_expires_at'] = $shareExpiresAt;
        }
    }
    $payloadConfig = is_array($payload['currentConfig'] ?? null) ? $payload['currentConfig'] : [];
    Security::auditLog('payroll_result_saved', [
        'employee_id' => (string) ($payload['empId'] ?? ($_SESSION['hr_user']['id'] ?? '')),
        'period_label' => (string) ($payloadConfig['label'] ?? ''),
        'matched_rows' => is_array($payload['found'] ?? null) ? count($payload['found']) : 0,
        'share_enabled' => $shareToken !== '',
    ]);
    echo json_encode([
        'success' => true,
        'redirect_url' => 'index.php?page=payroll_result',
        'share_url' => $shareUrl,
        'share_token' => $shareToken,
        'share_expires_at' => $shareExpiresAt,
        'expires_in_seconds' => $shareExpiresAt > 0 ? max(0, $shareExpiresAt - time()) : 0,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_payroll_result') {
    header('Content-Type: application/json');
    $shareToken = trim((string) ($_POST['share_token'] ?? ''));

    if ($shareToken !== '') {
        if (!isPayrollShareEnabled($config)) {
            Security::auditLog('payroll_share_view_blocked', ['share_token_prefix' => substr($shareToken, 0, 8)]);
            echo json_encode(['success' => false, 'message' => 'Chức năng chia sẻ kết quả đang tắt.']);
            exit;
        }
        $record = getSharedPayrollRecord($shareToken);
        if ($record === null) {
            Security::auditLog('payroll_share_view_failed', ['share_token_prefix' => substr($shareToken, 0, 8)]);
            echo json_encode(['success' => false, 'message' => 'Liên kết kết quả đã hết hạn hoặc không hợp lệ.']);
            exit;
        }
        $payloadConfig = is_array($record['payload']['currentConfig'] ?? null) ? $record['payload']['currentConfig'] : [];
        Security::auditLog('payroll_share_view_success', [
            'share_token_prefix' => substr($shareToken, 0, 8),
            'employee_id' => (string) ($record['payload']['empId'] ?? ''),
            'period_label' => (string) ($payloadConfig['label'] ?? ''),
        ]);
        echo json_encode([
            'success' => true,
            'payload' => $record['payload'],
            'source' => 'share',
            'share_enabled' => true,
            'share_expires_at' => (int) $record['expires_at'],
        ]);
        exit;
    }

    if (empty($_SESSION['hr_user']) && empty($_SESSION['hr_admin'])) {
        echo json_encode(['success' => false, 'message' => 'Phiên hết hạn.']);
        exit;
    }

    $payload = $_SESSION['payroll_result_payload'] ?? null;
    if (!is_array($payload)) {
        echo json_encode(['success' => false, 'message' => 'Không có dữ liệu kết quả trong phiên hiện tại.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'payload' => $payload,
        'source' => 'session',
        'share_enabled' => isPayrollShareEnabled($config),
        'share_token' => (string) ($_SESSION['payroll_share_token'] ?? ''),
        'share_expires_at' => (int) ($_SESSION['payroll_share_expires_at'] ?? 0),
    ]);
    exit;
}

if ($currentPage === 'attendance') {
    $attendanceShareToken = trim((string) ($_GET['share'] ?? ''));
    if ($attendanceShareToken !== '') {
        $attendanceState = CheckActions::buildSharedViewState($config, $attendanceShareToken);
        if (($attendanceState['api_error'] ?? '') === '') {
            Security::auditLog('attendance_share_view_success', [
                'share_token_prefix' => substr($attendanceShareToken, 0, 8),
                'employee_id' => (string) ($attendanceState['employee_id'] ?? ''),
            ]);
        } else {
            Security::auditLog('attendance_share_view_failed', [
                'share_token_prefix' => substr($attendanceShareToken, 0, 8),
            ]);
        }
    } else {
        $attendanceState = CheckActions::buildViewState($config);
    }
    require __DIR__ . '/views/attendance.php';
    exit;
}

if ($currentPage === 'payroll_result') {
    $payrollShareToken = trim((string) ($_GET['share'] ?? ''));
    require __DIR__ . '/views/payroll_result.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tra Cứu Phiếu Lương &amp; Chấm Công</title>
    <meta name="description" content="Hệ thống tra cứu phiếu lương và chấm công nhân viên.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/main.css?v=<?= time() ?>">
</head>
<body>

<div class="bg-effects"></div>

<div class="app-container">

    <!-- Navigation -->
    <nav class="top-nav" id="topNav">
        <div class="nav-brand">
            <div class="nav-logo"><?= htmlspecialchars($config['site_logo_text']) ?></div>
            <div class="nav-brand-text">
                <div class="nav-title"><?= htmlspecialchars($config['site_company']) ?></div>
                <div class="nav-subtitle"><?= htmlspecialchars($config['site_subtitle']) ?></div>
            </div>
        </div>
        <div class="nav-right">
            <div class="module-switch" role="tablist" aria-label="Chọn phân hệ">
                <a href="index.php" class="module-switch-link active" aria-current="page">Phiếu lương</a>
                <?php if ($showAttendanceModule): ?>
                    <a href="index.php?page=attendance" class="module-switch-link">Chấm công</a>
                <?php endif; ?>
            </div>
            <?php if ($latestPayrollUpdateLabel !== ''): ?>
                <div class="nav-meta-badge"><?= htmlspecialchars($latestPayrollUpdateLabel) ?></div>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Unified Search + Result Card -->
    <div class="search-wrapper">

        <!-- TOP: Branding + Form -->
        <div class="search-top">
            <!-- LEFT: Branding -->
            <div class="search-left">
                <div class="search-brand">
                    <div class="search-brand-logo"><?= htmlspecialchars($config['site_logo_text']) ?></div>
                    <div class="search-brand-title"><?= $safeHeroTitle ?></div>
                    <div class="search-brand-desc"><?= htmlspecialchars($config['site_hero_desc']) ?></div>
                </div>
            </div>

            <!-- RIGHT: Form -->
            <div class="search-right">
                <div class="search-right-title">Tra cứu phiếu lương</div>
                <div class="search-right-sub">Nhập mã nhân viên và mật khẩu để xem phiếu lương.</div>
                <div class="trust-note">Dữ liệu chỉ hiển thị sau khi xác thực thành công.</div>
                <?php if ($employeeNotice !== ''): ?>
                    <div class="employee-notice"><?= htmlspecialchars($employeeNotice) ?></div>
                <?php endif; ?>

                <!-- Step 1: Period -->
                <div class="step-header">
                    <span class="step-num">1</span>
                    <span class="step-label">Chọn kỳ phiếu lương</span>
                </div>
                <div id="periodContainer" class="period-grid">
                    <div class="period-loading"><span class="spinner"></span> Đang tải danh sách kỳ phiếu lương...</div>
                </div>

                <div class="search-divider"></div>

                <!-- Step 2: Form -->
                <div class="step-header">
                    <span class="step-num">2</span>
                    <span class="step-label">Nhập thông tin</span>
                </div>
                <form id="searchForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="inputEmpId">Mã nhân viên</label>
                            <input type="text" id="inputEmpId" class="form-input" placeholder="VD: 257" required autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="inputPassword">Mật khẩu</label>
                            <div class="password-field">
                                <input type="password" id="inputPassword" class="form-input" placeholder="Nhập mật khẩu" autocomplete="current-password">
                                <button type="button" id="togglePasswordBtn" class="password-toggle" aria-label="Hiện mật khẩu">Hiện</button>
                            </div>
                        </div>
                    </div>
                    <div class="form-helper-row form-helper-row--compact">
                        <button type="button" id="clearSearchBtn" class="helper-action">Làm mới biểu mẫu</button>
                    </div>
                    <button type="submit" id="btnSubmit" class="btn-submit">
                        <span id="btnText">Tra cứu</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- BOTTOM: Result strip (inside card) -->
        <div id="resultArea" class="result-area search-result-strip">
            <div class="ready-banner">
                <div class="ready-banner-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2M12 12v4M10 14h4"/></svg>
                </div>
                <div class="ready-banner-body">
                    <div class="ready-banner-title">Nhập thông tin để xem phiếu lương</div>
                    <div class="ready-banner-hint">Chọn kỳ lương → Nhập mã NV &amp; mật khẩu → Nhấn <strong>Tra cứu</strong></div>
                </div>
            </div>
        </div>

    </div>


    <!-- Footer -->
    <footer class="app-footer">
        <div class="footer-text"><?= htmlspecialchars($config['site_footer']) ?></div>
    </footer>

</div>

<!-- PHP → JS Config Bridge -->
<script>
    const HR_IS_ADMIN      = <?= $isAdmin ? 'true' : 'false' ?>;
    const HR_PERIODS       = <?= json_encode(isset($config['periods']) ? $config['periods'] : [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const HR_SEARCH_COL    = <?= json_encode($config['col_emp_id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const HR_PASS_COL      = <?= json_encode($config['col_password'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const HR_COL_EMP_NAME  = <?= json_encode($config['col_emp_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const HR_COL_DEPARTMENT= <?= json_encode($config['col_department'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const HR_STAT_COLS     = <?= json_encode($config['stat_cols'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<script src="assets/js/app.js?v=<?= time() ?>"></script>

<script>
(function(){
    var nav = document.getElementById('topNav');
    if (!nav) return;
    var onScroll = function() {
        if (window.scrollY > 10) {
            nav.classList.add('top-nav--scrolled');
        } else {
            nav.classList.remove('top-nav--scrolled');
        }
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
})();
</script>

</body>
</html>
