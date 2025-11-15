<?php
/**
 * 里程碑管理後端 API 模組
 * 
 * 修改記錄：
 * 2025-01-XX XX:XX - 只顯示狀態為1的團隊
 *   改動內容：在獲取團隊列表時，只返回狀態為1的團隊
 *   相關功能：get_teams API
 *   方式：在所有查詢中添加 team_status = 1 條件
 * 
 * 2025-01-XX XX:XX - 基本需求改為可選
 *   改動內容：允許里程碑不關聯基本需求，req_ID 可為 0 或 NULL
 *   相關功能：create_milestone, update_milestone API
 *   方式：移除 req_ID 必填驗證，在儲存時將 0 轉為 NULL
 * 
 * 2025-01-XX XX:XX - 修正 SQL 欄位名稱
 *   改動內容：修正 userrolesdata 表的欄位名稱從 u_ID 改為 ur_u_ID
 *   相關功能：權限檢查、團隊列表查詢
 *   方式：更新 SQL 查詢語句中的欄位名稱
 */

global $conn;
$p = $_POST;
$do = $_GET['do'] ?? '';

// 檢查是否為指導老師 (role_ID=4)
function checkTeacherPermission() {
    global $conn;
    $u_ID = $_SESSION['u_ID'] ?? null;
    if (!$u_ID) {
        json_err('請先登入', 'NOT_LOGGED_IN', 401);
    }
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM userrolesdata 
        WHERE ur_u_ID = ? AND role_ID = 4 AND user_role_status = 1
    ");
    $stmt->execute([$u_ID]);
    if (!$stmt->fetchColumn()) {
        json_err('此功能僅限指導老師使用', 'NO_PERMISSION', 403);
    }
    return $u_ID;
}

