<?php
session_start();
require '../includes/pdo.php';

// 檢查權限（主任 role_ID = 1 和 科辦 role_ID = 2）
$role_ID = $_SESSION['role_ID'] ?? null;
if (!isset($role_ID) || !in_array($role_ID, [1, 2])) {
  echo '<div class="alert alert-danger">您沒有權限訪問此頁面</div>';
  exit;
}

$u_ID = $_SESSION['u_ID'] ?? null;
?>
<!-- CSS 預載入 -->
<link rel="stylesheet" href="../css/history_project.css?v=<?= time() ?>" id="historyProjectFileCSS" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="../css/history_project.css?v=<?= time() ?>"></noscript>

<div class="history-project-container">
    <div class="history-project-header">
        <h1 class="history-project-title">歷屆成果管理</h1>
    </div>

    <!-- 搜尋與篩選區域 -->
    <div class="search-filter-section">
        <div class="search-filter-header">
            <i class="fa-solid fa-chevron-down"></i> 搜尋與篩選
        </div>
        <div class="search-filter-content">
            <div class="search-group">
                <label>搜尋專題名稱</label>
                <div class="search-input-group">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="searchInput" placeholder="搜尋專題名稱 輸入標題或簡介" class="search-input">
                </div>
            </div>
            <div class="search-group">
                <label>學年度</label>
                <select id="cohortSelect" class="filter-select">
                    <option value="">全部</option>
                </select>
            </div>
            <div class="search-group">
                <label>類組篩選</label>
                <select id="groupSelect" class="filter-select">
                    <option value="">全部</option>
                </select>
            </div>
            <div class="search-group">
                <label>狀態</label>
                <select id="statusSelect" class="filter-select">
                    <option value="">全部</option>
                    <option value="1">啟用</option>
                    <option value="0">停用</option>
                </select>
            </div>
            <div class="search-actions">
                <button class="btn-search" id="searchBtn">Q 搜尋</button>
                <button class="btn-clear" id="clearBtn">× 清除</button>
            </div>
        </div>
    </div>

    <!-- 整屆下載設定：科辦一併開放/不開放某屆的檔案類型（成果書、PPT、Word） -->
    <div class="cohort-download-section search-filter-section" style="margin-top: 16px;">
        <div class="search-filter-header">
            <i class="fa-solid fa-folder-open"></i> 整屆下載設定
            <span class="text-muted small ms-2">選擇學年度後，可一併開放或一併不開放該屆的「成果書 / PPT / Word」讓學生在歷屆專題瀏覽時下載</span>
        </div>
        <div class="search-filter-content">
            <div class="search-group">
                <label>學年度</label>
                <select id="cohortDownloadSelect" class="filter-select">
                    <option value="">請選擇學年度</option>
                </select>
            </div>
            <div id="cohortDownloadFileTypes" class="mt-3" style="display: none;">
                <div class="mb-2 fw-bold"><i class="fa-solid fa-file-lines"></i> 檔案類型</div>
                <div id="cohortDownloadFileTypesList" class="d-flex flex-column gap-2"></div>
            </div>
        </div>
    </div>

    <!-- 專題列表區域 -->
    <div class="project-list-section">
        <div class="project-list-header">
            <div class="project-list-title">
                <i class="fa-solid fa-list"></i> 歷屆專題
                <span class="project-count">共0筆</span>
            </div>
            <div class="project-list-actions">
                <button class="btn btn-success" id="batchPublishSelectedBtn" style="display: none;">
                    <i class="fa-solid fa-upload"></i> 上架
                </button>
                <button class="btn btn-warning" id="batchUnpublishSelectedBtn" style="display: none;">
                    <i class="fa-solid fa-arrow-down"></i> 下架
                </button>
                <button class="btn btn-success" id="batchPublishBtn" style="display: none;">
                    <i class="fa-solid fa-upload"></i> 一併上架
                </button>
            </div>
        </div>
        <table class="project-table">
            <thead>
                <tr>
                    <th style="width: 50px;">
                        <input type="checkbox" id="selectAllCheckbox" title="全選">
                    </th>
                    <th style="width: 80px;">海報</th>
                    <th>專題名稱</th>
                    <th>組員</th>
                    <th>簡介</th>
                    <th>狀態</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody id="projectTableBody">
                <!-- 初始不顯示任何內容，等待 JavaScript 載入資料 -->
            </tbody>
        </table>
    </div>

</div>

<style>
.history-project-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.history-project-title {
    font-size: 40px !important;
    font-weight: bold !important;
}

.submission-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.submission-list:has(.empty-state) {
    display: block;
}

.submission-card {
    background: white;
    border-radius: 12px;
    padding: 18px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border: 1px solid #e0e0e0;
    transition: all 0.3s ease;
    max-width: 100%;
}

.submission-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}

.submission-card-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f0;
}

.submission-card-title {
    font-size: 18px;
    font-weight: 600;
    color: #333;
    margin: 0;
    flex: 1;
}

.submission-card-meta {
    font-size: 12px;
    color: #666;
    margin-top: 5px;
}

.file-summary-section {
    margin-top: 15px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
}

.file-summary-item {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
    font-size: 14px;
}

.file-summary-item:last-child {
    margin-bottom: 0;
}

.file-summary-item i {
    color: #667eea;
    width: 20px;
}

.summary-label {
    color: #666;
    font-weight: 500;
}

