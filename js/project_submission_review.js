/**
 * 專題提交審核 - 科辦端
 */

(function () {
    'use strict';

    // 先取得後端設定（若有）
    const config = window.PROJECT_SUBMISSION_REVIEW_CONFIG || {};

    // 正規化並計算 API 路徑，需同時支援：
    // - 從 main.php 載入（hash 路由）
    // - 直接開啟 /pages/project_submission_review.php
    const API_BASE = (() => {
        let rawBase = (config.apiBase || 'pages/project_submission_review_api.php').replace(/\/+$/, '');

        // 修正後端可能產生的重複 /pages/pages/ 路徑
        rawBase = rawBase.replace(/\/pages\/pages\//, '/pages/');

        // 若已是絕對路徑或完整 URL，直接使用
        if (/^https?:\/\//i.test(rawBase) || rawBase.startsWith('/')) {
            return rawBase;
        }

        // 其餘情況依據目前網址計算相對路徑
        const path = window.location.pathname || '';
        if (path.includes('/pages/')) {
            // 目前在 /pages/ 底下，API 需回到上一層
            return '../' + rawBase.replace(/^\.\//, '');
        }

        // 預設從網站根目錄的 pages/ 取得
        return rawBase;
    })();

    /**
     * 自定義確認對話框（替換 confirm）
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

            const escapeHtml = (text) => {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            };

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
                backdrop.style.opacity = '1';
                modal.style.opacity = '1';
            });

            function closeModal(result) {
                backdrop.style.opacity = '0';
                modal.style.opacity = '0';
                setTimeout(() => {
                    if (document.body.contains(modal)) document.body.removeChild(modal);
                    if (document.body.contains(backdrop)) document.body.removeChild(backdrop);
                    document.body.style.overflow = '';
                    resolve(result);
                }, 150);
            }

            const confirmBtn = modal.querySelector('.confirm-btn');
            const cancelBtn = modal.querySelector('.cancel-btn');
            confirmBtn.onclick = () => closeModal(true);
            cancelBtn.onclick = () => closeModal(false);
            backdrop.onclick = () => closeModal(false);

            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    closeModal(false);
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        });
    }

    /**
     * 自定義提示框（替換 alert）
     * @param {string} message - 提示訊息
     * @param {string} type - 類型：'success', 'error', 'info', 'warning'（默認 'info'）
     * @param {number} autoClose - 自動關閉時間（毫秒），0 表示不自動關閉（默認 0）
     * @returns {Promise<void>}
     */
    function showAlertDialog(message, type = 'info', autoClose = 0) {
        return new Promise((resolve) => {
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center;';

            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1054;';

            const typeConfig = {
                'success': { bg: 'linear-gradient(135deg, #28a745 0%, #20c997 100%)', icon: 'fa-check-circle' },
                'error': { bg: 'linear-gradient(135deg, #dc3545 0%, #c82333 100%)', icon: 'fa-exclamation-circle' },
                'warning': { bg: 'linear-gradient(135deg, #ffc107 0%, #ff9800 100%)', icon: 'fa-exclamation-triangle' },
                'info': { bg: 'linear-gradient(135deg, #17a2b8 0%, #138496 100%)', icon: 'fa-info-circle' }
            };

            const config = typeConfig[type] || typeConfig['info'];
            const escapeHtml = (text) => {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            };

            const iconColor = type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : type === 'warning' ? '#ffc107' : '#7eb8da';
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="background: #fff; border-radius: 14px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,0.15); padding: 36px 32px; text-align: center; min-width: 360px;">
                        <div style="width: 72px; height: 72px; margin: 0 auto 20px; border: 2px solid ${iconColor}; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="fa-solid ${config.icon}" style="font-size: 32px; color: ${iconColor};"></i>
                        </div>
                        <h5 class="modal-title" style="font-weight: 600; font-size: 20px; color: #333; margin: 0 0 12px;">提示</h5>
                        <p style="font-size: 17px; color: #333; line-height: 1.5; margin: 0 0 28px;">${escapeHtml(message).replace(/\n/g, '<br>')}</p>
                        <div style="display: flex; justify-content: center;">
                            <button type="button" class="btn confirm-btn" style="border-radius: 8px; padding: 12px 28px; font-weight: 600; min-width: 100px; font-size: 16px; background: #667eea; color: #fff; border: none;">確定</button>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(backdrop);
            document.body.appendChild(modal);
            document.body.style.overflow = 'hidden';

            requestAnimationFrame(() => {
                backdrop.style.opacity = '1';
                modal.style.opacity = '1';
            });

            function closeModal() {
                backdrop.style.opacity = '0';
                modal.style.opacity = '0';
                setTimeout(() => {
                    if (document.body.contains(modal)) document.body.removeChild(modal);
                    if (document.body.contains(backdrop)) document.body.removeChild(backdrop);
                    document.body.style.overflow = '';
                    resolve();
                }, 150);
            }

            const confirmBtn = modal.querySelector('.confirm-btn');
            confirmBtn.onclick = closeModal;
            backdrop.onclick = closeModal;

            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);

            if (autoClose > 0) {
                setTimeout(closeModal, autoClose);
            }

            setTimeout(() => confirmBtn.focus(), 100);
        });
    }

    // DOM 元素
    const searchInput = document.getElementById('searchInput');
    const cohortSelect = document.getElementById('cohortSelect');
    const groupSelect = document.getElementById('groupSelect');
    const statusSelect = document.getElementById('statusSelect');
    const searchBtn = document.getElementById('searchBtn');
    const clearBtn = document.getElementById('clearBtn');

    /**
     * 初始化
     */
    function init() {
        loadCohorts();
        loadGroups();
        loadSubmissions();

        if (searchBtn) {
            searchBtn.addEventListener('click', handleSearch);
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', handleClear);
        }

        // Enter 鍵搜尋
        if (searchInput) {
            searchInput.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    handleSearch();
                }
            });
        }
    }

    /**
     * 載入學年度選項
     */
    async function loadCohorts() {
        try {
            // 這裡可以從 API 獲取學年度列表
            // 暫時使用空選項
        } catch (error) {
            console.error('載入學年度失敗:', error);
        }
    }

    /**
     * 載入類組選項
     */
    async function loadGroups() {
        try {
            // 這裡可以從 API 獲取類組列表
            // 暫時使用空選項
        } catch (error) {
            console.error('載入類組失敗:', error);
        }
    }

    /**
     * 載入提交記錄
     */
    async function loadSubmissions() {
        try {
            const params = new URLSearchParams();

            if (searchInput && searchInput.value.trim()) {
                params.append('search', searchInput.value.trim());
            }

            if (cohortSelect && cohortSelect.value) {
                params.append('cohort_ID', cohortSelect.value);
            }

            if (groupSelect && groupSelect.value) {
                params.append('group_ID', groupSelect.value);
            }

            if (statusSelect && statusSelect.value !== '') {
                params.append('status', statusSelect.value);
            }

            const response = await fetch(`${API_BASE}?do=get_projects&${params}`, { credentials: 'same-origin' });
            const data = await response.json();

            if (data.success) {
                displaySubmissions(data.data || data || { groups: [] });
            } else {
                console.error('載入失敗:', data.message);
                displaySubmissions({ groups: [] });
            }
        } catch (error) {
            console.error('載入提交記錄失敗:', error);
            displaySubmissions([]);
        }
    }

    /**
     * 顯示專題列表（表格形式，類似 Word 表格）
     */
    function displaySubmissions(data) {
        const projectsContainer = document.getElementById('projectsContainer');
        const submissionCount = document.getElementById('submissionCount');

        if (!projectsContainer) return;

        // 收集所有專題到一個陣列中
        let allProjects = [];
        if (data && data.groups) {
            data.groups.forEach(group => {
                group.projects.forEach(project => {
                    allProjects.push({
                        ...project,
                        group_name: group.group_name
                    });
                });
            });
        }

        const totalCount = allProjects.length;

        if (submissionCount) {
            submissionCount.textContent = `共${totalCount}筆`;
        }

        // 如果沒有資料，顯示空狀態
        if (allProjects.length === 0) {
            projectsContainer.innerHTML = `
                <div class="empty-state">
                    <i class="fa-solid fa-folder-open"></i>
                    <p>目前沒有專題資料</p>
                </div>
            `;
            return;
        }

        // 渲染表格
        projectsContainer.innerHTML = `
            <div class="table-wrapper">
                <table class="submission-table">
                    <thead>
                        <tr>
                            <th style="width: 4%;">序號</th>
                            <th style="width: 10%;">屆別</th>
                            <th style="width: 22%;">專題名稱</th>
                            <th style="width: 20%;">成員</th>
                            <th style="width: 10%;">類組</th>
                            <th style="width: 10%;">狀態</th>
                            <th style="width: 18%;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${allProjects.map((project, index) => {
            const status = project.submission_status;
            let statusText = '';
            let statusClass = '';

            if (status === null || status === undefined) {
                statusText = '未繳交';
                statusClass = 'status-not-submitted';
            } else if (status === 1) {
                statusText = '未審核';
                statusClass = 'status-pending';
            } else if (status === 3) {
                statusText = '通過';
                statusClass = 'status-approved';
            } else if (status === 0 || status === 2) {
                statusText = '退件';
                statusClass = 'status-rejected';
            } else {
                statusText = '未知';
                statusClass = 'status-unknown';
            }

            const memberList = Array.isArray(project.member_names) ? project.member_names : [];
            const memberDisplay = memberList.length > 0 ? escapeHtml(memberList.join('、')) : '—';
            const memberHint = memberList.length > 0 ? `共 ${memberList.length} 人` : '';
            const memberTitle = memberList.length > 0 ? escapeHtml(memberList.join('、')) : '';

            return `
                                <tr>
                                    <td style="text-align: center;">${index + 1}</td>
                                    <td>${escapeHtml(project.cohort_name || '—')}</td>
                                    <td>${escapeHtml(project.project_name || '未命名')}</td>
                                    <td title="${memberTitle}">
                                        <div class="member-names-cell">${memberDisplay}</div>
                                        ${memberHint ? `<div class="member-hint-cell">${memberHint}</div>` : ''}
                                    </td>
                                    <td>${escapeHtml(project.group_name || '')}</td>
                                    <td>
                                        <span class="status-badge ${statusClass}">
                                            ${status === 1 ? '<i class="fa-solid fa-star" style="margin-right: 4px;"></i>' : ''}
                                            ${escapeHtml(statusText)}
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn-table-action" onclick="viewTeamSubmissions(${project.team_ID}, ${status === 1 ? (project.prosub_ID || 'null') : 'null'})">
                                            <i class="fa-solid fa-eye"></i> 檢視提交紀錄
                                        </button>
                                    </td>
                                </tr>
                            `;
        }).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    /**
     * 渲染提交卡片
     */
    function renderSubmissionCard(sub) {
        const status = getStatusInfo(sub.prosub_status, sub.is_deleted);
        const displayTime = sub.prosub_update_d || sub.prosub_created_d || '';
        const imagePath = sub.prosub_img || '';
        const fileName = imagePath ? imagePath.split('/').pop() : '無檔案';
        const fileExt = imagePath ? imagePath.split('.').pop().toLowerCase() : '';
        const isPDF = fileExt === 'pdf';
        const isDeleted = sub.is_deleted;
        const statusInt = parseInt(sub.prosub_status);

        // 🔹 【修改邏輯】學生一提交，科辦隨時都可以審核（移除期限檢查）
        let isDeadlinePassed = true;  // 設為 true，允許隨時審核

        let statusBadge = '';
        if (statusInt === 1) {
            statusBadge = '<span class="submission-item-label status-pending-badge">未審核</span>';
        } else if (statusInt === 3) {
            statusBadge = '<span class="submission-item-label status-approved-badge">已通過</span>';
        } else if (statusInt === 0) {
            statusBadge = '<span class="submission-item-label status-rejected-badge">被退件</span>';
        }

        if (isDeleted) {
            statusBadge = '<span class="submission-item-label status-deleted-badge">已刪除</span>';
        }

        let actionButtons = '';
        if (isDeleted) {
            actionButtons = `
                <button type="button" class="btn btn-sm btn-primary" onclick="restoreSubmission(${sub.prosub_ID})">
                    <i class="fa-solid fa-undo"></i> 恢復
                </button>
            `;
        } else {
            // 🔹 【修改邏輯】隨時都可以審核
            if (statusInt === 1) {
                // 審核中：可以通過或退件
                actionButtons = `
                    <button type="button" class="btn btn-sm btn-success" onclick="reviewSubmission(${sub.prosub_ID}, 'approve')">
                        <i class="fa-solid fa-check"></i> 通過
                    </button>
                    <button type="button" class="btn btn-sm btn-warning" onclick="reviewSubmission(${sub.prosub_ID}, 'reject')">
                        <i class="fa-solid fa-times"></i> 退件
                    </button>
                `;
            } else if (statusInt === 3) {
                // 已通過：可以取消通過
                actionButtons = `
                    <button type="button" class="btn btn-sm btn-warning" onclick="reviewSubmission(${sub.prosub_ID}, 'cancel_approve')">
                        <i class="fa-solid fa-undo"></i> 取消通過
                    </button>
                `;
            } else if (statusInt === 0) {
                // 已退件：可以取消退件
                actionButtons = `
                    <button type="button" class="btn btn-sm btn-warning" onclick="reviewSubmission(${sub.prosub_ID}, 'cancel_reject')">
                        <i class="fa-solid fa-undo"></i> 取消退件
                    </button>
                `;
            }

            actionButtons += `
                <button type="button" class="btn btn-sm btn-primary" onclick="viewSubmission(${sub.prosub_ID})">
                    <i class="fa-solid fa-eye"></i> 查看
                </button>
                <button type="button" class="btn btn-sm btn-danger" onclick="deleteSubmission(${sub.prosub_ID})">
                    <i class="fa-solid fa-trash"></i> 刪除
                </button>
            `;
        }

        return `
            <div class="submission-item-card ${isDeleted ? 'deleted-item' : ''}" data-prosub-id="${sub.prosub_ID}" style="position: relative; ${isDeleted ? 'opacity: 0.5;' : ''}">
                ${statusBadge}
                <div class="submission-item-content">
                    ${imagePath ? `
                        <div class="submission-item-preview">
                            ${isPDF ? `
                                <object data="${escapeHtml(imagePath)}" type="application/pdf" class="preview-object"></object>
                            ` : `
                                <img src="${escapeHtml(imagePath)}" alt="預覽" class="preview-image">
                            `}
                        </div>
                    ` : ''}
                    <div class="submission-item-info">
                        <p class="file-name">${escapeHtml(fileName)}</p>
                        <p class="submit-time">${formatDate(displayTime)}</p>
                        ${sub.submitter_name ? `<p class="submitter" style="font-size: 11px; color: #6c757d; margin: 0;">提交者：${escapeHtml(sub.submitter_name)}</p>` : ''}
                        ${sub.prosub_re_reason && statusInt === 0 ? `<p class="reject-reason text-danger small" style="font-size: 10px; margin: 4px 0 0 0;">退件原因：${escapeHtml(sub.prosub_re_reason)}</p>` : ''}
                    </div>
                </div>
                ${!isDeleted ? `
                    <div class="submission-item-actions">
                        ${actionButtons}
                    </div>
                ` : ''}
            </div>
        `;
    }

    /**
     * 獲取狀態資訊
     */
    function getStatusInfo(status, isDeleted) {
        if (isDeleted) {
            return {
                text: '已刪除',
                badge: '<span class="status-badge status-deleted">已刪除</span>',
                class: 'deleted'
            };
        }

        switch (parseInt(status)) {
            case 0:
                return {
                    text: '退件',
                    badge: '<span class="status-badge status-rejected">退件</span>',
                    class: 'rejected'
                };
            case 1:
                return {
                    text: '未審核',
                    badge: '<span class="status-badge status-pending">未審核</span>',
                    class: 'pending'
                };
            case 3:
                return {
                    text: '通過',
                    badge: '<span class="status-badge status-approved">通過</span>',
                    class: 'approved'
                };
            default:
                return {
                    text: '未知',
                    badge: '<span class="status-badge">未知</span>',
                    class: ''
                };
        }
    }

    /**
     * 獲取操作按鈕
     */
    function getActionButtons(sub) {
        const status = parseInt(sub.prosub_status);
        const isDeleted = sub.is_deleted;

        // 🔹 【修改邏輯】學生一提交，科辦隨時都可以審核（移除期限檢查）
        let isDeadlinePassed = true;  // 設為 true，允許隨時審核

        if (isDeleted) {
            return `
                <button class="btn-restore" onclick="restoreSubmission(${sub.prosub_ID})" title="恢復">
                    <i class="fa-solid fa-undo"></i>
                </button>
            `;
        }

        let buttons = '';

        // 🔹 【修改邏輯】隨時都可以審核
        if (isDeadlinePassed) {
            if (status === 1) {
                // 審核中：可以通過或退件
                buttons += `
                    <button class="btn-approve" onclick="reviewSubmission(${sub.prosub_ID}, 'approve')" title="通過">
                        <i class="fa-solid fa-check"></i>
                    </button>
                    <button class="btn-reject" onclick="reviewSubmission(${sub.prosub_ID}, 'reject')" title="退件">
                        <i class="fa-solid fa-times"></i>
                    </button>
                `;
            } else if (status === 3) {
                // 已通過：可以取消通過
                buttons += `
                    <button class="btn-cancel" onclick="reviewSubmission(${sub.prosub_ID}, 'cancel_approve')" title="取消通過">
                        <i class="fa-solid fa-undo"></i>
                    </button>
                `;
            } else if (status === 0) {
                // 已退件：可以取消退件
                buttons += `
                    <button class="btn-cancel" onclick="reviewSubmission(${sub.prosub_ID}, 'cancel_reject')" title="取消退件">
                        <i class="fa-solid fa-undo"></i>
                    </button>
                `;
            }
        }

        // 科辦可以刪除
        buttons += `
            <button class="btn-delete" onclick="deleteSubmission(${sub.prosub_ID})" title="刪除">
                <i class="fa-solid fa-trash"></i>
            </button>
        `;

        return buttons;
    }

    /**
     * 查看提交詳情
     */
    window.viewSubmission = async function (prosub_ID) {
        try {
            const response = await fetch(`${API_BASE}?do=get_detail&prosub_ID=${prosub_ID}`);
            const data = await response.json();

            if (data.success && data.data) {
                showDetailModal(data.data);
            } else {
                await showAlertDialog('獲取詳情失敗：' + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error('查看詳情失敗:', error);
            await showAlertDialog('查看詳情失敗，請稍後再試', 'error');
        }
    };

    /**
     * 查看團隊提交記錄（使用 hash 路由載入，保持 sidebar 顯示）
     * @param {number} team_ID - 團隊ID
     * @param {number|null} prosub_ID - 提交記錄ID（未使用，保留以保持兼容性）
     */
    window.viewTeamSubmissions = function (team_ID, prosub_ID) {
        if (!team_ID) return;

        // 使用 hash 路由載入頁面，保持 sidebar 顯示
        const pathname = window.location.pathname;

        if (pathname.includes('main.php')) {
            // 在 main.php 中，使用 hash 路由
            window.location.hash = `pages/team_submission_detail.php?team_ID=${team_ID}`;
        } else {
            // 不在 main.php 中，跳轉到 main.php 並設置 hash
            window.location.href = `../main.php#pages/team_submission_detail.php?team_ID=${team_ID}`;
        }
    }

    /**
     * 查看提交詳情（從模態框調用）
     * 注意：此功能已由各個子頁面（如 team_submission_detail.php）自行實現，
     * 這裡不再提供衝突的全局實現，以避免跑版問題。
     */
    // window.viewSubmissionDetail = async function(prosub_ID) {
    //     window.location.href = `pages/project_upload.php?view=${prosub_ID}`;
    // }

    /**
     * 顯示備註輸入視窗
     */
    function showRemarkModal(action, actionText) {
        return new Promise((resolve) => {
            const isRequired = action === 'reject'; // 退件時必填
            const placeholder = action === 'reject'
                ? '請輸入退件原因...'
                : '請輸入審核備註（可選）...';

            // 創建模態框
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'remarkModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'remarkModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center;';

            // 創建背景遮罩
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1054;';

            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="background: #fff; border-radius: 14px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,0.15); overflow: hidden; min-width: 400px;">
                        <div style="padding: 36px 32px 0; text-align: center;">
                            <div style="width: 72px; height: 72px; margin: 0 auto 20px; border: 2px solid #7eb8da; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <span style="font-size: 36px; font-weight: 600; color: #7eb8da;">?</span>
                            </div>
                            <h5 class="modal-title" id="remarkModalLabel" style="font-weight: 600; font-size: 20px; color: #333; margin: 0 0 24px;">${actionText}審核</h5>
                        </div>
                        <div class="modal-body" style="padding: 0 32px 24px;">
                            <div class="mb-3" style="text-align: left;">
                                <label for="remarkTextarea" class="form-label" style="font-weight: 600; margin-bottom: 10px; color: #333; font-size: 16px;">
                                    ${isRequired ? '<span class="text-danger">*</span> ' : ''}備註
                                </label>
                                <textarea 
                                    class="form-control" 
                                    id="remarkTextarea" 
                                    rows="4" 
                                    placeholder="${placeholder}"
                                    style="border-radius: 8px; resize: vertical; font-size: 15px;"
                                    ${isRequired ? 'required' : ''}
                                ></textarea>
                                ${!isRequired ? '<small class="text-muted">此欄位為選填，可直接點擊確定跳過</small>' : ''}
                            </div>
                        </div>
                        <div style="border-top: 1px solid #e9ecef; padding: 24px 32px; display: flex; justify-content: center; gap: 16px;">
                            <button type="button" class="btn cancel-remark-btn" style="border-radius: 8px; padding: 12px 28px; font-weight: 600; min-width: 100px; font-size: 16px; background: #5a6268; color: #fff; border: none;">取消</button>
                            <button type="button" class="btn confirm-remark-btn" style="border-radius: 8px; padding: 12px 28px; font-weight: 600; min-width: 100px; font-size: 16px; background: #667eea; color: #fff; border: none;">確定</button>
                        </div>
                    </div>
                </div>
            `;

            // 添加到頁面
            document.body.appendChild(backdrop);
            document.body.appendChild(modal);
            document.body.style.overflow = 'hidden';

            // 顯示動畫
            requestAnimationFrame(() => {
                backdrop.style.opacity = '1';
                modal.style.opacity = '1';
            });

            // 聚焦到 textarea
            const textarea = modal.querySelector('#remarkTextarea');
            if (textarea) {
                setTimeout(() => textarea.focus(), 100);
            }

            // 關閉函數
            const closeModal = (result) => {
                backdrop.style.opacity = '0';
                modal.style.opacity = '0';

                setTimeout(() => {
                    if (document.body.contains(modal)) {
                        document.body.removeChild(modal);
                    }
                    if (document.body.contains(backdrop)) {
                        document.body.removeChild(backdrop);
                    }
                    document.body.style.overflow = '';
                    resolve(result);
                }, 150);
            };

            // 確定按鈕
            const confirmBtn = modal.querySelector('.confirm-remark-btn');
            confirmBtn.onclick = function () {
                const remark = textarea.value.trim();
                if (isRequired && !remark) {
                    showAlertDialog('請輸入退件原因', 'warning');
                    textarea.focus();
                    return;
                }
                closeModal(remark);
            };

            // 取消按鈕
            const cancelBtn = modal.querySelector('.cancel-remark-btn');
            cancelBtn.onclick = function () {
                closeModal(null);
            };

            // 點擊背景關閉（只有非必填時才允許）
            if (!isRequired) {
                backdrop.onclick = function () {
                    closeModal('');
                };
            }

            // ESC 鍵關閉
            const escHandler = function (e) {
                if (e.key === 'Escape') {
                    closeModal(null);
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        });
    }

    /**
     * 審核提交
     */
    window.reviewSubmission = async function (prosub_ID, action) {
        const actionText = {
            'approve': '通過',
            'reject': '退件',
            'cancel_approve': '取消通過',
            'cancel_reject': '取消退件'
        }[action] || '操作';

        let reason = '';

        // 如果是退件，顯示備註視窗（通過審核不需要備註，直接通過）
        if (action === 'reject') {
            const remark = await showRemarkModal(action, actionText);
            if (remark === null) {
                return; // 用戶取消
            }
            reason = remark || '';
        }

        // 確認操作
        const confirmed = await showConfirmDialog(`確定要${actionText}此提交嗎？`, '確認操作');
        if (!confirmed) {
            return;
        }

        try {
            const formData = new FormData();
            formData.append('prosub_ID', prosub_ID);
            formData.append('action', action);
            formData.append('reason', reason);

            const response = await fetch(`${API_BASE}?do=review`, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                await showAlertDialog(`${actionText}成功`, 'success', 2000);
                loadSubmissions();
            } else {
                await showAlertDialog(`${actionText}失敗：${data.message || '未知錯誤'}`, 'error');
            }
        } catch (error) {
            console.error('審核失敗:', error);
            await showAlertDialog(`${actionText}失敗，請稍後再試`, 'error');
        }
    };

    /**
     * 刪除提交
     */
    window.deleteSubmission = async function (prosub_ID) {
        const confirmed = await showConfirmDialog('確定要刪除此提交嗎？刪除後可以恢復。', '確認刪除');
        if (!confirmed) {
            return;
        }

        try {
            const formData = new FormData();
            formData.append('prosub_ID', prosub_ID);

            const response = await fetch(`${API_BASE}?do=delete`, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                await showAlertDialog('已刪除', 'success', 2000);
                loadSubmissions();
            } else {
                await showAlertDialog('刪除失敗：' + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error('刪除失敗:', error);
            await showAlertDialog('刪除失敗，請稍後再試', 'error');
        }
    };

    /**
     * 恢復提交
     */
    window.restoreSubmission = async function (prosub_ID) {
        const confirmed = await showConfirmDialog('確定要恢復此提交嗎？', '確認恢復');
        if (!confirmed) {
            return;
        }

        try {
            const formData = new FormData();
            formData.append('prosub_ID', prosub_ID);

            const response = await fetch(`${API_BASE}?do=restore`, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                await showAlertDialog('已恢復', 'success', 2000);
                loadSubmissions();
            } else {
                await showAlertDialog('恢復失敗：' + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error('恢復失敗:', error);
            await showAlertDialog('恢復失敗，請稍後再試', 'error');
        }
    };

    /**
     * 顯示詳情 Modal
     */
    function showDetailModal(submission) {
        // 這裡可以使用與 project_upload.js 相同的 Modal 邏輯
        // 暫時使用 alert 顯示
        const info = `
提交ID: ${submission.prosub_ID}
團隊名稱: ${submission.team_project_name || '未知'}
專題名稱: ${submission.pro_title || '未知'}
提交時間: ${formatDate(submission.prosub_created_d)}
更新時間: ${formatDate(submission.prosub_update_d)}
提交者: ${submission.submitter_name || '未知'}
狀態: ${getStatusInfo(submission.prosub_status, submission.is_deleted).text}
        `.trim();

        showAlertDialog(info, 'info');
    }

    /**
     * 顯示團隊歷史 Modal
     */
    function showTeamHistoryModal(team_ID, submissions) {
        const count = submissions.length;
        const info = `團隊共有 ${count} 筆提交記錄\n\n` +
            submissions.map((sub, index) => {
                const status = getStatusInfo(sub.prosub_status, sub.is_deleted).text;
                return `${index + 1}. ${formatDate(sub.prosub_created_d)} - ${status}`;
            }).join('\n');

        showAlertDialog(info, 'info');
    }

    /**
     * 處理搜尋
     */
    function handleSearch() {
        loadSubmissions();
    }

    /**
     * 處理清除
     */
    function handleClear() {
        if (searchInput) searchInput.value = '';
        if (cohortSelect) cohortSelect.value = '';
        if (groupSelect) groupSelect.value = '';
        if (statusSelect) statusSelect.value = '';
        loadSubmissions();
    }

    /**
     * 格式化日期
     */
    function formatDate(dateString) {
        if (!dateString) return '無';
        const date = new Date(dateString);
        return date.toLocaleString('zh-TW');
    }

    /**
     * 轉義 HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // 初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

