// 時程表管理 JavaScript
// 使用命名空間避免變數衝突
(function() {
    'use strict';
    
    /* ===== API 路徑解析（支援動態載入） ===== */
    function resolveScheduleApiUrl() {
        const pathname = window.location.pathname || '';
        const hash = window.location.hash || '';
        
        // 如果 hash 中包含 pages/ 或 pathname 中包含 /pages/，表示在 pages 目錄下
        if (hash.includes('pages/') || pathname.includes('/pages/')) {
            // 如果當前是通過 main.php 載入的，需要回到根目錄
            if (pathname.includes('/main.php')) {
                const mainIndex = pathname.indexOf('/main.php');
                const projectRoot = pathname.substring(0, mainIndex);
                return projectRoot + (projectRoot.endsWith('/') ? '' : '/') + 'pages/schedule_data.php';
            }
            // 如果在 pages 目錄下直接訪問（包括iframe），使用相對路徑（同一目錄）
            return 'schedule_data.php';
        }
        // 默認在根目錄，使用 pages/schedule_data.php
        return 'pages/schedule_data.php';
    }
    
    /* ===== URL參數解析（支援hash路由和直接訪問） ===== */
    function getUrlParams() {
        const params = new URLSearchParams();
        // 先從hash中解析參數（main.php的hash路由）
        const hash = window.location.hash || '';
        if (hash.includes('?')) {
            const hashQuery = hash.substring(hash.indexOf('?') + 1);
            const hashParams = new URLSearchParams(hashQuery);
            hashParams.forEach((value, key) => {
                params.append(key, value);
            });
        }
        // 再從search中解析參數（直接訪問時）
        const searchParams = new URLSearchParams(window.location.search);
        searchParams.forEach((value, key) => {
            // 如果hash中已有該參數，優先使用hash中的值
            if (!params.has(key)) {
                params.append(key, value);
            }
        });
        return params;
    }
    
    // 注意：不在這裡檢查初始化標記，因為 initPageScript 需要能夠重新初始化
    // 初始化標記將在 initScheduleManage 函數中設置
    
    // 全域變數（使用 window 物件避免重複宣告）
    if (!window.scheduleManageData) {
        window.scheduleManageData = {
            teams: [],
            schedules: [],
            specialTimeRows: [],
            currentTinformaID: null,
            startTime: null,
            reportDuration: 20,
            preparationTime: 10,
            specialTimes: {
                lunch: { start: null, end: null },
                break: { start: null, end: null },
                preparation: { start: null, end: null },
                presentation_instruction: { start: null, end: null }
            },
            specialTimesList: [],
            sortableInstance: null,
            isLoading: false,
            eventHandlers: {},
            currentCohortId: null,
            currentGroupId: null,
            isEditMode: false,  // 編輯模式狀態（是否可編輯）
            hasClickedEdit: false  // 是否按過「編輯」按鈕（用於區分新增/更新）
            , onlineScoringOpen: true  // 線上評分是否開放（預設開放中）
            , scoringStatusLocked: false  //  true=已存檔過評分選項，不再顯示/可改
        };
    }
    
    // 簡化變數存取
    const data = window.scheduleManageData;
    
    // 清理函數
    function cleanup() {
        console.log('開始清理時程表管理資源');
        
        // 移除事件監聽器
        const cohortSelect = document.getElementById('cohortSelect');
        if (cohortSelect && data.eventHandlers.cohortChange) {
            cohortSelect.removeEventListener('change', data.eventHandlers.cohortChange);
            data.eventHandlers.cohortChange = null;
        }
        
        // 移除「編輯/儲存」按鈕事件監聽器
        const editAllBtn = document.getElementById('schedule-edit-all-btn');
        if (editAllBtn && data.eventHandlers.editAllClick) {
            editAllBtn.removeEventListener('click', data.eventHandlers.editAllClick);
            data.eventHandlers.editAllClick = null;
        }
        
        // 移除「回到初始頁」按鈕事件監聽器
        const backHomeBtn = document.getElementById('schedule-back-home-btn');
        if (backHomeBtn && data.eventHandlers.backHomeClick) {
            backHomeBtn.removeEventListener('click', data.eventHandlers.backHomeClick);
            data.eventHandlers.backHomeClick = null;
        }

        // 移除「加入團隊」按鈕事件監聽器
        const addTeamBtn = document.getElementById('schedule-add-team-btn');
        if (addTeamBtn && data.eventHandlers.addTeamClick) {
            addTeamBtn.removeEventListener('click', data.eventHandlers.addTeamClick);
            data.eventHandlers.addTeamClick = null;
        }
        
        const toggleScoringBtn = document.getElementById('toggleOnlineScoringBtn');
        if (toggleScoringBtn && data.eventHandlers.toggleOnlineScoringClick) {
            toggleScoringBtn.removeEventListener('click', data.eventHandlers.toggleOnlineScoringClick);
            data.eventHandlers.toggleOnlineScoringClick = null;
        }
        const onlineScoringBox = document.getElementById('online-scoring-box');
        if (onlineScoringBox) onlineScoringBox.style.display = 'none';
        
        // 移除「新增時程表」按鈕事件監聽器
        const newScheduleBtn = document.getElementById('schedule-new-schedule-btn');
        if (newScheduleBtn && data.eventHandlers.newScheduleClick) {
            newScheduleBtn.removeEventListener('click', data.eventHandlers.newScheduleClick);
            data.eventHandlers.newScheduleClick = null;
        }
        
        // 移除 tbody 上的點擊事件監聽器
        const tbody = document.getElementById('scheduleTableBody');
        if (tbody) {
            // 移除 data-click-handler 標記，允許重新綁定
            tbody.removeAttribute('data-click-handler');
            // 清空內容，但保留元素本身（因為 Sortable 可能需要它）
            tbody.innerHTML = '';
        }
        
        // 銷毀 Sortable 實例
        if (data.sortableInstance) {
            try {
                data.sortableInstance.destroy();
            } catch (e) {
                console.warn('銷毀 Sortable 實例時出錯:', e);
            }
            data.sortableInstance = null;
        }
        
        // 清理所有自定義拖移元素
        document.querySelectorAll('.custom-drag-element').forEach(el => {
            try {
                el.remove();
            } catch (e) {
                console.warn('移除拖移元素時出錯:', e);
            }
        });
        
        // 重置載入狀態
        data.isLoading = false;
        
        // 重置編輯模式狀態
        data.isEditMode = false;
        data.hasClickedEdit = false;
        
        // 預設為開啟線上評分，未鎖定
        data.onlineScoringOpen = true;
        data.scoringStatusLocked = false;
        
        // 重置當前 ID
        data.currentTinformaID = null;
        data.currentCohortId = null;
        data.currentGroupId = null;
        
        console.log('清理完成');
    }
    
    // 更新線上評分狀態區塊的顯示（依 data.onlineScoringOpen），且僅編輯模式下可點擊
    function updateOnlineScoringUI() {
        const statusEl = document.getElementById('onlineScoringStatusText');
        const btnEl = document.getElementById('toggleOnlineScoringBtn');
        if (!statusEl || !btnEl) return;
        const d = window.scheduleManageData;
        if (d && d.onlineScoringOpen) {
            statusEl.textContent = '開放中';
            statusEl.classList.remove('closed');
            btnEl.textContent = '關閉線上評分';
        } else {
            statusEl.textContent = '已關閉';
            statusEl.classList.add('closed');
            btnEl.textContent = '開啟線上評分';
        }
        btnEl.disabled = !(d && d.isEditMode);
    }
    
    // 初始化函數（支援 AJAX 載入）
    function initScheduleManage() {
        // 檢查是否已經初始化過（避免重複初始化，除非是通過 initPageScript 調用）
        if (window.scheduleManageInitialized && !window.forceReinit) {
            console.log('時程表管理已初始化，跳過重複初始化');
            return;
        }
        
        // 清除強制重新初始化標記
        window.forceReinit = false;
        
        // 標記已初始化
        window.scheduleManageInitialized = true;
        
        // 如果正在載入，跳過
        if (data.isLoading) {
            console.log('正在載入中，跳過重複初始化');
            return;
        }
        
        console.log('初始化時程表管理頁面');
        
        // 先清理舊的資源
        cleanup();
        
        // 確保時間欄位在初始化時是空白的
        const startTime = document.getElementById('startTime');
        const endTime = document.getElementById('endTime');
        const endTimeContainer = document.getElementById('endTimeContainer');
        const specialTimeOptions = document.getElementById('special-time-options');
        if (startTime) {
            startTime.value = '';
        }
        if (endTime) {
            endTime.value = '';
        }
        if (endTimeContainer) {
            endTimeContainer.style.display = 'none';
        }
        // 確保特殊時間選項區域在初始化時是隱藏的（只在編輯模式下顯示）
        if (specialTimeOptions) {
            specialTimeOptions.style.display = 'none';
        }
        
        // 載入屆別選項
        loadCohorts();
        
        // 從URL參數讀取數據（如果從integrate頁面打開）
        const urlParams = getUrlParams();
        const urlCohort_ID = urlParams.get('cohort_ID');
        const urlTitle = urlParams.get('title');
        const urlTinforma_ID = urlParams.get('tinforma_ID');
        const fromIntegrate = urlParams.get('from_integrate') === '1';
        
        // 直接綁定事件（如果元素已經存在）
        const cohortSelect = document.getElementById('cohortSelect');
        const scheduleTitle = document.getElementById('scheduleTitle');
        
        if (cohortSelect) {
            console.log('找到屆別選擇器，綁定事件');
            
            // 創建新的事件處理函數
            const handleCohortChange = async function() {
                const cohort_ID = this.value;
                console.log('屆別選擇變化:', cohort_ID);
                
                data.currentCohortId = cohort_ID;
                
                // 更新輸入欄位和按鈕狀態
                const scheduleTitle = document.getElementById('scheduleTitle');
                const startTime = document.getElementById('startTime');
                const saveBtn = document.getElementById('schedule-save-btn');
                const exportBtn = document.getElementById('exportPDFBtn');
                
                if (!cohort_ID) {
                    if (scheduleTitle) scheduleTitle.disabled = true;
                    if (startTime) startTime.disabled = true;
                    if (saveBtn) saveBtn.disabled = true;
                    if (exportBtn) exportBtn.disabled = true;
                    const exportWordBtn = document.getElementById('exportWordBtn');
                    if (exportWordBtn) exportWordBtn.disabled = true;
                    
                    // 禁用編輯按鈕
                    const editAllBtn = document.getElementById('schedule-edit-all-btn');
                    if (editAllBtn) editAllBtn.disabled = true;
                    
                    // 清空顯示
                    const tbody = document.getElementById('scheduleTableBody');
                    const fileListContainer = document.getElementById('schedule-file-list');
                    const tableCard = document.getElementById('schedule-table-card');
                    
                    if (tbody) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">請選擇屆別</td></tr>';
                    }
                    if (fileListContainer) fileListContainer.style.display = 'none';
                    if (tableCard) tableCard.style.display = 'none';
                    
                    // 重置資料
                    data.teams = [];
                    data.schedules = [];
                } else {
                    // 更新輸入欄位和按鈕狀態
                    if (scheduleTitle) scheduleTitle.disabled = false;
                    if (startTime) startTime.disabled = false;
                    if (saveBtn) saveBtn.disabled = false;
                    if (exportBtn) exportBtn.disabled = false;
                    const exportWordBtn = document.getElementById('exportWordBtn');
                    if (exportWordBtn) exportWordBtn.disabled = false;
                    
                    // 清空標題輸入框
                    if (scheduleTitle) {
                        scheduleTitle.value = '';
                    }
                    const titleDisplay = document.getElementById('scheduleTitleDisplay');
                    if (titleDisplay) {
                        titleDisplay.textContent = '時程表';
                    }
                    
                    // 清空時間輸入欄位
                    if (startTime) {
                        startTime.value = '';
                    }
                    const endTime = document.getElementById('endTime');
                    const endTimeContainer = document.getElementById('endTimeContainer');
                    if (endTime) {
                        endTime.value = '';
                    }
                    // 隱藏結束時間欄位
                    if (endTimeContainer) {
                        endTimeContainer.style.display = 'none';
                    }
                    
                    // 重置 currentTinformaID
                    data.currentTinformaID = null;
                    
                    // 防止重複觸發
                    if (data.isLoading) {
                        console.log('正在載入中，跳過重複觸發');
                        return;
                    }
                    
                    // 先檢查並顯示模式（檔案列表或編輯界面）
                    await checkAndDisplayMode(cohort_ID);
                }
            };
            
            // 保存事件處理函數引用以便後續清理
            data.eventHandlers.cohortChange = handleCohortChange;
            
            // 綁定新的事件監聽器
            cohortSelect.addEventListener('change', handleCohortChange);
            
            // 如果從integrate頁面打開且有URL參數，自動設置屆別和標題
            if (fromIntegrate && urlCohort_ID) {
                // 顯示"返回上一頁"按鈕（從 integrate.php 打開時需要顯示）
                const backHomeBtn = document.getElementById('schedule-back-home-btn');
                if (backHomeBtn) {
                    backHomeBtn.style.display = 'inline-block';
                }
                
                // 設置屆別為只讀
                if (cohortSelect) {
                    cohortSelect.disabled = true;
                    cohortSelect.style.backgroundColor = '#f5f5f5';
                    cohortSelect.style.cursor = 'not-allowed';
                }
                
                // 等待屆別選項載入完成後再設置
                setTimeout(async () => {
                    cohortSelect.value = urlCohort_ID;
                    data.currentCohortId = urlCohort_ID;
                    
                    // 設置標題
                    if (scheduleTitle && urlTitle) {
                        scheduleTitle.value = urlTitle;
                        scheduleTitle.disabled = false;
                        // 同時更新標題顯示
                        const titleDisplay = document.getElementById('scheduleTitleDisplay');
                        if (titleDisplay) {
                            titleDisplay.textContent = urlTitle;
                        }
                    }
                    
                    // 直接顯示編輯模式，不觸發change事件（避免checkAndDisplayMode清空數據）
                    showEditMode();
                    
                    // 如果有標題，直接打開該時程表（這會載入時程數據和團隊數據）
                    if (urlTitle) {
                        setTimeout(async () => {
                            console.log('從integrate打開，準備載入時程表:', urlTitle);
                            await window.openScheduleFile(urlTitle, urlCohort_ID);
                        }, 800);
                    } else {
                        // 如果沒有標題，載入所有團隊（新增模式）
                        console.log('從integrate打開，沒有標題，載入所有團隊');
                        await loadTeams(urlCohort_ID);
                    }
                }, 500);
            }
        } else {
            console.warn('找不到屆別選擇器元素，將在 500ms 後重試');
            setTimeout(initScheduleManage, 500);
        }
        
        // 綁定「編輯」按鈕事件
        const editAllBtn = document.getElementById('schedule-edit-all-btn');
        if (editAllBtn) {
            // 移除舊的事件監聽器（如果有的話）
            if (data.eventHandlers.editAllClick) {
                editAllBtn.removeEventListener('click', data.eventHandlers.editAllClick);
            }
            
            // 創建新的事件處理函數：同一顆按鈕負責「編輯」與「儲存」
            const handleEditAllClick = async function() {
                console.log('編輯/儲存按鈕被點擊');

                const cohortSelect = document.getElementById('cohortSelect');
                const cohort_ID = cohortSelect ? cohortSelect.value : null;

                if (!data.isEditMode) {
                    // 尚未進入編輯模式：先檢查屆別，然後啟用編輯
                    if (!cohort_ID) {
                        Swal.fire({
                            icon: 'warning',
                            title: '請選擇屆別',
                            text: '請先選擇屆別後再進入編輯'
                        });
                        return;
                    }

                    // 標記已按過編輯按鈕（用於區分新增/更新）
                    data.hasClickedEdit = true;
                    console.log('設置 hasClickedEdit 為 true，表示要更新現有時程表');

                    enterEditMode(); // 進入編輯模式，按鈕文字改為「儲存」
                    return;
                }

                // 已在編輯模式：點擊視為「儲存」
                if (!cohort_ID) {
                    Swal.fire({
                        icon: 'warning',
                        title: '請選擇屆別',
                        text: '請先選擇屆別後再儲存'
                    });
                    return;
                }

                try {
                    await saveSchedules();
                    // 儲存成功後回到唯讀模式，按鈕文字改回「編輯」
                    exitEditMode();
                } catch (err) {
                    console.error('儲存時程表失敗:', err);
                    Swal.fire('儲存失敗', err.message || '請稍後再試', 'error');
                }
            };
            
            // 保存事件處理函數引用以便後續清理
            data.eventHandlers.editAllClick = handleEditAllClick;
            
            // 綁定新的事件監聽器
            editAllBtn.addEventListener('click', handleEditAllClick);
        }
        
        // 綁定「回到初始頁」按鈕事件
        const backHomeBtn = document.getElementById('schedule-back-home-btn');
        if (backHomeBtn) {
            // 移除舊的事件監聽器（如果有的話）
            if (data.eventHandlers.backHomeClick) {
                backHomeBtn.removeEventListener('click', data.eventHandlers.backHomeClick);
            }
            
            // 創建新的事件處理函數（含防呆：編輯中點擊時提醒是否存檔）
            const handleBackHomeClick = async function() {
                console.log('回到上一頁按鈕被點擊');

                // 若處於編輯模式，先詢問是否存檔
                const isInEditMode = data.isEditMode === true;

                if (isInEditMode) {
                    const result = await Swal.fire({
                        icon: 'warning',
                        title: '尚未儲存',
                        text: '目前處於編輯狀態，要儲存後再離開嗎？',
                        showDenyButton: true,
                        showCancelButton: true,
                        confirmButtonText: '儲存並退出',
                        denyButtonText: '直接退出',
                        cancelButtonText: '取消',
                        reverseButtons: true
                    });

                    if (result.isConfirmed) {
                        try {
                            await saveSchedules();
                        } catch (err) {
                            console.error('儲存失敗:', err);
                            Swal.fire('儲存失敗', err.message || '請稍後再試', 'error');
                            return; // 儲存失敗時不要離開
                        }
                    } else if (!result.isDenied) {
                        // 使用者按「取消」或關閉對話框時，不繼續執行返回邏輯
                        return;
                    }
                    // result.isDenied -> 直接退出（不儲存），繼續往下執行原本的返回流程
                }
                
                // 檢查是否從 integrate.php 打開
                const urlParams = getUrlParams();
                const fromIntegrate = urlParams.get('from_integrate') === '1';
                
                if (fromIntegrate) {
                    // 如果從 integrate.php 打開，跳轉回去
                    console.log('從 integrate.php 打開，跳轉回 integrate.php');
                    // 檢查當前路徑，構建正確的跳轉URL
                    const pathname = window.location.pathname || '';
                    let redirectUrl = 'main.php#pages/integrate.php';
                    // 如果當前在 main.php，直接使用 hash
                    if (pathname.includes('main.php')) {
                        redirectUrl = '#pages/integrate.php';
                    } else if (pathname.includes('/pages/')) {
                        // 如果在 pages 目錄下，需要回到上一層
                        redirectUrl = '../main.php#pages/integrate.php';
                    } else {
                        // 其他情況，使用相對路徑
                        redirectUrl = 'main.php#pages/integrate.php';
                    }
                    window.location.href = redirectUrl;
                    return;
                }
                
                const cohortSelect = document.getElementById('cohortSelect');
                const cohort_ID = cohortSelect ? cohortSelect.value : null;
                
                if (!cohort_ID) {
                    Swal.fire({
                        icon: 'warning',
                        title: '請選擇屆別',
                        text: '請先選擇屆別'
                    });
                    return;
                }
                
                // 重新載入檔案列表並顯示
                try {
                    const apiUrl = resolveScheduleApiUrl();
                    const requestUrl = `${apiUrl}?action=listTitles&cohort_ID=${cohort_ID}`;
                    console.log('重新載入標題列表，URL:', requestUrl);
                    
                    const response = await fetch(requestUrl);
                    const responseData = await response.json();
                    
                    console.log('重新載入標題列表回應:', responseData);
                    
                    if (responseData.success && Array.isArray(responseData.data) && responseData.data.length > 0) {
                        // 有資料，顯示檔案列表
                        showFileListMode();
                        await displayFileList(cohort_ID, responseData.data);
                        
                        // 清空標題輸入框
                        const titleInput = document.getElementById('scheduleTitle');
                        if (titleInput) {
                            titleInput.value = '';
                        }
                        
                        // 重置 currentTinformaID
                        data.currentTinformaID = null;
                        
                        // 滾動到檔案列表位置
                        const fileListContainer = document.getElementById('schedule-file-list');
                        if (fileListContainer && fileListContainer.style.display !== 'none') {
                            setTimeout(() => {
                                fileListContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }, 300);
                        }
                    } else {
                        // 沒有資料，保持編輯模式
                        Swal.fire({
                            icon: 'info',
                            title: '尚無時程表檔案',
                            text: '目前沒有已保存的時程表檔案'
                        });
                    }
                } catch (error) {
                    console.error('回到初始頁失敗:', error);
                    Swal.fire('錯誤', '無法載入檔案列表', 'error');
                }
            };
            
            // 保存事件處理函數引用以便後續清理
            data.eventHandlers.backHomeClick = handleBackHomeClick;
            
            // 綁定新的事件監聽器
            backHomeBtn.addEventListener('click', handleBackHomeClick);
        }

        // 綁定「加入團隊」按鈕事件（編輯模式下可用，將未在時程表中的團隊加入）
        const addTeamBtn = document.getElementById('schedule-add-team-btn');
        if (addTeamBtn) {
            if (data.eventHandlers.addTeamClick) {
                addTeamBtn.removeEventListener('click', data.eventHandlers.addTeamClick);
            }
            const handleAddTeamClick = async function() {
                await openAddTeamDialogForSchedule();
            };
            data.eventHandlers.addTeamClick = handleAddTeamClick;
            addTeamBtn.addEventListener('click', handleAddTeamClick);
        }
        
        // 綁定「新增時程表」按鈕事件
        const newScheduleBtn = document.getElementById('schedule-new-schedule-btn');
        if (newScheduleBtn) {
            // 移除舊的事件監聽器（如果有的話）
            if (data.eventHandlers.newScheduleClick) {
                newScheduleBtn.removeEventListener('click', data.eventHandlers.newScheduleClick);
            }
            
            // 創建新的事件處理函數
            const handleNewScheduleClick = async function() {
                console.log('新增時程表按鈕被點擊');
                
                const cohortSelect = document.getElementById('cohortSelect');
                const cohort_ID = cohortSelect ? cohortSelect.value : null;
                
                if (!cohort_ID) {
                    Swal.fire({
                        icon: 'warning',
                        title: '請選擇屆別',
                        text: '請先選擇屆別後再新增時程表'
                    });
                    return;
                }
                
                // 清空標題輸入
                const titleInput = document.getElementById('scheduleTitle');
                if (titleInput) {
                    titleInput.value = '';
                }
                
                // 清空時間輸入
                const startTimeInput = document.getElementById('startTime');
                const endTimeInput = document.getElementById('endTime');
                const endTimeContainer = document.getElementById('endTimeContainer');
                if (startTimeInput) {
                    startTimeInput.value = '';
                }
                if (endTimeInput) {
                    endTimeInput.value = '';
                }
                // 隱藏結束時間欄位（新增時程表時）
                if (endTimeContainer) {
                    endTimeContainer.style.display = 'none';
                }
                
            // 重置 currentTinformaID
            data.currentTinformaID = null;
            data.scoringStatusLocked = false;  // 新增時程表時顯示線上評分選項
            
            // 重置編輯標記（新增時程表時，未按過編輯）
            data.hasClickedEdit = false;
            console.log('新增時程表模式，重置 hasClickedEdit 為 false');
            
            // 清空時程資料和特殊時間段
            data.schedules = [];
            data.specialTimesList = [];
            data.specialTimes = {
                lunch: { start: null, end: null },
                break: { start: null, end: null },
                preparation: { start: null, end: null },
                presentation_instruction: { start: null, end: null }
            };
            
            // 更新標題顯示
            const titleDisplay = document.getElementById('scheduleTitleDisplay');
            if (titleDisplay) {
                titleDisplay.textContent = '時程表';
            }
            
            // 切換到編輯模式
            showEditMode();
            
            // 進入編輯模式（啟用編輯功能和刪除按鈕）
            enterEditMode();
            
            // 載入團隊列表（不載入現有時程）
            await loadTeamsForNewSchedule(cohort_ID);
            
            console.log('已切換到新增時程表模式');
            };
            
            // 保存事件處理函數引用以便後續清理
            data.eventHandlers.newScheduleClick = handleNewScheduleClick;
            
            // 綁定新的事件監聽器
            newScheduleBtn.addEventListener('click', handleNewScheduleClick);
        }
    
        // 綁定標題輸入欄位的自動保存
        const titleInput = document.getElementById('scheduleTitle');
        if (titleInput) {
            // 移除舊的事件監聽器
            if (data.eventHandlers.titleBlur) {
                titleInput.removeEventListener('blur', data.eventHandlers.titleBlur);
            }
            
            // 更新卡片標題顯示
            const updateTitleDisplay = function(value) {
                const titleDisplay = document.getElementById('scheduleTitleDisplay');
                if (titleDisplay) {
                    titleDisplay.textContent = value.trim() || '時程表';
                }
            };
            
            // 輸入時即時更新顯示
            const handleTitleInput = function() {
                updateTitleDisplay(this.value);
            };
            
            const handleTitleBlur = async function() {
                const titleValue = this.value.trim();
                updateTitleDisplay(titleValue);
                
                if (titleValue && data.currentTinformaID) {
                    // 自動保存標題
                    try {
                        const getApiPath = function() {
                            const pathname = window.location.pathname || '';
                            const hash = window.location.hash || '';
                            if (pathname.includes('/main.php')) {
                                const mainIndex = pathname.indexOf('/main.php');
                                const projectRoot = pathname.substring(0, mainIndex);
                                return projectRoot + (projectRoot.endsWith('/') ? '' : '/') + 'api.php';
                            }
                            if (hash.includes('pages/') || pathname.includes('/pages/')) {
                                return '../api.php';
                            }
                            return 'api.php';
                        };
                        
                        const apiPath = getApiPath();
                        const formData = new FormData();
                        formData.append('tinforma_ID', data.currentTinformaID);
                        formData.append('tinforma_content', '');
                        formData.append('tinforma_title', titleValue);
                        
                        const response = await fetch(`${apiPath}?do=save_schedule_info`, {
                            method: 'POST',
                            body: formData
                        });
                        
                        const responseData = await response.json();
                        if (responseData.ok) {
                            console.log('標題已自動保存');
                        } else {
                            console.warn('自動保存標題失敗:', responseData.msg);
                        }
                    } catch (error) {
                        console.error('自動保存標題錯誤:', error);
                    }
                }
            };
            
            data.eventHandlers.titleInput = handleTitleInput;
            data.eventHandlers.titleBlur = handleTitleBlur;
            titleInput.addEventListener('input', handleTitleInput);
            titleInput.addEventListener('blur', handleTitleBlur);
        }
        
        // 綁定時間輸入欄位的驗證
        const startTimeInput = document.getElementById('startTime');
        const endTimeInput = document.getElementById('endTime');
        
        if (startTimeInput) {
            // 移除舊的事件監聽器
            if (data.eventHandlers.startTimeChange) {
                startTimeInput.removeEventListener('change', data.eventHandlers.startTimeChange);
            }
            
            const handleStartTimeChange = async function() {
                if (this.value) {
                    updateStartTime(this.value);
                    // 顯示結束時間欄位（如果是新增時程表模式）
                    const endTimeContainer = document.getElementById('endTimeContainer');
                    if (endTimeContainer && !data.currentTinformaID) {
                        // 新增時程表模式：選擇開始時間後顯示結束時間
                        endTimeContainer.style.display = 'block';
                    }
                    // 驗證結束時間（如果已設定）
                    if (endTimeInput && endTimeInput.value) {
                        validateTimeRange();
                    }
                    
                    // 如果還沒有團隊資料，自動載入團隊（新增時程表模式）
                    const cohortSelect = document.getElementById('cohortSelect');
                    const cohort_ID = cohortSelect ? cohortSelect.value : null;
                    
                    if (cohort_ID && (!data.teams || data.teams.length === 0) && !data.currentTinformaID) {
                        console.log('開始時間已設定，自動載入團隊資料，屆別:', cohort_ID);
                        try {
                            await loadTeams(cohort_ID);
                            console.log('團隊資料載入完成，團隊數量:', data.teams ? data.teams.length : 0);
                        } catch (error) {
                            console.error('自動載入團隊資料失敗:', error);
                        }
                    }
                    
                    // 更新場次準備和上台報告說明的時間
                    if (data.isEditMode) {
                        ensureDefaultSpecialTimes();
                        // 重新渲染表格以顯示更新的時間
                        renderScheduleTable();
                    }
                }
            };
            
            data.eventHandlers.startTimeChange = handleStartTimeChange;
            startTimeInput.addEventListener('change', handleStartTimeChange);
        }
        
        // 結束時間是自動計算的，不需要手動輸入
        // 但保留驗證功能以防未來需要
        if (endTimeInput) {
            // 移除舊的事件監聽器
            if (data.eventHandlers.endTimeChange) {
                endTimeInput.removeEventListener('change', data.eventHandlers.endTimeChange);
            }
            
            const handleEndTimeChange = function() {
                validateTimeRange();
            };
            
            data.eventHandlers.endTimeChange = handleEndTimeChange;
            endTimeInput.addEventListener('change', handleEndTimeChange);
        }
        
        // 綁定「儲存」按鈕事件
        const saveBtn = document.getElementById('schedule-save-btn');
        if (saveBtn) {
            // 移除舊的事件監聽器（如果有的話）
            if (data.eventHandlers.saveClick) {
                saveBtn.removeEventListener('click', data.eventHandlers.saveClick);
            }
            
            // 創建新的事件處理函數
            const handleSaveClick = async function() {
                console.log('儲存按鈕被點擊');
                if (!data.isEditMode) {
                    Swal.fire('提示', '請先進入編輯模式', 'warning');
                    return;
                }
                await saveSchedules();
            };
            
            // 保存事件處理函數引用以便後續清理
            data.eventHandlers.saveClick = handleSaveClick;
            
            // 綁定新的事件監聽器
            saveBtn.addEventListener('click', handleSaveClick);
        }
        
        // 綁定「匯出 PDF」按鈕事件
        const exportBtn = document.getElementById('exportPDFBtn');
        if (exportBtn) {
            // 移除舊的事件監聽器（如果有的話）
            if (data.eventHandlers.exportClick) {
                exportBtn.removeEventListener('click', data.eventHandlers.exportClick);
            }
            
            // 創建新的事件處理函數
            const handleExportClick = async function() {
                console.log('匯出 PDF 按鈕被點擊');
                const cohortSelect = document.getElementById('cohortSelect');
                const cohort_ID = cohortSelect ? cohortSelect.value : null;
                
                if (!cohort_ID) {
                    Swal.fire('提示', '請先選擇屆別', 'warning');
                    return;
                }
                
                // 獲取標題
                const titleInput = document.getElementById('scheduleTitle');
                const title = titleInput ? titleInput.value.trim() : '';
                
                // 如果有 currentTinformaID，使用它；否則使用標題
                if (data.currentTinformaID) {
                    exportScheduleFile(null, cohort_ID);
                } else if (title) {
                    exportScheduleFile(title, cohort_ID);
                } else {
                    Swal.fire('提示', '請先選擇或建立時程表', 'warning');
                }
            };
            
            // 保存事件處理函數引用以便後續清理
            data.eventHandlers.exportClick = handleExportClick;
            
            // 綁定新的事件監聽器
            exportBtn.addEventListener('click', handleExportClick);
        }
        
        // 綁定「匯出 Word」按鈕事件
        const exportWordBtn = document.getElementById('exportWordBtn');
        if (exportWordBtn) {
            // 移除舊的事件監聽器（如果有的話）
            if (data.eventHandlers.exportWordClick) {
                exportWordBtn.removeEventListener('click', data.eventHandlers.exportWordClick);
            }
            
            // 創建新的事件處理函數
            const handleExportWordClick = async function() {
                console.log('匯出 Word 按鈕被點擊');
                const cohortSelect = document.getElementById('cohortSelect');
                const cohort_ID = cohortSelect ? cohortSelect.value : null;
                
                if (!cohort_ID) {
                    Swal.fire('提示', '請先選擇屆別', 'warning');
                    return;
                }
                
                // 獲取標題
                const titleInput = document.getElementById('scheduleTitle');
                const title = titleInput ? titleInput.value.trim() : '';
                
                // 如果有 currentTinformaID，使用它；否則使用標題
                if (data.currentTinformaID) {
                    exportScheduleFileWord(null, cohort_ID);
                } else if (title) {
                    exportScheduleFileWord(title, cohort_ID);
                } else {
                    Swal.fire('提示', '請先選擇或建立時程表', 'warning');
                }
            };
            
            // 保存事件處理函數引用以便後續清理
            data.eventHandlers.exportWordClick = handleExportWordClick;
            
            // 綁定新的事件監聽器
            exportWordBtn.addEventListener('click', handleExportWordClick);
        }
        
        // 綁定「關閉/開啟線上評分」按鈕
        const toggleOnlineScoringBtn = document.getElementById('toggleOnlineScoringBtn');
        if (toggleOnlineScoringBtn) {
            if (data.eventHandlers.toggleOnlineScoringClick) {
                toggleOnlineScoringBtn.removeEventListener('click', data.eventHandlers.toggleOnlineScoringClick);
            }
            const handleToggleOnlineScoring = function() {
                data.onlineScoringOpen = !data.onlineScoringOpen;
                updateOnlineScoringUI();
                Swal.fire({
                    icon: 'success',
                    title: data.onlineScoringOpen ? '已開放線上評分' : '已關閉線上評分',
                    timer: 1500,
                    showConfirmButton: false
                });
            };
            data.eventHandlers.toggleOnlineScoringClick = handleToggleOnlineScoring;
            toggleOnlineScoringBtn.addEventListener('click', handleToggleOnlineScoring);
        }
        
        // 綁定特殊時間按鈕事件
        const specialTimeButtons = document.querySelectorAll('.btn-special-time');
        specialTimeButtons.forEach(btn => {
            // 移除舊的事件監聽器（如果有的話）
            const oldHandler = btn.getAttribute('data-handler-bound');
            if (oldHandler === 'true') {
                btn.removeEventListener('click', btn._specialTimeClickHandler);
            }
            
            // 創建新的事件處理函數
            const handleSpecialTimeClick = function() {
                if (!data.isEditMode) {
                    Swal.fire('提示', '請先進入編輯模式', 'warning');
                    return;
                }
                
                const type = this.getAttribute('data-type');
                const label = this.getAttribute('data-label');
                
                // 如果是報告時間，顯示設置對話框
                if (type === 'report_duration') {
                    const currentDuration = data.reportDuration || 20;
                    Swal.fire({
                        title: '設定報告時間',
                        html: `
                            <div class="mb-3">
                                <label>報告時間（分鐘）</label>
                                <input type="number" id="reportDurationDialog" class="form-control" min="1" max="60" value="${currentDuration}" required>
                                <small class="text-muted">範圍：1-60分鐘</small>
                            </div>
                        `,
                        showCancelButton: true,
                        confirmButtonText: '確定',
                        cancelButtonText: '取消',
                        reverseButtons: true,
                        didOpen: () => {
                            const input = document.getElementById('reportDurationDialog');
                            if (input) {
                                input.focus();
                                input.select();
                            }
                        },
                        preConfirm: () => {
                            const input = document.getElementById('reportDurationDialog');
                            const value = parseInt(input.value);
                            if (isNaN(value) || value < 1 || value > 60) {
                                Swal.showValidationMessage('請輸入1-60之間的數字');
                                return false;
                            }
                            return value;
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            const duration = result.value;
                            data.reportDuration = duration;
                            // 重新計算時間
                            calculateTimes();
                            Swal.fire({
                                icon: 'success',
                                title: '設定成功',
                                text: `報告時間已設定為 ${duration} 分鐘`,
                                timer: 1500,
                                showConfirmButton: false
                            });
                        }
                    });
                    return;
                }
                
                // 檢查是否已經存在相同類型的特殊時間行
                const tbody = document.getElementById('scheduleTableBody');
                if (tbody) {
                    const existingRow = tbody.querySelector(`tr.special-time-row[data-special-type="${type}"]`);
                    if (existingRow) {
                        // 已存在時直接開啟修改時間對話框，不再顯示「已存在」提示
                        editExistingSpecialTime(type, existingRow);
                        return;
                    }
                }
                
                // 調用插入特殊時間函數
                insertSpecialTime(type);
            };
            
            // 保存事件處理函數引用
            btn._specialTimeClickHandler = handleSpecialTimeClick;
            btn.setAttribute('data-handler-bound', 'true');
            
            // 綁定事件
            btn.addEventListener('click', handleSpecialTimeClick);
        });
    }
    
    // 初始化函數（立即執行，也支援延遲執行）
    (function() {
        // 如果頁面已經載入，立即執行
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(initScheduleManage, 200);
            });
        } else {
            // 如果 DOM 已經載入完成，延遲執行以確保元素存在
            setTimeout(initScheduleManage, 200);
        }
    })();
    
    // 也支援 initPageScript 模式（AJAX 載入時使用）
    // 保存原有的 initPageScript（如果存在且不是我們自己的）
    let originalInitPageScript = null;
    if (typeof window.initPageScript === 'function' && 
        window.initPageScript.toString().indexOf('時程表管理') === -1) {
        originalInitPageScript = window.initPageScript;
    }
    
    window.initPageScript = function() {
        console.log('initPageScript 被調用（時程表管理）');
        
        // 先調用原有的 initPageScript（如果存在）
        if (typeof originalInitPageScript === 'function') {
            try {
                originalInitPageScript();
            } catch (e) {
                console.warn('調用原有 initPageScript 時出錯:', e);
            }
        }
        
        // 檢查當前頁面是否是時程表管理頁面
        const currentPath = window.location.hash || '';
        if (!currentPath.includes('schedule_manage')) {
            // 不是時程表管理頁面，執行清理並返回
            cleanup();
            window.scheduleManageInitialized = false;
            return;
        }
        
        // 先執行徹底清理
        cleanup();
        
        // 載入屆別選項
        loadCohorts();
        
        // 重置 DOM 狀態
        const tbody = document.getElementById('scheduleTableBody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">請選擇屆別以載入團隊資料</td></tr>';
        }
        
        const fileListContainer = document.getElementById('schedule-file-list');
        if (fileListContainer) {
            fileListContainer.innerHTML = '';
            // 先隱藏，但不要設置為 none，因為 CSS 中已經有 grid 設置
            // 讓 checkAndDisplayMode 來決定是否顯示
            fileListContainer.style.display = '';
        }
        
        const tableCard = document.getElementById('schedule-table-card');
        if (tableCard) {
            tableCard.style.display = 'none';
        }
        
        // 重置輸入欄位
        const scheduleTitle = document.getElementById('scheduleTitle');
        if (scheduleTitle) {
            scheduleTitle.value = '';
            scheduleTitle.disabled = true;
        }
        
        const startTime = document.getElementById('startTime');
        if (startTime) {
            startTime.value = '';
            startTime.disabled = true;
        }
        
        const endTime = document.getElementById('endTime');
        const endTimeContainer = document.getElementById('endTimeContainer');
        if (endTime) {
            endTime.value = '';
            endTime.disabled = true;
        }
        // 隱藏結束時間欄位
        if (endTimeContainer) {
            endTimeContainer.style.display = 'none';
        }
        
        // 重置按鈕狀態
        const saveBtn = document.getElementById('schedule-save-btn');
        const exportBtn = document.getElementById('exportPDFBtn');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.style.display = 'none';
        }
        if (exportBtn) {
            exportBtn.disabled = true;
            exportBtn.style.display = 'none';
        }
        const exportWordBtn = document.getElementById('exportWordBtn');
        if (exportWordBtn) {
            exportWordBtn.disabled = true;
            exportWordBtn.style.display = 'none';
        }
        const onlineScoringBox = document.getElementById('online-scoring-box');
        if (onlineScoringBox) onlineScoringBox.style.display = 'none';
        
        // 重置標題顯示
        const titleDisplay = document.getElementById('scheduleTitleDisplay');
        if (titleDisplay) {
            titleDisplay.textContent = '時程表';
        }
        
        // 重置屆別選擇
        const cohortSelect = document.getElementById('cohortSelect');
        if (cohortSelect) {
            cohortSelect.value = '';
        }
        
        // 設置強制重新初始化標記
        window.forceReinit = true;
        // 清除初始化標記，允許重新初始化
        window.scheduleManageInitialized = false;
        
        // 重置資料狀態
        if (window.scheduleManageData) {
            window.scheduleManageData.teams = [];
            window.scheduleManageData.schedules = [];
            window.scheduleManageData.specialTimeRows = [];
            window.scheduleManageData.specialTimesList = [];
            window.scheduleManageData.currentTinformaID = null;
            window.scheduleManageData.startTime = null;
            window.scheduleManageData.isLoading = false;
            window.scheduleManageData.isEditMode = false;
            window.scheduleManageData.hasClickedEdit = false;
            window.scheduleManageData.currentCohortId = null;
            window.scheduleManageData.currentGroupId = null;
            window.scheduleManageData.onlineScoringOpen = true;
        }
        
        // 重新執行初始化（延遲一點確保 DOM 已更新）
        setTimeout(initScheduleManage, 300);
    };
    
    // 監聽頁面切換事件（當切換到其他頁面時）
    window.addEventListener('pageBeforeUnload', function() {
        console.log('時程表管理：頁面即將卸載，清理資源');
        cleanup();
        // 清除初始化標記，允許下次重新初始化
        window.scheduleManageInitialized = false;
    });
    
    // 頁面卸載時清理
    window.addEventListener('beforeunload', cleanup);
    
    // 將函數暴露到全域作用域
    window.initScheduleManage = initScheduleManage;
    window.scheduleManageCleanup = cleanup;


    // 根據時程資料載入對應的團隊（只載入有時程記錄的團隊，保持資料庫中的順序）
    // skipLoadScheduleInfo: 如果為 true，跳過調用 loadScheduleInfoForRender（避免覆蓋已載入的時程資料）
    async function loadTeamsFromSchedules(cohort_ID, skipLoadScheduleInfo = false) {
        if (!cohort_ID) {
            const tbody = document.getElementById('scheduleTableBody');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">請選擇屆別</td></tr>';
            }
            return;
        }
        
        const tbody = document.getElementById('scheduleTableBody');
        if (!tbody) {
            console.error('找不到 scheduleTableBody 元素');
            return;
        }
        
        // 設置載入狀態
        data.isLoading = true;
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i>載入中...</td></tr>';
        
        try {
            // 如果沒有時程資料，顯示空訊息
            if (!data.schedules || data.schedules.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">該時程表目前沒有團隊資料</td></tr>';
                data.teams = [];
                data.isLoading = false;
                return;
            }
            
            // 從時程資料中提取團隊ID列表（保持順序），過濾掉null和undefined，並去重
            const allTeamIds = data.schedules.map(s => s.team_ID).filter(id => id != null);
            // 去重：確保每個 team_ID 只出現一次（保留第一個出現的）
            const seenTeamIds = new Set();
            const teamIds = [];
            allTeamIds.forEach(teamId => {
                if (!seenTeamIds.has(teamId)) {
                    seenTeamIds.add(teamId);
                    teamIds.push(teamId);
                }
            });
            
            console.log('=== loadTeamsFromSchedules: 載入團隊 ===');
            console.log('當前 currentTinformaID:', data.currentTinformaID);
            console.log('時程資料數量:', data.schedules.length);
            console.log('時程資料中的 tinforma_ID:', [...new Set(data.schedules.map(s => s.tinforma_ID))]);
            console.log('原始團隊ID列表:', allTeamIds);
            console.log('去重後的團隊ID列表（保持順序）:', teamIds);
            
            // 如果沒有有效的團隊ID，顯示提示
            if (teamIds.length === 0) {
                console.warn('警告：時程資料中沒有有效的團隊ID');
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">該時程表目前沒有團隊資料</td></tr>';
                data.teams = [];
                data.isLoading = false;
                return;
            }
            
            // 獲取這些團隊的詳細資料
            const apiUrl = resolveScheduleApiUrl();
            const url = `${apiUrl}?action=listTeams&cohort_ID=${cohort_ID}`;
            
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const responseData = await response.json();
            console.log('API 回應:', responseData);
            
            if (responseData.success && responseData.data) {
                // 獲取所有團隊資料
                const allTeams = responseData.data || [];
                
                // 只保留在時程資料中存在的團隊，並按照時程資料的順序排列
                const teamsMap = {};
                allTeams.forEach(team => {
                    teamsMap[team.team_ID] = team;
                });
                
                // 按照時程資料的順序構建團隊列表（已去重）
                data.teams = [];
                teamIds.forEach(teamId => {
                    if (teamsMap[teamId] && !data.teams.find(t => t.team_ID === teamId)) {
                        // 再次檢查避免重複（雙重保險）
                        data.teams.push(teamsMap[teamId]);
                    }
                });
                
                console.log('載入的團隊數量（只包含有時程記錄的）:', data.teams.length);
                console.log('團隊順序:', data.teams.map(t => t.team_ID));
                
                // 只有在不是從 openScheduleFile 調用時才載入時程表資訊
                // 因為 loadScheduleInfoForRender 會載入最新的時程表，會覆蓋已載入的資料
                if (!skipLoadScheduleInfo) {
                    // 載入時程表資訊（包含特殊時間段）
                    await loadScheduleInfoForRender();
                } else {
                    // 直接渲染表格，因為時程資料已經載入
                    renderScheduleTable();
                }
            } else {
                const errorMsg = responseData.msg || '載入團隊資料失敗';
                console.error('API 返回錯誤:', errorMsg, responseData);
                tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4">${errorMsg}</td></tr>`;
            }
        } catch (error) {
            console.error('載入團隊資料錯誤:', error);
            tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4">無法載入團隊資料：${error.message}</td></tr>`;
        } finally {
            data.isLoading = false;
        }
    }

    // 載入團隊資料
    // 載入團隊列表（用於新增時程表，不載入現有時程）
    async function loadTeamsForNewSchedule(cohort_ID) {
        // 防止重複載入
        if (data.isLoading) {
            console.log('正在載入中，跳過重複請求');
            return;
        }
        
        if (!cohort_ID) {
            const tbody = document.getElementById('scheduleTableBody');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">請選擇屆別</td></tr>';
            }
            return;
        }
        
        const tbody = document.getElementById('scheduleTableBody');
        if (!tbody) {
            console.error('找不到 scheduleTableBody 元素');
            return;
        }
        
        // 設置載入狀態
        data.isLoading = true;
        
        // 顯示載入中
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i>載入中...</td></tr>';
        
        try {
            const apiUrl = resolveScheduleApiUrl();
            const url = `${apiUrl}?action=listTeams&cohort_ID=${cohort_ID}`;
            
            console.log('載入團隊資料（新增模式），完整 URL:', url);
            
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const responseData = await response.json();
            console.log('API 回應:', responseData);
            
            if (responseData.success && responseData.data) {
                // 將 API 返回的資料保存到 window.scheduleManageData
                data.teams = responseData.data || [];
                
                console.log('API 返回的團隊資料:', data.teams);
                if (data.teams.length > 0) {
                    console.log('第一個團隊的完整資料:', JSON.stringify(data.teams[0], null, 2));
                }
                
                if (data.teams.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">該屆別目前沒有團隊資料</td></tr>';
                    data.isLoading = false;
                    return;
                }
                
                // 預設順序：按照類組排序（商務網站經營組 group_ID=2 在前，系統軟體開發組 group_ID=1 在後），再按團隊ID
                console.log('=== loadTeamsForNewSchedule: 排序前的團隊順序 ===');
                console.log(data.teams.map(t => ({ team_ID: t.team_ID, group_ID: t.group_ID, group_ID_type: typeof t.group_ID, group_name: t.group_name })));
                
                data.teams.sort((a, b) => {
                    // 確保 group_ID 是數字類型
                    const groupA = parseInt(a.group_ID) || 999;
                    const groupB = parseInt(b.group_ID) || 999;
                    // 自定義排序：2（商務網站經營組）優先於 1（系統軟體開發組/資訊組），其他按數字順序
                    const groupOrderA = (groupA === 2) ? 0 : ((groupA === 1) ? 1 : groupA + 10);
                    const groupOrderB = (groupB === 2) ? 0 : ((groupB === 1) ? 1 : groupB + 10);
                    if (groupOrderA !== groupOrderB) {
                        return groupOrderA - groupOrderB;
                    }
                    // 類組相同時，按團隊ID排序
                    return a.team_ID - b.team_ID;
                });
                
                console.log('=== loadTeamsForNewSchedule: 排序後的團隊順序 ===');
                console.log(data.teams.map(t => ({ team_ID: t.team_ID, group_ID: t.group_ID, group_name: t.group_name })));
                
                console.log('載入的團隊數量:', data.teams.length);
                console.log('第一個團隊的資料結構:', data.teams[0]);
                
                // 確保場次準備和上台報告說明存在
                ensureDefaultSpecialTimes();
                
                // 直接渲染空白時程表（不載入現有時程）
                renderScheduleTable();
            } else {
                const errorMsg = responseData.msg || '載入團隊資料失敗';
                console.error('API 返回錯誤:', errorMsg, responseData);
                tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4">${errorMsg}</td></tr>`;
            }
        } catch (error) {
            console.error('載入團隊資料錯誤:', error);
            tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4">無法載入團隊資料：${error.message}</td></tr>`;
        } finally {
            // 重置載入狀態
            data.isLoading = false;
        }
    }
    
    async function loadTeams(cohort_ID) {
        // 防止重複載入
        if (data.isLoading) {
            console.log('正在載入中，跳過重複請求');
            return;
        }
        
        if (!cohort_ID) {
            const tbody = document.getElementById('scheduleTableBody');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">請選擇屆別</td></tr>';
            }
            return;
        }
        
        const tbody = document.getElementById('scheduleTableBody');
        if (!tbody) {
            console.error('找不到 scheduleTableBody 元素');
            return;
        }
        
        // 設置載入狀態
        data.isLoading = true;
        
        // 顯示載入中
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i>載入中...</td></tr>';
        
        try {
        // 動態判斷 API 路徑
        // 從當前 URL 計算正確的 API 路徑
        const getApiPath = function() {
            const pathname = window.location.pathname || '';
            const hash = window.location.hash || '';
            
            // 如果路徑包含 /main.php，API 在根目錄
            if (pathname.includes('/main.php')) {
                // 從 pathname 提取項目根目錄
                const mainIndex = pathname.indexOf('/main.php');
                const projectRoot = pathname.substring(0, mainIndex);
                return projectRoot + (projectRoot.endsWith('/') ? '' : '/') + 'api.php';
            }
            
            // 如果 hash 包含 pages/，表示是通過 AJAX 載入的，API 在上一層
            if (hash.includes('pages/') || pathname.includes('/pages/')) {
                return '../api.php';
            }
            
            // 預設使用相對路徑
            return 'api.php';
        };

        const apiUrl = resolveScheduleApiUrl();
        const url = `${apiUrl}?action=listTeams&cohort_ID=${cohort_ID}`;
        
        console.log('載入團隊資料，完整 URL:', url);
        
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const responseData = await response.json();
            console.log('API 回應:', responseData);
            
            if (responseData.success && responseData.data) {
                // 將 API 返回的資料保存到 window.scheduleManageData
                data.teams = responseData.data || [];
                
                console.log('API 返回的團隊資料:', data.teams);
                
                if (data.teams.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">該屆別目前沒有團隊資料</td></tr>';
                    data.isLoading = false;
                    return;
                }
                
                // 預設順序：按照類組排序（商務網站經營組 group_ID=2 在前，系統軟體開發組 group_ID=1 在後），再按團隊ID
                data.teams.sort((a, b) => {
                    // 確保 group_ID 是數字類型
                    const groupA = parseInt(a.group_ID) || 999;
                    const groupB = parseInt(b.group_ID) || 999;
                    // 自定義排序：2（商務網站經營組）優先於 1（系統軟體開發組/資訊組），其他按數字順序
                    const groupOrderA = (groupA === 2) ? 0 : ((groupA === 1) ? 1 : groupA + 10);
                    const groupOrderB = (groupB === 2) ? 0 : ((groupB === 1) ? 1 : groupB + 10);
                    if (groupOrderA !== groupOrderB) {
                        return groupOrderA - groupOrderB;
                    }
                    // 類組相同時，按團隊ID排序
                    return a.team_ID - b.team_ID;
                });
                
                console.log('載入的團隊數量:', data.teams.length);
                console.log('第一個團隊的資料結構:', data.teams[0]);
                console.log('所有團隊資料:', data.teams);
                
                // 載入時程表資訊（包含特殊時間段）
                console.log('開始載入時程表資訊...');
                await loadScheduleInfoForRender();
                console.log('時程表資訊載入完成，將渲染表格');
            } else {
                const errorMsg = responseData.msg || '載入團隊資料失敗';
                console.error('API 返回錯誤:', errorMsg, responseData);
                tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4">${errorMsg}</td></tr>`;
            }
        } catch (error) {
            console.error('載入團隊資料錯誤:', error);
            tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4">無法載入團隊資料：${error.message}</td></tr>`;
        } finally {
            // 重置載入狀態
            data.isLoading = false;
        }
    }

    // 載入時程表資訊
    async function loadScheduleInfo() {
        try {
            const response = await fetch('../api.php?do=get_schedule_info');
            const responseData = await response.json();
            
            if (responseData.ok) {
                if (responseData.info) {
                    data.currentTinformaID = responseData.info.tinforma_ID;
                    const scheduleInfoEl = document.getElementById('scheduleInfo');
                    if (scheduleInfoEl) {
                        scheduleInfoEl.value = responseData.info.tinforma_content || '';
                    }
                    
                    // 解析特殊時間段
                    data.specialTimesList = parseSpecialTimes(responseData.info.tinforma_content);
                    console.log('從資料庫載入的特殊時間段:', data.specialTimesList);
                }
                
                // 載入時程資料並去重（確保每個 team_ID 只出現一次）
                const loadedSchedules = responseData.schedules || [];
                const uniqueSchedulesMap = new Map();
                loadedSchedules.forEach(schedule => {
                    const teamId = schedule.team_ID;
                    if (teamId) {
                        // 如果已存在該團隊，保留第一個（或可以保留最後一個）
                        if (!uniqueSchedulesMap.has(teamId)) {
                            uniqueSchedulesMap.set(teamId, schedule);
                        } else {
                            console.warn('載入時發現重複的團隊時程，team_ID:', teamId, '已跳過重複項');
                        }
                    }
                });
                data.schedules = Array.from(uniqueSchedulesMap.values());
                
                // 如果有已保存的時程，使用保存的時間
                if (data.schedules.length > 0) {
                    updateScheduleTable();
                } else {
                    // 否則使用預設時間計算
                    renderScheduleTable();
                }
            } else {
                Swal.fire('錯誤', responseData.msg || '載入時程表資訊失敗', 'error');
            }
        } catch (error) {
            console.error('載入時程表資訊錯誤:', error);
            Swal.fire('錯誤', '無法載入時程表資訊', 'error');
        }
    }
    
    // 解析特殊時間段
    function parseSpecialTimes(content) {
        console.log('=== parseSpecialTimes 開始解析 ===');
        console.log('輸入內容:', content);
        console.log('內容類型:', typeof content);
        console.log('內容長度:', content?.length);
        
        if (!content) {
            console.warn('警告：內容為空');
            return [];
        }
        
        const specialTimes = [];
        
        // 嘗試解析 JSON 格式（新格式）
        try {
            const jsonData = JSON.parse(content);
            console.log('成功解析 JSON:', jsonData);
            console.log('JSON 是陣列:', Array.isArray(jsonData));
            
            if (Array.isArray(jsonData)) {
                console.log('JSON 陣列長度:', jsonData.length);
                // 按照 row_order 排序（如果沒有 row_order，使用 sortOrder 作為向後兼容）
                jsonData.sort((a, b) => {
                    const orderA = a.row_order ?? a.sortOrder ?? 9999;
                    const orderB = b.row_order ?? b.sortOrder ?? 9999;
                    return orderA - orderB;
                });
                
                jsonData.forEach((item, index) => {
                    console.log(`處理項目 ${index}:`, item);
                    // 跳過 schedule_start 類型，它只是元數據，不應該顯示在表格中
                    if (item.type === 'schedule_start') {
                        console.log('跳過 schedule_start 類型');
                        return;
                    }
                    
                    // 處理報告時間間隔
                    if (item.type === 'report_duration' && item.duration) {
                        const duration = Math.max(1, Math.min(60, parseInt(item.duration) || 20));
                        data.reportDuration = duration;
                        console.log('載入報告時間間隔:', duration, '分鐘');
                        return;
                    }
                    
                    if (item.type && item.start && item.end) {
                        const timeRange = `${item.start}-${item.end}`;
                        console.log(`處理特殊時間: type=${item.type}, start=${item.start}, end=${item.end}, timeRange=${timeRange}`);
                        
                        // 更新對應的特殊時間資料
                        if (item.type === 'preparation') {
                            data.specialTimes.preparation = { start: item.start, end: item.end };
                        } else if (item.type === 'presentation_instruction') {
                            data.specialTimes.presentation_instruction = { start: item.start, end: item.end };
                        } else if (item.type === 'lunch') {
                            data.specialTimes.lunch = { start: item.start, end: item.end };
                        } else if (item.type === 'break') {
                            data.specialTimes.break = { start: item.start, end: item.end };
                        }
                        
                        // 獲取標籤
                        let label = '';
                        if (item.type === 'preparation') {
                            label = '場次預備';
                        } else if (item.type === 'presentation_instruction') {
                            label = '上台報告說明';
                        } else if (item.type === 'lunch') {
                            label = '午餐時間';
                        } else if (item.type === 'break') {
                            label = '休息時間';
                        }
                        
                        const specialTimeItem = {
                            type: item.type,
                            label: label,
                            timeRange: timeRange,
                            timeStart: item.start,
                            row_order: item.row_order ?? item.sortOrder ?? 0 // 優先使用 row_order，向後兼容 sortOrder
                        };
                        console.log('添加特殊時間項目:', specialTimeItem);
                        specialTimes.push(specialTimeItem);
                    } else {
                        console.warn('項目缺少必要欄位:', item);
                    }
                });
                
                console.log('解析完成，返回', specialTimes.length, '個特殊時間段');
                return specialTimes;
            } else {
                console.warn('JSON 不是陣列格式');
            }
        } catch (e) {
            // 如果不是 JSON 格式，嘗試解析舊的文字格式（向後兼容）
            console.log('內容不是 JSON 格式，嘗試解析舊格式:', e);
        }
        
        // 解析舊格式（向後兼容）
        // 解析場次預備
        const prepMatch = content.match(/場次預備[：:]\s*(\d{1,2}):(\d{2})\s*[-~]\s*(\d{1,2}):(\d{2})/);
        if (prepMatch) {
            const start = `${prepMatch[1].padStart(2, '0')}:${prepMatch[2]}`;
            const end = `${prepMatch[3].padStart(2, '0')}:${prepMatch[4]}`;
            data.specialTimes.preparation = { start, end };
            specialTimes.push({
                type: 'preparation',
                label: '場次預備',
                timeRange: `${start}-${end}`,
                timeStart: start
            });
        }
        
        // 解析上台報告說明
        const instMatch = content.match(/上台報告說明[：:]\s*(\d{1,2}):(\d{2})\s*[-~]\s*(\d{1,2}):(\d{2})/);
        if (instMatch) {
            const start = `${instMatch[1].padStart(2, '0')}:${instMatch[2]}`;
            const end = `${instMatch[3].padStart(2, '0')}:${instMatch[4]}`;
            data.specialTimes.presentation_instruction = { start, end };
            specialTimes.push({
                type: 'presentation_instruction',
                label: '上台報告說明',
                timeRange: `${start}-${end}`,
                timeStart: start
            });
        }
        
        // 解析午餐時間
        const lunchMatch = content.match(/午餐時間[：:]\s*(\d{1,2}):(\d{2})\s*[-~]\s*(\d{1,2}):(\d{2})/);
        if (lunchMatch) {
            const start = `${lunchMatch[1].padStart(2, '0')}:${lunchMatch[2]}`;
            const end = `${lunchMatch[3].padStart(2, '0')}:${lunchMatch[4]}`;
            data.specialTimes.lunch.start = start;
            data.specialTimes.lunch.end = end;
            specialTimes.push({
                type: 'lunch',
                label: '午餐時間',
                timeRange: `${start}-${end}`,
                timeStart: start
            });
        }
        
        // 解析中場休息
        const breakMatch = content.match(/中場休息[：:]\s*(\d{1,2}):(\d{2})\s*[-~]\s*(\d{1,2}):(\d{2})/);
        if (breakMatch) {
            const start = `${breakMatch[1].padStart(2, '0')}:${breakMatch[2]}`;
            const end = `${breakMatch[3].padStart(2, '0')}:${breakMatch[4]}`;
            data.specialTimes.break.start = start;
            data.specialTimes.break.end = end;
            specialTimes.push({
                type: 'break',
                label: '休息時間',
                timeRange: `${start}-${end}`,
                timeStart: start
            });
        }
        
        return specialTimes;
    }

    // 載入時程表資訊用於渲染
    async function loadScheduleInfoForRender() {
        try {
            const getApiPath = function() {
                const pathname = window.location.pathname || '';
                const hash = window.location.hash || '';
                if (pathname.includes('/main.php')) {
                    const mainIndex = pathname.indexOf('/main.php');
                    const projectRoot = pathname.substring(0, mainIndex);
                    return projectRoot + (projectRoot.endsWith('/') ? '' : '/') + 'api.php';
                }
                if (hash.includes('pages/') || pathname.includes('/pages/')) {
                    return '../api.php';
                }
                return 'api.php';
            };
            
            const apiPath = getApiPath();
            const cohortSelect = document.getElementById('cohortSelect');
            const cohort_ID = cohortSelect ? cohortSelect.value : null;
            
            // 如果是新增時程表模式（currentTinformaID 為 null），不載入現有時程表資訊
            const isNewScheduleMode = !data.currentTinformaID;
            console.log('載入時程表資訊，isNewScheduleMode:', isNewScheduleMode, 'currentTinformaID:', data.currentTinformaID);
            
            // 嘗試獲取時程表資訊
            let scheduleInfo = null;
            let tinforma_ID = null;
            
            // 只有在不是新增模式時才載入現有時程表資訊
            if (!isNewScheduleMode) {
                try {
                    console.log('開始獲取時程表資訊，API 路徑:', apiPath);
                    
                    // 直接獲取最新的時程表資訊
                    const scheduleResponse = await fetch(`${apiPath}?do=get_schedule_info`);
                    console.log('時程表 API 回應狀態:', scheduleResponse.status);
                    
                    if (!scheduleResponse.ok) {
                        throw new Error(`HTTP ${scheduleResponse.status}`);
                    }
                    
                    const scheduleData = await scheduleResponse.json();
                    console.log('時程表 API 回應資料:', scheduleData);
                    
                    if (scheduleData.ok && scheduleData.info) {
                        scheduleInfo = scheduleData.info.tinforma_content;
                        tinforma_ID = scheduleData.info.tinforma_ID;
                        data.currentTinformaID = tinforma_ID; // 設置 currentTinformaID
                        console.log('成功獲取時程表資訊，tinforma_ID:', tinforma_ID);
                        console.log('時程表內容:', scheduleInfo);
                        
                        // 載入標題
                        const titleInput = document.getElementById('scheduleTitle');
                        const titleDisplay = document.getElementById('scheduleTitleDisplay');
                        if (titleInput && scheduleData.info.tinforma_title) {
                            titleInput.value = scheduleData.info.tinforma_title;
                            if (titleDisplay) {
                                titleDisplay.textContent = scheduleData.info.tinforma_title;
                            }
                        } else if (titleInput && !scheduleData.info.tinforma_title) {
                            // 如果沒有標題，清空輸入欄位
                            titleInput.value = '';
                            if (titleDisplay) {
                                titleDisplay.textContent = '時程表';
                            }
                        }
                        
                        // 載入時程資料並去重（確保每個 team_ID 只出現一次）
                        if (scheduleData.schedules) {
                            const loadedSchedules = scheduleData.schedules;
                            const uniqueSchedulesMap = new Map();
                            loadedSchedules.forEach(schedule => {
                                const teamId = schedule.team_ID;
                                if (teamId) {
                                    // 如果已存在該團隊，保留 sort_no 較小的
                                    const existing = uniqueSchedulesMap.get(teamId);
                                    if (!existing) {
                                        uniqueSchedulesMap.set(teamId, schedule);
                                    } else {
                                        const existingSortNo = existing.sort_no ?? 999999;
                                        const currentSortNo = schedule.sort_no ?? 999999;
                                        if (currentSortNo < existingSortNo) {
                                            uniqueSchedulesMap.set(teamId, schedule);
                                            console.warn('載入時發現重複的團隊時程，team_ID:', teamId, '保留 sort_no 較小的');
                                        } else {
                                            console.warn('載入時發現重複的團隊時程，team_ID:', teamId, '已跳過重複項');
                                        }
                                    }
                                }
                            });
                            data.schedules = Array.from(uniqueSchedulesMap.values());
                            console.log('載入時程資料，原始數量:', loadedSchedules.length, '去重後數量:', data.schedules.length);
                        }
                        
                        // 解析特殊時間並載入報告時間間隔
                        if (scheduleInfo) {
                            try {
                                const jsonData = JSON.parse(scheduleInfo);
                                if (Array.isArray(jsonData)) {
                                    const reportDurationItem = jsonData.find(item => item.type === 'report_duration');
                                    if (reportDurationItem && reportDurationItem.duration) {
                                        const duration = Math.max(1, Math.min(60, parseInt(reportDurationItem.duration) || 20));
                                        data.reportDuration = duration;
                                        console.log('載入報告時間間隔:', duration, '分鐘');
                                    }
                                }
                            } catch (e) {
                                console.warn('解析報告時間間隔失敗:', e);
                            }
                        }
                        
                        // 載入現有時程表時，只有在編輯模式下才顯示結束時間欄位
                        // 注意：這裡 tinforma_ID 存在表示有現有時程表，但只有在編輯時才顯示結束時間
                        // 如果是新增模式（currentTinformaID 為 null），不顯示結束時間
                        const endTimeContainer = document.getElementById('endTimeContainer');
                        if (endTimeContainer) {
                            // 只有在編輯現有時程表時才顯示結束時間
                            // 新增模式下，需要等選擇開始時間後才顯示
                            if (tinforma_ID && data.currentTinformaID === tinforma_ID) {
                                // 編輯現有時程表，顯示結束時間
                                endTimeContainer.style.display = 'block';
                            } else {
                                // 新增模式或未選擇開始時間，隱藏結束時間
                                endTimeContainer.style.display = 'none';
                            }
                        }
                    } else {
                        console.warn('時程表 API 回應異常:', scheduleData);
                        data.currentTinformaID = null; // 清除 currentTinformaID
                    }
                } catch (e) {
                    console.error('獲取時程表資訊失敗:', e);
                }
            } else {
                console.log('新增時程表模式，跳過載入現有時程表資訊');
                // 新增模式下，確保 currentTinformaID 為 null，不載入現有時程
                data.currentTinformaID = null;
            }
            
            // 解析特殊時間段
            console.log('時程表資訊:', scheduleInfo);
            if (scheduleInfo) {
                // 先解析報告時間間隔
                try {
                    const jsonData = JSON.parse(scheduleInfo);
                    if (Array.isArray(jsonData)) {
                        const reportDurationItem = jsonData.find(item => item.type === 'report_duration');
                        if (reportDurationItem && reportDurationItem.duration) {
                            const duration = Math.max(1, Math.min(60, parseInt(reportDurationItem.duration) || 20));
                            data.reportDuration = duration;
                            console.log('載入報告時間間隔:', duration, '分鐘');
                        }
                    }
                } catch (e) {
                    console.warn('解析報告時間間隔失敗:', e);
                }
                
                data.specialTimesList = parseSpecialTimes(scheduleInfo);
                console.log('解析的特殊時間段:', data.specialTimesList);
            } else {
                data.specialTimesList = [];
            }
            
            // 確保場次準備和上台報告說明存在
            // 如果從資料庫載入的資料中沒有，則使用預設值
            // 注意：只有在確實沒有從資料庫載入特殊時間時，才添加預設值
            const hasPreparation = data.specialTimesList.some(item => item.type === 'preparation');
            const hasPresentationInstruction = data.specialTimesList.some(item => item.type === 'presentation_instruction');
            
            // 只有在編輯模式下，且確實沒有從資料庫載入場次準備或上台報告說明時，才添加預設值
            // 如果已經有從資料庫載入的特殊時間，不應該覆蓋它們
            if (data.isEditMode && (!hasPreparation || !hasPresentationInstruction) && data.specialTimesList.length === 0) {
                ensureDefaultSpecialTimes();
            }
            
            // 確保 data.specialTimesList 是一個陣列
            if (!Array.isArray(data.specialTimesList)) {
                data.specialTimesList = [];
                console.log('data.specialTimesList 不是陣列，已初始化為空陣列');
            }
            
            // 查找並設置開始時間
            // 如果是從數據庫載入（非新增模式），應該覆蓋輸入框的值
            // 如果是新增模式或輸入框已有值，只在輸入框為空時才設置
            const scheduleStart = data.specialTimesList.find(item => item && item.type === 'schedule_start');
            if (scheduleStart && scheduleStart.start) {
                const startTimeInput = document.getElementById('startTime');
                if (startTimeInput) {
                    // 從數據庫載入時應該覆蓋，新增模式或編輯模式下只在輸入框為空時才設置
                    const shouldOverride = !isNewScheduleMode && !data.isEditMode;
                    if (shouldOverride || !startTimeInput.value) {
                        try {
                            // 將時間設置為當天
                            const now = new Date();
                            const [hours, minutes] = scheduleStart.start.toString().split(':');
                            now.setHours(parseInt(hours, 10), parseInt(minutes, 10), 0, 0);
                            
                            // 格式化為 datetime-local 所需的格式
                            const formattedDateTime = now.toISOString().slice(0, 16);
                            startTimeInput.value = formattedDateTime;
                            
                            // 更新 data 對象中的開始時間
                            data.startTime = new Date(formattedDateTime);
                            console.log('已設置開始時間:', data.startTime);
                        } catch (e) {
                            console.error('設置開始時間時發生錯誤:', e);
                        }
                    } else {
                        // 如果輸入框已有值，同步更新 data.startTime
                        try {
                            const inputDate = new Date(startTimeInput.value);
                            if (!isNaN(inputDate.getTime())) {
                                data.startTime = inputDate;
                            }
                        } catch (e) {
                            console.warn('同步開始時間時發生錯誤:', e);
                        }
                    }
                }
            } else {
                console.log('未找到有效的開始時間數據');
            }
            
            // 渲染表格
            renderScheduleTable();
        } catch (error) {
            console.error('載入時程表資訊錯誤:', error);
            data.specialTimesList = [];
            renderScheduleTable();
        }
    }

    // 場次準備和上台報告說明改由使用者自行輸入時間，不再綁定開始時間
    function ensureDefaultSpecialTimes() {
        // 不再自動添加或更新場次準備、上台報告說明
    }
    
    // 渲染時程表
    function renderScheduleTable() {
        // 確保開始時間已設置（只在輸入框為空時才設置，避免覆蓋用戶輸入）
        const startTimeInput = document.getElementById('startTime');
        if (startTimeInput && !startTimeInput.value && data.startTime) {
            let startDate;
            
            // 處理不同類型的 startTime：字符串（如 "10:00"）或 Date 對象
            if (data.startTime instanceof Date) {
                // 如果是 Date 對象，直接使用
                startDate = data.startTime;
            } else if (typeof data.startTime === 'string') {
                // 如果是字符串，解析時間
                const timeMatch = data.startTime.match(/(\d{1,2}):(\d{2})/);
                if (timeMatch) {
                    const [, hours, minutes] = timeMatch;
                    startDate = new Date();
                    startDate.setHours(parseInt(hours, 10), parseInt(minutes, 10), 0, 0);
                } else {
                    // 如果無法解析，嘗試直接轉換為 Date
                    startDate = new Date(data.startTime);
                    if (isNaN(startDate.getTime())) {
                        console.warn('無法解析開始時間:', data.startTime);
                        startDate = null;
                    }
                }
            } else {
                console.warn('開始時間類型不正確:', typeof data.startTime, data.startTime);
                startDate = null;
            }
            
            // 只在輸入框為空時才更新
            if (startDate && !isNaN(startDate.getTime())) {
                startTimeInput.value = startDate.toISOString().slice(0, 16);
            }
        }
        
        // 如果輸入框有值，同步更新 data.startTime（確保一致性）
        if (startTimeInput && startTimeInput.value) {
            try {
                const inputDate = new Date(startTimeInput.value);
                if (!isNaN(inputDate.getTime())) {
                    // 只在 data.startTime 為空或類型不一致時才更新
                    if (!data.startTime || !(data.startTime instanceof Date) || 
                        data.startTime.getTime() !== inputDate.getTime()) {
                        data.startTime = inputDate;
                    }
                }
            } catch (e) {
                console.warn('同步開始時間時發生錯誤:', e);
            }
        }
        
        const tbody = document.getElementById('scheduleTableBody');
        if (!tbody) {
            console.error('找不到 scheduleTableBody 元素');
            return;
        }
        
        // 確保場次準備和上台報告說明存在（只在編輯模式下）
        // 注意：只有在確實沒有從資料庫載入特殊時間時，才添加預設值
        // 如果已經有從資料庫載入的特殊時間，不應該覆蓋它們
        if (data.isEditMode) {
            const hasSpecialTimes = data.specialTimesList && Array.isArray(data.specialTimesList) && data.specialTimesList.length > 0;
            if (!hasSpecialTimes) {
                ensureDefaultSpecialTimes();
            }
        }
        
        // 更新表格標題欄（顯示/隱藏操作列）
        const thead = document.querySelector('#scheduleTable thead tr');
        if (thead) {
            const deleteHeader = thead.querySelector('.header-cell-delete');
            if (data.isEditMode) {
                // 編輯模式下，顯示操作列標題
                if (deleteHeader) {
                    deleteHeader.style.display = 'table-cell';
                }
            } else {
                // 非編輯模式下，隱藏操作列標題
                if (deleteHeader) {
                    deleteHeader.style.display = 'none';
                }
            }
        }
        
        console.log('開始渲染時程表，團隊數量:', data.teams.length, '編輯模式狀態:', data.isEditMode);
        
        tbody.innerHTML = '';
        
        // 計算 colspan（表格固定為6列：報告時間、組次、學號、姓名、專題題目、指導老師，編輯模式下加1列操作）
        const colspan = data.isEditMode ? 7 : 6;
        console.log('表格 colspan:', colspan, 'isEditMode:', data.isEditMode);
        
        // 檢查是否有特殊時間段
        const hasSpecialTimes = data.specialTimesList && Array.isArray(data.specialTimesList) && data.specialTimesList.length > 0;
        
        // 如果沒有團隊資料且沒有特殊時間段，顯示提示訊息
        // 注意：檢查 data.schedules 而不是 data.teams，因為保存後可能只有 schedules 而沒有 teams
        const hasSchedules = data.schedules && data.schedules.length > 0;
        const hasTeams = data.teams && data.teams.length > 0;
        if (!hasSchedules && !hasTeams && !hasSpecialTimes) {
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center text-muted py-4">目前沒有團隊資料</td></tr>`;
            return;
        }
        
        // 準備所有行（團隊行 + 特殊時間段行）
        let allRows = [];
        
        // 添加團隊行
        if (data.schedules && data.schedules.length > 0) {
            // 如果有時程資料，按照時程資料的順序和 sort_no
            // 先按照 sort_no 排序時程資料，然後對應到團隊
            const sortedSchedules = [...data.schedules].sort((a, b) => {
                // 先按 sort_no 排序
                const sortA = a.sort_no ?? 999999;
                const sortB = b.sort_no ?? 999999;
                if (sortA !== sortB) {
                    return sortA - sortB;
                }
                // 如果 sort_no 相同，按時間排序
                if (a.time_start_d && b.time_start_d) {
                    return new Date(a.time_start_d) - new Date(b.time_start_d);
                }
                return 0;
            });
            
            // 去重：確保每個 team_ID 只出現一次（保留 sort_no 最小的，如果相同則保留第一個）
            const seenTeamIds = new Map(); // 使用 Map 來追蹤每個 team_ID 的最佳 schedule
            sortedSchedules.forEach((schedule) => {
                const teamId = schedule.team_ID;
                if (teamId) {
                    const existing = seenTeamIds.get(teamId);
                    if (!existing) {
                        seenTeamIds.set(teamId, schedule);
                    } else {
                        // 如果已存在，比較 sort_no，保留較小的
                        const existingSortNo = existing.sort_no ?? 999999;
                        const currentSortNo = schedule.sort_no ?? 999999;
                        if (currentSortNo < existingSortNo) {
                            seenTeamIds.set(teamId, schedule);
                            console.warn('發現重複的團隊時程，team_ID:', teamId, '保留 sort_no 較小的');
                        } else {
                            console.warn('發現重複的團隊時程，team_ID:', teamId, '已跳過重複項');
                        }
                    }
                }
            });
            const uniqueSchedules = Array.from(seenTeamIds.values());
            
            // 再次去重：確保每個團隊只渲染一次（使用 Set 追蹤已添加的團隊）
            const renderedTeamIds = new Set();
            uniqueSchedules.forEach((schedule) => {
                const teamId = schedule.team_ID;
                if (renderedTeamIds.has(teamId)) {
                    console.warn('renderScheduleTable: 發現重複的團隊，team_ID:', teamId, '已跳過');
                    return;
                }
                renderedTeamIds.add(teamId);
                
                const team = data.teams.find(t => t.team_ID == schedule.team_ID);
                if (!team) {
                    console.warn('找不到對應的團隊，team_ID:', schedule.team_ID);
                    return;
                }
                
                let timeStart = '99:99';
                if (schedule.time_start_d) {
                    try {
                        const startDate = new Date(schedule.time_start_d);
                        const hours = String(startDate.getHours()).padStart(2, '0');
                        const minutes = String(startDate.getMinutes()).padStart(2, '0');
                        timeStart = hours + ':' + minutes;
                    } catch (e) {
                        console.warn('解析時間失敗:', schedule.time_start_d, e);
                    }
                }
                
                // 使用 sort_no 作為組次，如果沒有則使用索引+1
                const sequence = schedule.sort_no ?? (uniqueSchedules.indexOf(schedule) + 1);
                
                // 為了正確排序，需要計算團隊行在包含特殊時間行的表格中的位置
                // 但由於我們不知道特殊時間行的確切位置，我們先使用一個大的基數
                // 實際排序會在後面根據 sortOrder 調整
                allRows.push({
                    type: 'team',
                    data: team,
                    index: sequence,
                    timeStart: timeStart,
                    sortOrder: undefined // 團隊行的 sortOrder 將在排序時根據特殊時間行的位置調整
                });
            });
        } else if (data.teams && data.teams.length > 0) {
            // 如果沒有時程資料但有團隊資料，按照預設順序（商管組在前）渲染
            console.log('渲染團隊前的順序:', data.teams.map(t => ({ team_ID: t.team_ID, group_ID: t.group_ID, group_name: t.group_name })));
            
            // 按照類組排序（商管組在前），然後按團隊ID排序
            const sortedTeams = [...data.teams].sort((a, b) => {
                const groupA = parseInt(a.group_ID) || 999;
                const groupB = parseInt(b.group_ID) || 999;
                const groupOrderA = (groupA === 2) ? 0 : ((groupA === 1) ? 1 : groupA + 10);  // 2（商管組）優先
                const groupOrderB = (groupB === 2) ? 0 : ((groupB === 1) ? 1 : groupB + 10);  // 2（商管組）優先
                if (groupOrderA !== groupOrderB) {
                    return groupOrderA - groupOrderB;
                }
                // 類組相同時，按團隊ID排序
                return (a.team_ID || 0) - (b.team_ID || 0);
            });
            
            sortedTeams.forEach((team, index) => {
                allRows.push({
                    type: 'team',
                    data: team,
                    index: index + 1, // 預設順序，渲染後會由 syncOrderFromDOM 更新
                    timeStart: '99:99' // 時間會在 calculateTimes 中計算
                });
            });
            console.log('allRows 中的團隊順序（預設順序）:', allRows.filter(r => r.type === 'team').map(r => ({ team_ID: r.data.team_ID, group_ID: r.data.group_ID, group_name: r.data.group_name })));
        }
        
        // 添加特殊時間段行
        console.log('=== 渲染特殊時間段 ===');
        console.log('data.specialTimesList:', data.specialTimesList);
        console.log('data.specialTimesList 是陣列:', Array.isArray(data.specialTimesList));
        console.log('data.specialTimesList 長度:', data.specialTimesList?.length);
        
        if (data.specialTimesList && Array.isArray(data.specialTimesList)) {
            data.specialTimesList.forEach((special, index) => {
                console.log(`特殊時間段 ${index}:`, special);
                
                // 構建 timeRange：優先使用 timeRange 字段，否則從 start 和 end 構建
                let timeRange = special.timeRange;
                if (!timeRange || timeRange === '-') {
                    if (special.start && special.end) {
                        timeRange = `${special.start}-${special.end}`;
                    } else {
                        // 如果沒有 start 和 end，嘗試從 data.specialTimes 中獲取
                        const type = special.type;
                        if (type === 'preparation' && data.specialTimes.preparation) {
                            timeRange = `${data.specialTimes.preparation.start}-${data.specialTimes.preparation.end}`;
                        } else if (type === 'presentation_instruction' && data.specialTimes.presentation_instruction) {
                            timeRange = `${data.specialTimes.presentation_instruction.start}-${data.specialTimes.presentation_instruction.end}`;
                        } else if (type === 'lunch' && data.specialTimes.lunch) {
                            timeRange = `${data.specialTimes.lunch.start}-${data.specialTimes.lunch.end}`;
                        } else if (type === 'break' && data.specialTimes.break) {
                            timeRange = `${data.specialTimes.break.start}-${data.specialTimes.break.end}`;
                        } else {
                            timeRange = '-'; // 如果都沒有，顯示 "-"
                        }
                    }
                }
                
                allRows.push({
                    type: 'special',
                    data: {
                        ...special,
                        timeRange: timeRange // 確保 timeRange 存在
                    },
                    timeStart: special.timeStart || special.start || '99:99',
                    row_order: special.row_order !== undefined ? special.row_order : 9999 // 使用 row_order 而不是 sortOrder
                });
            });
            console.log('已添加', data.specialTimesList.length, '個特殊時間段到 allRows');
        } else {
            console.warn('警告：data.specialTimesList 不是陣列或為空');
        }
        
        // 如果沒有任何行要渲染，顯示提示訊息
        if (allRows.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center text-muted">目前沒有資料</td></tr>`;
            return;
        }
        
        // 按照 sort_no（團隊）和 row_order（特殊時間）排序渲染
        // 團隊行按照 sort_no 排序，特殊時間行按照 row_order 排序
        // 然後合併排序，按照 row_order 插入團隊行
        
        // 分離團隊行和特殊時間行
        let teamRows = allRows.filter(r => r.type === 'team');
        const specialRows = allRows.filter(r => r.type === 'special');
        
        // 對團隊行進行去重（確保每個團隊只出現一次）
        const teamRowsMap = new Map();
        teamRows.forEach(row => {
            const teamId = row.data?.team_ID;
            if (teamId) {
                if (!teamRowsMap.has(teamId)) {
                    teamRowsMap.set(teamId, row);
                } else {
                    // 如果已存在，比較 index（sort_no），保留較小的
                    const existing = teamRowsMap.get(teamId);
                    const existingIndex = existing.index ?? 999999;
                    const currentIndex = row.index ?? 999999;
                    if (currentIndex < existingIndex) {
                        teamRowsMap.set(teamId, row);
                        console.warn('renderScheduleTable: 發現重複的團隊行，team_ID:', teamId, '保留 index 較小的');
                    } else {
                        console.warn('renderScheduleTable: 發現重複的團隊行，team_ID:', teamId, '已跳過重複項');
                    }
                }
            }
        });
        teamRows = Array.from(teamRowsMap.values());
        
        // 按照 sort_no 排序團隊行
        teamRows.sort((a, b) => {
            const sortA = a.index ?? 999999; // index 就是 sort_no
            const sortB = b.index ?? 999999;
            return sortA - sortB;
        });
        
        // 所有特殊時間（含場次預備、上台報告說明、午餐、休息）皆依時間排序，由使用者決定順序
        specialRows.sort((a, b) => {
            const orderA = a.row_order ?? 9999;
            const orderB = b.row_order ?? 9999;
            if (orderA !== orderB) return orderA - orderB;
            const timeA = a.data?.timeStart || a.timeStart || '99:99';
            const timeB = b.data?.timeStart || b.timeStart || '99:99';
            return timeA.localeCompare(timeB);
        });
        
        // 合併排序：按照 row_order 排序
        // 團隊行需要計算 row_order（基於 sort_no 和特殊時間行的位置）
        // 特殊時間行已經有 row_order
        
        // 為團隊行計算 row_order（時間相同時，特殊時間應在團隊之前，故團隊使用較大的 base）
        const teamRowsWithOrder = teamRows.map(teamRow => {
            const sortNo = teamRow.index ?? 999999;
            return {
                ...teamRow,
                timeStart: teamRow.timeStart || '99:99',
                row_order: 10000 + sortNo  // 確保時間相同時，特殊時間(row_order 1,2,3)排在團隊之前
            };
        });
        
        // 將時間字符串轉換為分鐘數（用於排序）
        const timeToMinutes = (timeStr) => {
            if (!timeStr || timeStr === '99:99' || timeStr === '00:00') {
                return 999999; // 無效時間排在最後
            }
            const match = timeStr.match(/(\d{1,2}):(\d{2})/);
            if (match) {
                const hours = parseInt(match[1], 10);
                const minutes = parseInt(match[2], 10);
                return hours * 60 + minutes;
            }
            return 999999;
        };
        
        // 為所有行添加時間分鐘數
        teamRowsWithOrder.forEach(row => {
            const timeStart = row.timeStart || '99:99';
            row.timeMinutes = timeToMinutes(timeStart);
        });
        specialRows.forEach(row => {
            const timeStart = row.data?.timeStart || row.timeStart || '99:99';
            row.timeMinutes = timeToMinutes(timeStart);
        });
        
        // 合併所有行並按照時間排序（主軸），然後按 row_order（輔助）
        const allRowsWithOrder = [...teamRowsWithOrder, ...specialRows];
        allRowsWithOrder.sort((a, b) => {
            // 主要排序：按時間（分鐘數）
            const timeA = a.timeMinutes ?? 999999;
            const timeB = b.timeMinutes ?? 999999;
            if (timeA !== timeB) {
                return timeA - timeB;
            }
            
            // 輔助排序：時間相同時，按 row_order 排序（特殊時間 row_order 較小，會排在團隊之前）
            const orderA = a.row_order ?? 999999;
            const orderB = b.row_order ?? 999999;
            return orderA - orderB;
        });
        
        allRows = allRowsWithOrder;
        
        // 最後一次去重：確保渲染時不會出現重複團隊
        const finalTeamRowsMap = new Map();
        const finalRows = [];
        allRows.forEach((rowData) => {
            if (rowData.type === 'special') {
                finalRows.push(rowData);
            } else if (rowData.type === 'team') {
                const teamId = rowData.data?.team_ID;
                if (teamId) {
                    if (!finalTeamRowsMap.has(teamId)) {
                        finalTeamRowsMap.set(teamId, rowData);
                        finalRows.push(rowData);
                    } else {
                        console.warn('renderScheduleTable: 最後檢查發現重複的團隊，team_ID:', teamId, '已跳過');
                    }
                } else {
                    finalRows.push(rowData);
                }
            } else {
                finalRows.push(rowData);
            }
        });
        
        // 創建並插入所有行（先渲染，再計算時間）
        finalRows.forEach((rowData) => {
            try {
                if (rowData.type === 'special') {
                    const row = createSpecialTimeRow(rowData.data.type, rowData.data.timeRange);
                    tbody.appendChild(row);
                } else {
                    const row = createTeamRow(rowData.data, rowData.index);
                    tbody.appendChild(row);
                }
            } catch (error) {
                console.error(`創建行時出錯:`, error, rowData);
            }
        });
        
        // 渲染完成後，計算時間（基於已渲染的行）
        // 只有在有開始時間且不是從已保存的時程表載入時才重新計算時間
        // 如果從已保存的時程表載入，應該使用數據庫中的時間，但需要確保開始時間正確
        try {
            const startTimeInput = document.getElementById('startTime');
            const hasStartTime = startTimeInput && startTimeInput.value;
            
            // 如果有開始時間輸入，重新計算時間（確保時間基於正確的開始時間）
            if (hasStartTime) {
                calculateTimes();
            } else if (data.schedules && data.schedules.length > 0) {
                // 如果沒有開始時間輸入但有時程資料，只更新序列號
                updateSequenceNumbers();
            }
        } catch (error) {
            console.error('計算時間錯誤:', error);
            // 即使計算時間失敗，也要顯示資料
        }
        
        // 確保特殊時間行的時間顯示正確（如果顯示 "-"，從 data.specialTimes 中獲取）
        const specialTimeRows = tbody.querySelectorAll('tr.special-time-row');
        specialTimeRows.forEach(row => {
            const timeCell = row.querySelector('.time-cell');
            const type = row.getAttribute('data-special-type');
            
            if (timeCell && (timeCell.textContent.trim() === '-' || !timeCell.textContent.trim())) {
                // 如果時間顯示為 "-" 或為空，從 data.specialTimes 中獲取
                let timeRange = '-';
                if (type === 'preparation' && data.specialTimes.preparation) {
                    timeRange = `${data.specialTimes.preparation.start}-${data.specialTimes.preparation.end}`;
                } else if (type === 'presentation_instruction' && data.specialTimes.presentation_instruction) {
                    timeRange = `${data.specialTimes.presentation_instruction.start}-${data.specialTimes.presentation_instruction.end}`;
                } else if (type === 'lunch' && data.specialTimes.lunch) {
                    timeRange = `${data.specialTimes.lunch.start}-${data.specialTimes.lunch.end}`;
                } else if (type === 'break' && data.specialTimes.break) {
                    timeRange = `${data.specialTimes.break.start}-${data.specialTimes.break.end}`;
                }
                
                if (timeRange !== '-') {
                    timeCell.textContent = timeRange;
                }
            }
        });
        
        console.log('渲染完成，已插入', tbody.querySelectorAll('tr').length, '行');
        
        // 同步 DOM 順序（確保顯示的順序與數據一致）
        // 如果數據中沒有 sort_no 或 row_order，按照 DOM 順序設置
        syncOrderFromDOM();
        
        // 更新結束時間顯示
        updateEndTimeDisplay();
        
        // 只在編輯模式下初始化拖放功能
        if (data.isEditMode) {
            setTimeout(() => {
                initSortable();
            }, 100);
        } else {
            // 非編輯模式下，銷毀拖放功能
            if (data.sortableInstance) {
                try {
                    data.sortableInstance.destroy();
                    data.sortableInstance = null;
                } catch (e) {
                    console.warn('銷毀 Sortable 實例時出錯:', e);
                }
            }
            // 移除行的 draggable 屬性
            const teamRows = tbody.querySelectorAll('.team-row');
            teamRows.forEach(row => {
                row.draggable = false;
            });
        }
        
        // 添加行選中功能（避免重複綁定）
        const existingHandler = tbody.getAttribute('data-click-handler');
        if (!existingHandler) {
            tbody.setAttribute('data-click-handler', 'true');
            tbody.addEventListener('click', function(e) {
                const row = e.target.closest('tr');
                if (row && (row.classList.contains('team-row') || row.classList.contains('special-time-row'))) {
                    tbody.querySelectorAll('tr.selected').forEach(r => r.classList.remove('selected'));
                    row.classList.add('selected');
                }
            });
        }
    }

    // 創建團隊行
    function createTeamRow(team, sequence) {
    const tr = document.createElement('tr');
    tr.className = 'team-row';
    tr.dataset.teamId = team.team_ID;
    // 只在編輯模式下啟用拖放
    tr.draggable = data.isEditMode;
    
    // 獲取該團隊的時程（如果有的話）
    const schedule = data.schedules.find(s => s.team_ID == team.team_ID);
    
    // 專題題目
    const projectName = team.team_project_name || '未設定';
    
    // 組合學號（每行一個）
    let studentIds = '-';
    if (team.students && Array.isArray(team.students) && team.students.length > 0) {
        const ids = team.students.map(s => escapeHtml(String(s.u_ID || s.id || ''))).filter(Boolean);
        studentIds = ids.length > 0 ? ids.join('<br>') : '-';
    }
    
    // 組合姓名（每行一個）
    let studentNames = '-';
    if (team.students && Array.isArray(team.students) && team.students.length > 0) {
        const names = team.students.map(s => escapeHtml(s.u_name || s.name || '')).filter(Boolean);
        studentNames = names.length > 0 ? names.join('<br>') : '-';
    }
    
    // 組合指導老師姓名
    let teacherNames = '-';
    if (team.teacher && Array.isArray(team.teacher) && team.teacher.length > 0) {
        const names = team.teacher.map(t => escapeHtml(t.u_name || t.name || '')).filter(Boolean);
        teacherNames = names.length > 0 ? names.join('、') : '-';
    } else if (team.teacher && typeof team.teacher === 'object' && team.teacher.u_name) {
        teacherNames = escapeHtml(team.teacher.u_name);
    }
    
    console.log(`團隊 ${team.team_ID} 的資料:`, {
        students: team.students,
        teacher: team.teacher,
        projectName: projectName,
        studentIds: studentIds,
        studentNames: studentNames,
        teacherNames: teacherNames
    });
    
    // 確保時間範圍格式正確（報告時間格式：10:10-10:20）
    let timeRange = '-';
    if (schedule && schedule.time_start_d && schedule.time_end_d) {
        try {
            const startDate = new Date(schedule.time_start_d);
            const endDate = new Date(schedule.time_end_d);
            const startHours = String(startDate.getHours()).padStart(2, '0');
            const startMinutes = String(startDate.getMinutes()).padStart(2, '0');
            const endHours = String(endDate.getHours()).padStart(2, '0');
            const endMinutes = String(endDate.getMinutes()).padStart(2, '0');
            timeRange = `${startHours}:${startMinutes}-${endHours}:${endMinutes}`;
        } catch (e) {
            console.error('格式化時間範圍錯誤:', e);
            timeRange = '-';
        }
    }
    
    // 刪除按鈕（只在編輯模式下顯示，單獨的操作列）
    const isEditMode = data.isEditMode;
    console.log(`創建團隊行 ${team.team_ID}，編輯模式狀態:`, isEditMode);
    const deleteBtnHtml = isEditMode ? 
        `<td class="action-cell" style="text-align: center; vertical-align: top; padding: 12px 8px;">
            <button class="btn-delete-team" data-team-id="${team.team_ID}" title="刪除團隊" style="background: none; border: none; color: #dc3545; font-size: 18px; cursor: pointer; padding: 4px 8px; border-radius: 4px; transition: all 0.2s;">
                <i class="fas fa-times"></i>
            </button>
        </td>` : '';
    
    tr.innerHTML = `
        <td class="time-cell" style="white-space: nowrap; vertical-align: top; padding: 12px 8px;">${timeRange}</td>
        <td class="sequence-cell" style="text-align: center; vertical-align: top; padding: 12px 8px;">${sequence}</td>
        <td class="student-id-cell" style="vertical-align: top; padding: 12px 8px;">${studentIds}</td>
        <td class="student-name-cell" style="vertical-align: top; padding: 12px 8px;">${studentNames}</td>
        <td class="project-cell" style="vertical-align: top; padding: 12px 8px;">${escapeHtml(projectName)}</td>
        <td class="teacher-cell" style="vertical-align: top; padding: 12px 8px;">${teacherNames}</td>
        ${deleteBtnHtml}
    `;
    
    // 綁定刪除按鈕事件（如果存在）
    const deleteBtn = tr.querySelector('.btn-delete-team');
    if (deleteBtn) {
        console.log(`為團隊 ${team.team_ID} 綁定刪除按鈕事件`);
        deleteBtn.addEventListener('click', function(e) {
            e.stopPropagation(); // 防止觸發行選中事件
            deleteTeam(team.team_ID);
        });
    } else {
        console.log(`團隊 ${team.team_ID} 沒有刪除按鈕，編輯模式狀態:`, isEditMode);
    }
    
    return tr;
}

    // 創建特殊時間段行
    function createSpecialTimeRow(type, timeRange, sequence = null) {
    const tr = document.createElement('tr');
    tr.className = 'special-time-row';
    tr.dataset.specialType = type;
    
    let label = '';
    if (type === 'presentation_instruction') {
        label = '上台報告說明';
    } else if (type === 'lunch') {
        label = '午餐時間';
    } else if (type === 'break') {
        label = '休息時間';
    } else if (type === 'preparation') {
        label = '場次預備';
    }
    
    // 編輯模式下添加刪除按鈕（單獨的操作列）
    const isEditMode = data.isEditMode;
    const deleteBtnHtml = isEditMode ? `
        <td class="action-cell" style="text-align: center; vertical-align: top; padding: 12px 8px;">
            <button type="button" class="btn-delete-team" title="刪除此項目" style="background: none; border: none; color: #dc3545; font-size: 18px; cursor: pointer; padding: 4px 8px; border-radius: 4px; transition: all 0.2s;">
                <i class="fas fa-times"></i>
            </button>
        </td>` : '';
    
    // 特殊時間行：報告時間顯示時間範圍，組次、學號、姓名為空，專題題目和指導老師合併顯示標籤
    // 如果編輯模式，專題題目和指導老師合併佔2列，操作列單獨；否則專題題目和指導老師合併佔2列
    tr.innerHTML = `
        <td class="time-cell" style="white-space: nowrap; vertical-align: top; padding: 12px 8px;">${timeRange || '-'}</td>
        <td class="sequence-cell" style="vertical-align: top; padding: 12px 8px;"></td>
        <td class="student-id-cell" style="vertical-align: top; padding: 12px 8px;"></td>
        <td class="student-name-cell" style="vertical-align: top; padding: 12px 8px;"></td>
        <td class="project-cell" colspan="2" style="text-align: center; font-weight: 600; font-size: 15px; vertical-align: top; padding: 12px 8px;">${label}</td>
        ${deleteBtnHtml}
    `;
    
    // 綁定刪除按鈕事件（如果存在）
    const deleteBtn = tr.querySelector('.btn-delete-team');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function(e) {
            e.stopPropagation(); // 防止觸發行選中事件
            const specialType = tr.getAttribute('data-special-type') || tr.dataset.specialType;
            // 從 data.specialTimesList 移除，之後重繪才不會再出現（含剛新增、尚未存檔的項目）
            if (data.specialTimesList && Array.isArray(data.specialTimesList)) {
                data.specialTimesList = data.specialTimesList.filter(st => st.type !== specialType);
            }
            if (data.specialTimes && specialType) {
                if (specialType === 'preparation') data.specialTimes.preparation = { start: null, end: null };
                else if (specialType === 'presentation_instruction') data.specialTimes.presentation_instruction = { start: null, end: null };
                else if (specialType === 'lunch') data.specialTimes.lunch = { start: null, end: null };
                else if (specialType === 'break') data.specialTimes.break = { start: null, end: null };
            }
            tr.remove();
            // 重新計算順序
            updateSequenceNumbers();
            // 重新初始化拖放（如果處於編輯模式）
            if (data.isEditMode) {
                setTimeout(() => {
                    initSortable();
                }, 100);
            }
        });
    }
    
    return tr;
}

    // 插入特殊時間段（選擇完時間後自動依時間排序位置，不需選擇位置）
    function insertSpecialTime(type) {
    const tbody = document.getElementById('scheduleTableBody');
    if (!tbody) return;
    
    doInsertSpecialTime(type, -1);
}

    // 編輯已存在的特殊時間（直接修改時間，不顯示「已存在」提示）
    function editExistingSpecialTime(type, existingRow) {
    const tbody = document.getElementById('scheduleTableBody');
    if (!tbody || !existingRow) return;
    
    // 從現有行的時間儲存格解析出開始與結束時間
    let existingStart = '';
    let existingEnd = '';
    const timeCell = existingRow.querySelector('.time-cell');
    if (timeCell) {
        const timeText = timeCell.textContent.trim();
        const timeMatch = timeText.match(/(\d{1,2}):(\d{2})\s*[-~]\s*(\d{1,2}):(\d{2})/);
        if (timeMatch) {
            existingStart = `${timeMatch[1].padStart(2, '0')}:${timeMatch[2]}`;
            existingEnd = `${timeMatch[3].padStart(2, '0')}:${timeMatch[4]}`;
        }
    }
    
    // 若無法解析，使用預設值
    if (!existingStart && type === 'lunch') {
        existingStart = '12:10';
        existingEnd = '13:00';
    }
    
    const timePickerHtml = `
        <div class="mb-3">
            <label>開始時間</label>
            <input type="time" id="specialStartTime" class="form-control" value="${existingStart}" required>
        </div>
        <div class="mb-3">
            <label>結束時間</label>
            <input type="time" id="specialEndTime" class="form-control" value="${existingEnd}" required>
        </div>
    `;
    
    Swal.fire({
        title: '修改時間範圍',
        html: timePickerHtml,
        showCancelButton: true,
        confirmButtonText: '確定',
        cancelButtonText: '取消',
        reverseButtons: true,
        didOpen: () => {
            const startInput = document.getElementById('specialStartTime');
            const endInput = document.getElementById('specialEndTime');
            if (startInput && endInput) {
                const validateTimes = () => {
                    const startValue = startInput.value;
                    const endValue = endInput.value;
                    startInput.classList.remove('is-invalid');
                    endInput.classList.remove('is-invalid');
                    if (startValue && endValue) {
                        const [sh, sm] = startValue.split(':').map(Number);
                        const [eh, em] = endValue.split(':').map(Number);
                        if (eh * 60 + em <= sh * 60 + sm) {
                            endInput.classList.add('is-invalid');
                            let err = endInput.parentElement.querySelector('.invalid-feedback');
                            if (!err) {
                                err = document.createElement('div');
                                err.className = 'invalid-feedback';
                                err.style.display = 'block';
                                endInput.parentElement.appendChild(err);
                            }
                            err.textContent = '結束時間必須晚於開始時間';
                            return false;
                        }
                        const err = endInput.parentElement.querySelector('.invalid-feedback');
                        if (err) err.remove();
                    }
                    return true;
                };
                startInput.addEventListener('input', validateTimes);
                startInput.addEventListener('change', validateTimes);
                endInput.addEventListener('input', validateTimes);
                endInput.addEventListener('change', validateTimes);
                if (startInput) startInput.focus();
            }
        },
        preConfirm: () => {
            const start = document.getElementById('specialStartTime').value;
            const end = document.getElementById('specialEndTime').value;
            if (!start || !end) {
                Swal.showValidationMessage('請填寫完整的時間範圍');
                return false;
            }
            const [sh, sm] = start.split(':').map(Number);
            const [eh, em] = end.split(':').map(Number);
            if (eh * 60 + em <= sh * 60 + sm) {
                Swal.showValidationMessage('結束時間必須晚於開始時間，請重新設定');
                return false;
            }
            return { start, end };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const timeRange = `${result.value.start}-${result.value.end}`;
            const newStartTime = result.value.start;
            const newEndTime = result.value.end;
            
            // 更新現有行的時間顯示
            if (timeCell) timeCell.textContent = timeRange;
            
            // 先移除現有行，再取得不含該行的 rows 以正確計算插入位置
            existingRow.remove();
            const rows = Array.from(tbody.querySelectorAll('tr.team-row, tr.special-time-row'));
            const timeToMinutes = (timeStr) => {
                const [hours, minutes] = timeStr.split(':').map(Number);
                return hours * 60 + minutes;
            };
            const newStartMinutes = timeToMinutes(newStartTime);
            
            let insertPos = rows.length;
            let foundMatchEndTime = false;
            for (let i = 0; i < rows.length; i++) {
                const currentRow = rows[i];
                const tc = currentRow.querySelector('.time-cell');
                if (!tc) continue;
                const timeText = tc.textContent.trim();
                const timeMatch = timeText.match(/(\d{1,2}):(\d{2})\s*[-~]\s*(\d{1,2}):(\d{2})/);
                if (timeMatch) {
                    const rowStartMinutes = timeToMinutes(`${timeMatch[1].padStart(2, '0')}:${timeMatch[2]}`);
                    const rowEndMinutes = timeToMinutes(`${timeMatch[3].padStart(2, '0')}:${timeMatch[4]}`);
                    if (newStartMinutes === rowEndMinutes) {
                        insertPos = i + 1;
                        foundMatchEndTime = true;
                        continue;
                    }
                    if (foundMatchEndTime && rowStartMinutes > newStartMinutes) break;
                    if (!foundMatchEndTime && rowStartMinutes > newStartMinutes) {
                        insertPos = i;
                        break;
                    }
                }
            }
            
            if (insertPos < rows.length) {
                tbody.insertBefore(existingRow, rows[insertPos]);
            } else {
                tbody.appendChild(existingRow);
            }
            
            updateSequenceNumbers();
            if (data.isEditMode) {
                setTimeout(() => initSortable(), 100);
            }
        }
    });
}

    // 執行插入特殊時間段
    function doInsertSpecialTime(type, insertIndex) {
    const tbody = document.getElementById('scheduleTableBody');
    if (!tbody) return;
    
    // 所有特殊時間類型（含場次準備、上台報告說明）皆由使用者輸入時間
    Swal.fire({
        title: '設定時間範圍',
        html: `
            <div class="mb-3">
                <label>開始時間</label>
                <input type="time" id="specialStartTime" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>結束時間</label>
                <input type="time" id="specialEndTime" class="form-control" required>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: '確定',
        cancelButtonText: '取消',
        reverseButtons: true,
        didOpen: () => {
            const startInput = document.getElementById('specialStartTime');
            const endInput = document.getElementById('specialEndTime');
            
            // 如果是午餐時間，預設為 12:10-13:00
            if (type === 'lunch') {
                if (startInput) startInput.value = '12:10';
                if (endInput) endInput.value = '13:00';
            }
            
            // 添加實時驗證：當開始時間改變時，檢查結束時間
            if (startInput && endInput) {
                const validateTimes = () => {
                    const startValue = startInput.value;
                    const endValue = endInput.value;
                    
                    // 移除之前的錯誤樣式
                    startInput.classList.remove('is-invalid');
                    endInput.classList.remove('is-invalid');
                    
                    // 如果兩個時間都已填寫，進行驗證
                    if (startValue && endValue) {
                        const [startHours, startMinutes] = startValue.split(':').map(Number);
                        const [endHours, endMinutes] = endValue.split(':').map(Number);
                        
                        const startTotalMinutes = startHours * 60 + startMinutes;
                        const endTotalMinutes = endHours * 60 + endMinutes;
                        
                        if (endTotalMinutes <= startTotalMinutes) {
                            endInput.classList.add('is-invalid');
                            // 顯示錯誤提示
                            let errorMsg = endInput.parentElement.querySelector('.invalid-feedback');
                            if (!errorMsg) {
                                errorMsg = document.createElement('div');
                                errorMsg.className = 'invalid-feedback';
                                errorMsg.style.display = 'block';
                                endInput.parentElement.appendChild(errorMsg);
                            }
                            errorMsg.textContent = '結束時間必須晚於開始時間';
                            return false;
                        } else {
                            // 移除錯誤提示
                            const errorMsg = endInput.parentElement.querySelector('.invalid-feedback');
                            if (errorMsg) {
                                errorMsg.remove();
                            }
                        }
                    }
                    return true;
                };
                
                // 監聽開始時間和結束時間的變化
                startInput.addEventListener('input', validateTimes);
                startInput.addEventListener('change', validateTimes);
                endInput.addEventListener('input', validateTimes);
                endInput.addEventListener('change', validateTimes);
                
                // 當開始時間改變時，如果結束時間早於開始時間，自動清空結束時間
                startInput.addEventListener('change', function() {
                    const startValue = startInput.value;
                    const endValue = endInput.value;
                    
                    if (startValue && endValue) {
                        const [startHours, startMinutes] = startValue.split(':').map(Number);
                        const [endHours, endMinutes] = endValue.split(':').map(Number);
                        
                        const startTotalMinutes = startHours * 60 + startMinutes;
                        const endTotalMinutes = endHours * 60 + endMinutes;
                        
                        if (endTotalMinutes <= startTotalMinutes) {
                            endInput.value = '';
                            endInput.classList.remove('is-invalid');
                            const errorMsg = endInput.parentElement.querySelector('.invalid-feedback');
                            if (errorMsg) {
                                errorMsg.remove();
                            }
                        }
                    }
                });
            }
            
            if (startInput) startInput.focus();
        },
        preConfirm: () => {
            const start = document.getElementById('specialStartTime').value;
            const end = document.getElementById('specialEndTime').value;
            if (!start || !end) {
                Swal.showValidationMessage('請填寫完整的時間範圍');
                return false;
            }
            
            // 驗證結束時間必須晚於開始時間
            const [startHours, startMinutes] = start.split(':').map(Number);
            const [endHours, endMinutes] = end.split(':').map(Number);
            
            const startTotalMinutes = startHours * 60 + startMinutes;
            const endTotalMinutes = endHours * 60 + endMinutes;
            
            if (endTotalMinutes <= startTotalMinutes) {
                Swal.showValidationMessage('結束時間必須晚於開始時間，請重新設定');
                // 聚焦到結束時間輸入框
                const endInput = document.getElementById('specialEndTime');
                if (endInput) {
                    setTimeout(() => endInput.focus(), 100);
                }
                return false;
            }
            
            return { start, end };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const timeRange = `${result.value.start}-${result.value.end}`;
            const row = createSpecialTimeRow(type, timeRange);
            
            const rows = Array.from(tbody.querySelectorAll('tr.team-row, tr.special-time-row'));
            
            // 所有有時間範圍的特殊時間，根據時間自動找到正確的插入位置
            if (['lunch', 'break', 'preparation', 'presentation_instruction'].includes(type)) {
                const newStartTime = result.value.start; // HH:MM 格式
                const newEndTime = result.value.end; // HH:MM 格式
                const timeToMinutes = (timeStr) => {
                    const [hours, minutes] = timeStr.split(':').map(Number);
                    return hours * 60 + minutes;
                };
                const newStartMinutes = timeToMinutes(newStartTime);
                const newEndMinutes = timeToMinutes(newEndTime);
                
                // 找到正確的插入位置
                // 規則：
                // 1. 如果新開始時間等於某行的結束時間，插入到該行之後
                // 2. 否則，插入到第一個開始時間大於新開始時間的行之前
                let insertPos = rows.length;
                let foundMatchEndTime = false;
                
                for (let i = 0; i < rows.length; i++) {
                    const currentRow = rows[i];
                    const timeCell = currentRow.querySelector('.time-cell');
                    if (!timeCell) continue;
                    
                    const timeText = timeCell.textContent.trim();
                    const timeMatch = timeText.match(/(\d{1,2}):(\d{2})\s*[-~]\s*(\d{1,2}):(\d{2})/);
                    if (timeMatch) {
                        const rowStartMinutes = timeToMinutes(`${timeMatch[1].padStart(2, '0')}:${timeMatch[2]}`);
                        const rowEndMinutes = timeToMinutes(`${timeMatch[3].padStart(2, '0')}:${timeMatch[4]}`);
                        
                        // 優先檢查：如果新開始時間等於某行的結束時間，插入到該行之後
                        if (newStartMinutes === rowEndMinutes) {
                            insertPos = i + 1;
                            foundMatchEndTime = true;
                            // 繼續查找，看是否有更合適的位置（例如連續多個相同結束時間的行）
                            continue;
                        }
                        
                        // 如果已經找到匹配的結束時間，且當前行的開始時間大於新開始時間，停止查找
                        if (foundMatchEndTime && rowStartMinutes > newStartMinutes) {
                            break;
                        }
                        
                        // 如果沒有找到匹配的結束時間，查找第一個開始時間大於新開始時間的行
                        if (!foundMatchEndTime && rowStartMinutes > newStartMinutes) {
                            insertPos = i;
                            break;
                        }
                    }
                }
                
                // 如果找到插入位置，插入到該位置
                if (insertPos < rows.length) {
                    tbody.insertBefore(row, rows[insertPos]);
                } else {
                    // 如果沒找到（所有行的開始時間都小於新時間），插入到最後
                    tbody.appendChild(row);
                }
            } else if (insertIndex === -1 || insertIndex >= rows.length) {
                // 插入到最後
                tbody.appendChild(row);
            } else {
                // 插入到指定位置
                tbody.insertBefore(row, rows[insertIndex]);
            }
            
            // 重新計算順序（會調用 calculateTimes，已包含去重邏輯）
            updateSequenceNumbers();
            
            // 確保時程資料去重（雙重保險，防止添加特殊時間後出現重複）
            if (data.schedules && data.schedules.length > 0) {
                const originalCount = data.schedules.length;
                const uniqueSchedulesMap = new Map();
                data.schedules.forEach(schedule => {
                    const teamId = schedule.team_ID;
                    if (teamId) {
                        const existing = uniqueSchedulesMap.get(teamId);
                        if (!existing) {
                            uniqueSchedulesMap.set(teamId, schedule);
                        } else {
                            // 如果已存在，比較 sort_no，保留較小的
                            const existingSortNo = existing.sort_no ?? 999999;
                            const currentSortNo = schedule.sort_no ?? 999999;
                            if (currentSortNo < existingSortNo) {
                                uniqueSchedulesMap.set(teamId, schedule);
                            }
                        }
                    }
                });
                const uniqueSchedules = Array.from(uniqueSchedulesMap.values());
                if (originalCount !== uniqueSchedules.length) {
                    console.warn('doInsertSpecialTime: 發現重複的時程資料，已去重。原始數量:', originalCount, '去重後數量:', uniqueSchedules.length);
                    data.schedules = uniqueSchedules;
                    // 重新渲染表格以確保顯示正確
                    renderScheduleTable();
                }
            }
            
            // 只在編輯模式下重新初始化拖放
            if (data.isEditMode) {
                setTimeout(() => {
                    initSortable();
                }, 100);
            }
        }
    });
    }

    // 同步 DOM 順序到 data.schedules.sort_no 和 specialTimes.row_order
    // 拖曳後的排序以 DOM (#scheduleTableBody) 中的實際順序為唯一依據
    // sort_no 只計算 .team-row，從上到下依序 1,2,3…，不包含 .special-time-row
    // 特殊時間列需要保存它在整張表格中的位置 row_order（包含 team+special 的 index）
    function syncOrderFromDOM() {
        const tbody = document.getElementById('scheduleTableBody');
        if (!tbody) {
            console.warn('syncOrderFromDOM: 找不到 scheduleTableBody 元素');
            return;
        }
        
        // 獲取表格中所有行的實際 DOM 順序（包括特殊時間段和團隊報告）
        const allRows = Array.from(tbody.querySelectorAll('tr.team-row, tr.special-time-row'));
        
        console.log('syncOrderFromDOM: 開始同步 DOM 順序，總行數:', allRows.length);
        
        let teamIndex = 0; // 只計算團隊行的索引，從 1 開始
        let rowOrder = 0;   // 計算所有行的索引（包含 team+special），從 0 開始
        
        allRows.forEach((row) => {
            rowOrder++; // 所有行的順序（從 1 開始）
            
            if (row.classList.contains('team-row')) {
                // 團隊行：更新 sort_no（只計算團隊行，從 1 開始）
                teamIndex++;
                const teamId = parseInt(row.getAttribute('data-team-id')) || parseInt(row.dataset.teamId);
                
                // 確保只找到一個時程（去重）
                const schedules = data.schedules.filter(s => s.team_ID == teamId);
                const schedule = schedules.length > 0 ? schedules[0] : null;
                
                if (schedule) {
                    schedule.sort_no = teamIndex;
                    console.log(`syncOrderFromDOM: 更新團隊 ${teamId} 的 sort_no 為 ${teamIndex}（DOM 順序第 ${teamIndex} 個團隊）`);
                } else {
                    console.warn(`syncOrderFromDOM: 找不到團隊 ${teamId} 的時程資料`);
                }
                
                // 更新組次顯示
                const sequenceCell = row.querySelector('.sequence-cell');
                if (sequenceCell) {
                    sequenceCell.textContent = teamIndex;
                }
                
            } else if (row.classList.contains('special-time-row')) {
                // 特殊時間行：更新 row_order（所有類型皆依 DOM 順序，由使用者決定）
                const type = row.getAttribute('data-special-type');
                
                // 確保 data.specialTimesList 存在（尚未存檔時可能只有 DOM 有新增的特殊時間）
                if (!data.specialTimesList || !Array.isArray(data.specialTimesList)) {
                    data.specialTimesList = [];
                }
                // 找到對應的特殊時間項目並更新 row_order，若 DOM 有但 list 沒有則新增
                const specialTime = data.specialTimesList.find(st => st.type === type);
                if (specialTime) {
                    specialTime.row_order = rowOrder;
                } else {
                    // 如果找不到，創建一個新的特殊時間項目（例如剛新增尚未存檔的特殊時間）
                    console.warn(`syncOrderFromDOM: 找不到特殊時間 ${type}，創建新項目`);
                    const timeCell = row.querySelector('.time-cell');
                    const timeRange = timeCell ? timeCell.textContent.trim() : '';
                    const timeMatch = timeRange.match(/(\d{1,2}):(\d{2})\s*[-~]\s*(\d{1,2}):(\d{2})/);
                    if (timeMatch) {
                        data.specialTimesList.push({
                            type: type,
                            start: `${timeMatch[1].padStart(2, '0')}:${timeMatch[2]}`,
                            end: `${timeMatch[3].padStart(2, '0')}:${timeMatch[4]}`,
                            row_order: rowOrder
                        });
                    }
                }
            }
        });
        
        console.log('syncOrderFromDOM: 同步完成，團隊數量:', teamIndex, '總行數:', rowOrder);
    }

    // 更新組次顯示和時間（按照已同步的 sort_no 顯示）
    // 注意：sort_no 和 row_order 應該已經由 syncOrderFromDOM() 同步，這裡只負責顯示
    function updateSequenceNumbers() {
        const tbody = document.getElementById('scheduleTableBody');
        if (!tbody) return;
        
        // 獲取表格中所有行的順序（包括特殊時間段和團隊報告）
        const allRows = Array.from(tbody.querySelectorAll('tr.team-row, tr.special-time-row'));
        
        // 重新計算時間（按照表格順序）
        calculateTimes();
        
        // 更新每一行的組次顯示（使用已同步的 sort_no）
        let teamIndex = 0;
        allRows.forEach((row, index) => {
            if (row.classList.contains('team-row')) {
                const teamId = parseInt(row.getAttribute('data-team-id')) || parseInt(row.dataset.teamId);
                // 確保只找到一個時程（去重）
                const schedules = data.schedules.filter(s => s.team_ID == teamId);
                const schedule = schedules.length > 0 ? schedules[0] : null;
                
                // 更新組次顯示（使用已同步的 sort_no）
                if (schedule && schedule.sort_no !== null && schedule.sort_no !== undefined) {
                    teamIndex = schedule.sort_no;
                } else {
                    // 如果沒有 sort_no，按照 DOM 順序計算（臨時顯示）
                    teamIndex++;
                }
                
                const sequenceCell = row.querySelector('.sequence-cell');
                if (sequenceCell) {
                    sequenceCell.textContent = teamIndex;
                }
                
                // 更新時間
                const timeCell = row.querySelector('.time-cell');
                if (timeCell && schedule && schedule.time_start_d && schedule.time_end_d) {
                    try {
                        timeCell.textContent = formatTimeRange(schedule.time_start_d, schedule.time_end_d);
                    } catch (error) {
                        console.error('格式化時間範圍錯誤:', error);
                    }
                }
                
                // 確保刪除按鈕存在（編輯模式下）
                if (data.isEditMode) {
                    const actionCell = row.querySelector('.action-cell');
                    const deleteBtn = row.querySelector('.btn-delete-team');
                    if (!actionCell || !deleteBtn) {
                        // 如果刪除按鈕不存在，重新添加
                        const teacherCell = row.querySelector('.teacher-cell');
                        if (teacherCell && teacherCell.nextSibling && teacherCell.nextSibling.classList.contains('action-cell')) {
                            // 操作列已存在但按鈕丟失，重新添加按鈕
                            const existingActionCell = teacherCell.nextSibling;
                            if (!existingActionCell.querySelector('.btn-delete-team')) {
                                const newDeleteBtn = document.createElement('button');
                                newDeleteBtn.className = 'btn-delete-team';
                                newDeleteBtn.setAttribute('data-team-id', teamId);
                                newDeleteBtn.title = '刪除團隊';
                                newDeleteBtn.style.cssText = 'background: none; border: none; color: #dc3545; font-size: 18px; cursor: pointer; padding: 4px 8px; border-radius: 4px; transition: all 0.2s;';
                                newDeleteBtn.innerHTML = '<i class="fas fa-times"></i>';
                                newDeleteBtn.addEventListener('click', function(e) {
                                    e.stopPropagation();
                                    deleteTeam(teamId);
                                });
                                existingActionCell.appendChild(newDeleteBtn);
                            }
                        } else {
                            // 操作列不存在，添加操作列和刪除按鈕
                            const newActionCell = document.createElement('td');
                            newActionCell.className = 'action-cell';
                            newActionCell.style.cssText = 'text-align: center; vertical-align: top; padding: 12px 8px;';
                            const newDeleteBtn = document.createElement('button');
                            newDeleteBtn.className = 'btn-delete-team';
                            newDeleteBtn.setAttribute('data-team-id', teamId);
                            newDeleteBtn.title = '刪除團隊';
                            newDeleteBtn.style.cssText = 'background: none; border: none; color: #dc3545; font-size: 18px; cursor: pointer; padding: 4px 8px; border-radius: 4px; transition: all 0.2s;';
                            newDeleteBtn.innerHTML = '<i class="fas fa-times"></i>';
                            newDeleteBtn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                deleteTeam(teamId);
                            });
                            newActionCell.appendChild(newDeleteBtn);
                            row.appendChild(newActionCell);
                        }
                    }
                }
            }
        });
    
    // 更新結束時間顯示
    updateEndTimeDisplay();
}

    // 驗證時間範圍
    function validateTimeRange() {
    const startTimeInput = document.getElementById('startTime');
    const endTimeInput = document.getElementById('endTime');
    
    if (!startTimeInput || !endTimeInput) return true;
    
    const startValue = startTimeInput.value;
    const endValue = endTimeInput.value;
    
    if (!startValue || !endValue) return true;
    
    // 比較日期時間
    const start = new Date(startValue);
    const end = new Date(endValue);
    
    if (end < start) {
        Swal.fire({
            icon: 'error',
            title: '時間設定錯誤',
            text: '結束時間不可小於開始時間',
            confirmButtonText: '確定',
            confirmButtonColor: '#3085d6'
        });
        endTimeInput.value = '';
        return false;
    }
    
    return true;
}

    // 更新開始時間（場次準備開始時間）
    function updateStartTime(datetimeValue) {
        if (!datetimeValue) return;
        
        data.startTime = new Date(datetimeValue);
        
        // 如果有團隊資料，重新計算時間
        if (data.teams.length > 0) {
            calculateTimes();
            updateEndTimeDisplay();
            updateSequenceNumbers();
        }
    }

    // 更新結束時間顯示（最後一組報告完成時間）
    function updateEndTimeDisplay() {
        const endTimeInput = document.getElementById('endTime');
        const endTimeContainer = document.getElementById('endTimeContainer');
        if (!endTimeInput) return;
        
        // 計算最後一組的報告完成時間
        if (data.schedules.length > 0) {
            // 找到最後一個時程
            const lastSchedule = data.schedules[data.schedules.length - 1];
            if (lastSchedule && lastSchedule.time_end_d) {
                const endDate = new Date(lastSchedule.time_end_d);
                // 轉換為 datetime-local 格式 (YYYY-MM-DDTHH:mm)
                const year = endDate.getFullYear();
                const month = String(endDate.getMonth() + 1).padStart(2, '0');
                const day = String(endDate.getDate()).padStart(2, '0');
                const hours = String(endDate.getHours()).padStart(2, '0');
                const minutes = String(endDate.getMinutes()).padStart(2, '0');
                endTimeInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
                // 只有在編輯現有時程表或已選擇開始時間時才顯示結束時間欄位
                if (endTimeContainer) {
                    const startTimeInput = document.getElementById('startTime');
                    const hasStartTime = startTimeInput && startTimeInput.value;
                    // 編輯現有時程表 或 已選擇開始時間（新增模式）
                    if (data.currentTinformaID || hasStartTime) {
                        endTimeContainer.style.display = 'block';
                    } else {
                        endTimeContainer.style.display = 'none';
                    }
                }
            } else {
                endTimeInput.value = '';
                // 如果是編輯模式且有 currentTinformaID，顯示欄位；否則隱藏
                if (endTimeContainer) {
                    if (data.currentTinformaID) {
                        endTimeContainer.style.display = 'block';
                    } else {
                        endTimeContainer.style.display = 'none';
                    }
                }
            }
        } else {
            endTimeInput.value = '';
            // 如果是編輯模式且有 currentTinformaID，顯示欄位；否則隱藏
            if (endTimeContainer) {
                if (data.currentTinformaID) {
                    endTimeContainer.style.display = 'block';
                } else {
                    endTimeContainer.style.display = 'none';
                }
            }
        }
    }

    // 計算時間（按照表格順序，每個項目接在前一個項目的結束時間之後）
    function calculateTimes() {
        // 優先使用輸入欄位的開始時間（場次準備開始時間）
        const startTimeInput = document.getElementById('startTime');
        if (startTimeInput && startTimeInput.value) {
            data.startTime = new Date(startTimeInput.value);
        } else if (!data.startTime) {
            // 如果沒有設定開始時間，使用今天的日期和預設時間（10:30）
            const today = new Date();
            today.setHours(10, 30, 0, 0);
            data.startTime = new Date(today);
        }
        
        // 確保有預設值
        // 使用 data.reportDuration，如果沒有則使用預設值20分鐘
        data.reportDuration = data.reportDuration || 20; // 預設20分鐘
        if (!data.preparationTime) data.preparationTime = 0; // 設置為0，因為不需要準備時間
        
        const tbody = document.getElementById('scheduleTableBody');
        if (!tbody) {
            console.warn('找不到表格，無法計算時間');
            return;
        }
        
        // 獲取表格中所有行的順序（包括特殊時間段和團隊報告）
        let allRows = Array.from(tbody.querySelectorAll('tr.team-row, tr.special-time-row'));
        
        // 建立團隊ID到團隊資料的映射
        const teamMap = {};
        data.teams.forEach(team => {
            teamMap[team.team_ID] = team;
        });
        
        // 保存現有時程數據（只清空表格中存在的團隊的時程，保留其他團隊的時程）
        const existingSchedulesMap = new Map();
        data.schedules.forEach(schedule => {
            existingSchedulesMap.set(schedule.team_ID, schedule);
        });
        
        // 清空現有時程（只清空表格中存在的團隊）
        const teamIdsInTable = new Set();
        allRows.forEach(row => {
            if (row.classList.contains('team-row')) {
                const teamId = parseInt(row.getAttribute('data-team-id'));
                if (teamId) {
                    teamIdsInTable.add(teamId);
                }
            }
        });
        
        // 保留不在表格中的團隊的時程數據
        const schedulesToKeep = data.schedules.filter(s => !teamIdsInTable.has(s.team_ID));
        data.schedules = [];
        
        // 當前時間從開始時間開始
        let currentTime;
        if (data.startTime instanceof Date) {
            // 如果是 Date 對象，創建副本
            currentTime = new Date(data.startTime);
        } else if (typeof data.startTime === 'string') {
            // 如果是字符串，嘗試解析
            const timeMatch = data.startTime.match(/(\d{1,2}):(\d{2})/);
            if (timeMatch) {
                const [, hours, minutes] = timeMatch;
                currentTime = new Date();
                currentTime.setHours(parseInt(hours, 10), parseInt(minutes, 10), 0, 0);
            } else {
                // 嘗試直接轉換為 Date
                currentTime = new Date(data.startTime);
                if (isNaN(currentTime.getTime())) {
                    // 如果無法解析，使用當前時間
                    currentTime = new Date();
                    currentTime.setHours(10, 0, 0, 0);
                }
            }
        } else {
            // 默認值
            currentTime = new Date();
            currentTime.setHours(10, 0, 0, 0);
        }
        
        // 追蹤已處理的團隊ID，防止重複處理
        const processedTeamIds = new Set();
        
        // 按照表格順序處理每一行（只負責時間計算，不更動 sort_no）
        allRows.forEach((row, index) => {
            if (row.classList.contains('special-time-row')) {
                // 特殊時間段：使用表格中的時間，不自動計算
                const type = row.getAttribute('data-special-type');
                const timeCell = row.querySelector('.time-cell');
                
                if (timeCell) {
                    const timeRange = timeCell.textContent.trim();
                    const timeMatch = timeRange.match(/(\d{1,2}):(\d{2})\s*[-~]\s*(\d{1,2}):(\d{2})/);
                    
                    if (timeMatch) {
                        const startHour = parseInt(timeMatch[1]);
                        const startMinute = parseInt(timeMatch[2]);
                        const endHour = parseInt(timeMatch[3]);
                        const endMinute = parseInt(timeMatch[4]);
                        
                        // 更新 specialTimes 資料
                        const start = `${String(startHour).padStart(2, '0')}:${String(startMinute).padStart(2, '0')}`;
                        const end = `${String(endHour).padStart(2, '0')}:${String(endMinute).padStart(2, '0')}`;
                        
                        // 設置下一個團隊報告的開始時間為當前特殊時間段的結束時間
                        const nextTime = new Date(currentTime);
                        nextTime.setHours(endHour, endMinute, 0, 0);
                        currentTime = new Date(nextTime);
                        
                        if (type === 'preparation') {
                            data.specialTimes.preparation = { start, end };
                        } else if (type === 'presentation_instruction') {
                            data.specialTimes.presentation_instruction = { start, end };
                        } else if (type === 'lunch') {
                            data.specialTimes.lunch = { start, end };
                        } else if (type === 'break') {
                            data.specialTimes.break = { start, end };
                        }
                    }
                }
            } else if (row.classList.contains('team-row')) {
                // 團隊報告：使用報告時間間隔，接在前一個時間段之後
                const teamId = parseInt(row.getAttribute('data-team-id'));
                
                // 檢查是否已經處理過這個團隊（防止重複）
                if (processedTeamIds.has(teamId)) {
                    console.warn('calculateTimes: 發現重複的團隊行，team_ID:', teamId, '已跳過');
                    return; // 跳過重複的團隊行
                }
                processedTeamIds.add(teamId);
                
                const team = teamMap[teamId];
                
                if (team) {
                    // 檢查是否有休息時間與當前時間重疊，如果有則延後團隊報告時間
                    const timeToMinutes = (timeStr) => {
                        const [hours, minutes] = timeStr.split(':').map(Number);
                        return hours * 60 + minutes;
                    };
                    
                    const formatTimeString = (date) => {
                        const hours = date.getHours();
                        const minutes = date.getMinutes();
                        return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
                    };
                    
                    let teamStartTime = new Date(currentTime);
                    const teamStartTimeStr = formatTimeString(teamStartTime);
                    const teamStartMinutes = timeToMinutes(teamStartTimeStr);
                    
                    // 檢查所有休息時間，看是否有重疊
                    const breakRows = Array.from(tbody.querySelectorAll('tr.special-time-row[data-special-type="break"]'));
                    for (const breakRow of breakRows) {
                        const breakTimeCell = breakRow.querySelector('.time-cell');
                        if (!breakTimeCell) continue;
                        
                        const breakTimeText = breakTimeCell.textContent.trim();
                        const breakTimeMatch = breakTimeText.match(/(\d{1,2}):(\d{2})\s*[-~]\s*(\d{1,2}):(\d{2})/);
                        if (breakTimeMatch) {
                            const breakStartMinutes = timeToMinutes(`${breakTimeMatch[1].padStart(2, '0')}:${breakTimeMatch[2]}`);
                            const breakEndMinutes = timeToMinutes(`${breakTimeMatch[3].padStart(2, '0')}:${breakTimeMatch[4]}`);
                            
                            // 計算團隊報告結束時間（使用報告時間間隔）
                            const reportDuration = data.reportDuration || 20;
                            const teamEndTime = addMinutes(new Date(teamStartTime), reportDuration);
                            const teamEndTimeStr = formatTimeString(teamEndTime);
                            const teamEndMinutes = timeToMinutes(teamEndTimeStr);
                            
                            // 檢查時間是否重疊：團隊開始時間 < 休息結束時間 且 團隊結束時間 > 休息開始時間
                            if (teamStartMinutes < breakEndMinutes && teamEndMinutes > breakStartMinutes) {
                                // 有重疊，將團隊報告開始時間延後到休息時間結束之後
                                teamStartTime = new Date(currentTime);
                                teamStartTime.setHours(parseInt(breakTimeMatch[3]), parseInt(breakTimeMatch[4]), 0, 0);
                                break; // 只處理第一個重疊的休息時間
                            }
                        }
                    }
                    
                    // 團隊報告開始時間 = 當前時間（可能已因休息時間而延後）
                    const startTimeStr = formatDateTime(teamStartTime);
                    currentTime = new Date(teamStartTime);
                    
                    // 團隊報告結束時間 = 開始時間 + 報告時間間隔（分鐘）
                    const reportDuration = data.reportDuration || 20; // 預設20分鐘
                    currentTime = addMinutes(currentTime, reportDuration);
                    const endTimeStr = formatDateTime(currentTime);
                    
                    // 更新表格中的時間顯示
                    const timeCell = row.querySelector('.time-cell');
                    if (timeCell) {
                        timeCell.textContent = `${startTimeStr.split(' ')[1].substring(0, 5)} ~ ${endTimeStr.split(' ')[1].substring(0, 5)}`;
                    }
                    
                    // 創建或更新時程（只更新時間，不更動 sort_no）
                    // 檢查是否已存在該團隊的時程
                    let schedule = data.schedules.find(s => s.team_ID === teamId);
                    if (schedule) {
                        // 更新現有時程（確保每個團隊只有一個時程）
                        // 只更新時間，不更動 sort_no（sort_no 由 syncOrderFromDOM() 負責）
                        schedule.time_start_d = startTimeStr;
                        schedule.time_end_d = endTimeStr;
                    } else {
                        // 創建新時程（sort_no 會在 syncOrderFromDOM() 中設置）
                        schedule = {
                            team_ID: teamId,
                            time_start_d: startTimeStr,
                            time_end_d: endTimeStr,
                            sort_no: null // 將由 syncOrderFromDOM() 設置
                        };
                        data.schedules.push(schedule);
                    }
                }
            }
        });
        
        // 將保留的時程數據添加回去（不在表格中的團隊）
        data.schedules.push(...schedulesToKeep);
        
        // 最後確保所有時程資料去重（防止添加特殊時間後出現重複）
        const originalCount = data.schedules.length;
        const uniqueSchedulesMap = new Map();
        data.schedules.forEach(schedule => {
            const teamId = schedule.team_ID;
            if (teamId) {
                // 如果已存在該團隊，保留最新的（後面的會覆蓋前面的）
                // 優先保留 sort_no 較小的（更早的順序）
                const existing = uniqueSchedulesMap.get(teamId);
                if (!existing || (schedule.sort_no !== undefined && existing.sort_no !== undefined && schedule.sort_no < existing.sort_no)) {
                    uniqueSchedulesMap.set(teamId, schedule);
                }
            }
        });
        data.schedules = Array.from(uniqueSchedulesMap.values());
        
        // 如果發現重複，記錄警告
        if (originalCount !== uniqueSchedulesMap.size) {
            console.warn('calculateTimes: 發現重複的時程資料，已去重。原始數量:', originalCount, '去重後數量:', uniqueSchedulesMap.size);
        }
    
        // 計算完成後，更新結束時間顯示
        updateEndTimeDisplay();
    }

    // 檢查並插入特殊時間段
    function checkAndInsertSpecialTime(currentTime, teamIndex) {
        const currentHour = currentTime.getHours();
        const currentMinute = currentTime.getMinutes();
        const currentTimeStr = `${String(currentHour).padStart(2, '0')}:${String(currentMinute).padStart(2, '0')}`;
        
        // 跳過場次預備（已經在計算開始時處理）
        // 場次預備應該在表格中顯示，但不影響團隊報告時間的計算
        
        // 檢查午餐時間（如果當前時間在午餐時間之前，且下一個時間會跨越午餐時間，則跳過午餐時間）
        if (data.specialTimes.lunch.start && data.specialTimes.lunch.end) {
            // 如果當前時間在午餐時間內，直接跳過到午餐結束時間
            if (currentTimeStr >= data.specialTimes.lunch.start && currentTimeStr < data.specialTimes.lunch.end) {
                const [lunchHour, lunchMinute] = data.specialTimes.lunch.end.split(':').map(Number);
                currentTime.setHours(lunchHour, lunchMinute, 0, 0);
                return currentTime;
            }
            
            // 如果當前時間在午餐時間之前，檢查下一個時間點是否會跨越午餐時間
            if (currentTimeStr < data.specialTimes.lunch.start) {
                // 計算下一個時間點（報告開始時間 + 報告時長）
                const nextTime = addMinutes(new Date(currentTime), data.reportDuration || 20);
                const nextHour = nextTime.getHours();
                const nextMinute = nextTime.getMinutes();
                const nextTimeStr = `${String(nextHour).padStart(2, '0')}:${String(nextMinute).padStart(2, '0')}`;
                
                // 如果下一個時間會跨越午餐時間，則跳過到午餐結束時間
                if (nextTimeStr >= data.specialTimes.lunch.start && nextTimeStr < data.specialTimes.lunch.end) {
                    const [lunchHour, lunchMinute] = data.specialTimes.lunch.end.split(':').map(Number);
                    currentTime.setHours(lunchHour, lunchMinute, 0, 0);
                    return currentTime;
                }
                
                // 如果報告結束時間在午餐時間內，也要跳過
                if (nextTimeStr >= data.specialTimes.lunch.start && nextTimeStr < data.specialTimes.lunch.end) {
                    const [lunchHour, lunchMinute] = data.specialTimes.lunch.end.split(':').map(Number);
                    currentTime.setHours(lunchHour, lunchMinute, 0, 0);
                    return currentTime;
                }
            }
        }
        
        // 檢查中場休息
        if (data.specialTimes.break.start && data.specialTimes.break.end) {
            if (currentTimeStr < data.specialTimes.break.start) {
                // 計算下一個時間點
                const nextTime = addMinutes(new Date(currentTime), data.reportDuration || 20);
                const nextHour = nextTime.getHours();
                const nextMinute = nextTime.getMinutes();
                const nextTimeStr = `${String(nextHour).padStart(2, '0')}:${String(nextMinute).padStart(2, '0')}`;
                
                // 如果下一個時間會跨越中場休息，則跳過到中場休息結束時間
                if (nextTimeStr >= data.specialTimes.break.start && nextTimeStr < data.specialTimes.break.end) {
                    const [breakHour, breakMinute] = data.specialTimes.break.end.split(':').map(Number);
                    currentTime.setHours(breakHour, breakMinute, 0, 0);
                    return currentTime;
                }
            } else if (currentTimeStr >= data.specialTimes.break.start && currentTimeStr < data.specialTimes.break.end) {
                // 如果當前時間在中場休息內，跳過到中場休息結束時間
                const [breakHour, breakMinute] = data.specialTimes.break.end.split(':').map(Number);
                currentTime.setHours(breakHour, breakMinute, 0, 0);
                return currentTime;
            }
        }
        
        return currentTime;
    }

    // 初始化拖放功能
    function initSortable() {
        const tbody = document.getElementById('scheduleTableBody');
        
        if (!tbody) {
            console.warn('找不到 scheduleTableBody 元素');
            return;
        }
        
        // 檢查 Sortable 是否已載入
        if (typeof Sortable === 'undefined') {
            console.error('Sortable.js 未載入，無法初始化拖放功能');
            // 嘗試動態載入
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js';
            script.onload = function() {
                console.log('Sortable.js 載入成功，重新初始化拖放功能');
                setTimeout(initSortable, 100);
            };
            script.onerror = function() {
                console.error('無法載入 Sortable.js');
            };
            document.head.appendChild(script);
            return;
        }
        
        // 如果已經初始化，先銷毀
        if (data.sortableInstance) {
            try {
                data.sortableInstance.destroy();
            } catch (e) {
                console.warn('銷毀 Sortable 實例時出錯:', e);
            }
        }
        
        console.log('初始化 Sortable，tbody 元素:', tbody, 'isEditMode:', data.isEditMode);
        
        // 只在編輯模式下才初始化拖放功能
        if (!data.isEditMode) {
            console.log('非編輯模式，不初始化拖放功能');
            return;
        }
        
        // 確保所有團隊行的 draggable 屬性正確設置
        const teamRows = tbody.querySelectorAll('.team-row');
        const specialRows = tbody.querySelectorAll('.special-time-row');
        console.log('找到', teamRows.length, '個團隊行，', specialRows.length, '個特殊時間行');
        
        // 設置所有團隊行為可拖動（不管資訊組還是商管組）
        teamRows.forEach(row => {
            row.draggable = true;
            row.style.cursor = 'move';
            // 移除任何可能阻止拖拽的事件監聽器
            row.ondragstart = null;
            row.ondrag = null;
            console.log('設置團隊行 draggable 為 true，team_ID:', row.getAttribute('data-team-id') || row.dataset.teamId);
        });
        
        // 設置所有特殊時間行為可拖動
        specialRows.forEach(row => {
            row.draggable = true;
            row.style.cursor = 'move';
        });
        
        console.log('準備初始化 Sortable，所有團隊都可以自由換順序');
        
        try {
            data.sortableInstance = new Sortable(tbody, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            chosenClass: 'sortable-chosen',
            // 只允許拖動團隊行，不允許拖動特殊時間行（可選）
            // filter: '.special-time-row', // 過濾掉特殊時間行（如果需要）
            // 允許拖動所有行（團隊行和特殊時間行）
            setData: function(dataTransfer, dragEl) {
                // 不設置默認的拖移數據，使用自定義拖移元素
                dataTransfer.effectAllowed = 'move';
            },
            // 確保可以拖動
            forceFallback: false,
            // 允許拖動的元素選擇器（所有團隊行和特殊時間行都可以拖動）
            draggable: '.team-row, .special-time-row',
            // 不限制移動，允許所有團隊自由換順序
            swapThreshold: 0.65,
            invertSwap: false,
        onStart: function(evt) {
            // 創建自定義拖移元素（橢圓形，只顯示團隊名稱）
            const row = evt.item;
            const projectCell = row.querySelector('.project-cell');
            const teamName = projectCell ? projectCell.textContent.trim() : '團隊';
            
            // 獲取滑鼠位置（從事件中）
            const mouseEvent = evt.originalEvent || window.event;
            let startX = 0, startY = 0;
            if (mouseEvent) {
                startX = mouseEvent.clientX || mouseEvent.pageX || 0;
                startY = mouseEvent.clientY || mouseEvent.pageY || 0;
            }
            
            // 創建橢圓形拖移元素
            const dragElement = document.createElement('div');
            dragElement.className = 'custom-drag-element';
            dragElement.textContent = teamName;
            document.body.appendChild(dragElement);
            
            // 設置初始位置在滑鼠位置
            dragElement.style.left = startX + 'px';
            dragElement.style.top = startY + 'px';
            
            // 保存拖移元素引用
            row._customDragElement = dragElement;
            
            // 隱藏原來的行（使用 opacity 而不是 visibility，避免行消失）
            row._originalOpacity = row.style.opacity;
            row.style.opacity = '0.3';
            // 保持 visibility 可見，這樣 Sortable 才能正常工作
            row.style.visibility = 'visible';
            
            // 添加滑鼠移動監聽器來更新拖移元素位置
            const updateDragPosition = function(e) {
                if (dragElement && dragElement.parentNode) {
                    dragElement.style.left = e.clientX + 'px';
                    dragElement.style.top = e.clientY + 'px';
                }
            };
            
            document.addEventListener('mousemove', updateDragPosition, true);
            row._dragMouseMoveHandler = updateDragPosition;
        },
        onMove: function(evt) {
            // 允許所有移動操作，不限制任何團隊的拖拽
            // 返回 true 表示允許移動，返回 false 表示阻止移動
            // 不返回任何值或返回 true，允許所有移動
            return true;
        },
        onCancel: function(evt) {
            // 如果拖移被取消，也要恢復顯示
            const row = evt.item;
            if (row._customDragElement) {
                if (row._customDragElement.parentNode) {
                    document.body.removeChild(row._customDragElement);
                }
                delete row._customDragElement;
            }
            
            // 移除滑鼠移動監聽器
            if (row._dragMouseMoveHandler) {
                document.removeEventListener('mousemove', row._dragMouseMoveHandler, true);
                delete row._dragMouseMoveHandler;
            }
            
            // 恢復原來的行顯示
            if (row._originalOpacity !== undefined) {
                row.style.opacity = row._originalOpacity;
                delete row._originalOpacity;
            } else {
                row.style.opacity = '';
            }
            // 確保 visibility 保持可見
            row.style.visibility = 'visible';
        },
        onEnd: async function(evt) {
            // 清理自定義拖移元素
            const row = evt.item;
            if (row._customDragElement) {
                if (row._customDragElement.parentNode) {
                    document.body.removeChild(row._customDragElement);
                }
                delete row._customDragElement;
            }
            
            // 移除滑鼠移動監聽器
            if (row._dragMouseMoveHandler) {
                document.removeEventListener('mousemove', row._dragMouseMoveHandler, true);
                delete row._dragMouseMoveHandler;
            }
            
            // 恢復原來的行顯示
            if (row._originalOpacity !== undefined) {
                row.style.opacity = row._originalOpacity;
                delete row._originalOpacity;
            } else {
                row.style.opacity = '';
            }
            // 確保 visibility 保持可見
            row.style.visibility = 'visible';
            
            // 同步 DOM 順序到 data.schedules.sort_no 和 specialTimes.row_order
            syncOrderFromDOM();
            
            // 重新計算時間（按照表格順序）
            calculateTimes();
            
            // 更新組次顯示（syncOrderFromDOM 已經更新了 sort_no，這裡只需要更新顯示）
            updateSequenceNumbers();
            
            // 更新結束時間顯示
            updateEndTimeDisplay();
            
            // 移除自動保存功能，只在用戶明確點擊「儲存」按鈕時才保存
            // 這樣可以避免取消編輯時意外保存資料
            console.log('拖放完成，順序已更新，請點擊「儲存」按鈕以保存變更');
        }
    });
            console.log('Sortable 初始化成功，實例:', data.sortableInstance);
        } catch (error) {
            console.error('初始化 Sortable 時發生錯誤:', error);
            Swal.fire({
                icon: 'error',
                title: '拖放功能初始化失敗',
                text: '無法啟用拖放功能，請刷新頁面重試',
                timer: 3000
            });
        }
    }

    // 更新時程表顯示
    function updateScheduleTable() {
        const tbody = document.getElementById('scheduleTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = '';
        
        // 準備所有行（團隊行 + 特殊時間段行）
        const allRows = [];
        
        // 將時間字符串轉換為分鐘數（用於排序）
        const timeToMinutes = (timeStr) => {
            if (!timeStr || timeStr === '99:99' || timeStr === '00:00') {
                return 999999; // 無效時間排在最後
            }
            const match = timeStr.match(/(\d{1,2}):(\d{2})/);
            if (match) {
                const hours = parseInt(match[1], 10);
                const minutes = parseInt(match[2], 10);
                return hours * 60 + minutes;
            }
            return 999999;
        };
        
        // 添加團隊行
        data.teams.forEach((team, index) => {
            const schedule = data.schedules.find(s => s.team_ID == team.team_ID);
            let timeStart = '99:99';
            if (schedule && schedule.time_start_d) {
                try {
                    const startDate = new Date(schedule.time_start_d);
                    const hours = String(startDate.getHours()).padStart(2, '0');
                    const minutes = String(startDate.getMinutes()).padStart(2, '0');
                    timeStart = `${hours}:${minutes}`;
                } catch (e) {
                    console.warn('解析團隊時間失敗:', schedule.time_start_d, e);
                }
            }
            const sortNo = schedule ? (schedule.sort_no ?? index + 1) : index + 1;
            allRows.push({
                type: 'team',
                data: team,
                index: index + 1,
                schedule: schedule,
                timeStart: timeStart,
                timeMinutes: timeToMinutes(timeStart),
                sort_no: sortNo,
                row_order: 10000 + sortNo  // 時間相同時，特殊時間排在團隊之前
            });
        });
        
        // 添加特殊時間段行（從 data.specialTimesList 載入）
        if (data.specialTimesList && Array.isArray(data.specialTimesList)) {
            data.specialTimesList.forEach((special) => {
                // 跳過 schedule_start 類型，它只是元數據
                if (special.type === 'schedule_start') {
                    return;
                }
                
                // 獲取時間：優先使用 timeStart，否則從 start 構建，最後從 timeRange 解析
                let timeStart = special.timeStart || '00:00';
                if (timeStart === '00:00' || !timeStart) {
                    if (special.start) {
                        timeStart = special.start;
                    } else if (special.timeRange) {
                        // 從 timeRange 解析開始時間（格式：HH:MM-HH:MM）
                        const match = special.timeRange.match(/(\d{1,2}):(\d{2})/);
                        if (match) {
                            timeStart = `${match[1].padStart(2, '0')}:${match[2]}`;
                        }
                    }
                }
                
                allRows.push({
                    type: 'special',
                    data: special,
                    timeStart: timeStart,
                    timeMinutes: timeToMinutes(timeStart),
                    sortOrder: special.sortOrder !== undefined ? special.sortOrder : 9999,
                    row_order: special.row_order !== undefined ? special.row_order : 9999
                });
            });
        }
        
        // 按照時間排序（主軸），然後按 row_order/sortOrder（輔助）
        allRows.sort((a, b) => {
            // 主要排序：按時間（分鐘數）
            const timeA = a.timeMinutes ?? 999999;
            const timeB = b.timeMinutes ?? 999999;
            if (timeA !== timeB) {
                return timeA - timeB;
            }
            
            // 輔助排序：時間相同時，按 row_order 排序（特殊時間較小，會排在團隊之前）
            const orderA = a.row_order ?? a.sortOrder ?? 999999;
            const orderB = b.row_order ?? b.sortOrder ?? 999999;
            return orderA - orderB;
        });
        
        // 創建並插入所有行（按照時間排序後的順序）
        allRows.forEach((rowData) => {
            try {
                if (rowData.type === 'special') {
                    // 確保 timeRange 存在
                    let timeRange = rowData.data.timeRange;
                    if (!timeRange || timeRange === '-') {
                        // 從 start 和 end 構建，或從 timeStart 構建
                        if (rowData.data.start && rowData.data.end) {
                            timeRange = `${rowData.data.start}-${rowData.data.end}`;
                        } else if (rowData.timeStart && rowData.timeStart !== '00:00' && rowData.timeStart !== '99:99') {
                            // 如果只有開始時間，嘗試構建一個合理的時間範圍（假設持續時間）
                            const duration = rowData.data.type === 'lunch' ? 60 : (rowData.data.type === 'break' ? 15 : 10);
                            const startMinutes = rowData.timeMinutes ?? 0;
                            const endMinutes = startMinutes + duration;
                            const endHours = Math.floor(endMinutes / 60);
                            const endMins = endMinutes % 60;
                            timeRange = `${rowData.timeStart}-${String(endHours).padStart(2, '0')}:${String(endMins).padStart(2, '0')}`;
                        } else {
                            timeRange = '-';
                        }
                    }
                    const row = createSpecialTimeRow(rowData.data.type, timeRange);
                    tbody.appendChild(row);
                } else {
                    const row = createTeamRow(rowData.data, rowData.index);
                    tbody.appendChild(row);
                }
            } catch (error) {
                console.error(`創建行時出錯:`, error, rowData);
            }
        });
    
        // 同步 DOM 順序（確保顯示的順序與數據一致）
        syncOrderFromDOM();
        
        // 更新組次顯示和時間
        updateSequenceNumbers();
    
    // 只在編輯模式下重新初始化拖放功能
    if (data.isEditMode) {
        setTimeout(() => {
            initSortable();
        }, 100);
    } else {
        // 非編輯模式下，銷毀拖放功能
        if (data.sortableInstance) {
            try {
                data.sortableInstance.destroy();
                data.sortableInstance = null;
            } catch (e) {
                console.warn('銷毀 Sortable 實例時出錯:', e);
            }
        }
        // 移除行的 draggable 屬性
        const teamRows = tbody.querySelectorAll('.team-row');
        teamRows.forEach(row => {
            row.draggable = false;
        });
    }
}

    // 套用特殊時間
    function applySpecialTimes() {
        const lunchStartEl = document.getElementById('lunchStart');
        const lunchEndEl = document.getElementById('lunchEnd');
        const breakStartEl = document.getElementById('breakStart');
        const breakEndEl = document.getElementById('breakEnd');
        
        if (lunchStartEl && lunchEndEl && lunchStartEl.value && lunchEndEl.value) {
            data.specialTimes.lunch.start = lunchStartEl.value;
            data.specialTimes.lunch.end = lunchEndEl.value;
        }
        
        if (breakStartEl && breakEndEl && breakStartEl.value && breakEndEl.value) {
            data.specialTimes.break.start = breakStartEl.value;
            data.specialTimes.break.end = breakEndEl.value;
        }
        
        calculateTimes();
        updateScheduleTable();
        
        Swal.fire('成功', '特殊時間已套用', 'success');
    }
    
    // 儲存時程表資訊
    async function saveScheduleInfo() {
        const scheduleInfoEl = document.getElementById('scheduleInfo');
        const titleInput = document.getElementById('scheduleTitle');
        
        // 在保存前，先同步 DOM 順序到 data.schedules.sort_no 和 specialTimes.row_order
        syncOrderFromDOM();
        
        // 從表格中收集特殊時間行並轉換為 JSON
        const tbody = document.getElementById('scheduleTableBody');
        const specialTimes = [];
        
        if (tbody) {
            const specialTimeRows = tbody.querySelectorAll('tr.special-time-row');
            specialTimeRows.forEach((row, index) => {
                const type = row.getAttribute('data-special-type');
                const timeCell = row.querySelector('.time-cell');
                const timeRange = timeCell ? timeCell.textContent.trim() : '';
                
                if (type && timeRange) {
                    // 解析時間範圍（格式：HH:MM-HH:MM）
                    const timeMatch = timeRange.match(/(\d{1,2}):(\d{2})\s*[-~]\s*(\d{1,2}):(\d{2})/);
                    if (timeMatch) {
                        const start = `${timeMatch[1].padStart(2, '0')}:${timeMatch[2]}`;
                        const end = `${timeMatch[3].padStart(2, '0')}:${timeMatch[4]}`;
                        
                        // 使用 syncOrderFromDOM 同步後的 row_order
                        let finalRowOrder = index + 1; // 預設值
                        if (data.specialTimesList && Array.isArray(data.specialTimesList)) {
                            const existingSpecial = data.specialTimesList.find(st => st.type === type);
                            if (existingSpecial && existingSpecial.row_order !== undefined) {
                                finalRowOrder = existingSpecial.row_order;
                            }
                        }
                        
                        specialTimes.push({
                            type: type,
                            start: start,
                            end: end,
                            row_order: finalRowOrder // 使用 row_order
                        });
                    }
                }
            });
        }
        
        // 若 DOM 沒有特殊時間列（例如刪除團隊後尚未重繪），改從 data.specialTimesList 收集，避免特殊時間遺失
        if (specialTimes.length === 0 && data.specialTimesList && Array.isArray(data.specialTimesList)) {
            const excludeTypes = ['schedule_start', 'report_duration'];
            data.specialTimesList.forEach((item) => {
                if (!item || excludeTypes.indexOf(item.type) >= 0) return;
                const start = item.start || '';
                const end = item.end || '';
                if (item.type && start && end) {
                    specialTimes.push({
                        type: item.type,
                        start: start,
                        end: end,
                        row_order: item.row_order !== undefined ? item.row_order : 9999
                    });
                }
            });
        }
        
        // 獲取開始時間
        const startTimeInput = document.getElementById('startTime');
        if (startTimeInput && startTimeInput.value) {
            const startTime = new Date(startTimeInput.value);
            const hours = String(startTime.getHours()).padStart(2, '0');
            const minutes = String(startTime.getMinutes()).padStart(2, '0');
            const formattedTime = `${hours}:${minutes}`;
            
            // 將開始時間添加到 specialTimes 陣列中
            specialTimes.push({
                type: 'schedule_start',
                start: formattedTime,
                end: formattedTime,
                sortOrder: -1 // 確保開始時間始終在最前面
            });
        }
        
        // 獲取報告時間間隔（從 data.reportDuration）
        const reportDuration = data.reportDuration || 20;
        // 驗證報告時間間隔（1-60分鐘）
        const validReportDuration = Math.max(1, Math.min(60, reportDuration));
        data.reportDuration = validReportDuration;
        
        // 將報告時間間隔添加到 specialTimes 陣列中
        specialTimes.push({
            type: 'report_duration',
            duration: validReportDuration,
            sortOrder: -2 // 確保報告時間間隔在最前面
        });
        
        // 將特殊時間轉換為 JSON 字串
        const content = JSON.stringify(specialTimes);
        const title = titleInput ? titleInput.value.trim() : '';
        
        // 判斷是新增還是更新
        // 如果按過「編輯」按鈕且有 currentTinformaID，則為更新
        // 否則為新增
        const isUpdate = data.hasClickedEdit && data.currentTinformaID;
        console.log('保存時程表資訊，isUpdate:', isUpdate, 'hasClickedEdit:', data.hasClickedEdit, 'currentTinformaID:', data.currentTinformaID);
        console.log('特殊時間 JSON:', content);
        
        try {
            const getApiPath = function() {
                const pathname = window.location.pathname || '';
                const hash = window.location.hash || '';
                if (pathname.includes('/main.php')) {
                    const mainIndex = pathname.indexOf('/main.php');
                    const projectRoot = pathname.substring(0, mainIndex);
                    return projectRoot + (projectRoot.endsWith('/') ? '' : '/') + 'api.php';
                }
                if (hash.includes('pages/') || pathname.includes('/pages/')) {
                    return '../api.php';
                }
                return 'api.php';
            };
            
            const apiPath = getApiPath();
            const formData = new FormData();
            formData.append('tinforma_content', content);
            if (title) {
                formData.append('tinforma_title', title);
            }
            // 只有在更新模式時才傳遞 tinforma_ID
            if (isUpdate) {
                formData.append('tinforma_ID', data.currentTinformaID);
                console.log('更新現有時程表，tinforma_ID:', data.currentTinformaID);
            } else {
                console.log('創建新時程表，不傳遞 tinforma_ID');
            }
            const cohortSelect = document.getElementById('cohortSelect');
            const cohort_ID = cohortSelect ? cohortSelect.value : null;
            if (cohort_ID) {
                formData.append('cohort_ID', cohort_ID);
            }
            formData.append('online_scoring_open', data.onlineScoringOpen ? '1' : '0');
            
            console.log('儲存時程表資訊：', { isUpdate, cohort_ID, online_scoring_open: data.onlineScoringOpen, title });
            
            const response = await fetch(`${apiPath}?do=save_schedule_info`, {
                method: 'POST',
                body: formData
            });
            
            const responseData = await response.json();
            
            if (responseData.suggest_skip_reason) {
                console.warn('未自動建立審查建議表，原因:', responseData.suggest_skip_reason, '(not_new=非首次新增, scoring_off=線上評分已關閉, no_title=無標題, title_is_update=時程表標題為update不建立, no_uid=未登入, duplicate=該屆別已有同標題建議表, insert_failed=寫入失敗)');
                if (responseData.suggest_skip_reason === 'insert_failed' && responseData.suggest_error) {
                    console.error('建立建議表錯誤訊息:', responseData.suggest_error);
                }
            }
            if (responseData.ok && responseData.suggest_created) {
                console.log('已自動建立審查建議表');
            }
            
            if (responseData.ok) {
                // 確保使用 API 返回的新 ID（新增時）或確認的 ID（更新時）
                const newTinformaID = responseData.tinforma_ID;
                console.log('保存成功，API 返回的 tinforma_ID:', newTinformaID);
                data.currentTinformaID = newTinformaID;
                // 時程表編輯模式可隨時修改線上評分，不再鎖定
                const onlineScoringBox = document.getElementById('online-scoring-box');
                if (onlineScoringBox) onlineScoringBox.style.display = 'block';
                
                // 注意：不要調用 loadScheduleInfoForRender()，因為它會載入最新的時程表，會覆蓋已載入的資料
                // 只在 saveSchedules() 中保存團隊時程時才需要重新載入
                
                // 退出編輯模式，保持在預覽非編輯模式
                exitEditMode();
                
                if (responseData.suggest_created) {
                    Swal.fire('成功', '時程表資訊已保存，已自動建立審查建議表', 'success');
                } else if (responseData.suggest_skip_reason === 'insert_failed' && responseData.suggest_error) {
                    Swal.fire({
                        icon: 'warning',
                        title: '時程表已保存，但審查建議表建立失敗',
                        text: '請聯絡管理員檢查後端日誌。錯誤：' + (responseData.suggest_error || '')
                    });
                } else if (responseData.suggest_skip_reason) {
                    Swal.fire({
                        icon: 'info',
                        title: '時程表已保存',
                        text: '未自動建立審查建議表（原因：' + (responseData.suggest_skip_reason === 'duplicate' ? '該屆別已有同名稱建議表' : responseData.suggest_skip_reason === 'scoring_off' ? '未開放線上評分' : responseData.suggest_skip_reason === 'no_title' ? '未填標題' : responseData.suggest_skip_reason) + '）'
                    });
                } else {
                    Swal.fire('成功', '時程表資訊已保存', 'success');
                }
            } else {
                Swal.fire('錯誤', responseData.msg || '保存失敗', 'error');
            }
        } catch (error) {
            console.error('保存時程表資訊錯誤:', error);
            Swal.fire('錯誤', '無法保存時程表資訊', 'error');
        }
    }
    
    // 儲存團隊時程（靜默版本，不顯示提示）
    async function saveSchedulesSilently() {
        if (!data.currentTinformaID) {
            console.warn('無法自動保存：缺少 tinforma_ID');
            return;
        }
        
        // 重新計算時間以確保最新
        calculateTimes();
        
        // 檢查 schedules 是否有有效資料
        if (!data.schedules || data.schedules.length === 0) {
            console.warn('無法自動保存：沒有時程資料');
            return;
        }
        
        // 重要：在保存之前，按照表格中的實際順序更新所有團隊的 sort_no
        const tbody = document.getElementById('scheduleTableBody');
        if (tbody) {
            const allRows = Array.from(tbody.querySelectorAll('tr.team-row, tr.special-time-row'));
            let teamIndex = 0;
            allRows.forEach((row) => {
                if (row.classList.contains('team-row')) {
                    teamIndex++; // 只計算團隊行的索引，按照表格中的實際順序
                    const teamId = parseInt(row.getAttribute('data-team-id')) || parseInt(row.dataset.teamId);
                    // 找到對應的時程並更新 sort_no
                    const schedules = data.schedules.filter(s => s.team_ID == teamId);
                    schedules.forEach(schedule => {
                        schedule.sort_no = teamIndex;
                    });
                }
            });
        }
        
        // 驗證 schedules 資料格式
        let validSchedules = data.schedules.filter(s => 
            s.team_ID && s.time_start_d && s.time_end_d
        );
        
        // 去重：確保每個 team_ID 只出現一次（保留 sort_no 最小的）
        const teamIdMap = new Map();
        validSchedules.forEach(schedule => {
            const teamId = schedule.team_ID;
            const existing = teamIdMap.get(teamId);
            if (!existing) {
                teamIdMap.set(teamId, schedule);
            } else {
                const existingSortNo = existing.sort_no ?? 999999;
                const currentSortNo = schedule.sort_no ?? 999999;
                if (currentSortNo < existingSortNo) {
                    teamIdMap.set(teamId, schedule);
                }
            }
        });
        validSchedules = Array.from(teamIdMap.values());
        
        // 按照 sort_no 排序，確保保存的順序正確
        validSchedules.sort((a, b) => {
            const sortA = a.sort_no ?? 999999;
            const sortB = b.sort_no ?? 999999;
            return sortA - sortB;
        });
        
        if (validSchedules.length === 0) {
            console.warn('無法自動保存：沒有有效的時程資料');
            return;
        }
        
        try {
            const getApiPath = function() {
                const pathname = window.location.pathname || '';
                const hash = window.location.hash || '';
                if (pathname.includes('/main.php')) {
                    const mainIndex = pathname.indexOf('/main.php');
                    const projectRoot = pathname.substring(0, mainIndex);
                    return projectRoot + (projectRoot.endsWith('/') ? '' : '/') + 'api.php';
                }
                if (hash.includes('pages/') || pathname.includes('/pages/')) {
                    return '../api.php';
                }
                return 'api.php';
            };
            
            const apiPath = getApiPath();
            const formData = new FormData();
            formData.append('tinforma_ID', data.currentTinformaID);
            formData.append('schedules', JSON.stringify(validSchedules));
            
            console.log('發送保存請求:', {
                tinforma_ID: data.currentTinformaID,
                schedules_count: validSchedules.length,
                api_path: apiPath
            });
            
            const response = await fetch(`${apiPath}?do=save_team_schedules`, {
                method: 'POST',
                body: formData
            });
            
            const responseData = await response.json();
            
            if (responseData.ok) {
                console.log('自動保存順序成功，已保存', validSchedules.length, '筆時程資料');
            } else {
                console.error('自動保存順序失敗:', responseData.msg || responseData);
            }
        } catch (error) {
            console.error('自動保存團隊時程錯誤:', error);
            throw error;
        }
    }
    
    // 儲存團隊時程（顯示提示版本）
    async function saveSchedules() {
        const cohortSelect = document.getElementById('cohortSelect');
        const cohort_ID = cohortSelect ? cohortSelect.value : null;
        
        if (!cohort_ID) {
            Swal.fire('提示', '請先選擇屆別', 'warning');
            return;
        }
        
        // 標題防呆驗證
        const titleInput = document.getElementById('scheduleTitle');
        const title = titleInput ? titleInput.value.trim() : '';
        
        if (!title) {
            Swal.fire({
                icon: 'warning',
                title: '標題不能為空',
                text: '請輸入時程表標題後再存檔',
                confirmButtonText: '確定'
            });
            if (titleInput) {
                titleInput.focus();
            }
            return;
        }
        
        // 判斷是新增還是更新
        // 如果沒有按過「編輯」按鈕，或者沒有 currentTinformaID，則為新增
        // 如果按過「編輯」按鈕且有 currentTinformaID，則為更新
        const isUpdateMode = data.hasClickedEdit && data.currentTinformaID;
        console.log('存檔模式判斷:', {
            hasClickedEdit: data.hasClickedEdit,
            currentTinformaID: data.currentTinformaID,
            isUpdateMode: isUpdateMode
        });
        
        if (!isUpdateMode) {
            // 新增模式：創建新的時程表
            console.log('新增模式：創建新的時程表');
            // 確保 currentTinformaID 為 null，讓 API 創建新的
            data.currentTinformaID = null;
            
            // 先保存時程表資訊（包含標題），創建新的
            await saveScheduleInfo();
            
            if (!data.currentTinformaID) {
                Swal.fire('錯誤', '無法創建時程表資訊', 'error');
                return;
            }
        } else {
            // 更新模式：更新現有的時程表
            console.log('更新模式：更新現有的時程表，tinforma_ID:', data.currentTinformaID);
            // 先保存特殊時間資訊（JSON 格式）和標題
            await saveScheduleInfo();
        }
        
        // 重要：在保存之前，同步 DOM 順序到 data.schedules.sort_no 和 specialTimes.row_order
        syncOrderFromDOM();
        
        // 重新計算時間以確保最新（calculateTimes 只負責時間計算，不會更動 sort_no）
        calculateTimes();
        
        // 若沒有任何團隊時程，仍可保存（特殊時間已由 saveScheduleInfo 寫入）；後續會送出空 schedules 以清除該時程表的團隊時程
        const hasSchedules = data.schedules && data.schedules.length > 0;
        const hasSpecialTimes = data.specialTimesList && Array.isArray(data.specialTimesList) && data.specialTimesList.length > 0;
        if (!hasSchedules && !hasSpecialTimes) {
            Swal.fire('提示', '沒有時程資料可保存', 'warning');
            return;
        }
        
        if (!hasSchedules) {
            // 僅有特殊時間、無團隊：仍送出空陣列給後端，以清除該 tinforma_ID 的 timedata
        }
        
        try {
            const getApiPath = function() {
                const pathname = window.location.pathname || '';
                const hash = window.location.hash || '';
                if (pathname.includes('/main.php')) {
                    const mainIndex = pathname.indexOf('/main.php');
                    const projectRoot = pathname.substring(0, mainIndex);
                    return projectRoot + (projectRoot.endsWith('/') ? '' : '/') + 'api.php';
                }
                if (hash.includes('pages/') || pathname.includes('/pages/')) {
                    return '../api.php';
                }
                return 'api.php';
            };
            
            const apiPath = getApiPath();
            const formData = new FormData();
            formData.append('tinforma_ID', data.currentTinformaID);
            
            // 確保只保存 data.schedules 中的團隊（已刪除的不會在其中）
            // 過濾掉無效的時程資料
            let validSchedules = data.schedules.filter(s => 
                s.team_ID && s.time_start_d && s.time_end_d
            );
            
            // 去重：確保每個 team_ID 只出現一次（保留 sort_no 最小的，即表格順序最前面的）
            const teamIdMap = new Map();
            validSchedules.forEach(schedule => {
                const teamId = schedule.team_ID;
                const existing = teamIdMap.get(teamId);
                if (!existing) {
                    teamIdMap.set(teamId, schedule);
                } else {
                    // 如果已存在該團隊，保留 sort_no 較小的（表格順序更前面的）
                    const existingSortNo = existing.sort_no ?? 999999;
                    const currentSortNo = schedule.sort_no ?? 999999;
                    if (currentSortNo < existingSortNo) {
                        teamIdMap.set(teamId, schedule);
                    }
                }
            });
            validSchedules = Array.from(teamIdMap.values());
            
            // 按照 sort_no 排序，確保保存的順序正確
            validSchedules.sort((a, b) => {
                const sortA = a.sort_no ?? 999999;
                const sortB = b.sort_no ?? 999999;
                return sortA - sortB;
            });
            
            console.log('準備存檔，tinforma_ID:', data.currentTinformaID);
            console.log('要保存的時程數量:', validSchedules.length);
            console.log('要保存的團隊 ID 列表:', validSchedules.map(s => s.team_ID));
            console.log('要保存的時程順序（sort_no）:', validSchedules.map(s => ({ team_ID: s.team_ID, sort_no: s.sort_no })));
            
            // 確保所有時程都有 sort_no 值（不能為 null 或 undefined）
            validSchedules.forEach((schedule, index) => {
                if (schedule.sort_no === null || schedule.sort_no === undefined) {
                    console.warn(`警告：團隊 ${schedule.team_ID} 的 sort_no 為 null/undefined，設置為 ${index + 1}`);
                    schedule.sort_no = index + 1;
                }
            });
            
            // 再次按 sort_no 排序，確保順序正確
            validSchedules.sort((a, b) => {
                const sortA = a.sort_no ?? 999999;
                const sortB = b.sort_no ?? 999999;
                return sortA - sortB;
            });
            
            console.log('最終要保存的時程順序（sort_no）:', validSchedules.map(s => ({ team_ID: s.team_ID, sort_no: s.sort_no })));
            
            formData.append('schedules', JSON.stringify(validSchedules));
            
            const response = await fetch(`${apiPath}?do=save_team_schedules`, {
                method: 'POST',
                body: formData
            });
            
            const responseData = await response.json();
            
            if (responseData.ok) {
                console.log('存檔成功，已保存', validSchedules.length, '個團隊的時程');
                console.log('保存的時程順序:', validSchedules.map(s => ({ team_ID: s.team_ID, sort_no: s.sort_no })));
                
                // 存檔成功後，不需要重新載入時程表資料
                // 因為我們已經在保存前更新了 sort_no，並且數據已經保存到數據庫
                // 重新載入可能會導致數據丟失或順序錯誤
                // 只需要確保當前顯示的順序與保存的順序一致即可
                console.log('存檔成功，保持當前顯示狀態');
                
                // 存檔成功後，確保數據仍然存在
                console.log('存檔成功後的數據狀態:');
                console.log('data.schedules 數量:', data.schedules ? data.schedules.length : 0);
                console.log('data.teams 數量:', data.teams ? data.teams.length : 0);
                
                // 確保表格正確渲染（使用保存後的數據）
                if (data.schedules && data.schedules.length > 0) {
                    // 按照 sort_no 排序，確保順序正確
                    data.schedules.sort((a, b) => {
                        const sortA = a.sort_no ?? 999999;
                        const sortB = b.sort_no ?? 999999;
                        return sortA - sortB;
                    });
                    // 重新渲染表格
                    renderScheduleTable();
                }
                
                // 存檔成功後，退出編輯模式（設置為唯讀）
                exitEditMode();
                
                // 顯示成功訊息
                Swal.fire({
                    icon: 'success',
                    title: '存檔成功',
                    timer: 1500,
                    showConfirmButton: false
                });
                
                // 存檔成功後，停留在當前頁面，不切換到檔案列表模式
            } else {
                Swal.fire('錯誤', responseData.msg || '保存失敗', 'error');
            }
        } catch (error) {
            console.error('保存團隊時程錯誤:', error);
            Swal.fire('錯誤', '無法保存團隊時程', 'error');
        }
    }
    
    // 工具函數
    function formatDateTime(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${year}-${month}-${day} ${hours}:${minutes}:00`;
    }
    
    function formatDateTimeLocal(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }
    
    function formatTimeRange(startStr, endStr) {
        if (!startStr || !endStr) return '';
        const start = new Date(startStr);
        const end = new Date(endStr);
        const startTime = `${String(start.getHours()).padStart(2, '0')}:${String(start.getMinutes()).padStart(2, '0')}`;
        const endTime = `${String(end.getHours()).padStart(2, '0')}:${String(end.getMinutes()).padStart(2, '0')}`;
        return `${startTime}-${endTime}`;
    }
    
    function addMinutes(date, minutes) {
        return new Date(date.getTime() + minutes * 60000);
    }
    
    // 將函數暴露到全域作用域（如果需要從 HTML 調用）
    window.loadScheduleInfo = loadScheduleInfo;
    window.parseSpecialTimes = parseSpecialTimes;
    window.createTeamRow = createTeamRow;
    window.createSpecialTimeRow = createSpecialTimeRow;
    window.insertSpecialTime = insertSpecialTime;
    window.doInsertSpecialTime = doInsertSpecialTime;
    window.validateTimeRange = validateTimeRange;
    window.updateStartTime = updateStartTime;
    window.updateEndTimeDisplay = updateEndTimeDisplay;
    window.updateSequenceNumbers = updateSequenceNumbers;
    window.checkAndInsertSpecialTime = checkAndInsertSpecialTime;
    window.applySpecialTimes = applySpecialTimes;
    window.saveScheduleInfo = saveScheduleInfo;
    window.saveSchedules = saveSchedules;
    window.formatDateTime = formatDateTime;
    window.formatDateTimeLocal = formatDateTimeLocal;
    window.formatTimeRange = formatTimeRange;
    window.addMinutes = addMinutes;
    window.renderScheduleTable = renderScheduleTable;
    window.updateScheduleTable = updateScheduleTable;
    window.loadTeams = loadTeams;
    window.calculateTimes = calculateTimes;
    
    // 載入屆別列表
    async function loadCohorts() {
        try {
            const apiUrl = resolveScheduleApiUrl();
            console.log('載入屆別，API URL:', apiUrl);
            const response = await fetch(`${apiUrl}?action=listCohorts`);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const result = await response.json();
            console.log('屆別 API 回應:', result);
            
            const cohortSelect = document.getElementById('cohortSelect');
            if (!cohortSelect) {
                console.warn('找不到屆別選擇器元素');
                return;
            }
            
            if (result.success && result.data && Array.isArray(result.data) && result.data.length > 0) {
                cohortSelect.innerHTML = '<option value="">請選擇屆別</option>';
                result.data.forEach(cohort => {
                    const option = document.createElement('option');
                    option.value = cohort.cohort_ID;
                    option.textContent = cohort.cohort_name || `${cohort.cohort_ID}級`;
                    cohortSelect.appendChild(option);
                });
                console.log(`已載入 ${result.data.length} 個屆別`);
            } else {
                cohortSelect.innerHTML = '<option value="">查無屆別資料</option>';
                console.warn('屆別資料為空');
            }
        } catch (error) {
            console.error('載入屆別失敗:', error);
            const cohortSelect = document.getElementById('cohortSelect');
            if (cohortSelect) {
                cohortSelect.innerHTML = '<option value="">載入失敗，請重新整理</option>';
            }
        }
    }
    
    // 檢查並顯示模式（檔案列表或時程表編輯界面）
    
    async function checkAndDisplayMode(cohort_ID) {
        try {
            if (!cohort_ID) {
                // 如果沒有屆別，顯示編輯界面
                const fileListContainer = document.getElementById('schedule-file-list');
                const tableCard = document.getElementById('schedule-table-card');
                if (fileListContainer) fileListContainer.style.display = 'none';
                if (tableCard) tableCard.style.display = 'none';
                return;
            }
            
            const apiUrl = resolveScheduleApiUrl();
            console.log('API 路徑:', apiUrl);
            const requestUrl = `${apiUrl}?action=listTitles&cohort_ID=${cohort_ID}`;
            console.log('請求 URL:', requestUrl);
            
            const response = await fetch(requestUrl);
            
            // 檢查 HTTP 狀態碼
            if (!response.ok) {
                console.error('API 請求失敗，HTTP 狀態:', response.status);
                // 即使 API 失敗，也顯示編輯界面
                showEditMode();
                await loadTeams(cohort_ID);
                return;
            }
            
            const responseData = await response.json();
            
            console.log('API 回應:', responseData);
            
            const fileListContainer = document.getElementById('schedule-file-list');
            const tableCard = document.getElementById('schedule-table-card');
            const saveBtn = document.getElementById('schedule-save-btn');
            const exportBtn = document.getElementById('exportPDFBtn');
            const editAllBtn = document.getElementById('schedule-edit-all-btn');
            const backHomeBtn = document.getElementById('schedule-back-home-btn');
            const newScheduleBtn = document.getElementById('schedule-new-schedule-btn');
            
            // 檢查是否有資料
            if (responseData.success && Array.isArray(responseData.data) && responseData.data.length > 0) {
                console.log('有資料，顯示檔案列表，標題數量:', responseData.data.length);
                
                // 有資料，顯示檔案列表（初始頁）
                showFileListMode();
                
                // 渲染檔案列表
                await displayFileList(cohort_ID, responseData.data);
                console.log('檔案列表已渲染');
            } else {
                console.log('沒有資料，顯示時程表編輯界面');
                // 沒有資料，顯示時程表編輯界面
                showEditMode();
                // 設置為唯讀模式（因為沒有已保存的資料）
                exitEditMode();
                console.log('開始載入團隊資料，屆別 ID:', cohort_ID);
                await loadTeams(cohort_ID);
                console.log('團隊資料載入完成，團隊數量:', data.teams ? data.teams.length : 0);
            }
        } catch (error) {
            console.error('檢查顯示模式錯誤:', error);
            // 發生錯誤時，顯示編輯界面
            showEditMode();
            if (cohort_ID) {
                await loadTeams(cohort_ID);
            }
        }
    }
    
    // 顯示檔案列表模式
    function showFileListMode() {
        const fileListContainer = document.getElementById('schedule-file-list');
        const tableCard = document.getElementById('schedule-table-card');
        const saveBtn = document.getElementById('schedule-save-btn');
        const exportBtn = document.getElementById('exportPDFBtn');
        const editAllBtn = document.getElementById('schedule-edit-all-btn');
        const backHomeBtn = document.getElementById('schedule-back-home-btn');
        const addTeamBtn = document.getElementById('schedule-add-team-btn');
        const newScheduleBtn = document.getElementById('schedule-new-schedule-btn');
        const specialTimeOptions = document.getElementById('special-time-options');
        const cohortSelect = document.getElementById('cohortSelect');
        const cohort_ID = cohortSelect ? cohortSelect.value : null;
        
        // 清空時間欄位
        const startTimeInput = document.getElementById('startTime');
        const endTimeInput = document.getElementById('endTime');
        const endTimeContainer = document.getElementById('endTimeContainer');
        if (startTimeInput) {
            startTimeInput.value = '';
        }
        if (endTimeInput) {
            endTimeInput.value = '';
        }
        if (endTimeContainer) {
            endTimeContainer.style.display = 'none';
        }
        
        // 清空標題（可選，根據需求決定是否保留）
        const titleInput = document.getElementById('scheduleTitle');
        if (titleInput) {
            titleInput.value = '';
        }
        
        // 重置 currentTinformaID
        data.currentTinformaID = null;
        data.schedules = [];
        data.teams = [];
        
        if (fileListContainer) {
            fileListContainer.style.setProperty('display', 'grid', 'important');
            fileListContainer.style.setProperty('visibility', 'visible', 'important');
            fileListContainer.style.setProperty('opacity', '1', 'important');
        }
        if (tableCard) tableCard.style.display = 'none';
        // 隱藏特殊時間選項區域（只在編輯模式下顯示）
        if (specialTimeOptions) {
            specialTimeOptions.style.display = 'none';
        }
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.style.display = 'none';
        }
        // 編輯按鈕在檔案列表模式下隱藏，但保持 disabled 狀態
        if (editAllBtn) {
            editAllBtn.disabled = !cohort_ID;
            editAllBtn.style.display = 'none';
        }
        if (exportBtn) {
            // 檔案列表模式下，如果有選擇屆別，匯出按鈕應該可用
            exportBtn.disabled = !cohort_ID;
            exportBtn.style.display = 'none';
        }
        const exportWordBtn = document.getElementById('exportWordBtn');
        if (exportWordBtn) {
            exportWordBtn.disabled = !cohort_ID;
            exportWordBtn.style.display = 'none';
        }
        // 返回上一頁按鈕：如果從integrate打開，一直顯示；否則隱藏
        const urlParams = getUrlParams();
        const fromIntegrate = urlParams.get('from_integrate') === '1';
        if (backHomeBtn) {
            backHomeBtn.style.display = fromIntegrate ? 'inline-block' : 'none';
        }
        if (newScheduleBtn) {
            newScheduleBtn.disabled = !cohort_ID;
            newScheduleBtn.style.display = 'inline-block';
        }
        if (addTeamBtn) {
            addTeamBtn.disabled = true;
            addTeamBtn.style.display = 'none';
        }
        const onlineScoringBox = document.getElementById('online-scoring-box');
        if (onlineScoringBox) onlineScoringBox.style.display = 'none';
    }
    
    // 顯示編輯模式
    function showEditMode() {
        const fileListContainer = document.getElementById('schedule-file-list');
        const tableCard = document.getElementById('schedule-table-card');
        const saveBtn = document.getElementById('schedule-save-btn');
        const exportBtn = document.getElementById('exportPDFBtn');
        const editAllBtn = document.getElementById('schedule-edit-all-btn');
        const backHomeBtn = document.getElementById('schedule-back-home-btn');
        const addTeamBtn = document.getElementById('schedule-add-team-btn');
        
        // 檢查是否從integrate頁面打開
        const urlParams = getUrlParams();
        const fromIntegrate = urlParams.get('from_integrate') === '1';
        const newScheduleBtn = document.getElementById('schedule-new-schedule-btn');
        const cohortSelect = document.getElementById('cohortSelect');
        const cohort_ID = cohortSelect ? cohortSelect.value : null;
        
        if (fileListContainer) fileListContainer.style.display = 'none';
        if (tableCard) tableCard.style.display = 'block';
        if (saveBtn) {
            // 「編輯/儲存」改由同一顆按鈕負責，這裡保持隱藏
            saveBtn.disabled = true;
            saveBtn.style.display = 'none';
        }
        // 返回上一頁按鈕：如果從integrate打開，一直顯示
        if (backHomeBtn) {
            backHomeBtn.style.display = fromIntegrate ? 'inline-block' : 'none';
        }
        // 編輯按鈕只在有選擇屆別時才啟用
        if (editAllBtn) {
            editAllBtn.disabled = !cohort_ID;
            editAllBtn.style.display = 'inline-block';
        }
        if (exportBtn) {
            // 編輯模式下啟用匯出按鈕（非編輯模式時也可以匯出）
            exportBtn.disabled = !cohort_ID;
            exportBtn.style.display = 'inline-block';
        }
        const exportWordBtn = document.getElementById('exportWordBtn');
        if (exportWordBtn) {
            exportWordBtn.disabled = !cohort_ID;
            exportWordBtn.style.display = 'inline-block';
        }
        // 返回上一頁按鈕：如果從integrate打開，一直顯示
        if (backHomeBtn) {
            backHomeBtn.style.display = fromIntegrate ? 'inline-block' : (cohort_ID ? 'inline-block' : 'none');
        }
        if (newScheduleBtn) newScheduleBtn.style.display = 'none';
        // 編輯模式下顯示「加入團隊」按鈕
        if (addTeamBtn) {
            addTeamBtn.disabled = !cohort_ID;
            addTeamBtn.style.display = 'inline-block';
        }
        const onlineScoringBox = document.getElementById('online-scoring-box');
        if (onlineScoringBox) {
            // 僅在「尚未存檔過評分選項」時顯示此列，存檔後不再顯示且不可更改
            onlineScoringBox.style.display = data.scoringStatusLocked ? 'none' : 'block';
            if (!data.scoringStatusLocked) updateOnlineScoringUI();
        }
    }
    
    // 顯示檔案列表
    async function displayFileList(cohort_ID, titles) {
        const fileListContainer = document.getElementById('schedule-file-list');
        if (!fileListContainer) {
            // 可能當前頁面已切換（例如在 suggest 頁），容器不存在屬正常，不輸出錯誤
            return;
        }
        
        console.log('displayFileList 開始，titles:', titles);
        
        // 如果沒有標題，顯示空狀態
        if (!titles || titles.length === 0) {
            console.warn('沒有標題資料');
            fileListContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: #6c757d;">尚無時程表檔案</div>';
            return;
        }
        
        // 取得每個標題的資訊
        const fileData = [];
        for (const title of titles) {
            try {
                const apiUrl = resolveScheduleApiUrl();
                const r = await fetch(`${apiUrl}?action=getTitleInfo&cohort_ID=${cohort_ID}&title=${encodeURIComponent(title)}`);
                const j = await r.json();
                
                if (j.success && j.data) {
                    fileData.push({
                        title: title,
                        date: j.data.latest_date || '',
                        teamCount: j.data.team_count || 0
                    });
                } else {
                    fileData.push({
                        title: title,
                        date: '',
                        teamCount: 0
                    });
                }
            } catch (err) {
                console.error(`取得標題 ${title} 資訊失敗:`, err);
                fileData.push({
                    title: title,
                    date: '',
                    teamCount: 0
                });
            }
        }
        
        // 生成檔案列表 HTML
        let html = '';
        fileData.forEach((file) => {
            if (!file.title || file.title.trim() === '') {
                return; // 跳過空標題
            }
            
            let displayDate = '';
            if (file.date) {
                try {
                    const date = new Date(file.date);
                    if (!isNaN(date.getTime())) {
                        displayDate = date.toLocaleDateString('zh-TW');
                    }
                } catch (e) {
                    console.warn('日期格式錯誤:', file.date);
                }
            }
            
            // 轉義 HTML 以防止 XSS
            const safeTitle = escapeHtml(file.title);
            const safeDate = displayDate ? escapeHtml(displayDate) : '';
            const teamCount = file.teamCount || 0;
            
            html += `
                <div class="schedule-file-card" data-title="${safeTitle}" data-cohort-id="${cohort_ID}">
                    <div class="schedule-file-card-buttons">
                        <button class="schedule-card-btn schedule-card-btn-notify" 
                                onclick="event.stopPropagation(); sendScheduleNotification('${safeTitle}', ${cohort_ID})" 
                                title="發送通知">
                            <i class="fas fa-bell"></i>
                        </button>
                        <button class="schedule-card-btn schedule-card-btn-export" 
                                onclick="event.stopPropagation(); exportScheduleFile('${safeTitle}', ${cohort_ID})" 
                                title="匯出">
                            <i class="fas fa-download"></i>
                        </button>
                    </div>
                    <div onclick="openScheduleFile('${safeTitle}', ${cohort_ID})" style="cursor: pointer;">
                        <div class="schedule-file-icon">📄</div>
                        <div class="schedule-file-name">${safeTitle}</div>
                        ${safeDate ? `<div class="schedule-file-date">${safeDate}</div>` : ''}
                        ${teamCount > 0 ? `<div class="schedule-file-count">${teamCount} 組</div>` : ''}
                    </div>
                </div>
            `;
        });
        
        if (html === '') {
            console.warn('沒有生成任何檔案卡片');
            fileListContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: #6c757d;">尚無時程表檔案</div>';
        } else {
            fileListContainer.innerHTML = html;
            console.log('檔案列表 HTML 已生成，卡片數量:', titles.length);
            console.log('檔案列表 HTML:', html.substring(0, 500));
            
            // 確保檔案列表容器是顯示的（使用 !important 確保覆蓋內聯樣式）
            fileListContainer.style.setProperty('display', 'grid', 'important');
            fileListContainer.style.setProperty('visibility', 'visible', 'important');
            fileListContainer.style.setProperty('opacity', '1', 'important');
            
            // 強制重新計算樣式
            fileListContainer.offsetHeight;
            
            // 檢查計算後的樣式
            const computedStyle = window.getComputedStyle(fileListContainer);
            console.log('檔案列表容器 display:', computedStyle.display);
            console.log('檔案列表容器 visibility:', computedStyle.visibility);
            console.log('檔案列表容器 opacity:', computedStyle.opacity);
            console.log('檔案列表容器 width:', computedStyle.width);
            console.log('檔案列表容器 height:', computedStyle.height);
            console.log('檔案列表容器 position:', computedStyle.position);
            console.log('檔案列表容器 z-index:', computedStyle.zIndex);
            
            // 確保檔案卡片正確顯示
            const fileCards = fileListContainer.querySelectorAll('.schedule-file-card');
            console.log('找到的檔案卡片數量:', fileCards.length);
            fileCards.forEach((card, index) => {
                console.log(`卡片 ${index}:`, card.innerHTML.substring(0, 100));
                // 確保卡片是顯示的
                card.style.setProperty('display', 'block', 'important');
                card.style.setProperty('visibility', 'visible', 'important');
                
                // 檢查卡片的計算樣式
                const cardStyle = window.getComputedStyle(card);
                console.log(`卡片 ${index} display:`, cardStyle.display);
                console.log(`卡片 ${index} visibility:`, cardStyle.visibility);
                console.log(`卡片 ${index} width:`, cardStyle.width);
                console.log(`卡片 ${index} height:`, cardStyle.height);
            });
            
            // 如果容器還是不可見，強制顯示
            if (computedStyle.display === 'none' || computedStyle.visibility === 'hidden') {
                console.warn('檔案列表容器被隱藏，強制顯示');
                fileListContainer.style.setProperty('display', 'grid', 'important');
                fileListContainer.style.setProperty('visibility', 'visible', 'important');
            }
            
            // 確保容器在 DOM 中可見（檢查父元素）
            let parent = fileListContainer.parentElement;
            let depth = 0;
            while (parent && depth < 5) {
                const parentStyle = window.getComputedStyle(parent);
                if (parentStyle.display === 'none' || parentStyle.visibility === 'hidden') {
                    console.warn(`父元素 ${parent.tagName} 被隱藏，display: ${parentStyle.display}, visibility: ${parentStyle.visibility}`);
                }
                parent = parent.parentElement;
                depth++;
            }
        }
    }
    
    // 開啟檔案（載入該標題的時程表）
    window.openScheduleFile = async function(title, cohort_ID) {
        try {
            // 設置標題
            const titleInput = document.getElementById('scheduleTitle');
            const titleDisplay = document.getElementById('scheduleTitleDisplay');
            
            if (titleInput) {
                titleInput.value = title;
            }
            if (titleDisplay) {
                titleDisplay.textContent = title;
            }
            
            // 切換到編輯模式（但保持唯讀狀態）
            showEditMode();
            
            // 設置為唯讀模式（存檔後的狀態）
            exitEditMode();
            
            // 重置編輯標記（打開已存在的時程表時，未按過編輯）
            data.hasClickedEdit = false;
            console.log('打開已存在的時程表，重置 hasClickedEdit 為 false');
            
            // 載入該標題的時程表資料
            const apiUrl = resolveScheduleApiUrl();
            const response = await fetch(`${apiUrl}?action=getSchedule&cohort_ID=${cohort_ID}&title=${encodeURIComponent(title)}`);
            const responseData = await response.json();
            
            if (responseData.success && responseData.data) {
                // 載入時程資料並去重（確保每個 team_ID 只出現一次）
                const loadedSchedules = responseData.data || [];
                const uniqueSchedulesMap = new Map();
                loadedSchedules.forEach(schedule => {
                    const teamId = schedule.team_ID;
                    if (teamId) {
                        // 如果已存在該團隊，保留第一個（或可以保留最後一個）
                        if (!uniqueSchedulesMap.has(teamId)) {
                            uniqueSchedulesMap.set(teamId, schedule);
                        } else {
                            console.warn('openScheduleFile: 發現重複的團隊時程，team_ID:', teamId, '已跳過重複項');
                        }
                    }
                });
                data.schedules = Array.from(uniqueSchedulesMap.values());
                
                console.log('=== openScheduleFile: 載入時程表資料 ===');
                console.log('標題:', title);
                console.log('原始載入的時程資料數量:', loadedSchedules.length);
                console.log('去重後的時程資料數量:', data.schedules.length);
                console.log('載入的時程資料:', data.schedules);
                console.log('API 返回的 info:', responseData.info);
                
                // 如果有 info 物件，設置 currentTinformaID 與線上評分狀態
                if (responseData.info && responseData.info.tinforma_ID) {
                    data.currentTinformaID = responseData.info.tinforma_ID;
                    console.log('載入時程表，設置 currentTinformaID:', data.currentTinformaID);
                }
                // 線上評分：編輯模式可隨時修改，不鎖定；僅依後端回傳值顯示目前狀態
                const scoringVal = responseData.info && responseData.info.hasOwnProperty('online_scoring_open') ? responseData.info.online_scoring_open : null;
                data.scoringStatusLocked = false;
                if (scoringVal !== null && scoringVal !== undefined && scoringVal !== '') {
                    data.onlineScoringOpen = (scoringVal === 1 || scoringVal === '1');
                } else {
                    data.onlineScoringOpen = true;
                }
                if (data.schedules.length > 0 && data.schedules[0].tinforma_ID && !data.currentTinformaID) {
                    // 如果沒有 info，嘗試從第一個時程中獲取 tinforma_ID
                    data.currentTinformaID = data.schedules[0].tinforma_ID;
                    console.log('從時程資料中獲取 currentTinformaID:', data.currentTinformaID);
                }
                if (!data.currentTinformaID) {
                    console.warn('警告：無法找到 tinforma_ID，可能載入了錯誤的資料');
                }
                
                // 驗證載入的資料是否正確（檢查所有時程是否有相同的 tinforma_ID）
                if (data.schedules.length > 0) {
                    const tinformaIds = [...new Set(data.schedules.map(s => s.tinforma_ID).filter(id => id))];
                    console.log('時程資料中的 tinforma_ID 列表:', tinformaIds);
                    if (tinformaIds.length > 1) {
                        console.warn('警告：時程資料包含多個不同的 tinforma_ID，可能載入了錯誤的資料');
                    }
                }
                
                // 載入特殊時間資訊（場次準備、上台報告說明等）
                let scheduleStartTime = null;
                let tinformaContent = null;
                
                console.log('=== 檢查特殊時間資訊 ===');
                console.log('responseData.info:', responseData.info);
                console.log('responseData.info.tinforma_content:', responseData.info?.tinforma_content);
                
                // 優先從 responseData.info 獲取
                if (responseData.info && responseData.info.tinforma_content) {
                    tinformaContent = responseData.info.tinforma_content;
                } else if (data.schedules && data.schedules.length > 0) {
                    // 如果 info 為空，嘗試從第一個時程中獲取 tinforma_content
                    const firstSchedule = data.schedules[0];
                    if (firstSchedule && firstSchedule.tinforma_content) {
                        console.log('從時程資料中獲取 tinforma_content:', firstSchedule.tinforma_content);
                        tinformaContent = firstSchedule.tinforma_content;
                    }
                }
                
                if (tinformaContent) {
                    console.log('載入特殊時間資訊:', tinformaContent);
                    
                    // 先解析 JSON 以提取 schedule_start（用於設置開始時間）和 report_duration
                    try {
                        const jsonData = JSON.parse(tinformaContent);
                        console.log('解析的 JSON 資料:', jsonData);
                        if (Array.isArray(jsonData)) {
                            const scheduleStart = jsonData.find(item => item.type === 'schedule_start');
                            if (scheduleStart && scheduleStart.start) {
                                scheduleStartTime = scheduleStart.start;
                            }
                            
                            // 提取報告時間間隔
                            const reportDurationItem = jsonData.find(item => item.type === 'report_duration');
                            if (reportDurationItem && reportDurationItem.duration) {
                                const duration = Math.max(1, Math.min(60, parseInt(reportDurationItem.duration) || 20));
                                data.reportDuration = duration;
                                console.log('載入報告時間間隔:', duration, '分鐘');
                            }
                        }
                    } catch (e) {
                        console.warn('解析特殊時間 JSON 失敗:', e);
                    }
                    
                    // 解析特殊時間（會過濾掉 schedule_start 和 report_duration）
                    data.specialTimesList = parseSpecialTimes(tinformaContent);
                    console.log('解析的特殊時間段:', data.specialTimesList);
                    console.log('特殊時間段數量:', data.specialTimesList.length);
                } else {
                    console.warn('警告：無法獲取 tinforma_content');
                    console.log('responseData.info 存在:', !!responseData.info);
                    console.log('tinforma_content 存在:', !!(responseData.info?.tinforma_content));
                    console.log('時程資料數量:', data.schedules?.length);
                    data.specialTimesList = [];
                }
                
                // 載入開始時間和結束時間
                const startTimeInput = document.getElementById('startTime');
                const endTimeInput = document.getElementById('endTime');
                const endTimeContainer = document.getElementById('endTimeContainer');
                
                // 優先使用 schedule_start 中的時間，否則使用第一個時程的開始時間
                if (scheduleStartTime) {
                    try {
                        // 如果有時程資料，使用第一個時程的日期；否則使用今天的日期
                        let baseDate = new Date();
                        if (data.schedules.length > 0 && data.schedules[0].time_start_d) {
                            baseDate = new Date(data.schedules[0].time_start_d);
                        }
                        
                        // 使用 schedule_start 中的時間（HH:mm 格式）
                        const [hours, minutes] = scheduleStartTime.toString().split(':');
                        baseDate.setHours(parseInt(hours, 10), parseInt(minutes, 10), 0, 0);
                        
                        // 格式化為 datetime-local 所需的格式
                        const year = baseDate.getFullYear();
                        const month = String(baseDate.getMonth() + 1).padStart(2, '0');
                        const day = String(baseDate.getDate()).padStart(2, '0');
                        const formattedDateTime = `${year}-${month}-${day}T${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
                        
                        if (startTimeInput) {
                            startTimeInput.value = formattedDateTime;
                            console.log('從 schedule_start 載入開始時間:', startTimeInput.value);
                        }
                        
                        // 更新 data 對象中的開始時間
                        data.startTime = baseDate;
                    } catch (e) {
                        console.error('設置開始時間時發生錯誤:', e);
                    }
                } else if (data.schedules.length > 0) {
                    // 如果沒有 schedule_start，使用第一個時程的開始時間
                    const firstSchedule = data.schedules[0];
                    if (firstSchedule && firstSchedule.time_start_d) {
                        const startDate = new Date(firstSchedule.time_start_d);
                        // 轉換為 datetime-local 格式 (YYYY-MM-DDTHH:mm)
                        const year = startDate.getFullYear();
                        const month = String(startDate.getMonth() + 1).padStart(2, '0');
                        const day = String(startDate.getDate()).padStart(2, '0');
                        const hours = String(startDate.getHours()).padStart(2, '0');
                        const minutes = String(startDate.getMinutes()).padStart(2, '0');
                        if (startTimeInput) {
                            startTimeInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
                            console.log('從第一個時程載入開始時間:', startTimeInput.value);
                        }
                        // 更新 data 對象中的開始時間
                        data.startTime = startDate;
                    }
                } else {
                    // 沒有時程資料，清空時間欄位
                    if (startTimeInput) {
                        startTimeInput.value = '';
                    }
                }
                
                // 載入結束時間
                if (data.schedules.length > 0) {
                    // 找到最後一個時程的結束時間（最後一組報告完成時間）
                    const lastSchedule = data.schedules[data.schedules.length - 1];
                    if (lastSchedule && lastSchedule.time_end_d) {
                        const endDate = new Date(lastSchedule.time_end_d);
                        // 轉換為 datetime-local 格式 (YYYY-MM-DDTHH:mm)
                        const year = endDate.getFullYear();
                        const month = String(endDate.getMonth() + 1).padStart(2, '0');
                        const day = String(endDate.getDate()).padStart(2, '0');
                        const hours = String(endDate.getHours()).padStart(2, '0');
                        const minutes = String(endDate.getMinutes()).padStart(2, '0');
                        if (endTimeInput) {
                            endTimeInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
                            console.log('載入結束時間:', endTimeInput.value);
                        }
                        // 顯示結束時間欄位
                        if (endTimeContainer) {
                            endTimeContainer.style.display = 'block';
                        }
                    }
                } else {
                    // 沒有時程資料，清空時間欄位
                    if (endTimeInput) {
                        endTimeInput.value = '';
                    }
                    if (endTimeContainer) {
                        endTimeContainer.style.display = 'none';
                    }
                }
                
                // 檢查時程資料是否為空
                if (!data.schedules || data.schedules.length === 0) {
                    // 如果時程資料為空，這是第一次編輯，載入當屆的所有團隊
                    console.log('時程資料為空，這是第一次編輯，載入當屆的所有團隊，屆別:', cohort_ID);
                    // 使用 loadTeamsForNewSchedule 而不是 loadTeams，避免調用 loadScheduleInfoForRender
                    await loadTeamsForNewSchedule(cohort_ID);
                    console.log('團隊資料載入完成，團隊數量:', data.teams ? data.teams.length : 0);
                } else {
                    // 根據時程資料載入對應的團隊（只載入有時程記錄的團隊）
                    // 注意：不要調用 loadScheduleInfoForRender，因為它會載入最新的時程表
                    // 時程資料已經從 getSchedule API 載入，這裡只需要載入團隊並渲染
                    console.log('準備載入團隊資料，時程資料數量:', data.schedules.length);
                    console.log('時程資料中的團隊ID:', data.schedules.map(s => s.team_ID));
                    
                    // 確保數據不會被清空
                    const savedSchedules = [...data.schedules];
                    const savedTeams = [...(data.teams || [])];
                    
                    await loadTeamsFromSchedules(cohort_ID, true); // 傳入 true 表示跳過 loadScheduleInfoForRender
                    
                    console.log('團隊資料載入完成，團隊數量:', data.teams ? data.teams.length : 0);
                    console.log('時程資料是否保留:', data.schedules.length === savedSchedules.length);
                    
                    // 如果時程資料被清空，恢復它
                    if (data.schedules.length === 0 && savedSchedules.length > 0) {
                        console.warn('警告：時程資料被清空，正在恢復...');
                        data.schedules = savedSchedules;
                        // 重新渲染表格
                        renderScheduleTable();
                    }
                }
                
                // 確保標題顯示正確（防止被其他函數覆蓋）
                const titleInput = document.getElementById('scheduleTitle');
                const titleDisplay = document.getElementById('scheduleTitleDisplay');
                if (titleInput && title) {
                    titleInput.value = title;
                }
                if (titleDisplay && title) {
                    titleDisplay.textContent = title;
                }
                
                // 最後再次確保時程資料去重（防止被其他函數覆蓋）
                if (data.schedules && data.schedules.length > 0) {
                    const originalCount = data.schedules.length;
                    const uniqueSchedulesMap = new Map();
                    data.schedules.forEach(schedule => {
                        const teamId = schedule.team_ID;
                        if (teamId) {
                            const existing = uniqueSchedulesMap.get(teamId);
                            if (!existing) {
                                uniqueSchedulesMap.set(teamId, schedule);
                            } else {
                                // 如果已存在，比較 sort_no，保留較小的
                                const existingSortNo = existing.sort_no ?? 999999;
                                const currentSortNo = schedule.sort_no ?? 999999;
                                if (currentSortNo < existingSortNo) {
                                    uniqueSchedulesMap.set(teamId, schedule);
                                }
                            }
                        }
                    });
                    const uniqueSchedules = Array.from(uniqueSchedulesMap.values());
                    if (originalCount !== uniqueSchedules.length) {
                        console.warn('openScheduleFile: 發現重複的時程資料，已去重。原始數量:', originalCount, '去重後數量:', uniqueSchedules.length);
                        data.schedules = uniqueSchedules;
                        // 重新渲染表格
                        renderScheduleTable();
                    }
                }
            } else {
                // API 返回失敗，但可能是因為時程表還沒有資料（第一次編輯）
                // 如果是這種情況，載入當屆的所有團隊
                console.log('API 返回失敗或沒有資料，可能是第一次編輯，載入當屆的所有團隊');
                const cohortSelect = document.getElementById('cohortSelect');
                const finalCohort_ID = cohort_ID || (cohortSelect ? cohortSelect.value : null);
                
                if (finalCohort_ID) {
                    // 使用 loadTeamsForNewSchedule 而不是 loadTeams，避免調用 loadScheduleInfoForRender
                    await loadTeamsForNewSchedule(finalCohort_ID);
                    console.log('團隊資料載入完成，團隊數量:', data.teams ? data.teams.length : 0);
                } else {
                    Swal.fire('錯誤', responseData.msg || '載入時程表失敗', 'error');
                }
            }
        } catch (error) {
            console.error('開啟檔案錯誤:', error);
            // 發生錯誤時，如果是第一次編輯，嘗試載入團隊
            const cohortSelect = document.getElementById('cohortSelect');
            const finalCohort_ID = cohort_ID || (cohortSelect ? cohortSelect.value : null);
            
            if (finalCohort_ID && (!data.teams || data.teams.length === 0)) {
                console.log('發生錯誤，嘗試載入當屆的所有團隊');
                try {
                    // 使用 loadTeamsForNewSchedule 而不是 loadTeams，避免調用 loadScheduleInfoForRender
                    await loadTeamsForNewSchedule(finalCohort_ID);
                } catch (loadError) {
                    console.error('載入團隊失敗:', loadError);
                    Swal.fire('錯誤', '無法載入時程表和團隊資料', 'error');
                }
            } else {
                Swal.fire('錯誤', '無法載入時程表', 'error');
            }
        }
    }
    
    // HTML 轉義函數
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // 進入編輯模式
    function enterEditMode() {
        console.log('進入編輯模式，當前 isEditMode:', data.isEditMode);
        data.isEditMode = true;
        console.log('設置 isEditMode 為 true，確認:', data.isEditMode);
        
        // 顯示特殊時間選項區域
        const specialTimeOptions = document.getElementById('special-time-options');
        if (specialTimeOptions) {
            specialTimeOptions.style.display = 'block';
        }
        
        // 啟用標題和時間輸入
        const titleInput = document.getElementById('scheduleTitle');
        const startTimeInput = document.getElementById('startTime');
        
        if (titleInput) {
            titleInput.disabled = false;
            titleInput.readOnly = false;
        }
        if (startTimeInput) {
            startTimeInput.disabled = false;
            startTimeInput.readOnly = false;
        }
        
        // 更新編輯按鈕文字（進入編輯模式後按鈕顯示為「儲存」）
        const editAllBtn = document.getElementById('schedule-edit-all-btn');
        if (editAllBtn) {
            editAllBtn.innerHTML = '<span>儲存</span>';
        }
        
        // 啟用保存和匯出按鈕（編輯模式時兩者都可用）
        const saveBtn = document.getElementById('schedule-save-btn');
        const exportBtn = document.getElementById('exportPDFBtn');
        const cohortSelect = document.getElementById('cohortSelect');
        const cohort_ID = cohortSelect ? cohortSelect.value : null;
        
        if (saveBtn) {
            saveBtn.disabled = !cohort_ID; // 有選擇屆別時才啟用
        }
        if (exportBtn) {
            exportBtn.disabled = !cohort_ID; // 有選擇屆別時才啟用
        }
        
        // 重新渲染表格以顯示刪除按鈕和啟用拖放
        console.log('準備重新渲染表格，isEditMode:', data.isEditMode);
        // 確保時程資料去重後再渲染
        if (data.schedules && data.schedules.length > 0) {
            const uniqueSchedulesMap = new Map();
            data.schedules.forEach(schedule => {
                const teamId = schedule.team_ID;
                if (teamId && !uniqueSchedulesMap.has(teamId)) {
                    uniqueSchedulesMap.set(teamId, schedule);
                }
            });
            const uniqueSchedules = Array.from(uniqueSchedulesMap.values());
            if (uniqueSchedules.length !== data.schedules.length) {
                console.warn('enterEditMode: 發現重複的時程資料，已去重。原始數量:', data.schedules.length, '去重後數量:', uniqueSchedules.length);
                data.schedules = uniqueSchedules;
            }
        }
        renderScheduleTable();
        
        // 確保拖放功能已啟用
        setTimeout(() => {
            if (data.isEditMode) {
                console.log('初始化拖放功能...');
                initSortable();
            }
        }, 200);
        
        updateOnlineScoringUI();
    }
    
    // 退出編輯模式（唯讀模式）
    function exitEditMode() {
        console.log('退出編輯模式（唯讀模式）');
        data.isEditMode = false;
        
        // 隱藏特殊時間選項區域
        const specialTimeOptions = document.getElementById('special-time-options');
        if (specialTimeOptions) {
            specialTimeOptions.style.display = 'none';
        }
        
        // 禁用標題和時間輸入
        const titleInput = document.getElementById('scheduleTitle');
        const startTimeInput = document.getElementById('startTime');
        
        if (titleInput) {
            titleInput.disabled = true;
            titleInput.readOnly = true;
        }
        if (startTimeInput) {
            startTimeInput.disabled = true;
            startTimeInput.readOnly = true;
        }
        
        updateOnlineScoringUI();
        
        // 更新編輯按鈕文字
        const editAllBtn = document.getElementById('schedule-edit-all-btn');
        if (editAllBtn) {
            editAllBtn.innerHTML = '<span>編輯</span>';
        }
        
        // 禁用保存按鈕，但保持匯出按鈕可用（非編輯模式時也可以匯出）
        const saveBtn = document.getElementById('schedule-save-btn');
        const exportBtn = document.getElementById('exportPDFBtn');
        const exportWordBtn = document.getElementById('exportWordBtn');
        const cohortSelect = document.getElementById('cohortSelect');
        const cohort_ID = cohortSelect ? cohortSelect.value : null;
        
        if (saveBtn) {
            saveBtn.disabled = true;
        }
        // 非編輯模式時，如果有選擇屆別，匯出按鈕應該可用
        if (exportBtn) {
            exportBtn.disabled = !cohort_ID;
        }
        if (exportWordBtn) {
            exportWordBtn.disabled = !cohort_ID;
        }
        
        // 返回上一頁按鈕：如果從integrate打開，一直顯示
        const urlParams = getUrlParams();
        const fromIntegrate = urlParams.get('from_integrate') === '1';
        const backHomeBtn = document.getElementById('schedule-back-home-btn');
        if (backHomeBtn) {
            backHomeBtn.style.display = fromIntegrate ? 'inline-block' : 'none';
        }
        
        // 銷毀拖放功能
        if (data.sortableInstance) {
            try {
                data.sortableInstance.destroy();
                data.sortableInstance = null;
                console.log('已銷毀 Sortable 實例');
            } catch (e) {
                console.warn('銷毀 Sortable 實例時出錯:', e);
            }
        }
        
        // 移除行的 draggable 屬性
        const tbody = document.getElementById('scheduleTableBody');
        if (tbody) {
            const teamRows = tbody.querySelectorAll('.team-row');
            teamRows.forEach(row => {
                row.draggable = false;
                // 移除所有拖放相關的事件監聽器
                row.ondragstart = null;
                row.ondragend = null;
                row.ondrag = null;
            });
            
            // 移除 tbody 上的所有拖放相關事件
            tbody.ondragover = null;
            tbody.ondrop = null;
            tbody.ondragenter = null;
            tbody.ondragleave = null;
        }
        
        // 重新渲染表格以隱藏刪除按鈕
        renderScheduleTable();
        
        // 確保不會重新初始化拖放功能（即使有其他代碼嘗試初始化）
        setTimeout(() => {
            if (data.sortableInstance && !data.isEditMode) {
                console.log('檢測到非編輯模式下仍有 Sortable 實例，強制銷毀');
                try {
                    data.sortableInstance.destroy();
                    data.sortableInstance = null;
                } catch (e) {
                    console.warn('強制銷毀 Sortable 實例時出錯:', e);
                }
            }
        }, 100);
    }
    
    // 刪除團隊
    function deleteTeam(team_ID) {
        if (!team_ID) return;
        
        Swal.fire({
            title: '確認刪除',
            text: '確定要刪除此團隊嗎？',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '確定刪除',
            cancelButtonText: '取消'
        }).then((result) => {
            if (result.isConfirmed) {
                console.log('刪除團隊，team_ID:', team_ID);
                console.log('刪除前的 teams 數量:', data.teams.length);
                console.log('刪除前的 schedules 數量:', data.schedules.length);
                
                // 先將 DOM 中的特殊時間列同步到 data.specialTimesList（包含尚未存檔、剛新增的特殊時間）
                syncOrderFromDOM();
                
                // 從 teams 陣列中移除
                data.teams = data.teams.filter(team => team.team_ID != team_ID);
                
                // 從 schedules 陣列中移除
                data.schedules = data.schedules.filter(schedule => schedule.team_ID != team_ID);
                
                console.log('刪除後的 teams 數量:', data.teams.length);
                console.log('刪除後的 schedules 數量:', data.schedules.length);
                
                // 先重新渲染表格（讓 DOM 與 data 一致，並保留特殊時間 data.specialTimesList）
                // 若先呼叫 calculateTimes()，DOM 仍含已刪團隊列，會把該團隊又加回 data.schedules
                renderScheduleTable();
                
                // 再依新表格順序重新計算時間
                calculateTimes();
                
                // 第二個「已刪除」提示改為右下角的小 Toast
                Swal.fire({
                    toast: true,
                    position: 'bottom-end',
                    icon: 'success',
                    title: '已刪除',
                    text: '團隊已從時程表中移除',
                    timer: 1800,
                    timerProgressBar: true,
                    showConfirmButton: false
                });
            }
        });
    }

    /* ==========================================
       加入團隊對話框（時程表專用）
       - 僅在編輯模式下可用
       - 顯示該屆所有尚未排入此時程表的團隊
    ========================================== */
    async function openAddTeamDialogForSchedule() {
        const cohortSelect = document.getElementById('cohortSelect');
        const cohort_ID = cohortSelect ? cohortSelect.value : null;

        if (!cohort_ID) {
            Swal.fire({
                icon: 'warning',
                title: '請先選擇屆別',
                text: '請先選擇屆別後再加入團隊'
            });
            return;
        }

        if (!data.isEditMode) {
            Swal.fire({
                icon: 'warning',
                title: '請先進入編輯模式',
                text: '請先點選「編輯」後再加入團隊'
            });
            return;
        }

        try {
            const apiUrl = resolveScheduleApiUrl();
            const url = `${apiUrl}?action=listTeams&cohort_ID=${encodeURIComponent(cohort_ID)}`;
            const resp = await fetch(url);
            const json = await resp.json();

            if (!json.success || !json.data) {
                throw new Error(json.msg || '載入團隊列表失敗');
            }

            const allTeams = json.data || [];
            const scheduledIds = new Set(
                (data.schedules || [])
                    .map(s => parseInt(s.team_ID))
                    .filter(id => !isNaN(id) && id > 0)
            );

            const availableTeams = allTeams.filter(team => {
                const teamId = parseInt(team.team_ID);
                return !isNaN(teamId) && !scheduledIds.has(teamId);
            });

            if (availableTeams.length === 0) {
                Swal.fire({
                    icon: 'info',
                    title: '沒有可加入的團隊',
                    text: '該屆所有團隊都已在此時程表中'
                });
                return;
            }

            let html = '<div style="max-height: 420px; overflow-y: auto; text-align: left;">';
            availableTeams.forEach(team => {
                const teamId = parseInt(team.team_ID);
                const teamName = team.team_project_name || `團隊 ${teamId}`;
                const groupName = team.group_name || '';
                html += `
                    <div style="padding: 8px 4px; border-bottom: 1px solid #e0e0e0;">
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="checkbox" class="schedule-add-team-checkbox" value="${teamId}"
                                   style="margin-right: 10px; width: 18px; height: 18px;">
                            <div>
                                <div style="font-weight: 600;">${teamName}</div>
                                <div style="font-size: 12px; color: #666;">${groupName}</div>
                            </div>
                        </label>
                    </div>
                `;
            });
            html += '</div>';

            const result = await Swal.fire({
                title: '選擇要加入時程表的團隊',
                html,
                width: '620px',
                showCancelButton: true,
                confirmButtonText: '加入',
                cancelButtonText: '取消',
                preConfirm: () => {
                    const checked = document.querySelectorAll('.schedule-add-team-checkbox:checked');
                    const ids = Array.from(checked)
                        .map(cb => parseInt(cb.value))
                        .filter(id => !isNaN(id) && id > 0);
                    if (ids.length === 0) {
                        Swal.showValidationMessage('請至少選擇一個團隊');
                        return false;
                    }
                    return ids;
                }
            });

            if (!result.isConfirmed || !result.value) return;

            await addTeamsToSchedule(result.value, allTeams);
        } catch (err) {
            console.error('顯示加入團隊對話框失敗:', err);
            Swal.fire({
                icon: 'error',
                title: '載入失敗',
                text: err.message || '請稍後再試'
            });
        }
    }

    /* ==========================================
       將選中的團隊加入當前時程表
    ========================================== */
    async function addTeamsToSchedule(selectedTeamIds, allTeams) {
        try {
            const idSet = new Set(
                selectedTeamIds.map(id => parseInt(id)).filter(id => !isNaN(id) && id > 0)
            );

            const teamsToAdd = allTeams.filter(team => {
                const teamId = parseInt(team.team_ID);
                return !isNaN(teamId) && idSet.has(teamId);
            });

            if (teamsToAdd.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: '沒有找到選中的團隊',
                    text: '請重新選擇要加入的團隊'
                });
                return;
            }

            console.log('加入團隊到時程表:', teamsToAdd.map(t => t.team_ID));

            // 1. 更新 data.teams
            teamsToAdd.forEach(team => {
                const teamId = parseInt(team.team_ID);
                if (isNaN(teamId) || teamId <= 0) return;
                if (!data.teams.some(t => parseInt(t.team_ID) === teamId)) {
                    data.teams.push(team);
                }
                // 2. 若尚未有時程記錄，建立空白時程（時間與 sort_no 之後會由 syncOrderFromDOM/calculateTimes 計算）
                if (!data.schedules.some(s => parseInt(s.team_ID) === teamId)) {
                    data.schedules.push({
                        team_ID: teamId,
                        tinforma_ID: data.currentTinformaID || null,
                        time_start_d: null,
                        time_end_d: null,
                        sort_no: null
                    });
                }
            });

            // 3. 重新渲染表格並重新計算組次與時間
            renderScheduleTable();
            // 先依目前 DOM 順序同步 sort_no
            if (typeof syncOrderFromDOM === 'function') {
                syncOrderFromDOM();
            }
            // 再依 sort_no 計算時間
            calculateTimes();
            renderScheduleTable();

            Swal.fire({
                icon: 'success',
                title: `已加入 ${teamsToAdd.length} 個團隊`,
                timer: 1500,
                showConfirmButton: false
            });
        } catch (err) {
            console.error('加入團隊失敗:', err);
            Swal.fire({
                icon: 'error',
                title: '加入失敗',
                text: err.message || '請稍後再試'
            });
        }
    }
    
    // 發送時程表通知給當屆所有人
    window.sendScheduleNotification = async function(title, cohort_ID) {
        try {
            // 確認對話框
            const result = await Swal.fire({
                title: '確認發送通知',
                html: `確定要發送時程表「<strong>${escapeHtml(title)}</strong>」的通知給當屆的所有人嗎？`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '確定',
                cancelButtonText: '取消',
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#6c757d',
                reverseButtons: true // 將確認按鈕放在右邊
            });
            
            if (!result.isConfirmed) {
                return;
            }
            
            // 顯示載入中
            Swal.fire({
                title: '發送中...',
                text: '正在發送通知，請稍候',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // 發送API請求
            const response = await fetch('api.php?do=send_schedule_notification', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    title: title,
                    cohort_ID: cohort_ID
                })
            });
            
            const data = await response.json();
            
            if (data.ok === true || data.status === 'ok' || data.success) {
                Swal.fire({
                    icon: 'success',
                    title: '發送成功',
                    text: data.message || '通知已成功發送給當屆的所有人',
                    timer: 2000,
                    showConfirmButton: false
                });
            } else {
                throw new Error(data.message || data.msg || '發送失敗');
            }
        } catch (error) {
            console.error('發送通知失敗:', error);
            Swal.fire({
                icon: 'error',
                title: '發送失敗',
                text: error.message || '請稍後再試'
            });
        }
    };
    
    // 匯出時程表
    window.exportScheduleFile = function(title, cohort_ID) {
        try {
            // 獲取當前時程表的 tinforma_ID（如果有的話）
            const currentTinformaID = window.scheduleManageData?.currentTinformaID || null;
            
            // 動態判斷匯出頁面路徑
            const getExportPath = function() {
                const pathname = window.location.pathname || '';
                const hash = window.location.hash || '';
                
                if (hash.includes('pages/')) {
                    return 'pages/schedule_export.php';
                }
                if (pathname.includes('/main.php')) {
                    return 'pages/schedule_export.php';
                }
                if (pathname.includes('/pages/')) {
                    const pagesIndex = pathname.indexOf('/pages/');
                    if (pagesIndex >= 0) {
                        return 'schedule_export.php';
                    }
                }
                return 'pages/schedule_export.php';
            };
            
            // 構建匯出 URL（加 download=1 直接下載，不預覽）
            let exportUrl = `${getExportPath()}?cohort_ID=${cohort_ID}`;
            if (currentTinformaID) {
                exportUrl += `&tinforma_ID=${currentTinformaID}`;
            } else if (title) {
                exportUrl += `&title=${encodeURIComponent(title)}`;
            }
            exportUrl += '&download=1';
            
            console.log('匯出 PDF URL:', exportUrl);
            // 使用隱藏 iframe 觸發下載，不開新分頁預覽
            const iframe = document.createElement('iframe');
            iframe.setAttribute('style', 'position:absolute;width:0;height:0;border:0;visibility:hidden');
            iframe.setAttribute('title', 'PDF export');
            document.body.appendChild(iframe);
            iframe.src = exportUrl;
            setTimeout(function() {
                try { iframe.remove(); } catch (e) {}
            }, 15000);
        } catch (error) {
            console.error('匯出 PDF 失敗:', error);
            Swal.fire({
                icon: 'error',
                title: '匯出失敗',
                text: error.message || '請稍後再試'
            });
        }
    };
    
    // 匯出 Word 檔案
    window.exportScheduleFileWord = function(title, cohort_ID) {
        try {
            // 獲取當前時程表的 tinforma_ID（如果有的話）
            const currentTinformaID = window.scheduleManageData?.currentTinformaID || null;
            
            // 動態判斷匯出頁面路徑
            const getExportPath = function() {
                const pathname = window.location.pathname || '';
                const hash = window.location.hash || '';
                
                if (hash.includes('pages/')) {
                    return 'pages/schedule_export_word.php';
                }
                if (pathname.includes('/main.php')) {
                    return 'pages/schedule_export_word.php';
                }
                if (pathname.includes('/pages/')) {
                    const pagesIndex = pathname.indexOf('/pages/');
                    if (pagesIndex >= 0) {
                        return 'schedule_export_word.php';
                    }
                }
                return 'pages/schedule_export_word.php';
            };
            
            // 構建匯出 URL
            let exportUrl = `${getExportPath()}?cohort_ID=${cohort_ID}`;
            if (currentTinformaID) {
                exportUrl += `&tinforma_ID=${currentTinformaID}`;
            } else if (title) {
                exportUrl += `&title=${encodeURIComponent(title)}`;
            }
            
            console.log('匯出 Word URL:', exportUrl);
            window.open(exportUrl, '_blank');
        } catch (error) {
            console.error('匯出 Word 失敗:', error);
            Swal.fire({
                icon: 'error',
                title: '匯出失敗',
                text: error.message || '請稍後再試'
            });
        }
    };
    
    // 暴露函數到全域
    window.checkAndDisplayMode = checkAndDisplayMode;
    window.displayFileList = displayFileList;
    window.openScheduleFile = openScheduleFile;
    window.enterEditMode = enterEditMode;
    window.exitEditMode = exitEditMode;
    window.deleteTeam = deleteTeam;
})();

