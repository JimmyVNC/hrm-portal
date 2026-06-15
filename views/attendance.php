<?php
/** @var array $config */
/** @var bool $isAdmin */
/** @var bool $showAttendanceModule */
/** @var array $attendanceState */

$pageTitle = 'Tra Cứu Chấm Công';
$safeCompany = htmlspecialchars((string) ($config['site_company'] ?? ''));
$safeLogo = htmlspecialchars((string) ($config['site_logo_text'] ?? 'HR'));
$safeSubtitle = htmlspecialchars((string) ($config['site_subtitle'] ?? 'Employee Self-Service'));
$safeFooter = htmlspecialchars((string) ($config['site_footer'] ?? ''));
$dayNames = [
    0 => 'Chủ nhật',
    1 => 'Thứ hai',
    2 => 'Thứ ba',
    3 => 'Thứ tư',
    4 => 'Thứ năm',
    5 => 'Thứ sáu',
    6 => 'Thứ bảy',
];
$formatAttendanceDateLabel = static function (string $date) use ($dayNames): array {
    $rawDate = trim($date);
    $parsed = false;
    foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!Y/m/d', '!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d\TH:i:s', '!Y-m-d\TH:i', '!d/m/Y H:i:s', '!d/m/Y H:i', '!d-m-Y H:i:s', '!d-m-Y H:i'] as $format) {
        $candidate = \DateTimeImmutable::createFromFormat($format, $rawDate);
        if ($candidate instanceof \DateTimeImmutable) {
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0)) {
                $parsed = $candidate;
                break;
            }
        }
    }

    if (!$parsed instanceof \DateTimeImmutable) {
        return [
            'weekday' => '',
            'date' => $rawDate,
            'is_weekend' => false,
        ];
    }

    $dow = (int) $parsed->format('w');
    return [
        'weekday' => $dayNames[$dow] ?? '',
        'date' => $parsed->format('d/m/Y'),
        'is_weekend' => ($dow === 0 || $dow === 6),
    ];
};
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> | <?= $safeCompany !== '' ? $safeCompany : 'HR Portal' ?></title>
    <meta name="description" content="Tra cứu chấm công nhân viên theo khoảng thời gian.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/main.css?v=<?= time() ?>">
</head>
<body>

<div class="bg-effects"></div>

