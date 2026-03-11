/**
 * 審核修改申請 - 科辦端
 * 用於審核學生提交的專題修改申請
 */

(function() {
    'use strict';

    const API_BASE = 'pages/review_modify_api.php';

    /**
     * 獲取待審核的修改申請列表
     * @param {Object} params - 查詢參數
     * @returns {Promise}
     */
    async function getPendingModifications(params = {}) {
        try {
            const queryParams = new URLSearchParams(params);
            const response = await fetch(`${API_BASE}?do=get_pending&${queryParams}`);
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('獲取待審核列表失敗:', error);
            throw error;
        }
    }

    /**
     * 審核修改申請
     * @param {number} prosub_ID - 提交ID
     * @param {string} action - 動作: 'approve' 或 'reject'
     * @param {string} reason - 審核備註
     * @returns {Promise}
     */
    async function reviewModification(prosub_ID, action, reason = '') {
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
            return data;
        } catch (error) {
            console.error('審核失敗:', error);
            throw error;
        }
    }

    /**
     * 獲取修改申請詳情
     * @param {number} prosub_ID - 提交ID
     * @returns {Promise}
     */
    async function getModificationDetail(prosub_ID) {
        try {
            const response = await fetch(`${API_BASE}?do=get_detail&prosub_ID=${prosub_ID}`);
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('獲取詳情失敗:', error);
            throw error;
        }
    }

    /**
     * 批准修改申請
     * @param {number} prosub_ID - 提交ID
     * @param {string} reason - 審核備註
     * @returns {Promise}
     */
    async function approveModification(prosub_ID, reason = '') {
        return reviewModification(prosub_ID, 'approve', reason);
    }

    /**
     * 拒絕修改申請
     * @param {number} prosub_ID - 提交ID
     * @param {string} reason - 拒絕原因
     * @returns {Promise}
     */
    async function rejectModification(prosub_ID, reason = '') {
        return reviewModification(prosub_ID, 'reject', reason);
    }

    // 匯出到全域
    window.ReviewModify = {
        getPendingModifications,
        getModificationDetail,
        approveModification,
        rejectModification,
        reviewModification
    };

})();


