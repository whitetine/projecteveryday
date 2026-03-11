<?php
session_start();
require '../includes/pdo.php';

header('Content-Type: application/json; charset=utf-8');

// 檢查登入狀態
if (!isset($_SESSION['u_ID'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => '請先登入'], JSON_UNESCAPED_UNICODE);
    exit;
}

$u_ID = $_SESSION['u_ID'];
$role_ID = $_SESSION['role_ID'] ?? null;
$cohort_ID = $_SESSION['cohort_ID'] ?? null; // 獲取當前選擇的屆別

// 檢查是否為指導老師 (role_ID = 4)
if ((int)$role_ID !== 4) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => '此功能僅限指導老師使用'], JSON_UNESCAPED_UNICODE);
    exit;
}

date_default_timezone_set('Asia/Taipei');

try {
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
    
    // 1. 獲取指導老師指導的所有團隊
    // 先確認當前用戶是否為指導老師
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM userrolesdata 
        WHERE {$userRoleUidField} = ? AND role_ID = 4 AND user_role_status = 1
    ");
    $stmt->execute([$u_ID]);
    $isTeacher = $stmt->fetchColumn() > 0;
    
    if (!$isTeacher) {
        echo json_encode([
            'ok' => true,
            'groups' => [],
            'actions' => ['您目前沒有指導任何團隊']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 查詢指導老師在 teammember 表中的所有團隊
    // 需要確保該用戶在團隊中確實是指導老師角色（role_ID = 4）
    // 如果 session 中有 cohort_ID，則只顯示該屆別的團隊
    $sql = "
        SELECT DISTINCT 
            t.team_ID,
            COALESCE(t.team_project_name, CONCAT('團隊 ', t.team_ID)) AS team_name,
            t.team_update_d
        FROM teammember tm
        JOIN teamdata t ON tm.team_ID = t.team_ID
        JOIN userrolesdata ur ON ur.{$userRoleUidField} = tm.{$teamUserField}
        WHERE tm.{$teamUserField} = ?
          AND ur.role_ID = 4
          AND ur.user_role_status = 1
          AND t.team_status = 1
          AND (tm.tm_status IS NULL OR tm.tm_status = 1)
    ";
    
    // 如果 session 中有 cohort_ID，添加屆別過濾條件
    $params = [$u_ID];
    if ($cohort_ID !== null && $cohort_ID !== '') {
        $sql .= " AND t.cohort_ID = ?";
        $params[] = (int)$cohort_ID;
    }
    
    $sql .= " ORDER BY t.team_ID";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 調試：記錄查詢結果
    error_log("Teamteacher Data - User ID: {$u_ID}, Team User Field: {$teamUserField}, User Role UID Field: {$userRoleUidField}, Found Teams: " . count($teams));
    
    if (count($teams) === 0) {
        // 調試：檢查是否有團隊成員記錄
        $debugStmt = $conn->prepare("
            SELECT COUNT(*) as cnt
            FROM teammember tm
            WHERE tm.{$teamUserField} = ?
        ");
        $debugStmt->execute([$u_ID]);
        $memberCount = $debugStmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        error_log("Teamteacher Data - Debug: User {$u_ID} has {$memberCount} teammember records");
        
        // 調試：檢查用戶角色
        $debugStmt2 = $conn->prepare("
            SELECT role_ID, user_role_status
            FROM userrolesdata
            WHERE {$userRoleUidField} = ?
        ");
        $debugStmt2->execute([$u_ID]);
        $roles = $debugStmt2->fetchAll(PDO::FETCH_ASSOC);
        error_log("Teamteacher Data - Debug: User {$u_ID} roles: " . json_encode($roles));
    }
    
    $groups = [];
    $allActions = []; // 儲存所有動態，格式：['time' => timestamp, 'text' => '動態文字']
    
    foreach ($teams as $team) {
        $team_ID = (int)$team['team_ID'];
        $team_name = $team['team_name'];
        
        // 2. 計算里程碑達成率
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN ms_status = 1 THEN 1 ELSE 0 END) AS done
            FROM milesdata
            WHERE team_ID = ?
        ");
        $stmt->execute([$team_ID]);
        $milestoneStats = $stmt->fetch(PDO::FETCH_ASSOC);
        $milestone_total = (int)($milestoneStats['total'] ?? 0);
        $milestone_done = (int)($milestoneStats['done'] ?? 0);
        
        // 3. 獲取即將到期的里程碑（3天內）
        $stmt = $conn->prepare("
            SELECT ms_title, ms_end_d
            FROM milesdata
            WHERE team_ID = ?
              AND ms_status = 0
              AND ms_end_d IS NOT NULL
              AND ms_end_d >= NOW()
              AND ms_end_d <= DATE_ADD(NOW(), INTERVAL 3 DAY)
            ORDER BY ms_end_d ASC
            LIMIT 1
        ");
        $stmt->execute([$team_ID]);
        $upcomingMilestone = $stmt->fetch(PDO::FETCH_ASSOC);
        $milestone_alert = null;
        if ($upcomingMilestone) {
            $endTime = strtotime($upcomingMilestone['ms_end_d']);
            $now = time();
            $daysLeft = ceil(($endTime - $now) / 86400);
            $milestone_alert = $upcomingMilestone['ms_title'] . "（還剩 {$daysLeft} 天）";
        }
        
        // 4. 計算任務完成度
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN task_status = 1 THEN 1 ELSE 0 END) AS done
            FROM taskdata
            WHERE task_team_ID = ?
        ");
        $stmt->execute([$team_ID]);
        $taskStats = $stmt->fetch(PDO::FETCH_ASSOC);
        $task_total = (int)($taskStats['total'] ?? 0);
        $task_done = (int)($taskStats['done'] ?? 0);
        $progress = $task_total > 0 ? round(($task_done / $task_total) * 100) : 0;
        
        // 5. 計算待審核的需求數量（reprogressdata 中 rp_status = 0 表示待審核）
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS pending_count
            FROM reprogressdata rp
            JOIN requirementdata req ON rp.req_ID = req.req_ID
            WHERE rp.rp_team_ID = ?
              AND rp.rp_status = 0
              AND req.req_status = 1
        ");
        $stmt->execute([$team_ID]);
        $pendingReq = $stmt->fetch(PDO::FETCH_ASSOC);
        $pending_requirements = (int)($pendingReq['pending_count'] ?? 0);
        
        // 6. 獲取最新更新時間（從任務、里程碑、需求進度中取最新的）
        $stmt = $conn->prepare("
            SELECT MAX(update_time) AS latest_update
            FROM (
                SELECT MAX(task_created_d) AS update_time FROM taskdata WHERE task_team_ID = ?
                UNION ALL
                SELECT MAX(ms_created_d) AS update_time FROM milesdata WHERE team_ID = ?
                UNION ALL
                SELECT MAX(rp_completed_d) AS update_time FROM reprogressdata WHERE rp_team_ID = ?
            ) AS updates
        ");
        $stmt->execute([$team_ID, $team_ID, $team_ID]);
        $latestUpdate = $stmt->fetch(PDO::FETCH_ASSOC);
        $latest_update = '';
        if ($latestUpdate && $latestUpdate['latest_update']) {
            $updateTime = strtotime($latestUpdate['latest_update']);
            $now = time();
            $diff = $now - $updateTime;
            
            if ($diff < 3600) {
                $minutes = floor($diff / 60);
                $latest_update = $minutes > 0 ? "{$minutes} 分鐘前" : "剛剛";
            } elseif ($diff < 86400) {
                $hours = floor($diff / 3600);
                $latest_update = "{$hours} 小時前";
            } else {
                $latest_update = date('Y-m-d H:i', $updateTime);
            }
        }
        
        // 7. 獲取最新的需求動態（待審核的需求）
        $stmt = $conn->prepare("
            SELECT 
                req.req_title,
                u.u_name,
                rp.rp_completed_d
            FROM reprogressdata rp
            JOIN requirementdata req ON rp.req_ID = req.req_ID
            LEFT JOIN userdata u ON rp.rp_u_ID = u.u_ID
            WHERE rp.rp_team_ID = ?
              AND rp.rp_status = 0
              AND req.req_status = 1
            ORDER BY rp.rp_completed_d DESC
            LIMIT 5
        ");
        $stmt->execute([$team_ID]);
        $recentRequirements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($recentRequirements as $req) {
            $reqTime = $req['rp_completed_d'] ? strtotime($req['rp_completed_d']) : 0;
            $reqTimeFormatted = $req['rp_completed_d'] ? date('Y-m-d H:i', $reqTime) : '';
            $studentName = $req['u_name'] ?: '未知';
            $allActions[] = [
                'time' => $reqTime,
                'text' => "{$team_name} 提交需求「{$req['req_title']}」待審核（{$studentName}，{$reqTimeFormatted}）"
            ];
        }
        
        // 8. 獲取最新完成的任務
        $stmt = $conn->prepare("
            SELECT 
                task_title,
                u.u_name,
                task_done_d
            FROM taskdata t
            LEFT JOIN userdata u ON t.task_done_u_ID = u.u_ID
            WHERE t.task_team_ID = ?
              AND t.task_status = 1
              AND t.task_done_d IS NOT NULL
            ORDER BY t.task_done_d DESC
            LIMIT 3
        ");
        $stmt->execute([$team_ID]);
        $recentTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($recentTasks as $task) {
            $taskTime = $task['task_done_d'] ? strtotime($task['task_done_d']) : 0;
            $taskTimeFormatted = $task['task_done_d'] ? date('Y-m-d H:i', $taskTime) : '';
            $studentName = $task['u_name'] ?: '未知';
            $allActions[] = [
                'time' => $taskTime,
                'text' => "{$team_name} 完成任務「{$task['task_title']}」（{$studentName}，{$taskTimeFormatted}）"
            ];
        }
        
        // 9. 獲取團隊成員（僅學生角色，role_ID = 6）
        $stmt = $conn->prepare("
            SELECT 
                u.u_ID,
                u.u_name,
                u.u_img,
                u.u_profile
            FROM teammember tm
            JOIN userdata u ON tm.{$teamUserField} = u.u_ID
            JOIN userrolesdata ur ON ur.{$userRoleUidField} = u.u_ID
            WHERE tm.team_ID = ? 
              AND ur.role_ID = 6 
              AND ur.user_role_status = 1
              AND (tm.tm_status IS NULL OR tm.tm_status = 1)
            ORDER BY u.u_ID ASC
        ");
        $stmt->execute([$team_ID]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 處理成員數據
        $memberList = [];
        foreach ($members as $member) {
            $memberList[] = [
                'u_ID' => $member['u_ID'],
                'u_name' => $member['u_name'] ?: $member['u_ID'],
                'u_img' => $member['u_img'] ?: null,
                'u_profile' => $member['u_profile'] ?: ''
            ];
        }
        
        $groups[] = [
            'team_ID' => $team_ID,
            'name' => $team_name,
            'progress' => $progress,
            'pending_requirements' => $pending_requirements,
            'milestone_alert' => $milestone_alert,
            'latest_update' => $latest_update ?: '無更新記錄',
            'milestone_total' => $milestone_total,
            'milestone_done' => $milestone_done,
            'members' => $memberList
        ];
    }
    
    // 按時間排序（最新的在前），取前10條
    usort($allActions, function($a, $b) {
        return $b['time'] - $a['time']; // 降序排序
    });
    $latestActions = array_slice(array_column($allActions, 'text'), 0, 10);
    
    // 如果沒有動態，添加預設訊息
    if (empty($latestActions)) {
        $latestActions = ['暫無最新動態'];
    }
    
    // 如果沒有團隊，返回空陣列和提示訊息
    if (empty($groups)) {
        // 返回調試信息（僅在開發環境）
        $debugInfo = [];
        if (count($teams) === 0) {
            $debugInfo['reason'] = '查詢未找到任何團隊';
            $debugInfo['query_params'] = [
                'u_ID' => $u_ID,
                'teamUserField' => $teamUserField,
                'userRoleUidField' => $userRoleUidField
            ];
        } else {
            $debugInfo['reason'] = '團隊數據處理後為空';
            $debugInfo['teams_found'] = count($teams);
        }
        
        echo json_encode([
            'ok' => true,
            'groups' => [],
            'actions' => ['目前沒有指導任何團隊，或團隊資料尚未建立'],
            'debug' => $debugInfo
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    echo json_encode([
        'ok' => true,
        'groups' => $groups,
        'actions' => $latestActions
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("Teamteacher Data PDO Error: " . $e->getMessage());
    error_log("Teamteacher Data PDO Error Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => '資料庫錯誤：' . $e->getMessage(),
        'debug' => [
            'u_ID' => $u_ID ?? 'unknown',
            'role_ID' => $role_ID ?? 'unknown',
            'teamUserField' => $teamUserField ?? 'unknown',
            'userRoleUidField' => $userRoleUidField ?? 'unknown'
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Teamteacher Data Exception: " . $e->getMessage());
    error_log("Teamteacher Data Exception Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => '系統錯誤：' . $e->getMessage(),
        'debug' => [
            'u_ID' => $u_ID ?? 'unknown',
            'role_ID' => $role_ID ?? 'unknown'
        ]
    ], JSON_UNESCAPED_UNICODE);
}
