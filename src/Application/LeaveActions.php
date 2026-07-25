<?php

namespace App\Application;

use App\Config;
use App\Security;
use App\Services\SpreadsheetReader;
use App\Services\SpreadsheetWriter;

/** File-backed leave-request workflow. The workbook is never exposed publicly. */
class LeaveActions {
    private const FILE_NAME = 'leave_requests.xlsx';
    private const HEADER = ['MÃ ĐƠN','MÃ NV','HỌ TÊN','BỘ PHẬN','LOẠI NGHỈ','TỪ NGÀY','ĐẾN NGÀY','SỐ NGÀY','LÝ DO','TRẠNG THÁI','KIỂU XÁC THỰC','MÃ NGƯỜI XÁC THỰC','TÊN NGƯỜI XÁC THỰC','BỘ PHẬN NGƯỜI XÁC THỰC','THỜI GIAN XÁC THỰC','MÃ QUẢN LÝ','THỜI GIAN XỬ LÝ','GHI CHÚ QUẢN LÝ','TOKEN XÁC THỰC','HẾT HẠN LINK','MÃ NV ĐƯỢC YÊU CẦU'];
    private function __construct() {}

    private static function path(): string { return rtrim(Config::uploadsDir(), '/\\') . DIRECTORY_SEPARATOR . self::FILE_NAME; }
    private static function lockPath(): string { return self::path() . '.lock'; }
    private static function clean(string $value, int $max = 1000): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 0, $max); }

    private static function withLock(callable $fn): array {
        $dir = dirname(self::path());
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return ['success' => false, 'message' => 'Không thể tạo nơi lưu đơn nghỉ.'];
        @chmod($dir, 0700);
        $lock = @fopen(self::lockPath(), 'c');
        if (!$lock || !@flock($lock, LOCK_EX)) return ['success' => false, 'message' => 'Hệ thống đang xử lý đơn khác, vui lòng thử lại.'];
        try { return $fn(); } catch (\Throwable $e) { Security::appLog('error', 'leave_request_failed', ['error' => $e->getMessage()]); return ['success' => false, 'message' => 'Không thể xử lý đơn nghỉ.']; }
        finally { @flock($lock, LOCK_UN); @fclose($lock); }
    }
    private static function rows(): array {
        $path = self::path();
        if (!is_file($path)) return [self::HEADER];
        $rows = SpreadsheetReader::fromLocalFile($path, 0, 50000, count(self::HEADER), 'xlsx');
        if (!$rows) return [self::HEADER];
        $rows[0] = self::HEADER;
        foreach ($rows as $i => $row) $rows[$i] = array_pad((array) $row, count(self::HEADER), '');
        return $rows;
    }
    // Đơn nghỉ là file nghiệp vụ độc lập: lưu XLSX thường để vẫn đọc được khi mất khóa .env.
    private static function save(array $rows): bool { return SpreadsheetWriter::toXlsx($rows, self::path(), false); }
    private static function date(string $value): ?string {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value ? $value : null;
    }
    private static function record(array $row): array { return array_combine(self::HEADER, array_pad(array_map('strval', $row), count(self::HEADER), '')) ?: []; }

    public static function submit(array $config, array $input): array {
        if (($config['leave_request_enabled'] ?? false) !== true) return ['success' => false, 'message' => 'Chức năng nộp đơn nghỉ hiện đang tắt.'];
        $employee = $_SESSION['hr_user'] ?? null;
        if (!is_array($employee) || empty($employee['id'])) return ['success' => false, 'message' => 'Vui lòng đăng nhập lại để nộp đơn.'];
        $from = self::date((string)($input['from_date'] ?? '')); $to = self::date((string)($input['to_date'] ?? ''));
        $type = self::clean((string)($input['leave_type'] ?? 'Nghỉ'), 80); $reason = self::clean((string)($input['reason'] ?? ''), 1000);
        if (!$from || !$to || $from > $to || $reason === '') return ['success' => false, 'message' => 'Vui lòng nhập đầy đủ ngày nghỉ và lý do hợp lệ.'];
        $days = (float) ($input['leave_days'] ?? 0);
        if ($days < 1 || $days > 365) return ['success' => false, 'message' => 'Số ngày nghỉ phải từ 1 đến 365 ngày.'];
        $verifierId = self::clean((string)($input['verifier_id'] ?? ''), 40); $verifierPassword = (string)($input['verifier_password'] ?? '');
        if ($verifierId === '' || $verifierPassword === '') return ['success' => false, 'message' => 'Cần mã nhân viên và mật khẩu của người xác thực.'];
        $verified = AuthActions::verifyUser($config, $verifierId, $verifierPassword);
        if (empty($verified['success']) || empty($verified['user'])) return ['success' => false, 'message' => 'Thông tin người xác thực không hợp lệ.'];
        $verifier = $verified['user']; $mode = (string)($config['leave_verification_mode'] ?? 'any_employee');
        if ((string)$verifier['id'] === (string)$employee['id']) return ['success' => false, 'message' => 'Người nộp không thể tự xác thực đơn của mình.'];
        if ($mode === 'leader') {
            if (strtolower((string)($verifier['role'] ?? '')) !== 'leader') return ['success' => false, 'message' => 'Chế độ hiện tại yêu cầu tổ trưởng xác thực.'];
            if (self::clean((string)($verifier['department'] ?? ''), 120) !== self::clean((string)($employee['department'] ?? ''), 120)) return ['success' => false, 'message' => 'Tổ trưởng phải thuộc cùng bộ phận với người nộp đơn.'];
        }
        return self::withLock(function () use ($employee, $verifier, $mode, $from, $to, $days, $type, $reason) {
            $rows = self::rows(); $id = 'NP' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)); $now = date('Y-m-d H:i:s');
            $rows[] = [$id,$employee['id'],self::clean((string)($employee['name'] ?? ''),150),self::clean((string)($employee['department'] ?? ''),120),$type,$from,$to,(string)$days,$reason,'Chờ quản lý duyệt',$mode === 'leader' ? 'Tổ trưởng' : 'Nhân viên bất kỳ',$verifier['id'],self::clean((string)($verifier['name'] ?? ''),150),self::clean((string)($verifier['department'] ?? ''),120),$now,'','','','',''];
            if (!self::save($rows)) return ['success' => false, 'message' => 'Không thể lưu đơn nghỉ.'];
            Security::auditLog('leave_request_submitted', ['leave_id' => $id, 'verifier_id' => $verifier['id'], 'verification_mode' => $mode]);
            return ['success' => true, 'message' => 'Đơn nghỉ đã gửi quản lý duyệt.', 'leave_id' => $id];
        });
    }

    public static function createShareLink(array $config, array $input): array {
        if (($config['leave_request_enabled'] ?? false) !== true) return ['success' => false, 'message' => 'Chức năng nộp đơn nghỉ hiện đang tắt.'];
        $employee = $_SESSION['hr_user'] ?? null;
        if (!is_array($employee) || empty($employee['id'])) return ['success' => false, 'message' => 'Vui lòng đăng nhập lại để nộp đơn.'];
        $from = self::date((string)($input['from_date'] ?? '')); $to = self::date((string)($input['to_date'] ?? ''));
        $type = self::clean((string)($input['leave_type'] ?? 'Nghỉ'), 80); $reason = self::clean((string)($input['reason'] ?? ''), 1000);
        if (!$from || !$to || $from > $to || $reason === '') return ['success' => false, 'message' => 'Vui lòng nhập đầy đủ ngày nghỉ và lý do hợp lệ.'];
        $days = (float) ($input['leave_days'] ?? 0);
        if ($days < 1 || $days > 365) return ['success' => false, 'message' => 'Số ngày nghỉ phải từ 1 đến 365 ngày.'];
        $mode = (string)($config['leave_verification_mode'] ?? 'any_employee');
        $requiredVerifierId = self::clean((string)($input['expected_verifier_id'] ?? ''), 40);
        if ($mode === 'leader' && $requiredVerifierId === '') return ['success' => false, 'message' => 'Vui lòng nhập Mã NV tổ trưởng trước khi tạo link.'];
        return self::withLock(function () use ($employee, $from, $to, $days, $type, $reason, $mode, $requiredVerifierId) {
            $rows = self::rows(); $id = 'NP' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)); $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 86400);
            $rows[] = [$id,$employee['id'],self::clean((string)($employee['name'] ?? ''),150),self::clean((string)($employee['department'] ?? ''),120),$type,$from,$to,(string)$days,$reason,'Chờ xác thực',$mode === 'leader' ? 'Tổ trưởng' : 'Nhân viên bất kỳ','','','','','','','',$token,$expires,$requiredVerifierId];
            if (!self::save($rows)) return ['success' => false, 'message' => 'Không thể tạo link xác thực.'];
            Security::auditLog('leave_request_share_created', ['leave_id' => $id]);
            return ['success' => true, 'message' => 'Đã tạo link xác thực. Link có hiệu lực 24 giờ.', 'leave_id' => $id, 'token' => $token, 'expires_at' => $expires];
        });
    }

    public static function confirmShared(array $config, string $token, string $verifierId, string $password): array {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return ['success' => false, 'message' => 'Link xác thực không hợp lệ.'];
        return self::withLock(function () use ($config, $token, $verifierId, $password) {
            $rows = self::rows();
            foreach ($rows as $i => $row) {
                if ($i === 0 || !hash_equals((string)($row[18] ?? ''), $token)) continue;
                if (($row[9] ?? '') !== 'Chờ xác thực') return ['success' => false, 'message' => 'Đơn này đã được xác thực hoặc xử lý.'];
                if (strtotime((string)($row[19] ?? '')) < time()) return ['success' => false, 'message' => 'Link xác thực đã hết hạn.'];
                $verified = AuthActions::verifyUser($config, $verifierId, $password);
                if (empty($verified['success']) || empty($verified['user'])) return ['success' => false, 'message' => 'Thông tin đăng nhập không hợp lệ.'];
                $v = $verified['user'];
                if ((string)$v['id'] === (string)($row[1] ?? '')) return ['success' => false, 'message' => 'Người nộp không thể tự xác thực đơn.'];
                if (($row[20] ?? '') !== '' && (string)$v['id'] !== (string)$row[20]) return ['success' => false, 'message' => 'Link này chỉ dành cho Mã NV ' . $row[20] . '.'];
                if (($config['leave_verification_mode'] ?? 'any_employee') === 'leader' && (strtolower((string)($v['role'] ?? '')) !== 'leader' || self::clean((string)($v['department'] ?? ''),120) !== self::clean((string)($row[3] ?? ''),120))) return ['success' => false, 'message' => 'Cần tổ trưởng cùng bộ phận xác thực.'];
                $rows[$i][9] = 'Chờ quản lý duyệt'; $rows[$i][11] = $v['id']; $rows[$i][12] = self::clean((string)($v['name'] ?? ''),150); $rows[$i][13] = self::clean((string)($v['department'] ?? ''),120); $rows[$i][14] = date('Y-m-d H:i:s'); $rows[$i][18] = '';
                if (!self::save($rows)) return ['success' => false, 'message' => 'Không thể lưu xác thực.'];
                Security::auditLog('leave_request_share_confirmed', ['leave_id' => $row[0], 'verifier_id' => $v['id']]);
                return ['success' => true, 'message' => 'Xác thực thành công. Đơn đã được gửi quản lý duyệt.'];
            }
            return ['success' => false, 'message' => 'Link xác thực không tồn tại hoặc đã được dùng.'];
        });
    }
    public static function listForEmployee(string $employeeId): array {
        return self::withLock(function () use ($employeeId) { $out=[]; foreach (array_slice(self::rows(),1) as $row) { $r=self::record($row); if (($r['MÃ NV'] ?? '') === $employeeId) $out[]=$r; } return ['success'=>true,'requests'=>array_reverse($out)]; });
    }
    public static function listAll(): array { return self::withLock(function () { $out=[]; foreach (array_slice(self::rows(),1) as $row) $out[]=self::record($row); return ['success'=>true,'requests'=>array_reverse($out)]; }); }
    public static function decide(string $id, string $decision, string $note): array {
        if (!in_array($decision, ['approved','rejected'], true)) return ['success'=>false,'message'=>'Quyết định không hợp lệ.'];
        return self::withLock(function () use ($id,$decision,$note) { $rows=self::rows(); foreach ($rows as $i=>$row) { if ($i===0 || ($row[0] ?? '') !== $id) continue; if (($row[9] ?? '') !== 'Chờ quản lý duyệt') return ['success'=>false,'message'=>'Đơn này đã được xử lý.']; $rows[$i][9]=$decision==='approved' ? 'Đã duyệt' : 'Từ chối'; $rows[$i][15]='ADMIN'; $rows[$i][16]=date('Y-m-d H:i:s'); $rows[$i][17]=self::clean($note,1000); if (!self::save($rows)) return ['success'=>false,'message'=>'Không thể cập nhật đơn.']; Security::auditLog('leave_request_decided',['leave_id'=>$id,'decision'=>$decision]); return ['success'=>true,'message'=>'Đã cập nhật đơn nghỉ.']; } return ['success'=>false,'message'=>'Không tìm thấy đơn nghỉ.']; });
    }

    public static function delete(string $id): array {
        if (!preg_match('/^NP[0-9A-F]+$/', $id)) return ['success' => false, 'message' => 'Mã đơn không hợp lệ.'];
        return self::withLock(function () use ($id) {
            $rows = self::rows();
            foreach ($rows as $i => $row) {
                if ($i === 0 || ($row[0] ?? '') !== $id) continue;
                array_splice($rows, $i, 1);
                if (!self::save($rows)) return ['success' => false, 'message' => 'Không thể xóa đơn nghỉ.'];
                Security::auditLog('leave_request_deleted', ['leave_id' => $id]);
                return ['success' => true, 'message' => 'Đã xóa đơn nghỉ.'];
            }
            return ['success' => false, 'message' => 'Không tìm thấy đơn nghỉ.'];
        });
    }
}
