let allNoTeamMembers = [];

/* 頭像 URL（相對路徑，依主頁面所在目錄解析）*/
function getAvatarUrl(u_img) {
    if (!u_img || !String(u_img).trim()) return '';
    const s = String(u_img).trim();
    if (s.startsWith('http')) return s;
    return 'headshot/' + encodeURIComponent(s);
}

/* 獲取類組徽章樣式類別 */
function getGroupBadgeClass(groupName) {
    if (!groupName) return 'other';
    const name = groupName.toLowerCase();
    if (name.includes('系統') || name.includes('system')) {
        return 'system';
    } else if (name.includes('商務') || name.includes('business') || name.includes('商業')) {
        return 'business';
    } else if (name.includes('設計') || name.includes('design')) {
        return 'design';
    } else {
        return 'other';
    }
}

/* 團隊管理 JavaScript */
// 全域變數（將在初始化時設置）
let cohort_ID = null;
let teamUserField = 'team_u_ID';
let userRoleUidField = 'ur_u_ID';

// 篩選狀態
let currentFilters = {
    cohort_ID: null,
    group_ID: null,
    grade: '',
    class_ID: null
};

// 篩選選項
let filterOptions = {
    cohorts: [],
    groups: [],
    grades: [],
    classes: []
};

/* ==========================================
   載入篩選選項
========================================== */
async function loadFilterOptions() {
    try {
        const apiPath = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
        const response = await fetch(`${apiPath}?do=get_filter_options`);
        const result = await response.json();

        if (!result.ok || !result.success) {
            throw new Error(result.msg || '載入篩選選項失敗');
        }

        filterOptions = result.data;
        populateFilters();
        updatePageSubtitle();

    } catch (error) {
        console.error('載入篩選選項失敗:', error);
    }
}

/* ==========================================
   更新頁面副標（屆別｜類組）
========================================== */
function updatePageSubtitle() {
    const el = document.getElementById('pageSubtitle');
    if (!el) return;
    const cohortVal = document.getElementById('filterCohort')?.value;
    const groupVal = document.getElementById('filterGroup')?.value;
    const cohortName = cohortVal ? (filterOptions.cohorts.find(c => String(c.cohort_ID) === cohortVal)?.cohort_name || '') : '';
    const groupName = groupVal ? (filterOptions.groups.find(g => String(g.group_ID) === groupVal)?.group_name || '') : '';
    const parts = [cohortName, groupName].filter(Boolean);
    el.textContent = parts.length ? parts.join('｜') : '';
    el.style.display = parts.length ? 'block' : 'none';
}

/* ==========================================
   填充篩選器選項
========================================== */
function populateFilters() {
    // 屆別（含 status=3 已結案，顯示「已結案」標記）
    const cohortSelect = document.getElementById('filterCohort');
    if (cohortSelect) {
        cohortSelect.innerHTML = '<option value="">全部</option>';
        filterOptions.cohorts.forEach(cohort => {
            const option = document.createElement('option');
            option.value = cohort.cohort_ID;
            const status = parseInt(cohort.cohort_status ?? 1, 10);
            option.textContent = cohort.cohort_name + (status === 3 ? '（已結案）' : '');
            option.setAttribute('data-cohort-status', status);
            if (cohort.cohort_ID == cohort_ID) {
                option.selected = true;
                currentFilters.cohort_ID = cohort.cohort_ID;
            }
            cohortSelect.appendChild(option);
        });
    }

    // 類組
    const groupSelect = document.getElementById('filterGroup');
    if (groupSelect) {
        groupSelect.innerHTML = '<option value="">全部</option>';
        filterOptions.groups.forEach(group => {
            const option = document.createElement('option');
            option.value = group.group_ID;
            option.textContent = group.group_name;
            groupSelect.appendChild(option);
        });
    }

    // 年級
    // const gradeSelect = document.getElementById('filterGrade');
    // if (gradeSelect) {
    //     gradeSelect.innerHTML = '<option value="">全部</option>';
    //     filterOptions.grades.forEach(grade => {
    //         const option = document.createElement('option');
    //         option.value = grade.enroll_grade;
    //         option.textContent = grade.enroll_grade;
    //         gradeSelect.appendChild(option);
    //     });
    // }

    // 班級
    const classSelect = document.getElementById('filterClass');
    if (classSelect) {
        classSelect.innerHTML = '<option value="">全部</option>';
        filterOptions.classes.forEach(cls => {
            const option = document.createElement('option');
            option.value = cls.c_ID;
            option.textContent = cls.c_name;
            classSelect.appendChild(option);
        });
    }
}

