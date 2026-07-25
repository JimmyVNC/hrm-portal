// ============================================================
// INTERNAL HRM PORTAL - ENTERPRISE DASHBOARD JS (V2)
// ============================================================

// ===== Tag Input System =====
function renderTags(container) {
    const wrapper = container.closest('.tag-input-wrapper');
    const hiddenInput = wrapper.querySelector('.real-cols-input');
    const tagInput = wrapper.querySelector('.tag-input');

    container.querySelectorAll('.tag-chip').forEach(el => el.remove());

    const val = hiddenInput.value.trim();
    if (val) {
        const tags = val.split(',').map(s => s.trim()).filter(Boolean);
        tags.forEach((tag, idx) => {
            const chip = document.createElement('span');
            chip.className = 'tag-chip';
            chip.innerHTML = `<span style="pointer-events:none">${tag}</span> <span class="tag-chip-remove" onclick="removeTag(this, ${idx})">×</span>`;
            chip.setAttribute('draggable', 'true');
            chip.addEventListener('dragstart', (e) => {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', tag);
                setTimeout(() => chip.classList.add('dragging'), 0);
            });
            chip.addEventListener('dragend', () => {
                chip.classList.remove('dragging');
                const chips = Array.from(container.querySelectorAll('.tag-chip'));
                const newTags = chips.map(c => c.textContent.replace('×', '').trim());
                hiddenInput.value = newTags.join(', ');
                renderTags(container);
            });
            container.insertBefore(chip, tagInput);
        });
    }
    makeContainerSortable(container);
}

function makeContainerSortable(container) {
    if (container.dataset.initSort) return;
    container.dataset.initSort = '1';
    container.addEventListener('dragover', e => {
        e.preventDefault();
        const draggable = document.querySelector('.dragging');
        if (!draggable) return;
        const afterElement = getDragAfterElement(container, e.clientX);
        container.insertBefore(draggable, afterElement === null ? container.querySelector('.tag-input') : afterElement);
    });
}

function getDragAfterElement(container, x) {
    const items = [...container.querySelectorAll('.tag-chip:not(.dragging)')];
    return items.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = x - box.left - box.width / 2;
        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        }
        return closest;
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

function handleTagInput(e) {
    if (e.key === ',' || e.key === 'Enter') {
        e.preventDefault();
        const val = e.target.value.trim().replace(/,/g, '');
        if (val) {
            const wrapper = e.target.closest('.tag-input-wrapper');
            const hiddenInput = wrapper.querySelector('.real-cols-input');
            const tags = (hiddenInput.value.trim() ? hiddenInput.value.split(',').map(s => s.trim()) : []);
            if (!tags.includes(val)) {
                tags.push(val);
                hiddenInput.value = tags.join(', ');
                renderTags(e.target.parentElement);
            }
            e.target.value = '';
        }
    } else if (e.key === 'Backspace' && e.target.value === '') {
        const hiddenInput = e.target.closest('.tag-input-wrapper').querySelector('.real-cols-input');
        const tags = hiddenInput.value.split(',').map(s => s.trim()).filter(Boolean);
        tags.pop();
        hiddenInput.value = tags.join(', ');
        renderTags(e.target.parentElement);
    }
}

function removeTag(btn, idx) {
    const hiddenInput = btn.closest('.tag-input-wrapper').querySelector('.real-cols-input');
    const tags = hiddenInput.value.split(',').map(s => s.trim()).filter(Boolean);
    tags.splice(idx, 1);
    hiddenInput.value = tags.join(', ');
    renderTags(btn.closest('.tag-container'));
}

