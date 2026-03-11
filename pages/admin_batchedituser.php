<?php
session_start();
require '../includes/pdo.php';

// 檢查權限
$role_ID = $_SESSION['role_ID'] ?? null;
if (!in_array($role_ID, [1, 2])) {
    echo '<div class="alert alert-danger">您沒有權限訪問此頁面</div>';
    exit;
}

// 獲取選中的使用者ID
$u_IDs = $_GET['u_IDs'] ?? '';
if (!$u_IDs) {
    echo '<div class="alert alert-danger">缺少參數</div>';
    exit;
}

$userIds = explode(',', $u_IDs);
$placeholders = implode(',', array_fill(0, count($userIds), '?'));

// 獲取選中的使用者資料
$sql = "SELECT u.*, r.role_ID, r.role_name, s.status_ID, s.status_name, 
               c.c_ID as class_ID, c.c_name as class_name,
               e.cohort_ID, ch.cohort_name, e.enroll_grade
        FROM userdata u
        LEFT JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID AND ur.user_role_status = 1 
        LEFT JOIN roledata r ON ur.role_ID = r.role_ID
        LEFT JOIN statusdata s ON s.status_ID = u.u_status
        LEFT JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID AND e.enroll_status = 1
        LEFT JOIN classdata c ON c.c_ID = e.class_ID
        LEFT JOIN cohortdata ch ON ch.cohort_ID = e.cohort_ID
        WHERE u.u_ID IN ($placeholders)
        ORDER BY u.u_ID ASC";
$stmt = $conn->prepare($sql);
$stmt->execute($userIds);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($users)) {
    echo '<div class="alert alert-warning">找不到選中的使用者</div>';
    exit;
}

// 獲取選項列表
$roles    = $conn->query("SELECT * FROM roledata")->fetchAll(PDO::FETCH_ASSOC);
$statuses = $conn->query("SELECT * FROM statusdata")->fetchAll(PDO::FETCH_ASSOC);
$classes  = $conn->query("SELECT * FROM classdata")->fetchAll(PDO::FETCH_ASSOC);
$cohorts  = $conn->query("SELECT * FROM cohortdata ORDER BY cohort_ID DESC")->fetchAll(PDO::FETCH_ASSOC);

// 將使用者資料轉為JSON供JS使用
$usersJson = json_encode($users, JSON_UNESCAPED_UNICODE);
?>

<link rel="stylesheet" href="css/admin_edituser.css?v=<?= time() ?>">
<link rel="stylesheet" href="css/admin_usermanage.css?v=<?= time() ?>">

<div class="edit-user-container">
    <div class="page-header-edit">
        <h1 class="page-title-edit mb-0">
            <i class="fa-solid fa-users-gear"></i>批量編輯使用者 
            <span id="userCounter">(1 / <?= count($users) ?>)</span>
        </h1>
        <a href="#pages/admin_usermanage.php" class="btn btn-outline-secondary ajax-link">
            <i class="fa-solid fa-arrow-left me-2"></i>返回
        </a>
    </div>

    <div class="edit-card-wrapper">
        <!-- 左側切換按鈕 -->
        <button type="button" class="btn-nav btn-nav-prev" id="btnPrevUser" style="display: none;">
            <i class="fa-solid fa-chevron-left"></i>
        </button>

        <div class="edit-card">
            <form id="batchEditForm" enctype="multipart/form-data">
                <input type="hidden" name="u_IDs" value="<?= htmlspecialchars($u_IDs) ?>" id="hiddenUserIds">
                
                <!-- 當前編輯的使用者表單 -->
                <div id="userFormContainer">
                    <!-- 這裡會由JS動態載入使用者表單 -->
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-action-edit btn-cancel-edit" id="btnCancel">
                        <i class="fa-solid fa-times me-2"></i>取消
                    </button>
                    <button type="button" class="btn btn-action-edit btn-save-edit" id="btnSaveAll">
                        <i class="fa-solid fa-check me-2"></i>完成編輯所有使用者
                    </button>
                </div>
            </form>
        </div>

        <!-- 右側切換按鈕 -->
        <button type="button" class="btn-nav btn-nav-next" id="btnNextUser" style="display: none;">
            <i class="fa-solid fa-chevron-right"></i>
        </button>
    </div>
</div>

<script>
// 將使用者資料傳遞給JS
window.batchEditUsers = <?= $usersJson ?>;
window.batchEditOptions = {
    roles: <?= json_encode($roles, JSON_UNESCAPED_UNICODE) ?>,
    statuses: <?= json_encode($statuses, JSON_UNESCAPED_UNICODE) ?>,
    classes: <?= json_encode($classes, JSON_UNESCAPED_UNICODE) ?>,
    cohorts: <?= json_encode($cohorts, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="js/admin_batchedituser.js?v=<?= time() ?>"></script>