/* ==========================================
   載入團隊資料
========================================== */
async function loadTeamData() {
    const container = document.getElementById('teamGroupsContainer');
    if (!container) return;

    try {
        container.innerHTML = '<div class="loading-indicator"><i class="fa-solid fa-spinner fa-spin"></i> 載入中...</div>';

        // 構建查詢參數
        const apiPath = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
        const params = new URLSearchParams();

        if (currentFilters.cohort_ID) {
            params.append('cohort_ID', currentFilters.cohort_ID);
        } else if (cohort_ID) {
            params.append('cohort_ID', cohort_ID);
        }

        if (currentFilters.group_ID) {
            params.append('group_ID', currentFilters.group_ID);
        }

        if (currentFilters.grade) {
            params.append('grade', currentFilters.grade);
        }

        if (currentFilters.class_ID) {
            params.append('class_ID', currentFilters.class_ID);
        }

        const response = await fetch(`${apiPath}?do=get_team_management_data&${params.toString()}`);
        const result = await response.json();

        if (!result.ok || !result.success) {
            throw new Error(result.msg || '載入失敗');
        }

        renderTeamGroups(result.data);
        updatePageSubtitle();

        // 如果有搜尋結果，重新應用高亮
        if (currentSearchResult) {
            setTimeout(() => {
                highlightAndExpandGroups(currentSearchResult);
            }, 100);
        }

    } catch (error) {
        console.error('載入組別資料失敗:', error);
        const container = document.getElementById('teamGroupsContainer');
        if (container) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fa-solid fa-exclamation-triangle"></i>
                    <p>載入失敗：${error.message}</p>
                </div>
            `;
        }
    }
}

/* ==========================================
   渲染團隊分組（Excel 風格列表）
========================================== */
function renderTeamGroups(data) {
    const container = document.getElementById('teamGroupsContainer');
    if (!container) return;

    let html = '';

    const cohortStatus = parseInt(data.cohort_status ?? 1, 10);
    const isCohortClosed = (cohortStatus === 3);

    // 已結案屆別提示
    if (isCohortClosed) {
        html += `<div class="alert alert-info cohort-closed-banner" style="margin-bottom:1rem;"><i class="fa-solid fa-lock"></i> 此屆別已結案，僅可檢視，無法修改成員。</div>`;
    }

    // 儀表板統計（4 主卡 + 1 例外，單位同一行）
    const dash = data.dashboard || {};
    const totalTeams = dash.total_teams ?? 0;
    const totalInTeam = dash.total_in_team_students ?? 0;
    const noTeamCount = dash.total_no_team_students ?? (data.noTeamMembers ? data.noTeamMembers.length : 0);
    const totalTeachers = dash.total_teachers ?? 0;
    const underMinCount = dash.under_min_count ?? 0;

    // 計算滿員、可加入數量（用於 Pill 篩選）
    let fullCount = 0, availableCount = 0;
    if (data.groups && data.groups.length > 0) {
        data.groups.forEach(g => {
            (g.teams || []).forEach(t => {
                const mc = t.member_count ?? 0;
                const maxM = t.max_member ?? 5;
                const minM = t.min_member ?? 2;
                if (mc >= maxM) fullCount++;
                else if (mc >= minM) availableCount++;
            });
        });
    }

    // 組隊總覽：簡潔統計
    html += `
        <div class="team-dashboard-cards">
            <div class="team-dashboard-card">
                <span class="team-dashboard-icon"><i class="fa-solid fa-layer-group"></i></span>
                <div class="kpi-right">
                    <span class="team-dashboard-value">${totalTeams}<span class="unit">組</span></span>
                    <span class="team-dashboard-label">組別總數</span>
                </div>
            </div>
            <div class="team-dashboard-card">
                <span class="team-dashboard-icon"><i class="fa-solid fa-users"></i></span>
                <div class="kpi-right">
                    <span class="team-dashboard-value">${totalInTeam}<span class="unit">人</span></span>
                    <span class="team-dashboard-label">已分組學生</span>
                </div>
            </div>
            <div class="team-dashboard-card team-dashboard-card-highlight">
                <span class="team-dashboard-icon"><i class="fa-solid fa-user-xmark"></i></span>
                <div class="kpi-right">
                    <span class="team-dashboard-value">${noTeamCount}<span class="unit">人</span></span>
                    <span class="team-dashboard-label">未分組學生</span>
                </div>
            </div>
            <div class="team-dashboard-card">
                <span class="team-dashboard-icon"><i class="fa-solid fa-chalkboard-user"></i></span>
                <div class="kpi-right">
                    <span class="team-dashboard-value">${totalTeachers}<span class="unit">位</span></span>
                    <span class="team-dashboard-label">指導老師</span>
                </div>
            </div>
            <div class="team-dashboard-card team-dashboard-card-warning">
                <span class="team-dashboard-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <div class="kpi-right">
                    <span class="team-dashboard-value">${underMinCount}<span class="unit">組</span></span>
                    <span class="team-dashboard-label">未達最低人數</span>
                    <span class="team-dashboard-hint">低於每組最低人數(min)</span>
                </div>
            </div>
        </div>
    `;

    // 快速篩選：Pill + 數量
    html += `
        <div class="team-quick-filters">
            <button type="button" class="btn-filter-pill active" data-filter="all">全部（${totalTeams}）</button>
            <button type="button" class="btn-filter-pill" data-filter="under">未達最低（${underMinCount}）</button>
            <button type="button" class="btn-filter-pill" data-filter="full">滿員（${fullCount}）</button>
            <button type="button" class="btn-filter-pill" data-filter="available">可加入（${availableCount}）</button>
        </div>
    `;

    // 狀態導向表格
    html += `
        <div class="team-excel-table-wrapper">
            <table class="team-excel-table table-clean">
                <colgroup>
                    <col style="width: 10%">
                    <col style="width: 22%">
                    <col style="width: 12%">
                    <col style="width: 20%">
                    <col style="width: 14%">
                    <col style="width: 10%">
                    <col style="width: 12%">
                </colgroup>
                <thead>
                    <tr>
                        <th>屆別</th>
                        <th>專題名稱</th>
                        <th>類組</th>
                        <th>指導老師</th>
                        <th>組員數</th>
                        <th>狀態</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
    `;

    // 渲染團隊列表（狀態導向）
    if (data.groups && data.groups.length > 0) {
        data.groups.forEach(group => {
            group.teams.forEach(team => {
                const members = team.members || [];
                const teachers = members.filter(m => m.role_ID == 4);
                const students = members.filter(m => m.role_ID == 6);
                const studentList = students.map(s => {
                    const name = s.u_name || s.u_ID || '';
                    const id = s.u_ID || '';
                    return `${escapeHtml(name)} (${escapeHtml(id)})`;
                }).join('、') || '無成員';

                const groupBadgeClass = getGroupBadgeClass(group.group_name);
                const teamCohortName = team.cohort_name || group.cohort_name || '-';

                const mc = team.member_count ?? students.length;
                const minM = team.min_member ?? 2;
                const maxM = team.max_member ?? 5;
                const status = team.status || 'normal';
                const isFull = mc >= maxM;
                const isAvailable = mc < maxM && mc >= minM;

                // 組員數：獨立欄 member-metric（4/4 人 + 最低 min 人 + 進度條）
                const pct = maxM > 0 ? Math.min(100, (mc / maxM) * 100) : 0;
                let subText = status === 'under' ? `缺 ${minM - mc} 人` : (isFull ? '滿員' : `最低 ${minM} 人`);
                const memberCountHtml = `
                    <div class="member-metric">
                        <div class="count">${mc} / ${maxM} <span class="sub">人</span></div>
                        <div class="sub">${subText}</div>
                        <div class="bar"><i style="--w: ${pct}%"></i></div>
                    </div>`;

                // 狀態：正常 / 未達最低 / 超額
                const statusLabel = status === 'under' ? '未達最低' : (status === 'over' ? '超額' : '正常');
                const statusClass = status === 'under' ? 'status-under' : (status === 'over' ? 'status-over' : 'status-normal');

                // 指導老師：chip 中性色，只有超載才 danger；+N 改為「更多」
                const teacherList = (team.teachers && team.teachers.length > 0)
                    ? team.teachers
                    : teachers.map(t => ({ u_name: t.u_name || t.u_ID || '-', team_count: 0, max_limit: 4, load_status: 'ok' }));
                const maxChips = 2;
                const teacherChips = teacherList.slice(0, maxChips).map(t => {
                    const dangerClass = (t.load_status === 'full') ? ' danger' : '';
                    const label = t.max_limit ? `${escapeHtml(t.u_name)} ${t.team_count}/${t.max_limit}` : escapeHtml(t.u_name);
                    return `<span class="teacher-chip${dangerClass}">${label}</span>`;
                }).join('');
                const teacherItems = teacherChips;

                const searchText = `${teamCohortName} ${team.team_project_name || ''} ${group.group_name || ''} ${teacherList.map(t=>t.u_name).join(' ')} ${studentList}`.toLowerCase();

                html += `
                    <tr class="team-excel-row team-status-${status}" 
                        data-team-id="${team.team_ID}" 
                        data-group-id="${group.group_ID}" 
                        data-cohort-id="${team.cohort_ID || group.cohort_ID || cohort_ID}"
                        data-team-status="${status}"
                        data-team-full="${isFull ? '1' : '0'}"
                        data-team-available="${isAvailable ? '1' : '0'}"
                        data-search-text="${escapeHtml(searchText)}">
                        <td class="team-cohort-cell">${escapeHtml(teamCohortName)}</td>
                        <td class="team-name-cell">
                            <strong>${escapeHtml(team.team_project_name || '未命名專題')}</strong>
                        </td>
                        <td class="team-group-cell">
                            ${group.group_name ? `<span class="team-group-badge group-badge-${groupBadgeClass}">${escapeHtml(group.group_name)}</span>` : '-'}
                        </td>
                        <td class="team-teacher-cell">${teacherItems || '-'}</td>
                        <td class="team-member-count-cell">${memberCountHtml}</td>
                        <td class="team-status-cell"><span class="team-status-badge ${statusClass}">${statusLabel}</span></td>
                        <td class="team-action-cell">
                            <button class="btn-view-team" data-team-id="${team.team_ID}" title="查看詳情">查看</button>
                            <button class="btn-delete-team" data-team-id="${team.team_ID}" data-team-name="${escapeHtml(team.team_project_name || '未命名專題')}" title="刪除團隊">刪除</button>
                        </td>
                    </tr>
                `;
            });
        });
    } else {
        html += `
            <tr>
                <td colspan="7" class="text-center text-muted py-4">
                    <i class="fa-solid fa-inbox"></i> 目前沒有組別資料
                </td>
            </tr>
        `;
    }

    html += `
                </tbody>
            </table>
        </div>
    `;


    // ② 未加入團隊區塊（永遠在最底下）
    if (data.noTeamMembers && data.noTeamMembers.length > 0) {
        allNoTeamMembers = data.noTeamMembers;
        const total = allNoTeamMembers.length;

        // 先整理屆別、班級選項
        const cohortSet = new Set();
        const classSet = new Set();
        allNoTeamMembers.forEach(m => {
            if (m.cohort_name) cohortSet.add(m.cohort_name);
            if (m.class_name) classSet.add(m.class_name);
        });

        const cohortOptions = Array.from(cohortSet)
            .sort()
            .map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`)
            .join('');

        const classOptions = Array.from(classSet)
            .sort()
            .map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`)
            .join('');

        html += `
    <div class="no-team-section">
        <div class="no-team-header">
            <div class="no-team-title-wrapper">
                <h3 class="no-team-title">
                    目前尚有 <span id="noTeamTotalCount">${total}</span> 位學生未加入組別
                </h3>
            </div>

            <div class="no-team-controls">
                <!-- 屆別篩選 -->
                <div class="no-team-filter-group">
                    <label class="no-team-filter-label" for="noTeamFilterCohort">屆別</label>
                    <select id="noTeamFilterCohort" class="no-team-filter-select">
                        <option value="">全部</option>
                        ${cohortOptions}
                    </select>
                </div>

                <!-- 班級篩選 -->
                <div class="no-team-filter-group">
                    <label class="no-team-filter-label" for="noTeamFilterClass">班級</label>
                    <select id="noTeamFilterClass" class="no-team-filter-select">
                        <option value="">全部</option>
                        ${classOptions}
                    </select>
                </div>

                <!-- 篩選結果人數 -->
                <div class="no-team-filter-summary">
                    篩選結果：<span id="noTeamFilterCount">${total}</span> 人
                </div>

                <!-- ✅ 新增：已選取人數 -->
                <div class="no-team-selected-summary">
                    已選取：<span id="noTeamSelectedCount">0</span> 人
                </div>

                <!-- 全選 + 通知老師 -->
                <div class="select-all-control">
                    <input type="checkbox" id="selectAllNoTeam" class="form-check-input">
                    <label for="selectAllNoTeam" class="form-check-label">全選</label>
                </div>

                <button type="button" class="btn-notify-teachers" id="btnNotifyTeachers" disabled>
                    <i class="fa-solid fa-bell me-2"></i>通知老師
                </button>
            </div>
        </div>

        <div id="noTeamList" class="no-team-list-table">
            <table class="no-team-table">
                <thead>
                    <tr>
                        <th class="select-header"><input type="checkbox" id="selectAllNoTeamHeader"></th>
                        <th class="name-header">姓名</th>
                        <th class="id-header">學號</th>
                        <th class="cohort-header">屆別</th>
                        <th class="class-header">班級</th>
                        <th class="action-header">操作</th>
                    </tr>
                </thead>
                <tbody>
                    ${allNoTeamMembers.map(member => createNoTeamMember(member)).join('')}
                </tbody>
            </table>
        </div>
    </div>
`;


    }

    if (!html) {
        html = `
            <div class="empty-state">
                <i class="fa-solid fa-inbox"></i>
                <p>目前沒有符合條件的組別資料</p>
            </div>
        `;
    }

    container.innerHTML = html;

    // 綁定團隊表格點擊事件
    bindTeamCardEvents();

    // 綁定快速篩選
    bindQuickFilterEvents();

    // 原本就有的勾選 / 通知事件
    bindNoTeamMemberEvents();

    // 綁定自己的篩選事件
    bindNoTeamFilterEvents();
}

/* ==========================================
   只重畫未加入團隊清單（年級 / 班級篩選用）
========================================== */
function renderNoTeamList(members) {
    const listEl = document.getElementById('noTeamList');
    if (!listEl) return;

    if (members.length === 0) {
        listEl.innerHTML = '<div class="no-team-empty"><i class="fas fa-inbox"></i><p>目前沒有未加入組別的學生</p></div>';
    } else {
        listEl.innerHTML = `
            <table class="no-team-table">
                <thead>
                    <tr>
                        <th class="select-header"><input type="checkbox" id="selectAllNoTeamHeader"></th>
                        <th class="name-header">姓名</th>
                        <th class="id-header">學號</th>
                        <th class="cohort-header">屆別</th>
                        <th class="class-header">班級</th>
                        <th class="action-header">操作</th>
                    </tr>
                </thead>
                <tbody>
                    ${members.map(m => createNoTeamMember(m)).join('')}
                </tbody>
            </table>
        `;
    }

    // 更新「篩選結果：X 人」
    const filterCountEl = document.getElementById('noTeamFilterCount');
    if (filterCountEl) {
        filterCountEl.textContent = members.length;
    }

    // 重綁選取事件
    bindNoTeamMemberEvents();

    // 重置全選
    const selectAll = document.getElementById('selectAllNoTeam');
    if (selectAll) selectAll.checked = false;

    // 重置表頭全選
    const selectAllHeader = document.getElementById('selectAllNoTeamHeader');
    if (selectAllHeader) selectAllHeader.checked = false;

    // 重置「已選取：X 人」與按鈕狀態
    updateNotifyButtonState();   // 這裡會把 noTeamSelectedCount 設成 0
}
/* ==========================================
   綁定屆別 / 班級篩選事件
========================================== */
function bindNoTeamFilterEvents() {
    const cohortSelect = document.getElementById('noTeamFilterCohort');
    const classSelect = document.getElementById('noTeamFilterClass');
    if (!cohortSelect || !classSelect) return;

    const handleFilter = () => {
        const cohort = cohortSelect.value;
        const c = classSelect.value;

        const filtered = allNoTeamMembers.filter(m => {
            const okCohort = !cohort || m.cohort_name == cohort;
            const okClass = !c || m.class_name == c;
            return okCohort && okClass;
        });

        renderNoTeamList(filtered);
    };

    cohortSelect.addEventListener('change', handleFilter);
    classSelect.addEventListener('change', handleFilter);
}
// renderTeamCharts 函數已移除，改用統計數字顯示

/* ==========================================
   創建團隊卡片 HTML
========================================== */
function createTeamCard(team) {
    // 處理成員列表的 HTML
    let membersHtml = team.members.map(m => `
        <li class="tm-member ${m.is_leader ? 'is-leader' : ''}">
            <i class="fa-solid ${m.is_leader ? 'fa-crown' : 'fa-user'}"></i>
            <span class="tm-m-name">${m.u_name}</span>
            <span class="tm-m-id">${m.student_id || ''}</span>
        </li>
    `).join('');

    // 回傳您設計的 HTML 模板
    return `
        <div class="tm-card" data-team-id="${team.team_ID}">
            <div class="tm-card-head">
                <span class="tm-group-tag">第 ${team.team_no} 組</span>
                <h4 class="tm-team-name">${team.team_name || '未命名組別'}</h4>
            </div>
            
            <ul class="tm-member-list">
                ${membersHtml}
            </ul>

            <div class="tm-card-footer">
                <button class="tm-btn-detail" onclick="openDetail(${team.team_ID})">
                    查看詳情
                </button>
            </div>
        </div>
    `;
}

/* ==========================================
   創建未加入團隊成員 HTML
========================================== */
function createNoTeamMember(member) {
    const name = member.u_name || member.u_ID;

    let classText = '';
    if (member.enroll_grade && member.class_name) {
        classText = `${member.enroll_grade} 年級・${member.class_name}`;
    } else if (member.class_name) {
        classText = member.class_name;
    } else if (member.enroll_grade) {
        classText = `${member.enroll_grade} 年級`;
    }

    const cohortText = member.cohort_name || '';

    return `
        <tr class="no-team-member-row student-item"
            data-student-id="${escapeHtml(member.u_ID)}"
            data-cohort="${escapeHtml(member.cohort_name || '')}"
            data-class="${escapeHtml(member.class_name || '')}"
            data-selected="false">
            <td class="select-cell">
                <input type="checkbox" class="student-checkbox">
            </td>
            <td class="name-cell">${escapeHtml(name)}</td>
            <td class="id-cell">${escapeHtml(member.u_ID)}</td>
            <td class="cohort-cell">${cohortText ? escapeHtml(cohortText) : '-'}</td>
            <td class="class-cell">${classText ? escapeHtml(classText) : '-'}</td>
            <td class="action-cell">
                <button class="btn-notify-student" data-student-id="${escapeHtml(member.u_ID)}" data-student-name="${escapeHtml(name)}" title="通知學生">
                    通知學生
                </button>
            </td>
        </tr>
    `;
}


/* ==========================================
   綁定快速篩選（Pill：全部/未達最低/滿員/可加入）
========================================== */
function bindQuickFilterEvents() {
    const container = document.getElementById('teamGroupsContainer');
    if (!container) return;
    container.querySelectorAll('.btn-filter-pill, .btn-filter-quick').forEach(btn => {
        btn.onclick = function () {
            container.querySelectorAll('.btn-filter-pill, .btn-filter-quick').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            filterTableRows(document.getElementById('searchStudent')?.value?.trim() || '');
        };
    });
}

/* ==========================================
   綁定團隊卡片點擊事件（使用事件委派）
========================================== */
function bindTeamCardEvents() {
    // 使用事件委派，避免重複綁定
    const container = document.getElementById('teamGroupsContainer');
    if (!container) {
        console.warn('找不到 teamGroupsContainer');
        return;
    }

    // 移除舊的監聽器（如果有的話）
    container.removeEventListener('click', handleTeamCardClick);

    // 綁定新的監聽器
    container.addEventListener('click', handleTeamCardClick);
    console.log('團隊卡片點擊事件已綁定');
}

// 處理團隊卡片點擊事件（現在是表格行）
function handleTeamCardClick(e) {
    // 刪除按鈕：不觸發查看，改為執行刪除
    const deleteBtn = e.target.closest('.btn-delete-team');
    if (deleteBtn) {
        e.preventDefault();
        e.stopPropagation();
        const teamId = deleteBtn.dataset.teamId || deleteBtn.getAttribute('data-team-id');
        const teamName = deleteBtn.dataset.teamName || '此團隊';
        if (teamId) deleteTeam(teamId, teamName);
        return;
    }

    // 找到被點擊的團隊行（Excel 表格行）
    const row = e.target.closest('.team-excel-row');
    if (!row) {
        // 如果不是點擊在表格行上，直接返回
        return;
    }

    // 支援 data-team-id 和 data-teamId 兩種格式
    const teamId = row.dataset.teamId || row.getAttribute('data-team-id');
    if (teamId) {
        e.preventDefault();
        e.stopPropagation();
        console.log('點擊團隊行，teamId:', teamId);
        showTeamDetail(teamId);
    } else {
        console.warn('團隊行沒有 teamId:', row);
    }
}

// 刪除團隊
async function deleteTeam(teamId, teamName) {
    if (typeof Swal === 'undefined') {
        if (!confirm(`確定要刪除「${teamName}」嗎？此操作將使團隊不再顯示於列表中。`)) return;
    } else {
        const { isConfirmed } = await Swal.fire({
            title: '確定刪除？',
            html: `確定要刪除「<b>${teamName}</b>」嗎？<br>此操作將使團隊不再顯示於列表中。`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '確定刪除',
            cancelButtonText: '取消',
            confirmButtonColor: '#dc3545'
        });
        if (!isConfirmed) return;
    }
    try {
        const formData = new FormData();
        formData.append('team_ID', teamId);
        const apiPath = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
        const res = await fetch(`${apiPath}?do=delete_team`, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.ok) {
            if (typeof Swal !== 'undefined') Swal.fire({ icon: 'success', title: data.message || '已刪除' });
            else alert(data.message || '已刪除');
            loadTeamData();
        } else {
            if (typeof Swal !== 'undefined') Swal.fire({ icon: 'error', title: data.msg || '刪除失敗' });
            else alert(data.msg || '刪除失敗');
        }
    } catch (err) {
        console.error('刪除團隊失敗:', err);
        if (typeof Swal !== 'undefined') Swal.fire({ icon: 'error', title: '刪除失敗' });
        else alert('刪除失敗');
    }
}

/* ==========================================
   綁定未加入團隊成員的事件（點擊選取、全選、通知）
========================================== */
function bindNoTeamMemberEvents() {
    const selectAllCheckbox = document.getElementById('selectAllNoTeam');
    if (selectAllCheckbox) {
        selectAllCheckbox.onchange = function () {     // ← 改這裡
            const isChecked = this.checked;
            document.querySelectorAll('.student-item').forEach(item => {
                const isSelected = item.dataset.selected === 'true';
                if (isChecked && !isSelected) {
                    toggleStudentSelection(item);
                } else if (!isChecked && isSelected) {
                    toggleStudentSelection(item);
                }
            });
            updateNotifyButtonState();
        };
    }

    // 綁定表格行中的 checkbox
    document.querySelectorAll('.student-item .student-checkbox').forEach(checkbox => {
        checkbox.onchange = function (e) {
            e.stopPropagation();
            const row = this.closest('.student-item');
            if (row) {
                toggleStudentSelection(row);
                updateNotifyButtonState();
                updateSelectAllState();
            }
        };
    });

    // 綁定表格行點擊（排除 checkbox 和按鈕）
    document.querySelectorAll('.student-item').forEach(item => {
        item.onclick = function (e) {
            // 如果點擊的是 checkbox 或按鈕，不處理
            if (e.target.type === 'checkbox' || e.target.closest('button') || e.target.closest('#selectAllNoTeam')) {
                return;
            }
            toggleStudentSelection(this);
            updateNotifyButtonState();
            updateSelectAllState();
        };
    });

    // 綁定通知學生按鈕
    document.querySelectorAll('.btn-notify-student').forEach(btn => {
        btn.onclick = function (e) {
            e.stopPropagation();
            const studentId = this.dataset.studentId;
            const studentName = this.dataset.studentName;
            if (studentId) {
                sendNotificationToStudent([studentId], [studentName]);
            }
        };
    });

    // 綁定表頭的全選 checkbox
    const selectAllHeader = document.getElementById('selectAllNoTeamHeader');
    if (selectAllHeader) {
        selectAllHeader.onchange = function () {
            const isChecked = this.checked;
            const selectAllNoTeam = document.getElementById('selectAllNoTeam');
            if (selectAllNoTeam) selectAllNoTeam.checked = isChecked;

            document.querySelectorAll('.student-item .student-checkbox').forEach(checkbox => {
                checkbox.checked = isChecked;
                const row = checkbox.closest('.student-item');
                if (row) {
                    row.dataset.selected = isChecked ? 'true' : 'false';
                    if (isChecked) {
                        row.classList.add('selected');
                    } else {
                        row.classList.remove('selected');
                    }
                }
            });
            updateNotifyButtonState();
        };
    }

    const notifyBtn = document.getElementById('btnNotifyTeachers');
    if (notifyBtn) {
        notifyBtn.onclick = function () {             // ← 改這裡
            const selectedStudents = Array.from(
                document.querySelectorAll('.student-item[data-selected="true"]')
            ).map(item => item.dataset.studentId);

            if (selectedStudents.length === 0) {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'warning',
                        title: '請選擇學生',
                        text: '請至少選擇一名學生',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
                return;
            }

            sendNotificationToTeachers(selectedStudents);
        };
    }
}


/* ==========================================
   切換學生選取狀態
========================================== */
function toggleStudentSelection(item) {
    const isSelected = item.dataset.selected === 'true';
    item.dataset.selected = isSelected ? 'false' : 'true';

    // 更新 checkbox 狀態
    const checkbox = item.querySelector('.student-checkbox');
    if (checkbox) {
        checkbox.checked = !isSelected;
    }

    // 更新行樣式
    if (isSelected) {
        item.classList.remove('selected');
    } else {
        item.classList.add('selected');
    }
}

/* ==========================================
   更新全選狀態
========================================== */
function updateSelectAllState() {
    const selectAllCheckbox = document.getElementById('selectAllNoTeam');
    const selectAllHeader = document.getElementById('selectAllNoTeamHeader');
    const totalSelected = document.querySelectorAll('.student-item[data-selected="true"]').length;
    const totalStudents = document.querySelectorAll('.student-item').length;
    const allSelected = totalSelected === totalStudents && totalStudents > 0;

    if (selectAllCheckbox) {
        selectAllCheckbox.checked = allSelected;
    }
    if (selectAllHeader) {
        selectAllHeader.checked = allSelected;
    }
}

/* ==========================================
   更新通知按鈕狀態
========================================== */
function updateNotifyButtonState() {
    const selectedCount = document.querySelectorAll('.student-item[data-selected="true"]').length;

    // 按鈕啟用 / 關閉
    const notifyBtn = document.getElementById('btnNotifyTeachers');
    if (notifyBtn) {
        notifyBtn.disabled = (selectedCount === 0);
    }

    // ✅ 更新「已選取：X 人」
    const selectedCountEl = document.getElementById('noTeamSelectedCount');
    if (selectedCountEl) {
        selectedCountEl.textContent = selectedCount;
    }
}

/* ==========================================
   發送通知給學生
========================================== */
function sendNotificationToStudent(studentIds, studentNames = []) {
    if (window.Swal) {
        const studentList = studentNames.length > 0
            ? studentNames.slice(0, 5).join('、') + (studentNames.length > 5 ? ' 等' : '')
            : `${studentIds.length} 位學生`;

        Swal.fire({
            title: '<span style="font-size: 1.5rem; font-weight: 700; color: #1e293b;">確認發送通知</span>',
            html: `<div style="margin-top: 1rem;">
                <p style="font-size: 1.1rem; color: #475569; margin: 0 0 0.5rem 0;">
                    將發送通知給 <strong>${studentIds.length}</strong> 位學生
                </p>
                <p style="font-size: 0.95rem; color: #64748b; margin: 0;">
                    通知內容：提醒您尚未加入組別，請儘快加入組別進行專題
                </p>
            </div>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '確認發送',
            cancelButtonText: '取消',
            confirmButtonColor: '#667eea',
            cancelButtonColor: '#94a3b8',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: '發送中...',
                    text: '請稍候',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                const apiPath = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
                fetch(`${apiPath}?do=notify_students`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        student_ids: studentIds
                    })
                })
                    .then(response => response.json())
                    .then(response => {
                        Swal.close();
                        if (response.ok !== false) {
                            Swal.fire({
                                icon: 'success',
                                title: '發送成功',
                                text: response.message || '通知已成功發送給學生',
                                timer: 2000,
                                showConfirmButton: false,
                                toast: true,
                                position: 'bottom-end'
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: '發送失敗',
                                text: response.msg || '發送通知時發生錯誤',
                                timer: 3000,
                                showConfirmButton: false,
                                toast: true,
                                position: 'bottom-end'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: '錯誤',
                            text: '發送通知時發生錯誤：' + error.message,
                            timer: 3000,
                            showConfirmButton: false,
                            toast: true,
                            position: 'bottom-end'
                        });
                    });
            }
        });
    }
}

