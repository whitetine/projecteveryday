<?php
session_start();
require '../includes/pdo.php';

// 檢查權限（教師 role_ID = 4 或班導 role_ID = 3 可以訪問）
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

if (!$u_ID) {
    echo '<div class="alert alert-danger">請先登入</div>';
    exit;
}

if (!in_array($role_ID, [3, 4])) {
    echo '<div class="alert alert-danger">此頁面僅限指導老師或班導使用</div>';
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

// 以文件呈現：指定要查看的文件
$selectedDocId = isset($_GET['doc_ID']) ? (int)$_GET['doc_ID'] : null;

// 獲取團隊列表（根據角色不同查詢方式）
$teams = [];
try {
    if ($role_ID == 4) {
        // 指導老師：查詢指導的團隊
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
        ORDER BY t.cohort_ID DESC, t.team_ID
    ");
    $stmt->execute([$u_ID]);
    } elseif ($role_ID == 3) {
        // 班導：查詢管理的班級中的團隊
        // 先獲取班導管理的班級ID列表
        $classIds = [];
        $classStmt = $conn->prepare("
            SELECT DISTINCT class_ID
            FROM enrollmentdata
            WHERE enroll_u_ID = ? AND enroll_status = 1
        ");
        $classStmt->execute([$u_ID]);
        $classIds = array_values(array_filter(array_column($classStmt->fetchAll(PDO::FETCH_ASSOC), 'class_ID')));
        
        if (!empty($classIds)) {
            $placeholders = implode(',', array_fill(0, count($classIds), '?'));
            $stmt = $conn->prepare("
                SELECT DISTINCT 
                    t.team_ID,
                    t.team_project_name,
                    t.cohort_ID,
                    t.group_ID,
                    c.cohort_name,
                    g.group_name
                FROM teamdata t
                JOIN teammember tm ON tm.team_ID = t.team_ID
                JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField}
                LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                WHERE t.team_status = 1
                  AND e.class_ID IN ($placeholders)
                  AND e.enroll_status = 1
                ORDER BY t.cohort_ID DESC, t.team_ID
            ");
            $stmt->execute($classIds);
        } else {
            $teams = [];
        }
    }
    
    if (isset($stmt)) {
        $allTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
            $teams = $allTeams;
    }
} catch (Exception $e) {
    $teams = [];
}

// 獲取所有啟用的文件類型
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
            d.doc_status
        FROM docdata d
        WHERE d.doc_status = 1
        ORDER BY d.is_top DESC, d.doc_created_d DESC
    ");
    $allDocuments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allDocuments = [];
}

// 獲取所有文件的目標對象（ALL / GROUP / COHORT / TEAM / USER ...）
$docTargets = [];
$docTargetAll = []; // 記錄哪些文件適用於所有目標
if (!empty($allDocuments)) {
    $docIds = array_column($allDocuments, 'doc_ID');
    $placeholders = implode(',', array_fill(0, count($docIds), '?'));
    try {
        $stmt = $conn->prepare("
            SELECT doc_ID, doc_target_type, doc_target_ID
            FROM doctargetdata
            WHERE doc_ID IN ($placeholders)
        ");
        $stmt->execute($docIds);
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($targets as $target) {
            $docId = (int)($target['doc_ID'] ?? 0);
            $type = (string)($target['doc_target_type'] ?? '');
            $targetId = (string)($target['doc_target_ID'] ?? '');
            if ($docId <= 0 || $type === '') continue;

            if ($type === 'ALL') {
                $docTargetAll[$docId] = true;
                continue;
            }

            if (!isset($docTargets[$docId])) $docTargets[$docId] = [];
            if (!isset($docTargets[$docId][$type])) $docTargets[$docId][$type] = [];
            $docTargets[$docId][$type][] = $targetId;
        }
    } catch (Exception $e) {
        // 忽略錯誤
    }
}

// 獲取所有團隊的文件繳交記錄
// 需要處理兩種情況：
// 1. dcsub_team_ID 有值：直接匹配團隊ID
// 2. dcsub_team_ID 為 NULL：通過學生ID查找所屬團隊
$teamSubmissions = [];
if (!empty($teams)) {
    $teamIds = array_column($teams, 'team_ID');
    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    
    // 獲取這些團隊的所有成員ID
    $teamMemberIds = [];
    try {
        $memberStmt = $conn->prepare("
            SELECT DISTINCT tm.{$teamUserField} as u_ID, tm.team_ID
            FROM teammember tm
            WHERE tm.team_ID IN ($placeholders) AND (tm.tm_status IS NULL OR tm.tm_status IN (0, 1))
        ");
        $memberStmt->execute($teamIds);
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 組織成員ID到團隊ID的映射
        $userToTeam = [];
        foreach ($members as $member) {
            $userId = $member['u_ID'];
            $teamId = $member['team_ID'];
            if (!isset($userToTeam[$userId])) {
                $userToTeam[$userId] = [];
            }
            $userToTeam[$userId][] = $teamId;
        }
        $teamMemberIds = array_keys($userToTeam);
    } catch (Exception $e) {
        $userToTeam = [];
    }
    
    try {
        // 查詢文件繳交記錄：匹配團隊ID或學生ID
        $conditions = ["ds.dcsub_team_ID IN ($placeholders)"];
        $params = $teamIds;
        
        if (!empty($teamMemberIds)) {
            $memberPlaceholders = implode(',', array_fill(0, count($teamMemberIds), '?'));
            $conditions[] = "ds.dcsub_u_ID IN ($memberPlaceholders)";
            $params = array_merge($params, $teamMemberIds);
        }
        
        $whereClause = implode(' OR ', $conditions);
        
        $stmt = $conn->prepare("
            SELECT 
                ds.sub_ID,
                ds.doc_ID,
                ds.dcsub_team_ID,
                ds.dcsub_u_ID,
                ds.dcsub_comment,
                ds.dcsub_url,
                ds.dcsub_sub_d,
                ds.dc_approved_u_ID,
                ds.dcsub_approved_d,
                ds.dcsub_remark,
                ds.dcsub_status,
                u.u_name as submitter_name
            FROM docsubdata ds
            LEFT JOIN userdata u ON ds.dcsub_u_ID = u.u_ID
            WHERE ($whereClause)
            ORDER BY ds.dcsub_sub_d DESC
        ");
        $stmt->execute($params);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 按團隊ID組織資料
        foreach ($submissions as $sub) {
            $teamId = $sub['dcsub_team_ID'];
            $userId = $sub['dcsub_u_ID'];
            
            // 如果 dcsub_team_ID 為 NULL，通過學生ID查找團隊
            if (empty($teamId) && !empty($userId) && isset($userToTeam[$userId])) {
                // 如果學生屬於多個團隊，都加上這筆記錄
                foreach ($userToTeam[$userId] as $tId) {
                    if (!isset($teamSubmissions[$tId])) {
                        $teamSubmissions[$tId] = [];
                    }
                    // 如果該文件ID還沒有記錄，或這筆記錄更新，則使用這筆
                    if (!isset($teamSubmissions[$tId][$sub['doc_ID']]) || 
                        strtotime($sub['dcsub_sub_d']) > strtotime($teamSubmissions[$tId][$sub['doc_ID']]['dcsub_sub_d'])) {
                        $teamSubmissions[$tId][$sub['doc_ID']] = $sub;
                    }
                }
            } elseif (!empty($teamId) && in_array($teamId, $teamIds)) {
                // 如果 dcsub_team_ID 有值，直接使用
                if (!isset($teamSubmissions[$teamId])) {
                    $teamSubmissions[$teamId] = [];
                }
                $teamSubmissions[$teamId][$sub['doc_ID']] = $sub;
            }
        }
    } catch (Exception $e) {
        // 忽略錯誤，但可以記錄日誌
        error_log("Error fetching team submissions: " . $e->getMessage());
    }
}

function isDocumentApplicableToTeam(int $docId, array $team, array $docTargets, array $docTargetAll, string $u_ID): bool {
    if (isset($docTargetAll[$docId]) && $docTargetAll[$docId]) return true;
    if (!isset($docTargets[$docId]) || empty($docTargets[$docId])) return true;

    $t = $docTargets[$docId];

    $teamId = (int)($team['team_ID'] ?? 0);
    $teamGroupId = (int)($team['group_ID'] ?? 0);
    $teamCohortId = (int)($team['cohort_ID'] ?? 0);

    if (!empty($t['TEAM']) && in_array((string)$teamId, $t['TEAM'], true)) return true;
    if (!empty($t['GROUP']) && in_array((string)$teamGroupId, $t['GROUP'], true)) return true;
    if (!empty($t['COHORT']) && in_array((string)$teamCohortId, $t['COHORT'], true)) return true;
    if (!empty($t['USER']) && in_array((string)$u_ID, $t['USER'], true)) return true;

    return false;
}

if (empty($selectedDocId) || $selectedDocId <= 0) {
    $fallbackList = ($role_ID == 3) ? 'class_teacher_document_submissions_list.php' : 'teacher_document_submissions_list.php';

    // 在 main.php (hash/AJAX) 下，直接回列表會更符合操作；同時保留純 PHP 直連的 fallback
    if (!headers_sent()) {
        header("Location: {$fallbackList}");
        exit;
    }

    echo '<div class="alert alert-warning m-3">未指定文件，已返回文件列表。</div>';
    echo '<script>try{location.hash="pages/' . $fallbackList . '";}catch(e){}<\/script>';
    echo '<div class="m-3"><a class="ajax-link" href="pages/' . $fallbackList . '">點我返回文件列表</a></div>';
    exit;
}

$selectedDocument = null;
foreach ($allDocuments as $d) {
    if ((int)($d['doc_ID'] ?? 0) === (int)$selectedDocId) {
        $selectedDocument = $d;
        break;
    }
}
if (!$selectedDocument) {
    echo '<div class="alert alert-danger m-3">找不到指定的文件或文件已停用</div>';
    exit;
}

$rows = [];
foreach ($teams as $team) {
    $teamId = (int)($team['team_ID'] ?? 0);
    if ($teamId <= 0) continue;

    $submission = $teamSubmissions[$teamId][$selectedDocId] ?? null;
    $applicable = isDocumentApplicableToTeam((int)$selectedDocId, $team, $docTargets, $docTargetAll, (string)$u_ID);

    if (!$applicable && empty($submission)) continue;

    $statusText = '未繳交';
    $sortOrder = 0;
    if (!empty($submission)) {
        $st = (int)($submission['dcsub_status'] ?? 0);
        if ($st === 4) { $statusText = '未繳交'; $sortOrder = 0; }  // 暫存
        elseif ($st === 1) { $statusText = '已通過'; $sortOrder = 2; }  // 已繳交
        elseif ($st === 0) { $statusText = '審核中'; $sortOrder = 1; }
        elseif ($st === 2) { $statusText = '退件'; $sortOrder = 1; }
        else { $statusText = '審核中'; $sortOrder = 1; }
    }

    $rows[] = [
        'team_ID' => $teamId,
        'team_project_name' => $team['team_project_name'] ?? '',
        'cohort_name' => $team['cohort_name'] ?? '',
        'group_name' => $team['group_name'] ?? '',
        'status_text' => $statusText,
        'sort_order' => $sortOrder,
        'submission' => $submission
    ];
}

usort($rows, function($a, $b) {
    $ao = (int)($a['sort_order'] ?? 0);
    $bo = (int)($b['sort_order'] ?? 0);
    if ($ao !== $bo) return $ao <=> $bo;
    return strcmp((string)($a['team_project_name'] ?? ''), (string)($b['team_project_name'] ?? ''));
});

// 非必繳文件只顯示有繳交的團隊
if (((int)($selectedDocument['is_required'] ?? 0)) !== 1) {
    $rows = array_values(array_filter($rows, function($r) {
        return !empty($r['submission']);
    }));
}

$statusCounts = ['未繳交' => 0, '審核中' => 0, '已通過' => 0, '退件' => 0, '其它' => 0];
foreach ($rows as $r) {
    $s = $r['status_text'] ?? '其它';
    if (!isset($statusCounts[$s])) $s = '其它';
    $statusCounts[$s]++;
}
$totalCount = count($rows);
$submittedCount = $totalCount - ($statusCounts['未繳交'] ?? 0);
?>

<meta charset="UTF-8">
<title>查看文件繳交</title>

<style>
    .teacher-document-submissions-container {
        padding: 0 30px 30px 30px !important;
        max-width: 100%;
        width: 100%;
        margin: 0 !important;
        box-sizing: border-box;
        overflow-x: auto;
        --tds-accent-1: #6a78e6;
        --tds-accent-2: #7852a4;
        --tds-accent-3: #5f8bfa;
        --tds-warm: #f2c85b;
        --tds-bg-1: #f6f7fb;
        --tds-bg-2: #e9eef8;
        --tds-bg-3: #f7f9ff;

        background: linear-gradient(135deg, var(--tds-bg-1) 0%, var(--tds-bg-2) 55%, var(--tds-bg-3) 100%);
        min-height: 100vh;
        min-width: 1200px;
    }

    .btn-return-list-container {
        margin: 25px 30px !important;
    }

    .btn-return-list {
        display: inline-flex !important;
        align-items: center;
        gap: 10px;
        color: var(--tds-accent-1);
        text-decoration: none;
        font-weight: 700 !important;
        padding: 12px 22px !important;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(111, 99, 198, 0.14);
        transition: all 0.25s ease;
        font-size: 18px !important;
        white-space: nowrap;
        height: 48px !important;
        min-width: 130px !important;
    }

    .btn-return-list:hover {
        transform: translateX(-3px) !important;
        box-shadow: 0 6px 16px rgba(111, 99, 198, 0.20);
        color: #5568d3;
    }

    .page-header {
        display: flex !important;
        justify-content: flex-start !important;
        align-items: center !important;
        gap: 15px !important;
        margin: 0 0 30px 0 !important;
        padding: 28px 35px !important;
        background: #ffffff !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        position: relative !important;
        overflow: hidden !important;
        text-align: left !important;
        z-index: 20 !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        flex-shrink: 0;
        height: auto !important;
        min-height: auto !important;
        max-height: none !important;
        border-bottom: 3px solid #ffc107 !important;
    }
    .page-header h1 i,
    .page-header .page-title i,
    .page-header h1.page-title i {
        color: #ffc107;
        font-size: 40px !important;
        line-height: 1;
        margin-right: 12px;
        flex-shrink: 0;
    }

    /* 更強制的標題樣式 */
    .page-header h1,
    .page-header .page-title,
    .page-header h1.page-title {
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
        height: auto !important;
        min-height: 0 !important;
        max-height: none !important;
    }
    .page-header h1 span,
    .page-header .page-title span,
    .page-header h1.page-title span {
        text-align: left !important;
        line-height: 1;
    }

    .team-section {
        background: white;
        border-radius: 16px;
        padding: 30px;
        margin-bottom: 30px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        animation: fadeInUp 0.5s ease-out;
        overflow-x: auto;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .team-section:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 25px rgba(0, 0, 0, 0.12);
    }

    .team-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding: 20px;
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 12px;
        border: none;
        flex-wrap: wrap;
        gap: 15px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }

    .team-title {
        font-size: 26px !important;
        font-weight: 700 !important;
        color: #2d3748;
        display: flex;
        align-items: center;
        gap: 12px;
        white-space: nowrap;
        flex-shrink: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
        line-height: 1.4 !important;
    }

    .team-title i {
        color: var(--tds-accent-1);
        font-size: 24px !important;
        width: 24px !important;
        height: 24px !important;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .team-meta {
        display: flex;
        gap: 20px;
        flex-wrap: nowrap;
        font-size: 17px;
        flex-shrink: 0;
    }

    .team-meta span {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px !important;
        background: white;
        border-radius: 20px;
        color: var(--tds-accent-1);
        font-weight: 600;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        white-space: nowrap;
        flex-shrink: 0;
        font-size: 17px !important;
        height: 40px !important;
        border: 1px solid rgba(15, 23, 42, 0.08);
    }

    .team-meta span i {
        color: var(--tds-accent-2);
        font-size: 14px !important;
        width: 14px !important;
        height: 14px !important;
        flex-shrink: 0;
    }

    .table-wrapper {
        overflow-x: auto;
        overflow-y: visible;
        -webkit-overflow-scrolling: touch;
        margin-top: 20px;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }

    .document-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 0;
        background: white;
        table-layout: fixed;
        width: 100%;
        min-width: 1200px;
        border: 1px solid #a0aec0;
        border-radius: 8px;
        overflow: visible;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .document-table thead {
        background: #2c5282;
        color: white;
    }

    .document-table th {
        padding: 18px 15px;
        text-align: center;
        font-weight: 600;
        color: white;
        border: 1px solid #1a365d;
        font-size: 16px;
        letter-spacing: 0.3px;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        height: 60px;
        line-height: 1.5;
    }

    .document-table th:first-child {
        text-align: left;
        padding-left: 25px;
    }

    .document-table th,
    .document-table td {
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        word-break: keep-all !important;
        word-wrap: normal !important;
    }

    .document-table th:first-child,
    .document-table td:first-child {
        width: 25%;
        min-width: 200px;
    }

    .document-table th:nth-child(2),
    .document-table td:nth-child(2) {
        width: 12%;
        min-width: 100px;
    }

    .document-table th:nth-child(3),
    .document-table td:nth-child(3) {
        width: 12%;
        min-width: 100px;
    }

    .document-table th:nth-child(4),
    .document-table td:nth-child(4) {
        width: 12%;
        min-width: 100px;
    }

    .document-table th:nth-child(5),
    .document-table td:nth-child(5) {
        width: 12%;
        min-width: 100px;
    }

    .document-table th:nth-child(6),
    .document-table td:nth-child(6) {
        width: 15%;
        min-width: 150px;
    }

    .document-table th:nth-child(7),
    .document-table td:nth-child(7) {
        width: 12%;
        min-width: 180px;
    }

    .document-table td {
        padding: 18px 15px;
        border: 1px solid #cbd5e0;
        vertical-align: middle;
        background: white;
        font-size: 15px;
        line-height: 1.6;
        text-align: center;
        color: #2d3748;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }

    .document-table td:first-child {
        text-align: left;
        padding-left: 25px;
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

    .document-table tbody tr.row-not-submitted {
        background: #fff5f5 !important;
    }

    .document-table tbody tr.row-not-submitted:hover {
        background: #fff8f8 !important;
    }

    .document-table tbody tr.row-approved {
        background: #f0fdf4 !important;
    }

    .document-table tbody tr.row-approved:hover {
        background: #f5fef8 !important;
    }

    .document-table tbody tr:last-child td {
        border-bottom: none;
    }

    .status-badge {
        display: inline-block;
        padding: 6px 14px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
        white-space: nowrap;
    }

    .status-pending {
        background: #fff3cd;
        color: #856404;
    }

    .status-approved {
        background: #d4edda;
        color: #155724;
    }

    .status-rejected {
        background: #f8d7da;
        color: #721c24;
    }

    .status-not-submitted {
        background: #e9ecef;
        color: #6c757d;
    }

    .action-links {
        display: flex;
        flex-direction: column;
        gap: 8px;
        flex-wrap: nowrap !important;
        align-items: center;
        justify-content: center;
        white-space: nowrap !important;
    }

    .action-links a {
        padding: 10px 18px;
        border-radius: 10px;
        text-decoration: none;
        font-size: 15px;
        font-weight: 600;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.12);
        letter-spacing: 0.3px;
        white-space: nowrap !important;
        flex-shrink: 0 !important;
        height: 44px;
        min-width: 110px;
        justify-content: center;
        width: 100%;
        max-width: 120px;
    }

    .action-links a i {
        font-size: 14px;
        width: 14px;
        height: 14px;
        flex-shrink: 0;
    }

    .btn-view {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }

    .btn-view:hover {
        background: linear-gradient(135deg, #5568d3 0%, #6a3f8f 100%);
        color: white;
        transform: translateY(-3px);
        box-shadow: 0 6px 15px rgba(102, 126, 234, 0.4);
    }

    .btn-view:active {
        transform: translateY(-1px);
        box-shadow: 0 3px 10px rgba(102, 126, 234, 0.3);
    }

    .btn-download {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
    }

    .btn-download:hover {
        background: linear-gradient(135deg, #218838 0%, #1aa179 100%);
        color: white;
        transform: translateY(-3px);
        box-shadow: 0 6px 15px rgba(40, 167, 69, 0.4);
    }

    .btn-download:active {
        transform: translateY(-1px);
        box-shadow: 0 3px 10px rgba(40, 167, 69, 0.3);
    }

    .submitter-info {
        font-size: 13px;
        color: #6c757d;
        margin-top: 4px;
    }

    .submitter-info i {
        margin-right: 4px;
    }

    .required-badge {
        display: inline-block;
        padding: 2px 8px;
        background: #dc3545;
        color: white;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        margin-left: 8px;
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

    /* 圖片查看 Modal */
    #imageModal {
        display: none;
        position: fixed;
        z-index: 10000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.9);
        backdrop-filter: blur(4px);
        cursor: pointer;
        align-items: center;
        justify-content: center;
        flex-direction: column;
    }

    .modal-content-wrapper {
        display: flex;
        align-items: center;
        justify-content: center;
        pointer-events: none;
    }

    #imageModal img {
        margin: auto;
        display: block;
        width: auto;
        max-width: 90%;
        max-height: calc(90vh - 80px);
        object-fit: contain;
        animation: zoomIn 0.3s;
        cursor: default;
        pointer-events: auto;
    }

    .modal-close-btn {
        margin-top: 20px;
        padding: 10px 30px;
        background-color: rgba(255, 255, 255, 0.2);
        border: 2px solid rgba(255, 255, 255, 0.5);
        color: #fff;
        font-size: 16px;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.3s ease;
        z-index: 10001;
        position: relative;
    }

    .modal-close-btn:hover {
        background-color: rgba(255, 255, 255, 0.3);
        border-color: rgba(255, 255, 255, 0.8);
        transform: translateY(-2px);
    }

    @keyframes zoomIn {
        from {
            transform: scale(0.8);
            opacity: 0;
        }
        to {
            transform: scale(1);
            opacity: 1;
        }
    }
</style>

<div class="teacher-document-submissions-container">
    <div class="page-header">
        <h1 class="page-title">
            <i class="fa-solid fa-file-lines"></i>
            <span>查看文件繳交</span>
        </h1>
    </div>

    <div class="btn-return-list-container">
        <a href="pages/<?= ($role_ID == 3) ? 'class_teacher_document_submissions_list.php' : 'teacher_document_submissions_list.php' ?>" class="ajax-link btn-return-list">
            <i class="fa-solid fa-arrow-left"></i> 返回文件列表
            </a>
        </div>

    <!-- 文件資訊 -->
    <div class="team-section" style="margin-bottom: 30px;">
        <div class="team-header">
            <div class="team-title">
                <i class="fa-solid fa-file-alt"></i>
                <?= htmlspecialchars($selectedDocument['doc_name'] ?? '未知文件', ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="team-meta">
                <?php if (((int)($selectedDocument['is_required'] ?? 0)) === 1): ?>
                    <span style="background:#fff3cd;color:#856404;border-color:#ffc107;font-weight:700;font-size:15px !important;padding:8px 16px !important;">
                        <i class="fa-solid fa-exclamation-triangle"></i> 必繳文件
                    </span>
                <?php else: ?>
                    <span style="background:#d4edda;color:#155724;border-color:#28a745;">
                        <i class="fa-solid fa-check-circle"></i> 非必繳
                    </span>
    <?php endif; ?>
                <?php if (!empty($selectedDocument['doc_start_d']) && !empty($selectedDocument['doc_end_d'])): ?>
                    <span>
                        <i class="fa-solid fa-calendar"></i>
                        <?= htmlspecialchars(date('Y-m-d', strtotime($selectedDocument['doc_start_d'])), ENT_QUOTES, 'UTF-8') ?> ~ <?= htmlspecialchars(date('Y-m-d', strtotime($selectedDocument['doc_end_d'])), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($selectedDocument['doc_des'])): ?>
            <div style="padding: 15px 20px; background: #f8f9fa; border-radius: 8px; margin-top: 15px;">
                <p style="margin: 0; color: #495057; line-height: 1.6;"><?= htmlspecialchars($selectedDocument['doc_des'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php endif; ?>
    </div>

    <?php 
    // 如果是必填文件，收集未缴交的团队名称
    $notSubmittedTeams = [];
    if (($selectedDocument['is_required'] ?? 0) == 1 && !empty($rows)) {
        foreach ($rows as $r) {
            if (($r['status_text'] ?? '') === '未繳交') {
                $teamName = $r['team_project_name'] ?? '未命名團隊';
                $teamId = (int)($r['team_ID'] ?? 0);
                if ($teamName && $teamId > 0) {
                    $notSubmittedTeams[] = [
                        'name' => $teamName,
                        'id' => $teamId
                    ];
                }
            }
        }
    }
    ?>
    
    <?php if (!empty($notSubmittedTeams)): ?>
    <div class="team-section" style="margin-bottom: 18px; background: #fef3c7; border-left: 3px solid #fbbf24; padding: 15px 20px;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
            <i class="fa-solid fa-exclamation-triangle" style="color: #92400e; font-size: 18px;"></i>
            <span style="color: #92400e; font-weight: 600; font-size: 16px;">
                未繳交團隊提醒（共 <?= count($notSubmittedTeams) ?> 組）
            </span>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
            <?php foreach ($notSubmittedTeams as $team): ?>
                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: white; border: 1px solid #fcd34d; border-radius: 6px; color: #92400e; font-weight: 500; font-size: 14px;">
                    <i class="fa-solid fa-users" style="color: #d97706; font-size: 13px;"></i>
                    <?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?>
                    <span style="color: #9ca3af; font-size: 12px;">(ID: <?= $team['id'] ?>)</span>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-folder-open"></i>
            <h3>目前沒有團隊</h3>
            <p>您管理/指導的團隊中目前沒有需要顯示的資料</p>
        </div>
    <?php else: ?>
            <div class="team-section">
                <div class="team-header">
                    <div class="team-title">
                        <i class="fa-solid fa-users"></i>
                    團隊繳交狀況
                    </div>
                    <div class="team-meta">
                    <span style="background:#eef2ff;color:#4338ca;border-color:#c7d2fe;font-weight:700;">
                        <i class="fa-solid fa-list"></i> 共 <?= (int)$totalCount ?> 組
                    </span>
                    <span class="status-badge status-not-submitted">
                        未繳交 <?= (int)($statusCounts['未繳交'] ?? 0) ?>
                    </span>
                    <span class="status-badge status-pending">
                        審核中 <?= (int)($statusCounts['審核中'] ?? 0) ?>
                    </span>
                    <span class="status-badge status-approved">
                        已通過 <?= (int)($statusCounts['已通過'] ?? 0) ?>
                    </span>
                    <?php if (!empty($statusCounts['退件'])): ?>
                        <span class="status-badge status-rejected">
                            退件 <?= (int)($statusCounts['退件'] ?? 0) ?>
                        </span>
                    <?php endif; ?>
                    <span style="background:#f8f9fa;color:#495057;border-color:#e9ecef;font-weight:700;">
                        <i class="fa-solid fa-check"></i> 已繳交 <?= (int)$submittedCount ?> / <?= (int)$totalCount ?> 組
                    </span>
                    </div>
                </div>

            <div class="table-wrapper">
                    <table class="document-table">
                        <thead>
                            <tr>
                            <th>團隊</th>
                            <th>屆別</th>
                            <th>類組</th>
                            <th>狀態</th>
                            <th>繳交者</th>
                            <th>繳交時間</th>
                            <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $status = (string)($r['status_text'] ?? '其它');
                                $teamId = (int)($r['team_ID'] ?? 0);
                                $cohortName = $r['cohort_name'] ?? '-';
                                $groupName = $r['group_name'] ?? '-';

                                $sub = $r['submission'] ?? null;
                                $submitterName = $sub['submitter_name'] ?? '-';
                                $submittedAt = (!empty($sub['dcsub_sub_d'])) ? date('Y-m-d H:i', strtotime($sub['dcsub_sub_d'])) : '-';

                                $rowClass = '';
                                if ($status === '未繳交') $rowClass = 'row-not-submitted';
                                if ($status === '已通過') $rowClass = 'row-approved';

                                $badgeClass = 'status-not-submitted';
                                if ($status === '審核中') $badgeClass = 'status-pending';
                                if ($status === '已通過') $badgeClass = 'status-approved';
                                if ($status === '退件') $badgeClass = 'status-rejected';

                                $fileUrl = '';
                                $isImage = false;
                                if (!empty($sub['dcsub_url'])) {
                                    $rawUrl = $sub['dcsub_url'];
                                    if (!preg_match('/^(https?:\/\/|\/)/', $rawUrl)) {
                                        $fileUrl = '/' . ltrim($rawUrl, '/');
                                    } else {
                                        $fileUrl = $rawUrl;
                                    }
                                    $isImage = preg_match('/\.(jpg|jpeg|png)$/i', $rawUrl);
                                }

                                $teamName = htmlspecialchars($r['team_project_name'] ?? '', ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr class="<?= $rowClass ?>">
                                <td>
                                    <div style="font-weight:700; color:#2d3748;"><?= $teamName ?: '未命名團隊' ?></div>
                                            <div class="submitter-info">
                                        <i class="fa-solid fa-hashtag"></i> 團隊 ID：<?= $teamId ?>
                                            </div>
                                    </td>
                                <td><?= htmlspecialchars($cohortName ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($groupName ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="status-badge <?= $badgeClass ?>">
                                        <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    </td>
                                <td><?= htmlspecialchars($submitterName ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($submittedAt, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                    <?php if (!empty($fileUrl)): ?>
                                        <div class="action-links">
                                                <?php if ($isImage): ?>
                                                    <a href="<?= htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn-view" onclick="showImageModal(event, this.href); return false;">
                                                        <i class="fa-solid fa-eye"></i> 查看
                                                    </a>
                                                <?php else: ?>
                                                <a href="<?= htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn-view" rel="noopener">
                                                    <i class="fa-solid fa-eye"></i> 查看
                                                    </a>
                                                <?php endif; ?>
                                                <a href="<?= htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') ?>" download class="btn-download">
                                                    <i class="fa-solid fa-download"></i> 下載
                                                </a>
                                        </div>
                                            <?php else: ?>
                                        <span class="text-muted">-</span>
                                            <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- 圖片放大 Modal -->
<div id="imageModal" class="modal" onclick="closeImageModal()">
    <div class="modal-content-wrapper" onclick="event.stopPropagation();">
        <img id="modalImg">
    </div>
    <button type="button" class="modal-close-btn" onclick="event.stopPropagation(); closeImageModal();">
        <i class="fa-solid fa-times"></i> 關閉
    </button>
</div>

<script>
    // 圖片放大功能
    function showImageModal(event, src) {
        event.preventDefault();
        const modalImg = document.getElementById('modalImg');
        const imgModal = document.getElementById('imageModal');
        if (modalImg && imgModal) {
            modalImg.src = src;
            imgModal.style.display = 'flex';
        }
    }

    function closeImageModal() {
        const imgModal = document.getElementById('imageModal');
        if (imgModal) {
            imgModal.style.display = 'none';
        }
    }

    // 按 ESC 鍵關閉
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeImageModal();
        }
    });

    // 設置側邊欄高亮（與科辦端一致）- 確保在詳情頁也能正確顯示
    (function() {
        const currentPagePath = 'pages/teacher_document_submissions_list.php';
        
        function setSidebarActive() {
            // 檢查當前頁面路徑，只有匹配時才執行
            const currentHash = location.hash.slice(1);
            if (currentHash && !currentHash.includes('teacher_document_submissions') && !currentHash.includes('class_teacher_document_submissions')) {
                return; // 不是這個頁面，不執行
            }
            
            // 確保 jQuery 和 DOM 都準備好
            if (typeof jQuery === 'undefined') {
                setTimeout(setSidebarActive, 100);
                return;
            }
            
            jQuery(function($) {
                // 多次嘗試，確保側邊欄已經完全渲染
                let attempts = 0;
                const maxAttempts = 5;
                
                function trySetActive() {
                    attempts++;
                    
                    // 再次檢查當前頁面（防止在執行過程中頁面已切換）
                    const currentHash = location.hash.slice(1);
                    if (currentHash && !currentHash.includes('teacher_document_submissions') && !currentHash.includes('class_teacher_document_submissions')) {
                        return; // 不是這個頁面，不執行
                    }
                    
                    // 移除所有 active 狀態
                    $('#layoutSidenav_nav .ajax-link, #sidenavAccordion .ajax-link, .sb-sidenav .ajax-link, .sb-sidenav-menu .ajax-link').removeClass('active');
                    
                    // 根據角色設置對應的側邊欄項目為 active
                    const role_ID = <?= $role_ID ?? 0 ?>;
                    let targetLink = null;
                    
                    if (role_ID == 3) {
                        // 班導：高亮「查看班級文件繳交」
                        targetLink = $('.sb-sidenav .ajax-link[href="pages/class_teacher_document_submissions_list.php"], .sb-sidenav-menu .ajax-link[href="pages/class_teacher_document_submissions_list.php"]');
                    } else if (role_ID == 4) {
                        // 指導老師：高亮「查看文件繳交」
                        targetLink = $('.sb-sidenav .ajax-link[href="pages/teacher_document_submissions_list.php"], .sb-sidenav-menu .ajax-link[href="pages/teacher_document_submissions_list.php"]');
                    }
                    
                    if (targetLink && targetLink.length > 0) {
                        targetLink.addClass('active');
                        
                        // 如果是在子選單中，確保父選單也是展開狀態
                        const parentSubmenu = targetLink.closest('.dropdown-submenu');
                        if (parentSubmenu.length > 0) {
                            parentSubmenu.addClass('active');
                        }
                    } else if (attempts < maxAttempts) {
                        // 如果找不到，再試一次
                        setTimeout(trySetActive, 200);
                    }
                }
                
                // 等待一下確保側邊欄已經渲染
                setTimeout(trySetActive, 150);
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
        
        // 監聽 hash 變化，確保在頁面切換時也能正確設置
        window.addEventListener('hashchange', function() {
            setTimeout(setSidebarActive, 200);
        });
    })();
</script>

