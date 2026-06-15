<?php
/** @var array $config */
/** @var bool $isAdmin */
/** @var bool $showAttendanceModule */
/** @var string $latestPayrollUpdateLabel */
/** @var string $payrollShareToken */
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kết Quả Phiếu Lương | <?= htmlspecialchars((string) ($config['site_company'] ?? 'HR Portal')) ?></title>
    <meta name="description" content="Kết quả tra cứu phiếu lương nhân viên.">
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
            <div class="nav-logo"><?= htmlspecialchars((string) ($config['site_logo_text'] ?? 'HR')) ?></div>
            <div>
                <div class="nav-title"><?= htmlspecialchars((string) ($config['site_company'] ?? 'HR Portal')) ?></div>
                <div class="nav-subtitle"><?= htmlspecialchars((string) ($config['site_subtitle'] ?? 'Employee Self-Service')) ?></div>
            </div>
        </div>
        <div class="nav-right">
            <div class="module-switch" role="tablist" aria-label="Chọn phân hệ">
                <a href="index.php" class="module-switch-link active" aria-current="page">Phiếu lương</a>
                <?php if ($showAttendanceModule): ?>
                    <a href="index.php?page=attendance" class="module-switch-link">Chấm công</a>
                <?php endif; ?>
            </div>
            <?php if ($latestPayrollUpdateLabel !== ''): ?>
                <div class="nav-meta-badge">Cập nhật: <?= htmlspecialchars($latestPayrollUpdateLabel) ?></div>
            <?php endif; ?>
        </div>
    </nav>

    <section class="attendance-shell">
        <div class="payroll-result-hero">
            <div class="payroll-result-hero-left">
                <div class="payroll-result-hero-label">
                    <span class="payroll-result-hero-dot"></span>
                    Kết quả tra cứu đã xác thực
                </div>
                <h1 class="payroll-result-hero-title">Phiếu Lương Nhân Viên</h1>
            </div>
            <a href="index.php" class="payroll-result-hero-back">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
                Tra cứu kỳ khác
            </a>
        </div>

        <div id="resultArea" class="result-area">
            <div class="result-loading" aria-live="polite" role="status" aria-label="Đang tải phiếu lương">
                <div class="loading-panel">
                    <div class="loading-spinner-wrap">
                        <div class="loading-ring"></div>
                        <div class="loading-ring"></div>
                        <div class="loading-ring"></div>
                        <div class="loading-core"></div>
                    </div>
                    <div>
                        <div class="loading-title">Đang tải phiếu lương</div>
                        <div class="loading-sub">Vui lòng chờ trong giây lát…</div>
                    </div>
                    <div class="loading-dots">
                        <span></span><span></span><span></span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer class="app-footer">
        <div class="footer-text"><?= htmlspecialchars((string) ($config['site_footer'] ?? '')) ?></div>
    </footer>
</div>

<script>
    const HR_STAT_COLS = <?= json_encode((string) ($config['stat_cols'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const HR_PAYROLL_SHARE_TOKEN = <?= json_encode((string) ($payrollShareToken ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/js/payroll-result.js?v=<?= time() ?>"></script>
</body>
</html>
