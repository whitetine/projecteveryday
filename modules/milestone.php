<?php
/**
 * 里程碑管理後端 API 模組
 * 
 * 修改記錄：
 * 2025-11-16 - 添加完成里程碑功能和通知系統
 *   改動內容：新增 complete_milestone API，學生可標記完成，系統自動通知指導老師
 *   相關功能：學生完成里程碑、通知系統
 *   方式：更新里程碑狀態，使用 msgdata 和 msgtargetdata 創建通知
 * 
 * 2025-11-16 - 添加優先級功能
 *   改動內容：在新增和更新里程碑時處理優先級欄位，在查詢中返回優先級
 *   相關功能：里程碑優先級管理
 *   方式：在 SQL 查詢和 INSERT/UPDATE 語句中添加 ms_priority 欄位
 * 
 * 2025-01-XX XX:XX - 添加學生端里程碑 API
 *   改動內容：新增 get_student_milestones API，獲取學生所屬團隊的里程碑列表
 *   相關功能：學生里程碑查看
 *   方式：檢查學生身份，獲取學生所屬團隊，返回該團隊的所有里程碑
 * 
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
                    m.ms_priority,
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
            /**
             * 為了完全相容不同版本的資料庫結構，這裡不直接寫欄位名稱，
             * 而是先讀取 requirementdata 的欄位，再動態映射到標準欄位：
             * - req_ID      → 內部使用的「需求ID」
             * - req_title   → 顯示用標題
             * - req_direction（可選）→ 說明文字
             */

            // 讀取欄位資訊
            $columnsStmt = $conn->query("SHOW COLUMNS FROM requirementdata");
            $columnsInfo = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$columnsInfo) {
                json_err('找不到 requirementdata 欄位資訊');
            }

            $allColumns = array_map(static function ($col) {
                return $col['Field'] ?? '';
            }, $columnsInfo);

            // 決定 ID 欄位
            $idCandidates = ['req_ID', 'reqId', 'req_id', 'id', 'ID'];
            $idCol = null;
            foreach ($idCandidates as $cand) {
                if (in_array($cand, $allColumns, true)) {
                    $idCol = $cand;
                    break;
                }
            }
            if ($idCol === null) {
                // 退而求其次：用第一個欄位當 ID
                $idCol = $allColumns[0];
            }

            // 決定標題欄位
            $titleCandidates = ['req_title', 'title', 'reqName', 'name'];
            $titleCol = null;
            foreach ($titleCandidates as $cand) {
                if (in_array($cand, $allColumns, true)) {
                    $titleCol = $cand;
                    break;
                }
            }
            if ($titleCol === null) {
                // 如果找不到，就嘗試用第二個欄位，或回退到 ID 欄位
                $titleCol = $allColumns[1] ?? $idCol;
            }

            // 決定說明欄位（可選）
            $directionCandidates = ['req_direction', 'direction', 'desc', 'description'];
            $directionCol = null;
            foreach ($directionCandidates as $cand) {
                if (in_array($cand, $allColumns, true)) {
                    $directionCol = $cand;
                    break;
                }
            }

            // 是否有狀態欄位
            $hasStatusCol = in_array('req_status', $allColumns, true);

            // 直接抓全部資料，再在 PHP 裡面做過濾 / 映射
            $rawRows = $conn->query("SELECT * FROM requirementdata")->fetchAll(PDO::FETCH_ASSOC);

            $normalized = [];
            foreach ($rawRows as $row) {
                // 如果有狀態欄位，只取啟用中的（req_status = 1）
                if ($hasStatusCol) {
                    $statusVal = (int)($row['req_status'] ?? 0);
                    if ($statusVal !== 1) {
                        continue;
                    }
                }

                $normalized[] = [
                    'req_ID'        => $row[$idCol] ?? null,
                    'req_title'     => $row[$titleCol] ?? '',
                    'req_direction' => $directionCol !== null ? ($row[$directionCol] ?? '') : '',
                ];
            }

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($normalized, JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => '資料讀取失敗：' . $e->getMessage()]);
            exit;
        }
        break;

    // 獲取學生團隊的里程碑列表
    case 'get_student_milestones':
        try {
            $u_ID = $_SESSION['u_ID'] ?? null;
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }
            
            // 檢查是否為學生
            $stmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM userrolesdata 
                WHERE ur_u_ID = ? AND role_ID = 6 AND user_role_status = 1
            ");
            $stmt->execute([$u_ID]);
            if (!$stmt->fetchColumn()) {
                json_err('此功能僅限學生使用', 'NO_PERMISSION', 403);
            }
            
            // 獲取學生所屬的團隊
            // 修改日期：2025-01-XX XX:XX
            // 改動內容：修正欄位名稱，使用兼容性查詢（先嘗試 team_u_ID，失敗則嘗試 u_ID）
            // 相關功能：獲取學生所屬團隊
            // 方式：使用 try-catch 處理不同版本的資料表結構
            try {
                $stmt = $conn->prepare("
                    SELECT DISTINCT t.team_ID
                    FROM teamdata t
                    JOIN teammember tm ON t.team_ID = tm.team_ID
                    WHERE tm.team_u_ID = ? AND t.team_status = 1
                    LIMIT 1
                ");
                $stmt->execute([$u_ID]);
            } catch (Exception $e) {
                // 如果失敗，嘗試使用舊的欄位名稱
                $stmt = $conn->prepare("
                    SELECT DISTINCT t.team_ID
                    FROM teamdata t
                    JOIN teammember tm ON t.team_ID = tm.team_ID
                    WHERE tm.u_ID = ? AND t.team_status = 1
                    LIMIT 1
                ");
                $stmt->execute([$u_ID]);
            }
            $team = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$team) {
                json_ok([]);
                exit;
            }
            
            // 獲取該團隊的所有里程碑
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
                    m.ms_priority,
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
                WHERE m.team_ID = ?
                ORDER BY m.ms_created_d ASC
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$team['team_ID']]);
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
        $ms_priority = isset($p['ms_priority']) ? (int)$p['ms_priority'] : 0;
        
        // 基本需求為可選，req_ID 可以為 0
        // 修改日期：2025-11-16
        // 改動內容：添加優先級欄位處理
        // 相關功能：新增里程碑功能
        // 方式：在 INSERT 語句中添加 ms_priority 欄位，預設狀態為 1(進行中)
        if ($team_ID <= 0) json_err('請選擇團隊');
        if ($ms_title === '') json_err('請輸入里程碑標題');
        if ($ms_start_d === '' || $ms_end_d === '') json_err('請選擇開始和截止時間');
        
        // 驗證時間：截止時間不可小於開始時間
        // 修改日期：2025-01-XX
        // 改動內容：添加時間驗證
        // 相關功能：新增里程碑時間驗證
        // 方式：比較開始時間和截止時間
        if ($ms_start_d && $ms_end_d) {
            $startTime = strtotime($ms_start_d);
            $endTime = strtotime($ms_end_d);
            if ($endTime < $startTime) {
                json_err('截止時間不可小於開始時間');
            }
        }
        
        // 驗證優先級範圍
        if ($ms_priority < 0 || $ms_priority > 3) {
            $ms_priority = 0;
        }
        
        try {
            // 如果 req_ID 為 0，則設為 NULL（允許不關聯基本需求）
            // 修改日期：2025-11-16
            // 改動內容：創建里程碑時，狀態為0（還未開始），等待學生接任務
            // 相關功能：新增里程碑功能
            // 方式：將 ms_status 設為 0
            $req_ID_value = $req_ID > 0 ? $req_ID : null;
            $stmt = $conn->prepare("
                INSERT INTO milesdata 
                (req_ID, team_ID, ms_title, ms_desc, ms_start_d, ms_end_d, ms_status, ms_priority, ms_created_d)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW())
            ");
            $stmt->execute([$req_ID_value, $team_ID, $ms_title, $ms_desc, $ms_start_d, $ms_end_d, $ms_priority]);
            
            $ms_ID = (int)$conn->lastInsertId();
            
            // 獲取團隊資訊
            $stmt = $conn->prepare("SELECT team_project_name FROM teamdata WHERE team_ID = ?");
            $stmt->execute([$team_ID]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);
            $teamName = $team['team_project_name'] ?? "團隊 {$team_ID}";
            
            // 獲取團隊的所有成員（學生，不包括指導老師）
            // 修改日期：2025-01-XX
            // 改動內容：新增里程碑時，通知團隊的所有學生
            // 相關功能：新增里程碑通知
            // 方式：查詢團隊成員並排除指導老師（role_ID=4），使用 msgdata 和 msgtargetdata 創建通知
            $teamMembers = [];
            try {
                // 先嘗試使用 team_u_ID，排除指導老師
                $stmt = $conn->prepare("
                    SELECT DISTINCT tm.team_u_ID
                    FROM teammember tm
                    LEFT JOIN userrolesdata ur ON ur.ur_u_ID = tm.team_u_ID AND ur.role_ID = 4 AND ur.user_role_status = 1
                    WHERE tm.team_ID = ? AND ur.ur_u_ID IS NULL
                ");
                $stmt->execute([$team_ID]);
                $teamMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // 如果沒有找到，嘗試使用 u_ID
                if (empty($teamMembers)) {
                    $stmt = $conn->prepare("
                        SELECT DISTINCT tm.u_ID
                        FROM teammember tm
                        LEFT JOIN userrolesdata ur ON ur.ur_u_ID = tm.u_ID AND ur.role_ID = 4 AND ur.user_role_status = 1
                        WHERE tm.team_ID = ? AND ur.ur_u_ID IS NULL
                    ");
                    $stmt->execute([$team_ID]);
                    $teamMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }
            } catch (Exception $e) {
                // 如果失敗，嘗試使用舊的欄位名稱，排除指導老師
                try {
                    $stmt = $conn->prepare("
                        SELECT DISTINCT tm.u_ID
                        FROM teammember tm
                        LEFT JOIN userrolesdata ur ON ur.ur_u_ID = tm.u_ID AND ur.role_ID = 4 AND ur.user_role_status = 1
                        WHERE tm.team_ID = ? AND ur.ur_u_ID IS NULL
                    ");
                    $stmt->execute([$team_ID]);
                    $teamMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e2) {
                    // 如果還是失敗，記錄錯誤但不中斷流程
                    error_log("獲取團隊成員失敗: " . $e2->getMessage());
                    $teamMembers = [];
                }
            }
            
            // 為團隊所有成員創建通知
            if (count($teamMembers) > 0) {
                try {
                    // 獲取指導老師姓名
                    $stmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                    $stmt->execute([$u_ID]);
                    $teacherName = $stmt->fetchColumn() ?: '指導老師';
                    
                    $stmt = $conn->prepare("
                        INSERT INTO msgdata 
                        (msg_title, msg_content, msg_type, msg_status, msg_start_d, msg_created_d, msg_a_u_ID)
                        VALUES (?, ?, 'SYSTEM_NOTICE', 1, NOW(), NOW(), 'system')
                    ");
                    $msgTitle = "新里程碑通知";
                    $msgContent = "{$teacherName} 為團隊「{$teamName}」新增了里程碑「{$ms_title}」，請前往查看。";
                    $stmt->execute([$msgTitle, $msgContent]);
                    $msg_ID = $conn->lastInsertId();
                    
                    if ($msg_ID > 0) {
                        // 為每位團隊成員添加通知目標
                        $stmt = $conn->prepare("
                            INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID)
                            VALUES (?, 'USER', ?)
                        ");
                        foreach ($teamMembers as $member_ID) {
                            if (!empty($member_ID)) {
                                try {
                                    $stmt->execute([$msg_ID, $member_ID]);
                                } catch (Exception $e) {
                                    error_log("為成員 {$member_ID} 添加通知目標失敗: " . $e->getMessage());
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    // 記錄錯誤但不中斷流程
                    error_log("創建通知失敗: " . $e->getMessage());
                }
            } else {
                // 如果沒有找到成員，記錄警告
                error_log("警告：團隊 {$team_ID} 沒有找到成員，無法發送通知");
            }
            
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
        $ms_priority = isset($p['ms_priority']) ? (int)$p['ms_priority'] : 0;
        
        if ($ms_ID <= 0) json_err('里程碑ID無效');
        // 基本需求為可選，req_ID 可以為 0
        // 修改日期：2025-11-16
        // 改動內容：添加優先級欄位處理
        // 相關功能：更新里程碑功能
        // 方式：在 UPDATE 語句中添加 ms_priority 欄位
        if ($team_ID <= 0) json_err('請選擇團隊');
        if ($ms_title === '') json_err('請輸入里程碑標題');
        
        // 驗證時間：截止時間不可小於開始時間
        // 修改日期：2025-01-XX
        // 改動內容：添加時間驗證
        // 相關功能：更新里程碑時間驗證
        // 方式：比較開始時間和截止時間
        if ($ms_start_d && $ms_end_d) {
            $startTime = strtotime($ms_start_d);
            $endTime = strtotime($ms_end_d);
            if ($endTime < $startTime) {
                json_err('截止時間不可小於開始時間');
            }
        }
        
        // 驗證優先級範圍
        if ($ms_priority < 0 || $ms_priority > 3) {
            $ms_priority = 0;
        }
        
        try {
            // 如果 req_ID 為 0，則設為 NULL（允許不關聯基本需求）
            $req_ID_value = $req_ID > 0 ? $req_ID : null;
            $stmt = $conn->prepare("
                UPDATE milesdata 
                SET req_ID = ?, team_ID = ?, ms_title = ?, ms_desc = ?, 
                    ms_start_d = ?, ms_end_d = ?, ms_status = ?, ms_priority = ?
                WHERE ms_ID = ?
            ");
            $stmt->execute([$req_ID_value, $team_ID, $ms_title, $ms_desc, $ms_start_d, $ms_end_d, $ms_status, $ms_priority, $ms_ID]);
            
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

    // 學生接任務
    case 'accept_milestone':
        $u_ID = $_SESSION['u_ID'] ?? null;
        if (!$u_ID) {
            json_err('請先登入', 'NOT_LOGGED_IN', 401);
        }
        
        // 檢查是否為學生
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM userrolesdata 
            WHERE ur_u_ID = ? AND role_ID = 6 AND user_role_status = 1
        ");
        $stmt->execute([$u_ID]);
        if (!$stmt->fetchColumn()) {
            json_err('此功能僅限學生使用', 'NO_PERMISSION', 403);
        }
        
        $ms_ID = isset($p['ms_ID']) ? (int)$p['ms_ID'] : 0;
        if ($ms_ID <= 0) json_err('里程碑ID無效');
        
        try {
            // 檢查里程碑是否存在且屬於學生的團隊，且狀態為0（還未開始）
            // 使用兼容性查詢處理不同版本的資料表結構
            $milestone = null;
            try {
                $stmt = $conn->prepare("
                    SELECT m.ms_ID, m.team_ID, m.ms_title, m.ms_status, t.team_project_name
                    FROM milesdata m
                    JOIN teamdata t ON m.team_ID = t.team_ID
                    JOIN teammember tm ON t.team_ID = tm.team_ID
                    WHERE m.ms_ID = ? 
                      AND tm.team_u_ID = ?
                      AND t.team_status = 1
                      AND m.ms_status = 0
                    LIMIT 1
                ");
                $stmt->execute([$ms_ID, $u_ID]);
                $milestone = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // 如果失敗，嘗試使用舊的欄位名稱
                $stmt = $conn->prepare("
                    SELECT m.ms_ID, m.team_ID, m.ms_title, m.ms_status, t.team_project_name
                    FROM milesdata m
                    JOIN teamdata t ON m.team_ID = t.team_ID
                    JOIN teammember tm ON t.team_ID = tm.team_ID
                    WHERE m.ms_ID = ? 
                      AND tm.u_ID = ?
                      AND t.team_status = 1
                      AND m.ms_status = 0
                    LIMIT 1
                ");
                $stmt->execute([$ms_ID, $u_ID]);
                $milestone = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if (!$milestone) {
                json_err('里程碑不存在、您無權限操作或任務已被接取');
            }
            
            // 更新里程碑：開始時間為當前時間，狀態變為1（進行中）
            $stmt = $conn->prepare("
                UPDATE milesdata 
                SET ms_start_d = NOW(), ms_status = 1
                WHERE ms_ID = ?
            ");
            $stmt->execute([$ms_ID]);
            
            json_ok(['message' => '任務已接取，開始計時']);
        } catch (Throwable $e) {
            json_err('操作失敗：'.$e->getMessage());
        }
        break;

    // 學生完成里程碑
    case 'complete_milestone':
        $u_ID = $_SESSION['u_ID'] ?? null;
        if (!$u_ID) {
            json_err('請先登入', 'NOT_LOGGED_IN', 401);
        }
        
        // 檢查是否為學生
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM userrolesdata 
            WHERE ur_u_ID = ? AND role_ID = 6 AND user_role_status = 1
        ");
        $stmt->execute([$u_ID]);
        if (!$stmt->fetchColumn()) {
            json_err('此功能僅限學生使用', 'NO_PERMISSION', 403);
        }
        
        $ms_ID = isset($p['ms_ID']) ? (int)$p['ms_ID'] : 0;
        if ($ms_ID <= 0) json_err('里程碑ID無效');
        
        try {
            // 檢查里程碑是否存在且屬於學生的團隊
            // 修改日期：2025-11-16
            // 改動內容：允許進行中(1)與退回(2)的里程碑提交完成
            // 相關功能：學生提交里程碑完成
            // 方式：檢查里程碑是否存在，允許狀態為 1 或 2 的里程碑提交
            // 使用兼容性查詢處理不同版本的資料表結構
            $milestone = null;
            try {
                $stmt = $conn->prepare("
                    SELECT m.ms_ID, m.team_ID, m.ms_title, m.ms_status, t.team_project_name
                    FROM milesdata m
                    JOIN teamdata t ON m.team_ID = t.team_ID
                    JOIN teammember tm ON t.team_ID = tm.team_ID
                    WHERE m.ms_ID = ? 
                      AND tm.team_u_ID = ?
                      AND t.team_status = 1
                      AND (m.ms_status = 1 OR m.ms_status = 2)
                    LIMIT 1
                ");
                $stmt->execute([$ms_ID, $u_ID]);
                $milestone = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // 如果失敗，嘗試使用舊的欄位名稱
                $stmt = $conn->prepare("
                    SELECT m.ms_ID, m.team_ID, m.ms_title, m.ms_status, t.team_project_name
                    FROM milesdata m
                    JOIN teamdata t ON m.team_ID = t.team_ID
                    JOIN teammember tm ON t.team_ID = tm.team_ID
                    WHERE m.ms_ID = ? 
                      AND tm.u_ID = ?
                      AND t.team_status = 1
                      AND (m.ms_status = 1 OR m.ms_status = 2)
                    LIMIT 1
                ");
                $stmt->execute([$ms_ID, $u_ID]);
                $milestone = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if (!$milestone) {
                json_err('里程碑不存在或您無權限操作');
            }
            
            // 更新里程碑狀態為待審核（直接寫入資料表）
            // 修改日期：2025-11-16
            // 改動內容：學生提交完成時，寫入完成者與完成時間，並將狀態設為 4(待審核)
            // 相關功能：學生提交里程碑完成
            // 方式：使用 UPDATE 語句直接寫入資料
            $stmt = $conn->prepare("
                UPDATE milesdata 
                SET ms_u_ID = ?, ms_completed_d = NOW(), ms_status = 4
                WHERE ms_ID = ?
            ");
            $stmt->execute([$u_ID, $ms_ID]);
            
            // 獲取團隊的指導老師（role_ID=4）
            // 修改日期：2025-11-16
            // 改動內容：修正查詢邏輯，使用兼容性處理不同版本的資料表結構
            // 相關功能：獲取團隊指導老師
            // 方式：先嘗試 team_u_ID，失敗則嘗試 u_ID
            $teachers = [];
            try {
                // 先嘗試使用 team_u_ID
                $stmt = $conn->prepare("
                    SELECT DISTINCT tm.team_u_ID
                    FROM teammember tm
                    JOIN userrolesdata ur ON ur.ur_u_ID = tm.team_u_ID
                    WHERE tm.team_ID = ? AND ur.role_ID = 4 AND ur.user_role_status = 1
                ");
                $stmt->execute([$milestone['team_ID']]);
                $teachers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // 如果沒有找到，嘗試使用 u_ID
                if (empty($teachers)) {
                    $stmt = $conn->prepare("
                        SELECT DISTINCT tm.u_ID
                        FROM teammember tm
                        JOIN userrolesdata ur ON ur.ur_u_ID = tm.u_ID
                        WHERE tm.team_ID = ? AND ur.role_ID = 4 AND ur.user_role_status = 1
                    ");
                    $stmt->execute([$milestone['team_ID']]);
                    $teachers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }
            } catch (Exception $e) {
                // 如果失敗，嘗試使用舊的欄位名稱
                try {
                    $stmt = $conn->prepare("
                        SELECT DISTINCT tm.u_ID
                        FROM teammember tm
                        JOIN userrolesdata ur ON ur.ur_u_ID = tm.u_ID
                        WHERE tm.team_ID = ? AND ur.role_ID = 4 AND ur.user_role_status = 1
                    ");
                    $stmt->execute([$milestone['team_ID']]);
                    $teachers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e2) {
                    // 如果還是失敗，記錄錯誤但不中斷流程
                    error_log("獲取指導老師失敗: " . $e2->getMessage());
                    $teachers = [];
                }
            }
            
            // 為每位指導老師創建通知
            // 修改日期：2025-11-16
            // 改動內容：學生完成里程碑時，通知指導老師
            // 相關功能：完成里程碑通知
            // 方式：使用 msgdata 和 msgtargetdata 表創建通知
            if (count($teachers) > 0) {
                // 獲取學生姓名
                $stmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                $stmt->execute([$u_ID]);
                $studentName = $stmt->fetchColumn() ?: '學生';
                
                // 創建通知
                // 修改日期：2025-11-16
                // 改動內容：通知發送者改為系統（u_ID='system'）
                // 相關功能：完成里程碑通知
                // 方式：將 msg_a_u_ID 設為 'system'
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO msgdata 
                        (msg_title, msg_content, msg_type, msg_status, msg_start_d, msg_created_d, msg_a_u_ID)
                        VALUES (?, ?, 'SYSTEM_NOTICE', 1, NOW(), NOW(), 'system')
                    ");
                    $msgTitle = "里程碑完成通知";
                    $msgContent = "團隊「{$milestone['team_project_name']}」的里程碑「{$milestone['ms_title']}」已由 {$studentName} 標記為完成。";
                    $stmt->execute([$msgTitle, $msgContent]);
                    $msg_ID = $conn->lastInsertId();
                    
                    if ($msg_ID > 0) {
                        // 為每位指導老師添加通知目標
                        $stmt = $conn->prepare("
                            INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID)
                            VALUES (?, 'USER', ?)
                        ");
                        foreach ($teachers as $teacher_ID) {
                            if (!empty($teacher_ID)) {
                                try {
                                    $stmt->execute([$msg_ID, $teacher_ID]);
                                } catch (Exception $e) {
                                    error_log("為老師 {$teacher_ID} 添加通知目標失敗: " . $e->getMessage());
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    // 記錄錯誤但不中斷流程
                    error_log("創建通知失敗: " . $e->getMessage());
                }
            } else {
                // 如果沒有找到老師，記錄警告
                error_log("警告：團隊 {$milestone['team_ID']} 沒有找到指導老師，無法發送通知");
            }
            
            json_ok(['message' => '里程碑已標記為完成']);
        } catch (Throwable $e) {
            json_err('操作失敗：'.$e->getMessage());
        }
        break;

    // 審核里程碑（通過/退回）
    case 'approve_milestone':
        $u_ID = checkTeacherPermission();
        
        $ms_ID = isset($p['ms_ID']) ? (int)$p['ms_ID'] : 0;
        $action = trim($p['action'] ?? ''); // 'approve' 或 'reject'
        
        if ($ms_ID <= 0) json_err('里程碑ID無效');
        if (!in_array($action, ['approve', 'reject'])) json_err('無效的操作');
        
        try {
            // 先獲取里程碑資訊
            $stmt = $conn->prepare("
                SELECT m.ms_ID, m.team_ID, m.ms_title, t.team_project_name
                FROM milesdata m
                JOIN teamdata t ON m.team_ID = t.team_ID
                WHERE m.ms_ID = ?
            ");
            $stmt->execute([$ms_ID]);
            $milestone = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$milestone) {
                json_err('里程碑不存在');
            }
            
            if ($action === 'approve') {
                // 通過：保留學生提交的完成時間與完成者，寫入審核者與審核時間，狀態改為 3(已完成)
                $stmt = $conn->prepare("
                    UPDATE milesdata 
                    SET ms_approved_u_ID = ?, ms_approved_d = NOW(), ms_status = 3
                    WHERE ms_ID = ?
                ");
                $stmt->execute([$u_ID, $ms_ID]);
                
                // 獲取團隊的所有成員（學生，不包括指導老師）
                // 修改日期：2025-01-XX
                // 改動內容：老師完成里程碑時，通知團隊的所有成員（學生），不包括指導老師
                // 相關功能：完成里程碑通知
                // 方式：查詢團隊成員並排除指導老師（role_ID=4）
                $teamMembers = [];
                try {
                    // 先嘗試使用 team_u_ID，排除指導老師
                    $stmt = $conn->prepare("
                        SELECT DISTINCT tm.team_u_ID
                        FROM teammember tm
                        LEFT JOIN userrolesdata ur ON ur.ur_u_ID = tm.team_u_ID AND ur.role_ID = 4 AND ur.user_role_status = 1
                        WHERE tm.team_ID = ? AND ur.ur_u_ID IS NULL
                    ");
                    $stmt->execute([$milestone['team_ID']]);
                    $teamMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // 如果沒有找到，嘗試使用 u_ID
                    if (empty($teamMembers)) {
                        $stmt = $conn->prepare("
                            SELECT DISTINCT tm.u_ID
                            FROM teammember tm
                            LEFT JOIN userrolesdata ur ON ur.ur_u_ID = tm.u_ID AND ur.role_ID = 4 AND ur.user_role_status = 1
                            WHERE tm.team_ID = ? AND ur.ur_u_ID IS NULL
                        ");
                        $stmt->execute([$milestone['team_ID']]);
                        $teamMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    }
                } catch (Exception $e) {
                    // 如果失敗，嘗試使用舊的欄位名稱，排除指導老師
                    try {
                        $stmt = $conn->prepare("
                            SELECT DISTINCT tm.u_ID
                            FROM teammember tm
                            LEFT JOIN userrolesdata ur ON ur.ur_u_ID = tm.u_ID AND ur.role_ID = 4 AND ur.user_role_status = 1
                            WHERE tm.team_ID = ? AND ur.ur_u_ID IS NULL
                        ");
                        $stmt->execute([$milestone['team_ID']]);
                        $teamMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    } catch (Exception $e2) {
                        error_log("獲取團隊成員失敗: " . $e2->getMessage());
                        $teamMembers = [];
                    }
                }
                
                // 獲取指導老師姓名
                $stmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                $stmt->execute([$u_ID]);
                $teacherName = $stmt->fetchColumn() ?: '指導老師';
                
                // 為團隊所有成員創建通知
                if (count($teamMembers) > 0) {
                    try {
                        $stmt = $conn->prepare("
                            INSERT INTO msgdata 
                            (msg_title, msg_content, msg_type, msg_status, msg_start_d, msg_created_d, msg_a_u_ID)
                            VALUES (?, ?, 'SYSTEM_NOTICE', 1, NOW(), NOW(), 'system')
                        ");
                        $msgTitle = "里程碑完成通知";
                        $msgContent = "團隊「{$milestone['team_project_name']}」的里程碑「{$milestone['ms_title']}」已被 {$teacherName} 審核通過。";
                        $stmt->execute([$msgTitle, $msgContent]);
                        $msg_ID = $conn->lastInsertId();
                        
                        if ($msg_ID > 0) {
                            // 為每位團隊成員添加通知目標
                            $stmt = $conn->prepare("
                                INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID)
                                VALUES (?, 'USER', ?)
                            ");
                            foreach ($teamMembers as $member_ID) {
                                if (!empty($member_ID)) {
                                    try {
                                        $stmt->execute([$msg_ID, $member_ID]);
                                    } catch (Exception $e) {
                                        error_log("為成員 {$member_ID} 添加通知目標失敗: " . $e->getMessage());
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        // 記錄錯誤但不中斷流程
                        error_log("創建完成通知失敗: " . $e->getMessage());
                    }
                } else {
                    error_log("警告：團隊 {$milestone['team_ID']} 沒有找到成員，無法發送完成通知");
                }
            } elseif ($action === 'reject') {
                // 退回：保留完成時間與完成者，清除審核資訊，狀態改為 2(退回)
                $stmt = $conn->prepare("
                    UPDATE milesdata 
                    SET ms_approved_u_ID = NULL, ms_approved_d = NULL, ms_status = 2
                    WHERE ms_ID = ?
                ");
                $stmt->execute([$ms_ID]);
                
                // 獲取團隊的所有成員（學生，不包括指導老師）
                // 修改日期：2025-11-18
                // 改動內容：退回里程碑時，通知團隊的所有成員（學生），不包括指導老師
                // 相關功能：退回里程碑通知
                // 方式：查詢團隊成員並排除指導老師（role_ID=4）
                $teamMembers = [];
                try {
                    // 先嘗試使用 team_u_ID，排除指導老師
                    $stmt = $conn->prepare("
                        SELECT DISTINCT tm.team_u_ID
                        FROM teammember tm
                        LEFT JOIN userrolesdata ur ON ur.ur_u_ID = tm.team_u_ID AND ur.role_ID = 4 AND ur.user_role_status = 1
                        WHERE tm.team_ID = ? AND ur.ur_u_ID IS NULL
                    ");
                    $stmt->execute([$milestone['team_ID']]);
                    $teamMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // 如果沒有找到，嘗試使用 u_ID
                    if (empty($teamMembers)) {
                        $stmt = $conn->prepare("
                            SELECT DISTINCT tm.u_ID
                            FROM teammember tm
                            LEFT JOIN userrolesdata ur ON ur.ur_u_ID = tm.u_ID AND ur.role_ID = 4 AND ur.user_role_status = 1
                            WHERE tm.team_ID = ? AND ur.ur_u_ID IS NULL
                        ");
                        $stmt->execute([$milestone['team_ID']]);
                        $teamMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    }
                } catch (Exception $e) {
                    // 如果失敗，嘗試使用舊的欄位名稱，排除指導老師
                    try {
                        $stmt = $conn->prepare("
                            SELECT DISTINCT tm.u_ID
                            FROM teammember tm
                            LEFT JOIN userrolesdata ur ON ur.ur_u_ID = tm.u_ID AND ur.role_ID = 4 AND ur.user_role_status = 1
                            WHERE tm.team_ID = ? AND ur.ur_u_ID IS NULL
                        ");
                        $stmt->execute([$milestone['team_ID']]);
                        $teamMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    } catch (Exception $e2) {
                        error_log("獲取團隊成員失敗: " . $e2->getMessage());
                        $teamMembers = [];
                    }
                }
                
                // 獲取指導老師姓名
                $stmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                $stmt->execute([$u_ID]);
                $teacherName = $stmt->fetchColumn() ?: '指導老師';
                
                // 為團隊所有成員創建通知
                if (count($teamMembers) > 0) {
                    try {
                        $stmt = $conn->prepare("
                            INSERT INTO msgdata 
                            (msg_title, msg_content, msg_type, msg_status, msg_start_d, msg_created_d, msg_a_u_ID)
                            VALUES (?, ?, 'SYSTEM_NOTICE', 1, NOW(), NOW(), 'system')
                        ");
                        $msgTitle = "里程碑退回通知";
                        $msgContent = "團隊「{$milestone['team_project_name']}」的里程碑「{$milestone['ms_title']}」已被 {$teacherName} 退回，請重新提交。";
                        $stmt->execute([$msgTitle, $msgContent]);
                        $msg_ID = $conn->lastInsertId();
                        
                        if ($msg_ID > 0) {
                            // 為每位團隊成員添加通知目標
                            $stmt = $conn->prepare("
                                INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID)
                                VALUES (?, 'USER', ?)
                            ");
                            foreach ($teamMembers as $member_ID) {
                                if (!empty($member_ID)) {
                                    try {
                                        $stmt->execute([$msg_ID, $member_ID]);
                                    } catch (Exception $e) {
                                        error_log("為成員 {$member_ID} 添加通知目標失敗: " . $e->getMessage());
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        // 記錄錯誤但不中斷流程
                        error_log("創建退回通知失敗: " . $e->getMessage());
                    }
                } else {
                    error_log("警告：團隊 {$milestone['team_ID']} 沒有找到成員，無法發送退回通知");
                }
            }
            
            json_ok(['message' => '操作成功']);
        } catch (Throwable $e) {
            json_err('操作失敗：'.$e->getMessage());
        }
        break;

    // 獲取甘特圖資料
    case 'get_gantt_data':
        $u_ID = $_SESSION['u_ID'] ?? null;
        if (!$u_ID) {
            json_err('請先登入', 'NOT_LOGGED_IN', 401);
        }
        
        $team_ID = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : 0;
        
        try {
            $milestones = [];
            $startDate = null;
            $endDate = null;
            
            if ($team_ID > 0) {
                // 獲取指定團隊的里程碑
                $stmt = $conn->prepare("
                    SELECT 
                        m.ms_ID,
                        m.ms_title,
                        m.ms_desc,
                        m.ms_start_d,
                        m.ms_end_d,
                        m.ms_status,
                        m.ms_priority,
                        m.ms_created_d,
                        m.ms_completed_d,
                        m.ms_approved_d,
                        m.ms_u_ID,
                        m.ms_approved_u_ID,
                        m.req_ID,
                        t.team_project_name as team_name,
                        u.u_name as student_name,
                        r.req_title,
                        u2.u_name as approver_name
                    FROM milesdata m
                    JOIN teamdata t ON m.team_ID = t.team_ID
                    LEFT JOIN userdata u ON m.ms_u_ID = u.u_ID
                    LEFT JOIN requirementdata r ON m.req_ID = r.req_ID
                    LEFT JOIN userdata u2 ON m.ms_approved_u_ID = u2.u_ID
                    WHERE m.team_ID = ? AND t.team_status = 1
                    ORDER BY COALESCE(m.ms_start_d, m.ms_created_d) ASC, m.ms_created_d ASC
                ");
                $stmt->execute([$team_ID]);
                $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // 獲取當前用戶相關的里程碑
                $role_ID = $_SESSION['role_ID'] ?? null;
                
                if ($role_ID == 6) {
                    // 學生：獲取所屬團隊的里程碑
                    try {
                        $stmt = $conn->prepare("
                            SELECT DISTINCT t.team_ID
                            FROM teamdata t
                            JOIN teammember tm ON t.team_ID = tm.team_ID
                            WHERE tm.team_u_ID = ? AND t.team_status = 1
                            LIMIT 1
                        ");
                        $stmt->execute([$u_ID]);
                    } catch (Exception $e) {
                        $stmt = $conn->prepare("
                            SELECT DISTINCT t.team_ID
                            FROM teamdata t
                            JOIN teammember tm ON t.team_ID = tm.team_ID
                            WHERE tm.u_ID = ? AND t.team_status = 1
                            LIMIT 1
                        ");
                        $stmt->execute([$u_ID]);
                    }
                    $team = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($team) {
                        $stmt = $conn->prepare("
                            SELECT 
                                m.ms_ID,
                                m.ms_title,
                                m.ms_desc,
                                m.ms_start_d,
                                m.ms_end_d,
                                m.ms_status,
                                m.ms_priority,
                                m.ms_created_d,
                                m.ms_completed_d,
                                m.ms_approved_d,
                                m.ms_u_ID,
                                m.ms_approved_u_ID,
                                m.req_ID,
                                t.team_project_name as team_name,
                                u.u_name as student_name,
                                r.req_title,
                                u2.u_name as approver_name
                            FROM milesdata m
                            JOIN teamdata t ON m.team_ID = t.team_ID
                            LEFT JOIN userdata u ON m.ms_u_ID = u.u_ID
                            LEFT JOIN requirementdata r ON m.req_ID = r.req_ID
                            LEFT JOIN userdata u2 ON m.ms_approved_u_ID = u2.u_ID
                            WHERE m.team_ID = ? AND t.team_status = 1
                            ORDER BY COALESCE(m.ms_start_d, m.ms_created_d) ASC, m.ms_created_d ASC
                        ");
                        $stmt->execute([$team['team_ID']]);
                        $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                } elseif ($role_ID == 4) {
                    // 老師：獲取所有指導的團隊的里程碑
                    try {
                        $stmt = $conn->prepare("
                            SELECT DISTINCT t.team_ID
                            FROM teamdata t
                            JOIN teammember tm ON t.team_ID = tm.team_ID
                            JOIN userrolesdata ur ON ur.ur_u_ID = tm.team_u_ID
                            WHERE ur.ur_u_ID = ? AND ur.role_ID = 4 AND ur.user_role_status = 1 AND t.team_status = 1
                        ");
                        $stmt->execute([$u_ID]);
                    } catch (Exception $e) {
                        $stmt = $conn->prepare("
                            SELECT DISTINCT t.team_ID
                            FROM teamdata t
                            JOIN teammember tm ON t.team_ID = tm.team_ID
                            JOIN userrolesdata ur ON ur.ur_u_ID = tm.u_ID
                            WHERE ur.ur_u_ID = ? AND ur.role_ID = 4 AND ur.user_role_status = 1 AND t.team_status = 1
                        ");
                        $stmt->execute([$u_ID]);
                    }
                    $teams = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (count($teams) > 0) {
                        $placeholders = implode(',', array_fill(0, count($teams), '?'));
                        $stmt = $conn->prepare("
                            SELECT 
                                m.ms_ID,
                                m.ms_title,
                                m.ms_desc,
                                m.ms_start_d,
                                m.ms_end_d,
                                m.ms_status,
                                m.ms_priority,
                                m.ms_created_d,
                                m.ms_completed_d,
                                m.ms_approved_d,
                                m.ms_u_ID,
                                m.ms_approved_u_ID,
                                m.req_ID,
                                t.team_project_name as team_name,
                                u.u_name as student_name,
                                r.req_title,
                                u2.u_name as approver_name
                            FROM milesdata m
                            JOIN teamdata t ON m.team_ID = t.team_ID
                            LEFT JOIN userdata u ON m.ms_u_ID = u.u_ID
                            LEFT JOIN requirementdata r ON m.req_ID = r.req_ID
                            LEFT JOIN userdata u2 ON m.ms_approved_u_ID = u2.u_ID
                            WHERE m.team_ID IN ($placeholders) AND t.team_status = 1
                            ORDER BY COALESCE(m.ms_start_d, m.ms_created_d) ASC, m.ms_created_d ASC
                        ");
                        $stmt->execute($teams);
                        $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                }
            }
            
            // 計算日期範圍
            if (count($milestones) > 0) {
                $dates = [];
                foreach ($milestones as $m) {
                    // 如果沒有開始時間，使用創建時間或結束時間
                    if (!empty($m['ms_start_d'])) {
                        try {
                            $dates[] = new DateTime($m['ms_start_d']);
                        } catch (Exception $e) {
                            // 忽略無效日期
                        }
                    } elseif (!empty($m['ms_end_d'])) {
                        // 如果沒有開始時間但有結束時間，使用結束時間作為開始時間
                        try {
                            $dates[] = new DateTime($m['ms_end_d']);
                        } catch (Exception $e) {
                            // 忽略無效日期
                        }
                    }
                    
                    if (!empty($m['ms_end_d'])) {
                        try {
                            $dates[] = new DateTime($m['ms_end_d']);
                        } catch (Exception $e) {
                            // 忽略無效日期
                        }
                    } elseif (!empty($m['ms_start_d'])) {
                        // 如果沒有結束時間但有開始時間，使用開始時間作為結束時間
                        try {
                            $dates[] = new DateTime($m['ms_start_d']);
                        } catch (Exception $e) {
                            // 忽略無效日期
                        }
                    }
                }
                
                if (count($dates) > 0) {
                    usort($dates, function($a, $b) {
                        if ($a < $b) return -1;
                        if ($a > $b) return 1;
                        return 0;
                    });
                    $startDate = $dates[0]->format('Y-m-d');
                    $endDate = $dates[count($dates) - 1]->format('Y-m-d');
                    
                    // 擴展範圍，前後各加7天
                    try {
                        $startDateObj = new DateTime($startDate);
                        $startDateObj->modify('-7 days');
                        $startDate = $startDateObj->format('Y-m-d');
                    } catch (Exception $e) {
                        // 如果失敗，使用原日期
                    }
                    
                    try {
                        $endDateObj = new DateTime($endDate);
                        $endDateObj->modify('+7 days');
                        $endDate = $endDateObj->format('Y-m-d');
                    } catch (Exception $e) {
                        // 如果失敗，使用原日期
                    }
                } else {
                    // 如果沒有任何有效日期，使用當前日期範圍
                    $startDate = date('Y-m-d', strtotime('-30 days'));
                    $endDate = date('Y-m-d', strtotime('+30 days'));
                }
            } else {
                // 如果沒有里程碑，返回空數組和當前日期範圍
                $startDate = date('Y-m-d', strtotime('-30 days'));
                $endDate = date('Y-m-d', strtotime('+30 days'));
            }
            
            json_ok([
                'milestones' => $milestones,
                'startDate' => $startDate,
                'endDate' => $endDate
            ]);
        } catch (Throwable $e) {
            json_err('載入甘特圖資料失敗：'.$e->getMessage());
        }
        break;
}

