<?php
session_start();
if (!isset($_SESSION['u_ID'])) {
    echo "<script>alert('請先登入');location.href='../index.php';</script>";
    exit;
}

$role_ID = $_SESSION['role_ID'] ?? 0;
if (!in_array($role_ID, [1, 2])) {
    echo "<script>alert('此頁面僅限主任和科辦使用');location.href='../main.php';</script>";
    exit;
}
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .form-management-page {
            min-height: 100vh;
            padding: 36px 24px;
            background: #f7f8fb;
            background-image: radial-gradient(#d9dfe7 1px, transparent 1px);
            background-size: 22px 22px;
        }
        .form-management-container {
            padding: 0;
            max-width: 1400px;
            margin: 0 auto;
        }
        .page-wrapper {
            background: linear-gradient(180deg, #ffffff 0%, #f3f6fb 100%);
            border-radius: 16px;
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.08);
            padding: 36px 42px 32px;
        }
        .page-header {
            margin-bottom: 26px;
        }
        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #333;
            display: flex;
            align-items: center;
        }
        .page-title i {
            color: #ffc107;
            margin-right: 10px;
        }
        .forms-list-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 28px rgba(0,0,0,0.08);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h5 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }
        .card-body {
            padding: 20px;
        }
        .btn-add-form {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-add-form:hover {
            background: #218838;
        }
        .forms-table {
            width: 100%;
            border-collapse: collapse;
        }
        .forms-table th,
        .forms-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .forms-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }
        .status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }
        .btn-action {
            padding: 5px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            margin-right: 5px;
        }
        .btn-edit {
            background: #007bff;
            color: white;
        }
        .btn-edit:hover {
            background: #0056b3;
        }
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        .btn-delete:hover {
            background: #c82333;
        }
        .form-edit-section {
            display: none;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            padding: 30px;
        }
        .form-edit-section.active {
            display: block;
        }
        .form-edit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eee;
        }
        .form-edit-content {
            display: flex;
            gap: 30px;
            align-items: flex-start;
        }
        .form-main-fields {
            flex: 1;
            min-width: 0;
        }
        .form-target-section {
            flex: 0 0 500px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            position: sticky;
            top: 20px;
            max-height: calc(100vh - 150px);
            overflow-y: auto;
        }
        @media (max-width: 1200px) {
            .form-edit-content {
                flex-direction: column;
            }
            .form-target-section {
                flex: 1;
                width: 100%;
                position: static;
                max-height: none;
            }
        }
        .form-edit-header h3 {
            margin: 0;
            font-size: 24px;
            color: #333;
        }
        .btn-close-edit {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-close-edit:hover {
            background: #5a6268;
        }
        .form-actions {
            display: none;
        }
        .form-edit-section.active .form-actions {
            display: block;
        }
        .btn-save-form {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-save-form:hover {
            background: #218838;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }
        .btn-save-form:active {
            transform: translateY(0);
        }
        .form-section {
            background: white;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
        }
        .form-section-title {
            font-size: 18px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            align-items: center;
        }
        .form-section-title i {
            margin-right: 10px;
            color: #007bff;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-row-inline {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        .form-row-inline .form-group {
            flex: 1;
            margin-bottom: 0;
        }
        @media (max-width: 992px) {
            .form-row-inline {
                flex-direction: column;
                gap: 20px;
            }
            .form-row-inline .form-group {
                width: 100%;
            }
        }
        .form-group label.required::after {
            content: " *";
            color: #dc3545;
        }
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .form-control:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        .questions-container {
            margin-top: 30px;
        }
        .question-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            border: 1px solid #dee2e6;
        }
        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .question-title-input {
            flex: 1;
            margin-right: 10px;
        }
        .btn-remove-question {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn-add-question {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 10px;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        .badge-count {
            background: #f8f9fa;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 13px;
            color: #6c757d;
        }
        .upload-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            overflow-y: auto;
        }
        .upload-modal-content {
            background: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 8px;
            width: 90%;
            max-width: 800px;
            position: relative;
        }
        .upload-area {
            border: 2px dashed #007bff;
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
        }
        .upload-area:hover {
            background: #e7f3ff;
            border-color: #0056b3;
        }
        .upload-area.dragover {
            background: #d4edff;
            border-color: #0056b3;
        }
        .upload-preview-container {
            display: flex;
            gap: 20px;
            margin-top: 20px;
            align-items: flex-start;
        }
        .upload-preview {
            flex: 1;
            max-width: 60%;
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            background: white;
        }
        .upload-preview img {
            max-width: 100%;
            height: auto;
            display: block;
        }
        .upload-preview canvas {
            max-width: 100%;
            height: auto;
            display: block;
        }
        .upload-target-settings {
            flex: 1;
            max-width: 40%;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 20px;
        }
        .upload-target-settings .form-section-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .upload-target-settings .form-group {
            margin-bottom: 15px;
        }
        .upload-target-settings .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 14px;
        }
        .upload-target-settings .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
        }
        .upload-target-settings select[multiple] {
            min-height: 120px;
        }
        .upload-target-settings small {
            display: block;
            margin-top: 5px;
            color: #6c757d;
            font-size: 12px;
        }
        @media (max-width: 992px) {
            .upload-preview-container {
                flex-direction: column;
            }
            .upload-preview,
            .upload-target-settings {
                max-width: 100%;
            }
        }
        .recognition-result {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
            max-height: 300px;
            overflow-y: auto;
        }
        .recognition-item {
            padding: 15px;
            margin-bottom: 15px;
            background: white;
            border-left: 3px solid #007bff;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }
        .recognition-item.editable {
            background: #f8f9fa;
        }
        .recognition-item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        .recognition-item-title {
            flex: 1;
            font-weight: 600;
            color: #333;
        }
        .recognition-item-edit-btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 4px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            margin-left: 10px;
        }
        .recognition-item-edit-btn:hover {
            background: #0056b3;
        }
        .recognition-item-delete-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 4px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            margin-left: 5px;
        }
        .recognition-item-delete-btn:hover {
            background: #c82333;
        }
        .recognition-item-body {
            margin-top: 10px;
        }
        .recognition-item-field {
            margin-bottom: 10px;
        }
        .recognition-item-field label {
            display: block;
            font-size: 12px;
            font-weight: 500;
            color: #666;
            margin-bottom: 4px;
        }
        .recognition-item-field input,
        .recognition-item-field select {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
        }
        .recognition-item-field textarea {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            min-height: 60px;
            resize: vertical;
        }
        .recognition-item-options {
            margin-top: 8px;
        }
        .recognition-item-option {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }
        .recognition-item-option input {
            flex: 1;
            margin-right: 5px;
        }
        .recognition-item-option-remove {
            background: #dc3545;
            color: white;
            border: none;
            padding: 2px 8px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
        }
        .recognition-item-add-option {
            background: #28a745;
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            margin-top: 5px;
        }
    </style>