<div class="app-container">
    <nav class="top-nav">
        <div class="nav-brand">
            <div class="nav-logo"><?= $safeLogo ?></div>
            <div>
                <div class="nav-title"><?= $safeCompany ?></div>
                <div class="nav-subtitle"><?= $safeSubtitle ?></div>
            </div>
        </div>
        <div class="nav-right">
            <div class="module-switch" role="tablist" aria-label="Chọn phân hệ">
                <a href="index.php" class="module-switch-link">Phiếu lương</a>
                <?php if ($showAttendanceModule): ?>
                    <a href="index.php?page=attendance" class="module-switch-link active" aria-current="page">Chấm công</a>
                <?php endif; ?>
            </div>
            <?php if (!empty($latestPayrollUpdateLabel)): ?>
                <div class="nav-meta-badge">Cập nhật: <?= htmlspecialchars((string) $latestPayrollUpdateLabel) ?></div>
            <?php endif; ?>
        </div>
    </nav>

    <section class="attendance-shell">
        <div class="attendance-hero">
            <div>
                <div class="attendance-kicker">Attendance</div>
                <h1 class="attendance-title">Tra cứu chấm công</h1>
                <p class="attendance-subtitle">Xem giờ vào ra theo nhân viên và khoảng thời gian được chọn.</p>
            </div>
            <?php if (!empty($attendanceState['formatted_update'])): ?>
                <div class="attendance-update-badge">Cập nhật: <?= htmlspecialchars($attendanceState['formatted_update']) ?></div>
            <?php endif; ?>
        </div>

        <?php if (!$attendanceState['enabled'] && !$isAdmin): ?>
            <div class="alert alert-info">
                <span class="alert-icon">ℹ️</span>
                <span>Phân hệ chấm công đang tạm tắt.</span>
            </div>
        <?php else: ?>
            <?php if (!$attendanceState['enabled'] && $isAdmin): ?>
                <div class="alert alert-info">
                    <span class="alert-icon">ℹ️</span>
                    <span>[Chế độ Admin] Phân hệ chấm công hiện đang tắt với nhân viên nhưng bạn vẫn có thể kiểm tra cấu hình.</span>
                </div>
            <?php endif; ?>
            <?php if (!empty($attendanceState['is_shared_view'])): ?>
                <div class="alert alert-info">
                    <span class="alert-icon">ℹ️</span>
                    <span>
                        Bạn đang xem bản chia sẻ chấm công của mã NV
                        <strong><?= htmlspecialchars((string) ($attendanceState['employee_id'] ?? '')) ?></strong>
                        <?php if (!empty($attendanceState['share_expires_at'])): ?>
                            , hết hạn lúc <?= htmlspecialchars(date('d/m/Y H:i', (int) $attendanceState['share_expires_at'])) ?>.
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
            <?php if ($attendanceState['availability_message'] !== '' && !$attendanceState['availability']['is_open']): ?>
                <div class="alert alert-info">
                    <span class="alert-icon">ℹ️</span>
                    <span><?= htmlspecialchars((string) $attendanceState['availability_message']) ?><?= $isAdmin ? ' [Admin vẫn có quyền kiểm tra.]' : '' ?></span>
                </div>
            <?php endif; ?>

            <?php if (($attendanceState['availability']['is_open'] || $isAdmin) && empty($attendanceState['is_shared_view'])): ?>
                <div class="attendance-search-card">
                    <form method="POST" action="index.php?page=attendance" class="attendance-form">
                        <div class="attendance-form-grid">
                            <div class="form-group">
                                <label class="form-label" for="m_tu_ngay">Từ ngày</label>
                                <input type="date" id="m_tu_ngay" name="m_tu_ngay" class="form-input" value="<?= htmlspecialchars((string) $attendanceState['from_date']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="m_den_ngay">Đến ngày</label>
                                <input type="date" id="m_den_ngay" name="m_den_ngay" class="form-input" value="<?= htmlspecialchars((string) $attendanceState['to_date']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="m_ma_nv">Mã nhân viên</label>
                                <input
                                    type="text"
                                    id="m_ma_nv"
                                    name="m_ma_nv"
                                    class="form-input"
                                    placeholder="VD: NV257 hoặc 257"
                                    value="<?= htmlspecialchars((string) $attendanceState['employee_id']) ?>"
                                    autocomplete="off"
                                    pattern="[A-Za-z0-9._-]+"
                                    title="Chỉ nhập mã nhân viên (chữ, số, dấu chấm, gạch dưới hoặc gạch ngang). Không nhập họ tên.">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="m_mat_khau">Mật khẩu</label>
                                <input
                                    type="password"
                                    id="m_mat_khau"
                                    name="m_mat_khau"
                                    class="form-input"
                                    placeholder="Nhập mật khẩu nhân viên"
                                    autocomplete="current-password"
                                    required>
                            </div>
                            <div class="attendance-submit-wrap">
                                <button type="submit" name="m_action" value="view" class="btn-submit attendance-submit-btn">Tra cứu</button>
                            </div>
                        </div>
                    </form>

                    <div class="attendance-hint-row">
                        <div class="helper-pill"><?= $isAdmin ? 'API chấm công đang được cấu hình từ Admin Panel' : 'Tra cứu theo mã nhân viên và khoảng ngày được chọn' ?></div>
                        <button type="button" class="btn-secondary" onclick="window.print()">In / Lưu PDF</button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($attendanceState['api_error'] !== ''): ?>
                <div class="alert alert-error">
                    <span class="alert-icon">⚠️</span>
                    <span><?= htmlspecialchars((string) $attendanceState['api_error']) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($attendanceState['is_submit']): ?>
                <?php if ($attendanceState['has_data']): ?>
                    <div class="attendance-results">
                        <?php foreach ($attendanceState['employees'] as $employee): ?>
                            <?php
                            $days = $employee['days'] ?? [];
                            ksort($days);
                            $employeeCode = (string) ($employee['info']['code'] ?? '');
                            $employeeName = trim((string) ($employee['info']['name'] ?? 'Nhân viên'));
                            $totalPunches = 0;
                            foreach ($days as $times) {
                                $totalPunches += is_array($times) ? count($times) : 0;
                            }
                            $nameParts = preg_split('/\s+/', $employeeName) ?: [];
                            $initials = '';
                            foreach ($nameParts as $part) {
                                if ($part === '') {
                                    continue;
                                }
                                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
                                if (mb_strlen($initials) >= 2) {
                                    break;
                                }
                            }
                            if ($initials === '') {
                                $initials = 'NV';
                            }
                            ?>
                            <article class="attendance-card">
                                <header class="attendance-card-header">
                                    <div class="attendance-card-identity">
                                        <div class="attendance-card-avatar"><?= htmlspecialchars($initials) ?></div>
                                        <div class="attendance-card-heading">
                                            <div class="attendance-card-title"><?= htmlspecialchars($employeeName) ?></div>
                                            <div class="attendance-card-meta">Mã NV: <strong><?= htmlspecialchars($employeeCode) ?></strong></div>
                                        </div>
                                    </div>
                                    <div class="attendance-card-stat-group">
                                        <div class="attendance-card-count"><?= count($days) ?> ngày</div>
                                        <div class="attendance-card-count attendance-card-count--muted"><?= $totalPunches ?> lượt chấm</div>
                                    </div>
                                </header>
                                <div class="attendance-summary-strip">
                                    <div class="attendance-summary-item">
                                        <span>Khoảng tra cứu</span>
                                        <strong><?= htmlspecialchars((string) $attendanceState['from_date']) ?> - <?= htmlspecialchars((string) $attendanceState['to_date']) ?></strong>
                                    </div>
                                    <div class="attendance-summary-item">
                                        <span>Số ngày có dữ liệu</span>
                                        <strong><?= count($days) ?> ngày</strong>
                                    </div>
                                    <div class="attendance-summary-item">
                                        <span>Tổng lượt chấm</span>
                                        <strong><?= $totalPunches ?> mốc giờ</strong>
                                    </div>
                                </div>
                                <div class="attendance-table-wrap">
                                    <table class="attendance-table">
                                        <thead>
                                            <tr>
                                                <th>Thứ, ngày</th>
                                                <th>Giờ chấm</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($days as $date => $times): ?>
                                                <?php
                                                $dateLabel = $formatAttendanceDateLabel((string) $date);
                                                $dayLabel = $dateLabel['weekday'];
                                                $isWeekend = $dateLabel['is_weekend'];
                                                ?>
                                                <tr<?= $isWeekend ? ' class="attendance-weekend-row"' : '' ?>>
                                                    <td data-label="Thứ, ngày">
                                                        <div class="attendance-date-cell">
                                                            <?php if ($dayLabel !== ''): ?>
                                                                <span class="attendance-weekday"><?= htmlspecialchars($dayLabel) ?></span>
                                                            <?php endif; ?>
                                                            <span class="attendance-date-value"><?= htmlspecialchars((string) $dateLabel['date']) ?></span>
                                                            <?php if ($isWeekend): ?>
                                                                <small>Cuối tuần</small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td data-label="Giờ chấm">
                                                        <div class="attendance-time-list">
                                                            <?php foreach ((array) $times as $time): ?>
                                                                <span class="attendance-time-chip"><?= htmlspecialchars((string) $time) ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($attendanceState['api_error'] === ''): ?>
                    <div class="alert alert-info">
                        <span class="alert-icon">ℹ️</span>
                        <span>Không tìm thấy dữ liệu chấm công phù hợp. Vui lòng kiểm tra lại mã nhân viên hoặc khoảng thời gian.</span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <footer class="app-footer">
        <div class="footer-text"><?= $safeFooter ?></div>
    </footer>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.innerWidth > 768) {
        var input = document.getElementById('m_ma_nv');
        if (input && !input.value) input.focus();
    }
});
</script>

</body>
</html>