/* ==========================================
   發送通知給學生
========================================== */
function sendNotificationToStudent(studentIds, studentNames = []) {
    if (window.Swal) {
        const studentList = studentNames.length > 0
            ? studentNames.slice(0, 5).join('、') + (studentNames.length > 5 ? ' 等' : '')
            : `${studentIds.length} 位學生`;

        Swal.fire({
            title: '<span style="font-size: 1.5rem; font-weight: 700; color: #1e293b;">確認發送通知</span>',
            html: `<div style="margin-top: 1rem;">
                <p style="font-size: 1.1rem; color: #475569; margin: 0 0 0.5rem 0;">
                    將發送通知給 <strong>${studentIds.length}</strong> 位學生
                </p>
                <p style="font-size: 0.95rem; color: #64748b; margin: 0;">
                    通知內容：提醒您尚未加入組別，請儘快加入組別進行專題
                </p>
            </div>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '確認發送',
            cancelButtonText: '取消',
            confirmButtonColor: '#667eea',
            cancelButtonColor: '#94a3b8',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: '發送中...',
                    text: '請稍候',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                const apiPath = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
                fetch(`${apiPath}?do=notify_students`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        student_ids: studentIds
                    })
                })
                    .then(response => response.json())
                    .then(response => {
                        Swal.close();
                        if (response.ok !== false) {
                            Swal.fire({
                                icon: 'success',
                                title: '發送成功',
                                text: response.message || '通知已成功發送給學生',
                                timer: 2000,
                                showConfirmButton: false,
                                toast: true,
                                position: 'bottom-end'
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: '發送失敗',
                                text: response.msg || '發送通知時發生錯誤',
                                timer: 3000,
                                showConfirmButton: false,
                                toast: true,
                                position: 'bottom-end'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: '錯誤',
                            text: '發送通知時發生錯誤：' + error.message,
                            timer: 3000,
                            showConfirmButton: false,
                            toast: true,
                            position: 'bottom-end'
                        });
                    });
            }
        });
    }
}

/* ==========================================
   發送通知給老師
========================================== */
function sendNotificationToTeachers(studentIds) {
    if (window.Swal) {
        Swal.fire({
            title: '<span style="font-size: 1.5rem; font-weight: 700; color: #1e293b;">確認發送通知</span>',
            html: `<div style="margin-top: 1rem;">
                <p style="font-size: 1.1rem; color: #475569; margin: 0 0 0.5rem 0;">
                    <i class="fa-solid fa-info-circle" style="color: #666; margin-right: 0.5rem;"></i>
                    確定要通知 <strong style="color: #667eea;">${studentIds.length}</strong> 位學生的班導嗎？
                </p>
                <p style="font-size: 0.9rem; color: #94a3b8; margin: 0;">
                    系統將根據學生的屆別和班級自動找到對應的班導
                </p>
            </div>`,
            icon: 'question',
            iconColor: '#667eea',
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-paper-plane me-2"></i>確認發送',
            cancelButtonText: '<i class="fa-solid fa-times me-2"></i>取消',
            confirmButtonColor: '#667eea',
            cancelButtonColor: '#94a3b8',
            buttonsStyling: true,
            reverseButtons: true,
            focusConfirm: false,
            customClass: {
                popup: 'swal-notify-popup',
                title: 'swal-notify-title',
                htmlContainer: 'swal-notify-html',
                confirmButton: 'swal-notify-confirm',
                cancelButton: 'swal-notify-cancel'
            },
            showClass: {
                popup: 'animate__animated animate__fadeInUp animate__faster'
            },
            hideClass: {
                popup: 'animate__animated animate__fadeOutDown animate__faster'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // 顯示載入中
                Swal.fire({
                    title: '發送中...',
                    text: '正在發送通知給班導',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                const apiPath = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
                fetch(`${apiPath}?do=notify_class_teacher`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        student_ids: studentIds
                    })
                })
                    .then(response => response.json())
                    .then(response => {
                        Swal.close();
                        if (response.ok !== false) {
                            Swal.fire({
                                icon: 'success',
                                title: '發送成功',
                                text: response.message || '通知已成功發送給班導',
                                timer: 2000,
                                showConfirmButton: false
                            });
                            // 清除選取
                            document.querySelectorAll('.student-item').forEach(item => {
                                item.dataset.selected = 'false';
                                item.classList.remove('selected');
                            });
                            const selectAll = document.getElementById('selectAllNoTeam');
                            if (selectAll) selectAll.checked = false;
                            updateNotifyButtonState();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: '發送失敗',
                                text: response.msg || response.message || '發送通知時發生錯誤',
                                timer: 3000
                            });
                        }
                    })
                    .catch(error => {
                        Swal.close();
                        console.error('發送通知失敗:', error);
                        Swal.fire({
                            icon: 'error',
                            title: '發送失敗',
                            text: '發送通知時發生錯誤，請稍後再試',
                            timer: 3000
                        });
                    });
            }
        });
    }
}

/* ==========================================
   顯示團隊詳情 Modal
========================================== */
async function showTeamDetail(teamId) {
    console.log('showTeamDetail 被調用，teamId:', teamId);
    const overlay = document.getElementById('teamModalOverlay');
    const modalBody = document.getElementById('teamModalBody');
    const modalTitle = document.getElementById('teamModalTitle');

    if (!overlay) {
        console.error('找不到 teamModalOverlay');
        return;
    }
    if (!modalBody) {
        console.error('找不到 teamModalBody');
        return;
    }

    try {
        console.log('顯示 Modal，overlay:', overlay);

        // 若 Modal 在 #content 內，移到 body 避免被 overflow/transform 影響定位（出現在頁面底部）
        if (overlay.parentNode && overlay.parentNode !== document.body) {
            document.body.appendChild(overlay);
        }

        // 清除任何可能存在的 inline style，確保 CSS 類別能正確應用
        overlay.style.display = '';
        overlay.style.opacity = '';
        overlay.style.visibility = '';
        overlay.style.pointerEvents = '';

        // 先添加 active class
        overlay.classList.add('active');

        // 使用 requestAnimationFrame 確保 DOM 更新後再設置樣式
        requestAnimationFrame(() => {
            // 強制顯示 Modal（雙重保險）
            overlay.style.display = 'flex';
            overlay.style.visibility = 'visible';
            overlay.style.opacity = '1';
            overlay.style.pointerEvents = 'auto';
        });

        modalBody.innerHTML = '<div class="loading-indicator">載入中...</div>';

        const apiPath = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
        console.log('發送 API 請求:', `${apiPath}?do=get_team_detail&team_ID=${teamId}`);
        const response = await fetch(`${apiPath}?do=get_team_detail&team_ID=${teamId}`);
        const result = await response.json();
        console.log('API 回應:', result);

        if (!result.ok || !result.success) {
            throw new Error(result.msg || '載入失敗');
        }

        const team = result.data;
        renderTeamDetail(team, modalBody, modalTitle);

    } catch (error) {
        console.error('載入組別詳情失敗:', error);
        modalBody.innerHTML = `
            <div class="empty-state">
                <i class="fa-solid fa-exclamation-triangle"></i>
                <p>載入失敗：${error.message}</p>
            </div>
        `;
    }
}

/* ==========================================
   渲染團隊詳情
========================================== */
function renderTeamDetail(team, modalBody, modalTitle) {
    if (!team) return;
    const cohortLabel = team.cohort_name || team.cohort_ID || '-';
    const isCohortClosed = parseInt(team.cohort_status, 10) === 3;

    // 設置標題：類組名稱：團隊名稱
    if (modalTitle) {
        const groupName = team.group_name || '未分類';
        const teamName = team.team_project_name || '未命名專題';
        modalTitle.textContent = `${escapeHtml(groupName)}：${escapeHtml(teamName)}`;
    }

    // 渲染內容
    let html = '';

    // 1️⃣ 組隊狀態摘要
    const mc = team.member_count ?? 0;
    const minM = team.min_member ?? 2;
    const maxM = team.max_member ?? 5;
    const status = team.status || 'normal';
    const statusLabel = status === 'under' ? '🟡 未滿' : (status === 'over' ? '🔴 超額' : '🟢 正常');
    const statusClass = status === 'under' ? 'status-under' : (status === 'over' ? 'status-over' : 'status-normal');
    if (isCohortClosed) {
        html += `<div class="team-detail-summary" style="background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:12px;margin-bottom:12px;"><span style="color:#92400e;"><i class="fa-solid fa-lock"></i> 此屆別已結案，僅可檢視，無法修改成員。</span></div>`;
    }
    html += `
        <div class="team-detail-summary">
            <div class="team-detail-summary-row">
                <span class="summary-label">組員人數：</span>
                <span class="summary-value">${mc} / ${maxM} 人</span>
            </div>
            <div class="team-detail-summary-row">
                <span class="summary-label">最低需求：</span>
                <span class="summary-value">${minM} 人</span>
            </div>
            <div class="team-detail-summary-row">
                <span class="summary-label">狀態：</span>
                <span class="team-status-badge ${statusClass}">${statusLabel}</span>
            </div>
        </div>
    `;

    // 2️⃣ 自動警告區塊（紅框）
    const warnings = [];
    if (status === 'under') warnings.push('未達最低人數');
    if (status === 'over') warnings.push('超過人數上限');
    // 老師已超過上限、學生重複組隊：需 API 提供，此處暫略
    if (warnings.length > 0) {
        html += `
            <div class="team-detail-warnings">
                <div class="warnings-title">⚠ 風險提示</div>
                <ul class="warnings-list">
                    ${warnings.map(w => `<li>${escapeHtml(w)}</li>`).join('')}
                </ul>
            </div>
        `;
    }

    // 團隊成員（包括指導老師）
    if (team.members && team.members.length > 0) {
        // 分組：指導老師和學生
        const teachers = team.members.filter(m => m.role_ID == 4);
        const students = team.members.filter(m => m.role_ID == 6);

        // 指導老師
        if (teachers.length > 0) {
            html += `
                <div class="member-section">
                    <div class="member-section-title">指導老師</div>
                    <div class="team-members-list">
                        ${teachers.map(member => {
                const name = member.u_name || member.u_ID;
                const hasAvatar = member.u_img && member.u_img.trim() !== '';
                const avatarUrl = hasAvatar ? (member.u_img.startsWith('http') ? member.u_img : getAvatarUrl(member.u_img)) : '';
                const initial = name.charAt(0);

                let avatarHtml = '';
                if (hasAvatar) {
                    avatarHtml = `<img src="${escapeHtml(avatarUrl)}" alt="${escapeHtml(name)}" class="team-member-avatar-img" onerror="this.onerror=null;this.style.display='none';var s=this.nextElementSibling;if(s)s.style.display='inline-flex';">`;
                }
                avatarHtml += `<span class="avatar-initial" style="display:${hasAvatar ? 'none' : 'inline-flex'}">${escapeHtml(initial)}</span>`;

                return `
                            <div class="team-member-item" data-user-id="${escapeHtml(member.u_ID)}" data-role="teacher">
                                <div class="team-member-avatar">
                                    ${avatarHtml}
                                </div>
                                <div class="team-member-info">
<p class="team-member-line">
  ${escapeHtml(member.u_ID)} ｜
  ${escapeHtml(name)}
</p>
                                </div>
                                ${!isCohortClosed ? `<button class="btn-remove-member" onclick="removeTeamMember(${team.team_ID}, '${escapeHtml(member.u_ID)}', '${escapeHtml(name)}')" title="移除成員">移除</button>` : ''}
                            </div>
                        `;
            }).join('')}
                    </div>
                </div>
            `;
        }

        // 學生
        html += `
            <div class="member-section">
                <div class="member-section-header">
                    <div class="member-section-title">組別成員</div>
                    ${!isCohortClosed ? `<button class="btn-add-member" onclick="showAddMemberModal(${team.team_ID}, ${team.cohort_ID || 'null'}, ${mc}, ${maxM})">加入成員</button>` : ''}
                </div>
                <div class="team-members-list">
                    ${students.length > 0 ? students.map(member => {
            const name = member.u_name || member.u_ID;
            const hasAvatar = member.u_img && member.u_img.trim() !== '';
            const avatarUrl = hasAvatar ? (member.u_img.startsWith('http') ? member.u_img : getAvatarUrl(member.u_img)) : '';
            const initial = name.charAt(0);

            let avatarHtml = '';
            if (hasAvatar) {
                avatarHtml = `<img src="${escapeHtml(avatarUrl)}" alt="${escapeHtml(name)}" class="team-member-avatar-img" onerror="this.onerror=null;this.style.display='none';var s=this.nextElementSibling;if(s)s.style.display='inline-flex';">`;
            }
            avatarHtml += `<span class="avatar-initial" style="display:${hasAvatar ? 'none' : 'inline-flex'}">${escapeHtml(initial)}</span>`;

            return `
                            <div class="team-member-item" data-user-id="${escapeHtml(member.u_ID)}" data-role="student">
                                <div class="team-member-avatar">
                                    ${avatarHtml}
                                </div>
                                <div class="team-member-info">
                                    <p class="team-member-line">
                                      ${escapeHtml(cohortLabel)} ｜
                                      ${escapeHtml(member.class_name || '-')} ｜
                                      ${escapeHtml(member.u_ID)} ｜
                                      ${escapeHtml(name)}
                                    </p>
                                </div>
                                ${!isCohortClosed ? `<button class="btn-remove-member" onclick="removeTeamMember(${team.team_ID}, '${escapeHtml(member.u_ID)}', '${escapeHtml(name)}')" title="移除成員">移除</button>` : ''}
                            </div>
                        `;
        }).join('') : '<div class="no-members-text">尚無學生成員</div>'}
                </div>
            </div>
        `;
    } else {
        html += `
            <div class="member-section">
                <div class="member-section-header">
                    <div class="member-section-title">組別成員</div>
                    ${!isCohortClosed ? `<button class="btn-add-member" onclick="showAddMemberModal(${team.team_ID}, ${team.cohort_ID || 'null'}, ${mc}, ${maxM})">加入成員</button>` : ''}
                </div>
                <div class="team-members-list">
                    <div class="no-members-text">尚無成員</div>
                </div>
            </div>
        `;
    }

    modalBody.innerHTML = html;
}

/* ==========================================
   關閉 Modal
========================================== */
function closeTeamModal() {
    console.log('closeTeamModal 被調用');
    const overlay = document.getElementById('teamModalOverlay');
    if (overlay) {
        // 移除 active class
        overlay.classList.remove('active');

        // 清除 inline style，確保 CSS 規則生效
        requestAnimationFrame(() => {
            overlay.style.display = '';
            overlay.style.opacity = '';
            overlay.style.visibility = '';
            overlay.style.pointerEvents = '';
        });

        console.log('Modal 已關閉');
    } else {
        console.warn('找不到 teamModalOverlay，無法關閉');
    }

    // 同時關閉加入成員 Modal
    closeAddMemberModal();
}

/* ==========================================
   重置篩選器
========================================== */
function resetFilters() {
    currentFilters = {
        cohort_ID: cohort_ID,
        group_ID: null,
        grade: '',
        class_ID: null
    };

    const cohortSelect = document.getElementById('filterCohort');
    const groupSelect = document.getElementById('filterGroup');
    // const gradeSelect = document.getElementById('filterGrade');
    const classSelect = document.getElementById('filterClass');
    const searchInput = document.getElementById('searchStudent');
    const clearBtn = document.getElementById('clearSearch');

    if (cohortSelect) cohortSelect.value = cohort_ID ? String(cohort_ID) : '';
    if (groupSelect) groupSelect.value = '';
    // if (gradeSelect) gradeSelect.value = '';

    // 清除搜尋
    if (searchInput) {
        searchInput.value = '';
        clearSearchResult();
    }
    if (clearBtn) {
        clearBtn.style.display = 'none';
    }
    if (classSelect) classSelect.value = '';

    updatePageSubtitle();
    loadTeamData();
}

/* ==========================================
   工具函數
========================================== */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/* ==========================================
   初始化函數（供頁面調用）
========================================== */
function initTeamManagePage() {
    // 取得配置
    const config = window.TEAM_MANAGE_CONFIG || {};
    cohort_ID = config.cohort_ID;
    teamUserField = config.teamUserField || 'team_u_ID';
    userRoleUidField = config.userRoleUidField || 'ur_u_ID';

    // 先綁定團隊卡片點擊事件（使用事件委派，只需要綁定一次）
    bindTeamCardEvents();

    // 初始化搜尋功能
    initSearchFeature();

    // 檢查必要配置
    if (!cohort_ID) {
        console.error('TEAM_MANAGE_CONFIG 未正確設置');
        const container = document.getElementById('teamGroupsContainer');
        if (container) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fa-solid fa-exclamation-triangle"></i>
                    <p>配置錯誤：無法載入組別資料</p>
                </div>
            `;
        }
        return;
    }

    // 初始化篩選器
    currentFilters.cohort_ID = cohort_ID;

    // 載入篩選選項
    loadFilterOptions().then(() => {
        // 載入團隊資料
        loadTeamData();
    });

    // 綁定篩選器事件
    const cohortSelect = document.getElementById('filterCohort');
    const groupSelect = document.getElementById('filterGroup');
    // const gradeSelect = document.getElementById('filterGrade');
    const classSelect = document.getElementById('filterClass');
    const resetBtn = document.getElementById('filterReset') || document.getElementById('resetFilters');

    if (cohortSelect) {
        cohortSelect.addEventListener('change', function () {
            currentFilters.cohort_ID = this.value ? parseInt(this.value) : cohort_ID;
            updatePageSubtitle();
            loadTeamData();
        });
    }

    if (groupSelect) {
        groupSelect.addEventListener('change', function () {
            currentFilters.group_ID = this.value ? parseInt(this.value) : null;
            updatePageSubtitle();
            loadTeamData();
        });
    }

    // if (gradeSelect) {
    //     gradeSelect.addEventListener('change', function () {
    //         currentFilters.grade = this.value || '';
    //         loadTeamData();
    //     });
    // }

    if (classSelect) {
        classSelect.addEventListener('change', function () {
            currentFilters.class_ID = this.value ? parseInt(this.value) : null;
            loadTeamData();
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', resetFilters);
    }

    // 綁定 Modal 關閉事件
    const closeBtn = document.getElementById('teamModalClose');
    const overlay = document.getElementById('teamModalOverlay');

    if (closeBtn) {
        closeBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('關閉按鈕被點擊');
            closeTeamModal();
        });
        console.log('Modal 關閉按鈕事件已綁定');
    } else {
        console.warn('找不到 teamModalClose 按鈕');
    }

    if (overlay) {
        overlay.addEventListener('click', function (e) {
            // 點擊 overlay 背景時關閉，但不要關閉點擊 modal 本身時
            if (e.target === overlay) {
                console.log('點擊 overlay 背景，關閉 Modal');
                closeTeamModal();
            }
        });
        console.log('Modal overlay 事件已綁定');
    } else {
        console.warn('找不到 teamModalOverlay');
    }

    // ESC 鍵關閉
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const overlay = document.getElementById('teamModalOverlay');
            if (overlay && overlay.classList.contains('active')) {
                console.log('ESC 鍵按下，關閉 Modal');
                closeTeamModal();
            }
        }
    });
}

