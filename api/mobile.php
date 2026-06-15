<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Infrastructure/bootstrap.php';

use App\Config;
use App\Security;
use App\Application\AuthActions;
use App\Application\DataActions;

Config::startSecureSession();
Security::applySecurityHeaders();

header('Content-Type: application/json; charset=utf-8');

function mobile_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$config = Config::loadConfig();
$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));

if ($action === '') {
    mobile_json(['success' => false, 'message' => 'Missing action'], 400);
}

if ($action === 'periods') {
    $periods = [];
    foreach (($config['periods'] ?? []) as $index => $period) {
        if (!is_array($period)) {
            continue;
        }
        if (!DataActions::isPeriodEnabled($period)) {
            continue;
        }
        $periods[] = [
            'index' => $index,
            'label' => (string) ($period['label'] ?? ('Kỳ ' . ($index + 1))),
            'publish_date' => (string) ($period['publish_date'] ?? ''),
            'source_type' => (string) ($period['source_type'] ?? ''),
            'cols' => (string) ($period['cols'] ?? ''),
            'highlight_cols' => (string) ($period['highlight_cols'] ?? ''),
            'money_cols' => (string) ($period['money_cols'] ?? ''),
        ];
    }
    mobile_json([
        'success' => true,
        'periods' => $periods,
        'branding' => [
            'logo_text' => (string) ($config['site_logo_text'] ?? 'HR'),
            'company' => (string) ($config['site_company'] ?? 'HRM System'),
            'subtitle' => (string) ($config['site_subtitle'] ?? 'Employee Self-Service'),
            'hero_title' => (string) ($config['site_hero_title'] ?? ''),
        ],
        'stat_cols' => (string) ($config['stat_cols'] ?? ''),
        'col_emp_name' => (string) ($config['col_emp_name'] ?? 'HỌ TÊN'),
        'col_department' => (string) ($config['col_department'] ?? 'BỘ PHẬN'),
    ]);
}

if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        mobile_json(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    $empId = trim((string) ($_POST['emp_id'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $result = AuthActions::verifyUser($config, $empId, $password);

    if (($result['success'] ?? false) === true && isset($result['user']) && is_array($result['user'])) {
        session_regenerate_id(true);
        $_SESSION['hr_user'] = $result['user'];
    }

    mobile_json($result);
}

if ($action === 'payroll') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        mobile_json(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    $periodIndex = (int) ($_POST['period_index'] ?? -1);
    $result = DataActions::handle($config, $periodIndex);
    mobile_json($result);
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
    mobile_json(['success' => true]);
}

mobile_json(['success' => false, 'message' => 'Unknown action'], 404);