// ===== Period Row Interactions =====
function togglePeriodCard(headerEl) {
    const row = headerEl.closest('.period-row');
    const isExpanded = row.classList.contains('expanded');
    
    // Collapse others
    document.querySelectorAll('.period-row.expanded').forEach(el => {
        if (el !== row) el.classList.remove('expanded');
    });

    if (isExpanded) {
        row.classList.remove('expanded');
    } else {
        row.classList.add('expanded');
        if (typeof lucide !== 'undefined') lucide.createIcons();
        row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

function updateCompactLabel(input) {
    const row = input.closest('.period-row');
    const labelText = row.querySelector('.period-title');
    labelText.textContent = input.value.trim() || 'Kỳ chưa đặt tên';
}

function updateCompactSource(select) {
    const row = select.closest('.period-row');
    const tag = row.querySelector('.period-tag');
    const isLocal = select.value === 'local';
    
    tag.className = `period-tag ${isLocal ? 'local' : 'google'}`;
    tag.innerHTML = `<i data-lucide="${isLocal ? 'database' : 'globe'}"></i> ${isLocal ? 'Local' : 'Google'}`;
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function syncPeriodEnabled(input) {
    const row = input.closest('.period-row');
    const hidden = row.querySelector('.period-enabled-hidden');
    const status = row.querySelector('.status-indicator');
    const labelText = input.closest('.period-toggle-row')?.querySelector('span');
    const enabled = !!input.checked;

    if (hidden) hidden.value = enabled ? '1' : '0';
    if (status) {
        status.className = `status-indicator ${enabled ? 'active' : 'inactive'}`;
        status.innerHTML = `<span class="status-dot"></span> ${enabled ? 'Đang bật' : 'Đang tắt'}`;
    }
    if (labelText) {
        labelText.textContent = enabled
            ? 'Đang bật cho nhân viên tra cứu'
            : 'Đang tắt, nhân viên không xem được';
    }
}

function getPeriodRowHTML() {
    const rowId = 'new_' + Math.random().toString(36).substring(2, 11) + '_' + Date.now();
    return `<div class="period-row">
        <input type="hidden" name="period_ids[]" value="${rowId}">
        <div class="period-header" onclick="togglePeriodCard(this)">
            <div class="period-meta">
                <i data-lucide="calendar" class="text-muted"></i>
                <span class="period-title">Kỳ lương mới</span>
                <span class="period-tag local">
                    <i data-lucide="database"></i> Local
                </span>
                <span class="period-tag google period-sheet-tag" style="display:none">
                    <i data-lucide="layers-3"></i> Sheet #0
                </span>
                <div class="status-indicator active">
                    <span class="status-dot"></span> Đang bật
                </div>
            </div>
            <div class="period-row-actions">
                <button type="button" class="btn-file-delete" style="padding:4px;" onclick="event.stopPropagation(); this.closest('.period-row').remove()">
                    <i data-lucide="trash-2" style="width:16px;height:16px"></i>
                </button>
                <i data-lucide="chevron-down" class="period-chevron"></i>
            </div>
        </div>
        <div class="period-body">
            <div class="field-grid-2">
                <div class="field-group">
                    <label class="field-label">Tên hiển thị</label>
                    <input type="text" name="period_labels[]" class="field-input" placeholder="VD: Tháng 10/2026" oninput="updateCompactLabel(this)">
                </div>
                <div class="field-group">
                    <label class="field-label">Ngày xuất bản</label>
                    <input type="datetime-local" name="period_publish_dates[]" class="field-input">
                </div>
            </div>
            <div class="field-group">
                <label class="field-label">Trạng thái kỳ lương</label>
                <label class="period-toggle-row">
                    <input type="checkbox" class="period-enabled-toggle" checked onchange="syncPeriodEnabled(this)">
                    <input type="hidden" name="period_enableds[]" class="period-enabled-hidden" value="1">
                    <span>Đang bật cho nhân viên tra cứu</span>
                </label>
            </div>
            <div class="field-group">
                <label class="field-label">Nguồn dữ liệu</label>
                <select name="period_source_types[]" class="field-input" onchange="toggleSourceType(this); updateCompactSource(this)">
                    <option value="local" selected>Tải lên Excel (Local)</option>
                    <option value="google">Google Sheets</option>
                </select>
            </div>
            
            <div class="source-google" style="display:none;">
                <div class="field-group">
                    <label class="field-label">Link Spreadsheet</label>
                    <input type="text" class="field-input" placeholder="Dán link..." oninput="parseSheetLink(this, 'period')">
                </div>
                <div class="field-grid-2">
                    <div class="field-group">
                        <label class="field-label">Sheet ID</label>
                        <input type="text" name="period_sheet_ids[]" class="field-input mono">
                    </div>
                    <div class="field-group">
                        <label class="field-label">GID</label>
                        <input type="text" name="period_gids[]" class="field-input mono" value="0">
                    </div>
                </div>
            </div>
            
            <div class="source-local">
                <div class="field-grid-2">
                    <div class="field-group">
                        <label class="field-label">Tệp Excel</label>
                        <select name="period_local_files[]" class="field-input period-local-file-select" onchange="inspectPeriodLocalSheets(this)">
                            <option value="">Chọn file đã tải lên</option>
                        </select>
                        <input type="file" name="period_file_${rowId}" class="field-input period-file-input" accept=".csv, .xlsx" onchange="inspectPeriodLocalSheets(this)">
                    </div>
                    <div class="field-group">
                        <label class="field-label">Sheet dữ liệu</label>
                        <select name="period_sheet_indexes[]" class="field-input period-sheet-select" data-selected-index="0">
                            <option value="0">Sheet #0</option>
                        </select>
                        <input type="hidden" name="period_sheet_names[]" class="period-sheet-name-input" value="">
                        <div class="field-help-text period-sheet-hint">Có thể dùng cùng một file cho nhiều kỳ và chọn sheet khác nhau.</div>
                    </div>
                </div>
            </div>
            
            <div class="field-group">
                <label class="field-label">Cột hiển thị (Tất cả)</label>
                <div class="tag-input-wrapper">
                    <input type="hidden" name="period_cols[]" class="real-cols-input" value="">
                    <div class="tag-container" onclick="this.querySelector('.tag-input').focus()">
                        <input type="text" class="tag-input" onkeydown="handleTagInput(event)">
                    </div>
                </div>
            </div>
            <div class="field-grid-2">
                <div class="field-group">
                    <label class="field-label">Cột nổi bật (Pills)</label>
                    <div class="tag-input-wrapper">
                        <input type="hidden" name="period_highlight_cols[]" class="real-cols-input" value="">
                        <div class="tag-container" onclick="this.querySelector('.tag-input').focus()">
                            <input type="text" class="tag-input" onkeydown="handleTagInput(event)">
                        </div>
                    </div>
                </div>
                <div class="field-group">
                    <label class="field-label">Cột Định dạng Tiền (VND)</label>
                    <div class="tag-input-wrapper">
                        <input type="hidden" name="period_money_cols[]" class="real-cols-input" value="">
                        <div class="tag-container" onclick="this.querySelector('.tag-input').focus()">
                            <input type="text" class="tag-input" onkeydown="handleTagInput(event)">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>`;
}

function populateSheetSelect(selectEl, sheets, selectedIndex) {
    if (!selectEl) return;
    const normalizedSelected = Number.isFinite(Number(selectedIndex)) ? Number(selectedIndex) : 0;
    const options = Array.isArray(sheets) && sheets.length
        ? sheets
        : [{ index: 0, name: 'Sheet 1' }];

    selectEl.innerHTML = options.map(sheet => {
        const idx = Number(sheet.index) || 0;
        const label = sheet.name || `Sheet ${idx + 1}`;
        const rows = Number(sheet.rows) || 0;
        const cols = Number(sheet.cols) || 0;
        const selected = idx === normalizedSelected ? 'selected' : '';
        return `<option value="${idx}" data-sheet-name="${escAttr(label)}" ${selected}>${escHtml(label)} (sheet ${idx}, ${rows}x${cols})</option>`;
    }).join('');
    syncPeriodSheetMeta(selectEl);
}

function syncPeriodSheetMeta(selectEl) {
    if (!selectEl) return;
    const row = selectEl.closest('.period-row');
    const hiddenNameInput = row?.querySelector('.period-sheet-name-input');
    const selectedOption = selectEl.options[selectEl.selectedIndex];
    if (hiddenNameInput) {
        hiddenNameInput.value = selectedOption ? (selectedOption.dataset.sheetName || '') : '';
    }
    const sourceSelect = row?.querySelector('select[name="period_source_types[]"]');
    const sheetTag = row?.querySelector('.period-sheet-tag');
    if (sheetTag) {
        if (sourceSelect?.value === 'local' && selectedOption) {
            sheetTag.style.display = '';
            sheetTag.innerHTML = `<i data-lucide="layers-3"></i> ${escHtml(selectedOption.dataset.sheetName || selectedOption.textContent.trim())}`;
        } else {
            sheetTag.style.display = 'none';
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

function inspectPeriodLocalSheets(triggerEl, isAutoLoad = false) {
    const row = triggerEl.closest('.period-row');
    if (!row) return;

    const fileInput = row.querySelector('.period-file-input');
    const existingFileSelect = row.querySelector('.period-local-file-select');
    const sheetSelect = row.querySelector('.period-sheet-select');
    const selectedIndex = parseInt(sheetSelect?.dataset.selectedIndex || sheetSelect?.value || '0', 10) || 0;

    if (!sheetSelect) return;

    const fd = new FormData();
    fd.append('ajax_action', 'inspect_period_file_sheets');
    if (window.HR_CSRF_TOKEN) fd.append('csrf_token', window.HR_CSRF_TOKEN);
    if (existingFileSelect && existingFileSelect.value) {
        fd.append('existing_file', existingFileSelect.value);
    }
    if (fileInput && fileInput.files && fileInput.files[0]) {
        fd.append('period_file', fileInput.files[0]);
    }

    if ((!existingFileSelect || !existingFileSelect.value) && (!fileInput || !fileInput.files || !fileInput.files[0])) {
        populateSheetSelect(sheetSelect, [{ index: 0, name: 'Sheet 1' }], 0);
        return;
    }

    sheetSelect.disabled = true;
    fetch('admin.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (!data.ok) {
                if (!isAutoLoad) {
                    alert('Không đọc được danh sách sheet: ' + data.message);
                } else {
                    console.warn('Auto-load sheet failed:', data.message);
                    const hint = row.querySelector('.period-sheet-hint');
                    if (hint) {
                        hint.innerHTML = `<span style="color:var(--danger)">Lỗi: ${data.message}</span>`;
                    }
                }
                populateSheetSelect(sheetSelect, [{ index: selectedIndex, name: `Sheet #${selectedIndex}` }], selectedIndex);
                return;
            }
            populateSheetSelect(sheetSelect, data.sheets || [], selectedIndex);
            sheetSelect.dataset.selectedIndex = sheetSelect.value || String(selectedIndex);
        })
        .catch(() => {
            alert('Lỗi kết nối khi đọc danh sách sheet.');
            populateSheetSelect(sheetSelect, [{ index: selectedIndex, name: `Sheet #${selectedIndex}` }], selectedIndex);
        })
        .finally(() => {
            sheetSelect.disabled = false;
        });
}

function addPeriod() {
    const list = document.querySelector('.period-list');
    list.insertAdjacentHTML('afterbegin', getPeriodRowHTML());
    const newRow = list.firstElementChild;
    newRow.classList.add('expanded');

    // Initialize tags for the new row
    newRow.querySelectorAll('.tag-container').forEach(c => renderTags(c));
    if (window.HR_LOCAL_FILES_OPTIONS_HTML) {
        const selectEl = newRow.querySelector('.period-local-file-select');
        if (selectEl) {
            selectEl.innerHTML = window.HR_LOCAL_FILES_OPTIONS_HTML;
        }
    }
    inspectPeriodLocalSheets(newRow.querySelector('.period-local-file-select'), true);
    
    if (typeof lucide !== 'undefined') lucide.createIcons();
    newRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function toggleSourceType(selectEl) {
    const row = selectEl.closest('.period-row') || selectEl.closest('.settings-group');
    const isLocal = selectEl.value === 'local';
    row.querySelectorAll('.source-local').forEach(el => el.style.display = isLocal ? 'block' : 'none');
    row.querySelectorAll('.source-google').forEach(el => el.style.display = isLocal ? 'none' : 'block');
    const sheetTag = row.querySelector('.period-sheet-tag');
    if (sheetTag) {
        sheetTag.style.display = isLocal ? '' : 'none';
    }
    if (isLocal) {
        const fileSelect = row.querySelector('.period-local-file-select');
        if (fileSelect) inspectPeriodLocalSheets(fileSelect, true);
    }
}

// ===== Sheet Link Parser =====
function parseSheetLink(input, type) {
    const url = input.value.trim();
    if (!url) return;
    const idMatch = url.match(/\/d\/([a-zA-Z0-9-_]+)/);
    const gidMatch = url.match(/[#&]gid=([0-9]+)/);
    if (idMatch) {
        const sheetId = idMatch[1];
        const gid = gidMatch ? gidMatch[1] : '0';
        if (type === 'auth') {
            document.querySelector('input[name="auth_sheet_id"]').value = sheetId;
            document.querySelector('input[name="auth_gid"]').value = gid;
        } else {
            const row = input.closest('.period-row');
            row.querySelector('input[name="period_sheet_ids[]"]').value = sheetId;
            row.querySelector('input[name="period_gids[]"]').value = gid;
        }
        input.style.borderColor = 'var(--success)';
        input.value = '✅ Đã trích xuất xong!';
        setTimeout(() => { input.style.borderColor = ''; input.value = ''; }, 1500);
    }
}

// ===== Tab System =====
function switchTab(tabId) {
    document.querySelectorAll('.admin-menu-item').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
    const triggers = document.querySelectorAll(`.admin-menu-item[data-target="${tabId}"]`);
    const pane = document.getElementById(tabId);
    if (triggers.length > 0 && pane) {
        triggers.forEach((btn) => btn.classList.add('active'));
        pane.classList.add('active');
        sessionStorage.setItem('hr_admin_active_tab', tabId);
        const headerTitle = document.getElementById('admin-header-title');
        const triggerLabel = triggers[0].getAttribute('data-label');
        if (headerTitle) {
            const defaultTitle = headerTitle.getAttribute('data-default-title') || 'Hệ thống Quản trị';
            headerTitle.textContent = triggerLabel ? `${defaultTitle} • ${triggerLabel}` : defaultTitle;
        }
        if (window.innerWidth <= 991) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
            const sidebar = document.querySelector('.admin-sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            if (sidebar) sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('visible');
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

// ===== Initialization & Previews =====
document.addEventListener('DOMContentLoaded', () => {
    if (typeof lucide !== 'undefined') lucide.createIcons();
    document.querySelectorAll('.tag-container').forEach(c => renderTags(c));

    // Login UX helpers
    const adminPassInput = document.getElementById('admin-pass-input');
    const toggleAdminPassBtn = document.getElementById('toggle-admin-pass');
    const capsLockWarning = document.getElementById('capslock-warning');

    // Password show/hide toggle (độc lập với CapsLock)
    if (adminPassInput && toggleAdminPassBtn) {
        toggleAdminPassBtn.addEventListener('click', () => {
            const isPassword = adminPassInput.type === 'password';
            adminPassInput.type = isPassword ? 'text' : 'password';
            const icon = toggleAdminPassBtn.querySelector('i');
            if (icon) icon.setAttribute('data-lucide', isPassword ? 'eye-off' : 'eye');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
    }

    // CapsLock detection — chỉ keydown/keyup mới có getModifierState chính xác
    // focus/blur KHÔNG có getModifierState nên không dùng để detect caps
    if (adminPassInput && capsLockWarning) {
        const setCapsWarning = (visible) => {
            capsLockWarning.hidden = !visible;
        };

        const handleCaps = (e) => {
            if (typeof e.getModifierState === 'function') {
                setCapsWarning(e.getModifierState('CapsLock'));
            }
        };

        adminPassInput.addEventListener('keydown', handleCaps);
        adminPassInput.addEventListener('keyup', handleCaps);

        // Khi blur: ẩn warning
        adminPassInput.addEventListener('blur', () => setCapsWarning(false));

        // Khi focus: reset ẩn — warning sẽ hiện ngay khi gõ phím đầu tiên
        adminPassInput.addEventListener('focus', () => setCapsWarning(false));
    }
    
    // UI Previews
    const companyInput = document.querySelector('input[name="site_company"]');
    const logoInput = document.querySelector('input[name="site_logo_text"]');
    if (companyInput && logoInput) {
        const updateP = () => {
            const cp = document.getElementById('preview-company');
            const lg = document.getElementById('preview-logo');
            if (cp) cp.textContent = companyInput.value.trim() || 'TÊN CÔNG TY';
            if (lg) lg.textContent = (logoInput.value.trim() || 'HR').toUpperCase();
        };
        companyInput.addEventListener('input', updateP);
        logoInput.addEventListener('input', updateP);
    }

    // Mobile Sidebar Drawer setup
    const sidebarToggleBtn = document.getElementById('admin-sidebar-toggle-btn');
    const sidebarCloseBtn = document.getElementById('sidebar-close-btn');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    const sidebar = document.querySelector('.admin-sidebar');

    if (sidebarToggleBtn && sidebar && sidebarOverlay) {
        sidebarToggleBtn.addEventListener('click', () => {
            sidebar.classList.add('open');
            sidebarOverlay.classList.add('visible');
        });
    }

    const closeSidebar = () => {
        if (sidebar) sidebar.classList.remove('open');
        if (sidebarOverlay) sidebarOverlay.classList.remove('visible');
    };

    if (sidebarCloseBtn) sidebarCloseBtn.addEventListener('click', closeSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);

    // Segmented control setup for auth source type selector
    const authSegments = document.querySelectorAll('#auth-source-type-segments .segment-btn');
    const authSelect = document.getElementById('auth-source-type-select');
    if (authSegments.length && authSelect) {
        authSegments.forEach(btn => {
            btn.addEventListener('click', () => {
                authSegments.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                authSelect.value = btn.dataset.value;
                authSelect.dispatchEvent(new Event('change'));
            });
        });
    }

    const savedTab = sessionStorage.getItem('hr_admin_active_tab');
    if (savedTab) switchTab(savedTab);

    document.querySelectorAll('.period-sheet-select').forEach(selectEl => {
        selectEl.addEventListener('change', () => {
            selectEl.dataset.selectedIndex = selectEl.value || '0';
            syncPeriodSheetMeta(selectEl);
        });
        syncPeriodSheetMeta(selectEl);
    });
    document.querySelectorAll('.period-row').forEach(row => {
        const localSelect = row.querySelector('.period-local-file-select');
        if (localSelect) inspectPeriodLocalSheets(localSelect, true);
    });
});

// ===== File Management =====
function deleteUploadedFile(filename, btn) {
    if (!confirm(`Xác nhận xóa file "${filename}"?`)) return;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('ajax_action', 'delete_file');
    fd.append('filename', filename);
    if (window.HR_CSRF_TOKEN) fd.append('csrf_token', window.HR_CSRF_TOKEN);
    
    fetch('admin.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            const card = btn.closest('.file-card-v2');
            card.style.opacity = '0';
            card.style.transform = 'scale(0.9)';
            setTimeout(() => card.remove(), 300);
        } else {
            alert('Lỗi: ' + data.message);
            btn.disabled = false;
        }
    })
    .catch(() => {
        alert('Lỗi kết nối máy chủ.');
        btn.disabled = false;
    });
}

// ============================================================
// UPLOAD PROGRESS UI — shared helpers
// ============================================================

function formatUploadBytes(bytes) {
    if (!bytes || bytes <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let size = bytes;
    while (size >= 1024 && i < units.length - 1) {
        size /= 1024;
        i++;
    }
    return (i === 0 ? size : size.toFixed(1)) + ' ' + units[i];
}

function setUploadStages(container, stage) {
    if (!container) return;
    const order = ['upload', 'process', 'done'];
    const activeIdx = order.indexOf(stage);
    const stages = container.querySelectorAll('.upload-stage');
    const lines = container.querySelectorAll('.upload-stage-line');
    stages.forEach((el, idx) => {
        el.classList.remove('upload-stage--active', 'upload-stage--done');
        if (idx < activeIdx) el.classList.add('upload-stage--done');
        else if (idx === activeIdx) el.classList.add('upload-stage--active');
    });
    lines.forEach((el, idx) => {
        el.classList.toggle('is-done', idx < activeIdx);
    });
}

function updateUploadProgressUI(refs, state) {
    const { status, speed, stage, title } = state;
    const percent = Math.max(0, Math.min(100, Math.round(Number(state.percent) || 0)));
    if (refs.bar) {
        refs.bar.style.width = percent + '%';
        refs.bar.classList.toggle('is-animating', stage === 'process' && percent >= 100);
    }
    if (refs.percent) refs.percent.textContent = percent + '%';
    if (refs.status && status) refs.status.textContent = status;
    if (refs.speed) refs.speed.textContent = speed ? formatUploadBytes(speed) + '/s' : '';
    if (refs.title && title) refs.title.textContent = title;
    if (refs.stages && stage) setUploadStages(refs.stages, stage);
}

function startSmoothSubmitProgress(overlay, options = {}) {
    const hasFiles = !!options.hasFiles;
    const startedAt = Date.now();
    let currentPercent = hasFiles ? 10 : 24;
    const checkpoints = hasFiles
        ? [
            { after: 250, percent: 22, stage: 'upload', status: 'Đang nén dữ liệu biểu mẫu...' },
            { after: 900, percent: 48, stage: 'upload', status: 'Đang tải tệp lên máy chủ...' },
            { after: 1800, percent: 72, stage: 'process', status: 'Máy chủ đang kiểm tra và lưu cấu hình...' },
            { after: 3200, percent: 88, stage: 'process', status: 'Đang hoàn tất ghi dữ liệu...' },
        ]
        : [
            { after: 180, percent: 38, stage: 'process', status: 'Đang gửi cấu hình lên máy chủ...' },
            { after: 650, percent: 62, stage: 'process', status: 'Đang kiểm tra dữ liệu cấu hình...' },
            { after: 1400, percent: 82, stage: 'process', status: 'Đang ghi cấu hình...' },
            { after: 2600, percent: 92, stage: 'process', status: 'Sắp hoàn tất...' },
        ];

    overlay.update({
        percent: currentPercent,
        stage: hasFiles ? 'upload' : 'process',
        status: hasFiles ? 'Đang chuẩn bị tải tệp...' : 'Đang chuẩn bị lưu cấu hình...',
    });

    const timers = checkpoints.map((checkpoint) => window.setTimeout(() => {
        currentPercent = Math.max(currentPercent, checkpoint.percent);
        overlay.update(checkpoint);
    }, checkpoint.after));

    const driftTimer = window.setInterval(() => {
        const elapsed = Date.now() - startedAt;
        const ceiling = elapsed > 6500 ? 97 : elapsed > 3800 ? 95 : 90;
        if (currentPercent < ceiling) {
            currentPercent += currentPercent < 70 ? 2 : 1;
            overlay.update({
                percent: currentPercent,
                stage: currentPercent > 64 ? 'process' : (hasFiles ? 'upload' : 'process'),
            });
        }
    }, 420);

    return () => {
        timers.forEach(window.clearTimeout);
        window.clearInterval(driftTimer);
    };
}

function xhrUploadWithProgress(url, formData, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        const startTime = Date.now();

        xhr.upload.onprogress = function(event) {
            if (!event.lengthComputable) return;
            const uploadComplete = event.loaded >= event.total;
            const percent = uploadComplete ? 99 : Math.round((event.loaded / event.total) * 100);
            const elapsed = (Date.now() - startTime) / 1000;
            const speed = elapsed > 0 ? event.loaded / elapsed : 0;
            const stage = uploadComplete ? 'process' : 'upload';
            onProgress({
                percent,
                loaded: event.loaded,
                total: event.total,
                speed,
                stage,
                status: !uploadComplete
                    ? 'Đang tải lên ' + formatUploadBytes(event.loaded) + ' / ' + formatUploadBytes(event.total)
                    : 'Dữ liệu đã tải lên, đang xử lý trên máy chủ...',
            });
        };

        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 300) {
                onProgress({
                    percent: 100,
                    stage: 'done',
                    status: 'Hoàn tất!',
                    speed: 0,
                });
                try {
                    resolve(JSON.parse(xhr.responseText));
                } catch {
                    resolve(xhr.responseText);
                }
            } else {
                reject(new Error('Mã lỗi phản hồi: ' + xhr.status));
            }
        };

        xhr.onerror = function() {
            reject(new Error('Lỗi kết nối máy chủ.'));
        };

        xhr.open('POST', url, true);
        xhr.send(formData);
    });
}

function createUploadOverlay(options) {
    const overlay = document.createElement('div');
    overlay.className = 'upload-overlay';
    overlay.id = options.id || 'upload-overlay';

    const safeTitle = (options.title || 'Đang tải lên...').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const safeFileName = options.fileName
        ? options.fileName.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        : '';

    const fileLine = safeFileName
        ? `<p class="upload-modal-file"><i data-lucide="file-spreadsheet"></i> <span>${safeFileName}</span>${options.fileSize ? `<span class="upload-modal-file-size">(${formatUploadBytes(options.fileSize)})</span>` : ''}</p>`
        : '';

    overlay.innerHTML = `
        <div class="upload-modal">
            <div class="upload-modal-icon">
                <div class="upload-modal-icon-ring"></div>
                <i data-lucide="cloud-upload"></i>
            </div>
            <h3 class="upload-modal-title">${safeTitle}</h3>
            ${fileLine}
            <div class="upload-progress-bar-wrapper upload-progress-bar-wrapper--lg">
                <div class="upload-progress-bar"></div>
            </div>
            <div class="upload-progress-info upload-progress-info--modal">
                <span class="upload-progress-percent">0%</span>
                <span class="upload-progress-status">Đang chuẩn bị...</span>
            </div>
            <div class="upload-stage-list">
                <div class="upload-stage upload-stage--active" data-stage="upload"><span class="upload-stage-dot"></span>Tải lên</div>
                <div class="upload-stage-line"></div>
                <div class="upload-stage" data-stage="process"><span class="upload-stage-dot"></span>Xử lý</div>
                <div class="upload-stage-line"></div>
                <div class="upload-stage" data-stage="done"><span class="upload-stage-dot"></span>Hoàn tất</div>
            </div>
            <p class="upload-modal-speed"></p>
        </div>`;

    document.body.appendChild(overlay);
    if (typeof lucide !== 'undefined') lucide.createIcons();

    const modal = overlay.querySelector('.upload-modal');
    return {
        el: overlay,
        refs: {
            bar: modal.querySelector('.upload-progress-bar'),
            percent: modal.querySelector('.upload-progress-percent'),
            status: modal.querySelector('.upload-progress-status'),
            speed: modal.querySelector('.upload-modal-speed'),
            title: modal.querySelector('.upload-modal-title'),
            stages: modal.querySelector('.upload-stage-list'),
        },
        update(state) {
            updateUploadProgressUI(this.refs, state);
        },
        remove() {
            overlay.remove();
        },
    };
}

// ============================================================
// AUTH FILE MANAGER — Upload / Preview / Editor / Save
// ============================================================

let _pendingAuthDuplicateResolution = null;

// --- Upload ---
function onAuthFileSelected(input) {
    const labelText = document.getElementById('auth-file-label-text');
    const uploadBtn = document.getElementById('auth-upload-btn');
    if (input.files && input.files[0]) {
        labelText.textContent = input.files[0].name;
        uploadBtn.disabled = false;
    } else {
        labelText.textContent = 'Chọn file .xlsx';
        uploadBtn.disabled = true;
    }
}

function initAuthUploadZoneDragDrop() {
    const zone = document.getElementById('auth-upload-zone');
    const input = document.getElementById('auth-file-input');
    if (!zone || !input) return;

    zone.addEventListener('dragover', (e) => {
        e.preventDefault();
        zone.classList.add('is-dragover');
    });
    zone.addEventListener('dragleave', (e) => {
        if (!zone.contains(e.relatedTarget)) zone.classList.remove('is-dragover');
    });
    zone.addEventListener('drop', (e) => {
        e.preventDefault();
        zone.classList.remove('is-dragover');
        const file = e.dataTransfer?.files?.[0];
        if (!file) return;
        const ext = file.name.split('.').pop().toLowerCase();
        if (ext !== 'xlsx') {
            showAuthToast('❌ Chỉ chấp nhận file .xlsx', 'error');
            return;
        }
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        onAuthFileSelected(input);
    });
}

function showAuthUploadProgress(file) {
    const zone = document.getElementById('auth-upload-zone');
    const container = document.getElementById('auth-upload-progress-container');
    const fileNameEl = document.getElementById('auth-upload-file-name');
    const fileSizeEl = document.getElementById('auth-upload-file-size');

    if (zone) zone.classList.add('is-uploading');
    if (container) container.hidden = false;
    if (fileNameEl) fileNameEl.textContent = file.name;
    if (fileSizeEl) fileSizeEl.textContent = formatUploadBytes(file.size);

    const refs = {
        bar: document.getElementById('auth-upload-progress-bar'),
        percent: document.getElementById('auth-upload-progress-text'),
        status: document.getElementById('auth-upload-progress-status'),
        speed: document.getElementById('auth-upload-speed'),
        stages: document.getElementById('auth-upload-stages'),
    };

    updateUploadProgressUI(refs, {
        percent: 0,
        status: 'Đang chuẩn bị tải lên...',
        speed: 0,
        stage: 'upload',
    });

    if (typeof lucide !== 'undefined') lucide.createIcons();

    return {
        refs,
        hide() {
            if (zone) zone.classList.remove('is-uploading');
            if (container) container.hidden = true;
        },
        update(state) {
            updateUploadProgressUI(refs, state);
        },
    };
}

function uploadAuthFile() {
    const input   = document.getElementById('auth-file-input');
    const btn     = document.getElementById('auth-upload-btn');
    const btnSpan = btn.querySelector('span');
    if (!input || !input.files[0]) return;

    const file = input.files[0];
    const fd = new FormData();
    fd.append('ajax_action', 'upload_auth_file');
    fd.append('auth_file', file);
    if (window.HR_CSRF_TOKEN) fd.append('csrf_token', window.HR_CSRF_TOKEN);

    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    if (btnSpan) btnSpan.textContent = 'Đang tải...';

    const progressUI = showAuthUploadProgress(file);

    xhrUploadWithProgress('admin.php', fd, (state) => {
        progressUI.update(state);
    })
        .then(data => {
            btn.disabled = false;
            btn.setAttribute('aria-busy', 'false');
            if (btnSpan) btnSpan.textContent = 'Tải lên';
            progressUI.hide();

            if (data.ok && data.requires_resolution) {
                openAuthDuplicateModal(data);
                input.value = '';
                document.getElementById('auth-file-label-text').textContent = 'Chọn file .xlsx';
                btn.disabled = true;
                return;
            }

            if (data.ok) {
                applyAuthUploadSuccess(data);
            } else {
                showAuthToast('❌ ' + data.message, 'error');
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.setAttribute('aria-busy', 'false');
            if (btnSpan) btnSpan.textContent = 'Tải lên';
            progressUI.hide();
            showAuthToast('❌ Tải lên thất bại. ' + err.message, 'error');
        });
}

function applyAuthUploadSuccess(data) {
    showAuthToast('✅ ' + data.message + (data.backup ? ' (Backup: ' + data.backup + ')' : ''), 'success');
    const bannerEl = document.getElementById('auth-file-banner');
    if (bannerEl) {
        bannerEl.innerHTML = `
            <div class="auth-file-info">
                <i data-lucide="file-spreadsheet" class="auth-file-icon"></i>
                <div>
                    <div class="auth-file-name" id="auth-current-filename">${data.filename}</div>
                    <div class="auth-file-meta">File xác thực nhân sự đang hoạt động</div>
                </div>
            </div>
            <div class="auth-file-actions">
                <a id="auth-download-btn" href="admin.php?action=download_auth_file&csrf_token=${encodeURIComponent(window.HR_CSRF_TOKEN)}" class="btn btn-sm btn-outline-secondary"><i data-lucide="download"></i> Tải xuống</a>
            </div>`;
    }
    const hidden = document.getElementById('auth-local-file-hidden');
    if (hidden) hidden.value = 'uploads/' + data.filename;
    const input = document.getElementById('auth-file-input');
    if (input) input.value = '';
    const label = document.getElementById('auth-file-label-text');
    if (label) label.textContent = 'Chọn file .xlsx';
    const btn = document.getElementById('auth-upload-btn');
    if (btn) btn.disabled = true;
    loadAuthPreview();
    loadAuthEditorData();
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function openAuthDuplicateModal(data) {
    _pendingAuthDuplicateResolution = data;
    const modal = document.getElementById('auth-duplicate-modal');
    const body = document.getElementById('auth-duplicate-body');
    const subtitle = document.getElementById('auth-duplicate-subtitle');
    const errorEl = document.getElementById('auth-duplicate-error');
    if (!modal || !body || !subtitle) return;

    if (errorEl) {
        errorEl.style.display = 'none';
        errorEl.textContent = '';
    }

    const groups = Array.isArray(data.duplicate_groups) ? data.duplicate_groups : [];
    subtitle.textContent = `Có ${groups.length} mã nhân viên bị trùng. Khuyến nghị mặc định là xóa dòng đầu và giữ dòng cuối, nhưng bạn vẫn có thể đổi lại trước khi import.`;
    body.innerHTML = groups.map((group, groupIdx) => buildAuthDuplicateGroupHtml(group, groupIdx, data.headers || [])).join('');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    updateAuthDuplicateActionState('delete-first');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function buildAuthDuplicateGroupHtml(group, groupIdx, headers) {
    const normalizedHeaders = headers.map(h => String(h || '').replace(/\s+/g, ' ').trim().toUpperCase());
    const passIdx = normalizedHeaders.findIndex(h => h === 'MẬT KHẨU');
    const nameIdx = normalizedHeaders.findIndex(h => h.includes('HỌ TÊN') || h.includes('HO TEN') || h.includes('TÊN') || h.includes('TEN'));
    const deptIdx = normalizedHeaders.findIndex(h => h.includes('BỘ PHẬN') || h.includes('BO PHAN'));
    const rows = Array.isArray(group.rows) ? group.rows : [];
    const rowNames = rows.map(row => {
        const values = Array.isArray(row.values) ? row.values : [];
        return String(nameIdx >= 0 ? (values[nameIdx] || '') : '').trim();
    }).filter(Boolean);
    const rowPasswords = rows.map(row => {
        const values = Array.isArray(row.values) ? row.values : [];
        return String(passIdx >= 0 ? (values[passIdx] || '') : '').trim();
    });
    const hasDifferentNames = new Set(rowNames.map(v => v.toLowerCase())).size > 1;
    const hasDifferentPasswords = new Set(rowPasswords.filter(Boolean)).size > 1;
    const conflictBadges = [];
    if (hasDifferentNames) conflictBadges.push('<span class="auth-duplicate-badge auth-duplicate-badge--danger">Khác tên</span>');
    if (hasDifferentPasswords) conflictBadges.push('<span class="auth-duplicate-badge auth-duplicate-badge--warning">Khác mật khẩu</span>');
    if (!conflictBadges.length) conflictBadges.push('<span class="auth-duplicate-badge">Trùng Mã NV</span>');

    const rowHtml = rows.map((row, idx) => {
        const values = Array.isArray(row.values) ? row.values : [];
        const name = nameIdx >= 0 ? String(values[nameIdx] || '') : '';
        const dept = deptIdx >= 0 ? String(values[deptIdx] || '') : '';
        const pwd = passIdx >= 0 ? String(values[passIdx] || '') : '';
        const maskedPwd = pwd ? '••••••••' : '(trống)';
        const isFirst = idx === 0;
        const isLast = idx === rows.length - 1;
        const checked = isLast ? '' : 'checked';
        const rowBadges = [];
        if (isFirst) rowBadges.push('<span class="auth-duplicate-chip auth-duplicate-chip--neutral">Dòng đầu</span>');
        if (isLast) rowBadges.push('<span class="auth-duplicate-chip auth-duplicate-chip--accent">Dòng cuối</span>');
        if (hasDifferentNames) rowBadges.push('<span class="auth-duplicate-chip auth-duplicate-chip--danger">Tên khác nhóm</span>');
        if (hasDifferentPasswords) rowBadges.push('<span class="auth-duplicate-chip auth-duplicate-chip--warning">Mật khẩu khác nhóm</span>');
        return `
            <label class="auth-duplicate-option">
                <input type="checkbox" name="auth-duplicate-group-${groupIdx}" value="${row.row_index}" ${checked}>
                <div class="auth-duplicate-option-body">
                    <div class="auth-duplicate-option-top">
                        <strong>Dòng Excel ${row.source_row_number}</strong>
                        <span>Mã NV: ${escHtml(group.emp_id || '')}</span>
                    </div>
                    <div class="auth-duplicate-option-badges">${rowBadges.join('')}</div>
                    <div class="auth-duplicate-option-meta">
                        <span>Tên: ${escHtml(name || '(trống)')}</span>
                        <span>Bộ phận: ${escHtml(dept || '(trống)')}</span>
                        <span>Mật khẩu: ${maskedPwd}</span>
                    </div>
                    <div class="auth-duplicate-option-note">${isLast ? 'Đang là dòng được giữ mặc định nếu bạn không đánh dấu xóa.' : 'Đang được đánh dấu xóa mặc định để tránh giữ nhầm dữ liệu cũ.'}</div>
                </div>
            </label>
        `;
    }).join('');

    return `
        <section class="auth-duplicate-group">
            <div class="auth-duplicate-group-head">
                <div class="auth-duplicate-group-title">
                    <h4>Mã NV ${escHtml(group.emp_id || '')}</h4>
                    <div class="auth-duplicate-group-badges">${conflictBadges.join('')}</div>
                </div>
                <span>${rows.length} dòng trùng</span>
            </div>
            <div class="auth-duplicate-options">${rowHtml}</div>
        </section>
    `;
}

function cancelAuthDuplicateResolution() {
    const token = _pendingAuthDuplicateResolution?.resolution_token || '';
    closeAuthDuplicateModal();
    if (!token) return;

    const fd = new FormData();
    fd.append('ajax_action', 'discard_auth_upload_duplicates');
    fd.append('resolution_token', token);
    if (window.HR_CSRF_TOKEN) fd.append('csrf_token', window.HR_CSRF_TOKEN);
    fetch('admin.php', { method: 'POST', body: fd }).catch(() => {});
}

function closeAuthDuplicateModal() {
    const modal = document.getElementById('auth-duplicate-modal');
    const errorEl = document.getElementById('auth-duplicate-error');
    const confirmBtn = document.getElementById('auth-duplicate-confirm-btn');
    if (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }
    if (errorEl) {
        errorEl.style.display = 'none';
        errorEl.textContent = '';
    }
    if (confirmBtn) {
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Xóa các dòng đã đánh dấu và import';
    }
    document.body.classList.remove('modal-open');
    _pendingAuthDuplicateResolution = null;
}

function updateAuthDuplicateActionState(mode) {
    const statusEl = document.getElementById('auth-duplicate-live-status');
    const deleteFirstBtn = document.getElementById('auth-duplicate-delete-first-btn');
    const deleteLastBtn = document.getElementById('auth-duplicate-delete-last-btn');
    const isDeleteFirst = mode === 'delete-first';

    if (statusEl) {
        statusEl.innerHTML = isDeleteFirst
            ? '<i data-lucide="badge-info"></i><span>Mặc định hiện tại: <strong>đang giữ dòng cuối</strong> cho mỗi Mã NV trùng.</span>'
            : '<i data-lucide="badge-info"></i><span>Tùy chọn hiện tại: <strong>đang giữ dòng đầu</strong> cho mỗi Mã NV trùng.</span>';
    }

    if (deleteFirstBtn) {
        deleteFirstBtn.classList.toggle('is-active', isDeleteFirst);
    }
    if (deleteLastBtn) {
        deleteLastBtn.classList.toggle('is-active', !isDeleteFirst);
    }

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function confirmAuthDuplicateResolution() {
    const resolution = _pendingAuthDuplicateResolution;
    const confirmBtn = document.getElementById('auth-duplicate-confirm-btn');
    const errorEl = document.getElementById('auth-duplicate-error');
    if (!resolution) return;

    const groups = Array.isArray(resolution.duplicate_groups) ? resolution.duplicate_groups : [];
    const keepRowIndexes = [];
    for (let i = 0; i < groups.length; i++) {
        const selected = Array.from(document.querySelectorAll(`input[name="auth-duplicate-group-${i}"]`));
        const keepCandidates = selected.filter(el => !el.checked);
        if (keepCandidates.length !== 1) {
            if (errorEl) {
                errorEl.textContent = 'Mỗi mã nhân viên trùng phải để lại đúng 1 dòng chưa đánh dấu xóa.';
                errorEl.style.display = 'block';
            }
            return;
        }
        keepRowIndexes.push(keepCandidates[0].value);
    }

    if (errorEl) {
        errorEl.style.display = 'none';
        errorEl.textContent = '';
    }
    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Đang xóa và import...';
    }

    const fd = new FormData();
    fd.append('ajax_action', 'resolve_auth_upload_duplicates');
    fd.append('resolution_token', resolution.resolution_token || '');
    keepRowIndexes.forEach(idx => fd.append('keep_row_indexes[]', idx));
    if (window.HR_CSRF_TOKEN) fd.append('csrf_token', window.HR_CSRF_TOKEN);

    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                closeAuthDuplicateModal();
                applyAuthUploadSuccess(data);
                return;
            }
            if (errorEl) {
                errorEl.textContent = data.message || 'Không thể import file sau khi xử lý trùng.';
                errorEl.style.display = 'block';
            }
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Xóa các dòng đã đánh dấu và import';
            }
        })
        .catch(() => {
            if (errorEl) {
                errorEl.textContent = 'Lỗi kết nối máy chủ khi import file.';
                errorEl.style.display = 'block';
            }
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Xóa các dòng đã đánh dấu và import';
            }
        });
}

function _setDuplicateGroupDeleteMode(groupIndex, mode) {
    const inputs = Array.from(document.querySelectorAll(`input[name="auth-duplicate-group-${groupIndex}"]`));
    if (!inputs.length) return;

    inputs.forEach((input, idx) => {
        const isFirst = idx === 0;
        const isLast = idx === (inputs.length - 1);
        if (mode === 'delete-first') {
            input.checked = isFirst;
            return;
        }
        if (mode === 'delete-last') {
            input.checked = isLast;
            return;
        }
    });
}

function markDeleteFirstRowsForAllDuplicates() {
    const groups = Array.isArray(_pendingAuthDuplicateResolution?.duplicate_groups)
        ? _pendingAuthDuplicateResolution.duplicate_groups
        : [];
    groups.forEach((_, index) => _setDuplicateGroupDeleteMode(index, 'delete-first'));
    updateAuthDuplicateActionState('delete-first');
}

function markDeleteLastRowsForAllDuplicates() {
    const groups = Array.isArray(_pendingAuthDuplicateResolution?.duplicate_groups)
        ? _pendingAuthDuplicateResolution.duplicate_groups
        : [];
    groups.forEach((_, index) => _setDuplicateGroupDeleteMode(index, 'delete-last'));
    updateAuthDuplicateActionState('delete-last');
}

// --- Preview ---
function loadAuthPreview() {
    const fd = new FormData();
    fd.append('ajax_action', 'preview_auth_file');
    if (window.HR_CSRF_TOKEN) fd.append('csrf_token', window.HR_CSRF_TOKEN);

    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const panel = document.getElementById('auth-preview-panel');
            const tableWrap = document.getElementById('auth-preview-table');
            if (!panel || !tableWrap) return;
            if (data.ok) {
                panel.style.display = 'block';
                tableWrap.innerHTML = buildPreviewTable(data.headers || [], data.rows || []);
            }
        })
        .catch(() => {});
}

function buildPreviewTable(headers, rows) {
    if (!headers.length) return '<p class="auth-preview-empty">Không có dữ liệu.</p>';
    let html = '<table class="auth-preview-tbl"><thead><tr>';
    headers.forEach(h => { html += `<th>${escHtml(h)}</th>`; });
    html += '</tr></thead><tbody>';
    rows.forEach(row => {
        html += '<tr>';
        row.forEach(cell => { html += `<td>${escHtml(String(cell ?? ''))}</td>`; });
        html += '</tr>';
    });
    html += '</tbody></table>';
    return html;
}

// --- Editor Toggle ---
function toggleAuthEditor() {
    const panel  = document.getElementById('auth-editor-panel');
    const btn    = document.getElementById('auth-editor-toggle-btn');
    if (!panel) return;
    const isOpen = panel.style.display !== 'none';
    if (isOpen) {
        panel.style.display = 'none';
        panel.setAttribute('aria-hidden', 'true');
        if (btn) { btn.setAttribute('aria-expanded', 'false'); }
    } else {
        panel.style.display = 'block';
        panel.setAttribute('aria-hidden', 'false');
        if (btn) { btn.setAttribute('aria-expanded', 'true'); }
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        // Reset search khi mở editor
        const searchInput = document.getElementById('auth-search-input');
        if (searchInput) searchInput.value = '';
        _authSearchQuery = '';
        const clearBtn = document.getElementById('auth-search-clear');
        if (clearBtn) clearBtn.style.display = 'none';
        const resultEl = document.getElementById('auth-search-result');
        if (resultEl) { resultEl.textContent = ''; resultEl.className = 'auth-search-result'; }
        loadAuthEditorData();
    }
}

// --- Load Editor Data ---
let _authHeaders = [];
let _passwdColIdx = -1;

function loadAuthEditorData() {
    const loading  = document.getElementById('auth-editor-loading');
    const tableWrap = document.getElementById('auth-editor-table-wrap');
    const warningEl = document.getElementById('auth-editor-warning');

    if (loading) loading.style.display = 'flex';
    if (tableWrap) tableWrap.style.display = 'none';
    if (warningEl) warningEl.style.display = 'none';

    const fd = new FormData();
    fd.append('ajax_action', 'get_auth_data');
    if (window.HR_CSRF_TOKEN) fd.append('csrf_token', window.HR_CSRF_TOKEN);

    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (loading) loading.style.display = 'none';
            if (!data.ok) {
                showAuthEditorToast('❌ ' + data.message, 'error');
                return;
            }
            if (data.warning && warningEl) {
                warningEl.textContent = '⚠️ ' + data.warning;
                warningEl.style.display = 'block';
            }
            _authHeaders = data.headers || [];
            _passwdColIdx = _authHeaders.findIndex(h =>
                h.replace(/\s+/g, ' ').trim().toUpperCase() === 'MẬT KHẨU'
            );
            renderAuthEditorGrid(_authHeaders, data.rows || []);
            updateAuthEditorCount();
            validateQuickAddEmpIdRealtime();
            if (tableWrap) tableWrap.style.display = 'block';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        })
        .catch(err => {
            if (loading) loading.style.display = 'none';
            showAuthEditorToast('❌ Lỗi kết nối máy chủ.', 'error');
        });
}

// --- Render Editable Grid ---
function renderAuthEditorGrid(headers, rows) {
    const thead = document.getElementById('auth-editor-thead');
    const tbody = document.getElementById('auth-editor-tbody');
    if (!thead || !tbody) return;

    // Header row (không edit được)
    let headHtml = '<tr><th class="auth-col-del"></th>';
    headers.forEach((h, i) => {
        const isPasswd = i === _passwdColIdx;
        headHtml += `<th>${escHtml(h)}${isPasswd ? ' <button type="button" class="auth-passwd-toggle-all" onclick="toggleAllPasswords(this)" title="Hiện/ẩn tất cả mật khẩu"><i data-lucide="eye"></i></button>' : ''}</th>`;
    });
    headHtml += '</tr>';
    thead.innerHTML = headHtml;

    // Data rows
    tbody.innerHTML = '';
    rows.forEach((row, rIdx) => {
        tbody.appendChild(buildEditorRow(row, rIdx));
    });
}

function buildEditorRow(rowData, rIdx) {
    const tr = document.createElement('tr');
    tr.dataset.rowIdx = rIdx;

    // Delete button
    const delTd = document.createElement('td');
    delTd.className = 'auth-col-del';
    delTd.innerHTML = `<button type="button" class="auth-del-row-btn" onclick="deleteAuthEditorRow(this)" title="Xóa dòng này"><i data-lucide="trash-2"></i></button>`;
    tr.appendChild(delTd);

    _authHeaders.forEach((h, colIdx) => {
        const td = document.createElement('td');
        const isPasswd = colIdx === _passwdColIdx;
        const cellVal  = String(rowData[colIdx] ?? '');

        if (isPasswd) {
            // Masked password field
            td.innerHTML = `
                <div class="auth-passwd-cell">
                    <input type="password" class="auth-cell-input auth-passwd-input" value="${escAttr(cellVal)}" autocomplete="off" placeholder="•••••••">
                    <button type="button" class="auth-passwd-eye" onclick="togglePasswordCell(this)" title="Hiện/ẩn" tabindex="-1"><i data-lucide="eye"></i></button>
                </div>`;
        } else {
            td.innerHTML = `<input type="text" class="auth-cell-input" value="${escAttr(cellVal)}" autocomplete="off">`;
        }
        tr.appendChild(td);
    });

    return tr;
}

function togglePasswordCell(btn) {
    const input = btn.closest('.auth-passwd-cell').querySelector('input');
    const icon  = btn.querySelector('i');
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
    if (icon) icon.setAttribute('data-lucide', input.type === 'password' ? 'eye' : 'eye-off');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function toggleAllPasswords(btn) {
    const icon   = btn.querySelector('i');
    const tbody  = document.getElementById('auth-editor-tbody');
    const inputs = tbody.querySelectorAll('.auth-passwd-input');
    const showAll = inputs.length > 0 && inputs[0].type === 'password';
    inputs.forEach(inp => { inp.type = showAll ? 'text' : 'password'; });
    if (icon) icon.setAttribute('data-lucide', showAll ? 'eye-off' : 'eye');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

// --- Add / Delete Row ---
function addAuthEditorRow() {
    const tbody = document.getElementById('auth-editor-tbody');
    if (!tbody) return;
    const emptyRow = new Array(_authHeaders.length).fill('');
    const rIdx = tbody.rows.length;
    const tr = buildEditorRow(emptyRow, rIdx);
    tbody.appendChild(tr);
    if (typeof lucide !== 'undefined') lucide.createIcons();
    // Focus first input
    const firstInput = tr.querySelector('.auth-cell-input');
    if (firstInput) firstInput.focus();
    updateAuthEditorCount();
}

function quickAddEmployeeRow() {
    const idEl = document.getElementById('qa-emp-id');
    const nameEl = document.getElementById('qa-emp-name');
    const passEl = document.getElementById('qa-emp-pass');
    const deptEl = document.getElementById('qa-emp-dept');
    if (!idEl || !passEl) return;

    const empId = idEl.value.trim();
    const empName = nameEl ? nameEl.value.trim() : '';
    const empPass = passEl.value;
    const empDept = deptEl ? deptEl.value.trim() : '';

    if (!empId) {
        showAuthEditorToast('❌ Vui lòng nhập Mã NV.', 'error');
        idEl.focus();
        return;
    }
    if (isQuickAddEmpIdDuplicate(empId)) {
        showAuthEditorToast('❌ Mã NV đã tồn tại trong bảng.', 'error');
        idEl.focus();
        return;
    }
    if (!empPass.trim()) {
        showAuthEditorToast('❌ Vui lòng nhập Mật khẩu.', 'error');
        passEl.focus();
        return;
    }

    const tbody = document.getElementById('auth-editor-tbody');
    if (!tbody || !_authHeaders.length) {
        showAuthEditorToast('❌ Bảng dữ liệu chưa sẵn sàng.', 'error');
        return;
    }

    const row = new Array(_authHeaders.length).fill('');
    const findIdx = (keyword) => _authHeaders.findIndex(h => h.replace(/\s+/g, ' ').trim().toUpperCase().includes(keyword));
    const idIdx = _authHeaders.findIndex(h => h.replace(/\s+/g, ' ').trim().toUpperCase() === 'MÃ NV');
    const nameIdx = findIdx('HỌ TÊN');
    const deptIdx = findIdx('BỘ PHẬN');
    const passIdx = _passwdColIdx;

    if (idIdx >= 0) row[idIdx] = empId;
    if (nameIdx >= 0) row[nameIdx] = empName;
    if (deptIdx >= 0) row[deptIdx] = empDept;
    if (passIdx >= 0) row[passIdx] = empPass;

    const tr = buildEditorRow(row, tbody.rows.length);
    tbody.appendChild(tr);
    if (typeof lucide !== 'undefined') lucide.createIcons();
    updateAuthEditorCount();

    idEl.value = '';
    if (nameEl) nameEl.value = '';
    passEl.value = '';
    if (deptEl) deptEl.value = '';
    idEl.focus();
    showAuthEditorToast('✅ Đã thêm nhân viên vào bảng (chưa lưu file).', 'success');
    validateQuickAddEmpIdRealtime();
}

function deleteAuthEditorRow(btn) {
    const tr = btn.closest('tr');
    if (!tr) return;
    tr.style.opacity = '0';
    tr.style.transition = 'opacity 0.2s';
    setTimeout(() => { tr.remove(); updateAuthEditorCount(); }, 200);
}

// --- Search / Filter ---

/**
 * Xác định index cột Mã NV và Tên NV (hoặc các cột có thể tìm kiếm)
 * để lọc dữ liệu chính xác.
 */
function _getSearchableColIndexes() {
    const result = [];
    _authHeaders.forEach((h, i) => {
        const norm = h.replace(/\s+/g, ' ').trim().toUpperCase();
        // Tìm kiếm trong cột Mã NV, Tên NV, Họ Tên, Tên, và cột không phải mật khẩu đầu tiên
        if (
            norm.includes('MÃ NV') || norm.includes('MA NV') ||
            norm.includes('TÊN') || norm.includes('TEN') ||
            norm.includes('HỌ') || norm.includes('HO ') ||
            norm.includes('NHÂN VIÊN') || norm.includes('NHAN VIEN') ||
            norm.includes('FULL') || norm.includes('NAME')
        ) {
            result.push(i);
        }
    });
    // Nếu không tìm thấy cột rõ ràng, tìm kiếm trong tất cả cột (trừ mật khẩu)
    if (result.length === 0) {
        _authHeaders.forEach((h, i) => {
            if (i !== _passwdColIdx) result.push(i);
        });
    }
    return result;
}

let _authSearchQuery = '';

function getAuthSearchFieldIndexes() {
    const indexes = { empId: -1, name: -1, department: -1, password: -1 };
    _authHeaders.forEach((header, idx) => {
        const norm = String(header || '').replace(/\s+/g, ' ').trim().toUpperCase();
        if (indexes.empId === -1 && (norm === 'MÃ NV' || norm === 'MA NV' || norm.includes('MÃ NV') || norm.includes('MA NV'))) indexes.empId = idx;
        if (indexes.name === -1 && (norm.includes('HỌ TÊN') || norm.includes('HO TEN') || norm.includes('TÊN') || norm.includes('TEN') || norm.includes('NAME'))) indexes.name = idx;
        if (indexes.department === -1 && (norm.includes('BỘ PHẬN') || norm.includes('BO PHAN') || norm.includes('PHÒNG BAN') || norm.includes('PHONG BAN') || norm.includes('DEPARTMENT'))) indexes.department = idx;
        if (indexes.password === -1 && (norm === 'MẬT KHẨU' || norm === 'MAT KHAU' || norm.includes('MẬT KHẨU') || norm.includes('MAT KHAU'))) indexes.password = idx;
    });
    return indexes;
}

function focusAuthEditorRow(rowIndex) {
    const tbody = document.getElementById('auth-editor-tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.rows);
    const tr = rows[rowIndex];
    if (!tr) return;
    tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    tr.classList.add('auth-row-flash');
    const firstInput = tr.querySelector('.auth-cell-input');
    if (firstInput) firstInput.focus();
    setTimeout(() => tr.classList.remove('auth-row-flash'), 1200);
}

function renderAuthSearchMobileCards(matches) {
    const container = document.getElementById('auth-search-mobile-results');
    if (!container) return;
    if (!Array.isArray(matches) || matches.length === 0 || !_authSearchQuery) {
        container.style.display = 'none';
        container.innerHTML = '';
        return;
    }

    container.style.display = 'grid';
    container.innerHTML = matches.slice(0, 8).map((item) => `
        <button type="button" class="auth-search-card" onclick="focusAuthEditorRow(${item.rowIndex})">
            <div class="auth-search-card__top">
                <strong>${escHtml(item.name || 'Chưa có tên')}</strong>
                <span>Dòng ${escHtml(String(item.rowIndex + 1))}</span>
            </div>
            <div class="auth-search-card__grid">
                <div><span>Mã NV</span><strong>${escHtml(item.empId || '(trống)')}</strong></div>
                <div><span>Mật khẩu</span><strong>${escHtml(item.password || '(trống)')}</strong></div>
                <div><span>Bộ phận</span><strong>${escHtml(item.department || '(trống)')}</strong></div>
            </div>
        </button>
    `).join('');
}

function filterAuthEditorRows(query) {
    _authSearchQuery = query.trim();
    const tbody       = document.getElementById('auth-editor-tbody');
    const resultEl    = document.getElementById('auth-search-result');
    const clearBtn    = document.getElementById('auth-search-clear');
    if (!tbody) return;

    // Hiện/ẩn nút xóa
    if (clearBtn) clearBtn.style.display = _authSearchQuery ? 'flex' : 'none';

    const rows = Array.from(tbody.rows);

    if (!_authSearchQuery) {
        // Không có query — hiện tất cả, bỏ highlight
        rows.forEach(tr => {
            tr.style.display = '';
            tr.querySelectorAll('.auth-cell-input').forEach(inp => {
                inp.style.removeProperty('background');
                inp.style.removeProperty('outline');
                inp.style.removeProperty('outline-offset');
            });
        });
        if (resultEl) resultEl.textContent = '';
        renderAuthSearchMobileCards([]);
        updateAuthEditorCount();
        return;
    }

    const searchableCols = _getSearchableColIndexes();
    const fieldIndexes = getAuthSearchFieldIndexes();
    const queryLower = _authSearchQuery.toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '');

    let matchCount = 0;
    const mobileMatches = [];

    rows.forEach((tr, rowIndex) => {
        const inputs = tr.querySelectorAll('.auth-cell-input');
        let matched = false;

        // Reset highlight trước
        inputs.forEach(inp => {
            inp.style.removeProperty('background');
            inp.style.removeProperty('outline');
            inp.style.removeProperty('outline-offset');
        });

        searchableCols.forEach(colIdx => {
            // inputs có offset +1 vì cột đầu là nút xóa (td.auth-col-del)
            // nhưng querySelectorAll chỉ lấy input, không lấy button nên index đúng
            const inp = inputs[colIdx];
            if (!inp) return;
            const val = inp.value.toLowerCase()
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            if (val.includes(queryLower)) {
                matched = true;
                // Highlight ô khớp
                inp.style.background = 'var(--auth-search-highlight, rgba(250, 204, 21, 0.25))';
                inp.style.outline = '2px solid var(--auth-search-highlight-border, #facc15)';
                inp.style.outlineOffset = '-2px';
            }
        });

        tr.style.display = matched ? '' : 'none';
        if (matched) {
            matchCount++;
            mobileMatches.push({
                rowIndex,
                empId: fieldIndexes.empId >= 0 && inputs[fieldIndexes.empId] ? inputs[fieldIndexes.empId].value : '',
                name: fieldIndexes.name >= 0 && inputs[fieldIndexes.name] ? inputs[fieldIndexes.name].value : '',
                department: fieldIndexes.department >= 0 && inputs[fieldIndexes.department] ? inputs[fieldIndexes.department].value : '',
                password: fieldIndexes.password >= 0 && inputs[fieldIndexes.password] ? inputs[fieldIndexes.password].value : ''
            });
        }
    });

    if (resultEl) {
        if (matchCount === 0) {
            resultEl.textContent = 'Không tìm thấy kết quả';
            resultEl.className = 'auth-search-result auth-search-result--empty';
        } else {
            resultEl.textContent = `Tìm thấy ${matchCount} kết quả`;
            resultEl.className = 'auth-search-result auth-search-result--found';
        }
    }

    renderAuthSearchMobileCards(mobileMatches);
}

function clearAuthSearch() {
    const input = document.getElementById('auth-search-input');
    if (input) {
        input.value = '';
        input.focus();
    }
    filterAuthEditorRows('');
}

function updateAuthEditorCount() {
    const tbody = document.getElementById('auth-editor-tbody');
    const countEl = document.getElementById('auth-editor-count');
    if (!tbody || !countEl) return;
    const total = tbody.rows.length;
    // Nếu đang search, reset highlight khi thêm row
    if (_authSearchQuery) {
        filterAuthEditorRows(_authSearchQuery);
    }
    countEl.textContent = total + ' nhân viên';
}

// --- Collect & Save ---
function saveAuthData() {
    const tbody   = document.getElementById('auth-editor-tbody');
    const saveBtn = document.getElementById('auth-save-btn');
    const saveLbl = document.getElementById('auth-save-btn-label');
    if (!tbody) return;

    // Collect rows
    const rows = [];
    let hasError = false;
    const empIdColIdx = _authHeaders.findIndex(h =>
        h.replace(/\s+/g, ' ').trim().toUpperCase() === 'MÃ NV'
    );

    Array.from(tbody.rows).forEach((tr, rIdx) => {
        const inputs = tr.querySelectorAll('.auth-cell-input');
        const row = [];
        inputs.forEach((inp, colIdx) => {
            const val = inp.value;
            // Client-side formula injection guard
            if (val.length > 0 && ['=', '+', '-', '@'].includes(val[0])) {
                inp.style.borderColor = 'var(--error, #ef4444)';
                inp.title = 'Ô không được bắt đầu bằng =, +, -, @';
                hasError = true;
            } else {
                inp.style.borderColor = '';
                inp.title = '';
            }
            // Client-side empty MÃ NV guard
            if (colIdx === empIdColIdx && val.trim() === '') {
                inp.style.borderColor = 'var(--error, #ef4444)';
                hasError = true;
            }
            row.push(val);
        });
        rows.push(row);
    });

    if (hasError) {
        showAuthEditorToast('❌ Vui lòng kiểm tra các ô được đánh dấu đỏ.', 'error');
        return;
    }
    if (rows.length === 0) {
        showAuthEditorToast('❌ Không có dòng dữ liệu nào.', 'error');
        return;
    }

    // Large data warning
    if (rows.length > 500) {
        if (!confirm(`Sắp lưu ${rows.length} nhân viên. Có thể mất vài giây. Tiếp tục?`)) return;
    }

    // Set loading state
    if (saveBtn) { saveBtn.disabled = true; saveBtn.setAttribute('aria-busy', 'true'); }
    if (saveLbl) saveLbl.textContent = 'Đang lưu...';

    const payload = { headers: _authHeaders, rows: rows };
    const csrfToken = window.HR_CSRF_TOKEN || '';

    fetch('admin.php?ajax_action=save_auth_data&csrf_token=' + encodeURIComponent(csrfToken), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.setAttribute('aria-busy', 'false'); }
        if (saveLbl) saveLbl.textContent = 'Lưu vào Excel';
        if (data.ok) {
            showAuthEditorToast(`✅ ${data.message}${data.backup ? ' | Backup: ' + data.backup : ''}`, 'success');
            // Update download link
            const dlBtn = document.getElementById('auth-download-btn');
            if (dlBtn) dlBtn.href = `admin.php?action=download_auth_file&csrf_token=${encodeURIComponent(window.HR_CSRF_TOKEN)}`;
            // Update filename banner
            const fnEl = document.getElementById('auth-current-filename');
            if (fnEl && data.filename) fnEl.textContent = data.filename;
            updateAuthEditorCount();
        } else {
            showAuthEditorToast('❌ ' + data.message, 'error');
        }
    })
    .catch(() => {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.setAttribute('aria-busy', 'false'); }
        if (saveLbl) saveLbl.textContent = 'Lưu vào Excel';
        showAuthEditorToast('❌ Lỗi kết nối máy chủ.', 'error');
    });
}

