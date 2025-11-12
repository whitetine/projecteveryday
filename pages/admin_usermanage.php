<?php
session_start();
require '../includes/pdo.php';

// 检查权限
$role_ID = $_SESSION['role_ID'] ?? null;
if (!in_array($role_ID, [1, 2])) {
    echo '<div class="alert alert-danger">您沒有權限訪問此頁面</div>';
    exit;
}

// 获取搜索和筛选参数
$search = trim($_GET['search'] ?? '');
$role_filter = $_GET['role_filter'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';
$cohort_filter = $_GET['cohort_filter'] ?? '';
$grade_filter = $_GET['grade_filter'] ?? '';

// 构建查询
$sql = "SELECT 
            u.u_ID,
            u.u_name,
            u.u_gmail,
            u.u_profile,
            u.u_img,
            u.u_status,
            r.role_ID,
            r.role_name,
            s.status_ID,
            s.status_name,
            c.c_ID,
            c.c_name as class_name,
            e.cohort_ID,
            ch.cohort_name,
            e.enroll_grade
        FROM userdata u
        LEFT JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID AND ur.user_role_status = 1
        LEFT JOIN roledata r ON ur.role_ID = r.role_ID
        LEFT JOIN statusdata s ON s.status_ID = u.u_status
        LEFT JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID AND e.enroll_status = 1
        LEFT JOIN classdata c ON c.c_ID = e.class_ID
        LEFT JOIN cohortdata ch ON ch.cohort_ID = e.cohort_ID
        WHERE 1=1";

$params = [];

if ($search) {
    $sql .= " AND (u.u_ID LIKE ? OR u.u_name LIKE ? OR u.u_gmail LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($role_filter) {
    $sql .= " AND r.role_ID = ?";
    $params[] = $role_filter;
}

if ($status_filter !== '') {
    $sql .= " AND u.u_status = ?";
    $params[] = $status_filter;
}

if ($cohort_filter) {
    $sql .= " AND e.cohort_ID = ?";
    $params[] = $cohort_filter;
}

if ($grade_filter !== '') {
    $sql .= " AND e.enroll_grade = ?";
    $params[] = $grade_filter;
}

$sql .= " ORDER BY u.u_ID ASC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取角色列表用于筛选
$roles = $conn->query("SELECT * FROM roledata WHERE role_status = 1 ORDER BY role_ID")->fetchAll(PDO::FETCH_ASSOC);
$statuses = $conn->query("SELECT * FROM statusdata")->fetchAll(PDO::FETCH_ASSOC);
$cohorts = $conn->query("SELECT * FROM cohortdata ORDER BY cohort_ID DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- CSS 已在 head.php 中预加载，这里确保加载 -->
<link rel="stylesheet" href="css/admin_usermanage.css?v=<?= time() ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="css/admin_usermanage.css?v=<?= time() ?>"></noscript>

<div class="user-management-container" style="visibility: hidden;" id="userManagementContent">
    <div class="page-header">
        <h1 class="page-title">
            <i class="fa-solid fa-users me-2" style="color: #ffc107;"></i>帳號管理
        </h1>
        <div class="page-header-actions">
            <button type="button" class="btn btn-warning" id="selectAllBtn">
                <i class="fa-solid fa-check-double me-2"></i>全選
            </button>
            <button type="button" class="btn btn-outline-secondary" id="deselectAllBtn">
                <i class="fa-solid fa-times me-2"></i>取消全選
            </button>
            <button type="button" class="btn btn-primary" id="batchEditBtn" disabled>
                <i class="fa-solid fa-pen-to-square me-2"></i>批量編輯 (<span id="selectedCount">0</span>)
            </button>
        </div>
    </div>

    <?php
    // 统计信息
    $total_users = count($users);
    $active_users = count(array_filter($users, fn($u) => $u['u_status'] == 1));
    $inactive_users = $total_users - $active_users;
    ?>

    <div class="stats-bar">
        <div class="stat-card">
            <p class="stat-value"><?= $total_users ?></p>
            <p class="stat-label">總用戶數</p>
        </div>
        <div class="stat-card success">
            <p class="stat-value"><?= $active_users ?></p>
            <p class="stat-label">啟用中</p>
        </div>
        <div class="stat-card warning">
            <p class="stat-value"><?= $inactive_users ?></p>
            <p class="stat-label">已停用</p>
        </div>
    </div>

    <div class="filter-section">
        <form method="GET" action="#pages/admin_usermanage.php" id="filterForm" class="filter-row">
            <div>
                <label class="form-label">
                    <i class="fa-solid fa-search me-2"></i>搜尋
                </label>
                <input type="text" name="search" class="form-control" 
                       placeholder="搜尋帳號、姓名或信箱..." 
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div>
                <label class="form-label">
                    <i class="fa-solid fa-user-tag me-2"></i>角色
                </label>
                <select name="role_filter" class="form-select">
                    <option value="">全部角色</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= $role['role_ID'] ?>" 
                                <?= $role_filter == $role['role_ID'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($role['role_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">
                    <i class="fa-solid fa-toggle-on me-2"></i>狀態
                </label>
                <select name="status_filter" class="form-select">
                    <option value="">全部狀態</option>
                    <?php 
                    // 状态显示映射（仅限账号相关）
                    $statusMap = [
                        0 => '休學',
                        1 => '就讀中',
                        2 => '離校',
                        3 => '畢業'
                    ];
                    foreach ($statuses as $status): 
                      // 只显示 0-3，不显示 4
                      if ($status['status_ID'] == 4) continue;
                      $displayName = isset($statusMap[$status['status_ID']]) ? $statusMap[$status['status_ID']] : $status['status_name'];
                    ?>
                        <option value="<?= $status['status_ID'] ?>" 
                                <?= $status_filter == $status['status_ID'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($displayName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">
                    <i class="fa-solid fa-calendar-alt me-2"></i>學級
                </label>
                <select name="cohort_filter" class="form-select">
                    <option value="">全部學級</option>
                    <?php foreach ($cohorts as $cohort): ?>
                        <option value="<?= $cohort['cohort_ID'] ?>" 
                                <?= $cohort_filter == $cohort['cohort_ID'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cohort['cohort_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">
                    <i class="fa-solid fa-sort-numeric-up me-2"></i>年級
                </label>
                <select name="grade_filter" class="form-select">
                    <option value="">全部年級</option>
                    <?php for ($g = 1; $g <= 6; $g++): ?>
                        <option value="<?= $g ?>" <?= $grade_filter == $g ? 'selected' : '' ?>>
                            <?= $g ?>年級
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0;">
                <button type="submit" class="btn btn-warning" style="min-width: 100px; white-space: nowrap;">
                    <i class="fa-solid fa-filter me-2"></i>篩選
                </button>
                <?php if ($search || $role_filter || $status_filter !== '' || $cohort_filter || $grade_filter !== ''): ?>
                <a href="#pages/admin_usermanage.php" class="btn btn-outline-secondary ajax-link" style="min-width: 100px; white-space: nowrap;">
                    <i class="fa-solid fa-times me-2"></i>清除
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (empty($users)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-users-slash"></i>
            <h3>找不到符合條件的用戶</h3>
            <p>請嘗試調整搜尋條件或篩選器</p>
        </div>
    <?php else: ?>
        <div class="user-card-grid">
            <?php foreach ($users as $user): ?>
                <?php 
                // 判斷是否為學生（role_ID = 6）
                $isStudent = ($user['role_ID'] == 6);
                ?>
                <div class="user-card" data-user-id="<?= htmlspecialchars($user['u_ID']) ?>" style="cursor: pointer;">
                    <!-- 頭上顯示：非學生顯示身份，學生顯示學級 -->
                    <?php if ($isStudent && !empty($user['cohort_name'])): ?>
                        <!-- 學生顯示學級 -->
                        <div class="user-cohort-badge">
                            <i class="fa-solid fa-calendar-alt me-2"></i>
                            <?= htmlspecialchars($user['cohort_name']) ?>
                        </div>
                    <?php elseif (!$isStudent && !empty($user['role_name'])): ?>
                        <!-- 非學生顯示身份 -->
                        <div class="user-role-badge">
                            <i class="fa-solid fa-user-tag me-2"></i>
                            <?= htmlspecialchars($user['role_name']) ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="user-card-header">
                        <?php if (!empty($user['u_img'])): ?>
                            <img src="headshot/<?= htmlspecialchars($user['u_img']) ?>" 
                                 alt="" class="user-avatar"
                                 loading="lazy"
                                 style="width: 64px; height: 64px; object-fit: cover; border-radius: 50%; border: 3px solid #ffc107; box-shadow: 0 2px 8px rgba(255,193,7,0.3);">
                        <?php else: ?>
                            <img src="https://cdn-icons-png.flaticon.com/512/1144/1144760.png" 
                                 alt="" class="user-avatar"
                                 loading="lazy"
                                 style="width: 64px; height: 64px; object-fit: cover; border-radius: 50%; border: 3px solid #ffc107; box-shadow: 0 2px 8px rgba(255,193,7,0.3);">
                        <?php endif; ?>
                        <div class="user-info">
                            <div class="user-name-row">
                                <h3 class="user-name"><?= htmlspecialchars($user['u_name']) ?></h3>
                                <?php if ($isStudent && !empty($user['role_name'])): ?>
                                <!-- 只有學生才在名字後面顯示身份 -->
                                <span class="badge badge-custom badge-role-inline">
                                    <?= htmlspecialchars($user['role_name']) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="user-id"><?= htmlspecialchars($user['u_ID']) ?></p>
                        </div>
                    </div>

                    <div class="user-details">
                        <div class="detail-item">
                            <i class="fa-solid fa-envelope"></i>
                            <span class="detail-item-label">信箱：</span>
                            <span class="detail-item-value"><?= htmlspecialchars($user['u_gmail'] ?: '未設定') ?></span>
                        </div>
                        <div class="detail-item">
                            <i class="fa-solid fa-graduation-cap"></i>
                            <span class="detail-item-label">目前班級：</span>
                            <span class="detail-item-value">
                                <?php
                                $classDisplay = '';
                                if (!empty($user['class_name'])) {
                                    $classDisplay = htmlspecialchars($user['class_name']);
                                }
                                if (!empty($user['enroll_grade'])) {
                                    if ($classDisplay) {
                                        $classDisplay .= ' - ' . $user['enroll_grade'] . '年級';
                                    } else {
                                        $classDisplay = $user['enroll_grade'] . '年級';
                                    }
                                }
                                echo $classDisplay ?: '無';
                                ?>
                            </span>
                        </div>
                        <div class="detail-item">
                            <i class="fa-solid fa-info-circle"></i>
                            <span class="detail-item-label">自介：</span>
                            <span class="detail-item-value" style="font-size: 0.85rem;">
                                <?php if ($user['u_profile']): ?>
                                    <?= htmlspecialchars(mb_substr($user['u_profile'], 0, 30)) ?>
                                    <?= mb_strlen($user['u_profile']) > 30 ? '...' : '' ?>
                                <?php else: ?>
                                    <span class="text-muted">無</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="detail-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span class="detail-item-label">狀態：</span>
                            <span class="badge badge-custom <?= $user['u_status'] == 1 ? 'badge-status-active' : 'badge-status-inactive' ?>">
                                <?php
                                // 狀態顯示映射（僅限帳號相關）
                                $statusMap = [
                                    0 => '休學',
                                    1 => '就讀中',
                                    2 => '離校',
                                    3 => '畢業'
                                ];
                                $displayStatus = isset($statusMap[$user['u_status']]) ? $statusMap[$user['u_status']] : htmlspecialchars($user['status_name']);
                                echo htmlspecialchars($displayStatus);
                                ?>
                            </span>
                        </div>
                    </div>

                    <div class="user-actions">
                        <div class="form-check user-select-checkbox">
                            <input class="form-check-input user-checkbox" 
                                   type="checkbox" 
                                   value="<?= htmlspecialchars($user['u_ID']) ?>"
                                   id="user_<?= htmlspecialchars($user['u_ID']) ?>">
                            <label class="form-check-label" for="user_<?= htmlspecialchars($user['u_ID']) ?>">
                                選擇
                            </label>
                        </div>
                        <a href="#pages/admin_edituser.php?u_ID=<?= htmlspecialchars($user['u_ID']) ?>" 
                           class="btn btn-action btn-edit ajax-link">
                            <i class="fa-solid fa-pen-to-square me-2"></i>編輯
                        </a>
                        <button class="btn btn-action btn-toggle toggle-btn <?= $user['u_status'] == 1 ? 'active' : '' ?>"
                                data-acc="<?= htmlspecialchars($user['u_ID']) ?>"
                                data-status="<?= $user['u_status'] == 1 ? '0' : '1' ?>"
                                data-action="<?= $user['u_status'] == 1 ? '停用' : '啟用' ?>">
                            <i class="fa-solid <?= $user['u_status'] == 1 ? 'fa-toggle-on' : 'fa-toggle-off' ?> me-2"></i>
                            <?= $user['u_status'] == 1 ? '停用' : '啟用' ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="js/admin_usermanage.js?v=<?= time() ?>"></script>
