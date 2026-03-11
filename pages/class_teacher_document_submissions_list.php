<?php
session_start();
require '../includes/pdo.php';

// 檢查權限（只有班導 role_ID = 3 可以訪問）
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

if (!$u_ID) {
    echo '<div class="alert alert-danger">請先登入</div>';
    exit;
}

if ($role_ID != 3) {
    echo '<div class="alert alert-danger">此頁面僅限班導使用</div>';
    exit;
}

// 檢查欄位名稱（兼容不同版本的資料表結構）
function columnExists(PDO $conn, string $table, string $column): bool
{
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$teamUserField = columnExists($conn, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';

// 獲取班導管理的班級ID列表
$classIds = [];
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT class_ID
        FROM enrollmentdata
        WHERE enroll_u_ID = ? AND enroll_status = 1
    ");
    $stmt->execute([$u_ID]);
    $classIds = array_values(array_filter(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'class_ID')));
} catch (Exception $e) {
    $classIds = [];
}

// 獲取屬於這些班級的團隊ID列表
$teamIds = [];
if (!empty($classIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $stmt = $conn->prepare("
            SELECT DISTINCT t.team_ID
            FROM teamdata t
            JOIN teammember tm ON tm.team_ID = t.team_ID
            JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField}
            WHERE t.team_status = 1
              AND e.class_ID IN ($placeholders)
              AND e.enroll_status = 1
        ");
        $stmt->execute($classIds);
        $teamIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'team_ID');
    } catch (Exception $e) {
        $teamIds = [];
    }
}

// 獲取這些團隊的類組ID列表（用於篩選適用的文件）
$teamGroupIds = [];
if (!empty($teamIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $stmt = $conn->prepare("
            SELECT DISTINCT t.group_ID
            FROM teamdata t
            WHERE t.team_ID IN ($placeholders)
              AND t.team_status = 1
              AND t.group_ID IS NOT NULL
        ");
        $stmt->execute($teamIds);
        $teamGroupIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'group_ID');
    } catch (Exception $e) {
        $teamGroupIds = [];
    }
}

