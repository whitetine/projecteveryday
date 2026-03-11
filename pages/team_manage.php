<!-- pages/team_manage.php -->
<?php
session_start();
require '../includes/pdo.php';

// 檢查權限（主任 role_ID = 1 和 科辦 role_ID = 2）
$role_ID = $_SESSION['role_ID'] ?? null;
if (!isset($role_ID) || !in_array($role_ID, [1, 2])) {
  echo '<div class="alert alert-danger">您沒有權限訪問此頁面</div>';
  exit;
}

// 檢查欄位名稱（兼容不同版本的資料表結構）
function columnExists(PDO $conn, string $table, string $column): bool {
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$teamUserField = columnExists($conn, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
$userRoleUidField = columnExists($conn, 'userrolesdata', 'ur_u_ID') ? 'ur_u_ID' : 'u_ID';

// 取得當前屆別（優先啟用 status=1，若無則取已結案 status=3）
$stmt = $conn->prepare("
    SELECT cohort_ID, cohort_name 
    FROM cohortdata 
    WHERE cohort_status IN (1, 3) 
    ORDER BY cohort_status ASC, cohort_ID DESC 
    LIMIT 1
");
$stmt->execute();
$currentCohort = $stmt->fetch(PDO::FETCH_ASSOC);
$cohort_ID = $currentCohort['cohort_ID'] ?? null;
$cohort_name = $currentCohort['cohort_name'] ?? '';

if (!$cohort_ID) {
    echo '<div class="alert alert-warning">目前沒有啟用的屆別</div>';
    exit;
}
?>
<!-- CSS 預載入，防止跑版 -->
<link rel="stylesheet" href="css/team_manage.css?v=<?= time() ?>" media="print" onload="this.media='all'">
<!-- <noscript><link rel="stylesheet" href="css/team_manage.css?v=<?= time() ?>"></noscript> -->

<div class="team-manage-container" style="visibility: hidden;" id="teamManageContent">
    <div class="page-header">
        <div class="page-header-row">
            <div class="page-header-left">
                <h1 class="page-title">組別管理</h1>
                <!-- <p class="page-subtitle" id="pageSubtitle"></p> -->
            </div>
            <div class="team-filter-toolbar">
                <div class="toolbar-search">
                    <i class="fa-solid fa-magnifying-glass toolbar-search-icon"></i>
                    <input type="text" id="searchStudent" class="toolbar-search-input"
                           placeholder="搜尋姓名或學號..." autocomplete="off">
                    <button type="button" id="clearSearch" class="toolbar-search-clear" style="display:none;">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
                <select id="filterCohort" class="toolbar-select" title="屆別">
                    <option value="">全部屆別</option>
                </select>
                <select id="filterGroup" class="toolbar-select" title="類組">
                    <option value="">全部類組</option>
                </select>
                <button type="button" id="filterReset" class="toolbar-btn-reset">重設</button>
            </div>
        </div>
    </div>

    <div class="team-groups-container" id="teamGroupsContainer">
        <div class="loading-indicator">
            <i class="fa-solid fa-spinner fa-spin"></i> 載入中...
        </div>
    </div>


<!-- 團隊詳情 Modal -->
<div class="team-modal-overlay" id="teamModalOverlay">
    <div class="team-modal">
        <div class="team-modal-header">
            <h3 class="team-modal-title" id="teamModalTitle">組別詳情</h3>
            <button class="team-modal-close" id="teamModalClose">×</button>
        </div>
        <div class="team-modal-body" id="teamModalBody">
            <!-- 動態載入內容 -->
        </div>
    </div>
</div>

<script>
    // 先設置配置（在載入 JS 之前）
    window.TEAM_MANAGE_CONFIG = {
        cohort_ID: <?= $cohort_ID ?>,
        cohort_name: '<?= htmlspecialchars($cohort_name, ENT_QUOTES) ?>',
        teamUserField: '<?= $teamUserField ?>',
        userRoleUidField: '<?= $userRoleUidField ?>'
    };
</script>
<script src="js/team_manage.js?v=<?= time() ?>"></script>
<script>
    // 確保 CSS 載入後顯示內容並初始化頁面
    (function() {
        function initPage() {
            const content = document.getElementById('teamManageContent');
            if (content) {
                content.style.visibility = 'visible';
            }
            
            // 初始化團隊管理頁面
            if (typeof window.initTeamManagePage === 'function') {
                window.initTeamManagePage();
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

