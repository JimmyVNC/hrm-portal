<?php

/**
 * Admin Panel — admin.php
 * Updated to V2 layout with Global Sticky Save Bar.
 */

require_once __DIR__ . '/src/Infrastructure/bootstrap.php';

use App\Config;
use App\Security;
use App\Application\AdminActions;
use App\Application\AdminFileManager;
use App\Application\CheckActions;

Config::startSecureSession();
Security::applySecurityHeaders();

$config     = Config::loadConfig();
$adminMsg   = '';
$adminMsgType = '';
$csrfToken = Security::getCsrfToken();
$adminSessionTimeout = 30 * 60;
$spreadsheetUploadOptions = [];
$spreadsheetUploadOptionsHtml = '<option value="">Chọn file đã tải lên</option>';

if (is_dir(Config::uploadsDir())) {
    $scanUploads = scandir(Config::uploadsDir());
    if (is_array($scanUploads)) {
        foreach ($scanUploads as $uploadName) {
            if ($uploadName === '.' || $uploadName === '..') {
                continue;
            }
            $uploadPath = Config::uploadsDir() . $uploadName;
            if (!is_file($uploadPath)) {
                continue;
            }
            $ext = strtolower(pathinfo($uploadName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'xlsx'], true)) {
                continue;
            }
            $spreadsheetUploadOptions[] = [
                'path' => 'uploads/' . $uploadName,
                'label' => $uploadName,
            ];
        }
        usort($spreadsheetUploadOptions, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));
    }
}

foreach ($spreadsheetUploadOptions as $uploadOption) {
    $spreadsheetUploadOptionsHtml .= '<option value="' . htmlspecialchars($uploadOption['path'], ENT_QUOTES) . '">'
        . htmlspecialchars($uploadOption['label'])
        . '</option>';
}

// Session Health Check
if (!empty($_SESSION['hr_admin'])) {
    $lastActivity = (int) ($_SESSION['admin_last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > $adminSessionTimeout) {
        unset($_SESSION['hr_admin']);
        $_SESSION['admin_msg'] = 'Phiên làm việc đã hết hạn.';
        $_SESSION['admin_msg_type'] = 'error';
    } else {
        $_SESSION['admin_last_activity'] = time();
    }
}

// ─── GET: Download Auth File ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_auth_file') {
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($_GET['csrf_token'] ?? '')) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    AdminFileManager::downloadAuthFile($config); // exits internally
}

// ─── AJAX: Upload Auth File ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'upload_auth_file') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Unauthorized hoặc CSRF không hợp lệ.']);
        exit;
    }
    echo json_encode(AdminFileManager::uploadAuthFile($config));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'resolve_auth_upload_duplicates') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $token = (string) ($_POST['resolution_token'] ?? '');
    $keepRowIndexes = $_POST['keep_row_indexes'] ?? [];
    if (!is_array($keepRowIndexes)) {
        $keepRowIndexes = [$keepRowIndexes];
    }
    echo json_encode(AdminFileManager::resolvePendingAuthUpload($config, $token, $keepRowIndexes));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'discard_auth_upload_duplicates') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $token = (string) ($_POST['resolution_token'] ?? '');
    echo json_encode(AdminFileManager::discardPendingAuthUpload($token));
    exit;
}

// ─── AJAX: Preview Auth File ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'preview_auth_file') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    echo json_encode(AdminFileManager::previewAuthFile($config));
    exit;
}

// ─── AJAX: Get Auth Data (Web Editor) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'get_auth_data') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    echo json_encode(AdminFileManager::getAuthData($config));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'search_auth_employee_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $query = (string) ($_POST['query'] ?? '');
    echo json_encode(AdminFileManager::searchAuthEmployees($config, $query, 8));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'get_auth_employee_lookup_detail') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $empId = (string) ($_POST['emp_id'] ?? '');
    echo json_encode(AdminFileManager::getAuthEmployeeDetail($config, $empId));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'admin_verify_employee_login') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $empId = (string) ($_POST['emp_id'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    echo json_encode(AdminFileManager::adminVerifyEmployeeLogin($config, $empId, $password));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'admin_lookup_employee_payroll') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $empId = (string) ($_POST['emp_id'] ?? '');
    $periodIndex = isset($_POST['period_index']) ? (int) $_POST['period_index'] : -1;
    echo json_encode(AdminFileManager::lookupPayrollForEmployee($config, $empId, $periodIndex));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'admin_lookup_employee_attendance') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $empId = (string) ($_POST['emp_id'] ?? '');
    $fromDate = (string) ($_POST['from_date'] ?? '');
    $toDate = (string) ($_POST['to_date'] ?? '');
    $state = CheckActions::buildAdminLookupState($config, $empId, $fromDate, $toDate);
    if (($state['api_error'] ?? '') !== '') {
        echo json_encode(['ok' => false, 'message' => $state['api_error']]);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'state' => $state,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'admin_create_employee_attendance_share') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $empId = (string) ($_POST['emp_id'] ?? '');
    $fromDate = (string) ($_POST['from_date'] ?? '');
    $toDate = (string) ($_POST['to_date'] ?? '');
    $expiresAtInput = (string) ($_POST['expires_at'] ?? '');
    $expiresAt = CheckActions::normalizeShareExpiryTimestamp($expiresAtInput);
    if ($expiresAt === null) {
        echo json_encode(['ok' => false, 'message' => 'Ngày giờ hết hạn không hợp lệ hoặc đã ở trong quá khứ.']);
        exit;
    }
    $state = CheckActions::buildAdminLookupState($config, $empId, $fromDate, $toDate);
    if (($state['api_error'] ?? '') !== '') {
        echo json_encode(['ok' => false, 'message' => $state['api_error']]);
        exit;
    }
    if (empty($state['has_data'])) {
        echo json_encode(['ok' => false, 'message' => 'Không có dữ liệu chấm công để tạo link.']);
        exit;
    }

    $share = CheckActions::createAttendanceShare($state, $expiresAt);
    if (!is_array($share)) {
        echo json_encode(['ok' => false, 'message' => 'Không thể tạo link chia sẻ chấm công lúc này.']);
        exit;
    }

    $shareToken = (string) ($share['token'] ?? '');
    $shareExpiresAt = (int) ($share['expires_at'] ?? 0);
    Security::auditLog('attendance_share_created', [
        'employee_id' => (string) ($state['employee_id'] ?? ''),
        'share_token_prefix' => substr($shareToken, 0, 8),
        'expires_at' => $shareExpiresAt,
    ]);
    echo json_encode([
        'ok' => true,
        'state' => $state,
        'share_token' => $shareToken,
        'share_url' => 'index.php?page=attendance&share=' . $shareToken,
        'share_expires_at' => $shareExpiresAt,
        'expires_at_input' => $expiresAtInput,
    ]);
    exit;
}

// ─── AJAX: Save Auth Data (Web Editor) ─────────────────────────────────────
$_isSaveAuthData = (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    (($_GET['ajax_action'] ?? '') === 'save_auth_data' || ($_POST['ajax_action'] ?? '') === 'save_auth_data')
);
if ($_isSaveAuthData) {
    header('Content-Type: application/json; charset=utf-8');
    $csrfFromGet  = $_GET['csrf_token'] ?? '';
    $csrfFromPost = $_POST['csrf_token'] ?? '';
    $csrfCheck    = $csrfFromGet !== '' ? $csrfFromGet : $csrfFromPost;
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($csrfCheck)) {
        echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        echo json_encode(['ok' => false, 'message' => 'Dữ liệu JSON không hợp lệ.']);
        exit;
    }
    echo json_encode(AdminFileManager::saveAuthData($config, $payload));
    exit;
}

// ─── AJAX: Inspect Local Period File Sheets ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'inspect_period_file_sheets') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $existingFile = $_POST['existing_file'] ?? '';
    echo json_encode(AdminActions::inspectSpreadsheetSheets('period_file', is_string($existingFile) ? $existingFile : ''));
    exit;
}