// --- Toast Helpers ---
let _authToastTimer = null;
function showAuthToast(msg, type) {
    const toast = document.getElementById('auth-toast');
    if (!toast) return;
    toast.className = `auth-toast auth-toast--${type}`;
    toast.textContent = msg;
    toast.style.display = 'block';
    clearTimeout(_authToastTimer);
    _authToastTimer = setTimeout(() => { toast.style.display = 'none'; }, 6000);
}

let _editorToastTimer = null;
function showAuthEditorToast(msg, type) {
    const toast = document.getElementById('auth-editor-toast');
    if (!toast) return;
    toast.className = `auth-toast-inline auth-toast-inline--${type}`;
    toast.textContent = msg;
    clearTimeout(_editorToastTimer);
    _editorToastTimer = setTimeout(() => { toast.textContent = ''; toast.className = 'auth-toast-inline'; }, 7000);
}

// --- Escape helpers ---
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

let _quickLookupTimer = null;
let _quickLookupSelectedEmployee = null;

function setQuickLookupActionFeedback(message, type = 'info') {
    const feedbackEl = document.getElementById('lookup-action-feedback');
    if (!feedbackEl) return;
    feedbackEl.className = `lookup-action-feedback lookup-action-feedback--${type}`;
    feedbackEl.textContent = message || '';
}