// 自動初始化（如果配置已存在）
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(initTeamManagePage, 100);
    });
} else {
    setTimeout(initTeamManagePage, 100);
}

// 導出初始化函數供外部調用
window.initTeamManagePage = initTeamManagePage;

// 導出加入成員相關函數供 onclick 調用
window.showAddMemberModal = showAddMemberModal;
window.addTeamMember = addTeamMember;
window.closeAddMemberModal = closeAddMemberModal;

/* ==========================================
   移除團隊成員
========================================== */
async function removeTeamMember(teamId, userId, userName) {
    if (!window.Swal) {
        if (!confirm(`確定要移除成員「${userName}」嗎？`)) {
            return;
        }
    } else {
        const confirmResult = await Swal.fire({
            icon: 'warning',
            title: '確認移除',
            text: `確定要移除成員「${userName}」嗎？`,
            showCancelButton: true,
            confirmButtonText: '確定移除',
            cancelButtonText: '取消',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            reverseButtons: true
        });

        if (!confirmResult.isConfirmed) {
            return;
        }
    }

    try {
        const apiPath = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
        const formData = new FormData();
        formData.append('team_ID', teamId);
        formData.append('user_ID', userId);

        const response = await fetch(`${apiPath}?do=remove_team_member`, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.ok) {
            if (window.Swal) {
                await Swal.fire({
                    icon: 'success',
                    title: '移除成功',
                    text: '成員已成功移除',
                    timer: 1500,
                    showConfirmButton: false
                });
            }
            // 重新載入團隊詳情（如果 Modal 是打開的）
            const overlay = document.getElementById('teamModalOverlay');
            if (overlay && overlay.classList.contains('active')) {
                await showTeamDetail(teamId);
            }
            // 重新載入團隊列表
            if (typeof loadTeamData === 'function') {
                await loadTeamData();
            }
        } else {
            if (window.Swal) {
                Swal.fire({
                    icon: 'error',
                    title: '移除失敗',
                    text: result.msg || '未知錯誤',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#ef4444'
                });
            } else {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'error',
                        title: '移除失敗',
                        text: result.msg || '未知錯誤',
                        confirmButtonText: '確定',
                        confirmButtonColor: '#ef4444'
                    });
                } else {
                    alert('移除失敗：' + (result.msg || '未知錯誤'));
                }
            }
        }
    } catch (error) {
        console.error('移除成員失敗:', error);
        if (window.Swal) {
            Swal.fire({
                icon: 'error',
                title: '錯誤',
                text: '移除失敗：' + error.message,
                confirmButtonText: '確定',
                confirmButtonColor: '#ef4444'
            });
        } else {
            if (window.Swal) {
                Swal.fire({
                    icon: 'error',
                    title: '移除失敗',
                    text: error.message,
                    confirmButtonText: '確定',
                    confirmButtonColor: '#ef4444'
                });
            } else {
                alert('移除失敗：' + error.message);
            }
        }
    }
}

