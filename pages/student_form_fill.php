<?php
session_start();
if (!isset($_SESSION['u_ID'])) {
    echo "<script>alert('請先登入');location.href='../index.php';</script>";
    exit;
}

$role_ID = $_SESSION['role_ID'] ?? 0;
if (!in_array($role_ID, [6])) {
    echo "<script>alert('此頁面僅限學生使用');location.href='../main.php';</script>";
    exit;
}

// 獲取表單ID（從URL參數或POST）
$form_ID = isset($_GET['form_ID']) ? (int)$_GET['form_ID'] : (isset($_POST['form_ID']) ? (int)$_POST['form_ID'] : 0);
$team_ID = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : null;
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>填寫表單</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Flatpickr 日期選擇器 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/zh-tw.js"></script>
    <style>
        .form-fill-container {
            padding: 20px;
            max-width: 900px;
            margin: 0 auto;
        }
        .page-header {
            margin-bottom: 30px;
            text-align: center;
        }
        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }
        .form-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 30px;
        }
        .form-description {
            color: #666;
            margin-bottom: 30px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .question-group {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        .question-group:last-child {
            border-bottom: none;
        }
        .question-label {
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 10px;
            display: block;
        }
        .question-label.required::after {
            content: ' *';
            color: #dc3545;
        }
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-control:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
        }
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        /* 日期選擇器樣式 */
        .date-picker-input {
            cursor: pointer;
            background-color: #fff;
        }
        .date-picker-input:focus {
            cursor: text;
        }
        .flatpickr-calendar {
            font-family: inherit;
        }
        .options-container {
            margin-top: 10px;
        }
        .option-item {
            margin-bottom: 10px;
        }
        .option-item label {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 8px;
            border-radius: 4px;
            transition: background 0.2s;
        }
        .option-item label:hover {
            background: #f8f9fa;
        }
        .option-item input[type="radio"],
        .option-item input[type="checkbox"] {
            margin-right: 8px;
        }
        .btn-submit {
            background: #28a745;
            color: white;
            border: none;
            padding: 12px 40px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            margin-top: 20px;
            width: 100%;
        }
        .btn-submit:hover {
            background: #218838;
        }
        .btn-submit:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        .loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .btn-export {
            background: #28a745;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.3s;
        }
        .btn-export:hover {
            background: #218838;
        }
        .btn-draft {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }
        .btn-draft:hover {
            background: #5a6268;
        }
        .btn-draft:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .auto-filled {
            transition: background-color 0.3s;
        }
        .auto-filled:focus {
            background-color: white !important;
        }
    </style>
</head>
<body>
    <div class="form-fill-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-file-alt me-2"></i>填寫表單
            </h1>
        </div>

        <div id="loadingIndicator" class="loading">
            <i class="fas fa-spinner fa-spin fa-2x"></i>
            <p>載入中...</p>
        </div>

        <div id="errorMessage" class="error-message" style="display: none;"></div>

        <div id="formCard" class="form-card" style="display: none;">
            <div id="formDescription" class="form-description"></div>
            <form id="formFillForm">
                <input type="hidden" id="form_ID" name="form_ID">
                <input type="hidden" id="fs_ID" name="fs_ID" value="0">
                <input type="hidden" id="team_ID" name="team_ID" value="<?= $team_ID ?: '' ?>">
                <div id="questionsContainer"></div>
                <div style="text-align: center; display: flex; justify-content: center; gap: 10px; align-items: center;">
                    <button type="button" class="btn-draft" id="draftBtn" onclick="saveDraft()">
                        <i class="fas fa-save me-2"></i>暫存
                    </button>
                    <button type="submit" class="btn-submit" id="submitBtn">
                        <i class="fas fa-paper-plane me-2"></i>提交表單
                    </button>
                    <button type="button" class="btn-export" id="exportBtn" style="display: none;" onclick="exportForm()">
                        <i class="fas fa-file-pdf me-2"></i>匯出 PDF
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let formData = null;
        let submissionData = null;
        const form_ID = <?= $form_ID ?>;
        const team_ID = <?= $team_ID ?: 'null' ?>;

        // 載入表單
        async function loadForm() {
            try {
                const API_ROOT = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
                const response = await fetch(`${API_ROOT}?do=get_form_detail&form_ID=${form_ID}`);
                const data = await response.json();
                
                if (data.ok) {
                    formData = data.form;
                    renderForm(data.form);
                    
                    // 先載入動態選項，然後再載入已保存的答案
                    // 這樣可以確保選項已經載入完成，答案才能正確匹配並顯示名稱
                    await loadDatabaseOptions(data.form.questions);
                    
                    // 等待選項完全載入後，再載入已保存的答案
                    setTimeout(async () => {
                        // 如果有團隊ID，先檢查是否有團隊的表單提交記錄（優先載入已填寫的內容）
                        if (team_ID) {
                            await loadTeamSubmission();
                        } else if (submissionData && submissionData.submission_id) {
                            // 個人表單，載入個人提交記錄
                            await loadSubmission(submissionData.submission_id);
                        }
                        
                        // 自動填入資料庫中的資料（只填入空白欄位，不覆蓋已填寫的內容）
                        await autoFillForm();
                    }, 500);
                } else {
                    showError(data.msg || '載入表單失敗');
                }
            } catch (error) {
                console.error('載入表單錯誤:', error);
                showError('無法載入表單');
            }
        }
        
        // 自動填入表單資料
        async function autoFillForm() {
            try {
                const API_ROOT = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
                const teamParam = team_ID ? `&team_ID=${team_ID}` : '';
                const response = await fetch(`${API_ROOT}?do=auto_fill_form_with_ai&form_ID=${form_ID}${teamParam}`);
                const data = await response.json();
                
                if (data.ok && data.auto_fill) {
                    const autoFill = data.auto_fill;
                    let filledCount = 0;
                    
                    // 填入匹配的資料
                    for (const [fq_ID, value] of Object.entries(autoFill)) {
                        const questionId = `q_${fq_ID}`;
                        const element = document.getElementById(questionId) || 
                                       document.querySelector(`input[name="${questionId}"]`) ||
                                       document.querySelector(`select[name="${questionId}"]`) ||
                                       document.querySelector(`textarea[name="${questionId}"]`);
                        
                        if (element) {
                            // 檢查是否已經有值（避免覆蓋已填寫的資料）
                            if (element.value === '' || element.value === null) {
                                if (element.type === 'checkbox') {
                                    // 複選框
                                    const checkbox = document.querySelector(`input[name="${questionId}[]"][value="${escapeHtml(value)}"]`);
                                    if (checkbox) {
                                        checkbox.checked = true;
                                        filledCount++;
                                    }
                                } else if (element.type === 'radio') {
                                    // 單選框
                                    const radio = document.querySelector(`input[name="${questionId}"][value="${escapeHtml(value)}"]`);
                                    if (radio) {
                                        radio.checked = true;
                                        filledCount++;
                                    }
                                } else {
                                    // 其他類型（text, textarea, select, number, date）
                                    element.value = value;
                                    filledCount++;
                                }
                                
                                // 標記為自動填入（添加視覺提示）
                                element.classList.add('auto-filled');
                                element.style.backgroundColor = '#e7f3ff';
                                element.title = '此欄位已自動填入，您可以編輯';
                            }
                        }
                    }
                    
                    if (filledCount > 0) {
                        // 顯示提示訊息
                        const infoDiv = document.createElement('div');
                        infoDiv.className = 'alert alert-info';
                        infoDiv.style.marginBottom = '20px';
                        infoDiv.style.padding = '15px';
                        infoDiv.style.borderRadius = '5px';
                        infoDiv.style.borderLeft = '4px solid #17a2b8';
                        infoDiv.innerHTML = `
                            <div style="display: flex; align-items: center;">
                                <i class="fas fa-magic" style="font-size: 20px; margin-right: 10px; color: #17a2b8;"></i>
                                <div>
                                    <strong>AI 自動填入完成</strong><br>
                                    <small>已自動填入 <strong>${filledCount}</strong> 個欄位（淺藍色背景），您可以編輯這些資料。</small>
                                </div>
                            </div>
                        `;
                        const formCard = document.getElementById('formCard');
                        const formDescription = document.getElementById('formDescription');
                        formCard.insertBefore(infoDiv, formDescription.nextSibling);
                        
                        // 3秒後淡出提示
                        setTimeout(() => {
                            infoDiv.style.transition = 'opacity 0.5s';
                            infoDiv.style.opacity = '0.7';
                        }, 5000);
                    }
                }
            } catch (error) {
                console.error('自動填入錯誤:', error);
                // 自動填入失敗不影響表單使用，只記錄錯誤
            }
        }
        
        // 保存已填寫的答案（用於動態選項載入後恢復）
        let savedAnswers = null;
        
        // 載入團隊的表單提交記錄
        async function loadTeamSubmission() {
            try {
                const API_ROOT = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
                // 查找該團隊對該表單的提交記錄
                const response = await fetch(`${API_ROOT}?do=get_team_form_submission&form_ID=${form_ID}&team_ID=${team_ID}`);
                const data = await response.json();
                
                if (data.ok && data.submission) {
                    document.getElementById('fs_ID').value = data.submission.fs_ID;
                    
                    // 保存答案，以便在動態選項載入後恢復
                    savedAnswers = data.submission.answers;
                    
                    const submission = data.submission;
                    const fsStatus = submission.fs_status;
                    const reviewStatus = submission.fs_review_status;
                    
                    // 如果已正式提交（fs_status != 1），顯示匯出按鈕和狀態提示
                    if (fsStatus != 1) {
                        document.getElementById('exportBtn').style.display = 'inline-block';
                        
                        // 顯示審核狀態提示
                        showReviewStatus(reviewStatus, submission.fs_review_remark, submission.needs_resubmit);
                    } else if (submission.needs_resubmit) {
                        // 即使還是暫存狀態，如果表單有更新，也顯示提示
                        showFormUpdatedNotice();
                    }
                    
                    // 等待動態選項載入完成後，再填入答案（確保選項已載入，才能正確顯示名稱）
                    setTimeout(() => {
                        if (savedAnswers) {
                            fillAnswers(savedAnswers);
                        }
                    }, 300); // 動態選項應該已經在 loadForm 中載入完成
                }
            } catch (error) {
                console.error('載入團隊提交記錄錯誤:', error);
            }
        }
        
        // 顯示表單更新提示
        function showFormUpdatedNotice() {
            const noticeDiv = document.createElement('div');
            noticeDiv.className = 'alert alert-warning';
            noticeDiv.style.marginBottom = '20px';
            noticeDiv.style.padding = '15px';
            noticeDiv.style.borderRadius = '5px';
            noticeDiv.style.borderLeft = '4px solid #ffc107';
            noticeDiv.innerHTML = `
                <div style="display: flex; align-items: flex-start;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-right: 15px; margin-top: 2px; color: #ffc107;"></i>
                    <div style="flex: 1;">
                        <strong style="font-size: 16px;">表單已更新</strong>
                        <p style="margin: 8px 0 0 0; font-size: 14px;">此表單已經更新（可能新增了題目或修改了內容），請重新填寫並提交。您可以保留之前填寫的內容，但請確認所有新題目都已填寫完成。</p>
                    </div>
                </div>
            `;
            
            const formCard = document.getElementById('formCard');
            const formDescription = document.getElementById('formDescription');
            formCard.insertBefore(noticeDiv, formDescription.nextSibling);
        }
        
        // 顯示審核狀態提示
        function showReviewStatus(reviewStatus, reviewRemark, needsResubmit = false) {
            const statusMap = {
                0: { text: '待審核', class: 'warning', icon: 'fa-clock', message: '您的表單已提交，正在等待審核中。審核通過後即可進入系統。' },
                1: { text: '已通過', class: 'info', icon: 'fa-check-circle', message: '您的表單已通過審核，但尚未結案。請等待管理員結案後即可進入系統。' },
                2: { text: '已退件', class: 'danger', icon: 'fa-times-circle', message: '您的表單已被退件，請根據審核意見修改後重新提交。' },
                3: { text: '已結案', class: 'success', icon: 'fa-check-double', message: '您的表單已結案，可以進入系統了！' }
            };
            
            // 如果沒有 reviewStatus，嘗試從 fs_remark 解析
            if (reviewStatus === null || reviewStatus === undefined) {
                // 嘗試從其他來源獲取（如果有的話）
                reviewStatus = 0; // 預設為待審核
            }
            
            const status = statusMap[reviewStatus] || statusMap[0];
            
            // 創建狀態提示框
            const statusDiv = document.createElement('div');
            statusDiv.className = `alert alert-${status.class}`;
            statusDiv.style.marginBottom = '20px';
            statusDiv.style.padding = '15px';
            statusDiv.style.borderRadius = '5px';
            statusDiv.style.borderLeft = `4px solid ${
                status.class === 'warning' ? '#ffc107' :
                status.class === 'danger' ? '#dc3545' :
                status.class === 'success' ? '#28a745' :
                '#17a2b8'
            }`;
            statusDiv.innerHTML = `
                <div style="display: flex; align-items: flex-start;">
                    <i class="fas ${status.icon}" style="font-size: 24px; margin-right: 15px; margin-top: 2px; color: ${
                        status.class === 'warning' ? '#ffc107' :
                        status.class === 'danger' ? '#dc3545' :
                        status.class === 'success' ? '#28a745' :
                        '#17a2b8'
                    };"></i>
                    <div style="flex: 1;">
                        <strong style="font-size: 16px;">表單狀態：${status.text}</strong>
                        <p style="margin: 8px 0 0 0; font-size: 14px;">${status.message}</p>
                        ${reviewRemark ? `<p style="margin: 8px 0 0 0; font-size: 13px; color: #666;"><strong>審核意見：</strong>${escapeHtml(reviewRemark)}</p>` : ''}
                    </div>
                </div>
            `;
            
            // 插入到表單描述下方
            const formCard = document.getElementById('formCard');
            const formDescription = document.getElementById('formDescription');
            formCard.insertBefore(statusDiv, formDescription.nextSibling);
            
            // 如果表單已更新，顯示更新提示
            if (needsResubmit) {
                const updateDiv = document.createElement('div');
                updateDiv.className = 'alert alert-warning';
                updateDiv.style.marginTop = '10px';
                updateDiv.style.padding = '12px';
                updateDiv.style.borderRadius = '5px';
                updateDiv.style.fontSize = '14px';
                updateDiv.style.borderLeft = '4px solid #ffc107';
                updateDiv.innerHTML = `
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>表單已更新：</strong>此表單已經更新（可能新增了題目或修改了內容），即使審核狀態正常，您也需要重新填寫並提交最新版本的表單。您可以保留之前填寫的內容，但請確認所有新題目都已填寫完成。
                `;
                statusDiv.appendChild(updateDiv);
                
                // 如果已結案，允許重新編輯和提交
                if (reviewStatus == 3) {
                    statusDiv.querySelector('.alert-info')?.remove();
                }
            }
            
            // 如果是已結案，顯示成功訊息並可以進入系統
            if (reviewStatus == 3 && !needsResubmit) {
                // 可以進入系統，不需要特別處理
            } else if (reviewStatus != 3) {
                // 未結案，顯示提示：需要等待審核通過才能進入系統
                const infoDiv = document.createElement('div');
                infoDiv.className = 'alert alert-info';
                infoDiv.style.marginTop = '10px';
                infoDiv.style.padding = '12px';
                infoDiv.style.borderRadius = '5px';
                infoDiv.style.fontSize = '14px';
                infoDiv.innerHTML = `
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>提示：</strong>此表單需要審核通過並結案後，您才能進入系統主頁面。目前請在此頁面等待審核結果。
                `;
                statusDiv.appendChild(infoDiv);
            }
        }
        
        // HTML 轉義函數
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // 載入提交記錄
        async function loadSubmission(fs_ID) {
            try {
                const API_ROOT = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
                const response = await fetch(`${API_ROOT}?do=get_form_submission&fs_ID=${fs_ID}`);
                const data = await response.json();
                
                if (data.ok) {
                    fillAnswers(data.submission.answers);
                }
            } catch (error) {
                console.error('載入提交記錄錯誤:', error);
            }
        }

        // 渲染表單
        function renderForm(form) {
            document.getElementById('form_ID').value = form.form_ID;
            document.getElementById('formDescription').innerHTML = form.form_des || '';
            
            const container = document.getElementById('questionsContainer');
            container.innerHTML = '';
            
            if (!form.questions || form.questions.length === 0) {
                container.innerHTML = '<p class="text-center text-secondary">此表單沒有題目</p>';
                return;
            }
            
            form.questions.forEach((q, index) => {
                const questionHtml = renderQuestion(q, index);
                container.insertAdjacentHTML('beforeend', questionHtml);
            });
            
            // 初始化日期選擇器（flatpickr）
            document.querySelectorAll('.date-picker-input').forEach(input => {
                flatpickr(input, {
                    locale: 'zh_tw',
                    dateFormat: 'Y-m-d',
                    allowInput: false, // 禁用文字輸入，只能通過日曆選擇
                    clickOpens: true,
                    theme: 'light'
                });
            });
            
            document.getElementById('loadingIndicator').style.display = 'none';
            document.getElementById('formCard').style.display = 'block';
            
            // 注意：動態選項的載入已經在 loadForm 中處理，這裡不需要重複載入
        }
        
        // 載入資料庫選項（動態從資料庫載入，確保選項是最新的）
        async function loadDatabaseOptions(questions) {
            const API_ROOT = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
            
            for (const q of questions) {
                if (!['select', 'radio', 'checkbox'].includes(q.fq_type)) continue;
                if (!q.option_source || q.option_source === 'manual') continue;
                // 移除檢查：即使有存儲的選項，也要重新從資料庫載入以確保是最新的
                
                const questionId = `q_${q.fq_ID}`;
                const loadingEl = document.getElementById(`${questionId}_loading`);
                // 在填寫表單時，強制使用 'name' 來顯示名稱（除非明確設定為 'id'）
                let optionField = q.option_field || 'default';
                // 如果是班級、屆別、類組，且不是明確設定為 'id'，則使用 'name' 來顯示名稱
                if (['classes', 'cohorts', 'groups'].includes(q.option_source)) {
                    if (optionField === 'default' || optionField === 'both') {
                        optionField = 'name'; // 強制顯示名稱
                    }
                }
                
                try {
                    // 動態從資料庫載入選項
                    const response = await fetch(`${API_ROOT}?do=get_form_options&option_type=${q.option_source}&option_field=${optionField}`);
                    const data = await response.json();
                    
                    // 調試：記錄 API 返回的資料
                    if (['classes', 'cohorts', 'groups'].includes(q.option_source)) {
                        console.log(`載入 ${q.option_source} 選項 (option_field=${optionField}):`, data);
                        if (data.ok && data.options && data.options.length > 0) {
                            console.log('前3個選項範例:', data.options.slice(0, 3));
                        }
                    }
                    
                    if (data.ok && data.options && data.options.length > 0) {
                        const options = data.options;
                        
                        if (q.fq_type === 'select') {
                            const selectEl = document.getElementById(questionId);
                            if (selectEl) {
                                // 保存當前選中的值
                                const currentValue = selectEl.value;
                                
                                // 清空現有選項（保留「請選擇...」）
                                const firstOption = selectEl.querySelector('option[value=""]');
                                selectEl.innerHTML = '';
                                if (firstOption) selectEl.appendChild(firstOption);
                                
                                // 添加新選項（從資料庫動態載入）
                                options.forEach(opt => {
                                    const option = document.createElement('option');
                                    // value 是 ID（主鍵），用於儲存到資料庫
                                    option.value = opt.value;
                                    // textContent 是中文名稱，用於顯示給用戶
                                    // 確保使用 label 而不是 value
                                    const displayText = opt.label || opt.name || opt.value;
                                    option.textContent = displayText;
                                    selectEl.appendChild(option);
                                    // 調試：記錄前幾個選項
                                    if (options.indexOf(opt) < 3) {
                                        console.log(`選項 ${options.indexOf(opt) + 1}: value=${opt.value}, label=${opt.label}, textContent=${displayText}`);
                                    }
                                });
                                
                                // 如果之前有選中的值且新選項中存在，則恢復選中
                                if (currentValue) {
                                    const optionExists = Array.from(selectEl.options).some(opt => opt.value === currentValue);
                                    if (optionExists) {
                                        selectEl.value = currentValue;
                                    }
                                }
                                
                                if (loadingEl) loadingEl.remove();
                                
                                // 動態選項載入完成後，如果有保存的答案，再次恢復
                                // 注意：保存的答案可能是 ID，需要匹配 option 的 value（也是 ID）
                                // 選項的 value 是 ID，textContent 是中文名稱，這樣選擇後會顯示名稱
                                if (savedAnswers) {
                                    const savedAnswer = savedAnswers.find(a => a.fq_ID == q.fq_ID);
                                    if (savedAnswer && savedAnswer.fa_value) {
                                        // 嘗試匹配 value（ID），因為選項的 value 是 ID，label 是名稱
                                        const savedValue = String(savedAnswer.fa_value);
                                        const option = Array.from(selectEl.options).find(opt => 
                                            String(opt.value) === savedValue
                                        );
                                        if (option) {
                                            // 設定 value 為 ID，但顯示的會是 textContent（中文名稱）
                                            selectEl.value = option.value;
                                            // 確保顯示的是名稱而不是 ID
                                            console.log(`設定選項: value=${option.value}, textContent=${option.textContent}`);
                                        } else if (savedAnswer.fa_value) {
                                            // 如果選項不存在，添加該選項（保留已填寫的值）
                                            const customOption = document.createElement('option');
                                            customOption.value = savedAnswer.fa_value;
                                            customOption.textContent = savedAnswer.fa_value + ' (已刪除)';
                                            customOption.style.color = '#999';
                                            selectEl.appendChild(customOption);
                                            selectEl.value = savedAnswer.fa_value;
                                        }
                                    }
                                }
                            }
                        } else {
                            // radio 或 checkbox
                            const container = document.querySelector(`[data-question-id="${q.fq_ID}"]`);
                            if (container) {
                                // 保存當前選中的值（包括已填寫的答案）
                                const checkedValues = [];
                                container.querySelectorAll('input:checked').forEach(input => {
                                    checkedValues.push(input.value);
                                });
                                
                                // 檢查是否有保存的答案
                                if (savedAnswers) {
                                    const savedAnswer = savedAnswers.find(a => a.fq_ID == q.fq_ID);
                                    if (savedAnswer) {
                                        const values = Array.isArray(savedAnswer.fa_value) ? savedAnswer.fa_value : [savedAnswer.fa_value];
                                        values.forEach(val => {
                                            if (!checkedValues.includes(String(val))) {
                                                checkedValues.push(String(val));
                                            }
                                        });
                                    }
                                }
                                
                                // 重新生成選項（從資料庫動態載入），保留已選中的值
                                // 確保使用 label（中文名稱）而不是 value（ID）來顯示
                                container.innerHTML = options.map(opt => `
                                    <div class="option-item">
                                        <label>
                                            <input type="${q.fq_type}" 
                                                   name="${questionId}${q.fq_type === 'checkbox' ? '[]' : ''}" 
                                                   value="${escapeHtml(opt.value)}"
                                                   ${checkedValues.includes(String(opt.value)) ? 'checked' : ''}
                                                   ${q.fq_required == 1 && q.fq_type === 'radio' ? 'required' : ''}>
                                            ${escapeHtml(opt.label || opt.name || opt.value)}
                                        </label>
                                    </div>
                                `).join('');
                                
                                if (loadingEl) loadingEl.remove();
                            } else {
                                // 如果找不到容器，嘗試用其他方式查找
                                const altContainer = document.querySelector(`[data-option-source="${q.option_source}"]`);
                                if (altContainer) {
                                    // 保存當前選中的值
                                    const checkedValues = [];
                                    altContainer.querySelectorAll('input:checked').forEach(input => {
                                        checkedValues.push(input.value);
                                    });
                                    
                                    // 檢查是否有保存的答案
                                    if (savedAnswers) {
                                        const savedAnswer = savedAnswers.find(a => a.fq_ID == q.fq_ID);
                                        if (savedAnswer) {
                                            const values = Array.isArray(savedAnswer.fa_value) ? savedAnswer.fa_value : [savedAnswer.fa_value];
                                            values.forEach(val => {
                                                if (!checkedValues.includes(String(val))) {
                                                    checkedValues.push(String(val));
                                                }
                                            });
                                        }
                                    }
                                    
                                    altContainer.innerHTML = options.map(opt => `
                                        <div class="option-item">
                                            <label>
                                                <input type="${q.fq_type}" 
                                                       name="${questionId}${q.fq_type === 'checkbox' ? '[]' : ''}" 
                                                       value="${escapeHtml(opt.value)}"
                                                       ${checkedValues.includes(String(opt.value)) ? 'checked' : ''}
                                                       ${q.fq_required == 1 && q.fq_type === 'radio' ? 'required' : ''}>
                                                ${escapeHtml(opt.label || opt.name || opt.value)}
                                            </label>
                                        </div>
                                    `).join('');
                                    
                                    if (loadingEl) loadingEl.remove();
                                }
                            }
                        }
                    } else {
                        if (loadingEl) {
                            loadingEl.textContent = '目前沒有可用的選項';
                            loadingEl.className = 'text-warning mt-2';
                        } else {
                            // 如果沒有 loadingEl，創建一個提示
                            const questionEl = document.getElementById(questionId)?.closest('.question-item');
                            if (questionEl) {
                                const warningEl = document.createElement('div');
                                warningEl.className = 'text-warning mt-2';
                                warningEl.textContent = '目前沒有可用的選項';
                                questionEl.appendChild(warningEl);
                            }
                        }
                    }
                } catch (error) {
                    console.error('載入選項錯誤:', error);
                    if (loadingEl) {
                        loadingEl.textContent = '載入選項失敗';
                        loadingEl.className = 'text-danger mt-2';
                    }
                }
            }
        }

        // 渲染單個題目
        function renderQuestion(q, index) {
            const required = q.fq_required == 1;
            const requiredClass = required ? 'required' : '';
            const questionId = `q_${q.fq_ID}`;
            
            let inputHtml = '';
            
            switch (q.fq_type) {
                case 'short_text':
                    inputHtml = `
                        <input type="text" 
                               id="${questionId}" 
                               name="${questionId}" 
                               class="form-control" 
                               placeholder="${escapeHtml(q.fq_placeholder || '')}"
                               ${required ? 'required' : ''}>
                    `;
                    break;
                    
                case 'long_text':
                    inputHtml = `
                        <textarea id="${questionId}" 
                                  name="${questionId}" 
                                  class="form-control" 
                                  placeholder="${escapeHtml(q.fq_placeholder || '')}"
                                  ${required ? 'required' : ''}></textarea>
                    `;
                    break;
                    
                case 'number':
                    inputHtml = `
                        <input type="number" 
                               id="${questionId}" 
                               name="${questionId}" 
                               class="form-control" 
                               placeholder="${escapeHtml(q.fq_placeholder || '')}"
                               ${required ? 'required' : ''}>
                    `;
                    break;
                    
                case 'date':
                    inputHtml = `
                        <input type="text" 
                               id="${questionId}" 
                               name="${questionId}" 
                               class="form-control date-picker-input" 
                               placeholder="請選擇日期"
                               readonly
                               ${required ? 'required' : ''}>
                    `;
                    break;
                    
                case 'select':
                    const selectOptionSource = q.option_source || 'manual';
                    // 如果選項來源是資料庫，不顯示存儲的選項，等待動態載入
                    const selectOptions = (selectOptionSource === 'manual' && Array.isArray(q.fq_options)) ? q.fq_options : [];
                    inputHtml = `
                        <select id="${questionId}" 
                                name="${questionId}" 
                                class="form-control"
                                data-option-source="${selectOptionSource}"
                                ${required ? 'required' : ''}>
                            <option value="">請選擇...</option>
                            ${selectOptions.map(opt => `
                                <option value="${escapeHtml(opt)}">${escapeHtml(opt)}</option>
                            `).join('')}
                        </select>
                    `;
                    // 如果選項來源是資料庫，顯示載入提示（會由 loadDatabaseOptions 動態更新）
                    if (selectOptionSource !== 'manual') {
                        inputHtml += `<div class="text-secondary mt-2" id="${questionId}_loading">載入選項中...</div>`;
                    }
                    break;
                    
                case 'radio':
                    const radioOptionSource = q.option_source || 'manual';
                    // 如果選項來源是資料庫，不顯示存儲的選項，等待動態載入
                    const radioOptions = (radioOptionSource === 'manual' && Array.isArray(q.fq_options)) ? q.fq_options : [];
                    inputHtml = `
                        <div class="options-container" 
                             data-option-source="${radioOptionSource}"
                             data-question-id="${q.fq_ID}">
                            ${radioOptions.length > 0 ? radioOptions.map(opt => `
                                <div class="option-item">
                                    <label>
                                        <input type="radio" 
                                               name="${questionId}" 
                                               value="${escapeHtml(opt)}"
                                               ${required ? 'required' : ''}>
                                        ${escapeHtml(opt)}
                                    </label>
                                </div>
                            `).join('') : '<div class="text-secondary" id="' + questionId + '_loading">載入選項中...</div>'}
                        </div>
                    `;
                    break;
                    
                case 'checkbox':
                    const checkboxOptionSource = q.option_source || 'manual';
                    // 如果選項來源是資料庫，不顯示存儲的選項，等待動態載入
                    const checkboxOptions = (checkboxOptionSource === 'manual' && Array.isArray(q.fq_options)) ? q.fq_options : [];
                    inputHtml = `
                        <div class="options-container" 
                             data-option-source="${checkboxOptionSource}"
                             data-question-id="${q.fq_ID}">
                            ${checkboxOptions.length > 0 ? checkboxOptions.map(opt => `
                                <div class="option-item">
                                    <label>
                                        <input type="checkbox" 
                                               name="${questionId}[]" 
                                               value="${escapeHtml(opt)}">
                                        ${escapeHtml(opt)}
                                    </label>
                                </div>
                            `).join('') : '<div class="text-secondary" id="' + questionId + '_loading">載入選項中...</div>'}
                        </div>
                    `;
                    break;
            }
            
            return `
                <div class="question-group">
                    <label class="question-label ${requiredClass}">
                        ${index + 1}. ${escapeHtml(q.fq_title)}
                    </label>
                    ${inputHtml}
                </div>
            `;
        }

        // 填入答案
        function fillAnswers(answers) {
            if (!answers || !Array.isArray(answers)) return;
            
            answers.forEach(ans => {
                const questionId = `q_${ans.fq_ID}`;
                const element = document.getElementById(questionId) || 
                               document.querySelector(`input[name="${questionId}"]`) ||
                               document.querySelector(`select[name="${questionId}"]`) ||
                               document.querySelector(`textarea[name="${questionId}"]`);
                
                if (!element) {
                    // 如果元素還不存在（可能是動態選項還沒載入），保存答案到隱藏欄位
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = `${questionId}_saved`;
                    hiddenInput.value = JSON.stringify(Array.isArray(ans.fa_value) ? ans.fa_value : [ans.fa_value]);
                    document.body.appendChild(hiddenInput);
                    return;
                }
                
                if (ans.fq_type === 'checkbox') {
                    // 複選框
                    const values = Array.isArray(ans.fa_value) ? ans.fa_value : [ans.fa_value];
                    values.forEach(val => {
                        // 嘗試匹配 value 或 label
                        let checkbox = document.querySelector(`input[name="${questionId}[]"][value="${escapeHtml(val)}"]`);
                        if (!checkbox) {
                            // 如果找不到，嘗試找包含該值的選項
                            const allCheckboxes = document.querySelectorAll(`input[name="${questionId}[]"]`);
                            allCheckboxes.forEach(cb => {
                                if (cb.value.includes(val) || cb.nextElementSibling?.textContent.includes(val)) {
                                    checkbox = cb;
                                }
                            });
                        }
                        if (checkbox) {
                            checkbox.checked = true;
                        } else {
                            // 如果選項還不存在，保存答案以便稍後恢復
                            const hiddenInput = document.createElement('input');
                            hiddenInput.type = 'hidden';
                            hiddenInput.name = `${questionId}_saved`;
                            hiddenInput.value = JSON.stringify(values);
                            document.body.appendChild(hiddenInput);
                        }
                    });
                } else if (element.type === 'radio') {
                    // 單選框
                    let radio = document.querySelector(`input[name="${questionId}"][value="${escapeHtml(ans.fa_value)}"]`);
                    if (!radio) {
                        // 如果找不到，嘗試找包含該值的選項
                        const allRadios = document.querySelectorAll(`input[name="${questionId}"]`);
                        allRadios.forEach(r => {
                            if (r.value.includes(ans.fa_value) || r.nextElementSibling?.textContent.includes(ans.fa_value)) {
                                radio = r;
                            }
                        });
                    }
                    if (radio) {
                        radio.checked = true;
                    } else {
                        // 如果選項還不存在，保存答案以便稍後恢復
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = `${questionId}_saved`;
                        hiddenInput.value = JSON.stringify([ans.fa_value]);
                        document.body.appendChild(hiddenInput);
                    }
                } else {
                    // 其他類型（包括 select）
                    // 嘗試直接匹配 value
                    if (element.tagName === 'SELECT') {
                        // 注意：保存的答案可能是 ID，需要匹配 option 的 value（也是 ID）
                        // 選項的 value 是 ID，textContent 是名稱
                        const savedValue = String(ans.fa_value);
                        const option = Array.from(element.options).find(opt => 
                            String(opt.value) === savedValue
                        );
                        if (option) {
                            element.value = option.value;
                        } else if (ans.fa_value) {
                            // 如果選項不存在，嘗試添加該選項（可能是已刪除的選項，但保留答案）
                            const customOption = document.createElement('option');
                            customOption.value = ans.fa_value;
                            customOption.textContent = ans.fa_value + ' (已刪除)';
                            customOption.style.color = '#999';
                            element.appendChild(customOption);
                            element.value = ans.fa_value;
                        }
                    } else {
                        element.value = ans.fa_value;
                    }
                }
            });
            
            // 更新提交按鈕文字
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save me-2"></i>更新表單';
            const fs_ID = answers[0]?.fs_ID || 0;
            document.getElementById('fs_ID').value = fs_ID;
            
            // 如果有提交記錄，顯示匯出按鈕
            if (fs_ID > 0) {
                document.getElementById('exportBtn').style.display = 'inline-block';
            }
        }
        
        // 匯出表單為 PDF
        async function exportForm() {
            const fs_ID = document.getElementById('fs_ID').value;
            if (!fs_ID || fs_ID == 0) {
                Swal.fire('提示', '請先提交表單後才能匯出', 'info');
                return;
            }
            
            try {
                const API_ROOT = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
                const response = await fetch(`${API_ROOT}?do=get_form_export_url&fs_ID=${fs_ID}`);
                const data = await response.json();
                
                if (data.ok && data.export_url) {
                    // 修正 URL 路徑（確保是正確的相對路徑）
                    let exportUrl = data.export_url;
                    // 如果當前頁面在 pages/ 目錄下，且 URL 是 pages/ 開頭，需要調整
                    if (location.pathname.includes('/pages/')) {
                        // 如果 URL 是 pages/ 開頭，改為 ../pages/ 或直接使用相對路徑
                        if (exportUrl.startsWith('pages/')) {
                            // 當前在 pages/ 目錄下，需要回到上一層再進入 pages/
                            exportUrl = '../' + exportUrl;
                        } else if (!exportUrl.startsWith('../') && !exportUrl.startsWith('http') && !exportUrl.startsWith('/')) {
                            // 如果是相對路徑但不是 ../ 開頭，加上 ../
                            exportUrl = '../' + exportUrl;
                        }
                    }
                    // 在新視窗中開啟匯出頁面
                    window.open(exportUrl, '_blank');
                } else {
                    Swal.fire('錯誤', data.msg || '無法獲取匯出連結', 'error');
                }
            } catch (error) {
                console.error('匯出錯誤:', error);
                Swal.fire('錯誤', '匯出失敗，請稍後再試', 'error');
            }
        }

        // 收集答案
        function collectAnswers() {
            const answers = [];
            const questions = formData.questions;
            
            questions.forEach(q => {
                const questionId = `q_${q.fq_ID}`;
                let value = null;
                
                if (q.fq_type === 'checkbox') {
                    // 複選框
                    const checkboxes = document.querySelectorAll(`input[name="${questionId}[]"]:checked`);
                    value = Array.from(checkboxes).map(cb => cb.value);
                } else {
                    const element = document.getElementById(questionId) || 
                                   document.querySelector(`input[name="${questionId}"]`) ||
                                   document.querySelector(`select[name="${questionId}"]`) ||
                                   document.querySelector(`textarea[name="${questionId}"]`);
                    
                    if (element) {
                        value = element.value.trim();
                    }
                }
                
                if (value !== null && value !== '') {
                    answers.push({
                        fq_ID: q.fq_ID,
                        fa_value: value
                    });
                }
            });
            
            return answers;
        }
        
        // 暫存表單
        async function saveDraft() {
            const draftBtn = document.getElementById('draftBtn');
            draftBtn.disabled = true;
            draftBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>暫存中...';
            
            const answers = collectAnswers();
            
            try {
                const fd = new FormData();
                fd.append('form_ID', form_ID);
                fd.append('fs_ID', document.getElementById('fs_ID').value);
                fd.append('answers', JSON.stringify(answers));
                fd.append('is_draft', '1'); // 標記為暫存
                if (team_ID) {
                    fd.append('team_ID', team_ID);
                }
                
                const API_ROOT = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
                const response = await fetch(`${API_ROOT}?do=submit_form`, {
                    method: 'POST',
                    body: fd
                });
                
                const data = await response.json();
                
                if (data.ok) {
                    if (data.fs_ID) {
                        document.getElementById('fs_ID').value = data.fs_ID;
                    }
                    Swal.fire({
                        icon: 'success',
                        title: '暫存成功',
                        text: '表單已暫存，您可以稍後繼續填寫',
                        confirmButtonText: '確定',
                        confirmButtonColor: '#6c757d',
                        timer: 2000
                    });
                } else {
                    Swal.fire('錯誤', data.msg || '暫存失敗', 'error');
                }
            } catch (error) {
                console.error('暫存表單錯誤:', error);
                Swal.fire('錯誤', '無法暫存表單', 'error');
            } finally {
                draftBtn.disabled = false;
                draftBtn.innerHTML = '<i class="fas fa-save me-2"></i>暫存';
            }
        }
        
        // 提交表單
        document.getElementById('formFillForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>提交中...';
            
            const answers = collectAnswers();
            
            try {
                const fd = new FormData();
                fd.append('form_ID', form_ID);
                fd.append('fs_ID', document.getElementById('fs_ID').value);
                fd.append('answers', JSON.stringify(answers));
                fd.append('is_draft', '0'); // 正式提交
                if (team_ID) {
                    fd.append('team_ID', team_ID);
                }
                
                const API_ROOT = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
                const response = await fetch(`${API_ROOT}?do=submit_form`, {
                    method: 'POST',
                    body: fd
                });
                
                const data = await response.json();
                
                if (data.ok) {
                    // 如果有提交ID，顯示匯出按鈕
                    if (data.fs_ID) {
                        document.getElementById('fs_ID').value = data.fs_ID;
                        document.getElementById('exportBtn').style.display = 'inline-block';
                    }
                    
                    // 提交成功，不自動跳轉，讓使用者可以匯出
                    Swal.fire({
                        icon: 'success',
                        title: '提交成功',
                        text: '表單已成功提交！您可以點擊「匯出 PDF」按鈕下載表單。',
                        confirmButtonText: '確定',
                        confirmButtonColor: '#28a745',
                        allowOutsideClick: false
                    });
                } else {
                    Swal.fire('錯誤', data.msg || '提交失敗', 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>提交表單';
                }
            } catch (error) {
                console.error('提交表單錯誤:', error);
                Swal.fire('錯誤', '無法提交表單', 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>提交表單';
            }
        });

        // 顯示錯誤
        function showError(message) {
            document.getElementById('loadingIndicator').style.display = 'none';
            document.getElementById('errorMessage').textContent = message;
            document.getElementById('errorMessage').style.display = 'block';
        }

        // 工具函數
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // 初始化
        if (form_ID > 0) {
            // 如果是專題初審單，先檢查是否已有提交記錄
            if (team_ID) {
                const API_ROOT = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
                fetch(`${API_ROOT}?do=get_team_form&team_ID=${team_ID}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.ok) {
                            submissionData = data.form;
                            if (data.form.submission_id) {
                                document.getElementById('fs_ID').value = data.form.submission_id;
                            }
                        }
                        loadForm();
                    })
                    .catch(err => {
                        console.error('獲取團隊表單錯誤:', err);
                        loadForm();
                    });
            } else {
                loadForm();
            }
        } else {
            showError('表單ID無效');
        }
    </script>
</body>
</html>

