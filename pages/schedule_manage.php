<?php
session_start();
require '../includes/pdo.php';

// 檢查權限（主任 role_ID = 1 和 科辦 role_ID = 2）
$role_ID = $_SESSION['role_ID'] ?? null;
if (!isset($role_ID) || !in_array($role_ID, [1, 2])) {
  echo '<div class="alert alert-danger">您沒有權限訪問此頁面</div>';
  exit;
}

// 取得當前屆別（最新啟用的屆別）
$stmt = $conn->prepare("
    SELECT cohort_ID, cohort_name 
    FROM cohortdata 
    WHERE cohort_status = 1 
    ORDER BY cohort_ID DESC 
    LIMIT 1
");
$stmt->execute();
$currentCohort = $stmt->fetch(PDO::FETCH_ASSOC);
$cohort_ID = $currentCohort['cohort_ID'] ?? null;
$cohort_name = $currentCohort['cohort_name'] ?? '';
?>
<!-- CSS 預載入，防止跑版 -->
<link rel="stylesheet" href="../css/schedule_manage.css?v=<?= time() ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="../css/schedule_manage.css?v=<?= time() ?>"></noscript>
<!-- Sortable.js 拖放功能 -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<div class="schedule-manage-container" style="visibility: hidden;" id="scheduleManageContent">
    <!-- 標題區域 -->
    <div class="schedule-header">
        <h1 class="schedule-title">
            <i class="fa-solid fa-calendar-alt me-2"></i>時程表管理
        </h1>
        <p class="schedule-subtitle">管理專題報告時程表，設定團隊報告時間與順序</p>
    </div>

    <!-- 篩選區塊 -->
    <div class="schedule-filter-box">
        <div class="schedule-filter-box-row1">
            <!-- 屆別選擇 -->
            <div class="schedule-cohort-box">
                <label for="cohortSelect">
                    <i class="fa-solid fa-graduation-cap me-1"></i>屆別
                </label>
                <select id="cohortSelect" class="form-select">
                    <option value="">請選擇屆別</option>
                    <!-- JS 會動態填入 -->
                </select>
            </div>

            <!-- 標題輸入 -->
            <div class="schedule-title-box">
                <label for="scheduleTitle">
                    </i>時程表標題
                </label>
                <input type="text" id="scheduleTitle" class="form-control" placeholder="輸入時程表標題" disabled>
            </div>

            <!-- 開始時間 -->
            <div class="schedule-time-box">
                <label for="startTime">
                    <i class="fa-solid fa-clock me-1"></i>開始時間
                </label>
                <input type="datetime-local" id="startTime" class="form-control" disabled>
            </div>

            <!-- 結束時間（隱藏） -->
            <div class="schedule-time-box" id="endTimeContainer" style="display: none;">
                <label for="endTime">
                    <i class="fa-solid fa-clock me-1"></i>結束時間
                </label>
                <input type="datetime-local" id="endTime" class="form-control" disabled>
            </div>
        </div>

        <div class="schedule-filter-box-row2">
            <!-- 按鈕組 -->
            <div class="schedule-export-box">
                <!-- 返回上一頁放在最前面，與建議表一致 -->
                <button type="button" id="schedule-back-home-btn" class="schedule-btn-back-home" style="display: none;">
                    <i class="fa-solid fa-home me-1"></i>返回上一頁
                </button>
                <!-- 編輯 / 儲存 使用同一顆按鈕 -->
                <button type="button" id="schedule-edit-all-btn" class="schedule-btn-edit-all" disabled>
                    <i class="fa-solid fa-edit me-1"></i>編輯
                </button>
                <!-- 時程表內「加入團隊」 -->
                <button type="button" id="schedule-add-team-btn" class="schedule-btn-new-schedule" disabled style="display: none;">
                    <i class="fa-solid fa-user-plus me-1"></i>加入團隊
                </button>
                <!-- 實際的儲存邏輯由 JS 觸發，按鈕保持隱藏 -->
                <button type="button" id="schedule-save-btn" class="schedule-btn-save" disabled style="display: none;">
                    <i class="fa-solid fa-save me-1"></i>儲存
                </button>
                <!-- 新增時程表放在後面，避免誤觸 -->
                <button type="button" id="schedule-new-schedule-btn" class="schedule-btn-new-schedule">
                    <i class="fa-solid fa-plus me-1"></i>新增時程表
                </button>
                <button type="button" id="exportPDFBtn" class="schedule-btn-export" disabled style="display: none;">
                    <i class="fa-solid fa-download me-1"></i>匯出 PDF
                </button>
                <button type="button" id="exportWordBtn" class="schedule-btn-export" disabled style="display: none;">
                    <i class="fa-solid fa-file-word me-1"></i>匯出 Word
                </button>
            </div>
        </div>

        <!-- 線上評分狀態（有載入時程表時顯示） -->
        <div id="online-scoring-box" class="schedule-online-scoring-row" style="display: none;">
            <div class="schedule-online-scoring-inner">
                <span class="schedule-online-scoring-label">線上評分狀態：</span>
                <span id="onlineScoringStatusText" class="schedule-online-scoring-status">開放中</span>
                <button type="button" id="toggleOnlineScoringBtn" class="schedule-btn-toggle-scoring">
                    關閉線上評分
                </button>
                <div class="schedule-online-scoring-notes">
                    <span>※ 開放後，評審可於審查報告時進行線上評分；關閉後，評審將無法填寫或修改評分。</span>
                    
                </div>
            </div>
        </div>
    </div>
    <div id="special-time-options" class="special-time-options-container" style="display: none;">
        <div class="special-time-options-header">
            <h6>特殊時間設定</h6>
        </div>
        <div class="special-time-buttons">
            <button type="button" class="btn btn-special-time" data-type="report_duration">
                <i class="fa-solid fa-hourglass-half me-1"></i>報告時間
            </button>
            <button type="button" class="btn btn-special-time" data-type="lunch">
                <i class="fa-solid fa-utensils me-1"></i>午餐時間
            </button>
            <button type="button" class="btn btn-special-time" data-type="break">
                <i class="fa-solid fa-coffee me-1"></i>休息時間
            </button>
            <button type="button" class="btn btn-special-time" data-type="preparation">
                <i class="fa-solid fa-clock me-1"></i>場次準備
            </button>
            <button type="button" class="btn btn-special-time" data-type="presentation_instruction">
                <i class="fa-solid fa-microphone me-1"></i>上台報告說明
            </button>
        </div>
    </div>

    <!-- 檔案列表容器 -->
    <div id="schedule-file-list" class="schedule-file-list-container" style="display: none;">
        <!-- JS 會動態填入檔案卡片 -->
    </div>

    <!-- 表格卡片 -->
    <div class="card" id="schedule-table-card" style="display: none;">
        <div class="card-header">
            <div class="page-header">
                <h2 class="page-title" id="scheduleTitleDisplay">時程表</h2>
            </div>
        </div>
        <div class="card-body">
            <div id="scheduleTableContainer">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 table-clean" id="scheduleTable">
                        <thead>
                            <tr>
                                <th>報告時間</th>
                                <th style="width: 60px;">組次</th>
                                <th>學號</th>
                                <th>姓名</th>
                                <th>專題題目</th>
                                <th>指導老師</th>
                                <th class="header-cell-delete" style="width: 60px; display: none;">操作</th>
                            </tr>
                        </thead>
                        <tbody id="scheduleTableBody">
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">請選擇屆別以載入團隊資料</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // 先設置配置（在載入 JS 之前）
    window.SCHEDULE_MANAGE_CONFIG = {
        cohort_ID: <?= $cohort_ID ? $cohort_ID : 'null' ?>,
        cohort_name: '<?= htmlspecialchars($cohort_name, ENT_QUOTES) ?>'
    };
</script>
<script src="../js/schedule_manage.js?v=<?= time() ?>"></script>
<script>
    // 確保 CSS 載入後顯示內容並初始化頁面
    (function() {
        function initPage() {
            const content = document.getElementById('scheduleManageContent');
            if (content) {
                content.style.visibility = 'visible';
            }
            
            // 初始化時程表管理頁面
            if (typeof window.initScheduleManage === 'function') {
                window.initScheduleManage();
            } else {
                // 如果函數還沒載入，等待一下
                setTimeout(initPage, 50);
            }
        }
        
        if (document.readyState === 'loading') {
            window.addEventListener('load', initPage);
        } else {
            initPage();
        }
    })();
</script>

