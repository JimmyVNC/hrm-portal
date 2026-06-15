<?php

declare(strict_types=1);

use App\Application\AdminFileManager;
use PHPUnit\Framework\TestCase;

final class AdminFileManagerTest extends TestCase
{
    public function testFindDuplicateEmployeeIdsReturnsGroupedRows(): void
    {
        $headers = ['Mã NV', 'Họ tên', 'Mật khẩu'];
        $rows = [
            ['001', 'Nguyen Van A', 'a1'],
            ['002', 'Nguyen Van B', 'b1'],
            ['001', 'Nguyen Van A Moi', 'a2'],
        ];

        $groups = AdminFileManager::findDuplicateEmployeeIds($headers, $rows, ['col_emp_id' => 'Mã NV'], [2, 3, 4]);

        $this->assertCount(1, $groups);
        $this->assertSame('1', $groups[0]['emp_id']);
        $this->assertSame(2, $groups[0]['duplicate_count']);
        $this->assertSame(2, $groups[0]['rows'][0]['source_row_number']);
        $this->assertSame(4, $groups[0]['rows'][1]['source_row_number']);
    }

    public function testFindDuplicateEmployeeIdsIgnoresUniqueRows(): void
    {
        $headers = ['Mã NV', 'Họ tên'];
        $rows = [
            ['001', 'A'],
            ['002', 'B'],
            ['003', 'C'],
        ];

        $groups = AdminFileManager::findDuplicateEmployeeIds($headers, $rows, ['col_emp_id' => 'Mã NV']);

        $this->assertSame([], $groups);
    }

    public function testSearchAuthEmployeesMatchesByEmployeeIdAndNameFromLocalFile(): void
    {
        $fixture = __DIR__ . '/../uploads/test_admin_lookup_auth.xlsx';
        @unlink($fixture);

        $rows = [
            ['MÃ NV', 'HỌ TÊN', 'BỘ PHẬN', 'MẬT KHẨU'],
            ['001', 'Nguyen Van A', 'Nhan su', password_hash('secret-1', PASSWORD_DEFAULT)],
            ['002', 'Tran Thi B', 'Ke toan', password_hash('secret-2', PASSWORD_DEFAULT)],
        ];

        $this->assertTrue(\App\Services\SpreadsheetWriter::toXlsx($rows, $fixture));

        $config = [
            'auth_source_type' => 'local',
            'auth_local_file' => 'uploads/' . basename($fixture),
            'col_emp_id' => 'MÃ NV',
            'col_password' => 'MẬT KHẨU',
            'col_emp_name' => 'HỌ TÊN',
            'col_department' => 'BỘ PHẬN',
        ];

        $searchById = AdminFileManager::searchAuthEmployees($config, '001', 8);
        $this->assertTrue($searchById['ok']);
        $this->assertCount(1, $searchById['employees']);
        $this->assertSame('001', $searchById['employees'][0]['emp_id_display']);
        $this->assertSame('hashed', $searchById['employees'][0]['password_mode']);

        $searchByName = AdminFileManager::searchAuthEmployees($config, 'tran thi', 8);
        $this->assertTrue($searchByName['ok']);
        $this->assertCount(1, $searchByName['employees']);
        $this->assertSame('Tran Thi B', $searchByName['employees'][0]['name']);

        @unlink($fixture);
    }
}