.summary-value {
    font-weight: 600;
    color: #333;
}

.file-summary-actions {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #dee2e6;
    text-align: center;
}

.view-files-btn {
    min-width: 120px;
}

.file-list {
    margin-top: 15px;
}

.file-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 10px;
    transition: background 0.2s;
    position: relative;
}

.file-item:hover {
    background: #e9ecef;
}

.file-info {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 10px;
}

.file-icon {
    font-size: 20px;
    color: #667eea;
}

.file-details {
    flex: 1;
}

.file-name {
    font-weight: 500;
    color: #333;
    margin-bottom: 4px;
    word-break: break-all;
}

.file-meta {
    font-size: 12px;
    color: #666;
}

.download-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}

.download-toggle .toggle-label {
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
    pointer-events: auto;
}

.toggle-switch {
    position: relative;
    width: 50px;
    height: 26px;
    background: #dc3545; /* 不開放 = 紅色 */
    border-radius: 13px;
    cursor: pointer;
    transition: background 0.3s;
    user-select: none;
    -webkit-user-select: none;
    flex-shrink: 0;
    pointer-events: auto;
    z-index: 1;
}

.toggle-switch::after {
    pointer-events: none;
}

.toggle-switch.active {
    background: #28a745; /* 開放 = 綠色 */
}

.toggle-switch::after {
    content: '';
    position: absolute;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: white;
    top: 3px;
    left: 3px;
    transition: left 0.3s;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.toggle-switch.active::after {
    left: 27px;
}

.toggle-label {
    font-size: 12px;
    color: #666;
    white-space: nowrap;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #999;
}

.empty-state i {
    font-size: 64px;
    color: #ddd;
    margin-bottom: 20px;
    display: block;
}

.empty-state p {
    font-size: 16px;
    margin: 0;
}

/* 模態框樣式 */
.modal {
    display: none;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.modal.show {
    display: flex;
    opacity: 1;
}

.modal-backdrop {
    opacity: 0;
    transition: opacity 0.3s ease;
}

.modal-backdrop.show {
    opacity: 1;
}

.modal-dialog {
    position: relative;
    width: 90%;
    max-width: 700px;
    margin: auto;
    z-index: 1055;
}

/* 專題詳情模態框：限制在視窗內，避免裁切 */
.history-detail-modal {
    padding: 20px;
    box-sizing: border-box;
    overflow-x: auto;
    overflow-y: auto;
}
.history-detail-dialog {
    max-width: 800px !important;
    width: 100% !important;
    margin: auto !important;
    flex-shrink: 0;
}
@media (max-width: 840px) {
    .history-detail-dialog {
        max-width: calc(100vw - 40px) !important;
    }
}
.history-detail-modal .modal-content {
    max-width: 100%;
}
/* 專題詳情視窗色彩 */
.history-detail-content {
    box-shadow: 0 4px 24px rgba(102, 126, 234, 0.2) !important;
    border-top: 3px solid #667eea !important;
}
.history-detail-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
    color: #fff !important;
}
.history-detail-header .modal-title { color: #fff !important; }
.history-detail-modal .modal-body { background: #fafbff !important; }
.history-detail-footer {
    background: #f8f9ff !important;
    border-top-color: rgba(102, 126, 234, 0.2) !important;
}
.history-detail-modal .btn-close-detail {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.35) !important;
}
.history-detail-modal .btn-close-detail:hover {
    filter: brightness(1.08);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.45) !important;
}

.modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    overflow: hidden;
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8f9fa;
}

.modal-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #333;
}

.modal-title i {
    margin-right: 8px;
    color: #667eea;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.btn-close {
    background: none;
    border: none;
    font-size: 24px;
    color: #666;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: background 0.2s;
}

.btn-close:hover {
    background: #e9ecef;
}

.text-success {
    color: #28a745 !important;
}

.text-warning {
    color: #ffc107 !important;
}

/* 狀態列樣式 */
.status-published {
    background-color: #28a745 !important;
    color: white !important;
}

.status-unpublished {
    background-color: #6c757d !important;
    color: #fff !important;
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
</style>

<script>
// 設置配置
window.HISTORY_PROJECT_FILE_CONFIG = {
    u_ID: '<?= htmlspecialchars($u_ID ?? '', ENT_QUOTES) ?>',
    role_ID: <?= $role_ID ?>
};

// 動態設置 CSS 和 JS 路徑
(function() {
    const getBasePath = function() {
        const path = window.location.pathname;
        if (path.includes('/main.php')) {
            return path.substring(0, path.indexOf('/main.php') + 1);
        }
        if (path.includes('/pages/')) {
            return path.substring(0, path.indexOf('/pages/') + 1);
        }
        return '../';
    };
    
    const basePath = getBasePath();
    
    // 更新 CSS 路徑
    const cssLink = document.getElementById('historyProjectFileCSS');
    if (cssLink) {
        cssLink.href = basePath + (basePath.endsWith('/') ? '' : '/') + 'css/history_project.css?v=<?= time() ?>';
        cssLink.media = 'all';
    }
    
    // 動態載入 JS（添加時間戳避免緩存）
    const script = document.createElement('script');
    script.src = basePath + (basePath.endsWith('/') ? '' : '/') + 'js/history_project_file.js?v=' + Date.now();
    document.head.appendChild(script);
})();
</script>

