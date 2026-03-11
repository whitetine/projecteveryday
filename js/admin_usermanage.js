// ===== 狀態顯示映射 =====
// 使用 var 避免 SPA 動態載入時 const 重複宣告錯誤
if (typeof window.statusDisplayMap === 'undefined') {
    window.statusDisplayMap = {
        0: '休學',
        1: '專題進行中',
        2: '專題未通過',
        3: '專題已通過',
        5: '暑修中',
        6: '暑修通過',
        7: '寒修中',
        8: '寒修通過'
    };
}
var statusDisplayMap = window.statusDisplayMap;

let allUsers = [];
let filteredUsers = [];

let gCohorts = [];
let gClasses = [];
let gRoles   = [];
let gStatuses = [];
let gTeacherLoadByCohort = {}; // 老師 × 屆別帶組數 { u_ID: { cohort_ID: { count, max, full } } }
let gTeacherEnrollmentByCohort = {}; // 老師在 enrollmentdata 的屆別開放 { u_ID: { cohort_ID: true } }
let gTeachersForLoad = []; // 老師名單（不受篩選影響）
let currentQuickFilter = null; // 追蹤當前快速篩選

// ===== 工具：讀 URL 篩選參數（給初始載入用） =====
function getFiltersFromURL() {
    const params = new URLSearchParams(window.location.search);
    return {
        search: params.get('search') || '',
        role_filter: params.get('role_filter') || '',
        status_filter: params.get('status_filter') || '',
        cohort_filter: params.get('cohort_filter') || '',
        class_filter: params.get('class_filter') || ''
    };
}

// ===== 工具：安全 fetch JSON =====
async function fetchJson(url, options = {}) {
    const res  = await fetch(url, options);
    const text = await res.text();

    if (!res.ok) {
        let errorData = null;
        try {
            errorData = JSON.parse(text);
        } catch (e) {}

        const msg = errorData?.msg || errorData?.message || `HTTP ${res.status}：伺服器錯誤`;
        throw new Error(msg);
    }

    try {
        return JSON.parse(text);
    } catch (e) {
        console.error('解析 JSON 失敗，原始回應：', text);
        throw new Error('回傳內容不是合法 JSON');
    }
}

// ===== API：載入帳號管理資料 =====
async function loadUserManageData() {
    const filters = getFiltersFromURL();
    const params = new URLSearchParams(filters);
    params.append('do', 'get_user_manage_data');

    const data = await fetchJson(`api.php?${params.toString()}`);

    if (!data.ok) throw new Error(data.msg || '載入資料失敗');
    return data;
}

// ===== UI：屆別切換 =====
function renderCohortTabs(cohorts, currentCohort) {
    const tabs = (cohorts || []).slice(0, 5).map(c => {
        const active = String(c.cohort_ID) === String(currentCohort || '');
        const status = c.cohort_status == 1 ? '進行中' : (c.cohort_status == 0 ? '封存' : '已完成');
        return `<button type="button" class="um-cohort-tab ${active ? 'active' : ''}" data-cohort="${c.cohort_ID}">
            ${c.cohort_name || '—'}
            ${status ? `<span class="um-tab-badge">${status}</span>` : ''}
        </button>`;
    });
    tabs.push(`<button type="button" class="um-cohort-tab ${!currentCohort ? 'active' : ''}" data-cohort="">全部</button>`);
    $('#cohortTabs').html(tabs.join(''));
}