function updateQuickLookupActionButtons() {
    const hasEmployee = !!_quickLookupSelectedEmployee;
    ['lookup-fill-test-btn', 'lookup-check-login-btn', 'lookup-payroll-btn'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.disabled = !hasEmployee;
    });
}

function renderQuickLookupEmptyCard() {
    const cardEl = document.getElementById('lookup-employee-card');
    if (!cardEl) return;
    cardEl.className = 'lookup-employee-card is-empty';
    cardEl.innerHTML = `
        <div class="lookup-empty-state">
            <i data-lucide="user-search"></i>
            <div>
                <strong>Chưa chọn nhân viên</strong>
                <span>Chọn một gợi ý để xem card chi tiết và bật các thao tác nhanh.</span>
            </div>
        </div>`;
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function renderQuickLookupSelectedEmployee(employee) {
    const cardEl = document.getElementById('lookup-employee-card');
    if (!cardEl || !employee) return;

    const passwordLabels = {
        hashed: { text: 'Mật khẩu đang được băm', cls: 'safe' },
        plain: { text: 'Mật khẩu đang ở dạng thường', cls: 'warning' },
        empty: { text: 'Chưa có mật khẩu', cls: 'muted' }
    };
    const passwordMeta = passwordLabels[employee.password_mode] || passwordLabels.empty;

    cardEl.className = 'lookup-employee-card';
    cardEl.innerHTML = `
        <div class="lookup-card-top">
            <div>
                <div class="lookup-card-name">${escHtml(employee.name || 'Chưa có tên')}</div>
                <div class="lookup-card-id">Mã NV: <strong>${escHtml(employee.emp_id_display || employee.emp_id || '')}</strong></div>
            </div>
            <div class="lookup-card-badges">
                <span class="lookup-badge lookup-badge--primary">${escHtml(employee.department || 'Chưa có bộ phận')}</span>
                <span class="lookup-badge lookup-badge--${passwordMeta.cls}">${escHtml(passwordMeta.text)}</span>
            </div>
        </div>
        <div class="lookup-card-grid">
            <div class="lookup-card-field">
                <span>Dòng dữ liệu</span>
                <strong>${escHtml(String(employee.source_row_number || '-'))}</strong>
            </div>
            <div class="lookup-card-field">
                <span>Loại kiểm tra</span>
                <strong>Tra cứu nhanh trong admin</strong>
            </div>
        </div>
        <div class="lookup-card-note">
            <i data-lucide="info"></i>
            <span>Khuyến nghị: bấm <strong>Điền vào form test nhanh</strong> trước, rồi nhập mật khẩu nhân viên cung cấp để kiểm tra đúng luồng thực tế.</span>
        </div>`;
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function renderQuickLookupSuggestions(employees, query) {
    const listEl = document.getElementById('lookup-suggestion-list');
    const statusEl = document.getElementById('lookup-search-status');
    if (!listEl || !statusEl) return;

    if (!Array.isArray(employees) || employees.length === 0) {
        listEl.innerHTML = `
            <div class="lookup-suggestion-empty">
                <i data-lucide="search-x"></i>
                <span>Không tìm thấy nhân viên phù hợp với "${escHtml(query)}".</span>
            </div>`;
        statusEl.textContent = 'Không có kết quả phù hợp.';
        if (typeof lucide !== 'undefined') lucide.createIcons();
        return;
    }

    listEl.innerHTML = employees.map((employee) => `
        <button type="button" class="lookup-suggestion-item" onclick="selectQuickLookupEmployee('${escAttr(employee.emp_id_display || employee.emp_id || '')}')">
            <div class="lookup-suggestion-main">
                <strong>${escHtml(employee.emp_id_display || employee.emp_id || '')}</strong>
                <span>${escHtml(employee.name || 'Chưa có tên')}</span>
            </div>
            <div class="lookup-suggestion-meta">
                <span>${escHtml(employee.department || 'Chưa có bộ phận')}</span>
                <small>Dòng ${escHtml(String(employee.source_row_number || '-'))}</small>
            </div>
        </button>`).join('');
    statusEl.textContent = `Tìm thấy ${employees.length} gợi ý gần nhất.`;
}

function clearQuickLookupSearch() {
    const inputEl = document.getElementById('lookup-search-input');
    const clearBtn = document.getElementById('lookup-search-clear');
    const listEl = document.getElementById('lookup-suggestion-list');
    const statusEl = document.getElementById('lookup-search-status');
    if (inputEl) {
        inputEl.value = '';
        inputEl.focus();
    }
    if (clearBtn) clearBtn.style.display = 'none';
    if (listEl) listEl.innerHTML = '';
    if (statusEl) statusEl.textContent = '';
    _quickLookupSelectedEmployee = null;
    updateQuickLookupActionButtons();
    renderQuickLookupEmptyCard();
    setQuickLookupActionFeedback('');
    const resultEl = document.getElementById('lookup-payroll-result');
    if (resultEl) resultEl.innerHTML = '';
}

function searchQuickLookupEmployees(query) {
    const clearBtn = document.getElementById('lookup-search-clear');
    const statusEl = document.getElementById('lookup-search-status');
    const listEl = document.getElementById('lookup-suggestion-list');
    const normalizedQuery = String(query || '').trim();

    if (clearBtn) clearBtn.style.display = normalizedQuery ? 'flex' : 'none';
    if (!normalizedQuery) {
        if (statusEl) statusEl.textContent = '';
        if (listEl) listEl.innerHTML = '';
        return;
    }

    if (statusEl) statusEl.textContent = 'Đang tìm nhân viên...';
    const fd = new FormData();
    fd.append('ajax_action', 'search_auth_employee_lookup');
    fd.append('query', normalizedQuery);
    if (window.HR_CSRF_TOKEN) fd.append('csrf_token', window.HR_CSRF_TOKEN);

    fetch('admin.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (!data.ok) {
                throw new Error(data.message || 'Không thể tra cứu danh sách nhân viên.');
            }
            renderQuickLookupSuggestions(data.employees || [], normalizedQuery);
        })
        .catch((error) => {
            if (listEl) {
                listEl.innerHTML = `<div class="lookup-suggestion-empty"><i data-lucide="alert-circle"></i><span>${escHtml(error.message || 'Không thể tải danh sách gợi ý.')}</span></div>`;
            }
            if (statusEl) statusEl.textContent = 'Tra cứu tạm thời chưa thực hiện được.';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
}

function handleQuickLookupSearchInput(value) {
    clearTimeout(_quickLookupTimer);
    _quickLookupTimer = setTimeout(() => searchQuickLookupEmployees(value), 220);
}

function selectQuickLookupEmployee(empId) {
    const fd = new FormData();
    fd.append('ajax_action', 'get_auth_employee_lookup_detail');
    fd.append('emp_id', empId);
    if (window.HR_CSRF_TOKEN) fd.append('csrf_token', window.HR_CSRF_TOKEN);

    setQuickLookupActionFeedback('Đang tải card chi tiết nhân viên...', 'info');

    fetch('admin.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (!data.ok || !data.employee) {
                throw new Error(data.message || 'Không thể tải thông tin nhân viên.');
            }
            _quickLookupSelectedEmployee = data.employee;
            renderQuickLookupSelectedEmployee(data.employee);
            updateQuickLookupActionButtons();
            setQuickLookupActionFeedback('Đã sẵn sàng. Bạn có thể điền vào form test nhanh hoặc chạy kiểm tra ngay.', 'success');
        })
        .catch((error) => {
            _quickLookupSelectedEmployee = null;
            renderQuickLookupEmptyCard();
            updateQuickLookupActionButtons();
            setQuickLookupActionFeedback(error.message || 'Không thể chọn nhân viên này.', 'error');
        });
}

