<?php
session_start();
require '../includes/pdo.php';

header('Content-Type: application/json; charset=utf-8');

// 檢查權限
$role_ID = $_SESSION['role_ID'] ?? null;
if (!isset($role_ID) || !in_array($role_ID, [1, 2])) {
    echo json_encode([
        "success" => false,
        "message" => "無權限"
    ]);
    exit;
}

/**
 * 同步 enrollmentdata 狀態
 * 當 prosubdata 狀態變為 2（異常）或 3（已結案）時，同步更新團隊成員的 enrollmentdata 狀態
 * 
 * @param PDO $conn 資料庫連線
 * @param int $team_ID 團隊ID
 * @param int $prosub_status prosubdata 的狀態（2=異常, 3=已結案）
 * @return bool 是否成功
 */
function syncEnrollmentStatus($conn, $team_ID, $prosub_status) {
    try {
        // 只處理狀態 2（異常）和 3（已結案）
        if (!in_array($prosub_status, [2, 3])) {
            return true; // 不需要同步，直接返回成功
        }
        
        // 獲取團隊的 cohort_ID
        $teamStmt = $conn->prepare("SELECT cohort_ID FROM teamdata WHERE team_ID = ?");
        $teamStmt->execute([$team_ID]);
        $team = $teamStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$team || !$team['cohort_ID']) {
            error_log("同步 enrollmentdata 狀態失敗: 找不到團隊或 cohort_ID (team_ID: {$team_ID})");
            return false;
        }
        
        $cohort_ID = $team['cohort_ID'];
        
        // 檢查 teammember 表的用戶欄位名稱
        $teamUserField = 'team_u_ID';
        $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
        $checkStmt->execute();
        if (!$checkStmt->fetch()) {
            $teamUserField = 'u_ID';
        }
        
        // 檢查 userrolesdata 表的用戶欄位名稱
        $userRoleUidField = 'ur_u_ID';
        $checkRoleStmt = $conn->prepare("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'");
        $checkRoleStmt->execute();
        if (!$checkRoleStmt->fetch()) {
            $checkRoleStmt2 = $conn->prepare("SHOW COLUMNS FROM userrolesdata LIKE 'user_u_ID'");
            $checkRoleStmt2->execute();
            if ($checkRoleStmt2->fetch()) {
                $userRoleUidField = 'user_u_ID';
            } else {
                $userRoleUidField = 'u_ID';
            }
        }
        
        // 獲取團隊所有成員的用戶ID（只包含學生角色）
        $memberStmt = $conn->prepare("
            SELECT DISTINCT tm.{$teamUserField} as u_ID
            FROM teammember tm
            WHERE tm.team_ID = ?
            AND (tm.tm_status IS NULL OR tm.tm_status IN (0, 1))
            AND EXISTS (
                SELECT 1
                FROM userrolesdata ur
                WHERE ur.{$userRoleUidField} = tm.{$teamUserField}
                AND ur.role_ID = 6
                AND ur.user_role_status = 1
            )
        ");
        $memberStmt->execute([$team_ID]);
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($members)) {
            // 沒有成員，不需要更新
            return true;
        }
        
        // 更新每個成員在對應 cohort_ID 的 enrollmentdata 狀態
        $enrollStatus = $prosub_status; // 2=異常, 3=已結案
        $updateStmt = $conn->prepare("
            UPDATE enrollmentdata
            SET enroll_status = ?
            WHERE enroll_u_ID = ?
            AND cohort_ID = ?
        ");
        
        $successCount = 0;
        foreach ($members as $member) {
            $u_ID = $member['u_ID'];
            try {
                $updateStmt->execute([$enrollStatus, $u_ID, $cohort_ID]);
                if ($updateStmt->rowCount() > 0) {
                    $successCount++;
                }
            } catch (PDOException $e) {
                error_log("更新 enrollmentdata 狀態失敗 (u_ID: {$u_ID}, cohort_ID: {$cohort_ID}): " . $e->getMessage());
            }
        }
        
        error_log("同步 enrollmentdata 狀態完成: 團隊 {$team_ID}, 狀態 {$prosub_status}, 更新 {$successCount} 個成員");
        return true;
        
    } catch (Exception $e) {
        error_log("同步 enrollmentdata 狀態發生錯誤 (team_ID: {$team_ID}): " . $e->getMessage());
        return false;
    }
}

