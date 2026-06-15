<?php

declare(strict_types=1);

/**
 * Prefer Composer autoload when vendor/ exists (local/CI); otherwise use the bundled PSR-4 loader (shared hosting).
 */
$hrmVendorAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_readable($hrmVendorAutoload)) {
    require_once $hrmVendorAutoload;
} else {
    require_once __DIR__ . '/Autoloader.php';
}

\App\Config::loadEnvFile();
\App\Config::applyDefaultTimezone();