// ===== UI：總覽（4 張統計卡片） =====
function renderGlobalStats(users) {
    const total = users.length;
    const byStatus = {};
    let studentCount = 0;
    let teacherCount = 0;

    users.forEach(u => {
        const s = u.u_status;
        byStatus[s] = (byStatus[s] || 0) + 1;
        if (String(u.role_ID) === '6') studentCount++;
        if (String(u.role_ID) === '4') teacherCount++;
    });

    const doing = byStatus[1] || 0;
    const passed = byStatus[3] || 0;
    const abnormalCount = (byStatus[0] || 0) + (byStatus[2] || 0) + (byStatus[5] || 0) + (byStatus[6] || 0) + (byStatus[7] || 0) + (byStatus[8] || 0);

    const html = `
        <div class="um-stat-card">
            <div class="um-stat-label">學生人數</div>
            <div class="um-stat-value">${studentCount}</div>
            <div class="um-stat-note">本屆有效帳號</div>
        </div>
        <div class="um-stat-card">
            <div class="um-stat-label">指導老師</div>
            <div class="um-stat-value">${teacherCount}</div>
            <div class="um-stat-note">含可帶組教師</div>
        </div>
        <div class="um-stat-card">
            <div class="um-stat-label">專題進行中</div>
            <div class="um-stat-value">${doing}</div>
            <div class="um-stat-note">已建立組別</div>
        </div>
        <div class="um-stat-card">
            <div class="um-stat-label">其他</div>
            <div class="um-stat-value">${passed + abnormalCount}</div>
            <div class="um-stat-note">已通過 / 休學等</div>
        </div>
    `;

    $('#globalStatsRow').html(html);
}

// ===== UI：篩選後統計（班級分布 + 指導老師負載） =====
function renderFilteredStats(users) {
    $('#filteredCount').text(users.length);
    $('#tableMeta').text(`符合條件：${users.length} 人`);

    // 班級分布（專題進行中的學生，帶進度條）
    const byClass = {};
    users.forEach(u => {
        if (!u.class_name || u.u_status != 1) return;
        const cls = u.class_name + (u.enroll_grade ? ` - ${u.enroll_grade}年級` : '');
        byClass[cls] = (byClass[cls] || 0) + 1;
    });

    const classEntries = Object.entries(byClass).sort((a, b) => b[1] - a[1]);
    const maxCount = Math.max(...classEntries.map(([, c]) => c), 1);

    const classHtml = classEntries.length
        ? classEntries.map(([cls, count]) => {
            const pct = Math.min((count / maxCount) * 100, 100);
            return `<div class="um-dist-item">
                <div class="um-dist-item-header">
                    <span class="um-dist-item-name">${cls}</span>
                    <span class="um-dist-item-count">${count} 人</span>
                </div>
                <div class="um-dist-bar">
                    <div class="um-dist-bar-fill" style="width:${pct}%"></div>
                </div>
            </div>`;
        }).join('')
        : '<p class="text-muted small mb-0">目前沒有班級統計資料。</p>';

    $('#classDistributionList').html(classHtml);

    // 指導老師負載：老師 × 屆別表格（使用 gTeachersForLoad，不受篩選影響）
    const teachers = gTeachersForLoad;
    const displayCohorts = (gCohorts || []).filter(c => c.cohort_status == 1).slice(0, 6);
    const defaultMax = 6;

    let teacherTableHtml = '';
    if (teachers.length && displayCohorts.length) {
        const thead = `<thead><tr><th class="um-th-teacher">老師</th>${displayCohorts.map(c => `<th class="um-th-cohort">${c.cohort_name || '—'}</th>`).join('')}</tr></thead>`;
        const tbody = teachers.slice(0, 12).map(t => {
            const load = gTeacherLoadByCohort[t.u_ID] || {};
            const cells = displayCohorts.map(c => {
                const hasEnrollment = gTeacherEnrollmentByCohort[t.u_ID] && gTeacherEnrollmentByCohort[t.u_ID][c.cohort_ID];
                if (!hasEnrollment) {
                    return `<td class="um-teacher-cell um-cell-empty" title="該屆未開放">未開放</td>`;
                }
                const d = load[c.cohort_ID];
                const count = d ? d.count : 0;
                const max = d ? d.max : defaultMax;
                let statusClass, title, text;
                if (count > max) {
                    statusClass = 'um-cell-full';
                    title = '超過上限';
                    text = `${count} / ${max}`;
                } else if (count >= max - 1) {
                    statusClass = 'um-cell-near';
                    title = '快到上限';
                    text = `${count} / ${max}`;
                } else {
                    statusClass = 'um-cell-ok';
                    title = '可再帶';
                    text = `${count} / ${max}`;
                }
                return `<td class="um-teacher-cell ${statusClass}" title="${title}">${text}</td>`;
            }).join('');
            return `<tr><td class="um-teacher-name">${t.u_name || '—'}</td>${cells}</tr>`;
        }).join('');
        teacherTableHtml = `<div class="um-teacher-table-wrap"><table class="um-teacher-table">${thead}<tbody>${tbody}</tbody></table></div>`;
    } else if (teachers.length) {
        teacherTableHtml = '<p class="text-muted small mb-0">目前沒有屆別資料可顯示。</p>';
    } else {
        teacherTableHtml = '<p class="text-muted small mb-0">目前沒有指導老師資料。</p>';
    }

    $('#teacherLoadList').html(teacherTableHtml);
}

