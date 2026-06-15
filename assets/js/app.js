// ============================================================
// Employee Portal JavaScript
// Configs are injected by PHP as global JS variables (see index.php)
// ============================================================

(function () {
    const STORAGE_KEYS = {
        empId: 'hr_portal_last_emp_id',
        periodLabel: 'hr_portal_last_period_label',
        payrollResult: 'hr_portal_payroll_result_payload',
    };

    let TCC_CONFIGS = [];
    let rawData = [];
    let headerMap = {};
    let currentConfig = null;
    let currentUser = null;

    const btn = document.getElementById('btnSubmit');
    const btnText = document.getElementById('btnText');
    const res = document.getElementById('resultArea');
    const periodBox = document.getElementById('periodContainer');
    const empInput = document.getElementById('inputEmpId');
    const passInput = document.getElementById('inputPassword');
    const togglePasswordBtn = document.getElementById('togglePasswordBtn');
    const clearSearchBtn = document.getElementById('clearSearchBtn');

    function log(msg) { console.log('[HR Portal]', msg); }

    function normalize(str) {
        return (str || '').toString().trim().toUpperCase().normalize('NFC').replace(/\s+/g, ' ');
    }

    function escapeHtml(value) {
        return (value || '').toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function safeText(value, fallback = '—') {
        const text = (value ?? '').toString().trim();
        return text === '' ? fallback : escapeHtml(text);
    }

    function parsePublishDate(value) {
        const raw = (value || '').toString().trim();
        if (!raw) return null;

        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
        if (match) {
            const [, year, month, day, hour, minute, second = '00'] = match;
            return new Date(
                Number(year),
                Number(month) - 1,
                Number(day),
                Number(hour),
                Number(minute),
                Number(second)
            );
        }

        const fallback = new Date(raw);
        return Number.isNaN(fallback.getTime()) ? null : fallback;
    }

    function getTimeRemainingStr(futureDate) {
        const now = new Date();
        const diffMs = futureDate - now;
        if (diffMs <= 0) return 'đã mở';

        const d = Math.floor(diffMs / (1000 * 60 * 60 * 24));
        const h = Math.floor((diffMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const m = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));

        const parts = [];
        if (d > 0) parts.push(`${d} ngày`);
        if (h > 0) parts.push(`${h} giờ`);
        if (m > 0) parts.push(`${m} phút`);

        if (parts.length === 0) return 'chưa tới 1 phút';
        return parts.join(' ');
    }

    function isFuturePeriod(cfg) {
        return !!(cfg && cfg.publishDate && cfg.publishDate > new Date());
    }

    function periodRank(cfg) {
        if (!cfg) return 9;
        if (cfg.isPending) return 3;
        if (isFuturePeriod(cfg)) return 2;
        return 1;
    }

    function getPeriodTimestamp(cfg) {
        return cfg && cfg.publishDate ? cfg.publishDate.getTime() : 0;
    }

    function friendlyErrorMessage(message) {
        const raw = (message || '').toString().trim();
        if (!raw) return 'Chưa xử lý được yêu cầu. Vui lòng thử lại sau.';

        if (/mật khẩu không chính xác/i.test(raw)) return 'Mật khẩu chưa đúng. Vui lòng kiểm tra lại.';
        if (/không tìm thấy mã/i.test(raw)) return 'Không tìm thấy mã nhân viên này. Vui lòng kiểm tra lại mã.';
        if (/phiên làm việc hết hạn/i.test(raw)) return 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.';
        if (/phiếu lương trống|chưa cấu hình|không hợp lệ|không tồn tại|không thể đọc|schema|sheet|google|file local|excel|csv/i.test(raw)) {
            return 'Dữ liệu phiếu lương chưa sẵn sàng. Vui lòng báo bộ phận nhân sự kiểm tra.';
        }
        if (/lỗi máy chủ|lỗi hệ thống|lỗi xử lý|không thể kết nối/i.test(raw)) {
            return 'Hệ thống đang gặp sự cố. Vui lòng thử lại sau hoặc báo bộ phận nhân sự.';
        }
        return raw;
    }

    function startSystemClock() {
        const dsp = document.getElementById('systemTimeDisplay');
        if (!dsp) return;
        const update = () => {
            const now = new Date();
            dsp.innerHTML = `Hệ thống hoạt động • <strong>${now.toLocaleTimeString('vi-VN')}</strong>`;
        };
        update();
        setInterval(update, 1000);
    }

    function restoreSavedState() {
        try {
            const savedEmpId = localStorage.getItem(STORAGE_KEYS.empId);
            if (savedEmpId) {
                empInput.value = savedEmpId;
            }
        } catch (err) {
            log('Storage unavailable: ' + err.message);
        }
    }

    function persistEmpId() {
        try {
            localStorage.setItem(STORAGE_KEYS.empId, empInput.value.trim());
        } catch (err) {
            log('Cannot persist emp id: ' + err.message);
        }
    }

    function persistSelectedPeriod() {
        try {
            localStorage.setItem(STORAGE_KEYS.periodLabel, currentConfig ? currentConfig.label : '');
        } catch (err) {
            log('Cannot persist period: ' + err.message);
        }
    }

    function goToResultPage(payload) {
        return fetch('index.php', {
            method: 'POST',
            body: (() => {
                const fd = new FormData();
                fd.append('action', 'save_payroll_result');
                fd.append('payload', JSON.stringify(payload));
                return fd;
            })(),
        }).then(async (resp) => {
            if (!resp.ok) throw new Error('Lỗi máy chủ: ' + resp.status);
            const json = await resp.json();
            if (!json.success) throw new Error(json.message || 'Không thể lưu kết quả phiên.');
            window.location.href = json.redirect_url || 'index.php?page=payroll_result';
        });
    }

    function restoreSelectedPeriodIndex() {
        try {
            const savedLabel = localStorage.getItem(STORAGE_KEYS.periodLabel);
            if (!savedLabel) return 0;
            const foundIndex = TCC_CONFIGS.findIndex(item => item.label === savedLabel);
            return foundIndex >= 0 ? foundIndex : 0;
        } catch (err) {
            log('Cannot restore period: ' + err.message);
            return 0;
        }
    }

    function showAlert(type, message) {
        const icons = { error: '!', success: 'OK', info: 'i' };
        res.innerHTML = `<div class="alert alert-${type}">
            <span class="alert-icon">${icons[type] || ''}</span>
            <span>${message}</span>
        </div>`;
    }

    function showStatus(icon, message, compact = false) {
        const iconHtml = icon ? `<span class="icon">${icon}</span>` : '';
        res.innerHTML = `<div class="result-status${compact ? ' result-status--compact' : ''}">
            ${iconHtml}
            ${message}
        </div>`;
    }

    function showLoadingState(message = 'Đang xác thực & tải phiếu lương') {
        res.innerHTML = `<div class="result-loading" aria-live="polite" role="status">
            <div class="loading-panel">
                <div class="loading-spinner-wrap">
                    <div class="loading-ring"></div>
                    <div class="loading-ring"></div>
                    <div class="loading-ring"></div>
                    <div class="loading-core"></div>
                </div>
                <div>
                    <div class="loading-title">${escapeHtml(message)}</div>
                    <div class="loading-sub">Vui lòng chờ trong giây lát…</div>
                </div>
                <div class="loading-dots">
                    <span></span><span></span><span></span>
                </div>
            </div>
        </div>`;
    }

    function setBtnLoading(loading) {
        btn.disabled = loading;
        btnText.textContent = loading ? 'Đang xử lý...' : 'Tra cứu';
    }

    function scrollResultsIntoView() {
        requestAnimationFrame(() => {
            res.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    function selectPeriod(index, shouldAutoSearch = false) {
        currentConfig = TCC_CONFIGS[index] || null;
        rawData = [];
        headerMap = {};
        persistSelectedPeriod();

        if (!currentConfig) {
            showStatus('', 'Không tìm thấy kỳ phiếu lương phù hợp.');
            return;
        }

        const now = new Date();
        if (currentConfig.publishDate && currentConfig.publishDate > now) {
            const pd = currentConfig.publishDate;
            const dateStr = pd.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });
            const timeStr = pd.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
            const waitStr = getTimeRemainingStr(pd);
            if (typeof HR_IS_ADMIN !== 'undefined' && HR_IS_ADMIN) {
                showAlert('info', `<strong>[Chế độ Admin]</strong> Phiếu lương này hiện đang ẩn với nhân viên và sẽ tự động mở xem vào ngày <strong>${dateStr} lúc ${timeStr}</strong> <em>(Còn: ${waitStr})</em>.`);
            } else {
                showAlert('info', `Phiếu lương <strong>${escapeHtml(currentConfig.label)}</strong> chưa đến giờ mở.<br/>Sẽ được mở xem từ ngày <strong>${dateStr} lúc ${timeStr}</strong> <em>(Cần chờ thêm: ${waitStr})</em>.`);
            }
        } else if (currentConfig.isPending) {
            showAlert('info', `Kỳ <strong>${escapeHtml(currentConfig.label)}</strong> đang chờ dữ liệu phiếu lương.`);
        }

        if (shouldAutoSearch && empInput.value.trim()) {
            document.getElementById('btnSubmit').click();
        }
    }

    async function loadConfig() {
        try {
            log('Loading period config...');
            TCC_CONFIGS = HR_PERIODS.map((r, index) => ({
                originalIndex: index,
                label: r.label,
                sourceType: r.source_type || 'google',
                localFile: r.local_file,
                sheetId: r.sheet_id,
                gid: r.gid,
                cols: r.cols ? r.cols.split(',').map(s => s.trim()).filter(Boolean) : null,
                highlightCols: r.highlight_cols ? r.highlight_cols.split(',').map(s => s.trim()).filter(Boolean) : [],
                moneyCols: r.money_cols ? r.money_cols.split(',').map(s => normalize(s.trim())).filter(Boolean) : null,
                publishDate: parsePublishDate(r.publish_date),
                enabled: r.enabled !== false,
                isPending: (r.source_type === 'local' ? !r.local_file : !r.sheet_id),
            })).filter(c => c.label && c.enabled);

            TCC_CONFIGS.sort((a, b) => {
                const rankDiff = periodRank(a) - periodRank(b);
                if (rankDiff !== 0) return rankDiff;
                return getPeriodTimestamp(b) - getPeriodTimestamp(a);
            });

            if (TCC_CONFIGS.length === 0) throw new Error('Chưa có kỳ phiếu lương nào đang được bật để tra cứu.');

            const savedIndex = restoreSelectedPeriodIndex();
            const activeIndex = periodRank(TCC_CONFIGS[savedIndex]) === 1
                ? savedIndex
                : Math.max(0, TCC_CONFIGS.findIndex(cfg => periodRank(cfg) === 1));
            const firstReadyIndex = TCC_CONFIGS.findIndex(cfg => periodRank(cfg) === 1);
            periodBox.innerHTML = TCC_CONFIGS.map((cfg, i) => {
                const rank = periodRank(cfg);
                const badge = rank === 1 && i === firstReadyIndex
                    ? '<span class="period-badge period-badge--ready">Mới nhất</span>'
                    : rank === 2
                        ? '<span class="period-badge period-badge--future">Chưa mở</span>'
                        : rank === 3
                            ? '<span class="period-badge period-badge--pending">Chờ dữ liệu</span>'
                            : '';
                return `<label class="period-item period-item--rank-${rank}">
                    <input type="radio" name="period" value="${i}" ${i === activeIndex ? 'checked' : ''}>
                    <span class="period-label"><span class="period-label-text">${escapeHtml(cfg.label)}</span>${badge}</span>
                </label>`;
            }).join('');

            periodBox.querySelectorAll('input[type="radio"]').forEach(el => {
                el.addEventListener('change', () => {
                    selectPeriod(parseInt(el.value, 10), true);
                });
            });

            selectPeriod(activeIndex, false);
            log('Config loaded. Default period: ' + currentConfig.label);
        } catch (e) {
            periodBox.innerHTML = `<div class="alert alert-error" style="margin:0">
                <span class="alert-icon">!</span>
                <span>${escapeHtml(friendlyErrorMessage(e.message))}</span>
            </div>`;
            log('Config error: ' + e.message);
        }
    }

    async function fetchData() {
        if (!currentConfig || currentConfig.isPending) return false;
        try {
            log('Fetching secure data for: ' + currentConfig.label);

            const pIdx = currentConfig.originalIndex;
            if (pIdx === -1) throw new Error('Không tìm thấy kỳ phiếu lương trong cấu hình.');

            const formData = new FormData();
            formData.append('action', 'get_data');
            formData.append('period_index', pIdx);

            const resp = await fetch('index.php', {
                method: 'POST',
                body: formData,
            });
            if (!resp.ok) throw new Error('Lỗi máy chủ: ' + resp.status);

            const result = await resp.json();
            if (!result.success) throw new Error(result.message || 'Lỗi lấy dữ liệu.');

            headerMap = {};
            result.header.forEach((h, i) => {
                if (h) headerMap[normalize(h)] = i;
            });
            rawData = result.data;

            log(`Success. Received ${rawData.length} rows.`);
            return true;
        } catch (e) {
            showAlert('error', escapeHtml(friendlyErrorMessage(e.message)));
            log('Data error: ' + e.message);
            return false;
        }
    }


    loadConfig();
    startSystemClock();
    restoreSavedState();
    empInput.focus();

    empInput.addEventListener('input', persistEmpId);

    if (togglePasswordBtn) {
        togglePasswordBtn.addEventListener('click', () => {
            const isMasked = passInput.type === 'password';
            passInput.type = isMasked ? 'text' : 'password';
            togglePasswordBtn.textContent = isMasked ? 'Ẩn' : 'Hiện';
            togglePasswordBtn.setAttribute('aria-label', isMasked ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
        });
    }

    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', () => {
            passInput.value = '';
            empInput.value = '';
            persistEmpId();
            res.innerHTML = `<div class="ready-banner">
                <div class="ready-banner-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2M12 12v4M10 14h4"/></svg>
                </div>
                <div class="ready-banner-body">
                    <div class="ready-banner-title">Nhập thông tin để xem phiếu lương</div>
                    <div class="ready-banner-hint">Chọn kỳ lương → Nhập mã NV &amp; mật khẩu → Nhấn <strong>Tra cứu</strong></div>
                </div>
            </div>`;
            empInput.focus();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    document.getElementById('searchForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const inpUser = empInput.value.trim();
        const inpPass = passInput.value.trim();

        if (!inpUser) {
            showAlert('error', 'Vui lòng nhập Mã nhân viên.');
            empInput.focus();
            return;
        }

        if (!currentConfig) {
            showAlert('error', 'Danh sách kỳ phiếu lương chưa sẵn sàng.');
            return;
        }

        if (currentConfig.isPending) {
            showAlert('info', `Kỳ <strong>${escapeHtml(currentConfig.label)}</strong> chưa có dữ liệu. Vui lòng chọn kỳ khác hoặc quay lại sau.`);
            scrollResultsIntoView();
            return;
        }

        setBtnLoading(true);
        showLoadingState('Đang xác thực danh tính');
        persistEmpId();

        if (currentConfig.publishDate) {
            const now = new Date();
            if (currentConfig.publishDate > now) {
                if (!(typeof HR_IS_ADMIN !== 'undefined' && HR_IS_ADMIN)) {
                    setBtnLoading(false);
                    const pd = currentConfig.publishDate;
                    const dateStr = pd.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });
                    const timeStr = pd.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
                    const waitStr = getTimeRemainingStr(pd);
                    showAlert('info', `Phiếu lương <strong>${escapeHtml(currentConfig.label)}</strong> chưa được mở.<br/>Vui lòng quay lại từ <strong>${dateStr} lúc ${timeStr}</strong> để tra cứu <em>(Cần chờ thêm: ${waitStr})</em>.`);
                    scrollResultsIntoView();
                    return;
                }
            }
        }

        let authResult = null;
        try {
            const formData = new FormData();
            formData.append('action', 'login');
            formData.append('emp_id', inpUser);
            formData.append('password', inpPass);

            const authResp = await fetch('index.php', {
                method: 'POST',
                body: formData,
            });
            if (!authResp.ok) throw new Error('Lỗi máy chủ: ' + authResp.status);
            authResult = await authResp.json();
        } catch (err) {
            setBtnLoading(false);
            showAlert('error', escapeHtml(friendlyErrorMessage(err.message)));
            scrollResultsIntoView();
            return;
        }

        if (!authResult.success) {
            setBtnLoading(false);
            showAlert('error', escapeHtml(friendlyErrorMessage(authResult.message || 'Xác thực thất bại.')));
            passInput.focus();
            scrollResultsIntoView();
            return;
        }

        currentUser = authResult.user;
        log('User logged in: ' + currentUser.name);

        showLoadingState('Đang tải dữ liệu phiếu lương');
        const ok = await fetchData();
        if (!ok) {
            setBtnLoading(false);
            scrollResultsIntoView();
            return;
        }

        const found = rawData;

        if (found.length === 0) {
            showStatus('', 'Không có dữ liệu phiếu lương cho kỳ này. Vui lòng chọn kỳ khác hoặc báo bộ phận nhân sự.');
            scrollResultsIntoView();
        } else {
            try {
                await goToResultPage({
                    found,
                    headerMap,
                    currentConfig,
                    empId: inpUser,
                    statColsRaw: HR_STAT_COLS || '',
                    generatedAt: Date.now(),
                });
                return;
            } catch (err) {
                showAlert('error', escapeHtml(friendlyErrorMessage(err.message || 'Không thể chuyển sang trang kết quả.')));
                scrollResultsIntoView();
            }
        }
        setBtnLoading(false);
    });
})();