/* ==========================================
   顯示加入成員 Modal
========================================== */
let currentTeamIdForAdd = null;
let currentCohortIdForAdd = null;

async function showAddMemberModal(teamId, cohortId) {
    currentTeamIdForAdd = teamId;
    currentCohortIdForAdd = cohortId;

    let addMemberModal = document.getElementById('addMemberModalOverlay');
    if (!addMemberModal) {
        // 創建 Modal
        const modalHtml = `
  <div class="team-modal-overlay" id="addMemberModalOverlay">
    <div class="team-modal" style="max-width: 680px;">
      <div class="team-modal-header">
        <h3 class="team-modal-title">加入成員</h3>
        <button class="team-modal-close" onclick="closeAddMemberModal()">×</button>
      </div>

      <div class="team-modal-body">

        <!-- ✅ 舊版 Bootstrap 風格的工具列 -->
        <div class="addmember-toolbar">
          <div class="addmember-row">
            <input id="addMemberSearch" type="text" class="addmember-input"
                   placeholder="搜尋姓名或學號...">
          </div>

          <div class="addmember-row addmember-row-2">
            <select id="addMemberFilterCohort" class="addmember-select">
              <option value="">全部屆別</option>
            </select>

            <select id="addMemberFilterClass" class="addmember-select">
              <option value="">全部班級</option>
            </select>

            <button type="button" id="addMemberClear" class="addmember-btn">
              清除
            </button>
          </div>
        </div>

        <div id="availableStudentsList" class="available-students-list">
          <div class="text-center py-3">載入中...</div>
        </div>

      </div>
    </div>
  </div>
`;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        addMemberModal = document.getElementById('addMemberModalOverlay');
    }

    const studentsList = document.getElementById('availableStudentsList');

    if (addMemberModal) {
        addMemberModal.classList.add('active');
    }
    if (studentsList) {
        studentsList.innerHTML = '<div class="text-center py-3">載入中...</div>';
    }

    try {
        const apiPath = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
        const url = cohortId
            ? `${apiPath}?do=get_available_students&team_ID=${teamId}&cohort_ID=${cohortId}`
            : `${apiPath}?do=get_available_students&team_ID=${teamId}`;

        const response = await fetch(url);
        const result = await response.json();

        if (result.ok && Array.isArray(result.data)) {
            if (result.data.length === 0) {
                studentsList.innerHTML = '<div class="text-center text-muted py-4">目前沒有可加入的學生</div>';

            } else {
                studentsList.innerHTML = result.data.map(student => {
                    const name = student.u_name || student.u_ID;
                    const hasAvatar = student.u_img && student.u_img.trim() !== '';
                    const cleanImgName = hasAvatar ? student.u_img.trim().replace(/\s+/g, '%20') : '';
                    const avatarUrl = hasAvatar ? (student.u_img.startsWith('http') ? student.u_img : `headshot/${cleanImgName}`) : '';
                    const initial = name.charAt(0);

                    let avatarHtml = '';
                    if (hasAvatar) {
                        avatarHtml = `<img src="${escapeHtml(avatarUrl)}" alt="${escapeHtml(name)}" class="student-avatar-img" onerror="this.onerror=null; this.textContent='${escapeHtml(initial)}';">`;
                    } else {
                        avatarHtml = `<span class="avatar-initial">${escapeHtml(initial)}</span>`;
                    }

                    return `
  <div class="available-student-item"
       data-user-id="${escapeHtml(student.u_ID)}"
       data-student-id="${escapeHtml(student.u_ID)}"
       data-user-name="${escapeHtml(name)}"
       data-cohort="${escapeHtml(student.cohort_name || '')}"
       data-class="${escapeHtml(student.class_name || '')}">

                            <div class="student-avatar">
                                ${avatarHtml}
                            </div>
                            <div class="student-info">
                                <p class="student-name">${escapeHtml(name)}</p>
                                <p class="student-id">${escapeHtml(student.u_ID)}</p>
                                ${student.class_name ? `<p class="student-class">${escapeHtml(student.class_name)}</p>` : ''}
                            </div>
                            <button class="btn-add-student" onclick="addTeamMember(${teamId}, '${escapeHtml(student.u_ID)}', '${escapeHtml(name)}')">
                                加入
                            </button>
                        </div>
                    `;
                }).join('');
                initAddMemberFilters(result.data);
                applyAddMemberFilters();
                await loadCohortOptionsForAddMember(apiPath, result.data);

            }
        } else {
            studentsList.innerHTML = '<div class="text-center text-danger py-4">載入失敗：' + (result.msg || '未知錯誤') + '</div>';
        }
    } catch (error) {
        console.error('載入可加入學生失敗:', error);
        studentsList.innerHTML = '<div class="text-center text-danger py-4">載入失敗：' + error.message + '</div>';
    }
}
async function loadCohortOptionsForAddMember(apiPath, studentsData) {
    const cohortSel = document.getElementById('addMemberFilterCohort');
    if (!cohortSel) return;

    // 先清空
    cohortSel.innerHTML = `<option value="">全部屆別</option>`;

    // ✅ 先嘗試從 cohortdata API 抓
    try {
        const r = await fetch(`${apiPath}?do=get_cohort_options`);
        const json = await r.json();
        console.log('[get_cohort_options]', json);

        // ⚠️ 兼容不同回傳格式：ok 或 success 任一為 true 就算成功
        const ok = (json.ok === true) || (json.success === true);
        const rows = json.data;

        if (ok && Array.isArray(rows) && rows.length > 0) {
            cohortSel.innerHTML =
                `<option value="">全部屆別</option>` +
                rows.map(c => {
                    const name = (c.cohort_name || '').trim();
                    if (!name) return '';
                    return `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`;
                }).join('');
            return; // ✅ 成功就結束
        }
    } catch (e) {
        console.warn('get_cohort_options 抓取失敗，改用 fallback：', e);
    }

    // ✅ fallback：從學生資料 result.data 裡自己整理 cohort_name（保證有值）
    const set = new Set();
    (studentsData || []).forEach(s => {
        const name = (s.cohort_name || '').trim();
        if (name) set.add(name);
    });

    if (set.size > 0) {
        cohortSel.innerHTML =
            `<option value="">全部屆別</option>` +
            Array.from(set).sort().map(name =>
                `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`
            ).join('');
    }
}
// ================================
// Add Member Modal (reuse noTeamMembers from get_team_management_data)
// ================================
let addMemberState = {
    teamId: null,
    cohortId: null,     // current selected cohort_ID
    memberCount: 0,     // 目前組員數（學生）
    maxMember: 5,      // 人數上限
    q: '',
    classId: '',
    data: []            // noTeamMembers full list
};

