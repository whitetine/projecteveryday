/**
 * 專題畫廊 - 學生端
 * 用於瀏覽和查看歷屆專題作品
 */

(function() {
    'use strict';

    const API_BASE = 'pages/history_api.php';

    /**
     * 獲取專題畫廊列表
     * @param {Object} params - 查詢參數（屆別、類組等）
     * @returns {Promise}
     */
    async function getGalleryProjects(params = {}) {
        try {
            const queryParams = new URLSearchParams(params);
            const response = await fetch(`${API_BASE}?do=get_gallery&${queryParams}`);
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('獲取專題畫廊失敗:', error);
            throw error;
        }
    }

    /**
     * 獲取專題詳情（用於畫廊展示）
     * @param {number} pro_ID - 專題ID
     * @returns {Promise}
     */
    async function getProjectGalleryDetail(pro_ID) {
        try {
            const response = await fetch(`${API_BASE}?do=get_gallery_detail&pro_ID=${pro_ID}`);
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('獲取專題詳情失敗:', error);
            throw error;
        }
    }

    /**
     * 搜索專題
     * @param {string} keyword - 關鍵字
     * @param {Object} filters - 篩選條件
     * @returns {Promise}
     */
    async function searchProjects(keyword, filters = {}) {
        try {
            const params = {
                keyword: keyword,
                ...filters
            };
            const queryParams = new URLSearchParams(params);
            const response = await fetch(`${API_BASE}?do=search&${queryParams}`);
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('搜索專題失敗:', error);
            throw error;
        }
    }

    /**
     * 初始化畫廊
     */
    function initGallery() {
        // 載入專題列表
        loadGalleryProjects();
        
        // 設置篩選器事件
        setupFilters();
        
        // 設置搜索功能
        setupSearch();
    }

    /**
     * 載入專題列表
     * @param {Object} filters - 篩選條件
     */
    async function loadGalleryProjects(filters = {}) {
        try {
            const container = document.getElementById('galleryContainer');
            if (!container) return;

            container.innerHTML = '<div class="loading">載入中...</div>';

            const data = await getGalleryProjects(filters);
            
            if (data.success && data.projects) {
                renderGallery(data.projects);
            } else {
                container.innerHTML = '<div class="no-data">沒有找到專題資料</div>';
            }
        } catch (error) {
            console.error('載入專題列表失敗:', error);
            const container = document.getElementById('galleryContainer');
            if (container) {
                container.innerHTML = '<div class="error">載入失敗，請稍後再試</div>';
            }
        }
    }

    /**
     * 渲染畫廊
     * @param {Array} projects - 專題列表
     */
    function renderGallery(projects) {
        const container = document.getElementById('galleryContainer');
        if (!container) return;

        if (projects.length === 0) {
            container.innerHTML = '<div class="no-data">沒有找到專題資料</div>';
            return;
        }

        const html = projects.map(project => `
            <div class="gallery-item" data-pro-id="${project.pro_ID}">
                <div class="gallery-item-image">
                    ${project.prosub_img ? 
                        `<img src="${project.prosub_img}" alt="${project.pro_title}" />` : 
                        '<div class="no-image">無圖片</div>'
                    }
                </div>
                <div class="gallery-item-content">
                    <h4 class="gallery-item-title">${project.pro_title}</h4>
                    <p class="gallery-item-info">
                        <span class="cohort">${project.cohort_name || ''}</span>
                        <span class="team">${project.team_name || ''}</span>
                    </p>
                    <button class="btn-view-detail" onclick="ProjectGallery.viewDetail(${project.pro_ID})">
                        查看詳情
                    </button>
                </div>
            </div>
        `).join('');

        container.innerHTML = html;
    }

    /**
     * 設置篩選器
     */
    function setupFilters() {
        const filterElements = document.querySelectorAll('[data-filter]');
        filterElements.forEach(element => {
            element.addEventListener('change', function() {
                const filters = getFilterValues();
                loadGalleryProjects(filters);
            });
        });
    }

    /**
     * 獲取篩選值
     * @returns {Object}
     */
    function getFilterValues() {
        const filters = {};
        document.querySelectorAll('[data-filter]').forEach(element => {
            const key = element.getAttribute('data-filter');
            const value = element.value;
            if (value) {
                filters[key] = value;
            }
        });
        return filters;
    }

    /**
     * 設置搜索功能
     */
    function setupSearch() {
        const searchInput = document.getElementById('gallerySearch');
        const searchButton = document.getElementById('gallerySearchBtn');
        
        if (searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    performSearch();
                }
            });
        }
        
        if (searchButton) {
            searchButton.addEventListener('click', performSearch);
        }
    }

    /**
     * 執行搜索
     */
    async function performSearch() {
        const searchInput = document.getElementById('gallerySearch');
        const keyword = searchInput ? searchInput.value.trim() : '';
        const filters = getFilterValues();

        try {
            const data = await searchProjects(keyword, filters);
            if (data.success && data.projects) {
                renderGallery(data.projects);
            }
        } catch (error) {
            console.error('搜索失敗:', error);
        }
    }

    /**
     * 查看專題詳情
     * @param {number} pro_ID - 專題ID
     */
    async function viewDetail(pro_ID) {
        try {
            const data = await getProjectGalleryDetail(pro_ID);
            if (data.success && data.project) {
                showDetailModal(data.project);
            }
        } catch (error) {
            console.error('獲取詳情失敗:', error);
            alert('無法載入專題詳情，請稍後再試');
        }
    }

    /**
     * 顯示詳情彈窗
     * @param {Object} project - 專題資料
     */
    function showDetailModal(project) {
        // 這裡可以實現詳情彈窗邏輯
        console.log('顯示專題詳情:', project);
        // 可以整合現有的 Modal 組件
    }

    // 匯出到全域
    window.ProjectGallery = {
        initGallery,
        loadGalleryProjects,
        searchProjects,
        viewDetail,
        getProjectGalleryDetail
    };

    // 自動初始化（當 DOM 載入完成時）
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initGallery);
    } else {
        initGallery();
    }

})();