function fillQuickLookupTestFormFromSelected() {
    if (!_quickLookupSelectedEmployee) {
        setQuickLookupActionFeedback('Vui lòng chọn nhân viên trước.', 'error');
        return;
    }
    const idEl = document.getElementById('lookup-test-emp-id');
    const nameEl = document.getElementById('lookup-test-emp-name');
    const deptEl = document.getElementById('lookup-test-emp-dept');
    const passwordEl = document.getElementById('lookup-test-password');
    if (idEl) idEl.value = _quickLookupSelectedEmployee.emp_id_display || _quickLookupSelectedEmployee.emp_id || '';
    if (nameEl) nameEl.value = _quickLookupSelectedEmployee.name || '';
    if (deptEl) deptEl.value = _quickLookupSelectedEmployee.department || '';
    if (passwordEl) passwordEl.focus();
    setQuickLookupActionFeedback('Đã điền Mã NV, Họ tên và Bộ phận vào form test nhanh. Chỉ cần nhập mật khẩu để kiểm tra.', 'success');
}

function quickLookupVerifyLogin() {
    if (!_quickLookupSelectedEmployee) {
        setQuickLookupActionFeedback('Vui lòng chọn nhân viên trước.', 'error');
        return;
    }

    fillQuickLookupTestFormFromSelected();
    const passwordEl = document.getElementById('lookup-test-password');
    const password = passwordEl ? passwordEl.value : '';
    const fd = new FormData();
    fd.append('ajax_action', 'admin_verify_employee_login');
    fd.append('emp_id', _quickLookupSelectedEmployee.emp_id_display || _quickLookupSelectedEmployee.emp_id || '');
    fd.append('password', password);
    if (window.HR_CSRF_TOKEN) fd.append('csrf_token', window.HR_CSRF_TOKEN);

    setQuickLookupActionFeedback('Đang kiểm tra đăng nhập...', 'info');

    fetch('admin.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (!data.ok) {
                setQuickLookupActionFeedback(data.message || 'Đăng nhập không hợp lệ.', 'error');
                return;
            }
            setQuickLookupActionFeedback(data.message || 'Đăng nhập hợp lệ.', 'success');
        })
        .catch(() => setQuickLookupActionFeedback('Không thể kiểm tra đăng nhập lúc này.', 'error'));
}