// ─── AJAX: Delete File ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_file') {
    header('Content-Type: application/json');
    if (empty($_SESSION['hr_admin']) || !Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized or Invalid CSRF']);
        exit;
    }

    $filename = basename($_POST['filename'] ?? '');
    if (!$filename) { echo json_encode(['status' => 'error', 'message' => 'Invalid Filename']); exit; }

    // ── Protected system files – can NEVER be deleted ────────────────────────
    $protectedNames = ['.htaccess', '.env', '.env.example', '.gitignore', 'web.config'];
    if (
        in_array(strtolower($filename), array_map('strtolower', $protectedNames), true)
        || str_starts_with($filename, '.')
    ) {
        Security::auditLog('admin_blocked_delete_protected', ['filename' => $filename]);
        echo json_encode(['status' => 'error', 'message' => 'File "' . htmlspecialchars($filename) . '" là file hệ thống được bảo vệ, không thể xóa.']);
        exit;
    }
    // ─────────────────────────────────────────────────────────────────────────

    $uploadDir   = rtrim(Config::uploadsDir(), '/\\');
    $targetPath  = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    // Path traversal guard: resolved path must stay inside uploads dir
    $realUploadDir = realpath($uploadDir);
    $realTarget    = realpath($targetPath);
    if ($realUploadDir === false || $realTarget === false || strpos($realTarget, $realUploadDir . DIRECTORY_SEPARATOR) !== 0) {
        echo json_encode(['status' => 'error', 'message' => 'Đường dẫn không hợp lệ.']);
        exit;
    }

    if (AdminActions::isUploadedFileInUse($config, $filename)) {
        echo json_encode(['status' => 'error', 'message' => 'File đang được dùng trong cấu hình, không thể xóa.']);
        exit;
    }

    if (unlink($realTarget)) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'File not found or delete failed']);
    }
    exit;
}


// Form Submission handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
    if (!Security::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['admin_msg'] = 'Invalid Security Token.';
        $_SESSION['admin_msg_type'] = 'error';
    } else {
        $result = AdminActions::handle($config);
        $_SESSION['admin_msg'] = $result['msg'];
        $_SESSION['admin_msg_type'] = $result['type'];
    }
    header("Location: admin.php");
    exit;
}

if (isset($_SESSION['admin_msg'])) {
    $adminMsg = $_SESSION['admin_msg'];
    $adminMsgType = $_SESSION['admin_msg_type'];
    unset($_SESSION['admin_msg'], $_SESSION['admin_msg_type']);
}

