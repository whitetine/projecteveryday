<?php
session_start();
require "../includes/pdo.php";
header("Content-Type: application/json; charset=utf-8");

date_default_timezone_set("Asia/Taipei");

/* ==========================================
   權限：主任 (role_ID = 1) 和 科辦 (role_ID = 2)
========================================== */
$role_ID = $_SESSION["role_ID"] ?? null;
if (!isset($role_ID) || !in_array($role_ID, [1, 2])) {
    echo json_encode(["success" => false, "msg" => "無權限"]);
    exit;
}

$u_ID = $_SESSION["u_ID"];
$action = $_GET["action"] ?? $_POST["action"] ?? "";

/* 回傳格式統一 */
function respond($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

/* PDO 錯誤 */
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ==========================================
   action: listCohorts
   取得啟用中屆別
========================================== */
if ($action === "listCohorts") {

    $sql = "SELECT cohort_ID, cohort_name
            FROM cohortdata
            WHERE cohort_status = 1
            ORDER BY cohort_ID DESC";

    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    respond(["success" => true, "data" => $rows]);
}

/* ==========================================
   action: listGroups
   取得該屆的類組列表
========================================== */
if ($action === "listGroups") {

    $cohort_ID = $_GET["cohort_ID"] ?? 0;
    
    if (!$cohort_ID) {
        respond(["success" => false, "msg" => "缺少屆別參數"]);
    }

    $sql = "SELECT DISTINCT 
                g.group_ID,
                g.group_name
            FROM groupdata g
            JOIN teamdata t ON t.group_ID = g.group_ID
            WHERE t.cohort_ID = ?
              AND t.team_status = 1
              AND g.group_status = 1
            ORDER BY g.group_ID";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$cohort_ID]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond(["success" => true, "data" => $rows]);
}

/* ==========================================
   action: listTitles
   取得該屆別的所有已使用過的標題（去重）
========================================== */
if ($action === "listTitles") {
    
    try {
        $cohort_ID = $_GET["cohort_ID"] ?? 0;
        
        if (!$cohort_ID) {
            respond(["success" => false, "msg" => "缺少參數"]);
        }
        
        // 檢查是否有 tinforma_title 欄位
        $hasTitleField = false;
        try {
            $checkStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_title'");
            $hasTitleField = $checkStmt->rowCount() > 0;
        } catch (Throwable $e) {
            $hasTitleField = false;
        }
        
        // 從 timeinformadata 表取得該屆別的所有已使用過的標題（去重）
        // 通過 timedata 關聯 teamdata 來篩選
        if ($hasTitleField) {
            // 如果有 tinforma_title 欄位，優先使用它
            $sql = "SELECT DISTINCT ti.tinforma_ID, ti.tinforma_title as title_name
                    FROM timeinformadata ti
                    JOIN timedata td ON ti.tinforma_ID = td.tinforma_ID
                    JOIN teamdata t ON td.team_ID = t.team_ID
                    WHERE t.cohort_ID = ? 
                      AND t.team_status = 1
                      AND ti.tinforma_title IS NOT NULL
                      AND TRIM(ti.tinforma_title) != ''
                    ORDER BY COALESCE(ti.tinforma_update_d, ti.tinforma_create_d) DESC";
        } else {
            // 如果沒有 tinforma_title 欄位，使用 tinforma_content
            $sql = "SELECT DISTINCT ti.tinforma_ID, ti.tinforma_content as title_name
                    FROM timeinformadata ti
                    JOIN timedata td ON ti.tinforma_ID = td.tinforma_ID
                    JOIN teamdata t ON td.team_ID = t.team_ID
                    WHERE t.cohort_ID = ? 
                      AND t.team_status = 1
                      AND ti.tinforma_content IS NOT NULL
                      AND TRIM(ti.tinforma_content) != ''
                    ORDER BY ti.tinforma_create_d DESC";
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$cohort_ID]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 提取標題陣列
        $titles = [];
        foreach ($rows as $row) {
            $title = trim($row['title_name'] ?? '');
            if ($title && !in_array($title, $titles)) {
                $titles[] = $title;
            }
        }
        
        // 去重並過濾空值
        $titles = array_values(array_unique(array_filter($titles)));
        
        respond(["success" => true, "data" => $titles]);
        
    } catch (Throwable $e) {
        error_log("listTitles 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "載入標題列表失敗"]);
    }
}

/* ==========================================
   action: getTitleInfo
   取得標題的資訊（最新更新時間、團隊數量）
========================================== */
if ($action === "getTitleInfo") {
    
    try {
        $cohort_ID = $_GET["cohort_ID"] ?? 0;
        $title = $_GET["title"] ?? "";
        
        if (!$cohort_ID || !$title) {
            respond(["success" => false, "msg" => "缺少參數"]);
        }
        
        // 檢查是否有 tinforma_title 欄位
        $hasTitleField = false;
        try {
            $checkStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_title'");
            $hasTitleField = $checkStmt->rowCount() > 0;
        } catch (Throwable $e) {
            $hasTitleField = false;
        }
        
        // 取得該標題的最新更新時間和團隊數量
        if ($hasTitleField) {
            // 如果有 tinforma_title 欄位，優先使用它
            $sql = "SELECT 
                        COALESCE(ti.tinforma_update_d, ti.tinforma_create_d) as latest_date,
                        COUNT(DISTINCT td.team_ID) as team_count
                    FROM timeinformadata ti
                    JOIN timedata td ON ti.tinforma_ID = td.tinforma_ID
                    JOIN teamdata t ON td.team_ID = t.team_ID
                    WHERE t.cohort_ID = ? 
                      AND ti.tinforma_title = ?
                      AND t.team_status = 1
                    GROUP BY ti.tinforma_ID, ti.tinforma_update_d, ti.tinforma_create_d
                    ORDER BY COALESCE(ti.tinforma_update_d, ti.tinforma_create_d) DESC
                    LIMIT 1";
        } else {
            // 如果沒有 tinforma_title 欄位，使用 tinforma_content
            $sql = "SELECT 
                        ti.tinforma_create_d as latest_date,
                        COUNT(DISTINCT td.team_ID) as team_count
                    FROM timeinformadata ti
                    JOIN timedata td ON ti.tinforma_ID = td.tinforma_ID
                    JOIN teamdata t ON td.team_ID = t.team_ID
                    WHERE t.cohort_ID = ? 
                      AND (ti.tinforma_content LIKE ? OR ti.tinforma_content = ?)
                      AND t.team_status = 1
                    GROUP BY ti.tinforma_ID, ti.tinforma_create_d
                    ORDER BY ti.tinforma_create_d DESC
                    LIMIT 1";
        }
        
        if ($hasTitleField) {
            $stmt = $conn->prepare($sql);
            $stmt->execute([$cohort_ID, $title]);
        } else {
            $stmt = $conn->prepare($sql);
            $stmt->execute([$cohort_ID, "%" . $title . "%", $title]);
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        respond([
            "success" => true, 
            "data" => [
                "latest_date" => $result['latest_date'] ?? null,
                "team_count" => (int)($result['team_count'] ?? 0)
            ]
        ]);
        
    } catch (Throwable $e) {
        error_log("getTitleInfo 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "取得標題資訊失敗"]);
    }
}

/* ==========================================
   action: listTeams
   取得該屆別的所有團隊（不分類組），包含學生和指導老師資訊
========================================== */
if ($action === "listTeams") {

    $cohort_ID = $_GET["cohort_ID"] ?? 0;

    if (!$cohort_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }

    try {
        // 先取得所有團隊基本資訊
        // 按類組排序：商務網站經營組（group_ID=2）在前，系統軟體開發組（group_ID=1）在後
        $sql = "SELECT 
                    t.team_ID,
                    t.team_project_name,
                    t.group_ID,
                    g.group_name
                FROM teamdata t
                JOIN groupdata g ON t.group_ID = g.group_ID
                WHERE t.cohort_ID = ?
                  AND t.team_status = 1
                ORDER BY 
                    CASE 
                        WHEN t.group_ID = 2 THEN 0  -- 商務網站經營組優先
                        WHEN t.group_ID = 1 THEN 1  -- 系統軟體開發組（資訊組）其次
                        ELSE t.group_ID + 10  -- 其他類組按ID順序
                    END ASC,
                    t.team_ID ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute([$cohort_ID]);
        $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 為每個團隊獲取成員和指導老師
        foreach ($teams as &$team) {
            $team_ID = $team['team_ID'];
            
            // 檢查 teammember 表結構（兼容兩種版本）
            $teamUserField = 'team_u_ID';
            $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $teamUserField = 'u_ID';
            }
            
            // 獲取團隊成員（包含所有成員）
            $sql = "
                SELECT DISTINCT
                    tm.{$teamUserField} as u_ID,
                    u.u_name
                FROM teammember tm
                INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
                WHERE tm.team_ID = ? AND tm.tm_status = 1
                ORDER BY u.u_ID
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$team_ID]);
            $allMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 分離學生和指導老師
            $students = [];
            $teachers = [];
            
            foreach ($allMembers as $member) {
                $u_ID = $member['u_ID'];
                
                // 檢查該用戶的角色
                $roleSql = "
                    SELECT role_ID 
                    FROM userrolesdata 
                    WHERE ur_u_ID = ? AND user_role_status = 1
                ";
                $roleStmt = $conn->prepare($roleSql);
                $roleStmt->execute([$u_ID]);
                $roles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (in_array(4, $roles)) {
                    // 指導老師（支援多位）
                    $teachers[] = [
                        'u_ID' => $member['u_ID'],
                        'u_name' => $member['u_name']
                    ];
                } elseif (in_array(6, $roles)) {
                    // 學生
                    $students[] = [
                        'u_ID' => $member['u_ID'],
                        'u_name' => $member['u_name']
                    ];
                }
            }
            
            $team['students'] = $students;
            // 如果有多位指導老師，返回陣列；如果只有一位，也返回陣列以保持一致性
            $team['teacher'] = $teachers;
            
            // 確保 students 是陣列（即使為空）
            if (!isset($team['students']) || !is_array($team['students'])) {
                $team['students'] = [];
            }
            // 確保 teacher 是陣列（即使為空）
            if (!isset($team['teacher']) || !is_array($team['teacher'])) {
                $team['teacher'] = [];
            }
        }
        
        // 取消引用（避免後續問題）
        unset($team);
        
        respond(["success" => true, "data" => $teams]);
        
    } catch (Throwable $e) {
        error_log("listTeams 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "載入團隊資料失敗"]);
    }
}

/* ==========================================
   action: getSchedule
   取得某屆別的時程表資料（所有團隊）
========================================== */
if ($action === "getSchedule") {

    $cohort_ID = $_GET["cohort_ID"] ?? 0;
    $title = $_GET["title"] ?? "";

    if (!$cohort_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }

    try {
        // 檢查是否有 tinforma_title 欄位
        $hasTitleField = false;
        try {
            $checkStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_title'");
            $hasTitleField = $checkStmt->rowCount() > 0;
        } catch (Throwable $e) {
            $hasTitleField = false;
        }
        
        // 根據標題查找對應的 tinforma_ID
        $tinforma_ID = null;
        if ($title) {
            if ($hasTitleField) {
                // 如果有 tinforma_title 欄位，優先使用它
                $sql = "SELECT tinforma_ID 
                        FROM timeinformadata 
                        WHERE tinforma_title = ?
                        ORDER BY COALESCE(tinforma_update_d, tinforma_create_d) DESC
                        LIMIT 1";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$title]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // 舊資料可能只寫在 tinforma_content，若找不到，退回用 tinforma_content 模糊比對
                if (!$result) {
                    $sql = "SELECT tinforma_ID 
                            FROM timeinformadata 
                            WHERE tinforma_content LIKE ? OR tinforma_content = ?
                            ORDER BY COALESCE(tinforma_update_d, tinforma_create_d) DESC
                            LIMIT 1";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute(["%" . $title . "%", $title]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            } else {
                // 如果沒有 tinforma_title 欄位，使用 tinforma_content
                $sql = "SELECT tinforma_ID 
                        FROM timeinformadata 
                        WHERE tinforma_content LIKE ? OR tinforma_content = ?
                        ORDER BY tinforma_create_d DESC
                        LIMIT 1";
                $stmt = $conn->prepare($sql);
                $stmt->execute(["%" . $title . "%", $title]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if ($result) {
                $tinforma_ID = $result['tinforma_ID'];
            } else {
                // 如果找不到對應的標題，返回空結果
                respond([
                    "success" => true, 
                    "data" => [],
                    "info" => null
                ]);
            }
        } else {
            // 如果沒有提供標題，返回空結果
            respond([
                "success" => true, 
                "data" => [],
                "info" => null
            ]);
        }

        // 取得時程表資料（只取得指定 tinforma_ID 的資料）
        $sql = "SELECT 
                    td.time_ID,
                    td.team_ID,
                    td.tinforma_ID,
                    td.time_start_d,
                    td.time_end_d,
                    td.sort_no,
                    t.team_project_name,
                    ti.tinforma_content
                FROM timedata td
                JOIN teamdata t ON td.team_ID = t.team_ID
                LEFT JOIN timeinformadata ti ON td.tinforma_ID = ti.tinforma_ID
                WHERE t.cohort_ID = ?
                  AND t.team_status = 1
                  AND td.tinforma_ID = ?
                ORDER BY td.sort_no ASC, td.time_start_d ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$cohort_ID, $tinforma_ID]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 獲取時程表資訊（如果有 tinforma_ID）
        $info = null;
        if ($tinforma_ID) {
            // 確保 timeinformadata 有 online_scoring_open 欄位，以便前端正確顯示線上評分狀態
            $hasScoringCol = false;
            try {
                $hasScoringCol = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'online_scoring_open'")->rowCount() > 0;
            } catch (Throwable $e) {
                $hasScoringCol = false;
            }
            if (!$hasScoringCol) {
                try {
                    $conn->exec("ALTER TABLE timeinformadata ADD COLUMN online_scoring_open TINYINT(1) DEFAULT NULL COMMENT '線上評分：NULL=未設定，0=關閉，1=開放（設定後不可更改）'");
                } catch (Throwable $e) {
                    error_log('schedule_data getSchedule 添加 online_scoring_open 欄位失敗: ' . $e->getMessage());
                }
            }
            $infoStmt = $conn->prepare("SELECT * FROM timeinformadata WHERE tinforma_ID = ?");
            $infoStmt->execute([$tinforma_ID]);
            $info = $infoStmt->fetch(PDO::FETCH_ASSOC);
        }

        respond([
            "success" => true, 
            "data" => $rows,
            "info" => $info
        ]);
        
    } catch (Throwable $e) {
        error_log("getSchedule 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "取得時程表失敗"]);
    }
}

/* ==========================================
   action 不存在
========================================== */
respond(["success" => false, "msg" => "未知 action"]);

