/**
 * 歷屆專題審核上架頁面 - 初始化與功能
 */

(function() {
    'use strict';

    const API_BASE = 'pages/history_api.php';
    let currentProjects = [];

    /**
     * 自定義提示框（替換 alert）
     * @param {string} message - 提示訊息
     * @param {string} type - 類型：'success', 'error', 'info', 'warning'（默認 'info'）
     * @param {number} autoClose - 自動關閉時間（毫秒），0 表示不自動關閉（默認 0）
     * @returns {Promise<void>}
     */
    /**
     * 自定義確認對話框（替換 confirm）
     * @param {string} message - 確認訊息
     * @param {string} title - 標題（可選）
     * @returns {Promise<boolean>} - 返回 true 表示確認，false 表示取消
     */
    function showConfirmDialog(message, title = '確認') {
        return new Promise((resolve) => {
            // 創建模態框
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'customConfirmModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'customConfirmModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center;';
            
            // 創建背景遮罩
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1054;';
            
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="background: #fff; border-radius: 14px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,0.15); padding: 36px 32px; text-align: center; min-width: 360px;">
                        <div style="width: 72px; height: 72px; margin: 0 auto 20px; border: 2px solid #7eb8da; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <span style="font-size: 36px; font-weight: 600; color: #7eb8da;">?</span>
                        </div>
                        <h5 class="modal-title" id="customConfirmModalLabel" style="font-weight: 600; font-size: 20px; color: #333; margin: 0 0 12px;">${escapeHtml(title)}</h5>
                        <p style="font-size: 17px; color: #333; line-height: 1.5; margin: 0 0 28px;">${escapeHtml(message).replace(/\n/g, '<br>')}</p>
                        <div style="display: flex; justify-content: center; gap: 16px;">
                            <button type="button" class="btn cancel-btn" style="border-radius: 8px; padding: 12px 28px; font-weight: 600; min-width: 100px; font-size: 16px; background: #5a6268; color: #fff; border: none;">取消</button>
                            <button type="button" class="btn confirm-btn" style="border-radius: 8px; padding: 12px 28px; font-weight: 600; min-width: 100px; font-size: 16px; background: #667eea; color: #fff; border: none;">確定</button>
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
            
            // 關閉模態框
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
            
            // 綁定按鈕事件
            const confirmBtn = modal.querySelector('.confirm-btn');
            const cancelBtn = modal.querySelector('.cancel-btn');
            
            confirmBtn.onclick = () => closeModal(true);
            cancelBtn.onclick = () => closeModal(false);
            backdrop.onclick = () => closeModal(false);
            
            // ESC 鍵關閉
            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    closeModal(false);
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        });
    }
    
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

    /**
     * 載入並顯示繳交時段列表（統一期限，從 projectdata 獲取）
     */
    async function loadDeadlineList() {
        const deadlineList = document.getElementById('deadlineList');
        if (!deadlineList) return;
        
        try {
            // 獲取所有不同的學年度及其統一期限
            const response = await fetch(`${API_BASE}?do=get_deadlines`);
            const data = await response.json();
            
            if (!data.success) {
                deadlineList.innerHTML = `
                    <div class="text-center p-3 text-muted">
                        <i class="fa-solid fa-calendar-xmark"></i>
                        <p class="mb-0 mt-2">載入失敗</p>
                    </div>
                `;
                return;
            }
            
            const deadlines = data.deadlines || [];
            
            if (deadlines.length === 0) {
                deadlineList.innerHTML = `
                    <div class="text-center p-3 text-muted">
                        <i class="fa-solid fa-calendar-xmark"></i>
                        <p class="mb-0 mt-2">目前沒有繳交時段</p>
                        <small class="d-block mt-2">請為專題設定統一的上傳期限</small>
                    </div>
                `;
                return;
            }
            
            let html = '<div class="list-group list-group-flush">';
            
            deadlines.forEach((item, index) => {
                const deadline = new Date(item.deadline);
                const now = new Date();
                
                let statusClass = 'secondary';
                let statusText = '未開始';
                
                if (now >= deadline) {
                    statusClass = 'secondary';
                    statusText = '已結束';
                } else {
                    statusClass = 'success';
                    statusText = '進行中';
                }
                
                const formatDate = (dateStr) => {
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
                
                const displayName = item.cohort_name ? `${item.cohort_name} 歷屆專題` : '歷屆專題';
                
                html += `
                    <div class="list-group-item deadline-item ${index === 0 ? 'active' : ''}" 
                         data-cohort-id="${item.cohort_ID}"
                         style="cursor: pointer;">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <h6 class="mb-1" style="font-weight: 600;">${escapeHtml(displayName)}</h6>
                                <small class="text-muted d-block">
                                    ${formatDate(item.deadline)}
                                </small>
                            </div>
                            <span class="badge bg-${statusClass} ms-2">${statusText}</span>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            deadlineList.innerHTML = html;
            
            // 綁定點擊事件（可選，用於高亮顯示）
            deadlineList.querySelectorAll('.deadline-item').forEach(item => {
                item.addEventListener('click', function() {
                    deadlineList.querySelectorAll('.deadline-item').forEach(i => i.classList.remove('active'));
                    this.classList.add('active');
                });
            });
        } catch (error) {
            console.error('載入繳交時段失敗:', error);
            deadlineList.innerHTML = `
                <div class="text-center p-3 text-muted">
                    <i class="fa-solid fa-exclamation-triangle"></i>
                    <p class="mb-0 mt-2">載入失敗</p>
                </div>
            `;
        }
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

    /**
     * 初始化時段設定表單
     */
    function initPeriodForm() {
        const form = document.getElementById('periodForm');
        if (!form) return;
        
        // 🔹 防止重複初始化：如果已經綁定過事件，先移除舊的事件監聽器
        if (form.dataset.initialized === 'true') {
            // 已經初始化過，不重複初始化
            return;
        }
        
        // 載入屆別選項
        loadCohortOptions();
        
        // 綁定屆別選擇變化事件
        const cohortSelect = document.getElementById('periodCohortSelect');
        if (cohortSelect) {
            cohortSelect.addEventListener('change', handleCohortChange);
        }
        
        // 綁定開始日和結束日的即時驗證
        const startInput = document.getElementById('pro_start_d');
        const endInput = document.getElementById('pro_end_d');
        
        // 日期範圍驗證函數
        function validateDateRange() {
            const startInput = document.getElementById('pro_start_d');
            const endInput = document.getElementById('pro_end_d');
            
            if (!startInput || !endInput) return true;
            
            const startValue = startInput.value;
            const endValue = endInput.value;
            
            // 清除之前的錯誤狀態
            startInput.classList.remove('is-invalid');
            endInput.classList.remove('is-invalid');
            
            // 移除之前的錯誤訊息
            const existingErrors = endInput.parentElement.querySelectorAll('.invalid-feedback');
            existingErrors.forEach(err => {
                if (err.style.display !== 'none') {
                    err.remove();
                }
            });
            
            if (!startValue || !endValue) {
                // 如果開始日有值，設定結束日的最小值
                if (startValue) {
                    // 設定結束日的最小值為開始日的下一個分鐘
                    const startDate = new Date(startValue);
                    startDate.setMinutes(startDate.getMinutes() + 1);
                    const minDateTime = startDate.toISOString().slice(0, 16);
                    endInput.min = minDateTime;
                }
                return true; // 如果任一為空，不驗證
            }
            
            const startTime = new Date(startValue);
            const endTime = new Date(endValue);
            
            if (isNaN(startTime.getTime()) || isNaN(endTime.getTime())) {
                return true; // 日期格式錯誤，讓瀏覽器原生驗證處理
            }
            
            if (endTime <= startTime) {
                // 顯示錯誤
                endInput.classList.add('is-invalid');
                let errorDiv = endInput.parentElement.querySelector('.invalid-feedback');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.className = 'invalid-feedback';
                    endInput.parentElement.appendChild(errorDiv);
                }
                errorDiv.textContent = '結束時間必須晚於開始時間';
                errorDiv.style.display = 'block';
                return false;
            }
            
            return true;
        }
        
        // 更新結束日的最小值（將起始日之前的日期設為不可選/反灰）
        function updateEndDateMin() {
            if (!startInput || !endInput) return;
            
            if (startInput.value) {
                // 設定結束日的最小值為開始日的下一個分鐘
                // 這樣瀏覽器會自動將起始日之前的日期反灰（禁用）
                const startDate = new Date(startInput.value);
                startDate.setMinutes(startDate.getMinutes() + 1);
                const minDateTime = startDate.toISOString().slice(0, 16);
                endInput.min = minDateTime;
                
                // 如果結束日已選擇但早於或等於開始日，自動清除
                if (endInput.value) {
                    const startTime = new Date(startInput.value);
                    const endTime = new Date(endInput.value);
                    if (!isNaN(startTime.getTime()) && !isNaN(endTime.getTime()) && endTime <= startTime) {
                        endInput.value = '';
                        validateDateRange();
                    }
                }
            } else {
                // 如果開始日被清空，移除結束日的最小值限制
                endInput.min = '';
            }
        }
        
        if (startInput) {
            startInput.addEventListener('change', function() {
                updateEndDateMin();
                validateDateRange();
            });
            
            // 頁面載入時也檢查一次（如果有預設值）
            updateEndDateMin();
        }
        
        if (endInput) {
            endInput.addEventListener('change', function() {
                validateDateRange();
            });
        }
        
        // 顯示自定義提示框
        function showMessage(type, title, message, callback) {
            // 如果有 SweetAlert，使用它
            if (window.Swal) {
                Swal.fire({
                    icon: type,
                    title: title,
                    text: message,
                    confirmButtonText: '確定',
                    confirmButtonColor: type === 'success' ? '#28a745' : '#dc3545',
                    timer: type === 'success' ? 2000 : null,
                    showConfirmButton: type === 'success' ? false : true
                }).then(() => {
                    if (callback) callback();
                });
                return;
            }
            
            // 否則使用自定義模態框
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center;';
            
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1054;';
            
            const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
            const iconColor = type === 'success' ? '#28a745' : '#dc3545';
            
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="background: #fff; border-radius: 14px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,0.15); padding: 36px 32px; text-align: center; min-width: 360px;">
                        <div style="width: 72px; height: 72px; margin: 0 auto 20px; border: 2px solid ${iconColor}; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="fa-solid ${iconClass}" style="font-size: 32px; color: ${iconColor};"></i>
                        </div>
                        <h5 class="modal-title" style="font-weight: 600; font-size: 20px; color: #333; margin: 0 0 12px;">${escapeHtml(title)}</h5>
                        <p style="font-size: 17px; color: #333; line-height: 1.5; margin: 0 0 28px;">${escapeHtml(message)}</p>
                        <button type="button" class="btn confirm-msg-btn" style="border-radius: 8px; padding: 12px 28px; font-weight: 600; min-width: 100px; font-size: 16px; background: #667eea; color: #fff; border: none;">確定</button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(backdrop);
            document.body.appendChild(modal);
            document.body.style.overflow = 'hidden';
            
            const closeModal = () => {
                backdrop.style.opacity = '0';
                modal.style.opacity = '0';
                setTimeout(() => {
                    if (document.body.contains(modal)) document.body.removeChild(modal);
                    if (document.body.contains(backdrop)) document.body.removeChild(backdrop);
                    document.body.style.overflow = '';
                    if (callback) callback();
                }, 150);
            };
            
            const confirmBtn = modal.querySelector('.confirm-msg-btn');
            confirmBtn.onclick = closeModal;
            backdrop.onclick = closeModal;
            
            // 自動關閉（成功訊息）
            if (type === 'success') {
                setTimeout(closeModal, 2000);
            }
        }
        
        // 綁定表單提交事件（加強防呆驗證）
        // 🔹 使用表單的數據屬性來追蹤提交狀態，避免重複提交
        let isSubmitting = false; // 防止重複提交
        
        // 🔹 移除舊的事件監聽器（如果存在）
        const oldHandler = form._submitHandler;
        if (oldHandler) {
            form.removeEventListener('submit', oldHandler);
        }
        
        // 創建新的事件處理函數
        const submitHandler = async function(e) {
            e.preventDefault();
            e.stopImmediatePropagation(); // 阻止其他事件監聽器執行
            
            // 防止重複提交
            if (isSubmitting || form.dataset.submitting === 'true') {
                console.log('正在提交中，跳過重複提交');
                return;
            }
            
            // 驗證所有必填欄位
            const startInput = document.getElementById('pro_start_d');
            const endInput = document.getElementById('pro_end_d');
            const titleInput = document.getElementById('pro_title');
            const cohortSelect = document.getElementById('periodCohortSelect');
            
            // 驗證開始日期
            if (!startInput || !startInput.value) {
                showMessage('error', '驗證失敗', '請選擇開始日期');
                startInput?.focus();
                return;
            }
            
            // 驗證結束日期
            if (!endInput || !endInput.value) {
                showMessage('error', '驗證失敗', '請選擇結束日期');
                endInput?.focus();
                return;
            }
            
            // 驗證標題
            if (!titleInput || !titleInput.value.trim()) {
                showMessage('error', '驗證失敗', '請輸入標題');
                titleInput?.focus();
                return;
            }
            
            // 驗證屆別
            const selectedCohorts = Array.from(cohortSelect?.selectedOptions || [])
                .map(opt => opt.value)
                .filter(Boolean);
            
            if (selectedCohorts.length === 0) {
                showMessage('error', '驗證失敗', '請至少選擇一個屆別');
                cohortSelect?.focus();
                return;
            }
            
            // 驗證日期範圍
            if (!validateDateRange()) {
                endInput.focus();
                return;
            }
            
            const startTime = new Date(startInput.value);
            const endTime = new Date(endInput.value);
            
            if (isNaN(startTime.getTime()) || isNaN(endTime.getTime())) {
                showMessage('error', '驗證失敗', '日期格式錯誤，請重新選擇');
                return;
            }
            
            if (endTime <= startTime) {
                showMessage('error', '驗證失敗', '結束時間必須晚於開始時間');
                endInput.focus();
                return;
            }
            
            // 檢查日期是否在過去（可選的額外驗證）
            const now = new Date();
            if (endTime < now) {
                const confirmed = await showConfirmDialog('結束日期已過期，確定要建立此時段嗎？', '確認操作');
                if (!confirmed) {
                    return;
                }
            }
            
            const formData = new FormData(form);
            // 收集可上傳檔案類型（checkbox）並轉成 JSON 字串（不含海報、其他）
            const typeValues = [];
            ['report','ppt','word'].forEach(t => {
                const el = document.getElementById('fileType_' + t);
                if (el && el.checked) typeValues.push(t);
            });
            formData.set('allow_file_types', JSON.stringify(typeValues));
            const action = formData.get('action');
            const submitBtn = document.getElementById('submitBtn');
            const originalBtnText = submitBtn ? submitBtn.textContent : '提交';
            
            // 顯示載入狀態
            isSubmitting = true;
            form.dataset.submitting = 'true'; // 標記表單正在提交
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>處理中...';
            }
            
            try {
                const response = await fetch(`${API_BASE}?do=${action}`, {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    showMessage('success', '操作成功', data.message || (action === 'create' ? '已新增專題時段' : '已更新專題時段'), () => {
                        resetPeriodForm();
                        // 🔹 刷新近期設定列表
                        loadRecentPeriods();
                    });
                } else {
                    showMessage('error', '操作失敗', data.message || '未知錯誤');
                }
            } catch (error) {
                console.error('提交表單錯誤:', error);
                showMessage('error', '連線錯誤', '無法連接到伺服器，請稍後再試');
            } finally {
                // 恢復按鈕狀態
                isSubmitting = false;
                form.dataset.submitting = 'false'; // 清除提交標記
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalBtnText;
                }
            }
        };
        
        // 保存事件處理函數的引用，以便後續移除
        form._submitHandler = submitHandler;
        form.addEventListener('submit', submitHandler);
        
        // 標記表單已初始化
        form.dataset.initialized = 'true';
    }

    /**
     * 顯示過去時段的模態框
     */
    async function showPastPeriodsModal() {
        // 檢查是否已有模態框
        let modal = document.getElementById('pastPeriodsModal');
        if (modal) {
            modal.style.display = 'flex';
            loadPastPeriodsList();
            return;
        }
        
        // 創建模態框
        modal = document.createElement('div');
        modal.id = 'pastPeriodsModal';
        modal.className = 'modal fade';
        modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.5);';
        
        const modalDialog = document.createElement('div');
        modalDialog.className = 'modal-dialog modal-lg';
        modalDialog.style.cssText = 'max-width: 800px; width: 90%; margin: 0;';
        
        const modalContent = document.createElement('div');
        modalContent.className = 'modal-content';
        
        modalContent.innerHTML = `
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fa-solid fa-history me-2"></i>過去開放的時段
                </h5>
                <button type="button" class="btn-close btn-close-white" onclick="closePastPeriodsModal()"></button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <div id="pastPeriodsList" class="text-center p-4">
                    <i class="fa-solid fa-spinner fa-spin"></i>
                    <span class="ms-2">載入中...</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePastPeriodsModal()">關閉</button>
            </div>
        `;
        
        modalDialog.appendChild(modalContent);
        modal.appendChild(modalDialog);
        document.body.appendChild(modal);
        
        // 點擊背景關閉
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closePastPeriodsModal();
            }
        });
        
        // 載入過去時段列表
        loadPastPeriodsList();
    }
    
    /**
     * 關閉過去時段模態框
     */
    window.closePastPeriodsModal = function() {
        const modal = document.getElementById('pastPeriodsModal');
        if (modal) {
            modal.style.display = 'none';
        }
    };
    
    /**
     * 載入過去時段列表
     */
    async function loadPastPeriodsList() {
        const container = document.getElementById('pastPeriodsList');
        if (!container) return;
        
        try {
            container.innerHTML = `
                <div class="text-center p-4">
                    <i class="fa-solid fa-spinner fa-spin"></i>
                    <span class="ms-2">載入中...</span>
                </div>
            `;
            
            const response = await fetch(`${API_BASE}?do=get_all_periods`, {
                credentials: 'include',
                cache: 'no-store'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (!data.success) {
                container.innerHTML = `
                    <div class="text-center p-4 text-danger">
                        <i class="fa-solid fa-exclamation-triangle"></i>
                        <p class="mb-0 mt-2">載入失敗：${escapeHtml(data.message || '未知錯誤')}</p>
                    </div>
                `;
                return;
            }
            
            const periods = data.data || [];
            
            if (periods.length === 0) {
                container.innerHTML = `
                    <div class="text-center p-4 text-muted">
                        <i class="fa-solid fa-calendar-xmark"></i>
                        <p class="mb-0 mt-2">目前沒有過去開放的時段</p>
                    </div>
                `;
                return;
            }
            
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
                });
            };
            
            let html = '<div class="table-responsive"><table class="table table-hover table-sm">';
            html += '<thead class="table-light"><tr>';
            html += '<th>標題</th><th>學級</th><th>開始時間</th><th>結束時間</th><th>狀態</th><th>建立時間</th>';
            html += '</tr></thead><tbody>';
            
            periods.forEach(period => {
                const statusBadge = period.pro_status == 1 
                    ? '<span class="badge bg-success">啟用</span>' 
                    : '<span class="badge bg-secondary">停用</span>';
                
                html += `
                    <tr>
                        <td><strong>${escapeHtml(period.pro_title || '未命名')}</strong></td>
                        <td>${escapeHtml(period.cohort_name || '未設定')}</td>
                        <td>${formatDateTime(period.pro_start_d)}</td>
                        <td>${formatDateTime(period.pro_end_d)}</td>
                        <td>${statusBadge}</td>
                        <td class="text-muted small">${formatDateTime(period.pro_created_d)}</td>
                    </tr>
                `;
            });
            
            html += '</tbody></table></div>';
            container.innerHTML = html;
        } catch (error) {
            console.error('載入過去時段失敗:', error);
            container.innerHTML = `
                <div class="text-center p-4 text-danger">
                    <i class="fa-solid fa-exclamation-triangle"></i>
                    <p class="mb-0 mt-2">載入失敗，請稍後再試</p>
                    <button class="btn btn-sm btn-outline-secondary mt-2" onclick="loadPastPeriodsList()">重試</button>
                </div>
            `;
        }
    }

    /**
     * 載入時段列表（表格形式）
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
                const errorText = await response.text();
                console.error('API 響應錯誤:', response.status, errorText);
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const text = await response.text();
            console.log('loadRecentPeriods raw response:', text);
            
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('JSON 解析失敗:', e, '原始內容:', text);
                throw new Error('伺服器回應格式錯誤');
            }
            
            console.log('loadRecentPeriods parsed response:', data);
            
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
                            <button class="btn btn-sm btn-outline-primary me-1" onclick="editPeriod(${period.pro_ID})" title="編輯">
                                <i class="fa-solid fa-edit"></i>
                            </button>
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
     * 切換時段狀態（啟用/停用）
     */
    window.togglePeriodStatus = async function(pro_ID, currentStatus) {
        if (!pro_ID) return;
        
        const action = currentStatus == 1 ? '停用' : '啟用';
        const confirmed = await showConfirmDialog(
            `確定要${action}此時段嗎？`,
            '確認操作'
        );
        
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
                await showAlertDialog(`${action}成功！`, 'success', 2000);
                loadRecentPeriods(); // 重新載入列表
            } else {
                await showAlertDialog(`${action}失敗：` + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error('切換時段狀態失敗:', error);
            await showAlertDialog('操作失敗，請稍後再試', 'error');
        }
    };
    
    /**
     * 刪除時段（整筆資料刪除）
     */
    window.deletePeriod = async function(pro_ID, title) {
        if (!pro_ID) return;
        
        const confirmed = await showConfirmDialog(
            `確定要刪除時段「${escapeHtml(title)}」嗎？\n此操作將永久刪除該時段資料，無法復原。`,
            '確認刪除'
        );
        
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
                await showAlertDialog('刪除成功！', 'success', 2000);
                loadRecentPeriods(); // 重新載入列表
            } else {
                await showAlertDialog('刪除失敗：' + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error('刪除時段失敗:', error);
            await showAlertDialog('刪除失敗，請稍後再試', 'error');
        }
    };

    /**
     * 編輯時段（載入到表單）
     */
    window.editPeriod = async function(pro_ID) {
        if (!pro_ID) return;
        
        try {
            const response = await fetch(`${API_BASE}?do=get_periods`, {
                credentials: 'include'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (!data.success || !Array.isArray(data.data)) {
                showMessage('error', '載入失敗', '無法載入時段資料');
                return;
            }
            
            const period = data.data.find(p => p.pro_ID == pro_ID);
            if (!period) {
                showMessage('error', '找不到資料', '該時段不存在');
                return;
            }
            
            // 填充表單
            const form = document.getElementById('periodForm');
            if (!form) return;
            
            document.getElementById('form_action').value = 'update';
            document.getElementById('pro_ID').value = period.pro_ID;
            document.getElementById('pro_title').value = period.pro_title || '';
            // 還原可上傳檔案類型 checkbox（不含海報、其他）
            const allowedTypes = Array.isArray(period.allow_file_types) ? period.allow_file_types : [];
            ['report','ppt','word'].forEach(t => {
                const el = document.getElementById('fileType_' + t);
                if (el) el.checked = allowedTypes.includes(t);
            });
            
            // 轉換日期格式（從 YYYY-MM-DD HH:mm:ss 轉為 YYYY-MM-DDTHH:mm）
            const formatDateForInput = (dateStr) => {
                if (!dateStr) return '';
                const date = new Date(dateStr);
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                return `${year}-${month}-${day}T${hours}:${minutes}`;
            };
            
            document.getElementById('pro_start_d').value = formatDateForInput(period.pro_start_d);
            document.getElementById('pro_end_d').value = formatDateForInput(period.pro_end_d);
            
            // 設定屆別
            const cohortSelect = document.getElementById('periodCohortSelect');
            if (cohortSelect && period.cohort_ID) {
                // 先確保選項已載入
                if (cohortSelect.options.length === 0 || cohortSelect.options[0].value === '') {
                    await loadCohortOptions();
                }
                // 設定選中的屆別
                Array.from(cohortSelect.options).forEach(opt => {
                    opt.selected = opt.value == period.cohort_ID;
                });
                handleCohortChange();
            }
            
            // 顯示取消編輯按鈕
            const cancelBtn = document.getElementById('cancelEditBtn');
            if (cancelBtn) cancelBtn.classList.remove('d-none');
            
            // 更改提交按鈕文字
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) submitBtn.textContent = '更新';
            
            // 滾動到表單
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            
        } catch (error) {
            console.error('載入時段資料失敗:', error);
            showMessage('error', '載入失敗', '無法載入時段資料，請稍後再試');
        }
    };

    /**
     * 載入屆別選項到篩選下拉選單
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
    
    /**
     * 載入屆別選項（從資料庫）
     */
    async function loadCohortOptions() {
        const select = document.getElementById('periodCohortSelect');
        if (!select) return;
        
        // 保存当前选中的值，避免闪烁
        const currentSelected = Array.from(select.selectedOptions).map(opt => opt.value);
        const wasDisabled = select.disabled;
        
        try {
            // 如果选项已经存在且不是"载入中"，不清空，避免闪烁
            // 不设置 disabled，避免反灰效果
            if (select.options.length === 0 || select.options[0].value === '') {
                select.innerHTML = '<option value="">載入中...</option>';
            }
            
            const response = await fetch(`${API_BASE}?do=get_cohorts`, {
                credentials: 'include'
            });
            const data = await response.json();
            
            if (data.success && Array.isArray(data.data) && data.data.length > 0) {
                // 使用 requestAnimationFrame 平滑更新
                requestAnimationFrame(() => {
                    select.innerHTML = '';
                    data.data.forEach(cohort => {
                        const option = document.createElement('option');
                        option.value = cohort.cohort_ID;
                        option.textContent = `${cohort.cohort_name} (${cohort.year_label})`;
                        select.appendChild(option);
                    });
                    
                    // 恢复之前选中的值
                    if (currentSelected.length > 0) {
                        Array.from(select.options).forEach(opt => {
                            opt.selected = currentSelected.includes(opt.value);
                        });
                    }
                    
                    select.disabled = wasDisabled;
                });
            } else {
                // API 返回明確的錯誤訊息，顯示給用戶
                requestAnimationFrame(() => {
                    const message = data.message || '尚無可選屆別';
                    select.innerHTML = `<option value="">${message}</option>`;
                    // 只有在没有选项时才禁用
                    if (data.data && data.data.length === 0) {
                        select.disabled = true;
                    }
                });
            }
        } catch (error) {
            console.error('載入屆別選項失敗:', error);
            requestAnimationFrame(() => {
                select.innerHTML = '<option value="">載入失敗</option>';
                // 错误时也不禁用，保持可用状态
            });
        }
    }

    /**
     * 處理屆別選擇變化
     */
    function handleCohortChange() {
        const select = document.getElementById('periodCohortSelect');
        if (!select) return;
        
        const selected = Array.from(select.selectedOptions)
            .map(option => option.value)
            .filter(Boolean);
        
        const valuesInput = document.getElementById('cohort_values');
        if (valuesInput) valuesInput.value = selected.join(',');
        
        const primaryInput = document.getElementById('cohort_primary');
        if (primaryInput) primaryInput.value = selected[0] || '';
        
        // 班級選項已經直接載入，不需要根據屆別過濾
        // 保留此函數以備將來需要時使用
    }


    /**
     * 清空表單
     */
    window.resetPeriodForm = function() {
        const form = document.getElementById('periodForm');
        if (!form) return;
        
        form.reset();
        document.getElementById('form_action').value = 'create';
        document.getElementById('pro_ID').value = '';
        document.getElementById('cohort_values').value = '';
        document.getElementById('cohort_primary').value = '';
        
        const cancelBtn = document.getElementById('cancelEditBtn');
        if (cancelBtn) cancelBtn.classList.add('d-none');
        
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) submitBtn.textContent = '新增';
        
        // 重新載入屆別選項
        loadCohortOptions();
        
        // 重新載入所有班級選項
    };

    /**
     * 初始化頁面
     */
    function init() {
        setupEventListeners();
        initPeriodForm(); // 初始化時段設定表單
        loadRecentPeriods(); // 🔹 載入時段管理列表
        
        // 定期檢查並自動鎖定過期專題（每5分鐘檢查一次）
        setInterval(() => {
            if (window.HistoryAPI && window.HistoryAPI.checkDeadlines) {
                window.HistoryAPI.checkDeadlines().catch(err => {
                    console.error('自動檢查期限失敗:', err);
                });
            }
            // 重新載入時段列表以更新狀態
            loadDeadlineList();
        }, 5 * 60 * 1000);
        
        // 頁面載入時檢查一次
        if (window.HistoryAPI && window.HistoryAPI.checkDeadlines) {
            window.HistoryAPI.checkDeadlines().catch(err => {
                console.error('初始檢查期限失敗:', err);
            });
        }
    }

    /**
     * 設置事件監聽器
     */
    function setupEventListeners() {
        // 時段篩選按鈕
        const periodFilterBtn = document.getElementById('periodFilterBtn');
        if (periodFilterBtn) {
            periodFilterBtn.addEventListener('click', function() {
                loadRecentPeriods();
            });
        }
        
        // 時段篩選清除按鈕
        const periodFilterClearBtn = document.getElementById('periodFilterClearBtn');
        if (periodFilterClearBtn) {
            periodFilterClearBtn.addEventListener('click', function() {
                const filterCohort = document.getElementById('periodFilterCohort');
                const filterStatus = document.getElementById('periodFilterStatus');
                if (filterCohort) filterCohort.value = '';
                if (filterStatus) filterStatus.value = '';
                loadRecentPeriods();
            });
        }
        
        // 載入屆別選項到篩選下拉選單
        loadPeriodFilterCohorts();
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
     * 更新上架按鈕顯示狀態
     */
    function updateBatchPublishButton() {
        const batchPublishSelectedBtn = document.getElementById('batchPublishSelectedBtn');
        const checkboxes = document.querySelectorAll('.project-checkbox:checked');
        if (batchPublishSelectedBtn) {
            if (checkboxes.length > 0) {
                batchPublishSelectedBtn.style.display = 'inline-block';
                batchPublishSelectedBtn.innerHTML = `<i class="fa-solid fa-upload"></i>上架 (${checkboxes.length})`;
            } else {
                batchPublishSelectedBtn.style.display = 'none';
            }
        }
    }
    
    /**
     * 處理上架（選中的專題）
     */
    async function handleBatchPublishSelected() {
        const checkboxes = document.querySelectorAll('.project-checkbox:checked');
        if (checkboxes.length === 0) {
            await showAlertDialog('請至少選擇一個專題', 'error');
            return;
        }
        
        const selectedIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
        const selectedCount = selectedIds.length;
        
        const confirmed = await showConfirmDialog(
            `確定要上架選中的 ${selectedCount} 個專題嗎？\n此操作將把選中的專題設為啟用狀態。`,
            '確認批量上架'
        );
        if (!confirmed) {
            return;
        }
        
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
                await showAlertDialog(`批量上架成功！\n已上架 ${data.count || 0} 個專題。`, 'success', 2000);
                // 清除所有選中狀態
                checkboxes.forEach(cb => cb.checked = false);
                updateSelectAllCheckbox();
                updateBatchPublishButton();
                // 重新載入列表並強制從伺服器獲取最新數據
                await loadProjects(true);
            } else {
                await showAlertDialog('批量上架失敗：' + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error('批量上架錯誤:', error);
            await showAlertDialog('批量上架失敗，請稍後再試', 'error');
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
        const confirmed = await showConfirmDialog(
            '確定要一併上架所有已截止的通過專題嗎？\n此操作將把所有通過的專題設為啟用狀態。',
            '確認一併上架'
        );
        if (!confirmed) {
            return;
        }
        
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
                await showAlertDialog(`一併上架成功！\n已上架 ${data.count || 0} 個專題。`, 'success', 2000);
                loadProjects(); // 重新載入列表
            } else {
                await showAlertDialog('一併上架失敗：' + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error('一併上架錯誤:', error);
            await showAlertDialog('一併上架失敗，請稍後再試', 'error');
        } finally {
            if (batchPublishBtn) {
                batchPublishBtn.disabled = false;
                batchPublishBtn.innerHTML = originalText;
            }
        }
    }

    /**
     * 載入專題列表
     * @param {boolean} forceReload - 是否強制從伺服器重新載入（跳過緩存）
     */
    async function loadProjects(forceReload = false) {
        const searchInput = document.getElementById('searchInput');
        const cohortSelect = document.getElementById('cohortSelect');
        const groupSelect = document.getElementById('groupSelect');
        const statusSelect = document.getElementById('statusSelect');

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
            
            const response = await fetch(`${API_BASE}?do=get_list&${new URLSearchParams(params)}`);
            const data = await response.json();

            if (data.success) {
                currentProjects = data.data || [];
                renderProjects(currentProjects);
                updateProjectCount(currentProjects.length);
                
                // 檢查是否有已截止的專題，顯示「一併上架」按鈕
                checkAndShowBatchPublishButton(currentProjects);
            } else {
                await showAlertDialog('載入專題列表失敗：' + (data.message || '未知錯誤'), 'error');
            }
        } catch (error) {
            console.error('載入專題列表錯誤:', error);
            await showAlertDialog('載入專題列表失敗，請稍後再試', 'error');
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
        
        // 檢查是否有已截止且未上架的專題
        projects.forEach(project => {
            if (project.hp_upload_deadline) {
                const deadline = new Date(project.hp_upload_deadline);
                if (now >= deadline && project.hp_status == 0) {
                    hasExpiredProjects = true;
                    hasUnpublishedProjects = true;
                }
            }
        });
        
        // 如果有已截止且未上架的專題，顯示按鈕
        if (hasExpiredProjects && hasUnpublishedProjects) {
            batchPublishBtn.style.display = 'inline-block';
        } else {
            batchPublishBtn.style.display = 'none';
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
                    <td colspan="7" class="empty-state">
                        <i class="fa-solid fa-folder-open"></i>
                        <p>目前沒有專題資料</p>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = projects.map(project => {
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
            
            // 根據表格邏輯判斷狀態：
            // history_status = 1 → 顯示"已上架"（綠色）
            // history_status = 0 或未設置 → 顯示"通過"（黃色），不顯示"已上架"
            // 注意：因為查詢條件已經限定 prosub_status = 3（通過），所以所有專題都是通過狀態
            // hp_status 固定為 1（通過），不能用來判斷是否停用
            const historyStatus = contentJson.history_status;
            const isPublished = historyStatus === 1 || historyStatus === "1"; // 已上架（綠色）
            const isApproved = !isPublished; // 通過但未上架（黃色）- 所有專題都是通過的，只要不是已上架就是通過
            
            // 調試信息（開發時使用，生產環境可移除）
            if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
                console.log('專題:', project.hp_project_name, 'history_status:', historyStatus, 'hp_status:', project.hp_status, 'isPublished:', isPublished, 'isApproved:', isApproved);
            }
            
            // 根據狀態顯示對應的文字和樣式
            // 因為查詢條件已經限定 prosub_status = 3（通過），所以只有兩種狀態：
            // 1. 已上架（history_status = 1）
            // 2. 通過但未上架（history_status = 0 或未設置）
            let statusText, statusClass;
            if (isPublished) {
                statusText = '已上架';
                statusClass = 'status-published'; // 綠色
            } else {
                statusText = '通過';
                statusClass = 'status-approved'; // 黃色
            }
            
            // 組員姓名（用逗號分隔）
            const members = project.team_members || [];
            const memberNames = members.map(m => escapeHtml(m.u_name || '')).join('、') || '無組員';
            
            // 簡介摘要（只顯示前50個字）
            const intro = project.hp_intro || '';
            const introPreview = intro.length > 50 ? intro.substring(0, 50) + '...' : intro;

            return `
                <tr>
                    <td style="text-align: center;">
                        <input type="checkbox" class="project-checkbox" value="${project.hp_ID}" data-status="${project.hp_status}">
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
                        <span>${escapeHtml(project.hp_project_name || '')}</span>
                    </td>
                    <td style="font-size: 14px;">${memberNames}</td>
                    <td style="font-size: 14px; max-width: 200px;">${escapeHtml(introPreview || '無簡介')}</td>
                    <td>
                        <span class="status-badge ${statusClass}">${statusText}</span>
                    </td>
                    <td class="action-cell">
                        <button class="btn btn-sm btn-info" onclick="viewProjectDetail(${project.hp_ID})" title="查看詳情">
                            <i class="fa-solid fa-eye"></i> 查看
                        </button>
                        <button class="btn btn-sm btn-secondary" 
                                onclick="toggleStatus(${project.hp_ID}, ${project.hp_status == 1 ? 0 : 1})" 
                                title="${project.hp_status == 1 ? '停用' : '啟用'}">
                            <i class="fa-solid fa-toggle-${project.hp_status == 1 ? 'on' : 'off'}"></i> 
                            ${project.hp_status == 1 ? '停用' : '啟用'}
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
        
        // 綁定全選 checkbox 和更新按鈕狀態
        updateSelectAllCheckbox();
        updateBatchPublishButton();
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
     * 清除篩選條件
     */
    function clearFilters() {
        const searchInput = document.getElementById('searchInput');
        const cohortSelect = document.getElementById('cohortSelect');
        const groupSelect = document.getElementById('groupSelect');
        const statusSelect = document.getElementById('statusSelect');

        if (searchInput) searchInput.value = '';
        if (cohortSelect) cohortSelect.value = '';
        if (groupSelect) groupSelect.value = '';
        if (statusSelect) statusSelect.value = '';

        loadProjects();
    }

    /**
     * 設定上傳期限（統一期限）
     */
    window.setDeadline = function(hp_ID) {
        showDeadlineModal(hp_ID).then(deadline => {
            if (!deadline) return;

            // 直接調用 API
            const formData = new FormData();
            formData.append('hp_ID', hp_ID);
            formData.append('deadline', deadline);

            fetch(`${API_BASE}?do=set_deadline`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlertDialog('統一上傳期限設定成功！\n此期限將套用到該學年度所有團隊。', 'success', 2000);
                    loadProjects();
                    loadDeadlineList(); // 重新載入時段列表
                } else {
                    showAlertDialog('設定失敗：' + (data.message || '未知錯誤'), 'error');
                }
            })
            .catch(error => {
                console.error('設定期限錯誤:', error);
                showAlertDialog('設定失敗，請稍後再試', 'error');
            });
        });
    };

    /**
     * 切換鎖定狀態
     */
    window.toggleLock = async function(hp_ID, newLockStatus) {
        const action = newLockStatus == 1 ? '鎖定' : '解鎖';
        const confirmed = await showConfirmDialog(`確定要${action}此專題的上傳功能嗎？`, '確認操作');
        if (!confirmed) return;

        if (window.HistoryAPI && window.HistoryAPI.lockProject) {
            window.HistoryAPI.lockProject(hp_ID, newLockStatus == 1)
                .then(data => {
                    if (data.success) {
                        showAlertDialog(`${action}成功！`, 'success', 2000);
                        loadProjects();
                    } else {
                        showAlertDialog(`${action}失敗：` + (data.message || '未知錯誤'), 'error');
                    }
                })
                .catch(error => {
                    console.error(`${action}錯誤:`, error);
                    showAlertDialog(`${action}失敗，請稍後再試`, 'error');
                });
        } else {
            // 直接調用 API
            const formData = new FormData();
            formData.append('hp_ID', hp_ID);
            formData.append('is_locked', newLockStatus);

            fetch(`${API_BASE}?do=lock`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlertDialog(`${action}成功！`, 'success', 2000);
                    loadProjects();
                    loadDeadlineList(); // 重新載入時段列表
                } else {
                    showAlertDialog(`${action}失敗：` + (data.message || '未知錯誤'), 'error');
                }
            })
            .catch(error => {
                console.error(`${action}錯誤:`, error);
                showAlertDialog(`${action}失敗，請稍後再試`, 'error');
            });
        }
    };

    /**
     * 切換專題狀態
     */
    window.toggleStatus = async function(hp_ID, newStatus) {
        const action = newStatus == 1 ? '啟用' : '停用';
        const confirmed = await showConfirmDialog(`確定要${action}此專題嗎？`, '確認操作');
        if (!confirmed) return;

        if (window.HistoryAPI && window.HistoryAPI.updateStatus) {
            window.HistoryAPI.updateStatus(hp_ID, newStatus)
                .then(data => {
                    if (data.success) {
                        showAlertDialog(`${action}成功！`, 'success', 2000);
                        loadProjects();
                    } else {
                        showAlertDialog(`${action}失敗：` + (data.message || '未知錯誤'), 'error');
                    }
                })
                .catch(error => {
                    console.error(`${action}錯誤:`, error);
                    showAlertDialog(`${action}失敗，請稍後再試`, 'error');
                });
        } else {
            // 直接調用 API
            const formData = new FormData();
            formData.append('hp_ID', hp_ID);
            formData.append('status', newStatus);

            fetch(`${API_BASE}?do=update_status`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlertDialog(`${action}成功！`, 'success', 2000);
                    loadProjects();
                    loadDeadlineList(); // 重新載入時段列表
                } else {
                    showAlertDialog(`${action}失敗：` + (data.message || '未知錯誤'), 'error');
                }
            })
            .catch(error => {
                console.error(`${action}錯誤:`, error);
                showAlertDialog(`${action}失敗，請稍後再試`, 'error');
            });
        }
    };

    /**
     * 轉義 HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * 查看專題詳情
     */
    window.viewProjectDetail = async function(hp_ID) {
        if (!hp_ID) return;
        
        // 從 currentProjects 中找到對應的專題
        const project = currentProjects.find(p => p.hp_ID == hp_ID);
        if (!project) {
            await showAlertDialog('找不到專題資料', 'error');
            return;
        }
        
        const posterPath = project.hp_poster ? `../${project.hp_poster}` : '';
        const isPDF = posterPath.toLowerCase().endsWith('.pdf');
        const members = project.team_members || [];
        const memberNames = members.map(m => m.u_name || '').join('、') || '無組員';
        const teachers = project.team_teachers || [];
        const teacherNames = teachers.map(t => t.u_name || '').join('、') || '';
        const intro = project.hp_intro || '無簡介';
        // 根據表格邏輯判斷狀態：
        // history_status = 1 → 顯示"已上架"（綠色）
        // history_status = 0 或未設置 → 顯示"通過"（黃色），不顯示"已上架"
        // 注意：因為查詢條件已經限定 prosub_status = 3（通過），所以所有專題都是通過狀態
        const contentJson = typeof project.content_json === 'string' ? JSON.parse(project.content_json) : (project.content_json || {});
        const historyStatus = contentJson.history_status;
        const isPublished = historyStatus === 1 || historyStatus === "1"; // 已上架（綠色）
        
        let statusText, statusClass;
        if (isPublished) {
            statusText = '已上架';
            statusClass = 'status-published'; // 綠色
        } else {
            statusText = '通過';
            statusClass = 'status-approved'; // 黃色
        }
        const lockedText = project.hp_is_locked == 1 ? '已鎖定' : '未鎖定';
        const deadline = project.hp_upload_deadline 
            ? new Date(project.hp_upload_deadline).toLocaleString('zh-TW')
            : '未設定';
        
        // 創建模態框
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center;';
        
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1054;';
        
        modal.innerHTML = `
            <div class="modal-dialog modal-lg" style="max-width: 800px;">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                        <h5 class="modal-title">
                            <i class="fa-solid fa-info-circle me-2"></i>專題詳情
                        </h5>
                        <button type="button" class="btn-close btn-close-white" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="max-height: 70vh; overflow-y: auto; padding: 20px;">
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
                            <div class="col-md-8">
                                ${posterPath ? `
                                    ${isPDF ? `
                                        <div style="width: 100%; height: 500px;">
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
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
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
            }, 150);
        }
        
        const closeBtn = modal.querySelector('.btn-close');
        const closeFooterBtn = modal.querySelector('.modal-footer .btn-secondary');
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

    // 匯出初始化函數
    window.initHistoryProject = init;

    // 如果頁面已載入，立即初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