switch ($do) {
    // 獲取里程碑列表
    case 'get_milestones':
        try {
            $team_ID = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : 0;
            $req_ID = isset($_GET['req_ID']) ? (int)$_GET['req_ID'] : 0;
            
            $sql = "
                SELECT 
                    m.ms_ID,
                    m.req_ID,
                    m.team_ID,
                    m.ms_title,
                    m.ms_desc,
                    m.ms_start_d,
                    m.ms_end_d,
                    m.ms_u_ID,
                    m.ms_completed_d,
                    m.ms_approved_d,
                    m.ms_approved_u_ID,
                    m.ms_status,
                    m.ms_created_d,
                    r.req_title,
                    r.req_direction,
                    t.team_project_name as team_name,
                    u2.u_name as completer_name,
                    u3.u_name as approver_name
                FROM milesdata m
                LEFT JOIN requirementdata r ON m.req_ID = r.req_ID
                LEFT JOIN teamdata t ON m.team_ID = t.team_ID
                LEFT JOIN userdata u2 ON m.ms_u_ID = u2.u_ID
                LEFT JOIN userdata u3 ON m.ms_approved_u_ID = u3.u_ID
                WHERE 1=1
            ";
            
            $params = [];
            if ($team_ID > 0) {
                $sql .= " AND m.team_ID = ?";
                $params[] = $team_ID;
            }
            if ($req_ID > 0) {
                $sql .= " AND m.req_ID = ?";
                $params[] = $req_ID;
            }
            
            $sql .= " ORDER BY m.ms_created_d DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status'=>'error','message'=>'資料讀取失敗：'.$e->getMessage()]);
            exit;
        }
        break;

    // 獲取基本需求列表
    case 'get_requirements':
        try {
            $rows = $conn->query("
                SELECT 
                    req_ID,
                    req_title,
                    req_direction,
                    req_count,
                    req_start_d,
                    req_end_d,
                    color_hex,
                    req_status
                FROM requirementdata
                WHERE req_status = 1
                ORDER BY req_created_d DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status'=>'error','message'=>'資料讀取失敗：'.$e->getMessage()]);
            exit;
        }
        break;

    // 獲取團隊列表
    case 'get_teams':
        try {
            $u_ID = $_SESSION['u_ID'] ?? null;
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }
            
            // 如果是指導老師，只顯示他指導的團隊
            $stmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM userrolesdata 
                WHERE ur_u_ID = ? AND role_ID = 4 AND user_role_status = 1
            ");
            $stmt->execute([$u_ID]);
            $isTeacher = $stmt->fetchColumn() > 0;
            
            if ($isTeacher) {
                // 嘗試使用 team_u_ID，如果失敗則嘗試 u_ID（兼容舊版本）
                // 修改日期：2025-01-XX XX:XX
                // 改動內容：只顯示狀態為1的團隊
                // 相關功能：獲取團隊列表
                // 方式：在 WHERE 條件中添加 t.team_status = 1
                try {
                    $stmt = $conn->prepare("
                        SELECT DISTINCT t.team_ID, t.team_project_name as team_name
                        FROM teamdata t
                        JOIN teammember tm ON t.team_ID = tm.team_ID
                        WHERE tm.team_u_ID = ? AND t.team_status = 1
                        ORDER BY t.team_ID
                    ");
                    $stmt->execute([$u_ID]);
                } catch (Exception $e) {
                    // 如果失敗，嘗試使用舊的欄位名稱
                    $stmt = $conn->prepare("
                        SELECT DISTINCT t.team_ID, t.team_project_name as team_name
                        FROM teamdata t
                        JOIN teammember tm ON t.team_ID = tm.team_ID
                        WHERE tm.u_ID = ? AND t.team_status = 1
                        ORDER BY t.team_ID
                    ");
                    $stmt->execute([$u_ID]);
                }
            } else {
                // 修改日期：2025-01-XX XX:XX
                // 改動內容：只顯示狀態為1的團隊
                // 相關功能：獲取團隊列表
                // 方式：在 WHERE 條件中添加 team_status = 1
                $stmt = $conn->query("
                    SELECT team_ID, team_project_name as team_name
                    FROM teamdata
                    WHERE team_status = 1
                    ORDER BY team_ID
                ");
            }
            
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status'=>'error','message'=>'資料讀取失敗：'.$e->getMessage()]);
            exit;
        }
        break;

    // 獲取團隊完成基本需求的進度
    case 'get_requirement_progress':
        try {
            $req_ID = isset($_GET['req_ID']) ? (int)$_GET['req_ID'] : 0;
            $team_ID = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : 0;
            
            if ($req_ID <= 0) {
                json_err('缺少需求ID', 'MISSING_REQ_ID', 400);
            }
            
            $sql = "
                SELECT 
                    rp.rp_ID,
                    rp.req_ID,
                    rp.rp_team_ID,
                    rp.rp_u_ID,
                    rp.rp_status,
                    rp.rp_completed_d,
                    rp.rp_approved_d,
                    rp.rp_approved_u_ID,
                    rp.rp_remark,
                    t.team_project_name as team_name,
                    u.u_name as completer_name,
                    u2.u_name as approver_name
                FROM reprogressdata rp
                LEFT JOIN teamdata t ON rp.rp_team_ID = t.team_ID
                LEFT JOIN userdata u ON rp.rp_u_ID = u.u_ID
                LEFT JOIN userdata u2 ON rp.rp_approved_u_ID = u2.u_ID
                WHERE rp.req_ID = ?
            ";
            
            $params = [$req_ID];
            if ($team_ID > 0) {
                $sql .= " AND rp.rp_team_ID = ?";
                $params[] = $team_ID;
            }
            
            $sql .= " ORDER BY rp.rp_completed_d DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status'=>'error','message'=>'資料讀取失敗：'.$e->getMessage()]);
            exit;
        }
        break;

    // 新增里程碑
    case 'create_milestone':
        $u_ID = checkTeacherPermission();
        
        $req_ID = isset($p['req_ID']) ? (int)$p['req_ID'] : 0;
        $team_ID = isset($p['team_ID']) ? (int)$p['team_ID'] : 0;
        $ms_title = trim($p['ms_title'] ?? '');
        $ms_desc = trim($p['ms_desc'] ?? '');
        $ms_start_d = trim($p['ms_start_d'] ?? '');
        $ms_end_d = trim($p['ms_end_d'] ?? '');
        
        // 基本需求為可選，req_ID 可以為 0
        // 修改日期：2025-01-XX XX:XX
        // 改動內容：移除 req_ID 必填驗證，允許不關聯基本需求
        // 相關功能：新增里程碑功能
        // 方式：移除 req_ID 驗證，在儲存時將 0 轉為 NULL
        if ($team_ID <= 0) json_err('請選擇團隊');
        if ($ms_title === '') json_err('請輸入里程碑標題');
        if ($ms_start_d === '' || $ms_end_d === '') json_err('請選擇開始和截止時間');
        
        try {
            // 如果 req_ID 為 0，則設為 NULL（允許不關聯基本需求）
            $req_ID_value = $req_ID > 0 ? $req_ID : null;
            $stmt = $conn->prepare("
                INSERT INTO milesdata 
                (req_ID, team_ID, ms_title, ms_desc, ms_start_d, ms_end_d, ms_status, ms_created_d)
                VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
            ");
            $stmt->execute([$req_ID_value, $team_ID, $ms_title, $ms_desc, $ms_start_d, $ms_end_d]);
            
            $ms_ID = (int)$conn->lastInsertId();
            json_ok(['ms_ID' => $ms_ID, 'message' => '里程碑建立成功']);
        } catch (Throwable $e) {
            json_err('建立失敗：'.$e->getMessage());
        }
        break;

    // 更新里程碑
    case 'update_milestone':
        $u_ID = checkTeacherPermission();
        
        $ms_ID = isset($p['ms_ID']) ? (int)$p['ms_ID'] : 0;
        $req_ID = isset($p['req_ID']) ? (int)$p['req_ID'] : 0;
        $team_ID = isset($p['team_ID']) ? (int)$p['team_ID'] : 0;
        $ms_title = trim($p['ms_title'] ?? '');
        $ms_desc = trim($p['ms_desc'] ?? '');
        $ms_start_d = trim($p['ms_start_d'] ?? '');
        $ms_end_d = trim($p['ms_end_d'] ?? '');
        $ms_status = isset($p['ms_status']) ? (int)$p['ms_status'] : 0;
        
        if ($ms_ID <= 0) json_err('里程碑ID無效');
        // 基本需求為可選，req_ID 可以為 0
        // 修改日期：2025-01-XX XX:XX
        // 改動內容：移除 req_ID 必填驗證，允許不關聯基本需求
        // 相關功能：更新里程碑功能
        // 方式：移除 req_ID 驗證，在儲存時將 0 轉為 NULL
        if ($team_ID <= 0) json_err('請選擇團隊');
        if ($ms_title === '') json_err('請輸入里程碑標題');
        
        try {
            // 如果 req_ID 為 0，則設為 NULL（允許不關聯基本需求）
            $req_ID_value = $req_ID > 0 ? $req_ID : null;
            $stmt = $conn->prepare("
                UPDATE milesdata 
                SET req_ID = ?, team_ID = ?, ms_title = ?, ms_desc = ?, 
                    ms_start_d = ?, ms_end_d = ?, ms_status = ?
                WHERE ms_ID = ?
            ");
            $stmt->execute([$req_ID_value, $team_ID, $ms_title, $ms_desc, $ms_start_d, $ms_end_d, $ms_status, $ms_ID]);
            
            json_ok(['message' => '里程碑更新成功']);
        } catch (Throwable $e) {
            json_err('更新失敗：'.$e->getMessage());
        }
        break;

    // 刪除里程碑
    case 'delete_milestone':
        $u_ID = checkTeacherPermission();
        
        $ms_ID = isset($p['ms_ID']) ? (int)$p['ms_ID'] : 0;
        if ($ms_ID <= 0) json_err('里程碑ID無效');
        
        try {
            $stmt = $conn->prepare("DELETE FROM milesdata WHERE ms_ID = ?");
            $stmt->execute([$ms_ID]);
            
            json_ok(['message' => '里程碑刪除成功']);
        } catch (Throwable $e) {
            json_err('刪除失敗：'.$e->getMessage());
        }
        break;

    // 審核里程碑（完成/通過）
    case 'approve_milestone':
        $u_ID = checkTeacherPermission();
        
        $ms_ID = isset($p['ms_ID']) ? (int)$p['ms_ID'] : 0;
        $action = trim($p['action'] ?? ''); // 'complete' 或 'approve'
        
        if ($ms_ID <= 0) json_err('里程碑ID無效');
        if (!in_array($action, ['complete', 'approve'])) json_err('無效的操作');
        
        try {
            if ($action === 'complete') {
                $stmt = $conn->prepare("
                    UPDATE milesdata 
                    SET ms_u_ID = ?, ms_completed_d = NOW(), ms_status = 1
                    WHERE ms_ID = ?
                ");
                $stmt->execute([$u_ID, $ms_ID]);
            } elseif ($action === 'approve') {
                $stmt = $conn->prepare("
                    UPDATE milesdata 
                    SET ms_approved_u_ID = ?, ms_approved_d = NOW(), ms_status = 2
                    WHERE ms_ID = ?
                ");
                $stmt->execute([$u_ID, $ms_ID]);
            }
            
            json_ok(['message' => '操作成功']);
        } catch (Throwable $e) {
            json_err('操作失敗：'.$e->getMessage());
        }
        break;
}

