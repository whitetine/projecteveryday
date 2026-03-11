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

// 獲取學年度列表
$cohorts = [];
try {
    $stmt = $conn->query("SELECT cohort_ID, cohort_name FROM cohortdata WHERE cohort_status = 1 ORDER BY cohort_ID DESC");
    $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $cohorts = [];
}

// 獲取類組列表
$groups = [];
try {
    $stmt = $conn->query("SELECT group_ID, group_name FROM groupdata WHERE group_status = 1 ORDER BY group_ID");
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $groups = [];
}
?>
<!-- CSS 預載入 -->
<link rel="stylesheet" href="./css/project_submission_review.css?v=<?= time() ?>">

<div class="project-submission-review-container">
    <div class="review-header">
        <h1 class="review-title">
            <i class="fa-solid fa-clipboard-check"></i> 專題提交審核
        </h1>
    </div>

    <!-- 搜尋與篩選區域 -->
    <div class="search-filter-section">
        <div class="search-filter-header">
            <i class="fa-solid fa-chevron-down"></i> 搜尋與篩選
        </div>
        <div class="search-filter-content">
            <div class="search-group">
                <label>搜尋團隊名稱</label>
                <div class="search-input-group">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="searchInput" placeholder="輸入團隊名稱或專題名稱..." class="search-input">
                </div>
            </div>
            <div class="search-group">
                <label>學年度</label>
                <select id="cohortSelect" class="filter-select">
                    <option value="">全部</option>
                    <?php foreach ($cohorts as $cohort): ?>
                        <option value="<?= $cohort['cohort_ID'] ?>"><?= htmlspecialchars($cohort['cohort_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="search-group">
                <label>類組篩選</label>
                <select id="groupSelect" class="filter-select">
                    <option value="">全部</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= $group['group_ID'] ?>"><?= htmlspecialchars($group['group_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="search-group">
                <label>狀態</label>
                <select id="statusSelect" class="filter-select">
                    <option value="">全部</option>
                    <option value="3">通過</option>
                    <option value="1">未審核</option>
                    <option value="0">退件</option>
                </select>
            </div>
            <div class="search-actions">
                <button class="btn-search" id="searchBtn">
                    <i class="fa-solid fa-magnifying-glass"></i> 搜尋
                </button>
                <button class="btn-clear" id="clearBtn">
                    <i class="fa-solid fa-times"></i> 清除
                </button>
            </div>
        </div>
    </div>

    <!-- 專題列表區域 -->
    <div class="project-list-section">
        <div class="project-list-header">
            <div class="project-list-title">
                <span id="submissionCount">共0筆</span>
            </div>
        </div>
        <div id="projectsContainer" class="projects-container">
            <!-- 專題列表將在這裡動態載入 -->
            <div class="empty-state">
                <i class="fa-solid fa-folder-open"></i>
                <p>目前沒有專題資料</p>
            </div>
        </div>
    </div>
</div>

<script>
    // 設置配置（含 API 路徑，確保在 main.php 下篩選時能正確請求）
    window.PROJECT_SUBMISSION_REVIEW_CONFIG = {
        u_ID: '<?= htmlspecialchars($u_ID ?? '', ENT_QUOTES) ?>',
        role_ID: <?= $role_ID ?>,
        apiBase: '<?= htmlspecialchars(rtrim(dirname($_SERVER["SCRIPT_NAME"] ?? ''), "/") . "/pages/project_submission_review_api.php", ENT_QUOTES) ?>'
    };
</script>
<script src="./js/project_submission_review.js?v=<?= time() ?>"></script>

