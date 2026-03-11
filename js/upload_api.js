/**
 * 上傳專題 API - 學生端
 * 提供上傳專題相關的 API 調用函數
 */

(function() {
    'use strict';

    const API_BASE = 'pages/upload_api.php';

    /**
     * 上傳專題資料
     * @param {FormData} formData - 表單資料
     * @param {Function} onProgress - 進度回調函數
     * @returns {Promise}
     */
    async function uploadProject(formData, onProgress = null) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();

            // 上傳進度監聽
            if (onProgress) {
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const percentComplete = (e.loaded / e.total) * 100;
                        onProgress(percentComplete);
                    }
                });
            }

            // 完成監聽
            xhr.addEventListener('load', function() {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        resolve(response);
                    } catch (error) {
                        reject(new Error('回應解析失敗'));
                    }
                } else {
                    reject(new Error(`上傳失敗: ${xhr.status}`));
                }
            });

            // 錯誤監聽
            xhr.addEventListener('error', function() {
                reject(new Error('網路錯誤'));
            });

            // 中止監聽
            xhr.addEventListener('abort', function() {
                reject(new Error('上傳已取消'));
            });

            // 發送請求
            xhr.open('POST', `${API_BASE}?do=upload`);
            xhr.send(formData);
        });
    }

    /**
     * 上傳圖片
     * @param {File} imageFile - 圖片文件
     * @returns {Promise}
     */
    async function uploadImage(imageFile) {
        const formData = new FormData();
        formData.append('image', imageFile);

        try {
            const response = await fetch(`${API_BASE}?do=upload_image`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('上傳圖片失敗:', error);
            throw error;
        }
    }

    /**
     * 上傳文件
     * @param {FileList} files - 文件列表
     * @returns {Promise}
     */
    async function uploadFiles(files) {
        const formData = new FormData();
        Array.from(files).forEach((file, index) => {
            formData.append(`files[${index}]`, file);
        });

        try {
            const response = await fetch(`${API_BASE}?do=upload_files`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('上傳文件失敗:', error);
            throw error;
        }
    }

    /**
     * 刪除已上傳的文件
     * @param {string} filePath - 文件路徑
     * @returns {Promise}
     */
    async function deleteFile(filePath) {
        try {
            const formData = new FormData();
            formData.append('file_path', filePath);

            const response = await fetch(`${API_BASE}?do=delete_file`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('刪除文件失敗:', error);
            throw error;
        }
    }

    /**
     * 驗證文件大小
     * @param {File} file - 文件對象
     * @param {number} maxSize - 最大大小（字節）
     * @returns {boolean}
     */
    function validateFileSize(file, maxSize = 50 * 1024 * 1024) {
        return file.size <= maxSize;
    }

    /**
     * 驗證文件類型
     * @param {File} file - 文件對象
     * @param {Array} allowedTypes - 允許的類型
     * @returns {boolean}
     */
    function validateFileType(file, allowedTypes = ['jpg', 'jpeg', 'png', 'pdf', 'zip']) {
        const ext = file.name.split('.').pop().toLowerCase();
        return allowedTypes.includes(ext);
    }

    /**
     * 格式化文件大小
     * @param {number} bytes - 字節數
     * @returns {string}
     */
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    // 匯出到全域
    window.UploadAPI = {
        uploadProject,
        uploadImage,
        uploadFiles,
        deleteFile,
        validateFileSize,
        validateFileType,
        formatFileSize
    };

})();


