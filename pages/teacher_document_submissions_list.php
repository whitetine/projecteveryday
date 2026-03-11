<?php
session_start();
require '../includes/pdo.php';

// 檢查權限（只有教師 role_ID = 4 可以訪問）
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

if (!$u_ID) {
    echo '<div class="alert alert-danger">請先登入</div>';
    exit;
}

if ($role_ID != 4) {
    echo '<div class="alert alert-danger">此頁面僅限指導老師使用</div>';
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
$userRoleUidField = columnExists($conn, 'userrolesdata', 'ur_u_ID') ? 'ur_u_ID' : 'u_ID';

// 獲取教師指導的所有團隊
$teams = [];
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT 
            t.team_ID,
            t.team_project_name,
            t.cohort_ID,
            t.group_ID,
            c.cohort_name,
            g.group_name
        FROM teammember tm
        JOIN teamdata t ON tm.team_ID = t.team_ID
        JOIN userrolesdata ur ON ur.{$userRoleUidField} = tm.{$teamUserField}
        LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
        LEFT JOIN groupdata g ON t.group_ID = g.group_ID
        WHERE tm.{$teamUserField} = ?
          AND ur.role_ID = 4
          AND ur.user_role_status = 1
          AND t.team_status = 1
        ORDER BY t.group_ID, t.cohort_ID DESC, t.team_ID
    ");
    $stmt->execute([$u_ID]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $teams = [];
}

// 以文件呈現：只列出與指導老師團隊相關的文件
$teamIds = array_values(array_unique(array_map('intval', array_column($teams, 'team_ID'))));
$teamGroupIds = array_values(array_unique(array_filter(array_map('intval', array_column($teams, 'group_ID')))));
$teamCohortIds = array_values(array_unique(array_filter(array_map('intval', array_column($teams, 'cohort_ID')))));

$documents = [];
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
            d.is_top
        FROM docdata d
        WHERE d.doc_status = 1
        ORDER BY d.is_top DESC, d.doc_created_d DESC
    ");
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $documents = [];
}

