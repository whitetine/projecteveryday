/**
 * 歷屆專題 API - 科辦端
 * 用於管理歷屆專題的 API 調用
 */

(function() {
    'use strict';

    // API 基礎路徑
    const API_BASE = 'pages/history_api.php';

    /**
     * 獲取歷屆專題列表
     * @param {Object} params - 查詢參數
     * @returns {Promise} 
     */
    async function getHistoryProjects(params = {}) {
        try {
            const queryParams = new URLSearchParams(params);
            const response = await fetch(`${API_BASE}?do=get_list&${queryParams}`);
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('獲取歷屆專題列表失敗:', error);
            throw error;
        }
    }

    /**
     * 獲取專題詳情
     * @param {number} pro_ID - 專題ID
     * @returns {Promise}
     */
    async function getProjectDetail(pro_ID) {
        try {
            const response = await fetch(`${API_BASE}?do=get_detail&pro_ID=${pro_ID}`);
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('獲取專題詳情失敗:', error);
            throw error;
        }
    }

    /**
     * 更新專題資料
     * @param {Object} projectData - 專題資料
     * @returns {Promise}
     */
    async function updateProject(projectData) {
        try {
            const formData = new FormData();
            Object.keys(projectData).forEach(key => {
                formData.append(key, projectData[key]);
            });

            const response = await fetch(`${API_BASE}?do=update`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('更新專題失敗:', error);
            throw error;
        }
    }

    /**
     * 刪除專題
     * @param {number} pro_ID - 專題ID
     * @returns {Promise}
     */
    async function deleteProject(pro_ID) {
        try {
            const response = await fetch(`${API_BASE}?do=delete&pro_ID=${pro_ID}`, {
                method: 'POST'
            });
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('刪除專題失敗:', error);
            throw error;
        }
    }

    /**
     * 設定上傳期限
     * @param {number} hp_ID - 歷屆專題ID
     * @param {string} deadline - 期限日期時間（格式：YYYY-MM-DDTHH:mm）
     * @returns {Promise}
     */
    async function setDeadline(hp_ID, deadline) {
        try {
            const formData = new FormData();
            formData.append('hp_ID', hp_ID);
            formData.append('deadline', deadline);

            const response = await fetch(`${API_BASE}?do=set_deadline`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('設定上傳期限失敗:', error);
            throw error;
        }
    }

    /**
     * 鎖定/解鎖專題
     * @param {number} hp_ID - 歷屆專題ID
     * @param {boolean} isLocked - 是否鎖定
     * @returns {Promise}
     */
    async function lockProject(hp_ID, isLocked) {
        try {
            const formData = new FormData();
            formData.append('hp_ID', hp_ID);
            formData.append('is_locked', isLocked ? 1 : 0);

            const response = await fetch(`${API_BASE}?do=lock`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('鎖定專題失敗:', error);
            throw error;
        }
    }

    /**
     * 檢查並自動鎖定過期專題
     * @returns {Promise}
     */
    async function checkDeadlines() {
        try {
            const response = await fetch(`${API_BASE}?do=check_deadlines`, {
                method: 'POST'
            });
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('檢查期限失敗:', error);
            throw error;
        }
    }

    /**
     * 更新專題狀態
     * @param {number} hp_ID - 歷屆專題ID
     * @param {number} status - 狀態（0=停用，1=啟用）
     * @returns {Promise}
     */
    async function updateStatus(hp_ID, status) {
        try {
            const formData = new FormData();
            formData.append('hp_ID', hp_ID);
            formData.append('status', status);

            const response = await fetch(`${API_BASE}?do=update_status`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('更新狀態失敗:', error);
            throw error;
        }
    }

    // 匯出函數到全域
    window.HistoryAPI = {
        getHistoryProjects,
        getProjectDetail,
        updateProject,
        deleteProject,
        setDeadline,
        lockProject,
        checkDeadlines,
        updateStatus
    };

})();