// ===== UI：篩選器選項 =====
function renderFilterOptions(roles, statuses, cohorts, classes, currentFilters) {
    // 角色
    let roleHtml = '<option value="">全部角色</option>';
    roles.forEach(role => {
        const selected = currentFilters.role_filter == role.role_ID ? 'selected' : '';
        roleHtml += `<option value="${role.role_ID}" ${selected}>${role.role_name}</option>`;
    });
    $('#filterRole').html(roleHtml);

    // 狀態
    let statusHtml = '<option value="">全部狀態</option>';
    statuses.forEach(status => {
        if (status.status_ID == 4 || status.status_ID == 2) return;
        const displayName = statusDisplayMap[status.status_ID] || status.status_name;
        const selected = currentFilters.status_filter == status.status_ID ? 'selected' : '';
        statusHtml += `<option value="${status.status_ID}" ${selected}>${displayName}</option>`;
    });
    for (let i = 5; i <= 8; i++) {
        if (statusDisplayMap[i]) {
            const selected = currentFilters.status_filter == i ? 'selected' : '';
            statusHtml += `<option value="${i}" ${selected}>${statusDisplayMap[i]}</option>`;
        }
    }
    $('#filterStatus').html(statusHtml);

    // 學級
    let cohortHtml = '<option value="">全部學級</option>';
    cohorts.forEach(cohort => {
        const selected = currentFilters.cohort_filter == cohort.cohort_ID ? 'selected' : '';
        cohortHtml += `<option value="${cohort.cohort_ID}" ${selected}>${cohort.cohort_name}</option>`;
    });
    $('#filterCohort').html(cohortHtml);

    // 班級
    let classHtml = '<option value="">全部班級</option>';
    classes.forEach(cls => {
        const selected = currentFilters.class_filter == cls.c_ID ? 'selected' : '';
        classHtml += `<option value="${cls.c_ID}" ${selected}>${cls.c_name}</option>`;
    });
    $('#filterClass').html(classHtml);

    $('#filterSearch').val(currentFilters.search || '');
}

