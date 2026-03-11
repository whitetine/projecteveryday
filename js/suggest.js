/* ================================
   suggest.js
   科辦多筆建議系統（自動編號 + 多筆新增）
================================ */

/* ===== API 路徑解析（支援動態載入） ===== */
function resolveSuggestApiUrl() {
    const path = window.location.pathname || '';
    if (path.includes('/pages/')) {
        return 'suggest_data.php';
    }
    return 'pages/suggest_data.php';
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

/* ===== SweetAlert Toast ===== */
// 避免重复声明，如果已存在则使用现有的
if (typeof window.SuggestToast === 'undefined') {
    window.SuggestToast = Swal.mixin({
        toast: true,
        position: "bottom-end",
        showConfirmButton: false,
        timer: 2000,
        timerProgressBar: true
    });
}
// 使用 window.Toast 避免重复声明问题，如果已存在则使用现有的
if (typeof window.Toast === 'undefined') {
    window.Toast = window.SuggestToast;
}
// 使用函数来获取 Toast，避免 const 重复声明
function getToast() {
    return window.Toast || window.SuggestToast;
}
// 为了兼容性，创建一个局部变量（使用 var 而不是 const，避免重复声明错误）
var Toast = getToast();

/* ==========================================
   1. 初始化 – 載入屆別
========================================== */
function initSuggest() {
    const cohortSelect = document.getElementById("sg-cohort");
    const groupSelect = document.getElementById("sg-group");
    if (!cohortSelect || !groupSelect) {
        return false;
    }
    
    // 如果已經初始化過，先重置標記
    if (cohortSelect.dataset.initialized === 'true') {
        cohortSelect.dataset.initialized = 'false';
    }
    
    // 標記為已初始化
    cohortSelect.dataset.initialized = 'true';
    
    // 重新載入屆別資料（每次初始化都會重新載入）
    loadCohorts();
    
    // 從URL參數讀取數據（如果從integrate頁面打開）
    const urlParams = getUrlParams();
    const urlCohort_ID = urlParams.get('cohort_ID');
    const urlTitle = urlParams.get('title');
    const urlSf_ID = urlParams.get('sf_ID');
    const fromIntegrate = urlParams.get('from_integrate') === '1';

    // 建議表類型：topic=題目初審建議表（不綁 tinforma、不含審查欄位），review=審查建議表（必綁 tinforma、含審查結果）
    window.suggestFormSfType = window.suggestFormSfType || 'review';

    // 移除舊的事件監聽器，然後添加新的
    let freshCohortSelect = cohortSelect;
    let freshGroupSelect = groupSelect;
    
    // 安全地替換元素（檢查 parentNode 是否存在）
    if (cohortSelect.parentNode) {
        try {
            const newCohortSelect = cohortSelect.cloneNode(true);
            cohortSelect.parentNode.replaceChild(newCohortSelect, cohortSelect);
            freshCohortSelect = document.getElementById("sg-cohort");
            if (!freshCohortSelect) {
                freshCohortSelect = newCohortSelect;
            }
        } catch (e) {
            console.warn('替換 cohortSelect 失敗，使用原元素:', e);
            freshCohortSelect = cohortSelect;
        }
    }
    
    if (groupSelect.parentNode) {
        try {
            const newGroupSelect = groupSelect.cloneNode(true);
            groupSelect.parentNode.replaceChild(newGroupSelect, groupSelect);
            freshGroupSelect = document.getElementById("sg-group");
            if (!freshGroupSelect) {
                freshGroupSelect = newGroupSelect;
            }
        } catch (e) {
            console.warn('替換 groupSelect 失敗，使用原元素:', e);
            freshGroupSelect = groupSelect;
        }
    }
    
    // 如果從integrate頁面打開且有URL參數，自動設置屆別和標題
    if (fromIntegrate && urlCohort_ID) {
        // 有標題時先立即帶入，避免依賴非同步流程才顯示（召集人僅一類組時曾抓不到標題）
        if (urlTitle) {
            const titleInputEarly = document.getElementById("sg-title");
            if (titleInputEarly) {
                titleInputEarly.value = urlTitle;
                titleInputEarly.setAttribute('data-original-title', urlTitle);
            }
        }
        // 顯示"回到列表"按鈕（從 integrate.php 打開時需要顯示）
        const backHomeBtn = document.getElementById("sg-back-home-btn");
        if (backHomeBtn) {
            backHomeBtn.style.display = 'inline-block';
        }
        
        // 設置屆別為只讀
        if (freshCohortSelect) {
            freshCohortSelect.disabled = true;
            freshCohortSelect.style.backgroundColor = '#f5f5f5';
            freshCohortSelect.style.cursor = 'not-allowed';
        }
        
        // 等待屆別選項載入完成後再設置
        setTimeout(async () => {
            freshCohortSelect.value = urlCohort_ID;
            
            // 載入類組
            if (freshCohortSelect.value) {
                await loadGroups(freshCohortSelect.value);
                
                // 自動選擇第一個類組（或"全部"如果存在）
                setTimeout(async () => {
                    // 優先選擇"全部"，否則選擇第一個類組（召集人可能只有一個類組，也要選到）
                    let selectedGroupId = null;
                    for (let i = 0; i < freshGroupSelect.options.length; i++) {
                        if (freshGroupSelect.options[i].value === 'all') {
                            selectedGroupId = 'all';
                            break;
                        }
                    }
                    if (!selectedGroupId && freshGroupSelect.options.length > 1) {
                        selectedGroupId = freshGroupSelect.options[1].value;
                    }
                    if (!selectedGroupId && freshGroupSelect.options.length > 0) {
                        // 召集人僅有一個類組時 options.length === 1，也要選取並帶入標題
                        selectedGroupId = freshGroupSelect.options[0].value;
                    }
                    
                    if (selectedGroupId) {
                        freshGroupSelect.value = selectedGroupId;
                        
                        // 如果有標題參數，直接打開該文件
                        if (urlTitle) {
                            // 保存標題到全局變量
                            window.currentViewingTitle = urlTitle;
                            freshCohortSelect.setAttribute('data-viewing-title', urlTitle);
                            freshGroupSelect.setAttribute('data-viewing-title', urlTitle);
                            
                            // 設置標題輸入框（從 integrate 帶入時僅填入內容，不啟用編輯；進入編輯模式時才啟用）
                            const titleInput = document.getElementById("sg-title");
                            if (titleInput) {
                                titleInput.value = urlTitle;
                                titleInput.setAttribute('data-original-title', urlTitle);
                                // 不在此處設定 disabled = false，由 openFile / checkAndDisplayMode 依是否為編輯模式決定
                            }
                            
                            // 若有 sf_ID 先取得 sf_type，再打開文件
                            setTimeout(async () => {
                                if (urlSf_ID) {
                                    try {
                                        const apiUrl = resolveSuggestApiUrl();
                                        const res = await fetch(`${apiUrl}?action=getSuggestFormInfo&sf_ID=${urlSf_ID}`);
                                        const json = await res.json();
                                        if (json.success && json.data) {
                                            if (json.data.sf_type) {
                                                window.suggestFormSfType = json.data.sf_type;
                                                if (typeof updateSuggestFilterLabel === 'function') updateSuggestFilterLabel();
                                            }
                                            // 召集人：依當屆召集人名單與是否已送交決定唯讀；sf_sent_to_office 為字串 '0'|'1'|帳號
                                            const wrapper = document.querySelector(".suggest-wrapper[data-suggest-page=\"true\"]");
                                            const isConvener = wrapper && wrapper.getAttribute("data-role-id") === "7";
                                            const allSent = String(json.data.sf_sent_to_office || '').trim() === '1';
                                            const convenerHasSent = !!json.data.convener_has_sent;
                                            const isInCohort = json.data.is_convener_in_cohort !== false;
                                            if (isConvener) {
                                                window.SuggestConvenerNotInCohort = !isInCohort;
                                                if (!isInCohort || allSent || convenerHasSent) {
                                                    window.SuggestReadOnlyForConvener = true;
                                                } else {
                                                    window.SuggestReadOnlyForConvener = false;
                                                }
                                            } else {
                                                window.SuggestConvenerNotInCohort = false;
                                                window.SuggestReadOnlyForConvener = false;
                                            }
                                        }
                                    } catch (e) {
                                        console.warn('getSuggestFormInfo 失敗:', e);
                                    }
                                }
                                // 先開啟檔案載入團隊與建議
                                await openFile(urlTitle);
                                // 再透過 checkAndDisplayMode 統一處理按鈕與模式狀態，
                                // 確保「第一次進入建議表」且為「全部類組」時就會正確顯示「加入團隊」按鈕
                                try {
                                    await checkAndDisplayMode(urlCohort_ID, selectedGroupId);
                                } catch (e) {
                                    console.warn('checkAndDisplayMode after openFile 失敗:', e);
                                }
                            }, 100);
                        } else {
                            // 沒有標題參數，觸發 change 事件正常載入（標題啟用狀態由 checkAndDisplayMode 決定）
                            freshGroupSelect.dispatchEvent(new Event('change'));
                        }
                    }
                }, 300);
            }
        }, 500);
    }
    
    // 當屆別改變 → 載入類組，然後載入團隊
    freshCohortSelect.addEventListener("change", () => {
        const cohortId = freshCohortSelect.value;
        const saveBtn = document.getElementById("sg-save-btn");
        const exportBtn = document.getElementById("sg-export-btn");
        const exportWordBtn = document.getElementById("sg-export-word-btn");
        const titleInput = document.getElementById("sg-title");
        
        if (cohortId) {
            loadGroups(cohortId);
        } else {
            freshGroupSelect.innerHTML = '<option value="">請先選擇屆別</option>';
            freshGroupSelect.disabled = true;
            if (titleInput) {
                titleInput.disabled = true;
                titleInput.value = "";
            }
            const titleSelect = document.getElementById("sg-title-select");
            const statusSelect = document.getElementById("sg-status");
            const filterRow2 = document.getElementById("sg-filter-row2");
            if (titleSelect) {
                titleSelect.innerHTML = '<option value="">請先選擇屆別和類組</option>';
                titleSelect.disabled = true;
            }
            if (statusSelect) {
                statusSelect.innerHTML = '<option value="">請先選擇屆別和類組</option>';
                statusSelect.disabled = true;
            }
            const filterHint = document.getElementById("sg-filter-hint");
            if (filterRow2) {
                filterRow2.style.display = 'none';
            }
            if (filterHint) {
                filterHint.style.display = 'none';
            }
            const teamList = document.getElementById("sg-team-list");
            const fileList = document.getElementById("sg-file-list");
            const leftCol = document.getElementById("sg-team-left");
            const rightCol = document.getElementById("sg-team-right");
            if (teamList) teamList.innerHTML = "";
            if (fileList) fileList.style.display = "none";
            if (leftCol) leftCol.innerHTML = "";
            if (rightCol) rightCol.innerHTML = "";
            if (saveBtn) saveBtn.disabled = true;
            if (exportBtn) exportBtn.disabled = true;
            if (exportWordBtn) exportWordBtn.disabled = true;
        }
    });
    
    // 當類組改變 → 載入團隊
    freshGroupSelect.addEventListener("change", async () => {
        const cohortId = freshCohortSelect.value;
        const groupId = freshGroupSelect.value;
        const saveBtn = document.getElementById("sg-save-btn");
        const exportBtn = document.getElementById("sg-export-btn");
        const exportWordBtn = document.getElementById("sg-export-word-btn");
        const titleInput = document.getElementById("sg-title");
        
        // 如果選擇了"全部"（value="all"）或有效的類組ID，則載入團隊
        if (cohortId && (groupId === "all" || groupId)) {
            // 標題欄位啟用與否由 openFile / checkAndDisplayMode 依編輯模式決定，不在此處強制啟用
            // 如果選擇了"全部"，使用 "all" 作為 groupId；否則使用實際的 groupId
            const actualGroupId = groupId === "all" ? "all" : groupId;
            
            // 記錄切換前是否處於編輯模式（存檔按鈕顯示且啟用）
            const wasInEditMode = isSuggestInEditMode();
            
            // 檢查是否有正在查看的標題（從文件列表點擊進入的狀態）
            const viewingTitle = window.currentViewingTitle || 
                                 freshCohortSelect.getAttribute('data-viewing-title') ||
                                 freshGroupSelect.getAttribute('data-viewing-title');
            
            if (viewingTitle) {
                // 如果有正在查看的標題，保持該狀態，直接打開該文件
                await openFile(viewingTitle);
                
                // 如果切換前就是編輯模式，切換後自動恢復統一編輯狀態
                if (wasInEditMode) {
                    window.SuggestEditAllMode = true;
                    await enableEditAllMode();
                }
                
                // 更新保存的標題
                if (freshCohortSelect) freshCohortSelect.setAttribute('data-viewing-title', viewingTitle);
                if (freshGroupSelect) freshGroupSelect.setAttribute('data-viewing-title', viewingTitle);
            } else {
                // 沒有正在查看的標題，正常載入
                // 載入已存在的標題列表，根據結果決定是否顯示選擇標題和選擇審查結果
                // listTitles API 已支持 "all" 值
                loadExistingTitles(cohortId, actualGroupId);
                // 檢查是否已經處於編輯模式（存檔按鈕顯示且啟用）
                const isAlreadyInEditMode = saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
                // 檢查是否有已存在的建議，如果有則顯示檔案列表，否則顯示團隊編輯界面
                await checkAndDisplayMode(cohortId, actualGroupId);
                
                // 確保存檔按鈕狀態正確
                // 等待一下讓 checkAndDisplayMode 完全執行完
                await new Promise(resolve => setTimeout(resolve, 100));
                
                // 重新獲取存檔按鈕元素（確保是最新的狀態）
                const saveBtnAfterCheck = document.getElementById("sg-save-btn");
                const fileListContainer = document.getElementById("sg-file-list");
                const teamListContainer = document.getElementById("sg-team-list");
                const titleInput = document.getElementById("sg-title");
                const currentTitle = titleInput ? titleInput.value.trim() : "";
                
                if (saveBtnAfterCheck) {
                    // 如果已經處於編輯模式，確保存檔按鈕保持啟用，並重新套用編輯模式到新載入的團隊
                    if (isAlreadyInEditMode) {
                        saveBtnAfterCheck.removeAttribute('disabled');
                        saveBtnAfterCheck.disabled = false;
                        saveBtnAfterCheck.style.display = 'inline-block';
                        console.log('類組切換：保持編輯模式，存檔按鈕已啟用');
                        // 重新啟用所有團隊的編輯模式，避免切換類組後回到唯讀狀態
                        await enableEditAllMode();
                    } else {
                        // 檢查是否應該顯示存檔按鈕（第一次輸入的情況）
                        // 條件：團隊列表顯示、檔案列表隱藏、沒有標題或標題下沒有建議
                        const shouldShowSaveBtn = teamListContainer && 
                                                 teamListContainer.style.display !== 'none' && 
                                                 fileListContainer && 
                                                 fileListContainer.style.display === 'none';
                        
                        if (shouldShowSaveBtn) {
                            // 如果有標題，檢查該標題下是否有建議
                            if (currentTitle) {
                                const hasAnySuggest = await checkIfHasAnySuggest(cohortId, actualGroupId, currentTitle);
                                if (!hasAnySuggest) {
                                    // 沒有建議，應該顯示存檔按鈕
                                    saveBtnAfterCheck.removeAttribute('disabled');
                                    saveBtnAfterCheck.disabled = false;
                                    saveBtnAfterCheck.style.display = 'inline-block';
                                    console.log('類組切換：第一次輸入（有標題但無建議），存檔按鈕已啟用');
                                }
                            } else {
                                // 沒有標題，應該顯示存檔按鈕
                                saveBtnAfterCheck.removeAttribute('disabled');
                                saveBtnAfterCheck.disabled = false;
                                saveBtnAfterCheck.style.display = 'inline-block';
                                console.log('類組切換：第一次輸入（無標題），存檔按鈕已啟用');
                            }
                        }
                    }
                }
            }
            if (exportBtn) exportBtn.disabled = false;
        } else {
            if (titleInput) titleInput.disabled = true;
            const titleSelect = document.getElementById("sg-title-select");
            const statusSelect = document.getElementById("sg-status");
            const filterRow2 = document.getElementById("sg-filter-row2");
            if (titleSelect) {
                titleSelect.innerHTML = '<option value="">請先選擇屆別和類組</option>';
                titleSelect.disabled = true;
            }
            if (statusSelect) {
                statusSelect.innerHTML = '<option value="">請先選擇屆別和類組</option>';
                statusSelect.disabled = true;
            }
            const filterHint = document.getElementById("sg-filter-hint");
            if (filterRow2) {
                filterRow2.style.display = 'none';
            }
            if (filterHint) {
                filterHint.style.display = 'none';
            }
            const teamList = document.getElementById("sg-team-list");
            const fileList = document.getElementById("sg-file-list");
            const leftCol = document.getElementById("sg-team-left");
            const rightCol = document.getElementById("sg-team-right");
            if (teamList) teamList.innerHTML = "";
            if (fileList) fileList.style.display = "none";
            if (leftCol) leftCol.innerHTML = "";
            if (rightCol) rightCol.innerHTML = "";
            if (saveBtn) saveBtn.disabled = true;
            if (exportBtn) exportBtn.disabled = true;
            if (exportWordBtn) exportWordBtn.disabled = true;
        }
    });
    
    // 標題選擇下拉選單事件：當選擇已存在的標題時，自動填入輸入框並過濾團隊
    const titleSelect = document.getElementById("sg-title-select");
    const titleInput = document.getElementById("sg-title");
    const statusSelect = document.getElementById("sg-status");
    
    if (titleSelect && titleInput) {
        titleSelect.addEventListener("change", async function() {
            if (this.value) {
                const selectedTitle = this.value;
                titleInput.value = selectedTitle;
                
                const cohortSelect = document.getElementById("sg-cohort");
                const groupSelect = document.getElementById("sg-group");
                if (cohortSelect && groupSelect && cohortSelect.value && groupSelect.value) {
                    // 改為開啟「表格檢視」並載入團隊建議（與手動輸入標題／從檔案列表點擊一致）
                    await openFile(selectedTitle);
                } else {
                    const filterRow2 = document.getElementById("sg-filter-row2");
                    const filterHint = document.getElementById("sg-filter-hint");
                    if (filterRow2) filterRow2.style.display = 'flex';
                    if (filterHint) filterHint.style.display = 'block';
                    updateSuggestFilterLabel();
                    const editAllBtn = document.getElementById("sg-edit-all-btn");
                    const saveBtn = document.getElementById("sg-save-btn");
                    const exportBtn = document.getElementById("sg-export-btn");
                    const exportWordBtn = document.getElementById("sg-export-word-btn");
                    if (editAllBtn) editAllBtn.style.display = 'none';
                    if (saveBtn) saveBtn.style.display = 'none';
                    if (exportBtn) exportBtn.style.display = 'none';
                    if (exportWordBtn) exportWordBtn.style.display = 'none';
                    const fileListContainer = document.getElementById("sg-file-list");
                    if (fileListContainer) fileListContainer.style.display = 'none';
                    const teamListContainer = document.getElementById("sg-team-list");
                    if (teamListContainer) teamListContainer.style.display = 'block';
                }
            } else {
                // 如果清空選擇，切換回檔案列表（如果有資料）或顯示團隊編輯界面
                const cohortSelect = document.getElementById("sg-cohort");
                const groupSelect = document.getElementById("sg-group");
                if (cohortSelect && groupSelect && cohortSelect.value && groupSelect.value) {
                    checkAndDisplayMode(cohortSelect.value, groupSelect.value);
                }
            }
        });
        
        // 當使用者手動輸入標題時，清空下拉選單的選擇
        titleInput.addEventListener("input", function() {
            if (titleSelect.value && titleSelect.value !== this.value) {
                titleSelect.value = "";
                // 切換回檔案列表（如果有資料）或顯示團隊編輯界面
                const cohortSelect = document.getElementById("sg-cohort");
                const groupSelect = document.getElementById("sg-group");
                if (cohortSelect && groupSelect && cohortSelect.value && groupSelect.value) {
                    checkAndDisplayMode(cohortSelect.value, groupSelect.value);
                }
            }
        });
    }
    
    // 狀態選擇事件：自動觸發篩選
    if (statusSelect) {
        statusSelect.addEventListener("change", async function() {
            // 當選擇審查結果時，自動觸發篩選
            const titleSelect = document.getElementById("sg-title-select");
            const cohortSelect = document.getElementById("sg-cohort");
            const groupSelect = document.getElementById("sg-group");
            
            // 如果已選擇標題，重新載入團隊列表（會自動根據審查結果篩選）
            if (titleSelect && titleSelect.value && cohortSelect && groupSelect && cohortSelect.value && groupSelect.value) {
                await loadTeamsWithTitleForFilter(cohortSelect.value, groupSelect.value, titleSelect.value, this.value);
            } else {
                // 如果沒有選擇標題，調用篩選函數（會清空列表）
                await filterTeamsByTitleAndStatus();
            }
        });
    }
    
    
    // 回到初始頁按鈕事件
    const backHomeBtn = document.getElementById("sg-back-home-btn");
    if (backHomeBtn) {
        backHomeBtn.addEventListener("click", () => {
            goBackToHomePage();
        });
    }
    
    // 回到初始頁函數
    window.goBackToHomePage = async function() {
        // 清除當前查看的標題狀態
        window.currentViewingTitle = null;
        const cohortSelect = document.getElementById("sg-cohort");
        const groupSelect = document.getElementById("sg-group");
        if (cohortSelect) cohortSelect.removeAttribute('data-viewing-title');
        if (groupSelect) groupSelect.removeAttribute('data-viewing-title');
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
        
        // 重新獲取元素（因為可能在return之前已經獲取過）
        const titleInput = document.getElementById("sg-title");
        const titleSelect = document.getElementById("sg-title-select");
        const filterRow2 = document.getElementById("sg-filter-row2");
        
        // cohortSelect 和 groupSelect 已經在上面聲明過了，這裡直接使用
        if (!cohortSelect || !groupSelect) return;
        
        const cohortId = cohortSelect.value;
        const groupId = groupSelect.value;
        
        if (!cohortId || !groupId) return;
        
        // 清空標題輸入框和下拉選單
        if (titleInput) titleInput.value = "";
        if (titleSelect) titleSelect.value = "";
        
        // 不再顯示檔案列表，直接顯示團隊編輯界面
        // 隱藏第二行篩選框和提示說明
        const filterHint = document.getElementById("sg-filter-hint");
        if (filterRow2) filterRow2.style.display = 'none';
        if (filterHint) filterHint.style.display = 'none';
        
        // 直接顯示團隊編輯界面
        await checkAndDisplayMode(cohortId, groupId);
    };
    
    // 統一編輯按鈕事件
    const editAllBtn = document.getElementById("sg-edit-all-btn");
    if (editAllBtn) {
        editAllBtn.addEventListener("click", () => {
            // 標記目前為「已進入統一編輯模式」
            window.SuggestEditAllMode = true;
            enableEditAllMode();
        });
    }
    
    // 新增建議表按鈕事件
    const newSuggestBtn = document.getElementById("sg-new-suggest-btn");
    if (newSuggestBtn) {
        newSuggestBtn.addEventListener("click", async () => {
            console.log("新增建議表按鈕被點擊");
            const cohortId = freshCohortSelect.value;
            const groupId = freshGroupSelect.value;
            console.log(`屆別: ${cohortId}, 類組: ${groupId}`);
            
            if (!cohortId || !groupId) {
                Toast.fire({
                    icon: "warning",
                    title: "請選擇屆別和類組",
                    text: "請先選擇屆別和類組"
                });
                return;
            }
            
            // 清空標題輸入
            const titleInput = document.getElementById("sg-title");
            if (titleInput) {
                titleInput.value = "";
            }
            
            // 清空標題選擇
            const titleSelect = document.getElementById("sg-title-select");
            if (titleSelect) {
                titleSelect.value = "";
            }
            
            // 顯示團隊列表（第一次填建議的狀態）
            const fileListContainer = document.getElementById("sg-file-list");
            const teamListContainer = document.getElementById("sg-team-list");
            const hint = document.getElementById("sg-team-list-hint");
            
            if (fileListContainer) fileListContainer.style.display = "none";
            if (teamListContainer) teamListContainer.style.display = "flex";
            if (hint) hint.style.display = "block";
            
            // 隱藏新增建議表按鈕，顯示存檔、匯出和回到初始頁按鈕（不顯示編輯按鈕，因為已經是編輯狀態）
            const saveBtn = document.getElementById("sg-save-btn");
            const editAllBtn = document.getElementById("sg-edit-all-btn");
            const exportBtn = document.getElementById("sg-export-btn");
            const exportWordBtn = document.getElementById("sg-export-word-btn");
            const backHomeBtn = document.getElementById("sg-back-home-btn");
            if (newSuggestBtn) newSuggestBtn.style.display = 'none';
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.style.display = 'inline-block';
            }
            // 不顯示編輯按鈕，因為團隊已經是可編輯狀態
            if (editAllBtn) editAllBtn.style.display = 'none';
            if (exportBtn) {
                exportBtn.style.display = 'inline-block';
                exportBtn.disabled = false;
            }
            if (exportWordBtn) {
                exportWordBtn.style.display = 'inline-block';
                exportWordBtn.disabled = false;
            }
            if (backHomeBtn) backHomeBtn.style.display = 'inline-block';
            
            // 載入團隊列表（不載入現有建議，顯示空白的可編輯狀態）
            await loadTeams(cohortId, groupId, false);
        });
    }
    
    // 加入團隊按鈕事件
    const addTeamBtn = document.getElementById("sg-add-team-btn");
    if (addTeamBtn) {
        addTeamBtn.addEventListener("click", async () => {
            await showAddTeamDialog();
        });
    }
    
    // 使用事件委派綁定刪除按鈕事件（避免動態載入後綁不到）
    $(document).on('click', '.sg-btn-del', function(e) {
        e.preventDefault();
        if (!isAddTeamButtonAvailable()) {
            Toast.fire({ icon: "warning", title: "請先進入編輯模式", text: "請點擊「編輯」按鈕後再進行刪除" });
            return;
        }
        const suggestId = parseInt($(this).data('suggest-id'));
        const teamId = parseInt($(this).data('team-id'));
        if (suggestId && teamId) {
            deleteSuggest(suggestId, teamId);
        }
    });
    
    // 使用事件委派綁定刪除團隊按鈕事件（第一次編輯時使用）
    $(document).on('click', '.sg-btn-delete-team', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (!isAddTeamButtonAvailable()) {
            Toast.fire({ icon: "warning", title: "請先進入編輯模式", text: "請點擊「編輯」按鈕後再進行刪除" });
            return;
        }
        const teamId = parseInt($(this).data('team'));
        if (teamId) {
            deleteTeamFromSuggest(teamId);
        }
    });
    
    // 使用事件委派綁定返回按鈕事件（防呆：編輯中點擊時提示存檔並退出 / 直接退出 / 取消）
    $(document).on('click', '#sg-back-to-integrate-btn', async function(e) {
        e.preventDefault();
        const href = $(this).data('href');
        if (!href) return;
        
        const saveBtn = document.getElementById("sg-save-btn");
        const isInEditMode = saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
        
        if (isInEditMode) {
            const result = await Swal.fire({
                icon: "warning",
                title: "尚未存檔",
                text: "目前處於編輯狀態，要存檔後再離開嗎？",
                showDenyButton: true,
                showCancelButton: true,
                confirmButtonText: "存檔並退出",
                denyButtonText: "直接退出",
                cancelButtonText: "取消",
                reverseButtons: true
            });
            
            if (result.isConfirmed) {
                const cohortSelect = document.getElementById("sg-cohort");
                const groupSelect = document.getElementById("sg-group");
                const cohortId = cohortSelect ? cohortSelect.value : null;
                const groupId = groupSelect ? groupSelect.value : null;
                if (cohortId && groupId) {
                    try {
                        await saveAllSuggestions(cohortId, groupId);
                        window.location.href = href;
                    } catch (err) {
                        console.error("存檔失敗:", err);
                        Toast.fire({
                            icon: "error",
                            title: "存檔失敗",
                            text: err.message || "請稍後再試"
                        });
                    }
                } else {
                    Toast.fire({
                        icon: "warning",
                        title: "請選擇屆別和類組",
                        text: "存檔前請先選擇屆別和類組"
                    });
                }
                return;
            }
            if (result.isDenied) {
                window.location.href = href;
                return;
            }
            return;
        }
        
        window.location.href = href;
    });
    
    // 存檔按鈕事件
    const saveBtn = document.getElementById("sg-save-btn");
    if (saveBtn) {
        saveBtn.addEventListener("click", async () => {
            console.log("存檔按鈕被點擊");
            const cohortId = freshCohortSelect.value;
            const groupId = freshGroupSelect.value;
            console.log(`屆別: ${cohortId}, 類組: ${groupId}`);
            if (cohortId && groupId) {
                try {
                    await saveAllSuggestions(cohortId, groupId);
                } catch (err) {
                    console.error("存檔過程發生錯誤:", err);
                    Toast.fire({
                        icon: "error",
                        title: "存檔失敗",
                        text: err.message || "請稍後再試"
                    });
                }
            } else {
                Toast.fire({
                    icon: "warning",
                    title: "請選擇屆別和類組",
                    text: "存檔前請先選擇屆別和類組"
                });
            }
        });
    }
    
    // 匯出PDF按鈕事件
    const exportBtn = document.getElementById("sg-export-btn");
    if (exportBtn) {
        exportBtn.addEventListener("click", () => {
            const cohortId = freshCohortSelect.value;
            const groupId = freshGroupSelect.value;
            if (cohortId && groupId) {
                exportSuggestions(cohortId, groupId);
            }
        });
    }
    
    // 匯出Word按鈕事件
    const exportWordBtn = document.getElementById("sg-export-word-btn");
    if (exportWordBtn) {
        exportWordBtn.addEventListener("click", () => {
            const cohortId = freshCohortSelect.value;
            const groupId = freshGroupSelect.value;
            if (cohortId && groupId) {
                exportSuggestionsWord(cohortId, groupId);
            }
        });
    }
    
    return true;
}

