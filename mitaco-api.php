<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Infrastructure/bootstrap.php';

use App\Config;

header('Content-Type: application/json; charset=utf-8');
$corsOrigin = trim((string) Config::getEnvValue('MITACO_CORS_ORIGIN', ''));
if ($corsOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $corsOrigin);
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$serverName = trim((string) Config::getEnvValue('MITACO_SQLSERVER_HOST', ''));
$database = trim((string) Config::getEnvValue('MITACO_SQLSERVER_DATABASE', ''));
$uid = trim((string) Config::getEnvValue('MITACO_SQLSERVER_UID', ''));
$pwd = (string) Config::getEnvValue('MITACO_SQLSERVER_PASSWORD', '');

$responseData = [];

function json_exit(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sqlsrv_error_message(): string
{
    $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    if (!$errors) {
        return 'Unknown SQL Server error';
    }

    $messages = [];
    foreach ($errors as $error) {
        $messages[] = sprintf(
            '[SQLSTATE %s] Code %s: %s',
            $error['SQLSTATE'] ?? '',
            $error['code'] ?? '',
            $error['message'] ?? ''
        );
    }

    return implode('; ', $messages);
}

function format_date_value($value, string $format): string
{
    if ($value === null || $value === '') {
        return '';
    }

    if ($value instanceof DateTimeInterface) {
        return $value->format($format);
    }

    try {
        return (new DateTime((string) $value))->format($format);
    } catch (Throwable $e) {
        return '';
    }
}

try {
    if (!extension_loaded('sqlsrv')) {
        throw new RuntimeException('PHP extension sqlsrv is not installed or not enabled.');
    }

    if ($serverName === '' || $database === '' || $uid === '' || $pwd === '') {
        throw new RuntimeException('MITACO SQL Server connection is not configured.');
    }

    $maNv = isset($_GET['ma_nv']) ? trim((string) $_GET['ma_nv']) : '';
    if ($maNv === '') {
        throw new InvalidArgumentException('Thiếu mã nhân viên.');
    }
    if ($maNv !== '' && $maNv !== 'SYSTEM_INFO' && !preg_match('/^[A-Za-z0-9._-]+$/', $maNv)) {
        throw new InvalidArgumentException('Mã nhân viên không hợp lệ.');
    }

    $tuNgay = isset($_GET['tu_ngay']) && $_GET['tu_ngay'] !== ''
        ? (string) $_GET['tu_ngay']
        : date('Y-m-01');
    $denNgay = isset($_GET['den_ngay']) && $_GET['den_ngay'] !== ''
        ? (string) $_GET['den_ngay']
        : date('Y-m-d');

    $connectionInfo = [
        'Database' => $database,
        'UID' => $uid,
        'PWD' => $pwd,
        'CharacterSet' => 'UTF-8',
        'ReturnDatesAsStrings' => false,
    ];

    $conn = sqlsrv_connect($serverName, $connectionInfo);
    if ($conn === false) {
        throw new RuntimeException(sqlsrv_error_message());
    }

    $items = [];
    if ($maNv !== 'SYSTEM_INFO') {
        $sql = "
            SELECT TOP 2000 nv.MaNhanVien, nv.TenNhanVien, ck.NgayCham, ck.GioCham, ck.MaSoMay
            FROM dbo.CheckInOut ck
            JOIN dbo.NHANVIEN nv ON ck.MaChamCong = nv.MaChamCong
            WHERE ck.NgayCham >= ? AND ck.NgayCham < DATEADD(day, 1, ?)";

        $params = [$tuNgay, $denNgay];
        $sql .= " AND nv.MaNhanVien = ?";
        $params[] = $maNv;

        $sql .= ' ORDER BY ck.NgayCham DESC, ck.GioCham DESC';

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new RuntimeException(sqlsrv_error_message());
        }

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $items[] = [
                'MaNhanVien' => (string) ($row['MaNhanVien'] ?? ''),
                'TenNhanVien' => (string) ($row['TenNhanVien'] ?? ''),
                'NgayCham' => format_date_value($row['NgayCham'] ?? null, 'd/m/Y'),
                'GioCham' => format_date_value($row['GioCham'] ?? null, 'H:i:s'),
                'MaSoMay' => (string) ($row['MaSoMay'] ?? ''),
            ];
        }
        sqlsrv_free_stmt($stmt);
    }

    $responseData['data'] = $items;
    $responseData['status'] = 'success';

    $sqlLatest = "
        SELECT TOP 1 NgayCham, GioCham
        FROM dbo.CheckInOut
        WHERE NgayCham <= GETDATE()
        ORDER BY NgayCham DESC, GioCham DESC";

    $stmtLatest = sqlsrv_query($conn, $sqlLatest);
    if ($stmtLatest === false) {
        throw new RuntimeException(sqlsrv_error_message());
    }

    $latestUpdate = '';
    $latestRow = sqlsrv_fetch_array($stmtLatest, SQLSRV_FETCH_ASSOC);
    if ($latestRow) {
        $lastDate = $latestRow['NgayCham'] ?? null;
        $lastTime = $latestRow['GioCham'] ?? null;

        if ($lastDate instanceof DateTimeInterface && $lastTime instanceof DateTimeInterface) {
            $latestUpdate = sprintf(
                '%s %s',
                $lastDate->format('d/m/Y'),
                $lastTime->format('H:i:s')
            );
        } elseif ($lastDate !== null && $lastTime !== null) {
            $latestUpdate = format_date_value($lastDate, 'd/m/Y') . ' ' . format_date_value($lastTime, 'H:i:s');
        } else {
            $latestUpdate = format_date_value($lastDate, 'd/m/Y');
        }
    }

    sqlsrv_free_stmt($stmtLatest);
    sqlsrv_close($conn);

    $responseData['latest_update'] = trim($latestUpdate);
} catch (Throwable $e) {
    $responseData['status'] = 'error';
    $responseData['message'] = $e->getMessage();
}

json_exit($responseData);