$isLoggedIn = !empty($_SESSION['hr_admin']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — <?= htmlspecialchars($config['site_company'] ?? 'HR') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin/admin.css?v=<?= time() ?>">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="<?= $isLoggedIn ? '' : 'is-admin-login' ?>">
<?php if (!$isLoggedIn): ?>
<div class="login-page">
    <div class="login-brand" aria-hidden="true">
        <div class="login-brand-bg"></div>
        <div class="login-brand-content">
            <div class="login-brand-badge"><i data-lucide="shield-check"></i></div>
            <p class="login-brand-kicker">Khu vực quản trị</p>
            <h1 class="login-brand-title"><?= htmlspecialchars($config['site_company'] ?? 'HRM Portal') ?></h1>
            <p class="login-brand-desc"><?= htmlspecialchars($config['site_subtitle'] ?? 'Employee Self-Service') ?> — cấu hình kỳ lương, tệp dữ liệu và hiển thị cổng nhân viên.</p>
            <ul class="login-brand-points">
                <li><span class="login-brand-point-ic"><i data-lucide="lock"></i></span> Phiên làm việc &amp; CSRF</li>
                <li><span class="login-brand-point-ic"><i data-lucide="file-spreadsheet"></i></span> Nguồn Google / file nội bộ</li>
                <li><span class="login-brand-point-ic"><i data-lucide="eye-off"></i></span> Không lưu mật khẩu trên trình duyệt công cộng</li>
            </ul>
        </div>
    </div>
    <div class="login-main">
        <div class="login-main-inner">
            <a class="login-back-link" href="index.php"><i data-lucide="arrow-left"></i> Về cổng nhân viên</a>

            <div class="login-card-v3">
                <div class="login-card-head">
                    <div class="login-card-logo" aria-hidden="true"><?= htmlspecialchars($config['site_logo_text'] ?? 'HR') ?></div>
                    <h2 class="login-card-title">Đăng nhập quản trị</h2>
                    <p class="login-card-sub">Chỉ người được ủy quyền. Mật khẩu không hiển thị lại sau khi thoát.</p>
                </div>
                <form method="POST" class="login-form-v3">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">

                    <?php if ($adminMsg): ?>
                    <div class="login-alert login-alert--<?= $adminMsgType === 'success' ? 'success' : 'error' ?>" role="alert">
                        <i data-lucide="<?= $adminMsgType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                        <span><?= htmlspecialchars($adminMsg) ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="field-group login-field-group">
                        <div class="login-label-row">
                            <label class="field-label" for="admin-pass-input">Mật khẩu quản trị</label>
                            <span class="login-pill">Nội bộ</span>
                        </div>
                        <div class="password-input-wrap">
                            <input type="password" id="admin-pass-input" name="admin_pass" class="field-input login-input" placeholder="Nhập mật khẩu" required autofocus autocomplete="current-password">
                            <button type="button" class="password-toggle-btn" id="toggle-admin-pass" aria-label="Hiện/ẩn mật khẩu">
                                <i data-lucide="eye"></i>
                            </button>
                        </div>
                        <div class="login-hints">
                            <span id="capslock-warning" class="capslock-warning" hidden>Caps Lock đang bật</span>
                            <span class="login-hint">Sai nhiều lần có thể bị tạm khóa đăng nhập.</span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-login-submit">
                        <span>Đăng nhập</span>
                        <i data-lucide="arrow-right"></i>
                    </button>
                </form>
            </div>
            <?php
                $loginFootCompany = trim((string) ($config['site_company'] ?? ''));
                $loginFootLine = '© ' . date('Y');
                if ($loginFootCompany !== '') {
                    $loginFootLine .= ' ' . htmlspecialchars($loginFootCompany, ENT_QUOTES, 'UTF-8');
                }
                $loginFootLine .= ' · Truy cập được ghi nhật ký.';
            ?>
            <p class="login-footnote"><?= $loginFootLine ?></p>
        </div>
    </div>
</div>
<?php else: ?>
<div class="admin-container">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-brand">
                <div class="sidebar-logo"><?= htmlspecialchars($config['site_logo_text'] ?? 'HR') ?></div>
                <div class="sidebar-title">Management</div>
            </div>
            <div class="sidebar-nav">
                <div class="sidebar-label">Cấu hình</div>
                <button type="button" class="admin-menu-item active" data-target="tab-periods" data-label="Kỳ Lương" onclick="switchTab('tab-periods')"><i data-lucide="calendar"></i> <span>Kỳ Lương</span></button>
                <button type="button" class="admin-menu-item" data-target="tab-files" data-label="Tệp tin" onclick="switchTab('tab-files')"><i data-lucide="folder"></i> <span>Tệp tin</span></button>
                <button type="button" class="admin-menu-item" data-target="tab-auth" data-label="Xác thực" onclick="switchTab('tab-auth')"><i data-lucide="shield"></i> <span>Xác thực</span></button>
                <button type="button" class="admin-menu-item" data-target="tab-lookup" data-label="Tra cứu nhanh" onclick="switchTab('tab-lookup')"><i data-lucide="search-check"></i> <span>Tra cứu nhanh</span></button>
                <button type="button" class="admin-menu-item" data-target="tab-cols" data-label="Cấu trúc" onclick="switchTab('tab-cols')"><i data-lucide="layout-grid"></i> <span>Cấu trúc</span></button>
                <button type="button" class="admin-menu-item" data-target="tab-attendance" data-label="Chấm công" onclick="switchTab('tab-attendance')"><i data-lucide="clock-3"></i> <span>Chấm công</span></button>
                <button type="button" class="admin-menu-item" data-target="tab-ui" data-label="Giao diện" onclick="switchTab('tab-ui')"><i data-lucide="palette"></i> <span>Giao diện</span></button>
                
                <div class="sidebar-label">Bảo mật</div>
                <button type="button" class="admin-menu-item" data-target="tab-pass" data-label="Mật khẩu" onclick="switchTab('tab-pass')"><i data-lucide="key"></i> <span>Mật khẩu</span></button>
                <a href="index.php" class="admin-menu-item" target="_blank"><i data-lucide="external-link"></i> <span>Trang chủ NV</span></a>

                <form method="POST" style="margin-top:auto;">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                    <button type="submit" class="admin-menu-item logout-btn"><i data-lucide="log-out"></i> <span>Đăng Xuất</span></button>
                </form>
            </div>
        </aside>

        <!-- Main -->
        <main class="admin-main">
            <header class="admin-header">
                <div class="admin-header-title" id="admin-header-title" data-default-title="Hệ thống Quản trị">Hệ thống Quản trị</div>
                <div class="admin-header-actions">
                    <span class="admin-badge">Admin Session Active</span>
                </div>
            </header>

            <nav class="admin-mobile-tabs" aria-label="Điều hướng nhanh admin">
                <button type="button" class="admin-mobile-tab active" data-target="tab-periods" data-label="Kỳ Lương" onclick="switchTab('tab-periods')"><i data-lucide="calendar"></i><span>Kỳ lương</span></button>
                <button type="button" class="admin-mobile-tab" data-target="tab-auth" data-label="Xác thực" onclick="switchTab('tab-auth')"><i data-lucide="shield"></i><span>Xác thực</span></button>
                <button type="button" class="admin-mobile-tab" data-target="tab-lookup" data-label="Tra cứu nhanh" onclick="switchTab('tab-lookup')"><i data-lucide="search-check"></i><span>Tra cứu</span></button>
                <button type="button" class="admin-mobile-tab" data-target="tab-attendance" data-label="Chấm công" onclick="switchTab('tab-attendance')"><i data-lucide="clock-3"></i><span>Chấm công</span></button>
                <button type="button" class="admin-mobile-tab" data-target="tab-files" data-label="Tệp tin" onclick="switchTab('tab-files')"><i data-lucide="folder"></i><span>Tệp</span></button>
                <button type="button" class="admin-mobile-tab" data-target="tab-cols" data-label="Cấu trúc" onclick="switchTab('tab-cols')"><i data-lucide="layout-grid"></i><span>Cột</span></button>
                <button type="button" class="admin-mobile-tab" data-target="tab-ui" data-label="Giao diện" onclick="switchTab('tab-ui')"><i data-lucide="palette"></i><span>Giao diện</span></button>
                <button type="button" class="admin-mobile-tab" data-target="tab-pass" data-label="Mật khẩu" onclick="switchTab('tab-pass')"><i data-lucide="key"></i><span>Mật khẩu</span></button>
            </nav>

            <div class="admin-content">
                <?php if ($adminMsg): ?>
                    <div class="msg msg-<?= $adminMsgType ?>">
                        <i data-lucide="<?= $adminMsgType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                        <span><?= htmlspecialchars($adminMsg) ?></span>
                    </div>
                <?php endif; ?>

                <form id="admin-form" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_config_all">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">

                    <!-- Tab: Periods -->
                    <div class="tab-pane active" id="tab-periods">
                        <div class="stat-row">
                            <div class="stat-card">
                                <div class="stat-label">Tổng số kỳ</div>
                                <div class="stat-value"><?= count($config['periods'] ?? []) ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-label">Google Sheets</div>
                                <div class="stat-value"><?= count(array_filter($config['periods'] ?? [], fn($p) => ($p['source_type']??'google') === 'google')) ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-label">File Excel</div>
                                <div class="stat-value"><?= count(array_filter($config['periods'] ?? [], fn($p) => ($p['source_type']??'') === 'local')) ?></div>
                            </div>
                        </div>

                        <div class="admin-card">
                            <div class="admin-card-header">
                                <h2 class="admin-card-title"><i data-lucide="calendar-days"></i> Quản lý Kỳ Lương</h2>
                                <button type="button" class="btn btn-primary btn-sm" onclick="addPeriod()"><i data-lucide="plus"></i> Thêm kỳ mới</button>
                            </div>
                            <div class="admin-card-body" style="padding:0">
                                <div class="period-list">
	                                    <?php foreach (($config['periods'] ?? []) as $idx => $p): 
	                                        $isLocal = ($p['source_type'] ?? '') === 'local';
	                                        $hasFile = $isLocal && !empty($p['local_file']);
	                                        $isEnabled = !array_key_exists('enabled', $p) || $p['enabled'] !== false;
                                            $localReady = $hasFile && is_file(\App\Config::uploadsDir() . basename((string) ($p['local_file'] ?? '')));
                                            $googleReady = !$isLocal && trim((string) ($p['sheet_id'] ?? '')) !== '';
                                            $dataReady = $isLocal ? $localReady : $googleReady;
	                                    ?>
                                    <div class="period-row">
                                        <div class="period-header" onclick="togglePeriodCard(this)">
                                            <div class="period-meta">
                                                <i data-lucide="calendar" class="text-muted"></i>
                                                <span class="period-title"><?= htmlspecialchars($p['label'] ?: 'Kỳ chưa đặt tên') ?></span>
                                                <span class="period-tag <?= $isLocal ? 'local':'google' ?>"><i data-lucide="<?= $isLocal ? 'database':'globe' ?>"></i> <?= $isLocal ? 'Local':'Google' ?></span>
	                                                <div class="status-indicator <?= $isEnabled ? 'active' : 'inactive' ?>">
	                                                    <span class="status-dot"></span> <?= $isEnabled ? 'Đang bật' : 'Đang tắt' ?>
	                                                </div>
                                                    <span class="period-tag <?= $dataReady ? 'local' : 'google' ?>"><i data-lucide="<?= $dataReady ? 'check-circle' : 'alert-triangle' ?>"></i> <?= $dataReady ? 'Sẵn sàng' : 'Thiếu dữ liệu' ?></span>
                                                <?php if ($hasFile): ?>
                                                    <span class="period-tag local period-file-tag"><i data-lucide="file-spreadsheet"></i> <?= htmlspecialchars(basename($p['local_file'])) ?></span>
                                                <?php endif; ?>
                                                <?php if ($isLocal): ?>
                                                    <span class="period-tag google period-sheet-tag"><i data-lucide="layers-3"></i> <?= htmlspecialchars(($p['sheet_name'] ?? '') !== '' ? $p['sheet_name'] : 'Sheet #' . (int) ($p['sheet_index'] ?? 0)) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="period-row-actions">
                                                <button type="button" class="btn-file-delete" onclick="event.stopPropagation(); this.closest('.period-row').remove()"><i data-lucide="trash-2"></i></button>
                                                <i data-lucide="chevron-down" class="period-chevron"></i>
                                            </div>
                                        </div>
                                        <div class="period-body">
                                            <div class="field-grid-2">
                                                <div class="field-group">
                                                    <label class="field-label">Tên hiển thị</label>
                                                    <input type="text" name="period_labels[]" class="field-input" value="<?= htmlspecialchars($p['label'] ?? '') ?>" oninput="updateCompactLabel(this)">
                                                </div>
                                                <div class="field-group">
                                                    <label class="field-label">Ngày xuất bản</label>
                                                    <input type="datetime-local" name="period_publish_dates[]" class="field-input" value="<?= htmlspecialchars(str_replace(' ','T', $p['publish_date'] ?? '')) ?>">
                                                </div>
                                            </div>
                                            <div class="field-group">
                                                <label class="field-label">Trạng thái kỳ lương</label>
                                                <label class="period-toggle-row">
                                                    <input type="checkbox" class="period-enabled-toggle" <?= $isEnabled ? 'checked' : '' ?> onchange="syncPeriodEnabled(this)">
                                                    <input type="hidden" name="period_enableds[]" class="period-enabled-hidden" value="<?= $isEnabled ? '1' : '0' ?>">
                                                    <span><?= $isEnabled ? 'Đang bật cho nhân viên tra cứu' : 'Đang tắt, nhân viên không xem được' ?></span>
                                                </label>
                                            </div>
                                            <div class="field-group">
                                                <label class="field-label">Nguồn dữ liệu</label>
                                                <select name="period_source_types[]" class="field-input" onchange="toggleSourceType(this); updateCompactSource(this)">
                                                    <option value="local" <?= ($p['source_type']??'local')==='local'?'selected':'' ?>>Tải lên Excel (Local)</option>
                                                    <option value="google" <?= ($p['source_type']??'local')==='google'?'selected':'' ?>>Google Sheets</option>
                                                </select>
                                            </div>
                                            <div class="source-google" style="<?= ($p['source_type']??'local')!=='google'?'display:none;':'' ?>">
                                                <div class="field-grid-2">
                                                    <div class="field-group">
                                                        <label class="field-label">Spreadsheet ID</label>
                                                        <input type="text" name="period_sheet_ids[]" class="field-input mono" value="<?= htmlspecialchars($p['sheet_id'] ?? '') ?>">
                                                    </div>
                                                    <div class="field-group">
                                                        <label class="field-label">GID</label>
                                                        <input type="text" name="period_gids[]" class="field-input mono" value="<?= htmlspecialchars($p['gid'] ?? '0') ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="source-local" style="<?= ($p['source_type']??'local')!=='local'?'display:none;':'' ?>">
                                                <div class="field-grid-2">
                                                    <div class="field-group">
                                                        <label class="field-label">Tệp Excel</label>
                                                        <select name="period_local_files[]" class="field-input period-local-file-select" onchange="inspectPeriodLocalSheets(this)">
                                                            <option value="">Chọn file đã tải lên</option>
                                                            <?php foreach ($spreadsheetUploadOptions as $uploadOption): ?>
                                                                <option value="<?= htmlspecialchars($uploadOption['path']) ?>" <?= ($p['local_file'] ?? '') === $uploadOption['path'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($uploadOption['label']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <input type="file" name="period_files[]" class="field-input period-file-input" accept=".csv,.xlsx" onchange="inspectPeriodLocalSheets(this)">
                                                        <?php if ($hasFile): ?>
                                                            <div class="field-file-status exists">
                                                                <i data-lucide="check-circle"></i>
                                                                <span>Hiện có: <?= htmlspecialchars(basename($p['local_file'])) ?></span>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="field-file-status empty">
                                                                <i data-lucide="file-warning"></i>
                                                                <span>Chưa có tệp dữ liệu</span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="field-group">
                                                        <label class="field-label">Sheet dữ liệu</label>
                                                        <select name="period_sheet_indexes[]" class="field-input period-sheet-select" data-selected-index="<?= (int) ($p['sheet_index'] ?? 0) ?>">
                                                            <option value="<?= (int) ($p['sheet_index'] ?? 0) ?>">
                                                                <?= htmlspecialchars(($p['sheet_name'] ?? '') !== '' ? $p['sheet_name'] : 'Sheet #' . (int) ($p['sheet_index'] ?? 0)) ?>
                                                            </option>
                                                        </select>
                                                        <input type="hidden" name="period_sheet_names[]" class="period-sheet-name-input" value="<?= htmlspecialchars($p['sheet_name'] ?? '') ?>">
                                                        <div class="field-help-text period-sheet-hint">Có thể dùng cùng một file cho nhiều kỳ và chọn sheet khác nhau.</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="field-group">
                                                <label class="field-label">Cột hiển thị (Tất cả)</label>
                                                <div class="tag-input-wrapper">
                                                    <input type="hidden" name="period_cols[]" class="real-cols-input" value="<?= htmlspecialchars($p['cols'] ?? '') ?>">
                                                    <div class="tag-container" onclick="this.querySelector('.tag-input').focus()">
                                                        <input type="text" class="tag-input" onkeydown="handleTagInput(event)">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="field-grid-2">
                                                <div class="field-group">
                                                    <label class="field-label">Cột nổi bật (Pills)</label>
                                                    <div class="tag-input-wrapper">
                                                        <input type="hidden" name="period_highlight_cols[]" class="real-cols-input" value="<?= htmlspecialchars($p['highlight_cols'] ?? '') ?>">
                                                        <div class="tag-container" onclick="this.querySelector('.tag-input').focus()">
                                                            <input type="text" class="tag-input" onkeydown="handleTagInput(event)">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="field-group">
                                                    <label class="field-label">Cột Định dạng Tiền (VND)</label>
                                                    <div class="tag-input-wrapper">
                                                        <input type="hidden" name="period_money_cols[]" class="real-cols-input" value="<?= htmlspecialchars($p['money_cols'] ?? '') ?>">
                                                        <div class="tag-container" onclick="this.querySelector('.tag-input').focus()">
                                                            <input type="text" class="tag-input" onkeydown="handleTagInput(event)">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Files -->
                    <div class="tab-pane" id="tab-files">
                        <div class="admin-card">
                            <div class="admin-card-header">
                                <h2 class="admin-card-title"><i data-lucide="folder"></i> Quản lý Tệp tin</h2>
                            </div>
                            <div class="admin-card-body">
                                <?php
                                    $uploadDir = Config::uploadsDir(); $filesList = [];
                                    if (is_dir($uploadDir)) {
                                        $scan = scandir($uploadDir);
                                        foreach ($scan as $f) if ($f !== '.' && $f !== '..') {
                                            $path = $uploadDir . $f;
                                            if (is_file($path)) $filesList[] = ['name' => $f, 'size' => filesize($path), 'mtime' => filemtime($path)];
                                        }
                                        usort($filesList, fn($a, $b) => $b['mtime'] - $a['mtime']);
                                    }
                                    $usageMap = AdminActions::getFileUsageMap($config);
                                    $usedFiles = [];
                                    $unusedFiles = [];
                                    foreach ($filesList as $file) {
                                        if (isset($usageMap[$file['name']])) $usedFiles[] = $file;
                                        else $unusedFiles[] = $file;
                                    }
                                ?>
                                <div class="file-summary-row">
                                    <div class="file-summary-card"><span class="k">Tổng file</span><span class="v"><?= count($filesList) ?></span></div>
                                    <div class="file-summary-card used"><span class="k">Đang dùng</span><span class="v"><?= count($usedFiles) ?></span></div>
                                    <div class="file-summary-card orphan"><span class="k">Không dùng</span><span class="v"><?= count($unusedFiles) ?></span></div>
                                </div>

                                <div class="files-section-title">Đang được sử dụng</div>
                                <div class="file-grid">
                                    <?php if (empty($usedFiles)): ?>
                                        <div class="file-empty-state">Không có file nào đang được liên kết.</div>
                                    <?php endif; ?>
                                    <?php foreach($usedFiles as $file): $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)); ?>
                                    <div class="file-card-v2 used">
                                        <button type="button" class="file-delete-btn" disabled title="File đang được dùng trong cấu hình"><i data-lucide="lock"></i></button>
                                        <div class="file-icon-box"><i data-lucide="<?= $ext==='csv'?'file-text':'file-spreadsheet' ?>"></i></div>
                                        <div class="file-name"><?= htmlspecialchars($file['name']) ?></div>
                                        <div class="file-meta"><?= round($file['size']/1024, 1) ?> KB • <?= date('d/m/Y H:i', $file['mtime']) ?></div>
                                        <div class="file-usage-banner">Đang sử dụng</div>
                                        <div class="file-usage-list">
                                            <?php foreach (($usageMap[$file['name']] ?? []) as $usage): ?>
                                                <div>• <?= htmlspecialchars($usage) ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="files-section-title">Không được sử dụng (có thể dọn dẹp)</div>
                                <div class="file-grid">
                                    <?php if (empty($unusedFiles)): ?>
                                        <div class="file-empty-state">Không có file mồ côi.</div>
                                    <?php endif; ?>
                                    <?php foreach($unusedFiles as $file): $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)); ?>
                                    <div class="file-card-v2 orphan">
                                        <button type="button" class="file-delete-btn" onclick="deleteUploadedFile('<?= htmlspecialchars($file['name']) ?>', this)"><i data-lucide="trash-2"></i></button>
                                        <div class="file-icon-box"><i data-lucide="<?= $ext==='csv'?'file-text':'file-spreadsheet' ?>"></i></div>
                                        <div class="file-name"><?= htmlspecialchars($file['name']) ?></div>
                                        <div class="file-meta"><?= round($file['size']/1024, 1) ?> KB • <?= date('d/m/Y H:i', $file['mtime']) ?></div>
                                        <div class="file-usage-banner orphan">Chưa liên kết</div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Auth -->
                    <div class="tab-pane" id="tab-auth">
                        <div class="admin-card">
                            <div class="admin-card-header"><h2 class="admin-card-title"><i data-lucide="shield"></i> Xác thực hệ thống</h2></div>
                            <div class="admin-card-body">
                                <div class="settings-group">
                                    <div class="settings-group-title"><i data-lucide="database"></i> Nguồn dữ liệu nhân sự</div>
                                    <div class="field-group">
                                        <label class="field-label">Loại nguồn</label>
                                        <select name="auth_source_type" class="field-input" onchange="toggleSourceType(this)">
                                            <option value="google" <?= ($config['auth_source_type']??'google')==='google'?'selected':'' ?>>Google Sheets</option>
                                            <option value="local" <?= ($config['auth_source_type']??'')=='local'?'selected':'' ?>>File Excel</option>
                                        </select>
                                    </div>
                                    <div class="source-google" style="<?= ($config['auth_source_type']??'google')!=='google'?'display:none;':''  ?>">
                                        <div class="field-grid-2">
                                            <div class="field-group">
                                                <label class="field-label">Spreadsheet ID</label>
                                                <input type="text" name="auth_sheet_id" class="field-input mono" value="<?= htmlspecialchars($config['auth_sheet_id'] ?? '') ?>">
                                            </div>
                                            <div class="field-group">
                                                <label class="field-label">GID</label>
                                                <input type="text" name="auth_gid" class="field-input mono" value="<?= htmlspecialchars($config['auth_gid'] ?? '0') ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="source-local" style="<?= ($config['auth_source_type']??'')=='local'?''  :'display:none;' ?>">
                                        <input type="hidden" name="auth_local_file" id="auth-local-file-hidden" value="<?= htmlspecialchars($config['auth_local_file'] ?? '') ?>">
                                        <!-- Current File Banner -->
                                        <div id="auth-file-banner" class="auth-file-banner">
                                            <?php if (!empty($config['auth_local_file'])): ?>
                                            <div class="auth-file-info">
                                                <i data-lucide="file-spreadsheet" class="auth-file-icon"></i>
                                                <div>
                                                    <div class="auth-file-name" id="auth-current-filename"><?= htmlspecialchars(basename($config['auth_local_file'])) ?></div>
                                                    <div class="auth-file-meta">File xác thực nhân sự đang hoạt động</div>
                                                </div>
                                            </div>
                                            <div class="auth-file-actions">
                                                <a id="auth-download-btn" href="admin.php?action=download_auth_file&amp;csrf_token=<?= urlencode($csrfToken) ?>" class="btn btn-sm btn-outline-secondary"><i data-lucide="download"></i> Tải xuống</a>
                                            </div>
                                            <?php else: ?>
                                            <div class="auth-file-empty" id="auth-file-empty-state"><i data-lucide="file-warning"></i> <span>Chưa có file xác thực. Hãy tải lên file bên dưới.</span></div>
                                            <?php endif; ?>
                                        </div>
                                        <!-- Upload Zone -->
                                        <div id="auth-upload-zone" class="auth-upload-zone">
                                            <div class="settings-group-title" style="margin-bottom:12px"><i data-lucide="upload-cloud"></i> Tải lên file xác thực mới</div>
                                            <div class="auth-upload-row">
                                                <label class="auth-file-input-label" for="auth-file-input"><i data-lucide="file-plus"></i> <span id="auth-file-label-text">Chọn file .xlsx</span></label>
                                                <input type="file" id="auth-file-input" accept=".xlsx" class="auth-file-input-hidden" onchange="onAuthFileSelected(this)" aria-label="Chọn file xác thực XLSX">
                                                <button type="button" id="auth-upload-btn" class="btn btn-primary" onclick="uploadAuthFile()" disabled aria-busy="false"><i data-lucide="upload"></i> <span>Tải lên</span></button>
                                            </div>
                                            <p class="auth-upload-hint">Chỉ nhận file <strong>.xlsx</strong>. Phải có cột <strong>MÃ NV</strong> và <strong>MẬT KHẨU</strong>. Tối đa 10 MB.</p>
                                        </div>
                                        <!-- Preview Panel -->
                                        <div id="auth-preview-panel" class="auth-preview-panel" style="display:none" aria-live="polite">
                                            <div class="settings-group-title"><i data-lucide="eye"></i> Xem trước 5 dòng đầu</div>
                                            <div id="auth-preview-table" class="auth-preview-table"></div>
                                        </div>
                                        <!-- Toast -->
                                        <div id="auth-toast" class="auth-toast" role="alert" aria-live="assertive" aria-atomic="true" style="display:none"></div>
                                        <div id="auth-duplicate-modal" class="auth-duplicate-modal" style="display:none" aria-hidden="true">
                                            <div class="auth-duplicate-backdrop" onclick="cancelAuthDuplicateResolution()"></div>
                                            <div class="auth-duplicate-dialog" role="dialog" aria-modal="true" aria-labelledby="auth-duplicate-title">
                                                <div class="auth-duplicate-header">
                                                    <div>
                                                        <h3 id="auth-duplicate-title">Phát hiện Mã NV trùng trong file upload</h3>
                                                        <p id="auth-duplicate-subtitle">Đánh dấu các dòng cần xóa, mỗi Mã NV phải giữ lại đúng 1 dòng.</p>
                                                    </div>
                                                    <button type="button" class="auth-duplicate-close" onclick="cancelAuthDuplicateResolution()" aria-label="Đóng popup">
                                                        <i data-lucide="x"></i>
                                                    </button>
                                                </div>
                                                <div id="auth-duplicate-body" class="auth-duplicate-body"></div>
                                                <div id="auth-duplicate-error" class="auth-duplicate-error" style="display:none"></div>
                                                <div class="auth-duplicate-footer">
                                                    <div class="auth-duplicate-footer-left">
                                                        <div class="auth-duplicate-recommendation">
                                                            <div class="auth-duplicate-recommendation-badge"><i data-lucide="sparkles"></i> Khuyến nghị hệ thống</div>
                                                            <div class="auth-duplicate-recommendation-text">
                                                                Mặc định hợp lý nhất thường là <strong>xóa dòng đầu, giữ dòng cuối</strong>, vì dòng cuối thường là bản HR vừa cập nhật mới hơn trong file upload.
                                                            </div>
                                                        </div>
                                                        <div class="auth-duplicate-live-status" id="auth-duplicate-live-status">
                                                            <i data-lucide="badge-info"></i>
                                                            <span>Mặc định hiện tại: <strong>đang giữ dòng cuối</strong> cho mỗi Mã NV trùng.</span>
                                                        </div>
                                                        <div class="auth-duplicate-bulk-actions">
                                                            <button type="button" class="btn btn-outline-secondary auth-duplicate-bulk-btn auth-duplicate-bulk-btn--recommended" id="auth-duplicate-delete-first-btn" onclick="markDeleteFirstRowsForAllDuplicates()">
                                                                <i data-lucide="arrow-up-circle"></i>
                                                                <span>Xóa dòng đầu cho tất cả</span>
                                                            </button>
                                                            <button type="button" class="btn btn-outline-secondary auth-duplicate-bulk-btn" id="auth-duplicate-delete-last-btn" onclick="markDeleteLastRowsForAllDuplicates()">
                                                                <i data-lucide="arrow-down-circle"></i>
                                                                <span>Xóa dòng cuối cho tất cả</span>
                                                            </button>
                                                        </div>
                                                        <div class="auth-duplicate-choice-guide">
                                                            <div class="auth-duplicate-choice-item">
                                                                <strong><i data-lucide="thumbs-up"></i> Tùy chọn: Xóa dòng đầu cho tất cả</strong>
                                                                <span>Dùng khi HR vừa upload một file mới hơn và muốn giữ lại bản cập nhật nằm ở dòng cuối của từng nhóm trùng.</span>
                                                            </div>
                                                            <div class="auth-duplicate-choice-item">
                                                                <strong><i data-lucide="shield-alert"></i> Tùy chọn: Xóa dòng cuối cho tất cả</strong>
                                                                <span>Dùng khi muốn giữ lại dữ liệu cũ hơn ở dòng đầu, hoặc khi HR biết dòng cuối là nhập nhầm.</span>
                                                            </div>
                                                        </div>
                                                        <div class="auth-duplicate-footer-note auth-duplicate-footer-note--recommended">
                                                            <strong><i data-lucide="triangle-alert"></i> Note cho HR:</strong> Nếu hệ thống báo <strong>khác tên</strong> hoặc <strong>khác mật khẩu</strong>, hãy kiểm tra kỹ từng dòng trước khi import để tránh giữ nhầm hồ sơ nhân viên.
                                                        </div>
                                                    </div>
                                                    <button type="button" class="btn btn-secondary" onclick="cancelAuthDuplicateResolution()">Hủy file này</button>
                                                    <button type="button" class="btn btn-primary" id="auth-duplicate-confirm-btn" onclick="confirmAuthDuplicateResolution()">Xóa các dòng đã đánh dấu và import</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Web Editor Panel -->
                        <div id="auth-editor-panel" class="auth-editor-panel" aria-hidden="false">
                            <div class="admin-card">
                                <div class="admin-card-header">
                                    <h2 class="admin-card-title"><i data-lucide="users"></i> Danh sách nhân viên — Chỉnh sửa trực tiếp</h2>
                                    <div class="auth-editor-header-actions">
                                        <span id="auth-editor-count" class="auth-row-count"></span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addAuthEditorRow()" id="auth-add-row-btn"><i data-lucide="user-plus"></i> Thêm nhân viên</button>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="saveAuthData()" id="auth-save-btn" aria-busy="false"><i data-lucide="save"></i> <span id="auth-save-btn-label">Lưu vào Excel</span></button>
                                    </div>
                                </div>
                                <div class="auth-quick-add" id="auth-quick-add">
                                    <div class="auth-quick-add-title"><i data-lucide="user-round-plus"></i> Thêm nhân viên nhanh</div>
                                    <div class="auth-quick-add-grid">
                                        <input type="text" id="qa-emp-id" class="field-input" placeholder="Mã NV">
                                        <input type="text" id="qa-emp-name" class="field-input" placeholder="Họ tên">
                                        <input type="password" id="qa-emp-pass" class="field-input" placeholder="Mật khẩu">
                                        <input type="text" id="qa-emp-dept" class="field-input" placeholder="Bộ phận">
                                        <button type="button" class="btn btn-primary" id="qa-add-btn" onclick="quickAddEmployeeRow()"><i data-lucide="plus"></i> Thêm vào bảng</button>
                                    </div>
                                    <div class="auth-quick-add-note" id="qa-emp-id-note" aria-live="polite"></div>
                                </div>
                                <!-- Search Bar -->
                                <div class="auth-editor-search-bar" id="auth-editor-search-bar">
                                    <div class="auth-search-input-wrap">
                                        <i data-lucide="search" class="auth-search-icon"></i>
                                        <input
                                            type="text"
                                            id="auth-search-input"
                                            class="auth-search-input"
                                            placeholder="Tìm theo Mã NV hoặc Tên nhân viên..."
                                            oninput="filterAuthEditorRows(this.value)"
                                            autocomplete="off"
                                            aria-label="Tìm kiếm nhân viên"
                                        >
                                        <button type="button" id="auth-search-clear" class="auth-search-clear" onclick="clearAuthSearch()" title="Xóa tìm kiếm" style="display:none">
                                            <i data-lucide="x-circle"></i>
                                        </button>
                                    </div>
                                    <span id="auth-search-result" class="auth-search-result"></span>
                                </div>
                                <div id="auth-search-mobile-results" class="auth-search-mobile-results" style="display:none"></div>
                                <div class="admin-card-body" style="padding:0">
                                    <div id="auth-editor-warning" class="auth-editor-warning" style="display:none" role="alert"></div>
                                    <div id="auth-editor-loading" class="auth-editor-loading" style="display:none"><i data-lucide="loader-2" class="spin-icon"></i> Đang tải dữ liệu...</div>
                                    <div id="auth-editor-table-wrap" class="auth-editor-table-wrap" style="display:none">
                                        <table id="auth-editor-table" class="auth-editor-table" aria-label="Bảng danh sách nhân viên">
                                            <thead id="auth-editor-thead"></thead>
                                            <tbody id="auth-editor-tbody"></tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="auth-editor-footer">
                                    <span class="auth-editor-footer-note"><i data-lucide="shield-check"></i> Tự động backup trước mỗi lần lưu</span>
                                    <div id="auth-editor-toast" class="auth-toast-inline" role="status" aria-live="polite"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane" id="tab-lookup">
                        <div class="admin-card">
                            <div class="admin-card-header">
                                <h2 class="admin-card-title"><i data-lucide="search-check"></i> Tra cứu nhanh nhân viên</h2>
                            </div>
                            <div class="admin-card-body">
                                <div class="lookup-hero">
                                    <div>
                                        <div class="lookup-hero-title">Tìm theo Mã NV hoặc Tên, rồi thao tác ngay trong admin</div>
                                        <p class="lookup-hero-subtitle">Phù hợp khi HR cần hỗ trợ nhân viên báo không đăng nhập được hoặc cần kiểm tra nhanh phiếu lương mà không phải lục tay từng file.</p>
                                    </div>
                                    <div class="lookup-hero-note">
                                        <i data-lucide="shield-check"></i>
                                        <span>Mật khẩu đã băm sẽ không hiển thị lại. Admin chỉ cần nhập mật khẩu nhân viên cung cấp để kiểm tra tính hợp lệ.</span>
                                    </div>
                                </div>

                                <div class="lookup-layout">
                                    <section class="lookup-panel">
                                        <div class="lookup-panel-title"><i data-lucide="search"></i> Tìm kiếm nhân viên</div>
                                        <div class="lookup-search-box">
                                            <i data-lucide="search" class="lookup-search-icon"></i>
                                            <input type="text" id="lookup-search-input" class="field-input lookup-search-input" placeholder="Nhập Mã NV hoặc tên nhân viên..." autocomplete="off">
                                            <button type="button" id="lookup-search-clear" class="lookup-search-clear" onclick="clearQuickLookupSearch()" aria-label="Xóa tìm kiếm" style="display:none">
                                                <i data-lucide="x-circle"></i>
                                            </button>
                                        </div>
                                        <div class="lookup-inline-note">Gợi ý sẽ ưu tiên Mã NV khớp chính xác trước, sau đó đến tên và bộ phận.</div>
                                        <div id="lookup-search-status" class="lookup-search-status" aria-live="polite"></div>
                                        <div id="lookup-suggestion-list" class="lookup-suggestion-list" role="listbox" aria-label="Danh sách gợi ý nhân viên"></div>
                                        <div id="lookup-employee-card" class="lookup-employee-card is-empty">
                                            <div class="lookup-empty-state">
                                                <i data-lucide="user-search"></i>
                                                <div>
                                                    <strong>Chưa chọn nhân viên</strong>
                                                    <span>Chọn một gợi ý để xem card chi tiết và bật các thao tác nhanh.</span>
                                                </div>
                                            </div>
                                        </div>
                                    </section>

                                    <section class="lookup-panel lookup-panel--actions">
                                        <div class="lookup-panel-title"><i data-lucide="workflow"></i> Form test nhanh</div>
                                        <div class="lookup-form-grid">
                                            <div class="field-group">
                                                <label class="field-label">Mã NV</label>
                                                <input type="text" id="lookup-test-emp-id" class="field-input mono" placeholder="Chưa chọn nhân viên" readonly>
                                            </div>
                                            <div class="field-group">
                                                <label class="field-label">Họ tên</label>
                                                <input type="text" id="lookup-test-emp-name" class="field-input" placeholder="Chưa chọn nhân viên" readonly>
                                            </div>
                                            <div class="field-group">
                                                <label class="field-label">Bộ phận</label>
                                                <input type="text" id="lookup-test-emp-dept" class="field-input" placeholder="Tự điền sau khi chọn" readonly>
                                            </div>
                                            <div class="field-group">
                                                <label class="field-label">Kỳ lương cần tra cứu</label>
                                                <select id="lookup-period-select" class="field-input">
                                                    <?php foreach (($config['periods'] ?? []) as $periodIdx => $period): ?>
                                                        <option value="<?= (int) $periodIdx ?>"><?= htmlspecialchars((string) (($period['label'] ?? '') !== '' ? $period['label'] : 'Kỳ #' . $periodIdx)) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="field-group">
                                            <label class="field-label">Mật khẩu để kiểm tra</label>
                                            <input type="password" id="lookup-test-password" class="field-input" placeholder="Nhập mật khẩu nhân viên đang dùng">
                                            <div class="field-help-text">Hệ thống không hiển thị lại mật khẩu gốc. Hãy nhập mật khẩu nhân viên cung cấp để test đúng luồng đăng nhập thực tế.</div>
                                        </div>
                                        <div class="lookup-action-row">
                                            <button type="button" class="btn btn-outline-secondary" id="lookup-fill-test-btn" onclick="fillQuickLookupTestFormFromSelected()" disabled><i data-lucide="arrow-down-to-line"></i> Điền vào form test nhanh</button>
                                            <button type="button" class="btn btn-primary" id="lookup-check-login-btn" onclick="quickLookupVerifyLogin()" disabled><i data-lucide="shield-check"></i> Kiểm tra đăng nhập</button>
                                            <button type="button" class="btn btn-secondary" id="lookup-payroll-btn" onclick="quickLookupPayroll()" disabled><i data-lucide="receipt-text"></i> Tra cứu phiếu lương</button>
                                        </div>
                                        <div id="lookup-action-feedback" class="lookup-action-feedback" aria-live="polite"></div>
                                        <div id="lookup-payroll-result" class="lookup-payroll-result"></div>
                                    </section>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Cols -->
                    <div class="tab-pane" id="tab-cols">
                        <div class="admin-card">
                            <div class="admin-card-header"><h2 class="admin-card-title"><i data-lucide="layout-grid"></i> Cấu hình Cột</h2></div>
                            <div class="admin-card-body">
                                <div class="settings-group">
                                    <div class="settings-group-title">Định danh</div>
                                    <div class="field-grid-2">
                                        <div class="field-group"><label class="field-label">Cột Mã NV</label><input type="text" name="col_emp_id" class="field-input" value="<?= htmlspecialchars($config['col_emp_id'] ?? '') ?>"></div>
                                        <div class="field-group"><label class="field-label">Cột Mật khẩu</label><input type="text" name="col_password" class="field-input" value="<?= htmlspecialchars($config['col_password'] ?? '') ?>"></div>
                                    </div>
                                </div>
                                <div class="settings-group">
                                    <div class="settings-group-title"><i data-lucide="bar-chart"></i> Thống kê Dashboard</div>
                                    <div class="field-group">
                                        <label class="field-label">Các cột tóm tắt (Kéo thả để sắp xếp)</label>
                                        <div class="tag-input-wrapper">
                                            <input type="hidden" name="stat_cols" class="real-cols-input" value="<?= htmlspecialchars($config['stat_cols'] ?? '') ?>">
                                            <div class="tag-container" onclick="this.querySelector('.tag-input').focus()">
                                                <input type="text" class="tag-input" onkeydown="handleTagInput(event)">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Attendance -->
                    <div class="tab-pane" id="tab-attendance">
                        <?php $checkEnabled = !array_key_exists('check_enabled', $config) || $config['check_enabled'] !== false; ?>
                        <?php $checkAvailableFrom = str_replace(' ', 'T', (string) ($config['check_available_from'] ?? '')); ?>
                        <?php $checkAvailableUntil = str_replace(' ', 'T', (string) ($config['check_available_until'] ?? '')); ?>
                        <div class="admin-card">
                            <div class="admin-card-header"><h2 class="admin-card-title"><i data-lucide="clock-3"></i> Tích hợp Chấm Công</h2></div>
                            <div class="admin-card-body">
                                <div class="settings-group">
                                    <div class="settings-group-title"><i data-lucide="toggle-left"></i> Trạng thái phân hệ</div>
                                    <div class="field-group">
                                        <label class="period-toggle-row">
                                            <input type="checkbox" <?= $checkEnabled ? 'checked' : '' ?> onchange="this.nextElementSibling.value = this.checked ? '1' : '0'; this.parentElement.querySelector('span').textContent = this.checked ? 'Đang bật trang tra cứu chấm công' : 'Đang tắt trang tra cứu chấm công';">
                                            <input type="hidden" name="check_enabled" value="<?= $checkEnabled ? '1' : '0' ?>">
                                            <span><?= $checkEnabled ? 'Đang bật trang tra cứu chấm công' : 'Đang tắt trang tra cứu chấm công' ?></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="settings-group">
                                    <div class="settings-group-title"><i data-lucide="link"></i> Kết nối API</div>
                                    <div class="field-group">
                                        <label class="field-label">Link API chấm công</label>
                                        <input type="text" name="check_api_url" class="field-input mono" value="<?= htmlspecialchars($config['check_api_url'] ?? '') ?>" placeholder="http://webapi.thepvinhthanh.com/mitaco-api.aspx">
                                        <div class="field-help-text">Trang chấm công sẽ gọi trực tiếp URL này để lấy dữ liệu vào ra theo mã nhân viên và khoảng thời gian.</div>
                                    </div>
                                </div>
                                <div class="settings-group">
                                    <div class="settings-group-title"><i data-lucide="calendar-range"></i> Khung thời gian sử dụng</div>
                                    <div class="field-grid-2">
                                        <div class="field-group">
                                            <label class="field-label">Mở từ</label>
                                            <input type="datetime-local" name="check_available_from" class="field-input" value="<?= htmlspecialchars($checkAvailableFrom) ?>">
                                            <div class="field-help-text">Để trống nếu không muốn giới hạn thời điểm bắt đầu.</div>
                                        </div>
                                        <div class="field-group">
                                            <label class="field-label">Khóa lúc</label>
                                            <input type="datetime-local" name="check_available_until" class="field-input" value="<?= htmlspecialchars($checkAvailableUntil) ?>">
                                            <div class="field-help-text">Để trống nếu không muốn giới hạn thời điểm kết thúc.</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="settings-group">
                                    <div class="settings-group-title"><i data-lucide="calendar-days"></i> Ngày mở cố định hàng tháng</div>
                                    <div class="field-group">
                                        <label class="field-label">Danh sách ngày trong tháng</label>
                                        <input type="text" name="check_month_days" class="field-input mono" value="<?= htmlspecialchars((string) ($config['check_month_days'] ?? '')) ?>" placeholder="VD: 1,2,3,28,29,30,31">
                                        <div class="field-help-text">Chỉ dùng khi 2 trường Mở từ/Khóa lúc đang để trống. Nhập các ngày cách nhau bằng dấu phẩy.</div>
                                    </div>
                                </div>
                                <div class="settings-group">
                                    <div class="settings-group-title"><i data-lucide="search-code"></i> Tra cứu nội bộ & tạo link xem</div>
                                    <div class="attendance-admin-grid">
                                        <div class="field-group">
                                            <label class="field-label" for="attendance-admin-emp-id">Mã NV</label>
                                            <input type="text" id="attendance-admin-emp-id" class="field-input mono" placeholder="VD: NV257">
                                        </div>
                                        <div class="field-group">
                                            <label class="field-label" for="attendance-admin-from-date">Từ ngày</label>
                                            <input type="date" id="attendance-admin-from-date" class="field-input" value="<?= htmlspecialchars(date('Y-m-01')) ?>">
                                        </div>
                                        <div class="field-group">
                                            <label class="field-label" for="attendance-admin-to-date">Đến ngày</label>
                                            <input type="date" id="attendance-admin-to-date" class="field-input" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                                        </div>
                                        <div class="field-group">
                                            <label class="field-label" for="attendance-admin-expires-at">Hết hạn lúc</label>
                                            <input type="datetime-local" id="attendance-admin-expires-at" class="field-input" value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime('+1 day'))) ?>">
                                        </div>
                                    </div>
                                    <div class="field-help-text">Admin có thể tra cứu trực tiếp theo mã nhân viên mà không cần mật khẩu, sau đó tạo link snapshot để gửi người xem. Bạn chọn chính xác ngày giờ hết hạn cho link này.</div>
                                    <div class="lookup-action-row">
                                        <button type="button" class="btn btn-primary" onclick="adminLookupAttendance()"><i data-lucide="clock-3"></i> Tra cứu chấm công</button>
                                        <button type="button" class="btn btn-secondary" onclick="adminCreateAttendanceShare()"><i data-lucide="share-2"></i> Tạo link xem</button>
                                    </div>
                                    <div id="attendance-admin-feedback" class="lookup-action-feedback" aria-live="polite"></div>
                                    <div id="attendance-admin-share-box" class="attendance-share-box" style="display:none"></div>
                                    <div id="attendance-admin-result" class="attendance-admin-result"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: UI -->
                    <div class="tab-pane" id="tab-ui">
                        <div class="admin-card">
                            <div class="admin-card-header"><h2 class="admin-card-title"><i data-lucide="palette"></i> Thương hiệu</h2></div>
                            <div class="admin-card-body">
                                <div class="field-grid-2">
                                    <div class="field-group">
                                        <label class="field-label">Tên Công Ty</label>
                                        <input type="text" name="site_company" class="field-input" value="<?= htmlspecialchars($config['site_company'] ?? '') ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">Logo Text</label>
                                        <input type="text" name="site_logo_text" class="field-input" maxlength="3" value="<?= htmlspecialchars($config['site_logo_text'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="field-group">
                                    <label class="field-label">Banner Title</label>
                                    <input type="text" name="site_hero_title" class="field-input" value="<?= htmlspecialchars($config['site_hero_title'] ?? '') ?>">
                                </div>
                                <div class="field-group">
                                    <label class="field-label">Banner Description</label>
                                    <textarea name="site_hero_desc" class="field-input" rows="3"><?= htmlspecialchars($config['site_hero_desc'] ?? '') ?></textarea>
                                </div>
                                <div class="field-group">
                                    <label class="field-label">Thông báo cho nhân viên</label>
                                    <textarea name="employee_notice" class="field-input" rows="3" placeholder="VD: Phiếu lương tháng này sẽ mở lúc 18:00 ngày 10."><?= htmlspecialchars((string) ($config['employee_notice'] ?? '')) ?></textarea>
                                    <div class="field-help-text">Hiển thị nhẹ trên trang tra cứu. Để trống nếu không có thông báo.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Pass -->
                    <div class="tab-pane" id="tab-pass">
                        <div class="admin-card">
                            <div class="admin-card-header"><h2 class="admin-card-title"><i data-lucide="key"></i> Mật khẩu Admin</h2></div>
                            <div class="admin-card-body">
                                <div class="field-group">
                                    <label class="field-label">Mật khẩu mới</label>
                                    <input type="password" name="new_admin_pass" class="field-input" placeholder="Để trống nếu không đổi">
                                </div>
                                <div class="msg msg-error"><i data-lucide="alert-triangle"></i> Cẩn trọng: Nếu quên mật khẩu, bạn phải reset config file.</div>
                            </div>
                        </div>
                        <div class="admin-card">
                            <div class="admin-card-header"><h2 class="admin-card-title"><i data-lucide="share-2"></i> Chia sẻ kết quả phiếu lương</h2></div>
                            <div class="admin-card-body">
                                <?php $payrollShareEnabled = !array_key_exists('payroll_share_enabled', $config) || $config['payroll_share_enabled'] !== false; ?>
                                <div class="field-group">
                                    <label class="period-toggle-row">
                                        <input type="checkbox" <?= $payrollShareEnabled ? 'checked' : '' ?> onchange="this.nextElementSibling.value = this.checked ? '1' : '0'; this.parentElement.querySelector('span').textContent = this.checked ? 'Đang bật link chia sẻ kết quả' : 'Đang tắt link chia sẻ kết quả';">
                                        <input type="hidden" name="payroll_share_enabled" value="<?= $payrollShareEnabled ? '1' : '0' ?>">
                                        <span><?= $payrollShareEnabled ? 'Đang bật link chia sẻ kết quả' : 'Đang tắt link chia sẻ kết quả' ?></span>
                                    </label>
                                </div>
                                <div class="field-group">
                                    <label class="field-label">Thời hạn link chia sẻ (giờ)</label>
                                    <input
                                        type="number"
                                        min="1"
                                        max="2"
                                        step="1"
                                        name="payroll_share_ttl_hours"
                                        class="field-input"
                                        value="<?= htmlspecialchars((string) ($config['payroll_share_ttl_hours'] ?? 2)) ?>">
                                    <div class="field-help-text">Giới hạn bảo mật: chỉ cho phép 1 hoặc 2 giờ. Link hết hạn sẽ tự vô hiệu.</div>
                                </div>
                                <div class="field-group">
                                    <label class="field-label">Timeout phiên nhân viên (phút)</label>
                                    <input
                                        type="number"
                                        min="5"
                                        max="120"
                                        step="5"
                                        name="employee_session_timeout_minutes"
                                        class="field-input"
                                        value="<?= htmlspecialchars((string) ($config['employee_session_timeout_minutes'] ?? 30)) ?>">
                                    <div class="field-help-text">Tự khóa phiên tra cứu khi nhân viên không hoạt động. Khuyến nghị 30 phút.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                </form>
            </div>

            <div class="sticky-save-bar">
                <div class="save-bar-info"><i data-lucide="info"></i> <span>Chưa lưu thay đổi...</span></div>
                <button type="submit" form="admin-form" class="btn btn-primary"><i data-lucide="save"></i> Lưu tất cả cấu hình</button>
            </div>
        </main>
</div>
<?php endif; ?>

<script>
window.HR_CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
window.HR_LOCAL_FILES_OPTIONS_HTML = <?= json_encode($spreadsheetUploadOptionsHtml) ?>;
</script>
<script src="assets/admin/admin.js?v=<?= time() ?>"></script>
</body>
</html>