// ===== UI：使用者表格（欄位順序：屆別、班級、帳號、名稱、信箱、狀態、操作） =====
function renderUserCards(users) {
    if (!users || users.length === 0) {
        $('#userCardGridContainer').html(`
            <div class="empty-state">
                <i class="fa-solid fa-users-slash"></i>
                <h3>找不到符合條件的使用者</h3>
                <p>請嘗試調整搜尋條件或篩選器。</p>
            </div>
        `);
        return;
    }

    const sortVal = $('#filterSort').val() || 'status';
    let sorted = [...users];
    if (sortVal === 'class') {
        sorted.sort((a, b) => ((a.class_name || '') + (a.enroll_grade || '')).localeCompare((b.class_name || '') + (b.enroll_grade || '')));
    } else if (sortVal === 'name') {
        sorted.sort((a, b) => (a.u_name || '').localeCompare(b.u_name || ''));
    } else if (sortVal === 'id') {
        sorted.sort((a, b) => (a.u_ID || '').localeCompare(b.u_ID || ''));
    } else {
        sorted.sort((a, b) => (a.u_status || 0) - (b.u_status || 0));
    }

    let html = `
        <table class="um-user-table">
            <thead>
                <tr>
                    <th class="um-th-check">選擇</th>
                    <th>屆別</th>
                    <th>班級</th>
                    <th>帳號</th>
                    <th>名稱</th>
                    <th>信箱</th>
                    <th>狀態</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
    `;

    sorted.forEach(user => {
        let cohortDisplay = user.cohort_name || '—';
        let classDisplay = '';
        if (user.class_name) classDisplay = user.class_name;
        if (user.enroll_grade) {
            classDisplay = classDisplay
                ? `${classDisplay} - ${user.enroll_grade}年級`
                : `${user.enroll_grade}年級`;
        }
        if (!classDisplay) classDisplay = '—';

        const displayStatus = statusDisplayMap[user.u_status] || user.status_name || '未知';
        const badgeClass = user.u_status == 1 ? 'um-status-badge-blue' : user.u_status == 3 ? 'um-status-badge-green' : user.u_status == 0 ? 'um-status-badge-red' : 'um-status-badge-amber';

        html += `
                    <tr class="user-table-row" data-user-id="${user.u_ID}">
                        <td class="um-td-check">
                            <input class="form-check-input user-checkbox" type="checkbox" value="${user.u_ID}" id="user_${user.u_ID}">
                        </td>
                        <td>${cohortDisplay}</td>
                        <td>${classDisplay}</td>
                        <td>${user.u_ID || '—'}</td>
                        <td>${user.u_name || '—'}</td>
                        <td>${user.u_gmail || '未設定'}</td>
                        <td>
                            <span class="um-status-badge ${badgeClass}">
                                <span class="um-status-dot"></span>${displayStatus}
                            </span>
                        </td>
                        <td class="um-td-actions">
                            <a href="#pages/admin_edituser.php?u_ID=${user.u_ID}" class="um-btn um-btn-outline um-btn-sm ajax-link">編輯</a>
                            <button class="um-btn um-btn-outline um-btn-sm btn-toggle toggle-btn ${user.u_status == 1 ? 'active' : ''}"
                                    data-acc="${user.u_ID}"
                                    data-status="${user.u_status == 1 ? '0' : '1'}"
                                    data-action="${user.u_status == 1 ? '停用' : '啟用'}">${user.u_status == 1 ? '停用' : '啟用'}</button>
                        </td>
                    </tr>
        `;
    });

    html += `
            </tbody>
        </table>
    `;

    $('#userCardGridContainer').html(html);
}