// 立即嘗試初始化（如果元素已存在）
// 注意：當頁面通過 AJAX 載入時，script 標籤會被移除，所以這裡不會執行
// 初始化將通過 MutationObserver 或 pageLoaded 事件觸發
if (document.getElementById("sg-cohort") && document.getElementById("sg-group")) {
    if (!initSuggest()) {
        // 如果元素不存在，等待 DOMContentLoaded
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                initSuggest();
            }, { once: true });
        } else {
            // DOM 已就緒但元素可能還沒載入（透過 AJAX 載入），延遲再試
            let attempts = 0;
            const maxAttempts = 20;
            
            const checkInterval = setInterval(() => {
                attempts++;
                if (initSuggest() || attempts >= maxAttempts) {
                    clearInterval(checkInterval);
                }
            }, 100);
            
            // 同時使用 MutationObserver 監聽 DOM 變化（更即時）
            const observer = new MutationObserver(() => {
                if (initSuggest()) {
                    observer.disconnect();
                    clearInterval(checkInterval);
                }
            });
            const targetEl = document.body || document.documentElement;
            if (targetEl && targetEl instanceof Node) {
                try {
                    observer.observe(targetEl, {
                        childList: true,
                        subtree: true
                    });
                } catch (e) {
                    console.warn('無法觀察 DOM 元素:', e);
                }
            }
            
            // 10 秒後停止觀察和檢查（避免記憶體洩漏）
            setTimeout(() => {
                observer.disconnect();
                clearInterval(checkInterval);
            }, 10000);
        }
    }
}

// 監聽自定義事件（當頁面動態載入完成時）
$(document).on('pageLoaded scriptExecuted', function(e, path) {
    if (path && path.includes('suggest')) {
        setTimeout(() => {
            // 重置初始化標記，強制重新初始化
            const cohortSelect = document.getElementById("sg-cohort");
            if (cohortSelect) {
                cohortSelect.dataset.initialized = 'false';
            }
            if (!initSuggest()) {
                // 如果第一次失敗，再試一次
                setTimeout(initSuggest, 300);
            }
        }, 200);
    }
});

// 監聽頁面載入事件（當 loadSubpage 完成後）
// 使用 MutationObserver 監聽 #content 的變化
// 使用條件聲明避免重複聲明錯誤
if (typeof window.SuggestContentObserver === 'undefined') {
    window.SuggestContentObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.addedNodes.length > 0) {
                // 檢查是否有 suggest 相關的元素被加入
                const hasSuggest = Array.from(mutation.addedNodes).some(node => {
                    if (node.nodeType === 1) { // Element node
                        return node.querySelector && (
                            node.querySelector('#sg-cohort') || 
                            node.id === 'sg-cohort' ||
                            node.classList?.contains('suggest-wrapper')
                        );
                    }
                    return false;
                });
                
                if (hasSuggest) {
                    // 延遲一下確保 DOM 完全載入
                    setTimeout(() => {
                        const cohortSelect = document.getElementById("sg-cohort");
                        if (cohortSelect && cohortSelect.dataset.initialized !== 'true') {
                            // 檢查 suggest.js 是否已載入（通過檢查 initSuggest 函數是否存在）
                            if (typeof window.initSuggest === 'function') {
                                // 如果已經載入，直接初始化
                                window.initSuggest();
                            } else {
                                // 如果 suggest.js 還沒載入，手動載入
                                const existingScript = document.querySelector('script[src*="suggest.js"]');
                                if (!existingScript) {
                                    console.log('檢測到 suggest 頁面，開始載入 suggest.js...');
                                    const suggestScript = document.createElement('script');
                                    suggestScript.src = '../js/suggest.js?v=' + Date.now();
                                    suggestScript.onload = function() {
                                        console.log('suggest.js 載入完成，開始初始化...');
                                        setTimeout(() => {
                                            if (typeof window.initSuggest === 'function') {
                                                window.initSuggest();
                                            } else {
                                                console.error('initSuggest 函數不存在');
                                            }
                                        }, 50);
                                    };
                                    suggestScript.onerror = function() {
                                        console.error('載入 suggest.js 失敗');
                                    };
                                    document.head.appendChild(suggestScript);
                                } else {
                                    // 如果 script 標籤存在但函數還不可用，等待一下再試
                                    setTimeout(() => {
                                        if (typeof window.initSuggest === 'function') {
                                            window.initSuggest();
                                        }
                                    }, 200);
                                }
                            }
                        }
                    }, 100);
                }
            }
        });
    });
}
// 使用 var 避免重複聲明錯誤（如果腳本被多次加載）
var contentObserver = window.SuggestContentObserver;

// 開始觀察 #content 的變化
var contentEl = document.getElementById('content');
if (contentEl && contentEl instanceof Node) {
    try {
        // 如果已經在觀察，先斷開連接
        if (contentObserver && contentObserver.disconnect) {
            contentObserver.disconnect();
        }
        contentObserver.observe(contentEl, {
            childList: true,
            subtree: true
        });
    } catch (e) {
        console.warn('無法觀察 content 元素:', e);
    }
}

/* ==========================================
   2. 取得屆別 (cohortdata)
========================================== */
async function loadCohorts() {
    try {
        const apiUrl = resolveSuggestApiUrl();
        console.log('載入屆別，API URL:', apiUrl);
        const r = await fetch(`${apiUrl}?action=listCohorts`, {
            credentials: 'same-origin'
        });
        
        if (!r.ok) {
            throw new Error(`HTTP ${r.status}: ${r.statusText}`);
        }
        
        const j = await r.json();
        console.log('屆別 API 回應:', j);
        
        if (!j.success) {
            throw new Error(j.msg || '未知錯誤');
        }
        
        let select = document.getElementById("sg-cohort");
        if (!select) {
            console.error('找不到屆別選單元素');
            return;
        }
        
        select.innerHTML = `<option value="">請選擇屆別</option>`;

        if (j.data && Array.isArray(j.data) && j.data.length > 0) {
            j.data.forEach(c => {
                select.innerHTML += `
                    <option value="${c.cohort_ID}">
                        ${c.cohort_name}
                    </option>`;
            });
            console.log(`已載入 ${j.data.length} 個屆別`);
        } else {
            select.innerHTML += `<option value="" disabled>查無屆別資料</option>`;
            console.warn('屆別資料為空');
        }

    } catch (err) {
        console.error('載入屆別失敗:', err);
        Toast.fire({ 
            icon: "error", 
            title: "屆別載入失敗",
            text: err.message || '請檢查網路連線或重新整理頁面'
        });
        
        // 顯示錯誤訊息在選單中
        const select = document.getElementById("sg-cohort");
        if (select) {
            select.innerHTML = `<option value="">載入失敗，請重新整理</option>`;
        }
    }
}

/* ==========================================
   2-2. 載入已存在的標題列表
========================================== */
async function loadExistingTitles(cohortId, groupId) {
    const titleSelect = document.getElementById("sg-title-select");
    const titleInput = document.getElementById("sg-title");
    
    if (!titleSelect || !titleInput) return;
    
    try {
        const apiUrl = resolveSuggestApiUrl();
        const r = await fetch(`${apiUrl}?action=listTitles&cohort_ID=${cohortId}&group_ID=${groupId}`, {
            credentials: 'same-origin'
        });
        
        if (!r.ok) {
            throw new Error(`HTTP ${r.status}: ${r.statusText}`);
        }
        
        // 檢查回應內容類型
        const contentType = r.headers.get("content-type");
        if (!contentType || !contentType.includes("application/json")) {
            const text = await r.text();
            console.error('API 返回非 JSON 格式:', text.substring(0, 200));
            throw new Error('API 返回格式錯誤');
        }
        
        const j = await r.json();
        
        if (!j.success) {
            throw new Error(j.msg || '未知錯誤');
        }
        
        const statusSelect = document.getElementById("sg-status");
        const filterRow2 = document.getElementById("sg-filter-row2");
        
        // 清空並重置下拉選單
        titleSelect.innerHTML = '<option value="">請選擇已存在的標題</option>';
        
        if (j.data && Array.isArray(j.data) && j.data.length > 0) {
            // 如果有已存在的標題，加入選項並顯示選擇標題和選擇審查結果
            j.data.forEach(title => {
                if (title && title.trim() !== '') {
                    titleSelect.innerHTML += `
                        <option value="${escapeHtml(title)}">${escapeHtml(title)}</option>
                    `;
                }
            });
            titleSelect.disabled = false;
            
            // 顯示選擇審查結果
            if (statusSelect) {
                statusSelect.disabled = false;
                loadStatusOptions();
            }
            
            const filterHint = document.getElementById("sg-filter-hint");
            if (filterRow2) filterRow2.style.display = 'flex';
            if (filterHint) filterHint.style.display = 'block';
            updateSuggestFilterLabel();
        } else {
            // 如果沒有已存在的標題，隱藏整個第二行
            titleSelect.disabled = true;
            if (statusSelect) {
                statusSelect.disabled = true;
            }
            const filterHint = document.getElementById("sg-filter-hint");
            if (filterRow2) {
                filterRow2.style.display = 'none';
            }
            if (filterHint) {
                filterHint.style.display = 'none';
            }
        }
        
    } catch (err) {
        console.error('載入標題列表失敗:', err);
        const statusSelect = document.getElementById("sg-status");
        const filterRow2 = document.getElementById("sg-filter-row2");
        titleSelect.innerHTML = '<option value="">載入失敗</option>';
        titleSelect.disabled = true;
        
        // 載入失敗時也隱藏整個第二行
        if (statusSelect) {
            statusSelect.disabled = true;
        }
        const filterHint = document.getElementById("sg-filter-hint");
        if (filterRow2) {
            filterRow2.style.display = 'none';
        }
        if (filterHint) {
            filterHint.style.display = 'none';
        }
    }
}

// HTML 轉義函數
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/* ==========================================
   2-2-1. 載入狀態選項
========================================== */
function loadStatusOptions() {
    const statusSelect = document.getElementById("sg-status");
    if (!statusSelect) return;
    const isTopic = (window.suggestFormSfType === 'topic');
    const placeholder = isTopic ? '請選擇初審建議' : '請選擇審查結果';
    statusSelect.innerHTML = `<option value="">${placeholder}</option>`;
    if (isTopic) {
        statusSelect.innerHTML += '<option value="3">通過</option><option value="2">不通過</option><option value="1">修改</option><option value="4">待確認</option>';
    } else {
        statusSelect.innerHTML += '<option value="3">通過</option><option value="2">不通過</option><option value="1">修改後通過</option><option value="4">修改後複評</option>';
    }
}

/** 依 sf_type 更新篩選列標籤：題目初審建議表顯示「初審建議」，審查建議表顯示「審查結果」 */
function updateSuggestFilterLabel() {
    const isTopic = (window.suggestFormSfType === 'topic');
    const labelEl = document.querySelector('.suggest-status-select-box label');
    const hintEl = document.querySelector('.suggest-filter-hint-text');
    if (labelEl) {
        labelEl.textContent = isTopic ? '選擇初審建議' : '選擇審查結果';
    }
    if (hintEl) {
        hintEl.textContent = isTopic
            ? '此功能可根據已存在的標題和初審建議篩選團隊，篩選後可選擇團隊建立新的建議表'
            : '此功能可根據已存在的標題和審查結果篩選團隊，篩選後可選擇團隊建立新的建議表';
    }
}

/* ==========================================
   2-2-1-1. 檢查並顯示模式（檔案列表或團隊編輯界面）
========================================== */
async function checkAndDisplayMode(cohortId, groupId) {
    try {
        // 清除被刪除的團隊列表（開始新的編輯時）
        deletedTeams.clear();
        
        // 不再顯示檔案列表，直接顯示團隊編輯界面
        const fileListContainer = document.getElementById("sg-file-list");
        const teamListContainer = document.getElementById("sg-team-list");
        const hint = document.getElementById("sg-team-list-hint");
        const saveBtn = document.getElementById("sg-save-btn");
        
        const editAllBtn = document.getElementById("sg-edit-all-btn");
        const exportBtn = document.getElementById("sg-export-btn");
        const exportWordBtn = document.getElementById("sg-export-word-btn");
        const backHomeBtn = document.getElementById("sg-back-home-btn");
        
        const newSuggestBtn = document.getElementById("sg-new-suggest-btn");
        
        // 隱藏檔案列表，顯示團隊編輯界面
        if (fileListContainer) fileListContainer.style.display = "none";
        if (teamListContainer) teamListContainer.style.display = "flex";
        
        // 確保團隊列表為表格結構（避免屆別切換時被清空後沒有 tbody）
        ensureSuggestTableStructure();
        
        // 隱藏第二行篩選框（選擇標題和選擇審查結果）和提示說明
        const filterRow2 = document.getElementById("sg-filter-row2");
        const filterHint = document.getElementById("sg-filter-hint");
        if (filterRow2) filterRow2.style.display = 'none';
        if (filterHint) filterHint.style.display = 'none';
        
        // 隱藏新增建議表按鈕（不再需要）
        if (newSuggestBtn) {
            newSuggestBtn.style.display = 'none';
        }
        
        // 檢查是否有標題輸入，如果有則檢查該標題下是否有建議
        const titleInput = document.getElementById("sg-title");
        const currentTitle = titleInput ? titleInput.value.trim() : "";
        
        // 檢查是否已經處於編輯模式（存檔按鈕顯示且啟用）
        const isAlreadyInEditMode = saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
        
        // 獲取加入團隊按鈕（在函數頂部聲明，避免重複聲明）
        const addTeamBtn = document.getElementById("sg-add-team-btn");
        
        if (currentTitle) {
            // 有標題，檢查該標題下是否有建議
            const hasAnySuggest = await checkIfHasAnySuggest(cohortId, groupId, currentTitle);
            
            if (hasAnySuggest) {
                const readOnly = !!window.SuggestReadOnlyForConvener;
                // 已有資料：檢視時顯示編輯、隱藏存檔；編輯時顯示存檔、隱藏編輯（二擇一）
                if (editAllBtn) {
                    if (readOnly) {
                        editAllBtn.style.display = 'none';
                    } else if (isAlreadyInEditMode) {
                        editAllBtn.style.display = 'none';
                    } else {
                        editAllBtn.disabled = false;
                        editAllBtn.style.display = 'inline-block';
                    }
                }
                if (saveBtn) {
                    if (readOnly || !isAlreadyInEditMode) {
                        saveBtn.disabled = true;
                        saveBtn.style.display = 'none';
                    } else {
                        // 保持編輯模式狀態
                        saveBtn.removeAttribute('disabled');
                        saveBtn.disabled = false;
                        saveBtn.style.display = 'inline-block';
                    }
                }
                // 更新加入團隊按鈕狀態：只在編輯模式下顯示（存檔按鈕顯示時才顯示），且僅召集人可新增建議
                if (addTeamBtn) {
                    const isInEditMode = saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
                    const readOnly = !!window.SuggestReadOnlyForConvener;
                    if (currentTitle && currentTitle.trim() !== '' && isInEditMode && window.isSuggestConvener && !readOnly) {
                        addTeamBtn.disabled = false;
                        addTeamBtn.style.display = 'inline-block';
                    } else {
                        addTeamBtn.disabled = true;
                        addTeamBtn.style.display = 'none';
                    }
                }
                // 檢視模式時標題唯讀，編輯模式才可改（由 enableEditAllMode 啟用）
                if (titleInput) titleInput.disabled = !isAlreadyInEditMode;
            } else {
                // 第一次輸入：顯示存檔按鈕，隱藏編輯按鈕
                if (editAllBtn) {
                    editAllBtn.style.display = 'none';
                }
                if (saveBtn) {
                    saveBtn.removeAttribute('disabled');
                    saveBtn.disabled = false;
                    saveBtn.style.display = 'inline-block';
                }
                // 第一次輸入時顯示加入團隊按鈕（存檔按鈕顯示時才顯示），且僅召集人可新增建議
                if (addTeamBtn) {
                    const isInEditMode = saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
                    if (currentTitle && currentTitle.trim() !== '' && isInEditMode && window.isSuggestConvener) {
                        addTeamBtn.disabled = false;
                        addTeamBtn.style.display = 'inline-block';
                    } else {
                        addTeamBtn.disabled = true;
                        addTeamBtn.style.display = 'none';
                    }
                }
                if (titleInput) titleInput.disabled = false;
            }
        } else {
            // 沒有標題，預設為第一次輸入（顯示存檔按鈕）
            if (editAllBtn) {
                editAllBtn.style.display = 'none';
            }
            if (saveBtn) {
                saveBtn.removeAttribute('disabled');
                saveBtn.disabled = false;
                saveBtn.style.display = 'inline-block';
            }
            // 沒有標題時不顯示加入團隊按鈕
            if (addTeamBtn) {
                addTeamBtn.disabled = true;
                addTeamBtn.style.display = 'none';
            }
            if (titleInput) titleInput.disabled = false;
        }

        // 如果外層曾經按下「編輯」（統一編輯），在載入團隊之後再次套用編輯模式，
        // 避免切換類組或重新載入資料時又回到唯讀狀態。已送交科辦的建議表不再套用編輯模式。
        if (window.SuggestEditAllMode && !window.SuggestReadOnlyForConvener) {
            await enableEditAllMode();
        }
        
        // 隱藏回到初始頁按鈕（不再需要）
        if (backHomeBtn) backHomeBtn.style.display = 'none';
        
        // 團隊編輯界面時顯示匯出按鈕
        // exportWordBtn 已在函數開頭宣告，這裡直接使用
        if (exportBtn) {
            exportBtn.style.display = 'inline-block';
            exportBtn.disabled = false;
        }
        if (exportWordBtn) {
            exportWordBtn.style.display = 'inline-block';
            exportWordBtn.disabled = false;
        }
        
        // 如果有標題，載入該標題的團隊和建議；否則載入所有團隊
        if (currentTitle) {
            // 有標題，載入該標題的團隊和建議
            await loadTeamsWithTitle(cohortId, groupId, currentTitle);
            
            // 再次檢查是否有建議（因為 loadTeamsWithTitle 可能已經加載了數據）
            // 檢查是否已經處於編輯模式（存檔按鈕顯示且啟用）
            const isAlreadyInEditModeAfterLoad = saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
            const hasAnySuggest = await checkIfHasAnySuggest(cohortId, groupId, currentTitle);
            
            if (hasAnySuggest) {
                // 已有資料：檢視時顯示編輯、隱藏存檔；編輯時顯示存檔、隱藏編輯（二擇一）
                if (editAllBtn) {
                    if (isAlreadyInEditModeAfterLoad) {
                        editAllBtn.style.display = 'none';
                    } else {
                        editAllBtn.disabled = false;
                        editAllBtn.style.display = 'inline-block';
                    }
                }
                if (saveBtn) {
                    if (!isAlreadyInEditModeAfterLoad) {
                        saveBtn.disabled = true;
                        saveBtn.style.display = 'none';
                    } else {
                        saveBtn.removeAttribute('disabled');
                        saveBtn.disabled = false;
                        saveBtn.style.display = 'inline-block';
                    }
                }
                const titleInputAfterLoad = document.getElementById("sg-title");
                if (titleInputAfterLoad) titleInputAfterLoad.disabled = !isAlreadyInEditModeAfterLoad;
            } else {
                // 第一次輸入：顯示存檔按鈕，隱藏編輯按鈕
                if (editAllBtn) {
                    editAllBtn.style.display = 'none';
                }
                if (saveBtn) {
                    saveBtn.removeAttribute('disabled');
                    saveBtn.disabled = false;
                    saveBtn.style.display = 'inline-block';
                    console.log('checkAndDisplayMode：第一次輸入（有標題），存檔按鈕已啟用');
                }
                const titleInputFirstLoad = document.getElementById("sg-title");
                if (titleInputFirstLoad) titleInputFirstLoad.disabled = false;
            }
        } else {
            // 沒有標題，載入所有團隊但不載入已存在的建議（讓用戶可以填寫新建議）
            await loadTeams(cohortId, groupId, false);
            
            // 載入團隊後，確保存檔按鈕狀態正確（第一次輸入的情況）
            const saveBtnAfterLoad = document.getElementById("sg-save-btn");
            if (saveBtnAfterLoad) {
                // 如果存檔按鈕顯示但被禁用，則啟用它
                if (saveBtnAfterLoad.style.display !== 'none' && saveBtnAfterLoad.disabled) {
                    saveBtnAfterLoad.removeAttribute('disabled');
                    saveBtnAfterLoad.disabled = false;
                    console.log('checkAndDisplayMode：載入團隊後，存檔按鈕已啟用（第一次輸入，無標題）');
                } else if (saveBtnAfterLoad.style.display === 'none') {
                    // 如果存檔按鈕被隱藏，檢查是否應該顯示（第一次輸入的情況）
                    const fileListContainer = document.getElementById("sg-file-list");
                    const teamListContainer = document.getElementById("sg-team-list");
                    
                    if (teamListContainer && teamListContainer.style.display !== 'none' && 
                        fileListContainer && fileListContainer.style.display === 'none') {
                        saveBtnAfterLoad.removeAttribute('disabled');
                        saveBtnAfterLoad.disabled = false;
                        saveBtnAfterLoad.style.display = 'inline-block';
                        console.log('checkAndDisplayMode：載入團隊後，存檔按鈕已顯示並啟用（第一次輸入，無標題）');
                    }
                }
            }
        }
        // 根據目前編輯模式更新操作按鈕（評分、刪除）的啟用狀態
        updateActionButtonsState();
    } catch (err) {
        console.error('檢查顯示模式失敗:', err);
        // 發生錯誤時，顯示團隊編輯界面（已送交科辦的建議表仍不顯示編輯按鈕）
        const fileListContainer = document.getElementById("sg-file-list");
        const teamListContainer = document.getElementById("sg-team-list");
        const editAllBtn = document.getElementById("sg-edit-all-btn");
        const exportBtn = document.getElementById("sg-export-btn");
        const exportWordBtn = document.getElementById("sg-export-word-btn");
        if (fileListContainer) fileListContainer.style.display = "none";
        if (teamListContainer) teamListContainer.style.display = "flex";
        if (editAllBtn) {
            if (!window.SuggestReadOnlyForConvener) {
                editAllBtn.style.display = 'inline-block';
            }
        }
        const saveBtnErr = document.getElementById("sg-save-btn");
        if (saveBtnErr) saveBtnErr.style.display = 'none';
        if (exportBtn) {
            exportBtn.style.display = 'inline-block';
            exportBtn.disabled = false;
        }
        if (exportWordBtn) {
            exportWordBtn.style.display = 'inline-block';
            exportWordBtn.disabled = false;
        }
        await loadTeams(cohortId, groupId, false);
        updateActionButtonsState();
    }
}

/* ==========================================
   2-2-1-2. 顯示檔案列表
========================================== */
async function displayFileList(cohortId, groupId, titles) {
    const fileListContainer = document.getElementById("sg-file-list");
    if (!fileListContainer) return;
    
    // 取得每個標題的最新更新時間
    const fileData = [];
    for (const title of titles) {
        try {
            const apiUrl = resolveSuggestApiUrl();
            const r = await fetch(`${apiUrl}?action=getTitleInfo&cohort_ID=${cohortId}&group_ID=${groupId}&title=${encodeURIComponent(title)}`);
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
    fileData.forEach(file => {
        if (!file || !file.title) return; // 跳過無效資料
        
        const displayDate = file.date ? new Date(file.date).toLocaleDateString('zh-TW') : '';
        const safeTitle = escapeHtml(file.title || '');
        const safeTitleAttr = escapeHtml(file.title || '').replace(/"/g, '&quot;');
        
        html += `
            <div class="sg-file-card" data-title="${safeTitleAttr}" data-cohort-id="${cohortId}" data-group-id="${groupId}">
                <div class="sg-file-card-buttons">
                    <button class="sg-card-btn sg-card-btn-notify" 
                            data-title="${safeTitleAttr}"
                            data-cohort-id="${cohortId}"
                            data-group-id="${groupId}"
                            title="發送通知">
                        <i class="fas fa-bell"></i>
                    </button>
                    <button class="sg-card-btn sg-card-btn-export" 
                            data-title="${safeTitleAttr}"
                            data-cohort-id="${cohortId}"
                            data-group-id="${groupId}"
                            title="匯出">
                        <i class="fas fa-download"></i>
                    </button>
                </div>
                <div class="sg-file-card-content" style="cursor: pointer;">
                    <div class="sg-file-icon">📄</div>
                    <div class="sg-file-name">${safeTitle}</div>
                    ${displayDate ? `<div class="sg-file-date">${displayDate}</div>` : ''}
                </div>
            </div>
        `;
    });
    
    if (html) {
        fileListContainer.innerHTML = html;
        
        // 綁定按鈕事件（使用事件委託）
        fileListContainer.querySelectorAll('.sg-card-btn-notify').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const title = this.getAttribute('data-title');
                const cohortId = parseInt(this.getAttribute('data-cohort-id'));
                const groupId = parseInt(this.getAttribute('data-group-id'));
                if (title && cohortId && groupId) {
                    sendSuggestNotification(title, cohortId, groupId);
                }
            });
        });
        
        fileListContainer.querySelectorAll('.sg-card-btn-export').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const title = this.getAttribute('data-title');
                const cohortId = parseInt(this.getAttribute('data-cohort-id'));
                const groupId = parseInt(this.getAttribute('data-group-id'));
                if (title && cohortId && groupId) {
                    exportSuggestFile(title, cohortId, groupId);
                }
            });
        });
        
        // 綁定文件卡片點擊事件
        fileListContainer.querySelectorAll('.sg-file-card-content').forEach(content => {
            content.addEventListener('click', function() {
                const card = this.closest('.sg-file-card');
                if (card) {
                    const title = card.getAttribute('data-title');
                    if (title) {
                        openFile(title);
                    }
                }
            });
        });
    } else {
        fileListContainer.innerHTML = '<div class="sg-empty-state"><div class="sg-empty-state-icon">📁</div><div class="sg-empty-state-text">尚無建議表</div></div>';
    }
}

/* ==========================================
   2-2-1-3. 開啟檔案（載入該標題的建議並顯示可編輯畫面）
========================================== */
window.openFile = async function(title) {
    const cohortSelect = document.getElementById("sg-cohort");
    const groupSelect = document.getElementById("sg-group");
    const titleInput = document.getElementById("sg-title");
    const titleSelect = document.getElementById("sg-title-select");
    const filterRow2 = document.getElementById("sg-filter-row2");
    
    if (!cohortSelect || !groupSelect) return;
    
    const cohortId = cohortSelect.value;
    const groupId = groupSelect.value;
    
    if (!cohortId || !groupId) return;
    
    // 保存當前查看的標題到全局變量，用於類組切換時保持狀態
    window.currentViewingTitle = title;
    if (cohortSelect) cohortSelect.setAttribute('data-viewing-title', title);
    if (groupSelect) groupSelect.setAttribute('data-viewing-title', title);
    
    // 設定標題輸入框
    if (titleInput) {
        titleInput.value = title;
        // 保存原始標題到 data 屬性，用於後續判斷是否更改了標題
        titleInput.setAttribute('data-original-title', title);
    }
    
    // 先清空標題下拉選單（避免觸發 change 事件導致按鈕被隱藏）
    if (titleSelect) {
        titleSelect.value = "";
    }
    
    // 隱藏第二行篩選框（選擇標題和選擇審查結果）和提示說明
    const filterHint = document.getElementById("sg-filter-hint");
    if (filterRow2) {
        filterRow2.style.display = 'none';
    }
    if (filterHint) {
        filterHint.style.display = 'none';
    }
    
    // 切換到團隊編輯界面
    const fileListContainer = document.getElementById("sg-file-list");
    const teamListContainer = document.getElementById("sg-team-list");
    const hint = document.getElementById("sg-team-list-hint");
    const editAllBtn = document.getElementById("sg-edit-all-btn");
    const saveBtn = document.getElementById("sg-save-btn");
    const exportBtn = document.getElementById("sg-export-btn");
    const exportWordBtn = document.getElementById("sg-export-word-btn");
    
    if (fileListContainer) fileListContainer.style.display = "none";
    if (teamListContainer) teamListContainer.style.display = "flex";
    if (hint) hint.style.display = "block";
    
    // 點擊檔案時，顯示編輯、匯出和回到初始頁按鈕，隱藏新增建議表按鈕
    const backHomeBtn = document.getElementById("sg-back-home-btn");
    const newSuggestBtn = document.getElementById("sg-new-suggest-btn");
    
    if (exportBtn) {
        exportBtn.style.display = 'inline-block';
        exportBtn.disabled = false;
    }
    if (exportWordBtn) {
        exportWordBtn.style.display = 'inline-block';
        exportWordBtn.disabled = false;
    }
    if (backHomeBtn) {
        backHomeBtn.style.display = 'inline-block';
    }
    if (newSuggestBtn) {
        newSuggestBtn.style.display = 'none';
    }
    // 只在編輯模式下顯示加入團隊按鈕（存檔按鈕顯示時才顯示）
    // 檢查是否處於編輯模式（存檔按鈕顯示且啟用）
    // saveBtn 已在函數開頭宣告（第1424行），這裡直接使用
    const isInEditMode = saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
    
    // 載入所有團隊，並載入該標題對應的建議
    await loadTeamsWithTitle(cohortId, groupId, title);
    
    // 重新初始化拖放功能（確保非編輯模式下禁用拖動）
    initDragAndDrop(cohortId, groupId);
    
    // 檢查是否有任何建議（判斷是第一次輸入還是已有資料）
    const hasAnySuggest = await checkIfHasAnySuggest(cohortId, groupId, title);
    
    if (hasAnySuggest) {
        // 已有資料：顯示編輯按鈕，隱藏存檔按鈕
        
        // 檢查是否需要初始化 suggest_sort_no（如果有 0 值或 null 值）
        // 通過檢查已載入的團隊數據來判斷
        try {
            const teamCards = document.querySelectorAll(".sg-team-card");
            let hasZeroOrNullSortNo = false;
            let checkedCount = 0;
            const maxCheck = 5; // 只檢查前 5 個團隊，如果發現問題就初始化
            
            for (const card of teamCards) {
                if (checkedCount >= maxCheck) break;
                
                const teamId = parseInt(card.getAttribute("data-team"));
                if (!teamId) continue;
                
                try {
                    const apiUrl = resolveSuggestApiUrl();
                    const suggestR = await fetch(`${apiUrl}?action=listSuggests&team_ID=${teamId}`);
                    const suggestJ = await suggestR.json();
                    
                    if (suggestJ.success && suggestJ.data && suggestJ.data.length > 0) {
                        const matchingSuggest = suggestJ.data.find(s => {
                            const suggestTitle = s.suggest_name || s.sf_name || '';
                            return suggestTitle.trim() === title.trim();
                        });
                        
                        if (matchingSuggest) {
                            const sortNo = matchingSuggest.suggest_sort_no;
                            if (sortNo === null || sortNo === undefined || sortNo === 0) {
                                hasZeroOrNullSortNo = true;
                                break;
                            }
                        }
                    }
                    checkedCount++;
                } catch (err) {
                    console.error(`檢查團隊 ${teamId} 的 suggest_sort_no 失敗:`, err);
                }
            }
            
            // 如果有 0 或 null 值，自動初始化
            if (hasZeroOrNullSortNo) {
                console.log("檢測到 suggest_sort_no 為 0 或 null，開始自動初始化...");
                const initResult = await initSuggestSortNo(null, title);
                if (initResult && initResult.success) {
                    console.log("已自動初始化 suggest_sort_no，重新載入數據...");
                    // 重新載入團隊數據
                    await loadTeamsWithTitle(cohortId, groupId, title);
                }
            }
        } catch (err) {
            console.error("檢查和初始化 suggest_sort_no 失敗:", err);
        }
        
        // 檢查是否已經處於編輯模式（存檔按鈕顯示且啟用）
        const isAlreadyInEditMode = saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
        const readOnlySent = !!window.SuggestReadOnlyForConvener;

        if (editAllBtn) {
            if (readOnlySent) {
                editAllBtn.style.display = 'none';
            } else if (isAlreadyInEditMode) {
                editAllBtn.style.display = 'none';
            } else {
                editAllBtn.disabled = false;
                editAllBtn.style.display = 'inline-block';
            }
        }
        if (saveBtn) {
            if (readOnlySent || !isAlreadyInEditMode) {
                saveBtn.disabled = true;
                saveBtn.style.display = 'none';
            } else {
                saveBtn.removeAttribute('disabled');
                saveBtn.disabled = false;
                saveBtn.style.display = 'inline-block';
            }
        }
        // 檢視模式：標題欄位唯讀，只有進入編輯模式後才可改
        const titleInputInOpen = document.getElementById("sg-title");
        if (titleInputInOpen && !isAlreadyInEditMode) titleInputInOpen.disabled = true;
        
        // 重新初始化拖放功能（確保非編輯模式下禁用拖動）
        initDragAndDrop(cohortId, groupId);
    } else {
        // 第一次輸入：顯示存檔按鈕，隱藏編輯按鈕
        if (editAllBtn) {
            editAllBtn.style.display = 'none';
        }
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.style.display = 'inline-block';
        }
        // 第一次輸入：標題可編輯
        const titleInputFirst = document.getElementById("sg-title");
        if (titleInputFirst) titleInputFirst.disabled = false;
        // 第一次輸入：其後統一由下方的加入團隊按鈕狀態更新邏輯處理
    }

    // 統一更新「加入團隊」按鈕狀態
    // 需求：第一次編輯就要有加入團隊按鈕；之後只要在編輯模式也要顯示。已送交科辦的建議表不顯示。
    const addTeamBtn = document.getElementById("sg-add-team-btn");
    if (addTeamBtn) {
        const titleInput = document.getElementById("sg-title");
        const hasTitle = titleInput && titleInput.value.trim() !== '';
        const isInEditMode = isSuggestInEditMode();
        const readOnlySent = !!window.SuggestReadOnlyForConvener;
        if (hasTitle && window.isSuggestConvener && !readOnlySent && (isInEditMode || !hasAnySuggest)) {
            addTeamBtn.disabled = false;
            addTeamBtn.style.display = 'inline-block';
        } else {
            addTeamBtn.disabled = true;
            addTeamBtn.style.display = 'none';
        }
    }
}

