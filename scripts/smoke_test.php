<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

/**
 * Smoke Test — scripts/smoke_test.php
 * Verifies core system functionality after reorganization.
 */

require_once __DIR__ . '/../src/Infrastructure/bootstrap.php';

use App\Config;
use App\Security;
use App\Application\AdminActions;
use App\Services\CacheStore;

$results = [];
$exitCode = 0;

function recordResult(array &$results, string $name, bool $ok, string $detail): void {
    $results[] = [
        'name' => $name,
        'ok' => $ok,
        'detail' => $detail,
    ];
}

$appClassesOk = class_exists(\App\Application\AuthActions::class)
    && class_exists(\App\Application\DataActions::class)
    && class_exists(\App\Application\AdminActions::class);
recordResult(
    $results,
    'Application classes autoload',
    $appClassesOk,
    $appClassesOk
        ? 'AuthActions, DataActions, AdminActions load correctly.'
        : 'Missing or misnamed Application classes — check src/Application and Autoloader.'
);

$xlsxOk = class_exists(\Shuchkin\SimpleXLSX::class);
recordResult(
    $results,
    'Spreadsheet reader (SimpleXLSX)',
    $xlsxOk,
    $xlsxOk
        ? 'Shuchkin\\SimpleXLSX resolves (Composer or bundled PSR-4).'
        : 'Missing SimpleXLSX — run composer dump-autoload or check src/SimpleXLSX.php.'
);

$metaOk = class_exists(\App\AppMetadata::class) && \App\AppMetadata::VERSION !== '';
recordResult(
    $results,
    'App metadata',
    $metaOk,
    $metaOk ? 'AppMetadata present for diagnostics and logs.' : 'AppMetadata missing.'
);

// 2. Test Config Loading
$config = Config::loadConfig();
recordResult(
    $results,
    'Config loaded',
    is_array($config) && !empty($config),
    is_array($config) ? 'Config file successfully read from config/ directory.' : 'Failed to read config.'
);

// 3. Test Env Handling
putenv('SMOKE_TEST_VAL=123');
$val = Config::getEnvValue('SMOKE_TEST_VAL');
recordResult(
    $results,
    'Env handling',
    $val === '123',
    $val === '123' ? 'Environment variables are readable.' : 'Env reading failed.'
);

// 4. Test Security Utilities
$hash = password_hash('test_pass', PASSWORD_DEFAULT);
$isValidHash = AdminActions::isPasswordHashString($hash);
recordResult(
    $results,
    'Password hashing detection',
    $isValidHash === true,
    $isValidHash ? 'Correctly identified password hash.' : 'Failed to identify hash.'
);

Config::startSecureSession();
$csrf = Security::getCsrfToken();
recordResult(
    $results,
    'CSRF Utilities',
    is_string($csrf) && Security::validateCsrfToken($csrf),
    'CSRF generation and validation works.'
);

// 5. Cache maintenance callable
$prune = CacheStore::prune(0, 0);
$pruneOk = isset($prune['deleted_files'], $prune['freed_bytes'], $prune['total_bytes_after']);
recordResult(
    $results,
    'Cache prune (no-op limits)',
    $pruneOk,
    $pruneOk ? 'CacheStore::prune executes without error.' : 'Unexpected prune response shape.'
);

// 6. Test File System Access
$uploadDir = __DIR__ . '/../uploads';
$uploadWritable = is_dir($uploadDir) && is_writable($uploadDir);
recordResult(
    $results,
    'Uploads directory',
    $uploadWritable,
    $uploadWritable ? 'uploads/ exists and is writable.' : 'uploads/ access issue.'
);

// 7. Summary
echo "\n--- HRM PORTAL SMOKE TEST ---\n";
foreach ($results as $result) {
    echo sprintf("[%s] %s: %s\n", $result['ok'] ? 'PASS' : 'FAIL', $result['name'], $result['detail']);
    if (!$result['ok']) {
        $exitCode = 1;
    }
}
echo "------------------------------\n";

exit($exitCode);