function renderQuickLookupPayrollResult(data) {
    const resultEl = document.getElementById('lookup-payroll-result');
    if (!resultEl) return;

    if (!data.ok) {
        resultEl.innerHTML = '';
        return;
    }

    const rows = Array.isArray(data.rows) ? data.rows : [];
    const header = Array.isArray(data.header) ? data.header : [];
    const blocks = rows.map((row, rowIndex) => {
        const items = header.map((label, colIdx) => {
            const value = row[colIdx];
            if (String(value || '').trim() === '') return '';
            return `
                <div class="lookup-payroll-item">
                    <span>${escHtml(label || `Cột ${colIdx + 1}`)}</span>
                    <strong>${escHtml(String(value))}</strong>
                </div>`;
        }).filter(Boolean).join('');

        return `
            <div class="lookup-payroll-card">
                <div class="lookup-payroll-card-title">Bản ghi ${rowIndex + 1}</div>
                <div class="lookup-payroll-grid">${items}</div>
            </div>`;
    }).join('');

    resultEl.innerHTML = `
        <div class="lookup-payroll-header">
            <div>
                <strong>${escHtml(data.period_label || 'Kỳ lương')}</strong>
                <span>${escHtml(`Tìm thấy ${data.matched_rows || rows.length} bản ghi phù hợp`)}</span>
            </div>
            <span class="lookup-badge lookup-badge--success">Tra cứu nội bộ</span>
        </div>
        ${blocks}`;
}