$do = $_GET['do'] ?? '';

try {
    switch ($do) {
        case 'get_projects':
            // ====== 獲取專題列表（按類組分組，包含所有團隊） ======
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            $group_ID = isset($_GET['group_ID']) ? (int)$_GET['group_ID'] : 0;
            $status = isset($_GET['status']) ? $_GET['status'] : '';
            // 前端「退件」送 0，資料庫存的是 2（異常）
            if ($status !== '' && $status !== null) {
                $status = (int)$status;
                if ($status === 0) $status = 2;
            } else {
                $status = -1;
            }
            
            // 先獲取所有類組
            $groupSql = "SELECT group_ID, group_name FROM groupdata WHERE group_status = 1";
            if ($group_ID > 0) {
                $groupSql .= " AND group_ID = ?";
                $groupStmt = $conn->prepare($groupSql);
                $groupStmt->execute([$group_ID]);
            } else {
                $groupStmt = $conn->query($groupSql);
            }
            $allGroups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 對每個類組獲取團隊
            $groups = [];
            foreach ($allGroups as $group) {
                $gID = $group['group_ID'];
                $gName = $group['group_name'];
                
                // 構建團隊查詢條件
                $teamWhere = ['t.group_ID = ?', 't.team_status = 1'];
                $teamParams = [$gID];
                
                if ($cohort_ID > 0) {
                    $teamWhere[] = 't.cohort_ID = ?';
                    $teamParams[] = $cohort_ID;
                }
                
                if ($search) {
                    $teamWhere[] = 't.team_project_name LIKE ?';
                    $teamParams[] = "%{$search}%";
                }
                
                // 查詢該類組的所有團隊
                $teamSql = "
                    SELECT 
                        t.team_ID,
                        t.team_project_name as project_name,
                        t.cohort_ID,
                        c.cohort_name
                    FROM teamdata t
                    LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                    WHERE " . implode(' AND ', $teamWhere) . "
                    ORDER BY t.team_project_name
                ";
                
                $teamStmt = $conn->prepare($teamSql);
                $teamStmt->execute($teamParams);
                $teams = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // 團隊成員欄位名稱
                $tmCol = 'team_u_ID';
                $colCheck = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $colCheck->execute();
                if (!$colCheck->fetch()) {
                    $tmCol = 'u_ID';
                }
                
                // 預先查詢所有團隊的成員名單（避免 N+1）
                $memberMap = [];
                if (!empty($teams)) {
                    $teamIds = array_column($teams, 'team_ID');
                    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
                    $memberSql = "
                        SELECT tm.team_ID, COALESCE(NULLIF(TRIM(u.u_name),''), tm.{$tmCol}) AS u_name
                        FROM teammember tm
                        LEFT JOIN userdata u ON u.u_ID = tm.{$tmCol}
                        WHERE tm.team_ID IN ({$placeholders})
                          AND (tm.tm_status IS NULL OR tm.tm_status = 1)
                        ORDER BY tm.team_ID, u_name
                    ";
                    $memberStmt = $conn->prepare($memberSql);
                    $memberStmt->execute($teamIds);
                    foreach ($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $tid = (int)$row['team_ID'];
                        if (!isset($memberMap[$tid])) $memberMap[$tid] = [];
                        $memberMap[$tid][] = $row['u_name'];
                    }
                }
                
                // 對每個團隊查詢提交記錄（用於狀態篩選）
                $filteredTeams = [];
                foreach ($teams as $team) {
                    $tID = $team['team_ID'];
                    
                    // 查詢該團隊的提交記錄（排除暫存和已刪除的記錄）
                    $subSql = "
                        SELECT 
                            ps.prosub_ID,
                            ps.prosub_status,
                            ps.prosub_created_d,
                            ps.prosub_update_d,
                            ps.content_json
                        FROM prosubdata ps
                        WHERE ps.team_ID = ? AND ps.prosub_status != 4
                        ORDER BY ps.prosub_created_d DESC
                    ";
                    $subStmt = $conn->prepare($subSql);
                    $subStmt->execute([$tID]);
                    $allSubmissions = $subStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // 過濾掉已刪除的記錄
                    $submission = null;
                    foreach ($allSubmissions as $sub) {
                        $contentJson = json_decode($sub['content_json'] ?? '{}', true);
                        $isDeleted = isset($contentJson['is_deleted']) && $contentJson['is_deleted'];
                        if (!$isDeleted) {
                            $submission = $sub;
                            break; // 取第一個未刪除的記錄
                        }
                    }
                    
                    // 狀態篩選邏輯
                    // 如果有提交記錄，檢查狀態篩選是否符合
                    if ($submission) {
                        // 如果有狀態篩選，檢查是否符合
                        if ($status >= 0) {
                            $submissionStatus = (int)$submission['prosub_status'];
                            // 狀態映射：
                            // - 0 = 退件（舊值）
                            // - 2 = 退件/異常（新值，與 0 視為同一類）
                            // - 1 = 未審核
                            // - 3 = 通過
                            if ($status == 0) {
                                // 篩選「退件」時，同時包含狀態 0 與 2
                                if (!in_array($submissionStatus, [0, 2], true)) {
                                    continue; // 狀態不匹配，跳過
                                }
                            } else {
                                if ($submissionStatus != $status) {
                                    continue; // 狀態不匹配，跳過
                                }
                            }
                        }
                    } else {
                        // 如果沒有提交記錄，且狀態篩選不是「全部」，則跳過
                        if ($status >= 0) {
                            continue; // 沒有提交記錄且狀態篩選不是「全部」，跳過
                        }
                    }
                    
                    $filteredTeams[] = [
                        'team_ID' => $team['team_ID'],
                        'project_name' => $team['project_name'],
                        'cohort_name' => $team['cohort_name'],
                        'member_names' => isset($memberMap[$tID]) ? $memberMap[$tID] : [],
                        'has_submission' => $submission ? true : false,
                        'submission_status' => $submission ? (int)$submission['prosub_status'] : null,
                        'prosub_ID' => $submission ? (int)$submission['prosub_ID'] : null
                    ];
                }
                
                // 依照需求：未審核 (1) 放最上面，其次通過 (3)、退件 (0/2)、最後未繳交(null)
                usort($filteredTeams, function($a, $b) {
                    $order = function($s) {
                        if ($s === 1) return 0;          // 未審核
                        if ($s === 3) return 1;          // 通過
                        if ($s === 0 || $s === 2) return 2; // 退件
                        if ($s === null) return 3;       // 未繳交
                        return 4;                        // 其他
                    };
                    $oa = $order($a['submission_status']);
                    $ob = $order($b['submission_status']);
                    if ($oa === $ob) {
                        return strcmp($a['project_name'] ?? '', $b['project_name'] ?? '');
                    }
                    return $oa <=> $ob;
                });
                
                // 添加所有類組（即使沒有團隊也要顯示類組標題）
                if (!empty($filteredTeams)) {
                    $groups[] = [
                        'group_ID' => $gID,
                        'group_name' => $gName,
                        'projects' => $filteredTeams
                    ];
                } elseif ($status < 0) {
                    // 如果狀態篩選是「全部」，即使沒有團隊也顯示類組（但沒有項目）
                    $groups[] = [
                        'group_ID' => $gID,
                        'group_name' => $gName,
                        'projects' => []
                    ];
                }
            }
            
            echo json_encode([
                "success" => true,
                "data" => [
                    "groups" => $groups
                ]
            ]);
            break;
            
        case 'get_team_submissions':
            // ====== 獲取團隊的提交記錄 ======
            $team_ID = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : 0;
            
            if ($team_ID <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
                exit;
            }
            
            // 獲取團隊資訊
            $teamStmt = $conn->prepare("
                SELECT 
                    t.team_ID,
                    t.team_project_name,
                    t.group_ID,
                    g.group_name,
                    t.cohort_ID,
                    c.cohort_name
                FROM teamdata t
                LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                WHERE t.team_ID = ?
            ");
            $teamStmt->execute([$team_ID]);
            $teamInfo = $teamStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$teamInfo) {
                echo json_encode([
                    "success" => false,
                    "message" => "團隊不存在"
                ]);
                exit;
            }
            
            // 獲取該團隊的所有提交記錄（包含所有狀態，包括已刪除的，用於查看歷屆所有資料）
            $stmt = $conn->prepare("
                SELECT 
                    ps.prosub_ID,
                    ps.prosub_status,
                    ps.prosub_created_d,
                    ps.prosub_update_d,
                    ps.prosub_img,
                    ps.content_json,
                    ps.prosub_reason,
                    ps.prosub_re_reason,
                    ps.prosub_u_ID,
                    u.u_name as submitter_name,
                    p.pro_end_d as deadline
                FROM prosubdata ps
                LEFT JOIN userdata u ON ps.prosub_u_ID = u.u_ID
                LEFT JOIN projectdata p ON ps.pro_ID = p.pro_ID
                WHERE ps.team_ID = ?
                ORDER BY ps.prosub_created_d DESC
            ");
            $stmt->execute([$team_ID]);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 處理每個提交記錄的內容
            foreach ($submissions as &$sub) {
                $contentJson = json_decode($sub['content_json'] ?? '{}', true);
                $sub['intro'] = $contentJson['intro'] ?? '';
                $sub['is_deleted'] = isset($contentJson['is_deleted']) && $contentJson['is_deleted'];
                // 獲取歷史記錄
                $sub['history'] = $contentJson['history'] ?? [];
            }
            unset($sub);
            
            // 獲取該團隊對應的專題截止時間（用於設定）
            $proStmt = $conn->prepare("
                SELECT 
                    p.pro_ID,
                    p.pro_end_d,
                    p.pro_status
                FROM projectdata p
                INNER JOIN prosubdata ps ON p.pro_ID = ps.pro_ID
                WHERE ps.team_ID = ? AND p.pro_status = 1
                ORDER BY p.pro_created_d DESC
                LIMIT 1
            ");
            $proStmt->execute([$team_ID]);
            $projectInfo = $proStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "team_info" => $teamInfo,
                "data" => $submissions,
                "project_info" => $projectInfo ? [
                    "pro_ID" => $projectInfo['pro_ID'],
                    "deadline" => $projectInfo['pro_end_d'],
                    "pro_status" => $projectInfo['pro_status']
                ] : null
            ]);
            break;
            
        case 'set_deadline':
            // ====== 設定提交截止時間 ======
            $pro_ID = isset($_POST['pro_ID']) ? (int)$_POST['pro_ID'] : 0;
            $deadline = isset($_POST['deadline']) ? trim($_POST['deadline']) : '';

            if ($pro_ID <= 0 || !$deadline) {
                echo json_encode([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
                exit;
            }

            // 驗證日期格式
            $deadlineDateTime = DateTime::createFromFormat('Y-m-d\TH:i', $deadline);
            if (!$deadlineDateTime) {
                echo json_encode([
                    "success" => false,
                    "message" => "日期格式錯誤"
                ]);
                exit;
            }
            
            // 更新截止時間
            $updateStmt = $conn->prepare("
                UPDATE projectdata 
                SET pro_end_d = ? 
                WHERE pro_ID = ?
            ");
            $updateStmt->execute([$deadlineDateTime->format('Y-m-d H:i:s'), $pro_ID]);
            
            echo json_encode([
                "success" => true,
                "message" => "截止時間設定成功"
            ]);
            break;
            
        case 'get_history':
            // ====== 獲取歷史修改記錄（科辦端） ======
            $prosub_ID = isset($_GET['prosub_ID']) ? (int)$_GET['prosub_ID'] : 0;
            
            if ($prosub_ID <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
                exit;
            }
            
            // 獲取提交記錄
            $stmt = $conn->prepare("
                SELECT content_json FROM prosubdata 
                WHERE prosub_ID = ?
            ");
            $stmt->execute([$prosub_ID]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                echo json_encode([
                    "success" => false,
                    "message" => "記錄不存在"
                ]);
                exit;
            }
            
            $contentJson = json_decode($record['content_json'] ?? '{}', true);
            $history = $contentJson['history'] ?? [];
            
            // 獲取用戶名稱
            foreach ($history as &$item) {
                $userID = $item['submitted_by'] ?? $item['replaced_by'] ?? $item['deleted_by'] ?? $item['reset_by'] ?? $item['restored_by'] ?? null;
                if ($userID) {
                    $userStmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                    $userStmt->execute([$userID]);
                    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                    if ($user) {
                        if (isset($item['submitted_by'])) $item['submitted_by'] = $user['u_name'];
                        if (isset($item['replaced_by'])) $item['replaced_by'] = $user['u_name'];
                        if (isset($item['deleted_by'])) $item['deleted_by'] = $user['u_name'];
                        if (isset($item['reset_by'])) $item['reset_by'] = $user['u_name'];
                        if (isset($item['restored_by'])) $item['restored_by'] = $user['u_name'];
                    }
                }
            }
            unset($item);
            
            echo json_encode([
                "success" => true,
                "history" => $history,
                "original_draft_id" => $contentJson['original_draft_id'] ?? null
            ]);
            break;
            
        case 'review':
            // ====== 審核提交 ======
            $prosub_ID = isset($_POST['prosub_ID']) ? (int)$_POST['prosub_ID'] : 0;
            $action = isset($_POST['action']) ? trim($_POST['action']) : '';
            $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
            
            if ($prosub_ID <= 0 || !in_array($action, ['approve', 'reject', 'cancel_approve', 'cancel_reject'])) {
                echo json_encode([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
                exit;
            }
            
            // 檢查記錄是否存在
            $stmt = $conn->prepare("SELECT prosub_status, content_json, team_ID FROM prosubdata WHERE prosub_ID = ?");
            $stmt->execute([$prosub_ID]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                echo json_encode([
                    "success" => false,
                    "message" => "記錄不存在"
                ]);
                exit;
            }
            
            // 🔹 【修改邏輯】學生一提交，科辦隨時都可以審核（移除期限檢查）
            
            $u_ID = $_SESSION['u_ID'] ?? null;
            
            $newStatus = null;
            $updateFields = [];
            $updateValues = [];
            
            switch ($action) {
                case 'approve':
                    $newStatus = 3; // 通過
                    $updateFields[] = "prosub_status = ?";
                    $updateValues[] = $newStatus;
                    
                    // 如果有備註，儲存到 prosub_re_reason（審核備註欄位）
                    if ($reason) {
                        $updateFields[] = "prosub_re_reason = ?";
                        $updateValues[] = $reason;
                    }
                    
                    // 當審核通過時，只儲存備註，不自動設置 history_status = 1
                    // 並且要確保清除之前可能存在的 history_status = 1（如果有的話）
                    // history_status 應該只在"歷屆專題管理"頁面中手動上架時才設置為 1
                    // 這樣可以區分"通過審核"和"已上架"兩種狀態：
                    // - 通過審核：prosub_status = 3, history_status 未設置或為 0
                    // - 已上架：prosub_status = 3, history_status = 1
                    try {
                        // 獲取現有的 content_json
                        $subStmt = $conn->prepare("SELECT content_json FROM prosubdata WHERE prosub_ID = ?");
                        $subStmt->execute([$prosub_ID]);
                        $submission = $subStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($submission) {
                            $contentJson = json_decode($submission['content_json'] ?? '{}', true);
                            
                            // 如果有備註，儲存備註
                            if ($reason) {
                                $contentJson['approve_remark'] = $reason;
                            }
                            
                            // 防呆邏輯：通過審核時，明確設置 history_status = 0（未上架）
                            // 即使該專題曾經被上架過（history_status = 1），再次通過時也要清除上架狀態
                            // 這樣可以確保即使之前有設置為 1，也會被清除
                            // 只有科辦在"歷屆專題管理"頁面明確按下"上架"按鈕後，才會設置為 1
                            $contentJson['history_status'] = 0;
                            
                            // 更新 content_json
                            $updateStmt = $conn->prepare("
                                UPDATE prosubdata 
                                SET content_json = ?
                                WHERE prosub_ID = ?
                            ");
                            $updateStmt->execute([
                                json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                                $prosub_ID
                            ]);
                        }
                    } catch (Exception $e) {
                        // 記錄錯誤但不影響審核流程
                        error_log("更新 content_json 失敗: " . $e->getMessage());
                    }
                    break;
                case 'reject':
                    $newStatus = 2; // 異常（不通過）
                    $updateFields[] = "prosub_status = ?";
                    $updateValues[] = $newStatus;
                    if ($reason) {
                        $updateFields[] = "prosub_re_reason = ?";
                        $updateValues[] = $reason;
                    }
                    break;
                case 'cancel_approve':
                    $newStatus = 1; // 改回未審核
                    $updateFields[] = "prosub_status = ?";
                    $updateValues[] = $newStatus;
                    
                    // 防呆邏輯：清除上架狀態
                    // 如果該專題曾經被上架過（history_status = 1），取消通過時必須清除上架狀態
                    // 這樣當學生重新上傳並再次通過時，不會自動變成已上架
                    try {
                        // 獲取現有的 content_json
                        $subStmt = $conn->prepare("SELECT content_json FROM prosubdata WHERE prosub_ID = ?");
                        $subStmt->execute([$prosub_ID]);
                        $submission = $subStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($submission) {
                            $contentJson = json_decode($submission['content_json'] ?? '{}', true);
                            
                            // 清除上架狀態，設置為 0（未上架）
                            // 即使之前是 1（已上架），取消通過後也要清除
                            $contentJson['history_status'] = 0;
                            
                            // 更新 content_json
                            $updateStmt = $conn->prepare("
                                UPDATE prosubdata 
                                SET content_json = ?
                                WHERE prosub_ID = ?
                            ");
                            $updateStmt->execute([
                                json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                                $prosub_ID
                            ]);
                        }
                    } catch (Exception $e) {
                        // 記錄錯誤但不影響審核流程
                        error_log("清除上架狀態失敗: " . $e->getMessage());
                    }
                    break;
                case 'cancel_reject':
                    $newStatus = 1; // 改回未審核
                    $updateFields[] = "prosub_status = ?";
                    $updateValues[] = $newStatus;
                    $updateFields[] = "prosub_re_reason = NULL";
                    break;
            }
            
            if ($newStatus !== null) {
                $updateFields[] = "prosub_update_d = NOW()";
                
                // 如果是審核操作（通過或退件），記錄審核人和審核時間
                if (in_array($action, ['approve', 'reject'])) {
                    // 只有在 u_ID 有效時才記錄審核人（允許 0，但不允許 null 或空字串）
                    if ($u_ID !== null && $u_ID !== '') {
                        $updateFields[] = "prosub_re_u_ID = ?";
                        $updateValues[] = $u_ID;
                    }
                    $updateFields[] = "prosub_re_d = NOW()";
                }
                
                $updateValues[] = $prosub_ID;
                
                $updateSql = "UPDATE prosubdata SET " . implode(', ', $updateFields) . " WHERE prosub_ID = ?";
                
                try {
                    $updateStmt = $conn->prepare($updateSql);
                    
                    if (!$updateStmt) {
                        $errorInfo = $conn->errorInfo();
                        throw new Exception("SQL 準備失敗: " . ($errorInfo[2] ?? implode(', ', $errorInfo)));
                    }
                    
                    $executeResult = $updateStmt->execute($updateValues);
                    
                    if (!$executeResult) {
                        $errorInfo = $updateStmt->errorInfo();
                        throw new Exception("SQL 執行失敗: " . ($errorInfo[2] ?? '未知錯誤'));
                    }
                } catch (PDOException $e) {
                    throw new Exception("資料庫錯誤: " . $e->getMessage());
                }
                
                // 同步 enrollmentdata 狀態
                // 當 prosub_status 變為 2（異常）或 3（已結案）時，同步更新團隊成員的 enrollmentdata 狀態
                if (in_array($newStatus, [2, 3])) {
                    syncEnrollmentStatus($conn, $record['team_ID'], $newStatus);
                }
                
                // 如果是通過，不自動設置 history_status = 1
                // history_status 應該只在"歷屆專題管理"頁面中手動上架時才設置為 1
                // 這樣可以區分"通過審核"和"已上架"兩種狀態
                // 通過審核：prosub_status = 3, history_status 未設置或為 0
                // 已上架：prosub_status = 3, history_status = 1
                
                echo json_encode([
                    "success" => true,
                    "message" => "操作成功"
                ]);
            } else {
                echo json_encode([
                    "success" => false,
                    "message" => "未知的操作"
                ]);
            }
            break;
            
        case 'delete':
            // ====== 刪除提交（軟刪除） ======
            $prosub_ID = isset($_POST['prosub_ID']) ? (int)$_POST['prosub_ID'] : 0;
            
            if ($prosub_ID <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
                exit;
            }
            
            // 獲取當前記錄
            $stmt = $conn->prepare("SELECT content_json, prosub_img FROM prosubdata WHERE prosub_ID = ?");
            $stmt->execute([$prosub_ID]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                echo json_encode([
                    "success" => false,
                    "message" => "記錄不存在"
                ]);
                exit;
            }
            
            $contentJson = json_decode($record['content_json'] ?? '{}', true);
            $contentJson['is_deleted'] = true;
            
            // 保存歷史記錄
            $history = $contentJson['history'] ?? [];
            $history[] = [
                'action' => 'deleted',
                'deleted_by' => $_SESSION['u_ID'] ?? 0,
                'deleted_at' => date('Y-m-d H:i:s'),
                'snapshot' => [
                    'intro' => $contentJson['intro'] ?? '',
                    'image' => $record['prosub_img'] ?? ''
                ]
            ];
            $contentJson['history'] = $history;
            
            // 更新記錄
            $updateStmt = $conn->prepare("UPDATE prosubdata SET content_json = ? WHERE prosub_ID = ?");
            $updateStmt->execute([json_encode($contentJson, JSON_UNESCAPED_UNICODE), $prosub_ID]);
            
            echo json_encode([
                "success" => true,
                "message" => "已刪除"
            ]);
            break;
            
        case 'restore':
            // ====== 恢復提交 ======
            $prosub_ID = isset($_POST['prosub_ID']) ? (int)$_POST['prosub_ID'] : 0;
            
            if ($prosub_ID <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
                exit;
            }
            
            // 獲取當前記錄
            $stmt = $conn->prepare("SELECT content_json FROM prosubdata WHERE prosub_ID = ?");
            $stmt->execute([$prosub_ID]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                echo json_encode([
                    "success" => false,
                    "message" => "記錄不存在"
                ]);
                exit;
            }
            
            $contentJson = json_decode($record['content_json'] ?? '{}', true);
            $contentJson['is_deleted'] = false;
            
            // 保存歷史記錄
            $history = $contentJson['history'] ?? [];
            $history[] = [
                'action' => 'restored',
                'restored_by' => $_SESSION['u_ID'] ?? 0,
                'restored_at' => date('Y-m-d H:i:s')
            ];
            $contentJson['history'] = $history;
            
            // 更新記錄
            $updateStmt = $conn->prepare("UPDATE prosubdata SET content_json = ? WHERE prosub_ID = ?");
            $updateStmt->execute([json_encode($contentJson, JSON_UNESCAPED_UNICODE), $prosub_ID]);
            
            echo json_encode([
                "success" => true,
                "message" => "已恢復"
            ]);
            break;
            
        case 'get_team_members':
            // 獲取團隊成員資訊
            $team_ID = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : 0;
            if ($team_ID <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
                exit;
            }
            
            // 獲取團隊資訊
            $stmt = $conn->prepare("
                SELECT cohort_ID 
                FROM teamdata 
                WHERE team_ID = ? AND team_status = 1
            ");
            $stmt->execute([$team_ID]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$team) {
                echo json_encode([
                    "success" => false,
                    "message" => "團隊不存在"
                ]);
                exit;
            }
            
            // 獲取團隊成員
            $teamUserField = 'team_u_ID';
            $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $teamUserField = 'u_ID';
            }
            
            $stmt = $conn->prepare("
                SELECT 
                    u.u_ID,
                    u.u_name,
                    u.u_account as student_id,
                    e.class_ID,
                    c.c_name as class_name
                FROM teammember tm
                INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
                LEFT JOIN enrollmentdata e ON u.u_ID = e.enroll_u_ID AND e.cohort_ID = ?
                LEFT JOIN classdata c ON e.class_ID = c.c_ID
                WHERE tm.team_ID = ? 
                  AND tm.tm_status = 1
                  AND EXISTS (
                      SELECT 1 FROM userrolesdata ur 
                      WHERE ur.ur_u_ID = tm.{$teamUserField} 
                        AND ur.role_ID = 6 
                        AND ur.user_role_status = 1
                  )
                ORDER BY tm.{$teamUserField}
            ");
            $stmt->execute([$team['cohort_ID'], $team_ID]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "members" => $members
            ]);
            break;
            
        default:
            echo json_encode([
                "success" => false,
                "message" => "未知的操作"
            ]);
            break;
    }
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "錯誤: " . $e->getMessage()
    ]);
}
?>

