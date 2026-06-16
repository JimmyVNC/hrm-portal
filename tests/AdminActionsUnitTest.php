<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Application\AdminActions;

final class AdminActionsUnitTest extends TestCase {
    public function testNormalizePeriodEnabledAcceptsTruthyStrings(): void {
        $this->assertTrue(AdminActions::normalizePeriodEnabled('1'));
        $this->assertTrue(AdminActions::normalizePeriodEnabled('true'));
        $this->assertFalse(AdminActions::normalizePeriodEnabled('0'));
    }

    public function testIsPasswordHashStringDetectsBcrypt(): void {
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $this->assertTrue(AdminActions::isPasswordHashString($hash));
    }

    public function testIsPasswordHashStringRejectsPlaintext(): void {
        $this->assertFalse(AdminActions::isPasswordHashString('admin123'));
    }

    public function testIsSafeRelativeUploadPathAcceptsUploadsPrefix(): void {
        $this->assertTrue(AdminActions::isSafeRelativeUploadPath('uploads/report_abc123.xlsx'));
    }

    public function testIsSafeRelativeUploadPathRejectsTraversal(): void {
        $this->assertFalse(AdminActions::isSafeRelativeUploadPath('uploads/../config/hr_config.json'));
    }

    public function testGetFileUsageMapIncludesSheetName(): void {
        $config = [
            'auth_local_file' => 'uploads/auth.xlsx',
            'periods' => [
                [
                    'label' => 'Tháng 1',
                    'local_file' => 'uploads/payroll.xlsx',
                    'sheet_index' => 1,
                    'sheet_name' => 'Ky 1',
                ],
            ],
        ];

        $usageMap = AdminActions::getFileUsageMap($config);

        $this->assertArrayHasKey('payroll.xlsx', $usageMap);
        $this->assertSame(['Kỳ lương: Tháng 1 (Ky 1)'], $usageMap['payroll.xlsx']);
        $this->assertArrayHasKey('auth.xlsx', $usageMap);
    }

    public function testIsUploadedFileInUseMatchesBasename(): void {
        $config = [
            'auth_local_file' => '',
            'periods' => [
                [
                    'label' => 'Tháng 1',
                    'local_file' => 'uploads/payroll.xlsx',
                    'sheet_index' => 0,
                    'sheet_name' => '',
                ],
            ],
        ];

        $this->assertTrue(AdminActions::isUploadedFileInUse($config, 'payroll.xlsx'));
        $this->assertFalse(AdminActions::isUploadedFileInUse($config, 'missing.xlsx'));
    }

    public function testResetLostEncryptionKeyClearsConfigAndFiles(): void {
        $backupEnv = @file_get_contents(\App\Config::ENV_FILE);
        $configPath = \App\Config::projectRoot() . '/config/hr_config.json';
        $backupConfig = @file_get_contents($configPath);

        $tempDir = sys_get_temp_dir() . '/hrm-admin-test-' . uniqid('', true);
        mkdir($tempDir, 0700, true);
        mkdir($tempDir . '/backups', 0700, true);
        
        putenv('UPLOADS_DIR=' . $tempDir);
        $_ENV['UPLOADS_DIR'] = $tempDir;
        $_SERVER['UPLOADS_DIR'] = $tempDir;

        $file1 = $tempDir . '/up_test.xlsx';
        $file2 = $tempDir . '/auth_test.xlsx';
        file_put_contents($file1, 'data1');
        file_put_contents($file2, 'data2');

        $config = [
            'auth_local_file' => 'uploads/auth_test.xlsx',
            'periods' => [
                [
                    'label' => 'Tháng 1',
                    'local_file' => 'uploads/up_test.xlsx',
                ]
            ]
        ];

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['hr_admin'] = true;

        $_POST['action'] = 'reset_lost_encryption_key';
        $_POST['app_file_encryption_key'] = 'base64:newkeynewkeynewkeynewkeynewkeynewkeynew=';

        $res = AdminActions::handle($config);
        $this->assertSame('success', $res['type']);

        $this->assertSame('', $config['auth_local_file']);
        $this->assertSame('', $config['periods'][0]['local_file']);

        $this->assertFalse(file_exists($file1));
        $this->assertFalse(file_exists($file2));

        $this->assertSame('base64:newkeynewkeynewkeynewkeynewkeynewkeynew=', \App\Config::getEnvValue('APP_FILE_ENCRYPTION_KEY'));

        putenv('UPLOADS_DIR');
        unset($_ENV['UPLOADS_DIR'], $_SERVER['UPLOADS_DIR']);
        unset($_POST['action'], $_POST['app_file_encryption_key']);
        unset($_SESSION['hr_admin']);
        if (is_string($backupEnv)) {
            file_put_contents(\App\Config::ENV_FILE, $backupEnv);
        } else {
            @unlink(\App\Config::ENV_FILE);
        }
        if (is_string($backupConfig)) {
            file_put_contents($configPath, $backupConfig);
        }
    }
}