function quickLookupPayroll() {
    if (!_quickLookupSelectedEmployee) {
        setQuickLookupActionFeedback('Vui lòng chọn nhân viên trước.', 'error');
        return;
    }

    fillQuickLookupTestFormFromSelected();
    const periodEl = document.getElementById('lookup-period-select');
    const periodIndex = periodEl ? periodEl.value : '';
    const fd = new FormData();
    fd.append('ajax_action', 'admin_lookup_employee_payroll');
    fd.append('emp_id', _quickLookupSelectedEmployee.emp_id_display || _quickLookupSelectedEmployee.emp_id || '');
    fd.append('period_index', periodIndex);
    if (window.HR_CSRF_TOKEN) fd.append('csrf_token', window.HR_CSRF_TOKEN);

    setQuickLookupActionFeedback('Đang tra cứu phiếu lương...', 'info');

    fetch('admin.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (!data.ok) {
                renderQuickLookupPayrollResult({ ok: false });
                setQuickLookupActionFeedback(data.message || 'Không tìm thấy phiếu lương.', 'error');
                return;
            }
            renderQuickLookupPayrollResult(data);
            setQuickLookupActionFeedback(`Đã tải phiếu lương kỳ ${data.period_label || ''}.`, 'success');
        })
        .catch(() => {
            renderQuickLookupPayrollResult({ ok: false });
            setQuickLookupActionFeedback('Không thể tra cứu phiếu lương lúc này.', 'error');
        });
}

function setAttendanceAdminFeedback(message, type) {
    const el = document.getElementById('attendance-admin-feedback');
    if (!el) return;
    el.textContent = message || '';
    el.className = 'lookup-action-feedback';
    if (type) {
        el.classList.add(`lookup-action-feedback--${type}`);
    }
}

function getAttendanceAdminFormPayload() {
    const empIdEl = document.getElementById('attendance-admin-emp-id');
    const fromDateEl = document.getElementById('attendance-admin-from-date');
    const toDateEl = document.getElementById('attendance-admin-to-date');
    const expiresAtEl = document.getElementById('attendance-admin-expires-at');

    return {
        emp_id: empIdEl ? empIdEl.value.trim() : '',
        from_date: fromDateEl ? fromDateEl.value : '',
        to_date: toDateEl ? toDateEl.value : '',
        expires_at: expiresAtEl ? expiresAtEl.value : ''
    };
}

