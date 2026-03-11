/**
 * 上傳專題 - 學生端
 * 用於學生上傳歷屆專題資料
 */

(function() {
    'use strict';

    const API_BASE = 'pages/upload_api.php';

    /**
     * 上傳專題資料
     * @param {Object} formData - 表單資料（包含圖片、檔案等）
     * @returns {Promise}
     */
    async function uploadProject(formData) {
        try {
            const response = await fetch(`${API_BASE}?do=upload`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('上傳專題失敗:', error);
            throw error;
        }
    }

    /**
     * 提交專題修改申請
     * @param {number} prosub_ID - 提交ID
     * @param {string} reason - 修改原因
     * @param {Object} formData - 表單資料
     * @returns {Promise}
     */
    async function submitModification(prosub_ID, reason, formData) {
        try {
            formData.append('prosub_ID', prosub_ID);
            formData.append('reason', reason);
            
            const response = await fetch(`${API_BASE}?do=modify`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('提交修改申請失敗:', error);
            throw error;
        }
    }

    /**
     * 獲取當前團隊的專題資料
     * @returns {Promise}
     */
    async function getMyProject() {
        try {
            const response = await fetch(`${API_BASE}?do=get_my_project`);
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('獲取我的專題失敗:', error);
            throw error;
        }
    }

    /**
     * 初始化上傳表單
     */
    function initUploadForm() {
        const form = document.getElementById('uploadProjectForm');
        if (!form) return;

        // 設置圖片預覽
        setupImagePreview();
        
        // 設置文件上傳
        setupFileUpload();
        
        // 設置表單提交
        form.addEventListener('submit', handleFormSubmit);
        
        // 載入現有資料（如果有）
        loadMyProject();
    }

    /**
     * 設置圖片預覽
     */
    function setupImagePreview() {
        const imageInput = document.getElementById('projectImage');
        const preview = document.getElementById('imagePreview');
        
        if (imageInput && preview) {
            imageInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
    }

    /**
     * 設置文件上傳
     */
    function setupFileUpload() {
        const fileInput = document.getElementById('projectFiles');
        const fileList = document.getElementById('fileList');
        
        if (fileInput && fileList) {
            fileInput.addEventListener('change', function(e) {
                const files = Array.from(e.target.files);
                renderFileList(files);
            });
        }
    }

    /**
     * 渲染文件列表
     * @param {Array} files - 文件列表
     */
    function renderFileList(files) {
        const fileList = document.getElementById('fileList');
        if (!fileList) return;

        if (files.length === 0) {
            fileList.innerHTML = '<p class="no-files">尚未選擇文件</p>';
            return;
        }

        const html = files.map((file, index) => `
            <div class="file-item">
                <span class="file-name">${file.name}</span>
                <span class="file-size">${formatFileSize(file.size)}</span>
                <button type="button" class="btn-remove-file" onclick="UploadProject.removeFile(${index})">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
        `).join('');

        fileList.innerHTML = html;
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

    /**
     * 處理表單提交
     * @param {Event} e - 事件對象
     */
    async function handleFormSubmit(e) {
        e.preventDefault();
        
        const form = e.target;
        const formData = new FormData(form);
        
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn ? submitBtn.textContent : '';
        
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = '上傳中...';
        }

        try {
            const result = await uploadProject(formData);
            
            if (result.success) {
                alert('上傳成功！');
                form.reset();
                resetPreview();
                
                // 重新載入資料
                loadMyProject();
            } else {
                alert(result.message || '上傳失敗，請稍後再試');
            }
        } catch (error) {
            console.error('上傳錯誤:', error);
            alert('上傳失敗，請稍後再試');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        }
    }

    /**
     * 重置預覽
     */
    function resetPreview() {
        const preview = document.getElementById('imagePreview');
        if (preview) {
            preview.src = '';
            preview.style.display = 'none';
        }
        
        const fileList = document.getElementById('fileList');
        if (fileList) {
            fileList.innerHTML = '<p class="no-files">尚未選擇文件</p>';
        }
    }

    /**
     * 載入我的專題資料
     */
    async function loadMyProject() {
        try {
            const data = await getMyProject();
            if (data.success && data.project) {
                populateForm(data.project);
            }
        } catch (error) {
            console.error('載入專題資料失敗:', error);
        }
    }

    /**
     * 填充表單
     * @param {Object} project - 專題資料
     */
    function populateForm(project) {
        // 這裡可以實現表單填充邏輯
        console.log('填充表單:', project);
    }

    /**
     * 移除文件
     * @param {number} index - 文件索引
     */
    function removeFile(index) {
        const fileInput = document.getElementById('projectFiles');
        if (fileInput) {
            const dt = new DataTransfer();
            const files = Array.from(fileInput.files);
            files.splice(index, 1);
            files.forEach(file => dt.items.add(file));
            fileInput.files = dt.files;
            
            renderFileList(Array.from(fileInput.files));
        }
    }

    // 匯出到全域
    window.UploadProject = {
        uploadProject,
        submitModification,
        getMyProject,
        initUploadForm,
        removeFile
    };

    // 自動初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUploadForm);
    } else {
        initUploadForm();
    }

})();