$docTargets = [];
$docTargetAll = [];
if (!empty($documents)) {
    $docIds = array_column($documents, 'doc_ID');
    $placeholders = implode(',', array_fill(0, count($docIds), '?'));
    try {
        $stmt = $conn->prepare("
            SELECT doc_ID, doc_target_type, doc_target_ID
            FROM doctargetdata
            WHERE doc_ID IN ($placeholders)
        ");
        $stmt->execute($docIds);
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($targets as $t) {
            $docId = (int)$t['doc_ID'];
            $type = (string)$t['doc_target_type'];
            $targetId = (string)$t['doc_target_ID'];
            if ($type === 'ALL') {
                $docTargetAll[$docId] = true;
                continue;
            }
            if (!isset($docTargets[$docId])) $docTargets[$docId] = [];
            if (!isset($docTargets[$docId][$type])) $docTargets[$docId][$type] = [];
            $docTargets[$docId][$type][] = $targetId;
        }
    } catch (Exception $e) {
        // ignore
    }
}

function isDocumentApplicableToTeacherTeams(
    int $docId,
    array $teamIds,
    array $teamGroupIds,
    array $teamCohortIds,
    array $docTargets,
    array $docTargetAll,
    string $u_ID
): bool {
    if (isset($docTargetAll[$docId]) && $docTargetAll[$docId]) return true;
    if (!isset($docTargets[$docId]) || empty($docTargets[$docId])) return true;

    $t = $docTargets[$docId];

    if (!empty($t['TEAM'])) {
        foreach ($t['TEAM'] as $teamId) {
            if (in_array((int)$teamId, $teamIds, true)) return true;
        }
    }
    if (!empty($t['GROUP'])) {
        foreach ($t['GROUP'] as $gid) {
            if (in_array((int)$gid, $teamGroupIds, true)) return true;
        }
    }
    if (!empty($t['COHORT'])) {
        foreach ($t['COHORT'] as $cid) {
            if (in_array((int)$cid, $teamCohortIds, true)) return true;
        }
    }
    if (!empty($t['USER'])) {
        foreach ($t['USER'] as $uid) {
            if ((string)$uid === (string)$u_ID) return true;
        }
    }

    return false;
}

$applicableDocuments = [];
foreach ($documents as $doc) {
    $docId = (int)($doc['doc_ID'] ?? 0);
    if ($docId <= 0) continue;
    if (isDocumentApplicableToTeacherTeams($docId, $teamIds, $teamGroupIds, $teamCohortIds, $docTargets, $docTargetAll, (string)$u_ID)) {
        $applicableDocuments[] = $doc;
    }
}
?>

<meta charset="UTF-8">
<title>查看文件繳交</title>

<style>
    .document-submissions-list-container {
        padding: 0 30px 30px 50px !important;
        max-width: 100%;
        width: 100%;
        margin: 0 !important;
        box-sizing: border-box;
        overflow-x: hidden;
        --tdsl-accent-1: #6a78e6;
        --tdsl-accent-2: #7852a4;
        --tdsl-accent-3: #5f8bfa;
        --tdsl-warm: #f2c85b;
        --tdsl-bg-1: #f6f7fb;
        --tdsl-bg-2: #e9eef8;
        --tdsl-bg-3: #f7f9ff;

        background: linear-gradient(135deg, var(--tdsl-bg-1) 0%, var(--tdsl-bg-2) 55%, var(--tdsl-bg-3) 100%);
        min-height: 100vh;
        min-width: 1200px;
    }

    .page-header {
        display: flex;
        justify-content: flex-start; /* Align items to the start */
        align-items: center;
        gap: 15px;
        margin: 0 0 30px 0 !important;
        padding: 28px 35px !important;
        background: #ffffff !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        position: relative;
        overflow: hidden;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        flex-shrink: 0;
        height: auto !important;
        min-height: auto !important;
        max-height: none !important;
        border-bottom: 3px solid #ffc107;
        text-align: left; /* Ensure text aligns left */
    }
    .page-title i {
        color: #ffc107;
        font-size: 40px !important;
        line-height: 1;
        margin-right: 12px;
        flex-shrink: 0;
    }

    .page-title {
        font-size: 40px !important;
        font-weight: 700 !important;
        color: #1a202c !important;
        text-align: left !important;
        margin: 0 !important;
        display: flex !important;
        align-items: baseline !important;
        justify-content: flex-start !important;
        gap: 12px;
        text-shadow: none !important;
        position: relative;
        z-index: 1;
        flex-shrink: 0;
        line-height: 1;
        transform: none !important;
        zoom: 1 !important;
        padding-left: 0 !important;
        width: 100%;
    }
    .page-title span {
        text-align: left !important;
        line-height: 1;
    }

    .project-list-section {
        background: white;
        border-radius: 16px !important;
        margin: 0 0 30px 0 !important;
        padding: 30px !important;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        backdrop-filter: blur(10px);
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
        <h1 class="page-title">
            <i class="fa-solid fa-file-lines"></i>
            <span>查看文件繳交</span>
        </h1>
    </div>

    <div class="project-list-section">
        <?php if (empty($teams)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-folder-open"></i>
                <h3>目前沒有團隊</h3>
                <p>您尚未指導任何團隊</p>
            </div>
        <?php elseif (empty($applicableDocuments)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-folder-open"></i>
                <h3>目前沒有文件</h3>
                <p>目前沒有適用於您指導團隊的文件</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="document-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">序號</th>
                            <th style="width: 30%;">文件名稱</th>
                            <th style="width: 10%;">類型</th>
                            <th style="width: 10%;">狀態</th>
                            <th style="width: 20%;">日期範圍</th>
                            <th style="width: 15%;">說明</th>
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
                                    <span class="table-badge" style="background: #f0f0f0; color: #666; border-color: #ccc;">
                                        <i class="fa-solid fa-file"></i> 文件
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <?php if (($doc['is_required'] ?? 0) == 1): ?>
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
                                    <a href="pages/teacher_document_submissions.php?doc_ID=<?= (int)$doc['doc_ID'] ?>" class="btn-table-action ajax-link">
                                        <i class="fa-solid fa-eye"></i> 查看
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>








