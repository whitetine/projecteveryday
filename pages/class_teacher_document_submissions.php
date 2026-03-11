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

// 檢查是否指定了文件ID或表單ID
$selectedDocId = isset($_GET['doc_ID']) ? (int)$_GET['doc_ID'] : null;
$selectedFormId = isset($_GET['form_ID']) ? (int)$_GET['form_ID'] : null;
$isForm = $selectedFormId !== null;
$itemType = $isForm ? 'form' : 'document';

if ($selectedDocId === null && $selectedFormId === null) {
    echo '<div class="alert alert-danger">請選擇要查看的文件或表單</div>';
    exit;
}

// 獲取指定文件或表單的詳細資訊
$selectedDocument = null;
if ($isForm) {
    // 查詢表單
    try {
        $stmt = $conn->prepare("
            SELECT 
                f.form_ID as doc_ID,
                f.form_name as doc_name,
                f.form_des as doc_des,
                0 as is_required,
                f.form_start_d as doc_start_d,
                f.form_end_d as doc_end_d,
                f.form_status as doc_status
            FROM formdata f
            WHERE f.form_ID = ? AND f.form_status = 1
        ");
        $stmt->execute([$selectedFormId]);
        $selectedDocument = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$selectedDocument) {
            echo '<div class="alert alert-danger">找不到指定的表單或表單已停用</div>';
            exit;
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">載入表單資訊失敗</div>';
        exit;
    }
} else {
    // 查詢文件
    try {
        $stmt = $conn->prepare("
            SELECT 
                d.doc_ID,
                d.doc_name,
                d.doc_des,
                d.is_required,
                d.doc_start_d,
                d.doc_end_d,
                d.doc_status
            FROM docdata d
            WHERE d.doc_ID = ? AND d.doc_status = 1
        ");
        $stmt->execute([$selectedDocId]);
        $selectedDocument = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$selectedDocument) {
            echo '<div class="alert alert-danger">找不到指定的文件或文件已停用</div>';
            exit;
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">載入文件資訊失敗</div>';
        exit;
    }
}

// 獲取該文件或表單的目標對象（類組和ALL）
$docTargets = [];
$docTargetAll = false;
$formCohortFrom = null;
$formCohortTo = null;
if ($isForm) {
    // 表單目標對象
    try {
        $stmt = $conn->prepare("
            SELECT ft_group, ft_cohort_from, ft_cohort_to
            FROM formtargetdata
            WHERE form_ID = ?
        ");
        $stmt->execute([$selectedFormId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($target) {
            if (empty($target['ft_group'])) {
                $docTargetAll = true;
            } else {
                $docTargets = explode(',', str_replace(' ', '', $target['ft_group']));
            }
            $formCohortFrom = $target['ft_cohort_from'];
            $formCohortTo = $target['ft_cohort_to'];
        } else {
            $docTargetAll = true; // 沒有設定目標，視為適用於所有類組
        }
    } catch (Exception $e) {
        $docTargetAll = true;
    }
} else {
    // 文件目標對象
    try {
        $stmt = $conn->prepare("
            SELECT doc_ID, doc_target_type, doc_target_ID
            FROM doctargetdata
            WHERE doc_ID = ?
        ");
        $stmt->execute([$selectedDocId]);
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($targets as $target) {
            if ($target['doc_target_type'] === 'ALL') {
                $docTargetAll = true;
            } elseif ($target['doc_target_type'] === 'GROUP') {
                $docTargets[] = $target['doc_target_ID'];
            }
        }
    } catch (Exception $e) {
        // 忽略錯誤
    }
}

// 判斷文件或表單是否適用於該團隊的類組
function isDocumentApplicable($teamGroupId, $teamCohortId, $docTargets, $docTargetAll, $isForm = false, $cohortFrom = null, $cohortTo = null)
{
    // 如果設定了 ALL，適用於所有類組
    if ($docTargetAll) {
        // 如果是表單，還需要檢查屆別範圍
        if ($isForm) {
            if ($cohortFrom !== null && $teamCohortId < $cohortFrom) {
                return false;
            }
            if ($cohortTo !== null && $teamCohortId > $cohortTo) {
                return false;
            }
        }
        return true;
    }

    // 如果沒有設定類組目標，則適用於所有類組
    if (empty($docTargets)) {
        // 如果是表單，還需要檢查屆別範圍
        if ($isForm) {
            if ($cohortFrom !== null && $teamCohortId < $cohortFrom) {
                return false;
            }
            if ($cohortTo !== null && $teamCohortId > $cohortTo) {
                return false;
            }
        }
        return true;
    }

    // 如果設定了類組目標，檢查團隊的類組是否在目標中
    $groupMatch = in_array((string)$teamGroupId, $docTargets);

    // 如果是表單，還需要檢查屆別範圍
    if ($isForm && $groupMatch) {
        if ($cohortFrom !== null && $teamCohortId < $cohortFrom) {
            return false;
        }
        if ($cohortTo !== null && $teamCohortId > $cohortTo) {
            return false;
        }
    }

    return $groupMatch;
}

// 獲取這些團隊的詳細資訊（包含班級資訊）
$teams = [];
if (!empty($teamIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $stmt = $conn->prepare("
            SELECT 
                t.team_ID,
                t.team_project_name,
                t.cohort_ID,
                t.group_ID,
                c.cohort_name,
                g.group_name,
                MIN(e.class_ID) as class_ID,
                MIN(cl.class_name) as class_name
            FROM teamdata t
            JOIN teammember tm ON tm.team_ID = t.team_ID
            JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField} AND e.enroll_status = 1
            LEFT JOIN classdata cl ON e.class_ID = cl.class_ID
            LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
            LEFT JOIN groupdata g ON t.group_ID = g.group_ID
            WHERE t.team_ID IN ($placeholders)
              AND t.team_status = 1
              AND e.class_ID IN (" . implode(',', array_fill(0, count($classIds), '?')) . ")
            GROUP BY t.team_ID, t.team_project_name, t.cohort_ID, t.group_ID, c.cohort_name, g.group_name
            ORDER BY MIN(e.class_ID), t.cohort_ID DESC, t.team_ID
        ");
        $stmt->execute(array_merge($teamIds, $classIds));
        $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $teams = [];
    }
}

// 篩選出適用的團隊
$applicableTeams = [];
foreach ($teams as $team) {
    $teamGroupId = $team['group_ID'];
    $teamCohortId = $team['cohort_ID'];
    if (isDocumentApplicable($teamGroupId, $teamCohortId, $docTargets, $docTargetAll, $isForm, $formCohortFrom, $formCohortTo)) {
        $applicableTeams[] = $team;
    }
}

// 如果沒有找到適用團隊，但班導有管理的團隊，則顯示所有管理的團隊（以便查看提交記錄）
if (empty($applicableTeams) && !empty($teams)) {
    $applicableTeams = $teams;
}

// 獲取該文件或表單的所有繳交記錄
$teamSubmissions = [];
$rows = []; // 初始化 $rows 變數

// 獲取所有班導管理的班級中的團隊ID（用於查詢提交記錄）
$allManagedTeamIds = [];
if (!empty($teamIds)) {
    $allManagedTeamIds = $teamIds;
}

// 獲取這些團隊的所有成員ID（用於通過學生ID查詢提交記錄）
$teamMemberIds = [];
$userToTeam = [];
if (!empty($allManagedTeamIds)) {
    try {
        $memberPlaceholders = implode(',', array_fill(0, count($allManagedTeamIds), '?'));
        $memberStmt = $conn->prepare("
            SELECT DISTINCT tm.{$teamUserField} as u_ID, tm.team_ID
            FROM teammember tm
            WHERE tm.team_ID IN ($memberPlaceholders) AND tm.tm_status = 1
        ");
        $memberStmt->execute($allManagedTeamIds);
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

        // 組織成員ID到團隊ID的映射
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
}

if ($isForm) {
    // 查詢表單提交記錄
    try {
        // 檢查表是否有審核欄位
        $stmt = $conn->query("SHOW COLUMNS FROM formsubdata LIKE 'fs_review_status'");
        $hasReviewStatus = $stmt->fetch() !== false;

        // 構建查詢條件：通過團隊ID或學生ID查詢
        $conditions = ["fs.form_ID = ?"];
        $params = [$selectedFormId];

        if (!empty($allManagedTeamIds)) {
            $teamPlaceholders = implode(',', array_fill(0, count($allManagedTeamIds), '?'));
            $conditions[] = "fs.fs_team_ID IN ($teamPlaceholders)";
            $params = array_merge($params, $allManagedTeamIds);
        }

        if (!empty($teamMemberIds)) {
            $memberPlaceholders = implode(',', array_fill(0, count($teamMemberIds), '?'));
            $conditions[] = "fs.fs_u_ID IN ($memberPlaceholders)";
            $params = array_merge($params, $teamMemberIds);
        }

        $whereClause = implode(' OR ', $conditions);

        $stmt = $conn->prepare("
            SELECT 
                fs.fs_ID as sub_ID,
                fs.form_ID as doc_ID,
                fs.fs_team_ID as dcsub_team_ID,
                fs.fs_u_ID as dcsub_u_ID,
                fs.fs_remark as dcsub_comment,
                fs.fs_submitted_d as dcsub_sub_d,
                fs.fs_reviewed_u_ID as dc_approved_u_ID,
                fs.fs_reviewed_d as dcsub_approved_d,
                fs.fs_review_remark as dcsub_remark,
                fs.fs_docsub_ID,
                " . ($hasReviewStatus ? "COALESCE(fs.fs_review_status, 0) as dcsub_status" : "0 as dcsub_status") . ",
                u.u_name as submitter_name,
                ds.dcsub_url
            FROM formsubdata fs
            LEFT JOIN userdata u ON fs.fs_u_ID = u.u_ID
            LEFT JOIN docsubdata ds ON fs.fs_docsub_ID = ds.sub_ID
            WHERE ($whereClause)
              AND fs.fs_status = 0
            ORDER BY fs.fs_submitted_d DESC
        ");
        $stmt->execute($params);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 按團隊ID組織資料（每個團隊只保留最新的提交記錄）
        foreach ($submissions as $sub) {
            $teamId = $sub['dcsub_team_ID'];
            $userId = $sub['dcsub_u_ID'];

            // 如果 fs_team_ID 為 NULL，通過學生ID查找團隊
            if (empty($teamId) && !empty($userId) && isset($userToTeam[$userId])) {
                foreach ($userToTeam[$userId] as $tId) {
                    if (in_array($tId, $allManagedTeamIds)) {
                        if (
                            !isset($teamSubmissions[$tId]) ||
                            strtotime($sub['dcsub_sub_d'] ?? '1970-01-01') > strtotime($teamSubmissions[$tId]['dcsub_sub_d'] ?? '1970-01-01')
                        ) {
                            $teamSubmissions[$tId] = $sub;
                        }
                    }
                }
            } elseif (!empty($teamId) && in_array($teamId, $allManagedTeamIds)) {
                if (
                    !isset($teamSubmissions[$teamId]) ||
                    strtotime($sub['dcsub_sub_d'] ?? '1970-01-01') > strtotime($teamSubmissions[$teamId]['dcsub_sub_d'] ?? '1970-01-01')
                ) {
                    $teamSubmissions[$teamId] = $sub;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching form submissions: " . $e->getMessage());
    }
} else {
    // 查詢文件繳交記錄
    // 邏輯：以 doc_ID 為主軸，先列出所有團隊，再從 docsubdata 查出提交記錄並關聯
    $u_ID  = $_SESSION['u_ID'];
    $doc_ID = (int)$_GET['doc_ID'];
    try {
        // ① 先查出這個班導對應的 class_ID
        $sql = "SELECT class_ID
                FROM enrollmentdata
                WHERE enroll_u_ID = ?
                  AND role_ID = 3
                  AND enroll_status = 1
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$u_ID]);
        $class_ID = $stmt->fetchColumn();

        // 找不到班級就不用查下去了，但不要讓程式炸掉
        if ($class_ID === false || $class_ID === null) {
            $class_ID = 0; // 或者直接 return / exit 都可以
        }

        // ② 查團隊 + 最新一筆 docsubdata（含只有 dcsub_u_ID 的情況）
        $sql = "SELECT
            t.team_ID,
            t.team_project_name,
            ds.sub_ID,
            ds.doc_ID,
            ds.dcsub_status,
            ds.dcsub_u_ID,
            ds.dcsub_sub_d,
            ds.dcsub_url,
            u.u_name AS submitter_name,
            CASE
                WHEN ds.sub_ID IS NULL   THEN '未繳交'
                WHEN ds.dcsub_status = 0 THEN '審核中'
                WHEN ds.dcsub_status = 1 THEN '已通過'
                ELSE '其它'
            END AS status_text,
            CASE
                WHEN ds.sub_ID IS NULL   THEN 0
                WHEN ds.dcsub_status = 0 THEN 1
                WHEN ds.dcsub_status = 1 THEN 2
                ELSE 3
            END AS sort_order
        FROM (
            SELECT DISTINCT tm.team_ID
            FROM enrollmentdata AS e
            JOIN teammember AS tm
                ON tm.team_u_ID = e.enroll_u_ID
            WHERE e.class_ID      = " . $class_ID . "
              AND e.role_ID       = 6
              AND e.enroll_status = 1
        ) AS c
        JOIN teamdata AS t
            ON t.team_ID = c.team_ID
        /* 這裡開始改：用 dcsub_u_ID 取每個學生最新一筆上傳 */
        LEFT JOIN (
            SELECT d1.*
            FROM docsubdata AS d1
            JOIN (
                SELECT doc_ID, dcsub_u_ID, MAX(sub_ID) AS max_sub_ID
                FROM docsubdata
                WHERE doc_ID = " . $doc_ID . "
                GROUP BY doc_ID, dcsub_u_ID
            ) AS x
              ON x.doc_ID      = d1.doc_ID
             AND x.dcsub_u_ID  = d1.dcsub_u_ID
             AND x.max_sub_ID  = d1.sub_ID
        ) AS ds
            /* 先試著用 dcsub_team_ID 直接對 team_ID
               若 dcsub_team_ID 為 NULL，就用 dcsub_u_ID 去 teammember 找 team */
            ON ds.dcsub_team_ID = t.team_ID
               OR EXISTS (
                    SELECT 1
                    FROM teammember AS tm2
                    WHERE tm2.team_ID   = t.team_ID
                      AND tm2.team_u_ID = ds.dcsub_u_ID
                      AND tm2.tm_status = 1
               )
        LEFT JOIN userdata u
            ON u.u_ID = ds.dcsub_u_ID
        ORDER BY sort_order, t.team_project_name";
        $rows = fetchAll(query($sql));
        
        // 如果是非必繳文件，只顯示有繳交的團隊
        if (($selectedDocument['is_required'] ?? 0) != 1) {
            $rows = array_filter($rows, function($row) {
                return !empty($row['sub_ID']);
            });
            // 重新索引数组
            $rows = array_values($rows);
        }
    } catch (Throwable $e) {
        // 先記 log，下次再慢慢調
        error_log('Doc submissions query error: ' . $e->getMessage());
    }
}

// team_ID -> 屆別/類組資訊（用於顯示）
$teamInfoMap = [];
$rowTeamIds = [];
if (!empty($rows) && is_array($rows)) {
    foreach ($rows as $r) {
        if (isset($r['team_ID'])) {
            $rowTeamIds[] = (int)$r['team_ID'];
        }
    }
    $rowTeamIds = array_values(array_unique(array_filter($rowTeamIds)));
}

if (!empty($rowTeamIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($rowTeamIds), '?'));
        $stmt = $conn->prepare("
            SELECT
                t.team_ID,
                c.cohort_name,
                g.group_name
            FROM teamdata t
            LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
            LEFT JOIN groupdata g ON t.group_ID = g.group_ID
            WHERE t.team_ID IN ($placeholders)
        ");
        $stmt->execute($rowTeamIds);
        $metaRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($metaRows as $m) {
            if (!empty($m['team_ID'])) {
                $teamInfoMap[(int)$m['team_ID']] = $m;
            }
        }
    } catch (Exception $e) {
        $teamInfoMap = [];
    }
}

// 統計用
$statusCounts = [
    '未繳交' => 0,
    '審核中' => 0,
    '已通過' => 0,
    '其它' => 0
];
foreach (($rows ?? []) as $r) {
    $s = $r['status_text'] ?? '其它';
    if (!isset($statusCounts[$s])) $s = '其它';
    $statusCounts[$s]++;
}
$totalCount = is_array($rows ?? null) ? count($rows) : 0;
$submittedCount = $totalCount - ($statusCounts['未繳交'] ?? 0);


// 如果沒有找到適用團隊，但有提交記錄，則從提交記錄中獲取團隊信息
// 或者如果提交記錄中有團隊不在適用團隊列表中，也要獲取這些團隊的信息
$submissionTeamIds = array_keys($teamSubmissions);
if (!empty($submissionTeamIds)) {
    // 獲取提交記錄中所有團隊的ID（包括不在適用團隊列表中的）
    $allSubmissionTeamIds = array_unique($submissionTeamIds);
    $existingTeamIds = array_column($applicableTeams, 'team_ID');
    $missingTeamIds = array_diff($allSubmissionTeamIds, $existingTeamIds);

    if (!empty($missingTeamIds)) {
        try {
            $missingPlaceholders = implode(',', array_fill(0, count($missingTeamIds), '?'));
            $stmt = $conn->prepare("
                SELECT 
                    t.team_ID,
                    t.team_project_name,
                    t.cohort_ID,
                    t.group_ID,
                    c.cohort_name,
                    g.group_name,
                    MIN(e.class_ID) as class_ID,
                    MIN(cl.class_name) as class_name
                FROM teamdata t
                LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                LEFT JOIN teammember tm ON tm.team_ID = t.team_ID
                LEFT JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField} AND e.enroll_status = 1
                LEFT JOIN classdata cl ON e.class_ID = cl.class_ID
                WHERE t.team_ID IN ($missingPlaceholders)
                  AND t.team_status = 1
                  AND e.class_ID IN (" . implode(',', array_fill(0, count($classIds), '?')) . ")
                GROUP BY t.team_ID, t.team_project_name, t.cohort_ID, t.group_ID, c.cohort_name, g.group_name
                ORDER BY MIN(e.class_ID), t.cohort_ID DESC, t.team_ID
            ");
            $stmt->execute(array_merge($missingTeamIds, $classIds));
            $missingTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // 將缺失的團隊添加到適用團隊列表中
            $applicableTeams = array_merge($applicableTeams, $missingTeams);
        } catch (Exception $e) {
            error_log("Error fetching missing teams from submissions: " . $e->getMessage());
        }
    }

    // 如果完全沒有適用團隊，但有提交記錄，則從提交記錄中獲取所有團隊信息
    if (empty($applicableTeams) && !empty($submissionTeamIds) && !empty($classIds)) {
        try {
            $submissionPlaceholders = implode(',', array_fill(0, count($submissionTeamIds), '?'));
            $stmt = $conn->prepare("
                SELECT 
                    t.team_ID,
                    t.team_project_name,
                    t.cohort_ID,
                    t.group_ID,
                    c.cohort_name,
                    g.group_name,
                    MIN(e.class_ID) as class_ID,
                    MIN(cl.class_name) as class_name
                FROM teamdata t
                LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                LEFT JOIN teammember tm ON tm.team_ID = t.team_ID
                LEFT JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField} AND e.enroll_status = 1
                LEFT JOIN classdata cl ON e.class_ID = cl.class_ID
                WHERE t.team_ID IN ($submissionPlaceholders)
                  AND t.team_status = 1
                  AND e.class_ID IN (" . implode(',', array_fill(0, count($classIds), '?')) . ")
                GROUP BY t.team_ID, t.team_project_name, t.cohort_ID, t.group_ID, c.cohort_name, g.group_name
                ORDER BY MIN(e.class_ID), t.cohort_ID DESC, t.team_ID
            ");
            $stmt->execute(array_merge($submissionTeamIds, $classIds));
            $applicableTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Fetched " . count($applicableTeams) . " teams from submissions");
        } catch (Exception $e) {
            error_log("Error fetching teams from submissions: " . $e->getMessage());
        }
    }
}

// 調試：記錄當前狀態
error_log("=== Class Teacher Document Submissions Debug ===");
error_log("Class IDs: " . implode(',', $classIds));
error_log("Applicable teams count: " . count($applicableTeams));
error_log("Team submissions count: " . count($teamSubmissions));
error_log("Team submissions keys: " . implode(',', array_keys($teamSubmissions)));

// 如果有提交記錄，檢查是否有團隊不在適用團隊列表中，需要補充這些團隊
if (!empty($teamSubmissions)) {
    $submissionTeamIds = array_keys($teamSubmissions);
    $existingTeamIds = array_column($applicableTeams, 'team_ID');
    $missingTeamIds = array_diff($submissionTeamIds, $existingTeamIds);

    error_log("Submission team IDs: " . implode(',', $submissionTeamIds));
    error_log("Existing team IDs: " . implode(',', $existingTeamIds));
    error_log("Missing team IDs: " . implode(',', $missingTeamIds));

    // 如果有團隊不在適用團隊列表中，補充這些團隊
    if (!empty($missingTeamIds)) {
        try {
            $missingPlaceholders = implode(',', array_fill(0, count($missingTeamIds), '?'));

            // 如果有班級ID，則查詢屬於這些班級的團隊
            if (!empty($classIds)) {
                $stmt = $conn->prepare("
                    SELECT 
                        t.team_ID,
                        t.team_project_name,
                        t.cohort_ID,
                        t.group_ID,
                        c.cohort_name,
                        g.group_name,
                        MIN(e.class_ID) as class_ID,
                        MIN(cl.class_name) as class_name
                    FROM teamdata t
                    LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                    LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                    LEFT JOIN teammember tm ON tm.team_ID = t.team_ID
                    LEFT JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField} AND e.enroll_status = 1
                    LEFT JOIN classdata cl ON e.class_ID = cl.class_ID
                    WHERE t.team_ID IN ($missingPlaceholders)
                      AND t.team_status = 1
                      AND e.class_ID IN (" . implode(',', array_fill(0, count($classIds), '?')) . ")
                    GROUP BY t.team_ID, t.team_project_name, t.cohort_ID, t.group_ID, c.cohort_name, g.group_name
                    ORDER BY MIN(e.class_ID), t.cohort_ID DESC, t.team_ID
                ");
                $stmt->execute(array_merge($missingTeamIds, $classIds));
            } else {
                // 如果沒有班級ID，至少查詢團隊基本信息
                $stmt = $conn->prepare("
                    SELECT 
                        t.team_ID,
                        t.team_project_name,
                        t.cohort_ID,
                        t.group_ID,
                        c.cohort_name,
                        g.group_name,
                        NULL as class_ID,
                        '未分類' as class_name
                    FROM teamdata t
                    LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                    LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                    WHERE t.team_ID IN ($missingPlaceholders)
                      AND t.team_status = 1
                    ORDER BY t.cohort_ID DESC, t.team_ID
                ");
                $stmt->execute($missingTeamIds);
            }

            $fetchedTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $applicableTeams = array_merge($applicableTeams, $fetchedTeams);
            error_log("Added " . count($fetchedTeams) . " missing teams from submission team IDs");
        } catch (Exception $e) {
            error_log("Error fetching missing teams from submission team IDs: " . $e->getMessage());
        }
    }
}

// 如果完全沒有適用團隊，但有提交記錄，則從提交記錄中獲取所有團隊信息
if (empty($applicableTeams) && !empty($teamSubmissions)) {
    $submissionTeamIds = array_keys($teamSubmissions);

    if (!empty($submissionTeamIds)) {
        try {
            $submissionPlaceholders = implode(',', array_fill(0, count($submissionTeamIds), '?'));

            // 如果有班級ID，則查詢屬於這些班級的團隊
            if (!empty($classIds)) {
                $stmt = $conn->prepare("
                    SELECT 
                        t.team_ID,
                        t.team_project_name,
                        t.cohort_ID,
                        t.group_ID,
                        c.cohort_name,
                        g.group_name,
                        MIN(e.class_ID) as class_ID,
                        MIN(cl.class_name) as class_name
                    FROM teamdata t
                    LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                    LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                    LEFT JOIN teammember tm ON tm.team_ID = t.team_ID
                    LEFT JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField} AND e.enroll_status = 1
                    LEFT JOIN classdata cl ON e.class_ID = cl.class_ID
                    WHERE t.team_ID IN ($submissionPlaceholders)
                      AND t.team_status = 1
                      AND e.class_ID IN (" . implode(',', array_fill(0, count($classIds), '?')) . ")
                    GROUP BY t.team_ID, t.team_project_name, t.cohort_ID, t.group_ID, c.cohort_name, g.group_name
                    ORDER BY MIN(e.class_ID), t.cohort_ID DESC, t.team_ID
                ");
                $stmt->execute(array_merge($submissionTeamIds, $classIds));
            } else {
                // 如果沒有班級ID，至少查詢團隊基本信息
                $stmt = $conn->prepare("
                    SELECT 
                        t.team_ID,
                        t.team_project_name,
                        t.cohort_ID,
                        t.group_ID,
                        c.cohort_name,
                        g.group_name,
                        NULL as class_ID,
                        '未分類' as class_name
                    FROM teamdata t
                    LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                    LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                    WHERE t.team_ID IN ($submissionPlaceholders)
                      AND t.team_status = 1
                    ORDER BY t.cohort_ID DESC, t.team_ID
                ");
                $stmt->execute($submissionTeamIds);
            }

            $fetchedTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $applicableTeams = $fetchedTeams;
            error_log("Fetched " . count($applicableTeams) . " teams from submission team IDs (no applicable teams)");
        } catch (Exception $e) {
            error_log("Error fetching teams from submission team IDs: " . $e->getMessage());
        }
    }
}

// 按班級分組組織資料
$teamsByClass = [];
foreach ($applicableTeams as $team) {
    $classId = $team['class_ID'] ?? 0;
    $className = $team['class_name'] ?? '未分類';

    if (!isset($teamsByClass[$classId])) {
        $teamsByClass[$classId] = [
            'class_ID' => $classId,
            'class_name' => $className,
            'teams' => []
        ];
    }

    $teamId = $team['team_ID'];
    $submission = $teamSubmissions[$teamId] ?? null;

    $teamsByClass[$classId]['teams'][] = [
        'team_info' => $team,
        'submission' => $submission
    ];
}

// 對每個班級的團隊進行排序：未繳交排最前、已繳交排後、已通過排最尾
foreach ($teamsByClass as $classId => &$classData) {
    usort($classData['teams'], function ($a, $b) {
        $submissionA = $a['submission'];
        $submissionB = $b['submission'];

        $hasA = $submissionA !== null;
        $hasB = $submissionB !== null;

        // 未繳交排最前
        if (!$hasA && $hasB) return -1;
        if ($hasA && !$hasB) return 1;

        // 如果都未繳交或都已繳交，比較狀態
        if ($hasA && $hasB) {
            $statusA = (int)($submissionA['dcsub_status'] ?? 0);
            $statusB = (int)($submissionB['dcsub_status'] ?? 0);

            // 已通過（status = 1）排最尾
            if ($statusA === 1 && $statusB !== 1) return 1;
            if ($statusA !== 1 && $statusB === 1) return -1;

            // 如果狀態相同，按時間排序（新的在前）
            $timeA = strtotime($submissionA['dcsub_sub_d'] ?? '1970-01-01');
            $timeB = strtotime($submissionB['dcsub_sub_d'] ?? '1970-01-01');
            return $timeB - $timeA;
        }

        // 都未繳交，按團隊ID排序
        return $a['team_info']['team_ID'] - $b['team_info']['team_ID'];
    });
}
unset($classData); // 解除引用

// 調試：記錄最終結果
error_log("Teams by class count: " . count($teamsByClass));
foreach ($teamsByClass as $classId => $classData) {
    error_log("Class {$classId} ({$classData['class_name']}): " . count($classData['teams']) . " teams");
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
    .teacher-document-submissions-container * {
        -webkit-transform: none !important;
        transform: none !important;
        zoom: 1 !important;
    }

    /* 允許特定動畫元素 */
    .team-section:hover,
    .document-table tbody tr:hover {
        transform: translateY(-2px) !important;
    }

    .document-table tbody tr:hover {
        transform: translateX(2px) !important;
    }

    .teacher-document-submissions-container {
        padding: 0 30px 30px 30px !important;
        /* 與內容區域一致，讓子元素寬度靠齊 */
        max-width: 100% !important;
        /* 移除最大寬度限制 */
        width: 100%;
        margin: 0 !important;
        /* 移除居中對齊 */
        box-sizing: border-box;
        overflow-x: auto;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        min-height: 100vh;
        min-width: 1200px;
        /* 設置最小寬度防止過度壓縮 */
        position: relative;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        margin-bottom: 2rem;
        padding: 1.5rem 0;
        background: transparent !important;
        border-radius: 0;
        box-shadow: none;
        position: relative;
        overflow: visible;
        text-align: left;
        z-index: 20;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        min-width: 0;
        flex-shrink: 0;
        height: auto !important;
        min-height: auto !important;
        max-height: none !important;
        border-bottom: 3px solid #ffc107;
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
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 12px;
        padding: 0 !important;
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1) !important;
        position: relative;
        z-index: 1;
        white-space: nowrap;
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

    .team-section {
        background: white;
        border-radius: 16px !important;
        padding: 30px;
        margin: 0 0 30px 0 !important;
        /* 用父容器內邊距控制左右對齊 */
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        animation: fadeInUp 0.5s ease-out;
        min-width: 0;
        /* 允許 flex 子元素縮小 */
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        /* 確保 box-sizing 一致 */
        overflow-x: auto;
    }

    /* 表格包裝容器，確保表格可以橫向滾動 */
    .table-wrapper {
        overflow-x: auto;
        overflow-y: visible;
        -webkit-overflow-scrolling: touch;
        margin-top: 20px;
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
        padding: 20px !important;
        /* 固定內邊距 */
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 12px;
        border: none;
        flex-wrap: nowrap;
        /* 防止換行 */
        gap: 15px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        min-width: 0;
        /* 允許 flex 子元素縮小 */
        min-height: 80px !important;
        /* 固定最小高度 */
        height: auto;
    }

    .team-title {
        font-size: 26px !important;
        /* 固定字體大小 */
        font-weight: 700 !important;
        color: #2d3748;
        display: flex;
        align-items: center;
        gap: 12px;
        white-space: nowrap;
        /* 防止標題換行 */
        flex-shrink: 0;
        /* 防止標題被壓縮 */
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
        line-height: 1.4 !important;
        /* 固定行高 */
    }

    .team-title i {
        color: #667eea;
        font-size: 24px !important;
        /* 固定圖標大小 */
        width: 24px !important;
        height: 24px !important;
        flex-shrink: 0;
        /* 防止圖標被壓縮 */
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .team-meta {
        display: flex;
        gap: 20px;
        flex-wrap: nowrap;
        /* 防止標籤換行 */
        font-size: 17px;
        flex-shrink: 0;
        /* 防止標籤區域被壓縮 */
    }

    .team-meta span {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px !important;
        /* 固定內邊距 */
        background: white;
        border-radius: 20px;
        color: #667eea;
        font-weight: 500;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        white-space: nowrap;
        /* 防止標籤文字換行 */
        flex-shrink: 0;
        /* 防止標籤被壓縮 */
        font-size: 17px !important;
        /* 固定字體大小 */
        height: 40px !important;
        /* 固定高度 */
    }

    .team-meta span i {
        color: #764ba2;
        font-size: 14px !important;
        /* 固定圖標大小 */
        width: 14px !important;
        height: 14px !important;
        flex-shrink: 0;
        /* 防止圖標被壓縮 */
    }

    /* 表格包裝容器，確保表格可以橫向滾動 */
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

    /* 必填文件未缴交的团队行 - 浅红色背景 */
    .document-table tbody tr.row-not-submitted {
        background: #fff5f5 !important;
    }

    .document-table tbody tr.row-not-submitted:hover {
        background: #fff8f8 !important;
    }

    /* 已通过的团队行 - 浅绿色背景 */
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
        /* 防止按鈕換行 */
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
        font-size: 16px;
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

    /* 返回列表按鈕固定樣式 */
    .btn-return-list {
        display: inline-flex !important;
        align-items: center;
        gap: 10px;
        color: #667eea;
        text-decoration: none;
        font-weight: 600 !important;
        padding: 10px 20px !important;
        /* 固定內邊距 */
        background: white;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(102, 126, 234, 0.15);
        transition: all 0.3s ease;
        font-size: 15px !important;
        /* 固定字體大小 */
        white-space: nowrap;
        /* 防止文字換行 */
        height: 40px !important;
        /* 固定高度 */
        min-width: 120px !important;
        /* 固定最小寬度 */
    }

    /* 返回列表按鈕容器 */
    .btn-return-list-container {
        margin: 25px 30px !important;
        /* 與內容區域對齊 */
        margin-top: 25px !important;
        margin-bottom: 25px !important;
    }

    .btn-return-list i {
        font-size: 14px !important;
        /* 固定圖標大小 */
        width: 14px !important;
        height: 14px !important;
        flex-shrink: 0;
    }

    .btn-return-list:hover {
        transform: translateX(-3px) !important;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.25);
        color: #5568d3;
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
    <div class="btn-return-list-container">
        <a href="pages/class_teacher_document_submissions_list.php" class="ajax-link btn-return-list">
            <i class="fa-solid fa-arrow-left"></i> 返回列表
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
                <?php if (($selectedDocument['is_required'] ?? 0) == 1): ?>
                    <span style="background: #fff3cd; color: #856404; border-color: #ffc107; font-weight: 700; font-size: 15px !important; padding: 8px 16px !important;">
                        <i class="fa-solid fa-exclamation-triangle"></i> 必繳文件
                    </span>
                <?php else: ?>
                    <span style="background: #d4edda; color: #155724; border-color: #28a745;">
                        <i class="fa-solid fa-check-circle"></i> <?= $isForm ? '表單' : '非必繳' ?>
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

    <?php if (empty($rows)): ?>
    <div class="empty-state">
        <i class="fa-solid fa-folder-open"></i>
        <h3>目前沒有團隊</h3>
        <p>您管理的班級中目前沒有適用此文件的團隊</p>
    </div>
<?php else: ?>
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
                            $status = $r['status_text'] ?? '其它';
                            $teamId = (int)($r['team_ID'] ?? 0);
                            $teamInfo = $teamInfoMap[$teamId] ?? null;
                            $cohortName = $teamInfo['cohort_name'] ?? '-';
                            $groupName = $teamInfo['group_name'] ?? '-';
                            $submitterName = $r['submitter_name'] ?? '-';
                            $submittedAt = !empty($r['dcsub_sub_d']) ? date('Y-m-d H:i', strtotime($r['dcsub_sub_d'])) : '-';
                            $fileUrl = $r['dcsub_url'] ?? '';

                            $rowClass = '';
                            if ($status === '未繳交') $rowClass = 'row-not-submitted';
                            if ($status === '已通過') $rowClass = 'row-approved';

                            $badgeClass = 'status-not-submitted';
                            if ($status === '審核中') $badgeClass = 'status-pending';
                            if ($status === '已通過') $badgeClass = 'status-approved';

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
                                        <a class="btn-view" href="<?= htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                                            <i class="fa-solid fa-eye"></i> 查看
                                        </a>
                                        <a class="btn-download" href="<?= htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') ?>" download>
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
        function setSidebarActive() {
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