function ensureAddMemberModal() {
    let modal = document.getElementById('addMemberModalOverlay');
    if (modal) return modal;

    const html = `
    <div class="team-modal-overlay" id="addMemberModalOverlay">
      <div class="team-modal" style="max-width: 680px;">
        <div class="team-modal-header">
          <h3 class="team-modal-title">加入成員</h3>
          <button class="team-modal-close" type="button" id="btnCloseAddMember">×</button>
        </div>

        <div class="team-modal-body">
          <div id="addMemberFullBanner" class="addmember-full-banner" style="display:none;">
            ⚠ 已達上限，無法再加入成員
          </div>
          <div class="addmember-toolbar">
            <div class="addmember-row">
              <input id="addMemberSearch" type="text" class="addmember-input"
                     placeholder="搜尋姓名或學號...">
            </div>

            <div class="addmember-row addmember-row-2">
              <select id="addMemberFilterCohort" class="addmember-select">
                <option value="">全部屆別</option>
              </select>



              <select id="addMemberFilterClass" class="addmember-select">
                <option value="">全部班級</option>
              </select>

              <button type="button" id="addMemberClear" class="addmember-btn">清除</button>
            </div>
          </div>

          <div id="availableStudentsList" class="available-students-list">
            <div class="text-center py-3">載入中...</div>
          </div>
        </div>
      </div>
    </div>
  `;
    document.body.insertAdjacentHTML('beforeend', html);

    modal = document.getElementById('addMemberModalOverlay');

    // close
    document.getElementById('btnCloseAddMember').onclick = closeAddMemberModal;
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeAddMemberModal();
    });

    return modal;
}

