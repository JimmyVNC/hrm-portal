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
}