// ===== 篩選：前端套用 =====
function applyFilters() {
    const search = $('#filterSearch').val().trim().toLowerCase();
    const role   = $('#filterRole').val();
    const status = $('#filterStatus').val();
    const cohort = $('#filterCohort').val();
    const cls    = $('#filterClass').val();
    
    // 獲取當前快速篩選類型
    const activeQuickFilter = $('.um-quick-filter-tag.active').data('filter');

    filteredUsers = allUsers.filter(u => {
        // 文字搜尋：帳號 + 姓名 + 信箱
        if (search) {
            const text = ((u.u_acc || '') + (u.u_name || '') + (u.u_gmail || '')).toLowerCase();
            if (!text.includes(search)) return false;
        }

        // 角色篩選：支援「多重角色（例如 '1,2'）」的情況
        if (role) {
            const userRoleRaw = (u.role_ID ?? u.roles ?? '').toString();
            const rolesArr = userRoleRaw
                .split(',')
                .map(r => r.trim())
                .filter(r => r !== '');
            
            if (!rolesArr.includes(String(role))) return false;
        }

        // 狀態篩選
        if (status && String(u.u_status) !== String(status)) return false;

        // 學級篩選：後端可能給 cohort_ID 或 cohort，兩種都試
        const userCohortID = (u.cohort_ID ?? u.cohort ?? '').toString();
        if (cohort && userCohortID !== String(cohort)) return false;

        // 班級篩選：後端可能給 class_ID 或 c_ID，兩種都試
        const userClassID = (u.class_ID ?? u.c_ID ?? '').toString();
        if (cls && userClassID !== String(cls)) return false;
        
        // 團隊限制篩選
        if (activeQuickFilter === 'team_limit_reached') {
            // 只顯示已達上限的指導老師
            if (String(u.role_ID) !== '4') return false; // 必須是指導老師
            if (!u.team_limit_reached || u.team_limit_reached === '0' || u.team_limit_reached === 0) return false;
        } else if (activeQuickFilter === 'no_team_limit') {
            // 只顯示未設定上限的指導老師
            if (String(u.role_ID) !== '4') return false; // 必須是指導老師
            if (u.has_team_limit && u.has_team_limit !== '0' && u.has_team_limit !== 0) return false;
        }

        return true;
    });

    renderFilteredStats(filteredUsers);
    renderUserCards(filteredUsers);
}

// ===== 初始化頁面 =====
async function initPage() {
    const content = document.getElementById('userManagementContent');
    if (content) content.style.visibility = 'visible';

    try {
        const data = await loadUserManageData();

        gCohorts = data.cohorts || [];
        gClasses = data.classes || [];
        gRoles   = data.roles   || [];
        gStatuses = data.statuses || [];
        gTeacherLoadByCohort = data.teacherLoadByCohort || {};
        gTeacherEnrollmentByCohort = data.teacherEnrollmentByCohort || {};
        gTeachersForLoad = data.teachersForLoad || [];

        allUsers = data.users || [];
        filteredUsers = [...allUsers];

        renderCohortTabs(gCohorts, data.filters?.cohort_filter || '');
        renderGlobalStats(allUsers);
        renderFilteredStats(filteredUsers);
        renderFilterOptions(data.roles, data.statuses, data.cohorts, data.classes, data.filters || {});
        renderUserCards(filteredUsers);

        initEventListeners();
    } catch (err) {
        console.error('初始化頁面錯誤：', err);
        $('#userManagementContent').html(`
            <div class="alert alert-danger m-3">
                <h4 class="alert-heading">載入錯誤</h4>
                <p>${err.message || '無法載入資料，請稍後再試。'}</p>
                <p class="mb-0 small text-muted">請開啟開發者工具 (F12) 查看 Console 詳細錯誤。</p>
            </div>
        `);
    }
}