/* ==========================================
   檢查該標題下是否有任何建議（判斷是否為第一次輸入）
========================================== */
async function checkIfHasAnySuggest(cohortId, groupId, title) {
    try {
        const apiUrl = resolveSuggestApiUrl();
        // 獲取該屆別和類組的所有團隊
        const groupIdParam = groupId === "all" ? "all" : groupId;
        const teamsR = await fetch(`${apiUrl}?action=listTeams&cohort_ID=${cohortId}&group_ID=${groupIdParam}`);
        const teamsJ = await teamsR.json();
        
        if (!teamsJ.success || !teamsJ.data || teamsJ.data.length === 0) {
            return false;
        }
        
        // 檢查每個團隊是否有該標題的建議
        for (const team of teamsJ.data) {
            const teamId = team.team_ID;
            try {
                const suggestR = await fetch(`${apiUrl}?action=listSuggests&team_ID=${teamId}`);
                const suggestJ = await suggestR.json();
                
                if (suggestJ.success && suggestJ.data && suggestJ.data.length > 0) {
                    // 檢查是否有符合該標題的建議
                    const matchingSuggest = suggestJ.data.find(s => {
                        const suggestTitle = s.suggest_name || s.sf_name || '';
                        return suggestTitle.trim() === title.trim();
                    });
                    
                    if (matchingSuggest) {
                        // 檢查建議內容是否為空
                        const suggestComment = matchingSuggest.suggest_comment || '';
                        if (suggestComment.trim() !== '') {
                            // 找到至少一個非空的建議，返回 true
                            return true;
                        }
                    }
                }
            } catch (err) {
                console.error(`檢查團隊 ${teamId} 建議失敗:`, err);
            }
        }
        
        // 沒有找到任何建議，返回 false
        return false;
    } catch (err) {
        console.error("檢查是否有建議失敗:", err);
        // 如果檢查失敗，預設為已有資料（顯示編輯按鈕）
        return true;
    }
}