<div class="form-management-page">
<div class="form-management-container">
       <div class="page-wrapper">

        <!-- 左側內容（占 80%） -->
        <div class="page-left"></div>
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-file-alt me-2"></i>表單管理
            </h1>
        </div>
             <div id="formEditSection" class="form-edit-section"></div>

        <!-- 表單編輯區域 -->
        <div id="formEditSection" class="form-edit-section">
            <div class="form-edit-header">
                <h3 id="formEditTitle">新增表單</h3>
                <button type="button" class="btn-close-edit" onclick="closeFormEdit()">
                    <i class="fas fa-times me-2"></i>關閉
                </button>
            </div>
            <form id="formEditForm">
                <div class="form-edit-content">
                <input type="hidden" id="form_ID" name="form_ID" value="0">
                
                <!-- 左側：基本表單欄位 -->
                <div class="form-main-fields">
                    <div class="form-group">
                        <label class="required">表單名稱</label>
                        <input type="text" id="form_name" class="form-control" required placeholder="請輸入表單名稱">
                    </div>

                    <div class="form-group">
                        <label>表單分類</label>
                        <input type="text" id="form_category" class="form-control" placeholder="例如：專題初審單、申請表">
                    </div>

                    <div class="form-group">
                        <label>說明內容</label>
                        <textarea id="form_des" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="form-row-inline">
                        <div class="form-group">
                            <label>狀態</label>
                            <select id="form_status" class="form-control">
                                <option value="1">啟用</option>
                                <option value="0">停用</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>開放時間</label>
                            <input type="datetime-local" id="form_start_d" class="form-control">
                        </div>

                        <div class="form-group">
                            <label>結束時間</label>
                            <input type="datetime-local" id="form_end_d" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>管理者備註</label>
                        <textarea id="form_remark" class="form-control" rows="2" placeholder="僅供管理員查看的備註資訊"></textarea>
                    </div>
                </div>

                <!-- 右側：目標對象設定區塊 -->
                <div class="form-target-section">
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-users"></i>目標對象設定
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="target_all_groups" onchange="handleTargetAllGroupsChange()">
                                不限類組（所有類組都可見）
                            </label>
                        </div>
                        <div class="form-group" id="targetGroupContainer" style="display: none;">
                            <label>指定類組</label>
                            <select id="target_group" class="form-control" multiple style="min-height: 100px;">
                                <!-- 將由 JavaScript 動態載入 -->
                            </select>
                            <small class="text-muted">可按住 Ctrl (Windows) 或 Cmd (Mac) 選擇多個類組</small>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>開始屆別</label>
                                <select id="target_cohort_from" class="form-control">
                                    <option value="">不限（留空表示不限）</option>
                                    <!-- 將由 JavaScript 動態載入 -->
                                </select>
                                <small class="text-muted">設定表單可見的起始屆別</small>
                            </div>
                            <div class="form-group">
                                <label>結束屆別</label>
                                <select id="target_cohort_to" class="form-control">
                                    <option value="">不限（留空表示不限）</option>
                                    <!-- 將由 JavaScript 動態載入 -->
                                </select>
                                <small class="text-muted">設定表單可見的結束屆別</small>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>目標對象備註</label>
                            <textarea id="target_remark" class="form-control" rows="2" placeholder="關於目標對象設定的說明"></textarea>
                        </div>
                    </div>
                </div>
                </div>

                <!-- 表單欄位區塊（全寬顯示） -->
                <div class="questions-container form-section" style="width: 100%; margin-top: 30px;">
                    <div class="form-section-title" style="margin-bottom: 20px;">
                        <i class="fas fa-list-ul"></i>表單欄位
                        <div style="margin-left: auto;">
                            <button type="button" class="btn-add-question" onclick="openUploadModal()" style="background: #17a2b8; margin: 0; padding: 8px 16px; font-size: 13px; margin-right: 10px;">
                                <i class="fas fa-upload me-2"></i>上傳格式並自動識別
                            </button>
                            <button type="button" class="btn-add-question" onclick="openFillTemplateModal()" style="background: #6f42c1; margin: 0; padding: 8px 16px; font-size: 13px;">
                                <i class="fas fa-file-import me-2"></i>填入標準範本
                            </button>
                        </div>
                    </div>
                    <div id="questionsList"></div>
                    <button type="button" class="btn-add-question" onclick="addQuestion()">
                        <i class="fas fa-plus me-2"></i>新增欄位
                </div>
                
                <!-- 儲存表單按鈕 -->
                <div class="form-actions" style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #e9ecef; text-align: right;">
                    <button type="button" class="btn-close-edit" onclick="closeFormEdit()" style="margin-right: 10px;">
                        <i class="fas fa-times me-2"></i>取消
                    </button>
                    <button type="submit" class="btn-save-form" form="formEditForm">
                        <i class="fas fa-save me-2"></i>儲存表單
                    </button>
                </div>
            </form>
        </div>

        <div class="forms-list-card">
            <div class="card-header">
                <h5><i class="fas fa-list me-2"></i>表單清單</h5>
                <div style="display: flex; align-items: center; gap: 15px;">
                    <span class="badge-count" id="formCount">載入中...</span>
                    <button class="btn-add-form" onclick="openFormEdit()">
                        <i class="fas fa-plus me-2"></i>新增表單
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div id="formsList">
                    <div class="text-center text-secondary">
                        <i class="fas fa-spinner fa-spin"></i> 載入中...
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <!-- 填入標準範本 Modal -->
    <div id="fillTemplateModal" class="upload-modal">
        <div class="upload-modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <h3 style="margin: 0;">填入標準範本</h3>
                <button onclick="closeFillTemplateModal()" style="background: none; border: none; font-size: 28px; cursor: pointer; color: #999; line-height: 1;">&times;</button>
            </div>
            
            <!-- 選擇填入方式 -->
            <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                <label style="display: block; margin-bottom: 12px; font-weight: 600;">選擇填入方式：</label>
                <div style="display: flex; gap: 15px;">
                    <label style="flex: 1; padding: 12px; border: 2px solid #dee2e6; border-radius: 6px; cursor: pointer; transition: all 0.3s;" 
                           onmouseover="this.style.borderColor='#6f42c1'; this.style.background='#f0f0ff';" 
                           onmouseout="if(!document.getElementById('fillMethodOCR').checked) {this.style.borderColor='#dee2e6'; this.style.background='transparent';}">
                        <input type="radio" name="fillMethod" id="fillMethodOCR" value="ocr" checked onchange="handleFillMethodChange()" style="margin-right: 8px;">
                        <strong>OCR 識別填入</strong>
                        <p style="font-size: 12px; color: #666; margin: 5px 0 0 0;">上傳表單文件，使用 AI 識別內容後填入</p>
                    </label>
                    <label style="flex: 1; padding: 12px; border: 2px solid #dee2e6; border-radius: 6px; cursor: pointer; transition: all 0.3s;" 
                           onmouseover="this.style.borderColor='#28a745'; this.style.background='#f0fff4';" 
                           onmouseout="if(!document.getElementById('fillMethodUser').checked) {this.style.borderColor='#dee2e6'; this.style.background='transparent';}">
                        <input type="radio" name="fillMethod" id="fillMethodUser" value="user" onchange="handleFillMethodChange()" style="margin-right: 8px;">
                        <strong>使用學生資料填入</strong>
                        <p style="font-size: 12px; color: #666; margin: 5px 0 0 0;">選擇要填寫表單的學生，自動從資料庫抓取其學號、姓名、團隊、指導老師等資料填入</p>
                    </label>
                </div>
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600;">步驟 1: 上傳標準範本文件</label>
                <p style="font-size: 13px; color: #666; margin-bottom: 10px;">請上傳您的標準範本文件（PDF、DOCX、DOC）</p>
                <input type="file" id="templateFileInput" accept=".pdf,.docx,.doc" style="display: none;" onchange="handleTemplateFileSelect(event)">
                <div class="upload-area" id="templateUploadArea" onclick="document.getElementById('templateFileInput').click()" style="min-height: 100px;">
                    <i class="fas fa-file-alt" style="font-size: 32px; color: #6f42c1; margin-bottom: 10px;"></i>
                    <p style="font-size: 14px; color: #666; margin: 5px 0;">點擊選擇標準範本文件</p>
                    <p style="font-size: 12px; color: #999;">支援 PDF、DOCX、DOC 格式</p>
                </div>
                <div id="templateFileInfo" style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px; display: none;">
                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                    <span id="templateFileName"></span>
                </div>
            </div>
            
            <!-- OCR 識別方式：上傳表單文件 -->
            <div id="ocrFormUploadSection" style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600;">步驟 2: 上傳要填寫的表單文件</label>
                <p style="font-size: 13px; color: #666; margin-bottom: 10px;">請上傳要填寫的表單文件（PDF、PNG、JPG）</p>
                <input type="file" id="formFileInput" accept=".pdf,.png,.jpg,.jpeg" style="display: none;" onchange="handleFormFileSelect(event)">
                <div class="upload-area" id="formUploadArea" onclick="document.getElementById('formFileInput').click()" style="min-height: 100px;">
                    <i class="fas fa-file-upload" style="font-size: 32px; color: #007bff; margin-bottom: 10px;"></i>
                    <p style="font-size: 14px; color: #666; margin: 5px 0;">點擊選擇要填寫的表單文件</p>
                    <p style="font-size: 12px; color: #999;">支援 PDF、PNG、JPG 格式</p>
                </div>
                <div id="formFileInfo" style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px; display: none;">
                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                    <span id="formFileName"></span>
                </div>
            </div>
            
            <!-- 使用學生資料方式：顯示將填入的資料預覽（實際使用時會自動抓「正在填寫表單的學生」的資料） -->
            <div id="userDataPreviewSection" style="margin-bottom: 20px; display: none; padding: 15px; background: #e7f3ff; border-radius: 8px; border: 1px solid #b3d9ff;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                    <i class="fas fa-info-circle me-2"></i>以下是「正在填寫表單的學生」會自動被填入的資料範例：
                </label>
                <div id="userDataPreview" style="font-size: 13px; color: #333;">
                    <div style="margin-bottom: 5px;"><strong>學號：</strong><span id="previewStudentId">載入中...</span></div>
                    <div style="margin-bottom: 5px;"><strong>姓名：</strong><span id="previewStudentName">載入中...</span></div>
                    <div style="margin-bottom: 5px;"><strong>班級：</strong><span id="previewClass">載入中...</span></div>
                    <div style="margin-bottom: 5px;"><strong>團隊名稱：</strong><span id="previewTeamName">載入中...</span></div>
                    <div style="margin-bottom: 5px;"><strong>指導老師：</strong><span id="previewAdvisor">載入中...</span></div>
                    <div style="margin-bottom: 5px;"><strong>團隊成員：</strong><span id="previewTeamMembers">載入中...</span></div>
                </div>
            </div>
            
            <div id="fillTemplateProgress" style="display: none; text-align: center; padding: 20px;">
                <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: #6f42c1;"></i>
                <p style="margin-top: 10px;">正在處理，請稍候...</p>
                <p style="font-size: 12px; color: #666; margin-top: 5px;">1. OCR 識別中...</p>
            </div>
            
            <div id="fillTemplateResult" style="display: none; margin-top: 20px;">
                <h5>識別結果：</h5>
                <div id="recognizedDataDisplay" style="padding: 15px; background: #f8f9fa; border-radius: 4px; margin-bottom: 15px; max-height: 300px; overflow-y: auto;"></div>
                <div style="text-align: right;">
                    <button type="button" onclick="closeFillTemplateModal()" style="padding: 8px 20px; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer; margin-right: 10px;">取消</button>
                    <a id="downloadFilledTemplate" href="#" download style="padding: 8px 20px; border: none; background: #6f42c1; color: white; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block;">
                        <i class="fas fa-download me-2"></i>下載填好的文件
                    </a>
                </div>
            </div>
            
            <div style="margin-top: 20px; text-align: right;">
                <button type="button" onclick="closeFillTemplateModal()" style="padding: 8px 20px; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer; margin-right: 10px;">取消</button>
                <button type="button" onclick="processFillTemplate()" id="processFillTemplateBtn" style="padding: 8px 20px; border: none; background: #6f42c1; color: white; border-radius: 4px; cursor: pointer;" disabled>
                    <i class="fas fa-magic me-2"></i>開始處理
                </button>
            </div>
        </div>
    </div>

    <!-- 上傳格式並自動識別 Modal -->
    <div id="uploadModal" class="upload-modal">
        <div class="upload-modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <h3 style="margin: 0;">上傳格式並自動識別題目</h3>
                <button onclick="closeUploadModal()" style="background: none; border: none; font-size: 28px; cursor: pointer; color: #999; line-height: 1;">&times;</button>
            </div>
            
            <div class="upload-area" id="uploadArea" onclick="document.getElementById('fileInput').click()">
                <i class="fas fa-cloud-upload-alt" style="font-size: 48px; color: #007bff; margin-bottom: 15px;"></i>
                <p style="font-size: 16px; color: #666; margin: 10px 0;">點擊或拖放文件到此處上傳</p>
                <p style="font-size: 14px; color: #999;">支援 PDF、PNG、JPG 格式</p>
            </div>
            <input type="file" id="fileInput" accept=".pdf,.png,.jpg,.jpeg" style="display: none;" onchange="handleFileSelect(event)">
            
            <div id="uploadPreviewContainer" class="upload-preview-container" style="display: none;">
                <div id="uploadPreview" class="upload-preview"></div>
                <div class="upload-target-settings">
                    <div class="form-section-title">
                        <i class="fas fa-users"></i>目標對象設定
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="upload_target_all_groups" onchange="handleUploadTargetAllGroupsChange()">
                            不限類組（所有類組都可見）
                        </label>
                    </div>
                    <div class="form-group" id="uploadTargetGroupContainer" style="display: none;">
                        <label>指定類組</label>
                        <select id="upload_target_group" class="form-control" multiple style="min-height: 100px;">
                            <!-- 將由 JavaScript 動態載入 -->
                        </select>
                        <small class="text-muted">可按住 Ctrl (Windows) 或 Cmd (Mac) 選擇多個類組</small>
                    </div>
                    <div class="form-row" style="display: flex; gap: 10px;">
                        <div class="form-group" style="flex: 1;">
                            <label>開始屆別</label>
                            <select id="upload_target_cohort_from" class="form-control">
                                <option value="">不限</option>
                                <!-- 將由 JavaScript 動態載入 -->
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>結束屆別</label>
                            <select id="upload_target_cohort_to" class="form-control">
                                <option value="">不限</option>
                                <!-- 將由 JavaScript 動態載入 -->
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="recognitionResult" class="recognition-result" style="display: none;">
                <h5>識別結果：</h5>
                <div id="recognitionItems"></div>
                <div style="margin-top: 15px; text-align: right;">
                    <button type="button" onclick="closeUploadModal()" style="padding: 8px 20px; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer; margin-right: 10px;">取消</button>
                    <button type="button" onclick="applyRecognitionResults()" style="padding: 8px 20px; border: none; background: #28a745; color: white; border-radius: 4px; cursor: pointer;">套用到表單</button>
                </div>
            </div>
            
            <div id="uploadProgress" style="display: none; text-align: center; padding: 20px;">
                <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: #007bff;"></i>
                <p style="margin-top: 10px;">正在識別題目，請稍候...</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        // 使用命名空間避免變數重複聲明
        (function() {
            // 初始化命名空間（如果不存在）
            if (!window.adminFormManage) {
                window.adminFormManage = {};
            }
            
            const adminForm = window.adminFormManage;
            
            // 如果已經初始化過，清理舊的事件監聽器
            if (adminForm.initialized) {
                const oldForm = document.getElementById('formEditForm');
                if (oldForm) {
                    const newForm = oldForm.cloneNode(true);
                    oldForm.parentNode.replaceChild(newForm, oldForm);
                }
            }
            
            // 初始化變數
            adminForm.currentForm = adminForm.currentForm || null;
            adminForm.questionCounter = adminForm.questionCounter || 0;
            
            // 動態判斷 API 路徑
            const FORM_API_ROOT = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';

        // 載入表單列表
        async function loadForms() {
            try {
                const response = await fetch(`${FORM_API_ROOT}?do=get_forms`);
                const data = await response.json();
                
                if (data.ok) {
                    renderFormsList(data.forms);
                    // 更新計數
                    document.getElementById('formCount').textContent = `共 ${data.forms.length} 筆`;
                } else {
                    Swal.fire('錯誤', data.msg || '載入失敗', 'error');
                    document.getElementById('formsList').innerHTML = '<div class="text-center text-danger">載入失敗</div>';
                }
            } catch (error) {
                console.error('載入表單列表錯誤:', error);
                Swal.fire('錯誤', '無法載入表單列表', 'error');
                document.getElementById('formsList').innerHTML = '<div class="text-center text-danger">無法連線到伺服器</div>';
            }
        }

        // 渲染表單列表
        function renderFormsList(forms) {
            const container = document.getElementById('formsList');
            
            if (forms.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h4>目前沒有表單</h4>
                        <p>請使用上方按鈕新增表單</p>
                    </div>
                `;
                return;
            }

            let html = `
                <div style="overflow-x: auto;">
                    <table class="forms-table">
                        <thead>
                            <tr>
                                <th>表單名稱</th>
                                <th>分類</th>
                                <th>狀態</th>
                                <th>建立時間</th>
                                <th style="width: 180px;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            forms.forEach(form => {
                const createdDate = form.form_created_d ? new Date(form.form_created_d).toLocaleString('zh-TW', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                }) : '-';
                html += `
                    <tr>
                        <td><strong>${escapeHtml(form.form_name)}</strong></td>
                        <td>${escapeHtml(form.form_category || '-')}</td>
                        <td>
                            <span class="status-badge ${form.form_status == 1 ? 'active' : 'inactive'}">
                                ${form.form_status == 1 ? '啟用' : '停用'}
                            </span>
                        </td>
                        <td>${createdDate}</td>
                        <td>
                            <button class="btn-action btn-edit" onclick="editForm(${form.form_ID})" title="編輯表單">
                                <i class="fas fa-edit"></i> 編輯
                            </button>
                            <button class="btn-action btn-delete" onclick="deleteForm(${form.form_ID})" title="刪除表單">
                                <i class="fas fa-trash"></i> 刪除
                            </button>
                        </td>
                    </tr>
                `;
            });

            html += `
                        </tbody>
                    </table>
                </div>
            `;

            container.innerHTML = html;
        }

        // 載入類組選項
        async function loadGroupOptions() {
            try {
                const response = await fetch(`${FORM_API_ROOT}?do=get_form_options&option_type=groups&option_field=default`);
                const data = await response.json();
                const select = document.getElementById('target_group');
                if (data.ok && data.options) {
                    select.innerHTML = '';
                    data.options.forEach(opt => {
                        const option = document.createElement('option');
                        option.value = opt.value;
                        option.textContent = opt.label;
                        select.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('載入類組選項錯誤:', error);
            }
        }

        // 載入屆別選項
        async function loadCohortOptions() {
            try {
                const response = await fetch(`${FORM_API_ROOT}?do=get_form_options&option_type=cohorts&option_field=default`);
                const data = await response.json();
                const fromSelect = document.getElementById('target_cohort_from');
                const toSelect = document.getElementById('target_cohort_to');
                
                if (data.ok && data.options) {
                    // 載入開始屆別選單
                    const fromDefaultOption = fromSelect.querySelector('option[value=""]');
                    fromSelect.innerHTML = '';
                    if (fromDefaultOption) {
                        fromSelect.appendChild(fromDefaultOption);
                    }
                    data.options.forEach(opt => {
                        const option = document.createElement('option');
                        option.value = opt.value;
                        option.textContent = opt.label;
                        fromSelect.appendChild(option);
                    });
                    
                    // 載入結束屆別選單
                    const toDefaultOption = toSelect.querySelector('option[value=""]');
                    toSelect.innerHTML = '';
                    if (toDefaultOption) {
                        toSelect.appendChild(toDefaultOption);
                    }
                    data.options.forEach(opt => {
                        const option = document.createElement('option');
                        option.value = opt.value;
                        option.textContent = opt.label;
                        toSelect.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('載入屆別選項錯誤:', error);
            }
        }

        // 處理「不限類組」選項變更
        window.handleTargetAllGroupsChange = function() {
            const checkbox = document.getElementById('target_all_groups');
            const container = document.getElementById('targetGroupContainer');
            if (checkbox.checked) {
                container.style.display = 'none';
                // 清空選中的類組
                const select = document.getElementById('target_group');
                Array.from(select.options).forEach(opt => opt.selected = false);
            } else {
                container.style.display = 'block';
            }
        }

        // 開啟表單編輯區域（暴露到全域作用域）
        window.openFormEdit = function openFormEdit(formId = 0) {
            adminForm.currentForm = null;
            adminForm.questionCounter = 0;
            document.getElementById('formEditTitle').textContent = formId > 0 ? '編輯表單' : '新增表單';
            document.getElementById('formEditForm').reset();
            document.getElementById('form_ID').value = formId;
            document.getElementById('questionsList').innerHTML = '';
            
            // 重置目標對象設定
            document.getElementById('target_all_groups').checked = true;
            handleTargetAllGroupsChange();
            document.getElementById('target_cohort_from').value = '';
            document.getElementById('target_cohort_to').value = '';
            document.getElementById('target_remark').value = '';
            
            // 載入類組和屆別選項
            loadGroupOptions();
            loadCohortOptions();
            
            // 顯示編輯區域並滾動到頂部
            const editSection = document.getElementById('formEditSection');
            editSection.classList.add('active');
            editSection.scrollIntoView({ behavior: 'smooth', block: 'start' });

            if (formId > 0) {
                loadFormDetail(formId);
            } else {
                addQuestion(); // 預設新增一個題目
            }
        }

        // 關閉編輯區域（暴露到全域作用域）
        window.closeFormEdit = function closeFormEdit() {
            document.getElementById('formEditSection').classList.remove('active');
            // 重置表單
            document.getElementById('formEditForm').reset();
            document.getElementById('questionsList').innerHTML = '';
        }

        // 載入表單詳情
        async function loadFormDetail(formId) {
            try {
                const response = await fetch(`${FORM_API_ROOT}?do=get_form_detail&form_ID=${formId}`);
                const data = await response.json();
                
                if (data.ok) {
                    adminForm.currentForm = data.form;
                    fillFormData(data.form);
                    renderQuestions(data.form.questions || []);
                } else {
                    Swal.fire('錯誤', data.msg || '載入失敗', 'error');
                }
            } catch (error) {
                console.error('載入表單詳情錯誤:', error);
                Swal.fire('錯誤', '無法載入表單詳情', 'error');
            }
        }

        // 填入表單資料
        function fillFormData(form) {
            document.getElementById('form_ID').value = form.form_ID;
            document.getElementById('form_name').value = form.form_name || '';
            document.getElementById('form_category').value = form.form_category || '';
            document.getElementById('form_des').value = form.form_des || '';
            document.getElementById('form_status').value = form.form_status || 1;
            document.getElementById('form_remark').value = form.form_remark || '';
            
            if (form.form_start_d) {
                const startDate = new Date(form.form_start_d);
                document.getElementById('form_start_d').value = startDate.toISOString().slice(0, 16);
            }
            if (form.form_end_d) {
                const endDate = new Date(form.form_end_d);
                document.getElementById('form_end_d').value = endDate.toISOString().slice(0, 16);
            }
            
            // 填入目標對象資料
            if (form.targets) {
                const targets = form.targets;
                // 檢查是否不限類組（ft_group 為 null 或空字串表示不限）
                const targetAllGroups = !targets.ft_group || targets.ft_group === '';
                document.getElementById('target_all_groups').checked = targetAllGroups;
                handleTargetAllGroupsChange();
                
                if (!targetAllGroups && targets.ft_group) {
                    // 如果有限定類組，設定選中的類組
                    // ft_group 可能是逗號分隔的字串
                    const select = document.getElementById('target_group');
                    const groupStr = String(targets.ft_group);
                    const groups = groupStr.includes(',') ? groupStr.split(',').map(g => g.trim()) : [groupStr.trim()];
                    Array.from(select.options).forEach(opt => {
                        opt.selected = groups.includes(opt.value);
                    });
                }
                
                document.getElementById('target_cohort_from').value = targets.ft_cohort_from || '';
                document.getElementById('target_cohort_to').value = targets.ft_cohort_to || '';
                document.getElementById('target_remark').value = targets.ft_remark || '';
            } else {
                // 如果沒有目標對象資料，預設為不限類組
                document.getElementById('target_all_groups').checked = true;
                handleTargetAllGroupsChange();
            }
        }

        // 渲染題目列表
        function renderQuestions(questions) {
            const container = document.getElementById('questionsList');
            container.innerHTML = '';
            
            if (questions.length === 0) {
                addQuestion();
                return;
            }

            questions.forEach((q, index) => {
                addQuestion(q, index);
                // 如果題目有選項來源且不是手動輸入，載入資料庫選項
                if (q.option_source && q.option_source !== 'manual' && ['select', 'radio', 'checkbox'].includes(q.fq_type)) {
                    setTimeout(() => {
                        const container = document.querySelectorAll('.question-item')[index];
                        if (container) {
                            const select = container.querySelector('.question-option-source');
                            if (select) {
                                handleOptionSourceChange(select);
                            }
                        }
                    }, 100);
                }
            });
        }

        // 新增欄位（暴露到全域作用域）
欄位        window.addQuestion = function addQuestion(question = null, index = null) {
            const container = document.getElementById('questionsList');
            const qIndex = index !== null ? index : adminForm.questionCounter++;
            
            const q = question || {
                fq_ID: 0,
                fq_title: '',
                fq_type: 'short_text',
                fq_required: 1,
                fq_placeholder: '',
                fq_options: [],
                fq_remark: ''
            };

            const questionHtml = `
                <div class="question-item" data-index="${qIndex}">
                    <div class="question-header">
                        <input type="text" 
                               class="form-control question-title-input" 
                               placeholder="欄位文字內容 *" 
                               value="${escapeHtml(q.fq_title)}"
                               required>
                        <button type="button" class="btn-remove-question" onclick="removeQuestion(this)">
                            <i class="fas fa-times"></i> 刪除
                        </button>
                    </div>
                    <div class="form-group">
                        <label>欄位類型</label>
                        <select class="form-control question-type" onchange="handleQuestionTypeChange(this)">
                            <option value="short_text" ${q.fq_type === 'short_text' ? 'selected' : ''}>短文字</option>
                            <option value="long_text" ${q.fq_type === 'long_text' ? 'selected' : ''}>長文字</option>
                            <option value="number" ${q.fq_type === 'number' ? 'selected' : ''}>數字</option>
                            <option value="date" ${q.fq_type === 'date' ? 'selected' : ''}>日期</option>
                            <option value="select" ${q.fq_type === 'select' ? 'selected' : ''}>下拉選單</option>
                            <option value="radio" ${q.fq_type === 'radio' ? 'selected' : ''}>單選</option>
                            <option value="checkbox" ${q.fq_type === 'checkbox' ? 'selected' : ''}>複選</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" class="question-required" ${q.fq_required == 1 ? 'checked' : ''}>
                            必填
                        </label>
                    </div>
                    <div class="form-group">
                        <label>輸入提示文字</label>
                        <input type="text" class="form-control question-placeholder" value="${escapeHtml(q.fq_placeholder || '')}">
                    </div>
                    <div class="form-group question-options-container" style="display: ${['select', 'radio', 'checkbox'].includes(q.fq_type) ? 'block' : 'none'};">
                        <label>選項來源 <small class="text-muted">(選擇如何提供選項)</small></label>
                        <div style="margin-bottom: 10px;">
                            <select class="form-control question-option-source" onchange="handleOptionSourceChange(this)" style="margin-bottom: 10px;">
                                <option value="manual" ${q.option_source === 'manual' || !q.option_source ? 'selected' : ''}>📝 手動輸入選項</option>
                                <option value="classes" ${q.option_source === 'classes' ? 'selected' : ''}>🏫 班級（從資料庫載入）</option>
                                <option value="cohorts" ${q.option_source === 'cohorts' ? 'selected' : ''}>📅 屆別（從資料庫載入）</option>
                                <option value="groups" ${q.option_source === 'groups' ? 'selected' : ''}>👥 類組（從資料庫載入）</option>
                                <option value="students" ${q.option_source === 'students' ? 'selected' : ''}>🎓 學生（當前屆別，從資料庫載入）</option>
                                <option value="teachers" ${q.option_source === 'teachers' ? 'selected' : ''}>👨‍🏫 指導老師（從資料庫載入）</option>
                                <option value="teams" ${q.option_source === 'teams' ? 'selected' : ''}>🤝 團隊（當前屆別，從資料庫載入）</option>
                            </select>
                        </div>
                        <!-- 資料欄位選擇（僅在選擇資料庫來源時顯示） -->
                        <div class="question-option-field-selector" style="display: ${q.option_source && q.option_source !== 'manual' ? 'block' : 'none'}; margin-bottom: 10px;">
                            <label>顯示欄位 <small class="text-muted">(選擇要顯示的資料)</small></label>
                            <select class="form-control question-option-field" onchange="handleOptionFieldChange(this)">
                                ${(() => {
                                    const fieldOptions = {
                                        'classes': [
                                            { value: 'default', label: '班級名稱' },
                                            { value: 'id', label: '班級ID' },
                                            { value: 'both', label: '班級ID - 班級名稱' }
                                        ],
                                        'cohorts': [
                                            { value: 'default', label: '屆別名稱' },
                                            { value: 'id', label: '屆別ID' },
                                            { value: 'both', label: '屆別ID - 屆別名稱' }
                                        ],
                                        'groups': [
                                            { value: 'default', label: '類組名稱' },
                                            { value: 'id', label: '類組ID' },
                                            { value: 'both', label: '類組ID - 類組名稱' }
                                        ],
                                        'students': [
                                            { value: 'default', label: '學號 - 姓名' },
                                            { value: 'id', label: '學號' },
                                            { value: 'name', label: '姓名' }
                                        ],
                                        'teachers': [
                                            { value: 'default', label: '學號 - 姓名' },
                                            { value: 'id', label: '學號' },
                                            { value: 'name', label: '姓名' }
                                        ],
                                        'teams': [
                                            { value: 'default', label: '專題名稱' },
                                            { value: 'id', label: '團隊ID' },
                                            { value: 'both', label: '團隊ID - 專題名稱' }
                                        ]
                                    };
                                    const options = fieldOptions[q.option_source] || [];
                                    const selected = q.option_field || 'default';
                                    return options.map(opt => 
                                        `<option value="${opt.value}" ${opt.value === selected ? 'selected' : ''}>${opt.label}</option>`
                                    ).join('');
                                })()}
                            </select>
                            <input type="hidden" class="question-option-field-value" value="${q.option_field || 'default'}">
                        </div>
                        <div class="question-options-manual" style="display: ${q.option_source && q.option_source !== 'manual' ? 'none' : 'block'};">
                            <label>選項（每行一個）</label>
                            <textarea class="form-control question-options" rows="4">${Array.isArray(q.fq_options) ? q.fq_options.join('\n') : ''}</textarea>
                        </div>
                        <div class="question-options-db" style="display: ${q.option_source && q.option_source !== 'manual' ? 'block' : 'none'};">
                            <label>資料庫選項預覽 <small class="text-muted">(將從資料庫動態載入)</small></label>
                            <div class="form-control" style="min-height: 100px; max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px;" data-option-source="${q.option_source || ''}">
                                ${q.option_source && q.option_source !== 'manual' ? '<div class="text-secondary"><i class="fas fa-spinner fa-spin"></i> 載入中...</div>' : '<div class="text-secondary">請選擇選項來源</div>'}
                            </div>
                            <small class="text-muted" style="display: block; margin-top: 5px;">
                                <i class="fas fa-info-circle"></i> 選擇資料庫來源後，選項會自動從資料庫載入，無需手動輸入
                            </small>
                            <input type="hidden" class="question-option-source-value" value="${q.option_source || 'manual'}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>欄位備註</label>
                        <input type="text" class="form-control question-remark" value="${escapeHtml(q.fq_remark || '')}">
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', questionHtml);
        }

        // 處理欄位類型變更（暴露到全域作用域）
        window.handleQuestionTypeChange = function handleQuestionTypeChange(select) {
            const container = select.closest('.question-item');
            const optionsContainer = container.querySelector('.question-options-container');
            const questionType = select.value;
            
            if (['select', 'radio', 'checkbox'].includes(questionType)) {
                optionsContainer.style.display = 'block';
            } else {
                optionsContainer.style.display = 'none';
            }
        }

        // 生成欄位選項 HTML
        function getOptionFieldOptions(optionSource, selectedField = 'default') {
            const fieldOptions = {
                'classes': [
                    { value: 'default', label: '班級名稱' },
                    { value: 'id', label: '班級ID' },
                    { value: 'both', label: '班級ID - 班級名稱' }
                ],
                'cohorts': [
                    { value: 'default', label: '屆別名稱' },
                    { value: 'id', label: '屆別ID' },
                    { value: 'both', label: '屆別ID - 屆別名稱' }
                ],
                'groups': [
                    { value: 'default', label: '類組名稱' },
                    { value: 'id', label: '類組ID' },
                    { value: 'both', label: '類組ID - 類組名稱' }
                ],
                'students': [
                    { value: 'default', label: '學號 - 姓名' },
                    { value: 'id', label: '學號' },
                    { value: 'name', label: '姓名' }
                ],
                'teachers': [
                    { value: 'default', label: '學號 - 姓名' },
                    { value: 'id', label: '學號' },
                    { value: 'name', label: '姓名' }
                ],
                'teams': [
                    { value: 'default', label: '專題名稱' },
                    { value: 'id', label: '團隊ID' },
                    { value: 'both', label: '團隊ID - 專題名稱' }
                ]
            };
            
            const options = fieldOptions[optionSource] || [];
            return options.map(opt => 
                `<option value="${opt.value}" ${opt.value === selectedField ? 'selected' : ''}>${opt.label}</option>`
            ).join('');
        }
        
        // 處理選項來源變更（暴露到全域作用域）
        window.handleOptionSourceChange = async function handleOptionSourceChange(select) {
            const container = select.closest('.question-item');
            const optionSource = select.value;
            const manualDiv = container.querySelector('.question-options-manual');
            const dbDiv = container.querySelector('.question-options-db');
            const fieldSelector = container.querySelector('.question-option-field-selector');
            const fieldSelect = container.querySelector('.question-option-field');
            const dbPreview = dbDiv.querySelector('[data-option-source]');
            const hiddenInput = container.querySelector('.question-option-source-value');
            
            hiddenInput.value = optionSource;
            
            if (optionSource === 'manual') {
                manualDiv.style.display = 'block';
                dbDiv.style.display = 'none';
                if (fieldSelector) fieldSelector.style.display = 'none';
            } else {
                manualDiv.style.display = 'none';
                dbDiv.style.display = 'block';
                if (fieldSelector) fieldSelector.style.display = 'block';
                
                // 更新欄位選擇器選項
                if (fieldSelect) {
                    const fieldOptions = {
                        'classes': [
                            { value: 'default', label: '班級名稱' },
                            { value: 'id', label: '班級ID' },
                            { value: 'both', label: '班級ID - 班級名稱' }
                        ],
                        'cohorts': [
                            { value: 'default', label: '屆別名稱' },
                            { value: 'id', label: '屆別ID' },
                            { value: 'both', label: '屆別ID - 屆別名稱' }
                        ],
                        'groups': [
                            { value: 'default', label: '類組名稱' },
                            { value: 'id', label: '類組ID' },
                            { value: 'both', label: '類組ID - 類組名稱' }
                        ],
                        'students': [
                            { value: 'default', label: '學號 - 姓名' },
                            { value: 'id', label: '學號' },
                            { value: 'name', label: '姓名' }
                        ],
                        'teachers': [
                            { value: 'default', label: '學號 - 姓名' },
                            { value: 'id', label: '學號' },
                            { value: 'name', label: '姓名' }
                        ],
                        'teams': [
                            { value: 'default', label: '專題名稱' },
                            { value: 'id', label: '團隊ID' },
                            { value: 'both', label: '團隊ID - 專題名稱' }
                        ]
                    };
                    const options = fieldOptions[optionSource] || [];
                    fieldSelect.innerHTML = options.map(opt => 
                        `<option value="${opt.value}">${opt.label}</option>`
                    ).join('');
                    
                    // 更新隱藏欄位值
                    const fieldValueInput = container.querySelector('.question-option-field-value');
                    if (fieldValueInput) {
                        fieldValueInput.value = fieldSelect.value;
                    }
                    
                    // 觸發欄位變更以載入選項
                    handleOptionFieldChange(fieldSelect);
                } else {
                    // 如果欄位選擇器不存在，直接載入預設選項
                    loadDatabaseOptions(optionSource, 'default', dbPreview);
                }
            }
        }
        
        // 處理欄位選擇變更
        window.handleOptionFieldChange = async function handleOptionFieldChange(select) {
            const container = select.closest('.question-item');
            const optionSource = container.querySelector('.question-option-source').value;
            const optionField = select.value;
            const dbPreview = container.querySelector('[data-option-source]');
            const fieldValueInput = container.querySelector('.question-option-field-value');
            
            // 更新隱藏欄位值
            if (fieldValueInput) {
                fieldValueInput.value = optionField;
            }
            
            if (optionSource && optionSource !== 'manual' && dbPreview) {
                loadDatabaseOptions(optionSource, optionField, dbPreview);
            }
        }
        
        // 載入資料庫選項
        async function loadDatabaseOptions(optionSource, optionField, dbPreview) {
            try {
                dbPreview.innerHTML = '<div class="text-secondary"><i class="fas fa-spinner fa-spin"></i> 載入中...</div>';
                const response = await fetch(`${FORM_API_ROOT}?do=get_form_options&option_type=${optionSource}&option_field=${optionField || 'default'}`);
                const data = await response.json();
                
                if (data.ok && data.options) {
                    if (data.options.length === 0) {
                        dbPreview.innerHTML = '<div class="text-warning"><i class="fas fa-exclamation-triangle"></i> 目前沒有可用的選項</div>';
                    } else {
                        const optionsList = data.options.map(opt => 
                            `<div style="padding: 6px 10px; border-bottom: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center;">
                                <span><strong>${escapeHtml(opt.label)}</strong></span>
                                <span class="text-muted" style="font-size: 12px;">值: ${escapeHtml(opt.value)}</span>
                            </div>`
                        ).join('');
                        dbPreview.innerHTML = `
                            <div style="margin-bottom: 8px; padding: 8px; background: #e7f3ff; border-radius: 4px; font-size: 12px;">
                                <i class="fas fa-check-circle" style="color: #28a745;"></i> 找到 ${data.options.length} 個選項
                            </div>
                            ${optionsList}
                        `;
                        dbPreview.setAttribute('data-option-source', optionSource);
                        dbPreview.setAttribute('data-option-field', optionField);
                    }
                } else {
                    dbPreview.innerHTML = '<div class="text-danger"><i class="fas fa-times-circle"></i> 載入失敗: ' + (data.msg || '未知錯誤') + '</div>';
                }
            } catch (error) {
                console.error('載入選項錯誤:', error);
                dbPreview.innerHTML = '<div class="text-danger">無法載入選項</div>';
            }
        }
        window.removeQuestion = function removeQuestion(btn) {
            btn.closest('.question-item').remove();
        }

        // 儲存表單
        document.getElementById('formEditForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            e.stopPropagation(); // 阻止事件冒泡，避免影響 sidebar
            
            // 收集目標對象資料
            const targetAllGroups = document.getElementById('target_all_groups').checked;
            const targetGroupSelect = document.getElementById('target_group');
            const selectedGroups = targetAllGroups ? [] : Array.from(targetGroupSelect.selectedOptions).map(opt => opt.value);
            
            const formData = {
                form_ID: parseInt(document.getElementById('form_ID').value),
                form_name: document.getElementById('form_name').value.trim(),
                form_category: document.getElementById('form_category').value.trim(),
                form_des: document.getElementById('form_des').value.trim(),
                form_status: parseInt(document.getElementById('form_status').value),
                form_start_d: document.getElementById('form_start_d').value || null,
                form_end_d: document.getElementById('form_end_d').value || null,
                form_remark: document.getElementById('form_remark').value.trim(),
                questions: [],
                targets: {
                    ft_group: targetAllGroups ? null : (selectedGroups.length > 0 ? selectedGroups.join(',') : null),
                    ft_cohort_from: document.getElementById('target_cohort_from').value || null,
                    ft_cohort_to: document.getElementById('target_cohort_to').value || null,
                    ft_remark: document.getElementById('target_remark').value.trim() || null
                }
            };

            // 收集題目
            const questionItems = document.querySelectorAll('.question-item');
            questionItems.forEach(item => {
                const title = item.querySelector('.question-title-input').value.trim();
                if (!title) return;

                const type = item.querySelector('.question-type').value;
                const required = item.querySelector('.question-required').checked ? 1 : 0;
                const placeholder = item.querySelector('.question-placeholder').value.trim();
                const remark = item.querySelector('.question-remark').value.trim();
                const optionSource = item.querySelector('.question-option-source-value')?.value || 'manual';
                const optionField = item.querySelector('.question-option-field-value')?.value || 'default';
                
                let options = [];
                if (['select', 'radio', 'checkbox'].includes(type)) {
                    if (optionSource === 'manual') {
                        // 手動輸入的選項
                        const optionsText = item.querySelector('.question-options').value.trim();
                        if (optionsText) {
                            options = optionsText.split('\n').map(o => o.trim()).filter(o => o);
                        }
                    } else {
                        // 從資料庫載入的選項
                        try {
                            const dbPreview = item.querySelector('[data-option-source]');
                            if (dbPreview && dbPreview.getAttribute('data-option-source') === optionSource) {
                                // 從預覽中提取選項（這裡我們需要重新從 API 獲取，確保資料最新）
                                // 或者我們可以在儲存時再次從 API 獲取
                                // 為了簡化，我們先標記選項來源，後端會根據來源動態載入
                                options = []; // 空陣列，後端會根據 option_source 動態載入
                            }
                        } catch (e) {
                            console.error('處理資料庫選項錯誤:', e);
                        }
                    }
                }

                formData.questions.push({
                    fq_ID: 0,
                    fq_title: title,
                    fq_type: type,
                    fq_required: required,
                    fq_placeholder: placeholder,
                    fq_options: options,
                    fq_remark: remark,
                    option_source: optionSource,
                    option_field: optionField
                });
            });

            if (formData.questions.length === 0) {
                Swal.fire('錯誤', '請至少新增一個欄位', 'error');
                return;
            }

            try {
                const fd = new FormData();
                Object.keys(formData).forEach(key => {
                    if (key === 'questions' || key === 'targets') {
                        fd.append(key, JSON.stringify(formData[key]));
                    } else {
                        fd.append(key, formData[key] || '');
                    }
                });

                const response = await fetch(`${FORM_API_ROOT}?do=save_form`, {
                    method: 'POST',
                    body: fd
                });

                const data = await response.json();

                if (data.ok) {
                    Swal.fire('成功', data.message, 'success').then(() => {
                        closeFormEdit();
                        loadForms();
                        // 滾動到表單列表
                        document.querySelector('.forms-list-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
                    });
                } else {
                    Swal.fire('錯誤', data.msg || '儲存失敗', 'error');
                }
            } catch (error) {
                console.error('儲存表單錯誤:', error);
                Swal.fire('錯誤', '無法儲存表單', 'error');
            }
        });

        // 編輯表單（暴露到全域作用域）
        window.editForm = function editForm(formId) {
            openFormEdit(formId);
        };

        // 刪除表單（暴露到全域作用域）
        window.deleteForm = async function deleteForm(formId) {
            const result = await Swal.fire({
                title: '確認刪除',
                text: '確定要刪除此表單嗎？此操作無法復原。',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '確定刪除',
                cancelButtonText: '取消',
                reverseButtons: true
            });

            if (!result.isConfirmed) return;

            try {
                const fd = new FormData();
                fd.append('form_ID', formId);

                const response = await fetch(`${FORM_API_ROOT}?do=delete_form`, {
                    method: 'POST',
                    body: fd
                });

                const data = await response.json();

                if (data.ok) {
                    Swal.fire('成功', data.message, 'success').then(() => {
                        loadForms();
                    });
                } else {
                    Swal.fire('錯誤', data.msg || '刪除失敗', 'error');
                }
            } catch (error) {
                console.error('刪除表單錯誤:', error);
                Swal.fire('錯誤', '無法刪除表單', 'error');
            }
        }

        // 工具函數
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }


        // ==================== 上傳格式並自動識別功能 ====================
        let recognitionResults = [];
        let uploadedFile = null;

        // 載入上傳 modal 中的目標對象選項
        async function loadUploadTargetOptions() {
            try {
                // 載入類組選項
                const groupResponse = await fetch(`${FORM_API_ROOT}?do=get_form_options&option_type=groups&option_field=default`);
                const groupData = await groupResponse.json();
                const uploadGroupSelect = document.getElementById('upload_target_group');
                if (groupData.ok && groupData.options) {
                    uploadGroupSelect.innerHTML = '';
                    groupData.options.forEach(option => {
                        const opt = document.createElement('option');
                        opt.value = option.value;
                        opt.textContent = option.label;
                        uploadGroupSelect.appendChild(opt);
                    });
                }
                
                // 載入屆別選項
                const cohortResponse = await fetch(`${FORM_API_ROOT}?do=get_form_options&option_type=cohorts&option_field=default`);
                const cohortData = await cohortResponse.json();
                const fromSelect = document.getElementById('upload_target_cohort_from');
                const toSelect = document.getElementById('upload_target_cohort_to');
                if (cohortData.ok && cohortData.options) {
                    fromSelect.innerHTML = '<option value="">不限</option>';
                    toSelect.innerHTML = '<option value="">不限</option>';
                    cohortData.options.forEach(option => {
                        const opt1 = document.createElement('option');
                        opt1.value = option.value;
                        opt1.textContent = option.label;
                        fromSelect.appendChild(opt1);
                        const opt2 = document.createElement('option');
                        opt2.value = option.value;
                        opt2.textContent = option.label;
                        toSelect.appendChild(opt2);
                    });
                }
            } catch (error) {
                console.error('載入目標對象選項錯誤:', error);
            }
        }

        // 處理上傳 modal 中的「不限類組」選項變更
        window.handleUploadTargetAllGroupsChange = function() {
            const checkbox = document.getElementById('upload_target_all_groups');
            const container = document.getElementById('uploadTargetGroupContainer');
            if (checkbox.checked) {
                container.style.display = 'none';
            } else {
                container.style.display = 'block';
            }
        };

        // 打開上傳模態框
        window.openUploadModal = function() {
            document.getElementById('uploadModal').style.display = 'block';
            document.getElementById('uploadPreviewContainer').style.display = 'none';
            document.getElementById('uploadPreview').innerHTML = '';
            document.getElementById('recognitionResult').style.display = 'none';
            document.getElementById('uploadProgress').style.display = 'none';
            document.getElementById('fileInput').value = '';
            recognitionResults = [];
            uploadedFile = null;
            
            // 重置目標對象設定
            document.getElementById('upload_target_all_groups').checked = true;
            handleUploadTargetAllGroupsChange();
            document.getElementById('upload_target_cohort_from').value = '';
            document.getElementById('upload_target_cohort_to').value = '';
            
            // 載入目標對象設定的選項
            loadUploadTargetOptions();
        };

        // 關閉上傳模態框
        window.closeUploadModal = function() {
            document.getElementById('uploadModal').style.display = 'none';
        };

        // 處理文件選擇
        window.handleFileSelect = function(event) {
            const file = event.target.files[0];
            if (!file) return;

            uploadedFile = file;
            const fileType = file.type;
            const fileName = file.name.toLowerCase();

            // 檢查文件類型
            if (!fileName.match(/\.(pdf|png|jpg|jpeg)$/i)) {
                Swal.fire('錯誤', '僅支援 PDF、PNG、JPG 格式', 'error');
                return;
            }

            // 顯示預覽容器（包含圖片和目標對象設定）
            const previewContainer = document.getElementById('uploadPreviewContainer');
            previewContainer.style.display = 'flex';
            const preview = document.getElementById('uploadPreview');
            preview.innerHTML = '';

            if (fileType === 'application/pdf') {
                // PDF 預覽
                preview.innerHTML = '<p><i class="fas fa-file-pdf"></i> ' + escapeHtml(file.name) + '</p>';
                // 可以嘗試使用 PDF.js 渲染第一頁
                loadPdfPreview(file);
            } else if (fileType.startsWith('image/')) {
                // 圖片預覽
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.style.maxWidth = '100%';
                    preview.innerHTML = '';
                    preview.appendChild(img);
                };
                reader.readAsDataURL(file);
            }

            // 開始識別
            startRecognition(file);
        };

        // 載入 PDF 預覽
        async function loadPdfPreview(file) {
            try {
                const arrayBuffer = await file.arrayBuffer();
                const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
                const page = await pdf.getPage(1);
                const viewport = page.getViewport({ scale: 1.5 });
                
                const canvas = document.createElement('canvas');
                const context = canvas.getContext('2d');
                canvas.height = viewport.height;
                canvas.width = viewport.width;
                
                await page.render({
                    canvasContext: context,
                    viewport: viewport
                }).promise;
                
                const preview = document.getElementById('uploadPreview');
                preview.innerHTML = '';
                preview.appendChild(canvas);
            } catch (error) {
                console.error('PDF 預覽錯誤:', error);
            }
        }

        // 開始識別
        async function startRecognition(file) {
            document.getElementById('uploadProgress').style.display = 'block';
            document.getElementById('recognitionResult').style.display = 'none';

            try {
                const formData = new FormData();
                formData.append('file', file);
                // 注意：do 參數已經在 URL 中，不需要在 FormData 中重複添加

                const response = await fetch(`${FORM_API_ROOT}?do=recognize_form_questions`, {
                    method: 'POST',
                    body: formData
                });

                // 檢查回應狀態
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('API 回應錯誤:', response.status, errorText);
                    let errorMsg = '伺服器錯誤';
                    try {
                        const errorData = JSON.parse(errorText);
                        errorMsg = errorData.msg || errorData.message || errorMsg;
                    } catch (e) {
                        errorMsg = errorText || `HTTP ${response.status}`;
                    }
                    throw new Error(errorMsg);
                }

                const data = await response.json();
                console.log('API 回應:', data);

                document.getElementById('uploadProgress').style.display = 'none';

                if (data.ok && data.questions && data.questions.length > 0) {
                    recognitionResults = data.questions;
                    displayRecognitionResults(data.questions);
                } else {
                    Swal.fire('提示', data.msg || '無法識別欄位，請手動新增', 'info');
                }
            } catch (error) {
                console.error('識別錯誤:', error);
                document.getElementById('uploadProgress').style.display = 'none';
                Swal.fire('錯誤', error.message || '識別過程中發生錯誤', 'error');
            }
        }

        // 顯示識別結果（可編輯）
        function displayRecognitionResults(questions) {
            const resultDiv = document.getElementById('recognitionResult');
            const itemsDiv = document.getElementById('recognitionItems');
            
            itemsDiv.innerHTML = '';
            
            questions.forEach((q, index) => {
                const item = createEditableQuestionItem(q, index);
                itemsDiv.appendChild(item);
            });

            resultDiv.style.display = 'block';
        }

        // 建立可編輯的題目項目
        function createEditableQuestionItem(q, index) {
            const item = document.createElement('div');
            item.className = 'recognition-item editable';
            item.dataset.index = index;
            
            const typeOptions = {
                'short_text': '短文字',
                'long_text': '長文字',
                'number': '數字',
                'date': '日期',
                'select': '下拉選單',
                'radio': '單選',
                'checkbox': '複選'
            };
            
            const optionsHtml = Array.isArray(q.options) && q.options.length > 0 
                ? q.options.map((opt, optIdx) => `
                    <div class="recognition-item-option">
                        <input type="text" class="recognition-option-input" value="${escapeHtml(opt)}" placeholder="選項 ${optIdx + 1}">
                        <button type="button" class="recognition-item-option-remove" onclick="removeRecognitionOption(this)">刪除</button>
                    </div>
                `).join('')
                : '';
            
            item.innerHTML = `
                <div class="recognition-item-header">
                    <div class="recognition-item-title">欄位 ${index + 1}</div>
                    <button type="button" class="recognition-item-delete-btn" onclick="removeRecognitionItem(this)">
                        <i class="fas fa-trash"></i> 刪除
                    </button>
                </div>
                <div class="recognition-item-body">
                    <div class="recognition-item-field">
                        <label>欄位文字 *</label>
                        <input type="text" class="recognition-title-input" value="${escapeHtml(q.title || q.text || '')}" placeholder="請輸入欄位文字" required>
                    </div>
                    <div class="recognition-item-field">
                        <label>欄位類型</label>
                        <select class="recognition-type-select" onchange="handleRecognitionTypeChange(this)">
                            ${Object.entries(typeOptions).map(([value, label]) => 
                                `<option value="${value}" ${q.type === value ? 'selected' : ''}>${label}</option>`
                            ).join('')}
                        </select>
                    </div>
                    <div class="recognition-item-field">
                        <label>
                            <input type="checkbox" class="recognition-required-checkbox" ${q.required !== false ? 'checked' : ''}>
                            必填
                        </label>
                    </div>
                    <div class="recognition-item-field recognition-options-field" style="display: ${['select', 'radio', 'checkbox'].includes(q.type) ? 'block' : 'none'};">
                        <label>選項（${q.type === 'checkbox' ? '複選' : '單選'}）</label>
                        <div class="recognition-item-options">
                            ${optionsHtml}
                        </div>
                        <button type="button" class="recognition-item-add-option" onclick="addRecognitionOption(this)">
                            <i class="fas fa-plus"></i> 新增選項
                        </button>
                    </div>
                </div>
            `;
            
            return item;
        }

        // 處理識別結果欄位類型變更
        window.handleRecognitionTypeChange = function(select) {
            const item = select.closest('.recognition-item');
            const optionsField = item.querySelector('.recognition-options-field');
            const type = select.value;
            
            if (['select', 'radio', 'checkbox'].includes(type)) {
                optionsField.style.display = 'block';
                // 如果沒有選項，新增一個
                const optionsContainer = item.querySelector('.recognition-item-options');
                if (optionsContainer.children.length === 0) {
                    addRecognitionOption(item.querySelector('.recognition-item-add-option'));
                }
            } else {
                optionsField.style.display = 'none';
            }
        };

        // 新增識別結果選項
        window.addRecognitionOption = function(btn) {
            const item = btn.closest('.recognition-item');
            const optionsContainer = item.querySelector('.recognition-item-options');
            const optionCount = optionsContainer.children.length;
            
            const optionDiv = document.createElement('div');
            optionDiv.className = 'recognition-item-option';
            optionDiv.innerHTML = `
                <input type="text" class="recognition-option-input" placeholder="選項 ${optionCount + 1}">
                <button type="button" class="recognition-item-option-remove" onclick="removeRecognitionOption(this)">刪除</button>
            `;
            optionsContainer.appendChild(optionDiv);
        };

        // 移除識別結果選項
        window.removeRecognitionOption = function(btn) {
            btn.closest('.recognition-item-option').remove();
        };

        // 移除識別結果項目
        window.removeRecognitionItem = function(btn) {
            btn.closest('.recognition-item').remove();
        };

        // 套用識別結果到表單（使用編輯後的結果）
        window.applyRecognitionResults = function() {
            const items = document.querySelectorAll('.recognition-item');
            
            if (items.length === 0) {
                Swal.fire('提示', '沒有可套用的題目', 'info');
                return;
            }

            // 收集目標對象設定
            const targetAllGroups = document.getElementById('upload_target_all_groups').checked;
            const targetGroups = [];
            if (!targetAllGroups) {
                const selectedGroups = document.getElementById('upload_target_group').selectedOptions;
                for (let i = 0; i < selectedGroups.length; i++) {
                    targetGroups.push(selectedGroups[i].value);
                }
            }
            const targetCohortFrom = document.getElementById('upload_target_cohort_from').value || null;
            const targetCohortTo = document.getElementById('upload_target_cohort_to').value || null;

            // 收集編輯後的題目資料
            const editedQuestions = [];
            items.forEach((item, index) => {
                const title = item.querySelector('.recognition-title-input').value.trim();
                if (!title) {
                    Swal.fire('錯誤', `欄位 ${index + 1} 的欄位文字不能為空`, 'error');
                    return;
                }

                const type = item.querySelector('.recognition-type-select').value;
                const required = item.querySelector('.recognition-required-checkbox').checked ? 1 : 0;
                
                // 收集選項
                let options = [];
                if (['select', 'radio', 'checkbox'].includes(type)) {
                    const optionInputs = item.querySelectorAll('.recognition-option-input');
                    optionInputs.forEach(input => {
                        const opt = input.value.trim();
                        if (opt) {
                            options.push(opt);
                        }
                    });
                }

                editedQuestions.push({
                    fq_ID: 0,
                    fq_title: title,
                    fq_type: type,
                    fq_required: required,
                    fq_placeholder: '',
                    fq_options: options,
                    fq_remark: ''
                });
            });

            if (editedQuestions.length === 0) {
                Swal.fire('提示', '沒有有效的欄位可套用', 'info');
                return;
            }

            // 清空現有題目（可選）
            const clearExisting = editedQuestions.length > 0;
            if (clearExisting) {
                document.getElementById('questionsList').innerHTML = '';
                adminForm.questionCounter = 0;
            }

            // 添加編輯後的題目
            editedQuestions.forEach(q => {
                addQuestion(q);
            });

            // 套用目標對象設定到主表單
            document.getElementById('target_all_groups').checked = targetAllGroups;
            handleTargetAllGroupsChange();
            
            if (!targetAllGroups && targetGroups.length > 0) {
                const mainGroupSelect = document.getElementById('target_group');
                Array.from(mainGroupSelect.options).forEach(option => {
                    option.selected = targetGroups.includes(option.value);
                });
            }
            
            if (targetCohortFrom) {
                document.getElementById('target_cohort_from').value = targetCohortFrom;
            }
            if (targetCohortTo) {
                document.getElementById('target_cohort_to').value = targetCohortTo;
            }

            closeUploadModal();
            Swal.fire('成功', `已自動新增 ${editedQuestions.length} 個欄位及目標對象設定`, 'success');
        };

        // 拖放功能
        const uploadArea = document.getElementById('uploadArea');
        if (uploadArea) {
            uploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadArea.classList.add('dragover');
            });

            uploadArea.addEventListener('dragleave', () => {
                uploadArea.classList.remove('dragover');
            });

            uploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadArea.classList.remove('dragover');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    document.getElementById('fileInput').files = files;
                    handleFileSelect({ target: { files: files } });
                }
            });
        }

        // 點擊模態框外部關閉
        window.onclick = function(event) {
            const uploadModal = document.getElementById('uploadModal');
            const fillTemplateModal = document.getElementById('fillTemplateModal');
            if (event.target == uploadModal) {
                closeUploadModal();
            }
            if (event.target == fillTemplateModal) {
                closeFillTemplateModal();
            }
        };

        // ==================== 填入標準範本功能 ====================
        let templateFile = null;
        let formFile = null;

        // 打開填入標準範本模態框
        window.openFillTemplateModal = function() {
            document.getElementById('fillTemplateModal').style.display = 'block';
            resetFillTemplateModal();
            // 如果選擇了使用學生資料方式，載入預覽
            setTimeout(() => {
                if (document.getElementById('fillMethodUser').checked) {
                    loadUserDataPreview();
                }
            }, 100);
        };

        // 關閉填入標準範本模態框
        window.closeFillTemplateModal = function() {
            document.getElementById('fillTemplateModal').style.display = 'none';
            resetFillTemplateModal();
        };

        // 重置填入標準範本模態框
        function resetFillTemplateModal() {
            templateFile = null;
            formFile = null;
            document.getElementById('templateFileInput').value = '';
            document.getElementById('formFileInput').value = '';
            document.getElementById('templateFileInfo').style.display = 'none';
            document.getElementById('formFileInfo').style.display = 'none';
            document.getElementById('fillTemplateProgress').style.display = 'none';
            document.getElementById('fillTemplateResult').style.display = 'none';
            document.getElementById('processFillTemplateBtn').disabled = true;
            // 重置填入方式
            document.getElementById('fillMethodOCR').checked = true;
            handleFillMethodChange();
        }

        // 處理填入方式變更
        window.handleFillMethodChange = function() {
            const fillMethod = document.querySelector('input[name="fillMethod"]:checked').value;
            const ocrSection = document.getElementById('ocrFormUploadSection');
            const userDataPreviewSection = document.getElementById('userDataPreviewSection');
            
            if (fillMethod === 'ocr') {
                ocrSection.style.display = 'block';
                userDataPreviewSection.style.display = 'none';
                formFile = null;
                document.getElementById('formFileInput').value = '';
                document.getElementById('formFileInfo').style.display = 'none';
            } else {
                ocrSection.style.display = 'none';
                userDataPreviewSection.style.display = 'block';
                formFile = null;
                document.getElementById('formFileInput').value = '';
                document.getElementById('formFileInfo').style.display = 'none';
                // 載入目前登入者（或實際填寫者）的資料預覽
                loadUserDataPreview();
            }
            updateProcessButtonState();
        };

        // 載入用戶資料預覽（使用當前登入者 / 實際填寫者的 session）
        window.loadUserDataPreview = async function() {
            const previewSection = document.getElementById('userDataPreviewSection');
            
            try {
                const response = await fetch(`${FORM_API_ROOT}?do=get_user_data_for_template`);
                const data = await response.json();
                
                if (data.ok && data.user_data) {
                    const userData = data.user_data;
                    document.getElementById('previewStudentId').textContent = userData.u_ID || '無';
                    document.getElementById('previewStudentName').textContent = userData.u_name || '無';
                    document.getElementById('previewClass').textContent = userData.class_name || '無';
                    document.getElementById('previewTeamName').textContent = userData.team_project_name || '無';
                    document.getElementById('previewAdvisor').textContent = userData.advisor_name || '無';
                    document.getElementById('previewTeamMembers').textContent = userData.team_members || '無團隊';
                    previewSection.style.display = 'block';
                } else {
                    // 如果獲取失敗，可能是管理員沒有指定學生
                    // 顯示提示訊息（但如果是學生使用，這個錯誤不應該出現）
                    const errorMsg = data.msg || '無法載入學生資料';
                    // 檢查是否為管理員未指定學生的錯誤
                    if (errorMsg.includes('請指定要填寫表單的學生')) {
                        previewSection.innerHTML = `
                            <div style="padding: 15px; background: #fff3cd; border-radius: 8px; border: 1px solid #ffc107;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #856404;">
                                    <i class="fas fa-exclamation-triangle me-2"></i>提示
                                </label>
                                <p style="font-size: 13px; color: #856404; margin: 0;">${errorMsg}</p>
                                <p style="font-size: 12px; color: #856404; margin-top: 8px; margin-bottom: 0;">
                                    <small>注意：此功能會自動使用「正在填寫表單的學生」的資料。如果是學生使用，會自動填入自己的資料；如果是管理員使用，請指定要填入的學生。</small>
                                </p>
                            </div>
                        `;
                    } else {
                        previewSection.innerHTML = `
                            <div style="padding: 15px; background: #f8d7da; border-radius: 8px; border: 1px solid #dc3545;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #721c24;">
                                    <i class="fas fa-exclamation-circle me-2"></i>錯誤
                                </label>
                                <p style="font-size: 13px; color: #721c24; margin: 0;">${errorMsg}</p>
                            </div>
                        `;
                    }
                    previewSection.style.display = 'block';
                }
            } catch (error) {
                console.error('載入用戶資料預覽錯誤:', error);
                previewSection.innerHTML = `
                    <div style="padding: 15px; background: #f8d7da; border-radius: 8px; border: 1px solid #dc3545;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #721c24;">
                            <i class="fas fa-exclamation-circle me-2"></i>錯誤
                        </label>
                        <p style="font-size: 13px; color: #721c24; margin: 0;">載入學生資料時發生錯誤，請稍後再試</p>
                    </div>
                `;
                previewSection.style.display = 'block';
            }
        };

        // 處理標準範本文件選擇
        window.handleTemplateFileSelect = function(event) {
            const file = event.target.files[0];
            if (!file) return;

            const allowedTypes = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword'];
            const allowedExts = ['pdf', 'docx', 'doc'];
            const fileExt = file.name.toLowerCase().split('.').pop();

            if (!allowedTypes.includes(file.type) && !allowedExts.includes(fileExt)) {
                Swal.fire('錯誤', '標準範本僅支援 PDF、DOCX、DOC 格式', 'error');
                return;
            }

            templateFile = file;
            document.getElementById('templateFileName').textContent = file.name;
            document.getElementById('templateFileInfo').style.display = 'block';
            updateProcessButtonState();
        };

        // 處理表單文件選擇
        window.handleFormFileSelect = function(event) {
            const file = event.target.files[0];
            if (!file) return;

            const allowedTypes = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg'];
            const allowedExts = ['pdf', 'png', 'jpg', 'jpeg'];
            const fileExt = file.name.toLowerCase().split('.').pop();

            if (!allowedTypes.includes(file.type) && !allowedExts.includes(fileExt)) {
                Swal.fire('錯誤', '表單文件僅支援 PDF、PNG、JPG 格式', 'error');
                return;
            }

            formFile = file;
            document.getElementById('formFileName').textContent = file.name;
            document.getElementById('formFileInfo').style.display = 'block';
            updateProcessButtonState();
        };

        // 更新處理按鈕狀態
        function updateProcessButtonState() {
            const btn = document.getElementById('processFillTemplateBtn');
            const fillMethod = document.querySelector('input[name="fillMethod"]:checked')?.value;
            
            if (fillMethod === 'ocr') {
                // OCR 方式需要範本和表單文件
                btn.disabled = !(templateFile && formFile);
            } else {
                // 使用學生資料方式只需要範本文件（實際填寫者由後端依照 session 判斷）
                btn.disabled = !templateFile;
            }
        }

        // 處理填入標準範本
        window.processFillTemplate = async function() {
            const fillMethod = document.querySelector('input[name="fillMethod"]:checked')?.value;
            
            if (!templateFile) {
                Swal.fire('錯誤', '請選擇標準範本文件', 'error');
                return;
            }
            
            if (fillMethod === 'ocr' && !formFile) {
                Swal.fire('錯誤', '請選擇要填寫的表單文件', 'error');
                return;
            }

            // 顯示進度
            document.getElementById('fillTemplateProgress').style.display = 'block';
            document.getElementById('fillTemplateResult').style.display = 'none';
            document.getElementById('processFillTemplateBtn').disabled = true;

            try {
                const formData = new FormData();
                formData.append('template_file', templateFile);
                
                let apiEndpoint = '';
                
                if (fillMethod === 'ocr') {
                    formData.append('form_file', formFile);
                    apiEndpoint = 'fill_template_with_recognized_data';
                } else {
                    // 使用學生資料（由後端依照當前登入者 / 實際填寫者的 session 決定）
                    apiEndpoint = 'fill_template_with_user_data';
                }

                const response = await fetch(`${FORM_API_ROOT}?do=${apiEndpoint}`, {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    let errorMsg = '處理失敗';
                    try {
                        const errorData = JSON.parse(errorText);
                        errorMsg = errorData.msg || errorMsg;
                    } catch (e) {
                        errorMsg = errorText || `HTTP ${response.status}`;
                    }
                    throw new Error(errorMsg);
                }

                const data = await response.json();

                if (data.ok) {
                    // 顯示識別結果或用戶資料
                    const displayData = data.recognized_data || data.user_data || {};
                    let displayHtml = '<div style="font-size: 13px;">';
                    for (const [key, value] of Object.entries(displayData)) {
                        if (value !== null && value !== '') {
                            const labelMap = {
                                'student_name': '學生姓名',
                                'student_id': '學號',
                                'dept': '系級',
                                'class': '班級',
                                'cohort': '屆別',
                                'group': '類組',
                                'team_name': '團隊名稱',
                                'project_title': '專題名稱',
                                'advisor': '指導老師',
                                'team_members': '團隊成員',
                                'submission_date': '提交日期'
                            };
                            const label = labelMap[key] || key;
                            displayHtml += `<div style="margin-bottom: 8px;"><strong>${label}:</strong> ${escapeHtml(String(value))}</div>`;
                        }
                    }
                    displayHtml += '</div>';
                    document.getElementById('recognizedDataDisplay').innerHTML = displayHtml;

                    // 設置下載連結
                    const downloadLink = document.getElementById('downloadFilledTemplate');
                    downloadLink.href = data.file_url;
                    downloadLink.download = data.file_name;

                    // 顯示結果
                    document.getElementById('fillTemplateProgress').style.display = 'none';
                    document.getElementById('fillTemplateResult').style.display = 'block';

                    Swal.fire('成功', '已成功識別並填入範本', 'success');
                } else {
                    throw new Error(data.msg || '處理失敗');
                }
            } catch (error) {
                console.error('填入範本錯誤:', error);
                document.getElementById('fillTemplateProgress').style.display = 'none';
                Swal.fire('錯誤', error.message || '處理過程中發生錯誤', 'error');
            } finally {
                document.getElementById('processFillTemplateBtn').disabled = false;
            }
        };

        // 初始化 - 確保在頁面載入完成後執行
        function initPage() {
            // 等待 DOM 完全載入
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(loadForms, 100);
                });
            } else {
                setTimeout(loadForms, 100);
            }
        }
        
        // 執行初始化
        initPage();
        adminForm.initialized = true;
        })(); // 結束 IIFE
    </script>

