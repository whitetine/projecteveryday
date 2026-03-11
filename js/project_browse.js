/**
 * 歷屆專題瀏覽 - 學生端
 * 對應頁面：pages/project_browse.php
 */

(function() {
    'use strict';

    const API_BASE = 'pages/history_api.php';
    let currentView = 'grid'; // 'grid' 或 'list'
const isPublic = window.PROJECT_BROWSE_CONFIG?.isPublic || false;
const role_ID = window.PROJECT_BROWSE_CONFIG?.role_ID || null;
const isLoggedIn = !!role_ID; // 只要有登入就算登入
const canDownloadFiles = role_ID === 6 || role_ID === 1 || role_ID === 2; // 學生與主任/科辦可實際下載
    let currentFilters = {
        cohort_ID: 0,
        keyword: ''
    };
    let searchDebounceTimer = null;
    let projectFilesMap = {}; // 儲存每個專題的可下載檔案 { prosub_ID: [files] }

    /**
     * 初始化頁面
     */
    function init() {
        loadCohorts();
        setupFilterListeners();
        showFilterSection();
        loadProjects();
    }

    /**
     * 顯示篩選區域
     */
    function showFilterSection() {
        const filterSection = document.querySelector('.search-filter-section');
        if (filterSection) {
            // 移除內聯的隱藏樣式，讓 CSS 控制顯示
            filterSection.style.opacity = '';
            filterSection.style.visibility = '';
        }
    }

    /**
     * 載入學級選項
     */
    async function loadCohorts() {
        try {
            const cohortFilter = document.getElementById('cohortFilter');
            if (!cohortFilter) return;

            const apiUrl = isPublic ? `${API_BASE}?do=get_all_cohorts&public=1` : `${API_BASE}?do=get_all_cohorts`;
            const response = await fetch(apiUrl);
            const data = await response.json();

            if (data.success && data.data) {
                // 清空現有選項（保留"全部學級"）
                cohortFilter.innerHTML = '<option value="0">全部學級</option>';
                
                // 添加學級選項
                data.data.forEach(cohort => {
                    const option = document.createElement('option');
                    // 確保 cohort_ID 是數字類型（從資料庫讀取可能是字串）
                    option.value = String(cohort.cohort_ID);
                    // get_all_cohorts 返回 cohort_name 或 year_label，如果沒有則使用 cohort_ID
                    option.textContent = cohort.cohort_name || cohort.year_label || `${cohort.cohort_ID}級`;
                    cohortFilter.appendChild(option);
                });
            }
        } catch (error) {
            console.error('載入學級選項失敗:', error);
        }
    }

    /**
     * 設置篩選監聽器
     */
    function setupFilterListeners() {
        // 搜索框（智能搜索，支持防抖）
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const keyword = this.value.trim();
                currentFilters.keyword = keyword;
                
                // 清除之前的防抖計時器
                if (searchDebounceTimer) {
                    clearTimeout(searchDebounceTimer);
                }
                
                // 設置新的防抖計時器（300ms 延遲）
                searchDebounceTimer = setTimeout(() => {
                    loadProjects();
                }, 300);
            });
            
            // 支持 Enter 鍵立即搜索
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (searchDebounceTimer) {
                        clearTimeout(searchDebounceTimer);
                    }
                    loadProjects();
                }
            });
        }

        // 學級篩選
        const cohortFilter = document.getElementById('cohortFilter');
        if (cohortFilter) {
            cohortFilter.addEventListener('change', function() {
                currentFilters.cohort_ID = parseInt(this.value) || 0;
                loadProjects();
            });
        }

        // 清除篩選按鈕
        const clearFiltersBtn = document.getElementById('clearFiltersBtn');
        if (clearFiltersBtn) {
            clearFiltersBtn.addEventListener('click', function() {
                currentFilters.cohort_ID = 0;
                currentFilters.keyword = '';
                if (cohortFilter) {
                    cohortFilter.value = '0';
                }
                if (searchInput) {
                    searchInput.value = '';
                }
                loadProjects();
            });
        }
    }

    /**
     * 載入專題列表
     */
    async function loadProjects() {
        try {
            const container = document.getElementById('projectDisplayArea');
            if (!container) return;

            // 🔹 【關鍵修復】首次載入時不顯示"載入中..."，直接載入內容
            const isFirstLoad = !container.hasAttribute('data-loaded');
            if (!isFirstLoad) {
                // 使用淡出效果，避免閃爍（僅在後續更新時使用）
            container.style.opacity = '0';
            container.style.transition = 'opacity 0.2s ease-out';
            await new Promise(resolve => setTimeout(resolve, 200));
            container.innerHTML = '<div class="loading">載入中...</div>';
            } else {
                // 🔹 【關鍵修復】首次載入時確保容器可見且無過渡效果，不顯示"載入中..."
                container.style.opacity = '1';
                container.style.transition = 'none';
                // 不設置 innerHTML，直接載入內容
            }

            // 構建 API URL，包含篩選參數
            let apiUrl = isPublic ? `${API_BASE}?do=get_gallery&public=1` : `${API_BASE}?do=get_gallery`;
            
            if (currentFilters.keyword) {
                apiUrl += `&keyword=${encodeURIComponent(currentFilters.keyword)}`;
            }
            
            if (currentFilters.cohort_ID > 0) {
                apiUrl += `&cohort_ID=${currentFilters.cohort_ID}`;
            }

            const response = await fetch(apiUrl);
            const data = await response.json();

            if (data.success && data.projects) {
                window.currentProjects = data.projects;
                
                // 🔹 【關鍵修復】如果已登入，為每個專題獲取可下載檔案（非阻塞，後台載入）
                if (isLoggedIn) {
                    // 不等待檔案載入完成，先顯示專題列表
                    loadProjectFiles(data.projects).catch(err => {
                        console.error('載入檔案失敗:', err);
                    });
                }
                
                renderProjects(data.projects);
                container.setAttribute('data-loaded', 'true'); // 標記已載入
            } else {
                container.innerHTML = '<div class="empty-state"><i class="fa-solid fa-folder-open"></i><p>沒有找到專題資料</p></div>';
                container.setAttribute('data-loaded', 'true');
            }
            
            // 🔹 【關鍵修復】首次載入時立即顯示，不淡入
            if (isFirstLoad) {
                container.style.opacity = '1';
                container.style.transition = '';
            } else {
                // 確保內容已更新後再淡入（僅在後續更新時使用）
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    container.style.opacity = '1';
                    container.style.transition = 'opacity 0.2s ease-in';
                });
            });
            }
        } catch (error) {
            console.error('載入專題失敗:', error);
            const container = document.getElementById('projectDisplayArea');
            if (container) {
                container.innerHTML = '<div class="empty-state"><i class="fa-solid fa-exclamation-triangle"></i><p>載入失敗，請稍後再試</p></div>';
                    container.style.opacity = '1';
                container.style.transition = '';
            }
        }
    }

    /**
     * 為每個專題載入可下載檔案（僅限登入用戶）
     */
    async function loadProjectFiles(projects) {
        if (!isLoggedIn) {
            return; // 訪客不載入檔案
        }
        
        // 清空舊的檔案映射
        projectFilesMap = {};
        
        // 🔹 優化：批量獲取所有專題的檔案（按屆別分組）
        const cohortGroups = {};
        projects.forEach(project => {
            if (!project.prosub_ID) return;
            const cohort_ID = project.hp_cohort_ID || 0;
            if (!cohortGroups[cohort_ID]) {
                cohortGroups[cohort_ID] = [];
            }
            cohortGroups[cohort_ID].push(project);
        });
        
        // 為每個屆別批量獲取檔案
        const filePromises = Object.keys(cohortGroups).map(async (cohort_ID) => {
            try {
                const params = new URLSearchParams();
                if (cohort_ID > 0) {
                    params.append('cohort_ID', cohort_ID);
                }
                
                const response = await fetch(`pages/get_archive_results.php?${params.toString()}`);
                const data = await response.json();
                
                if (data.success && data.submissions) {
                    // 將檔案映射到對應的專題
                    data.submissions.forEach(submission => {
                        if (submission.files && submission.files.length > 0) {
                            projectFilesMap[submission.prosub_ID] = submission.files;
                        }
                    });
                }
            } catch (error) {
                console.error(`載入屆別 ${cohort_ID} 的檔案失敗:`, error);
            }
        });
        
        await Promise.all(filePromises);
        console.log('[loadProjectFiles] 已載入', Object.keys(projectFilesMap).length, '個專題的檔案');
    }

    /**
     * 渲染專題列表
     */
    function renderProjects(projects) {
        const container = document.getElementById('projectDisplayArea');
        if (!container) return;

        if (projects.length === 0) {
            container.innerHTML = '<div class="empty-state"><i class="fa-solid fa-folder-open"></i><p>沒有找到專題資料</p></div>';
            return;
        }

        if (currentView === 'grid') {
            container.innerHTML = renderGridView(projects);
        } else {
            container.innerHTML = renderListView(projects);
        }
    }

    /**
     * 渲染網格視圖（卡片模式）
     */
    function renderGridView(projects) {
        return `
            <div class="project-grid">
                ${projects.map(project => {
                    const posterPath = project.hp_poster ? `../${project.hp_poster}` : '';
                    const isPDF = posterPath.toLowerCase().endsWith('.pdf');
                    const members = project.team_members || [];
                    const memberNames = members.map(m => m.u_name || '').join('、') || '無組員';
                    const teachers = project.team_teachers || [];
                    const teacherNames = teachers.map(t => t.u_name || '').join('、') || '';
                    const groupName = project.hp_group_name || '';
                    
                    return `
                        <div class="project-card">
                            <div class="card-poster-wrapper">
                                ${posterPath ? `
                                    ${isPDF ? `
                                        <div class="card-poster pdf-poster" style="width: 100%; height: 200px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; border-radius: 8px 8px 0 0;">
                                            <i class="fa-solid fa-file-pdf" style="font-size: 48px; color: #dc3545;"></i>
                                        </div>
                                    ` : `
                                        <img src="${escapeHtml(posterPath)}" alt="海報" class="card-poster" onerror="this.src='assets/no-image.png'">
                                    `}
                                ` : `
                                    <div class="card-poster no-poster" style="width: 100%; height: 200px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; border-radius: 8px 8px 0 0;">
                                        <i class="fa-solid fa-image" style="font-size: 48px; color: #999;"></i>
                                    </div>
                                `}
                            </div>
                        <div class="card-content">
                                <h4 class="card-title">${escapeHtml(project.hp_project_name || '')}</h4>
                                <div class="card-group">
                                    <strong>類別：</strong>
                                    <span>${escapeHtml(groupName || '未設定')}</span>
                                </div>
                                <div class="card-members">
                                    <strong>組員：</strong>
                                    <span>${escapeHtml(memberNames)}</span>
                                </div>
                                <div class="card-teachers">
                                    <strong>指導老師：</strong>
                                    <span>${escapeHtml(teacherNames || '無')}</span>
                            </div>
                            <div class="card-footer">
                                    <button class="btn-view-detail" onclick="ProjectBrowse.viewDetail(${project.prosub_ID})">
                                        <i class="fa-solid fa-eye"></i> 查看
                                </button>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }

    /**
     * 渲染列表視圖
     */
    function renderListView(projects) {
        return `
            <div class="project-list-view active">
                ${projects.map(project => {
                    const posterPath = project.hp_poster ? `../${project.hp_poster}` : '';
                    const isPDF = posterPath.toLowerCase().endsWith('.pdf');
                    const members = project.team_members || [];
                    const memberNames = members.map(m => m.u_name || '').join('、') || '無組員';
                    const teachers = project.team_teachers || [];
                    const teacherNames = teachers.map(t => t.u_name || '').join('、') || '';
                    const groupName = project.hp_group_name || '';
                    
                    return `
                        <div class="project-list-item">
                            <div class="list-poster-wrapper">
                                ${posterPath ? `
                                    ${isPDF ? `
                                        <div class="list-poster pdf-poster">
                                            <i class="fa-solid fa-file-pdf" style="font-size: 48px; color: white;"></i>
                                        </div>
                                    ` : `
                                        <img src="${escapeHtml(posterPath)}" alt="海報" class="list-poster" onerror="this.src='assets/no-image.png'">
                                    `}
                                ` : `
                                    <div class="list-poster no-poster">
                                        <i class="fa-solid fa-image" style="font-size: 48px; color: white;"></i>
                                    </div>
                                `}
                            </div>
                        <div class="list-content">
                                <h4 class="list-title">${escapeHtml(project.hp_project_name || '')}</h4>
                            <div class="list-meta">
                                    <div class="list-group">
                                        <strong>類別：</strong>${escapeHtml(groupName || '未設定')}
                                    </div>
                                    <div class="list-members">
                                        <strong>組員：</strong>${escapeHtml(memberNames)}
                                    </div>
                                    <div class="list-teachers">
                                        <strong>指導老師：</strong>${escapeHtml(teacherNames || '無')}
                                    </div>
                            </div>
                            <div class="list-actions">
                                    <button class="btn-view-detail" onclick="ProjectBrowse.viewDetail(${project.prosub_ID})">
                                        <i class="fa-solid fa-eye"></i> 查看
                                </button>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }


    /**
     * 載入該團隊歷次建議並渲染到 modal 內
     */
    async function loadTeamSuggestHistory(modal, team_ID, cohort_ID) {
        const contentEl = modal.querySelector('.suggest-history-content');
        if (!contentEl) return;
        const baseUrl = isPublic ? `${API_BASE}?do=get_team_suggest_history&team_ID=${team_ID}&cohort_ID=${cohort_ID}&public=1` : `${API_BASE}?do=get_team_suggest_history&team_ID=${team_ID}&cohort_ID=${cohort_ID}`;
        try {
            const res = await fetch(baseUrl);
            const data = await res.json();
            const loadingEl = contentEl.querySelector('.suggest-history-loading');
            if (loadingEl) loadingEl.remove();
            if (data.success && Array.isArray(data.data) && data.data.length > 0) {
                contentEl.innerHTML = data.data.map(item => {
                    const title = escapeHtml(item.title || '（未命名）');
                    const comment = escapeHtml((item.comment || '').trim() || '—');
                    const status = escapeHtml(item.status || '—');
                    return `
                        <div class="suggest-history-item" style="margin-bottom: 14px; padding: 12px 14px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #667eea;">
                            <div class="suggest-history-item-title" style="font-weight: 600; color: #333; margin-bottom: 6px;">${title}</div>
                            <div class="suggest-history-item-status" style="font-size: 13px; color: #667eea; margin-bottom: 4px;">審查結果：${status}</div>
                            <div class="suggest-history-item-comment" style="font-size: 14px; color: #4a5568; line-height: 1.5; white-space: pre-wrap;">${comment}</div>
                        </div>
                    `;
                }).join('');
            } else {
                contentEl.innerHTML = '<span style="color: #999;">尚無歷次建議</span>';
            }
        } catch (err) {
            console.error('載入歷次建議失敗:', err);
            const loadingEl = contentEl.querySelector('.suggest-history-loading');
            if (loadingEl) loadingEl.remove();
            contentEl.innerHTML = '<span style="color: #999;">無法載入歷次建議</span>';
        }
    }

    /**
     * 查看專題詳情
     */
    async function viewDetail(prosub_ID) {
        if (!prosub_ID) return;
        
        try {
            const apiUrl = isPublic 
                ? `${API_BASE}?do=get_gallery_detail&prosub_ID=${prosub_ID}&public=1`
                : `${API_BASE}?do=get_gallery_detail&prosub_ID=${prosub_ID}`;
            const response = await fetch(apiUrl);
            const data = await response.json();
            
            if (!data.success || !data.project) {
                await showAlertDialog('找不到專題資料', 'error');
                return;
            }
            
            const project = data.project;
            const posterPath = project.hp_poster ? `../${project.hp_poster}` : '';
            const isPDF = posterPath.toLowerCase().endsWith('.pdf');
            const members = project.team_members || [];
            const memberNames = members.map(m => m.u_name || '').join('、') || '無組員';
            const teachers = project.team_teachers || [];
            const teacherNames = teachers.map(t => t.u_name || '').join('、') || '';
            const groupName = project.hp_group_name || '';
            const intro = project.hp_intro || '無簡介';
            
            // 解析 content_json 以檢查是否允許多檔案下載
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
            const allowMultiFileDownload = contentJson.allow_multi_file_download === true || contentJson.allow_multi_file_download === 1;
            
            // 創建模態框
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center;';
            
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1054;';
            
            modal.innerHTML = `
                <div class="modal-dialog modal-lg" style="max-width: 1000px; margin: 20px auto;">
                    <div class="modal-content" style="border-radius: 16px; border: none; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                        <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 24px 30px; border: none;">
                            <h5 class="modal-title" style="font-size: 20px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-info-circle"></i>專題詳情
                            </h5>
                            <button type="button" class="btn-close btn-close-white" aria-label="Close" style="opacity: 0.9; font-size: 20px;"></button>
                        </div>
                        <div class="modal-body" style="max-height: 75vh; overflow-y: auto; padding: 30px; background: #f8f9fa;">
                            <div class="detail-section" style="background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                <div class="detail-item" style="display: flex; margin-bottom: 16px; align-items: flex-start;">
                                    <div class="detail-label" style="min-width: 100px; font-weight: 600; color: #667eea; font-size: 15px;">專題名稱</div>
                                    <div class="detail-value" style="flex: 1; color: #2d3748; font-size: 15px; line-height: 1.6;">${escapeHtml(project.hp_project_name || '')}</div>
                                </div>
                                <div class="detail-item" style="display: flex; margin-bottom: 16px; align-items: flex-start;">
                                    <div class="detail-label" style="min-width: 100px; font-weight: 600; color: #667eea; font-size: 15px;">類別</div>
                                    <div class="detail-value" style="flex: 1; color: #2d3748; font-size: 15px; line-height: 1.6;">${escapeHtml(groupName || '未設定')}</div>
                                </div>
                                <div class="detail-item" style="display: flex; margin-bottom: 16px; align-items: flex-start;">
                                    <div class="detail-label" style="min-width: 100px; font-weight: 600; color: #667eea; font-size: 15px;">組員</div>
                                    <div class="detail-value" style="flex: 1; color: #2d3748; font-size: 15px; line-height: 1.6;">${escapeHtml(memberNames)}</div>
                                </div>
                                <div class="detail-item" style="display: flex; margin-bottom: 16px; align-items: flex-start;">
                                    <div class="detail-label" style="min-width: 100px; font-weight: 600; color: #667eea; font-size: 15px;">指導老師</div>
                                    <div class="detail-value" style="flex: 1; color: #2d3748; font-size: 15px; line-height: 1.6;">${escapeHtml(teacherNames || '無')}</div>
                                </div>
                                <div class="detail-item" style="display: flex; margin-bottom: 0; align-items: flex-start;">
                                    <div class="detail-label" style="min-width: 100px; font-weight: 600; color: #667eea; font-size: 15px; padding-top: 4px;">簡介</div>
                                    <div class="detail-value" style="flex: 1; color: #4a5568; font-size: 15px; line-height: 1.8; white-space: pre-wrap; word-wrap: break-word;">${escapeHtml(intro)}</div>
                                </div>
                            </div>
                            <div class="poster-section" style="background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                <div class="detail-item" style="display: flex; margin-bottom: 16px; align-items: flex-start;">
                                    <div class="detail-label" style="min-width: 100px; font-weight: 600; color: #667eea; font-size: 15px; padding-top: 4px;">海報</div>
                                    <div class="detail-value" style="flex: 1;">
                                        ${posterPath ? `
                                            ${isPDF ? `
                                                <div style="width: 100%; height: 600px; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                                                    <iframe src="${escapeHtml(posterPath)}" type="application/pdf" style="width: 100%; height: 100%; border: none;"></iframe>
                                                </div>
                                            ` : `
                                                <img src="${escapeHtml(posterPath)}" alt="海報" style="max-width: 100%; height: auto; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                                            `}
                                        ` : '<div style="padding: 40px; text-align: center; color: #999; background: #f8f9fa; border-radius: 8px;"><i class="fa-solid fa-image" style="font-size: 48px; margin-bottom: 10px; opacity: 0.3;"></i><div>無海報</div></div>'}
                                    </div>
                                </div>
                                ${(() => {
                                    // 🔹 【關鍵修復】獲取該專題的可下載檔案（僅限登入用戶）
                                    const downloadableFiles = isLoggedIn ? (projectFilesMap[prosub_ID] || []) : [];
                                    
                                    // 🔹 渲染可下載檔案列表（登入的任何角色都能看到，在海報底下）
                                    // 注意：get_archive_results.php 已經過濾了 allow=1 的檔案，所以這裡直接顯示即可
                                    if (isLoggedIn) {
                                        if (downloadableFiles.length > 0) {
                                            return `
                                                <div class="downloadable-files-section" style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #e9ecef;">
                                                    <div class="files-title" style="font-size: 16px; font-weight: 600; color: #333; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                                                        <i class="fa-solid fa-file" style="color: #667eea; font-size: 18px;"></i>
                                                        <span>可下載檔案 (${downloadableFiles.length})</span>
                                                    </div>
                                                     <div class="files-list" style="display: flex; flex-direction: column; gap: 8px;">
                                                         ${downloadableFiles.map(file => {
                                                             const downloadUrl = `download.php?prosub_ID=${prosub_ID}&fid=${encodeURIComponent(file.fid)}`;
                                                             return `
                                                                 <a href="${downloadUrl}" 
                                                                    class="file-download-link" 
                                                                    style="display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: #f8f9fa; border-radius: 8px; text-decoration: none; color: #333; transition: all 0.2s; font-size: 14px; border: 1px solid #e9ecef;"
                                                                    onmouseover="this.style.background='#e9ecef'; this.style.color='#667eea'; this.style.borderColor='#667eea';"
                                                                    onmouseout="this.style.background='#f8f9fa'; this.style.color='#333'; this.style.borderColor='#e9ecef';"
                                                                    target="_blank"
                                                                    title="點擊下載：${escapeHtml(file.name || '未知檔案')}">
                                                                     <i class="fa-solid fa-file" style="color: #667eea; font-size: 16px;"></i>
                                                                     <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(file.name || '未知檔案')}</span>
                                                                 </a>
                                                             `;
                                                         }).join('')}
                                                     </div>
                                                </div>
                                            `;
                                        } else {
                                            return `
                                                <div class="downloadable-files-section" style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #e9ecef;">
                                                    <div style="text-align: center; color: #999; font-size: 14px; padding: 20px;">
                                                        <i class="fa-solid fa-file" style="font-size: 24px; margin-bottom: 8px; opacity: 0.5; display: block;"></i>
                                                        <span>目前沒有可下載的檔案</span>
                                                    </div>
                                                </div>
                                            `;
                                        }
                                    }
                                    return '';
                                })()}
                                <div class="team-suggest-history-section" style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #e9ecef;">
                                    <div class="suggest-history-title" style="font-size: 16px; font-weight: 600; color: #333; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                                        <i class="fa-solid fa-lightbulb" style="color: #667eea; font-size: 18px;"></i>
                                        <span>歷次建議</span>
                                    </div>
                                    <div class="suggest-history-content" data-team-id="${project.team_ID}" data-cohort-id="${project.hp_cohort_ID || ''}" style="min-height: 40px; color: #666; font-size: 14px;">
                                        <span class="suggest-history-loading">載入中...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer" style="padding: 20px 30px; background: white; border-top: 1px solid #e2e8f0; border-radius: 0 0 16px 16px;">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="padding: 10px 24px; border-radius: 8px; font-weight: 600; background: #e2e8f0; border: none; color: #4a5568;">關閉</button>
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

            // 載入該團隊歷次建議（顯示在下載檔案下方）
            const teamId = project.team_ID;
            const cohortId = project.hp_cohort_ID != null ? project.hp_cohort_ID : '';
            if (teamId && cohortId !== '') {
                loadTeamSuggestHistory(modal, teamId, cohortId);
            } else {
                const contentEl = modal.querySelector('.suggest-history-content');
                if (contentEl) {
                    contentEl.querySelector('.suggest-history-loading')?.remove();
                    contentEl.innerHTML = '<span style="color: #999;">尚無歷次建議資料</span>';
                }
            }
        } catch (error) {
            console.error('載入專題詳情失敗:', error);
            await showAlertDialog('載入專題詳情失敗，請稍後再試', 'error');
        }
    }
    
    /**
     * 自定義提示框（替換 alert）
     */
    function showAlertDialog(message, type = 'info') {
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
                'info': { bg: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', icon: 'fa-info-circle' }
            };
            
            const config = typeConfig[type] || typeConfig['info'];
            
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
                        <div class="modal-header" style="background: ${config.bg}; color: white; border-radius: 16px 16px 0 0; border: none; padding: 20px;">
                            <h5 class="modal-title" style="font-weight: 600; font-size: 18px;">
                                <i class="fa-solid ${config.icon} me-2"></i>提示
                            </h5>
                        </div>
                        <div class="modal-body" style="padding: 30px; font-size: 15px; line-height: 1.6; color: #333;">
                            ${escapeHtml(message).replace(/\n/g, '<br>')}
                        </div>
                        <div class="modal-footer" style="border-top: 1px solid #e9ecef; padding: 20px; display: flex; justify-content: flex-end;">
                            <button type="button" class="btn btn-primary confirm-btn" style="border-radius: 8px; padding: 10px 24px; font-weight: 600; min-width: 100px; background: ${config.bg}; border: none;">
                                <i class="fa-solid fa-check me-2"></i>確定
                            </button>
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
            if (confirmBtn) confirmBtn.onclick = closeModal;
            backdrop.onclick = closeModal;
            
            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        });
    }

    /**
     * HTML 轉義
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * 格式化日期
     */
    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleDateString('zh-TW');
    }

    /**
     * 格式化檔案大小
     */
    function formatFileSize(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    // 匯出到全域，供外部調用
    window.ProjectBrowse = {
        init,
        loadProjects,
        viewDetail
    };

    // 🔹 【關鍵修復】立即初始化，確保一進入頁面就能看到歷屆專題
    // 如果配置已存在，自動初始化（用於直接載入的情況）
    if (window.PROJECT_BROWSE_CONFIG) {
        // 立即執行初始化，不等待延遲
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                init(); // 移除延遲，立即執行
            });
        } else {
            // DOM 已準備好，立即執行
            init();
        }
    }

})();