/* ==========================================
   初始化舊資料的 suggest_sort_no（依 suggest_d 補成連號）
   規則：同一個 sf_ID，依 suggest_d ASC 補 1,2,3...
   可以通過 sf_ID 或 title 來初始化
========================================== */
async function initSuggestSortNo(sf_ID, title) {
    try {
        const apiUrl = resolveSuggestApiUrl();
        const formData = new FormData();
        formData.append("action", "initSuggestSortNo");
        if (sf_ID) {
            formData.append("sf_ID", sf_ID);
        }
        if (title) {
            formData.append("title", title);
        }
        
        const response = await fetch(apiUrl, {
            method: "POST",
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            console.log(`已初始化 suggest_sort_no:`, result);
            return result;
        } else {
            console.error(`初始化失敗:`, result.msg);
            return null;
        }
    } catch (error) {
        console.error("初始化 suggest_sort_no 時發生錯誤:", error);
        return null;
    }
}

/* ==========================================
   初始化所有舊資料的 suggest_sort_no
========================================== */
async function initAllSuggestSortNo() {
    try {
        const apiUrl = resolveSuggestApiUrl();
        const formData = new FormData();
        formData.append("action", "initAllSuggestSortNo");
        
        const response = await fetch(apiUrl, {
            method: "POST",
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            console.log(`已初始化所有 suggest_sort_no:`, result);
            Toast.fire({
                icon: "success",
                title: "初始化完成",
                text: `已初始化 ${result.updated_count} 筆記錄（共處理 ${result.processed_sf_ids} 個標題）`
            });
            return result;
        } else {
            console.error(`初始化失敗:`, result.msg);
            Toast.fire({
                icon: "error",
                title: "初始化失敗",
                text: result.msg || "請稍後再試"
            });
            return null;
        }
    } catch (error) {
        console.error("初始化所有 suggest_sort_no 時發生錯誤:", error);
        Toast.fire({
            icon: "error",
            title: "初始化失敗",
            text: error.message || "請稍後再試"
        });
        return null;
    }
}

/* ==========================================
   2-2-1-4-1. 載入團隊並顯示簡化版（只顯示名稱和審查結果）- 用於篩選模式
========================================== */
async function loadTeamsWithTitleForFilter(cohortId, groupId, title, selectedStatus = '') {
    const { leftCol, rightCol } = ensureSuggestColumnsStructure();
    
    if (!leftCol || !rightCol) {
        console.error("無法創建左右欄元素（篩選模式）");
        return;
    }
    
    leftCol.innerHTML = "";
    rightCol.innerHTML = "";

    // 允許 groupId 為 "all" 或有效的類組ID
    if (!cohortId || (!groupId && groupId !== "all")) {
        const hint = document.getElementById("sg-team-list-hint");
        if (hint) hint.style.display = "none";
        return;
    }

    try {
        const apiUrl = resolveSuggestApiUrl();
        // 如果 groupId 為 "all"，傳遞 "all" 給 API；否則傳遞實際的 groupId
        const groupIdParam = groupId === "all" ? "all" : groupId;
        
        // 檢查是否從 integrate 建立
        const urlParams = getUrlParams();
        const fromIntegrate = urlParams.get('from_integrate') === '1';
        
        // 構建 API URL
        let apiUrlWithParams = `${apiUrl}?action=listTeams&cohort_ID=${cohortId}&group_ID=${groupIdParam}`;
        if (fromIntegrate) {
            apiUrlWithParams += '&from_integrate=1';
        }
        
        const r = await fetch(apiUrlWithParams);
        const j = await r.json();

        if (!j.success) throw j.msg;

        if (!j.data || j.data.length === 0) {
            leftCol.innerHTML = "<p>該屆別和類組沒有團隊</p>";
            const hint = document.getElementById("sg-team-list-hint");
            if (hint) hint.style.display = "none";
            return;
        }
        
        // 隱藏提示（因為這是篩選模式，不需要拖放提示）
        const hint = document.getElementById("sg-team-list-hint");
        if (hint) hint.style.display = "none";

        // 載入每個團隊的建議和審查結果
        const teamsWithStatus = [];
        
        for (const team of j.data) {
            const teamId = team.team_ID;
            
            // 取得該團隊該標題的建議
            const suggestR = await fetch(`${apiUrl}?action=listSuggests&team_ID=${teamId}`);
            const suggestJ = await suggestR.json();
            
            let suggestStatus = null;
            if (suggestJ.success && suggestJ.data && suggestJ.data.length > 0) {
                // 找到符合該標題的建議（檢查 suggest_name 或 sf_name）
                const matchingSuggest = suggestJ.data.find(s => {
                    const suggestTitle = s.suggest_name || s.sf_name || '';
                    return suggestTitle.trim() === title.trim();
                });
                
                if (matchingSuggest) {
                    suggestStatus = matchingSuggest.suggest_status;
                }
            }
            
            // 確保團隊資料包含必要的屬性
            teamsWithStatus.push({
                team_ID: team.team_ID,
                team_project_name: team.team_project_name,
                group_name: team.group_name || '',
                suggest_status: suggestStatus
            });
        }
        
        // 如果有選擇審查結果，排序：符合的在前，其他的在後
        let sortedTeams = [...teamsWithStatus];
        if (selectedStatus) {
            sortedTeams.sort((a, b) => {
                const aMatch = a.suggest_status == selectedStatus;
                const bMatch = b.suggest_status == selectedStatus;
                if (aMatch && !bMatch) return -1;
                if (!aMatch && bMatch) return 1;
                return 0;
            });
        }
        
        // 分配到左右兩欄
        sortedTeams.forEach((team, index) => {
            const cardHtml = createSimpleTeamCard(team, team.suggest_status);
            if (index % 2 === 0) {
                leftCol.innerHTML += cardHtml;
            } else {
                rightCol.innerHTML += cardHtml;
            }
        });
        
        // 綁定卡片點擊事件
        bindFilterCardClickEvents();
        
        // 如果有選擇審查結果，自動選取符合條件的團隊卡片
        if (selectedStatus) {
            // 先清除所有選取狀態
            const allCards = document.querySelectorAll('.sg-team-card-filter');
            allCards.forEach(card => {
                card.classList.remove('selected');
            });
            
            // 然後選取符合條件的團隊卡片
            allCards.forEach(card => {
                const teamDataStr = card.getAttribute('data-team-data');
                if (teamDataStr) {
                    try {
                        const teamData = JSON.parse(teamDataStr);
                        // 如果團隊的審查結果符合選擇的狀態，自動選取
                        if (teamData.suggest_status == selectedStatus) {
                            card.classList.add('selected');
                        }
                    } catch (e) {
                        console.warn('解析團隊資料失敗:', e);
                    }
                }
            });
        } else {
            // 如果沒有選擇審查結果，清除所有選取狀態
            const allCards = document.querySelectorAll('.sg-team-card-filter');
            allCards.forEach(card => {
                card.classList.remove('selected');
            });
        }
        
        // 顯示或隱藏「建立建議表」按鈕
        updateCreateSuggestButton();
        
        // 顯示回到初始頁按鈕
        const backHomeBtn = document.getElementById("sg-back-home-btn");
        if (backHomeBtn) {
            backHomeBtn.style.display = 'inline-block';
        }

    } catch (err) {
        console.error("載入團隊失敗:", err);
        if (leftCol) leftCol.innerHTML = "<p>載入團隊失敗</p>";
        if (rightCol) rightCol.innerHTML = "";
    }
}

/* ==========================================
   2-2-1-4. 載入所有團隊並載入指定標題的建議
========================================== */
async function loadTeamsWithTitle(cohortId, groupId, title) {
    const container = document.getElementById("sg-team-list");
    const tbody = ensureSuggestTableStructure();
    
    if (!container || !tbody) {
        console.error("找不到 sg-team-list 或 sg-team-tbody 元素");
        return;
    }
    
    tbody.innerHTML = "";

    // 允許 groupId 為 "all" 或有效的類組ID
    if (!cohortId || (!groupId && groupId !== "all")) {
        // 隱藏提示
        const hint = document.getElementById("sg-team-list-hint");
        if (hint) hint.style.display = "none";
        return;
    }

    try {
        const apiUrl = resolveSuggestApiUrl();
        // 如果 groupId 為 "all"，傳遞 "all" 給 API；否則傳遞實際的 groupId
        const groupIdParam = groupId === "all" ? "all" : groupId;
        // 傳遞 title 參數以獲取時程表的 sort_no
        const titleParam = encodeURIComponent(title);
        
        const urlParams = getUrlParams();
        const fromIntegrate = urlParams.get('from_integrate') === '1';
        let sfIdForApi = urlParams.get('sf_ID') || '';
        if (!sfIdForApi && title) {
            try {
                const sfIdR = await fetch(`${apiUrl}?action=getSfIdByTitle&cohort_ID=${cohortId}&group_ID=${groupIdParam}&title=${titleParam}`);
                const sfIdJ = await sfIdR.json();
                if (sfIdJ.success && sfIdJ.data && sfIdJ.data.sf_ID) {
                    sfIdForApi = String(sfIdJ.data.sf_ID);
                }
            } catch (e) {
                console.warn('取得 sf_ID 失敗:', e);
            }
        }
        // 只要有 sf_ID 就取得建議表資訊，並設定「召集人＋已送交科辦」唯讀旗標
        if (sfIdForApi) {
            try {
                const infoR = await fetch(`${apiUrl}?action=getSuggestFormInfo&sf_ID=${sfIdForApi}`);
                const infoJ = await infoR.json();
                if (infoJ.success && infoJ.data) {
                    if (infoJ.data.sf_type) window.suggestFormSfType = infoJ.data.sf_type;
                    const wrapper = document.querySelector(".suggest-wrapper[data-suggest-page=\"true\"]");
                    const isConvener = wrapper && wrapper.getAttribute("data-role-id") === "7";
                    const allSent = String(infoJ.data.sf_sent_to_office || '').trim() === '1';
                    const convenerHasSent = !!infoJ.data.convener_has_sent;
                    const isInCohort = infoJ.data.is_convener_in_cohort !== false;
                    if (isConvener) {
                        window.SuggestConvenerNotInCohort = !isInCohort;
                        if (!isInCohort || allSent || convenerHasSent) {
                            window.SuggestReadOnlyForConvener = true;
                        } else {
                            window.SuggestReadOnlyForConvener = false;
                        }
                    } else {
                        window.SuggestConvenerNotInCohort = false;
                        window.SuggestReadOnlyForConvener = false;
                    }
                }
            } catch (e) {
                console.warn('getSuggestFormInfo 失敗:', e);
            }
        }
        let apiUrlWithParams = `${apiUrl}?action=listTeams&cohort_ID=${cohortId}&group_ID=${groupIdParam}&title=${titleParam}`;
        if (fromIntegrate) apiUrlWithParams += '&from_integrate=1';
        if (sfIdForApi) apiUrlWithParams += `&sf_ID=${encodeURIComponent(sfIdForApi)}`;
        
        const r = await fetch(apiUrlWithParams);
        const j = await r.json();

        if (!j.success) throw (j.msg || 'listTeams 失敗');

        if (!j.data || !Array.isArray(j.data)) {
            tbody.innerHTML = "<tr><td colspan=\"6\" class=\"sg-table-empty\"><p>無法取得團隊資料，請稍後再試</p></td></tr>";
            const hint = document.getElementById("sg-team-list-hint");
            if (hint) hint.style.display = "none";
            return;
        }

        // 載入保存的順序
        // 重要：同一張建議表（同一個 sf_ID）下，必須固定依 suggest_sort_no 由小到大排序
        // 切換「全部類組」時，不可再依 team_ID / 類組 / 建立順序重新排序，只能使用 suggest_sort_no
        let orderedTeams = [...j.data];
        
        // 如果從 integrate 建立，還需要載入那些不在時程表中但已有該標題建議的團隊
        if (fromIntegrate) {
            try {
                // 獲取該標題的 sf_ID
                const sfIdR = await fetch(`${apiUrl}?action=getSfIdByTitle&cohort_ID=${cohortId}&group_ID=${groupIdParam}&title=${titleParam}`);
                const sfIdJ = await sfIdR.json();
                
                if (sfIdJ.success && sfIdJ.data && sfIdJ.data.sf_ID) {
                    const sf_ID = sfIdJ.data.sf_ID;
                    
                    // 獲取該標題下所有有建議的團隊（包括不在時程表中的）
                    const allSuggestsR = await fetch(`${apiUrl}?action=listSuggestsBySfId&sf_ID=${sf_ID}`);
                    const allSuggestsJ = await allSuggestsR.json();
                    
                    if (allSuggestsJ.success && allSuggestsJ.data && allSuggestsJ.data.length > 0) {
                        // 獲取所有團隊的 team_ID（從時程表中已載入的）
                        const scheduleTeamIds = new Set(orderedTeams.map(t => t.team_ID));
                        
                        // 找出不在時程表中但已有建議的團隊
                        const teamsNotInSchedule = [];
                        for (const suggest of allSuggestsJ.data) {
                            const teamId = suggest.team_ID;
                            if (!scheduleTeamIds.has(teamId)) {
                                // 獲取該團隊的詳細資訊
                                const teamInfoR = await fetch(`${apiUrl}?action=getTeamInfo&team_ID=${teamId}`);
                                const teamInfoJ = await teamInfoR.json();
                                
                                if (teamInfoJ.success && teamInfoJ.data) {
                                    // 檢查是否符合當前的 group_ID 篩選條件
                                    const teamGroupId = teamInfoJ.data.group_ID;
                                    if (groupIdParam === "all" || teamGroupId == groupIdParam) {
                                        teamsNotInSchedule.push({
                                            team_ID: teamId,
                                            team_project_name: teamInfoJ.data.team_project_name || `團隊 ${teamId}`,
                                            group_ID: teamGroupId,
                                            group_name: teamInfoJ.data.group_name || '',
                                            time_sort_no: null // 不在時程表中，所以沒有 time_sort_no
                                        });
                                    }
                                }
                            }
                        }
                        
                        // 將不在時程表中的團隊加入 orderedTeams
                        if (teamsNotInSchedule.length > 0) {
                            console.log(`找到 ${teamsNotInSchedule.length} 個不在時程表中但已有建議的團隊:`, teamsNotInSchedule.map(t => t.team_ID));
                            orderedTeams = [...orderedTeams, ...teamsNotInSchedule];
                        }
                    }
                }
            } catch (err) {
                console.error("載入不在時程表中的團隊失敗:", err);
                // 如果載入失敗，繼續使用時程表中的團隊
            }
        }

        // 若有 sf_ID，只顯示「在此建議表仍有建議紀錄」的團隊（被刪除的團隊已無建議，不會再出現）
        // 例外：科辦與召集人一律顯示 listTeams 回傳的全部團隊（含尚未填寫的），未填處顯示空白；避免 A 送出後 B 看不到自己類組未填的團隊
        const wrapper = document.querySelector(".suggest-wrapper[data-suggest-page=\"true\"]");
        const isOffice = wrapper && wrapper.getAttribute("data-role-id") === "2";
        const isConvener = wrapper && wrapper.getAttribute("data-role-id") === "7";
        const skipFilterShowAllTeams = isOffice || isConvener;
        if (sfIdForApi && !skipFilterShowAllTeams) {
            try {
                const listBySfR = await fetch(`${apiUrl}?action=listSuggestsBySfId&sf_ID=${encodeURIComponent(sfIdForApi)}`);
                const listBySfJ = await listBySfR.json();
                if (listBySfJ.success && listBySfJ.data && Array.isArray(listBySfJ.data) && listBySfJ.data.length > 0) {
                    // 統一用數字比對，避免 team_ID 字串/數字導致召集人看不到團隊
                    const teamIdsWithSuggest = new Set(listBySfJ.data.map(s => Number(s.team_ID)));
                    orderedTeams = orderedTeams.filter(t => teamIdsWithSuggest.has(Number(t.team_ID)));
                }
            } catch (e) {
                console.warn("依建議表篩選團隊失敗:", e);
            }
        }

        if (orderedTeams.length === 0) {
            tbody.innerHTML = "<tr><td colspan=\"6\" class=\"sg-table-empty\"><p>該屆別和類組沒有團隊</p></td></tr>";
            const hint = document.getElementById("sg-team-list-hint");
            if (hint) hint.style.display = "none";
            return;
        }
        
        const hint = document.getElementById("sg-team-list-hint");
        if (hint) {
            hint.style.display = "block";
            if (window.SuggestConvenerNotInCohort) {
                hint.textContent = "您不在當屆召集人名單中，無法填寫。";
            } else {
                hint.textContent = "可以用滑鼠拖移表格列來調整順序；可點擊組別列展開／收合指導老師與學生建議";
            }
        }
        
        // 注意：這裡暫時保持 orderedTeams 的原始順序
        // 實際的排序會在後面根據 suggest_sort_no 進行（在獲取建議數據後）

        // 檢查每個團隊是否有該標題的建議
        // 重要：顯示所有團隊，不管是否有建議（新創建的建議表應該顯示所有團隊）
        // 保持 orderedTeams 的順序（當選擇"全部"時，已經按組和組內順序排序）
        const teamsWithSuggest = [];
        const teamsWithSuggestData = []; // 儲存團隊和對應的 suggest_ID、suggest_sort_no 和 time_sort_no
        
        console.log(`開始檢查 ${orderedTeams.length} 個團隊是否有標題 "${title}" 的建議`);
        
        if (orderedTeams.length === 0) {
            console.error(`錯誤：orderedTeams 為空！無法顯示團隊`);
            leftCol.innerHTML = "<p>該屆別和類組沒有團隊</p>";
            const hint = document.getElementById("sg-team-list-hint");
            if (hint) hint.style.display = "none";
            return;
        }
        
        for (const team of orderedTeams) {
            const teamId = team.team_ID;
            const teamGroupId = team.group_ID || team.group_id;
            
            if (!teamId) {
                console.warn(`跳過無效團隊:`, team);
                continue;
            }
            
            // 檢查該團隊是否有該標題的建議
            let matchingSuggest = null;
            try {
                const apiUrl = resolveSuggestApiUrl();
                const suggestR = await fetch(`${apiUrl}?action=listSuggests&team_ID=${teamId}`);
                const suggestJ = await suggestR.json();
                
                if (suggestJ.success && suggestJ.data && suggestJ.data.length > 0) {
                    // 檢查是否有符合該標題的建議（檢查 suggest_name 或 sf_name）
                    matchingSuggest = suggestJ.data.find(s => {
                        const suggestTitle = s.suggest_name || s.sf_name || '';
                        return suggestTitle.trim() === title.trim();
                    });
                    
                    if (matchingSuggest) {
                        console.log(`團隊 ${teamId} (組 ${teamGroupId}) 有標題 "${title}" 的建議，suggest_ID: ${matchingSuggest.suggest_ID || matchingSuggest.sf_ID || 0}, suggest_sort_no: ${matchingSuggest.suggest_sort_no || 'null'}`);
                    }
                }
            } catch (err) {
                console.error(`檢查團隊 ${teamId} 建議失敗:`, err);
            }
            
            // 獲取時程表的 sort_no（time_sort_no）
            const time_sort_no = team.time_sort_no || null;
            
            // 無論是否有建議，都加入列表（這樣新創建的建議表也能顯示所有團隊）
            teamsWithSuggestData.push({
                team: team,
                suggest_ID: matchingSuggest ? (matchingSuggest.suggest_ID || matchingSuggest.sf_ID || 0) : null,
                suggest_sort_no: matchingSuggest ? (matchingSuggest.suggest_sort_no || null) : null,
                time_sort_no: time_sort_no,
                suggest_comment: matchingSuggest ? (matchingSuggest.suggest_comment || '') : null
            });
        }
        
        console.log(`teamsWithSuggestData 已填充 ${teamsWithSuggestData.length} 個團隊`);
        
        // 重要：同一張建議表（同一個 sf_ID）下，必須固定依 suggest_sort_no 由小到大排序
        // 如果 suggest_sort_no 為空或 0，fallback 使用時程表的 time_sort_no
        // 切換「全部類組」時，不可再依 team_ID / 類組 / 建立順序重新排序，只能使用排序欄位
        
        // 使用 COALESCE(suggest_sort_no, time_sort_no) 進行排序
        console.log("使用 COALESCE(suggest_sort_no, time_sort_no) 進行排序");
        
        // 調試：輸出排序前的數據
        console.log("排序前的數據:", teamsWithSuggestData.map(item => ({
            team_ID: item.team.team_ID,
            team_name: item.team.team_project_name,
            suggest_sort_no: item.suggest_sort_no,
            time_sort_no: item.time_sort_no
        })));
        
        teamsWithSuggestData.sort((a, b) => {
            // 計算實際使用的排序值：優先使用 suggest_sort_no，如果為空或 0，則使用 time_sort_no
            const sortNoA = (a.suggest_sort_no !== null && a.suggest_sort_no !== undefined && a.suggest_sort_no > 0) 
                ? a.suggest_sort_no 
                : (a.time_sort_no !== null && a.time_sort_no !== undefined && a.time_sort_no > 0 ? a.time_sort_no : null);
            const sortNoB = (b.suggest_sort_no !== null && b.suggest_sort_no !== undefined && b.suggest_sort_no > 0) 
                ? b.suggest_sort_no 
                : (b.time_sort_no !== null && b.time_sort_no !== undefined && b.time_sort_no > 0 ? b.time_sort_no : null);
            
            // 如果兩個都有有效的排序值，按數值排序
            if (sortNoA !== null && sortNoB !== null) {
                return sortNoA - sortNoB;
            }
            // 如果只有一個有有效的排序值，有排序值的優先
            if (sortNoA !== null) return -1;
            if (sortNoB !== null) return 1;
            // 如果兩個都沒有有效的排序值，使用 team_ID 作為最後的排序依據（確保順序穩定）
            return (a.team.team_ID || 0) - (b.team.team_ID || 0);
        });
        
        // 調試：輸出排序後的數據
        console.log("排序後的數據:", teamsWithSuggestData.map(item => {
            const finalSortNo = (item.suggest_sort_no !== null && item.suggest_sort_no !== undefined && item.suggest_sort_no > 0) 
                ? item.suggest_sort_no 
                : (item.time_sort_no !== null && item.time_sort_no !== undefined && item.time_sort_no > 0 ? item.time_sort_no : null);
            return {
                team_ID: item.team.team_ID,
                team_name: item.team.team_project_name,
                suggest_sort_no: item.suggest_sort_no,
                time_sort_no: item.time_sort_no,
                final_sort_no: finalSortNo
            };
        }));
        
        // 提取團隊
        teamsWithSuggestData.forEach(item => {
            teamsWithSuggest.push(item.team);
        });
        
        console.log(`過濾後共有 ${teamsWithSuggest.length} 個團隊，teamsWithSuggestData 長度: ${teamsWithSuggestData.length}，orderedTeams 長度: ${orderedTeams.length}`);
        console.log(`團隊列表:`, teamsWithSuggest.map((t, idx) => {
            const data = teamsWithSuggestData[idx];
            const finalSortNo = (data?.suggest_sort_no !== null && data?.suggest_sort_no !== undefined && data?.suggest_sort_no > 0) 
                ? data.suggest_sort_no 
                : (data?.time_sort_no !== null && data?.time_sort_no !== undefined && data?.time_sort_no > 0 ? data.time_sort_no : null);
            return { 
                team_ID: t.team_ID, 
                group_ID: t.group_ID || t.group_id,
                team_name: t.team_project_name || t.team_name || '',
                suggest_ID: data?.suggest_ID || 'N/A',
                suggest_sort_no: data?.suggest_sort_no || 'N/A',
                time_sort_no: data?.time_sort_no || 'N/A',
                final_sort_no: finalSortNo || 'N/A'
            };
        }));
        
        if (teamsWithSuggest.length === 0) {
            console.error(`錯誤：teamsWithSuggest 為空！orderedTeams 長度: ${orderedTeams.length}, teamsWithSuggestData 長度: ${teamsWithSuggestData.length}`);
            tbody.innerHTML = "<tr><td colspan=\"6\" class=\"sg-table-empty\"><p>該標題沒有團隊建議</p></td></tr>";
            const hint = document.getElementById("sg-team-list-hint");
            if (hint) hint.style.display = "none";
            return;
        }
        
        // 檢查是否為第一次編輯（沒有建議資料）
        // 檢查該標題下是否有任何團隊有非空的建議內容
        let hasAnySuggest = false;
        for (const item of teamsWithSuggestData) {
            if (item.suggest_ID !== null && item.suggest_comment !== null && item.suggest_comment.trim() !== '') {
                hasAnySuggest = true;
                console.log(`找到有建議內容的團隊: team_ID=${item.team.team_ID}, suggest_ID=${item.suggest_ID}, comment_length=${item.suggest_comment.length}`);
                break;
            }
        }
        
        // 檢查是否從 integrate 建立（fromIntegrate 已在函數開頭聲明，第1912行，這裡直接使用）
        // 用戶需求：建議為空也要有刪除團隊的按鈕
        // 如果沒有任何建議內容，就顯示刪除按鈕（第一次編輯時）
        const showDeleteButton = !hasAnySuggest;
        
        console.log(`判斷是否顯示刪除按鈕: hasAnySuggest=${hasAnySuggest}, fromIntegrate=${fromIntegrate}, showDeleteButton=${showDeleteButton}, 團隊數=${teamsWithSuggestData.length}`);
        console.log(`teamsWithSuggestData 詳情:`, teamsWithSuggestData.map(item => ({
            team_ID: item.team.team_ID,
            suggest_ID: item.suggest_ID,
            has_comment: item.suggest_comment !== null && item.suggest_comment.trim() !== ''
        })));
        
        const filteredTeams = teamsWithSuggest.filter(team => !deletedTeams.has(team.team_ID));
        
        filteredTeams.forEach((team) => {
            tbody.insertAdjacentHTML("beforeend", createTeamTableRow(team, showDeleteButton));
        });

        bindTeamTableRowEvents();
        initDragAndDrop(cohortId, groupId);
        updateTeamNumbers();
        bindAutoNumberEvent();
        bindStatusSelectEvent();

        // 載入每個團隊該標題的建議（載入到 textarea）
        // 有標題時一律呼叫 loadTeamSuggest，並傳入 title 確保用正確標題比對、能看到之前填寫的內容
        if (title && title.trim() !== '') {
            await Promise.all(filteredTeams.map(team => loadTeamSuggest(team.team_ID, title)));
        } else {
            // 無標題時，確保所有團隊都是可編輯狀態
            filteredTeams.forEach(team => {
                const area = document.getElementById(`sg-textarea-${team.team_ID}`);
                const statusSelect = document.getElementById(`sg-status-${team.team_ID}`);
                if (area) {
                    area.value = "";
                    area.readOnly = false;
                    area.classList.remove('sg-textarea-readonly');
                }
                if (statusSelect) {
                    statusSelect.value = "";
                    updateStatusSelectStyle(statusSelect);
                }
                setEditableMode(team.team_ID);
            });
        }
        // 召集人＋已送交科辦：整表唯讀，不可編輯團隊建議與審查結果
        if (window.SuggestReadOnlyForConvener) {
            container.querySelectorAll('.sg-textarea').forEach(ta => {
                ta.readOnly = true;
                ta.classList.add('sg-textarea-readonly');
            });
            container.querySelectorAll('.sg-team-status-select').forEach(s => {
                s.disabled = true;
            });
            const hint = document.getElementById("sg-team-list-hint");
            if (hint) hint.style.display = "none";
            if (typeof updateActionButtonsState === 'function') updateActionButtonsState();
        }
        
        // 隱藏新增建議表按鈕，顯示編輯、匯出和回到初始頁按鈕
        // 初始狀態下隱藏存檔按鈕（只有在編輯模式下才顯示）
        const newSuggestBtn = document.getElementById("sg-new-suggest-btn");
        const editAllBtn = document.getElementById("sg-edit-all-btn");
        const saveBtn = document.getElementById("sg-save-btn");
        const addTeamBtn = document.getElementById("sg-add-team-btn");
        const exportBtn = document.getElementById("sg-export-btn");
        const exportWordBtn = document.getElementById("sg-export-word-btn");
        const backHomeBtn = document.getElementById("sg-back-home-btn");
        if (newSuggestBtn) newSuggestBtn.style.display = 'none';
        if (window.SuggestReadOnlyForConvener) {
            if (editAllBtn) { editAllBtn.style.display = 'none'; editAllBtn.disabled = true; }
            if (saveBtn) { saveBtn.style.display = 'none'; saveBtn.disabled = true; }
            if (addTeamBtn) { addTeamBtn.style.display = 'none'; addTeamBtn.disabled = true; }
        } else {
        const isAlreadyInEditMode = saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
        if (editAllBtn) {
            if (isAlreadyInEditMode) {
                editAllBtn.style.display = 'none';
            } else {
                editAllBtn.disabled = false;
                editAllBtn.style.display = 'inline-block';
            }
        }
        // 初始狀態下隱藏存檔和加入團隊按鈕，只有在點擊編輯按鈕後才顯示
        // 檢查是否已經處於編輯模式（存檔按鈕顯示且啟用）
        if (saveBtn) {
            if (!isAlreadyInEditMode) {
                saveBtn.disabled = true;
                saveBtn.style.display = 'none';
            } else {
                saveBtn.removeAttribute('disabled');
                saveBtn.disabled = false;
                saveBtn.style.display = 'inline-block';
            }
        }
        // 加入團隊按鈕：只在編輯模式下顯示（存檔按鈕顯示時才顯示），且僅召集人可新增建議
        if (addTeamBtn) {
            const titleInput = document.getElementById("sg-title");
            const hasTitle = titleInput && titleInput.value.trim() !== '';
            const isInEditMode = saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
            if (hasTitle && isInEditMode && window.isSuggestConvener) {
                addTeamBtn.disabled = false;
                addTeamBtn.style.display = 'inline-block';
            } else {
                addTeamBtn.disabled = true;
                addTeamBtn.style.display = 'none';
            }
        }
        }
        if (exportBtn) {
            exportBtn.style.display = 'inline-block';
            exportBtn.disabled = false;
        }
        if (exportWordBtn) {
            exportWordBtn.style.display = 'inline-block';
            exportWordBtn.disabled = false;
        }
        if (backHomeBtn) backHomeBtn.style.display = 'inline-block';
        
        // 從 integrate 進入且為「新建立」（尚無建議）時：顯示存檔與加入團隊，隱藏編輯（僅召集人可看到加入團隊）
        // 讓操作區可立即填寫；存檔後 hasAnySuggest 為 true，會保持唯讀
        const urlParamsFinal = getUrlParams();
        if (urlParamsFinal.get('from_integrate') === '1' && !hasAnySuggest) {
            if (saveBtn) {
                saveBtn.removeAttribute('disabled');
                saveBtn.disabled = false;
                saveBtn.style.display = 'inline-block';
            }
            if (addTeamBtn) {
                if (window.isSuggestConvener) {
                    addTeamBtn.removeAttribute('disabled');
                    addTeamBtn.disabled = false;
                    addTeamBtn.style.display = 'inline-block';
                } else {
                    addTeamBtn.disabled = true;
                    addTeamBtn.style.display = 'none';
                }
            }
            if (editAllBtn) {
                editAllBtn.style.display = 'none';
            }
            // 此時加入團隊已顯示，需更新操作區按鈕（評分、刪除）為可用
            if (typeof updateActionButtonsState === 'function') {
                updateActionButtonsState();
            }
        }

    } catch (err) {
        console.error("載入團隊失敗:", err);
        if (tbody) tbody.innerHTML = "<tr><td colspan=\"6\" class=\"sg-table-empty\"><p>載入團隊失敗</p></td></tr>";
    }
}

/* ==========================================
   2-2-1-1. 清空團隊列表
========================================== */
function clearTeamList() {
    const tbody = document.getElementById("sg-team-tbody");
    const hint = document.getElementById("sg-team-list-hint");
    
    if (tbody) tbody.innerHTML = "";
    if (hint) hint.style.display = "none";
}

/* ==========================================
   2-2-2. 根據標題和狀態過濾團隊
========================================== */
// 使用條件聲明避免重複聲明錯誤（如果腳本被多次加載）
if (typeof window.SuggestAllTeamsData === 'undefined') {
    window.SuggestAllTeamsData = []; // 儲存所有團隊資料
}
var allTeamsData = window.SuggestAllTeamsData;

// 記錄被刪除的團隊ID（用於第一次編輯時）
if (typeof window.SuggestDeletedTeams === 'undefined') {
    window.SuggestDeletedTeams = new Set(); // 使用 Set 來避免重複
}
var deletedTeams = window.SuggestDeletedTeams;

// 記錄是否已經按下「統一編輯」進入編輯模式
if (typeof window.SuggestEditAllMode === 'undefined') {
    window.SuggestEditAllMode = false;
}

async function filterTeamsByTitleAndStatus() {
    const cohortSelect = document.getElementById("sg-cohort");
    const groupSelect = document.getElementById("sg-group");
    const titleSelect = document.getElementById("sg-title-select");
    const statusSelect = document.getElementById("sg-status");
    
    if (!cohortSelect || !groupSelect) return;
    
    const cohortId = cohortSelect.value;
    const groupId = groupSelect.value;
    // 只使用 titleSelect 的值（已存在的標題），不使用 titleInput（手動輸入的標題）
    const selectedTitle = titleSelect ? titleSelect.value : '';
    const selectedStatus = statusSelect ? statusSelect.value : '';
    
    if (!cohortId || !groupId) return;
    
    // 如果沒有選擇已存在的標題，不載入任何資料（因為這是篩選框，需要用戶主動選擇）
    if (!selectedTitle) {
        // 清空團隊列表，等待用戶選擇標題
        clearTeamList();
        return;
    }
    
    try {
        const apiUrl = resolveSuggestApiUrl();
        const r = await fetch(`${apiUrl}?action=listTeams&cohort_ID=${cohortId}&group_ID=${groupId}`);
        const j = await r.json();

        if (!j.success) throw j.msg;

        // 過濾團隊：只顯示有該標題的建議的團隊
        let filteredTeams = [];
        
        for (const team of j.data) {
            const teamId = team.team_ID;
            
            // 取得該團隊的建議
            const suggestR = await fetch(`${apiUrl}?action=listSuggests&team_ID=${teamId}`);
            const suggestJ = await suggestR.json();
            
            if (suggestJ.success && suggestJ.data && suggestJ.data.length > 0) {
                // 檢查是否有符合標題和狀態的建議
                const matchingSuggests = suggestJ.data.filter(s => {
                    const titleMatch = s.suggest_name && s.suggest_name.trim() === selectedTitle;
                    const statusMatch = !selectedStatus || s.suggest_status == selectedStatus;
                    return titleMatch && statusMatch;
                });
                
                if (matchingSuggests.length > 0) {
                    filteredTeams.push(team);
                }
            }
        }
        
        // 顯示過濾後的團隊
        await displayFilteredTeams(filteredTeams, cohortId, groupId);
        
    } catch (err) {
        console.error("過濾團隊失敗:", err);
    }
}

/* ==========================================
   2-2-3. 顯示過濾後的團隊
========================================== */
async function displayFilteredTeams(teams, cohortId, groupId) {
    const container = document.getElementById("sg-team-list");
    const leftColumn = document.getElementById("sg-team-left");
    const rightColumn = document.getElementById("sg-team-right");
    
    if (!container || !leftColumn || !rightColumn) return;
    
    // 清空兩欄
    leftColumn.innerHTML = "";
    rightColumn.innerHTML = "";

    if (teams.length === 0) {
        leftColumn.innerHTML = "<p>沒有符合條件的團隊</p>";
        const hint = document.getElementById("sg-team-list-hint");
        if (hint) hint.style.display = "none";
        return;
    }
    
    // 顯示提示
    const hint = document.getElementById("sg-team-list-hint");
    if (hint) hint.style.display = "block";

    // 載入保存的順序
    let orderedTeams = [...teams];
    
    if (groupId === "all") {
        // 當選擇"全部"時，需要按照每個組各自的排序順序進行排序
        // 重要：不要重新依類組、team_id、或 sort_no 排序
        // 應該要依畫面上「各類組顯示的先後順序」直接串接
        // 先按 group_ID 分組，同時記錄組在 API 返回數據中的出現順序
        const teamsByGroup = {};
        const teamsWithoutGroup = []; // 儲存沒有 group_ID 的團隊
        const groupOrder = []; // 記錄組的出現順序（按照 API 返回的順序）
        
        orderedTeams.forEach(team => {
            const gId = team.group_ID || team.group_id;
            // 確保 gId 存在且有效
            if (gId !== null && gId !== undefined && gId !== '') {
                if (!teamsByGroup[gId]) {
                    teamsByGroup[gId] = [];
                    // 記錄組的出現順序（按照 API 返回的順序）
                    groupOrder.push(gId);
                }
                teamsByGroup[gId].push(team);
            } else {
                // 將沒有 group_ID 的團隊也加入處理
                teamsWithoutGroup.push(team);
                console.warn('團隊缺少 group_ID:', team);
            }
        });
        
        // 使用 groupOrder 來保持組的出現順序（按照 API 返回的順序）
        // 不要按照 group_ID 數字排序
        console.log(`全部類組模式：找到 ${groupOrder.length} 個組，組出現順序:`, groupOrder);
        
        // 對每個組內的團隊按照各自的排序順序排序
        const sortedTeams = [];
        groupOrder.forEach(gId => {
            const groupTeams = teamsByGroup[gId];
            // 確保 groupTeams 存在且是數組
            if (!groupTeams || !Array.isArray(groupTeams) || groupTeams.length === 0) {
                console.warn(`組 ${gId} 沒有團隊數據`);
                return;
            }
            
            // 載入該組的排序順序
            const groupOrderKey = `suggest_team_order_${cohortId}_${gId}`;
            const groupSavedOrder = localStorage.getItem(groupOrderKey);
            
            if (groupSavedOrder) {
                try {
                    const orderArray = JSON.parse(groupSavedOrder);
                    const numericOrderArray = orderArray.map(id => parseInt(id));
                    console.log(`組 ${gId} 載入保存的順序:`, numericOrderArray);
                    console.log(`組 ${gId} 原始團隊ID:`, groupTeams.map(t => t.team_ID));
                    
                    // 重要：完全按照保存的順序重新排列，而不是只排序
                    // 這樣可以確保順序完全一致，即使有新團隊也會按照保存的順序插入
                    const orderedGroupTeams = [];
                    const teamMap = new Map();
                    
                    // 先建立團隊ID到團隊對象的映射
                    groupTeams.forEach(team => {
                        teamMap.set(parseInt(team.team_ID), team);
                    });
                    
                    // 按照保存的順序添加團隊
                    numericOrderArray.forEach(teamId => {
                        const team = teamMap.get(teamId);
                        if (team) {
                            orderedGroupTeams.push(team);
                            teamMap.delete(teamId); // 從map中移除，避免重複
                        }
                    });
                    
                    // 將沒有在保存順序中的新團隊添加到最後
                    teamMap.forEach(team => {
                        orderedGroupTeams.push(team);
                    });
                    
                    // 使用重新排列後的順序
                    groupTeams.length = 0;
                    groupTeams.push(...orderedGroupTeams);
                    
                    console.log(`組 ${gId} 已按保存順序重新排列，最終順序:`, groupTeams.map(t => t.team_ID));
                } catch (e) {
                    console.warn(`載入組 ${gId} 順序失敗:`, e);
                }
            } else {
                console.log(`沒有找到組 ${gId} 的保存順序`);
            }
            
            // 將該組的團隊添加到最終列表（按照組的出現順序）
            sortedTeams.push(...groupTeams);
        });
        
        // 將沒有 group_ID 的團隊添加到最後
        if (teamsWithoutGroup.length > 0) {
            sortedTeams.push(...teamsWithoutGroup);
        }
        
        orderedTeams = sortedTeams;
        console.log(`全部類組排序後的團隊順序 (共 ${orderedTeams.length} 個團隊):`, orderedTeams.map(t => ({ team_ID: t.team_ID, group_ID: t.group_ID || t.group_id })));
    } else {
        // 單個類組，使用原有的排序邏輯
        const orderKey = `suggest_team_order_${cohortId}_${groupId}`;
        const savedOrder = localStorage.getItem(orderKey);
        
        if (savedOrder) {
            try {
                const orderArray = JSON.parse(savedOrder);
                orderedTeams.sort((a, b) => {
                    const indexA = orderArray.indexOf(a.team_ID);
                    const indexB = orderArray.indexOf(b.team_ID);
                    if (indexA === -1) return 1;
                    if (indexB === -1) return -1;
                    return indexA - indexB;
                });
            } catch (e) {
                console.warn('載入順序失敗:', e);
            }
        }
    }

    // 分配到左右兩欄
    orderedTeams.forEach((team, index) => {
        const cardHtml = createTeamCard(team);
        if (index % 2 === 0) {
            leftColumn.innerHTML += cardHtml;
        } else {
            rightColumn.innerHTML += cardHtml;
        }
    });

    // 初始化拖放功能
    initDragAndDrop(cohortId, groupId);

    // 更新團隊編號
    updateTeamNumbers();

    // 對每個 textarea 綁定自動編號事件
    bindAutoNumberEvent();
    
    // 對每個審查結果下拉選單綁定 change 事件
    bindStatusSelectEvent();

    // 載入每個團隊既有建議（載入到 textarea），await 確保全部設為唯讀後再結束
    await Promise.all(orderedTeams.map(team => loadTeamSuggest(team.team_ID)));
}

/* ==========================================
   2-3. 取得類組 (groupdata) - 依屆別
========================================== */
async function loadGroups(cohortId) {
    const groupSelect = document.getElementById("sg-group");
    if (!groupSelect) return;
    
    try {
        groupSelect.innerHTML = '<option value="">載入中...</option>';
        groupSelect.disabled = true;
        
        const apiUrl = resolveSuggestApiUrl();
        const r = await fetch(`${apiUrl}?action=listGroups&cohort_ID=${cohortId}`, {
            credentials: 'same-origin'
        });
        
        if (!r.ok) {
            throw new Error(`HTTP ${r.status}: ${r.statusText}`);
        }
        
        const j = await r.json();
        
        if (!j.success) {
            throw new Error(j.msg || '未知錯誤');
        }
        
        // 召集人不顯示「全部類組」，只顯示其可看到的類組（系統組或商管組）
        const isConvener = document.querySelector(".suggest-wrapper[data-suggest-page=\"true\"]")?.getAttribute("data-role-id") === "7";
        if (isConvener) {
            groupSelect.innerHTML = '';
        } else {
            groupSelect.innerHTML = '<option value="all">全部類組</option>';
        }
        
        if (j.data && Array.isArray(j.data) && j.data.length > 0) {
            j.data.forEach(g => {
                groupSelect.innerHTML += `
                    <option value="${g.group_ID}">
                        ${g.group_name}
                    </option>`;
            });
            groupSelect.disabled = false;
            // 有類組選項時觸發 change，讓團隊列表會載入（避免只選屆別、未手動點類組時團隊不出現）
            const cohortSelect = document.getElementById("sg-cohort");
            if (cohortSelect && cohortSelect.value && groupSelect.value) {
                setTimeout(function () {
                    groupSelect.dispatchEvent(new Event("change", { bubbles: true }));
                }, 0);
            }
        } else {
            groupSelect.innerHTML = '<option value="">該屆別無類組資料</option>';
            groupSelect.disabled = true;
        }
        
        // 只清空團隊表格內容，保留表格結構，避免下方團隊建議不顯示
        const teamListContainer = document.getElementById("sg-team-list");
        if (teamListContainer) {
            const tbody = document.getElementById("sg-team-tbody");
            if (tbody) {
                tbody.innerHTML = "";
            } else {
                teamListContainer.innerHTML = "";
            }
        }
        
    } catch (err) {
        console.error('載入類組失敗:', err);
        groupSelect.innerHTML = '<option value="">載入失敗</option>';
        groupSelect.disabled = true;
        Toast.fire({ 
            icon: "error", 
            title: "類組載入失敗",
            text: err.message || '請檢查網路連線'
        });
    }
}

/* ==========================================
   3. 取得團隊列表 (teamdata + groupdata)
   @param {boolean} loadExistingSuggests - 是否載入已存在的建議（預設為 true）
========================================== */
async function loadTeams(cohortId, groupId, loadExistingSuggests = true) {
    const container = document.getElementById("sg-team-list");
    const tbody = ensureSuggestTableStructure();
    
    if (!container || !tbody) {
        console.error("找不到 sg-team-list 或 sg-team-tbody 元素");
        return;
    }
    
    // 清空表格列
    tbody.innerHTML = "";

    // 允許 groupId 為 "all" 或有效的類組ID
    if (!cohortId || (!groupId && groupId !== "all")) {
        // 隱藏提示
        const hint = document.getElementById("sg-team-list-hint");
        if (hint) hint.style.display = "none";
        return;
    }

    try {
        const apiUrl = resolveSuggestApiUrl();
        // 如果 groupId 為 "all"，傳遞 "all" 給 API；否則傳遞實際的 groupId
        const groupIdParam = groupId === "all" ? "all" : groupId;
        
        // 檢查是否從 integrate 建立
        const urlParams = getUrlParams();
        const fromIntegrate = urlParams.get('from_integrate') === '1';
        
        // 構建 API URL
        let apiUrlWithParams = `${apiUrl}?action=listTeams&cohort_ID=${cohortId}&group_ID=${groupIdParam}`;
        if (fromIntegrate) {
            apiUrlWithParams += '&from_integrate=1';
        }
        
        const r = await fetch(apiUrlWithParams);
        const j = await r.json();

        if (!j.success) throw j.msg;

        if (j.data.length === 0) {
            // 根據是否選擇"全部"顯示不同的消息
            const message = groupId === "all" ? "<p>該屆別沒有團隊</p>" : "<p>該屆別和類組沒有團隊</p>";
            tbody.innerHTML = "<tr><td colspan=\"6\" class=\"sg-table-empty\">" + message + "</td></tr>";
            const hint = document.getElementById("sg-team-list-hint");
            if (hint) hint.style.display = "none";
            return;
        }
        
        const hint = document.getElementById("sg-team-list-hint");
        if (hint) hint.style.display = "block";

        // 載入保存的順序
        let orderedTeams = [...j.data];
        
        if (groupId === "all") {
            // 當選擇"全部"時，需要按照每個組各自的排序順序進行排序
            // 重要：不要重新依類組、team_id、或 sort_no 排序
            // 應該要依畫面上「各類組顯示的先後順序」直接串接
            // 先按 group_ID 分組，同時記錄組在 API 返回數據中的出現順序
            const teamsByGroup = {};
            const teamsWithoutGroup = []; // 儲存沒有 group_ID 的團隊
            const groupOrder = []; // 記錄組的出現順序（按照 API 返回的順序）
            
            orderedTeams.forEach(team => {
                const gId = team.group_ID || team.group_id;
                // 確保 gId 存在且有效
                if (gId !== null && gId !== undefined && gId !== '') {
                    if (!teamsByGroup[gId]) {
                        teamsByGroup[gId] = [];
                        // 記錄組的出現順序（按照 API 返回的順序）
                        groupOrder.push(gId);
                    }
                    teamsByGroup[gId].push(team);
                } else {
                    // 將沒有 group_ID 的團隊也加入處理
                    teamsWithoutGroup.push(team);
                    console.warn('團隊缺少 group_ID:', team);
                }
            });
            
            // 使用 groupOrder 來保持組的出現順序（按照 API 返回的順序）
            // 不要按照 group_ID 數字排序
            console.log(`全部類組模式：找到 ${groupOrder.length} 個組，組出現順序:`, groupOrder);
            
            // 對每個組內的團隊按照各自的排序順序排序
            const sortedTeams = [];
            groupOrder.forEach(gId => {
                const groupTeams = teamsByGroup[gId];
                // 確保 groupTeams 存在且是數組
                if (!groupTeams || !Array.isArray(groupTeams) || groupTeams.length === 0) {
                    console.warn(`組 ${gId} 沒有團隊數據`);
                    return;
                }
                
                console.log(`處理組 ${gId}，原始團隊數: ${groupTeams.length}`);
                
                // 載入該組的排序順序
                const groupOrderKey = `suggest_team_order_${cohortId}_${gId}`;
                const groupSavedOrder = localStorage.getItem(groupOrderKey);
                
                if (groupSavedOrder) {
                    try {
                        const orderArray = JSON.parse(groupSavedOrder);
                        const numericOrderArray = orderArray.map(id => parseInt(id));
                        console.log(`組 ${gId} 載入保存的順序:`, numericOrderArray);
                        console.log(`組 ${gId} 原始團隊ID:`, groupTeams.map(t => t.team_ID));
                        
                        // 重要：完全按照保存的順序重新排列，而不是只排序
                        // 這樣可以確保順序完全一致，即使有新團隊也會按照保存的順序插入
                        const orderedGroupTeams = [];
                        const teamMap = new Map();
                        
                        // 先建立團隊ID到團隊對象的映射
                        groupTeams.forEach(team => {
                            teamMap.set(parseInt(team.team_ID), team);
                        });
                        
                        // 按照保存的順序添加團隊
                        numericOrderArray.forEach(teamId => {
                            const team = teamMap.get(teamId);
                            if (team) {
                                orderedGroupTeams.push(team);
                                teamMap.delete(teamId); // 從map中移除，避免重複
                            }
                        });
                        
                        // 將沒有在保存順序中的新團隊添加到最後
                        teamMap.forEach(team => {
                            orderedGroupTeams.push(team);
                        });
                        
                        // 使用重新排列後的順序
                        groupTeams.length = 0;
                        groupTeams.push(...orderedGroupTeams);
                        
                        console.log(`組 ${gId} 已按保存順序重新排列，最終順序:`, groupTeams.map(t => t.team_ID));
                    } catch (e) {
                        console.warn(`載入組 ${gId} 順序失敗:`, e);
                        console.log(`組 ${gId} 使用原始順序，團隊ID:`, groupTeams.map(t => t.team_ID));
                    }
                } else {
                    console.log(`組 ${gId} 沒有保存的順序，使用原始順序，團隊ID:`, groupTeams.map(t => t.team_ID));
                }
                
                // 將該組的團隊添加到最終列表（按照組的出現順序）
                sortedTeams.push(...groupTeams);
                console.log(`組 ${gId} 已添加到最終列表，當前總數: ${sortedTeams.length}`);
            });
            
            // 將沒有 group_ID 的團隊添加到最後
            if (teamsWithoutGroup.length > 0) {
                sortedTeams.push(...teamsWithoutGroup);
            }
            
            orderedTeams = sortedTeams;
            console.log(`全部類組排序後的團隊順序 (共 ${orderedTeams.length} 個團隊):`, orderedTeams.map(t => ({ team_ID: t.team_ID, group_ID: t.group_ID || t.group_id })));
        } else {
            // 單個類組，使用原有的排序邏輯
            const orderKey = `suggest_team_order_${cohortId}_${groupId}`;
            const savedOrder = localStorage.getItem(orderKey);
            
            if (savedOrder) {
                try {
                    const orderArray = JSON.parse(savedOrder);
                    console.log(`載入團隊順序 (${cohortId}_${groupId}):`, orderArray);
                    // 確保 orderArray 中的值都是數字
                    const numericOrderArray = orderArray.map(id => parseInt(id));
                    // 按照保存的順序排序
                    orderedTeams.sort((a, b) => {
                        // 確保 team_ID 是數字類型
                        const teamIdA = parseInt(a.team_ID);
                        const teamIdB = parseInt(b.team_ID);
                        const indexA = numericOrderArray.indexOf(teamIdA);
                        const indexB = numericOrderArray.indexOf(teamIdB);
                        // 如果找不到（新團隊），放在最後
                        if (indexA === -1) return 1;
                        if (indexB === -1) return -1;
                        return indexA - indexB;
                    });
                    console.log(`排序後的團隊順序:`, orderedTeams.map(t => parseInt(t.team_ID)));
                } catch (e) {
                    console.warn('載入順序失敗:', e);
                }
            } else {
                console.log(`沒有找到保存的順序 (${cohortId}_${groupId})`);
            }
        }

        // 過濾掉被刪除的團隊
        const filteredTeams = orderedTeams.filter(team => !deletedTeams.has(team.team_ID));
        
        const showDeleteButton = !loadExistingSuggests;
        
        filteredTeams.forEach((team) => {
            tbody.insertAdjacentHTML("beforeend", createTeamTableRow(team, showDeleteButton));
        });

        bindTeamTableRowEvents();
        initDragAndDrop(cohortId, groupId);
        updateTeamNumbers();
        bindAutoNumberEvent();
        bindStatusSelectEvent();

        if (loadExistingSuggests) {
            await Promise.all(filteredTeams.map(team => loadTeamSuggest(team.team_ID)));
        } else {
            orderedTeams.forEach(team => {
                const area = document.getElementById(`sg-textarea-${team.team_ID}`);
                const statusSelect = document.getElementById(`sg-status-${team.team_ID}`);
                if (area) {
                    area.value = "";
                    area.readOnly = false;
                    area.classList.remove('sg-textarea-readonly');
                }
                if (statusSelect) {
                    statusSelect.value = "";
                    updateStatusSelectStyle(statusSelect);
                }
                setEditableMode(team.team_ID);
            });
        }

    } catch (err) {
        console.error("載入團隊失敗:", err);
        if (tbody) tbody.innerHTML = "<tr><td colspan=\"6\" class=\"sg-table-empty\"><p>載入團隊失敗</p></td></tr>";
    }
}

/* ==========================================
   3-1. 確保團隊列表為表格結構（編輯模式用）
========================================== */
function ensureSuggestTableStructure() {
    const container = document.getElementById("sg-team-list");
    if (!container) return null;
    let tbody = document.getElementById("sg-team-tbody");
    if (!tbody) {
        container.innerHTML = `
            <table class="sg-suggest-table" id="sg-suggest-table">
                <colgroup>
                    <col class="sg-col-name" style="width:26%">
                    <col class="sg-col-group" style="width:14%">
                    <col class="sg-col-suggest" style="width:26%">
                    <col class="sg-col-score" style="width:12%">
                    <col class="sg-col-status" style="width:12%">
                    <col class="sg-col-action" style="width:10%">
                </colgroup>
                <thead>
                    <tr>
                        <th class="sg-th-name">團隊名稱</th>
                        <th class="sg-th-group">類組</th>
                        <th class="sg-th-suggest">團隊建議</th>
                        <th class="sg-th-score">評分</th>
                        <th class="sg-th-status">選擇審查結果</th>
                        <th class="sg-th-action">操作</th>
                    </tr>
                </thead>
                <tbody id="sg-team-tbody"></tbody>
            </table>
        `;
        tbody = document.getElementById("sg-team-tbody");
    }
    return tbody;
}

/* ==========================================
   3-2. 確保團隊列表為左右兩欄結構（篩選模式用）
========================================== */
function ensureSuggestColumnsStructure() {
    const container = document.getElementById("sg-team-list");
    if (!container) return { leftCol: null, rightCol: null };
    let leftCol = document.getElementById("sg-team-left");
    let rightCol = document.getElementById("sg-team-right");
    if (!leftCol || !rightCol) {
        container.innerHTML = `
            <div class="sg-team-column sg-team-column-left" id="sg-team-left"></div>
            <div class="sg-team-column sg-team-column-right" id="sg-team-right"></div>
        `;
        leftCol = document.getElementById("sg-team-left");
        rightCol = document.getElementById("sg-team-right");
    }
    return { leftCol, rightCol };
}

/* ==========================================
   4. 團隊卡片 HTML（純 JS 產生）- 表格列版本
========================================== */
function createTeamTableRow(team, showDeleteButton = false) {
    const groupId = team.group_ID || team.group_id || '';
    const isTopic = (window.suggestFormSfType === 'topic');
    const teamName = team.team_project_name || team.team_name || team.team_title || '';
    const statusPlaceholder = isTopic ? '請選擇初審建議' : '請選擇審查結果';
    const statusOptions = isTopic
        ? '<option value="3">通過</option><option value="2">不通過</option><option value="1">修改</option><option value="4">待確認</option>'
        : '<option value="3">通過</option><option value="2">不通過</option><option value="1">修改後通過</option><option value="4">修改後複評</option>';
    const statusSelectHtml = `
        <select class="sg-team-status-select form-select" id="sg-status-${team.team_ID}" data-team="${team.team_ID}" data-sf-type="${window.suggestFormSfType || 'review'}">
            <option value="">${statusPlaceholder}</option>
            ${statusOptions}
        </select>
    `;
    const deleteBtnData = showDeleteButton
        ? `data-delete-type="team" data-team="${team.team_ID}"`
        : `data-delete-type="suggest" data-team="${team.team_ID}" data-suggest-id=""`;
    // 操作欄只顯示「刪除」按鈕（無 icon）
    const actionButtonsHtml = `<button type="button" class="sg-btn-delete-row btn btn-danger btn-sm" ${deleteBtnData}>刪除</button>`;
    const isReview = (window.suggestFormSfType === 'review');
    // 組別建議欄：僅召集人輸入框；點整列展開/收合指導老師建議（下一列顯示），不另放按鈕
    const rowExpandClass = isReview ? ' sg-row-expandable' : '';
    const expandIconHtml = ""; // 不再使用展開 icon，改用 checkbox
    const rowTitle = isReview ? ' title="點擊展開/收合指導老師建議"' : '';
    return `
        <tr class="sg-team-card${rowExpandClass}" data-team="${team.team_ID}" data-group="${groupId}"${rowTitle}>
            <td class="sg-td-name">
                <input type="checkbox" class="sg-row-select form-check-input" data-team="${team.team_ID}" />
                <span class="sg-team-title">${escapeHtml(teamName)}</span>
            </td>
            <td class="sg-td-group"><span class="sg-team-group">${escapeHtml(team.group_name || '')}</span></td>
            <td class="sg-td-suggest">
                <textarea class="sg-textarea" id="sg-textarea-${team.team_ID}" data-team="${team.team_ID}" placeholder="點此輸入建議..." style="resize: vertical;"></textarea>
                <div class="sg-btn-group" id="sg-btns-${team.team_ID}" style="display:none;"></div>
            </td>
            <td class="sg-td-score">
                <input type="number" class="sg-score-input form-control form-control-sm" id="sg-score-${team.team_ID}" data-team="${team.team_ID}" placeholder="—" min="0" max="100" step="0.01" style="width:4.5em;">
            </td>
            <td class="sg-td-status">${statusSelectHtml}</td>
            <td class="sg-td-action">
                <div class="sg-action-buttons">
                    ${actionButtonsHtml}
                </div>
            </td>
        </tr>
    `;
}

/* ==========================================
   4. 團隊卡片 HTML（純 JS 產生）- 舊版卡片（篩選模式或相容用）
========================================== */
function createTeamCard(team, showDeleteButton = false) {
    const groupId = team.group_ID || team.group_id || '';
    const isTopic = (window.suggestFormSfType === 'topic');
    const deleteButtonHtml = showDeleteButton ? `
        <button class="sg-btn-delete-team btn btn-danger btn-sm" 
                data-team="${team.team_ID}" 
                title="刪除團隊"
                style="display: inline-block !important; visibility: visible !important; opacity: 1 !important;">
            <i class="fa-solid fa-trash"></i> 刪除
        </button>
    ` : '';
    const statusPlaceholder = isTopic ? '請選擇初審建議' : '請選擇審查結果';
    const statusOptions = isTopic
        ? '<option value="3">通過</option><option value="2">不通過</option><option value="1">修改</option><option value="4">待確認</option>'
        : '<option value="3">通過</option><option value="2">不通過</option><option value="1">修改後通過</option><option value="4">修改後複評</option>';
    const statusSelectHtml = `
                    <select class="sg-team-status-select form-select" id="sg-status-${team.team_ID}" data-team="${team.team_ID}" data-sf-type="${window.suggestFormSfType || 'review'}">
                        <option value="">${statusPlaceholder}</option>
                        ${statusOptions}
                    </select>
                `;
    console.log(`createTeamCard: team_ID=${team.team_ID}, showDeleteButton=${showDeleteButton}, sf_type=${window.suggestFormSfType}`);
    return `
        <div class="sg-team-card" data-team="${team.team_ID}" data-group="${groupId}">
            <div class="sg-team-header">
                <span class="sg-team-number"></span>
                <div class="sg-team-group">${team.group_name}</div>
                <div class="sg-team-title-wrapper">
                    <div class="sg-team-title">${team.team_project_name}</div>
                    ${statusSelectHtml}
                </div>
                <div class="sg-btn-group-top" id="sg-btns-top-${team.team_ID}">
                    ${deleteButtonHtml}
                </div>
            </div>

            <textarea 
                class="sg-textarea" 
                id="sg-textarea-${team.team_ID}"
                data-team="${team.team_ID}"
                placeholder="點此輸入建議..."
            ></textarea>

            <div class="sg-btn-group" id="sg-btns-${team.team_ID}">
            </div>
        </div>
    `;
}

/* ==========================================
   創建簡化版團隊卡片（只顯示名稱和審查結果）
========================================== */
function createSimpleTeamCard(team, suggestStatus) {
    const isTopic = (window.suggestFormSfType === 'topic');
    let statusText = '—';
    let statusClass = '';
    if (suggestStatus == 1) {
        statusText = isTopic ? '修改' : '修改後通過';
        statusClass = 'status-1';
    } else if (suggestStatus == 2) {
        statusText = '不通過';
        statusClass = 'status-2';
    } else if (suggestStatus == 3) {
        statusText = '通過';
        statusClass = 'status-3';
    } else if (suggestStatus == 4) {
        statusText = isTopic ? '待確認' : '修改後複評';
        statusClass = 'status-4';
    }
    
    return `
        <div class="sg-team-card-filter" data-team="${team.team_ID}" data-team-data='${JSON.stringify(team)}'>
            <div class="sg-team-header-filter">
                <div class="sg-team-title-filter">${escapeHtml(team.team_project_name)}</div>
                <div class="sg-team-status-filter ${statusClass}">${statusText}</div>
            </div>
        </div>
    `;
}

/* ==========================================
   綁定篩選卡片點擊事件
========================================== */
function bindFilterCardClickEvents() {
    const cards = document.querySelectorAll('.sg-team-card-filter');
    cards.forEach(card => {
        card.addEventListener('click', function() {
            // 切換選中狀態
            this.classList.toggle('selected');
            // 更新「建立建議表」按鈕顯示狀態
            updateCreateSuggestButton();
        });
    });
}

/* ==========================================
   更新「建立建議表」按鈕顯示狀態
========================================== */
function updateCreateSuggestButton() {
    const selectedCards = document.querySelectorAll('.sg-team-card-filter.selected');
    const buttonContainer = document.getElementById('sg-create-suggest-btn-container');
    
    if (!buttonContainer) {
        // 如果按鈕容器不存在，創建它
        const teamListContainer = document.getElementById('sg-team-list');
        if (teamListContainer) {
            const container = document.createElement('div');
            container.id = 'sg-create-suggest-btn-container';
            container.className = 'sg-create-suggest-btn-container';
            container.style.display = selectedCards.length > 0 ? 'block' : 'none';
            container.innerHTML = `
                <button id="sg-create-suggest-btn" class="sg-btn-create-suggest">
                    <span>建立建議表</span>
                </button>
            `;
            teamListContainer.parentNode.insertBefore(container, teamListContainer);
            
            // 綁定按鈕點擊事件
            const btn = document.getElementById('sg-create-suggest-btn');
            if (btn) {
                btn.addEventListener('click', createSuggestForSelectedTeams);
            }
        }
    } else {
        // 更新顯示狀態
        buttonContainer.style.display = selectedCards.length > 0 ? 'block' : 'none';
    }
}

/* ==========================================
   為選中的團隊建立建議表
========================================== */
async function createSuggestForSelectedTeams() {
    const selectedCards = document.querySelectorAll('.sg-team-card-filter.selected');
    if (selectedCards.length === 0) {
        Toast.fire({
            icon: "warning",
            title: "請先選擇團隊"
        });
        return;
    }
    
    const cohortSelect = document.getElementById("sg-cohort");
    const groupSelect = document.getElementById("sg-group");
    const titleInput = document.getElementById("sg-title");
    
    if (!cohortSelect || !groupSelect || !titleInput) return;
    
    const cohortId = cohortSelect.value;
    const groupId = groupSelect.value;
    
    if (!cohortId || !groupId) {
        Toast.fire({
            icon: "warning",
            title: "請先選擇屆別和類組"
        });
        return;
    }
    
    // 收集選中的團隊資料
    const selectedTeams = [];
    selectedCards.forEach(card => {
        const teamData = JSON.parse(card.getAttribute('data-team-data'));
        // 確保團隊資料包含必要的屬性
        selectedTeams.push({
            team_ID: teamData.team_ID,
            team_project_name: teamData.team_project_name,
            group_name: teamData.group_name || ''
        });
    });
    
    // 清空標題輸入框
    if (titleInput) {
        titleInput.value = "";
    }
    
    // 清空標題下拉選單
    const titleSelect = document.getElementById("sg-title-select");
    if (titleSelect) {
        titleSelect.value = "";
    }
    
    const tbody = ensureSuggestTableStructure();
    if (tbody) tbody.innerHTML = "";
    
    const hint = document.getElementById("sg-team-list-hint");
    if (hint) hint.style.display = "block";
    
    // 隱藏「建立建議表」按鈕
    const buttonContainer = document.getElementById('sg-create-suggest-btn-container');
    if (buttonContainer) buttonContainer.style.display = 'none';
    
    // 顯示存檔、匯出和回到初始頁按鈕，隱藏新增建議表按鈕（不顯示編輯按鈕，因為已經是編輯狀態）
    const editAllBtn = document.getElementById("sg-edit-all-btn");
    const saveBtn = document.getElementById("sg-save-btn");
    const addTeamBtn = document.getElementById("sg-add-team-btn");
    const exportBtn = document.getElementById("sg-export-btn");
    const exportWordBtn = document.getElementById("sg-export-word-btn");
    const backHomeBtn = document.getElementById("sg-back-home-btn");
    const newSuggestBtn = document.getElementById("sg-new-suggest-btn");
    
    // 不顯示編輯按鈕，因為團隊已經是可編輯狀態
    if (editAllBtn) {
        editAllBtn.style.display = 'none';
    }
    if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.style.display = 'inline-block';
    }
    // 顯示加入團隊按鈕（編輯模式下且有標題時）
    if (addTeamBtn) {
        const titleInput = document.getElementById("sg-title");
        const hasTitle = titleInput && titleInput.value.trim() !== '';
        const isInEditMode = saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
        if (hasTitle && isInEditMode) {
            addTeamBtn.disabled = false;
            addTeamBtn.style.display = 'inline-block';
        } else {
            addTeamBtn.disabled = true;
            addTeamBtn.style.display = 'none';
        }
    }
    if (exportBtn) {
        exportBtn.disabled = false;
        exportBtn.style.display = 'inline-block';
    }
    if (exportWordBtn) {
        exportWordBtn.disabled = false;
        exportWordBtn.style.display = 'inline-block';
    }
    if (backHomeBtn) {
        backHomeBtn.style.display = 'inline-block';
    }
    if (newSuggestBtn) {
        newSuggestBtn.style.display = 'none';
    }
    
    // 隱藏第二行篩選框和提示說明
    const filterRow2 = document.getElementById("sg-filter-row2");
    const filterHint = document.getElementById("sg-filter-hint");
    if (filterRow2) filterRow2.style.display = 'none';
    if (filterHint) filterHint.style.display = 'none';
    
    // 確保團隊列表容器是顯示的
    const teamListContainer = document.getElementById("sg-team-list");
    const fileListContainer = document.getElementById("sg-file-list");
    if (teamListContainer) teamListContainer.style.display = "block";
    if (fileListContainer) fileListContainer.style.display = "none";
    
    selectedTeams.forEach((team) => {
        tbody.insertAdjacentHTML("beforeend", createTeamTableRow(team, true));
    });
    
    bindTeamTableRowEvents();
    initDragAndDrop(cohortId, groupId);
    updateTeamNumbers();
    bindAutoNumberEvent();
    bindStatusSelectEvent();
    selectedTeams.forEach(team => {
        setEditableMode(team.team_ID);
    });
    
    Toast.fire({
        icon: "success",
        title: `已為 ${selectedTeams.length} 個團隊建立建議表`
    });
}

/* ==========================================
   4-1a. 評分彈跳視窗（各老師的團隊建議與分數）
========================================== */
async function openScoreModal(teamId, teamName) {
    const cohortSelect = document.getElementById("sg-cohort");
    const titleInput = document.getElementById("sg-title");
    const titleSelect = document.getElementById("sg-title-select");
    const groupSelect = document.getElementById("sg-group");
    // 當屆：優先使用畫面上選擇的屆別，若無則用 URL 參數（例如從 integrate 進入時）
    const cohort_ID = (cohortSelect && cohortSelect.value) || getUrlParams().get("cohort_ID") || "";
    const title = (titleInput && titleInput.value) || (titleSelect && titleSelect.value) || "";
    const group_ID = groupSelect ? (groupSelect.value || "all") : "all";

    if (!cohort_ID) {
        Toast.fire({ icon: "warning", title: "請先選擇屆別" });
        return;
    }
    if (!title) {
        Toast.fire({ icon: "warning", title: "請先選擇或輸入標題" });
        return;
    }

    const apiUrl = resolveSuggestApiUrl();
    let sf_ID = getUrlParams().get("sf_ID") || "";
    if (!sf_ID && title) {
        try {
            const r = await fetch(`${apiUrl}?action=getSfIdByTitle&cohort_ID=${cohort_ID}&group_ID=${group_ID}&title=${encodeURIComponent(title)}`);
            const j = await r.json();
            if (j.success && j.data && j.data.sf_ID) sf_ID = String(j.data.sf_ID);
        } catch (e) {
            console.warn("取得 sf_ID 失敗:", e);
        }
    }
    if (!sf_ID) {
        Toast.fire({ icon: "error", title: "無法取得建議表資訊，請確認已選擇標題" });
        return;
    }

    // 當屆：以「建議表所屬屆別」為準，若無則改以「團隊所屬屆別」，這樣 108 的建議表會顯示 108 的指導老師
    let cohortForTeachers = cohort_ID;
    try {
        const formRes = await fetch(`${apiUrl}?action=getSuggestFormInfo&sf_ID=${sf_ID}`);
        const formJ = await formRes.json();
        if (formJ.success && formJ.data) {
            if (formJ.data.sf_cohort != null && formJ.data.sf_cohort !== "") {
                cohortForTeachers = String(formJ.data.sf_cohort);
            } else {
                const teamRes = await fetch(`${apiUrl}?action=getTeamCohort&team_ID=${teamId}`);
                const teamJ = await teamRes.json();
                if (teamJ.success && teamJ.data && teamJ.data.cohort_ID != null) {
                    cohortForTeachers = String(teamJ.data.cohort_ID);
                }
            }
        }
    } catch (e) {
        console.warn("取得建議表/團隊屆別失敗，使用畫面上的屆別:", e);
    }

    let teachers = [];
    const existingReviews = {};
    try {
        const [tr, rev] = await Promise.all([
            fetch(`${apiUrl}?action=listCohortTeachers&cohort_ID=${cohortForTeachers}`),
            fetch(`${apiUrl}?action=getTeacherReviews&sf_ID=${sf_ID}&team_ID=${teamId}`)
        ]);
        const trJ = await tr.json();
        const revJ = await rev.json();
        if (trJ.success && trJ.data) teachers = trJ.data;
        if (revJ.success && revJ.data) {
            revJ.data.forEach(function (r) {
                existingReviews[r.teacher_u_ID] = { score: r.score, suggest_text: r.suggest_text || "" };
            });
        }
    } catch (e) {
        Toast.fire({ icon: "error", title: "載入失敗", text: e.message });
        return;
    }

    if (teachers.length === 0) {
        Toast.fire({ icon: "info", title: "該屆尚無指導老師資料" });
        return;
    }

    const viewOnly = !isAddTeamButtonAvailable();

    const backdrop = document.createElement("div");
    backdrop.className = "modal-backdrop fade show sg-score-modal-backdrop";
    const modal = document.createElement("div");
    modal.className = "modal fade show sg-score-modal";
    modal.setAttribute("tabindex", "-1");
    modal.setAttribute("role", "dialog");
    modal.style.cssText = "display: block; z-index: 1056;";
    modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable sg-score-modal-dialog">
            <div class="modal-content sg-score-modal-content">
                <div class="modal-header sg-score-modal-header">
                    <h5 class="modal-title">評分 — ${escapeHtml(teamName || "團隊 " + teamId)}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
                </div>
                <div class="modal-body sg-score-modal-body">
                    <p class="sg-score-modal-hint text-muted small mb-3">${viewOnly ? "僅供查看評分與建議，無法修改。請先進入編輯模式後方可編輯。" : "可編輯團隊建議與團隊分數。"}</p>
                    <div class="table-responsive">
                        <table class="table table-bordered sg-score-table">
                            <thead>
                                <tr>
                                    <th style="width:20%;">老師名稱</th>
                                    <th style="width:45%;">團隊建議</th>
                                    <th style="width:25%;">團隊分數</th>
                                </tr>
                            </thead>
                            <tbody id="sg-score-tbody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="sg-score-modal-close">關閉</button>
                    <button type="button" class="btn btn-primary" id="sg-score-modal-save" style="${viewOnly ? 'display:none;' : ''}"><i class="fa-solid fa-save me-1"></i>儲存</button>
                </div>
            </div>
        </div>
    `;

    const tbody = modal.querySelector("#sg-score-tbody");
    teachers.forEach(function (t) {
        const rev = existingReviews[t.u_ID] || {};
        const teacherRow = document.createElement("tr");
        teacherRow.className = "sg-score-teacher-row";
        teacherRow.dataset.teacherId = t.u_ID;
        teacherRow.innerHTML = `
            <td>${escapeHtml(t.u_name || "")}</td>
            <td>
                <textarea class="form-control sg-score-suggest" data-teacher="${escapeHtml(t.u_ID)}" rows="2" placeholder="${viewOnly ? "" : "請輸入建議"}">${escapeHtml(viewOnly && !(rev.suggest_text && rev.suggest_text.trim()) ? "尚無建議" : (rev.suggest_text || ""))}</textarea>
            </td>
            <td><input type="number" class="form-control sg-score-num" data-teacher="${escapeHtml(t.u_ID)}" min="0" max="100" placeholder="分數" value="${rev.score !== undefined && rev.score !== null ? rev.score : ""}"></td>
        `;
        tbody.appendChild(teacherRow);
    });

    // 召集人查看/編輯時：若有指導老師評分學生，自動載入並顯示被評分的學生
    if (viewOnly) {
        modal.querySelectorAll(".sg-score-suggest, .sg-score-num").forEach(function (el) {
            el.readOnly = true;
        });
    }
    (async function () {
        let students = [];
        try {
            const teamStuRes = await fetch(`${apiUrl}?action=listTeamStudents&team_ID=${teamId}`);
            const teamStuJ = await teamStuRes.json();
            if (teamStuJ.success && teamStuJ.data) students = teamStuJ.data;
        } catch (e) {
            console.warn("載入團隊學生失敗", e);
        }
        const studentNames = {};
        students.forEach(function (s) { studentNames[s.u_ID] = s.u_name || ""; });
        for (let i = 0; i < teachers.length; i++) {
            const t = teachers[i];
            let revs = [];
            try {
                const r = await fetch(`${apiUrl}?action=getStudentReviews&sf_ID=${sf_ID}&team_ID=${teamId}&teacher_u_ID=${encodeURIComponent(t.u_ID)}`);
                const j = await r.json();
                if (j.success && Array.isArray(j.data)) revs = j.data;
                else if (j.success && j.data && typeof j.data === "object" && Array.isArray(j.data[t.u_ID])) revs = j.data[t.u_ID];
            } catch (e) {
                console.warn("載入老師 " + t.u_ID + " 學生評分失敗", e);
            }
            if (revs.length === 0) continue;
            const teacherRow = tbody.querySelector(`tr.sg-score-teacher-row[data-teacher-id="${t.u_ID}"]`);
            if (!teacherRow) continue;
            const studentRow = document.createElement("tr");
            studentRow.className = "sg-score-student-block-row";
            studentRow.dataset.teacherId = t.u_ID;
            const revByStudent = {};
            revs.forEach(function (sr) { revByStudent[sr.student_u_ID] = sr; });
            let html = '<td colspan="3"><div class="sg-score-students sg-score-students-view">';
            revs.forEach(function (sr) {
                const name = studentNames[sr.student_u_ID] || sr.student_u_ID || "—";
                const suggestDisplay = (sr.suggest_text && sr.suggest_text.trim()) ? escapeHtml(sr.suggest_text) : "尚無建議";
                const scoreDisplay = (sr.score !== undefined && sr.score !== null && sr.score !== "") ? escapeHtml(String(sr.score)) : "—";
                html += `<div class="sg-score-student-row"><span class="sg-score-student-label">↳ ${escapeHtml(name)}</span><span class="sg-score-student-view-text">建議：${suggestDisplay}</span><span class="sg-score-student-view-num">分數：${scoreDisplay}</span></div>`;
            });
            html += "</div></td>";
            studentRow.innerHTML = html;
            if (teacherRow.nextSibling) {
                tbody.insertBefore(studentRow, teacherRow.nextSibling);
            } else {
                tbody.appendChild(studentRow);
            }
        }
    })();

    document.body.classList.add("modal-open");
    document.body.appendChild(backdrop);
    document.body.appendChild(modal);

    function closeModal() {
        modal.classList.remove("show");
        backdrop.classList.remove("show");
        document.body.classList.remove("modal-open");
        setTimeout(function () {
            if (backdrop.parentNode) backdrop.remove();
            if (modal.parentNode) modal.remove();
        }, 150);
    }

    function collectTeacherReviews() {
        const list = [];
        modal.querySelectorAll(".sg-score-suggest").forEach(function (suggestEl) {
            const tid = suggestEl.getAttribute("data-teacher");
            if (!tid) return;
            const row = suggestEl.closest("tr");
            const scoreEl = row ? row.querySelector(".sg-score-num") : null;
            list.push({
                teacher_u_ID: tid,
                suggest_text: suggestEl.value.trim(),
                score: scoreEl && scoreEl.value !== "" ? scoreEl.value : ""
            });
        });
        return list;
    }

    function collectStudentReviews() {
        const byTeacher = {};
        modal.querySelectorAll(".sg-score-student-row").forEach(function (row) {
            const tid = row.getAttribute("data-teacher") || row.getAttribute("data-teacher-id");
            const sid = row.getAttribute("data-student") || row.getAttribute("data-student-id");
            if (!tid || !sid) return;
            const suggestEl = row.querySelector(".sg-score-student-suggest");
            const scoreEl = row.querySelector(".sg-score-student-num");
            const suggestText = suggestEl ? suggestEl.value.trim() : "";
            const scoreVal = scoreEl && scoreEl.value !== "" ? scoreEl.value : "";
            if (suggestText === "" && scoreVal === "") return;
            if (!byTeacher[tid]) byTeacher[tid] = [];
            byTeacher[tid].push({
                student_u_ID: sid,
                suggest_text: suggestText,
                score: scoreVal
            });
        });
        return byTeacher;
    }

    async function doSave() {
        const teacherReviews = collectTeacherReviews();
        const studentReviewsByTeacher = collectStudentReviews();
        try {
            const formData = new FormData();
            formData.append("action", "saveTeacherReviews");
            formData.append("sf_ID", sf_ID);
            formData.append("team_ID", teamId);
            formData.append("reviews", JSON.stringify(teacherReviews));
            const r1 = await fetch(apiUrl, { method: "POST", body: formData });
            const j1 = await r1.json();
            if (!j1.success) {
                Toast.fire({ icon: "error", title: j1.msg || "儲存失敗" });
                return;
            }
            for (const teacherId in studentReviewsByTeacher) {
                const reviews = studentReviewsByTeacher[teacherId];
                if (reviews.length === 0) continue;
                const fd = new FormData();
                fd.append("action", "saveStudentReviews");
                fd.append("sf_ID", sf_ID);
                fd.append("team_ID", teamId);
                fd.append("teacher_u_ID", teacherId);
                fd.append("reviews", JSON.stringify(reviews));
                const r2 = await fetch(apiUrl, { method: "POST", body: fd });
                const j2 = await r2.json();
                if (!j2.success) {
                    Toast.fire({ icon: "error", title: "個別學生評分儲存失敗：" + (j2.msg || "") });
                    return;
                }
            }
            Toast.fire({ icon: "success", title: "已儲存" });
            closeModal();
        } catch (e) {
            Toast.fire({ icon: "error", title: "儲存失敗", text: e.message });
        }
    }

    modal.querySelector(".btn-close").onclick = closeModal;
    modal.querySelector("#sg-score-modal-close").onclick = closeModal;
    if (!viewOnly) {
        modal.querySelector("#sg-score-modal-save").onclick = doSave;
    }
    backdrop.onclick = function () { closeModal(); };
    modal.onclick = function (e) { if (e.target === modal) closeModal(); };
}

/* ==========================================
   4-1b. 新增指導老師建議 Modal（單一老師）
========================================== */
async function openAddTeacherSuggestModal(teamId) {
    const apiUrl = resolveSuggestApiUrl();
    const cohortSelect = document.getElementById("sg-cohort");
    const cohort_ID = cohortSelect && cohortSelect.value ? cohortSelect.value : getUrlParams().get("cohort_ID") || "";
    if (!cohort_ID) {
        Toast && Toast.fire ? Toast.fire({ icon: "warning", title: "請先選擇屆別" }) : alert("請先選擇屆別");
        return;
    }
    // 取得 sf_ID（與展開指導老師建議時相同邏輯）
    let sf_ID = getUrlParams().get("sf_ID") || "";
    if (!sf_ID) {
        const titleInput = document.getElementById("sg-title");
        const titleSelect = document.getElementById("sg-title-select");
        const title = (titleInput && titleInput.value) || (titleSelect && titleSelect.value) || "";
        const groupSelect = document.getElementById("sg-group");
        const group_ID = groupSelect ? (groupSelect.value || "all") : "all";
        if (title) {
            try {
                const r = await fetch(`${apiUrl}?action=getSfIdByTitle&cohort_ID=${cohort_ID}&group_ID=${group_ID}&title=${encodeURIComponent(title)}`);
                const j = await r.json();
                if (j.success && j.data && j.data.sf_ID) sf_ID = String(j.data.sf_ID);
            } catch (e) {
                console.warn("取得 sf_ID 失敗", e);
            }
        }
    }
    if (!sf_ID) {
        Toast && Toast.fire ? Toast.fire({ icon: "error", title: "無法取得建議表" }) : alert("無法取得建議表");
        return;
    }
    let teachers = [];
    let students = [];
    try {
        const [tRes, sRes] = await Promise.all([
            fetch(`${apiUrl}?action=listCohortTeachers&cohort_ID=${encodeURIComponent(cohort_ID)}`),
            fetch(`${apiUrl}?action=listTeamStudents&team_ID=${encodeURIComponent(teamId)}`)
        ]);
        const tJ = await tRes.json();
        const sJ = await sRes.json();
        if (tJ.success && Array.isArray(tJ.data)) teachers = tJ.data;
        if (sJ.success && Array.isArray(sJ.data)) students = sJ.data;
    } catch (e) {
        Toast && Toast.fire ? Toast.fire({ icon: "error", title: "載入老師或學生失敗", text: e.message }) : alert("載入老師或學生失敗");
        return;
    }
    if (!teachers.length) {
        Toast && Toast.fire ? Toast.fire({ icon: "info", title: "該屆尚無指導老師資料" }) : alert("該屆尚無指導老師資料");
        return;
    }
    // 建立 Modal
    const backdrop = document.createElement("div");
    backdrop.className = "modal-backdrop fade show sg-add-teacher-modal-backdrop";
    const modal = document.createElement("div");
    modal.className = "modal fade show sg-add-teacher-modal";
    modal.setAttribute("tabindex", "-1");
    modal.setAttribute("role", "dialog");
    modal.style.cssText = "display: block; z-index: 1056;";
    const teacherOptions = teachers.map(t => `<option value="${escapeHtml(t.u_ID)}">${escapeHtml(t.u_name || "")}</option>`).join("");
    const studentsRows = students.map(s => {
        const name = escapeHtml(s.u_name || "");
        const id = escapeHtml(s.u_ID || "");
        return `
            <tr data-student-id="${id}">
                <td class="align-middle">${name}</td>
                <td>
                    <textarea class="form-control sg-add-student-suggest" data-student="${id}" rows="2" placeholder="請輸入建議"></textarea>
                </td>
                <td class="text-center">
                    <input type="number" class="form-control sg-add-student-score" data-student="${id}" min="0" max="100" style="width:70px; margin:0 auto;" placeholder="分數">
                </td>
            </tr>
        `;
    }).join("");
    modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered" style="max-width: 700px;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">新增指導老師建議</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
                </div>
                <div class="modal-body sg-add-teacher-modal-body">
                    <div class="sg-add-teacher-row mb-3">
                        <div class="sg-add-teacher-select">
                            <label class="form-label">指導老師</label>
                            <select class="form-select" id="sg-add-teacher-select">
                                <option value="">請選擇指導老師</option>
                                ${teacherOptions}
                            </select>
                        </div>
                        <div class="sg-add-teacher-score">
                            <label class="form-label">組別評分</label>
                            <input type="number" class="form-control" id="sg-add-group-score" min="0" max="100" style="width:70px; text-align:center;">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">組別建議</label>
                        <textarea class="form-control" id="sg-add-group-suggest" rows="3" placeholder="請輸入老師對整組的建議"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">學生個別建議</label>
                    </div>
                    <div class="table-responsive" style="max-height: 260px; overflow-y:auto;">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:20%;">學生</th>
                                    <th style="width:60%;">建議</th>
                                    <th style="width:20%;">評分</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${studentsRows || '<tr><td colspan="3" class="text-muted text-center">此團隊目前沒有學生資料</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="sg-add-teacher-cancel">取消</button>
                    <button type="button" class="btn btn-primary" id="sg-add-teacher-save">儲存</button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(backdrop);
    document.body.appendChild(modal);
    document.body.classList.add("modal-open");
    const closeModal = () => {
        document.body.classList.remove("modal-open");
        backdrop.remove();
        modal.remove();
    };
    modal.querySelector(".btn-close").addEventListener("click", closeModal);
    modal.querySelector("#sg-add-teacher-cancel").addEventListener("click", closeModal);
    // 儲存
    modal.querySelector("#sg-add-teacher-save").addEventListener("click", async () => {
        const teacherSelect = modal.querySelector("#sg-add-teacher-select");
        const teacher_u_ID = teacherSelect ? teacherSelect.value : "";
        const groupSuggest = (modal.querySelector("#sg-add-group-suggest").value || "").trim();
        const groupScoreStr = (modal.querySelector("#sg-add-group-score").value || "").trim();
        const groupScore = groupScoreStr === "" ? null : Number(groupScoreStr);
        if (!teacher_u_ID) {
            Toast && Toast.fire ? Toast.fire({ icon: "warning", title: "請先選擇指導老師" }) : alert("請先選擇指導老師");
            return;
        }
        const reviewsTeacher = [{
            teacher_u_ID,
            score: groupScore,
            suggest_text: groupSuggest
        }];
        const studentReviews = [];
        modal.querySelectorAll(".sg-add-student-suggest, .sg-add-student-score").forEach(() => {}); // 占位避免 eslint
        const rows = modal.querySelectorAll("tbody tr[data-student-id]");
        rows.forEach(row => {
            const sid = row.getAttribute("data-student-id");
            const suggestEl = row.querySelector(".sg-add-student-suggest");
            const scoreEl = row.querySelector(".sg-add-student-score");
            const sText = (suggestEl && suggestEl.value || "").trim();
            const sScoreStr = (scoreEl && scoreEl.value || "").trim();
            const sScore = sScoreStr === "" ? null : Number(sScoreStr);
            if (sText === "" && (sScoreStr === "" || isNaN(sScore))) return;
            studentReviews.push({
                student_u_ID: sid,
                score: sScore,
                suggest_text: sText
            });
        });
        try {
            // 先儲存老師對團隊的建議與評分
            const fdTeacher = new FormData();
            fdTeacher.append("action", "saveTeacherReviews");
            fdTeacher.append("sf_ID", sf_ID);
            fdTeacher.append("team_ID", teamId);
            fdTeacher.append("reviews", JSON.stringify(reviewsTeacher));
            const tRes = await fetch(apiUrl, { method: "POST", body: fdTeacher });
            const tJ = await tRes.json();
            if (!tJ.success) {
                throw new Error(tJ.msg || "儲存老師建議失敗");
            }
            // 再儲存學生個別建議（如有）
            if (studentReviews.length > 0) {
                const fdStu = new FormData();
                fdStu.append("action", "saveStudentReviews");
                fdStu.append("sf_ID", sf_ID);
                fdStu.append("team_ID", teamId);
                fdStu.append("teacher_u_ID", teacher_u_ID);
                fdStu.append("reviews", JSON.stringify(studentReviews));
                const sRes = await fetch(apiUrl, { method: "POST", body: fdStu });
                const sJ = await sRes.json();
                if (!sJ.success) {
                    throw new Error(sJ.msg || "儲存學生個別建議失敗");
                }
            }
            Toast && Toast.fire ? Toast.fire({ icon: "success", title: "已新增指導老師建議" }) : alert("已新增指導老師建議");
            closeModal();
            // 重新載入該團隊的指導老師建議區塊（不重整整頁）
            const mainRow = document.querySelector(`tr.sg-team-card[data-team="${teamId}"]`);
            const detailRow = document.getElementById(`sg-teacher-detail-${teamId}`);
            if (mainRow && detailRow) {
                const cell = detailRow.querySelector(".sg-teacher-detail-cell");
                if (cell) cell.dataset.loaded = "";
                // 先收起，再展開一次觸發重新載入
                detailRow.style.display = "none";
                mainRow.classList.remove("sg-row-expanded");
                mainRow.dispatchEvent(new MouseEvent("click", { bubbles: true }));
            }
        } catch (e) {
            console.error(e);
            Toast && Toast.fire ? Toast.fire({ icon: "error", title: "儲存失敗", text: e.message }) : alert("儲存失敗：" + e.message);
        }
    });
}

/* ==========================================
   4-1. 更新審查結果樣式
========================================== */
function updateStatusSelectStyle(selectElement) {
    if (!selectElement) return;
    
    // 移除所有狀態類別
    selectElement.classList.remove('status-1', 'status-2', 'status-3', 'status-4');
    
    // 根據選擇的值添加對應的類別
    const value = selectElement.value;
    if (value && ['1', '2', '3', '4'].includes(value)) {
        selectElement.classList.add(`status-${value}`);
    }
}

/* ==========================================
   4-2-0. 檢查是否處於編輯模式
========================================== */
function isSuggestInEditMode() {
    const saveBtn = document.getElementById("sg-save-btn");
    return saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
}

/* ==========================================
   4-2-0-0. 檢查「加入團隊」按鈕是否可用（編輯模式且有標題）
   當加入團隊可用時：操作區的評分、刪除皆可使用。
   當非編輯模式：刪除為唯讀（禁用），評分僅可查看不可更改。
========================================== */
function isAddTeamButtonAvailable() {
    const addTeamBtn = document.getElementById("sg-add-team-btn");
    return addTeamBtn && addTeamBtn.style.display !== 'none' && !addTeamBtn.disabled;
}

/* ==========================================
   4-2-0-1. 根據編輯模式更新操作按鈕（評分、刪除）的啟用狀態
   有加入團隊按鈕可用時：評分、刪除皆可使用。
   非編輯模式：刪除為唯讀按鈕（禁用），評分僅可查看不可更改。
========================================== */
function updateActionButtonsState() {
    const canEdit = isAddTeamButtonAvailable() && !window.SuggestReadOnlyForConvener;
    const container = document.getElementById("sg-team-list");
    if (!container) return;
    container.querySelectorAll(".sg-btn-score").forEach(btn => {
        btn.removeAttribute("disabled");
        btn.disabled = false;
        btn.classList.remove("sg-btn-action-disabled");
    });
    container.querySelectorAll(".sg-btn-delete-row, .sg-btn-delete-team").forEach(btn => {
        if (canEdit) {
            btn.removeAttribute("disabled");
            btn.disabled = false;
            btn.classList.remove("sg-btn-action-disabled");
        } else {
            btn.setAttribute("disabled", "disabled");
            btn.disabled = true;
            btn.classList.add("sg-btn-action-disabled");
        }
    });
}

/* ==========================================
   4-2-1. 綁定表格列：點整列展開/收合指導老師建議（下一列）、刪除按鈕
   點到刪除、輸入框、評分、審查結果下拉時不觸發展開
========================================== */
function bindTeamTableRowEvents() {
    const container = document.getElementById("sg-team-list");
    if (!container) return;

    // 點整列展開/收合（只綁定一次，用事件委派）
    if (!container.dataset.sgRowExpandBound) {
        container.dataset.sgRowExpandBound = "1";
        container.addEventListener("click", async function(e) {
            const mainRow = e.target.closest("tr.sg-team-card.sg-row-expandable");
            if (!mainRow) return;
            if (e.target.closest(".sg-btn-delete-row") || e.target.closest(".sg-textarea") ||
                e.target.closest(".sg-team-status-select") || e.target.closest(".sg-score-input") || e.target.closest(".sg-row-select") ||
                e.target.closest(".sg-btn-group") || e.target.closest(".sg-action-buttons")) return;

            const teamId = mainRow.getAttribute("data-team");
        if (!teamId) return;
        let detailRow = document.getElementById(`sg-teacher-detail-${teamId}`);
        const isCurrentlyOpen = detailRow && detailRow.style.display !== "none";
            if (isCurrentlyOpen) {
                if (detailRow) detailRow.style.display = "none";
                mainRow.classList.remove("sg-row-expanded");
                const icon = mainRow.querySelector(".sg-row-expand-icon");
                if (icon) icon.textContent = "▶";
                return;
            }
        if (!detailRow) {
            detailRow = document.createElement("tr");
            detailRow.id = `sg-teacher-detail-${teamId}`;
            detailRow.className = "sg-teacher-detail-row";
            detailRow.setAttribute("data-team", teamId);
            detailRow.innerHTML = '<td colspan="6" class="sg-teacher-detail-cell p-3"><p class="text-muted small mb-0">載入中…</p></td>';
            mainRow.insertAdjacentElement("afterend", detailRow);
        }
        detailRow.style.display = "";
        mainRow.classList.add("sg-row-expanded");
        const icon = mainRow.querySelector(".sg-row-expand-icon");
        if (icon) icon.textContent = "▼";
        const cell = detailRow.querySelector(".sg-teacher-detail-cell");
        if (!cell) return;
        if (!cell.dataset.loaded) {
            try {
                const apiUrl = resolveSuggestApiUrl();
                const titleInput = document.getElementById("sg-title");
                const titleSelect = document.getElementById("sg-title-select");
                const title = (titleInput && titleInput.value) || (titleSelect && titleSelect.value) || "";
                const cohortId = document.getElementById("sg-cohort")?.value || getUrlParams().get("cohort_ID") || "";
                const groupId = document.getElementById("sg-group")?.value || "all";
                let sf_ID = getUrlParams().get("sf_ID") || "";
                if (!sf_ID && title) {
                    const r = await fetch(`${apiUrl}?action=getSfIdByTitle&cohort_ID=${cohortId}&group_ID=${groupId}&title=${encodeURIComponent(title)}`);
                    const j = await r.json();
                    if (j.success && j.data && j.data.sf_ID) sf_ID = String(j.data.sf_ID);
                }
                if (!sf_ID) {
                    cell.innerHTML = "<p class=\"text-muted small mb-0\">無法取得建議表</p>";
                    cell.dataset.loaded = "1";
                    return;
                }
                const res = await fetch(`${apiUrl}?action=getTeacherReviewsWithStudents&sf_ID=${encodeURIComponent(sf_ID)}&team_ID=${encodeURIComponent(teamId)}`);
                const data = await res.json();
                if (!data.success) {
                    cell.innerHTML = "<p class=\"text-danger small mb-0\">載入失敗</p>";
                    cell.dataset.loaded = "1";
                    return;
                }
                const payload = data.data || { team: [], students: [] };
                const teamList = payload.team || [];
                const studentList = payload.students || [];
                let html = "";
                if (teamList.length > 0 || studentList.length > 0) {
                    const teamByTeacher = {};
                    teamList.forEach(r => {
                        const name = (r.teacher_name || '').trim() || r.teacher_u_ID;
                        if (name) teamByTeacher[name] = { score: r.score, suggest_text: (r.suggest_text || '').trim() };
                    });
                    const studentsByTeacher = {};
                    studentList.forEach(r => {
                        const name = (r.teacher_name || '').trim() || r.teacher_u_ID;
                        if (!name) return;
                        if (!studentsByTeacher[name]) studentsByTeacher[name] = [];
                        studentsByTeacher[name].push({
                            student_name: r.student_name || r.student_u_ID || '',
                            score: r.score,
                            suggest_text: (r.suggest_text || '').trim()
                        });
                    });
                    const teacherOrder = [];
                    teamList.forEach(r => {
                        const name = (r.teacher_name || '').trim() || r.teacher_u_ID;
                        if (name && !teacherOrder.includes(name)) teacherOrder.push(name);
                    });
                    Object.keys(studentsByTeacher).forEach(name => {
                        if (name && !teacherOrder.includes(name)) teacherOrder.push(name);
                    });
                    const avgScores = [];
                    teacherOrder.forEach(teacherName => {
                        const team = teamByTeacher[teacherName];
                        const students = studentsByTeacher[teacherName] || [];
                        if (team && team.score != null && team.score !== '' && !isNaN(team.score)) {
                            avgScores.push(Number(team.score));
                        }
                        const headerScore = team && team.score != null && team.score !== '' ? `｜組別評分：${team.score}` : '';
                        const headerText = `${escapeHtml(teacherName)}${headerScore}`;
                        const teamText = team && team.suggest_text ? escapeHtml(team.suggest_text) : '';

                        html += `<div class="sg-teacher-block">`;
                        html += `<div class="sg-teacher-header">[${headerText}]</div>`;
                        if (teamText) {
                            html += `<div class="sg-teacher-team-text">${teamText}</div>`;
                        }
                        if (students.length > 0) {
                            html += `<div class="sg-teacher-students-title">學生個別建議</div>`;
                            html += `<ul class="sg-teacher-students-list">`;
                            students.forEach(s => {
                                const sScore = s.score != null && s.score !== '' ? `｜評分：${s.score}` : '';
                                const sText = s.suggest_text ? `：${escapeHtml(s.suggest_text)}` : '';
                                html += `<li><span class="sg-teacher-student-name">${escapeHtml(s.student_name || '')}</span>${sScore ? `<span class="sg-teacher-student-score">${escapeHtml(sScore)}</span>` : ''}${sText}</li>`;
                            });
                            html += `</ul>`;
                        }
                        html += `</div>`;
                    });
                    // 按鈕區塊：新增指導老師建議（僅召集人）+ 平均分數
                    const isConvener = !!window.isSuggestConvener;
                    const scoresStr = avgScores.filter(v => !isNaN(v)).join(",");
                    let actionsHtml = `<div class="teacher-suggest-actions">`;
                    if (isConvener) {
                        actionsHtml += `<button type="button" class="btn btn-outline-secondary btn-sm sg-btn-add-teacher-suggest" data-team="${teamId}">新增指導老師建議</button>`;
                    }
                    actionsHtml += `<button type="button" class="btn btn-outline-primary btn-sm sg-btn-avg-score" data-team="${teamId}" data-scores="${scoresStr}">平均分數</button>`;
                    actionsHtml += `</div>`;
                    html += actionsHtml;
                }
                if (!html) {
                    html = "<p class=\"text-muted small mb-0\">尚無指導老師或個別學生的建議與分數</p>";
                }
                cell.innerHTML = html;
                // 綁定底部按鈕事件
                const avgBtn = cell.querySelector(".sg-btn-avg-score");
                if (avgBtn) {
                    avgBtn.addEventListener("click", function(e) {
                        e.stopPropagation();
                        const scoresStr = this.getAttribute("data-scores") || "";
                        const parts = scoresStr.split(",").map(s => parseFloat(s)).filter(v => !isNaN(v));
                        if (parts.length === 0) {
                            Toast && Toast.fire ? Toast.fire({ icon: "info", title: "沒有可計算的老師組別評分" }) : alert("沒有可計算的老師組別評分");
                            return;
                        }
                        const sum = parts.reduce((a, b) => a + b, 0);
                        const avg = Math.round(sum / parts.length);
                        const scoreInput = document.getElementById(`sg-score-${teamId}`);
                        if (scoreInput) {
                            scoreInput.value = String(avg);
                        }
                    });
                }
                const addBtn = cell.querySelector(".sg-btn-add-teacher-suggest");
                if (addBtn) {
                    addBtn.addEventListener("click", function(e) {
                        e.stopPropagation();
                        openAddTeacherSuggestModal(teamId);
                    });
                }
                cell.dataset.loaded = "1";
            } catch (err) {
                cell.innerHTML = "<p class=\"text-danger small mb-0\">載入失敗</p>";
                cell.dataset.loaded = "1";
            }
        }
    });
    }

    container.querySelectorAll(".sg-btn-delete-row").forEach(btn => {
        const newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);
        newBtn.addEventListener("click", function() {
            if (!isAddTeamButtonAvailable()) {
                Toast.fire({
                    icon: "warning",
                    title: "請先進入編輯模式",
                    text: "請點擊「編輯」按鈕後再進行刪除"
                });
                return;
            }
            const teamId = parseInt(this.getAttribute("data-team"));
            const type = this.getAttribute("data-delete-type");
            if (type === "team") {
                deleteTeamFromSuggest(teamId);
            } else {
                const suggestId = this.getAttribute("data-suggest-id");
                if (suggestId) deleteSuggest(parseInt(suggestId), teamId);
            }
        });
    });
    // 根據目前編輯模式更新按鈕啟用狀態
    updateActionButtonsState();
}

/* ==========================================
   4-2. 綁定審查結果下拉選單事件
========================================== */
function bindStatusSelectEvent() {
    document.querySelectorAll(".sg-team-status-select").forEach(select => {
        // 先保存目前選擇的值（cloneNode 可能無法保留以 value 設定的選取狀態）
        const savedValue = select.value;
        
        // 移除舊的事件監聽器（如果有的話）
        const newSelect = select.cloneNode(true);
        select.parentNode.replaceChild(newSelect, select);
        
        // 為新的 select 綁定事件
        const freshSelect = document.getElementById(newSelect.id);
        if (freshSelect) {
            // 還原選擇的值，避免加入團隊後審查結果消失（cloneNode 可能無法保留以 value 設定的選取狀態）
            if (savedValue) {
                freshSelect.value = savedValue;
            }
            // 初始化樣式（根據當前選擇的值）
            updateStatusSelectStyle(freshSelect);
            
            freshSelect.addEventListener("change", function() {
                // 更新樣式
                updateStatusSelectStyle(this);
                // 注意：審查結果的更改需要通過「存檔」按鈕統一保存，不自動儲存
            });
        }
    });
}

/* ==========================================
   5. 自動編號（滑鼠點入就出現 1.）
   Enter 自動跳下一行「n.」
========================================== */
function bindAutoNumberEvent() {
    document.querySelectorAll(".sg-textarea").forEach(area => {
        // 避免重複綁定（加入團隊時會再次呼叫，重複綁定會造成異常行為）
        if (area.dataset.autoNumberBound === "1") return;
        area.dataset.autoNumberBound = "1";
        
        const teamId = area.getAttribute("data-team");
        
        area.addEventListener("focus", function () {
            // 允許先填建議再填審查結果，填寫順序不拘；存檔時會防呆檢查
            if (this.value.trim() === "") {
                this.value = "1. ";
            }
        });

        // Enter → 自動下一行（只在非只讀模式下生效）
        area.addEventListener("keydown", function (e) {
            // 如果是只讀模式，阻止所有輸入
            if (this.readOnly || this.classList.contains('sg-textarea-readonly')) {
                e.preventDefault();
                return;
            }
            
            if (e.key === "Enter") {
                e.preventDefault();

                const lines = this.value.split("\n");
                const lastLine = lines[lines.length - 1];
                
                let nextNumber = lines.length + 1;

                // 追加下一行編號
                this.value += `\n${nextNumber}. `;
            }
        });
        
        // 阻止只讀模式下的所有鍵盤輸入
        area.addEventListener("keypress", function (e) {
            if (this.readOnly || this.classList.contains('sg-textarea-readonly')) {
                e.preventDefault();
                return false;
            }
        });
        
        // 阻止只讀模式下的輸入事件
        area.addEventListener("input", function (e) {
            if (this.readOnly || this.classList.contains('sg-textarea-readonly')) {
                e.preventDefault();
                // 恢復原值
                const originalValue = this.getAttribute('data-original-value') || '';
                this.value = originalValue;
                return false;
            }
        });
    });
}

/* ==========================================
   6. 儲存建議（新增或更新）
   sortNo：新增時可傳入表格順序（1-based），後端會寫入 suggest_sort_no，避免後來加入的團隊重複為 1
========================================== */
async function saveSuggest(teamId, suggestId = null, sortNo = null) {
    const area = document.getElementById(`sg-textarea-${teamId}`);
    const titleInput = document.getElementById("sg-title");
    const statusSelect = document.getElementById(`sg-status-${teamId}`);
    
    let text = area.value.trim();
    if (!text) {
        Toast.fire({ icon: "warning", title: "請輸入建議內容" });
        return;
    }
    
    const suggestName = titleInput ? titleInput.value.trim() : "";
    const suggestStatus = statusSelect ? statusSelect.value : "";
    const scoreInput = document.getElementById(`sg-score-${teamId}`);
    const suggestScore = (scoreInput && scoreInput.value.trim() !== '') ? scoreInput.value.trim() : '';
    const labelName = (window.suggestFormSfType === 'topic') ? '初審建議' : '審查結果';
    if (text && !suggestStatus) {
        Toast.fire({ 
            icon: "error", 
            title: `請選擇${labelName}`,
            text: `輸入建議後必須選擇${labelName}才能存檔`
        });
        return;
    }

    try {
        const formData = new FormData();
        if (suggestId) {
            // 更新
            formData.append("action", "updateSuggest");
            formData.append("suggest_ID", suggestId);
        } else {
            // 新增：傳入表格順序，後端依此寫入 suggest_sort_no（建議表以時程表為預設，使用者調整順序後以實際順序為準）
            formData.append("action", "addSuggest");
            if (sortNo != null && sortNo > 0) {
                formData.append("suggest_sort_no", sortNo);
            }
        }
        formData.append("team_ID", teamId);
        formData.append("content", text);
        formData.append("suggest_name", suggestName);
        formData.append("suggest_status", suggestStatus || "");
        if (suggestScore !== '') formData.append("suggest_score", suggestScore);

        const apiUrl = resolveSuggestApiUrl();
        const r = await fetch(apiUrl, { method: "POST", body: formData });
        const j = await r.json();

        if (!j.success) {
            // 顯示後端返回的錯誤訊息
            Toast.fire({ 
                icon: "error", 
                title: "儲存失敗",
                text: j.msg || "請檢查輸入內容"
            });
            throw new Error(j.msg || "API 回傳失敗");  // 拋出錯誤，讓 saveAllSuggestions 正確顯示存檔失敗
        }

        Toast.fire({ icon: "success", title: suggestId ? "已更新建議" : "已新增建議" });

        // 儲存後重新載入過濾後的內容，然後設為唯讀
        await loadTeamSuggest(teamId);

    } catch (err) {
        console.error("saveSuggest 錯誤:", err);
        Toast.fire({ icon: "error", title: "儲存失敗", text: err.message || "請稍後再試" });
        throw err;  // 拋出錯誤，讓 saveAllSuggestions 正確顯示存檔失敗
    }
}

/* ==========================================
   7. 載入某團隊建議（載入到 textarea）
   @param {string|number} teamId - 團隊 ID
   @param {string} [titleOverride] - 可選，指定標題用於篩選（避免依賴尚未寫入的 input，確保能看到之前填寫的內容）
========================================== */
async function loadTeamSuggest(teamId, titleOverride) {
    const area = document.getElementById(`sg-textarea-${teamId}`);
    const statusSelect = document.getElementById(`sg-status-${teamId}`);
    const titleInput = document.getElementById("sg-title");
    if (!area) return;

    try {
        const apiUrl = resolveSuggestApiUrl();
        const r = await fetch(`${apiUrl}?action=listSuggests&team_ID=${teamId}`);
        const j = await r.json();

        if (!j.success) throw j.msg;

        if (!j.data || !Array.isArray(j.data)) {
            area.value = "";
            if (statusSelect) { statusSelect.value = ""; updateStatusSelectStyle(statusSelect); }
            setEditableMode(teamId);
            return;
        }

        // 標題：優先使用傳入的 titleOverride，否則用 input（確保載入時一定用正確標題比對）
        const currentTitle = (titleOverride !== undefined && titleOverride !== null)
            ? String(titleOverride).trim()
            : (titleInput ? titleInput.value.trim() : '');

        function normalizeTitle(str) {
            if (!str || typeof str !== 'string') return '';
            return str.trim().replace(/\s+/g, ' ');
        }

        // 過濾出符合當前標題的建議（標題比對放寬：trim + 多空白視為單一空白）
        let matchingSuggests = j.data;
        if (currentTitle) {
            const normalizedCurrent = normalizeTitle(currentTitle);
            matchingSuggests = j.data.filter(s => {
                const name = s.suggest_name || s.sf_name || '';
                return normalizedCurrent === normalizeTitle(name);
            });
        }

        if (matchingSuggests.length === 0) {
            area.value = "";
            if (statusSelect) {
                statusSelect.value = "";
                updateStatusSelectStyle(statusSelect);
            }
            setEditableMode(teamId);
            return;
        }

        // 載入符合標題的最新建議（第一筆）
        const latest = matchingSuggests[0];
        area.value = latest.suggest_comment || "";
        
        const scoreInput = document.getElementById(`sg-score-${teamId}`);
        if (scoreInput) {
            const displayScore = (latest.suggest_score != null && latest.suggest_score !== '') ? Number(latest.suggest_score) : (latest.teacher_avg != null ? Math.round(Number(latest.teacher_avg) * 100) / 100 : '');
            scoreInput.value = displayScore === '' ? '' : String(displayScore);
        }
        
        if (statusSelect && latest.suggest_status) {
            statusSelect.value = String(latest.suggest_status);
            updateStatusSelectStyle(statusSelect);
        } else if (statusSelect) {
            updateStatusSelectStyle(statusSelect);
        }
        
        setReadonlyMode(teamId, latest.suggest_ID);

    } catch (err) {
        console.log(err);
        Toast.fire({ icon: "error", title: "載入失敗" });
    }
}

/* ==========================================
   7-1. 設為唯讀模式（顯示編輯和刪除按鈕）
========================================== */
function setReadonlyMode(teamId, suggestId) {
    const area = document.getElementById(`sg-textarea-${teamId}`);
    const statusSelect = document.getElementById(`sg-status-${teamId}`);
    const scoreInput = document.getElementById(`sg-score-${teamId}`);
    const btnGroup = document.getElementById(`sg-btns-${teamId}`);
    const btnGroupTop = document.getElementById(`sg-btns-top-${teamId}`);
    
    if (!area) return;
    
    area.setAttribute('data-original-value', area.value);
    area.readOnly = true;
    area.disabled = false;
    area.classList.add('sg-textarea-readonly');
    
    if (statusSelect) {
        statusSelect.disabled = true;
    }
    
    if (scoreInput) {
        scoreInput.disabled = true;
        scoreInput.readOnly = true;
    }
    
    if (btnGroupTop) {
        btnGroupTop.innerHTML = '';
    }
    
    if (btnGroup) {
        btnGroup.innerHTML = '';
    }
    
    // 表格列：設定 data-suggest-id 並更新操作欄的刪除按鈕為「刪除建議」
    const row = area.closest ? area.closest('tr.sg-team-card') : null;
    if (row && suggestId) {
        row.setAttribute('data-suggest-id', suggestId);
        const deleteBtn = row.querySelector('.sg-btn-delete-row');
        if (deleteBtn) {
            deleteBtn.setAttribute('data-delete-type', 'suggest');
            deleteBtn.setAttribute('data-suggest-id', suggestId);
        }
    }
}

/* ==========================================
   7-2. 設為可編輯模式（顯示儲存和取消按鈕）
========================================== */
function setEditableMode(teamId, suggestId = null) {
    const area = document.getElementById(`sg-textarea-${teamId}`);
    const statusSelect = document.getElementById(`sg-status-${teamId}`);
    const scoreInput = document.getElementById(`sg-score-${teamId}`);
    const btnGroup = document.getElementById(`sg-btns-${teamId}`);
    const btnGroupTop = document.getElementById(`sg-btns-top-${teamId}`);
    
    if (!area) return;
    
    area.removeAttribute('readonly');
    area.readOnly = false;
    area.classList.remove('sg-textarea-readonly');
    area.removeAttribute('data-original-value');
    
    if (statusSelect) {
        statusSelect.removeAttribute('disabled');
        statusSelect.disabled = false;
    }
    
    if (scoreInput) {
        scoreInput.disabled = false;
        scoreInput.removeAttribute('readOnly');
    }
    
    if (btnGroupTop) {
        btnGroupTop.innerHTML = '';
    }
    
    if (suggestId) {
        if (btnGroup) {
            btnGroup.innerHTML = `
                <button class="sg-btn-del" data-suggest-id="${suggestId}" data-team-id="${teamId}">刪除</button>
            `;
        }
    } else {
        if (btnGroup) {
            btnGroup.innerHTML = '';
        }
    }
    
    // 表格列：操作欄僅有刪除按鈕
    const row = area.closest ? area.closest('tr.sg-team-card') : null;
    if (row) {
        // 評分按鈕已移除，無需更新
    }
}

/* ==========================================
   7-3. 統一編輯所有團隊（啟用編輯模式）
========================================== */
async function enableEditAllMode() {
    // 編輯模式下應允許直接修改標題
    const titleInput = document.getElementById("sg-title");
    if (titleInput) {
        titleInput.disabled = false;
    }
    
    const teamCards = document.querySelectorAll(".sg-team-card");
    
    for (const card of teamCards) {
        const teamId = parseInt(card.getAttribute("data-team"));
        if (!teamId) continue;
        
        const area = document.getElementById(`sg-textarea-${teamId}`);
        if (!area) continue;
        
        // 取得該團隊的建議 ID（表格列優先從 data-suggest-id 讀取）
        let suggestId = null;
        const rowSuggestId = card.getAttribute("data-suggest-id");
        if (rowSuggestId) {
            suggestId = parseInt(rowSuggestId) || null;
        }
        if (!suggestId) {
            try {
                const deleteBtn = card.querySelector('.sg-btn-delete-row[data-suggest-id]');
                if (deleteBtn) {
                    const sid = deleteBtn.getAttribute("data-suggest-id");
                    if (sid) suggestId = parseInt(sid) || null;
                }
            } catch (e) {}
        }
        if (!suggestId) {
            try {
                const btnGroup = document.getElementById(`sg-btns-${teamId}`);
                if (btnGroup) {
                    const deleteBtn = btnGroup.querySelector('.sg-btn-del');
                    if (deleteBtn) {
                        const sid = deleteBtn.getAttribute('data-suggest-id');
                        if (sid) suggestId = parseInt(sid) || null;
                    }
                }
            } catch (e) {}
        }
        if (!suggestId) {
            try {
                const titleInput = document.getElementById("sg-title");
                const currentTitle = titleInput ? titleInput.value.trim() : '';
                if (currentTitle) {
                    const apiUrl = resolveSuggestApiUrl();
                    const r = await fetch(`${apiUrl}?action=listSuggests&team_ID=${teamId}`);
                    const j = await r.json();
                    if (j.success && j.data && j.data.length > 0) {
                        const matchingSuggest = j.data.find(s => 
                            s.suggest_name && s.suggest_name.trim() === currentTitle
                        );
                        if (matchingSuggest) {
                            suggestId = matchingSuggest.suggest_ID;
                        }
                    }
                }
            } catch (e) {
                console.warn(`無法取得團隊 ${teamId} 的 suggestId:`, e);
            }
        }
        
        // 設為可編輯模式（解除唯讀，讓團隊建議與審查結果可編輯）
        setEditableMode(teamId, suggestId);
    }
    
    // 重新綁定 textarea 事件（確保編輯模式下的檢查生效）
    bindAutoNumberEvent();
    
    // 更新統一編輯按鈕狀態
    const editAllBtn = document.getElementById("sg-edit-all-btn");
    const saveBtn = document.getElementById("sg-save-btn");
    const addTeamBtn = document.getElementById("sg-add-team-btn");
    const cohortSelect = document.getElementById("sg-cohort");
    const groupSelect = document.getElementById("sg-group");
    const cohortId = cohortSelect ? cohortSelect.value : null;
    const groupId = groupSelect ? groupSelect.value : null;
    
    if (editAllBtn) {
        editAllBtn.style.display = 'none';
    }
    if (saveBtn) {
        // 強制啟用存檔按鈕（移除 disabled 屬性）
        saveBtn.removeAttribute('disabled');
        saveBtn.disabled = false; // 確保 disabled 屬性為 false
        saveBtn.style.display = 'inline-block';
        console.log('編輯模式：存檔按鈕已啟用', {
            disabled: saveBtn.disabled,
            display: saveBtn.style.display,
            hasDisabledAttr: saveBtn.hasAttribute('disabled')
        });
    }
    // 顯示加入團隊按鈕（編輯模式下且有標題時）
    if (addTeamBtn) {
        const titleInput = document.getElementById("sg-title");
        const hasTitle = titleInput && titleInput.value.trim() !== '';
        const isInEditMode = saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
        if (hasTitle && isInEditMode) {
            addTeamBtn.disabled = false;
            addTeamBtn.style.display = 'inline-block';
        } else {
            addTeamBtn.disabled = true;
            addTeamBtn.style.display = 'none';
        }
    }
    
    // 重新初始化拖放功能（確保編輯模式下啟用拖動）
    if (cohortId && groupId) {
        initDragAndDrop(cohortId, groupId);
    }
    // 啟用操作按鈕（評分、刪除）
    updateActionButtonsState();
}

/* ==========================================
   7-3-1. 編輯單個建議（保留以備用）
========================================== */
function editSuggest(teamId, suggestId) {
    setEditableMode(teamId, suggestId);
    const area = document.getElementById(`sg-textarea-${teamId}`);
    if (area) {
        area.focus();
        // 移動游標到最後
        area.setSelectionRange(area.value.length, area.value.length);
    }
}

/* ==========================================
   7-4. 取消編輯
========================================== */
async function cancelEdit(teamId) {
    // 重新載入原始內容
    await loadTeamSuggest(teamId);
}

/* ==========================================
   8. 刪除建議
========================================== */
async function deleteSuggest(id, teamId) {
    const result = await Swal.fire({
        icon: "warning",
        title: "確定要刪除此團隊？",
        text: "刪除後該團隊的建議與所有評分（含團隊分數、個別學生評分）都會一併移除。",
        showCancelButton: true,
        confirmButtonText: "確定",
        cancelButtonText: "取消",
        reverseButtons: true
    });
    if (!result.isConfirmed) return;

    try {
        const fd = new FormData();
        fd.append("action", "deleteSuggest");
        fd.append("suggest_ID", id);

        const apiUrl = resolveSuggestApiUrl();
        const r = await fetch(apiUrl, { method: "POST", body: fd });
        const j = await r.json();

        if (!j.success) throw j.msg;

        Toast.fire({ icon: "success", title: "已刪除", text: "該團隊已從建議表中移除" });
        
        // 從畫面上移除該團隊列（可能同時存在表格列與卡片，全部移除）
        deletedTeams.add(teamId);
        document.querySelectorAll(`.sg-team-card[data-team="${teamId}"]`).forEach(function (el) { el.remove(); });
        const detailRow = document.getElementById(`sg-teacher-detail-${teamId}`);
        if (detailRow) detailRow.remove();
        updateTeamNumbers();

    } catch (err) {
        Toast.fire({ icon: "error", title: "刪除失敗" });
    }
}

/* ==========================================
   刪除團隊（第一次編輯時使用）
========================================== */
async function deleteTeamFromSuggest(teamId) {
    const teamCard = document.querySelector(`.sg-team-card[data-team="${teamId}"]`);
    if (!teamCard) return;
    
    const teamTitle = teamCard.querySelector('.sg-team-title');
    const teamName = teamTitle ? teamTitle.textContent.trim() : `團隊 ${teamId}`;
    
    const result = await Swal.fire({
        icon: "warning",
        title: "確定要刪除此團隊？",
        text: "刪除後該團隊將不會出現在建議表中，且該團隊的建議與所有評分（含團隊分數、個別學生評分）都會一併移除。存檔後生效。",
        showCancelButton: true,
        confirmButtonText: "確定",
        cancelButtonText: "取消",
        reverseButtons: true
    });
    
    if (!result.isConfirmed) return;
    
    deletedTeams.add(teamId);
    // 可能同時存在表格列與卡片，全部移除
    document.querySelectorAll(`.sg-team-card[data-team="${teamId}"]`).forEach(function (el) { el.remove(); });
    const detailRow = document.getElementById(`sg-teacher-detail-${teamId}`);
    if (detailRow) detailRow.remove();
    updateTeamNumbers();
    
    Toast.fire({ 
        icon: "success", 
        title: "已刪除",
        text: "存檔後此團隊將不會出現在建議表中，相關評分也會一併移除"
    });
}


/* ==========================================
   8-1. 存檔所有建議
========================================== */
async function saveAllSuggestions(cohortId, groupId) {
    const titleInput = document.getElementById("sg-title");
    const titleName = titleInput ? titleInput.value.trim() : "";
    
    if (!titleName) {
        Toast.fire({ 
            icon: "warning", 
            title: "請輸入標題",
            text: "存檔前請先輸入建議表標題"
        });
        return;
    }
    
    // 取得所有團隊卡片（排除被刪除的團隊）
    const allTeamCards = document.querySelectorAll(".sg-team-card");
    const teamCards = Array.from(allTeamCards).filter(card => {
        const teamId = parseInt(card.getAttribute("data-team"));
        return teamId && !deletedTeams.has(teamId);
    });
    
    if (teamCards.length === 0) {
        Toast.fire({ 
            icon: "warning", 
            title: "沒有團隊資料",
            text: "請先選擇屆別和類組"
        });
        return;
    }
    
    
    // 第一步：先檢查所有團隊，記錄錯誤（不建立 savePromises）
    const teamsWithoutContent = []; // 記錄沒有填寫建議內容的團隊
    const teamsWithoutStatus = []; // 記錄沒有選擇審查結果的團隊
    const teamsOnlyNumbers = [];   // 記錄建議內容僅有數字/編號、缺少文字說明的團隊
    const teamDataMap = new Map(); // 儲存團隊資料，用於後續存檔
    
    // 與後端一致的檢查：內容是否僅有數字/編號（至少需有一行含文字說明）
    function isSuggestionOnlyNumbers(text) {
        if (!text || typeof text !== "string") return true;
        const lines = text.split(/\r\n|\r|\n/);
        let hasValid = false;
        for (const line of lines) {
            const t = line.trim();
            if (t === "") continue;
            let lineContent = t.replace(/^\s*\d+[.、):]\s*/u, "").replace(/^\s*\d+\s+/u, "").trim();
            if (lineContent !== "") {
                const noPunct = lineContent.replace(/[.、):，。！？\s]/gu, "");
                if (noPunct !== "" && !/^\d+$/u.test(noPunct)) { hasValid = true; break; }
            }
        }
        return !hasValid;
    }
    
    // 檢查所有顯示的團隊（排除被刪除的）
    console.log(`開始檢查 ${teamCards.length} 個團隊卡片（已排除 ${deletedTeams.size} 個被刪除的團隊）`);
    
    for (const card of teamCards) {
        const teamId = parseInt(card.getAttribute("data-team"));
        if (!teamId || deletedTeams.has(teamId)) {
            console.log(`跳過無效或被刪除的團隊卡片:`, card);
            continue;
        }
        
        const area = document.getElementById(`sg-textarea-${teamId}`);
        const statusSelect = document.getElementById(`sg-status-${teamId}`);
        
        if (!area) {
            console.log(`團隊 ${teamId} 找不到 textarea，跳過`);
            continue;
        }
        
        // 取得團隊名稱
        const teamTitle = card.querySelector('.sg-team-title');
        const teamName = teamTitle ? teamTitle.textContent.trim() : `團隊 ${teamId}`;
        
        const content = area.value.trim();
        // 確保正確獲取狀態值
        let status = "";
        if (statusSelect) {
            status = (statusSelect.value || "").trim();
        } else {
            console.log(`團隊 ${teamName} (ID: ${teamId}) 找不到 statusSelect`);
        }
        
        // 調試：記錄檢查結果
        console.log(`存檔檢查 - 團隊 ${teamName} (ID: ${teamId}): 內容="${content}", 狀態="${status}"`);
        
        // 檢查1：須選擇審查結果/初審建議（review=審查結果，topic=初審建議）
        const hasValidStatus = statusSelect && statusSelect.value && statusSelect.value.trim() !== "" && status !== "";
        if (!hasValidStatus) {
            console.log(`✓ 團隊 ${teamName} 沒有選擇審查結果，加入 teamsWithoutStatus`);
            teamsWithoutStatus.push(teamName);
        }
        
        // 檢查2：所有團隊都必須填寫建議內容
        const trimmedContent = content.replace(/^\s*\d+[.、):]\s*/u, '').trim();
        const hasValidContent = content && content.trim() !== "" && trimmedContent !== "";
        if (!hasValidContent) {
            console.log(`✓ 團隊 ${teamName} 沒有填寫建議內容，加入 teamsWithoutContent`);
            teamsWithoutContent.push(teamName);
        } else if (isSuggestionOnlyNumbers(content)) {
            teamsOnlyNumbers.push(teamName);
        }
        
        if (hasValidContent && hasValidStatus && !isSuggestionOnlyNumbers(content)) {
            teamDataMap.set(teamId, { teamId, teamName });
        }
    }
    
    // 第二步：檢查是否有任何錯誤，如果有則立即返回，不執行任何存檔操作
    console.log(`存檔前最終檢查: teamsWithoutContent=${teamsWithoutContent.length}, teamsWithoutStatus=${teamsWithoutStatus.length}`);
    console.log(`teamsWithoutStatus 列表:`, teamsWithoutStatus);
    console.log(`teamsWithoutContent 列表:`, teamsWithoutContent);
    console.log(`有效團隊數: ${teamDataMap.size}, 總團隊數: ${teamCards.length}`);
    
    // 收集所有錯誤訊息
    const errorMessages = [];
    
    const statusLabel = (window.suggestFormSfType === 'topic') ? '初審建議' : '審查結果';
    if (teamsWithoutStatus.length > 0) {
        errorMessages.push(`以下團隊未選擇${statusLabel}：<strong>${teamsWithoutStatus.join('、')}</strong>`);
    }
    
    // 檢查：如果有任何團隊沒有填寫建議內容，記錄錯誤
    if (teamsWithoutContent.length > 0) {
        errorMessages.push(`以下團隊未填寫建議內容：<strong>${teamsWithoutContent.join('、')}</strong>`);
    }
    if (teamsOnlyNumbers.length > 0) {
        errorMessages.push(`以下團隊的建議內容請補充文字說明（不可僅有數字或編號）：<strong>${teamsOnlyNumbers.join('、')}</strong>`);
    }
    
    // 重要：必須確保所有團隊都有完整的資料才能存檔
    // 如果有任何團隊缺少審查結果或建議內容，必須阻止存檔
    if (errorMessages.length > 0) {
        console.log(`阻止存檔：發現 ${errorMessages.length} 個錯誤`);
        console.log(`錯誤訊息:`, errorMessages);
        
        // 使用 Swal.fire 顯示錯誤對話框
        Swal.fire({
            icon: "error",
            title: "請完整填寫所有團隊的建議",
            html: `<div style="text-align: left; margin-top: 10px;">${errorMessages.join('<br>')}</div>`,
            confirmButtonText: "確定",
            allowOutsideClick: false,
            allowEscapeKey: false,
            width: '500px'
        });
        
        return; // 立即返回，不執行任何存檔操作
    }
    
    // 額外檢查：確保所有團隊都有完整的資料
    // 如果有效團隊數不等於團隊總數，說明有團隊缺少資料
    if (teamDataMap.size !== teamCards.length) {
        console.log(`警告：有效團隊數 (${teamDataMap.size}) 不等於團隊總數 (${teamCards.length})`);
        Toast.fire({ 
            icon: "error", 
            title: "存檔失敗",
            text: `請確保所有團隊都已選擇${statusLabel}並填寫建議內容`
        });
        return;
    }
    
    // 如果沒有任何可儲存的內容
    if (teamDataMap.size === 0) {
        Toast.fire({ 
            icon: "warning", 
            title: "沒有可儲存的內容",
            text: `請填寫所有團隊的建議內容並選擇${statusLabel}`
        });
        return;
    }
    
    // 第三步：所有檢查都通過後，依表格順序（DOM 從上到下）取得 suggestId 並建立 savePromises
    // 新增時傳入 sortNo（1-based），後端寫入 suggest_sort_no，避免後來加入的團隊順序重複為 1
    const titleInputForOriginal = document.getElementById("sg-title");
    const originalTitle = titleInputForOriginal ? titleInputForOriginal.getAttribute('data-original-title') : null;
    
    const orderedTeamIds = teamCards.map(card => parseInt(card.getAttribute("data-team"))).filter(id => id && teamDataMap.has(id));
    const savePromises = [];
    for (let i = 0; i < orderedTeamIds.length; i++) {
        const teamId = orderedTeamIds[i];
        const sortNo = i + 1;
        try {
            const apiUrl = resolveSuggestApiUrl();
            const r = await fetch(`${apiUrl}?action=listSuggests&team_ID=${teamId}`);
            const j = await r.json();
            let suggestId = null;
            if (j.success && j.data && j.data.length > 0) {
                const existingSuggest = j.data.find(s => s.suggest_name && s.suggest_name.trim() === titleName);
                if (existingSuggest) {
                    suggestId = existingSuggest.suggest_ID;
                } else if (originalTitle && originalTitle !== titleName) {
                    const oldSuggest = j.data.find(s => s.suggest_name && s.suggest_name.trim() === originalTitle);
                    if (oldSuggest) suggestId = oldSuggest.suggest_ID;
                }
            }
            savePromises.push(saveSuggest(teamId, suggestId, suggestId ? null : sortNo));
        } catch (err) {
            console.error(`檢查團隊 ${teamId} 建議失敗:`, err);
        }
    }
    
    if (savePromises.length === 0) {
        Toast.fire({
            icon: "error",
            title: "存檔失敗",
            text: "無法取得團隊建議資料，請檢查網路連線或重新整理頁面"
        });
        return;
    }
    
    // 顯示載入提示
    const loadingToast = Toast.fire({
        icon: "info",
        title: "正在存檔...",
        text: `正在儲存 ${savePromises.length} 個團隊的建議`,
        timer: 0,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    try {
        // 若有從建議表移除的團隊，先刪除該團隊在此建議表下的建議與所有評分（含個別學生評分）
        if (deletedTeams.size > 0) {
            const apiUrl = resolveSuggestApiUrl();
            const groupIdParam = groupId || document.getElementById("sg-group")?.value || "";
            const sfIdRes = await fetch(`${apiUrl}?action=getSfIdByTitle&cohort_ID=${cohortId}&group_ID=${groupIdParam}&title=${encodeURIComponent(titleName)}`);
            const sfIdJson = await sfIdRes.json();
            if (sfIdJson.success && sfIdJson.data && sfIdJson.data.sf_ID) {
                const sf_ID = sfIdJson.data.sf_ID;
                for (const teamId of deletedTeams) {
                    const fd = new FormData();
                    fd.append("action", "deleteTeamFromSuggestForm");
                    fd.append("sf_ID", sf_ID);
                    fd.append("team_ID", teamId);
                    await fetch(apiUrl, { method: "POST", body: fd });
                }
            }
        }
        // 等待所有儲存完成
        await Promise.all(savePromises);
        
        await Swal.close();
        Toast.fire({ 
            icon: "success", 
            title: "存檔成功",
            text: `已成功儲存 ${savePromises.length} 個團隊的建議`
        });
        
        // 召集人＋審查建議表：存檔後詢問「是否發送給科辦」
        const wrapper = document.querySelector(".suggest-wrapper[data-suggest-page=\"true\"]");
        const isConvener = wrapper && wrapper.getAttribute("data-role-id") === "7";
        const isReviewForm = (window.suggestFormSfType || "") === "review";
        if (isConvener && isReviewForm) {
            const apiUrl = resolveSuggestApiUrl();
            const sfIdRes = await fetch(`${apiUrl}?action=getSfIdByTitle&cohort_ID=${cohortId}&group_ID=${groupId || document.getElementById("sg-group")?.value || ""}&title=${encodeURIComponent(titleName)}`);
            const sfIdJson = await sfIdRes.json();
            const sf_ID = sfIdJson.success && sfIdJson.data && sfIdJson.data.sf_ID ? sfIdJson.data.sf_ID : null;
            if (sf_ID) {
                const sendResult = await Swal.fire({
                    icon: "question",
                    title: "是否發送給科辦？",
                    text: "送交後科辦將可於列表中看到此建議表，並會收到最新消息通知。",
                    showCancelButton: true,
                    confirmButtonText: "發送給科辦",
                    cancelButtonText: "僅存檔"
                });
                if (sendResult.isConfirmed) {
                    try {
                        const fd = new FormData();
                        fd.append("action", "sendSuggestToOffice");
                        fd.append("sf_ID", sf_ID);
                        fd.append("title", titleName);
                        fd.append("cohort_ID", cohortId);
                        const r = await fetch(apiUrl, { method: "POST", body: fd });
                        const j = await r.json();
                        if (j.success) {
                            Toast.fire({ icon: "success", title: "已送交科辦並已通知科辦" });
                        } else {
                            Toast.fire({ icon: "warning", title: j.msg || "送交科辦失敗" });
                        }
                    } catch (e) {
                        console.error("sendSuggestToOffice 錯誤:", e);
                        Toast.fire({ icon: "error", title: "送交科辦失敗" });
                    }
                }
            }
        }
        
        // 存檔成功後離開編輯模式：清除編輯旗標，後續 checkAndDisplayMode 才不會又套用編輯模式
        window.SuggestEditAllMode = false;
        
        // 存檔成功後，清除被刪除的團隊列表（因為已經存檔，這些團隊不會再出現）
        deletedTeams.clear();
        
        // 存檔後依畫面順序寫入資料庫 suggest_sort_no（使用者若有拖曳調整要以實際順序為準）
        await saveTeamOrder(cohortId, groupId);
        
        // 重新載入團隊列表（這樣被刪除的團隊就不會再出現了）
        const titleInput = document.getElementById("sg-title");
        const currentTitle = titleInput ? titleInput.value.trim() : "";
        if (currentTitle) {
            await loadTeamsWithTitle(cohortId, groupId, currentTitle);
        } else {
            await loadTeams(cohortId, groupId, true);
        }
        
        // 恢復統一編輯按鈕顯示（已送交科辦的建議表不顯示編輯）
        const editAllBtn = document.getElementById("sg-edit-all-btn");
        const saveBtn = document.getElementById("sg-save-btn");
        if (editAllBtn) {
            if (!window.SuggestReadOnlyForConvener) {
                editAllBtn.style.display = 'inline-block';
            }
        }
        if (saveBtn) {
            saveBtn.style.display = 'none';
        }
        
        // 重新載入已存在的標題列表（更新下拉選單選項）
        await loadExistingTitles(cohortId, groupId);
        
        // 再次確保順序已保存至資料庫
        await saveTeamOrder(cohortId, groupId);
        
        // 存檔後隱藏第二行篩選框（選擇標題和選擇審查結果）
        const filterRow2 = document.getElementById("sg-filter-row2");
        const filterHint = document.getElementById("sg-filter-hint");
        if (filterRow2) filterRow2.style.display = 'none';
        if (filterHint) filterHint.style.display = 'none';
        
        // 存檔後跳轉到初始頁面
        await checkAndDisplayMode(cohortId, groupId);
        
        // 存檔後重新初始化拖放功能（確保非編輯模式下禁用拖動）
        const currentGroupId = document.getElementById("sg-group")?.value || groupId;
        initDragAndDrop(cohortId, currentGroupId);
        
    } catch (err) {
        await Swal.close();
        console.error("存檔失敗:", err);
        Toast.fire({ 
            icon: "error", 
            title: "存檔失敗",
            text: err.message || "請稍後再試"
        });
    }
}

/* ==========================================
   9. 匯出建議
========================================== */
async function exportSuggestions(cohortId, groupId) {
    const apiUrl = resolveSuggestApiUrl();
    
    // 獲取使用者輸入的標題
    const titleInput = document.getElementById("sg-title");
    const userTitle = titleInput ? titleInput.value.trim() : "";
    
    if (!userTitle) {
        Toast.fire({
            icon: "warning",
            title: "請先輸入標題",
            text: "匯出前請先輸入建議表標題"
        });
        return;
    }
    
    // 只檢查當前顯示的團隊卡片（不是所有團隊）
    const teamCards = document.querySelectorAll(".sg-team-card");
    if (teamCards.length === 0) {
        Toast.fire({
            icon: "warning",
            title: "沒有團隊資料",
            text: "請先選擇團隊"
        });
        return;
    }
    
    // 檢查當前顯示的團隊是否都有建議
    const teamsWithoutSuggest = [];
    for (const card of teamCards) {
        const teamId = parseInt(card.getAttribute("data-team"));
        if (!teamId) continue;
        
        const area = document.getElementById(`sg-textarea-${teamId}`);
        if (!area) continue;
        
        const content = area.value.trim();
        if (!content) {
            // 取得團隊名稱
            const teamTitle = card.querySelector('.sg-team-title');
            const teamName = teamTitle ? teamTitle.textContent.trim() : `團隊 ${teamId}`;
            teamsWithoutSuggest.push(teamName);
        }
    }
    
    if (teamsWithoutSuggest.length > 0) {
        // 如果有團隊沒有建議，顯示錯誤訊息
        Toast.fire({
            icon: "error",
            html: `<div>以下團隊尚未填寫建議：<br><strong>${teamsWithoutSuggest.join('、')}</strong></div>`
        });
        return;
    }
    
    // 所有當前顯示的團隊都有建議，繼續匯出
    try {
        
        // 所有團隊都有建議，繼續匯出
        // 收集當前顯示的團隊ID和組ID
        const displayedTeamIds = [];
        const teamGroupMap = {}; // teamId -> groupId 映射
        for (const card of teamCards) {
            const teamId = parseInt(card.getAttribute("data-team"));
            const cardGroupId = card.getAttribute("data-group");
            if (teamId) {
                displayedTeamIds.push(teamId);
                if (cardGroupId) {
                    teamGroupMap[teamId] = parseInt(cardGroupId);
                }
            }
        }
        
        let teamOrder = null;
        let sortedTeamIds = [];
        const tbodyExport = document.getElementById("sg-team-tbody");
        if (tbodyExport) {
            tbodyExport.querySelectorAll("tr.sg-team-card").forEach(row => {
                const teamId = parseInt(row.getAttribute("data-team"));
                if (teamId && displayedTeamIds.includes(teamId)) sortedTeamIds.push(teamId);
            });
        } else {
            const leftCards = Array.from(document.querySelectorAll("#sg-team-left .sg-team-card"));
            const rightCards = Array.from(document.querySelectorAll("#sg-team-right .sg-team-card"));
            const maxRows = Math.max(leftCards.length, rightCards.length);
            for (let i = 0; i < maxRows; i++) {
                if (i < leftCards.length) {
                    const teamId = parseInt(leftCards[i].getAttribute("data-team"));
                    if (teamId && displayedTeamIds.includes(teamId)) sortedTeamIds.push(teamId);
                }
                if (i < rightCards.length) {
                    const teamId = parseInt(rightCards[i].getAttribute("data-team"));
                    if (teamId && displayedTeamIds.includes(teamId)) sortedTeamIds.push(teamId);
                }
            }
        }
        displayedTeamIds.forEach(teamId => {
            if (!sortedTeamIds.includes(teamId)) sortedTeamIds.push(teamId);
        });
        teamOrder = sortedTeamIds;
        console.log(`匯出時使用當前頁面顯示順序:`, teamOrder);
        
        const path = window.location.pathname || '';
        let exportUrl = 'pages/suggest_export.php';
        if (path.includes('/pages/')) {
            exportUrl = 'suggest_export.php';
        }
        
        const params = new URLSearchParams({
            cohort_ID: cohortId,
            group_ID: groupId
        });
        
        // 如果有使用者輸入的標題，將它作為參數傳遞
        if (userTitle) {
            params.append('title', userTitle);
        }
        
        // 傳遞排序後的團隊ID列表（team_ids 用於查詢，team_order 用於排序）
        if (teamOrder && teamOrder.length > 0) {
            params.append('team_ids', JSON.stringify(teamOrder));
            params.append('team_order', JSON.stringify(teamOrder));
            console.log(`匯出 URL 參數 team_order:`, teamOrder);
        } else if (displayedTeamIds.length > 0) {
            params.append('team_ids', JSON.stringify(displayedTeamIds));
        }
        
        // 開啟新視窗下載 PDF
        window.open(`${exportUrl}?${params.toString()}`, '_blank');
        
    } catch (error) {
        console.error('檢查建議時發生錯誤:', error);
        Toast.fire({
            icon: "error",
            title: "檢查建議時發生錯誤，請稍後再試"
        });
    }
}

/* ==========================================
   10. 匯出建議（Word格式）
========================================== */
async function exportSuggestionsWord(cohortId, groupId) {
    const apiUrl = resolveSuggestApiUrl();
    
    // 獲取使用者輸入的標題
    const titleInput = document.getElementById("sg-title");
    const userTitle = titleInput ? titleInput.value.trim() : "";
    
    if (!userTitle) {
        Toast.fire({
            icon: "warning",
            title: "請先輸入標題",
            text: "匯出前請先輸入建議表標題"
        });
        return;
    }
    
    // 只檢查當前顯示的團隊卡片（不是所有團隊）
    const teamCards = document.querySelectorAll(".sg-team-card");
    if (teamCards.length === 0) {
        Toast.fire({
            icon: "warning",
            title: "沒有團隊資料",
            text: "請先選擇團隊"
        });
        return;
    }
    
    // 檢查當前顯示的團隊是否都有建議
    const teamsWithoutSuggest = [];
    for (const card of teamCards) {
        const teamId = parseInt(card.getAttribute("data-team"));
        if (!teamId) continue;
        
        const area = document.getElementById(`sg-textarea-${teamId}`);
        if (!area) continue;
        
        const content = area.value.trim();
        if (!content) {
            // 取得團隊名稱
            const teamTitle = card.querySelector('.sg-team-title');
            const teamName = teamTitle ? teamTitle.textContent.trim() : `團隊 ${teamId}`;
            teamsWithoutSuggest.push(teamName);
        }
    }
    
    if (teamsWithoutSuggest.length > 0) {
        // 如果有團隊沒有建議，顯示錯誤訊息
        Toast.fire({
            icon: "error",
            html: `<div>以下團隊尚未填寫建議：<br><strong>${teamsWithoutSuggest.join('、')}</strong></div>`
        });
        return;
    }
    
    // 所有當前顯示的團隊都有建議，繼續匯出
    try {
        
        // 所有團隊都有建議，繼續匯出
        // 收集當前顯示的團隊ID和組ID
        const displayedTeamIds = [];
        const teamGroupMap = {}; // teamId -> groupId 映射
        for (const card of teamCards) {
            const teamId = parseInt(card.getAttribute("data-team"));
            const cardGroupId = card.getAttribute("data-group");
            if (teamId) {
                displayedTeamIds.push(teamId);
                if (cardGroupId) {
                    teamGroupMap[teamId] = parseInt(cardGroupId);
                }
            }
        }
        
        let teamOrder = null;
        let sortedTeamIdsWord = [];
        const tbodyWord = document.getElementById("sg-team-tbody");
        if (tbodyWord) {
            tbodyWord.querySelectorAll("tr.sg-team-card").forEach(row => {
                const teamId = parseInt(row.getAttribute("data-team"));
                if (teamId && displayedTeamIds.includes(teamId)) sortedTeamIdsWord.push(teamId);
            });
        } else {
            const leftCards = Array.from(document.querySelectorAll("#sg-team-left .sg-team-card"));
            const rightCards = Array.from(document.querySelectorAll("#sg-team-right .sg-team-card"));
            const maxRows = Math.max(leftCards.length, rightCards.length);
            for (let i = 0; i < maxRows; i++) {
                if (i < leftCards.length) {
                    const teamId = parseInt(leftCards[i].getAttribute("data-team"));
                    if (teamId && displayedTeamIds.includes(teamId)) sortedTeamIdsWord.push(teamId);
                }
                if (i < rightCards.length) {
                    const teamId = parseInt(rightCards[i].getAttribute("data-team"));
                    if (teamId && displayedTeamIds.includes(teamId)) sortedTeamIdsWord.push(teamId);
                }
            }
        }
        displayedTeamIds.forEach(teamId => {
            if (!sortedTeamIdsWord.includes(teamId)) sortedTeamIdsWord.push(teamId);
        });
        teamOrder = sortedTeamIdsWord;
        console.log(`匯出Word時使用當前頁面顯示順序:`, teamOrder);
        
        const path = window.location.pathname || '';
        let exportUrl = 'pages/suggest_export_word.php';
        if (path.includes('/pages/')) {
            exportUrl = 'suggest_export_word.php';
        }
        
        const params = new URLSearchParams({
            cohort_ID: cohortId,
            group_ID: groupId
        });
        
        // 如果有使用者輸入的標題，將它作為參數傳遞
        if (userTitle) {
            params.append('title', userTitle);
        }
        
        // 傳遞排序後的團隊ID列表（team_ids 用於查詢，team_order 用於排序）
        if (teamOrder && teamOrder.length > 0) {
            params.append('team_ids', JSON.stringify(teamOrder));
            params.append('team_order', JSON.stringify(teamOrder));
            console.log(`匯出Word URL 參數 team_order:`, teamOrder);
        } else if (displayedTeamIds.length > 0) {
            params.append('team_ids', JSON.stringify(displayedTeamIds));
        }
        
        // 開啟新視窗下載 Word
        window.open(`${exportUrl}?${params.toString()}`, '_blank');
        
    } catch (error) {
        console.error('檢查建議時發生錯誤:', error);
        Toast.fire({
            icon: "error",
            title: "檢查建議時發生錯誤，請稍後再試"
        });
    }
}

/* ==========================================
   10. 拖放排序功能（支持左右兩欄）
========================================== */
function initDragAndDrop(cohortId, groupId) {
    const tbody = document.getElementById("sg-team-tbody");
    if (!tbody) return;
    
    const saveBtn = document.getElementById("sg-save-btn");
    const isEditingMode = saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
    
    const cards = tbody.querySelectorAll("tr.sg-team-card");
    if (!isEditingMode) {
        cards.forEach(card => {
            card.draggable = false;
            card.style.cursor = 'default';
            card.ondragstart = function(e) { e.preventDefault(); return false; };
            card.ondrag = function(e) { e.preventDefault(); return false; };
        });
        return;
    }
    
    cards.forEach(card => {
        card.draggable = true;
        card.style.cursor = 'move';
        card.ondragstart = null;
        card.ondrag = null;
    });
    
    let draggedElement = null;
    
    function getAllCards() {
        return tbody.querySelectorAll("tr.sg-team-card");
    }
    
    function bindDragEvents() {
        getAllCards().forEach(card => {
            card.addEventListener("dragstart", function(e) {
                if (e.target.tagName === "TEXTAREA" || e.target.tagName === "BUTTON" || e.target.closest("button") || e.target.tagName === "SELECT") {
                    e.preventDefault();
                    this.draggable = false;
                    return false;
                }
                draggedElement = this;
                this.classList.add("sg-dragging");
                e.dataTransfer.effectAllowed = "move";
                e.dataTransfer.setData("text/plain", this.getAttribute("data-team") || "");
            });
            
            card.addEventListener("mousedown", function(e) {
                if (e.target.tagName !== "TEXTAREA" && e.target.tagName !== "BUTTON" && !e.target.closest("button") && e.target.tagName !== "SELECT") {
                    this.draggable = true;
                } else {
                    this.draggable = false;
                }
            });
            
            card.addEventListener("dragend", function(e) {
                this.classList.remove("sg-dragging");
                getAllCards().forEach(c => c.classList.remove("sg-drag-over", "sg-drag-over-before", "sg-drag-over-after"));
                draggedElement = null;
            });
            
            card.addEventListener("dragover", function(e) {
                if (e.preventDefault) e.preventDefault();
                e.dataTransfer.dropEffect = "move";
                if (draggedElement && draggedElement !== this) {
                    const rect = this.getBoundingClientRect();
                    const next = (e.clientY - rect.top) / (rect.bottom - rect.top) < 0.5;
                    getAllCards().forEach(c => c.classList.remove("sg-drag-over", "sg-drag-over-before", "sg-drag-over-after"));
                    this.classList.add("sg-drag-over", next ? "sg-drag-over-before" : "sg-drag-over-after");
                }
                return false;
            });
            
            card.addEventListener("dragleave", function(e) {
                this.classList.remove("sg-drag-over", "sg-drag-over-before", "sg-drag-over-after");
            });
            
            card.addEventListener("drop", function(e) {
                if (e.stopPropagation) e.stopPropagation();
                if (draggedElement && draggedElement !== this) {
                    const rect = this.getBoundingClientRect();
                    const next = (e.clientY - rect.top) / (rect.bottom - rect.top) < 0.5;
                    if (next) {
                        tbody.insertBefore(draggedElement, this);
                    } else {
                        tbody.insertBefore(draggedElement, this.nextSibling);
                    }
                    saveTeamOrder(cohortId, groupId);
                    updateTeamNumbers();
                }
                getAllCards().forEach(c => c.classList.remove("sg-drag-over", "sg-drag-over-before", "sg-drag-over-after"));
                return false;
            });
        });
    }
    
    bindDragEvents();
}

/* ==========================================
   10-1. 重新組織團隊到左右兩欄
========================================== */
function reorganizeTeams(cohortId, groupId) {
    const tbody = document.getElementById("sg-team-tbody");
    if (tbody) {
        saveTeamOrder(cohortId, groupId);
        updateTeamNumbers();
        return;
    }
    const leftColumn = document.getElementById("sg-team-left");
    const rightColumn = document.getElementById("sg-team-right");
    if (!leftColumn || !rightColumn) return;
    
    const leftCards = Array.from(leftColumn.querySelectorAll(".sg-team-card"));
    const rightCards = Array.from(rightColumn.querySelectorAll(".sg-team-card"));
    const maxRows = Math.max(leftCards.length, rightCards.length);
    const allCards = [];
    for (let i = 0; i < maxRows; i++) {
        if (i < leftCards.length) allCards.push(leftCards[i]);
        if (i < rightCards.length) allCards.push(rightCards[i]);
    }
    leftColumn.innerHTML = "";
    rightColumn.innerHTML = "";
    allCards.forEach((card, index) => {
        if (index % 2 === 0) leftColumn.appendChild(card);
        else rightColumn.appendChild(card);
    });
    saveTeamOrder(cohortId, groupId);
    updateTeamNumbers();
}

/* ==========================================
   10-2. 更新團隊編號（按照從上到下：左欄先，然後右欄）
========================================== */
function updateTeamNumbers() {
    const tbody = document.getElementById("sg-team-tbody");
    if (tbody) {
        const rows = Array.from(tbody.querySelectorAll("tr.sg-team-card"));
        rows.forEach((row, index) => {
            const numberEl = row.querySelector(".sg-team-number");
            if (numberEl) numberEl.textContent = (index + 1) + ". ";
        });
        return;
    }
    const leftColumn = document.getElementById("sg-team-left");
    const rightColumn = document.getElementById("sg-team-right");
    if (!leftColumn || !rightColumn) return;
    const leftCards = Array.from(leftColumn.querySelectorAll(".sg-team-card"));
    const rightCards = Array.from(rightColumn.querySelectorAll(".sg-team-card"));
    const maxRows = Math.max(leftCards.length, rightCards.length);
    let number = 1;
    for (let i = 0; i < maxRows; i++) {
        if (i < leftCards.length) {
            const numberElement = leftCards[i].querySelector(".sg-team-number");
            if (numberElement) numberElement.textContent = number + ". ";
            number++;
        }
        if (i < rightCards.length) {
            const numberElement = rightCards[i].querySelector(".sg-team-number");
            if (numberElement) numberElement.textContent = number + ". ";
            number++;
        }
    }
}

/* ==========================================
   10-2. 保存團隊順序（從上到下：左欄先，然後右欄）
   同時保存到 localStorage 和數據庫（suggest_sort_no）
========================================== */
async function saveTeamOrder(cohortId, groupId) {
    const tbody = document.getElementById("sg-team-tbody");
    let order = [];
    let cardsInOrder = [];
    if (tbody) {
        const rows = tbody.querySelectorAll("tr.sg-team-card");
        rows.forEach(row => {
            const teamId = parseInt(row.getAttribute("data-team"));
            if (teamId) {
                order.push(teamId);
                cardsInOrder.push(row);
            }
        });
    } else {
        const leftColumn = document.getElementById("sg-team-left");
        const rightColumn = document.getElementById("sg-team-right");
        if (!leftColumn || !rightColumn) {
            console.warn(`無法保存團隊順序：找不到表格或左右欄 (${cohortId}_${groupId})`);
            return;
        }
        const leftCards = Array.from(leftColumn.querySelectorAll(".sg-team-card"));
        const rightCards = Array.from(rightColumn.querySelectorAll(".sg-team-card"));
        const maxRows = Math.max(leftCards.length, rightCards.length);
        for (let i = 0; i < maxRows; i++) {
            if (i < leftCards.length) {
                const teamId = parseInt(leftCards[i].getAttribute("data-team"));
                if (teamId) {
                    order.push(teamId);
                    cardsInOrder.push(leftCards[i]);
                }
            }
            if (i < rightCards.length) {
                const teamId = parseInt(rightCards[i].getAttribute("data-team"));
                if (teamId) {
                    order.push(teamId);
                    cardsInOrder.push(rightCards[i]);
                }
            }
        }
    }
    
    if (order.length === 0) {
        console.warn(`無法保存團隊順序：沒有找到團隊 (${cohortId}_${groupId})`);
        return;
    }
    
    const titleInput = document.getElementById("sg-title");
    const title = titleInput ? titleInput.value.trim() : "";
    if (!title) console.warn("無法保存團隊順序到數據庫：沒有標題");
    
    if (groupId === "all") {
        const teamsByGroup = {};
        const allCards = cardsInOrder;
        
        // 從卡片中獲取每個團隊的 group_ID
        allCards.forEach(card => {
            const teamId = parseInt(card.getAttribute("data-team"));
            const groupIdFromCard = card.getAttribute("data-group");
            if (!teamId || !groupIdFromCard) return;
            
            const gId = parseInt(groupIdFromCard);
            if (!teamsByGroup[gId]) {
                teamsByGroup[gId] = [];
            }
            teamsByGroup[gId].push(teamId);
        });
        
        Object.keys(teamsByGroup).forEach(gId => {
            const groupOrder = allCards
                .filter(card => card.getAttribute("data-group") == gId)
                .map(card => parseInt(card.getAttribute("data-team")))
                .filter(id => id);
            if (groupOrder.length > 0) {
                const groupOrderKey = `suggest_team_order_${cohortId}_${gId}`;
                localStorage.setItem(groupOrderKey, JSON.stringify(groupOrder));
            }
        });
        
        const teamOrdersForDB = order.map((teamId, index) => ({ team_ID: teamId, sort_no: index + 1 }));
        
        // 保存到數據庫
        if (title && teamOrdersForDB.length > 0) {
            try {
                const apiUrl = resolveSuggestApiUrl();
                const formData = new FormData();
                formData.append("action", "updateSuggestSortNo");
                formData.append("cohort_ID", cohortId);
                formData.append("title", title);
                formData.append("team_orders", JSON.stringify(teamOrdersForDB));
                
                const response = await fetch(apiUrl, {
                    method: "POST",
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    console.log(`已保存團隊順序到數據庫 (全部模式):`, result);
                } else {
                    console.warn(`保存團隊順序到數據庫失敗:`, result.msg);
                }
            } catch (error) {
                console.error("保存團隊順序到數據庫時發生錯誤:", error);
            }
        }
    } else {
        // 單個類組，使用原有的保存邏輯
        const orderKey = `suggest_team_order_${cohortId}_${groupId}`;
        localStorage.setItem(orderKey, JSON.stringify(order));
        console.log(`已保存團隊順序到 localStorage (${cohortId}_${groupId}):`, order);
        
        // 保存到數據庫
        if (title && order.length > 0) {
            try {
                const apiUrl = resolveSuggestApiUrl();
                const formData = new FormData();
                formData.append("action", "updateSuggestSortNo");
                formData.append("cohort_ID", cohortId);
                formData.append("title", title);
                
                // 準備數據庫數據
                const teamOrdersForDB = order.map((teamId, index) => ({
                    team_ID: teamId,
                    sort_no: index + 1
                }));
                
                formData.append("team_orders", JSON.stringify(teamOrdersForDB));
                
                const response = await fetch(apiUrl, {
                    method: "POST",
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    console.log(`已保存團隊順序到數據庫 (單個類組模式):`, result);
                } else {
                    console.warn(`保存團隊順序到數據庫失敗:`, result.msg);
                }
            } catch (error) {
                console.error("保存團隊順序到數據庫時發生錯誤:", error);
            }
        }
    }
}

/* ==========================================
   11. 匯出建議檔案（從檔案列表）
========================================== */
window.exportSuggestFile = async function(title, cohortId, groupId) {
    try {
        const apiUrl = resolveSuggestApiUrl();
        const groupIdParam = groupId === "all" ? "all" : groupId;
        
        // 獲取該標題下的所有團隊ID（通過查詢該標題下的所有建議）
        // 使用 listTeams API 獲取所有團隊，然後檢查哪些團隊有該標題的建議
        const teamsResponse = await fetch(`${apiUrl}?action=listTeams&cohort_ID=${cohortId}&group_ID=${groupIdParam}`);
        const teamsData = await teamsResponse.json();
        
        if (!teamsData.success || !teamsData.data || teamsData.data.length === 0) {
            Toast.fire({
                icon: 'warning',
                title: '沒有團隊資料',
                text: '該標題下沒有可匯出的團隊'
            });
            return;
        }
        
        // 獲取該標題下實際有建議的團隊ID
        const teamIds = [];
        const checkPromises = teamsData.data.map(async (team) => {
            try {
                // 獲取該團隊的所有建議
                const suggestResponse = await fetch(`${apiUrl}?action=getTeamSuggests&team_ID=${team.team_ID}`);
                const suggestData = await suggestResponse.json();
                
                if (suggestData.success && suggestData.data) {
                    // 檢查是否有該標題的建議
                    const hasTitleSuggest = suggestData.data.some(s => 
                        s.suggest_name === title && 
                        [1, 2, 3, 4].includes(s.suggest_status)
                    );
                    
                    if (hasTitleSuggest) {
                        return parseInt(team.team_ID);
                    }
                }
                return null;
            } catch (err) {
                console.error(`檢查團隊 ${team.team_ID} 失敗:`, err);
                return null;
            }
        });
        
        const results = await Promise.all(checkPromises);
        teamIds.push(...results.filter(id => id !== null));
        
        if (teamIds.length === 0) {
            Toast.fire({
                icon: 'warning',
                title: '沒有建議資料',
                text: '該標題下沒有可匯出的建議'
            });
            return;
        }
        
        const path = window.location.pathname || '';
        let exportUrl = 'pages/suggest_export.php';
        if (path.includes('/pages/')) {
            exportUrl = 'suggest_export.php';
        }
        
        // 如果 groupId 是 "all"，需要處理：使用第一個有建議的團隊的 group_ID
        // 由於 suggest_export.php 需要 group_ID，我們需要傳遞一個有效的 group_ID
        // 但當傳遞了 team_ids 時，suggest_export.php 會使用 team_ids 來獲取團隊，group_ID 主要用於驗證
        let actualGroupId = groupId;
        if (groupId === "all") {
            // 從有建議的團隊中獲取第一個團隊的 group_ID
            if (teamIds.length > 0) {
                // 找到第一個有建議的團隊
                const firstTeamWithSuggest = teamsData.data.find(team => teamIds.includes(parseInt(team.team_ID)));
                actualGroupId = firstTeamWithSuggest?.group_ID || "1";
            } else {
                actualGroupId = teamsData.data[0]?.group_ID || "1";
            }
        }
        
        const params = new URLSearchParams({
            cohort_ID: cohortId,
            group_ID: actualGroupId,
            title: title,
            team_ids: JSON.stringify(teamIds)
        });
        
        // 開啟新視窗匯出該文件
        window.open(`${exportUrl}?${params.toString()}`, '_blank');
    } catch (error) {
        console.error('匯出失敗:', error);
        Toast.fire({
            icon: 'error',
            title: '匯出失敗',
            text: '請稍後再試'
        });
    }
}

/* ==========================================
   12. 發送建議通知（從檔案列表）
========================================== */
window.sendSuggestNotification = async function(title, cohortId, groupId) {
    try {
        // 確認對話框
        const result = await Swal.fire({
            title: '確認發送通知',
            html: `確定要發送建議表「<strong>${escapeHtml(title)}</strong>」的通知嗎？`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '確定',
            cancelButtonText: '取消',
            confirmButtonColor: '#667eea',
            cancelButtonColor: '#6c757d',
            reverseButtons: true
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
        const response = await fetch('api.php?do=send_suggest_notification', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                title: title,
                cohort_ID: cohortId,
                group_ID: groupId
            })
        });
        
        const data = await response.json();
        
        if (data.ok === true || data.status === 'ok' || data.success) {
            Swal.fire({
                icon: 'success',
                title: '發送成功',
                text: data.message || '通知已成功發送給當屆所有人',
                timer: 2000,
                showConfirmButton: false
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: '發送失敗',
                text: data.message || '請稍後再試'
            });
        }
    } catch (error) {
        console.error('發送通知失敗:', error);
        Swal.fire({
            icon: 'error',
            title: '發送失敗',
            text: '請稍後再試'
        });
    }
}

/* ==========================================
   加入團隊對話框
========================================== */
async function showAddTeamDialog() {
    // 檢查是否處於編輯模式（存檔按鈕顯示且啟用）
    const saveBtn = document.getElementById("sg-save-btn");
    const isInEditMode = saveBtn && saveBtn.style.display !== 'none' && !saveBtn.disabled;
    
    if (!isInEditMode) {
        Toast.fire({
            icon: "warning",
            title: "請先進入編輯模式",
            text: "請點擊「編輯」按鈕後再添加團隊"
        });
        return;
    }
    
    const cohortSelect = document.getElementById("sg-cohort");
    const groupSelect = document.getElementById("sg-group");
    const titleInput = document.getElementById("sg-title");
    
    if (!cohortSelect || !groupSelect) {
        Toast.fire({
            icon: "warning",
            title: "請先選擇屆別和類組"
        });
        return;
    }
    
    const cohortId = cohortSelect.value;
    const groupId = groupSelect.value;
    const title = titleInput ? titleInput.value.trim() : '';
    
    if (!cohortId || !groupId) {
        Toast.fire({
            icon: "warning",
            title: "請先選擇屆別和類組"
        });
        return;
    }
    
    try {
        // 加入團隊候選 = 該屆別/類組中「不在建議表中」的所有團隊（時程表僅供預測，不作為篩選條件）
        const apiUrl = resolveSuggestApiUrl();
        const groupIdParam = groupId === "all" ? "all" : groupId;

        // 獲取該屆別/類組的所有團隊（不依時程表篩選）
        const allTeamsR = await fetch(`${apiUrl}?action=listTeams&cohort_ID=${cohortId}&group_ID=${groupIdParam}`);
        const allTeamsJ = await allTeamsR.json();

        if (!allTeamsJ.success || !allTeamsJ.data) {
            throw new Error("無法載入團隊列表");
        }

        // 獲取當前建議表中已有的團隊
        const currentTeamCards = document.querySelectorAll(".sg-team-card");
        const currentTeamIds = new Set(Array.from(currentTeamCards).map(card => {
            const teamId = parseInt(card.getAttribute("data-team"));
            return isNaN(teamId) ? null : teamId;
        }).filter(id => id !== null));

        console.log(`當前建議表中已有的團隊 ID:`, Array.from(currentTeamIds));

        // 可加入的團隊 = 不在當前建議表中的團隊，或本 session 曾刪除可加回
        const availableTeams = allTeamsJ.data.filter(team => {
            const teamId = parseInt(team.team_ID);
            if (isNaN(teamId)) return false;

            const notInCurrent = !currentTeamIds.has(teamId);
            const wasDeletedThisSession = deletedTeams.has(teamId);

            return notInCurrent || wasDeletedThisSession;
        });
        
        console.log(`可加入的團隊:`, availableTeams.map(t => ({ team_ID: t.team_ID, name: t.team_project_name })));
        console.log(`所有團隊數: ${allTeamsJ.data.length}, 當前建議表中的團隊數: ${currentTeamIds.size}, 可加入的團隊數: ${availableTeams.length}`);

        if (availableTeams.length === 0) {
            Toast.fire({
                icon: "info",
                title: "沒有可加入的團隊",
                text: "該屆別/類組中所有團隊都已在建議表中"
            });
            return;
        }
        
        // 構建團隊選擇列表 HTML
        let teamsHtml = '<div style="max-height: 400px; overflow-y: auto; text-align: left;">';
        availableTeams.forEach(team => {
            const teamId = parseInt(team.team_ID); // 確保是數字
            const teamName = team.team_project_name || `團隊 ${teamId}`;
            const groupName = team.group_name || '';
            console.log(`構建團隊選擇項: team_ID=${teamId}, name=${teamName}`);
            teamsHtml += `
                <div style="padding: 8px; border-bottom: 1px solid #e0e0e0;">
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" class="sg-add-team-checkbox" value="${teamId}" 
                               data-team-id="${teamId}" style="margin-right: 10px; width: 18px; height: 18px;">
                        <div>
                            <div style="font-weight: 600;">${escapeHtml(teamName)}</div>
                            <div style="font-size: 12px; color: #666;">${escapeHtml(groupName)}</div>
                        </div>
                    </label>
                </div>
            `;
        });
        teamsHtml += '</div>';
        
        console.log(`構建的團隊選擇列表，共 ${availableTeams.length} 個團隊`);
        
        // 顯示選擇對話框
        const result = await Swal.fire({
            title: '選擇要加入的團隊',
            html: teamsHtml,
            width: '600px',
            showCancelButton: true,
            confirmButtonText: '加入',
            cancelButtonText: '取消',
            didOpen: () => {
                // 綁定全選/取消全選功能
                const checkboxes = document.querySelectorAll('.sg-add-team-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        // 可以添加全選邏輯
                    });
                });
            },
            preConfirm: () => {
                const selectedCheckboxes = document.querySelectorAll('.sg-add-team-checkbox:checked');
                const selectedTeamIds = Array.from(selectedCheckboxes).map(cb => {
                    const value = cb.value;
                    const teamIdAttr = cb.getAttribute('data-team-id');
                    const teamId = parseInt(value) || parseInt(teamIdAttr);
                    console.log(`選中的團隊 checkbox: value=${value}, data-team-id=${teamIdAttr}, parsed=${teamId}`);
                    return teamId;
                }).filter(id => !isNaN(id) && id > 0);
                
                console.log(`preConfirm: 選中的團隊 ID 列表:`, selectedTeamIds);
                
                if (selectedTeamIds.length === 0) {
                    Swal.showValidationMessage('請至少選擇一個團隊');
                    return false;
                }
                
                return selectedTeamIds;
            }
        });
        
        if (result.isConfirmed && result.value) {
            const selectedTeamIds = result.value;
            console.log(`showAddTeamDialog: 準備加入團隊，selectedTeamIds:`, selectedTeamIds);
            await addTeamsToSuggestion(selectedTeamIds, cohortId, groupId);
        }
        
    } catch (err) {
        console.error("顯示加入團隊對話框失敗:", err);
        Toast.fire({
            icon: "error",
            title: "載入失敗",
            text: err.message || "請稍後再試"
        });
    }
}

/* ==========================================
   將選中的團隊添加到建議表
========================================== */
async function addTeamsToSuggestion(selectedTeamIds, cohortId, groupId) {
    try {
        const apiUrl = resolveSuggestApiUrl();
        
        // 獲取選中團隊的詳細資訊（不傳遞 from_integrate=1，確保獲取所有團隊）
        const groupIdParam = groupId === "all" ? "all" : groupId;
        // 明確不傳遞 from_integrate，這樣才能獲取所有團隊（包括不在時程表中的）
        const allTeamsR = await fetch(`${apiUrl}?action=listTeams&cohort_ID=${cohortId}&group_ID=${groupIdParam}`);
        const allTeamsJ = await allTeamsR.json();
        
        if (!allTeamsJ.success || !allTeamsJ.data) {
            throw new Error("無法載入團隊資訊");
        }
        
        console.log(`addTeamsToSuggestion: 選中的團隊 ID:`, selectedTeamIds);
        console.log(`addTeamsToSuggestion: 所有團隊數: ${allTeamsJ.data.length}, 團隊 ID 列表:`, allTeamsJ.data.map(t => t.team_ID));
        
        // 過濾出選中的團隊（確保 team_ID 類型匹配）
        // 將 selectedTeamIds 轉換為數字數組（如果還不是）
        const selectedTeamIdsNum = selectedTeamIds.map(id => parseInt(id)).filter(id => !isNaN(id));
        console.log(`addTeamsToSuggestion: 轉換後的選中團隊 ID (數字):`, selectedTeamIdsNum);
        
        const selectedTeams = allTeamsJ.data.filter(team => {
            const teamId = parseInt(team.team_ID);
            const isSelected = selectedTeamIdsNum.includes(teamId);
            if (isSelected) {
                console.log(`找到選中的團隊: team_ID=${teamId}, name=${team.team_project_name}, group_name=${team.group_name || 'N/A'}`);
            }
            return isSelected;
        });
        
        console.log(`addTeamsToSuggestion: 找到的團隊數: ${selectedTeams.length}`, selectedTeams.map(t => ({ 
            team_ID: t.team_ID, 
            name: t.team_project_name,
            group_name: t.group_name || 'N/A',
            group_ID: t.group_ID || t.group_id || 'N/A'
        })));
        
        if (selectedTeams.length === 0) {
            console.error(`addTeamsToSuggestion: 無法找到選中的團隊`);
            console.error(`選中的團隊 ID:`, selectedTeamIds);
            console.error(`選中的團隊 ID (數字):`, selectedTeamIdsNum);
            console.error(`所有團隊 ID 列表:`, allTeamsJ.data.map(t => parseInt(t.team_ID)));
            Toast.fire({
                icon: "warning",
                title: "沒有找到選中的團隊",
                text: `請確認團隊 ID: ${selectedTeamIds.join(', ')}。可能這些團隊不在當前屆別或類組中。`
            });
            return;
        }
        
        // 再次檢查是否有重複（防止在對話框打開期間有團隊被添加）
        const currentTeamCards = document.querySelectorAll(".sg-team-card");
        const currentTeamIds = new Set(Array.from(currentTeamCards).map(card => {
            const teamId = parseInt(card.getAttribute("data-team"));
            return isNaN(teamId) ? null : teamId;
        }).filter(id => id !== null));
        
        // 過濾掉已經存在的團隊
        const teamsToAdd = selectedTeams.filter(team => {
            const teamId = parseInt(team.team_ID);
            const alreadyExists = currentTeamIds.has(teamId);
            if (alreadyExists) {
                console.log(`團隊 ${teamId} (${team.team_project_name}) 已存在，跳過重複添加`);
            }
            return !alreadyExists;
        });
        
        if (teamsToAdd.length === 0) {
            Toast.fire({
                icon: "warning",
                title: "沒有可加入的團隊",
                text: "選中的團隊都已在建議表中"
            });
            return;
        }
        
        if (teamsToAdd.length < selectedTeams.length) {
            Toast.fire({
                icon: "info",
                title: "部分團隊已存在",
                text: `已過濾 ${selectedTeams.length - teamsToAdd.length} 個重複的團隊，將加入 ${teamsToAdd.length} 個團隊`
            });
        }
        
        const tbody = document.getElementById("sg-team-tbody");
        if (!tbody) {
            throw new Error("找不到團隊列表容器");
        }
        
        teamsToAdd.forEach((team) => {
            const teamData = {
                team_ID: team.team_ID,
                team_project_name: team.team_project_name || `團隊 ${team.team_ID}`,
                group_name: team.group_name || '',
                group_ID: team.group_ID || team.group_id || null
            };
            // 新加入的團隊尚未在資料庫中建立建議紀錄，
            // 預設使用「刪除團隊」模式（data-delete-type="team"），
            // 讓使用者可以在存檔前隨時移除這些剛加入的團隊。
            tbody.insertAdjacentHTML("beforeend", createTeamTableRow(teamData, true));
        });
        
        // 若為「刪除後又加回」的團隊，從 deletedTeams 移除，存檔時會一併儲存該團隊資料而非刪除
        teamsToAdd.forEach(team => {
            const teamId = parseInt(team.team_ID);
            if (!isNaN(teamId)) deletedTeams.delete(teamId);
        });
        
        bindTeamTableRowEvents();
        initDragAndDrop(cohortId, groupId);
        updateTeamNumbers();
        bindAutoNumberEvent();
        bindStatusSelectEvent();
        selectedTeams.forEach(team => {
            const teamId = parseInt(team.team_ID);
            if (!isNaN(teamId)) setEditableMode(teamId);
        });
        
        // 依目前畫面順序寫入 localStorage 與資料庫（新團隊在最後，之後存檔會帶入正確 suggest_sort_no）
        await saveTeamOrder(cohortId, groupId);
        if (typeof updateActionButtonsState === 'function') {
            updateActionButtonsState();
        }
        
        Toast.fire({
            icon: "success",
            title: `已加入 ${selectedTeams.length} 個團隊`
        });
        
    } catch (err) {
        console.error("加入團隊失敗:", err);
        Toast.fire({
            icon: "error",
            title: "加入失敗",
            text: err.message || "請稍後再試"
        });
    }
}
