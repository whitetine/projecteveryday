/**
 * 歷屆成果管理頁面 - JavaScript
 */

(function() {
    'use strict';

    const API_BASE = 'pages/history_api.php';
    const TOGGLE_API = 'pages/archive_toggle_download.php';
    let currentSubmissions = [];
    let currentFilters = {
        search: '',
        cohort_ID: '',
        group_ID: ''
    };

    /**
     * 初始化頁面
     */
    async function initPage() {
        bindEvents(); // 先綁定事件
        await loadFilterOptions(); // 載入篩選選項
        await loadProjects(); // 載入專題列表（等待載入完成）
    }

    /**
     * 載入篩選選項
     */
    async function loadFilterOptions() {
        try {
            const response = await fetch(`${API_BASE}?do=get_all_cohorts`);
            const data = await response.json();
            
            // 🔹 【修復】API 返回的是 data.data，不是 data.cohorts
            if (data.success && data.data) {
                const cohortSelect = document.getElementById('cohortSelect');
                if (cohortSelect) {
                    cohortSelect.innerHTML = '<option value="">全部</option>';
                    data.data.forEach(cohort => {
                        const option = document.createElement('option');
                        option.value = String(cohort.cohort_ID);
                        option.textContent = cohort.cohort_name || cohort.year_label || `${cohort.cohort_ID}級`;
                        cohortSelect.appendChild(option);
                    });
                }
                const cohortDownloadSelect = document.getElementById('cohortDownloadSelect');
                if (cohortDownloadSelect) {
                    cohortDownloadSelect.innerHTML = '<option value="">請選擇學年度</option>';
                    data.data.forEach(cohort => {
                        const option = document.createElement('option');
                        option.value = String(cohort.cohort_ID);
                        option.textContent = cohort.cohort_name || cohort.year_label || `${cohort.cohort_ID}級`;
                        cohortDownloadSelect.appendChild(option);
                    });
                }
            }

            const groupResponse = await fetch(`${API_BASE}?do=get_all_groups`);
            const groupData = await groupResponse.json();
            
            // 🔹 【修復】API 返回的是 data.data，不是 data.groups
            if (groupData.success && groupData.data) {
                const groupSelect = document.getElementById('groupSelect');
                if (groupSelect) {
                    // 清空現有選項（保留"全部"）
                    groupSelect.innerHTML = '<option value="">全部</option>';
                    
                    // 添加類組選項（從資料庫 groupdata 表動態讀取，不寫死）
                    groupData.data.forEach(group => {
                        const option = document.createElement('option');
                        option.value = String(group.group_ID);
                        option.textContent = group.group_name;
                        groupSelect.appendChild(option);
                    });
                }
            }
        } catch (error) {
            console.error('載入篩選選項失敗:', error);
        }
    }

    /**
     * 載入整屆檔案類型（供整屆下載設定使用）
     */
    async function loadCohortFileTypes(cohort_ID) {
        const container = document.getElementById('cohortDownloadFileTypes');
        const listEl = document.getElementById('cohortDownloadFileTypesList');
        if (!container || !listEl) return;
        listEl.innerHTML = '<span class="text-muted"><i class="fa-solid fa-spinner fa-spin"></i> 載入中…</span>';
        container.style.display = 'block';
        try {
            const response = await fetch(`${API_BASE}?do=get_cohort_file_types&cohort_ID=${cohort_ID}`);
            const data = await response.json();
            if (!data.success || !Array.isArray(data.data) || data.data.length === 0) {
                listEl.innerHTML = '<span class="text-muted">此屆尚無多檔案資料</span>';
                return;
            }
            listEl.innerHTML = data.data.map(item => {
                const total = item.total || 0;
                const allowed = item.allowed || 0;
                const allOn = total > 0 && allowed === total;
                const allOff = allowed === 0;
                const checked = allOn;
                
                let statusText = '';
                let statusColor = '';
                if (total === 0) {
                    statusText = '尚無檔案';
                    statusColor = '#6c757d';
                } else if (allOn) {
                    statusText = '全部開放';
                    statusColor = '#28a745';
                } else if (allOff) {
                    statusText = '全部不開放';
                    statusColor = '#dc3545';
                } else {
                    statusText = '部分開放';
                    statusColor = '#ffc107';
                }
                
                const safeType = escapeHtml(item.file_type);
                const label = escapeHtml(item.label);
                const labelId = `label_cohort_${cohort_ID}_${safeType}`;
                const rowId = `row_cohort_${cohort_ID}_${safeType}`;
                const countId = `count_cohort_${cohort_ID}_${safeType}`;
                const statusId = `status_cohort_${cohort_ID}_${safeType}`;
                const inputId = `input_cohort_${cohort_ID}_${safeType}`;

                return `
                    <div class="cohort-file-type-row" id="${rowId}">
                        <div class="cohort-file-type-info">
                            <div class="cohort-file-type-name">${label}</div>
                            <div class="cohort-file-type-meta">
                                <span id="${statusId}" class="cohort-file-type-status" style="color: ${statusColor}; font-weight: 600;">${statusText}</span>
                                <span id="${countId}" class="cohort-file-type-count text-muted">${allowed}/${total} 筆已開放</span>
                            </div>
                        </div>
                        <div class="cohort-file-type-toggle">
                            <label for="${inputId}" id="${labelId}" class="cohort-file-type-toggle-label" style="color: ${checked ? '#28a745' : '#dc3545'}; font-weight: bold; cursor: pointer;">${checked ? '開放' : '不開放'} ${label}</label>
                            <label class="file-toggle-switch mb-0">
                                <input type="checkbox" id="${inputId}" ${checked ? 'checked' : ''} data-file-type="${safeType}" data-label="${label}"
                                    onchange="updateCohortToggleUI('${labelId}', '${label}', this.checked); batchSetDownloadByCohortAndFileType(${cohort_ID}, '${safeType}', this.checked ? 1 : 0, this)">
                                <span class="toggle-slider">
                                    <span class="toggle-slider-button"></span>
                                </span>
                            </label>
                            <span class="cohort-file-type-toggle-hint text-muted">${checked ? '開啟中' : '關閉中'}</span>
                        </div>
                    </div>
                `;
            }).join('');
        } catch (e) {
            console.error('loadCohortFileTypes error', e);
            listEl.innerHTML = '<span class="text-danger">載入失敗，請稍後再試</span>';
        }
    }

    /**
     * 即時更新整屆檔案類型開關的 UI 文字
     */
    window.updateCohortToggleUI = function(labelId, typeLabel, isChecked) {
        const labelEl = document.getElementById(labelId);
        if (labelEl) {
            labelEl.textContent = (isChecked ? '開放' : '不開放') + ' ' + typeLabel;
            labelEl.style.color = isChecked ? '#28a745' : '#dc3545';
        }
        // 更新提示文字
        const hintEl = labelEl?.nextElementSibling?.nextElementSibling;
        if (hintEl && hintEl.classList.contains('cohort-file-type-toggle-hint')) {
            hintEl.textContent = isChecked ? '開啟中' : '關閉中';
        }
    };

    /**
     * 整屆一併開放/不開放某檔案類型（科辦）
     */
    window.batchSetDownloadByCohortAndFileType = async function(cohort_ID, file_type, allow_download, checkboxEl) {
        const formData = new FormData();
        formData.append('cohort_ID', cohort_ID);
        formData.append('file_type', file_type);
        formData.append('allow_download', allow_download);
        
        const previousChecked = !checkboxEl.checked; // 注意：此時 checked 已是新值
        const safeType = checkboxEl.getAttribute('data-file-type');
        
        // 查找對應的 UI 元素以便局部更新
        const statusEl = document.getElementById(`status_cohort_${cohort_ID}_${safeType}`);
        const countEl = document.getElementById(`count_cohort_${cohort_ID}_${safeType}`);
        
        try {
            const response = await fetch(`${API_BASE}?do=batch_set_download_by_cohort_and_file_type`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                // 不再呼叫 loadCohortFileTypes(cohort_ID)，改為手動更新局部 UI 以避免閃爍
                if (statusEl && countEl) {
                    const totalMatch = countEl.textContent.match(/\/(\d+)/);
                    const total = totalMatch ? totalMatch[1] : 0;
                    
                    if (allow_download === 1) {
                        statusEl.textContent = '全部開放';
                        statusEl.style.color = '#28a745';
                        countEl.textContent = `${total}/${total} 筆已開放`;
                    } else {
                        statusEl.textContent = '全部不開放';
                        statusEl.style.color = '#dc3545';
                        countEl.textContent = `0/${total} 筆已開放`;
                    }
                }
                
                showMessage(data.message || (allow_download ? '已一併開放' : '已一併不開放'), 'success');
                
                // 仍需更新下方專題列表以反映變更（若已載入）
                // 為了避免大閃爍，可以使用靜默載入或延遲
                if (typeof loadProjects === 'function') {
                    loadProjects(true); 
                }
            } else {
                showMessage(data.message || '更新失敗', 'error');
                checkboxEl.checked = previousChecked;
                // 恢復文字
                const labelId = `label_cohort_${cohort_ID}_${safeType}`;
                const typeLabel = checkboxEl.getAttribute('data-label') || '';
                updateCohortToggleUI(labelId, typeLabel, previousChecked);
            }
        } catch (e) {
            console.error('batchSetDownloadByCohortAndFileType error', e);
            showMessage('更新失敗，請稍後再試', 'error');
            checkboxEl.checked = previousChecked;
            const labelId = `label_cohort_${cohort_ID}_${safeType}`;
            const typeLabel = checkboxEl.getAttribute('data-label') || '';
            updateCohortToggleUI(labelId, typeLabel, previousChecked);
        }
    };

    /**
     * 載入專題列表
     */
    async function loadProjects(forceReload = false) {
        const searchInput = document.getElementById('searchInput');
        const cohortSelect = document.getElementById('cohortSelect');
        const groupSelect = document.getElementById('groupSelect');
        const statusSelect = document.getElementById('statusSelect');
        const tbody = document.getElementById('projectTableBody');

        // 🔹 【關鍵修復】首次載入時不顯示空狀態，直接載入資料
        // 檢查是否為首次載入：tbody 為空或只有一個包含 empty-state 的 tr
        const isFirstLoad = !tbody || tbody.innerHTML.trim() === '' || 
                           (tbody.children.length === 1 && tbody.querySelector('.empty-state'));

        const params = {};
        if (searchInput && searchInput.value.trim()) {
            params.search = searchInput.value.trim();
        }
        if (cohortSelect && cohortSelect.value) {
            params.cohort_ID = cohortSelect.value;
        }
        if (groupSelect && groupSelect.value) {
            params.group_ID = groupSelect.value;
        }
        if (statusSelect && statusSelect.value !== '') {
            params.status = statusSelect.value;
        }

        try {
            // 如果強制重新載入，添加時間戳參數避免緩存
            if (forceReload) {
                params._t = new Date().getTime();
            }
            
            // 管理端需要查看所有已通過的專題（不只是已上架），所以加上 status=all
            // 如果狀態篩選器有選擇，則使用選擇的值；否則使用 'all' 顯示所有已通過的專題
            if (!params.status) {
                params.status = 'all';
            }
            
            const response = await fetch(`${API_BASE}?do=get_list&${new URLSearchParams(params)}`);
            const data = await response.json();

            if (data.success) {
                currentProjects = data.data || [];
                // 根據狀態篩選器進行前端過濾
                // 如果 statusSelect 是空值（全部），顯示所有已通過的專題
                // 如果 statusSelect 是 '1'（啟用），只顯示已上架的專題（history_status = 1）
                // 如果 statusSelect 是 '0'（停用），只顯示未上架的專題（history_status = 0 或未設置）
                let filteredProjects = currentProjects;
                if (statusSelect && statusSelect.value !== '') {
                    const selectedStatus = statusSelect.value;
                    filteredProjects = currentProjects.filter(project => {
                        let contentJson = {};
                        try {
                            if (project.content_json) {
                                if (typeof project.content_json === 'string') {
                                    contentJson = JSON.parse(project.content_json);
                                } else if (typeof project.content_json === 'object') {
                                    contentJson = project.content_json;
                                }
                            }
                        } catch (e) {
                            console.warn('解析 content_json 失敗:', e);
                        }
                        const historyStatus = contentJson.history_status;
                        const statusValue = historyStatus === undefined || historyStatus === null ? 0 : (historyStatus === 1 || historyStatus === "1" ? 1 : 0);
                        return statusValue.toString() === selectedStatus;
                    });
                }
                renderProjects(filteredProjects);
                updateProjectCount(filteredProjects.length);
                
                // 更新批次按鈕狀態
                updateBatchButtons();
            } else {
                // 只有在首次載入失敗時才顯示錯誤訊息
                if (isFirstLoad && tbody) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="8" class="empty-state">
                                <i class="fa-solid fa-exclamation-triangle"></i>
                                <p>載入失敗：${data.message || '未知錯誤'}</p>
                            </td>
                        </tr>
                    `;
                }
                showMessage('載入專題列表失敗：' + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error('載入專題列表錯誤:', error);
            // 只有在首次載入失敗時才顯示錯誤訊息
            if (isFirstLoad && tbody) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="empty-state">
                            <i class="fa-solid fa-exclamation-triangle"></i>
                            <p>載入失敗，請稍後再試</p>
                        </td>
                    </tr>
                `;
            }
            showMessage('載入專題列表失敗，請稍後再試', 'error');
        }
    }
    
    /**
     * 渲染專題列表
     */
    function renderProjects(projects) {
        const tbody = document.getElementById('projectTableBody');
        if (!tbody) return;

        if (projects.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="empty-state">
                        <i class="fa-solid fa-folder-open"></i>
                        <p>目前沒有專題資料</p>
                    </td>
                </tr>
            `;
            return;
        }

        // 未上架的專題要顯示在最上面，其次已上架
        const sortedProjects = [...projects].sort((a, b) => {
            let aJson = {};
            let bJson = {};
            try {
                aJson = a.content_json ? (typeof a.content_json === 'string' ? JSON.parse(a.content_json) : a.content_json) : {};
            } catch (e) {}
            try {
                bJson = b.content_json ? (typeof b.content_json === 'string' ? JSON.parse(b.content_json) : b.content_json) : {};
            } catch (e) {}
            const aPublished = aJson.history_status === 1 || aJson.history_status === "1";
            const bPublished = bJson.history_status === 1 || bJson.history_status === "1";
            if (aPublished === bPublished) {
                // 同一狀態時，依學年度+專題名稱排序，避免順序跳動
                const aKey = (a.hp_cohort_name || '') + (a.hp_project_name || '');
                const bKey = (b.hp_cohort_name || '') + (b.hp_project_name || '');
                return aKey.localeCompare(bKey, 'zh-Hant');
            }
            // 未上架 (false) 要在已上架 (true) 之前
            return aPublished ? 1 : -1;
        });

        tbody.innerHTML = sortedProjects.map(project => {
            const posterPath = project.hp_poster ? `../${project.hp_poster}` : '';
            const isPDF = posterPath.toLowerCase().endsWith('.pdf');
            // 解析 content_json
            let contentJson = {};
            try {
                if (project.content_json) {
                    if (typeof project.content_json === 'string') {
                        contentJson = JSON.parse(project.content_json);
                    } else if (typeof project.content_json === 'object') {
                        contentJson = project.content_json;
                    }
                }
            } catch (e) {
                console.warn('解析 content_json 失敗:', e, project.content_json);
                contentJson = {};
            }
            
            // 根據表格邏輯判斷狀態
            const historyStatus = contentJson.history_status;
            const isPublished = historyStatus === 1 || historyStatus === "1";
            
            let statusText, statusClass;
            if (isPublished) {
                statusText = '已上架';
                statusClass = 'status-published';
            } else {
                statusText = '未上架';
                statusClass = 'status-unpublished';
            }
            
            // 組員姓名（用逗號分隔）
            const members = project.team_members || [];
            const memberNames = members.map(m => escapeHtml(m.u_name || '')).join('、') || '無組員';
            const memberCount = members.length;
            
            // 簡介處理（支持展開/收起）
            const intro = project.hp_intro || '無簡介';
            const introId = `intro-${project.hp_ID}`;
            const introPreviewLength = 60;
            const needsTruncate = intro.length > introPreviewLength;
            const introPreview = needsTruncate ? intro.substring(0, introPreviewLength) + '...' : intro;

            const otherFiles = project.other_files || [];
            const otherFilesJson = (JSON.stringify(otherFiles) || '').replace(/&/g, '&amp;').replace(/'/g, '&#39;');

            return `
                <tr data-prosub-id="${project.hp_ID}" data-other-files="${otherFilesJson}">
                    <td style="text-align: center;">
                        <input type="checkbox" class="project-checkbox" value="${project.hp_ID}" data-status="${project.hp_status}" data-published="${isPublished ? '1' : '0'}">
                    </td>
                    <td class="poster-cell">
                        ${posterPath ? `
                            ${isPDF ? `
                                <div class="poster-thumbnail pdf-thumbnail" style="width: 60px; height: 80px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; border-radius: 4px; border: 1px solid #ddd;">
                                    <i class="fa-solid fa-file-pdf" style="font-size: 24px; color: #dc3545;"></i>
                                </div>
                            ` : `
                                <img src="${escapeHtml(posterPath)}" alt="海報" class="poster-thumbnail" style="width: 60px; height: 80px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd;">
                            `}
                        ` : '<span class="text-muted" style="font-size: 12px;">無海報</span>'}
                    </td>
                    <td>
                        <div style="font-weight: 600; color: #333; font-size: 15px;">
                            ${escapeHtml(project.hp_project_name || '無標題')}
                        </div>
                        ${project.hp_cohort_name ? `<div style="font-size: 12px; color: #6c757d; margin-top: 4px;"><i class="fa-solid fa-calendar"></i> ${escapeHtml(project.hp_cohort_name)}</div>` : ''}
                    </td>
                    <td style="font-size: 14px;">
                        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                            <i class="fa-solid fa-users" style="color: #667eea; font-size: 12px;"></i>
                            <span>${memberNames}</span>
                            ${memberCount > 0 ? `<span style="background: rgba(102, 126, 234, 0.1); color: #667eea; padding: 2px 6px; border-radius: 10px; font-size: 11px; font-weight: 600;">${memberCount}人</span>` : ''}
                        </div>
                    </td>
                    <td style="font-size: 14px; max-width: 250px;">
                        <div id="${introId}" style="line-height: 1.6;">
                            <span class="intro-text">${escapeHtml(introPreview)}</span>
                            ${needsTruncate ? `<a href="javascript:void(0)" onclick="toggleIntro('${introId}', '${escapeHtml(intro.replace(/'/g, "\\'"))}', ${introPreviewLength})" style="color: #667eea; text-decoration: none; margin-left: 4px; font-weight: 500; font-size: 12px;">展開</a>` : ''}
                        </div>
                    </td>
                    <td style="font-size: 14px;">
                        <span class="badge ${statusClass === 'status-published' ? 'bg-success' : 'bg-secondary'}" style="font-weight: 500; padding: 6px 12px; font-size: 13px;">
                            <i class="fa-solid ${statusClass === 'status-published' ? 'fa-check-circle' : 'fa-times-circle'}"></i>
                            ${statusText}
                        </span>
                    </td>
                    <td class="action-cell">
                        <div class="action-buttons" style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                            <button class="btn-action btn-view" onclick="viewProjectDetail(${project.hp_ID})" title="查看詳情">
                                <i class="fa-solid fa-eye"></i> 查看
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
        
        // 綁定全選 checkbox 和更新按鈕狀態
        updateSelectAllCheckbox();
        updateBatchButtons();
    }
    
    /**
     * 更新專題數量
     */
    function updateProjectCount(count) {
        const countElement = document.querySelector('.project-count');
        if (countElement) {
            countElement.textContent = `共${count}筆`;
        }
    }
    
    /**
     * 檢查並顯示「一併上架」按鈕
     */
    function checkAndShowBatchPublishButton(projects) {
        const batchPublishBtn = document.getElementById('batchPublishBtn');
        if (!batchPublishBtn) return;
        
        const now = new Date();
        let hasExpiredProjects = false;
        let hasUnpublishedProjects = false;
        
        projects.forEach(project => {
            if (project.hp_upload_deadline) {
                const deadline = new Date(project.hp_upload_deadline);
                if (now >= deadline && project.hp_status == 0) {
                    hasExpiredProjects = true;
                    hasUnpublishedProjects = true;
                }
            }
        });
        
        if (hasExpiredProjects && hasUnpublishedProjects) {
            batchPublishBtn.style.display = 'inline-block';
        } else {
            batchPublishBtn.style.display = 'none';
        }
    }
    
    let currentProjects = [];
    
    /**
     * 載入成果列表（保留原功能，但現在改用 loadProjects）
     */
    async function loadSubmissions() {
        // 這個函數保留以兼容舊代碼，但現在使用 loadProjects
        await loadProjects();
    }

    /**
     * 渲染成果列表
     */
    function renderSubmissions() {
        const container = document.getElementById('submissionList');
        
        if (!currentSubmissions || currentSubmissions.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fa-solid fa-folder-open"></i>
                    <p>目前沒有成果資料</p>
                </div>
            `;
            return;
        }

        container.innerHTML = currentSubmissions.map(submission => {
            const summary = submission.file_summary || { total: 0, public: 0, restricted: 0 };
            
            return `
                <div class="submission-card" data-prosub-id="${submission.prosub_ID}">
                    <div class="submission-card-header">
                        <div>
                            <h3 class="submission-card-title">${escapeHtml(submission.team_name || '未命名團隊')}</h3>
                            <div class="submission-card-meta">
                                ${submission.cohort_name ? escapeHtml(submission.cohort_name) : ''} 
                                ${submission.group_name ? ' · ' + escapeHtml(submission.group_name) : ''}
                            </div>
                        </div>
                    </div>
                    <div class="file-summary-section">
                        <div class="file-summary-item">
                            <i class="fa-solid fa-file"></i>
                            <span class="summary-label">總檔案數：</span>
                            <span class="summary-value">${summary.total}</span>
                        </div>
                        <div class="file-summary-item">
                            <i class="fa-solid fa-unlock"></i>
                            <span class="summary-label">開放學生下載：</span>
                            <span class="summary-value text-success">${summary.public}</span>
                        </div>
                        <div class="file-summary-item">
                            <i class="fa-solid fa-lock"></i>
                            <span class="summary-label">限制下載：</span>
                            <span class="summary-value text-warning">${summary.restricted}</span>
                        </div>
                        <div class="file-summary-actions">
                            <button class="btn btn-primary btn-sm view-files-btn" 
                                    onclick="viewFiles(${submission.prosub_ID})">
                                <i class="fa-solid fa-eye"></i> 查看檔案
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    /**
     * 渲染檔案項目（用於模態框）
     */
    function renderFileItem(prosub_ID, file) {
        const uploadDate = file.uploaded_at ? new Date(file.uploaded_at).toLocaleDateString('zh-TW') : '-';
        const isPublic = file.public === true || (file.allow_download === true || file.allowDownload === true); // 兼容舊格式

        return `
            <div class="file-item">
                <div class="file-info">
                    <i class="fa-solid fa-file file-icon"></i>
                    <div class="file-details">
                        <div class="file-name">${escapeHtml(file.name || '未命名檔案')}</div>
                        <div class="file-meta">${uploadDate}</div>
                    </div>
                </div>
                <div class="download-toggle">
                    <div class="toggle-switch ${isPublic ? 'active' : ''}" 
                         data-prosub-id="${prosub_ID}" 
                         data-fid="${file.fid || file.path || ''}"
                         data-public="${isPublic ? '1' : '0'}">
                    </div>
                    <span class="toggle-label">${isPublic ? '開放學生下載' : '不開放學生下載'}</span>
                </div>
            </div>
        `;
    }

    /**
     * 查看檔案（顯示模態框）
     */
    window.viewFiles = async function(prosub_ID) {
        if (!prosub_ID) return;
        
        try {
            const response = await fetch(`${API_BASE}?do=get_submission_files&prosub_ID=${prosub_ID}`);
            const data = await response.json();
            
            if (!data.success) {
                showMessage(data.message || '載入檔案失敗', 'error');
                return;
            }
            
            const files = data.files || [];
            const teamName = data.team_name || '未命名團隊';
            
            // 創建模態框
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'filesModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'filesModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center;';
            
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1054;';
            
            const filesHtml = files.length > 0 
                ? files.map(file => renderFileItem(prosub_ID, file)).join('')
                : '<p class="text-muted text-center p-3">尚無檔案</p>';
            
            modal.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="filesModalLabel">
                                <i class="fa-solid fa-files"></i> ${escapeHtml(teamName)} - 檔案清單
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                            <div class="file-list">
                                ${filesHtml}
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(backdrop);
            document.body.appendChild(modal);
            document.body.classList.add('modal-open');
            document.body.style.overflow = 'hidden';
            
            // 顯示動畫
            requestAnimationFrame(() => {
                backdrop.style.opacity = '1';
                modal.style.opacity = '1';
                backdrop.classList.add('show');
                modal.classList.add('show');
            });
            
            // 關閉模態框
            const closeModal = function() {
                backdrop.style.opacity = '0';
                modal.style.opacity = '0';
                backdrop.classList.remove('show');
                modal.classList.remove('show');
                
                setTimeout(() => {
                    if (document.body.contains(modal)) {
                        document.body.removeChild(modal);
                    }
                    if (document.body.contains(backdrop)) {
                        document.body.removeChild(backdrop);
                    }
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                }, 300);
            };
            
            // 綁定關閉事件
            const closeBtn = modal.querySelector('.btn-close');
            const closeFooterBtn = modal.querySelector('.modal-footer .btn-secondary');
            if (closeBtn) closeBtn.onclick = closeModal;
            if (closeFooterBtn) closeFooterBtn.onclick = closeModal;
            backdrop.onclick = closeModal;
            
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeModal();
            });
            
            // 使用事件委託綁定開關點擊事件（確保動態生成的元素也能響應）
            const modalBody = modal.querySelector('.modal-body');
            if (modalBody) {
                modalBody.addEventListener('click', function(e) {
                    // 檢查是否點擊了開關本身、開關內的任何元素、或標籤文字
                    let toggleSwitch = null;
                    let downloadToggle = e.target.closest('.download-toggle');
                    
                    // 如果點擊的是 download-toggle 區域內的任何元素
                    if (downloadToggle) {
                        toggleSwitch = downloadToggle.querySelector('.toggle-switch');
                        if (toggleSwitch) {
                            e.preventDefault();
                            e.stopPropagation();
                            console.log('切換開關被點擊', { prosub_ID: toggleSwitch.getAttribute('data-prosub-id'), fid: toggleSwitch.getAttribute('data-fid') });
                            window.toggleDownload(toggleSwitch);
                            return;
                        }
                    }
                    
                    // 也檢查是否直接點擊了開關
                    toggleSwitch = e.target.closest('.toggle-switch');
                    if (toggleSwitch) {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log('開關被直接點擊', { prosub_ID: toggleSwitch.getAttribute('data-prosub-id'), fid: toggleSwitch.getAttribute('data-fid') });
                        window.toggleDownload(toggleSwitch);
                        return;
                    }
                });
            }
            
            // ESC 鍵關閉
            const escHandler = function(e) {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
            
        } catch (error) {
            console.error('載入檔案失敗:', error);
            showMessage('載入檔案失敗', 'error');
        }
    };

    /**
     * 切換下載權限（更新 public 值）
     */
    window.toggleDownload = async function(toggleElement) {
        if (!toggleElement) {
            console.error('切換元素不存在');
            return;
        }
        
        const prosub_ID = toggleElement.getAttribute('data-prosub-id');
        const fid = toggleElement.getAttribute('data-fid');
        
        if (!prosub_ID || !fid) {
            console.error('缺少必要參數:', { prosub_ID, fid });
            showMessage('參數錯誤', 'error');
            return;
        }
        
        const currentState = toggleElement.classList.contains('active');
        const newState = !currentState;

        // 立即更新UI（樂觀更新）
        toggleElement.classList.toggle('active');
        const label = toggleElement.nextElementSibling;
        if (label) {
            label.textContent = newState ? '開放學生下載' : '不開放學生下載';
        }

        try {
            const response = await fetch(TOGGLE_API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    prosub_ID: prosub_ID,
                    fid: fid,
                    public: newState ? '1' : '0'
                })
            });

            const data = await response.json();

            if (data.success) {
                showMessage(data.message || '更新成功', 'success');
                // 更新卡片摘要資訊
                updateCardSummary(prosub_ID, newState);
            } else {
                // 如果失敗，恢復原狀態
                toggleElement.classList.toggle('active');
                if (label) {
                    label.textContent = currentState ? '開放學生下載' : '不開放學生下載';
                }
                showMessage(data.message || '更新失敗', 'error');
            }
        } catch (error) {
            console.error('切換下載權限失敗:', error);
            // 如果失敗，恢復原狀態
            toggleElement.classList.toggle('active');
            if (label) {
                label.textContent = currentState ? '開放學生下載' : '不開放學生下載';
            }
            showMessage('切換下載權限失敗', 'error');
        }
    };

    /**
     * 更新卡片摘要資訊
     */
    function updateCardSummary(prosub_ID, isNowPublic) {
        // 找到對應的卡片
        const card = document.querySelector(`.submission-card[data-prosub-id="${prosub_ID}"]`);
        if (!card) {
            // 如果找不到卡片（可能在模態框中），重新載入列表
            loadSubmissions();
            return;
        }
        
        // 更新摘要數值
        const summaryItems = card.querySelectorAll('.file-summary-item');
        if (summaryItems.length >= 3) {
            const publicValue = summaryItems[1].querySelector('.summary-value');
            const restrictedValue = summaryItems[2].querySelector('.summary-value');
            
            if (publicValue && restrictedValue) {
                let publicCount = parseInt(publicValue.textContent) || 0;
                let restrictedCount = parseInt(restrictedValue.textContent) || 0;
                
                if (isNowPublic) {
                    publicCount++;
                    restrictedCount = Math.max(0, restrictedCount - 1);
                } else {
                    publicCount = Math.max(0, publicCount - 1);
                    restrictedCount++;
                }
                
                publicValue.textContent = publicCount;
                restrictedValue.textContent = restrictedCount;
            }
        }
    }

    /**
     * 更新計數
     */
    function updateCount() {
        const countElement = document.querySelector('.project-count');
        if (countElement) {
            countElement.textContent = `共${currentSubmissions.length}筆`;
        }
    }

    /**
     * 綁定事件
     */
    /**
     * 切換簡介展開/收起
     */
    window.toggleIntro = function(introId, fullText, previewLength) {
        const introElement = document.getElementById(introId);
        if (!introElement) return;
        
        const introText = introElement.querySelector('.intro-text');
        const toggleLink = introElement.querySelector('a');
        
        if (!introText) return;
        
        // 检查当前是否已展开（通过检查文本长度或是否有"收起"链接）
        const isExpanded = toggleLink && toggleLink.textContent === '收起';
        
        if (isExpanded) {
            // 收起
            const escapedPreview = escapeHtml(fullText.substring(0, previewLength));
            introText.innerHTML = escapedPreview + '...';
            if (toggleLink) {
                toggleLink.textContent = '展開';
                toggleLink.onclick = function() {
                    toggleIntro(introId, fullText, previewLength);
                };
            }
        } else {
            // 展開
            const escapedFull = escapeHtml(fullText);
            introText.innerHTML = escapedFull;
            if (toggleLink) {
                toggleLink.textContent = '收起';
                toggleLink.onclick = function() {
                    toggleIntro(introId, fullText, previewLength);
                };
            }
        }
    };

    function bindEvents() {
        // 搜尋與篩選區域的折疊/展開
        const searchFilterHeader = document.querySelector('.search-filter-header');
        const searchFilterSection = document.querySelector('.search-filter-section');
        document.querySelectorAll('.search-filter-section').forEach(section => {
            const header = section.querySelector('.search-filter-header');
            if (header) header.addEventListener('click', function() {
                section.classList.toggle('collapsed');
            });
        });

        // 搜尋按鈕
        const searchBtn = document.getElementById('searchBtn');
        if (searchBtn) {
            searchBtn.addEventListener('click', () => {
                loadProjects();
            });
        }

        // 清除按鈕
        const clearBtn = document.getElementById('clearBtn');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                const searchInput = document.getElementById('searchInput');
                const cohortSelect = document.getElementById('cohortSelect');
                const groupSelect = document.getElementById('groupSelect');
                const statusSelect = document.getElementById('statusSelect');
                
                if (searchInput) searchInput.value = '';
                if (cohortSelect) cohortSelect.value = '';
                if (groupSelect) groupSelect.value = '';
                if (statusSelect) statusSelect.value = '';
                
                loadProjects();
            });
        }

        // 篩選變更
        const cohortSelect = document.getElementById('cohortSelect');
        if (cohortSelect) {
            cohortSelect.addEventListener('change', () => {
                loadProjects();
            });
        }

        const groupSelect = document.getElementById('groupSelect');
        if (groupSelect) {
            groupSelect.addEventListener('change', () => {
                loadProjects();
            });
        }
        
        const statusSelect = document.getElementById('statusSelect');
        if (statusSelect) {
            statusSelect.addEventListener('change', () => {
                loadProjects();
            });
        }

        // 整屆下載設定：選擇學年度後載入該屆檔案類型
        const cohortDownloadSelect = document.getElementById('cohortDownloadSelect');
        if (cohortDownloadSelect) {
            cohortDownloadSelect.addEventListener('change', function() {
                const cid = this.value ? parseInt(this.value, 10) : 0;
                if (cid > 0) loadCohortFileTypes(cid);
                else {
                    document.getElementById('cohortDownloadFileTypes').style.display = 'none';
                    document.getElementById('cohortDownloadFileTypesList').innerHTML = '';
                }
            });
        }

        // Enter 鍵搜尋
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                    loadProjects();
                }
            });
        }
        
        // 全選 checkbox
        const selectAllCheckbox = document.getElementById('selectAllCheckbox');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('.project-checkbox');
                checkboxes.forEach(cb => {
                    cb.checked = this.checked;
                });
                updateBatchButtons();
            });
        }
        
        // 單個 checkbox 變化
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('project-checkbox')) {
                updateSelectAllCheckbox();
                updateBatchButtons();
            }
        });
        
        // 批量上架按鈕
        const batchPublishSelectedBtn = document.getElementById('batchPublishSelectedBtn');
        if (batchPublishSelectedBtn) {
            batchPublishSelectedBtn.addEventListener('click', handleBatchPublishSelected);
        }
        
        const batchPublishBtn = document.getElementById('batchPublishBtn');
        if (batchPublishBtn) {
            batchPublishBtn.addEventListener('click', handleBatchPublish);
        }
        
        // 批量下架按鈕
        const batchUnpublishSelectedBtn = document.getElementById('batchUnpublishSelectedBtn');
        if (batchUnpublishSelectedBtn) {
            batchUnpublishSelectedBtn.addEventListener('click', handleBatchUnpublishSelected);
        }
        
        // 同步團隊結案狀態按鈕（修正已上架但 teamdata 未結案的舊資料）
        const syncTeamStatusBtn = document.getElementById('syncTeamStatusBtn');
        if (syncTeamStatusBtn) {
            syncTeamStatusBtn.addEventListener('click', handleSyncTeamStatus);
        }
    }
    
    /**
     * 同步已上架專題的 teamdata 狀態為已結案（修正舊資料）
     */
    async function handleSyncTeamStatus() {
        const syncTeamStatusBtn = document.getElementById('syncTeamStatusBtn');
        const confirmed = await showConfirmDialog(
            '將所有「已上架」專題對應的團隊狀態同步為已結案。\n適用於先前上架但團隊未顯示結案的資料，是否繼續？',
            '同步團隊結案狀態'
        );
        if (!confirmed) return;
        if (syncTeamStatusBtn) {
            syncTeamStatusBtn.disabled = true;
            syncTeamStatusBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 同步中…';
        }
        try {
            const response = await fetch(`${API_BASE}?do=sync_published_team_status`, { method: 'POST' });
            const data = await response.json();
            if (data.success) {
                showMessage(data.message || '已同步團隊結案狀態', 'success');
            } else {
                showMessage(data.message || '同步失敗', 'error');
            }
        } catch (error) {
            console.error('同步團隊結案狀態錯誤:', error);
            showMessage('同步失敗，請稍後再試', 'error');
        } finally {
            if (syncTeamStatusBtn) {
                syncTeamStatusBtn.disabled = false;
                syncTeamStatusBtn.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> 同步團隊結案狀態';
            }
        }
    }
    
    /**
     * 更新全選 checkbox 狀態
     */
    function updateSelectAllCheckbox() {
        const selectAllCheckbox = document.getElementById('selectAllCheckbox');
        const checkboxes = document.querySelectorAll('.project-checkbox');
        if (selectAllCheckbox && checkboxes.length > 0) {
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            const someChecked = Array.from(checkboxes).some(cb => cb.checked);
            selectAllCheckbox.checked = allChecked;
            selectAllCheckbox.indeterminate = someChecked && !allChecked;
        }
    }
    
    /**
     * 更新批次按鈕顯示狀態
     */
    function updateBatchButtons() {
        const batchPublishSelectedBtn = document.getElementById('batchPublishSelectedBtn');
        const batchUnpublishSelectedBtn = document.getElementById('batchUnpublishSelectedBtn');
        const checkboxes = document.querySelectorAll('.project-checkbox:checked');
        
        // 分別統計未上架和已上架的數量
        let unpublishedCount = 0;  // 未上架（可上架）
        let publishedCount = 0;    // 已上架（可下架）
        
        checkboxes.forEach(cb => {
            const isPublished = cb.getAttribute('data-published') === '1';
            if (isPublished) {
                publishedCount++;
            } else {
                unpublishedCount++;
            }
        });
        
        // 上架按鈕：只有未上架的資料時顯示
        if (batchPublishSelectedBtn) {
            if (unpublishedCount > 0) {
                batchPublishSelectedBtn.style.display = 'inline-block';
                batchPublishSelectedBtn.innerHTML = `<i class="fa-solid fa-upload"></i> 上架 (${unpublishedCount})`;
            } else {
                batchPublishSelectedBtn.style.display = 'none';
            }
        }
        
        // 下架按鈕：只有已上架的資料時顯示
        if (batchUnpublishSelectedBtn) {
            if (publishedCount > 0) {
                batchUnpublishSelectedBtn.style.display = 'inline-block';
                batchUnpublishSelectedBtn.innerHTML = `<i class="fa-solid fa-arrow-down"></i> 下架 (${publishedCount})`;
            } else {
                batchUnpublishSelectedBtn.style.display = 'none';
            }
        }
    }
    
    /**
     * 更新上架按鈕顯示狀態（向後兼容）
     */
    function updateBatchPublishButton() {
        updateBatchButtons();
    }
    
    /**
     * 處理上架（選中的專題）
     */
    async function handleBatchPublishSelected() {
        const checkboxes = document.querySelectorAll('.project-checkbox:checked');
        if (checkboxes.length === 0) {
            showMessage('請至少選擇一個專題', 'error');
            return;
        }
        
        // 過濾出未上架的專題（只處理未上架的）
        const unpublishedCheckboxes = Array.from(checkboxes).filter(cb => {
            const isPublished = cb.getAttribute('data-published') === '1';
            return !isPublished;
        });
        
        // 如果全部都是已上架，顯示提示並返回
        if (unpublishedCheckboxes.length === 0) {
            showMessage('所選資料皆已上架，無需再次上架', 'warning');
            return;
        }
        
        const selectedIds = unpublishedCheckboxes.map(cb => parseInt(cb.value));
        const selectedCount = selectedIds.length;
        const totalSelected = checkboxes.length;
        
        const confirmMessage = totalSelected > selectedCount
            ? `確定要上架選中的 ${selectedCount} 個專題嗎？（已自動排除 ${totalSelected - selectedCount} 個已上架的專題）`
            : `確定要上架選中的 ${selectedCount} 個專題嗎？`;
        const confirmed = await showConfirmDialog(confirmMessage, '確認上架');
        if (!confirmed) return;
        
        const batchPublishSelectedBtn = document.getElementById('batchPublishSelectedBtn');
        const originalText = batchPublishSelectedBtn ? batchPublishSelectedBtn.innerHTML : '';
        
        if (batchPublishSelectedBtn) {
            batchPublishSelectedBtn.disabled = true;
            batchPublishSelectedBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 處理中...';
        }
        
        try {
            const formData = new FormData();
            formData.append('prosub_ids', JSON.stringify(selectedIds));
            
            const response = await fetch(`${API_BASE}?do=batch_publish_selected`, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                checkboxes.forEach(cb => cb.checked = false);
                updateSelectAllCheckbox();
                updateBatchButtons();
                await loadProjects(true);
            } else {
                showMessage('批量上架失敗：' + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error('批量上架錯誤:', error);
            showMessage('批量上架失敗，請稍後再試', 'error');
        } finally {
            if (batchPublishSelectedBtn) {
                batchPublishSelectedBtn.disabled = false;
                batchPublishSelectedBtn.innerHTML = originalText;
            }
        }
    }
    
    /**
     * 處理一併上架
     */
    async function handleBatchPublish() {
        const confirmed = await showConfirmDialog('確定要一併上架所有已截止的通過專題嗎？', '確認一併上架');
        if (!confirmed) return;
        
        const batchPublishBtn = document.getElementById('batchPublishBtn');
        const originalText = batchPublishBtn ? batchPublishBtn.innerHTML : '';
        
        if (batchPublishBtn) {
            batchPublishBtn.disabled = true;
            batchPublishBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 處理中...';
        }
        
        try {
            const response = await fetch(`${API_BASE}?do=batch_publish`, {
                method: 'POST'
            });
            
            const data = await response.json();
            
            if (data.success) {
                loadProjects();
            } else {
                showMessage('一併上架失敗：' + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error('一併上架錯誤:', error);
            showMessage('一併上架失敗，請稍後再試', 'error');
        } finally {
            if (batchPublishBtn) {
                batchPublishBtn.disabled = false;
                batchPublishBtn.innerHTML = originalText;
            }
        }
    }
    
    /**
     * 處理批次下架（選中的專題）
     */
    async function handleBatchUnpublishSelected() {
        const checkboxes = document.querySelectorAll('.project-checkbox:checked');
        if (checkboxes.length === 0) {
            showMessage('請至少選擇一個專題', 'error');
            return;
        }
        
        // 過濾出已上架的專題（只處理已上架的）
        const publishedCheckboxes = Array.from(checkboxes).filter(cb => {
            const isPublished = cb.getAttribute('data-published') === '1';
            return isPublished;
        });
        
        // 如果全部都是未上架，顯示提示並返回
        if (publishedCheckboxes.length === 0) {
            showMessage('所選資料皆未上架，無法進行下架', 'warning');
            return;
        }
        
        const selectedIds = publishedCheckboxes.map(cb => parseInt(cb.value));
        const selectedCount = selectedIds.length;
        const totalSelected = checkboxes.length;
        
        const confirmMessage = totalSelected > selectedCount
            ? `確定要下架選中的 ${selectedCount} 個專題嗎？（已自動排除 ${totalSelected - selectedCount} 個未上架的專題）`
            : `確定要下架選中的 ${selectedCount} 個專題嗎？`;
        const confirmed = await showConfirmDialog(confirmMessage, '確認下架');
        if (!confirmed) return;
        
        const batchUnpublishSelectedBtn = document.getElementById('batchUnpublishSelectedBtn');
        const originalText = batchUnpublishSelectedBtn ? batchUnpublishSelectedBtn.innerHTML : '';
        
        if (batchUnpublishSelectedBtn) {
            batchUnpublishSelectedBtn.disabled = true;
            batchUnpublishSelectedBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 處理中...';
        }
        
        try {
            const formData = new FormData();
            formData.append('prosub_ids', JSON.stringify(selectedIds));
            
            const response = await fetch(`${API_BASE}?do=batch_unpublish_selected`, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                checkboxes.forEach(cb => cb.checked = false);
                updateSelectAllCheckbox();
                updateBatchButtons();
                await loadProjects(true);
            } else {
                showMessage('批量下架失敗：' + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error('批量下架錯誤:', error);
            showMessage('批量下架失敗，請稍後再試', 'error');
        } finally {
            if (batchUnpublishSelectedBtn) {
                batchUnpublishSelectedBtn.disabled = false;
                batchUnpublishSelectedBtn.innerHTML = originalText;
            }
        }
    }
    
    /**
     * 切換檔案清單顯示/隱藏（保留供相容）
     */
    window.toggleFileList = function(prosub_ID) {
        const fileListId = `file-list-${prosub_ID}`;
        const fileList = document.getElementById(fileListId);
        if (fileList) {
            if (fileList.style.display === 'none' || !fileList.style.display) {
                fileList.style.display = 'block';
            } else {
                fileList.style.display = 'none';
            }
        }
    };
    
    /**
     * 更新檔案狀態摘要（全部開放/全部未開放/部分開放）
     */
    function updateFileStatusSummary(prosub_ID) {
        const fileListId = `file-list-${prosub_ID}`;
        const fileList = document.getElementById(fileListId);
        if (!fileList) return;
        
        // 找到該專題的狀態摘要元素（在"設定多檔案下載"按鈕上方）
        const toggles = fileList.querySelectorAll('.file-toggle-switch input[type="checkbox"]');
        if (toggles.length === 0) return;
        
        let allowedCount = 0;
        let totalCount = toggles.length;
        toggles.forEach(toggle => {
            if (toggle.checked) allowedCount++;
        });
        
        // 生成新的狀態文本
        const statusText = allowedCount === totalCount 
            ? `<span style="color: #28a745; font-weight: 600;"><i class="fa-solid fa-check-circle"></i> 全部開放 (${totalCount})</span>`
            : allowedCount === 0
            ? `<span style="color: #dc3545; font-weight: 600;"><i class="fa-solid fa-lock"></i> 全部未開放 (${totalCount})</span>`
            : `<span style="color: #ffc107; font-weight: 600;"><i class="fa-solid fa-exclamation-circle"></i> 部分開放 (${allowedCount}/${totalCount})</span>`;
        
        // 找到狀態摘要元素並更新
        // 狀態摘要應該在按鈕之前，在同一個父元素中
        const fileListParent = fileList.parentElement;
        if (fileListParent) {
            // 找到狀態摘要元素（第一個包含圖標和文字的 div）
            const statusElement = fileListParent.querySelector('div[style*="font-size: 13px"]');
            if (statusElement) {
                statusElement.innerHTML = statusText;
            }
        }
    }
    
    /**
     * 切換上架狀態（上架/下架）
     */
    window.togglePublishStatus = async function(hp_ID, newStatus) {
        // 獲取當前 toggle 狀態
        const row = document.querySelector(`tr[data-prosub-id="${hp_ID}"]`);
        let currentChecked = false;
        if (row) {
            const toggle = row.querySelector('.publish-toggle-switch input[type="checkbox"]');
            if (toggle) {
                currentChecked = toggle.checked;
            }
        }
        
        const newChecked = newStatus == 1;
        
        // 防呆：如果狀態沒變更，不執行
        if (currentChecked === newChecked) {
            return;
        }
        
        const action = newStatus == 1 ? '上架' : '下架';
        const confirmed = await showConfirmDialog(`確定要${action}此專題嗎？`, `確認${action}`);
        if (!confirmed) {
            if (row) {
                const toggle = row.querySelector('.publish-toggle-switch input[type="checkbox"]');
                if (toggle) toggle.checked = currentChecked;
            }
            return;
        }

        const formData = new FormData();
        formData.append('hp_ID', hp_ID);
        formData.append('status', newStatus);

        try {
            const response = await fetch(`${API_BASE}?do=update_status`, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                loadProjects(true); // 重新載入列表
            } else {
                showMessage(data.message || `${action}失敗：未知錯誤`, 'error');
                // 失敗時恢復 toggle 狀態
                if (row) {
                    const toggle = row.querySelector('.publish-toggle-switch input[type="checkbox"]');
                    if (toggle) {
                        toggle.checked = currentChecked;
                    }
                }
            }
        } catch (error) {
            console.error(`${action}錯誤:`, error);
            showMessage(`${action}失敗，請稍後再試`, 'error');
            // 錯誤時恢復 toggle 狀態
            if (row) {
                const toggle = row.querySelector('.publish-toggle-switch input[type="checkbox"]');
                if (toggle) {
                    toggle.checked = currentChecked;
                }
            }
        }
    };
    
    /**
     * 切換多檔案下載狀態
     */
    window.toggleMultiFileDownload = async function(hp_ID, allowDownload) {
        if (!hp_ID) {
            showMessage('參數錯誤', 'error');
            return;
        }
        
        const action = allowDownload == 1 ? '開放下載' : '停止下載';
        const confirmed = confirm(`確定要${action}此專題嗎？`);
        if (!confirmed) return;

        const formData = new FormData();
        formData.append('hp_ID', hp_ID);
        formData.append('allow_download', allowDownload);

        try {
            const response = await fetch(`${API_BASE}?do=toggle_multi_file_download`, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                showMessage(data.message || `${action}成功！`, 'success');
                loadProjects(true); // 重新載入列表
            } else {
                showMessage(data.message || `${action}失敗：未知錯誤`, 'error');
            }
        } catch (error) {
            console.error(`${action}錯誤:`, error);
            showMessage(`${action}失敗，請稍後再試`, 'error');
        }
    };
    
    /**
     * 切換專題狀態（啟用/停用）
     */
    window.toggleStatus = async function(hp_ID, newStatus) {
        const action = newStatus == 1 ? '啟用' : '停用';
        const confirmed = confirm(`確定要${action}此專題嗎？`);
        if (!confirmed) return;

        const formData = new FormData();
        formData.append('hp_ID', hp_ID);
        formData.append('status', newStatus);

        try {
            const response = await fetch(`${API_BASE}?do=update_status`, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                showMessage(`${action}成功！`, 'success');
                loadProjects();
            } else {
                showMessage(`${action}失敗：` + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error(`${action}錯誤:`, error);
            showMessage(`${action}失敗，請稍後再試`, 'error');
        }
    };
    
    /**
     * 切換單個檔案的下載開關
     */
    window.toggleFileDownload = async function(prosub_ID, fileIndex, allow_download) {
        // 🔹 【統一使用 allow_download 欄位】根據用戶要求，使用 allow_download
        if (!prosub_ID || fileIndex < 0) {
            showMessage('參數錯誤', 'error');
            return;
        }
        
        const fileListId = `file-list-${prosub_ID}`;
        const fileList = document.getElementById(fileListId);
        // 切換前的狀態，供 API 失敗／錯誤時還原；onchange 時 DOM 已是新值，故用 allow_download 反推
        const previousChecked = (allow_download != 1);

        const formData = new FormData();
        formData.append('prosub_ID', prosub_ID);
        formData.append('file_index', fileIndex);
        formData.append('allow_download', allow_download); // 🔹 【統一使用 allow_download】

        try {
            const response = await fetch(`${API_BASE}?do=toggle_file_download`, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                // 切換時不顯示右上角提示
                // 更新該檔案的 toggle 視覺狀態
                if (fileList) {
                    const toggles = fileList.querySelectorAll('.file-toggle-switch input[type="checkbox"]');
                    const toggleLabels = fileList.querySelectorAll('.file-toggle-switch');
                    if (toggles[fileIndex] && toggleLabels[fileIndex]) {
                        // 更新 checkbox 狀態
                        toggles[fileIndex].checked = allow_download == 1;
                        // 更新視覺狀態（toggle-slider 的背景色和按鈕位置）
                        const toggleSlider = toggleLabels[fileIndex].querySelector('.toggle-slider');
                        const toggleSliderButton = toggleLabels[fileIndex].querySelector('.toggle-slider-button');
                        if (toggleSlider) {
                            toggleSlider.style.backgroundColor = allow_download == 1 ? '#28a745' : '#dc3545';
                        }
                        if (toggleSliderButton) {
                            toggleSliderButton.style.transform = allow_download == 1 ? 'translateX(24px)' : 'translateX(0)';
                        }
                    }
                }
                
                // 🔹 【增強】更新狀態摘要（全部開放/全部未開放/部分開放）
                updateFileStatusSummary(prosub_ID);
            } else {
                showMessage(data.message || '更新失敗', 'error');
                // 失敗時恢復 toggle 狀態
                if (fileList) {
                    const toggles = fileList.querySelectorAll('.file-toggle-switch input[type="checkbox"]');
                    const toggleLabels = fileList.querySelectorAll('.file-toggle-switch');
                    if (toggles[fileIndex] && toggleLabels[fileIndex]) {
                        toggles[fileIndex].checked = previousChecked;
                        const toggleSlider = toggleLabels[fileIndex].querySelector('.toggle-slider');
                        const toggleSliderButton = toggleLabels[fileIndex].querySelector('.toggle-slider-button');
                        if (toggleSlider) toggleSlider.style.backgroundColor = previousChecked ? '#28a745' : '#dc3545';
                        if (toggleSliderButton) toggleSliderButton.style.transform = previousChecked ? 'translateX(24px)' : 'translateX(0)';
                    }
                }
            }
        } catch (error) {
            console.error('切換檔案下載狀態錯誤:', error);
            showMessage('更新失敗，請稍後再試', 'error');
            if (fileList) {
                const toggles = fileList.querySelectorAll('.file-toggle-switch input[type="checkbox"]');
                const toggleLabels = fileList.querySelectorAll('.file-toggle-switch');
                if (toggles[fileIndex] && toggleLabels[fileIndex]) {
                    toggles[fileIndex].checked = previousChecked;
                    const toggleSlider = toggleLabels[fileIndex].querySelector('.toggle-slider');
                    const toggleSliderButton = toggleLabels[fileIndex].querySelector('.toggle-slider-button');
                    if (toggleSlider) toggleSlider.style.backgroundColor = previousChecked ? '#28a745' : '#dc3545';
                    if (toggleSliderButton) toggleSliderButton.style.transform = previousChecked ? 'translateX(24px)' : 'translateX(0)';
                }
            }
        }
    };
    
    /**
     * 查看專題詳情
     */
    window.viewProjectDetail = async function(hp_ID) {
        if (!hp_ID) return;
        
        const project = currentProjects.find(p => p.hp_ID == hp_ID);
        if (!project) {
            showMessage('找不到專題資料', 'error');
            return;
        }
        
        const posterPath = project.hp_poster ? `../${project.hp_poster}` : '';
        const isPDF = posterPath.toLowerCase().endsWith('.pdf');
        const members = project.team_members || [];
        const memberNames = members.map(m => m.u_name || '').join('、') || '無組員';
        const teachers = project.team_teachers || [];
        const teacherNames = teachers.map(t => t.u_name || '').join('、') || '';
        const intro = project.hp_intro || '無簡介';
        const contentJson = typeof project.content_json === 'string' ? JSON.parse(project.content_json) : (project.content_json || {});
        const historyStatus = contentJson.history_status;
        const isPublished = historyStatus === 1 || historyStatus === "1";
        
        let statusText, statusClass;
        if (isPublished) {
            statusText = '已上架';
            statusClass = 'status-published';
        } else {
            statusText = '已通過';
            statusClass = 'status-approved';
        }
        const lockedText = project.hp_is_locked == 1 ? '已鎖定' : '未鎖定';
        const deadline = project.hp_upload_deadline 
            ? new Date(project.hp_upload_deadline).toLocaleString('zh-TW')
            : '未設定';
        
        // 創建模態框（限制在視窗內，避免裁切）
        const modal = document.createElement('div');
        modal.className = 'modal fade history-detail-modal';
        modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box; overflow: auto;';
        
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1054;';
        
        modal.innerHTML = `
            <div class="modal-dialog modal-lg history-detail-dialog" style="max-width: min(800px, calc(100vw - 40px)); width: 100%; margin: auto; flex-shrink: 0;">
                <div class="modal-content history-detail-content" style="background: #fff; border-radius: 12px; border: none; box-shadow: 0 4px 24px rgba(102, 126, 234, 0.2); overflow: hidden; border-top: 3px solid #667eea;">
                    <div class="modal-header history-detail-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-bottom: none; padding: 20px;">
                        <h5 class="modal-title" style="font-weight: 600; font-size: 18px; color: #fff;">
                            <i class="fa-solid fa-circle-info" style="margin-right: 8px; opacity: 0.9;"></i>專題詳情
                        </h5>
                        <button type="button" class="btn-close btn-close-white" aria-label="Close" style="filter: brightness(0) invert(1); opacity: 0.9;"></button>
                    </div>
                    <div class="modal-body" style="max-height: 70vh; overflow-y: auto; overflow-x: auto; padding: 20px; background: #fafbff;">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <strong>專題名稱：</strong>
                            </div>
                            <div class="col-md-8">
                                ${escapeHtml(project.hp_project_name || '')}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <strong>類別：</strong>
                            </div>
                            <div class="col-md-8">
                                ${escapeHtml(project.hp_group_name || '未設定')}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <strong>組員：</strong>
                            </div>
                            <div class="col-md-8">
                                ${escapeHtml(memberNames)}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <strong>指導老師：</strong>
                            </div>
                            <div class="col-md-8">
                                ${escapeHtml(teacherNames || '無')}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <strong>簡介：</strong>
                            </div>
                            <div class="col-md-8">
                                <div style="white-space: pre-wrap; word-wrap: break-word;">${escapeHtml(intro)}</div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <strong>海報：</strong>
                            </div>
                            <div class="col-md-8" style="max-width: 100%; min-width: 0;">
                                ${posterPath ? `
                                    ${isPDF ? `
                                        <div style="width: 100%; max-width: 100%; height: 500px; min-width: 0;">
                                            <iframe src="${escapeHtml(posterPath)}" type="application/pdf" style="width: 100%; height: 100%; border: 1px solid #ddd; border-radius: 4px;"></iframe>
                                        </div>
                                    ` : `
                                        <img src="${escapeHtml(posterPath)}" alt="海報" style="max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 4px;">
                                    `}
                                ` : '<span class="text-muted">無海報</span>'}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <strong>狀態：</strong>
                            </div>
                            <div class="col-md-8">
                                <span class="status-badge ${statusClass}">${statusText}</span>
                                <span class="text-muted ms-2">${lockedText}</span>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <strong>上傳期限：</strong>
                            </div>
                            <div class="col-md-8">
                                ${deadline}
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer history-detail-footer" style="border-top: 1px solid rgba(102, 126, 234, 0.2); padding: 20px; justify-content: center; background: #f8f9ff;">
                        <button type="button" class="btn btn-close-detail" style="border-radius: 8px; padding: 10px 24px; font-weight: 600; min-width: 90px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; border: none; box-shadow: 0 2px 8px rgba(102, 126, 234, 0.35);">關閉</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(backdrop);
        document.body.appendChild(modal);
        document.body.style.overflow = 'hidden';
        document.body.classList.add('modal-open');
        
        requestAnimationFrame(() => {
            backdrop.style.opacity = '1';
            modal.style.opacity = '1';
            modal.classList.add('show');
        });
        
        function closeModal() {
            backdrop.style.opacity = '0';
            modal.style.opacity = '0';
            modal.classList.remove('show');
            setTimeout(() => {
                if (document.body.contains(modal)) document.body.removeChild(modal);
                if (document.body.contains(backdrop)) document.body.removeChild(backdrop);
                document.body.style.overflow = '';
                document.body.classList.remove('modal-open');
            }, 150);
        }
        
        const closeBtn = modal.querySelector('.btn-close');
        const closeFooterBtn = modal.querySelector('.btn-close-detail');
        if (closeBtn) closeBtn.onclick = closeModal;
        if (closeFooterBtn) closeFooterBtn.onclick = closeModal;
        backdrop.onclick = closeModal;
        
        modal.onclick = function(e) {
            if (e.target === modal) closeModal();
        };
        
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                closeModal();
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
    };

    /**
     * 自定義確認對話框（替換 confirm，不顯示 localhost）
     * @param {string} message - 確認訊息
     * @param {string} title - 標題（默認 '確認操作'）
     * @returns {Promise<boolean>} - 返回 true 表示確認，false 表示取消
     */
    function showConfirmDialog(message, title = '確認操作') {
        return new Promise((resolve) => {
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center;';
            
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1054;';
            
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="background: #fff; border-radius: 14px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,0.15); padding: 36px 32px; text-align: center; min-width: 360px;">
                        <div style="width: 72px; height: 72px; margin: 0 auto 20px; border: 2px solid #7eb8da; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <span style="font-size: 36px; font-weight: 600; color: #7eb8da;">?</span>
                        </div>
                        <h5 class="modal-title" style="font-weight: 600; font-size: 20px; color: #333; margin: 0 0 12px;">${escapeHtml(title)}</h5>
                        <p style="font-size: 17px; color: #333; line-height: 1.5; margin: 0 0 28px;">${escapeHtml(message).replace(/\n/g, '<br>')}</p>
                        <div style="display: flex; justify-content: center; gap: 16px;">
                            <button type="button" class="btn cancel-btn" style="border-radius: 8px; padding: 12px 28px; font-weight: 600; min-width: 100px; font-size: 16px; background: #5a6268; color: #fff; border: none;">取消</button>
                            <button type="button" class="btn confirm-btn" style="border-radius: 8px; padding: 12px 28px; font-weight: 600; min-width: 100px; font-size: 16px; background: #667eea; color: #fff; border: none;">確定</button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(backdrop);
            document.body.appendChild(modal);
            document.body.style.overflow = 'hidden';
            
            requestAnimationFrame(() => {
                modal.classList.add('show');
                modal.style.opacity = '1';
            });
            
            const cleanup = () => {
                modal.classList.remove('show');
                backdrop.classList.remove('show');
                setTimeout(() => {
                    if (modal.parentNode) modal.remove();
                    if (backdrop.parentNode) backdrop.remove();
                    document.body.style.overflow = '';
                }, 300);
            };
            
            modal.querySelector('.confirm-btn').addEventListener('click', () => {
                cleanup();
                resolve(true);
            });
            
            modal.querySelector('.cancel-btn').addEventListener('click', () => {
                cleanup();
                resolve(false);
            });
            
            backdrop.addEventListener('click', () => {
                cleanup();
                resolve(false);
            });
        });
    }

    /**
     * 顯示訊息
     */
    function showMessage(message, type = 'info') {
        // 簡單的提示，可以根據需要改進
        let alertClass = 'alert-info';
        if (type === 'error') {
            alertClass = 'alert-danger';
        } else if (type === 'success') {
            alertClass = 'alert-success';
        } else if (type === 'warning') {
            alertClass = 'alert-warning';
        }
        const alert = document.createElement('div');
        alert.className = `alert ${alertClass} alert-dismissible fade show`;
        alert.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        alert.innerHTML = `
            ${escapeHtml(message)}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alert);
        setTimeout(() => {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 3000);
    }


    /**
     * 導出開放下載的資料為 JSON
     */
    async function exportDownloadableJson() {
        try {
            // 直接下載 JSON 文件
            const url = `${API_BASE}?do=export_downloadable_json`;
            window.location.href = url;
            showMessage('正在下載 JSON 檔案...', 'success');
        } catch (error) {
            console.error('導出 JSON 失敗:', error);
            showMessage('導出 JSON 失敗', 'error');
        }
    }

    /**
     * 載入成果列表（保留原功能，但現在改用 loadProjects）
     */
    async function loadSubmissions() {
        // 這個函數保留以兼容舊代碼，但現在使用 loadProjects
        loadProjects();
    }
    
    /**
     * 載入時段列表（表格形式）- 已移除，時段管理現在在 history_project.js
     */
    async function loadRecentPeriods() {
        const container = document.getElementById('periodsTableBody');
        if (!container) return;
        
        // 獲取篩選條件
        const filterCohort = document.getElementById('periodFilterCohort')?.value || '';
        const filterStatus = document.getElementById('periodFilterStatus')?.value || '';
        
        try {
            container.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        <i class="fa-solid fa-spinner fa-spin"></i>
                        <span class="ms-2">載入中...</span>
                    </td>
                </tr>
            `;
            
            // 構建查詢參數
            const params = new URLSearchParams();
            if (filterCohort) params.append('cohort_ID', filterCohort);
            if (filterStatus !== '') params.append('status', filterStatus);
            
            const queryString = params.toString();
            const url = `${API_BASE}?do=get_recent_periods${queryString ? '&' + queryString : ''}`;
            
            const response = await fetch(url, {
                credentials: 'include',
                cache: 'no-store'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (!data.success) {
                container.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center text-danger py-4">
                            <i class="fa-solid fa-exclamation-triangle"></i>
                            <span class="ms-2">載入失敗：${escapeHtml(data.message || '未知錯誤')}</span>
                        </td>
                    </tr>
                `;
                return;
            }
            
            const periods = data.data || [];
            
            // 格式化日期時間
            const formatDateTime = (dateStr) => {
                if (!dateStr) return '未設定';
                const date = new Date(dateStr);
                return date.toLocaleString('zh-TW', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false
                }).replace(/\//g, '/');
            };
            
            // 格式化日期時間（簡短版，用於建立時間）
            const formatDateTimeShort = (dateStr) => {
                if (!dateStr) return '未設定';
                const date = new Date(dateStr);
                return date.toLocaleString('zh-TW', {
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false
                }).replace(/\//g, '/');
            };
            
            if (periods.length === 0) {
                container.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="fa-solid fa-calendar-xmark"></i>
                            <span class="ms-2">目前沒有時段資料</span>
                        </td>
                    </tr>
                `;
                return;
            }
            
            // 生成表格行
            let html = '';
            periods.forEach((period) => {
                const statusBadge = period.pro_status == 1 
                    ? '<span class="badge bg-success">啟用</span>' 
                    : '<span class="badge bg-secondary">停用</span>';
                
                html += `
                    <tr>
                        <td>${escapeHtml(period.pro_title || '未命名')}</td>
                        <td>
                            <span class="badge bg-info">${escapeHtml(period.cohort_name || '未設定')}</span>
                        </td>
                        <td>${formatDateTime(period.pro_start_d)}</td>
                        <td>${formatDateTime(period.pro_end_d)}</td>
                        <td>${statusBadge}</td>
                        <td class="text-muted small">${formatDateTimeShort(period.pro_created_d)}</td>
                        <td class="text-muted small">${escapeHtml(period.creator_name || '未知')}</td>
                        <td>
                            <button class="btn btn-sm ${period.pro_status == 1 ? 'btn-outline-warning' : 'btn-outline-success'} me-1" 
                                    onclick="togglePeriodStatus(${period.pro_ID}, ${period.pro_status})" 
                                    title="${period.pro_status == 1 ? '停用' : '啟用'}">
                                <i class="fa-solid fa-toggle-${period.pro_status == 1 ? 'on' : 'off'}"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deletePeriod(${period.pro_ID}, '${escapeHtml(period.pro_title || '')}')" title="刪除">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            container.innerHTML = html;
        } catch (error) {
            console.error('載入時段列表失敗:', error);
            container.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center text-danger py-4">
                        <i class="fa-solid fa-exclamation-triangle"></i>
                        <span class="ms-2">載入失敗，請稍後再試</span>
                        <button class="btn btn-sm btn-outline-secondary mt-2 ms-2" onclick="loadRecentPeriods()">重試</button>
                    </td>
                </tr>
            `;
        }
    }
    
    /**
     * 載入屆別選項到篩選下拉選單 - 已移除，時段管理現在在 history_project.js
     */
    async function loadPeriodFilterCohorts() {
        const select = document.getElementById('periodFilterCohort');
        if (!select) return;
        
        try {
            const response = await fetch(`${API_BASE}?do=get_cohorts`, {
                credentials: 'include'
            });
            const data = await response.json();
            
            if (data.success && Array.isArray(data.data) && data.data.length > 0) {
                select.innerHTML = '<option value="">全部</option>';
                data.data.forEach(cohort => {
                    const option = document.createElement('option');
                    option.value = cohort.cohort_ID;
                    option.textContent = `${cohort.cohort_name} (${cohort.year_label})`;
                    select.appendChild(option);
                });
            }
        } catch (error) {
            console.error('載入屆別選項失敗:', error);
        }
    }
    
    window.togglePeriodStatus = async function(pro_ID, currentStatus) {
        if (!pro_ID) return;
        
        const action = currentStatus == 1 ? '停用' : '啟用';
        const confirmed = await showConfirmDialog(`確定要${action}此時段嗎？`, `確認${action}`);
        
        if (!confirmed) return;
        
        try {
            const formData = new FormData();
            formData.append('pro_ID', pro_ID);
            
            const response = await fetch(`${API_BASE}?do=toggle_period_status`, {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                showMessage(`${action}成功！`, 'success');
                loadRecentPeriods(); // 重新載入列表
            } else {
                showMessage(`${action}失敗：` + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error('切換時段狀態失敗:', error);
            showMessage('操作失敗，請稍後再試', 'error');
        }
    };
    
    /**
     * 刪除時段 - 已移除
     */
    window.deletePeriod = async function(pro_ID, title) {
        if (!pro_ID) return;
        
        const confirmed = await showConfirmDialog(`確定要刪除時段「${escapeHtml(title)}」嗎？\n此操作將停用該時段（軟刪除）。`, '確認刪除');
        
        if (!confirmed) return;
        
        try {
            const formData = new FormData();
            formData.append('pro_ID', pro_ID);
            
            const response = await fetch(`${API_BASE}?do=delete_period`, {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                showMessage('刪除成功！', 'success');
                loadRecentPeriods(); // 重新載入列表
            } else {
                showMessage('刪除失敗：' + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error('刪除時段失敗:', error);
            showMessage('刪除失敗，請稍後再試', 'error');
        }
    };

    /**
     * HTML 轉義
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // 初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPage);
    } else {
        initPage();
    }

    // 導出給外部使用
    window.initHistoryProjectFile = initPage;
})();