function closeAddMemberModal() {
    const modal = document.getElementById('addMemberModalOverlay');
    if (modal) modal.classList.remove('active');
}

// 把 student.u_img 轉成可用 url（這裡用根目錄 /headshot/ 最穩）
function resolveAvatarUrl(u_img) {
    if (!u_img) return '';
    const s = String(u_img).trim();
    if (!s) return '';
    if (s.startsWith('http')) return s;
    return `/headshot/${encodeURIComponent(s)}`; // ✅ 避免 pages/ 相對路徑炸掉
}

function renderAddMemberList(rows) {
    const list = document.getElementById('availableStudentsList');
    if (!list) return;

    if (!rows.length) {
        list.innerHTML = `<div class="text-center text-muted py-4">找不到符合條件的學生</div>`;
        return;
    }

    const isFull = (addMemberState.memberCount || 0) >= (addMemberState.maxMember || 5);
    const btnDisabled = isFull ? ' disabled' : '';
    const btnClass = isFull ? 'btn-add-student btn-add-student-disabled' : 'btn-add-student';
    const btnText = isFull ? '不可加入' : '加入';
    const reasonHtml = isFull ? '<div class="text-muted small addmember-reason">原因：組別已達上限</div>' : '';

    list.innerHTML = rows.map(student => {
        const name = student.u_name || student.u_ID;
        const avatarUrl = resolveAvatarUrl(student.u_img);
        const initial = (name && name.length) ? name.charAt(0) : '？';

        const avatarHtml = avatarUrl
            ? `<img src="${escapeHtml(avatarUrl)}" class="student-avatar-img" alt="${escapeHtml(name)}"
              onerror="this.onerror=null;this.style.display='none';this.parentNode.querySelector('.avatar-initial').style.display='inline-flex';">`
            : '';

        return `
      <div class="available-student-item"
           data-student-id="${escapeHtml(student.u_ID)}"
           data-user-id="${escapeHtml(student.u_ID)}"
           data-student-name="${escapeHtml(name)}"
           data-cohort-id="${escapeHtml(student.cohort_ID ?? '')}"
           data-class-id="${escapeHtml(student.class_ID ?? '')}"
           data-grade="${escapeHtml(student.enroll_grade ?? '')}">
        <div class="student-avatar">
          ${avatarHtml}
          <span class="avatar-initial" style="display:${avatarUrl ? 'none' : 'inline-flex'}">${escapeHtml(initial)}</span>
        </div>

        <div class="student-info">
          <p class="student-name">${escapeHtml(name)}</p>
          <p class="student-id">${escapeHtml(student.u_ID)}</p>
          <p class="student-status">目前狀態：未分組</p>
          <p class="student-class">
            ${escapeHtml(student.cohort_name || '')}
            ${student.enroll_grade ? `・${escapeHtml(student.enroll_grade)}年級` : ''}
            ${student.class_name ? `・${escapeHtml(student.class_name)}` : ''}
          </p>
        </div>

        <div class="addmember-btn-wrap">
          <button class="${btnClass}" type="button"${btnDisabled}
                  onclick="${isFull ? '' : `addTeamMember(${addMemberState.teamId}, '${escapeHtml(student.u_ID)}', '${escapeHtml(name)}')`}">
            ${btnText}
          </button>
          ${reasonHtml}
        </div>
      </div>
    `;
    }).join('');
}

function populateAddMemberDropdowns() {
    const cohortSel = document.getElementById('addMemberFilterCohort');
    //   const gradeSel  = document.getElementById('addMemberFilterGrade');
    const classSel = document.getElementById('addMemberFilterClass');
    if (!cohortSel || !classSel) return;

    // cohorts：用你現成的 get_cohort_options（value=cohort_ID）
    (async () => {
        try {
            const apiPath = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
            const r = await fetch(`${apiPath}?do=get_cohort_options`);
            const json = await r.json();
            if (!json.ok || !json.success) return;

            cohortSel.innerHTML = `<option value="">全部屆別</option>` +
                json.data.map(c => {
                    const status = parseInt(c.cohort_status ?? 1, 10);
                    const label = c.cohort_name + (status === 3 ? '（已結案）' : '');
                    return `<option value="${escapeHtml(c.cohort_ID)}">${escapeHtml(label)}</option>`;
                }).join('');

            // 預設：打開 modal 時如果有傳 cohortId，就先選它
            if (addMemberState.cohortId) cohortSel.value = String(addMemberState.cohortId);
        } catch (e) { }
    })();

    // grades/classes：從資料本身生（跟下面 noTeamMembers 一樣）
    const grades = new Set();
    const classes = new Map(); // class_ID -> class_name
    addMemberState.data.forEach(s => {
        if (s.enroll_grade !== null && s.enroll_grade !== undefined && String(s.enroll_grade).trim() !== '') {
            grades.add(String(s.enroll_grade));
        }
        if (s.class_ID) classes.set(String(s.class_ID), s.class_name || String(s.class_ID));
    });

    //   gradeSel.innerHTML = `<option value="">全部年級</option>` +
    //     Array.from(grades).sort().map(g => `<option value="${escapeHtml(g)}">${escapeHtml(g)}年級</option>`).join('');

    classSel.innerHTML = `<option value="">全部班級</option>` +
        Array.from(classes.entries()).sort((a, b) => a[0].localeCompare(b[0])).map(([id, name]) =>
            `<option value="${escapeHtml(id)}">${escapeHtml(name)}</option>`
        ).join('');
}

// ✅ 核心：套用搜尋/篩選（純前端，不改 case）
function applyAddMemberFiltersClient() {
    const q = (addMemberState.q || '').toLowerCase();
    const cohortId = addMemberState.cohortId ? String(addMemberState.cohortId) : '';
    const grade = addMemberState.grade ? String(addMemberState.grade) : '';
    const classId = addMemberState.classId ? String(addMemberState.classId) : '';

    const filtered = addMemberState.data.filter(s => {
        const okCohort = !cohortId || String(s.cohort_ID || '') === cohortId;
        // const okGrade  = !grade || String(s.enroll_grade || '') === grade;
        const okClass = !classId || String(s.class_ID || '') === classId;

        const name = String(s.u_name || '').toLowerCase();
        const sid = String(s.u_ID || '').toLowerCase();
        const okQ = !q || name.includes(q) || sid.includes(q);

        return okCohort && okClass && okQ;
    });

    renderAddMemberList(filtered);
}

function bindAddMemberControls() {
    const searchEl = document.getElementById('addMemberSearch');
    const cohortSel = document.getElementById('addMemberFilterCohort');
    //   const gradeSel  = document.getElementById('addMemberFilterGrade');
    const classSel = document.getElementById('addMemberFilterClass');
    const clearBtn = document.getElementById('addMemberClear');

    if (!searchEl || !cohortSel || !classSel) return;

    searchEl.oninput = () => {
        addMemberState.q = searchEl.value.trim();
        applyAddMemberFiltersClient();
    };

    cohortSel.onchange = () => {
        addMemberState.cohortId = cohortSel.value ? parseInt(cohortSel.value, 10) : null;
        applyAddMemberFiltersClient();
    };

    //   gradeSel.onchange = () => {
    //     addMemberState.grade = gradeSel.value || '';
    //     applyAddMemberFiltersClient();
    //   };

    classSel.onchange = () => {
        addMemberState.classId = classSel.value || '';
        applyAddMemberFiltersClient();
    };

    clearBtn.onclick = () => {
        searchEl.value = '';
        cohortSel.value = '';
        // gradeSel.value = '';
        classSel.value = '';
        addMemberState.q = '';
        addMemberState.cohortId = null;
        // addMemberState.grade = '';
        addMemberState.classId = '';
        applyAddMemberFiltersClient();
    };
}

// ✅ 入口：showAddMemberModal 改成「拉 noTeamMembers」
// 不改 PHP case：直接叫 get_team_management_data 拿 noTeamMembers
async function showAddMemberModal(teamId, cohortId, memberCount, maxMember) {
    addMemberState.teamId = teamId;
    addMemberState.cohortId = cohortId ? parseInt(cohortId, 10) : null;
    addMemberState.memberCount = (memberCount != null && memberCount !== '') ? parseInt(memberCount, 10) : 0;
    addMemberState.maxMember = (maxMember != null && maxMember !== '') ? parseInt(maxMember, 10) : 5;
    addMemberState.q = '';
    addMemberState.grade = '';
    addMemberState.classId = '';

    const modal = ensureAddMemberModal();
    modal.classList.add('active');

    const list = document.getElementById('availableStudentsList');
    if (list) list.innerHTML = `<div class="text-center py-3">載入中...</div>`;

    const fullBanner = document.getElementById('addMemberFullBanner');
    if (fullBanner) {
        fullBanner.style.display = (addMemberState.memberCount >= addMemberState.maxMember) ? 'block' : 'none';
    }

    try {
        const apiPath = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
        const params = new URLSearchParams();

        // ✅ 你想用屆別篩：就丟 cohort_ID
        if (addMemberState.cohortId) params.set('cohort_ID', addMemberState.cohortId);

        const r = await fetch(`${apiPath}?do=get_team_management_data&${params.toString()}`);
        const json = await r.json();
        if (!json.ok || !json.success) throw new Error(json.msg || '載入失敗');

        // ✅ 重用下面區塊的資料
        addMemberState.data = (json.data?.noTeamMembers || []);

        populateAddMemberDropdowns();
        bindAddMemberControls();
        applyAddMemberFiltersClient();
    } catch (err) {
        if (list) list.innerHTML = `<div class="text-center text-danger py-4">載入失敗：${escapeHtml(err.message)}</div>`;
    }
}

// 導出給 onclick 用
window.showAddMemberModal = showAddMemberModal;

/* ==========================================
   關閉加入成員 Modal
========================================== */
function closeAddMemberModal() {
    const addMemberModal = document.getElementById('addMemberModalOverlay');
    if (addMemberModal) {
        addMemberModal.classList.remove('active');
    }
}
// ================================
// 加入成員 Modal：搜尋 + 屆別/班級下拉（舊版 Bootstrap 風格）
// ================================
let addMemberAllData = [];