// ===== 綁定事件 =====
function initEventListeners() {
    const $page = $('#userManagementContent');
    const $grid = $('#userCardGridContainer');

    // 切換狀態
    $(document)
        .off('click', '.toggle-btn')
        .on('click', '.toggle-btn', function () {
            const btn    = $(this);
            const acc    = btn.data('acc');
            const status = btn.data('status');
            const action = btn.data('action');

            if (window.Swal && typeof Swal.fire === 'function') {
                Swal.fire({
                    title: `是否要${action}此帳號？`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: `是，${action}`,
                    cancelButtonText: '取消',
                    reverseButtons: true
                }).then(r => {
                    if (r.isConfirmed) {
                        location.href = `pages/somefunction/toggle_user.php?acc=${acc}&status=${status}`;
                    }
                });
            } else if (confirm(`是否要${action}此帳號？`)) {
                location.href = `pages/somefunction/toggle_user.php?acc=${acc}&status=${status}`;
            }
        });

    // 已選取數量
    function updateSelectedCount() {
        const checked = $grid.find('.user-checkbox:checked').length;
        $('#selectedCount').text(checked);
    }

    // 全選 / 取消全選
    $('#selectAllBtn').off('click').on('click', () => {
        $grid.find('.user-checkbox').prop('checked', true).trigger('change');
    });

    $('#deselectAllBtn').off('click').on('click', () => {
        $grid.find('.user-checkbox').prop('checked', false).trigger('change');
    });

    // 單一 checkbox
    $page.off('change', '.user-checkbox')
        .on('change', '.user-checkbox', function () {
            const $row = $(this).closest('.user-table-row');
            $row.toggleClass('user-table-row-selected', $(this).is(':checked'));
            updateSelectedCount();
        });

    // 點整列切換勾選（排除按鈕／連結）
    $page.off('click', '.user-table-row')
        .on('click', '.user-table-row', function (e) {
            if ($(e.target).closest('.btn, .ajax-link, .user-checkbox, input').length) {
                return;
            }
            const $chk = $(this).find('.user-checkbox');
            $chk.prop('checked', !$chk.prop('checked')).trigger('change');
        });

    updateSelectedCount();

    // 屆別切換
    $page.off('click', '.um-cohort-tab').on('click', '.um-cohort-tab', function () {
        const cohort = $(this).data('cohort');
        $('.um-cohort-tab').removeClass('active');
        $(this).addClass('active');
        $('#filterCohort').val(cohort || '');
        applyFilters();
    });

    // 排序變更
    $('#filterSort').off('change').on('change', function () {
        applyFilters();
    });

    // 篩選表單：前端篩選
    $('#filterForm').off('submit').on('submit', function (e) {
        e.preventDefault();
        applyFilters();
    });

    // 匯出名單（placeholder）
    $('#btnExportList').off('click').on('click', function () {
        if (window.toast) toast('info', '匯出功能可依需求擴充');
        else alert('匯出功能可依需求擴充');
    });

    // 清除篩選
    $('#clearFiltersBtn').off('click').on('click', function (e) {
        e.preventDefault();
        $('#filterSearch').val('');
        $('#filterRole').val('');
        $('#filterStatus').val('');
        $('#filterCohort').val('');
        $('#filterClass').val('');
        $('.um-quick-filter-tag').removeClass('active');
        $('.um-cohort-tab').removeClass('active').filter('[data-cohort=""]').addClass('active');
        currentQuickFilter = null;
        filteredUsers = [...allUsers];
        renderFilteredStats(filteredUsers);
        renderUserCards(filteredUsers);
    });

    // 快速篩選標籤（使用事件委派，因為標籤是動態的）
    $page.off('click', '.um-quick-filter-tag').on('click', '.um-quick-filter-tag', function () {
        const $tag = $(this);
        const filterType = $tag.data('filter');
        const isActive = $tag.hasClass('active');
        
        // 切換 active 狀態：如果點擊已激活的標籤，則取消激活；否則先取消所有標籤，再激活當前標籤
        if (isActive) {
            $tag.removeClass('active');
            currentQuickFilter = null;
            // 清除相關篩選條件
            if (filterType === 'team_limit_reached' || filterType === 'no_team_limit') {
                $('#filterRole').val('');
            }
        } else {
            $('.um-quick-filter-tag').removeClass('active');
            $tag.addClass('active');
            currentQuickFilter = filterType;
        }
        
        // 根據篩選類型設置對應的篩選條件
        if (!isActive) {
            switch(filterType) {
                case 'role_student':
                    $('#filterRole').val('6'); // 6 是學生的 role_ID
                    break;
                case 'status_doing':
                    $('#filterStatus').val('1'); // 1 是專題進行中
                    break;
                case 'role_teacher':
                    $('#filterRole').val('4'); // 4 是指導老師的 role_ID
                    break;
                case 'role_class_teacher':
                    $('#filterRole').val('3'); // 3 是班導的 role_ID
                    break;
                case 'team_limit_reached':
                    // 只篩選指導老師，並在 applyFilters 中進一步過濾
                    $('#filterRole').val('4');
                    break;
                case 'no_team_limit':
                    // 只篩選指導老師，並在 applyFilters 中進一步過濾
                    $('#filterRole').val('4');
                    break;
            }
        }
        
        // 自動套用篩選
        applyFilters();
    });

    // 進階篩選折疊/展開
    $('#toggleAdvancedFilters').off('click').on('click', function () {
        const $advanced = $('#advancedFilters');
        const $icon = $(this).find('i');
        const isVisible = $advanced.is(':visible');
        
        if (isVisible) {
            $advanced.slideUp(200);
            $icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
        } else {
            $advanced.slideDown(200);
            $icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
        }
    });



    // 批次匯入
    $('#btnBatchImport').off('click').on('click', async function () {
        const { value: file } = await Swal.fire({
            title: '批量匯入帳號',
            width: 720,
            html: `
                <div class="mb-3 text-start">
                    <label class="form-label">請上傳 CSV 檔案</label>
                    <input type="file" id="bulkFile" class="form-control" accept=".csv">
                    <div class="form-text mt-1">
                        欄位：帳號, 姓名, Email, 狀態, 角色, 屆別, 班級, 年級
                    </div>
                </div>
            `,
            focusConfirm: false,
            showCancelButton: true,
            confirmButtonText: '開始匯入',
            cancelButtonText: '取消',
            preConfirm: () => {
                const f = document.getElementById('bulkFile').files[0];
                if (!f) {
                    Swal.showValidationMessage('請選擇 CSV 檔案');
                    return false;
                }
                return f;
            }
        });

        if (!file) return;

        const text = await file.text();
        const rows = text
            .split(/\r?\n/)
            .map(r => r.trim())
            .filter(r => r);

        const list = [];
        rows.forEach((row, idx) => {
            const parts = row.split(',').map(p => p.trim());
            if (idx === 0 && /帳號|u_ID/i.test(parts[0])) return;

            list.push({
                u_id: parts[0] ?? '',
                u_name: parts[1] ?? '',
                u_gmail: parts[2] ?? '',
                u_status: parts[3] ? parseInt(parts[3], 10) : 1,
                role_key: parts[4] ?? '',
                cohort_key: parts[5] ?? '',
                class_key: parts[6] ?? '',
                enroll_grade: parts[7] ?? ''
            });
        });

        if (list.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: '匯入資料為空',
                text: '請確認 CSV 內容是否正確'
            });
            return;
        }

        try {
            const res = await fetchJson('api.php?do=bulk_create_user', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ users: list })
            });

            Swal.fire({
                icon: 'success',
                title: '批量匯入完成',
                html: `
                    成功：${res.success} 筆<br>
                    失敗：${res.failed} 筆<br>
                    ${res.skipped_existing ? `（其中 ${res.skipped_existing} 筆帳號已存在略過）` : ''}
                `
            });

            initPage();
        } catch (err) {
            console.error(err);
            Swal.fire({
                icon: 'error',
                title: '批量匯入失敗',
                text: err.message || '請稍後再試'
            });
        }
    });

    // 單次新增帳號
    $('#btnAddSingle').off('click').on('click', async function () {
        if (!window.Swal || typeof Swal.fire !== 'function') {
            alert('SweetAlert 尚未載入，請稍後再試');
            return;
        }

        let roleOptions = '<option value="">未指定</option>';
        (gRoles || []).forEach(r => {
            roleOptions += `<option value="${r.role_ID}">${r.role_name}</option>`;
        });

        let statusOptions = '<option value="">未指定</option>';
        (gStatuses || []).forEach(s => {
            if (s.status_ID == 4 || s.status_ID == 2) return;
            const label = statusDisplayMap[s.status_ID] || s.status_name;
            statusOptions += `<option value="${s.status_ID}">${label}</option>`;
        });
        for (let i = 5; i <= 8; i++) {
            if (statusDisplayMap[i]) {
                statusOptions += `<option value="${i}">${statusDisplayMap[i]}</option>`;
            }
        }

        let cohortOptions = '<option value="">未指定</option>';
        (gCohorts || []).forEach(c => {
            cohortOptions += `<option value="${c.cohort_ID}">${c.cohort_name}</option>`;
        });

        let classOptions = '<option value="">未指定</option>';
        (gClasses || []).forEach(cls => {
            classOptions += `<option value="${cls.c_ID}">${cls.c_name}</option>`;
        });

        const { value: formValues } = await Swal.fire({
            title: '單次新增帳號',
            width: 720,
            showCancelButton: true,
            confirmButtonText: '確認新增',
            cancelButtonText: '取消',
            focusConfirm: false,
            html: `
                <div class="useradd-modal text-start">
                    <div class="useradd-section mb-2">
                        <div class="section-title mb-2">
                            <i class="fa-solid fa-id-badge me-2"></i>基本資料
                        </div>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">登入帳號 <span class="text-danger">*</span></label>
                                <input id="sw_u_acc" class="form-control form-control-sm" placeholder="例如 s1130001">
                                <div class="form-text">預設密碼會設為此帳號，登入後可自行變更。</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">姓名 <span class="text-danger">*</span></label>
                                <input id="sw_u_name" class="form-control form-control-sm" placeholder="王小明">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input id="sw_u_email" class="form-control form-control-sm" placeholder="s1130001@ukn.edu.tw">
                            </div>
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="useradd-section">
                        <div class="section-title mb-2">
                            <i class="fa-solid fa-graduation-cap me-2"></i>學籍資訊
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">角色</label>
                                <select id="sw_role_ID" class="form-select form-select-sm">
                                    ${roleOptions}
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">狀態</label>
                                <select id="sw_u_status" class="form-select form-select-sm">
                                    ${statusOptions}
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">屆別</label>
                                <select id="sw_cohort_ID" class="form-select form-select-sm">
                                    ${cohortOptions}
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">班級</label>
                                <select id="sw_class_ID" class="form-select form-select-sm">
                                    ${classOptions}
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            `,
            preConfirm: () => {
                const u_acc  = $('#sw_u_acc').val().trim();
                const u_name = $('#sw_u_name').val().trim();
                const u_email= $('#sw_u_email').val().trim();

                const roleVal   = $('#sw_role_ID').val();
                const statusVal = $('#sw_u_status').val();
                const cohort_ID = $('#sw_cohort_ID').val() || null;
                const class_ID  = $('#sw_class_ID').val() || null;

                if (!u_acc || !u_name) {
                    Swal.showValidationMessage('「登入帳號」與「姓名」為必填');
                    return false;
                }

                return {
                    u_acc,
                    u_name,
                    u_email,
                    role_ID: roleVal ? parseInt(roleVal, 10) : null,
                    u_status: statusVal ? parseInt(statusVal, 10) : null,
                    cohort_ID,
                    class_ID
                };
            }
        });

        if (!formValues) return;

        try {
            const json = await fetchJson('api.php?do=create_user', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formValues)
            });

            if (!json.ok) throw new Error(json.msg || '新增失敗');

            await Swal.fire({
                icon: 'success',
                title: '新增成功',
                text: json.msg || '已新增一位帳號'
            });

            initPage();
        } catch (err) {
            console.error(err);
            Swal.fire({
                icon: 'error',
                title: '新增失敗',
                text: err.message || '請稍後再試'
            });
        }
    });
}

// ===== 對外入口（給 main.php 用） =====
if (typeof window !== 'undefined') {
    window.renderAdminUsermanagePage = function () {
        initPage();
    };
}
