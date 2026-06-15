(function () {
    const res = document.getElementById('resultArea');

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

    function getInitials(name) {
        const parts = (name || '').toString().trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return 'NV';
        const last = parts[parts.length - 1] || '';
        const first = parts.length > 1 ? parts[0] : '';
        return ((first[0] || '') + (last[0] || '')).toUpperCase() || 'NV';
    }

    function findHeaderIndex(headerMap, names) {
        for (const name of names) {
            const idx = headerMap[normalize(name)];
            if (idx !== undefined) return idx;
        }
        return -1;
    }

    function showInfo(message) {
        res.innerHTML = `<div class="result-status"><span class="icon">i</span>${message}</div>`;
    }

    function formatValueByHeader(headerName, rawValue, moneyColsNormalized) {
        if (rawValue === undefined || rawValue === null || rawValue === '') return '';
        const valStr = rawValue.toString().trim();
        const h = normalize(headerName);

        const isWorkingDays = h.includes('NGÀY CÔNG');
        if (isWorkingDays) {
            const num = parseFloat(valStr);
            return isNaN(num) ? valStr : Math.round(num).toString().replace('.', ',');
        }

        const moneyKeywords = ['PHỤ CẤP', 'TẠM ỨNG', 'LƯƠNG', 'THƯỞNG', 'KHẤU TRỪ', 'THỰC LÃNH', 'TIỀN'];
        const isMoney = moneyKeywords.some(k => h.includes(k)) || moneyColsNormalized.includes(h);
        if (isMoney) {
            let cleanVal = valStr.replace(/[^0-9,\.\-]/g, '');
            if (cleanVal.includes(',') && !cleanVal.includes('.')) cleanVal = cleanVal.replace(',', '.');
            const num = parseFloat(cleanVal);
            return isNaN(num) ? valStr : num.toLocaleString('vi-VN', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' ₫';
        }

        if (/^-?\d+([.,]\d+)?$/.test(valStr)) return valStr.replace('.', ',');
        return valStr;
    }

    function formatExpiryLabel(timestamp) {
        const ts = Number(timestamp) || 0;
        if (ts <= 0) return '';
        return new Date(ts * 1000).toLocaleString('vi-VN');
    }

    function render(payload, meta = {}) {
        const found = Array.isArray(payload.found) ? payload.found : [];
        const headerMap = payload.headerMap || {};
        const currentConfig = payload.currentConfig || {};
        const empId = (payload.empId || '').toString().trim();

        if (!found.length || !Object.keys(headerMap).length) {
            showInfo('Không có dữ liệu để hiển thị. Vui lòng tra cứu lại.');
            return;
        }

        const defaultCols = ['HỌ TÊN NHÂN VIÊN', 'BỘ PHẬN', 'NGÀY CÔNG', 'THỰC LÃNH', 'TỔNG TIỀN LƯƠNG'];
        let displayCols = Array.isArray(currentConfig.cols) && currentConfig.cols.length ? currentConfig.cols : defaultCols;
        displayCols = displayCols.filter(c => headerMap[normalize(c)] !== undefined);
        const highlightList = (currentConfig.highlightCols || []).map(s => normalize((s || '').toString())).filter(Boolean);
        const moneyColsNormalized = (currentConfig.moneyCols || []).map(s => normalize((s || '').toString())).filter(Boolean);

        const firstRow = found[0];
        const nameIdx = findHeaderIndex(headerMap, ['HỌ TÊN NHÂN VIÊN', 'HỌ TÊN']);
        const deptIdx = findHeaderIndex(headerMap, ['BỘ PHẬN', 'PHÒNG BAN']);
        const empName = nameIdx >= 0 ? (firstRow[nameIdx] || 'Nhân viên') : 'Nhân viên';
        const empDept = deptIdx >= 0 ? (firstRow[deptIdx] || '') : '';
        const periodLabel = currentConfig.label || 'Kỳ phiếu lương';

        let html = `<div class="payroll-card">
            <div class="payroll-card-top">
                <div class="result-header-left">
                    <div class="result-avatar">${safeText(getInitials(empName), 'NV')}</div>
                    <div class="result-header-info">
                        <div class="result-kicker">Phiếu lương</div>
                        <div class="result-title">${safeText(empName, 'Nhân viên')}</div>
                        <div class="result-subtitle">${empDept ? `${safeText(empDept)} · ` : ''}Mã NV: ${safeText(empId)}</div>
                    </div>
                </div>
                <div class="result-header-right">
                    <div class="result-period-badge">${safeText(periodLabel)}</div>
                </div>
            </div>`;

        const statCols = (typeof HR_STAT_COLS === 'string' ? HR_STAT_COLS : '').split(',').map(s => s.trim()).filter(Boolean);
        const preferredStats = ['THỰC LÃNH', 'TỔNG TIỀN LƯƠNG', 'NGÀY CÔNG', 'GIỜ TĂNG CA', 'TĂNG CA', 'BỘ PHẬN'];
        const visibleStats = [...preferredStats, ...statCols]
            .filter((value, index, array) => value && array.findIndex(v => normalize(v) === normalize(value)) === index)
            .filter(c => headerMap[normalize(c)] !== undefined)
            .slice(0, 6);
        const iconMap = {
            'THỰC LÃNH': '💵', 'TỔNG TIỀN LƯƠNG': '💰', 'NGÀY CÔNG': '📅',
            'GIỜ TĂNG CA': '⏱', 'TĂNG CA': '⏱', 'BỘ PHẬN': '🏢',
            'TỔNG SỐ TIỀN PHẢI TT LẠI': '🔴', 'TIỀN TẠM ỨNG': '💳',
        };
        const filledStats = visibleStats.filter(col => {
            const rawVal = firstRow[headerMap[normalize(col)]];
            if (rawVal === undefined || rawVal === null) return false;
            const str = rawVal.toString().trim();
            return str !== '' && str !== '0' && str !== '0.0' && str !== '0,0';
        });
        if (filledStats.length) {
            html += '<div class="payroll-card-divider"></div>';
            html += '<section class="payroll-summary" aria-label="Tóm tắt phiếu lương">';
            filledStats.forEach(col => {
                const rawVal = firstRow[headerMap[normalize(col)]];
                const isPrimary = ['THỰC LÃNH', 'TỔNG TIỀN LƯƠNG'].includes(normalize(col));
                const isHighlighted = highlightList.includes(normalize(col)) || isPrimary;
                const formatted = safeText(formatValueByHeader(col, rawVal, moneyColsNormalized));
                const cardClass = isPrimary ? 'stat-card stat-card--primary' : (isHighlighted ? 'stat-card money-card' : 'stat-card');
                const valClass = isHighlighted ? 'stat-value success' : 'stat-value';
                const icon = iconMap[normalize(col)] || iconMap[col] || '';
                html += `<div class="${cardClass}">
                    <div class="stat-label">${icon ? `<span class="stat-icon">${icon}</span>` : ''}${safeText(col)}</div>
                    <div class="${valClass}">${formatted}</div>
                </div>`;
            });
            html += '</section>';
        }

        html += '</div>';

        const now = new Date();
        const timeStr = now.toLocaleString('vi-VN');
        html += `<div class="data-card">
            <div class="data-card-header">
                <div class="data-card-title">
                    <span class="icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </span>
                    Chi tiết phiếu lương
                </div>
                <div class="data-card-cols">${displayCols.length} mục</div>
            </div>
            <div class="table-wrapper">`;

        found.forEach(row => {
            html += '<div class="slip-grid">';
            displayCols.forEach((col, ci) => {
                const rawVal = row[headerMap[normalize(col)]] || '';
                const isHighlighted = highlightList.includes(normalize(col));
                const formatted = safeText(formatValueByHeader(col, rawVal, moneyColsNormalized) || '—');
                const isLastOdd = (ci === displayCols.length - 1) && (displayCols.length % 2 !== 0);
                const itemClass = isLastOdd ? 'slip-item full-row' : 'slip-item';
                let valHtml;
                if (ci === 0) {
                    valHtml = `<div class="slip-value highlight">${formatted}</div>`;
                } else if (isHighlighted) {
                    valHtml = `<div class="slip-value"><span class="money-pill" title="${formatted}">${formatted}</span></div>`;
                } else {
                    valHtml = `<div class="slip-value">${formatted}</div>`;
                }
                html += `<div class="${itemClass}"><span class="slip-label">${safeText(col)}</span>${valHtml}</div>`;
            });
            html += '</div>';
        });

        const shareToken = (meta.share_token || (typeof HR_PAYROLL_SHARE_TOKEN === 'string' ? HR_PAYROLL_SHARE_TOKEN : '') || '').toString();
        const shareEnabled = meta.share_enabled !== false && shareToken !== '';
        const shareExpiresAt = Number(meta.share_expires_at || 0);
        const shareExpiryLabel = formatExpiryLabel(shareExpiresAt);
        const shareUrl = shareEnabled
            ? `${window.location.origin}${window.location.pathname}?page=payroll_result&share=${encodeURIComponent(shareToken)}`
            : '';

        html += `</div>
            <div class="data-card-footer">
                <div class="timestamp">
                    <span>Truy vấn lúc: ${safeText(timeStr)}</span>
                    ${shareExpiryLabel ? `<span class="share-expiry">Link chia sẻ hết hạn: ${safeText(shareExpiryLabel)}</span>` : ''}
                </div>
                <div class="data-card-actions">
                    <a class="btn-secondary" href="index.php">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
                        Tra cứu kỳ khác
                    </a>
                    <button class="btn-print" type="button" onclick="window.print()">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                        In / Lưu PDF
                    </button>
                    ${shareUrl ? `
                    <button class="btn-secondary" id="copyShareBtn" type="button">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                        </svg>
                        Sao chép link
                    </button>` : ''}
                </div>
            </div>
        </div>`;

        res.innerHTML = html;
        if (shareUrl) {
            const copyBtn = document.getElementById('copyShareBtn');
            if (copyBtn) {
                copyBtn.addEventListener('click', async () => {
                    try {
                        await navigator.clipboard.writeText(shareUrl);
                        copyBtn.textContent = 'Đã sao chép';
                        setTimeout(() => { copyBtn.textContent = 'Sao chép link'; }, 1600);
                    } catch (_e) {
                        alert('Không thể sao chép tự động. Link chia sẻ:\n' + shareUrl);
                    }
                });
            }
        }
    }

    async function loadResultPayload() {
        const fd = new FormData();
        fd.append('action', 'get_payroll_result');
        if (typeof HR_PAYROLL_SHARE_TOKEN === 'string' && HR_PAYROLL_SHARE_TOKEN.trim() !== '') {
            fd.append('share_token', HR_PAYROLL_SHARE_TOKEN.trim());
        }

        const resp = await fetch('index.php', { method: 'POST', body: fd });
        if (!resp.ok) throw new Error('Lỗi máy chủ: ' + resp.status);
        const json = await resp.json();
        if (!json.success || !json.payload) throw new Error(json.message || 'Không có dữ liệu kết quả.');
        return json;
    }

    loadResultPayload()
        .then((data) => render(data.payload, data))
        .catch((err) => {
            showInfo(escapeHtml(err.message || 'Không thể tải kết quả. Vui lòng tra cứu lại.'));
        });
})();