function renderAttendanceAdminResult(state) {
    const resultEl = document.getElementById('attendance-admin-result');
    if (!resultEl) return;

    const employees = state && typeof state === 'object' && state.employees ? Object.values(state.employees) : [];
    if (!employees.length) {
        resultEl.innerHTML = '<div class="lookup-suggestion-empty"><i data-lucide="info"></i><span>Không tìm thấy dữ liệu chấm công phù hợp.</span></div>';
        if (typeof lucide !== 'undefined') lucide.createIcons();
        return;
    }

    const html = employees.map((employee) => {
        const days = employee && employee.days ? Object.entries(employee.days) : [];
        const rows = days.map(([date, times]) => `
            <tr>
                <td>${escHtml(String(date || ''))}</td>
                <td>${(Array.isArray(times) ? times : []).map((time) => `<span class="attendance-admin-chip">${escHtml(String(time || ''))}</span>`).join(' ')}</td>
            </tr>
        `).join('');

        return `
            <div class="attendance-admin-card">
                <div class="lookup-payroll-header">
                    <div>
                        <strong>${escHtml(employee.info?.name || 'Nhân viên')}</strong>
                        <span>Mã NV: ${escHtml(employee.info?.code || '')} • ${days.length} ngày có dữ liệu</span>
                    </div>
                    <span class="lookup-badge lookup-badge--success">Chấm công</span>
                </div>
                <div class="attendance-admin-table-wrap">
                    <table class="attendance-admin-table">
                        <thead><tr><th>Ngày</th><th>Giờ chấm</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </div>
        `;
    }).join('');

    resultEl.innerHTML = html;
}

function renderAttendanceAdminShareBox(data) {
    const boxEl = document.getElementById('attendance-admin-share-box');
    if (!boxEl) return;
    if (!data || !data.share_url) {
        boxEl.style.display = 'none';
        boxEl.innerHTML = '';
        return;
    }

    const expiresAt = data.share_expires_at
        ? new Date(Number(data.share_expires_at) * 1000).toLocaleString('vi-VN')
        : '';
    boxEl.style.display = 'block';
    boxEl.innerHTML = `
        <div class="attendance-share-box__header">
            <strong>Link xem chấm công đã tạo</strong>
            <span>Hết hạn: ${escHtml(expiresAt || '-')}</span>
        </div>
        <div class="attendance-share-box__row">
            <input type="text" id="attendance-admin-share-url" class="field-input mono" readonly value="${escAttr(data.share_url)}">
            <button type="button" class="btn btn-primary" onclick="copyAttendanceShareUrl()"><i data-lucide="copy"></i> Sao chép</button>
        </div>
    `;
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function copyAttendanceShareUrl() {
    const input = document.getElementById('attendance-admin-share-url');
    if (!input) return;
    input.select();
    input.setSelectionRange(0, input.value.length);
    navigator.clipboard.writeText(input.value)
        .then(() => setAttendanceAdminFeedback('Đã sao chép link chấm công.', 'success'))
        .catch(() => setAttendanceAdminFeedback('Không thể sao chép tự động. Bạn có thể copy thủ công trong ô link.', 'info'));
}

function adminLookupAttendance() {
    const payload = getAttendanceAdminFormPayload();
    const fd = new FormData();
    fd.append('ajax_action', 'admin_lookup_employee_attendance');
    fd.append('emp_id', payload.emp_id);
    fd.append('from_date', payload.from_date);
    fd.append('to_date', payload.to_date);
    if (window.HR_CSRF_TOKEN) fd.append('csrf_token', window.HR_CSRF_TOKEN);

    setAttendanceAdminFeedback('Đang tra cứu chấm công...', 'info');
    renderAttendanceAdminShareBox(null);

    fetch('admin.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (!data.ok) {
                renderAttendanceAdminResult({ employees: [] });
                setAttendanceAdminFeedback(data.message || 'Không thể tra cứu chấm công.', 'error');
                return;
            }
            renderAttendanceAdminResult(data.state || {});
            setAttendanceAdminFeedback('Đã tải dữ liệu chấm công nội bộ.', 'success');
        })
        .catch(() => {
            renderAttendanceAdminResult({ employees: [] });
            setAttendanceAdminFeedback('Không thể tra cứu chấm công lúc này.', 'error');
        });
}

function adminCreateAttendanceShare() {
    const payload = getAttendanceAdminFormPayload();
    const fd = new FormData();
    fd.append('ajax_action', 'admin_create_employee_attendance_share');
    fd.append('emp_id', payload.emp_id);
    fd.append('from_date', payload.from_date);
    fd.append('to_date', payload.to_date);
    fd.append('expires_at', payload.expires_at);
    if (window.HR_CSRF_TOKEN) fd.append('csrf_token', window.HR_CSRF_TOKEN);

    setAttendanceAdminFeedback('Đang tạo link xem chấm công...', 'info');

    fetch('admin.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (!data.ok) {
                renderAttendanceAdminShareBox(null);
                setAttendanceAdminFeedback(data.message || 'Không thể tạo link xem.', 'error');
                return;
            }
            renderAttendanceAdminResult(data.state || {});
            renderAttendanceAdminShareBox(data);
            setAttendanceAdminFeedback('Đã tạo link xem chấm công với thời điểm hết hạn đã chọn.', 'success');
        })
        .catch(() => {
            renderAttendanceAdminShareBox(null);
            setAttendanceAdminFeedback('Không thể tạo link xem lúc này.', 'error');
        });
}

function normalizeEmpIdKey(value) {
    let v = String(value || '').trim();
    v = v.replace(/^'+/, '').trim().toUpperCase();
    v = v.replace(/^0+/, '');
    return v;
}

function getAuthEmpIdColIndex() {
    return _authHeaders.findIndex(h => h.replace(/\s+/g, ' ').trim().toUpperCase() === 'MÃ NV');
}

function isQuickAddEmpIdDuplicate(empId) {
    const tbody = document.getElementById('auth-editor-tbody');
    if (!tbody) return false;
    const idIdx = getAuthEmpIdColIndex();
    if (idIdx < 0) return false;

    const targetKey = normalizeEmpIdKey(empId);
    if (!targetKey) return false;

    const rows = Array.from(tbody.rows);
    return rows.some((tr) => {
        const inputs = tr.querySelectorAll('.auth-cell-input');
        const val = inputs[idIdx] ? inputs[idIdx].value : '';
        return normalizeEmpIdKey(val) === targetKey;
    });
}

function validateQuickAddEmpIdRealtime() {
    const idEl = document.getElementById('qa-emp-id');
    const noteEl = document.getElementById('qa-emp-id-note');
    const addBtn = document.getElementById('qa-add-btn');
    if (!idEl || !noteEl || !addBtn) return;

    const val = idEl.value.trim();
    noteEl.className = 'auth-quick-add-note';
    idEl.classList.remove('input-error');
    addBtn.disabled = false;

    if (!val) {
        noteEl.textContent = '';
        return;
    }

    if (isQuickAddEmpIdDuplicate(val)) {
        noteEl.textContent = 'Mã NV đã tồn tại trong danh sách hiện tại.';
        noteEl.classList.add('error');
        idEl.classList.add('input-error');
        addBtn.disabled = true;
        return;
    }

    noteEl.textContent = 'Mã NV hợp lệ, có thể thêm mới.';
    noteEl.classList.add('success');
}

document.addEventListener('DOMContentLoaded', function () {
    const panel = document.getElementById('auth-editor-panel');
    if (panel && panel.style.display !== 'none') {
        loadAuthEditorData();
    }
    const qaIdInput = document.getElementById('qa-emp-id');
    if (qaIdInput) {
        qaIdInput.addEventListener('input', validateQuickAddEmpIdRealtime);
    }

    const lookupInput = document.getElementById('lookup-search-input');
    if (lookupInput) {
        lookupInput.addEventListener('input', (event) => handleQuickLookupSearchInput(event.target.value));
        lookupInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchQuickLookupEmployees(event.target.value);
            }
        });
        renderQuickLookupEmptyCard();
        updateQuickLookupActionButtons();
    }
    enableEmployeeAutocomplete('qa-emp-id', (person) => {
        const name = document.getElementById('qa-emp-name');
        const dept = document.getElementById('qa-emp-dept');
        if (name) name.value = person.name || '';
        if (dept) dept.value = person.department || '';
    });
    enableEmployeeAutocomplete('attendance-admin-emp-id');
    setupAdminFormSubmit();
    initAuthUploadZoneDragDrop();
});

function setupAdminFormSubmit() {
    const form = document.getElementById('admin-form');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        if (form.dataset.submitting === '1') {
            e.preventDefault();
            return;
        }

        const fileInputs = form.querySelectorAll('input[type="file"]');
        const fileList = [];
        for (const input of fileInputs) {
            if (input.files) {
                for (const f of input.files) {
                    if (f.size > 0) fileList.push(f);
                }
            }
        }
        const hasFiles = fileList.length > 0;
        const totalSize = fileList.reduce((sum, f) => sum + f.size, 0);

        let fileLabel = '';
        if (fileList.length === 1) {
            fileLabel = fileList[0].name;
        } else if (fileList.length > 1) {
            fileLabel = fileList.length + ' tệp (' + fileList.map(f => f.name).join(', ') + ')';
        }

        const overlay = createUploadOverlay({
            id: 'admin-submit-loading-overlay',
            title: hasFiles ? 'Đang tải lên tệp & lưu cấu hình' : 'Đang lưu cấu hình',
            fileName: fileLabel,
            fileSize: totalSize,
        });
        const saveBar = document.querySelector('.sticky-save-bar');
        const saveBarInfo = saveBar?.querySelector('.save-bar-info span');
        const saveBarIcon = saveBar?.querySelector('.save-bar-info i');
        const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        submitButtons.forEach((button) => {
            const label = button.querySelector('span');
            button.dataset.originalText = button.textContent.trim();
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.classList.add('is-saving');
            if (label) {
                label.textContent = hasFiles ? 'Đang tải lên...' : 'Đang lưu...';
            } else if (button.tagName === 'BUTTON') {
                button.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span>' + (hasFiles ? 'Đang tải lên...' : 'Đang lưu...');
            }
        });
        if (saveBar) saveBar.classList.add('is-saving');
        if (saveBarInfo) saveBarInfo.textContent = hasFiles ? 'Đang tải tệp và lưu cấu hình...' : 'Đang lưu cấu hình...';
        if (saveBarIcon) saveBarIcon.setAttribute('data-lucide', hasFiles ? 'cloud-upload' : 'loader-circle');
        form.dataset.submitting = '1';
        if (typeof lucide !== 'undefined') lucide.createIcons();

        startSmoothSubmitProgress(overlay, { hasFiles });
    });
}

// Reusable employee autocomplete for Admin fields that accept an employee ID.
function enableEmployeeAutocomplete(inputId, onSelect) {
    const input = document.getElementById(inputId);
    if (!input || input.dataset.employeeAutocomplete === '1') return;
    input.dataset.employeeAutocomplete = '1';
    input.autocomplete = 'off';
    const host = input.parentElement;
    host.classList.add('employee-autocomplete-host');
    const menu = document.createElement('div');
    menu.className = 'employee-autocomplete-menu';
    host.appendChild(menu);
    let timer;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        const query = input.value.trim();
        if (!query) { menu.innerHTML = ''; return; }
        timer = setTimeout(async () => {
            const body = new FormData();
            body.append('ajax_action', 'search_auth_employee_lookup');
            body.append('query', query);
            body.append('csrf_token', window.HR_CSRF_TOKEN || '');
            try {
                const response = await fetch('admin.php', { method: 'POST', body });
                const json = await response.json();
                if (!json.ok) throw new Error();
                menu.innerHTML = '';
                (json.employees || []).forEach(person => {
                    const option = document.createElement('button');
                    option.type = 'button';
                    const id = person.emp_id_display || person.emp_id || '';
                    option.innerHTML = '<strong></strong><span></span>';
                    option.querySelector('strong').textContent = (person.name || 'Chưa có tên') + ' · ' + id;
                    option.querySelector('span').textContent = person.department || 'Chưa có bộ phận';
                    option.addEventListener('click', () => {
                        input.value = id;
                        menu.innerHTML = '';
                        if (typeof onSelect === 'function') onSelect(person);
                    });
                    menu.appendChild(option);
                });
            } catch (_) { menu.innerHTML = ''; }
        }, 220);
    });
    document.addEventListener('click', event => { if (!host.contains(event.target)) menu.innerHTML = ''; });
}
