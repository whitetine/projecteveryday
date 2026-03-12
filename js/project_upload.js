/**
 * 專題上傳 - 學生端
 * 對應頁面：pages/project_upload.php
 */

(function() {
    'use strict';

    // API 路徑需能在 /main.php 與 /pages/* 兩種入口下使用
    const API_BASE = (function() {
        const path = window.location.pathname || '';
        // 若目前網址已在 /pages/ 底下，API 需回到上一層
        if (path.includes('/pages/')) {
            return '../pages/upload_api.php';
        }
        // 其他情況使用原本相對根目錄的路徑
        return 'pages/upload_api.php';
    })();
    let zoomLevel = 100;
    let currentFile = null;
    let fileInputElement = null;
    let hasFileSelected = false; // 全局標記，是否有文件已選擇
    let selectedOtherFiles = []; // 新選擇的多個檔案
    let existingOtherFiles = []; // 已存在的檔案列表（從資料庫載入）
    let deletedExistingKeys = new Set(); // 🔹 記錄被刪除的舊檔案唯一key（編輯模式專用）
    let isSaving = false; // 暫存狀態標記
    let isSubmitting = false; // 提交狀態標記
    let justSubmitted = false; // 🔹 標記是否剛剛提交成功，防止重新載入草稿
    let shouldClearAllFiles = false; // 🔹 【修復清除全部】標記是否要清空所有檔案（編輯模式專用）
    
    // 🔹 【修復重複聲明】uploadedFilesList 只宣告一次，全局使用
    let uploadedFilesList = null;

    const FILE_TYPE_LABELS = {
        report: '成果書',
        ppt: 'PPT',
        word: 'Word',
        poster: '海報',
        other: '其他'
    };

    function configureFileTypeSelect() {
        const select = document.getElementById('fileTypeSelect');
        if (!select) return;

        const allowedTypes = getAllowedFileTypes();
        Array.from(select.options).forEach(opt => {
            if (!allowedTypes.includes(opt.value)) {
                opt.remove();
            }
        });

        if (select.options.length === 0) {
            select.disabled = true;
        }
    }

    /** 多檔上傳區塊不顯示的類型（海報為單獨上傳區、其他不再提供） */
    const FILE_TYPE_DROPDOWN_EXCLUDE = ['poster', 'other'];

    /**
     * 歷屆專題改為依副檔名自動判斷檔案類型，
     * 這裡回傳空陣列代表「不限制」，前端下拉選單會顯示通用選項。
     */
    function getAllowedFileTypes() {
        return [];
    }
    
    // 🔹 【修復重複聲明】創建輔助函數獲取 URL 參數，避免重複聲明
    function getUrlParams() {
        return new URLSearchParams(window.location.search);
    }
    
    // 繳交時段狀態管理（防止舊回應覆寫新狀態）
    let periodLoading = false;
    let periodError = null;
    let periodData = null;
    let periodAbortController = null; // AbortController 用於取消舊請求
    let periodRequestId = 0; // 請求 ID，用於比對是否為最新請求

    /**
     * 🔹 確保文件輸入框可用（修復 ReferenceError）
     * @param {string|HTMLElement} inputOrId - input 元素或 id
     * @returns {boolean} - 返回 true 表示成功，false 表示失敗
     */
    function ensureFileInputEnabled(inputOrId) {
        const input = typeof inputOrId === 'string'
            ? document.getElementById(inputOrId)
            : inputOrId;

        if (!input) {
            console.warn('[ensureFileInputEnabled] 找不到 input:', inputOrId);
            return false;
        }

        input.disabled = false;
        input.removeAttribute('disabled');
        input.style.pointerEvents = 'auto';
        input.style.setProperty('pointer-events', 'auto', 'important');

        // 有些情況是被外層蓋住，至少先讓 input/label 可點
        if (input.id) {
            const label = document.querySelector(`label[for="${input.id}"]`);
            if (label) {
                label.style.pointerEvents = 'auto';
                label.style.setProperty('pointer-events', 'auto', 'important');
                label.style.cursor = 'pointer';
                label.removeAttribute('disabled');
            }
        }

        return true;
    }

    /**
     * 自定義確認對話框（替換 confirm）
     * @param {string} message - 確認訊息
     * @param {string} title - 標題（預設「確認」）
     * @param {string} confirmText - 確認按鈕文字（預設「確定」）
     * @param {string} cancelText - 取消按鈕文字（預設「取消」）
     * @returns {Promise<boolean>} - 返回 true 表示確認，false 表示取消
     */
    // 🔹 【修復】全局標記，防止重複顯示確認對話框
    let isConfirmDialogShowing = false;
    
    function showConfirmDialog(message, title = '確認', confirmText = '確定', cancelText = '取消') {
        return new Promise((resolve) => {
            // 🔹 【修復】防止重複顯示：使用全局標記和 DOM 檢查
            if (isConfirmDialogShowing) {
                console.warn('[showConfirmDialog] 確認對話框已經在顯示中，跳過重複顯示');
                resolve(false);
                return;
            }
            
            const existingModal = document.getElementById('customConfirmModal');
            if (existingModal && document.body.contains(existingModal)) {
                // 如果 DOM 中還有舊的對話框，先移除它
                try {
                    existingModal.remove();
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) backdrop.remove();
                } catch (e) {
                    console.warn('[showConfirmDialog] 移除舊對話框失敗:', e);
                }
            }
            
            // 🔹 設置標記
            isConfirmDialogShowing = true;
            
            // 創建模態框
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'customConfirmModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'customConfirmModalLabel');
            modal.setAttribute('aria-hidden', 'false'); // 顯示時設為 false，避免無障礙性警告
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center;';
            
            // 創建背景遮罩
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: transparent !important; z-index: 1054;';
            
            // 版型比照圖 2：置中白色卡片、大型問號圖示、下方兩個按鈕
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="border-radius: 8px; border: none; box-shadow: 0 12px 30px rgba(0,0,0,0.18); padding: 28px 36px 24px; text-align: center; max-width: 380px; margin: 0 auto;">
                        <div class="modal-body" style="padding: 0 0 10px 0;">
                            <div style="width: 64px; height: 64px; border-radius: 50%; border: 4px solid #9CA3AF; margin: 0 auto 18px; display: flex; align-items: center; justify-content: center;">
                                <span style="font-size: 36px; line-height: 1; color: #9CA3AF;">?</span>
                            </div>
                            <h5 id="customConfirmModalLabel" style="font-weight: 700; font-size: 22px; margin-bottom: 10px; color: #374151;">
                                ${escapeHtml(title)}
                            </h5>
                            <p style="margin: 0; font-size: 15px; color: #4B5563; white-space: pre-line;">
                                ${escapeHtml(message)}
                            </p>
                        </div>
                        <div class="modal-footer" style="border-top: none; padding: 20px 0 0; display: flex; justify-content: center; gap: 10px;">
                            <button type="button" class="btn btn-secondary cancel-btn"
                                style="border-radius: 4px; padding: 8px 24px; font-weight: 600; min-width: 104px; background-color: #6B7280; border: none; color: #FFFFFF;">
                                ${escapeHtml(cancelText)}
                            </button>
                            <button type="button" class="btn btn-primary confirm-btn"
                                style="border-radius: 4px; padding: 8px 24px; font-weight: 600; min-width: 104px; background: #6366F1; border: none; color: #FFFFFF;">
                                ${escapeHtml(confirmText)}
                            </button>
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
            function closeModal() {
                // 🔹 【修復】清除標記
                isConfirmDialogShowing = false;
                
                // 關閉時設置 aria-hidden="true"（雖然會被移除，但為了完整性）
                modal.setAttribute('aria-hidden', 'true');
                backdrop.style.opacity = '0';
                modal.style.opacity = '0';
                
                setTimeout(() => {
                    if (document.body.contains(modal)) {
                        modal.remove();
                    }
                    if (document.body.contains(backdrop)) {
                        backdrop.remove();
                    }
                    document.body.style.overflow = '';
                }, 300);
            }
            
            // 確認按鈕事件
            const confirmBtn = modal.querySelector('.confirm-btn');
            confirmBtn.onclick = function() {
                isConfirmDialogShowing = false; // 🔹 清除標記
                closeModal();
                resolve(true);
            };
            
            // 取消按鈕事件
            const cancelBtn = modal.querySelector('.cancel-btn');
            cancelBtn.onclick = function() {
                isConfirmDialogShowing = false; // 🔹 清除標記
                closeModal();
                resolve(false);
            };
            
            // 點擊背景關閉
            backdrop.onclick = function() {
                isConfirmDialogShowing = false; // 🔹 清除標記
                closeModal();
                resolve(false);
            };
            
            // ESC 鍵關閉
            const escHandler = function(e) {
                if (e.key === 'Escape') {
                    isConfirmDialogShowing = false; // 🔹 清除標記
                    closeModal();
                    resolve(false);
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
            
            // 關閉模態框
            function closeModal() {
                // 關閉時設置 aria-hidden="true"（雖然會被移除，但為了完整性）
                modal.setAttribute('aria-hidden', 'true');
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
                }, 150);
            }
            
            // 聚焦確認按鈕（在設置 aria-hidden="false" 之後）
            setTimeout(() => {
                confirmBtn.focus();
            }, 100);
        });
    }

    /**
     * 轉義 HTML 字符
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * 自定義提示框（替換 alert），樣式對齊圖2：置中白卡、圓形圖示、單一確定鈕；可選自動關閉
     * @param {string} message - 提示訊息
     * @param {string} type - 類型：'success', 'error', 'info', 'warning'（默認 'info'）
     * @param {number} autoClose - 自動關閉時間（毫秒），0 表示不自動關閉（默認 0）
     * @returns {Promise<void>}
     */
    function showAlertDialog(message, type = 'info', autoClose = 0) {
        return new Promise((resolve) => {
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.id = 'customAlertModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'customAlertModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center; opacity: 1;';
            
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop show';
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: transparent !important; z-index: 1054; opacity: 0; transition: opacity 0.15s ease-out;';
            
            // 圖2風格：圓形圖示（青/灰邊）、標題、內文、單一確定鈕
            const iconConfig = {
                'success': { border: '#10B981', icon: 'fa-check', iconColor: '#10B981' },
                'error': { border: '#EF4444', icon: 'fa-times', iconColor: '#EF4444' },
                'warning': { border: '#F59E0B', icon: 'fa-exclamation', iconColor: '#F59E0B' },
                'info': { border: '#0EA5E9', icon: 'fa-info', iconColor: '#0EA5E9' }
            };
            const ico = iconConfig[type] || iconConfig['info'];
            
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="border-radius: 12px; border: none; box-shadow: 0 12px 30px rgba(0,0,0,0.18); padding: 28px 36px 24px; text-align: center; max-width: 380px; margin: 0 auto;">
                        <div class="modal-body" style="padding: 0 0 10px 0;">
                            <div style="width: 64px; height: 64px; border-radius: 50%; border: 4px solid ${ico.border}; margin: 0 auto 18px; display: flex; align-items: center; justify-content: center;">
                                <i class="fa-solid ${ico.icon}" style="font-size: 28px; color: ${ico.iconColor};"></i>
                            </div>
                            <h5 id="customAlertModalLabel" style="font-weight: 700; font-size: 22px; margin-bottom: 10px; color: #374151;">提示</h5>
                            <p style="margin: 0; font-size: 15px; color: #4B5563; line-height: 1.6; white-space: pre-line;">${escapeHtml(message).replace(/\n/g, '<br>')}</p>
                        </div>
                        <div class="modal-footer" style="border-top: none; padding: 20px 0 0; display: flex; justify-content: center;">
                            <button type="button" class="btn btn-primary confirm-btn" style="border-radius: 4px; padding: 8px 24px; font-weight: 600; min-width: 104px; background: #6366F1; border: none; color: #FFFFFF;">確定</button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(backdrop);
            document.body.appendChild(modal);
            document.body.style.overflow = 'hidden';
            // 先顯示提示框，再淡入遮罩，避免整片黑閃一下
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    backdrop.style.opacity = '0.5';
                });
            });
            
            function closeModal() {
                backdrop.style.opacity = '0';
                modal.style.opacity = '0';
                setTimeout(() => {
                    if (document.body.contains(modal)) modal.remove();
                    if (document.body.contains(backdrop)) backdrop.remove();
                    document.body.style.overflow = '';
                    resolve();
                }, 200);
            }
            
            const confirmBtn = modal.querySelector('.confirm-btn');
            confirmBtn.onclick = closeModal;
            backdrop.onclick = closeModal;
            
            const escHandler = function(e) {
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
     * 清理繳交時段相關狀態（teardown）
     */
    function cleanupDeadlineList() {
        // 取消正在進行的請求
        if (periodAbortController) {
            periodAbortController.abort();
            periodAbortController = null;
        }
        
        // 重置狀態
        periodLoading = false;
        periodError = null;
        periodData = null;
        periodRequestId = 0;
    }
    
    /**
     * 載入繳交時段（loadSchedule）
     * 每次頁面載入時都必須調用，不依賴 DOMContentLoaded 或 inited 標誌
     * 使用 AbortController 防止舊請求覆寫新狀態
     *  如果 PHP 端已經渲染了內容，就不再重新渲染，避免狀態閃爍
     */
    async function loadSchedule() {
        const deadlineList = document.getElementById('deadlineList');
        if (!deadlineList) return;
        
        //  檢查是否已經有內容（由 PHP 端渲染的），如果有就不重新渲染
        const hasContent = deadlineList.querySelector('.list-group-item') !== null;
        if (hasContent) {
            // PHP 端已經渲染了內容，不需要 JavaScript 再次渲染，避免狀態閃爍
            return;
        }
        
        // 取消舊請求（如果存在）
        if (periodAbortController) {
            periodAbortController.abort();
        }
        
        // 創建新的 AbortController
        periodAbortController = new AbortController();
        
        // 生成新的請求 ID
        const currentRequestId = ++periodRequestId;
        
        // 重置狀態
        periodLoading = false;
        periodError = null;
        periodData = null;
        
        try {
            // 優先從 PROJECT_UPLOAD_CONFIG 讀取（如果存在且可用）
            if (typeof window.PROJECT_UPLOAD_CONFIG !== 'undefined') {
                const config = window.PROJECT_UPLOAD_CONFIG;
                const unifiedDeadline = config?.unifiedDeadline;
                
                if (unifiedDeadline && unifiedDeadline.deadline) {
                    // 檢查是否為最新請求
                    if (currentRequestId === periodRequestId && !periodAbortController.signal.aborted) {
                        periodData = unifiedDeadline;
                        loadDeadlineListContent(unifiedDeadline, currentRequestId);
                        return; // 成功顯示數據，直接返回
                    }
                }
            }
            
            //  如果配置不可用或沒有數據，顯示無數據狀態（不是錯誤狀態）
            if (currentRequestId === periodRequestId && !periodAbortController.signal.aborted) {
                deadlineList.innerHTML = `
                    <div class="text-center p-3 text-muted">
                        <i class="fa-solid fa-calendar-xmark"></i>
                        <p class="mb-0 mt-2">目前沒有繳交時段</p>
                        <small class="d-block mt-2">科辦會設定統一的上傳期限</small>
                    </div>
                `;
            }
        } catch (err) {
            //  只有真正的錯誤才顯示錯誤提示（例如數據解析錯誤、渲染錯誤等）
            console.error('載入繳交時段時發生錯誤:', err);
            const deadlineList = document.getElementById('deadlineList');
            if (deadlineList && currentRequestId === periodRequestId && !periodAbortController.signal.aborted) {
                deadlineList.innerHTML = `
                    <div class="text-center p-3 text-muted">
                        <i class="fa-solid fa-exclamation-triangle text-warning"></i>
                        <p class="mb-0 mt-2 text-danger">繳交時段載入失敗</p>
                        <small class="d-block mt-2">請重新整理頁面或稍後再試</small>
                    </div>
                `;
            }
        }
    }
    
    // 保留舊函數名作為別名（向後兼容）
    const loadDeadlineList = loadSchedule;
    
    function loadDeadlineListContent(unifiedDeadline, requestId) {
        const deadlineList = document.getElementById('deadlineList');
        if (!deadlineList) return;
        
        // 檢查是否為最新請求，如果不是則直接返回（防止舊回應覆寫新狀態）
        if (requestId && requestId !== periodRequestId) {
            return;
        }
        
        const startTime = unifiedDeadline.start_time ? new Date(unifiedDeadline.start_time) : null;
        const deadline = new Date(unifiedDeadline.deadline);
        const now = new Date();
        
        let statusClass = 'secondary';
        let statusText = '未開始';
        
        // 判斷時段狀態
        if (startTime && now < startTime) {
            statusClass = 'secondary';
            statusText = '未開始';
        } else if (now >= deadline) {
            statusClass = 'secondary';
            statusText = '已結束';
        } else {
            statusClass = 'success';
            statusText = '進行中';
        }
        
        const formatDate = (dateStr) => {
            if (!dateStr) return '';
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
        
        const displayName = unifiedDeadline.pro_title || 
                            (unifiedDeadline.cohort_name ? `${unifiedDeadline.cohort_name} 歷屆專題` : '歷屆專題');
        
        deadlineList.innerHTML = `
            <div class="list-group list-group-flush">
                <div class="list-group-item deadline-item active" style="cursor: pointer;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <h6 class="mb-1" style="font-weight: 600;">${escapeHtml(displayName)}</h6>
                            ${startTime ? `
                                <small class="text-muted d-block">
                                    <i class="fa-solid fa-calendar-check me-1"></i>開放時間：${formatDate(unifiedDeadline.start_time)}
                                </small>
                            ` : ''}
                            <small class="text-muted d-block ${startTime ? 'mt-1' : ''}">
                                <i class="fa-solid fa-calendar-times me-1"></i>截止時間：${formatDate(unifiedDeadline.deadline)}
                            </small>
                            ${unifiedDeadline.group_name ? `<small class="text-muted d-block mt-1"><i class="fa-solid fa-users me-1"></i>${escapeHtml(unifiedDeadline.group_name)}</small>` : ''}
                        </div>
                        <span class="badge bg-${statusClass} ms-2">${statusText}</span>
                    </div>
                </div>
            </div>
        `;
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

    // 事件監聽器初始化標誌（只綁一次）
    let eventListenersInitialized = false;
    
    /**
     * 初始化事件監聽器（只執行一次）
     */
    function initEventListeners() {
        if (eventListenersInitialized) {
            return; // 已經初始化過，跳過
        }
        
        setupFileUpload();
        setupMultipleFilesUpload();
        setupFormActions(); // 🔹 確保表單操作按鈕的事件監聽器已設置
        
        // 🔹 確保多文件選擇功能已初始化
        if (typeof initOtherFiles === 'function') {
            initOtherFiles();
        }
        
        // 🔹 【修復刪除按鈕事件】設置事件委派（在容器上綁定一次，避免重新渲染後事件丟失）
        if (typeof setupFileDeleteDelegation === 'function') {
            setupFileDeleteDelegation();
        }
        
        // 🔹 【防呆】延遲確保暫存和提交按鈕可用
        setTimeout(() => {
            // 🔹 【修復編輯模式】編輯模式下不顯示暫存按鈕，因此不需要查找
            const urlParams = new URLSearchParams(window.location.search);
            const isEditMode = urlParams.has('edit');
            
            if (!isEditMode) {
                const saveDraftBtn = document.getElementById('saveDraftBtn');
                if (saveDraftBtn) {
                    saveDraftBtn.removeAttribute('disabled');
                    saveDraftBtn.disabled = false;
                    saveDraftBtn.style.setProperty('pointer-events', 'auto', 'important');
                    saveDraftBtn.style.setProperty('cursor', 'pointer', 'important');
                    console.log('[initEventListeners] 延遲檢查：暫存按鈕已啟用');
                }
            }
            
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.removeAttribute('disabled');
                submitBtn.disabled = false;
                submitBtn.style.setProperty('pointer-events', 'auto', 'important');
                submitBtn.style.setProperty('cursor', 'pointer', 'important');
                console.log('[initEventListeners] 延遲檢查：提交按鈕已啟用');
            }
        }, 100);
        
        eventListenersInitialized = true;
    }
    
    /**
     * 初始化頁面
     */
    /**
     * 獲取簡介 textarea 元素（統一函數）
     * @returns {HTMLElement|null} 簡介 textarea 元素，如果不存在則返回 null
     */
    function getIntroEl() {
        return document.getElementById('projectIntro') || document.querySelector('#projectIntro');
    }
    
    /**
     * 初始化函數（Persistence First - 資料持久化優先）
     * 流程：先調用 getDraft 獲取資料，取回資料後再渲染，不進行任何清空操作
     */
    function init() {
        console.log('[init] ========== 開始初始化 ==========');
        console.log('[init] 時間戳:', new Date().toISOString());
        
        // 🔹 【防重複旗標】#uploadedFilesList 存在且 data-rendered-by="php" 時，既有檔案已由 PHP 渲染，JS 不得再渲染到 otherFilesPanel
        const _ul = document.getElementById('uploadedFilesList');
        window.__OTHER_FILES_RENDERED_BY_PHP__ = !!(_ul && _ul.dataset.renderedBy === 'php');
        console.log('[init] __OTHER_FILES_RENDERED_BY_PHP__ =', window.__OTHER_FILES_RENDERED_BY_PHP__);
        
        // 🔹 【關鍵修復】在 init 開始時就要正確取得簡介 textarea 的 DOM
        const projectIntroElement = getIntroEl();
        if (!projectIntroElement) {
            console.warn('[init] ⚠️ 找不到簡介 textarea（#projectIntro），停止載入草稿，避免爆錯');
            // 即使找不到簡介元素，也要繼續執行其他初始化（不 return）
            // 但跳過草稿載入流程
        } else {
            console.log('[init] ✅ 找到簡介 textarea（#projectIntro）');
        }
        
        // 🔹 【修復重複聲明】初始化全局變數
        if (!uploadedFilesList) {
            uploadedFilesList = document.getElementById('uploadedFilesList');
            console.log('[init] 初始化 uploadedFilesList:', !!uploadedFilesList);
        }
        
        // 🔹 【修復編輯頁多檔案顯示】編輯模式下，立即從 PHP 輸出的 JSON 讀取並渲染
        const urlParams = new URLSearchParams(window.location.search);
        const isEditMode = urlParams.has('edit');
        
        if (isEditMode && !window.__OTHER_FILES_RENDERED_BY_PHP__) {
            // 讀取 PHP 輸出的 existing files（僅在「非 PHP 已渲染」時才同步並渲染到 otherFilesPanel）
            let existingFiles = window.__EXISTING_OTHER_FILES__;
            if (!Array.isArray(existingFiles)) {
                console.warn('[init] 編輯模式：window.__EXISTING_OTHER_FILES__ 不是陣列，轉換為空陣列');
                existingFiles = [];
            } else {
                console.log('[init] 編輯模式：從 PHP 讀取到', existingFiles.length, '個已存在的檔案');
            }
            
            // 🔹 立即同步到 existingOtherFiles（避免被後續邏輯清空）
            if (existingFiles.length > 0) {
                existingOtherFiles = existingFiles.map(file => {
                    // 確保格式統一
                    if (typeof file === 'string') {
                        return {
                            original_name: basename(file),
                            name: basename(file),
                            path: file,
                            type: '',
                            uploaded_at: '',
                            public: true,
                            file_type: ''
                        };
                    } else if (file && typeof file === 'object') {
                        return {
                            original_name: file.original_name || file.name || (file.path ? basename(file.path) : ''),
                            name: file.name || file.original_name || (file.path ? basename(file.path) : ''),
                            path: file.path || '',
                            type: file.type || '',
                            uploaded_at: file.uploaded_at || file.upload_time || '',
                            public: file.public !== undefined ? file.public : true,
                            file_type: file.file_type ?? ''
                        };
                    }
                    return null;
                }).filter(f => f !== null);
                
                console.log('[init] 編輯模式：已同步到 existingOtherFiles，數量:', existingOtherFiles.length);
                
                // 🔹 立即渲染（延遲一點確保 DOM 準備好）
                setTimeout(() => {
                    if (typeof renderSelectedFiles === 'function') {
                        renderSelectedFiles();
                        console.log('[init] 編輯模式：已調用 renderSelectedFiles() 顯示', existingOtherFiles.length, '個檔案');
                        
                        // 確保面板顯示
                        const panel = document.getElementById('otherFilesPanel');
                        const empty = document.getElementById('otherFilesEmpty');
                        if (panel && empty && existingOtherFiles.length > 0) {
                            panel.style.display = '';
                            empty.style.display = 'none';
                            console.log('[init] 編輯模式：已強制顯示文件列表面板');
                        }
                    }
                }, 50);
            } else {
                console.log('[init] 編輯模式：沒有已存在的檔案');
            }
        } else if (isEditMode && window.__OTHER_FILES_RENDERED_BY_PHP__) {
            // 🔹 既有檔案已由 PHP 渲染，仍須同步 existingOtherFiles 供暫存/提交/刪除使用（但不渲染到 otherFilesPanel）
            let existingFiles = window.__EXISTING_OTHER_FILES__;
            if (Array.isArray(existingFiles) && existingFiles.length > 0) {
                existingOtherFiles = existingFiles.map(file => {
                    if (typeof file === 'string') {
                        return { original_name: basename(file), name: basename(file), path: file, type: '', uploaded_at: '', public: true, file_type: '' };
                    }
                    if (file && typeof file === 'object') {
                        return {
                            original_name: file.original_name || file.name || (file.path ? basename(file.path) : ''),
                            name: file.name || file.original_name || (file.path ? basename(file.path) : ''),
                            path: file.path || '', type: file.type || '', uploaded_at: file.uploaded_at || file.upload_time || '',
                            public: file.public !== undefined ? file.public : true,
                            file_type: file.file_type ?? ''
                        };
                    }
                    return null;
                }).filter(f => f !== null);
                console.log('[init] 編輯模式（PHP已渲染）：已同步 existingOtherFiles 供暫存/刪除，數量:', existingOtherFiles.length, '，不渲染到 otherFilesPanel');
            }
        }

        // 根據科辦設定的 allow_file_types 限制學生可選的檔案類型
        configureFileTypeSelect();
        
        // 清理舊狀態（teardown）
        cleanupDeadlineList();
        
        // 初始化事件監聽器（只綁一次）
        initEventListeners();
        
        //  檢查 PHP 端是否已經渲染了繳交時段內容，如果有就不調用 loadSchedule（避免重複渲染）
        const deadlineList = document.getElementById('deadlineList');
        const hasPhpContent = deadlineList && deadlineList.querySelector('.list-group-item') !== null;
        if (!hasPhpContent) {
            // ⭐ 只有當 PHP 端沒有渲染內容時，才載入繳交時段（不依賴 inited 標誌）
            loadSchedule();
        }
        
        // 🔹 【關鍵修復】不保存或清空任何值，直接調用 getDraft 獲取資料
        // 延遲執行，確保 PHP 端已經完全渲染完成
        setTimeout(() => {
            // 🔹 檢查是否為編輯模式（已在上面聲明，這裡不需要重複）
            
            // 🔹 【重要】編輯模式下，如果 existingOtherFiles 已有資料（從 window.__EXISTING_OTHER_FILES__ 讀取），不再從DOM讀取
            // 🔹 【修復編輯頁多檔案顯示】優先使用 window.__EXISTING_OTHER_FILES__，避免重複讀取和覆蓋
            // 🔹 【防重複】若 __OTHER_FILES_RENDERED_BY_PHP__，既有檔案已由 PHP 渲染，不再渲染到 otherFilesPanel
            if (isEditMode && existingOtherFiles.length === 0 && uploadedFilesList && !window.__OTHER_FILES_RENDERED_BY_PHP__) {
                const phpRenderedItems = uploadedFilesList.querySelectorAll('.uploaded-file-item[data-file-info]');
                const renderedBy = uploadedFilesList.getAttribute('data-rendered-by');
                const shouldReadFromDOM = phpRenderedItems.length > 0 && (renderedBy === 'php' || !renderedBy);
                
                if (shouldReadFromDOM) {
                    console.log('[init] 編輯模式：檢測到PHP端已渲染的檔案，從DOM同步到existingOtherFiles');
                    // 🔹 編輯模式下，初始化時清空狀態
                    existingOtherFiles = [];
                    deletedExistingKeys.clear();
                    shouldClearAllFiles = false;
                    
                    // 從DOM讀取文件列表
                    phpRenderedItems.forEach(item => {
                        const fileInfoStr = item.getAttribute('data-file-info');
                        if (fileInfoStr) {
                            try {
                                const fileInfo = JSON.parse(fileInfoStr);
                                // 🔹 確保有 original_name 字段
                                if (!fileInfo.original_name) {
                                    fileInfo.original_name = fileInfo.name || (fileInfo.path ? basename(fileInfo.path) : '');
                                }
                                existingOtherFiles.push(fileInfo);
                            } catch (e) {
                                const filePath = item.getAttribute('data-file-path');
                                const fileName = item.querySelector('.file-name')?.textContent || '';
                                if (filePath) {
                                    existingOtherFiles.push({
                                        original_name: fileName,
                                        name: fileName,
                                        path: filePath,
                                        type: '',
                                        uploaded_at: '',
                                        public: true
                                    });
                                }
                            }
                        }
                    });
                    console.log('[init] 編輯模式：從DOM同步後，existingOtherFiles 數量:', existingOtherFiles.length);
                    
                    // 🔹 【關鍵修復】立即調用 renderSelectedFiles() 顯示文件列表
                    if (existingOtherFiles.length > 0 && typeof renderSelectedFiles === 'function') {
                        setTimeout(() => {
                            renderSelectedFiles();
                            console.log('[init] 編輯模式：已調用 renderSelectedFiles() 顯示', existingOtherFiles.length, '個檔案');
                            
                            // 確保面板顯示
                            const panel = document.getElementById('otherFilesPanel');
                            const empty = document.getElementById('otherFilesEmpty');
                            if (panel && empty) {
                                panel.style.display = '';
                                empty.style.display = 'none';
                            }
                        }, 100);
                    }
                } else {
                    // 🔹 PHP端沒有渲染文件（數據庫為空），確保前端狀態也是空的
                    console.log('[init] 編輯模式：PHP端沒有渲染文件（數據庫為空），確保前端狀態為空');
                    existingOtherFiles = [];
                    deletedExistingKeys.clear();
                    shouldClearAllFiles = false;
                }
            } else if (!isEditMode && uploadedFilesList) {
                // 🔹 非編輯模式：只在 existingOtherFiles 為空時才從DOM讀取
                const phpRenderedItems = uploadedFilesList.querySelectorAll('.uploaded-file-item[data-file-info]');
                const renderedBy = uploadedFilesList.getAttribute('data-rendered-by');
                const shouldReadFromDOM = phpRenderedItems.length > 0 && (renderedBy === 'php' || !renderedBy) && existingOtherFiles.length === 0;
                
                if (shouldReadFromDOM) {
                    console.log('[init] 非編輯模式：檢測到PHP端已渲染的檔案，從DOM同步到existingOtherFiles');
                    phpRenderedItems.forEach(item => {
                        const fileInfoStr = item.getAttribute('data-file-info');
                        if (fileInfoStr) {
                            try {
                                const fileInfo = JSON.parse(fileInfoStr);
                                existingOtherFiles.push(fileInfo);
                            } catch (e) {
                                const filePath = item.getAttribute('data-file-path');
                                const fileName = item.querySelector('.file-name')?.textContent || '';
                                if (filePath) {
                                    existingOtherFiles.push({
                                        name: fileName,
                                        path: filePath
                                    });
                                }
                            }
                        }
                    });
                    console.log('[init] 非編輯模式：從DOM同步後，existingOtherFiles 數量:', existingOtherFiles.length);
                }
            }
            
            // 🔹 【關鍵修復】如果找不到簡介元素，跳過草稿載入流程
            if (!projectIntroElement) {
                console.warn('[init] ⚠️ 找不到簡介 textarea，跳過草稿載入流程');
                initializeUIAfterDraftLoad();
                return;
            }
            
            // 🔹 檢查是否為編輯模式或已提交唯讀狀態
            const urlParamsForDraft = new URLSearchParams(window.location.search);
            const isEditModeForDraft = urlParamsForDraft.has('edit');
            const isSubmittedReadonly = window.PROJECT_UPLOAD_CONFIG?.isSubmittedReadonly === true;
            
            if (isEditModeForDraft) {
                // 🔹 編輯模式：不載入草稿，直接執行初始化
                console.log('[init] 編輯模式：跳過載入草稿，直接執行初始化');
                initializeUIAfterDraftLoad();
            } else if (isSubmittedReadonly) {
                // 🔹 已提交唯讀狀態：不載入草稿，保留PHP端渲染的內容，直接執行初始化
                console.log('[init] 已提交唯讀狀態：跳過載入草稿，保留PHP端渲染的內容');
                // 🔹 確保簡介內容不被清空
                if (projectIntroElement) {
                    const initialValue = projectIntroElement.getAttribute('data-initial-value') || projectIntroElement.value || '';
                    if (initialValue.trim() && !projectIntroElement.value.trim()) {
                        projectIntroElement.value = initialValue;
                        console.log('[init] 已提交狀態：從 data-initial-value 恢復簡介內容');
                    }
                    // 延遲僅在簡介被清空時恢復，不覆寫使用者輸入（避免貼上時閃爍）
                    function restoreIfCleared() {
                        if (projectIntroElement && !projectIntroElement.value.trim() && initialValue.trim()) {
                            projectIntroElement.value = initialValue;
                        }
                    }
                    setTimeout(restoreIfCleared, 200);
                    setTimeout(restoreIfCleared, 1000);
                }
                initializeUIAfterDraftLoad();
            } else {
                // 非編輯模式：載入草稿
            loadDraft().then(() => {
                console.log('[init] ✅ 暫存資料載入完成，所有欄位已回填');
                
                // 草稿載入完成後，才執行其他初始化
                initializeUIAfterDraftLoad();
            }).catch(error => {
                console.error('[init] 載入草稿時發生錯誤:', error);
                // 即使載入失敗，也要執行初始化
                initializeUIAfterDraftLoad();
            });
            }
        }, 300); // 增加延遲時間，確保 PHP 端完全渲染
    }
    
    /**
     * 在草稿載入後執行的 UI 初始化
     */
    function initializeUIAfterDraftLoad() {
        // 🔹 檢查並顯示暫存狀態
        checkAndShowDraftStatus();
        
        // 🔹 設置表單內容變更監聽器（用戶修改時隱藏提示）
        setupDraftStatusChangeListener();
        // 🔹 已提交狀態下，保護簡介內容不被清空
        const isSubmittedReadonly = window.PROJECT_UPLOAD_CONFIG?.isSubmittedReadonly === true;
        if (isSubmittedReadonly) {
            const projectIntroElement = getIntroEl();
            if (projectIntroElement) {
                const initialValue = projectIntroElement.getAttribute('data-initial-value') || projectIntroElement.value || '';
                if (initialValue.trim()) {
                    // 確保簡介內容存在
                    if (!projectIntroElement.value.trim()) {
                        projectIntroElement.value = initialValue;
                        console.log('[initializeUIAfterDraftLoad] 已提交狀態：恢復簡介內容');
                    }
                    // 僅在簡介被清空時恢復，不覆寫使用者輸入（避免貼上時閃爍）
                    const protectIntro = setInterval(() => {
                        if (projectIntroElement && !projectIntroElement.value.trim() && initialValue.trim()) {
                            projectIntroElement.value = initialValue;
                        }
                    }, 500);
                    setTimeout(() => clearInterval(protectIntro), 5000);
                }
            }
        }
        
        // 頁面刷新時重置文件顯示文字（但不清空已有草稿的顯示）
        function resetFileDisplayOnRefresh() {
            const fileInput = document.getElementById('posterFileInput');
            const noFileBtn = document.getElementById('noFileBtn');
            
            if (fileInput && noFileBtn) {
                // 確保 input 的 value 被清空（頁面刷新後瀏覽器會自動清空，但確保一下）
                // 注意：不操作 file input，因為瀏覽器安全限制
                fileInput.value = '';
                
                // 檢查是否在編輯模式或查看模式（有已存在的檔案）
                // 通過檢查 URL 參數和按鈕的 disabled 狀態來判斷
                const isEditMode = window.location.search.includes('edit=');
                const isViewMode = window.location.search.includes('view=');
                const isLocked = fileInput.hasAttribute('disabled');
                
                // 檢查是否已有草稿資料（從 existingOtherFiles 或 noFileBtn 的狀態判斷）
                const hasDraftData = existingOtherFiles.length > 0 || 
                                    (noFileBtn && noFileBtn.classList.contains('has-file'));
                
                // 如果不在編輯/查看模式，且沒有草稿資料，且按鈕未被鎖定，才重置顯示文字
                if (!isEditMode && !isViewMode && !isLocked && !hasDraftData) {
                    // 初始化時同步設置：固定文字和狀態，避免後續改動造成跳動
                    noFileBtn.textContent = '未選擇任何檔案';
                    noFileBtn.classList.remove('has-file');
                    noFileBtn.disabled = true;
                    // 禁止在初始化後再次改動 class/style 造成跳動
                    // 移除可能導致跳動的 transition
                    noFileBtn.style.transition = 'none';
                    currentFile = null;
                    hasFileSelected = false;
                }
            }
        }
        
        // 執行重置（但不會清空已有草稿的顯示）
        resetFileDisplayOnRefresh();
        
        // 🔹 將 ensureFileButtonEnabled 暴露到全局作用域
        window.ensureFileButtonEnabled = function() {
            // 🔹 處理海報選擇按鈕
            const selectFileBtn = document.getElementById('selectFileBtn');
            const fileInput = document.getElementById('posterFileInput');
            
            if (selectFileBtn && fileInput) {
            // 檢查按鈕是否在 HTML 中被明確禁用（由後端 PHP 設置，表示被鎖定）
            const isLockedInHTML = selectFileBtn.hasAttribute('disabled');
            
            // 如果沒有被後端鎖定，確保按鈕可以點擊
            if (!isLockedInHTML) {
                // 強制啟用按鈕
                selectFileBtn.removeAttribute('disabled');
                selectFileBtn.disabled = false;
                
                // 確保按鈕可以點擊（使用最高優先級）
                selectFileBtn.style.setProperty('pointer-events', 'auto', 'important');
                selectFileBtn.style.setProperty('cursor', 'pointer', 'important');
                selectFileBtn.style.setProperty('opacity', '1', 'important');
                selectFileBtn.style.setProperty('user-select', 'auto', 'important');
                selectFileBtn.style.setProperty('z-index', '10', 'important');
                selectFileBtn.style.setProperty('position', 'relative', 'important');
                
                // 確保文件輸入框可以正常使用
                const isInputLockedInHTML = fileInput.hasAttribute('disabled');
                if (!isInputLockedInHTML) {
                    fileInput.removeAttribute('disabled');
                    fileInput.disabled = false;
                    fileInput.style.setProperty('pointer-events', 'auto', 'important');
                }
                
                console.log('選擇檔案按鈕已啟用');
            } else {
                console.log('選擇檔案按鈕被後端鎖定，無法使用');
                }
            }
            
            // 🔹 處理多檔案選擇按鈕
            const selectMultipleFilesBtn = document.getElementById('selectMultipleFilesBtn');
            const multipleFilesInput = document.getElementById('multipleFilesInput');
            
            if (selectMultipleFilesBtn && multipleFilesInput) {
                // 檢查按鈕是否在 HTML 中被明確禁用（由後端 PHP 設置，表示被鎖定）
                const isLockedInHTML = selectMultipleFilesBtn.hasAttribute('disabled');
                
                // 如果沒有被後端鎖定，確保按鈕可以點擊
                if (!isLockedInHTML) {
                    // 強制啟用按鈕
                    selectMultipleFilesBtn.removeAttribute('disabled');
                    selectMultipleFilesBtn.disabled = false;
                    
                    // 確保按鈕可以點擊（使用最高優先級）
                    selectMultipleFilesBtn.style.setProperty('pointer-events', 'auto', 'important');
                    selectMultipleFilesBtn.style.setProperty('cursor', 'pointer', 'important');
                    selectMultipleFilesBtn.style.setProperty('opacity', '1', 'important');
                    selectMultipleFilesBtn.style.setProperty('user-select', 'auto', 'important');
                    selectMultipleFilesBtn.style.setProperty('z-index', '10', 'important');
                    selectMultipleFilesBtn.style.setProperty('position', 'relative', 'important');
                    
                    // 確保文件輸入框可以正常使用
                    const isInputLockedInHTML = multipleFilesInput.hasAttribute('disabled');
                    if (!isInputLockedInHTML) {
                        multipleFilesInput.removeAttribute('disabled');
                        multipleFilesInput.disabled = false;
                        multipleFilesInput.style.setProperty('pointer-events', 'auto', 'important');
                    }
                    
                    console.log('選擇多個檔案按鈕已啟用');
                } else {
                    console.log('選擇多個檔案按鈕被後端鎖定，無法使用');
                }
            }
        }
        
        // 立即執行
        ensureFileButtonEnabled();
        
        // 延遲執行，確保在頁面完全載入後再次檢查
        setTimeout(ensureFileButtonEnabled, 100);
        setTimeout(ensureFileButtonEnabled, 500);
        setTimeout(ensureFileButtonEnabled, 1000);
        
        // 🔹 確保專題簡介 textarea 可以正常輸入，並且保留暫存資料
        // 重要：禁止在頁面載入時清空 textarea，必須保留 PHP 端回填的值
        /**
         * 確保 textarea 可以輸入（不進行任何清空操作）
         * 🔹 【關鍵修復】移除所有清空行為，只設置樣式確保可以輸入
         * 資料應該由 loadDraft() 從資料庫載入，不依賴 DOM 的初始值
         * 🔹 【已提交唯讀】isSubmittedReadonly 時強制 readonly，不允許打字
         */
        function ensureTextareaInput() {
            const projectIntro = document.getElementById('projectIntro');
            if (!projectIntro) return;
            
            // 🔹 已提交唯讀：強制 readonly，連打字都不允許；不執行「移除 readonly」的邏輯
            if (window.PROJECT_UPLOAD_CONFIG?.isSubmittedReadonly === true) {
                projectIntro.readOnly = true;
                projectIntro.setAttribute('readonly', 'readonly');
                projectIntro.disabled = false;
                projectIntro.removeAttribute('disabled');
                projectIntro.style.setProperty('cursor', 'not-allowed', 'important');
                projectIntro.style.setProperty('background-color', '#f8f9fa', 'important');
                projectIntro.style.setProperty('pointer-events', 'auto', 'important');
                projectIntro.style.setProperty('user-select', 'text', 'important'); // 仍可選取複製
                projectIntro.style.setProperty('opacity', '1', 'important');
                console.log('[ensureTextareaInput] 已提交唯讀：已強制 readonly，不允許輸入');
                return;
            }
            
            // 🔹 【關鍵修復】不進行任何清空或恢復操作，只設置樣式確保可以輸入
            // 資料應該由 loadDraft() 從資料庫載入，不依賴 DOM 的初始值
            projectIntro.disabled = false;
            projectIntro.readOnly = false;
            projectIntro.removeAttribute('disabled');
            projectIntro.removeAttribute('readonly');
            projectIntro.style.setProperty('pointer-events', 'auto', 'important');
            projectIntro.style.setProperty('user-select', 'text', 'important');
            projectIntro.style.setProperty('cursor', 'text', 'important');
            projectIntro.style.setProperty('opacity', '1', 'important');
            projectIntro.style.setProperty('position', 'relative', 'important');
            projectIntro.style.setProperty('z-index', '1', 'important');
            projectIntro.style.setProperty('background-color', '#fff', 'important');
            projectIntro.setAttribute('tabindex', '0');
            projectIntro.onclick = null;
            projectIntro.onmousedown = null;
            projectIntro.onfocus = null;
            console.log('[ensureTextareaInput] 已設置樣式，不進行任何清空操作');
        }
        
        // 立即執行（只設置樣式，不清空）
        ensureTextareaInput();
        
        // 只在有預覽區域時才設置縮放控制和禁用縮放
        const previewArea = document.getElementById('previewArea');
        if (previewArea) {
            setupZoomControl();
            
            // 如果頁面載入時已有預覽內容，設置縮放
            const previewContent = document.getElementById('previewContent');
            if (previewContent) {
                updateZoom();
            }
            
            // 禁用預覽區域的滾輪縮放
            disablePreviewZoom();
        }
        
        setupFormActions();
        
        // 如果頁面載入時已有文件（編輯模式），更新文件狀態按鈕樣式
        // 在初始化時同步設置，避免後續改動造成跳動
        const noFileBtn = document.getElementById('noFileBtn');
        if (noFileBtn) {
            // 初始化時同步設置：固定高度和對齊，避免跳動
            // 不在此處修改 class 或 style，保持初始狀態
            // 只有在確實有文件時才添加 has-file class（在 resetFileDisplayOnRefresh 中已處理）
        }
    }
    
    /**
     * 禁用預覽區域的點擊和滾輪縮放
     */
    function disablePreviewZoom() {
        const previewArea = document.getElementById('previewArea');
        if (!previewArea) return;
        
        // 禁用圖片/PDF的點擊事件
        previewArea.addEventListener('click', function(e) {
            // 如果是點擊圖片或PDF內容，阻止預設行為
            if (e.target.tagName === 'IMG' || e.target.tagName === 'OBJECT') {
                e.stopPropagation();
                e.preventDefault();
            }
        }, true);
        
        // 禁用滾輪縮放
        previewArea.addEventListener('wheel', function(e) {
            // 如果按住Ctrl或Cmd鍵，阻止預設的縮放行為
            if (e.ctrlKey || e.metaKey) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, { passive: false });
        
        // 確保圖片和PDF對象不可選中
        const images = previewArea.querySelectorAll('img');
        images.forEach(img => {
            img.style.pointerEvents = 'none';
            img.style.userSelect = 'none';
            img.draggable = false;
        });
        
        const objects = previewArea.querySelectorAll('object');
        objects.forEach(obj => {
            obj.style.pointerEvents = 'none';
            obj.style.userSelect = 'none';
        });
    }

    /**
     * 設置多個檔案上傳
     */
    // 🔹 使用用戶提供的格式化函數
    function formatSize(bytes) {
        const mb = bytes / (1024 * 1024);
        return mb >= 1 ? `${mb.toFixed(2)} MB` : `${(bytes/1024).toFixed(1)} KB`;
    }
    
    function syncInputFiles(input, filesArr) {
        const dt = new DataTransfer();
        filesArr.forEach(f => dt.items.add(f));
        input.files = dt.files;
    }
    
    /**
     * 🔹 生成文件的唯一key（用於追蹤刪除）
     * 對於已存在的文件：使用 path 作為唯一key
     * 對於新選擇的文件：使用 name+size+lastModified
     */
    function getFileKey(file, isExisting = false) {
        if (isExisting) {
            // 已存在的文件：使用 path 作為唯一key
            return file.path || file.name || '';
        } else {
            // 新選擇的文件：使用 name+size+lastModified
            return `${file.name}_${file.size}_${file.lastModified || 0}`;
        }
    }
    
    /**
     * 🔹 獲取顯示用的文件列表（排除已刪除的舊文件）
     * 這是 Single Source of Truth
     */
    function getDisplayFileList() {
        // 過濾出未被刪除的舊文件
        const keptExistingFiles = existingOtherFiles.filter(file => {
            const key = getFileKey(file, true);
            return !deletedExistingKeys.has(key);
        });
        
        // 合併：保留的舊文件 + 新選擇的文件（新選擇為 { file, file_type }）
        return {
            existing: keptExistingFiles,
            new: selectedOtherFiles,
            all: [...keptExistingFiles, ...selectedOtherFiles]
        };
    }
    
    // 🔹 【修復刪除按鈕事件】使用事件委派，在容器上綁定一次，避免重新渲染後事件丟失
    function setupFileDeleteDelegation() {
        const listContainer = document.getElementById('otherFilesList');
        if (!listContainer) {
            console.warn('[setupFileDeleteDelegation] 找不到 otherFilesList 容器');
            return;
        }
        
        // 🔹 移除舊的事件監聽器（如果有的話），避免重複綁定
        if (listContainer._deleteHandler) {
            listContainer.removeEventListener('click', listContainer._deleteHandler);
            delete listContainer._deleteHandler;
        }
        
        // 🔹 事件委派：在容器上綁定一次 click 事件
        listContainer._deleteHandler = async function(e) {
            // 檢查是否點擊了刪除按鈕
            const deleteBtn = e.target.closest('button.remove-existing-file-btn, button.remove-new-file-btn');
            if (!deleteBtn) return;
            
            // 🔹 【修復】阻止預設行為和事件冒泡，但不要阻止事件傳播到容器
            e.stopPropagation(); // 只阻止冒泡，不阻止預設行為（因為按鈕已經是 type="button"）
            
            // 🔹 【修復】如果確認對話框正在顯示，不處理刪除操作
            if (isConfirmDialogShowing) {
                console.warn('[setupFileDeleteDelegation] 確認對話框正在顯示，跳過刪除操作');
                return;
            }
            
            // 🔹 確保按鈕有 type="button"，避免觸發表單提交
            if (deleteBtn.type !== 'button') {
                deleteBtn.type = 'button';
            }
            
            const fileKey = deleteBtn.getAttribute('data-file-key');
            if (!fileKey) {
                console.error('[setupFileDeleteDelegation] 找不到 fileKey');
                return;
            }
            
            // 🔹 判斷是舊檔案還是新檔案
            const isExistingFile = deleteBtn.classList.contains('remove-existing-file-btn');
            
            if (isExistingFile) {
                // 🔹 刪除舊檔案（必須真正從 existingOtherFiles 移除）
                const filePath = deleteBtn.getAttribute('data-file-path') || '';
                const fileItem = deleteBtn.closest('[data-file-type="existing"]');
                const fileName = fileItem?.querySelector('.fw-semibold')?.textContent || filePath || '檔案';
                
                const confirmed = await showConfirmDialog(`確定要移除「${fileName}」這個檔案嗎？`, '確認刪除');
                if (!confirmed) return;
                
                // 🔹 【修復刪除後暫存不生效】必須真正從 existingOtherFiles 移除，使用唯一鍵（fileKey）刪除
                const beforeCount = existingOtherFiles.length;
                existingOtherFiles = existingOtherFiles.filter(file => {
                    const currentKey = getFileKey(file, true);
                    return currentKey !== fileKey;
                });
                const afterCount = existingOtherFiles.length;
                
                // 🔹 同時記錄到 deletedExistingKeys（用於 UI 過濾）
                deletedExistingKeys.add(fileKey);
                
                console.log('[setupFileDeleteDelegation] 已刪除舊檔案:', fileKey, '檔案數:', beforeCount, '->', afterCount);
            } else {
                // 🔹 刪除新選擇的檔案
                const fileItem = deleteBtn.closest('[data-file-type="new"]');
                const fileName = fileItem?.querySelector('.fw-semibold')?.textContent || '檔案';
                
                const confirmed = await showConfirmDialog(`確定要移除「${fileName}」這個檔案嗎？`, '確認刪除');
                if (!confirmed) return;
                
                // 從 selectedOtherFiles 中移除（使用 fileKey 匹配；每項為 { file, file_type }）
                const beforeCount = selectedOtherFiles.length;
                selectedOtherFiles = selectedOtherFiles.filter(item => getFileKey(item.file, false) !== fileKey);
                const afterCount = selectedOtherFiles.length;
                
                console.log('[setupFileDeleteDelegation] 已刪除新檔案:', fileKey, '檔案數:', beforeCount, '->', afterCount);
                
                // 同步更新 input（傳入 File 陣列）
                const input = document.getElementById('otherFilesInput');
                if (input) {
                    syncInputFiles(input, selectedOtherFiles.map(x => x.file));
                }
            }
            
            // 🔹 重新渲染列表（UI 會立刻更新）
            renderSelectedFiles();
        };
        
        // 綁定事件（使用捕獲階段，確保能捕獲到）
        listContainer.addEventListener('click', listContainer._deleteHandler, true);
        console.log('[setupFileDeleteDelegation] ✅ 已設置事件委派');
    }
    
    /** 🔹 每列檔案類型下拉選單：變更時寫回 selectedOtherFiles / existingOtherFiles */
    function setupFileTypeSelectDelegation() {
        const listContainer = document.getElementById('otherFilesList');
        if (!listContainer) return;
        if (listContainer._fileTypeChangeHandler) {
            listContainer.removeEventListener('change', listContainer._fileTypeChangeHandler);
            delete listContainer._fileTypeChangeHandler;
        }
        listContainer._fileTypeChangeHandler = function(e) {
            const select = e.target.closest('select.file-type-select');
            if (!select) return;
            const fileKey = select.getAttribute('data-file-key');
            const rowType = select.getAttribute('data-row-type');
            const value = (select.value || '').trim();
            if (rowType === 'new') {
                const idx = selectedOtherFiles.findIndex(item => getFileKey(item.file, false) === fileKey);
                if (idx !== -1) selectedOtherFiles[idx].file_type = value;
            } else if (rowType === 'existing') {
                const idx = existingOtherFiles.findIndex(f => getFileKey(f, true) === fileKey);
                if (idx !== -1) existingOtherFiles[idx].file_type = value;
            }
        };
        listContainer.addEventListener('change', listContainer._fileTypeChangeHandler);
    }
    
    function renderSelectedFiles() {
        // 🔹 檢查是否為已提交唯讀狀態
        const isSubmittedReadonly = window.PROJECT_UPLOAD_CONFIG?.isSubmittedReadonly === true;
        if (isSubmittedReadonly) {
            // 已提交狀態下，不顯示新文件選擇面板
            const empty = document.getElementById('otherFilesEmpty');
            const panel = document.getElementById('otherFilesPanel');
            if (empty) empty.style.display = 'none';
            if (panel) panel.style.display = 'none';
            return;
        }
        
        // 🔹 使用 Single Source of Truth 獲取顯示列表
        const displayList = getDisplayFileList();
        const keptExistingCount = displayList.existing.length;
        const newFilesCount = displayList.new.length;
        const totalFilesLength = displayList.all.length;
        
        console.log('[debug] renderSelectedFiles 開始，keptExisting:', keptExistingCount, 'newFiles:', newFilesCount, 'deletedKeys:', deletedExistingKeys.size);
        
        const empty = document.getElementById('otherFilesEmpty');
        const panel = document.getElementById('otherFilesPanel');
        const list = document.getElementById('otherFilesList');
        const sumEl = document.getElementById('otherFilesSummary');
        
        if (!empty || !panel || !list || !sumEl) {
            console.warn('[renderSelectedFiles] 找不到必要的 DOM 元素');
            console.warn('[renderSelectedFiles] empty:', !!empty, 'panel:', !!panel, 'list:', !!list, 'sumEl:', !!sumEl);
            return;
        }
        
        // 🔹 【防重複】既有檔案已由 PHP 在 #uploadedFilesList 顯示時：僅在「本次新選檔案」時顯示 otherFilesPanel，否則隱藏
        if (window.__OTHER_FILES_RENDERED_BY_PHP__ && newFilesCount === 0) {
            if (panel) panel.style.display = 'none';
            if (empty) empty.style.display = 'none';
            if (list) list.innerHTML = '';
            if (sumEl) sumEl.textContent = '';
            return;
        }
        
        // 🔹 【步驟 4】UI 顯示條件必須以 input.files.length 或 selectedOtherFiles.length 為準
        const otherFilesInput = document.getElementById('otherFilesInput');
        const inputFilesLength = otherFilesInput?.files?.length || 0;
        const selectedFilesLength = selectedOtherFiles.length;
        
        // 🔹 如果 input 有檔案但 selectedOtherFiles 為空，同步更新（每項為 { file, file_type }）
        if (inputFilesLength > 0 && selectedFilesLength === 0) {
            console.log('[debug] input 有檔案但 selectedOtherFiles 為空，同步更新');
            selectedOtherFiles = Array.from(otherFilesInput.files || []).map(f => ({ file: f, file_type: '' }));
            // 重新獲取顯示列表
            const updatedDisplayList = getDisplayFileList();
            displayList.all = updatedDisplayList.all;
            displayList.new = updatedDisplayList.new;
            // 更新計數變數
            const newTotalFilesLength = displayList.all.length;
            const newNewFilesCount = displayList.new.length;
            totalFilesLength = newTotalFilesLength;
            newFilesCount = newNewFilesCount;
        }
        
        if (totalFilesLength === 0) {
            console.log('[debug] 沒有檔案，顯示「尚未上傳檔案」');
            if (empty) empty.style.display = '';
            if (panel) panel.style.display = 'none';
            if (list) list.innerHTML = '';
            if (sumEl) sumEl.textContent = '';
            return;
        }
        
        console.log('[debug] 有檔案，顯示清單，數量:', totalFilesLength, '(保留舊檔:', keptExistingCount, ', 新選擇:', newFilesCount, ')');
        
        // 🔹 確保文件列表能正確顯示
        if (empty) empty.style.display = 'none';
        if (panel) {
        panel.style.display = '';
            panel.style.visibility = 'visible';
        }
        
        // 🔹 合併顯示保留的舊檔案和新選擇的檔案（當 __OTHER_FILES_RENDERED_BY_PHP__ 時，不把既有檔案重複渲染到本面板）
        let filesToDisplay = [];
        let totalBytes = 0;
        
        // 先添加保留的舊檔案（使用實際索引；每個需帶 file_type 供下拉選單）
        if (!window.__OTHER_FILES_RENDERED_BY_PHP__) {
            displayList.existing.forEach((file) => {
                const originalIndex = existingOtherFiles.findIndex(f => getFileKey(f, true) === getFileKey(file, true));
                // 🔹 優先使用 original_name（學生原始上傳檔名），沒有再用 name，最後用 basename
                const fileName = file.original_name || file.name || (file.path ? basename(file.path) : '未知檔案');
                filesToDisplay.push({
                    type: 'existing',
                    file: file,
                    fileKey: getFileKey(file, true),
                    originalIndex: originalIndex,
                    fileName: fileName,
                    filePath: file.path || '',
                    size: 0, // 已暫存的檔案大小未知
                    file_type: file.file_type ?? ''
                });
            });
        }
        
        // 再添加新選擇的檔案（每項為 { file, file_type }）
        displayList.new.forEach((item, idx) => {
            const f = item.file;
            const fileType = item.file_type || '';
            filesToDisplay.push({
                type: 'new',
                file: f,
                fileKey: getFileKey(f, false),
                index: idx,
                fileName: f.name,
                filePath: '',
                size: f.size || 0,
                file_type: fileType
            });
            totalBytes += f.size || 0;
        });
        
        // 🔹 【UI优化】更新摘要文字，明确区分"已保存"和"待提交"（當 __OTHER_FILES_RENDERED_BY_PHP__ 時本面板只顯示本次新選，摘要不計入既有）
        const _kept = window.__OTHER_FILES_RENDERED_BY_PHP__ ? 0 : keptExistingCount;
        if (_kept > 0 && newFilesCount > 0) {
            sumEl.innerHTML = `已選擇 <strong>${totalFilesLength}</strong> 個檔案（<span class="badge bg-success">${_kept} 個已保存</span>，<span class="badge bg-warning text-dark">${newFilesCount} 個待提交</span>）${totalBytes > 0 ? `，待提交檔案總大小：${formatSize(totalBytes)}` : ''}`;
        } else if (_kept > 0) {
            sumEl.innerHTML = `已選擇 <strong>${_kept}</strong> 個檔案（<span class="badge bg-success">全部已保存</span>）`;
        } else if (newFilesCount > 0) {
            sumEl.innerHTML = `已選擇 <strong>${newFilesCount}</strong> 個檔案（<span class="badge bg-warning text-dark">待提交</span>）${totalBytes > 0 ? `，總大小：${formatSize(totalBytes)}` : ''}`;
        } else {
            sumEl.textContent = '尚未選擇檔案';
        }
        
        // 不再使用檔案類型下拉選單，類型改由副檔名自動判斷
        
        // 🔹 【修復重複渲染】渲染前先清空容器
        list.innerHTML = '';
        
        list.innerHTML = filesToDisplay.map(file => {
            if (file.type === 'existing') {
                const fileUrl = file.filePath ? '../' + file.filePath : '';
                return `
                    <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2 bg-light" data-file-type="existing" data-file-key="${escapeHtml(file.fileKey)}" style="background-color: #f8f9fa !important;">
                        <div class="d-flex align-items-center gap-2 flex-grow-1" style="min-width: 0;">
                            <i class="fa-regular fa-file text-primary"></i>
                            <div class="flex-grow-1" style="min-width: 0; overflow: hidden;">
                                <div class="fw-semibold text-truncate" style="max-width: 100%;" title="${escapeHtml(file.fileName)}">${escapeHtml(file.fileName)}</div>
                                <div class="text-muted small"><span class="badge bg-success">已保存</span></div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                            ${fileUrl ? `<a href="${escapeHtml(fileUrl)}" target="_blank" class="btn btn-sm btn-success" download title="下載" style="white-space: nowrap;"><i class="fa-solid fa-download"></i> <span class="d-none d-md-inline">下載</span></a>` : ''}
                            <button type="button" class="btn btn-sm btn-danger remove-existing-file-btn" data-file-key="${escapeHtml(file.fileKey)}" data-file-path="${escapeHtml(file.filePath)}" title="刪除" style="white-space: nowrap;"><i class="fa-solid fa-trash"></i> <span class="d-none d-md-inline">刪除</span></button>
                        </div>
                    </div>
                `;
            } else {
                return `
                    <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2" data-file-type="new" data-file-key="${escapeHtml(file.fileKey)}" style="background-color: #fff3cd !important; border-color: #ffc107 !important;">
                        <div class="d-flex align-items-center gap-2 flex-grow-1" style="min-width: 0;">
                            <i class="fa-regular fa-file text-warning"></i>
                            <div class="flex-grow-1" style="min-width: 0; overflow: hidden;">
                                <div class="fw-semibold text-truncate" style="max-width: 100%;" title="${escapeHtml(file.fileName)}">${escapeHtml(file.fileName)}</div>
                                <div class="text-muted small"><span class="badge bg-warning text-dark">待提交</span> <span class="ms-1">${formatSize(file.size)}</span></div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                            <button type="button" class="btn btn-sm btn-danger remove-new-file-btn" data-file-key="${escapeHtml(file.fileKey)}" title="刪除" style="white-space: nowrap;"><i class="fa-solid fa-trash"></i> <span class="d-none d-md-inline">刪除</span></button>
                        </div>
                    </div>
                `;
            }
        }).join('');
        
        // 🔹 【修復刪除按鈕事件】不再在這裡綁定事件，改用事件委派（在容器上綁定一次）
    }
    
    // 🔹 【修復重複綁定】使用全局變數存儲 change handler，確保只綁定一次
    let otherFilesChangeHandler = null;
    
    function initOtherFiles() {
        // 🔹 檢查是否為已提交唯讀狀態
        const isSubmittedReadonly = window.PROJECT_UPLOAD_CONFIG?.isSubmittedReadonly === true;
        if (isSubmittedReadonly) {
            console.log('[initOtherFiles] 已提交唯讀狀態，跳過文件選擇功能初始化');
            // 確保新文件選擇面板隱藏
            const empty = document.getElementById('otherFilesEmpty');
            const panel = document.getElementById('otherFilesPanel');
            if (empty) empty.style.display = 'none';
            if (panel) panel.style.display = 'none';
            return;
        }
        
        // 🔹 【步驟 1】確認 input 是否真的存在且 id 正確
        const input = document.getElementById('otherFilesInput');
        console.log('[debug] otherFilesInput exists?', !!input, input);
        
        if (!input) {
            console.warn('[initOtherFiles] 找不到 otherFilesInput');
            return;
        }
        
        // 🔹 檢查是否為編輯模式
        const urlParams = new URLSearchParams(window.location.search);
        const isEditMode = urlParams.has('edit');
        
        const btnClear = document.getElementById('btnClearOtherFiles');
        
        // 🔹 【修復重複綁定】確保 change 事件只綁定一次
        if (input.hasAttribute('data-change-handler-bound')) {
            console.log('[initOtherFiles] change 事件已經綁定過，跳過重複綁定');
            // 🔹 編輯模式下，如果已有文件但未顯示，強制渲染
            if (isEditMode && existingOtherFiles.length > 0) {
                const displayList = getDisplayFileList();
                if (displayList.all.length > 0) {
                    renderSelectedFiles();
                    console.log('[initOtherFiles] 編輯模式：強制渲染已有文件列表');
                }
            } else {
            renderSelectedFiles(); // 仍然要渲染，確保 UI 正確
            }
            return;
        }
        
        // 🔹 【修復重複綁定】標記已綁定
        input.setAttribute('data-change-handler-bound', 'true');
        
        // 🔹 【修復重複綁定】定義 change handler 函數
        otherFilesChangeHandler = function(e) {
            console.log('[debug] change fired, files=', e.target.files?.length, e.target.files);
            
            if (!e.target.files || e.target.files.length === 0) {
                console.warn('[initOtherFiles] change 事件觸發但沒有檔案');
                // 🔹 【修復允許重選同檔】即使沒有檔案，也要清空 input.value
                e.target.value = '';
                return;
            }
            
            console.log('[initOtherFiles] change 事件觸發，files:', e.target.files);
            
            // 🔹 【檔案大小驗證】每個檔案最大 50MB
            const maxSize = 50 * 1024 * 1024;
            const newFiles = Array.from(e.target.files || []);
            const invalidFiles = newFiles.filter(file => file.size > maxSize);
            
            if (invalidFiles.length > 0) {
                showAlertDialog(`有 ${invalidFiles.length} 個檔案超過 50GB 限制，已跳過`, 'warning');
            }
            
            // 過濾出有效的檔案
            const validFiles = newFiles.filter(file => file.size <= maxSize);
            
            if (validFiles.length === 0) {
                console.warn('[initOtherFiles] 沒有有效的檔案');
                // 【修復允許重選同檔】清空 input.value
                e.target.value = '';
                return;
            }
            
            // 🔹 【修復檔案重複】使用 name+size+lastModified 做去重（每項為 { file, file_type }）
            const selectedFileKeys = new Set(
                selectedOtherFiles.map(item => `${item.file.name}_${item.file.size}_${item.file.lastModified || 0}`)
            );
            
            // 檢查原有檔案中是否有重複（避免新選擇的檔案與原有檔案重複）
            const existingFileKeys = new Set(
                existingOtherFiles.map(f => {
                    const fileName = f.original_name || f.name || (f.path ? basename(f.path) : '');
                    return `${fileName}_${f.size || 0}_0`; // 原有檔案沒有 lastModified，用 0 代替
                })
            );
            
            // 🔹 【同名文件检测】检查是否有同名文件（只比较文件名，不比较大小）
            const existingFileNames = new Set(
                existingOtherFiles.map(f => {
                    const fileName = f.original_name || f.name || (f.path ? basename(f.path) : '');
                    return fileName.toLowerCase(); // 不区分大小写
                })
            );
            const selectedFileNames = new Set(
                selectedOtherFiles.map(item => (item.file && item.file.name || '').toLowerCase())
            );
            
            // 检测同名文件
            const duplicateNames = [];
            const uniqueNewFiles = validFiles.filter(file => {
                const key = `${file.name}_${file.size}_${file.lastModified || 0}`;
                if (selectedFileKeys.has(key)) {
                    console.log('[initOtherFiles] 跳過重複的新選擇檔案:', file.name);
                    return false;
                }
                // 檢查是否與原有檔案重複（只比較檔名和大小）
                const existingKey = `${file.name}_${file.size}_0`;
                if (existingFileKeys.has(existingKey)) {
                    console.log('[initOtherFiles] 跳過與原有檔案重複的檔案:', file.name);
                    return false;
                }
                // 🔹 【同名文件检测】检查是否有同名文件（只比较文件名）
                const fileNameLower = file.name.toLowerCase();
                if (existingFileNames.has(fileNameLower) || selectedFileNames.has(fileNameLower)) {
                    duplicateNames.push(file.name);
                    // 不跳过，允许用户选择同名文件，但会提醒
                }
                selectedFileKeys.add(key);
                selectedFileNames.add(fileNameLower);
                return true;
            });
            
            // 🔹 【同名文件提醒】如果有同名文件，提醒用户
            if (duplicateNames.length > 0) {
                const duplicateList = duplicateNames.join('、');
                showAlertDialog(
                    `檢測到以下檔案與已存在的檔案同名：\n${duplicateList}\n\n系統會保留兩個檔案，但建議使用不同的檔名以避免混淆。`,
                    'warning',
                    5000
                );
            }
            
            // 合併到 selectedOtherFiles（每項為 { file, file_type }）
            selectedOtherFiles = [...selectedOtherFiles, ...uniqueNewFiles.map(f => ({ file: f, file_type: '' }))];
            console.log('[initOtherFiles] 更新 selectedOtherFiles，新增:', uniqueNewFiles.length, '個，總數:', selectedOtherFiles.length);
            
            // 🔹 【修復清除全部】如果用戶選擇了新文件，清除 shouldClearAllFiles 標記
            if (uniqueNewFiles.length > 0 && shouldClearAllFiles) {
                shouldClearAllFiles = false;
                console.log('[initOtherFiles] 用戶選擇了新文件，已清除 shouldClearAllFiles 標記');
            }
            
            // 🔹 【修復允許重選同檔】清空 input.value，允許重新選擇同一個檔案
            e.target.value = '';
            
            // 🔹 【步驟 4】確保 UI 顯示（防止被其他流程重置）
            renderSelectedFiles();
            
            // 🔹 驗證：選檔後 console 必須出現 change fired 並且 files.length > 0
            if (selectedOtherFiles.length > 0) {
                console.log('[debug] ✅ 驗收通過：change fired 且 files.length > 0');
            }
        };
        
        // 🔹 【修復重複綁定】只綁定一次 change 事件
        input.addEventListener('change', otherFilesChangeHandler, false);
        
        // 🔹 【修復清除全部按鈕】綁定清除全部按鈕，清除所有檔案（包括原有檔案和新選擇的）
        if (btnClear) {
            btnClear.addEventListener('click', async () => {
                // 檢查是否為編輯模式
                const urlParams = new URLSearchParams(window.location.search);
                const isEditMode = urlParams.has('edit');
                
                // 計算要清除的檔案數（保留的舊檔案 + 新選擇的檔案）
                const displayList = getDisplayFileList();
                const totalFilesToClear = displayList.all.length;
                
                if (totalFilesToClear > 0) {
                    const confirmed = await showConfirmDialog(
                        `確定要清除全部 ${totalFilesToClear} 個檔案嗎？此操作無法復原。`,
                        '確認清除全部'
                    );
                    if (!confirmed) return;
                }
                
                // 🔹 【修復清除全部】必須同時清掉三種來源：existingOtherFiles、deletedExistingKeys、selectedOtherFiles
                // 1. 清除新選擇的檔案
                selectedOtherFiles = [];
                if (input) {
                    input.value = '';
                }
                
                // 2. 編輯模式：將所有舊檔案加入 deletedExistingKeys，並清空 existingOtherFiles
                if (isEditMode) {
                    // 先記錄所有舊檔案的 key（用於加入 deletedExistingKeys）
                    const allExistingKeys = [];
                    existingOtherFiles.forEach(file => {
                        const fileKey = getFileKey(file, true);
                        deletedExistingKeys.add(fileKey);
                        allExistingKeys.push(fileKey);
                    });
                    // 🔹 關鍵：清空 existingOtherFiles，避免刷新後重新載入
                existingOtherFiles = [];
                    // 🔹 【修復清除全部】設置標記，表示要清空所有檔案
                    shouldClearAllFiles = true;
                    console.log('[initOtherFiles] 編輯模式：已將所有舊檔案加入 deletedExistingKeys，數量:', deletedExistingKeys.size, '，已清空 existingOtherFiles，已設置 shouldClearAllFiles=true');
                } else {
                    // 非編輯模式：直接清空
                    existingOtherFiles = [];
                }
                
                console.log('[initOtherFiles] 已清除全部檔案 - selectedOtherFiles:', selectedOtherFiles.length, 'existingOtherFiles:', existingOtherFiles.length, 'deletedKeys:', deletedExistingKeys.size, 'shouldClearAllFiles:', shouldClearAllFiles);
                
                // 重新渲染列表
                renderSelectedFiles();
            });
        }
        
        // 🔹 【修復 F5 後文件消失】檢查 input.files 是否有值，如果有則同步到 selectedOtherFiles（每項為 { file, file_type }）
        if (input.files && input.files.length > 0) {
            console.log('[initOtherFiles] 檢測到 input 已有檔案，同步到 selectedOtherFiles');
            selectedOtherFiles = Array.from(input.files).map(f => ({ file: f, file_type: '' }));
            console.log('[initOtherFiles] 已同步', selectedOtherFiles.length, '個檔案');
        }
        
        // 🔹 初始渲染（根據當前狀態）
        renderSelectedFiles();
        
        // 🔹 【修復暫存後刷新檔案消失】如果已有檔案，也要顯示在 otherFilesPanel 中
        if (existingOtherFiles.length > 0) {
            console.log('[initOtherFiles] 檢測到原有檔案，調用 renderSelectedFiles() 顯示');
            renderSelectedFiles();
        }
        
        // 🔹 編輯模式：確保文件列表已顯示
        if (isEditMode) {
            // 🔹 【修復編輯頁多檔案顯示】編輯模式下，如果 existingOtherFiles 已有資料（從 window.__EXISTING_OTHER_FILES__ 讀取），不再從DOM讀取，避免覆蓋
            // 🔹 【關鍵】只有在 existingOtherFiles 為空時，才從DOM讀取（作為備用方案）
            // 🔹 【關鍵】如果 existingOtherFiles 已有資料，絕對不要清空或覆蓋
            if (existingOtherFiles.length === 0) {
                if (!uploadedFilesList) {
                    uploadedFilesList = document.getElementById('uploadedFilesList');
                }
                if (uploadedFilesList) {
                    const renderedBy = uploadedFilesList.getAttribute('data-rendered-by');
                    const fileItems = uploadedFilesList.querySelectorAll('.uploaded-file-item');
                    // 🔹 只有當 PHP 端渲染了文件（renderedBy === 'php'）且 DOM 中有文件項目時，才從 DOM 讀取
                    if (fileItems.length > 0 && (renderedBy === 'php' || !renderedBy)) {
                        console.log('[initOtherFiles] 編輯模式：existingOtherFiles 為空，從DOM讀取', fileItems.length, '個檔案');
                        fileItems.forEach(item => {
                            const filePath = item.getAttribute('data-file-path');
                            const fileInfoStr = item.getAttribute('data-file-info');
                            
                            if (filePath) {
                                if (fileInfoStr) {
                                    try {
                                        const fileInfo = JSON.parse(fileInfoStr);
                                        const fileName = fileInfo.original_name || fileInfo.name || basename(filePath);
                                        existingOtherFiles.push({
                                            original_name: fileInfo.original_name || fileName,
                                            name: fileName,
                                            path: fileInfo.path || filePath,
                                            type: fileInfo.type || '',
                                            uploaded_at: fileInfo.uploaded_at || fileInfo.upload_time || '',
                                            public: fileInfo.public !== undefined ? fileInfo.public : true,
                                            file_type: fileInfo.file_type ?? ''
                                        });
                                    } catch (e) {
                                        const fileName = item.querySelector('.file-name')?.textContent || basename(filePath);
                                        existingOtherFiles.push({
                                            original_name: fileName,
                                            name: fileName,
                                            path: filePath,
                                            type: '',
                                            uploaded_at: '',
                                            public: true,
                                            file_type: ''
                                        });
                                    }
                                } else {
                                    const fileName = item.querySelector('.file-name')?.textContent || basename(filePath);
                                    existingOtherFiles.push({
                                        original_name: fileName,
                                        name: fileName,
                                        path: filePath,
                                        type: '',
                                        uploaded_at: '',
                                        public: true,
                                        file_type: ''
                                    });
                                }
                            }
                        });
                        console.log('[initOtherFiles] 編輯模式：從DOM讀取完成，existingOtherFiles 數量:', existingOtherFiles.length);
                    } else {
                        // 🔹 【修復清除全部】PHP端沒有渲染文件（數據庫為空），確保前端狀態也是空的
                        if (renderedBy === 'js' || fileItems.length === 0) {
                            console.log('[initOtherFiles] 編輯模式：PHP端沒有渲染文件（數據庫為空），確保前端狀態為空');
                            existingOtherFiles = [];
                            deletedExistingKeys.clear();
                            shouldClearAllFiles = false;
                        }
                    }
                }
            } else {
                console.log('[initOtherFiles] 編輯模式：existingOtherFiles 已有', existingOtherFiles.length, '個檔案（從 init 讀取），跳過DOM讀取');
            }
            
            // 🔹 確保文件列表已顯示（若 __OTHER_FILES_RENDERED_BY_PHP__ 則不強制顯示 otherFilesPanel，既有檔案僅在 #uploadedFilesList）
            const displayList = getDisplayFileList();
            if (displayList.all.length > 0) {
                console.log('[initOtherFiles] 編輯模式：確保文件列表已顯示，文件數:', displayList.all.length);
                // 延遲調用，確保 DOM 完全準備好
                setTimeout(() => {
                    renderSelectedFiles();
                    // 🔹 【防重複】既有檔案已由 PHP 渲染時，不強制顯示 otherFilesPanel
                    if (!window.__OTHER_FILES_RENDERED_BY_PHP__) {
                        const panel = document.getElementById('otherFilesPanel');
                        const empty = document.getElementById('otherFilesEmpty');
                        if (panel && empty && displayList.all.length > 0) {
                            panel.style.display = '';
                            empty.style.display = 'none';
                            console.log('[initOtherFiles] 編輯模式：已強制顯示文件列表面板');
                        }
                    }
                }, 100);
            } else {
                console.log('[initOtherFiles] 編輯模式：沒有文件需要顯示，調用 renderSelectedFiles() 顯示「尚未上傳檔案」');
                // 🔹 【修復清除全部】即使沒有文件，也要調用 renderSelectedFiles() 確保 UI 正確
                setTimeout(() => {
                    renderSelectedFiles();
                }, 100);
            }
        }
        
        console.log('[initOtherFiles] 初始化完成，selectedOtherFiles 數量:', selectedOtherFiles.length, 'existingOtherFiles 數量:', existingOtherFiles.length, 'deletedKeys 數量:', deletedExistingKeys.size);
    }
    
    function setupMultipleFilesUpload() {
        // 🔹 使用新的初始化函數
        initOtherFiles();
        
        // 🔹 【修復刪除按鈕事件】設置事件委派（在容器上綁定一次，避免重新渲染後事件丟失）
        setupFileDeleteDelegation();
        setupFileTypeSelectDelegation();
        
        // 🔹 【修復】先獲取 multipleFilesInput，確保變數存在
        const multipleFilesInput = document.getElementById('otherFilesInput');
        const selectMultipleFilesBtn = document.getElementById('selectMultipleFilesBtn') || document.querySelector('label[for="otherFilesInput"]');
        
        if (!multipleFilesInput || !selectMultipleFilesBtn) {
            console.log('多檔案上傳功能未找到，可能不在編輯模式');
            return;
        }
        
        // 🔹 保留原有的按鈕啟用邏輯（確保按鈕可點擊）
        if (selectMultipleFilesBtn) {
            ensureFileInputEnabled('otherFilesInput');
        }
        
        // 🔹 【防呆】立即確保 input 和按鈕可用（編輯模式和新增模式都必須允許選檔）
        ensureFileInputEnabled('otherFilesInput');
        
        // 【C1/D2】編輯模式：儲存原始值用於比對變更
        const urlParams = new URLSearchParams(window.location.search);
        const isEditMode = urlParams.has('edit');
        if (isEditMode) {
            const projectIntroElement = getIntroEl();
            if (projectIntroElement && !projectIntroElement.getAttribute('data-original-value')) {
                projectIntroElement.setAttribute('data-original-value', projectIntroElement.value || '');
            }
        }
        
        // 🔹 編輯模式：如果 existingOtherFiles 已有資料（從 init 讀取），不再從DOM讀取，避免覆蓋
        // urlParams 和 isEditMode 已在上面聲明，不需要重複聲明
        
        if (isEditMode) {
            // 🔹 【修復編輯頁多檔案顯示】編輯模式下，如果 existingOtherFiles 已有資料，不再從DOM讀取
            if (existingOtherFiles.length === 0) {
                // 🔹 只有在 existingOtherFiles 為空時，才從DOM讀取（作為備用方案）
                console.log('[setupMultipleFilesUpload] 編輯模式：existingOtherFiles 為空，檢查DOM中的文件列表');
                if (!uploadedFilesList) {
                    uploadedFilesList = document.getElementById('uploadedFilesList');
                }
                if (uploadedFilesList) {
                    // 🔹 【修復清除全部】檢查 data-rendered-by 屬性，如果是 'js' 表示PHP端沒有渲染文件（數據庫為空）
                    const renderedBy = uploadedFilesList.getAttribute('data-rendered-by');
                    const fileItems = uploadedFilesList.querySelectorAll('.uploaded-file-item');
                    
                    if (fileItems.length > 0 && (renderedBy === 'php' || !renderedBy)) {
                        // 清空現有列表，重新從DOM讀取（確保與PHP端同步）
                        existingOtherFiles = [];
                        deletedExistingKeys.clear();
                        shouldClearAllFiles = false; // 🔹 【修復清除全部】初始化時清除標記
                        console.log('[setupMultipleFilesUpload] 編輯模式：已清空 existingOtherFiles 和 deletedExistingKeys，準備從DOM讀取');
                        
                        fileItems.forEach(item => {
                            const filePath = item.getAttribute('data-file-path');
                            const fileInfoStr = item.getAttribute('data-file-info');
                            
                            if (filePath) {
                                // 如果有 data-file-info 屬性（新格式），使用它；否則使用舊格式
                                if (fileInfoStr) {
                                    try {
                                        const fileInfo = JSON.parse(fileInfoStr);
                                        // 確保包含所有必要欄位，並優先使用 original_name
                                        const fileName = fileInfo.original_name || fileInfo.name || basename(filePath);
                                        const uploadTime = fileInfo.uploaded_at || fileInfo.upload_time || '';
                                        const isPublic = fileInfo.public !== undefined ? fileInfo.public : (fileInfo.allow_download !== undefined ? fileInfo.allow_download : true);
                                        
                                        existingOtherFiles.push({
                                            original_name: fileInfo.original_name || fileName,
                                            name: fileName,
                                            path: fileInfo.path || filePath,
                                            type: fileInfo.type || '',
                                            uploaded_at: uploadTime,
                                            public: isPublic
                                        });
                                    } catch (e) {
                                        // JSON 解析失敗，使用舊格式
                                        const fileName = item.querySelector('.file-name')?.textContent || basename(filePath);
                                        existingOtherFiles.push({
                                            original_name: fileName,
                                            name: fileName,
                                            path: filePath,
                                            type: '',
                                            uploaded_at: '',
                                            public: true
                                        });
                                    }
                                } else {
                                    // 舊格式：只有路徑，從DOM讀取檔名
                                    const fileName = item.querySelector('.file-name')?.textContent || basename(filePath);
                                    existingOtherFiles.push({
                                        original_name: fileName,
                                        name: fileName,
                                        path: filePath,
                                        type: '',
                                        uploaded_at: '',
                                        public: true
                                    });
                                }
                            }
                        });
                        console.log('[setupMultipleFilesUpload] 編輯模式：從DOM讀取', existingOtherFiles.length, '個檔案');
                        
                        // 🔹 關鍵：延遲調用 renderSelectedFiles() 顯示文件列表（確保 DOM 完全準備好）
                        setTimeout(() => {
                            if (typeof renderSelectedFiles === 'function') {
                                renderSelectedFiles();
                                console.log('[setupMultipleFilesUpload] 編輯模式：已調用 renderSelectedFiles() 顯示文件列表');
                                
                                // 🔹 【防重複】既有檔案已由 PHP 渲染時，不強制顯示 otherFilesPanel
                                const panel = document.getElementById('otherFilesPanel');
                                const list = document.getElementById('otherFilesList');
                                if (panel && list && !window.__OTHER_FILES_RENDERED_BY_PHP__) {
                                    const displayList = getDisplayFileList();
                                    if (displayList.all.length > 0 && panel.style.display === 'none') {
                                        console.warn('[setupMultipleFilesUpload] 編輯模式：文件列表未顯示，強制顯示');
                                        panel.style.display = '';
                                        const empty = document.getElementById('otherFilesEmpty');
                                        if (empty) empty.style.display = 'none';
                                    }
                                }
                            }
                        }, 100);
                    } else {
                        // 🔹 【修復清除全部】DOM中沒有找到文件項目，表示數據庫為空，確保前端狀態也是空的
                        console.log('[setupMultipleFilesUpload] 編輯模式：DOM中沒有找到文件項目（數據庫為空）');
                        existingOtherFiles = [];
                        deletedExistingKeys.clear();
                        shouldClearAllFiles = false;
                        // 🔹 調用 renderSelectedFiles() 確保 UI 正確顯示「尚未上傳檔案」
                        setTimeout(() => {
                            if (typeof renderSelectedFiles === 'function') {
                                renderSelectedFiles();
                            }
                        }, 100);
                    }
                }
            } else {
                console.log('[setupMultipleFilesUpload] 編輯模式：existingOtherFiles 已有', existingOtherFiles.length, '個檔案（從 init 讀取），跳過DOM讀取，直接渲染');
                // 🔹 即使已有資料，也要確保渲染顯示（若 __OTHER_FILES_RENDERED_BY_PHP__ 則不強制顯示 otherFilesPanel）
                setTimeout(() => {
                    if (typeof renderSelectedFiles === 'function') {
                        renderSelectedFiles();
                        if (!window.__OTHER_FILES_RENDERED_BY_PHP__) {
                            const panel = document.getElementById('otherFilesPanel');
                            const empty = document.getElementById('otherFilesEmpty');
                            if (panel && empty && existingOtherFiles.length > 0) {
                                panel.style.display = '';
                                empty.style.display = 'none';
                            }
                        }
                    }
                }, 100);
            }
        } else {
            // 非編輯模式：只在 existingOtherFiles 為空時才初始化，避免重複添加
        if (existingOtherFiles.length === 0) {
            // 🔹 使用全局變數，不再重複聲明
            if (!uploadedFilesList) {
                uploadedFilesList = document.getElementById('uploadedFilesList');
            }
            if (uploadedFilesList) {
                const fileItems = uploadedFilesList.querySelectorAll('.uploaded-file-item');
                fileItems.forEach(item => {
                    const filePath = item.getAttribute('data-file-path');
                    const fileInfoStr = item.getAttribute('data-file-info');
                    
                    if (filePath) {
                        // 檢查是否已存在（避免重複）
                        const alreadyExists = existingOtherFiles.some(file => {
                            if (typeof file === 'string') {
                                return file === filePath;
                            } else if (file && file.path) {
                                return file.path === filePath;
                            }
                            return false;
                        });
                        
                        if (alreadyExists) {
                            console.log('[初始化] 檔案已存在，跳過:', filePath);
                            return;
                        }
                        
                        // 如果有 data-file-info 屬性（新格式），使用它；否則使用舊格式
                        if (fileInfoStr) {
                            try {
                                const fileInfo = JSON.parse(fileInfoStr);
                                // 確保包含所有必要欄位（name, path, type, uploaded_at, public）
                                if (fileInfo.name && fileInfo.path && fileInfo.type !== undefined && fileInfo.uploaded_at !== undefined && fileInfo.public !== undefined) {
                                    existingOtherFiles.push(fileInfo);
                                } else {
                                    // 部分欄位缺失，補全為新格式
                                    const fileName = fileInfo.name || fileInfo.original_name || basename(filePath);
                                    const uploadTime = fileInfo.uploaded_at || fileInfo.upload_time || '';
                                    const isPublic = fileInfo.public !== undefined ? fileInfo.public : (fileInfo.allow_download !== undefined ? fileInfo.allow_download : true);
                                    
                                    existingOtherFiles.push({
                                        original_name: fileInfo.original_name || fileName,
                                        name: fileName,
                                        path: fileInfo.path || filePath,
                                        type: fileInfo.type || '',
                                        uploaded_at: uploadTime,
                                        public: isPublic
                                    });
                                }
                            } catch (e) {
                                // JSON 解析失敗，使用舊格式
                                existingOtherFiles.push({
                                    name: basename(filePath),
                                    path: filePath,
                                    type: '',
                                    uploaded_at: '',
                                    public: true
                                });
                            }
                        } else {
                            // 舊格式：只有路徑
                            existingOtherFiles.push({
                                name: basename(filePath),
                                path: filePath,
                                type: '',
                                uploaded_at: '',
                                public: true
                            });
                        }
                    }
                });
                console.log('[初始化] 從 PHP 端載入的檔案數:', existingOtherFiles.length);
            }
        } else {
            console.log('[初始化] existingOtherFiles 已有資料，跳過從 PHP 端載入');
            }
        }
        
        // 輔助函數：從路徑獲取檔名
        /**
         *  更新預覽按鈕（當有海報時）
         * @param {string} posterPath - 海報路徑
         */
        function updatePreviewButton(posterPath) {
            if (!posterPath) return;
            
            // 檢查是否已有預覽按鈕
            let previewBtn = document.getElementById('previewPosterBtn');
            const isPDF = posterPath.toLowerCase().endsWith('.pdf');
            
            if (!previewBtn) {
                // 創建預覽按鈕
                const noFileBtn = document.getElementById('noFileBtn');
                if (noFileBtn && noFileBtn.parentElement) {
                    previewBtn = document.createElement('button');
                    previewBtn.type = 'button';
                    previewBtn.className = 'btn btn-info btn-sm';
                    previewBtn.id = 'previewPosterBtn';
                    previewBtn.setAttribute('data-poster-path', posterPath);
                    previewBtn.setAttribute('data-is-pdf', isPDF ? '1' : '0');
                    previewBtn.title = '預覽海報';
                    previewBtn.innerHTML = '<i class="fa-solid fa-eye"></i> 預覽';
                    
                    // 插入到 noFileBtn 後面
                    noFileBtn.parentElement.insertBefore(previewBtn, noFileBtn.nextSibling);
                }
            } else {
                // 更新現有按鈕
                previewBtn.setAttribute('data-poster-path', posterPath);
                previewBtn.setAttribute('data-is-pdf', isPDF ? '1' : '0');
            }
        }
        
        /**
         *  移除預覽按鈕（當檔案不存在時）
         */
        function removePreviewButton() {
            const previewBtn = document.getElementById('previewPosterBtn');
            if (previewBtn) {
                previewBtn.remove();
            }
        }
        
        // 將 removePreviewButton 暴露到全局作用域，供 resetFormToInitial 使用
        window.removePreviewButton = removePreviewButton;
        
        // 🔹 將 updatePreviewButton 暴露到全局作用域，供 loadDraft 等函數使用
        window.updatePreviewButton = updatePreviewButton;
        
        // 🔹 將 basename 函數暴露到全局作用域，供 loadDraft 等函數使用
        function basename(path) {
            if (!path) return '';
            const parts = path.split('/');
            return parts[parts.length - 1] || path;
        }
        window.basename = basename;
        
        // 🔹 【防呆】選擇多個檔案按鈕點擊事件（使用多種方式確保可以點擊）
        function handleSelectMultipleFilesClick(e) {
            // 🔹 如果是 label，不要阻止默認行為（label 會自動觸發 input）
            if (selectMultipleFilesBtn.tagName !== 'LABEL') {
                e.preventDefault();
                e.stopPropagation();
            }
            console.log('[setupMultipleFilesUpload] 選擇多個檔案按鈕被點擊');
            
            // 🔹 【修復】確保 multipleFilesInput 變數可用
            const input = document.getElementById('otherFilesInput');
            if (!input) {
                console.error('[setupMultipleFilesUpload] 找不到 otherFilesInput');
                return;
            }
            
            // 🔹 【防呆】如果是 label，label 的點擊會自動觸發 input，但我們也要確保 input 可用
            if (selectMultipleFilesBtn.tagName === 'LABEL') {
                ensureFileInputEnabled('otherFilesInput');
            }
            
            // 🔹 【防呆】檢查並啟用按鈕（如果是 button）
            if (selectMultipleFilesBtn.tagName === 'BUTTON' && selectMultipleFilesBtn.disabled) {
                console.warn('[setupMultipleFilesUpload] 按鈕被禁用，嘗試啟用...');
                ensureFileInputEnabled('otherFilesInput');
            }
            
            // 🔹 【防呆】檢查並啟用文件輸入框
            if (input.disabled) {
                console.warn('[setupMultipleFilesUpload] 文件輸入框被禁用，嘗試啟用...');
                ensureFileInputEnabled('otherFilesInput');
            }
            
            // 🔹 【防呆】確保按鈕和輸入框可以點擊
            selectMultipleFilesBtn.style.setProperty('pointer-events', 'auto', 'important');
            selectMultipleFilesBtn.style.setProperty('cursor', 'pointer', 'important');
            input.style.setProperty('pointer-events', 'auto', 'important');
            
            // 觸發文件選擇
            try {
                input.click();
                console.log('[setupMultipleFilesUpload] 已觸發文件選擇對話框');
            } catch (error) {
                console.error('[setupMultipleFilesUpload] 觸發文件選擇失敗:', error);
                // 如果 click() 失敗，嘗試使用其他方式
                try {
                    const event = new MouseEvent('click', {
                        bubbles: true,
                        cancelable: true,
                        view: window
                    });
                    input.dispatchEvent(event);
                    console.log('[setupMultipleFilesUpload] 使用 dispatchEvent 觸發文件選擇');
                } catch (err2) {
                    console.error('[setupMultipleFilesUpload] dispatchEvent 也失敗:', err2);
                }
            }
        }
        
        // 🔹 【防呆】確保按鈕可以點擊（使用最高優先級）
        selectMultipleFilesBtn.removeAttribute('disabled');
        selectMultipleFilesBtn.disabled = false;
        selectMultipleFilesBtn.style.setProperty('pointer-events', 'auto', 'important');
        selectMultipleFilesBtn.style.setProperty('cursor', 'pointer', 'important');
        selectMultipleFilesBtn.style.setProperty('opacity', '1', 'important');
        selectMultipleFilesBtn.style.setProperty('z-index', '10', 'important');
        selectMultipleFilesBtn.style.setProperty('position', 'relative', 'important');
        
        // 🔹 【防呆】確保文件輸入框可以訪問（使用已獲取的變數）
        if (multipleFilesInput) {
            multipleFilesInput.removeAttribute('disabled');
            multipleFilesInput.disabled = false;
            multipleFilesInput.style.setProperty('pointer-events', 'auto', 'important');
        }
        
        // 先移除可能存在的舊事件監聽器
        selectMultipleFilesBtn.onclick = null;
        
        // 🔹 【防呆】使用多種方式綁定事件，確保可以點擊（根據元素類型選擇不同方式）
        if (selectMultipleFilesBtn.tagName === 'LABEL') {
            // 如果是 label，不要阻止 label 的默認行為
            selectMultipleFilesBtn.addEventListener('click', function(e) {
                console.log('[setupMultipleFilesUpload] label 被點擊');
                ensureFileInputEnabled('otherFilesInput');
            }, false);
        } else {
            // 如果是 button，只綁定一個 click 事件
            selectMultipleFilesBtn.addEventListener('click', function(e) {
                console.log('[setupMultipleFilesUpload] 按鈕被點擊');
                handleSelectMultipleFilesClick(e);
            }, false);
        }
        
        console.log('[setupMultipleFilesUpload] 多檔案上傳功能初始化完成，按鈕已綁定事件（多種方式）');
        
        // 🔹 【防呆】延遲再次檢查和綁定，確保按鈕可以點擊
        setTimeout(() => {
            ensureFileInputEnabled('otherFilesInput');
        }, 500);
        
        setTimeout(() => {
            ensureFileInputEnabled('otherFilesInput');
        }, 1000);
    }
    
    /**
     * 🔹 【防呆】確保多檔案輸入框和按鈕可用（編輯模式和新增模式都必須允許選檔）
     */
    function ensureMultipleFilesInputEnabled() {
        const multipleFilesInput = document.getElementById('otherFilesInput');
        const selectBtn = document.getElementById('selectMultipleFilesBtn') || document.querySelector('label[for="otherFilesInput"]');
        
        if (!multipleFilesInput || !selectBtn) return;
        
        // 🔹 檢查是否為唯讀檢視模式（只有 view 模式才禁用）
        const urlParams = new URLSearchParams(window.location.search);
        const isViewMode = urlParams.has('view');
        const isFinalLocked = selectBtn.hasAttribute('disabled') && selectBtn.getAttribute('disabled') !== 'false';
        
        // 🔹 編輯模式和新增模式都必須允許選檔，只有唯讀檢視模式才禁用
        if (!isViewMode && !isFinalLocked) {
            // 強制啟用 input
            multipleFilesInput.removeAttribute('disabled');
            multipleFilesInput.disabled = false;
            multipleFilesInput.style.setProperty('pointer-events', 'auto', 'important');
            multipleFilesInput.style.setProperty('display', 'none', 'important'); // 保持隱藏但可訪問
            
            // 強制啟用按鈕/label
            if (selectBtn.tagName === 'LABEL') {
                // 如果是 label，確保可以點擊
                selectBtn.style.setProperty('pointer-events', 'auto', 'important');
                selectBtn.style.setProperty('cursor', 'pointer', 'important');
            } else {
                // 如果是 button
                selectBtn.removeAttribute('disabled');
                selectBtn.disabled = false;
                selectBtn.style.setProperty('pointer-events', 'auto', 'important');
                selectBtn.style.setProperty('cursor', 'pointer', 'important');
            }
            
            console.log('[ensureMultipleFilesInputEnabled] 已確保多檔案輸入框和按鈕可用');
        }
        
        // 🔹 【防呆】檔案選擇 change 事件（使用多種方式確保能觸發）
        // 🔹 【修復重複綁定】移除 setupMultipleFilesUpload 中的 change 事件綁定
        // change 事件已經在 initOtherFiles() 中統一處理，避免重複綁定
        // 如果 multipleFilesInput 還沒有綁定 change 事件，initOtherFiles() 會處理
        
        // 注意：不再在這裡綁定 change 事件，避免重複綁定導致檔案重複添加
        // 所有 change 事件處理都在 initOtherFiles() 中統一管理
        
        // 4. 🔹 【防呆】手動監聽文件選擇，作為最後的備用機制
        // 使用 MutationObserver 監聽 input 的 value 變化
        const observer = new MutationObserver(function(mutations) {
            console.log('[setupMultipleFilesUpload] MutationObserver 檢測到變化');
            if (multipleFilesInput.files && multipleFilesInput.files.length > 0) {
                console.log('[setupMultipleFilesUpload] MutationObserver 檢測到檔案，手動觸發處理');
                const event = new Event('change', { bubbles: true, cancelable: true });
                multipleFilesInput.dispatchEvent(event);
            }
        });
        
        // 監聽 input 的屬性變化
        observer.observe(multipleFilesInput, {
            attributes: true,
            attributeFilter: ['value']
        });
        
        // 5. 🔹 【防呆】延遲檢查，確保事件監聽器已正確綁定
        setTimeout(() => {
            console.log('[setupMultipleFilesUpload] 延遲檢查：multipleFilesInput 是否可用:', !multipleFilesInput.disabled);
            console.log('[setupMultipleFilesUpload] 延遲檢查：change 事件監聽器數量:', multipleFilesInput.onchange ? 1 : 0);
            console.log('[setupMultipleFilesUpload] 延遲檢查：input 元素:', multipleFilesInput);
        }, 100);
        
        // 6. 🔹 【防呆】定期檢查文件是否已選擇但未顯示（每 500ms 檢查一次，持續 5 秒）
        let checkCount = 0;
        const maxChecks = 10;
        const checkInterval = setInterval(() => {
            checkCount++;
            if (checkCount > maxChecks) {
                clearInterval(checkInterval);
                return;
            }
            
            if (multipleFilesInput.files && multipleFilesInput.files.length > 0) {
                console.log('[setupMultipleFilesUpload] 定期檢查：發現檔案但 selectedOtherFiles 為空，手動觸發處理');
                const files = Array.from(multipleFilesInput.files);
                if (selectedOtherFiles.length === 0 && files.length > 0) {
                    console.log('[setupMultipleFilesUpload] 手動處理檔案選擇');
                    handleMultipleFilesChange({ target: multipleFilesInput });
                }
            }
        }, 500);
        
        // 處理刪除已存在檔案的按鈕
        const removeFileBtns = document.querySelectorAll('.remove-file-btn');
        removeFileBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const fileIndex = parseInt(this.getAttribute('data-file-index'));
                const fileItem = this.closest('.uploaded-file-item');
                
                if (fileItem) {
                    const filePath = fileItem.getAttribute('data-file-path');
                    // 從已存在檔案列表中移除（支持新舊格式）
                    existingOtherFiles = existingOtherFiles.filter(file => {
                        if (typeof file === 'string') {
                            return file !== filePath;
                        } else if (file && file.path) {
                            return file.path !== filePath;
                        }
                        return true;
                    });
                    // 移除 DOM 元素
                    fileItem.remove();
                    
                    // 如果沒有檔案了，隱藏列表
                    // 🔹 使用全局變數，不再重複聲明
                    if (!uploadedFilesList) {
                        uploadedFilesList = document.getElementById('uploadedFilesList');
                    }
                    if (uploadedFilesList && uploadedFilesList.querySelectorAll('.uploaded-file-item').length === 0) {
                        uploadedFilesList.innerHTML = '';
                    }
                }
            });
        });
        
        // 綁定清除全部按鈕事件
        const clearAllSelectedFilesBtn = document.getElementById('clearAllSelectedFilesBtn');
        if (clearAllSelectedFilesBtn) {
            clearAllSelectedFilesBtn.addEventListener('click', function() {
                clearAllSelectedFiles();
            });
        }
    }
    
    /**
     * 更新已選擇檔案列表顯示
     */
    function updateSelectedFilesList() {
        console.log('[updateSelectedFilesList] ========== 開始更新 ==========');
        console.log('[updateSelectedFilesList] selectedOtherFiles 數量:', selectedOtherFiles.length);
        console.log('[updateSelectedFilesList] selectedOtherFiles 內容:', selectedOtherFiles.map(item => ({ name: item.file?.name, size: item.file?.size })));
        
        const selectedFilesList = document.getElementById('selectedFilesList');
        const selectedFilesContainer = document.getElementById('selectedFilesContainer');
        
        if (!selectedFilesList) {
            console.error('[updateSelectedFilesList] ❌ 找不到 selectedFilesList 元素');
            console.error('[updateSelectedFilesList] 嘗試查找元素...');
            const allElements = document.querySelectorAll('[id*="selected"], [id*="Selected"]');
            console.log('[updateSelectedFilesList] 找到相關元素:', allElements);
            return;
        }
        
        if (!selectedFilesContainer) {
            console.error('[updateSelectedFilesList] ❌ 找不到 selectedFilesContainer 元素');
            return;
        }
        
        console.log('[updateSelectedFilesList] ✅ 找到元素，selectedFilesList:', selectedFilesList, 'selectedFilesContainer:', selectedFilesContainer);
        
        if (selectedOtherFiles.length === 0) {
            selectedFilesList.style.display = 'none';
            selectedFilesContainer.innerHTML = '';
            console.log('[updateSelectedFilesList] 沒有檔案，隱藏列表');
            return;
        }
        
        console.log('[updateSelectedFilesList] 準備顯示', selectedOtherFiles.length, '個檔案');
        selectedFilesList.style.display = 'block';
        
        // 計算總大小（每項為 { file, file_type }）
        const totalSize = selectedOtherFiles.reduce((sum, item) => sum + (item.file && item.file.size ? item.file.size : 0), 0);
        const totalSizeMB = (totalSize / 1024 / 1024).toFixed(2);
        
        // 🔹 【防呆】生成 HTML 內容
        let htmlContent = `
            <div style="padding: 10px; background: #e7f3ff; border-radius: 5px; margin-bottom: 10px; font-weight: 600; color: #1976D2;">
                <i class="fa-solid fa-info-circle"></i> 已選擇 ${selectedOtherFiles.length} 個檔案，總大小：${totalSizeMB} MB
            </div>
        `;
        
        selectedOtherFiles.forEach((item, index) => {
            const file = item.file;
            const fileSize = file && file.size ? (file.size / 1024 / 1024).toFixed(2) : '0.00';
            const fileName = (file && file.name) || '未知檔案';
            htmlContent += `
                <div class="d-flex align-items-center justify-content-between p-2 border rounded mb-2" data-file-index="${index}">
                    <div class="d-flex align-items-center flex-grow-1">
                        <i class="fa-solid fa-file me-2 text-primary"></i>
                        <span class="file-name">${escapeHtml(fileName)}</span>
                        <small class="text-muted ms-2">(${fileSize} MB)</small>
                    </div>
                    <button type="button" class="btn btn-sm btn-danger ms-2 remove-selected-file-btn" data-file-index="${index}">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            `;
        });
        
        selectedFilesContainer.innerHTML = htmlContent;
        console.log('[updateSelectedFilesList] HTML 已更新，檔案數:', selectedOtherFiles.length);
        
        // 🔹 【防呆】綁定刪除按鈕事件
        selectedFilesContainer.querySelectorAll('.remove-selected-file-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const fileIndex = parseInt(this.getAttribute('data-file-index'));
                console.log('[updateSelectedFilesList] 刪除檔案，索引:', fileIndex);
                if (fileIndex >= 0 && fileIndex < selectedOtherFiles.length) {
                selectedOtherFiles.splice(fileIndex, 1);
                updateSelectedFilesList();
                }
            });
        });
        
        console.log('[updateSelectedFilesList] 更新完成');
    }
    
    /**
     * 清除所有已選擇的檔案
     */
    function clearAllSelectedFiles() {
        selectedOtherFiles = [];
        updateSelectedFilesList();
        // 清空 input，允許重新選擇
        const multipleFilesInput = document.getElementById('multipleFilesInput');
        if (multipleFilesInput) {
            multipleFilesInput.value = '';
        }
    }

    /**
     * 設置文件上傳
     */
    function setupFileUpload() {
        fileInputElement = document.getElementById('posterFileInput');
        const selectFileBtn = document.getElementById('selectFileBtn') || document.querySelector('label[for="posterFileInput"]');

        if (!fileInputElement || !selectFileBtn) {
            return; // 已提交/唯讀/檢視時海報上傳區不渲染，屬預期
        }

        // 檢查是否已經初始化過，避免重複綁定事件
        if (fileInputElement.hasAttribute('data-initialized')) {
            console.log('文件上傳功能已初始化，跳過重複初始化');
            // 🔹 即使已初始化，也要確保按鈕和 input 可用
            ensureFileInputEnabled('posterFileInput');
            return;
        }
        
        // 標記為已初始化
        fileInputElement.setAttribute('data-initialized', 'true');
        console.log('開始初始化文件上傳功能...');
        
        // 🔹 【防呆】立即確保 input 和按鈕可用（編輯模式和新增模式都必須允許選檔）
        ensureFileInputEnabled('posterFileInput');

        // 標記是否正在處理文件選擇，避免重複觸發
        let isProcessingFile = false;

        // 文件選擇 change 事件處理函數
        function handleFileChange(e) {
            console.log('文件選擇 change 事件觸發');
            
            // 如果正在處理，忽略
            if (isProcessingFile) {
                console.log('正在處理文件，忽略此次選擇');
                return;
            }
            
            const file = e.target.files && e.target.files[0];
            if (file) {
                console.log('選擇了文件:', file.name);
                // 設置處理標記，防止在處理過程中重複觸發
                isProcessingFile = true;
                hasFileSelected = true;
                
                // 立即處理文件選擇
                handleFileSelect(file);
                
                // 處理完成後重置處理標記，允許用戶重新選擇
                setTimeout(function() {
                    isProcessingFile = false;
                    console.log('文件處理完成，可以重新選擇');
                }, 500);
            } else {
                console.log('未選擇文件');
            }
        }

        // 綁定 change 事件（只綁定一次）
        fileInputElement.addEventListener('change', handleFileChange, false);
        console.log('已綁定文件選擇 change 事件');

        // 選擇按鈕點擊事件處理函數
        function handleSelectFileClick(e) {
            console.log('選擇檔案按鈕被點擊');
            
            // 🔹 【防呆】如果是 label，label 的點擊會自動觸發 input，但我們也要確保 input 可用
            if (selectFileBtn.tagName === 'LABEL') {
                // label 點擊會自動觸發對應的 input，但我們要確保 input 可用
                ensureFileInputEnabled('posterFileInput');
            }
            
            // 檢查按鈕是否被禁用（如果是 button）
            if (selectFileBtn.tagName === 'BUTTON' && selectFileBtn.disabled) {
                console.warn('選擇檔案按鈕被禁用，嘗試啟用...');
                ensureFileInputEnabled('posterFileInput');
                // 如果還是禁用，返回
            if (selectFileBtn.disabled) {
                return false;
                }
            }
            
            // 🔹 【防呆】檢查文件輸入框是否被禁用，如果禁用則啟用
            if (fileInputElement.disabled) {
                console.warn('文件輸入框被禁用，嘗試啟用...');
                ensureFileInputEnabled('posterFileInput');
                // 如果還是禁用，返回
                if (fileInputElement.disabled) {
                return false;
                }
            }
            
            // 如果正在處理文件，提示用戶
            if (isProcessingFile) {
                console.log('正在處理文件，請稍候...');
                showAlertDialog('正在處理文件，請稍候...', 'info');
                return false;
            }
            
            // 重置所有標記以允許重新選擇
            hasFileSelected = false;
            
            // 獲取當前的文件輸入框
            const currentInput = document.getElementById('posterFileInput');
            if (!currentInput) {
                console.error('找不到文件輸入框');
                showAlertDialog('找不到文件輸入框，請刷新頁面重試', 'error');
                return false;
            }
            
            // 先清空值，確保下次選擇能觸發 change 事件
            currentInput.value = '';
            
            // 清空當前文件引用，允許選擇新檔案
            currentFile = null;
            
            // 🔹 【防呆】直接觸發文件選擇對話框（使用多種方式確保可以觸發）
            try {
                console.log('準備觸發文件選擇對話框...');
                
                // 確保文件輸入框可以點擊
                fileInputElement.style.setProperty('pointer-events', 'auto', 'important');
                fileInputElement.style.setProperty('display', 'none', 'important'); // 保持隱藏但可訪問
                
                // 使用 setTimeout 確保在事件處理完成後再觸發
                setTimeout(function() {
                    currentInput.click();
                    console.log('文件選擇對話框已觸發');
                }, 10);
            } catch(err) {
                console.error('無法打開文件選擇對話框:', err);
                showAlertDialog('無法打開文件選擇對話框，請刷新頁面重試', 'error');
            }
            
            return false;
        }

        // 🔹 【防呆】確保按鈕可以點擊（使用最高優先級）
        selectFileBtn.removeAttribute('disabled');
        selectFileBtn.disabled = false;
        selectFileBtn.style.setProperty('pointer-events', 'auto', 'important');
        selectFileBtn.style.setProperty('cursor', 'pointer', 'important');
        selectFileBtn.style.setProperty('opacity', '1', 'important');
        selectFileBtn.style.setProperty('user-select', 'auto', 'important');
        selectFileBtn.style.setProperty('z-index', '10', 'important');
        selectFileBtn.style.setProperty('position', 'relative', 'important');
        
        // 🔹 【防呆】確保文件輸入框可以訪問
        fileInputElement.removeAttribute('disabled');
        fileInputElement.disabled = false;
        fileInputElement.style.setProperty('pointer-events', 'auto', 'important');
        
        // 🔹 【防呆】根據元素類型選擇不同的事件綁定方式
        if (selectFileBtn.tagName === 'LABEL') {
            selectFileBtn.addEventListener('click', function(e) {
                console.log('[setupFileUpload] label 被點擊');
                ensureFileInputEnabled('posterFileInput');
            }, false);
        } else {
            selectFileBtn.addEventListener('click', function(e) {
                console.log('[setupFileUpload] 按鈕被點擊');
                handleSelectFileClick(e);
            }, false);
        }
        
        console.log('[setupFileUpload] 文件上傳功能初始化完成，按鈕已綁定事件（多種方式）');
        
        // 🔹 【防呆】延遲再次檢查和綁定，確保按鈕可以點擊
        setTimeout(() => {
            // 再次確保按鈕可用
            if (selectFileBtn) {
                selectFileBtn.removeAttribute('disabled');
                selectFileBtn.disabled = false;
                selectFileBtn.style.setProperty('pointer-events', 'auto', 'important');
                selectFileBtn.style.setProperty('cursor', 'pointer', 'important');
                
                // 如果 onclick 被清空，重新綁定
                if (!selectFileBtn.onclick) {
                    selectFileBtn.onclick = handleSelectFileClick;
                    console.log('[setupFileUpload] 延遲檢查：重新綁定 onclick 事件');
                }
            }
        }, 500);
        
        setTimeout(() => {
            // 再次檢查
            if (selectFileBtn) {
                selectFileBtn.removeAttribute('disabled');
                selectFileBtn.disabled = false;
                selectFileBtn.style.setProperty('pointer-events', 'auto', 'important');
                if (!selectFileBtn.onclick) {
                    selectFileBtn.onclick = handleSelectFileClick;
                }
            }
        }, 1000);
    }

    /**
     * 完全鎖定文件輸入框，防止再次打開文件選擇對話框
     */
    function lockFileInput() {
        const fileInput = document.getElementById('posterFileInput');
        if (!fileInput) return;
        
        // 立即移除焦點，避免觸發 CSS transition 動畫
        fileInput.blur();
    }

    /**
     * 處理文件選擇 - 立即顯示預覽
     */
    function handleFileSelect(file) {
        console.log('處理文件選擇:', file.name, file.type);
        
        // 快速驗證文件類型
        const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf'];
        if (!validTypes.includes(file.type)) {
            showAlertDialog('請上傳圖片或PDF檔案', 'warning');
            const fileInput = document.getElementById('posterFileInput');
            if (fileInput) {
                fileInput.value = '';
                fileInput.blur();
            }
            // 重置標記，允許用戶重新選擇
            hasFileSelected = false;
            currentFile = null;
            return;
        }

        // 快速驗證文件大小（最大 10MB）
        if (file.size > 10 * 1024 * 1024) {
            showAlertDialog('檔案大小不能超過 10MB', 'warning');
            const fileInput = document.getElementById('posterFileInput');
            if (fileInput) {
                fileInput.value = '';
                fileInput.blur();
            }
            // 重置標記，允許用戶重新選擇
            hasFileSelected = false;
            currentFile = null;
            return;
        }

        // 立即保存文件引用
        currentFile = file;

        // 🔹 立即更新文件狀態按鈕（確保文件選擇後能顯示）
        const noFileBtn = document.getElementById('noFileBtn');
        if (noFileBtn) {
            // 用戶選擇文件時立即更新顯示（顯示原始檔名）
            noFileBtn.textContent = file.name; // 顯示原始檔名
                noFileBtn.classList.add('has-file');
                noFileBtn.disabled = false;
                // 確保 transition 已設置（如果之前被禁用）
                if (noFileBtn.style.transition === 'none') {
                    noFileBtn.style.transition = '';
                }
                
            // 確保按鈕可見
            noFileBtn.style.display = '';
            noFileBtn.style.visibility = 'visible';
            
            console.log('[handleFileSelect] 已更新文件顯示:', file.name);
        } else {
            console.error('[handleFileSelect] 找不到 noFileBtn 元素');
        }

        // 移除文件輸入框焦點，避免觸發 CSS transition 動畫
        // 但不鎖定，允許用戶隨時重新選擇
        const fileInput = document.getElementById('posterFileInput');
        if (fileInput) {
            fileInput.blur();
        }

        // 只在有預覽區域時才顯示預覽（編輯模式下可能沒有預覽區域）
        const previewArea = document.getElementById('previewArea');
        console.log('[handleFileSelect] 檢查預覽區域:', {
            'previewArea 是否存在': !!previewArea,
            'file.type': file.type,
            'file.name': file.name
        });
        
        if (previewArea) {
            // 立即顯示預覽
            if (file.type === 'application/pdf') {
                console.log('[handleFileSelect] 顯示 PDF 預覽');
                showPDFPreview(file);
            } else {
                console.log('[handleFileSelect] 開始讀取圖片文件，準備顯示預覽');
                const reader = new FileReader();
                reader.onload = function(e) {
                    const imageSrc = e.target.result;
                    console.log('[handleFileSelect] 文件讀取成功，開始顯示預覽，imageSrc 長度:', imageSrc.length);
                    showPreview(imageSrc);
                    
                    // 🔹 【防呆】延遲確認預覽是否顯示成功
                    setTimeout(() => {
                        const previewContent = document.getElementById('previewContent');
                        const previewImg = previewContent?.querySelector('img');
                        if (!previewImg || !previewImg.src) {
                            console.error('[handleFileSelect] ❌ 預覽顯示失敗，強制重新顯示');
                            showPreview(imageSrc);
                        } else {
                            console.log('[handleFileSelect] ✅ 預覽顯示成功');
                        }
                    }, 100);
                    
                    // 比例驗證在背景進行
                    const img = new Image();
                    img.src = imageSrc;
                    img.onload = function() {
                        const aspectRatio = img.height / img.width;
                        if (aspectRatio <= 1.0) {
                            console.warn('建議使用直式海報（高度應大於寬度）');
                        }
                    };
                };
                reader.onerror = function(error) {
                    console.error('[handleFileSelect] 文件讀取失敗:', error);
                    showAlertDialog('檔案讀取失敗，請重新選擇', 'error');
                    resetFileInput();
                };
                reader.readAsDataURL(file);
            }
        } else {
            console.warn('[handleFileSelect] ⚠️ 找不到預覽區域（previewArea），無法顯示預覽');
        }
    }

    /**
     * 顯示預覽
     */
    function showPreview(imageSrc) {
        console.log('[showPreview] 開始顯示預覽，imageSrc 長度:', imageSrc?.length || 0);
        
        const previewArea = document.getElementById('previewArea');
        if (!previewArea) {
            console.error('[showPreview] ❌ 找不到預覽區域（previewArea）');
            return;
        }

        let previewContainer = document.getElementById('previewContentContainer');
        if (!previewContainer) {
            console.log('[showPreview] 創建 previewContentContainer');
            previewContainer = document.createElement('div');
            previewContainer.id = 'previewContentContainer';
            previewArea.appendChild(previewContainer);
        }

        console.log('[showPreview] 清空預覽容器並創建新內容');
        previewContainer.innerHTML = '';

        const previewContent = document.createElement('div');
        previewContent.id = 'previewContent';
        previewContent.className = 'preview-content';
        
        const img = document.createElement('img');
        img.src = imageSrc;
        img.style.display = 'block';
        img.style.width = '100%';
        img.style.height = 'auto';
        img.alt = '預覽';
        
        // 🔹 添加載入成功/失敗的日誌
        img.onload = function() {
            console.log('[showPreview] ✅ 圖片載入成功');
        };
        img.onerror = function(error) {
            console.error('[showPreview] ❌ 圖片載入失敗:', error);
        };
        
        previewContent.appendChild(img);
        previewContainer.appendChild(previewContent);

        console.log('[showPreview] 預覽內容已添加到 DOM，調用 updateZoom');
        if (typeof updateZoom === 'function') {
            updateZoom();
        } else {
            console.warn('[showPreview] ⚠️ updateZoom 函數不存在');
        }
    }

    /**
     * 顯示PDF預覽
     */
    function showPDFPreview(file) {
        const previewArea = document.getElementById('previewArea');
        if (!previewArea) return;

        let previewContainer = document.getElementById('previewContentContainer');
        if (!previewContainer) {
            previewContainer = document.createElement('div');
            previewContainer.id = 'previewContentContainer';
            previewArea.appendChild(previewContainer);
        }

        previewContainer.innerHTML = '';

        const previewContent = document.createElement('div');
        previewContent.id = 'previewContent';
        previewContent.className = 'preview-content';
        
        const object = document.createElement('object');
        object.data = URL.createObjectURL(file);
        object.type = 'application/pdf';
        object.style.width = '100%';
        object.style.minHeight = '600px';
        // 保持當前的縮放級別，不要重置
        object.style.transform = `scale(${zoomLevel / 100})`;
        object.style.transformOrigin = 'top left';
        
        previewContent.appendChild(object);
        previewContainer.appendChild(previewContent);
        
        // 不調用 updateZoom()，保持當前的縮放級別
        // 只更新縮放控制顯示
        const zoomPercentage = document.getElementById('zoomPercentage');
        if (zoomPercentage) {
            zoomPercentage.textContent = `${zoomLevel}%`;
        }
        const zoomSlider = document.getElementById('zoomSlider');
        if (zoomSlider) {
            zoomSlider.value = zoomLevel;
        }
    }

    /**
     * 設置縮放控制（只在有預覽區域時）
     */
    function setupZoomControl() {
        const previewArea = document.getElementById('previewArea');
        if (!previewArea) return; // 如果沒有預覽區域（編輯模式），不設置縮放控制
        
        const zoomSlider = document.getElementById('zoomSlider');
        const zoomPercentage = document.getElementById('zoomPercentage');

        if (zoomSlider) {
            zoomSlider.addEventListener('input', function(e) {
                zoomLevel = parseInt(e.target.value);
                if (zoomPercentage) {
                    zoomPercentage.textContent = `${zoomLevel}%`;
                }
                updateZoom();
            });
        }

        const zoomInBtn = document.getElementById('zoomInBtn');
        const zoomOutBtn = document.getElementById('zoomOutBtn');

        if (zoomInBtn) {
            zoomInBtn.addEventListener('click', function() {
                zoomLevel = Math.min(zoomLevel + 10, 200);
                updateZoomControls();
                updateZoom();
            });
        }

        if (zoomOutBtn) {
            zoomOutBtn.addEventListener('click', function() {
                zoomLevel = Math.max(zoomLevel - 10, 50);
                updateZoomControls();
                updateZoom();
            });
        }
    }

    /**
     * 更新縮放控制顯示
     */
    function updateZoomControls() {
        const zoomSlider = document.getElementById('zoomSlider');
        const zoomPercentage = document.getElementById('zoomPercentage');

        if (zoomSlider) {
            zoomSlider.value = zoomLevel;
        }
        if (zoomPercentage) {
            zoomPercentage.textContent = `${zoomLevel}%`;
        }
    }

    /**
     * 更新預覽縮放
     */
    function updateZoom() {
        const previewContent = document.getElementById('previewContent');
        if (previewContent) {
            const scale = zoomLevel / 100;
            previewContent.style.transform = `scale(${scale})`;
            previewContent.style.transformOrigin = 'center top';
        }
    }

    /**
     * 設置表單操作
     */
    function setupFormActions() {
        // 重置按鈕（只重置縮放）
        const resetBtn = document.getElementById('resetBtn');
        if (resetBtn) {
            resetBtn.addEventListener('click', function() {
                // 直接重置縮放，不需要確認
                resetForm();
            });
        }

        // 移除按鈕（直接移除，不需要確認）
        const removeBtn = document.getElementById('removeBtn');
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                removeFile();
            });
        }

        // 暫存按鈕
        // 🔹 【修復編輯模式】編輯模式下不顯示暫存按鈕，因此不需要查找和綁定
        const urlParams = new URLSearchParams(window.location.search);
        const isEditMode = urlParams.has('edit');
        
        if (!isEditMode) {
            // 🔹 【防呆】確保暫存和提交按鈕可以點擊（僅在非編輯模式）
            const saveDraftBtn = document.getElementById('saveDraftBtn');
            if (saveDraftBtn) {
                // 強制啟用按鈕
                saveDraftBtn.removeAttribute('disabled');
                saveDraftBtn.disabled = false;
                saveDraftBtn.style.setProperty('pointer-events', 'auto', 'important');
                saveDraftBtn.style.setProperty('cursor', 'pointer', 'important');
                saveDraftBtn.style.setProperty('opacity', '1', 'important');
                
                // 只綁定一個事件監聽器（避免重複觸發）
                saveDraftBtn.addEventListener('click', function(e) {
                    console.log('[setupFormActions] 暫存按鈕被點擊');
                    e.preventDefault();
                    e.stopPropagation();
                    handleSaveDraft();
                }, false);
                
                console.log('[setupFormActions] 暫存按鈕已綁定單一事件');
            } else {
                console.warn('[setupFormActions] 找不到 saveDraftBtn（非編輯模式）');
            }
        } else {
            console.log('[setupFormActions] 編輯模式：跳過暫存按鈕綁定');
        }

        // 🔹 【防呆】確保提交按鈕可以點擊
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) {
            // 強制啟用按鈕
            submitBtn.removeAttribute('disabled');
            submitBtn.disabled = false;
            submitBtn.style.setProperty('pointer-events', 'auto', 'important');
            submitBtn.style.setProperty('cursor', 'pointer', 'important');
            submitBtn.style.setProperty('opacity', '1', 'important');
            
            // 🔹 【修復提交按鈕】確保按鈕有 type="button"，避免觸發表單提交
            if (submitBtn.type !== 'button') {
                submitBtn.type = 'button';
                console.log('[setupFormActions] 已將提交按鈕 type 改為 button');
            }
            
            // 🔹 【修復提交按鈕】移除舊的事件監聽器，避免重複綁定
            // 先移除所有可能的事件監聽器
            const newSubmitBtn = submitBtn.cloneNode(true);
            newSubmitBtn.type = 'button'; // 🔹 確保克隆的按鈕也是 button 類型
            submitBtn.parentNode.replaceChild(newSubmitBtn, submitBtn);
            const freshSubmitBtn = document.getElementById('submitBtn');
            
            // 確保按鈕狀態正確
            if (freshSubmitBtn) {
                freshSubmitBtn.removeAttribute('disabled');
                freshSubmitBtn.disabled = false;
                freshSubmitBtn.type = 'button'; // 🔹 確保是 button 類型
                freshSubmitBtn.style.setProperty('pointer-events', 'auto', 'important');
                freshSubmitBtn.style.setProperty('cursor', 'pointer', 'important');
                freshSubmitBtn.style.setProperty('opacity', '1', 'important');
                
                // 🔹 只綁定一個事件監聽器（避免重複觸發）
                const submitClickHandler = function(e) {
                    console.log('[setupFormActions] 提交按鈕被點擊', e);
                    
                    // 🔹 【修復】如果確認對話框正在顯示，不處理提交操作
                    if (isConfirmDialogShowing) {
                        console.warn('[setupFormActions] 確認對話框正在顯示，跳過提交操作');
                        e.preventDefault();
                        e.stopPropagation();
                        return;
                    }
                    
                    e.preventDefault();
                    e.stopPropagation(); // 防止事件冒泡
                    handleFormSubmit(e);
                };
                
                freshSubmitBtn.addEventListener('click', submitClickHandler, false);
                
                // 🔹 驗證按鈕狀態
                console.log('[setupFormActions] ✅ 提交按鈕已綁定事件（單一監聽器）');
                console.log('[setupFormActions] 提交按鈕狀態 - disabled:', freshSubmitBtn.disabled, 'type:', freshSubmitBtn.type, 'pointer-events:', freshSubmitBtn.style.pointerEvents);
            } else {
                console.error('[setupFormActions] ❌ 替換後找不到 submitBtn');
            }
        } else {
            console.warn('[setupFormActions] 找不到 submitBtn');
        }
        
        // 也監聽表單 submit 事件（作為備用，但按鈕已改為 type="button"）
        const uploadForm = document.getElementById('uploadProjectForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', function(e) {
                e.preventDefault();
                handleFormSubmit(e);
            });
        }
    }
    
    /**
     * 載入草稿資料（Persistence First - 資料持久化優先）
     * 確保 F5 刷新後所有資料都能正確回填
     */
    /**
     * 載入草稿資料（Persistence First - 資料持久化優先）
     * 確保 F5 刷新後所有資料都能正確回填
     */
    async function loadDraft() {
        // 🔹 檢查是否為已提交唯讀狀態
        const isSubmittedReadonly = window.PROJECT_UPLOAD_CONFIG?.isSubmittedReadonly === true;
        if (isSubmittedReadonly) {
            console.log('[loadDraft] 已提交唯讀狀態，跳過載入草稿，保留PHP端渲染的內容');
            // 🔹 確保簡介內容不被清空
            const projectIntroElement = getIntroEl();
            if (projectIntroElement) {
                const initialValue = projectIntroElement.getAttribute('data-initial-value') || projectIntroElement.value || '';
                if (initialValue.trim() && !projectIntroElement.value.trim()) {
                    projectIntroElement.value = initialValue;
                    console.log('[loadDraft] 已提交狀態：從 data-initial-value 恢復簡介內容');
                }
                const finalValue = initialValue.trim() || projectIntroElement.value.trim();
                if (finalValue) {
                    function restoreIfCleared() {
                        if (projectIntroElement && !projectIntroElement.value.trim()) {
                            projectIntroElement.value = finalValue;
                        }
                    }
                    setTimeout(restoreIfCleared, 200);
                    setTimeout(restoreIfCleared, 1000);
                }
            }
            return;
        }
        
        // 🔹 【期限到後清除暫存】如果已超過截止時間，不載入暫存資料，讓頁面顯示為空白
        const isDeadlinePassed = window.PROJECT_UPLOAD_CONFIG?.isDeadlinePassed === true;
        if (isDeadlinePassed) {
            console.log('[loadDraft] 已超過截止時間，跳過載入暫存資料，保持頁面空白');
            return;
        }
        
        // 🔹 【修復】如果剛剛提交成功，不載入草稿（避免重新填充已清空的表單）
        if (justSubmitted) {
            console.log('[loadDraft] 剛剛提交成功，跳過載入草稿');
            justSubmitted = false; // 重置標記
            return;
        }
        
        console.log('[loadDraft] ========== 開始載入草稿資料 ==========');
        console.log('[loadDraft] 時間戳:', new Date().toISOString());
        
        // 🔹 【修復 urlParams 重複宣告】在函數開頭統一宣告一次
        const urlParams = new URLSearchParams(window.location.search);
        const isEditMode = urlParams.has('edit');
        
        // 🔹 【重要】如果PHP端已經渲染了檔案列表，先從DOM讀取並同步到existingOtherFiles
        if (!uploadedFilesList) {
            uploadedFilesList = document.getElementById('uploadedFilesList');
        }
        if (uploadedFilesList) {
            const phpRenderedItems = uploadedFilesList.querySelectorAll('.uploaded-file-item[data-file-info]');
            // 🔹 編輯模式下，只有在 existingOtherFiles 為空時才從 DOM 同步（避免覆蓋用戶操作）
            const shouldSync = isEditMode ? (existingOtherFiles.length === 0) : (phpRenderedItems.length > 0 && existingOtherFiles.length === 0);
            
            if (shouldSync && phpRenderedItems.length > 0) {
                console.log('[loadDraft] 檢測到PHP端已渲染的檔案，先從DOM同步到existingOtherFiles');
                existingOtherFiles = [];
                // 🔹 編輯模式下，只有在第一次載入時才清空 deletedExistingKeys
                if (isEditMode && deletedExistingKeys.size === 0) {
                    console.log('[loadDraft] 編輯模式：第一次載入，確保 deletedExistingKeys 為空');
                }
                phpRenderedItems.forEach(item => {
                    const fileInfoStr = item.getAttribute('data-file-info');
                    if (fileInfoStr) {
                        try {
                            const fileInfo = JSON.parse(fileInfoStr);
                            existingOtherFiles.push(fileInfo);
                        } catch (e) {
                            const filePath = item.getAttribute('data-file-path');
                            const fileName = item.querySelector('.file-name')?.textContent || '';
                            if (filePath) {
                                existingOtherFiles.push({
                                    name: fileName,
                                    path: filePath
                                });
                            }
                        }
                    }
                });
                console.log('[loadDraft] 從DOM同步後，existingOtherFiles 數量:', existingOtherFiles.length);
            }
        }
        
        // 🔹 【步驟 4】修正重置邏輯：禁止在沒有新資料回來前先清空上傳區
        // 當使用者已選擇檔案（otherFilesInput.files.length > 0）時，不可覆蓋或清空「選取清單」
        const otherFilesInput = document.getElementById('otherFilesInput');
        const hasSelectedFiles = otherFilesInput && otherFilesInput.files && otherFilesInput.files.length > 0;
        const hasSelectedOtherFiles = selectedOtherFiles && selectedOtherFiles.length > 0;
        
        if (hasSelectedFiles || hasSelectedOtherFiles) {
            console.log('[debug] ⚠️ loadDraft 檢測到已選擇檔案，禁止重置 UI');
            console.log('[debug] otherFilesInput.files.length:', otherFilesInput?.files?.length || 0);
            console.log('[debug] selectedOtherFiles.length:', selectedOtherFiles?.length || 0);
            // 🔹 不重置已選擇的檔案，只處理其他欄位
        }
        
        // 🔹 【關鍵修復】使用統一的 getIntroEl() 函數獲取簡介元素
        const projectIntroElement = getIntroEl();
        if (!projectIntroElement) {
            console.warn('[loadDraft] ⚠️ 找不到簡介 textarea（#projectIntro），跳過簡介回填');
        }
        
        // 🔹 【修復 F5 後文件消失】編輯模式下，也必須從 API 載入最新數據
        // 原因：PHP 端渲染的可能是舊數據，必須以 API 返回的最新數據為準
        // 🔹 urlParams 和 isEditMode 已在函數開頭宣告，這裡不需要重複宣告
        
        // 🔹 編輯模式下，確保 textarea 的值不被清空（但不阻止 API 載入）
        if (isEditMode && projectIntroElement) {
                const currentValue = projectIntroElement.value || '';
                const initialValue = projectIntroElement.getAttribute('data-initial-value') || projectIntroElement.defaultValue || '';
                
                // 🔹 【優先級】1. 當前值（如果存在） 2. 初始值（PHP端渲染）
                let finalValue = '';
                if (currentValue.trim()) {
                    finalValue = currentValue;
                    console.log('[getDraft] 編輯模式：保留現有簡介內容:', currentValue.substring(0, 50) + '...');
                } else if (initialValue.trim()) {
                    finalValue = initialValue;
                    projectIntroElement.value = initialValue;
                    console.log('[getDraft] 編輯模式：恢復PHP端渲染的簡介內容');
                }
                
                // 延遲僅在簡介被清空時恢復，不覆寫使用者輸入（避免貼上時閃爍）
                if (finalValue.trim()) {
                    function restoreIfCleared() {
                        if (projectIntroElement && !projectIntroElement.value.trim()) {
                            projectIntroElement.value = finalValue;
                        }
                    }
                    setTimeout(restoreIfCleared, 200);
                    setTimeout(restoreIfCleared, 1000);
                }
        }
        
        // 🔹 強制從 API 載入暫存資料，確保 F5 後所有欄位都能正確回填
        // 即使 PHP 端有部分回填，也要從 API 完整載入，確保所有欄位都被「強力膠黏住」
        // 注意：projectIntroElement 已在函數開頭聲明，這裡不需要重複聲明
        // 🔹 使用全局變數，不再重複聲明
        if (!uploadedFilesList) {
            uploadedFilesList = document.getElementById('uploadedFilesList');
        }
        const noFileBtn = document.getElementById('noFileBtn');
        
        // 檢查 PHP 端是否已經回填了內容（用於同步，但不阻止 API 載入）
        const renderedBy = uploadedFilesList?.getAttribute('data-rendered-by');
        const hasPhpFiles = renderedBy === 'php' && uploadedFilesList && uploadedFilesList.querySelector('.uploaded-file-item') !== null;
        const hasPhpIntro = projectIntroElement && projectIntroElement.value.trim() !== '';
        const hasPhpPoster = noFileBtn && noFileBtn.classList.contains('has-file');
        
        // 🔹 【修復文件數量異常增加 + F5 後文件消失】編輯模式下，不從 PHP 端 DOM 讀取文件列表
        // 原因：PHP 端可能渲染了舊的數據，必須以 API 返回的最新數據為準
        // 🔹 urlParams 和 isEditMode 已在函數開頭宣告，這裡不需要重複宣告
        
        // 🔹 編輯模式下，清空 existingOtherFiles，強制從 API 載入最新數據
        if (isEditMode) {
            existingOtherFiles = [];
            deletedExistingKeys.clear(); // 🔹 清空刪除標記，因為要從 API 重新載入
            console.log('[getDraft] 編輯模式：已清空 existingOtherFiles，強制從 API 載入最新數據');
        } else if (hasPhpFiles) {
            // 🔹 非編輯模式下，可以從 PHP 端 DOM 讀取文件列表（作為初始數據）
            const fileItems = uploadedFilesList.querySelectorAll('.uploaded-file-item');
            existingOtherFiles = [];
            fileItems.forEach(item => {
                const fileInfo = item.getAttribute('data-file-info');
                if (fileInfo) {
                    try {
                        const file = JSON.parse(fileInfo);
                        // 🔹 確保有 original_name 字段
                        if (!file.original_name) {
                            file.original_name = file.name || basename(file.path || '');
                        }
                        existingOtherFiles.push(file);
                    } catch (e) {
                        const filePath = item.getAttribute('data-file-path');
                        const fileName = item.querySelector('.file-name')?.textContent || '';
                        if (filePath) {
                            existingOtherFiles.push({
                                original_name: fileName,
                                name: fileName,
                                path: filePath
                            });
                        }
                    }
                }
            });
            console.log('[getDraft] PHP 端已回填多檔案，已同步到 existingOtherFiles，檔案數:', existingOtherFiles.length);
        }
        
        // 🔹 不跳過 API 載入，強制從 API 完整載入暫存資料（確保所有欄位都被回填）
        // 即使 PHP 端有部分回填，也要從 API 完整載入，確保一致性
        
        try {
            const response = await fetch(`${API_BASE}?do=getDraft`);
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
                console.log('[getDraft] API 回應:', data);
            } catch (parseError) {
                console.error('[getDraft] JSON 解析失敗:', parseError);
                console.error('[getDraft] 原始回應:', text);
                return;
            }
            
            if (data.success && data.data) {
                const draft = data.data;
                console.log('[getDraft] 找到草稿資料:', draft);
                
                // 🔹 【關鍵修復】檢查狀態，只有狀態為 4（暫存）才回填，已提交的（狀態 1）不回填
                const draftStatus = draft.status || draft.prosub_status || null;
                if (draftStatus !== null && parseInt(draftStatus) !== 4) {
                    console.log('[getDraft] 資料狀態不是暫存（status=' + draftStatus + '），跳過回填，保持表單空白');
                    return; // 已提交的資料不回填到表單
                }
                
                // 🔹 【關鍵修復】如果用戶已經選擇了新文件（currentFile 存在），不要覆蓋用戶的選擇
                // 只回填簡介和其他資料，不覆蓋預覽
                if (currentFile) {
                    console.log('[getDraft] 用戶已選擇新文件，跳過預覽回填，保留用戶選擇的預覽');
                }
                
                // 🔹 【修復簡介刷新時閃爍消失】智能回填簡介（避免覆蓋 PHP 端已回填的內容）
                if (projectIntroElement) {
                    // 🔹 檢查 API 返回的 intro 字段和當前值
                    const apiIntroValue = draft.intro;
                    const currentValue = projectIntroElement.value || '';
                    const initialValue = projectIntroElement.getAttribute('data-initial-value') || '';
                    
                    // 🔹 【關鍵修復】優先級：API 有值 > 當前值 > 初始值 > 空字串
                    // 如果 API 返回的值有內容（非空字串），使用 API 的值
                    // 如果 API 返回空字串或 undefined/null，保留 PHP 端已經回填的值，避免閃爍
                    let finalIntroValue = '';
                    
                    if (apiIntroValue !== undefined && apiIntroValue !== null && apiIntroValue.trim() !== '') {
                        // API 有值，使用 API 的值
                        finalIntroValue = apiIntroValue;
                        console.log('[getDraft] 使用 API 返回的簡介:', finalIntroValue.substring(0, 50) + '...');
                    } else if (currentValue.trim() !== '') {
                        // API 沒有值，保留當前值（PHP 端已回填）
                        finalIntroValue = currentValue;
                        console.log('[getDraft] API 無值，保留 PHP 端已回填的簡介:', finalIntroValue.substring(0, 50) + '...');
                    } else if (initialValue.trim() !== '') {
                        // 當前值也沒有，使用初始值（PHP 端渲染的初始值）
                        finalIntroValue = initialValue;
                        console.log('[getDraft] 使用 PHP 端初始值:', finalIntroValue.substring(0, 50) + '...');
                    } else {
                        // 都沒有值，設置為空字串
                        finalIntroValue = '';
                        console.log('[getDraft] 簡介為空');
                    }
                    
                    // 🔹 只有在值發生變化時才設置，避免不必要的 DOM 操作
                    if (projectIntroElement.value !== finalIntroValue) {
                        projectIntroElement.value = finalIntroValue;
                    }
                    
                    // 🔹 延遲僅在「簡介被清空」時恢復，不覆寫使用者正在貼上/輸入的內容（避免貼上時閃爍）
                    function restoreIntroIfCleared() {
                        if (!projectIntroElement) return;
                        const cur = (projectIntroElement.value || '').trim();
                        const target = (finalIntroValue || '').trim();
                        if (cur === '' && target !== '') {
                            projectIntroElement.value = finalIntroValue;
                        }
                    }
                    setTimeout(restoreIntroIfCleared, 200);
                    setTimeout(restoreIntroIfCleared, 1000);
                    setTimeout(restoreIntroIfCleared, 2000);
                }
                
                // 回填 prosub_ID
                const prosubID = document.getElementById('prosubID');
                if (prosubID && draft.prosub_ID) {
                    prosubID.value = draft.prosub_ID;
                    console.log('[getDraft] 已回填 prosub_ID:', draft.prosub_ID);
                }
                
                // 🔹 強制回填海報（即使 PHP 端已回填，也要確保從 API 載入的資料正確）
                if (draft.poster_path) {
                    if (noFileBtn) {
                        // 🔹 使用全局 basename 函數或本地定義
                        const getBasename = (path) => {
                            if (!path) return '';
                            if (typeof window.basename === 'function') {
                                return window.basename(path);
                            }
                            const parts = path.split('/');
                            return parts[parts.length - 1] || path;
                        };
                        
                        //  顯示原始檔名（若存在），否則顯示目前可用檔名
                        const displayFileName = draft.poster_original_name || getBasename(draft.poster_path);
                        noFileBtn.textContent = displayFileName;
                        noFileBtn.classList.add('has-file');
                        noFileBtn.disabled = false;
                        console.log('[getDraft] 已回填海報:', draft.poster_path, '原始檔名:', draft.poster_original_name);
                    }
                    
                    // 🔹 【關鍵修復】確保預覽按鈕存在並使用正確的路徑（F5 刷新後穩定顯示）
                    // 即使 PHP 端已經渲染了預覽按鈕，也要確保路徑正確
                    if (typeof window.updatePreviewButton === 'function') {
                        window.updatePreviewButton(draft.poster_path);
                    } else if (typeof updatePreviewButton === 'function') {
                        updatePreviewButton(draft.poster_path);
                    } else {
                        console.warn('[getDraft] updatePreviewButton 函數尚未初始化，稍後再試');
                        // 延遲調用，等待函數初始化
                        setTimeout(() => {
                            if (typeof window.updatePreviewButton === 'function') {
                                window.updatePreviewButton(draft.poster_path);
                            }
                        }, 100);
                    }
                    
                    // 🔹 F5 時，若草稿/暫存資料中有 poster_path，預覽區要自動載入該圖片
                    // 🔹 【關鍵修復】如果用戶已經選擇了新文件（currentFile 存在），不要覆蓋用戶的選擇
                    // 只回填簡介和其他資料，不覆蓋預覽
                    if (currentFile) {
                        console.log('[getDraft] 用戶已選擇新文件，跳過預覽回填，保留用戶選擇的預覽');
                    } else {
                        // 容錯處理：直接嘗試載入預覽，如果失敗則不顯示錯誤
                        // 🔹 【關鍵修復】只有在沒有用戶選擇新文件時才載入預覽
                        if (!currentFile) {
                            try {
                                // 顯示預覽（已暫存海報）
                                console.log('[getDraft] 開始載入預覽，poster_path:', draft.poster_path);
                                displayPreview(draft.poster_path);
                            
                                // 🔹 【防呆】延遲再次確認預覽是否顯示成功（防止被其他邏輯清空）
                                setTimeout(() => {
                                    if (!currentFile) {
                                        const previewArea = document.getElementById('previewArea');
                                        const previewContentContainer = document.getElementById('previewContentContainer');
                                        if (previewArea && previewContentContainer) {
                                            const previewContent = previewContentContainer.querySelector('#previewContent');
                                            if (!previewContent || previewContent.innerHTML.trim() === '') {
                                                console.warn('[getDraft] 預覽被清空，重新載入...');
                                                displayPreview(draft.poster_path);
                                            } else {
                                                console.log('[getDraft] 預覽載入成功');
                                            }
                                        } else {
                                            console.warn('[getDraft] 找不到預覽區域，嘗試重新載入...');
                                            // 如果找不到預覽區域，可能是頁面結構問題，但我們仍然嘗試顯示
                                            if (draft.poster_path) {
                                                displayPreview(draft.poster_path);
                                            }
                                        }
                                    }
                                }, 200);
                                
                                // 🔹 【防呆】再次延遲確認（更長的延遲）
                                setTimeout(() => {
                                    if (!currentFile) {
                                        const previewArea = document.getElementById('previewArea');
                                        const previewContentContainer = document.getElementById('previewContentContainer');
                                        if (previewArea && previewContentContainer) {
                                            const previewContent = previewContentContainer.querySelector('#previewContent');
                                            if (!previewContent || previewContent.innerHTML.trim() === '') {
                                                console.warn('[getDraft] 預覽再次被清空，強制重新載入...');
                                                displayPreview(draft.poster_path);
                                            }
                                        }
                                    }
                                }, 1000);
                                
                                // 🔹 【關鍵修復】確保預覽按鈕存在（F5 刷新後穩定顯示）
                                // 🔹 直接使用 prosub_img 的值（已包含資料夾的相對路徑），不添加 ../ 前綴
                                // 即使檔案暫時無法訪問，也顯示預覽按鈕（讓用戶嘗試預覽）
                                // 因為檔案可能暫時無法訪問，但資料庫記錄應該保留
                                const posterPath = draft.poster_path;
                                if (posterPath) {
                                    // 直接顯示預覽按鈕，不檢查檔案是否存在
                                    // 原因：檔案可能暫時無法訪問，但資料庫記錄應該保留，F5後應該顯示
                                    if (typeof window.updatePreviewButton === 'function') {
                                        window.updatePreviewButton(posterPath);
                                    } else if (typeof updatePreviewButton === 'function') {
                                        updatePreviewButton(posterPath);
                                    } else {
                                        console.warn('[getDraft] updatePreviewButton 函數尚未初始化，稍後再試');
                                        // 延遲調用，等待函數初始化
                                        setTimeout(() => {
                                            if (typeof window.updatePreviewButton === 'function') {
                                                window.updatePreviewButton(posterPath);
                                            }
                                        }, 100);
                                    }
                                }
                            } catch (error) {
                                // 容錯處理：預覽載入失敗時不顯示錯誤
                                console.warn('[getDraft] 預覽載入失敗:', error);
                                // 即使失敗，也嘗試再次載入（只有在沒有用戶選擇新文件時）
                                if (!currentFile) {
                                    setTimeout(() => {
                                        if (draft.poster_path) {
                                            displayPreview(draft.poster_path);
                                        }
                                    }, 500);
                                }
                            }
                        } else {
                            console.log('[getDraft] 用戶已選擇新文件，跳過預覽回填，保留用戶選擇的預覽');
                        }
                }
                
                // 🔹 強制回填多檔案列表（即使 PHP 端已回填，也要確保從 API 載入的資料正確）
                // 注意：API 返回的格式是 data.other_files（draft 就是 data.data）
                const otherFilesFromAPI = draft.other_files || [];
                
                console.log('[getDraft] 🔍 檢查 other_files 數據:', {
                    'otherFilesFromAPI 是否存在': !!otherFilesFromAPI,
                    'otherFilesFromAPI 是否為數組': Array.isArray(otherFilesFromAPI),
                    'otherFilesFromAPI 長度': otherFilesFromAPI?.length || 0,
                    'otherFilesFromAPI 內容': JSON.stringify(otherFilesFromAPI).substring(0, 200),
                    'selectedOtherFiles 長度': selectedOtherFiles.length,
                    '用戶是否已選擇新文件': selectedOtherFiles.length > 0
                });
                
                // 🔹 【關鍵修復】如果用戶已經選擇了新文件（selectedOtherFiles 不為空），不要覆蓋用戶的選擇
                // 只更新 existingOtherFiles（已暫存檔案），保留 selectedOtherFiles（用戶新選擇的檔案）
                if (selectedOtherFiles.length > 0) {
                    console.log('[getDraft] 用戶已選擇新文件（', selectedOtherFiles.length, '個），保留用戶選擇，只更新已暫存檔案列表');
                }
                
                // 🔹 【修復文件數量異常增加】編輯模式下，以 API 返回的資料為準，清空現有列表
                // 重要：必須先清空現有列表，避免重複添加
                // 🔹 【關鍵修復】只清空 existingOtherFiles，不清空 selectedOtherFiles（保留用戶選擇）
                existingOtherFiles = [];
                deletedExistingKeys.clear(); // 🔹 清空刪除標記，因為已經從 API 重新載入
                
                if (otherFilesFromAPI && Array.isArray(otherFilesFromAPI) && otherFilesFromAPI.length > 0) {
                    console.log('[getDraft] 找到多檔案 JSON，檔案數:', otherFilesFromAPI.length);
                    console.log('[getDraft] 多檔案資料:', JSON.stringify(otherFilesFromAPI));
                    
                    // 🔹 使用全局 basename 函數或本地定義
                    const getBasename = (path) => {
                        if (!path) return '';
                        if (typeof window.basename === 'function') {
                            return window.basename(path);
                        }
                        const parts = path.split('/');
                        return parts[parts.length - 1] || path;
                    };
                    
                    // 將草稿中的檔案添加到 existingOtherFiles（已暫存檔案）
                    // JSON 只存在 JS 記憶體中，不渲染到 DOM
                    existingOtherFiles = otherFilesFromAPI.map(file => {
                        // 處理不同格式的檔案資料
                        const filePath = typeof file === 'string' ? file : (file.path || '');
                        const fileName = typeof file === 'string' ? getBasename(file) : (file.original_name || file.name || getBasename(filePath));
                        
                        return {
                            original_name: fileName,
                            name: fileName,
                            path: filePath,
                            type: typeof file === 'object' ? (file.type || '') : '',
                            size: typeof file === 'object' ? (file.size || 0) : 0,
                            uploaded_at: typeof file === 'object' ? (file.uploaded_at || '') : '',
                            public: typeof file === 'object' ? (file.public !== undefined ? file.public : true) : true,
                            mime: typeof file === 'object' ? (file.mime || '') : '',
                            file_type: typeof file === 'object' ? (file.file_type ?? '') : ''
                        };
                    });
                    
                    console.log('[getDraft] ✅ 已解析多檔案 JSON，existingOtherFiles:', existingOtherFiles.length, '個檔案');
                    console.log('[getDraft] existingOtherFiles 詳細內容:', JSON.stringify(existingOtherFiles, null, 2));
                } else {
                    // 🔹 【關鍵修復】即使 otherFilesFromAPI 為空，也要記錄日誌，幫助調試
                    console.warn('[getDraft] ⚠️ otherFilesFromAPI 為空或不是數組，existingOtherFiles 保持為空');
                    console.warn('[getDraft] draft.other_files 值:', draft.other_files);
                }
                
                // 🔹 【關鍵修復】無論是否有檔案，都要調用 renderSelectedFiles() 確保 UI 正確顯示
                // 如果有檔案，顯示檔案列表；如果沒有檔案，顯示「尚未上傳檔案」
                // 🔹 【關鍵修復】確保顯示包含：已暫存檔案（existingOtherFiles）+ 用戶新選擇的檔案（selectedOtherFiles）
                if (typeof renderSelectedFiles === 'function') {
                    const totalFiles = existingOtherFiles.length + selectedOtherFiles.length;
                    console.log('[getDraft] 調用 renderSelectedFiles() 更新顯示（已暫存:', existingOtherFiles.length, '個，新選擇:', selectedOtherFiles.length, '個，總數:', totalFiles, '）');
                    renderSelectedFiles();
                }
                
                // 🔹 【關鍵修復】強制顯示已暫存檔案清單（即使 PHP 端已渲染，也要確保前端狀態正確）
                // 立即渲染
                if (existingOtherFiles.length > 0) {
                    if (typeof renderExistingFiles === 'function') {
                        console.log('[getDraft] 開始調用 renderExistingFiles() 渲染檔案清單...');
                        renderExistingFiles();
                        console.log('[getDraft] ✅ 已調用 renderExistingFiles()，應該顯示', existingOtherFiles.length, '個檔案');
                    }
                        
                        // 🔹 【防呆】立即檢查渲染結果
                        setTimeout(() => {
                            if (!uploadedFilesList) {
                                uploadedFilesList = document.getElementById('uploadedFilesList');
                            }
                            if (uploadedFilesList) {
                                const renderedItems = uploadedFilesList.querySelectorAll('.uploaded-file-item');
                                console.log('[getDraft] 渲染後立即檢查：DOM中有', renderedItems.length, '個檔案項目');
                                if (renderedItems.length === 0 && existingOtherFiles.length > 0) {
                                    console.error('[getDraft] ❌ 渲染失敗！DOM中沒有檔案項目，但existingOtherFiles有', existingOtherFiles.length, '個檔案，強制重新渲染...');
                                    renderExistingFiles();
                                } else if (renderedItems.length !== existingOtherFiles.length) {
                                    console.warn('[getDraft] ⚠️ 渲染數量不一致：DOM', renderedItems.length, '個，existingOtherFiles', existingOtherFiles.length, '個，重新渲染...');
                                    renderExistingFiles();
                    } else {
                                    console.log('[getDraft] ✅ 渲染成功！DOM和existingOtherFiles數量一致：', renderedItems.length, '個檔案');
                                }
                            }
                        }, 100);
                    } else {
                        console.error('[getDraft] ❌ renderExistingFiles 函數不存在，無法渲染檔案清單');
                    }
                    
                    // 🔹 【防呆】延遲再次確認渲染成功（防止被其他邏輯清空）
                    setTimeout(() => {
                        // 🔹 使用全局變數，不再重複聲明
                        if (!uploadedFilesList) {
                            uploadedFilesList = document.getElementById('uploadedFilesList');
                        }
                        if (uploadedFilesList) {
                            const renderedItems = uploadedFilesList.querySelectorAll('.uploaded-file-item');
                            if (renderedItems.length === 0 && existingOtherFiles.length > 0) {
                            console.warn('[getDraft] 多檔列表渲染後被清空，重新渲染...');
                                if (typeof renderExistingFiles === 'function') {
                            renderExistingFiles();
                                }
                            } else {
                                console.log('[getDraft] 多檔列表渲染成功，顯示', renderedItems.length, '個檔案');
                            }
                        } else {
                            console.error('[getDraft] 找不到 uploadedFilesList 元素');
                        }
                    }, 200);
                    
                    // 🔹 【防呆】再次延遲確認（更長的延遲）
                    setTimeout(() => {
                        if (!uploadedFilesList) {
                            uploadedFilesList = document.getElementById('uploadedFilesList');
                        }
                        if (uploadedFilesList) {
                            const renderedItems = uploadedFilesList.querySelectorAll('.uploaded-file-item');
                            if (renderedItems.length === 0 && existingOtherFiles.length > 0) {
                            console.warn('[getDraft] 多檔列表再次被清空，重新渲染...');
                                if (typeof renderExistingFiles === 'function') {
                            renderExistingFiles();
                                }
                            } else {
                                console.log('[getDraft] 多檔列表最終確認，顯示', renderedItems.length, '個檔案');
                            }
                        }
                    }, 1000);
                    
                    // 🔹 【防呆】第三次延遲確認（更長的延遲，確保渲染成功）
                    setTimeout(() => {
                        if (!uploadedFilesList) {
                            uploadedFilesList = document.getElementById('uploadedFilesList');
                        }
                        if (uploadedFilesList) {
                            const renderedItems = uploadedFilesList.querySelectorAll('.uploaded-file-item');
                            if (renderedItems.length === 0 && existingOtherFiles.length > 0) {
                                console.error('[getDraft] 多檔列表第三次檢查仍被清空，強制重新渲染...');
                                if (typeof renderExistingFiles === 'function') {
                                    renderExistingFiles();
                                }
                            } else {
                                console.log('[getDraft] 多檔列表第三次檢查通過，顯示', renderedItems.length, '個檔案');
                            }
                        }
                    }, 2000);
                } else {
                    // 🔹 【防呆】如果 API 返回沒有檔案，但 existingOtherFiles 有值，保留現有值
                    if (existingOtherFiles.length > 0) {
                        console.warn('[getDraft] API 返回沒有檔案，但 existingOtherFiles 有值，保留現有檔案');
                        if (typeof renderExistingFiles === 'function') {
                            renderExistingFiles();
                        }
                } else {
                    console.log('[getDraft] 沒有多檔案資料');
                    }
                }
                
                console.log('[getDraft] 草稿載入完成');
                
                // 🔹 載入草稿後，顯示暫存提示（僅在未完成提交時顯示）
                const isSubmittedReadonly = window.PROJECT_UPLOAD_CONFIG?.isSubmittedReadonly;
                if (!isSubmittedReadonly) {
                    showDraftStatusAlert();
                    saveDraftStatusToStorage(true);
                }
            } else {
                console.log('[getDraft] 沒有草稿資料');
            }
        } catch (error) {
            console.error('[getDraft] 載入草稿失敗:', error);
        }
    }
    
    /**
     * 顯示預覽
     */
    function displayPreview(imagePath) {
        const previewArea = document.getElementById('previewArea');
        const previewContentContainer = document.getElementById('previewContentContainer');
        
        if (!previewArea && !previewContentContainer) return;
        
        if (!imagePath) {
            // 如果沒有圖片路徑，顯示空白預覽區
            if (previewContentContainer) {
                previewContentContainer.innerHTML = `
                    <div class="preview-empty">
                        <i class="fa-solid fa-image"></i>
                        <p>預覽區域</p>
                    </div>
                `;
            }
            return;
        }
        
        const ext = imagePath.split('.').pop().toLowerCase();
        // 🔹 直接使用 prosub_img 的值（已包含資料夾的相對路徑），不添加 ../ 前綴
        // 只有絕對 URL（http/https）才直接使用，否則使用相對路徑
        const previewUrl = imagePath.startsWith('http') ? imagePath : imagePath;
        
        // 容錯處理：圖片載入失敗時不顯示錯誤
        let previewHTML = '';
        if (ext === 'pdf') {
            previewHTML = `<div style="position: relative; width: 100%; height: 600px;">
                <iframe src="${previewUrl}" type="application/pdf" frameborder="0" style="width: 100%; height: 100%;" 
                        onerror="this.parentElement.innerHTML='<div class=\'text-center text-muted p-4\'><i class=\'fa-solid fa-exclamation-triangle\'></i><p class=\'mt-2\'>無法載入 PDF</p></div>';"></iframe>
                <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #f8f9fa; display: flex; align-items: center; justify-content: center; pointer-events: none; transition: opacity 0.3s;" id="previewPdfLoader">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
            </div>`;
        } else {
            previewHTML = `<img src="${previewUrl}" alt="預覽" style="max-width: 100%; height: auto; opacity: 0; transition: opacity 0.3s;" 
                onload="this.style.opacity='1'; const loader = this.nextElementSibling; if(loader) loader.style.opacity='0'; setTimeout(() => loader && loader.remove(), 300);"
                onerror="this.style.display='none'; this.nextElementSibling && this.nextElementSibling.remove();">
            <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #f8f9fa; display: flex; align-items: center; justify-content: center; pointer-events: none; transition: opacity 0.3s;">
                <div class="spinner-border text-primary" role="status"></div>
            </div>`;
        }
        
        if (previewContentContainer) {
            previewContentContainer.innerHTML = `<div id="previewContent" class="preview-content" style="position: relative;">${previewHTML}</div>`;
            
            // PDF iframe 載入完成後移除載入提示
            if (ext === 'pdf') {
                const iframe = previewContentContainer.querySelector('iframe');
                if (iframe) {
                    iframe.addEventListener('load', function() {
                        const loader = previewContentContainer.querySelector('#previewPdfLoader');
                        if (loader) {
                            loader.style.opacity = '0';
                            setTimeout(() => loader.remove(), 300);
                        }
                    });
                }
            }
        } else if (previewArea) {
            const previewContent = previewArea.querySelector('#previewContent');
            if (previewContent) {
                previewContent.innerHTML = previewHTML;
            }
        }
        
        // 更新縮放
        if (typeof updateZoom === 'function') {
            updateZoom();
        }
    }
    
    /**
     * 渲染已暫存檔案列表（不操作 file input）
     */
    function renderExistingFiles() {
        console.log('[renderExistingFiles] ========== 開始渲染已暫存檔案列表 ==========');
        console.log('[renderExistingFiles] existingOtherFiles 數量:', existingOtherFiles.length);
        console.log('[renderExistingFiles] existingOtherFiles 內容:', JSON.stringify(existingOtherFiles));
        
        // 🔹 使用全局變數，不再重複聲明
        if (!uploadedFilesList) {
            uploadedFilesList = document.getElementById('uploadedFilesList');
        }
        if (!uploadedFilesList) {
            console.error('[renderExistingFiles] ❌ 找不到 uploadedFilesList 元素');
            return;
        }
        
        // 🔹 【重要】如果PHP端已經渲染了檔案列表，先從DOM讀取並同步到existingOtherFiles
        const phpRenderedItems = uploadedFilesList.querySelectorAll('.uploaded-file-item[data-file-info]');
        if (phpRenderedItems.length > 0 && existingOtherFiles.length === 0) {
            console.log('[renderExistingFiles] 檢測到PHP端已渲染的檔案，從DOM同步到existingOtherFiles');
            existingOtherFiles = [];
            phpRenderedItems.forEach(item => {
                const fileInfoStr = item.getAttribute('data-file-info');
                if (fileInfoStr) {
                    try {
                        const fileInfo = JSON.parse(fileInfoStr);
                        existingOtherFiles.push(fileInfo);
                    } catch (e) {
                        const filePath = item.getAttribute('data-file-path');
                        const fileName = item.querySelector('.file-name')?.textContent || '';
                        if (filePath) {
                            existingOtherFiles.push({
                                name: fileName,
                                path: filePath
                            });
                        }
                    }
                }
            });
            console.log('[renderExistingFiles] 從DOM同步後，existingOtherFiles 數量:', existingOtherFiles.length);
        }
        
        // 去重：確保 existingOtherFiles 中沒有重複的檔案（根據 path）
        const uniqueFiles = [];
        const seenPaths = new Set();
        existingOtherFiles.forEach(file => {
            const filePath = typeof file === 'string' ? file : (file.path || '');
            if (filePath && !seenPaths.has(filePath)) {
                seenPaths.add(filePath);
                uniqueFiles.push(file);
            }
        });
        existingOtherFiles = uniqueFiles;
        
        console.log('[renderExistingFiles] 去重後，existingOtherFiles 數量:', existingOtherFiles.length);
        
        // 🔹 若已不存在任何檔案（包含最後一個被刪除的情況），清空並隱藏整個清單
        if (existingOtherFiles.length === 0) {
            uploadedFilesList.innerHTML = '';
            uploadedFilesList.style.display = 'none';
            uploadedFilesList.style.visibility = 'hidden';
            console.log('[renderExistingFiles] 沒有已暫存檔案，已清空列表並隱藏區塊');
            return;
        }
        
        console.log('[renderExistingFiles] ✅ 開始渲染已暫存檔案清單，檔案數:', existingOtherFiles.length);
        
        // 渲染每個已暫存檔案（列表模式：檔名 + 檔案類型下拉 + 下載/刪除按鈕）
        const getBasename = (path) => {
            if (!path) return '';
            if (typeof window.basename === 'function') {
                return window.basename(path);
            }
            const parts = path.split('/');
            return parts[parts.length - 1] || path;
        };
        
        const urlParams = new URLSearchParams(window.location.search);
        const isEditMode = urlParams.has('edit');
        const isViewMode = urlParams.has('view');
        const isFinalLocked = uploadedFilesList.closest('.file-upload-section')?.querySelector('input[disabled]') !== null;
        const isSubmittedReadonly = window.PROJECT_UPLOAD_CONFIG?.isSubmittedReadonly === true;
        
        // 與 renderSelectedFiles 一致：每列都要有檔案類型下拉（維持在畫面上，不閃一下就不見）
        const allowedTypes = getAllowedFileTypes();
        const fileTypeSelectHtml = (currentValue, fileKey) => {
            const opts = '<option value="">請選擇</option>' + allowedTypes.map(t => {
                const label = (typeof FILE_TYPE_LABELS !== 'undefined' ? FILE_TYPE_LABELS[t] : null) || t;
                const sel = (currentValue === t) ? ' selected' : '';
                return `<option value="${escapeHtml(t)}"${sel}>${escapeHtml(label)}</option>`;
            }).join('');
            return `<select class="file-type-select form-select form-select-sm" data-file-key="${escapeHtml(fileKey)}" data-row-type="existing" style="min-width: 100px;" required>${opts}</select>`;
        };
        
        let listHTML = existingOtherFiles.map((file, index) => {
            const fileName = file.original_name || file.name || getBasename(file.path || '');
            const filePath = file.path || '';
            const fileKey = filePath;
            const fileTypeValue = file.file_type ?? '';
            const fileUrl = filePath ? '../' + filePath : '';
            const showDropdown = !isViewMode && !isFinalLocked && !isSubmittedReadonly;
            
            return `
                <div class="uploaded-file-item d-flex align-items-center justify-content-between py-2 border-bottom" 
                     data-file-path="${escapeHtml(filePath)}" 
                     data-file-index="${index}"
                     data-file-info='${('' + JSON.stringify(file)).replace(/'/g, '&#39;')}'>
                    <div class="d-flex align-items-center flex-grow-1" style="min-width: 0; overflow: hidden;">
                        <i class="fa-solid fa-file me-2" style="flex-shrink: 0;"></i>
                        <span class="file-name text-truncate" style="flex: 1;">${escapeHtml(fileName)}</span>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        ${showDropdown ? fileTypeSelectHtml(fileTypeValue, fileKey) : ''}
                        ${filePath ? `
                            <a href="${escapeHtml(fileUrl)}" target="_blank" class="btn btn-success btn-sm" download title="下載">下載</a>
                        ` : ''}
                        ${!isViewMode && !isFinalLocked ? `
                        <button type="button" class="btn btn-danger btn-sm remove-file-btn" data-file-index="${index}" title="刪除">刪除</button>
                        ` : ''}
                    </div>
                </div>
            `;
        }).join('');
        
        uploadedFilesList.innerHTML = listHTML;
        uploadedFilesList.style.display = '';
        uploadedFilesList.style.visibility = 'visible';
        uploadedFilesList.setAttribute('data-rendered-by', 'js');
        console.log('[renderExistingFiles] ✅ 已更新DOM，渲染了', existingOtherFiles.length, '個檔案（含檔案類型下拉）');
        
        // 🔹 檔案類型下拉委派（與 otherFilesList 一致，維持在畫面上可操作）
        if (!uploadedFilesList._fileTypeChangeHandler) {
            uploadedFilesList._fileTypeChangeHandler = function(e) {
                const select = e.target.closest('select.file-type-select');
                if (!select) return;
                const fileKey = select.getAttribute('data-file-key');
                const rowType = select.getAttribute('data-row-type');
                const value = (select.value || '').trim();
                if (rowType === 'existing') {
                    const idx = existingOtherFiles.findIndex(f => getFileKey(f, true) === fileKey);
                    if (idx !== -1) existingOtherFiles[idx].file_type = value;
                }
            };
            uploadedFilesList.addEventListener('change', uploadedFilesList._fileTypeChangeHandler);
        }
        
        // 綁定刪除按鈕事件
        uploadedFilesList.querySelectorAll('.remove-file-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                const fileIndex = parseInt(this.getAttribute('data-file-index'));
                const fileItem = this.closest('.uploaded-file-item');
                
                if (!fileItem) return;
                
                    const filePath = fileItem.getAttribute('data-file-path');
                const fileName = fileItem.querySelector('.file-name')?.textContent || filePath;
                
                const confirmed = await showConfirmDialog(`確定要移除「${fileName}」這個檔案嗎？`, '確認刪除');
                if (!confirmed) return;
                
                // 從 existingOtherFiles 中移除
                existingOtherFiles = existingOtherFiles.filter(file => {
                    const fPath = typeof file === 'string' ? file : (file.path || '');
                    return fPath !== filePath;
                });
                
                    console.log('[renderExistingFiles] 已移除檔案:', filePath);
                    console.log('[renderExistingFiles] 剩餘檔案數:', existingOtherFiles.length);
                    
                    // 重新渲染列表
                    renderExistingFiles();
            });
        });
        
        console.log('[renderExistingFiles] ✅ 已暫存檔案清單渲染完成，共', existingOtherFiles.length, '個檔案');
    }
    

    /**
     * 重置文件輸入框
     */
    function resetFileInput() {
        const fileInput = document.getElementById('posterFileInput');
        const noFileBtn = document.getElementById('noFileBtn');
        if (fileInput) fileInput.value = '';
        if (noFileBtn) {
            noFileBtn.textContent = '未選擇任何檔案';
            noFileBtn.classList.remove('has-file');
            noFileBtn.disabled = true;
        }
        currentFile = null;
    }

    /**
     * 重置表單到初始狀態（完全清空）
     * 重要：只能在「提交成功」後執行；暫存成功後不可清空表單
     * 🔹 編輯模式下不應調用此函數（編輯模式應保留資料）
     */
    function resetFormToInitial() {
        // 🔹 檢查是否為編輯模式，編輯模式下不應重置表單
        const urlParams = new URLSearchParams(window.location.search);
        const isEditMode = urlParams.has('edit');
        
        // 🔹 檢查是否為已提交唯讀狀態
        const isSubmittedReadonly = window.PROJECT_UPLOAD_CONFIG?.isSubmittedReadonly === true;
        
        if (isEditMode) {
            console.warn('[resetFormToInitial] 編輯模式下不應重置表單，跳過重置');
            return;
        }
        
        if (isSubmittedReadonly) {
            console.warn('[resetFormToInitial] 已提交唯讀狀態下不應重置表單，跳過重置');
            return;
        }
        
        console.log('[resetFormToInitial] 開始重置表單到初始狀態...');
        
        // 0) 清空 prosubID 隱藏字段（提交後不應保留 ID）
        const prosubIDInput = document.getElementById('prosubID');
        if (prosubIDInput) {
            prosubIDInput.value = '';
            console.log('[resetFormToInitial] 已清空 prosubID');
        }
        
        // 1) 清空專題簡介 textarea
        const projectIntro = document.getElementById('projectIntro');
        if (projectIntro) {
            projectIntro.value = '';
            // 清空原始值標記
            projectIntro.removeAttribute('data-original-value');
            projectIntro.removeAttribute('data-initial-value');
            console.log('[resetFormToInitial] 已清空專題簡介');
        }
        
        // 2) 清空單一檔案 input（並把檔名顯示改回「未選擇任何檔案」）
        const fileInput = document.getElementById('posterFileInput');
        if (fileInput) {
            fileInput.value = '';
        }
        
        const noFileBtn = document.getElementById('noFileBtn');
        if (noFileBtn) {
            noFileBtn.textContent = '未選擇任何檔案';
            noFileBtn.classList.remove('has-file');
            noFileBtn.disabled = true;
            console.log('[resetFormToInitial] 已清空單一檔案顯示');
        }
        
        // 3) 清空多檔案 input（清空檔案清單/預覽區）
        const multipleFilesInput = document.getElementById('multipleFilesInput');
        if (multipleFilesInput) {
            multipleFilesInput.value = '';
        }
        
        // 清空已暫存檔案列表顯示
        // 🔹 使用全局變數，不再重複聲明
        if (!uploadedFilesList) {
            uploadedFilesList = document.getElementById('uploadedFilesList');
        }
        if (uploadedFilesList) {
            uploadedFilesList.innerHTML = '';
            console.log('[resetFormToInitial] 已清空多檔案列表');
        }
        
        // 清空新選擇的檔案列表顯示
        const selectedFilesList = document.getElementById('selectedFilesList');
        if (selectedFilesList) {
            selectedFilesList.style.display = 'none';
            const selectedFilesContainer = document.getElementById('selectedFilesContainer');
            if (selectedFilesContainer) {
                selectedFilesContainer.innerHTML = '';
            }
            console.log('[resetFormToInitial] 已清空新選擇檔案列表');
        }
        
        // 🔹 清空 otherFilesPanel 和 otherFilesEmpty 顯示
        const otherFilesPanel = document.getElementById('otherFilesPanel');
        if (otherFilesPanel) {
            otherFilesPanel.style.display = 'none';
            const otherFilesList = document.getElementById('otherFilesList');
            if (otherFilesList) {
                otherFilesList.innerHTML = '';
            }
            const otherFilesSummary = document.getElementById('otherFilesSummary');
            if (otherFilesSummary) {
                otherFilesSummary.textContent = '';
            }
        }
        const otherFilesEmpty = document.getElementById('otherFilesEmpty');
        if (otherFilesEmpty) {
            otherFilesEmpty.style.display = '';
        }
        const otherFilesInput = document.getElementById('otherFilesInput');
        if (otherFilesInput) {
            otherFilesInput.value = '';
        }
        console.log('[resetFormToInitial] 已清空 otherFilesPanel');
        
        // 4) 清空前端暫存用的 arrays/state
        currentFile = null;
        hasFileSelected = false;
        selectedOtherFiles = [];
        existingOtherFiles = [];
        console.log('[resetFormToInitial] 已清空前端暫存變數');
        
        // 5) 移除預覽按鈕（如果存在）- 使用安全呼叫
        if (typeof removePreviewButton === 'function') {
            removePreviewButton();
        } else if (typeof window.removePreviewButton === 'function') {
            window.removePreviewButton();
        } else {
            // 如果函數不存在，直接移除預覽按鈕元素
            const previewBtn = document.getElementById('previewPosterBtn');
            if (previewBtn) {
                previewBtn.remove();
            }
        }
        console.log('[resetFormToInitial] 已移除預覽按鈕');
        
        // 清空預覽區域（若有預覽 iframe / img / file list，全部移除或還原預設狀態）
        const previewArea = document.getElementById('previewArea');
        const previewContentContainer = document.getElementById('previewContentContainer');
        
        if (previewContentContainer) {
            previewContentContainer.innerHTML = `
                <div class="preview-empty">
                    <i class="fa-solid fa-image"></i>
                    <p>預覽區域</p>
                </div>
            `;
        } else if (previewArea) {
            const previewContent = previewArea.querySelector('#previewContent');
            if (previewContent) {
                previewContent.innerHTML = `
                    <div class="preview-empty">
                        <i class="fa-solid fa-image"></i>
                        <p>預覽區域</p>
                    </div>
                `;
            }
        }
        console.log('[resetFormToInitial] 已清空預覽區域');
        
        // 重置縮放（如果存在）
        if (typeof zoomLevel !== 'undefined') {
            zoomLevel = 100;
            if (typeof updateZoomControls === 'function') {
                updateZoomControls();
            }
            if (typeof updateZoom === 'function') {
                updateZoom();
            }
        }
        
        console.log('[resetFormToInitial] 表單已完全重置到初始狀態');
    }

    /**
     * 重置縮放（只重置圖片放大縮小）
     */
    function resetForm() {
        // 只重置縮放級別，不影響文件或表單內容
        zoomLevel = 100;
        updateZoomControls();
        updateZoom();
    }

    /**
     * 移除文件（只移除上傳的圖檔）
     */
    function removeFile() {
        // 只移除文件相關內容，不重置縮放
        resetFileInput();
        
        // 清空預覽區域
        const previewArea = document.getElementById('previewArea');
        if (previewArea) {
            const previewContainer = document.getElementById('previewContentContainer');
            if (previewContainer) {
                previewContainer.innerHTML = '<div class="preview-empty"><i class="fa-solid fa-image"></i><p>預覽區域</p></div>';
            }
        }

        // 移除文件引用
        currentFile = null;
        
        // 重置所有文件選擇標記，允許再次選擇文件
        hasFileSelected = false;
        fileSelectedLock = false;
        
        // 注意：不移除縮放級別，保持用戶設定的縮放
    }

    /**
     * 處理暫存
     */
    /**
     * 計算所有選擇檔案的總大小
     */
    function calculateTotalFileSize() {
        let totalSize = 0;
        
        // 計算海報大小
        if (currentFile) {
            totalSize += currentFile.size;
        }
        
        // 計算多個檔案大小（每項為 { file, file_type }）
        selectedOtherFiles.forEach(item => {
            totalSize += (item.file && item.file.size) ? item.file.size : 0;
        });
        
        return totalSize;
    }
    
    /**
     * 檢查是否每個檔案都已選擇檔案類型（多檔案區塊）
     * @returns {{ ok: boolean, message?: string }}
     */
    function validateAllFileTypesSelected() {
        // 檔案類型已改由副檔名自動判斷，前端不再要求人工選擇
        return { ok: true };
    }

    /**
     * 檢查檔案總大小是否超過限制
     * 🔹 【修復】允許沒有檔案時也能暫存（總大小為0時直接通過）
     */
    async function checkFileSizeLimit() {
        const totalSize = calculateTotalFileSize();
        
        // 如果沒有檔案，直接通過（允許空白狀態暫存）
        if (totalSize === 0) {
            return true;
        }
        
        // 後端限制為 100MB，前端預留一些緩衝（95MB）
        const maxSize = 150 * 1024 * 1024; // 95MB
        
        if (totalSize > maxSize) {
            const totalSizeMB = (totalSize / 1024 / 1024).toFixed(2);
            await showAlertDialog(
                `檔案總大小（${totalSizeMB} MB）超過限制（1100 MB），請移除部分檔案後再試`,
                'warning'
            );
            return false;
        }
        
        return true;
    }

    async function handleSaveDraft() {
        // 防止重複點擊
        if (isSaving) {
            return;
        }
        
        // 🔹 立即設置暫存狀態，防止重複進入
        isSaving = true;
        const saveBtn = document.getElementById('saveDraftBtn');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fa-solid fa-save"></i> 暫存中...';
        }

        // 定義重置暫存狀態的輔助函數
        const resetSavingState = () => {
            isSaving = false;
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fa-solid fa-save"></i> 暫存';
            }
        };

        const form = document.getElementById('uploadProjectForm');
        if (!form) {
            resetSavingState();
            return;
        }

        // 檢查檔案總大小
        const sizeCheck = await checkFileSizeLimit();
        if (!sizeCheck) {
            resetSavingState();
            return;
        }

        // 🔹 若有任一多檔案區塊的檔案未選類型，阻擋暫存並提示
        const displayListForDraft = getDisplayFileList();
        if (displayListForDraft.all.length > 0) {
            const typeCheck = validateAllFileTypesSelected();
            if (!typeCheck.ok) {
                await showAlertDialog(typeCheck.message || '請先為每個檔案選擇檔案類型', 'warning', 2500);
                resetSavingState();
                return;
            }
        }

        // 統一從 DOM 元素讀取簡介值（與提交邏輯一致）
        const projectIntroElement = getIntroEl();
        const introText = projectIntroElement ? (projectIntroElement.value || '') : '';

        const formData = new FormData(form);
        formData.append('is_draft', '1');
        
        // 確保簡介值被正確添加到 FormData（使用與後端一致的 key：project_intro）
        if (formData.has('project_intro')) {
            formData.delete('project_intro');
        }
        formData.append('project_intro', introText.trim());

        // 🔹 暫存時也確保海報有選就送出（與提交邏輯一致，從表單 input 或 currentFile 取）
        const posterInput = form.querySelector('input[name="poster"]');
        const posterFile = currentFile || (posterInput && posterInput.files && posterInput.files[0]);
        if (posterFile) {
            if (formData.has('poster')) formData.delete('poster');
            formData.append('poster', posterFile);
        }
        
        // 🔹 檢查是否為編輯模式
        const urlParams = new URLSearchParams(window.location.search);
        const isEditMode = urlParams.has('edit');
        
        if (isEditMode) {
            // 🔹 編輯模式：使用新的 payload 格式（keep/delete/new）
            // 🔹 【修復刪除後暫存不生效】必須使用更新後的 existingOtherFiles（已真正移除刪除的檔案）
            // 1. 要保留的舊檔案（直接使用 existingOtherFiles，因為已經真正移除了刪除的檔案）
            const keepExistingFiles = existingOtherFiles; // 已經真正移除了刪除的檔案
            
            // 2. 要刪除的舊檔案 key 列表（用於後端驗證和實體檔案刪除）
            const deleteKeysArray = Array.from(deletedExistingKeys);
            
            // 🔹 【修復清除全部】如果 shouldClearAllFiles 為 true，添加 clear_all=1 標誌
            // 同時確保 keep_existing_files 為空，delete_existing_keys 包含所有舊文件
            if (shouldClearAllFiles) {
                formData.append('clear_all', '1');
                // 確保 keep_existing_files 為空
                formData.append('keep_existing_files', JSON.stringify([]));
                // 確保 delete_existing_keys 包含所有舊文件的 key
                const allExistingKeys = existingOtherFiles.map(f => {
                    const filePath = f.path || '';
                    return filePath || getFileKey(f, true);
                }).filter(key => key);
                formData.append('delete_existing_keys', JSON.stringify(allExistingKeys));
                console.log('[handleSaveDraft] 編輯模式：清除全部操作，clear_all=1, keep_existing_files=[], delete_existing_keys:', allExistingKeys.length);
            } else {
                formData.append('keep_existing_files', JSON.stringify(keepExistingFiles));
                formData.append('delete_existing_keys', JSON.stringify(deleteKeysArray));
            }
            
            // 3. 新選擇的檔案（每項為 { file, file_type }），送出 file 與對應的 file_type
            selectedOtherFiles.forEach(item => {
                formData.append('new_files[]', item.file);
                formData.append('new_file_types[]', item.file_type || '');
            });
            
            // 🔹 【Debug 必做】前端送出 payload 的 other_files（必須不含被刪的 file_url）
            console.log('[handleSaveDraft] 🔍 前端送出 payload:');
            console.log('[handleSaveDraft] - keep_existing_files 數量:', keepExistingFiles.length);
            console.log('[handleSaveDraft] - keep_existing_files 內容:', JSON.stringify(keepExistingFiles));
            console.log('[handleSaveDraft] - delete_existing_keys 數量:', deleteKeysArray.length);
            console.log('[handleSaveDraft] - delete_existing_keys 內容:', JSON.stringify(deleteKeysArray));
            console.log('[handleSaveDraft] - new_files 數量:', selectedOtherFiles.length);
            console.log('[handleSaveDraft] - clear_all:', shouldClearAllFiles);
        } else {
            // 非編輯模式：使用 kept_files_json 與新檔案合併後再存；每個檔案送對應的 file_type
            selectedOtherFiles.forEach(item => {
                formData.append('other_files[]', item.file);
                formData.append('file_types[]', item.file_type || '');
            });
        
        // 🔹 【關鍵修復】添加要保留的舊檔案列表（刪除後的列表），使用 kept_files_json 參數名
        // 必須使用 getDisplayFileList().existing（過濾掉已刪除的檔案），而不是整個 existingOtherFiles
        // 這樣暫存時才能正確更新 DB，避免刪除的檔案在 F5 後復活
        const displayList = getDisplayFileList();
        const keptFiles = displayList.existing; // 只包含未被刪除的檔案
        formData.append('kept_files_json', JSON.stringify(keptFiles));
        
        console.log('[handleSaveDraft] 非編輯模式：kept_files_json 數量:', keptFiles.length, '（已過濾刪除的檔案）');
        console.log('[handleSaveDraft] deletedExistingKeys 數量:', deletedExistingKeys.size);
        }

        // 🔹 狀態已在函數開頭設置


        try {
            const response = await fetch(`${API_BASE}?do=save_draft`, {
                method: 'POST',
                body: formData
            });

            // 使用 .text() 再解析，避免非 JSON 內容導致解析失敗
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (parseError) {
                console.error('JSON 解析失敗，原始回應:', text);
                console.error('解析錯誤:', parseError);
                await showAlertDialog('暫存失敗：後端回傳了非 JSON 內容', 'error');
                resetSavingState();
                return;
            }

            if (data.success) {
                // 兼容新舊格式：data.prosub_ID 或 data.data.prosub_ID
                const prosub_ID = data.data?.prosub_ID || data.prosub_ID;
                if (prosub_ID) {
                    const prosubID = document.getElementById('prosubID');
                    if (prosubID) {
                        prosubID.value = prosub_ID;
                    }
                }
                
                console.log('[handleSaveDraft] ✅ 暫存成功，prosub_ID:', prosub_ID);
                console.log('[handleSaveDraft] 暫存前 selectedOtherFiles 數量:', selectedOtherFiles.length);
                console.log('[handleSaveDraft] 暫存前 existingOtherFiles 數量:', existingOtherFiles.length);
                
                // 🔹 檢查是否為編輯模式（urlParams 已在函數開頭聲明，這裡直接使用）
                // urlParams 和 isEditMode 已在上面聲明（第 3581-3582 行），不需要重複聲明
                
                if (isEditMode) {
                    // 🔹 【修復文件數量異常增加】編輯模式：暫存成功後，直接使用後端返回的 other_files 更新 existingOtherFiles，並保留每筆 file_type
                    const returnedOtherFiles = data.data?.other_files || [];
                    console.log('[handleSaveDraft] 後端返回的 other_files 數量:', returnedOtherFiles.length);
                    console.log('[handleSaveDraft] 後端返回的 other_files 內容:', JSON.stringify(returnedOtherFiles));
                    
                    const prevExisting = existingOtherFiles;
                    existingOtherFiles = returnedOtherFiles.map(file => {
                        const path = typeof file === 'string' ? file : (file.path || '');
                        const kept = prevExisting.find(f => (f.path || '') === path);
                        const preservedType = (kept && (kept.file_type != null && kept.file_type !== '')) ? kept.file_type : '';
                        if (typeof file === 'string') {
                            return {
                                original_name: basename(file),
                                name: basename(file),
                                path: file,
                                type: '',
                                uploaded_at: '',
                                public: true,
                                file_type: preservedType
                            };
                        }
                        const fromApi = (file.file_type != null && file.file_type !== '') ? file.file_type : '';
                        return {
                            original_name: file.original_name || file.name || basename(file.path || ''),
                            name: file.original_name || file.name || basename(file.path || ''),
                            path: file.path || '',
                            type: file.type || '',
                            uploaded_at: file.uploaded_at || file.upload_time || '',
                            public: file.public !== undefined ? file.public : true,
                            file_type: fromApi || preservedType
                        };
                    });
                    
                    // 2. 清空當前選擇的文件（但不影響已上傳的文件）
                    currentFile = null;
                    hasFileSelected = false;
                    
                    // 清空海報文件選擇 input，允許重新選擇
                    const posterFileInput = document.getElementById('posterFileInput');
                    if (posterFileInput) {
                        posterFileInput.value = '';
                    }
                    
                    // 3. 清空 selectedOtherFiles（檔案已上傳到服務器）
                    selectedOtherFiles = [];
                    
                    // 清空文件選擇 input，允許重新選擇
                    const otherFilesInput = document.getElementById('otherFilesInput');
                    if (otherFilesInput) {
                        otherFilesInput.value = '';
                    }
                    
                    // 4. 清空 deletedExistingKeys（因為已經使用後端返回的最新數據）
                    deletedExistingKeys.clear();
                    
                    // 5. 清空 shouldClearAllFiles 標記
                    if (shouldClearAllFiles) {
                        shouldClearAllFiles = false;
                        console.log('[handleSaveDraft] 編輯模式：已清除 shouldClearAllFiles 標記');
                    }
                    
                    console.log('[handleSaveDraft] ✅ 已使用後端返回的數據更新 existingOtherFiles，數量:', existingOtherFiles.length);
                    
                    // 6. 暫存後只顯示已保存的文件列表（在 uploadedFilesList 中）
                    // 隱藏 otherFilesPanel，顯示 uploadedFilesList
                    const otherFilesPanel = document.getElementById('otherFilesPanel');
                    const otherFilesEmpty = document.getElementById('otherFilesEmpty');
                    if (otherFilesPanel) {
                        otherFilesPanel.style.display = 'none';
                    }
                    if (otherFilesEmpty) {
                        otherFilesEmpty.style.display = 'none';
                    }
                    
                    // 顯示已保存的文件列表
                    if (typeof renderExistingFiles === 'function') {
                        console.log('[handleSaveDraft] 調用 renderExistingFiles() 顯示已保存的文件');
                        renderExistingFiles();
                    }
                } else {
                    // 非編輯模式：暫存成功後，直接使用後端返回的 other_files 更新 existingOtherFiles 並重新 render
                // 1. 清空當前選擇的文件（但不影響已上傳的文件）
                currentFile = null;
                hasFileSelected = false;
                
                // 清空海報文件選擇 input，允許重新選擇
                const posterFileInput = document.getElementById('posterFileInput');
                if (posterFileInput) {
                    posterFileInput.value = '';
                }
                
                // 2. 清空 selectedOtherFiles（檔案已上傳到服務器）
                    selectedOtherFiles = [];
                    
                // 清空文件選擇 input，允許重新選擇
                const otherFilesInput = document.getElementById('otherFilesInput');
                if (otherFilesInput) {
                    otherFilesInput.value = '';
                }
                
                // 🔹 【關鍵修復】直接使用後端返回的 other_files 更新 existingOtherFiles，並保留每筆 file_type（不應清掉下拉選單）
                const returnedOtherFiles = data.data?.other_files || [];
                console.log('[handleSaveDraft] 後端返回的 other_files 數量:', returnedOtherFiles.length);
                console.log('[handleSaveDraft] 後端返回的 other_files 內容:', JSON.stringify(returnedOtherFiles));
                
                const prevExisting = existingOtherFiles;
                existingOtherFiles = returnedOtherFiles.map(file => {
                    const path = typeof file === 'string' ? file : (file.path || '');
                    const kept = prevExisting.find(f => (f.path || '') === path);
                    const preservedType = (kept && (kept.file_type != null && kept.file_type !== '')) ? kept.file_type : '';
                    if (typeof file === 'string') {
                        return {
                            original_name: basename(file),
                            name: basename(file),
                            path: file,
                            type: '',
                            uploaded_at: '',
                            public: true,
                            file_type: preservedType
                        };
                    }
                    return {
                        original_name: file.original_name || file.name || basename(file.path || ''),
                        name: file.original_name || file.name || basename(file.path || ''),
                        path: file.path || '',
                        type: file.type || '',
                        uploaded_at: file.uploaded_at || file.upload_time || '',
                        public: file.public !== undefined ? file.public : true,
                        file_type: (file.file_type != null && file.file_type !== '') ? file.file_type : preservedType
                    };
                });
                
                console.log('[handleSaveDraft] ✅ 已使用後端返回的數據更新 existingOtherFiles，數量:', existingOtherFiles.length);
                
                // 3. 清空 deletedExistingKeys（因為已經使用後端返回的最新數據，刪除的檔案已從 DB 移除）
                deletedExistingKeys.clear();
                console.log('[handleSaveDraft] 已清空 deletedExistingKeys（刪除的檔案已從 DB 移除）');
                
                // 4. 暫存後只顯示已保存的文件列表（在 uploadedFilesList 中）
                // 隱藏 otherFilesPanel，顯示 uploadedFilesList
                const otherFilesPanel = document.getElementById('otherFilesPanel');
                const otherFilesEmpty = document.getElementById('otherFilesEmpty');
                if (otherFilesPanel) {
                    otherFilesPanel.style.display = 'none';
                }
                if (otherFilesEmpty) {
                    otherFilesEmpty.style.display = 'none';
                }
                
                // 顯示已保存的文件列表
                if (typeof renderExistingFiles === 'function') {
                    console.log('[handleSaveDraft] 調用 renderExistingFiles() 顯示已保存的文件');
                    renderExistingFiles();
                }
                }
                
                // 🔹 【防呆】再次延遲確認（更長的延遲）
                setTimeout(async () => {
                    if (!uploadedFilesList) {
                        uploadedFilesList = document.getElementById('uploadedFilesList');
                    }
                    if (uploadedFilesList) {
                        const renderedItems = uploadedFilesList.querySelectorAll('.uploaded-file-item');
                        if (renderedItems.length === 0 && existingOtherFiles.length > 0) {
                            console.error('[handleSaveDraft] ❌ 文件列表仍未渲染，強制重新載入草稿...');
                            await loadDraft();
                            renderExistingFiles();
                            // 再次調用 renderSelectedFiles 確保顯示正確
                            if (typeof renderSelectedFiles === 'function') {
                                renderSelectedFiles();
                            }
                        } else {
                            console.log('[handleSaveDraft] ✅ 文件列表最終確認，顯示', renderedItems.length, '個檔案');
                            // 確保 otherFilesPanel 也正確顯示
                            if (typeof renderSelectedFiles === 'function') {
                                renderSelectedFiles();
                            }
                        }
                    }
                }, 2000);
                
                // 🔹 【防呆】確保文件選擇按鈕可以正常使用
                setTimeout(() => {
                    ensureFileButtonEnabled();
                }, 100);
                
                // 顯示成功提示（不等待，立即恢復按鈕狀態）
                showAlertDialog('已暫存', 'success', 2000);
                
                // 🔹 顯示「已暫存」提示並保存狀態（僅在未完成提交時顯示）
                const isSubmittedReadonly = window.PROJECT_UPLOAD_CONFIG?.isSubmittedReadonly;
                if (!isSubmittedReadonly) {
                    showDraftStatusAlert();
                    saveDraftStatusToStorage(true);
                }
            } else {
                await showAlertDialog(data.message || '暫存失敗，請稍後再試', 'error');
                resetSavingState();
            }
        } catch (error) {
            console.error('暫存錯誤:', error);
            await showAlertDialog('暫存失敗，請稍後再試', 'error');
        } finally {
            // 無論成功或失敗，都要恢復按鈕狀態
            isSaving = false;
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fa-solid fa-save"></i> 暫存';
            }
        }
    }

    /**
     * 處理表單提交
     * 注意：一組只能有一筆提交記錄，如果已有則提示是否覆蓋
     */
    async function handleFormSubmit(e) {
        console.log('[handleFormSubmit] ========== 開始提交 ==========');
        console.log('[handleFormSubmit] 事件:', e);
        console.log('[handleFormSubmit] isSubmitting:', isSubmitting);
        
        e.preventDefault();
        e.stopPropagation(); // 防止事件冒泡

        // 防止重複提交
        if (isSubmitting) {
            console.log('[handleFormSubmit] 正在提交中，跳過重複提交');
            return;
        }

        // 🔹 立即設置提交狀態
        isSubmitting = true;
        let submitBtn = document.getElementById('submitBtn');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-upload"></i> 提交中...';
        }

        // 定義重置提交狀態的輔助函數
        const resetSubmitState = () => {
            isSubmitting = false;
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-upload"></i> ' + (isEditMode ? '提交' : (window.PROJECT_UPLOAD_CONFIG?.draft_ID ? '重新提交' : '提交'));
            }
        };

        // 🔹 檢查是否已提交（已提交則無法再次提交）
        // 注意：期限內狀態為「未審核」時，允許重新提交（需確認覆蓋）
        const isSubmittedReadonly = window.PROJECT_UPLOAD_CONFIG?.isSubmittedReadonly === true;
        
        if (isSubmittedReadonly) {
            await showAlertDialog('您已提交專題，無法再次提交。', 'error');
            resetSubmitState();
            return;
        }

        // 獲取表單元素（如果 e.target 是按鈕，則獲取表單）
        const form = e.target.closest('form') || document.getElementById('uploadProjectForm');
        if (!form) {
            console.error('[handleFormSubmit] 找不到表單');
            await showAlertDialog('找不到表單', 'error');
            resetSubmitState();
            return;
        }
        
        console.log('[handleFormSubmit] 找到表單:', form.id);
        
        // 🔹 從「當前表單」內讀取專題簡介，避免 AJAX 動態載入時取到其他區塊的空欄位
        const projectIntroElement = form.querySelector('textarea[name="project_intro"]') || getIntroEl();
        if (!projectIntroElement) {
            await showAlertDialog('找不到專題簡介欄位', 'error');
            resetSubmitState();
            return;
        }
        
        // 讀取簡介值（統一使用 DOM 元素的值）
        const introText = projectIntroElement.value || '';
        
        // Debug：確認取到的值
        console.log('[handleFormSubmit] desc=', introText, 'trim length=', introText?.trim().length);
        
        // 判斷是否為編輯模式
        const urlParams = new URLSearchParams(window.location.search);
        const isEditMode = urlParams.has('edit');
        
        // 🔹 提交必填驗證：簡介/海報（多個檔案改為可選）
        const noFileBtn = document.getElementById('noFileBtn');
        // 🔹 檢查是否有已存在的海報（通過 has-file 類或按鈕文字判斷）
        const hasExistingPoster = noFileBtn && (
            noFileBtn.classList.contains('has-file') || 
            (noFileBtn.textContent && noFileBtn.textContent.trim() !== '未選擇任何檔案')
        );
        
        // 🔹 【關鍵修復】使用實際待提交清單判斷（過濾掉已刪除的檔案）
        const displayList = getDisplayFileList();
        const pendingFiles = displayList.all; // 包含：保留的舊檔案 + 新選擇的檔案（排除已刪除的）
        const totalPendingFiles = pendingFiles.length;
        
        console.log('[handleFormSubmit] 待提交檔案清單：', {
            '保留的舊檔案': displayList.existing.length,
            '新選擇的檔案': displayList.new.length,
            '總數': totalPendingFiles
        });
        
        // 驗證專題簡介（必填）
        if (!introText || !introText.trim()) {
            await showAlertDialog('請填寫專題簡介', 'warning');
            projectIntroElement.focus();
            projectIntroElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            resetSubmitState();
            return;
        }
        
        // 🔹 驗證海報（必填：新選擇的檔案或已存在的檔案）
        // 編輯模式下：如果已有海報（hasExistingPoster），不要求必須上傳新海報
        // 新增模式下：必須有新選擇的檔案或已存在的檔案
        if (!currentFile && !hasExistingPoster) {
            await showAlertDialog('請上傳直式海報', 'warning');
            resetSubmitState();
            return;
        }
        
        // 🔹 編輯模式下，如果已有海報且沒有選擇新檔案，允許提交（不要求必須上傳）
        if (isEditMode && hasExistingPoster && !currentFile) {
            console.log('[handleFormSubmit] 編輯模式：已有海報，不要求必須上傳新海報');
        }
        
        // 🔹 【多個檔案改為可選】不再強制要求至少1個檔案；若有檔案則每個都須選類型
        if (totalPendingFiles > 0) {
            const typeCheck = validateAllFileTypesSelected();
            if (!typeCheck.ok) {
                await showAlertDialog(typeCheck.message || '請先為每個檔案選擇檔案類型', 'warning', 2500);
                resetSubmitState();
                return;
            }
        }

        // 創建 FormData
        const formData = new FormData(form);
        
        // 確保簡介值被正確添加到 FormData（使用與後端一致的 key：project_intro）
        // 先刪除可能存在的舊值，再添加新值
        if (formData.has('project_intro')) {
            formData.delete('project_intro');
        }
        formData.append('project_intro', introText.trim());
        
        // Debug：確認 FormData 中的值
        console.log('[handleFormSubmit] FormData project_intro=', formData.get('project_intro'));
        
        // 檢查檔案總大小
        const sizeCheck = await checkFileSizeLimit();
        if (!sizeCheck) {
            return;
        }

        // 🔹 海報：以「表單內檔案 input」或全域 currentFile 為準，確保有選檔時一定會送出
        const posterInput = form.querySelector('input[name="poster"]');
        const posterFile = currentFile || (posterInput && posterInput.files && posterInput.files[0]);
        if (posterFile) {
            if (formData.has('poster')) formData.delete('poster');
            formData.append('poster', posterFile);
        }
        
        // 🔹 檢查是否為編輯模式（urlParams 已在函數開頭聲明，這裡直接使用）
        // urlParams 和 isEditMode 已在上面聲明（第 3838-3839 行），不需要重複聲明
        
        if (isEditMode) {
            // 🔹 編輯模式：使用新的 payload 格式（keep/delete/new）
            // 【D2 前端防呆】編輯模式下，檢查是否有任何變更
            const projectIntroOriginal = projectIntroElement.getAttribute('data-original-value') || '';
            const hasIntroChanged = introText.trim() !== projectIntroOriginal.trim();
            const hasPosterChanged = currentFile !== null;
            const hasNewOtherFiles = selectedOtherFiles.length > 0;
            const hasDeletedFiles = deletedExistingKeys.size > 0;
            
            // 如果沒有任何變更，阻止提交
            if (!hasIntroChanged && !hasPosterChanged && !hasNewOtherFiles && !hasDeletedFiles) {
                await showAlertDialog('你沒有做任何變更，不需要提交。', 'warning');
                return;
            }
            
            // 1. 要保留的舊檔案（排除已刪除的）- 這是 Single Source of Truth
            const displayList = getDisplayFileList();
            const keepExistingFiles = displayList.existing;
            
            // 2. 要刪除的舊檔案 key 列表
            const deleteKeysArray = Array.from(deletedExistingKeys);
            
            // 3. 新選擇的檔案
            // 🔹 【修復】構建最終要送出的完整檔案清單 JSON（包含保留的舊檔案 + 新選擇的檔案）
            const finalFilesList = [...keepExistingFiles];
            // 注意：新選擇的檔案會在後端上傳後加入，這裡先不加入（因為還沒有 path）
            
            // 🔹 【Debug 必做】提交前 console.log 送出的 JSON，確認已刪除的檔案不在裡面
            console.log('[handleFormSubmit] 🔍 編輯模式提交前檢查：');
            console.log('[handleFormSubmit] - keep_existing_files 數量:', keepExistingFiles.length);
            console.log('[handleFormSubmit] - keep_existing_files JSON:', JSON.stringify(keepExistingFiles));
            console.log('[handleFormSubmit] - delete_existing_keys 數量:', deleteKeysArray.length);
            console.log('[handleFormSubmit] - delete_existing_keys:', JSON.stringify(deleteKeysArray));
            console.log('[handleFormSubmit] - new_files 數量:', selectedOtherFiles.length);
            console.log('[handleFormSubmit] - 最終要保留的檔案清單:', JSON.stringify(finalFilesList));
            
            // 驗證：確保已刪除的檔案不在 keep_existing_files 中
            const keepFilePaths = keepExistingFiles.map(f => {
                const filePath = f.path || '';
                return filePath || getFileKey(f, true);
            });
            const deletedInKeep = deleteKeysArray.filter(key => keepFilePaths.includes(key));
            if (deletedInKeep.length > 0) {
                console.error('[handleFormSubmit] ⚠️ 錯誤：已刪除的檔案仍在 keep_existing_files 中！', deletedInKeep);
            } else {
                console.log('[handleFormSubmit] ✅ 驗證通過：已刪除的檔案不在 keep_existing_files 中');
            }
            
            formData.append('keep_existing_files', JSON.stringify(keepExistingFiles));
            formData.append('delete_existing_keys', JSON.stringify(deleteKeysArray));
            
            // 3. 新選擇的檔案（每項為 { file, file_type }）
            selectedOtherFiles.forEach(item => {
                formData.append('new_files[]', item.file);
                formData.append('new_file_types[]', item.file_type || '');
            });
            
            // 🔹 【修復】編輯模式下，移除刪除檔案時的額外確認（因為後面還有一個統一的確認）
            // 如果有刪除檔案，不需要額外確認，直接進入後續流程
        } else {
            // 非編輯模式：使用 multi_files[] 與 file_types[] 發送待提交的多檔案（每檔對應一筆類型）
            selectedOtherFiles.forEach(item => {
                formData.append('multi_files[]', item.file);
                formData.append('file_types[]', item.file_type || '');
            });
            
            // 🔹 非編輯模式：傳遞已暫存的檔案列表（kept_files_json，每筆含 file_type）
            // 如果沒有新選檔案，但已有暫存檔案，也要傳遞以便後端驗證
            const keptFiles = displayList.existing; // 只包含未被刪除的已暫存檔案
            if (keptFiles.length > 0) {
                formData.append('kept_files_json', JSON.stringify(keptFiles));
            }
            
            console.log('[handleFormSubmit] 非編輯模式：multi_files[] 數量:', selectedOtherFiles.length, 'kept_files_json 數量:', keptFiles.length);
        }

        // 使用函數開頭獲取的 submitBtn，而非重新宣告
        if (!submitBtn) {
            submitBtn = form.querySelector('button[type="submit"]');
        }

        const originalText = submitBtn ? (submitBtn.querySelector('i') ? submitBtn.innerHTML.replace('提交中...', '提交').replace(/<i[^>]*>.*?<\/i>\s*/, '') : submitBtn.textContent) : '提交';

        // 🔹 【提交確認】顯示提示：提交後將無法修改
        // 使用已存在的 isEditMode 變數（已在第 4553 行聲明）
        const hasExistingSubmission = window.PROJECT_UPLOAD_CONFIG?.draft_ID || 
                                     (window.PROJECT_UPLOAD_CONFIG?.isEditMode && !isEditMode);
        
        // 🔹 統一顯示確認對話框，提示提交後將無法修改
        let confirmMessage = '是否確定提交？\n提交後將無法修改。';
        if (!isEditMode && hasExistingSubmission) {
            confirmMessage = '您已有提交記錄，確定要覆蓋原本提交的資料嗎？\n提交後將無法修改。';
        }
        
        const confirmed = await showConfirmDialog(
                confirmMessage,
                '確認提交',
                '確定提交',
                '取消'
            );
        
        if (!confirmed) {
            resetSubmitState();
            return; // 用戶取消，不提交
        }
        
        // 🔹 自動設置 confirm_override，避免後端再次要求確認
        formData.append('confirm_override', '1');

        // 🔹 狀態已在開頭設置


        // 在 try 外宣告 data，避免在 finally 中引用未宣告變數
        let data = null;
        let hasSuccess = false; // 標記是否成功提交

        try {
            const response = await fetch(`${API_BASE}?do=submit`, {
                method: 'POST',
                body: formData
            });

            // 檢查 HTTP 狀態碼
            if (!response.ok) {
                console.error('HTTP 錯誤:', response.status, response.statusText);
                await showAlertDialog(`提交失敗：伺服器錯誤 (${response.status})`, 'error');
                return;
            }

            // 使用 .text() 再解析，避免非 JSON 內容導致解析失敗
            const text = await response.text();
            try {
                data = JSON.parse(text);
            } catch (parseError) {
                console.error('JSON 解析失敗，原始回應:', text);
                console.error('解析錯誤:', parseError);
                await showAlertDialog('提交失敗：後端回傳了非 JSON 內容', 'error');
                return;
            }

            if (data && data.success) {
                // 標記為成功
                hasSuccess = true;
                
                // 檢查是否為編輯模式（urlParams 已在函數開頭聲明，這裡直接使用）
                // urlParams 和 isEditMode 已在上面聲明（第 3837-3838 行），不需要重複聲明
                
                // 立即刷新提交記錄列表，不需要等刷新頁面
                const newProsubID = data.data?.prosub_ID || data.prosub_ID;
                
                // 🔹 在清空 deletedExistingKeys 之前，先保存刪除的文件數量（用於顯示提示）
                const deletedFilesCount = deletedExistingKeys.size;
                const hasNewFiles = selectedOtherFiles.length > 0;
                
                if (isEditMode && newProsubID) {
                    // 🔹 【修復】編輯模式：提交成功後，顯示「已送出」
                    await showAlertDialog('已送出', 'success', 2000);
                    
                    // 🔹 移除「已暫存」提示並清除狀態
                    hideDraftStatusAlert();
                    clearDraftStatusFromStorage();
                    
                    // 🔹 【修復刷新後數據同步】提交成功後，重新載入頁面以確保從 DB 讀取最新數據
                    // 這樣可以確保刷新後刪除的舊資料不會回來（因為 DB 已經更新為最新清單）
                    console.log('[handleFormSubmit] 編輯模式：提交成功，重新載入頁面以同步最新數據');
                    window.location.reload();
                    return;
                } else {
                    // 非編輯模式：提交成功後，顯示「已送出」提示
                    await showAlertDialog('已送出', 'success', 2000);
                    
                    // 🔹 移除「已暫存」提示並清除狀態
                    hideDraftStatusAlert();
                    clearDraftStatusFromStorage();
                    
                    // 非編輯模式：清空表單並刷新列表
                    // 【提交成功後必須清空上方表單（回到初始）】
                    // 只有在「後端回傳提交成功」後才執行 resetFormToInitial()
                    
                    // 🔹 【修復】設置標記，防止 loadDraft() 重新載入草稿
                    justSubmitted = true;
                    
                    resetFormToInitial();
                    
                    if (newProsubID) {
                        await refreshSubmissionRecords(newProsubID);
                    } else {
                        // 如果沒有返回 prosub_ID，重新載入整個頁面
                        const url = new URL(window.location.href);
                        url.searchParams.delete('edit');
                        window.location.href = url.toString();
                        return;
                    }
                    
                    // 移除 URL 中的 edit 參數（但不跳轉，因為已經更新了列表）
                    const url = new URL(window.location.href);
                    url.searchParams.delete('edit');
                    window.history.replaceState({}, '', url.toString());
                    
                    return; // 不需要恢復按鈕狀態
                }
            } else if (data && (data.data?.has_existing || data.has_existing) && (data.data?.need_confirm || data.need_confirm)) {
                // 🔹 已設置 confirm_override，不應該再出現此情況，直接顯示錯誤
                await showAlertDialog('提交失敗：系統錯誤，請重新提交', 'error');
            } else {
                await showAlertDialog(data?.message || '提交失敗，請稍後再試', 'error');
            }
        } catch (error) {
            console.error('提交錯誤:', error);
            // 只有在沒有成功標記時才顯示錯誤
            if (!hasSuccess) {
                await showAlertDialog('提交失敗，請稍後再試', 'error');
            }
        } finally {
            // 無論成功或失敗，都要恢復按鈕狀態（除非已經跳轉或成功）
            isSubmitting = false;
            if (submitBtn && !hasSuccess) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-upload"></i> 提交';
            }
        }
    }

    /**
     * 從 get_detail API 獲取資料並更新表單（編輯模式下提交後使用）
     */
    async function updateFormFromDetail(prosubID) {
        console.log('[updateFormFromDetail] 開始更新表單資料，prosub_ID:', prosubID);
        
        // 輔助函數：從路徑獲取檔名
        function getBasename(path) {
            return path.split('/').pop() || path;
        }
        
        try {
            const response = await fetch(`${API_BASE}?do=get_detail&prosub_ID=${prosubID}`);
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (parseError) {
                console.error('[updateFormFromDetail] JSON 解析失敗:', parseError);
                console.error('[updateFormFromDetail] 原始回應:', text);
                return;
            }
            
            if (!data.success || !data.data) {
                console.error('[updateFormFromDetail] 獲取資料失敗:', data);
                return;
            }
            
            const recordData = data.data;
            console.log('[updateFormFromDetail] 獲取到資料:', recordData);
            console.log('[updateFormFromDetail] intro 值:', recordData.intro, '類型:', typeof recordData.intro, '長度:', recordData.intro?.length || 0);
            console.log('[updateFormFromDetail] other_files 值:', recordData.other_files, '類型:', Array.isArray(recordData.other_files) ? 'array' : typeof recordData.other_files, '長度:', Array.isArray(recordData.other_files) ? recordData.other_files.length : 'N/A');
            
            // 1. 更新專題簡介
            const projectIntroElement = document.getElementById('projectIntro');
            if (projectIntroElement) {
                // 🔹 【修復】確保 intro 值正確更新，即使為空字串也要更新
                const introValue = recordData.intro !== undefined ? (recordData.intro || '') : '';
                projectIntroElement.value = introValue;
                // 更新原始值用於比對變更
                projectIntroElement.setAttribute('data-original-value', introValue);
                projectIntroElement.setAttribute('data-initial-value', introValue);
                console.log('[updateFormFromDetail] 已更新專題簡介，長度:', introValue.length, '內容:', introValue.substring(0, 50) + '...');
                
                // 🔹 【防呆】延遲再次確認，防止被其他邏輯清空
                setTimeout(() => {
                    if (projectIntroElement && projectIntroElement.value.trim() !== introValue.trim()) {
                        console.warn('[updateFormFromDetail] 簡介被清空，重新恢復...');
                        projectIntroElement.value = introValue;
                    }
                }, 200);
                
                setTimeout(() => {
                    if (projectIntroElement && projectIntroElement.value.trim() !== introValue.trim()) {
                        console.warn('[updateFormFromDetail] 簡介再次被清空，重新恢復...');
                        projectIntroElement.value = introValue;
                    }
                }, 1000);
            } else {
                console.warn('[updateFormFromDetail] 找不到 projectIntroElement');
            }
            
            // 2. 更新海報
            const noFileBtn = document.getElementById('noFileBtn');
            if (recordData.image_path) {
                // 有海報：更新按鈕文字和預覽
                if (noFileBtn) {
                    // 從路徑獲取檔名
                    const fileName = getBasename(recordData.image_path);
                    noFileBtn.textContent = fileName;
                    noFileBtn.classList.add('has-file');
                    noFileBtn.disabled = false;
                }
                // 顯示預覽（使用全局函數或檢查是否存在）
                if (typeof displayPreview === 'function') {
                    displayPreview(recordData.image_path);
                }
                if (typeof updatePreviewButton === 'function') {
                    updatePreviewButton(recordData.image_path);
                }
                console.log('[updateFormFromDetail] 已更新海報:', recordData.image_path);
            } else {
                // 沒有海報：清空
                if (noFileBtn) {
                    noFileBtn.textContent = '未選擇任何檔案';
                    noFileBtn.classList.remove('has-file');
                    noFileBtn.disabled = true;
                }
                if (typeof displayPreview === 'function') {
                    displayPreview('');
                }
                if (typeof removePreviewButton === 'function') {
                    removePreviewButton();
                }
                console.log('[updateFormFromDetail] 已清空海報');
            }
            
            // 3. 🔹 【防呆機制】更新多檔案列表
            console.log('[updateFormFromDetail] 檢查 other_files，recordData.other_files:', recordData.other_files);
            
            if (recordData.other_files && Array.isArray(recordData.other_files) && recordData.other_files.length > 0) {
                // 轉換格式並添加到 existingOtherFiles
                const newOtherFiles = recordData.other_files.map(file => {
                    // 處理各種格式的檔案資料
                    let filePath = '';
                    let fileName = '';
                    
                    if (typeof file === 'string') {
                        // 舊格式：字符串路徑
                        filePath = file;
                        fileName = getBasename(file);
                    } else if (file && typeof file === 'object') {
                        // 新格式：對象（API 返回的格式是 { path, name }）
                        filePath = file.path || file.stored || '';
                        fileName = file.name || file.original_name || getBasename(filePath);
                    }
                    
                    return {
                        original_name: fileName,
                        name: fileName,
                        path: filePath,
                    type: file.type || '',
                    size: file.size || 0,
                    uploaded_at: file.uploaded_at || '',
                        public: file.public !== undefined ? file.public : (file.allow_download !== undefined ? file.allow_download : true),
                    mime: file.mime || ''
                    };
                }).filter(file => file.path); // 過濾掉沒有路徑的檔案
                
                // 🔹 【防呆】確保 existingOtherFiles 被正確更新
                existingOtherFiles = newOtherFiles;
                
                console.log('[updateFormFromDetail] 準備更新多檔案列表，檔案數:', existingOtherFiles.length);
                console.log('[updateFormFromDetail] 檔案列表:', JSON.stringify(existingOtherFiles, null, 2));
                
                // 🔹 【防呆】立即渲染文件列表
                if (typeof renderExistingFiles === 'function') {
                    renderExistingFiles();
                    console.log('[updateFormFromDetail] 已調用 renderExistingFiles()');
                } else {
                    console.error('[updateFormFromDetail] renderExistingFiles 函數不存在！');
                }
                
                // 🔹 【防呆】延遲再次確認渲染成功（防止被其他邏輯清空）
                setTimeout(() => {
                    const uploadedFilesList = document.getElementById('uploadedFilesList');
                    if (uploadedFilesList) {
                        const renderedItems = uploadedFilesList.querySelectorAll('.uploaded-file-item');
                        if (renderedItems.length === 0 && existingOtherFiles.length > 0) {
                            console.warn('[updateFormFromDetail] 多檔列表渲染後被清空，重新渲染...');
                            if (typeof renderExistingFiles === 'function') {
                                renderExistingFiles();
                            }
                        } else {
                            console.log('[updateFormFromDetail] 多檔列表渲染成功，顯示', renderedItems.length, '個檔案');
                        }
                    } else {
                        console.warn('[updateFormFromDetail] 找不到 uploadedFilesList 元素');
                    }
                }, 200);
                
                // 🔹 【防呆】再次延遲確認（更長的延遲）
                setTimeout(() => {
                    const uploadedFilesList = document.getElementById('uploadedFilesList');
                    if (uploadedFilesList) {
                        const renderedItems = uploadedFilesList.querySelectorAll('.uploaded-file-item');
                        if (renderedItems.length === 0 && existingOtherFiles.length > 0) {
                            console.warn('[updateFormFromDetail] 多檔列表再次被清空，重新渲染...');
                            if (typeof renderExistingFiles === 'function') {
                                renderExistingFiles();
                            }
                        } else {
                            console.log('[updateFormFromDetail] 多檔列表最終確認，顯示', renderedItems.length, '個檔案');
                        }
                    }
                }, 1000);
                
                console.log('[updateFormFromDetail] 已更新多檔案列表，檔案數:', existingOtherFiles.length);
            } else {
                // 沒有檔案：清空列表
                console.log('[updateFormFromDetail] API 返回無檔案或格式錯誤，recordData.other_files:', recordData.other_files);
                existingOtherFiles = [];
                if (typeof renderExistingFiles === 'function') {
                    renderExistingFiles();
                }
                console.log('[updateFormFromDetail] 已清空多檔案列表（API 返回無檔案）');
            }
            
            console.log('[updateFormFromDetail] 表單更新完成');
        } catch (error) {
            console.error('[updateFormFromDetail] 更新表單資料失敗:', error);
        }
    }

    /**
     * 刷新提交記錄列表（提交成功後立即更新，不需要刷新頁面）
     */
    async function refreshSubmissionRecords(newProsubID) {
        console.log('[refreshSubmissionRecords] 開始刷新提交記錄列表，新記錄 ID:', newProsubID);
        
        try {
            // 獲取新提交記錄的詳細資訊
            const detailResponse = await fetch(`${API_BASE}?do=get_detail&prosub_ID=${newProsubID}`);
            const detailText = await detailResponse.text();
            let detailData;
            try {
                detailData = JSON.parse(detailText);
            } catch (parseError) {
                console.error('[refreshSubmissionRecords] JSON 解析失敗:', parseError);
                // 如果獲取詳細資訊失敗，重新載入整個頁面
                window.location.reload();
                return;
            }
            
            if (!detailData.success || !detailData.data) {
                console.error('[refreshSubmissionRecords] 獲取詳細資訊失敗:', detailData);
                // 如果獲取詳細資訊失敗，重新載入整個頁面
                window.location.reload();
                return;
            }
            
            const newRecord = detailData.data;
            const submissionRecordsBody = document.querySelector('.submission-records-body');
            
            if (!submissionRecordsBody) {
                console.warn('[refreshSubmissionRecords] 找不到提交記錄容器，重新載入頁面');
                window.location.reload();
                return;
            }
            
            // 狀態映射
            const statusMap = {
                0: { text: '退件', class: 'status-rejected', itemClass: 'record-rejected' },
                1: { text: '已送出', class: 'status-submitted', itemClass: 'record-submitted' },
                2: { text: '申請修改中', class: 'status-modifying', itemClass: 'record-modifying' },
                3: { text: '修改完成', class: 'status-completed', itemClass: 'record-completed' },
                4: { text: '暫存', class: 'status-draft', itemClass: 'record-draft' }
            };
            
            // 從返回的數據中獲取狀態和時間
            const status = parseInt(newRecord.prosub_status || newRecord.status || 1);
            const statusInfo = statusMap[status] || { text: '未知', class: 'status-unknown', itemClass: 'record-unknown' };
            
            // 格式化時間（從 API 返回的時間格式轉換為顯示格式）
            let displayTime = '';
            if (newRecord.prosub_update_d) {
                displayTime = newRecord.prosub_update_d;
            } else if (newRecord.updated_time) {
                displayTime = newRecord.updated_time;
            } else if (newRecord.prosub_created_d) {
                displayTime = newRecord.prosub_created_d;
            } else if (newRecord.created_time) {
                displayTime = newRecord.created_time;
            } else {
                displayTime = new Date().toISOString().slice(0, 19).replace('T', ' ');
            }
            
            // 確保時間格式正確（YYYY-MM-DD HH:MM:SS）
            if (displayTime.includes('T')) {
                displayTime = displayTime.replace('T', ' ').slice(0, 19);
            }
            
            // 檢查是否已存在（避免重複添加）
            const existingItem = submissionRecordsBody.querySelector(`[data-prosub-id="${newProsubID}"]`);
            if (existingItem) {
                console.log('[refreshSubmissionRecords] 記錄已存在，更新現有記錄');
                // 更新現有記錄的時間和狀態
                const statusText = existingItem.querySelector('.record-status-text');
                const timeText = existingItem.querySelector('.record-time-text');
                if (statusText) statusText.textContent = statusInfo.text;
                if (timeText) timeText.textContent = displayTime;
                // 更新 item class
                existingItem.className = `submission-record-item ${statusInfo.itemClass}`;
                // 移動到最前面
                submissionRecordsBody.insertBefore(existingItem, submissionRecordsBody.firstChild);
                return;
            }
            
            // 移除「尚無提交記錄」提示
            const noRecords = submissionRecordsBody.querySelector('.no-records');
            if (noRecords) {
                noRecords.remove();
            }
            
            // 創建新記錄的 HTML
            // 從返回的數據中獲取鎖定狀態
            const isLocked = newRecord.is_locked || newRecord.prosub_is_locked || false;
            const isTimeLocked = false; // 從全局配置獲取，這裡簡化處理（如果需要可以從 PROJECT_UPLOAD_CONFIG 獲取）
            const isFinalLocked = isTimeLocked || isLocked;
            
            const newRecordHTML = `
                <div class="submission-record-item ${statusInfo.itemClass}" data-prosub-id="${newProsubID}">
                    <button type="button" class="record-main-btn">
                        <div class="record-left">
                            <div class="record-bottom">
                                <span class="record-status-text">${escapeHtml(statusInfo.text)}</span>
                                <span class="record-time-text">${escapeHtml(displayTime)}</span>
                            </div>
                        </div>
                    </button>
                    <div class="record-actions">
                        <button type="button" class="btn btn-info btn-sm record-view-btn" data-prosub-id="${newProsubID}" onclick="viewSubmissionDetail(${newProsubID})" title="檢視">
                            <i class="fa-solid fa-eye"></i> 檢視
                        </button>
                        <button type="button" class="btn btn-primary btn-sm record-edit-btn" data-prosub-id="${newProsubID}" onclick="editSubmission(${newProsubID})" ${isFinalLocked ? 'disabled' : ''} title="編輯">
                            <i class="fa-solid fa-pencil"></i> 編輯
                        </button>
                        <button type="button" class="btn btn-danger btn-sm record-delete-btn" data-prosub-id="${newProsubID}" onclick="deleteSubmission(${newProsubID})" ${isFinalLocked ? 'disabled' : ''} title="刪除">
                            <i class="fa-solid fa-trash"></i> 刪除
                        </button>
                    </div>
                </div>
            `;
            
            // 插入到列表最前面（最新的記錄在最上面）
            submissionRecordsBody.insertAdjacentHTML('afterbegin', newRecordHTML);
            
            console.log('[refreshSubmissionRecords] 提交記錄列表已更新');
        } catch (error) {
            console.error('[refreshSubmissionRecords] 刷新提交記錄失敗:', error);
            // 如果刷新失敗，重新載入整個頁面
            window.location.reload();
        }
    }
    
    /**
     * 編輯提交記錄
     */
    function editSubmission(prosubID) {
        if (!prosubID) return;
        window.location.href = 'pages/project_upload.php?edit=' + prosubID;
    }

    // 歷史記錄列表顯示狀態
    let historyListVisible = false;
    let currentHistoryProsubID = null;

    /**
     * 顯示歷史記錄列表（彈出模態框）
     */
    window.toggleHistoryList = async function(prosubID) {
        if (!prosubID || isViewingHistory) return;
        isViewingHistory = true;

        // 移除舊的模態框（如果存在）
        const oldModal = document.getElementById('historyModal');
        const oldBackdrop = document.querySelector('.modal-backdrop.history-backdrop');
        if (oldModal) oldModal.remove();
        if (oldBackdrop) oldBackdrop.remove();

        // 創建 backdrop
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade history-backdrop';
        backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1040;';
        
        // 創建模態框
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.id = 'historyModal';
        modal.setAttribute('tabindex', '-1');
        modal.setAttribute('aria-labelledby', 'historyModalLabel');
        modal.setAttribute('aria-hidden', 'true');
        modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1050; display: flex; align-items: center; justify-content: center;';
        
        // 設置載入狀態的內容
            modal.innerHTML = `
                <div class="modal-dialog" style="max-width: 55%; width: 55%;">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                        <h5 class="modal-title" id="historyModalLabel">
                            <i class="fa-solid fa-clock-rotate-left"></i> 歷史提交紀錄
                        </h5>
                        <button type="button" class="btn-close btn-close-white" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="min-height: 300px; max-height: 65vh; overflow-y: auto; padding: 20px;">
                        <div class="text-center p-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">載入中...</span>
                            </div>
                            <p class="mt-3 mb-0">載入中...</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" style="border-radius: 8px;">關閉</button>
                    </div>
                </div>
            </div>
        `;
        
        // 添加到 DOM
        document.body.appendChild(backdrop);
        document.body.appendChild(modal);
        document.body.style.overflow = 'hidden';
        
        // 立即顯示
        requestAnimationFrame(() => {
            backdrop.style.opacity = '1';
            modal.style.opacity = '1';
        });

        // 預先定義關閉函數
        const closeModal = function() {
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
                isViewingHistory = false;
            }, 150);
        };
        
        // 綁定關閉事件
        const closeBtn = modal.querySelector('.btn-close');
        const closeFooterBtn = modal.querySelector('.modal-footer .btn-secondary');
        if (closeBtn) closeBtn.onclick = closeModal;
        if (closeFooterBtn) closeFooterBtn.onclick = closeModal;
        backdrop.onclick = closeModal;
        modal.onclick = function(e) {
            if (e.target === modal) closeModal();
        };
        
        // ESC 鍵關閉
        const escHandler = function(e) {
            if (e.key === 'Escape') closeModal();
        };
        document.addEventListener('keydown', escHandler, { once: true });

        try {
            console.log('正在獲取歷史記錄，prosub_ID:', prosubID);
            const response = await fetch(`${API_BASE}?do=get_history&prosub_ID=${prosubID}`);
            
            // 檢查響應狀態
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            // 檢查響應內容類型
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('API 回應非 JSON:', text.substring(0, 200));
                throw new Error('伺服器回應格式錯誤，請檢查後端 API');
            }
            
            const data = await response.json();
            console.log('歷史記錄 API 回應:', data);
            
            const modalBody = modal.querySelector('.modal-body');
            if (!modalBody) {
                isViewingHistory = false;
                return;
            }
            
            if (data.success) {
                const history = data.history || [];
                
                // 預先定義映射
                const actionMap = {
                    'submitted': '已送出',
                    'replaced': '已取代',
                    'deleted': '已刪除'
                };
                
                // 使用 DocumentFragment 批量創建元素
                const fragment = document.createDocumentFragment();
                
                if (history.length === 0) {
                    const p = document.createElement('p');
                    p.className = 'text-muted text-center p-4 mb-0';
                    p.textContent = '尚無歷史提交紀錄';
                    fragment.appendChild(p);
                } else {
                    const historyList = document.createElement('div');
                    historyList.className = 'history-list';
                    historyList.style.cssText = 'display: flex; flex-direction: column; gap: 12px; width: 100%;';
                    
                    // 按時間倒序排列（最新的在前）
                    const sortedHistory = [...history].reverse();
                    
                    sortedHistory.forEach((item) => {
                        // 只顯示已送出/已取代/已刪除
                        const action = item.action;
                        if (!['submitted', 'replaced', 'deleted'].includes(action)) {
                            return; // 跳過其他類型的記錄
                        }
                        
                        // 獲取動作類型文字
                        const actionText = item.action_text || actionMap[action] || action;
                        
                        // 獲取提交人（優先使用用戶名稱）
                        const userName = item.submitted_by_name || item.replaced_by_name || item.deleted_by_name || item.operator_name || '';
                        const userId = item.submitted_by || item.replaced_by || item.deleted_by || item.operator_id || '';
                        const user = userName || (userId ? `用戶ID: ${userId}` : '未知');
                        
                        // 創建簡單的列表項：提交人｜動作類型
                        const listItem = document.createElement('div');
                        listItem.style.cssText = 'padding: 12px 16px; border-bottom: 1px solid #e9ecef; font-size: 16px; color: #495057;';
                        listItem.textContent = `${user}｜${actionText}`;
                        
                        historyList.appendChild(listItem);
                    });
                    
                    fragment.appendChild(historyList);
                }
                
                // 一次性更新 DOM
                modalBody.innerHTML = '';
                modalBody.appendChild(fragment);
            } else {
                modalBody.innerHTML = `<p class="text-danger text-center p-4 mb-0">${escapeHtml(data.message || '獲取歷史記錄失敗')}</p>`;
            }
        } catch (error) {
            console.error('獲取歷史記錄錯誤:', error);
            const modalBody = modal.querySelector('.modal-body');
            if (modalBody) {
                modalBody.innerHTML = `<p class="text-danger text-center p-4 mb-0">獲取歷史記錄失敗：${escapeHtml(error.message || '請稍後再試')}</p>`;
            }
            isViewingHistory = false;
        }
    }
    
    // 防止重複打開歷史記錄模態框
    let isViewingHistory = false;
    
    // 保留舊的 showHistory 函數以向後兼容
    window.showHistory = function(prosubID) {
        if (window.toggleHistoryList) {
            window.toggleHistoryList(prosubID);
        }
    }
    
    /**
     * 轉義 HTML（用於防止 XSS）
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * 重置回原本的暫存
     */
    async function resetToDraft(prosubID) {
        if (!prosubID) return;

        const confirmed = await showConfirmDialog('確定要重置回原本的暫存嗎？這將恢復到提交前的暫存狀態。', '確認操作');
        if (!confirmed) {
            return;
        }

        try {
            const formData = new FormData();
            formData.append('prosub_ID', prosubID);

            const response = await fetch(`${API_BASE}?do=reset_to_draft`, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                await showAlertDialog('已重置回原始暫存！', 'success', 2000);
                window.location.reload();
            } else {
                await showAlertDialog(data.message || '重置失敗，請稍後再試', 'error');
            }
        } catch (error) {
            console.error('重置錯誤:', error);
            await showAlertDialog('重置失敗，請稍後再試', 'error');
        }
    }

    /**
     * 恢復到歷史版本
     * @param {number} prosubID - 提交記錄ID
     * @param {Object} historyItem - 歷史記錄項目
     * @param {number} index - 在倒序排列的歷史記錄中的索引（0是最新的）
     * @param {number} totalHistoryLength - 歷史記錄總數（用於計算原始索引）
     */
    async function resetToHistoryVersion(prosubID, historyItem, index, totalHistoryLength) {
        if (!prosubID || !historyItem) return;

        try {
            const formData = new FormData();
            formData.append('prosub_ID', prosubID);
            // 計算原始索引
            // 前端顯示時是倒序的（sortedHistory = history.reverse()），所以：
            // - sortedHistory[0] 對應 history[history.length - 1]（最新的）
            // - sortedHistory[1] 對應 history[history.length - 2]
            // - sortedHistory[index] 對應 history[history.length - 1 - index]
            // 後端會再次反轉 history，所以後端期望的索引就是倒序中的索引，即 index
            formData.append('history_index', index);

            const response = await fetch(`${API_BASE}?do=reset_to_history`, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                await showAlertDialog('已恢復到指定歷史版本！', 'success', 2000);
                window.location.reload(); // 重新載入頁面以顯示恢復後的內容
            } else {
                await showAlertDialog(data.message || '恢復失敗，請稍後再試', 'error');
            }
        } catch (error) {
            console.error('恢復歷史版本錯誤:', error);
            await showAlertDialog('恢復失敗，請稍後再試', 'error');
        }
    }

    /**
     * 刪除提交記錄
     */
    async function deleteSubmission(prosubID) {
        if (!prosubID) return;

        const confirmed = await showConfirmDialog('確定要刪除這筆記錄嗎？', '確認刪除');
        if (!confirmed) {
            return;
        }

        try {
            const response = await fetch(`${API_BASE}?do=delete&prosub_ID=${prosubID}`, {
                method: 'POST'
            });

            const data = await response.json();

            if (data.success) {
                await showAlertDialog('刪除成功！', 'success', 2000);
                // 🔹 刪除成功後，從 DOM 中移除該記錄（不重新載入頁面）
                // 🔹 【關鍵修復】查找包含該 prosubID 的記錄項（data-prosub-id 在按鈕上，需要找到父元素 submission-record-item）
                const deleteBtn = document.querySelector(`.record-delete-btn[data-prosub-id="${prosubID}"]`);
                let recordItem = null;
                
                if (deleteBtn) {
                    // 從按鈕向上查找 submission-record-item
                    recordItem = deleteBtn.closest('.submission-record-item');
                }
                
                // 如果找不到，嘗試直接查找（兼容其他可能的結構）
                if (!recordItem) {
                    recordItem = document.querySelector(`.submission-record-item[data-prosub-id="${prosubID}"]`);
                }
                
                // 如果還是找不到，嘗試查找任何包含該 ID 的元素
                if (!recordItem) {
                    const anyElement = document.querySelector(`[data-prosub-id="${prosubID}"]`);
                    if (anyElement) {
                        recordItem = anyElement.closest('.submission-record-item');
                    }
                }
                
                if (recordItem) {
                    console.log('[deleteSubmission] 找到記錄項，準備移除');
                    recordItem.remove();
                    
                    // 檢查是否還有其他記錄
                    const submissionRecordsBody = document.querySelector('.submission-records-body');
                    if (submissionRecordsBody) {
                        const remainingRecords = submissionRecordsBody.querySelectorAll('.submission-record-item');
                        console.log('[deleteSubmission] 剩餘記錄數:', remainingRecords.length);
                        if (remainingRecords.length === 0) {
                            // 如果沒有記錄了，顯示「尚無提交記錄」提示
                            submissionRecordsBody.innerHTML = `
                                <div class="no-records text-center p-4 text-muted">
                                    <i class="fa-solid fa-inbox fa-2x mb-3"></i>
                                    <p class="mb-0">尚無提交記錄</p>
                                </div>
                            `;
                        }
                    }
                    console.log('[deleteSubmission] ✅ 記錄已從 DOM 移除');
                } else {
                    console.warn('[deleteSubmission] ⚠️ 找不到記錄項，重新載入頁面');
                    // 如果找不到 DOM 元素，重新載入頁面
                    const url = new URL(window.location.href);
                    url.searchParams.delete('edit');
                    window.location.href = url.toString();
                }
            } else {
                await showAlertDialog(data.message || '刪除失敗，請稍後再試', 'error');
            }
        } catch (error) {
            console.error('刪除錯誤:', error);
            await showAlertDialog('刪除失敗，請稍後再試', 'error');
        }
    }

    /**
     * 查看提交記錄
     */
    function viewSubmission(prosubID) {
        if (!prosubID) return;
        showAlertDialog('查看功能待實現', 'info');
    }

    /**
     * 檢視提交記錄詳細資訊
     */
    // 防止重複點擊
    let isViewingDetail = false;

    async function viewSubmissionDetail(prosubID) {
        if (!prosubID || isViewingDetail) return;
        isViewingDetail = true;

        // 顯示載入提示（使用 requestAnimationFrame 確保流暢）
        const loadingModal = document.createElement('div');
        loadingModal.className = 'modal fade';
        loadingModal.style.cssText = 'display: block; z-index: 1055;';
        loadingModal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-body text-center p-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">載入中...</span>
                        </div>
                        <p class="mt-3 mb-0">載入中...</p>
                    </div>
                </div>
            </div>
        `;
        const loadingBackdrop = document.createElement('div');
        loadingBackdrop.className = 'modal-backdrop fade show';
        loadingBackdrop.style.cssText = 'z-index: 1050;';
        
        document.body.appendChild(loadingBackdrop);
        document.body.appendChild(loadingModal);
        document.body.classList.add('modal-open');
        document.body.style.overflow = 'hidden';

        try {
            // 【前端一律用 fetch + response.json()】
            const response = await fetch(`${API_BASE}?do=get_detail&prosub_ID=${prosubID}`);
            
            // 檢查回應是否為 JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('回應不是有效的 JSON 格式');
            }
            
            let data;
            try {
                // 使用 response.json() 解析 JSON
                data = await response.json();
            } catch (jsonError) {
                console.error('[viewSubmissionDetail] JSON 解析失敗:', jsonError);
                // 如果 JSON 解析失敗，嘗試讀取原始文字以便調試
                const text = await response.text();
                console.error('[viewSubmissionDetail] 原始回應內容:', text.substring(0, 500));
                throw new Error('資料解析失敗：回應不是有效的 JSON 格式');
            }

            // 使用 requestAnimationFrame 確保流暢移除載入提示
            requestAnimationFrame(() => {
                if (document.body.contains(loadingModal)) {
                    loadingModal.classList.remove('show');
                    setTimeout(() => {
                        if (document.body.contains(loadingModal)) {
                            document.body.removeChild(loadingModal);
                        }
                        if (document.body.contains(loadingBackdrop)) {
                            document.body.removeChild(loadingBackdrop);
                        }
                        document.body.classList.remove('modal-open');
                        document.body.style.overflow = '';
                    }, 150);
                }
            });

            if (data.success && data.data) {
                // 【資料來源必須來自資料庫（不可寫死）】
                // 從 API 返回的 data.data 中獲取所有資料
                const recordData = data.data;
                
                // 定義 escapeHtml 函數
                function escapeHtml(text) {
                    if (!text) return '';
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }
                
                // 【禁止使用 (無組別名)/(無簡介)/(未知) 當預設顯示】
                // 如果資料為空，顯示明確的錯誤訊息
                const teamName = recordData.team_name || '';
                const intro = recordData.intro || '';
                const createdTime = recordData.created_time ? new Date(recordData.created_time).toLocaleString('zh-TW') : '';
                const updatedTime = recordData.updated_time ? new Date(recordData.updated_time).toLocaleString('zh-TW') : '';
                const hasUpdate = recordData.has_update || false;
                const uploaderName = recordData.uploader_name || '';
                const updaterName = recordData.updater_name || '';
                // 🔹 修復海報路徑：使用絕對路徑（從網站根目錄開始）
                // 獲取網站根目錄路徑
                const getBasePath = function() {
                    const path = window.location.pathname || '';
                    if (path.includes('/main.php')) {
                        return path.substring(0, path.indexOf('/main.php') + 1);
                    }
                    if (path.includes('/pages/')) {
                        return path.substring(0, path.indexOf('/pages/') + 1);
                    }
                    return '/';
                };
                
                const basePath = getBasePath();
                // 如果 image_path 已經是絕對路徑（以 / 開頭），直接使用；否則從根目錄開始
                let imagePath = '';
                if (recordData.image_path) {
                    if (recordData.image_path.startsWith('/')) {
                        imagePath = recordData.image_path;
                    } else {
                        // 移除可能的 ../ 前綴，確保從根目錄開始
                        const cleanPath = recordData.image_path.replace(/^\.\.\//, '');
                        imagePath = basePath + (basePath.endsWith('/') ? '' : '/') + cleanPath;
                    }
                }
                
                // 🔹 輸出海報 URL 以便調試
                console.log('posterUrl:', imagePath);
                console.log('原始 image_path:', recordData.image_path);
                console.log('basePath:', basePath);
                
                const isPDF = imagePath.toLowerCase().endsWith('.pdf');
                const otherFiles = recordData.other_files || [];
                
                // 驗證必要資料是否存在
                if (!teamName) {
                    await showAlertDialog('查無此提交記錄的組別資訊', 'error');
                    isViewingDetail = false;
                    return;
                }
                
                console.log('[viewSubmissionDetail] 載入提交記錄資料:', {
                    prosub_ID: recordData.prosub_ID,
                    team_name: teamName,
                    intro_length: intro.length,
                    other_files_count: otherFiles.length
                });
                
                let detailHtml = `
                    <div class="submission-detail">
                        <div class="detail-section">
                            <h5><i class="fa-solid fa-users"></i> 組別名</h5>
                            <div class="detail-content">${escapeHtml(teamName)}</div>
                        </div>
                        <div class="detail-section">
                            <h5><i class="fa-solid fa-file-text"></i> 專題簡介</h5>
                            <div class="detail-content">${escapeHtml(intro).replace(/\n/g, '<br>')}</div>
                        </div>
                        <div class="detail-section">
                            <h5><i class="fa-solid fa-clock"></i> 上傳時間</h5>
                            <div class="detail-content">${escapeHtml(createdTime)}</div>
                        </div>
                        <div class="detail-section">
                            <h5><i class="fa-solid fa-user"></i> 上傳人</h5>
                            <div class="detail-content">${escapeHtml(uploaderName)}</div>
                        </div>
                        ${hasUpdate && updatedTime ? `
                        <div class="detail-section">
                            <h5><i class="fa-solid fa-clock-rotate-left"></i> 更新時間</h5>
                            <div class="detail-content">${escapeHtml(updatedTime)}</div>
                        </div>
                        ${updaterName ? `
                        <div class="detail-section">
                            <h5><i class="fa-solid fa-user-pen"></i> 更新人</h5>
                            <div class="detail-content">${escapeHtml(updaterName)}</div>
                        </div>
                        ` : ''}
                        ` : ''}
                        ${imagePath ? `
                        <div class="detail-section">
                            <h5><i class="fa-solid fa-image"></i> 上傳海報</h5>
                            <div class="detail-content">
                                <div class="detail-image-container" style="position: relative; min-height: 200px;">
                                    ${isPDF ? 
                                        `<div style="position: relative; width: 100%; height: 500px;">
                                            <iframe src="${escapeHtml(imagePath)}" type="application/pdf" class="detail-pdf" frameborder="0" style="width: 100%; height: 100%;" onload="this.style.opacity='1'; const loader = this.parentElement.querySelector('#pdfLoader'); if (loader) { loader.style.opacity='0'; setTimeout(() => loader.remove(), 300); }"></iframe>
                                            <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #f8f9fa; display: flex; align-items: center; justify-content: center; pointer-events: none; transition: opacity 0.3s;" id="pdfLoader">
                                                <div class="spinner-border text-primary" role="status"></div>
                                            </div>
                                        </div>` :
                                        `<img src="${escapeHtml(imagePath)}" alt="上傳檔案" class="detail-image" loading="lazy" style="opacity: 0; transition: opacity 0.3s;" 
                                            onload="this.style.opacity='1'; const spinner = this.nextElementSibling; if (spinner) spinner.remove();" 
                                            onerror="const spinner = this.nextElementSibling; if (spinner) spinner.remove(); const container = this.parentElement; container.innerHTML='<div style=\\'text-align: center; padding: 20px; color: #dc3545;\\'><i class=\\'fa-solid fa-exclamation-triangle\\'></i><p class=\\'mt-2 mb-0\\'>海報載入失敗</p></div>';">
                                        <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #f8f9fa; display: flex; align-items: center; justify-content: center; pointer-events: none;">
                                            <div class="spinner-border text-primary" role="status"></div>
                                        </div>`
                                    }
                                </div>
                            </div>
                        </div>
                        ` : ''}
                        ${otherFiles.length > 0 ? `
                        <div class="detail-section">
                            <h5><i class="fa-solid fa-files"></i> 其他檔案 (${otherFiles.length})</h5>
                            <div class="detail-content">
                                <div class="list-group">
                                ${otherFiles.map(file => {
                                    // 🔹 多檔案顯示為檔案清單，只使用後端返回的 name 和 path，不顯示 JSON key
                                    // 後端已處理，file 對象只包含 {path, name}
                                    const filePath = file.path || '';
                                    const fileName = file.name || basename(filePath);
                                    const fileUrl = filePath ? '../' + filePath : '';
                                    const isFilePDF = filePath.toLowerCase().endsWith('.pdf');
                                    const isFileImage = /\.(jpg|jpeg|png|gif|bmp|webp)$/i.test(filePath);
                                    
                                    return `
                                        <div class="list-group-item d-flex align-items-center justify-content-between">
                                            <div class="d-flex align-items-center flex-grow-1">
                                                <i class="fa-solid fa-file me-2 text-primary"></i>
                                                <span class="file-name">${escapeHtml(fileName)}</span>
                                            </div>
                                            <div class="d-flex gap-2">
                                                ${isFileImage || isFilePDF ? `
                                                <button type="button" class="btn btn-sm btn-info preview-file-btn" 
                                                        data-file-url="${escapeHtml(fileUrl)}" 
                                                        data-is-pdf="${isFilePDF ? '1' : '0'}"
                                                        title="預覽">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                                ` : ''}
                                                <a href="${escapeHtml(fileUrl)}" target="_blank" class="btn btn-sm btn-success" download title="下載">
                                                    <i class="fa-solid fa-download"></i> 下載
                                                </a>
                                            </div>
                                        </div>
                                    `;
                                }).join('')}
                                </div>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                `;
                
                // 使用 requestAnimationFrame 確保流暢顯示模態框
                requestAnimationFrame(() => {
                    // 顯示詳細資訊模態框
                    const modal = document.createElement('div');
                    modal.className = 'modal fade';
                    modal.setAttribute('tabindex', '-1');
                    modal.setAttribute('aria-labelledby', 'detailModalLabel');
                    modal.setAttribute('aria-hidden', 'true');
                    modal.style.cssText = 'display: block; z-index: 1055;';
                    modal.innerHTML = `
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="detailModalLabel">提交記錄詳細資訊</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                                    ${detailHtml}
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
                                    <button type="button" class="btn btn-primary" onclick="editSubmission(${prosubID}); window.location.href='pages/project_upload.php?edit=' + ${prosubID};">編輯</button>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    const backdrop = document.createElement('div');
                    backdrop.className = 'modal-backdrop fade';
                    backdrop.style.cssText = 'z-index: 1050;';
                    
                    document.body.appendChild(backdrop);
                    document.body.appendChild(modal);
                    document.body.classList.add('modal-open');
                    document.body.style.overflow = 'hidden';
                    
                    // 使用 requestAnimationFrame 觸發動畫
                    requestAnimationFrame(() => {
                        backdrop.classList.add('show');
                        modal.classList.add('show');
                        
                        // 綁定檔案預覽按鈕事件（在 modal 顯示後）
                        setTimeout(() => {
                            const previewButtons = modal.querySelectorAll('.preview-file-btn');
                            previewButtons.forEach(btn => {
                                btn.addEventListener('click', function() {
                                    const fileUrl = this.getAttribute('data-file-url');
                                    const isFilePDF = this.getAttribute('data-is-pdf') === '1';
                                    
                                    const previewModal = document.createElement('div');
                                    previewModal.className = 'modal fade show';
                                    previewModal.style.cssText = 'display: block; z-index: 1065;';
                                    
                                    // 使用字符串拼接避免模板字符串嵌套問題
                                    const escapedFileUrl = fileUrl.replace(/\\/g, '\\\\').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                                    const previewContent = isFilePDF ? 
                                        '<iframe src="' + escapedFileUrl + '" type="application/pdf" frameborder="0" style="width: 100%; height: 600px;"></iframe>' :
                                        '<img src="' + escapedFileUrl + '" alt="檔案預覽" style="max-width: 100%; max-height: 70vh;">';
                                    
                                    previewModal.innerHTML = '<div class="modal-dialog modal-lg modal-dialog-centered">' +
                                        '<div class="modal-content">' +
                                        '<div class="modal-header">' +
                                        '<h5 class="modal-title">檔案預覽</h5>' +
                                        '<button type="button" class="btn-close" aria-label="Close"></button>' +
                                        '</div>' +
                                        '<div class="modal-body text-center" style="position: relative; min-height: 400px;">' +
                                        previewContent +
                                        '</div>' +
                                        '</div>' +
                                        '</div>';
                                    
                                    const previewBackdrop = document.createElement('div');
                                    previewBackdrop.className = 'modal-backdrop fade show';
                                    previewBackdrop.style.cssText = 'z-index: 1060;';
                                    
                                    const closePreview = () => {
                                        previewModal.remove();
                                        previewBackdrop.remove();
                                        document.body.classList.remove('modal-open');
                                    };
                                    
                                    previewBackdrop.onclick = closePreview;
                                    const closeBtn = previewModal.querySelector('.btn-close');
                                    if (closeBtn) {
                                        closeBtn.onclick = closePreview;
                                    }
                                    
                                    document.body.appendChild(previewBackdrop);
                                    document.body.appendChild(previewModal);
                                    document.body.classList.add('modal-open');
                                });
                            });
                        }, 100);
                        
                        // PDF iframe 載入完成或失敗後移除載入提示
                        if (isPDF) {
                            const iframe = modal.querySelector('iframe');
                            if (iframe) {
                                // 成功載入
                                iframe.addEventListener('load', function() {
                                    const loader = modal.querySelector('#pdfLoader');
                                    if (loader) {
                                        loader.style.opacity = '0';
                                        setTimeout(() => loader.remove(), 300);
                                    }
                                });
                                
                                // 載入失敗（iframe 可能不會觸發 error，但設置超時處理）
                                setTimeout(() => {
                                    const loader = modal.querySelector('#pdfLoader');
                                    if (loader && loader.style.opacity !== '0') {
                                        // 如果 5 秒後還在顯示 spinner，可能是載入失敗
                                        loader.style.opacity = '0';
                                        setTimeout(() => {
                                            if (loader.parentElement) {
                                                loader.remove();
                                                const container = iframe.parentElement;
                                                if (container && !container.querySelector('.text-danger')) {
                                                    container.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;"><i class="fa-solid fa-exclamation-triangle"></i><p class="mt-2 mb-0">海報載入失敗</p></div>';
                                                }
                                            }
                                        }, 300);
                                    }
                                }, 5000);
                            }
                        } else {
                            // 圖片載入：onerror 已在 HTML 中處理，這裡添加超時備用處理
                            const img = modal.querySelector('.detail-image');
                            if (img) {
                                setTimeout(() => {
                                    if (img.style.opacity === '0' || img.style.opacity === '') {
                                        // 如果 5 秒後圖片還是透明的，可能是載入失敗
                                        const spinner = img.nextElementSibling;
                                        if (spinner) {
                                            spinner.remove();
                                            const container = img.parentElement;
                                            if (container && !container.querySelector('.text-danger')) {
                                                container.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;"><i class="fa-solid fa-exclamation-triangle"></i><p class="mt-2 mb-0">海報載入失敗</p></div>';
                                            }
                                        }
                                    }
                                }, 5000);
                            }
                        }
                    });
                    
                    // 簡化關閉邏輯
                    const closeModal = function() {
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
                            isViewingDetail = false;
                        }, 300);
                    };
                    
                    // 綁定關閉事件
                    const closeBtn = modal.querySelector('.btn-close');
                    const closeFooterBtn = modal.querySelector('.modal-footer .btn-secondary');
                    if (closeBtn) {
                        closeBtn.addEventListener('click', closeModal);
                    }
                    if (closeFooterBtn) {
                        closeFooterBtn.addEventListener('click', closeModal);
                    }
                    backdrop.addEventListener('click', closeModal);
                    
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            closeModal();
                        }
                    });
                    
                    // ESC 鍵關閉
                    const escHandler = function(e) {
                        if (e.key === 'Escape') {
                            closeModal();
                            document.removeEventListener('keydown', escHandler);
                        }
                    };
                    document.addEventListener('keydown', escHandler);
                });
            } else {
                // 【若查無該 id → 明確顯示「查無此提交記錄」】
                isViewingDetail = false;
                const errorMessage = data.message || '查無此提交記錄';
                await showAlertDialog(errorMessage, 'error');
            }
        } catch (error) {
            // 【錯誤處理：避免再「靜默失敗」】
            console.error('[viewSubmissionDetail] 獲取詳細資訊錯誤:', error);
            isViewingDetail = false;
            
            // 移除載入提示
            requestAnimationFrame(() => {
                if (document.body.contains(loadingModal)) {
                    loadingModal.classList.remove('show');
                    setTimeout(() => {
                        if (document.body.contains(loadingModal)) {
                            document.body.removeChild(loadingModal);
                        }
                        if (document.body.contains(loadingBackdrop)) {
                            document.body.removeChild(loadingBackdrop);
                        }
                        document.body.classList.remove('modal-open');
                        document.body.style.overflow = '';
                    }, 150);
                }
            });
            
            // 顯示明確的錯誤訊息
            const errorMsg = error.message || '資料解析失敗，請稍後再試';
            await showAlertDialog(errorMsg, 'error');
        }
    }

    // 匯出到全域
    window.ProjectUpload = {
        init,
        loadSchedule, // 暴露 loadSchedule 函數，供外部調用
        resetForm,
        resetFormToInitial,
        removeFile,
        editSubmission,
        cleanup: cleanupDeadlineList, // teardown 函數，用於頁面切換時清理狀態
        deleteSubmission,
        viewSubmission,
        showHistory,
        resetToDraft,
        viewSubmissionDetail,
        setupFormActions // 🔹 暴露 setupFormActions，供外部調用
    };
    
    // toggleHistoryList 已經在定義時就暴露到全局作用域了（window.toggleHistoryList）

    /**
     * 🔹 暫存提示相關函數
     */
    
    /**
     * 顯示「已暫存」提示
     */
    function showDraftStatusAlert() {
        const alertEl = document.getElementById('draftStatusAlert');
        if (alertEl) {
            alertEl.style.display = '';
        }
    }
    
    /**
     * 隱藏「已暫存」提示
     */
    function hideDraftStatusAlert() {
        const alertEl = document.getElementById('draftStatusAlert');
        if (alertEl) {
            alertEl.style.display = 'none';
        }
    }
    
    /**
     * 保存暫存狀態到 localStorage
     * @param {boolean} isDrafted - 是否已暫存
     */
    function saveDraftStatusToStorage(isDrafted) {
        try {
            const team_ID = window.PROJECT_UPLOAD_CONFIG?.team_ID;
            if (team_ID) {
                const key = `project_draft_status_${team_ID}`;
                if (isDrafted) {
                    localStorage.setItem(key, 'true');
                } else {
                    localStorage.removeItem(key);
                }
            }
        } catch (e) {
            console.warn('[saveDraftStatusToStorage] 無法保存到 localStorage:', e);
        }
    }
    
    /**
     * 從 localStorage 清除暫存狀態
     */
    function clearDraftStatusFromStorage() {
        try {
            const team_ID = window.PROJECT_UPLOAD_CONFIG?.team_ID;
            if (team_ID) {
                const key = `project_draft_status_${team_ID}`;
                localStorage.removeItem(key);
            }
        } catch (e) {
            console.warn('[clearDraftStatusFromStorage] 無法清除 localStorage:', e);
        }
    }
    
    /**
     * 檢查並顯示暫存狀態（頁面載入時調用）
     */
    function checkAndShowDraftStatus() {
        try {
            // 🔹 如果已完成提交，不顯示「已暫存」提示
            const isSubmittedReadonly = window.PROJECT_UPLOAD_CONFIG?.isSubmittedReadonly;
            if (isSubmittedReadonly) {
                hideDraftStatusAlert();
                clearDraftStatusFromStorage();
                return;
            }
            
            // 🔹 檢查是否有真正的暫存資料（必須有 draft_ID 且狀態為 4）
            const draft_ID = window.PROJECT_UPLOAD_CONFIG?.draft_ID;
            if (draft_ID) {
                // 有 draft_ID 表示有暫存資料，顯示提示
                showDraftStatusAlert();
                saveDraftStatusToStorage(true);
                return;
            }
            
            // 🔹 如果沒有 draft_ID，檢查 localStorage（但僅作為備用，主要還是以 draft_ID 為準）
            const team_ID = window.PROJECT_UPLOAD_CONFIG?.team_ID;
            if (team_ID) {
                const key = `project_draft_status_${team_ID}`;
                const savedStatus = localStorage.getItem(key);
                // 如果 localStorage 有記錄但沒有 draft_ID，可能是舊的暫存記錄，清除它
                if (savedStatus === 'true' && !draft_ID) {
                    clearDraftStatusFromStorage();
                    hideDraftStatusAlert();
                    return;
                }
            }
            
            // 🔹 如果沒有任何暫存資料，隱藏提示
            hideDraftStatusAlert();
        } catch (e) {
            console.warn('[checkAndShowDraftStatus] 檢查暫存狀態失敗:', e);
            hideDraftStatusAlert();
        }
    }
    
    /**
     * 監聽表單內容變更，隱藏暫存提示
     */
    function setupDraftStatusChangeListener() {
        // 監聽簡介變更
        const projectIntro = document.getElementById('projectIntro');
        if (projectIntro) {
            projectIntro.addEventListener('input', function() {
                // 用戶修改簡介時，隱藏提示（但不清除狀態，因為資料仍在）
                // 根據需求：若使用者再次修改任一欄位，可先隱藏提示
                hideDraftStatusAlert();
            });
        }
        
        // 監聽海報選擇
        const posterFileInput = document.getElementById('posterFileInput');
        if (posterFileInput) {
            posterFileInput.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    // 用戶選擇新海報時，隱藏提示
                    hideDraftStatusAlert();
                }
            });
        }
        
        // 監聽多檔案選擇
        const otherFilesInput = document.getElementById('otherFilesInput');
        if (otherFilesInput) {
            otherFilesInput.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    // 用戶選擇新檔案時，隱藏提示
                    hideDraftStatusAlert();
                }
            });
        }
    }

    // 🔹 【防呆】如果頁面已經載入完成，自動初始化（避免按鈕無法點擊）
    // 注意：這個自動初始化是備用機制，主要還是依賴 pages/project_upload.php 中的 initProjectUpload()
    // 但如果 PHP 端的初始化失敗，這個機制可以確保按鈕仍然可以點擊
    (function autoInit() {
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            // 頁面已經載入，延遲一點確保 DOM 完全準備好
            setTimeout(() => {
                // 檢查是否已經初始化過（避免重複初始化）
                if (typeof window.ProjectUpload !== 'undefined' && window.ProjectUpload.init) {
                    // 如果已經初始化過，只確保按鈕可用
                    if (typeof window.ensureFileButtonEnabled === 'function') {
                        window.ensureFileButtonEnabled();
                    }
                } else if (typeof init === 'function') {
                    console.log('[project_upload.js] 頁面已載入，自動初始化...');
                    init();
                }
            }, 500); // 延遲更長一點，確保 PHP 端的初始化先執行
        } else {
            // 頁面還在載入，等待 DOMContentLoaded
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(() => {
                    // 檢查是否已經初始化過
                    if (typeof window.ProjectUpload !== 'undefined' && window.ProjectUpload.init) {
                        // 如果已經初始化過，只確保按鈕可用
                        if (typeof window.ensureFileButtonEnabled === 'function') {
                            window.ensureFileButtonEnabled();
                        }
                    } else if (typeof init === 'function') {
                        console.log('[project_upload.js] DOMContentLoaded，自動初始化...');
                        init();
                    }
                }, 500);
            });
        }
    })();

})();