function initAddMemberFilters(data) {
    addMemberAllData = Array.isArray(data) ? data : [];

    const cohortSel = document.getElementById('addMemberFilterCohort');
    const classSel = document.getElementById('addMemberFilterClass');
    const searchEl = document.getElementById('addMemberSearch');
    const clearBtn = document.getElementById('addMemberClear');

    if (!cohortSel || !classSel || !searchEl) return;

    // 1) 組 options
    const cohorts = new Set();
    const classes = new Set();

    addMemberAllData.forEach(s => {
        const cName = (s.cohort_name || '').trim();
        const cls = (s.class_name || '').trim();
        if (cName) cohorts.add(cName);
        if (cls) classes.add(cls);
    });

    // 2) 填入 options（保留第一個「全部」）
    cohortSel.innerHTML = `<option value="">全部屆別</option>` +
        Array.from(cohorts).sort().map(v => `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`).join('');

    classSel.innerHTML = `<option value="">全部班級</option>` +
        Array.from(classes).sort().map(v => `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`).join('');

    // 3) 綁事件
    const onChange = () => applyAddMemberFilters();
    cohortSel.onchange = onChange;
    classSel.onchange = onChange;

    searchEl.oninput = () => applyAddMemberFilters();

    if (clearBtn) {
        clearBtn.onclick = () => {
            searchEl.value = '';
            cohortSel.value = '';
            classSel.value = '';
            applyAddMemberFilters();
        };
    }
}

function applyAddMemberFilters() {
    const searchEl = document.getElementById('addMemberSearch');
    const cohortSel = document.getElementById('addMemberFilterCohort');
    const classSel = document.getElementById('addMemberFilterClass');

    const q = (searchEl?.value || '').trim().toLowerCase();
    const cohortVal = (cohortSel?.value || '').trim();
    const classVal = (classSel?.value || '').trim();

    // 逐列顯示/隱藏（不重畫 DOM，效能好、也比較像舊版）
    const items = document.querySelectorAll('#availableStudentsList .available-student-item');
    let visible = 0;

    items.forEach(item => {
        const id = item.getAttribute('data-user-id') || '';
        const name = (item.getAttribute('data-user-name') || '').toLowerCase();
        const studentId = (item.getAttribute('data-student-id') || '').toLowerCase();
        const cName = item.getAttribute('data-cohort') || '';
        const cls = item.getAttribute('data-class') || '';

        const okSearch = !q || name.includes(q) || studentId.includes(q) || id.toLowerCase().includes(q);
        const okCohort = !cohortVal || cName === cohortVal;
        const okClass = !classVal || cls === classVal;

        const show = okSearch && okCohort && okClass;
        item.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    // 沒結果提示
    const list = document.getElementById('availableStudentsList');
    if (!list) return;

    let empty = list.querySelector('.addmember-empty');
    if (visible === 0) {
        if (!empty) {
            empty = document.createElement('div');
            empty.className = 'addmember-empty';
            empty.textContent = '找不到符合條件的學生';
            list.appendChild(empty);
        }
    } else {
        if (empty) empty.remove();
    }
}

// 點擊外部區域關閉加入成員 Modal
document.addEventListener('click', (e) => {
    const addMemberModal = document.getElementById('addMemberModalOverlay');
    if (addMemberModal && e.target === addMemberModal) {
        closeAddMemberModal();
    }
});

/* ==========================================
   添加團隊成員
========================================== */
async function addTeamMember(teamId, userId, userName) {
    try {
        const apiPath = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
        const formData = new FormData();
        formData.append('team_ID', teamId);
        formData.append('user_ID', userId);

        const response = await fetch(`${apiPath}?do=add_team_member`, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.ok) {
            // 關閉加入成員 Modal
            closeAddMemberModal();
            // 重新載入團隊詳情（如果 Modal 是打開的）
            const overlay = document.getElementById('teamModalOverlay');
            if (overlay && overlay.classList.contains('active')) {
                await showTeamDetail(teamId);
            }
            // 重新載入團隊列表
            if (typeof loadTeamData === 'function') {
                await loadTeamData();
            }
        } else {
            if (window.Swal) {
                Swal.fire({
                    icon: 'error',
                    title: '加入失敗',
                    text: result.msg || '未知錯誤',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#ef4444'
                });
            } else {
                alert('加入失敗：' + (result.msg || '未知錯誤'));
            }
        }
    } catch (error) {
        console.error('加入成員失敗:', error);
        if (window.Swal) {
            Swal.fire({
                icon: 'error',
                title: '加入失敗',
                text: error.message,
                confirmButtonText: '確定',
                confirmButtonColor: '#ef4444'
            });
        } else {
            alert('加入失敗：' + error.message);
        }
    }
}

/* ==========================================
   搜尋學生功能
========================================== */
let searchDebounceTimer = null;
let currentSearchResult = null;

function initSearchFeature() {
    const searchInput = document.getElementById('searchStudent');
    const clearBtn = document.getElementById('clearSearch');

    if (!searchInput) return;

    // 即時過濾表格（客戶端過濾）
    searchInput.addEventListener('input', (e) => {
        const query = e.target.value.trim().toLowerCase();

        if (clearBtn) {
            clearBtn.style.display = query ? 'block' : 'none';
        }

        // 過濾表格行
        filterTableRows(query);
    });

    // 清除按鈕
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            clearBtn.style.display = 'none';
            filterTableRows('');
        });
    }
}

/* ==========================================
   客戶端表格過濾功能
========================================== */
function filterTableRows(query) {
    const tableRows = document.querySelectorAll('.team-excel-row');
    const activePill = document.querySelector('.btn-filter-pill.active, .btn-filter-quick.active');
    const filter = activePill?.dataset.filter || 'all';

    tableRows.forEach(row => {
        const searchText = row.getAttribute('data-search-text') || '';
        const matchesSearch = !query || searchText.toLowerCase().includes(query);
        const status = row.dataset.teamStatus || '';
        const isFull = row.dataset.teamFull === '1';
        const isAvailable = row.dataset.teamAvailable === '1';
        const matchesPill = filter === 'all' || (filter === 'under' && status === 'under') || (filter === 'over' && status === 'over') || (filter === 'full' && isFull) || (filter === 'available' && isAvailable);
        row.style.display = (matchesSearch && matchesPill) ? '' : 'none';
    });

    // 檢查是否有結果
    const visibleRows = Array.from(tableRows).filter(row => row.style.display !== 'none');
    const tbody = document.querySelector('.team-excel-table tbody');

    // 如果沒有可見的行，顯示提示
    if (visibleRows.length === 0 && tbody) {
        const existingNoResults = tbody.querySelector('.no-results-row');
        if (!existingNoResults) {
            const noResultsRow = document.createElement('tr');
            noResultsRow.className = 'no-results-row';
            noResultsRow.innerHTML = `
                <td colspan="7" class="text-center text-muted py-4">
                    <i class="fa-solid fa-search"></i> 找不到符合條件的資料
                </td>
            `;
            tbody.appendChild(noResultsRow);
        }
    } else {
        // 移除提示行
        const noResultsRow = tbody?.querySelector('.no-results-row');
        if (noResultsRow) {
            noResultsRow.remove();
        }
    }
}

async function searchStudent(query) {
    if (!query || query.length < 1) {
        return;
    }

    try {
        const apiPath = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
        const response = await fetch(`${apiPath}?do=search_student_team&query=${encodeURIComponent(query)}`);
        const result = await response.json();

        if (!result.ok || !result.success) {
            if (window.Swal) {
                Swal.fire({
                    icon: 'info',
                    title: '搜尋結果',
                    text: result.message || '找不到符合條件的學生',
                    timer: 2000,
                    showConfirmButton: false
                });
            }
            return;
        }

        if (!result.found || !result.data || result.data.length === 0) {
            if (window.Swal) {
                Swal.fire({
                    icon: 'info',
                    title: '搜尋結果',
                    text: '找不到符合條件的學生',
                    timer: 2000,
                    showConfirmButton: false
                });
            }
            return;
        }

        currentSearchResult = result.data;

        // 高亮並展開找到的組別
        highlightAndExpandGroups(result.data);

        // 顯示成功訊息
        if (window.Swal) {
            const studentCount = result.data.reduce((sum, group) => {
                return sum + group.teams.reduce((teamSum, team) => teamSum + team.students.length, 0);
            }, 0);
            Swal.fire({
                icon: 'success',
                title: '找到學生',
                html: `找到 <strong>${studentCount}</strong> 位符合條件的學生<br>已自動展開相關組別`,
                timer: 2000,
                showConfirmButton: false
            });
        }

    } catch (error) {
        console.error('搜尋失敗:', error);
        if (window.Swal) {
            Swal.fire({
                icon: 'error',
                title: '搜尋失敗',
                text: error.message,
                timer: 2000,
                showConfirmButton: false
            });
        }
    }
}

function highlightAndExpandGroups(searchData) {
    // 移除之前的高亮
    document.querySelectorAll('.team-group-section.search-highlight').forEach(el => {
        el.classList.remove('search-highlight');
    });
    document.querySelectorAll('.team-card.search-highlight').forEach(el => {
        el.classList.remove('search-highlight');
    });

    // 為每個找到的組別添加高亮
    searchData.forEach(groupData => {
        // 先嘗試根據 data 屬性查找
        let groupSection = document.querySelector(`.team-group-section[data-group-id="${groupData.group_ID}"][data-cohort-id="${groupData.cohort_ID}"]`);

        // 如果找不到，嘗試根據組別名稱查找
        if (!groupSection) {
            const allGroupSections = document.querySelectorAll('.team-group-section');
            allGroupSections.forEach(section => {
                const title = section.querySelector('.group-title');
                if (title && title.textContent.trim() === groupData.group_name) {
                    groupSection = section;
                }
            });
        }

        if (groupSection) {
            groupSection.classList.add('search-highlight');
            groupSection.scrollIntoView({ behavior: 'smooth', block: 'center' });

            // 高亮相關的團隊卡片
            groupData.teams.forEach(team => {
                const teamCard = groupSection.querySelector(`.team-card[data-team-id="${team.team_ID}"]`);
                if (teamCard) {
                    teamCard.classList.add('search-highlight');
                }
            });
        }
    });
}

function clearSearchResult() {
    currentSearchResult = null;
    document.querySelectorAll('.team-group-section.search-highlight').forEach(el => {
        el.classList.remove('search-highlight');
    });
    document.querySelectorAll('.team-card.search-highlight').forEach(el => {
        el.classList.remove('search-highlight');
    });
}
