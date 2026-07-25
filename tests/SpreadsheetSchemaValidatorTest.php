<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\SpreadsheetSchemaValidator;

final class SpreadsheetSchemaValidatorTest extends TestCase {
    public function testFindHeaderByEmployeeIdColumn(): void {
        $rows = [
            ['note', 'ignore'],
            ['Mã NV', 'Thực lãnh'],
            ['001', '1000000'],
        ];

        $header = SpreadsheetSchemaValidator::findHeader($rows, 'MÃ NV');
        $this->assertNotNull($header);
        $this->assertSame(1, $header['header_index']);
    }

    public function testMissingHeadersReturnsConfiguredNames(): void {
        $headerNormalized = ['MÃ NV', 'THỰC LÃNH'];
        $missing = SpreadsheetSchemaValidator::missingHeaders($headerNormalized, ['MÃ NV', 'BỘ PHẬN']);
        $this->assertSame(['BỘ PHẬN'], $missing);
    }

    public function testValidateTypedColumnsReportsPreciseError(): void {
        $rows = [
            ['Mã NV', 'Thực lãnh', 'Ngày trả'],
            ['001', 'abc', '2026-01-01'],
        ];
        $headerNormalized = ['MÃ NV', 'THỰC LÃNH', 'NGÀY TRẢ'];
        $result = SpreadsheetSchemaValidator::validateTypedColumns(
            $rows,
            0,
            $headerNormalized,
            [
                'THỰC LÃNH' => 'number',
                'NGÀY TRẢ' => 'date',
            ],
            100
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('dòng 2', $result['message'] ?? '');
        $this->assertStringContainsString('THỰC LÃNH', $result['message'] ?? '');
    }

    public function testValidatePeriodDatasetHappyPath(): void {
        $rows = [
            ['Mã NV', 'Thực lãnh', 'Ngày trả'],
            ['001', '1,000,000', '2026-01-01'],
        ];
        $config = [
            'col_emp_id' => 'Mã NV',
        ];
        $period = [
            'cols' => 'Mã NV, Thực lãnh',
            'highlight_cols' => '',
            'money_cols' => 'Thực lãnh',
        ];

        $result = SpreadsheetSchemaValidator::validatePeriodDataset($rows, $config, $period);
        $this->assertTrue($result['ok']);
    }

    public function testValidatePeriodDatasetAllowsMissingOptionalConfiguredColumns(): void {
        $rows = [
            ['Mã NV', 'Thực lãnh'],
            ['001', '1000000'],
        ];
        $config = [
            'col_emp_id' => 'Mã NV',
        ];
        $period = [
            'cols' => 'Mã NV, Tạm ứng mua điện thoại, Tạm ứng khác',
            'highlight_cols' => 'Tạm ứng khác',
            'money_cols' => 'Tạm ứng mua điện thoại',
        ];

        $result = SpreadsheetSchemaValidator::validatePeriodDataset($rows, $config, $period);
        $this->assertTrue($result['ok']);
    }

    public function testValidatePeriodDatasetAllowsFileWithoutEmployeeIdColumn(): void {
        $rows = [
            ['Họ tên', 'Thực lãnh'],
            ['Nguyễn Văn A', '1000000'],
        ];
        $config = ['col_emp_id' => 'Mã NV'];
        $period = ['money_cols' => 'Thực lãnh'];

        $result = SpreadsheetSchemaValidator::validatePeriodDataset($rows, $config, $period);

        $this->assertTrue($result['ok']);
    }
}
