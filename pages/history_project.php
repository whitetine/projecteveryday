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
<!-- CSS 預載入，防止跑版 -->
<link rel="stylesheet" href="../css/history_project.css?v=<?= time() ?>" id="historyProjectCSS" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="../css/history_project.css?v=<?= time() ?>"></noscript>

<div class="history-project-container">
    <div class="history-project-header">
        <h1 class="history-project-title">歷屆專題繳交設定</h1>
    </div>

    <!-- 時段設定表單 -->
    <div class="card mb-4">
        <div class="card-body">
            <form id="periodForm" method="post" class="row g-3">
                <input type="hidden" name="action" id="form_action" value="create">
                <input type="hidden" name="pro_ID" id="pro_ID">

                <div class="col-md-3">
                    <label class="form-label">開始日</label>
                    <input type="datetime-local" class="form-control" name="pro_start_d" id="pro_start_d" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">結束日</label>
                    <input type="datetime-local" class="form-control" name="pro_end_d" id="pro_end_d" required min="">
                    <div class="invalid-feedback" style="display: none;"></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">標題</label>
                    <input type="text" class="form-control" name="pro_title" id="pro_title" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">屆別</label>
                    <select class="form-select multi-select-list" id="periodCohortSelect" multiple size="6" aria-label="屆別多選">
                        <option value="">載入中...</option>
                    </select>
                    <small class="text-muted d-block mt-1">可多選，按住 Ctrl/Cmd 鍵；優先使用第一個屆別建立時段。</small>
                    <input type="hidden" name="cohort_values" id="cohort_values" value="">
                    <input type="hidden" name="cohort_primary" id="cohort_primary" value="">
                </div>

                <input type="hidden" name="pro_status" id="pro_status" value="1">

                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit" id="submitBtn">新增</button>
                    <button class="btn btn-secondary" type="button" onclick="resetPeriodForm()">清空</button>
                    <button class="btn btn-outline-secondary d-none" type="button" id="cancelEditBtn" onclick="resetPeriodForm()">取消編輯</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 時段管理表格 -->
    <div class="card mb-4">
        <!-- <div class="card-header bg-info text-white d-flex justify-content-between align-items-center py-2">
            <strong><i class="fa-solid fa-clock-rotate-left me-2"></i>時段管理</strong>
        </div> -->
        <div class="card-body">
            <!-- 篩選區域 -->
            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label small">篩選屆別</label>
                    <select class="form-select form-select-sm" id="periodFilterCohort">
                        <option value="">全部</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">篩選狀態</label>
                    <select class="form-select form-select-sm" id="periodFilterStatus">
                        <option value="">全部</option>
                        <option value="1">啟用</option>
                        <option value="0">停用</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button class="btn btn-sm btn-primary" type="button" id="periodFilterBtn">
                        <i class="fa-solid fa-filter me-1"></i>篩選
                    </button>
                    <button class="btn btn-sm btn-secondary ms-2" type="button" id="periodFilterClearBtn">
                        <i class="fa-solid fa-times me-1"></i>清除
                    </button>
                </div>
            </div>
            
            <!-- 時段表格 -->
            <div class="table-responsive" style="overflow-x: auto; max-width: 100%;">
                <table class="table table-hover table-sm" style="width: 100%; table-layout: auto; margin: 0;">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width: 150px; max-width: 200px;">標題</th>
                            <th style="min-width: 100px; max-width: 120px;">屆別</th>
                            <th style="min-width: 160px; max-width: 180px;">開始時間</th>
                            <th style="min-width: 160px; max-width: 180px;">結束時間</th>
                            <th style="min-width: 80px; max-width: 100px;">狀態</th>
                            <th style="min-width: 130px; max-width: 150px;">建立時間</th>
                            <th style="min-width: 100px; max-width: 150px;">建立者</th>
                            <th style="min-width: 120px; max-width: 150px;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="periodsTableBody">
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <i class="fa-solid fa-spinner fa-spin"></i>
                                <span class="ms-2">載入中...</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    // 設置配置
    window.HISTORY_PROJECT_CONFIG = {
        u_ID: '<?= htmlspecialchars($u_ID ?? '', ENT_QUOTES) ?>',
        role_ID: <?= $role_ID ?>
    };
</script>
<script>
// 動態設置 CSS 和 JS 路徑（解決 AJAX 載入時的相對路徑問題）
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
    const cssLink = document.getElementById('historyProjectCSS');
    if (cssLink) {
        cssLink.href = basePath + (basePath.endsWith('/') ? '' : '/') + 'css/history_project.css?v=<?= time() ?>';
        cssLink.media = 'all';
    }
    
    // 動態載入 JS
    const script1 = document.createElement('script');
    script1.src = basePath + (basePath.endsWith('/') ? '' : '/') + 'js/history_api.js?v=<?= time() ?>';
    script1.onload = function() {
        // 載入 history_project.js
        const script2 = document.createElement('script');
        script2.src = basePath + (basePath.endsWith('/') ? '' : '/') + 'js/history_project.js?v=<?= time() ?>';
        script2.onload = function() {
            // 初始化頁面
            function initPage() {
                const content = document.querySelector('.history-project-container');
                if (content) {
                    content.style.visibility = 'visible';
                }
                
                // 初始化功能
                if (typeof window.initHistoryProject === 'function') {
                    window.initHistoryProject();
                } else {
                    setTimeout(initPage, 50);
                }
            }
            
            if (document.readyState === 'loading') {
                window.addEventListener('load', initPage);
            } else {
                initPage();
            }
        };
        document.head.appendChild(script2);
    };
    document.head.appendChild(script1);
})();
</script>