// 獲取所有啟用的文件
$allDocuments = [];
try {
    $stmt = $conn->query("
        SELECT 
            d.doc_ID,
            d.doc_name,
            d.doc_des,
            d.is_required,
            d.doc_start_d,
            d.doc_end_d,
            d.doc_status,
            'document' as item_type
        FROM docdata d
        WHERE d.doc_status = 1
        ORDER BY d.is_top DESC, d.doc_created_d DESC
    ");
    $allDocuments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allDocuments = [];
}

// 獲取所有啟用的表單（與文件合併顯示）
$allForms = [];
try {
    $stmt = $conn->query("
        SELECT 
            f.form_ID,
            f.form_name,
            f.form_des as doc_des,
            0 as is_required,
            f.form_start_d as doc_start_d,
            f.form_end_d as doc_end_d,
            f.form_status as doc_status,
            'form' as item_type
        FROM formdata f
        WHERE f.form_status = 1
        ORDER BY f.form_created_d DESC
    ");
    $allForms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 將表單ID轉換為負數，避免與文件ID衝突（在URL中使用 form_ID 參數區分）
    foreach ($allForms as &$form) {
        $form['doc_ID'] = -$form['form_ID']; // 使用負數標記表單
        $form['doc_name'] = $form['form_name'];
    }
    unset($form);
} catch (Exception $e) {
    $allForms = [];
}

// 合併文件和表單列表
$allItems = array_merge($allDocuments, $allForms);

// 獲取所有文件的目標對象（類組和ALL）
$docTargets = [];
$docTargetAll = []; // 記錄哪些文件適用於所有類組
$fileDocIds = array_filter(array_column($allDocuments, 'doc_ID'), function($id) { return $id > 0; });
if (!empty($fileDocIds)) {
    $placeholders = implode(',', array_fill(0, count($fileDocIds), '?'));
    try {
        $stmt = $conn->prepare("
            SELECT doc_ID, doc_target_type, doc_target_ID
            FROM doctargetdata
            WHERE doc_ID IN ($placeholders)
        ");
        $stmt->execute($fileDocIds);
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($targets as $target) {
            if ($target['doc_target_type'] === 'ALL') {
                $docTargetAll[$target['doc_ID']] = true;
            } elseif ($target['doc_target_type'] === 'GROUP') {
                if (!isset($docTargets[$target['doc_ID']])) {
                    $docTargets[$target['doc_ID']] = [];
                }
                $docTargets[$target['doc_ID']][] = $target['doc_target_ID'];
            }
        }
    } catch (Exception $e) {
        // 忽略錯誤
    }
}

// 獲取所有表單的目標對象（類組和屆別）
$formTargets = [];
$formTargetAll = [];
$formIds = array_column($allForms, 'form_ID');
if (!empty($formIds)) {
    $placeholders = implode(',', array_fill(0, count($formIds), '?'));
    try {
        $stmt = $conn->prepare("
            SELECT form_ID, ft_group, ft_cohort_from, ft_cohort_to
            FROM formtargetdata
            WHERE form_ID IN ($placeholders)
        ");
        $stmt->execute($formIds);
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($targets as $target) {
            $formId = $target['form_ID'];
            // 如果 ft_group 為 NULL 或空，表示適用於所有類組
            if (empty($target['ft_group'])) {
                $formTargetAll[$formId] = true;
            } else {
                $formTargets[$formId] = [
                    'groups' => explode(',', str_replace(' ', '', $target['ft_group'])),
                    'cohort_from' => $target['ft_cohort_from'],
                    'cohort_to' => $target['ft_cohort_to']
                ];
            }
        }
    } catch (Exception $e) {
        // 忽略錯誤
    }
}

// 判斷文件是否適用於班導管理的團隊
function isDocumentApplicable($docId, $teamGroupIds, $docTargets, $docTargetAll) {
    // 如果文件設定了 ALL，適用於所有類組
    if (isset($docTargetAll[$docId]) && $docTargetAll[$docId]) {
        return true;
    }
    
    // 如果文件沒有設定類組目標，則適用於所有類組
    if (!isset($docTargets[$docId]) || empty($docTargets[$docId])) {
        return true;
    }
    
    // 如果文件設定了類組目標，檢查是否有團隊的類組在目標中
    foreach ($teamGroupIds as $teamGroupId) {
        if (in_array((string)$teamGroupId, $docTargets[$docId])) {
            return true;
        }
    }
    
    return false;
}

// 判斷表單是否適用於班導管理的團隊
function isFormApplicable($formId, $teamGroupIds, $teamCohortIds, $formTargets, $formTargetAll) {
    // 如果表單設定了適用於所有類組
    if (isset($formTargetAll[$formId]) && $formTargetAll[$formId]) {
        return true;
    }
    
    // 如果表單沒有設定目標，則適用於所有類組
    if (!isset($formTargets[$formId])) {
        return true;
    }
    
    $target = $formTargets[$formId];
    
    // 檢查類組是否匹配
    $groupMatch = false;
    if (!empty($target['groups'])) {
        foreach ($teamGroupIds as $teamGroupId) {
            if (in_array((string)$teamGroupId, $target['groups'])) {
                $groupMatch = true;
                break;
            }
        }
    } else {
        $groupMatch = true; // 沒有設定類組限制，視為匹配
    }
    
    // 檢查屆別是否在範圍內
    $cohortMatch = true;
    if ($target['cohort_from'] !== null || $target['cohort_to'] !== null) {
        $cohortMatch = false;
        foreach ($teamCohortIds as $teamCohortId) {
            if (($target['cohort_from'] === null || $teamCohortId >= $target['cohort_from']) &&
                ($target['cohort_to'] === null || $teamCohortId <= $target['cohort_to'])) {
                $cohortMatch = true;
                break;
            }
        }
    }
    
    return $groupMatch && $cohortMatch;
}

// 獲取這些團隊的屆別ID列表（用於篩選適用的表單）
$teamCohortIds = [];
if (!empty($teamIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $stmt = $conn->prepare("
            SELECT DISTINCT t.cohort_ID
            FROM teamdata t
            WHERE t.team_ID IN ($placeholders)
              AND t.team_status = 1
              AND t.cohort_ID IS NOT NULL
        ");
        $stmt->execute($teamIds);
        $teamCohortIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'cohort_ID');
    } catch (Exception $e) {
        $teamCohortIds = [];
    }
}

// 篩選出適用的文件和表單
$applicableDocuments = [];
foreach ($allItems as $item) {
    if ($item['item_type'] === 'document') {
        // 文件
        if (isDocumentApplicable($item['doc_ID'], $teamGroupIds, $docTargets, $docTargetAll)) {
            $applicableDocuments[] = $item;
        }
    } else {
        // 表單
        $formId = abs($item['doc_ID']); // 還原表單ID
        if (isFormApplicable($formId, $teamGroupIds, $teamCohortIds, $formTargets, $formTargetAll)) {
            $applicableDocuments[] = $item;
        }
    }
}
?>

<meta charset="UTF-8">
<title>查看班級文件繳交</title>

<style>
    /* 防止跑版 - 全局設置 */
    * {
        box-sizing: border-box;
    }
    
    /* 防止標題橫幅在縮放時改變樣式 */
    .page-header {
        -webkit-transform: scale(1) !important;
        transform: scale(1) !important;
        -webkit-backface-visibility: hidden;
        backface-visibility: hidden;
    }
    
    /* 全局防止縮放影響 */
    .document-submissions-list-container * {
        -webkit-transform: none !important;
        transform: none !important;
        zoom: 1 !important;
    }
    
    /* 允許特定動畫元素 */
    .project-item:hover,
    .group-section {
        transform: translateY(0) !important;
    }
    
    .project-item:hover {
        transform: translateY(-3px) !important;
    }
    
    .document-submissions-list-container {
        padding: 0 30px 30px 50px !important; /* 增加左側內邊距，使內容不貼齊左邊框 */
        max-width: 100%;
        width: 100%;
        margin: 0 !important;
        box-sizing: border-box;
        overflow-x: hidden;
        --ctds-accent-1: #6a78e6;
        --ctds-accent-2: #7852a4;
        --ctds-accent-3: #5f8bfa;
        --ctds-warm: #f2c85b;
        --ctds-bg-1: #f6f7fb;
        --ctds-bg-2: #e9eef8;
        --ctds-bg-3: #f7f9ff;

        background: linear-gradient(135deg, var(--ctds-bg-1) 0%, var(--ctds-bg-2) 55%, var(--ctds-bg-3) 100%);
        min-height: 100vh;
        min-width: 1200px; /* 設置最小寬度防止過度壓縮 */
        position: relative;
        padding-bottom: 30px !important; /* 底部留一點空間 */
    }

    .page-header {
        display: flex;
        justify-content: space-between; /* 左右分布 */
        align-items: center;
        gap: 15px;
        margin: 0 0 30px 0 !important;
        padding: 1.5rem 0 !important;
        background: transparent !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        position: relative;
        overflow: visible;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        flex-shrink: 0;
        height: auto !important;
        min-height: auto !important;
        max-height: none !important;
        border-bottom: 3px solid #ffc107;
        text-align: left;
        flex-wrap: wrap;
    }

    .page-header::before {
        display: none !important;
    }

    .page-title {
        font-size: 2.5rem !important; /* 40px */
        font-weight: 700 !important;
        color: #2c3e50 !important; /* 深藍色/黑色 */
        text-align: left !important;
        margin: 0 !important;
        display: flex !important;
        align-items: center;
        justify-content: flex-start;
        gap: 12px;
        padding: 0 !important;
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1) !important;
        position: relative;
        z-index: 1;
        flex-shrink: 0;
        line-height: 1.2;
        transform: none !important;
        zoom: 1 !important;
        background: none !important;
        -webkit-background-clip: unset !important;
        -webkit-text-fill-color: unset !important;
        background-clip: unset !important;
    }

    .page-title i {
        color: #ffc107 !important;
        font-size: 2.5rem !important;
        filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
        margin-right: 12px;
        flex-shrink: 0;
        width: auto !important;
        height: auto !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .page-header .text-muted {
        font-size: 0.95rem;
        color: #64748b;
        margin: 0;
        display: flex;
        align-items: center;
    }

    .page-logo {
        position: relative;
        width: 48px;
        height: 48px;
        flex-shrink: 0;
    }

    .logo-layer {
        position: absolute;
        width: 40px;
        height: 40px;
        background: #ffc107;
        border-radius: 8px;
        transform: rotate(45deg);
    }

    .logo-layer:nth-child(1) {
        top: 0;
        left: 0;
        opacity: 1;
        z-index: 3;
    }

    .logo-layer:nth-child(2) {
        top: 4px;
        left: 4px;
        opacity: 0.8;
        z-index: 2;
    }

    .logo-layer:nth-child(3) {
        top: 8px;
        left: 8px;
        opacity: 0.6;
        z-index: 1;
    }

    .project-list-section {
        background: white;
        border-radius: 16px !important; /* 長方形圓角，與標題橫幅一致 */
        margin: 0 0 30px 0 !important; /* 改用父容器內邊距控制左右對齊 */
        padding: 30px !important; /* 固定內邊距 */
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08); /* 恢復陰影 */
        backdrop-filter: blur(10px);
        overflow-x: auto; /* 如果內容過寬，允許橫向滾動 */
        width: 100% !important; /* 滿寬，與父容器 padding 對齊 */
        max-width: 100% !important;
        box-sizing: border-box !important; /* 確保 box-sizing 一致 */
    }

    /* Word 風格表格 */
    .table-wrapper {
        width: 100%;
        overflow-x: auto;
        background: white;
        border: 1px solid #a0aec0;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .document-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 16px;
        background: white;
    }

    .document-table thead {
        background: #2c5282;
        color: white;
    }

    .document-table th {
        padding: 14px 16px;
        text-align: center;
        font-weight: 600;
        font-size: 16px;
        border: 1px solid #1a365d;
        white-space: nowrap;
    }

    .document-table th:nth-child(2) {
        text-align: left; /* 文件名稱靠左 */
    }

    .document-table tbody tr {
        border-bottom: 1px solid #cbd5e0;
    }

    .document-table tbody tr:nth-child(even) {
        background-color: #f7fafc;
    }

    .document-table tbody tr:nth-child(odd) {
        background-color: white;
    }

    .document-table tbody tr:hover {
        background-color: #f1f5f9 !important;
    }

    .document-table td {
        padding: 14px 16px;
        border: 1px solid #cbd5e0;
        vertical-align: middle;
        font-size: 15px;
        text-align: center;
    }

    .document-table td:first-child {
        font-weight: 500;
    }

    .document-table td:nth-child(2) {
        text-align: left; /* 文件名稱靠左 */
    }

    /* 表格標籤 */
    .table-badge {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 600;
        white-space: nowrap;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }

    .table-badge i {
        font-size: 12px;
    }

    /* 日期範圍 */
    .date-range {
        font-size: 14px;
        color: #333;
        white-space: nowrap;
    }

    .date-range i {
        color: #667eea;
    }

    /* 說明文字 */
    .doc-description {
        font-size: 14px;
        color: #666;
        max-width: 200px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        display: inline-block;
    }

    /* 表格操作按鈕 */
    .btn-table-action {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
        text-decoration: none;
    }

    .btn-table-action:hover {
        background: linear-gradient(135deg, #5568d3 0%, #653a91 100%);
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        color: white;
    }

    .btn-table-action i {
        font-size: 13px;
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

    .empty-state h3 {
        font-size: 20px;
        margin-bottom: 10px;
        color: #495057;
    }

    .empty-state p {
        font-size: 14px;
    }
</style>

<div class="document-submissions-list-container">
    <div class="page-header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div class="page-logo">
                <div class="logo-layer"></div>
                <div class="logo-layer"></div>
                <div class="logo-layer"></div>
            </div>
            <h1 class="page-title">
                查看班級文件繳交
            </h1>
        </div>
    </div>
    <div class="project-list-section">
        <?php if (empty($applicableDocuments)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-folder-open"></i>
                <h3>目前沒有文件</h3>
                <p>目前沒有適用於您管理班級的文件</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="document-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">序號</th>
                            <th style="width: 35%;">文件名稱</th>
                            <th style="width: 10%;">狀態</th>
                            <th style="width: 20%;">日期範圍</th>
                            <th style="width: 20%;">說明</th>
                            <th style="width: 10%;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applicableDocuments as $index => $doc): ?>
                            <tr>
                                <td style="text-align: center;"><?= $index + 1 ?></td>
                                <td style="text-align: left;">
                                    <i class="fa-solid fa-file-alt" style="color: #667eea; margin-right: 8px;"></i>
                                    <?= htmlspecialchars($doc['doc_name'] ?? '未知文件', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($doc['item_type'] === 'form'): ?>
                                        <span class="table-badge" style="background: #f0f0f0; color: #666; border-color: #ccc;">
                                            -
                                        </span>
                                    <?php elseif (($doc['is_required'] ?? 0) == 1): ?>
                                        <span class="table-badge" style="background: #fff3cd; color: #856404; border-color: #ffc107;">
                                            <i class="fa-solid fa-exclamation-circle"></i> 必繳
                                        </span>
                                    <?php else: ?>
                                        <span class="table-badge" style="background: #d4edda; color: #155724; border-color: #28a745;">
                                            <i class="fa-solid fa-check-circle"></i> 非必繳
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if (!empty($doc['doc_start_d']) && !empty($doc['doc_end_d'])): ?>
                                        <span class="date-range">
                                            <i class="fa-solid fa-calendar" style="margin-right: 4px;"></i>
                                            <?= htmlspecialchars(date('Y-m-d', strtotime($doc['doc_start_d'])), ENT_QUOTES, 'UTF-8') ?> ~ <?= htmlspecialchars(date('Y-m-d', strtotime($doc['doc_end_d'])), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                <?php if (!empty($doc['doc_des'])): ?>
                                        <span class="doc-description" title="<?= htmlspecialchars($doc['doc_des'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(mb_substr($doc['doc_des'], 0, 20, 'UTF-8') . (mb_strlen($doc['doc_des'], 'UTF-8') > 20 ? '...' : ''), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                            <?php if ($doc['item_type'] === 'form'): ?>
                                        <a href="pages/class_teacher_document_submissions.php?form_ID=<?= abs($doc['doc_ID']) ?>" class="btn-table-action ajax-link">
                                            <i class="fa-solid fa-eye"></i> 查看
                                </a>
                            <?php else: ?>
                                        <a href="pages/class_teacher_document_submissions.php?doc_ID=<?= $doc['doc_ID'] ?>" class="btn-table-action ajax-link">
                                            <i class="fa-solid fa-eye"></i> 查看
                                </a>
                            <?php endif; ?>
                                </td>
                            </tr>
                <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // 設置側邊欄高亮（與科辦端一致）
    (function() {
        function setSidebarActive() {
            // 確保 jQuery 和 DOM 都準備好
            if (typeof jQuery === 'undefined') {
                setTimeout(setSidebarActive, 100);
                return;
            }
            
            jQuery(function($) {
                // 等待一下確保側邊欄已經渲染
                setTimeout(function() {
                    // 移除所有 active 狀態
                    $('#layoutSidenav_nav .ajax-link, #sidenavAccordion .ajax-link, .sb-sidenav .ajax-link, .sb-sidenav-menu .ajax-link').removeClass('active');
                    
                    // 設置對應的側邊欄項目為 active
                    const targetLink = $('.sb-sidenav .ajax-link[href="pages/class_teacher_document_submissions_list.php"], .sb-sidenav-menu .ajax-link[href="pages/class_teacher_document_submissions_list.php"]');
                    if (targetLink.length > 0) {
                        targetLink.addClass('active');
                        
                        // 如果是在子選單中，確保父選單也是展開狀態
                        const parentSubmenu = targetLink.closest('.dropdown-submenu');
                        if (parentSubmenu.length > 0) {
                            parentSubmenu.addClass('active');
                        }
                    } else {
                        // 如果找不到，再試一次
                        setTimeout(function() {
                            const retryLink = $('.sb-sidenav .ajax-link[href="pages/class_teacher_document_submissions_list.php"], .sb-sidenav-menu .ajax-link[href="pages/class_teacher_document_submissions_list.php"]');
                            if (retryLink.length > 0) {
                                $('#layoutSidenav_nav .ajax-link, #sidenavAccordion .ajax-link, .sb-sidenav .ajax-link, .sb-sidenav-menu .ajax-link').removeClass('active');
                                retryLink.addClass('active');
                                const parentSubmenu = retryLink.closest('.dropdown-submenu');
                                if (parentSubmenu.length > 0) {
                                    parentSubmenu.addClass('active');
                                }
                            }
                        }, 200);
                    }
                }, 150);
            });
        }
        
        // 等待頁面載入完成
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setSidebarActive);
        } else {
            // DOM 已經載入，但可能 jQuery 還沒準備好
            if (typeof jQuery !== 'undefined') {
                setSidebarActive();
            } else {
                setTimeout(setSidebarActive, 100);
            }
        }
    })();
</script>

