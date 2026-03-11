<?php
// 開啟輸出緩衝區，捕獲所有可能的輸出
ob_start();

// 關閉錯誤顯示，確保只輸出 JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 設置 JSON 響應頭（必須在最前面，在任何輸出之前）
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

session_start();
require '../includes/pdo.php';
require '../config/path.php'; // 引入 BASE_PATH 常量

// 清除輸出緩衝區中的任何內容（包括可能的警告）
ob_clean();

// 輔助函數：清除緩衝區並輸出 JSON（強制只回傳 JSON）
function json_response($data) {
    // 清除輸出緩衝區中的任何內容（包括可能的警告、錯誤等）
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // 確保 header 已設置
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    
    // 只輸出 JSON，格式統一為 {success, message, data}
    $response = [
        'success' => $data['success'] ?? false,
        'message' => $data['message'] ?? '',
        'data' => $data['data'] ?? []
    ];
    
    // 如果有其他欄位，合併到 data 中
    foreach ($data as $key => $value) {
        if (!in_array($key, ['success', 'message', 'data'])) {
            $response['data'][$key] = $value;
        }
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 檢查權限（只有學生 role_ID = 6 可以訪問）
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

if (!$u_ID || $role_ID != 6) {
    json_response([
        "success" => false,
        "message" => "權限不足"
    ]);
}

// 獲取操作類型
$do = $_GET['do'] ?? '';

try {
    // 獲取學生所屬的團隊
    $team_ID = null;
    $teamUserField = 'team_u_ID';
    $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
    $checkStmt->execute();
    if (!$checkStmt->fetch()) {
        $teamUserField = 'u_ID';
    }
    
    $stmt = $conn->prepare("
        SELECT t.team_ID, t.cohort_ID
        FROM teamdata t
        JOIN teammember tm ON t.team_ID = tm.team_ID
        WHERE tm.{$teamUserField} = ? AND t.team_status = 1
        LIMIT 1
    ");
    $stmt->execute([$u_ID]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($team) {
        $team_ID = $team['team_ID'];
        // 先從 teamdata 獲取 cohort_ID（作為備用）
        $cohort_ID_from_team = $team['cohort_ID'] ?? null;
    }

    if (!$team_ID) {
        json_response([
            "success" => false,
            "message" => "您尚未加入任何團隊"
        ]);
    }
    
    // 🔹 【與前端邏輯一致】從 enrollmentdata 獲取 cohort_ID 和 class_ID（與前端顯示邏輯完全一致）
    $cohort_ID = null;
    $class_ID = null;
    if ($u_ID && $role_ID == 6) {
        $enrollmentStmt = $conn->prepare("
            SELECT cohort_ID, class_ID
            FROM enrollmentdata
            WHERE enroll_u_ID = ?
            LIMIT 1
        ");
        $enrollmentStmt->execute([$u_ID]);
        $enrollment = $enrollmentStmt->fetch(PDO::FETCH_ASSOC);
        if ($enrollment) {
            if ($enrollment['cohort_ID']) {
                $cohort_ID = (int)$enrollment['cohort_ID'];
            }
            if ($enrollment['class_ID']) {
                $class_ID = (int)$enrollment['class_ID'];
            }
        }
    }
    
    // 🔹 【備用邏輯】如果從 enrollmentdata 獲取不到 cohort_ID，使用 teamdata 的 cohort_ID
    if (!$cohort_ID && isset($cohort_ID_from_team)) {
        $cohort_ID = (int)$cohort_ID_from_team;
    }
    
    /**
     * 🔹 【統一判斷邏輯】檢查該學級是否可以上傳（與前端顯示使用完全相同的 SQL 條件）
     * 使用資料庫 NOW() 判斷時間，不使用 PHP date() 或 JavaScript Date()
     * 
     * 查詢條件（與前端完全一致）：
     * SELECT pro_ID
     * FROM projectdata
     * WHERE pro_status = 1
     *   AND pro_chorot_ID = ?
     *   AND pro_start_d IS NOT NULL
     *   AND pro_end_d IS NOT NULL
     *   AND NOW() BETWEEN pro_start_d AND pro_end_d
     *   [並過濾 class_ID 條件]
     * LIMIT 1
     * 
     * 若查得到資料 → 允許提交
     * 若查不到資料 → 顯示「目前沒有開放上傳時段」
     * 
     * @param PDO $conn 資料庫連接
     * @param int $cohort_ID 學級ID
     * @param int|null $class_ID 班級ID（可選，用於過濾）
     * @return array|null 返回時段資訊，如果沒有則返回null
     */
    function checkActivePeriod($conn, $cohort_ID, $class_ID = null) {
        if (!$cohort_ID) {
            error_log("checkActivePeriod - cohort_ID 為空");
            return null;
        }
        
        // 🔹 【統一查詢邏輯】使用資料庫 NOW() 判斷時間，與前端顯示邏輯完全一致
        // 先查詢所有符合時間條件的時段（使用 NOW() BETWEEN pro_start_d AND pro_end_d）
        $historyPeriodStmt = $conn->prepare("
            SELECT pro_ID, pro_title, pro_start_d, pro_end_d, pro_des, pro_chorot_ID, pro_status
            FROM projectdata
            WHERE pro_status = 1
              AND pro_chorot_ID = ?
              AND pro_start_d IS NOT NULL
              AND pro_end_d IS NOT NULL
              AND NOW() BETWEEN pro_start_d AND pro_end_d
            ORDER BY pro_start_d DESC, pro_ID DESC
        ");
        $historyPeriodStmt->execute([$cohort_ID]);
        $allHistoryPeriods = $historyPeriodStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 🔹 【調試日誌】記錄查詢結果
        error_log("checkActivePeriod - cohort_ID: $cohort_ID, 查詢到 " . count($allHistoryPeriods) . " 筆時段（時間範圍內）");
        
        // 🔹 【過濾符合班級條件的資料】與前端邏輯一致
        $validHistoryPeriods = [];
        foreach ($allHistoryPeriods as $period) {
            $pro_des = $period['pro_des'] ?? null;
            
            // 如果 pro_des 為 NULL、空值，或無 class_ID 欄位 → 所有學生可見
            if (empty($pro_des)) {
                $validHistoryPeriods[] = $period;
                error_log("checkActivePeriod - 時段 pro_ID: {$period['pro_ID']}, pro_des 為空，所有學生可見");
                continue;
            }
            
            // 解析 JSON
            $desData = json_decode($pro_des, true);
            if (!is_array($desData)) {
                // JSON 解析失敗，視為所有學生可見
                $validHistoryPeriods[] = $period;
                error_log("checkActivePeriod - 時段 pro_ID: {$period['pro_ID']}, JSON 解析失敗，所有學生可見");
                continue;
            }
            
            // 檢查是否有 class_ID 欄位
            if (!isset($desData['class_ID']) || !is_array($desData['class_ID'])) {
                // 無 class_ID 欄位或不是陣列，視為所有學生可見
                $validHistoryPeriods[] = $period;
                error_log("checkActivePeriod - 時段 pro_ID: {$period['pro_ID']}, 無 class_ID 欄位，所有學生可見");
                continue;
            }
            
            // 如果有 class_ID 陣列，檢查學生的 class_ID 是否在陣列內
            $classIDs = array_map('intval', $desData['class_ID']);
            if (empty($classIDs)) {
                // 陣列為空，視為所有學生可見
                $validHistoryPeriods[] = $period;
                error_log("checkActivePeriod - 時段 pro_ID: {$period['pro_ID']}, class_ID 陣列為空，所有學生可見");
                continue;
            }
            
            // 如果提供了 class_ID，檢查是否在陣列內
            if ($class_ID && in_array($class_ID, $classIDs, true)) {
                $validHistoryPeriods[] = $period;
                error_log("checkActivePeriod - 時段 pro_ID: {$period['pro_ID']}, class_ID: $class_ID 在陣列內，符合條件");
            } else {
                error_log("checkActivePeriod - 時段 pro_ID: {$period['pro_ID']}, class_ID: " . ($class_ID ?? 'null') . " 不在陣列 [" . implode(',', $classIDs) . "] 內，不符合條件");
            }
            // 如果沒有提供 class_ID，但時段有 class_ID 限制，則不顯示（已在上面處理空陣列的情況）
        }
        
        // 🔹 【調試日誌】記錄過濾結果
        error_log("checkActivePeriod - 過濾後符合條件的時段數量: " . count($validHistoryPeriods));
        
        // 如果找到符合條件的時段，返回第一個
        if (!empty($validHistoryPeriods)) {
            $historyPeriod = $validHistoryPeriods[0];
            error_log("checkActivePeriod - 返回時段: pro_ID={$historyPeriod['pro_ID']}, title={$historyPeriod['pro_title']}");
            return [
                'period_type' => 'history',
                'start_d' => $historyPeriod['pro_start_d'],
                'end_d' => $historyPeriod['pro_end_d'],
                'title' => $historyPeriod['pro_title'] ?? '歷屆專題上傳'
            ];
        }
        
        // 查不到資料，返回 null
        error_log("checkActivePeriod - 沒有符合條件的時段");
        return null;
    }
    
    // 檢查是否超過鎖定時間（從 projectdata 獲取截止時間）
    $lockTime = null;
    if (isset($cohort_ID)) {
        $lockStmt = $conn->prepare("
            SELECT pro_end_d 
            FROM projectdata 
            WHERE pro_chorot_ID = ? AND pro_status = 1 
            ORDER BY pro_created_d DESC 
            LIMIT 1
        ");
        $lockStmt->execute([$cohort_ID]);
        $lockRecord = $lockStmt->fetch(PDO::FETCH_ASSOC);
        if ($lockRecord && $lockRecord['pro_end_d']) {
            $lockTime = $lockRecord['pro_end_d'];
        }
    }
    
    // 如果沒有找到特定 cohort 的鎖定時間，查找任何有效的鎖定時間
    if (!$lockTime) {
        $lockStmt2 = $conn->prepare("
            SELECT pro_end_d 
            FROM projectdata 
            WHERE pro_status = 1 AND pro_end_d IS NOT NULL
            ORDER BY pro_created_d DESC 
            LIMIT 1
        ");
        $lockStmt2->execute();
        $lockRecord2 = $lockStmt2->fetch(PDO::FETCH_ASSOC);
        if ($lockRecord2 && $lockRecord2['pro_end_d']) {
            $lockTime = $lockRecord2['pro_end_d'];
        }
    }
    
    // 檢查是否已超過鎖定時間
    $isTimeLocked = false;
    if ($lockTime) {
        $lockDateTime = new DateTime($lockTime);
        $now = new DateTime();
        if ($now > $lockDateTime) {
            $isTimeLocked = true;
        }
    }

    /**
     * 將歷史記錄添加到 content_json['history'] 中
     * 注意：此函數只返回歷史記錄陣列，不直接寫入資料庫
     * 需要在更新 content_json 時將返回的陣列合併進去
     * 
     * 動作類型：
     * - 'submitted': 已送出（第一次提交）
     * - 'replaced': 已取代（覆蓋提交）
     * - 'deleted': 已刪除（刪除提交）
     */
    function addHistoryRecord($existingHistory, $actionType, $u_ID, $snapshotData = null) {
        $actionText = [
            'submitted' => '已送出',
            'replaced' => '已取代',
            'deleted' => '已刪除'
        ];
        
        $history = is_array($existingHistory) ? $existingHistory : [];
        $history[] = [
            'action' => $actionType,
            'action_text' => $actionText[$actionType] ?? $actionType,
            'action_time' => date('Y-m-d H:i:s'),
            'operator_id' => $u_ID,
            'submitted_by' => $u_ID, // 兼容舊格式
            'submitted_at' => date('Y-m-d H:i:s'), // 兼容舊格式
            'replaced_by' => ($actionType === 'replaced') ? $u_ID : null, // 兼容舊格式
            'replaced_at' => ($actionType === 'replaced') ? date('Y-m-d H:i:s') : null, // 兼容舊格式
            'deleted_by' => ($actionType === 'deleted') ? $u_ID : null, // 兼容舊格式
            'deleted_at' => ($actionType === 'deleted') ? date('Y-m-d H:i:s') : null // 兼容舊格式
        ];
        
        return $history;
    }

    /**
     * 獲取或創建專題資料的 pro_ID
     * 確保返回的 pro_ID 一定存在於 projectdata 表中
     */
    function getOrCreateProjectID($conn, $team_ID, $u_ID) {
        // 優先：從該團隊的現有 prosubdata 記錄中獲取 pro_ID
        $existingProStmt = $conn->prepare("
            SELECT DISTINCT pro_ID 
            FROM prosubdata 
            WHERE team_ID = ? AND pro_ID IS NOT NULL
            ORDER BY prosub_created_d DESC 
            LIMIT 1
        ");
        $existingProStmt->execute([$team_ID]);
        $existingPro = $existingProStmt->fetch(PDO::FETCH_ASSOC);
        if ($existingPro && isset($existingPro['pro_ID'])) {
            // 驗證這個 pro_ID 是否仍然存在於 projectdata 中
            $verifyStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM projectdata WHERE pro_ID = ?");
            $verifyStmt->execute([$existingPro['pro_ID']]);
            $verify = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            if ($verify && $verify['cnt'] > 0) {
                return (int)$existingPro['pro_ID'];
            }
        }
        
        // 獲取團隊的 cohort_ID
        $teamCohortStmt = $conn->prepare("SELECT cohort_ID FROM teamdata WHERE team_ID = ? AND team_status = 1");
        $teamCohortStmt->execute([$team_ID]);
        $teamCohort = $teamCohortStmt->fetch(PDO::FETCH_ASSOC);
        $cohort_ID = $teamCohort ? $teamCohort['cohort_ID'] : null;
        
        // 如果團隊有 cohort_ID，根據 cohort_ID 查找專題
        if ($cohort_ID) {
            // 先找狀態為1的專題
            $proStmt = $conn->prepare("
                SELECT pro_ID FROM projectdata 
                WHERE pro_chorot_ID = ? AND pro_status = 1 
                ORDER BY pro_created_d DESC 
                LIMIT 1
            ");
            $proStmt->execute([$cohort_ID]);
            $pro = $proStmt->fetch(PDO::FETCH_ASSOC);
            
            // 如果找不到，找任何狀態的專題
            if (!$pro) {
                $proStmt2 = $conn->prepare("
                    SELECT pro_ID FROM projectdata 
                    WHERE pro_chorot_ID = ?
                    ORDER BY pro_created_d DESC 
                    LIMIT 1
                ");
                $proStmt2->execute([$cohort_ID]);
                $pro = $proStmt2->fetch(PDO::FETCH_ASSOC);
            }
            
            if ($pro && isset($pro['pro_ID'])) {
                return (int)$pro['pro_ID'];
            }
        }
        
        // 如果找不到，嘗試找任何狀態為1的專題（不限 cohort）
        $proStmt3 = $conn->prepare("
            SELECT pro_ID FROM projectdata 
            WHERE pro_status = 1 
            ORDER BY pro_created_d DESC 
            LIMIT 1
        ");
        $proStmt3->execute();
        $pro = $proStmt3->fetch(PDO::FETCH_ASSOC);
        
        if ($pro && isset($pro['pro_ID'])) {
            return (int)$pro['pro_ID'];
        }
        
        // 如果還是找不到，嘗試找任何存在的專題
        $proStmt4 = $conn->prepare("
            SELECT pro_ID FROM projectdata 
            ORDER BY pro_created_d DESC 
            LIMIT 1
        ");
        $proStmt4->execute();
        $pro = $proStmt4->fetch(PDO::FETCH_ASSOC);
        
        if ($pro && isset($pro['pro_ID'])) {
            return (int)$pro['pro_ID'];
        }
        
        // 如果完全找不到任何專題，自動創建一個新的 projectdata
        // 需要獲取一個有效的 cohort_ID（從團隊或從用戶的學籍）
        $createCohort_ID = $cohort_ID;
        
        if (!$createCohort_ID) {
            // 從用戶的學籍獲取 cohort_ID
            // 【規則】同一個 enroll_u_ID 在 enrollmentdata 永遠只允許 1 筆資料
            $enrollStmt = $conn->prepare("
                SELECT cohort_ID FROM enrollmentdata 
                WHERE enroll_u_ID = ?
                LIMIT 1
            ");
            $enrollStmt->execute([$u_ID]);
            $enrollment = $enrollStmt->fetch(PDO::FETCH_ASSOC);
            $createCohort_ID = $enrollment ? $enrollment['cohort_ID'] : null;
        }
        
        // 如果還是沒有 cohort_ID，嘗試獲取第一個有效的 cohort_ID
        if (!$createCohort_ID) {
            $cohortStmt = $conn->prepare("SELECT cohort_ID FROM cohortdata ORDER BY cohort_ID DESC LIMIT 1");
            $cohortStmt->execute();
            $cohort = $cohortStmt->fetch(PDO::FETCH_ASSOC);
            $createCohort_ID = $cohort ? $cohort['cohort_ID'] : null;
        }
        
        // 如果還是沒有 cohort_ID，嘗試獲取第一個有效的 cohort_ID
        if (!$createCohort_ID) {
            $cohortStmt2 = $conn->prepare("SELECT MIN(cohort_ID) as cohort_ID FROM cohortdata LIMIT 1");
            $cohortStmt2->execute();
            $cohort2 = $cohortStmt2->fetch(PDO::FETCH_ASSOC);
            $createCohort_ID = $cohort2 ? $cohort2['cohort_ID'] : null;
        }
        
        // 如果還是沒有，這是一個系統配置問題，但我們仍然嘗試創建
        if (!$createCohort_ID) {
            error_log("警告：找不到有效的 cohort_ID，使用預設值 1，team_ID: {$team_ID}, u_ID: {$u_ID}");
            $createCohort_ID = 1;
        }
        
        // 驗證 cohort_ID 是否存在於 cohortdata 表中
        $verifyCohortStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM cohortdata WHERE cohort_ID = ?");
        $verifyCohortStmt->execute([$createCohort_ID]);
        $verifyCohort = $verifyCohortStmt->fetch(PDO::FETCH_ASSOC);
        
        // 如果 cohort_ID 不存在，嘗試創建一個（如果允許）或使用第一個存在的
        if (!$verifyCohort || $verifyCohort['cnt'] == 0) {
            // 獲取第一個存在的 cohort_ID
            $cohortStmt3 = $conn->prepare("SELECT MIN(cohort_ID) as cohort_ID FROM cohortdata LIMIT 1");
            $cohortStmt3->execute();
            $cohort3 = $cohortStmt3->fetch(PDO::FETCH_ASSOC);
            if ($cohort3 && $cohort3['cohort_ID']) {
                $createCohort_ID = $cohort3['cohort_ID'];
            } else {
                // 如果完全沒有 cohortdata，這是一個嚴重的系統配置問題
                error_log("嚴重錯誤：cohortdata 表中沒有任何資料，無法創建 projectdata");
                throw new Exception("系統配置錯誤：找不到有效的屆別資料");
            }
        }
        
        // 獲取預設的狀態值（狀態 1 表示正常/啟用）
        $defaultStatus = 1;
        
        // 驗證狀態值是否存在於 statusdata 表中
        $verifyStatusStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM statusdata WHERE status_ID = ?");
        $verifyStatusStmt->execute([$defaultStatus]);
        $verifyStatus = $verifyStatusStmt->fetch(PDO::FETCH_ASSOC);
        
        // 如果狀態值不存在，使用第一個存在的狀態
        if (!$verifyStatus || $verifyStatus['cnt'] == 0) {
            $statusStmt = $conn->prepare("SELECT MIN(status_ID) as status_ID FROM statusdata WHERE status_ID > 0 LIMIT 1");
            $statusStmt->execute();
            $status = $statusStmt->fetch(PDO::FETCH_ASSOC);
            if ($status && $status['status_ID']) {
                $defaultStatus = $status['status_ID'];
            } else {
                $defaultStatus = 1; // 最後手段
            }
        }
        
        // 創建新的 projectdata 記錄
        $insertProStmt = $conn->prepare("
            INSERT INTO projectdata (
                pro_chorot_ID, pro_title, pro_status, pro_created_u_ID, pro_created_d
            ) VALUES (?, ?, ?, ?, NOW())
        ");
        
        $defaultTitle = "專題上傳 - " . date('Y-m-d H:i:s');
        $insertProStmt->execute([
            $createCohort_ID,
            $defaultTitle,
            $defaultStatus,
            $u_ID
        ]);
        
        $newPro_ID = $conn->lastInsertId();
        
        if ($newPro_ID) {
            // 再次驗證創建的 pro_ID 是否存在
            $verifyNewProStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM projectdata WHERE pro_ID = ?");
            $verifyNewProStmt->execute([$newPro_ID]);
            $verifyNewPro = $verifyNewProStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($verifyNewPro && $verifyNewPro['cnt'] > 0) {
                return (int)$newPro_ID;
            }
        }
        
        // 如果創建失敗，這是一個嚴重的系統錯誤
        error_log("嚴重錯誤：無法創建 projectdata 記錄，team_ID: {$team_ID}, u_ID: {$u_ID}, cohort_ID: {$createCohort_ID}");
        throw new Exception("無法創建專題資料");
    }

    /**
     * 取得專題允許的檔案類型（projectdata.allow_file_types）
     * @return array 允許的類型代碼陣列，若未設定則回傳空陣列（表示不限制）
     */
    function getAllowedFileTypesForProject($conn, $pro_ID) {
        if (!$pro_ID) return [];
        try {
            $stmt = $conn->prepare("SELECT allow_file_types FROM projectdata WHERE pro_ID = ?");
            $stmt->execute([$pro_ID]);
            $json = $stmt->fetchColumn();
            if (!$json) return [];
            $arr = json_decode($json, true);
            return is_array($arr) ? array_values($arr) : [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * 獲取指定學級及其班級下，所有當前開放時段所允許的檔案類型聯集
     * 如果任一時段未設定（empty），則視為不限制（返回空陣列表示不限制）
     * 
     * @param PDO $conn 資料庫連接
     * @param int $cohort_ID 學級ID
     * @param int|null $class_ID 班級ID（可選）
     * @return array 允許的檔案類型聯集；若不限制則返回空陣列
     */
    function getUnionOfAllowedFileTypes($conn, $cohort_ID, $class_ID = null) {
        if (!$cohort_ID) return [];
        
        $historyPeriodStmt = $conn->prepare("
            SELECT pro_des, allow_file_types
            FROM projectdata
            WHERE pro_status = 1
              AND pro_chorot_ID = ?
              AND pro_start_d IS NOT NULL
              AND pro_end_d IS NOT NULL
              AND NOW() BETWEEN pro_start_d AND pro_end_d
        ");
        $historyPeriodStmt->execute([$cohort_ID]);
        $periods = $historyPeriodStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($periods)) return []; // 沒有開放時段
        
        $union = [];
        $hasRestrictedPeriod = false;
        
        foreach ($periods as $period) {
            $pro_des = $period['pro_des'] ?? null;
            $match = false;
            
            if (empty($pro_des)) {
                $match = true;
            } else {
                $desData = json_decode($pro_des, true);
                if (!is_array($desData) || !isset($desData['class_ID']) || !is_array($desData['class_ID'])) {
                    $match = true;
                } else {
                    $classIDs = array_map('intval', $desData['class_ID']);
                    if (empty($classIDs) || ($class_ID && in_array($class_ID, $classIDs, true))) {
                        $match = true;
                    }
                }
            }
            
            if ($match) {
                // 如果任一符合條件的時段沒有設定限制，則視為全部開放
                if (empty($period['allow_file_types'])) {
                    return []; // 不限制
                }
                
                $types = json_decode($period['allow_file_types'], true);
                if (is_array($types)) {
                    $union = array_merge($union, array_values($types));
                    $hasRestrictedPeriod = true;
                }
            }
        }
        
        return $hasRestrictedPeriod ? array_unique($union) : [];
    }

    /**
     * 驗證單一檔案的 file_type 是否在允許清單內；若不允許則輸出 JSON 錯誤並結束
     * 
     * @param PDO $conn 資料庫連接
     * @param array $allowedTypes 預先計算好的允許類型聯集（若為空則不限制）
     * @param string $file_type 待驗證的檔案類型
     */
    function validateFileTypeOrFail($conn, $allowedTypes, $file_type) {
        // 若後台未設定限制，或前端沒有傳入類型，就不阻擋（由前端下拉選單控制即可）
        if (empty($allowedTypes)) {
            return;
        }
        
        $file_type = trim((string)$file_type);
        if ($file_type === '') {
            // 沒有類型時不再中斷，只記錄在錯誤日誌中供科辦追蹤
            error_log('[validateFileTypeOrFail] file_type 為空，略過驗證');
            return;
        }
        
        // 若類型不在允許清單內，改為只記錄 log，不再回傳錯誤給前端
        if (!in_array($file_type, $allowedTypes, true)) {
            error_log('[validateFileTypeOrFail] 非允許的 file_type: ' . $file_type . '，allowed: ' . implode(',', $allowedTypes));
            return;
        }
    }

    switch ($do) {
        case 'save_draft':
            // ====== 暫存功能 ======
            //  暫存只代表「目前編輯狀態」，允許資料不完整（簡介可空、檔案可有可無）
            $prosub_ID = isset($_POST['prosub_ID']) ? (int)$_POST['prosub_ID'] : 0;
            $file_type = isset($_POST['file_type']) ? trim($_POST['file_type']) : '';
            $project_intro = trim($_POST['project_intro'] ?? ''); //  允許為空
            
            //  檢查是否有 intro 字段
            $hasIntroField = false;
            try {
                $checkStmt = $conn->prepare("SHOW COLUMNS FROM prosubdata LIKE 'prosub_intro'");
                $checkStmt->execute();
                $hasIntroField = $checkStmt->rowCount() > 0;
            } catch (Exception $e) {
                // 忽略錯誤
            }
            
            //  準備內容 JSON（不假設一定包含所有欄位，僅用於彈性資料）
            $contentJson = [];
            
            // 處理文件上傳
            $posterPath = null;
            if (isset($_FILES['poster']) && $_FILES['poster']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['poster'];
                $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf'];
                $maxSize = 10 * 1024 * 1024; // 10MB
                
                if (!in_array($file['type'], $allowedTypes)) {
                    json_response([
                        "success" => false,
                        "message" => "檔案格式不正確，請上傳圖片或PDF"
                    ]);
                }
                
                if ($file['size'] > $maxSize) {
                    json_response([
                        "success" => false,
                        "message" => "檔案大小超過 10MB"
                    ]);
                }
                
                // 創建上傳目錄
                $uploadDir = '../uploads/project_posters/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                // 生成唯一檔名
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'poster_' . $team_ID . '_' . time() . '_' . uniqid() . '.' . $ext;
                $filePath = $uploadDir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $filePath)) {
                    $posterPath = 'uploads/project_posters/' . $filename;
                    
                    //  儲存原始檔名到 content_json（僅用於顯示，不影響實際檔案儲存與路徑）
                    $originalFileName = $file['name'];
                    $contentJson['poster_original_name'] = $originalFileName;
                    
                    // 如果有舊的暫存記錄，刪除舊的海報文件
                    if ($prosub_ID > 0) {
                        $oldStmt = $conn->prepare("SELECT prosub_img FROM prosubdata WHERE prosub_ID = ?");
                        $oldStmt->execute([$prosub_ID]);
                        $oldRecord = $oldStmt->fetch(PDO::FETCH_ASSOC);
                        if ($oldRecord && $oldRecord['prosub_img']) {
                            $oldFilePath = '../' . $oldRecord['prosub_img'];
                            if (file_exists($oldFilePath)) {
                                @unlink($oldFilePath);
                            }
                        }
                    }
                } else {
                    json_response([
                        "success" => false,
                        "message" => "檔案上傳失敗"
                    ]);
                }
            }
            
            // 🔹 檢查是否為編輯模式（通過 prosub_ID 判斷）
            $isEditMode = ($prosub_ID > 0);
            
            // 🔹 處理多個檔案上傳（支持新舊兩種格式）
            $otherFiles = []; // 新上傳的檔案
            $keepExistingFiles = []; // 要保留的舊檔案
            $deleteExistingKeys = []; // 要刪除的舊檔案 key 列表
            $shouldClearAllFiles = false; // 🔹 【修復清除全部】標記是否要清空所有檔案
            
            // 🔹 【修復清除全部】優先檢查 clear_all 標誌（方式A）
            if ($isEditMode && isset($_POST['clear_all']) && $_POST['clear_all'] == '1') {
                // 直接清空所有檔案，不進行任何合併或保留操作
                $shouldClearAllFiles = true;
                $allOtherFiles = [];
                $otherFilesJson = null; // 明確設置為 null，表示清空
                error_log("[save_draft] 收到 clear_all=1，直接清空多檔案 JSON");
            }
            // 🔹 優先處理新格式（編輯模式專用）
            elseif ($isEditMode && isset($_POST['keep_existing_files']) && isset($_POST['delete_existing_keys'])) {
                // 新格式：keep/delete/new
                $keepExistingFilesJson = json_decode($_POST['keep_existing_files'], true);
                $deleteExistingKeysJson = json_decode($_POST['delete_existing_keys'], true);
                
                $keepExistingFiles = [];
                $deleteExistingKeys = [];
                
                if (is_array($keepExistingFilesJson)) {
                    $keepExistingFiles = $keepExistingFilesJson;
                }
                if (is_array($deleteExistingKeysJson)) {
                    $deleteExistingKeys = $deleteExistingKeysJson;
                }
                
                $pro_ID_draft = getOrCreateProjectID($conn, $team_ID, $u_ID);
                $allAllowedTypes = getUnionOfAllowedFileTypes($conn, $cohort_ID, $class_ID);
                
                $newFileTypes = isset($_POST['new_file_types']) && is_array($_POST['new_file_types']) ? $_POST['new_file_types'] : [];
                // 處理新上傳的檔案（new_files[]），每筆對應 new_file_types[] 的 file_type
                $hasNewFiles = false;
                if (isset($_FILES['new_files']) && is_array($_FILES['new_files']['error'])) {
                    $uploadDir = BASE_PATH . '/uploads/project_other_files/';
                    
                    if (!is_dir($uploadDir)) {
                        if (!mkdir($uploadDir, 0777, true)) {
                            json_response([
                                "success" => false,
                                "message" => "無法建立上傳資料夾"
                            ]);
                        }
                    }
                    
                    if (!is_writable($uploadDir)) {
                        json_response([
                            "success" => false,
                            "message" => "上傳資料夾不可寫入"
                        ]);
                    }
                    
                    $fileCount = count($_FILES['new_files']['error']);
                    for ($i = 0; $i < $fileCount; $i++) {
                        if ($_FILES['new_files']['error'][$i] === UPLOAD_ERR_OK) {
                            $file_type_i = isset($newFileTypes[$i]) ? trim((string)$newFileTypes[$i]) : '';
                            validateFileTypeOrFail($conn, $allAllowedTypes, $file_type_i);
                            $file = [
                                'name' => $_FILES['new_files']['name'][$i],
                                'type' => $_FILES['new_files']['type'][$i],
                                'tmp_name' => $_FILES['new_files']['tmp_name'][$i],
                                'size' => $_FILES['new_files']['size'][$i]
                            ];
                            
                            $maxSize = 50 * 1024 * 1024; // 1000MB per file
                            if ($file['size'] > $maxSize) {
                                continue;
                            }
                            
                            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                            $filename = 'other_' . $team_ID . '_' . time() . '_' . $i . '_' . uniqid() . '.' . $ext;
                            $absPath = $uploadDir . $filename;
                            
                            if (!move_uploaded_file($file['tmp_name'], $absPath)) {
                                error_log("move_uploaded_file failed: " . $absPath);
                                continue;
                            }
                            
                            $relPath = 'uploads/project_other_files/' . $filename;
                            $otherFiles[] = [
                                'original_name' => $file['name'],
                                'name' => $file['name'],
                                'path' => $relPath,
                                'type' => $file['type'] ?? '',
                                'uploaded_at' => date('Y-m-d H:i:s'),
                                'public' => true,
                                'file_type' => $file_type_i
                            ];
                            $hasNewFiles = true;
                        }
                    }
                }
                
                // 🔹 【修復清除全部】如果 keep_existing_files 為空且 delete_existing_keys 不為空，且沒有新上傳的檔案，表示要清空所有檔案
                // 🔹 如果有新上傳的檔案，即使 keep_existing_files 為空，也要保留新上傳的檔案
                $shouldClearAllFiles = empty($keepExistingFiles) && !empty($deleteExistingKeys) && !$hasNewFiles;
                
                // 🔹 從原始資料中獲取舊檔案列表，然後根據 keep/delete 處理
                $oldOtherFiles = [];
                if ($prosub_ID > 0) {
                    $oldStmt = $conn->prepare("SELECT prosub_other FROM prosubdata WHERE prosub_ID = ?");
                    $oldStmt->execute([$prosub_ID]);
                    $oldRecord = $oldStmt->fetch(PDO::FETCH_ASSOC);
                    if ($oldRecord && $oldRecord['prosub_other']) {
                        $oldOtherFilesJson = json_decode($oldRecord['prosub_other'], true);
                        if (is_array($oldOtherFilesJson)) {
                            $oldOtherFiles = $oldOtherFilesJson;
                        }
                    }
                }
                
                // 🔹 根據 delete_existing_keys 刪除舊檔案（從檔案系統和列表中移除）
                foreach ($deleteExistingKeys as $deleteKey) {
                    // 找到要刪除的檔案
                    foreach ($oldOtherFiles as $index => $oldFile) {
                        $filePath = is_array($oldFile) ? ($oldFile['path'] ?? '') : $oldFile;
                        if ($filePath === $deleteKey || (is_array($oldFile) && isset($oldFile['path']) && $oldFile['path'] === $deleteKey)) {
                            // 刪除實體檔案
                            $fullPath = BASE_PATH . '/' . $filePath;
                            if (file_exists($fullPath)) {
                                @unlink($fullPath);
                            }
                            // 從列表中移除
                            unset($oldOtherFiles[$index]);
                        }
                    }
                }
                $oldOtherFiles = array_values($oldOtherFiles); // 重新索引
                
                // 🔹 【修復刪除後暫存不生效】最終檔案列表 = 保留的舊檔案（從 keep_existing_files） + 新上傳的檔案
                // 🔹 【關鍵】keep_existing_files 是前端傳來的，已經真正排除了被刪除的檔案（前端已從陣列移除）
                // 🔹 【關鍵】禁止把 DB 原本 oldFiles 再 merge 回 finalFiles（那會讓刪除無效）
                // 🔹 【修復清除全部】如果 shouldClearAllFiles 為 true 且沒有新上傳的檔案，最終列表為空
                // 🔹 如果有新上傳的檔案，即使 shouldClearAllFiles 為 true，也要保留新上傳的檔案
                if ($shouldClearAllFiles && !$hasNewFiles) {
                    $allOtherFiles = []; // 清空所有檔案（只有在沒有新上傳檔案時）
                } else {
                    // 🔹 【修復文件數量異常增加】合併前先做去重，避免同一檔案被重複加入
                    // 使用 path 作為唯一 key 進行去重
                    $finalFilesMap = []; // 使用 path 作為 key 的 map，避免重複
                    
                    // 1. 先加入保留的舊檔案（從 keep_existing_files），每筆須帶 file_type 並驗證
                    foreach ($keepExistingFiles as $keptFile) {
                        $filePath = is_array($keptFile) ? ($keptFile['path'] ?? '') : $keptFile;
                        if ($filePath && !isset($finalFilesMap[$filePath])) {
                            $file_type_kept = isset($keptFile['file_type']) ? trim((string)$keptFile['file_type']) : '';
                            validateFileTypeOrFail($conn, $allAllowedTypes, $file_type_kept);
                            if (is_array($keptFile)) {
                                $finalFilesMap[$filePath] = [
                                    'original_name' => $keptFile['original_name'] ?? $keptFile['name'] ?? basename($filePath),
                                    'name' => $keptFile['original_name'] ?? $keptFile['name'] ?? basename($filePath),
                                    'path' => $keptFile['path'] ?? $filePath,
                                    'type' => $keptFile['type'] ?? '',
                                    'uploaded_at' => $keptFile['uploaded_at'] ?? $keptFile['upload_time'] ?? '',
                                    'public' => isset($keptFile['public']) ? (bool)$keptFile['public'] : true,
                                    'file_type' => $file_type_kept
                                ];
                            } else {
                                $finalFilesMap[$filePath] = [
                                    'original_name' => basename($filePath),
                                    'name' => basename($filePath),
                                    'path' => $filePath,
                                    'type' => '',
                                    'uploaded_at' => '',
                                    'public' => true,
                                    'file_type' => $file_type_kept
                                ];
                            }
                        }
                    }
                    
                    // 2. 再加入新上傳的檔案（如果 path 已存在則跳過）
                    foreach ($otherFiles as $newFile) {
                        $filePath = $newFile['path'] ?? '';
                        if ($filePath && !isset($finalFilesMap[$filePath])) {
                            $finalFilesMap[$filePath] = $newFile;
                        }
                    }
                    
                    // 3. 轉換為數組
                    $allOtherFiles = array_values($finalFilesMap);
                    
                    // 🔹 【Debug 必做】後端寫入 DB 前 finalFiles JSON
                    error_log("[save_draft] 🔍 後端寫入 DB 前:");
                    error_log("[save_draft] - oldFiles count: " . count($oldOtherFiles));
                    error_log("[save_draft] - keptFiles count: " . count($keepExistingFiles));
                    error_log("[save_draft] - addedFiles count: " . count($otherFiles));
                    error_log("[save_draft] - finalFiles count: " . count($allOtherFiles));
                    error_log("[save_draft] - finalFiles JSON: " . json_encode($allOtherFiles, JSON_UNESCAPED_UNICODE));
                }
            } else {
                // 🔹 舊格式（向後兼容）：existing_files + other_files[]，每筆對應 file_types[]
                $pro_ID_draft_non = getOrCreateProjectID($conn, $team_ID, $u_ID);
                $allAllowedTypes = getUnionOfAllowedFileTypes($conn, $cohort_ID, $class_ID);
                $fileTypesPost = isset($_POST['file_types']) && is_array($_POST['file_types']) ? $_POST['file_types'] : [];
                if (isset($_FILES['other_files']) && is_array($_FILES['other_files']['error'])) {
                    $uploadDir = BASE_PATH . '/uploads/project_other_files/';
                    
                    if (!is_dir($uploadDir)) {
                        if (!mkdir($uploadDir, 0777, true)) {
                            json_response([
                                "success" => false,
                                "message" => "無法建立上傳資料夾"
                            ]);
                        }
                    }
                    
                    if (!is_writable($uploadDir)) {
                        json_response([
                            "success" => false,
                            "message" => "上傳資料夾不可寫入"
                        ]);
                    }
                    
                    $fileCount = count($_FILES['other_files']['error']);
                    for ($i = 0; $i < $fileCount; $i++) {
                        if ($_FILES['other_files']['error'][$i] === UPLOAD_ERR_OK) {
                            $file_type_i = isset($fileTypesPost[$i]) ? trim((string)$fileTypesPost[$i]) : '';
                            validateFileTypeOrFail($conn, $allAllowedTypes, $file_type_i);
                            $file = [
                                'name' => $_FILES['other_files']['name'][$i],
                                'type' => $_FILES['other_files']['type'][$i],
                                'tmp_name' => $_FILES['other_files']['tmp_name'][$i],
                                'size' => $_FILES['other_files']['size'][$i]
                            ];
                            
                            $maxSize = 50 * 1024 * 1024; // 1000MB per file
                            if ($file['size'] > $maxSize) {
                                continue;
                            }
                            
                            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                            $filename = 'other_' . $team_ID . '_' . time() . '_' . $i . '_' . uniqid() . '.' . $ext;
                            $absPath = $uploadDir . $filename;
                            
                            if (!move_uploaded_file($file['tmp_name'], $absPath)) {
                                error_log("move_uploaded_file failed: " . $absPath);
                                continue;
                            }
                            
                            $relPath = 'uploads/project_other_files/' . $filename;
                            $otherFiles[] = [
                                'original_name' => $file['name'],
                                'name' => $file['name'],
                                'path' => $relPath,
                                'type' => $file['type'] ?? '',
                                'uploaded_at' => date('Y-m-d H:i:s'),
                                'public' => true,
                                'file_type' => $file_type_i
                            ];
                        }
                    }
                }
            
            // 🔹 處理要保留的舊檔案（優先使用 kept_files_json，向後兼容 existing_files）
            $existingFiles = [];
            $shouldClearFiles = false;
            
            // 優先檢查 kept_files_json（新格式）
            if (isset($_POST['kept_files_json'])) {
                $keptFilesJson = json_decode($_POST['kept_files_json'], true);
                if (is_array($keptFilesJson)) {
                    if (empty($keptFilesJson)) {
                        $shouldClearFiles = true;
                    } else {
                        foreach ($keptFilesJson as $file) {
                            if (is_string($file)) {
                                $existingFiles[] = [
                                    'original_name' => basename($file),
                                    'name' => basename($file),
                                    'path' => $file,
                                    'type' => '',
                                    'uploaded_at' => '',
                                    'public' => true
                                ];
                            } elseif (is_array($file) && isset($file['path'])) {
                                if (isset($file['name']) && isset($file['type']) && isset($file['uploaded_at']) && isset($file['public'])) {
                                    // 確保有 original_name
                                    if (!isset($file['original_name'])) {
                                        $file['original_name'] = $file['name'] ?? basename($file['path']);
                                    }
                                    $existingFiles[] = $file;
                                } else {
                                    $fileName = $file['original_name'] ?? $file['name'] ?? basename($file['path']);
                                    $uploadTime = $file['uploaded_at'] ?? $file['upload_time'] ?? '';
                                    $isPublic = isset($file['public']) ? (bool)$file['public'] : (isset($file['allow_download']) ? (bool)$file['allow_download'] : true);
                                    
                                    $existingFiles[] = [
                                        'original_name' => $fileName,
                                        'name' => $fileName,
                                        'path' => $file['path'],
                                        'type' => $file['type'] ?? '',
                                        'uploaded_at' => $uploadTime,
                                        'public' => $isPublic,
                                        'file_type' => $file['file_type'] ?? ''
                                    ];
                                }
                            }
                        }
                    }
                }
            } elseif (isset($_POST['existing_files'])) {
                // 向後兼容：使用 existing_files（舊格式）
                $existingFilesJson = json_decode($_POST['existing_files'], true);
                if (is_array($existingFilesJson)) {
                    if (empty($existingFilesJson)) {
                        $shouldClearFiles = true;
                    } else {
                        foreach ($existingFilesJson as $file) {
                            if (is_string($file)) {
                                $existingFiles[] = [
                                    'original_name' => basename($file),
                                    'name' => basename($file),
                                    'path' => $file,
                                    'type' => '',
                                    'uploaded_at' => '',
                                    'public' => true
                                ];
                            } elseif (is_array($file) && isset($file['path'])) {
                                if (isset($file['name']) && isset($file['type']) && isset($file['uploaded_at']) && isset($file['public'])) {
                                    // 確保有 original_name
                                    if (!isset($file['original_name'])) {
                                        $file['original_name'] = $file['name'] ?? basename($file['path']);
                                    }
                                    $existingFiles[] = $file;
                                } else {
                                    $fileName = $file['original_name'] ?? $file['name'] ?? basename($file['path']);
                                    $uploadTime = $file['uploaded_at'] ?? $file['upload_time'] ?? '';
                                    $isPublic = isset($file['public']) ? (bool)$file['public'] : (isset($file['allow_download']) ? (bool)$file['allow_download'] : true);
                                    
                                    $existingFiles[] = [
                                        'original_name' => $fileName,
                                        'name' => $fileName,
                                        'path' => $file['path'],
                                        'type' => $file['type'] ?? '',
                                        'uploaded_at' => $uploadTime,
                                        'public' => $isPublic
                                    ];
                                }
                            }
                        }
                    }
                }
            }
            
            // 🔹 【修復 JSON 膨脹】舊格式也要做去重，使用 path 作為唯一鍵
            $finalFilesMap = [];
            
            // 1. 先加入保留的舊檔案（從 existing_files），每筆須帶 file_type 並驗證
            foreach ($existingFiles as $existingFile) {
                $filePath = is_array($existingFile) ? ($existingFile['path'] ?? '') : $existingFile;
                if ($filePath && !isset($finalFilesMap[$filePath])) {
                    $ft = isset($existingFile['file_type']) ? trim((string)$existingFile['file_type']) : '';
                    validateFileTypeOrFail($conn, $allAllowedTypes, $ft);
                    if (is_array($existingFile)) {
                        $finalFilesMap[$filePath] = [
                            'original_name' => $existingFile['original_name'] ?? $existingFile['name'] ?? basename($filePath),
                            'name' => $existingFile['original_name'] ?? $existingFile['name'] ?? basename($filePath),
                            'path' => $existingFile['path'] ?? $filePath,
                            'type' => $existingFile['type'] ?? '',
                            'uploaded_at' => $existingFile['uploaded_at'] ?? $existingFile['upload_time'] ?? '',
                            'public' => isset($existingFile['public']) ? (bool)$existingFile['public'] : true,
                            'file_type' => $ft
                        ];
                    } else {
                        $finalFilesMap[$filePath] = [
                            'original_name' => basename($filePath),
                            'name' => basename($filePath),
                            'path' => $filePath,
                            'type' => '',
                            'uploaded_at' => '',
                            'public' => true,
                            'file_type' => $ft
                        ];
                    }
                }
            }
            
            // 2. 再加入新上傳的檔案（如果 path 已存在則跳過）
            foreach ($otherFiles as $newFile) {
                $filePath = $newFile['path'] ?? '';
                if ($filePath && !isset($finalFilesMap[$filePath])) {
                    if (!isset($newFile['original_name'])) {
                        $newFile['original_name'] = $newFile['name'] ?? basename($filePath);
                    }
                    $finalFilesMap[$filePath] = $newFile;
                }
            }
            
            // 3. 轉換為數組
            $allOtherFiles = array_values($finalFilesMap);
            
            // 🔹 【關鍵修復】必須用 kept_files_json 與新檔案合併後的結果覆蓋更新 DB
            // 即使沒有新上傳檔案，也要更新 DB（用刪除後的 kept_files_json）
            // 這樣才能確保刪除的檔案在 F5 後不會復活
            if ($shouldClearFiles && empty($otherFiles)) {
                // 只有在明確要清空且沒有新檔案時，才設置為 null
                $otherFilesJson = null;
            } else {
                // 無論是否有新檔案，都要用合併後的結果更新 DB
                $otherFilesJson = !empty($allOtherFiles) ? json_encode($allOtherFiles, JSON_UNESCAPED_UNICODE) : null;
            }
            
            // 🔹 【除錯輸出】確保 otherFilesJson 正確
            error_log("[save_draft] 非編輯模式：kept_files_json count: " . count($existingFiles) . ", new_files count: " . count($otherFiles) . ", final count: " . count($allOtherFiles) . ", otherFilesJson: " . ($otherFilesJson ? 'has_value' : 'null'));
            }
            
            // 🔹 如果使用新格式，生成最終 JSON
            // 🔹 【修復清除全部】如果收到 clear_all=1，$otherFilesJson 已經設置為 null，不需要再次處理
            if ($isEditMode && isset($_POST['keep_existing_files']) && !isset($_POST['clear_all'])) {
                // 🔹 【修復清除全部】如果 shouldClearAllFiles 為 true，明確設置為 null（清空）
                if ($shouldClearAllFiles) {
                    $otherFilesJson = null; // 明確清空
                } else {
                    $otherFilesJson = !empty($allOtherFiles) ? json_encode($allOtherFiles, JSON_UNESCAPED_UNICODE) : null;
                }
            }
            
            // 檢查是否已有該團隊的暫存記錄（狀態 4）
            if ($prosub_ID <= 0) {
                $stmt = $conn->prepare("
                    SELECT prosub_ID, prosub_img
                    FROM prosubdata
                    WHERE team_ID = ? AND prosub_status = 4
                    ORDER BY prosub_update_d DESC
                    LIMIT 1
                ");
                $stmt->execute([$team_ID]);
                $existingDraft = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existingDraft) {
                    $prosub_ID = $existingDraft['prosub_ID'];
                    // 如果沒有上傳新文件，保留舊的圖片路徑
                    if (!$posterPath && $existingDraft['prosub_img']) {
                        $posterPath = $existingDraft['prosub_img'];
                    }
                }
            }
            
            $conn->beginTransaction();
            
            try {
                // 檢查是否被鎖定
                $isLocked = false;
                if ($prosub_ID > 0) {
                    $checkLockStmt = $conn->prepare("SELECT content_json FROM prosubdata WHERE prosub_ID = ? AND team_ID = ?");
                    $checkLockStmt->execute([$prosub_ID, $team_ID]);
                    $lockRecord = $checkLockStmt->fetch(PDO::FETCH_ASSOC);
                    if ($lockRecord) {
                        $lockContentJson = json_decode($lockRecord['content_json'] ?? '{}', true);
                        $isLocked = isset($lockContentJson['is_locked']) && $lockContentJson['is_locked'] === true;
                    }
                }
                
                if ($isLocked) {
                    $conn->rollBack();
                    
                    // 清除輸出緩衝區中的任何內容（包括可能的警告）
                    if (ob_get_level() > 0) {
                        ob_clean();
                    }
                    
                    json_response([
                        "success" => false,
                        "message" => "此提交已被科辦鎖定，無法修改"
                    ]);
                }
                
                if ($prosub_ID > 0) {
                    // 更新現有的暫存記錄
                    // 如果沒有上傳新海報，保留舊的海報路徑
                    if (!$posterPath) {
                        $oldStmt = $conn->prepare("SELECT prosub_img FROM prosubdata WHERE prosub_ID = ?");
                        $oldStmt->execute([$prosub_ID]);
                        $oldRecord = $oldStmt->fetch(PDO::FETCH_ASSOC);
                        if ($oldRecord && $oldRecord['prosub_img']) {
                            $posterPath = $oldRecord['prosub_img'];
                        }
                    }
                    
                    // 🔹 【關鍵修復】非編輯模式下，必須用 kept_files_json 覆蓋更新 DB
                    // 如果收到 kept_files_json，無論是否有新上傳檔案，都要用合併後的結果更新 DB
                    // 禁止 merge 舊 JSON，禁止使用初始化載入的檔案清單回寫 DB
                    // 只有在沒有收到 kept_files_json 且沒有新檔案時，才保留舊的檔案列表（向後兼容）
                    if (!$otherFilesJson && !$shouldClearAllFiles && !isset($_POST['clear_all']) && !isset($_POST['kept_files_json'])) {
                        // 只有在沒有收到 kept_files_json 時，才向後兼容保留舊的檔案列表
                        $oldStmt = $conn->prepare("SELECT prosub_other FROM prosubdata WHERE prosub_ID = ?");
                        $oldStmt->execute([$prosub_ID]);
                        $oldRecord = $oldStmt->fetch(PDO::FETCH_ASSOC);
                        if ($oldRecord && $oldRecord['prosub_other']) {
                            $otherFilesJson = $oldRecord['prosub_other'];
                        }
                    }
                    // 🔹 如果收到 kept_files_json，$otherFilesJson 已經在之前正確設置（包含刪除後的檔案列表）
                    // 這裡不需要再做任何處理，直接使用 $otherFilesJson 更新 DB
                    
                    // 🔹 【修復專題簡介暫存後消失】構建 UPDATE 語句，包含 prosub_intro 字段
                    $updateFields = [
                        'prosub_img = ?',
                        'prosub_other = ?',
                        'content_json = ?',
                        'prosub_update_d = NOW()',
                        'prosub_u_ID = ?'
                    ];
                    $updateValues = [
                        $posterPath,
                        $otherFilesJson,
                        json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                        $u_ID
                    ];
                    
                    // 如果存在 prosub_intro 字段，必須寫入專題簡介
                    if ($hasIntroField) {
                        $updateFields[] = 'prosub_intro = ?';
                        $updateValues[] = $project_intro;
                    } else {
                        // 如果沒有 intro 字段，存到 JSON（兼容舊資料）
                        $contentJson['intro'] = $project_intro;
                        $updateValues[2] = json_encode($contentJson, JSON_UNESCAPED_UNICODE);
                    }
                    
                    $updateValues[] = $prosub_ID;
                    $updateValues[] = $team_ID;
                    
                    $updateSql = "UPDATE prosubdata SET " . implode(', ', $updateFields) . "
                        WHERE prosub_ID = ? AND team_ID = ? AND prosub_status = 4";
                    
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->execute($updateValues);
                    
                    // 🔹 【Debug 必做】後端 UPDATE 成功後重新 SELECT 確認 DB other_files 已更新
                    $verifyStmt = $conn->prepare("SELECT prosub_other FROM prosubdata WHERE prosub_ID = ?");
                    $verifyStmt->execute([$prosub_ID]);
                    $verifyRecord = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                    if ($verifyRecord) {
                        $verifyOtherFiles = json_decode($verifyRecord['prosub_other'] ?? '[]', true);
                        error_log("[save_draft] ✅ UPDATE 成功後確認 DB other_files count: " . (is_array($verifyOtherFiles) ? count($verifyOtherFiles) : 0));
                        error_log("[save_draft] ✅ UPDATE 成功後確認 DB other_files JSON: " . ($verifyRecord['prosub_other'] ?? 'null'));
                    }
                    
                    // 暫存不需要記錄歷史
                } else {
                    // 使用 upsert 邏輯：先查詢是否存在（pro_ID + team_ID 的唯一鍵）
                    // 使用函數獲取或創建有效的 pro_ID
                    $pro_ID = getOrCreateProjectID($conn, $team_ID, $u_ID);
                    
                    // 查詢是否已存在記錄（不論狀態）
                    $checkStmt = $conn->prepare("SELECT prosub_ID FROM prosubdata WHERE pro_ID = ? AND team_ID = ?");
                    $checkStmt->execute([$pro_ID, $team_ID]);
                    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        // 已存在記錄，更新為暫存狀態
                        $prosub_ID = $existing['prosub_ID'];
                        
                        //  構建 UPDATE 語句（如果有 intro 字段，則更新字段；否則存到 JSON）
                        $updateFields = [
                            'prosub_img = ?',
                            'prosub_other = ?',
                            'content_json = ?',
                            'prosub_status = 4',
                            'prosub_update_d = NOW()',
                            'prosub_u_ID = ?'
                        ];
                        $updateValues = [
                            $posterPath,
                            $otherFilesJson,
                            json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                            $u_ID
                        ];
                        
                        if ($hasIntroField) {
                            $updateFields[] = 'prosub_intro = ?';
                            $updateValues[] = $project_intro;
                        } else {
                            // 如果沒有 intro 字段，存到 JSON（兼容舊資料）
                            $contentJson['intro'] = $project_intro;
                            $updateValues[2] = json_encode($contentJson, JSON_UNESCAPED_UNICODE);
                        }
                        
                        $updateValues[] = $prosub_ID;
                        $updateValues[] = $team_ID;
                        
                        $updateSql = "UPDATE prosubdata SET " . implode(', ', $updateFields) . "
                            WHERE prosub_ID = ? AND team_ID = ?";
                        
                        $updateStmt = $conn->prepare($updateSql);
                        $updateStmt->execute($updateValues);
                    } else {
                        // 不存在記錄，創建新的暫存記錄
                        //  構建 INSERT 語句（如果有 intro 字段，則插入字段；否則存到 JSON）
                        $insertFields = ['pro_ID', 'team_ID', 'prosub_img', 'prosub_other', 'content_json', 'prosub_status', 'prosub_u_ID', 'prosub_created_d', 'prosub_update_d'];
                        $insertPlaceholders = ['?', '?', '?', '?', '?', '4', '?', 'NOW()', 'NOW()'];
                        $insertValues = [$pro_ID, $team_ID, $posterPath, $otherFilesJson, json_encode($contentJson, JSON_UNESCAPED_UNICODE), $u_ID];
                        
                        if ($hasIntroField) {
                            $insertFields[] = 'prosub_intro';
                            $insertPlaceholders[] = '?';
                            $insertValues[] = $project_intro;
                        } else {
                            // 如果沒有 intro 字段，存到 JSON（兼容舊資料）
                            $contentJson['intro'] = $project_intro;
                            $insertValues[4] = json_encode($contentJson, JSON_UNESCAPED_UNICODE);
                        }
                        
                        $insertSql = "INSERT INTO prosubdata (" . implode(', ', $insertFields) . ")
                            VALUES (" . implode(', ', $insertPlaceholders) . ")";
                        
                        $insertStmt = $conn->prepare($insertSql);
                        $insertStmt->execute($insertValues);
                        
                        $prosub_ID = $conn->lastInsertId();
                    }
                }
                
                $conn->commit();
                
                // 🔹 【修復文件數量異常增加】暫存成功後，返回最新的 other_files JSON
                // 前端必須以回傳結果為準，避免從舊數據重新載入
                $finalOtherFiles = [];
                if ($otherFilesJson) {
                    $finalOtherFilesJson = json_decode($otherFilesJson, true);
                    if (is_array($finalOtherFilesJson)) {
                        $finalOtherFiles = $finalOtherFilesJson;
                    }
                }
                
                // 🔹 【除錯輸出】確保返回的數據正確
                error_log("[save_draft] 返回給前端的 other_files count: " . count($finalOtherFiles));
                
                json_response([
                    "success" => true,
                    "message" => "暫存成功",
                    "data" => [
                        "prosub_ID" => $prosub_ID,
                        "other_files" => $finalOtherFiles // 🔹 返回最新的文件列表
                    ]
                ]);
            } catch (Exception $e) {
                $conn->rollBack();
                json_response([
                    "success" => false,
                    "message" => "暫存失敗：" . $e->getMessage()
                ]);
            }
            break;

        case 'submit':
            // 🔹 【動態判斷機制】檢查該學級是否可以上傳（完全以資料庫設定為依據，與前端顯示邏輯一致）
            // 🔹 【調試日誌】記錄查詢參數
            error_log("提交驗證 - cohort_ID: " . ($cohort_ID ?? 'null') . ", class_ID: " . ($class_ID ?? 'null') . ", team_ID: " . ($team_ID ?? 'null'));
            
            $activePeriod = checkActivePeriod($conn, $cohort_ID, $class_ID);
            
            // 🔹 【調試日誌】記錄查詢結果
            error_log("提交驗證 - checkActivePeriod 結果: " . ($activePeriod ? json_encode($activePeriod) : 'null'));
            
            if (!$activePeriod) {
                // 🔹 【調試日誌】記錄失敗原因
                error_log("提交驗證失敗 - cohort_ID: " . ($cohort_ID ?? 'null') . ", class_ID: " . ($class_ID ?? 'null'));
                
                json_response([
                    "success" => false,
                    "message" => "目前沒有開放上傳時段，科辦會設定統一的上傳期限"
                ]);
            }
            
            // 🔹 【統一邏輯】因為 checkActivePeriod 已使用 NOW() BETWEEN pro_start_d AND pro_end_d 查詢
            // 所以返回的時段一定是正在進行中的，不需要再次檢查時間
            // 繼續執行提交邏輯
            
            // ====== 提交功能 ======
            //  提交時才做必要驗證（例如簡介必填），不要影響暫存流程
            $prosub_ID = isset($_POST['prosub_ID']) ? (int)$_POST['prosub_ID'] : 0;
            $project_intro = trim($_POST['project_intro'] ?? '');
            $confirmOverride = isset($_POST['confirm_override']) && $_POST['confirm_override'] === '1';
            
            // 【表單防呆】提交時驗證必填欄位
            $missingFields = [];
            if (empty($project_intro)) {
                $missingFields[] = '專題簡介';
            }
            
            // 檢查海報（必須有已上傳的或這次有選的）
            $hasPoster = false;
            if (isset($_FILES['poster']) && $_FILES['poster']['error'] === UPLOAD_ERR_OK) {
                $hasPoster = true;
            } elseif ($prosub_ID > 0) {
                // 編輯模式：檢查資料庫中是否有海報
                $checkPosterStmt = $conn->prepare("SELECT prosub_img FROM prosubdata WHERE prosub_ID = ? AND team_ID = ?");
                $checkPosterStmt->execute([$prosub_ID, $team_ID]);
                $posterRecord = $checkPosterStmt->fetch(PDO::FETCH_ASSOC);
                if ($posterRecord && !empty($posterRecord['prosub_img'])) {
                    $hasPoster = true;
                }
            }
            if (!$hasPoster) {
                $missingFields[] = '海報';
            }
            
            // 🔹 【多個檔案改為可選】檢查多個檔案（不強制上傳）
            // 優先檢查新上傳的檔案（multi_files[]），然後檢查已暫存的檔案（kept_files_json）
            $hasOtherFiles = false;
            
            // 1. 檢查是否有新上傳的檔案（multi_files[]）
            if (isset($_FILES['multi_files']) && is_array($_FILES['multi_files']['error']) && !empty($_FILES['multi_files']['name'][0])) {
                $hasOtherFiles = true;
            }
            // 2. 檢查是否有已暫存的檔案（kept_files_json）- 非編輯模式
            elseif (isset($_POST['kept_files_json']) && !empty($_POST['kept_files_json'])) {
                $keptFilesJson = json_decode($_POST['kept_files_json'], true);
                if (is_array($keptFilesJson) && count($keptFilesJson) > 0) {
                    $hasOtherFiles = true;
                }
            }
            // 3. 編輯模式：檢查資料庫中是否有檔案
            elseif ($prosub_ID > 0) {
                $checkFilesStmt = $conn->prepare("SELECT prosub_other FROM prosubdata WHERE prosub_ID = ? AND team_ID = ?");
                $checkFilesStmt->execute([$prosub_ID, $team_ID]);
                $filesRecord = $checkFilesStmt->fetch(PDO::FETCH_ASSOC);
                if ($filesRecord && !empty($filesRecord['prosub_other'])) {
                    $otherFilesJson = json_decode($filesRecord['prosub_other'], true);
                    if (is_array($otherFilesJson) && count($otherFilesJson) > 0) {
                        $hasOtherFiles = true;
                    }
                }
            }
            // 4. 向後兼容：檢查 existing_files（舊格式）
            elseif (isset($_POST['existing_files']) && !empty($_POST['existing_files'])) {
                $existingFilesJson = json_decode($_POST['existing_files'], true);
                if (is_array($existingFilesJson) && count($existingFilesJson) > 0) {
                    $hasOtherFiles = true;
                }
            }
            
            // 🔹 【移除強制驗證】多個檔案改為可選，不再強制要求
            // if (!$hasOtherFiles) {
            //     $missingFields[] = '多個檔案（至少1個）';
            // }
            
            // 如果有缺少的欄位，返回錯誤
            if (!empty($missingFields)) {
                json_response([
                    "success" => false,
                    "message" => "缺少必填項目：" . implode('、', $missingFields)
                ]);
            }
            
            // 獲取專題 ID（使用函數確保總是返回有效的 pro_ID）
            $pro_ID = getOrCreateProjectID($conn, $team_ID, $u_ID);

            // 檔案類型改為每筆檔案各自驗證（見下方處理 multi_files / keep_existing / new_files 時）
            
            //  檢查是否有 intro 字段
            $hasIntroField = false;
            try {
                $checkStmt = $conn->prepare("SHOW COLUMNS FROM prosubdata LIKE 'prosub_intro'");
                $checkStmt->execute();
                $hasIntroField = $checkStmt->rowCount() > 0;
            } catch (Exception $e) {
                // 忽略錯誤
            }
            
            // 檢查是否已有提交記錄（狀態不是4的）
            $existingSubmitStmt = $conn->prepare("
                SELECT prosub_ID, prosub_status, content_json 
                FROM prosubdata 
                WHERE team_ID = ? AND prosub_status != 4
                ORDER BY prosub_created_d DESC 
                LIMIT 1
            ");
            $existingSubmitStmt->execute([$team_ID]);
            $existingSubmit = $existingSubmitStmt->fetch(PDO::FETCH_ASSOC);
            
            // 🔹 【新邏輯】期限內維持學生僅能繳交一次，一旦繳交，科辦隨時都可以審核
            // 通過→無法修改，退件→重新修改上傳
            if ($existingSubmit) {
                $currentStatus = (int)$existingSubmit['prosub_status'];
                
                // 編輯模式下，如果prosub_ID等於existingSubmit的ID，表示是在編輯當前記錄
                $isEditingCurrentRecord = ($prosub_ID > 0 && $prosub_ID == $existingSubmit['prosub_ID']);
                
                // 🔹 【新邏輯】不論是否編輯當前記錄，都根據狀態判斷是否允許提交
                // 通過狀態（狀態3）：無法修改
                if ($currentStatus == 3) {
                    json_response([
                        "success" => false,
                        "message" => "此提交已通過審核，無法修改。"
                    ]);
                }
                // 退件狀態（狀態0）：可以重新修改上傳
                elseif ($currentStatus == 0) {
                    // 允許提交，繼續執行
                }
                // 其他已提交狀態（如未審核1、審核中2等）：期限內一旦提交就不能再修改
                elseif ($currentStatus != 4) {
                    json_response([
                        "success" => false,
                        "message" => "您已提交專題，期限內僅能繳交一次。如需修改，請等待科辦審核結果。"
                    ]);
                }
            }
            
            // 如果已有提交記錄且不是確認覆蓋，且不是編輯當前記錄，返回提示
            // 編輯模式下，如果prosub_ID等於existingSubmit的ID，表示是在編輯當前記錄，應該允許提交
            if ($existingSubmit && !$confirmOverride && (!($prosub_ID > 0 && $prosub_ID == $existingSubmit['prosub_ID']))) {
                $existingContentJson = json_decode($existingSubmit['content_json'] ?? '{}', true);
                $existingIntro = $existingContentJson['intro'] ?? '';
                $existingTime = $existingSubmit['prosub_created_d'] ?? '';
                
                json_response([
                    "success" => false,
                    "message" => "已有提交記錄",
                    "data" => [
                        "has_existing" => true,
                        "existing_id" => $existingSubmit['prosub_ID'],
                        "existing_intro" => $existingIntro,
                        "existing_time" => $existingTime,
                        "need_confirm" => true
                    ]
                ]);
            }
            
            // 【D1 編輯模式防呆】先 SELECT 取得原始資料（prosub_img, prosub_other, content_json）
            // 這是防呆的核心：先獲取原值，再根據新文件決定是否更新
            $originalData = null;
            $isEditMode = false;
            if ($prosub_ID > 0) {
                $originalStmt = $conn->prepare("SELECT prosub_img, prosub_other, content_json, prosub_status FROM prosubdata WHERE prosub_ID = ? AND team_ID = ?");
                $originalStmt->execute([$prosub_ID, $team_ID]);
                $originalData = $originalStmt->fetch(PDO::FETCH_ASSOC);
                if ($originalData) {
                    $isEditMode = true;
                }
            }
            
            // 處理文件上傳
            $posterPath = null;
            if (isset($_FILES['poster']) && $_FILES['poster']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['poster'];
                $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf'];
                $maxSize = 10 * 1024 * 1024; // 10MB
                
                if (!in_array($file['type'], $allowedTypes)) {
                    json_response([
                        "success" => false,
                        "message" => "檔案格式不正確，請上傳圖片或PDF"
                    ]);
                }
                
                if ($file['size'] > $maxSize) {
                    json_response([
                        "success" => false,
                        "message" => "檔案大小超過 10MB"
                    ]);
                }
                
                // 創建上傳目錄
                $uploadDir = '../uploads/project_posters/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                // 生成唯一檔名
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'poster_' . $team_ID . '_' . time() . '_' . uniqid() . '.' . $ext;
                $filePath = $uploadDir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $filePath)) {
                    $posterPath = 'uploads/project_posters/' . $filename;
                    
                    //  儲存原始檔名到 content_json（僅用於顯示，不影響實際檔案儲存與路徑）
                    $originalFileName = $file['name'];
                    $contentJson['poster_original_name'] = $originalFileName;
                } else {
                    json_response([
                        "success" => false,
                        "message" => "檔案上傳失敗"
                    ]);
                }
            } else {
                // 【D1 編輯模式防呆】如果沒有上傳新文件，使用原始資料中的值
                if ($isEditMode && $originalData && $originalData['prosub_img']) {
                    $posterPath = $originalData['prosub_img'];
                    
                    //  保留原始檔名（如果記錄中有）
                    if ($originalData['content_json']) {
                        $originalContentJson = json_decode($originalData['content_json'], true);
                        if (isset($originalContentJson['poster_original_name'])) {
                            $contentJson['poster_original_name'] = $originalContentJson['poster_original_name'];
                        }
                    }
                }
                
                // 編輯模式下，如果資料庫中有檔案就保留，沒有才要求上傳
                // 新增模式或暫存模式下，必須上傳海報
                if (!$posterPath && !$isEditMode) {
                    json_response([
                        "success" => false,
                        "message" => "請上傳直式海報"
                    ]);
                }
            }
            
            // 🔹 【關鍵修復】處理多個檔案上傳（優先使用 multi_files[]，向後兼容 other_files[]）
            $otherFiles = [];
            
            // 優先處理新格式：multi_files[]
            $filesKey = 'multi_files';
            if (!isset($_FILES['multi_files']) || !is_array($_FILES['multi_files']['error'])) {
                // 向後兼容：檢查舊格式 other_files[]
                if (isset($_FILES['other_files']) && is_array($_FILES['other_files']['error'])) {
                    $filesKey = 'other_files';
                } else {
                    $filesKey = null;
                }
            }
            
                $allAllowedTypes = getUnionOfAllowedFileTypes($conn, $cohort_ID, $class_ID);
                $submitFileTypes = isset($_POST['file_types']) && is_array($_POST['file_types']) ? $_POST['file_types'] : [];
                if ($filesKey && isset($_FILES[$filesKey]) && is_array($_FILES[$filesKey]['error'])) {
                    $uploadDir = BASE_PATH . '/uploads/project_other_files/';
                    
                    if (!is_dir($uploadDir)) {
                        if (!mkdir($uploadDir, 0777, true)) {
                            json_response([
                                "success" => false,
                                "message" => "無法建立上傳資料夾"
                            ]);
                        }
                    }
                    
                    if (!is_writable($uploadDir)) {
                        json_response([
                            "success" => false,
                            "message" => "上傳資料夾不可寫入"
                        ]);
                    }
                    
                    $fileCount = count($_FILES[$filesKey]['error']);
                    for ($i = 0; $i < $fileCount; $i++) {
                        if ($_FILES[$filesKey]['error'][$i] === UPLOAD_ERR_OK) {
                            $file_type_i = isset($submitFileTypes[$i]) ? trim((string)$submitFileTypes[$i]) : '';
                            validateFileTypeOrFail($conn, $allAllowedTypes, $file_type_i);
                        $file = [
                            'name' => $_FILES[$filesKey]['name'][$i],
                            'type' => $_FILES[$filesKey]['type'][$i],
                            'tmp_name' => $_FILES[$filesKey]['tmp_name'][$i],
                            'size' => $_FILES[$filesKey]['size'][$i]
                        ];
                        
                        $maxSize = 50 * 1024 * 1024; // 1000MB per file
                        if ($file['size'] > $maxSize) {
                            continue;
                        }
                        
                        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $filename = 'other_' . $team_ID . '_' . time() . '_' . $i . '_' . uniqid() . '.' . $ext;
                        $absPath = $uploadDir . $filename;
                        
                        if (!move_uploaded_file($file['tmp_name'], $absPath)) {
                            error_log("move_uploaded_file failed: " . $absPath);
                            continue;
                        }
                        
                        $relPath = 'uploads/project_other_files/' . $filename;
                        $otherFiles[] = [
                            'original_name' => $file['name'],
                            'name' => $file['name'],
                            'path' => $relPath,
                            'type' => $file['type'] ?? '',
                            'uploaded_at' => date('Y-m-d H:i:s'),
                            'public' => true,
                            'allow_download' => true,
                            'file_type' => $file_type_i
                        ];
                    }
                }
            }
            
            // 🔹 處理多文件（支持新舊兩種格式）
            // 優先處理新格式（編輯模式專用）
            if ($isEditMode && isset($_POST['keep_existing_files']) && isset($_POST['delete_existing_keys'])) {
                // 新格式：keep/delete/new
                $keepExistingFilesJson = json_decode($_POST['keep_existing_files'], true);
                $deleteExistingKeysJson = json_decode($_POST['delete_existing_keys'], true);
                
                $keepExistingFiles = [];
                $deleteExistingKeys = [];
                
                if (is_array($keepExistingFilesJson)) {
                    $keepExistingFiles = $keepExistingFilesJson;
                }
                if (is_array($deleteExistingKeysJson)) {
                    $deleteExistingKeys = $deleteExistingKeysJson;
                }
                
                $submitNewFileTypes = isset($_POST['new_file_types']) && is_array($_POST['new_file_types']) ? $_POST['new_file_types'] : [];
                if (isset($_FILES['new_files']) && is_array($_FILES['new_files']['error'])) {
                    $uploadDir = BASE_PATH . '/uploads/project_other_files/';
                    
                    if (!is_dir($uploadDir)) {
                        if (!mkdir($uploadDir, 0777, true)) {
                            json_response([
                                "success" => false,
                                "message" => "無法建立上傳資料夾"
                            ]);
                        }
                    }
                    
                    if (!is_writable($uploadDir)) {
                        json_response([
                            "success" => false,
                            "message" => "上傳資料夾不可寫入"
                        ]);
                    }
                    
                    $fileCount = count($_FILES['new_files']['error']);
                    for ($i = 0; $i < $fileCount; $i++) {
                        if ($_FILES['new_files']['error'][$i] === UPLOAD_ERR_OK) {
                            $file_type_i = isset($submitNewFileTypes[$i]) ? trim((string)$submitNewFileTypes[$i]) : '';
                            validateFileTypeOrFail($conn, $allAllowedTypes, $file_type_i);
                            $file = [
                                'name' => $_FILES['new_files']['name'][$i],
                                'type' => $_FILES['new_files']['type'][$i],
                                'tmp_name' => $_FILES['new_files']['tmp_name'][$i],
                                'size' => $_FILES['new_files']['size'][$i]
                            ];
                            
                            $maxSize = 50 * 1024 * 1024; // 1000MB per file
                            if ($file['size'] > $maxSize) {
                                continue;
                            }
                            
                            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                            $filename = 'other_' . $team_ID . '_' . time() . '_' . $i . '_' . uniqid() . '.' . $ext;
                            $absPath = $uploadDir . $filename;
                            
                            if (!move_uploaded_file($file['tmp_name'], $absPath)) {
                                error_log("move_uploaded_file failed: " . $absPath);
                                continue;
                            }
                            
                            $relPath = 'uploads/project_other_files/' . $filename;
                            $otherFiles[] = [
                                'original_name' => $file['name'],
                                'name' => $file['name'],
                                'path' => $relPath,
                                'type' => $file['type'] ?? '',
                                'uploaded_at' => date('Y-m-d H:i:s'),
                                'public' => true,
                                'file_type' => $file_type_i
                            ];
                        }
                    }
                }
                
                // 🔹 從原始資料中獲取舊檔案列表，然後根據 keep/delete 處理
                $oldOtherFiles = [];
                if ($originalData && $originalData['prosub_other']) {
                    $oldOtherFilesJson = json_decode($originalData['prosub_other'], true);
                    if (is_array($oldOtherFilesJson)) {
                        $oldOtherFiles = $oldOtherFilesJson;
                    }
                }
                
                // 🔹 根據 delete_existing_keys 刪除舊檔案（從檔案系統和列表中移除）
                foreach ($deleteExistingKeys as $deleteKey) {
                    foreach ($oldOtherFiles as $index => $oldFile) {
                        $filePath = is_array($oldFile) ? ($oldFile['path'] ?? '') : $oldFile;
                        if ($filePath === $deleteKey || (is_array($oldFile) && isset($oldFile['path']) && $oldFile['path'] === $deleteKey)) {
                            // 刪除實體檔案
                            $fullPath = BASE_PATH . '/' . $filePath;
                            if (file_exists($fullPath)) {
                                @unlink($fullPath);
                            }
                            // 從列表中移除
                            unset($oldOtherFiles[$index]);
                        }
                    }
                }
                $oldOtherFiles = array_values($oldOtherFiles); // 重新索引
                
                // 🔹 最終檔案列表 = 保留的舊檔案（從 keep_existing_files） + 新上傳的檔案
                // 🔹 【修復文件數量異常增加】合併前先做去重，避免同一檔案被重複加入
                // 使用 path 作為唯一 key 進行去重
                $finalFilesMap = []; // 使用 path 作為 key 的 map，避免重複
                
                // 1. 先加入保留的舊檔案（從 keep_existing_files），每筆須帶 file_type 並驗證
                foreach ($keepExistingFiles as $keptFile) {
                    $filePath = is_array($keptFile) ? ($keptFile['path'] ?? '') : $keptFile;
                    if ($filePath && !isset($finalFilesMap[$filePath])) {
                        $ft = isset($keptFile['file_type']) ? trim((string)$keptFile['file_type']) : '';
                        validateFileTypeOrFail($conn, $allAllowedTypes, $ft);
                        if (is_array($keptFile)) {
                            $finalFilesMap[$filePath] = [
                                'original_name' => $keptFile['original_name'] ?? $keptFile['name'] ?? basename($filePath),
                                'name' => $keptFile['original_name'] ?? $keptFile['name'] ?? basename($filePath),
                                'path' => $keptFile['path'] ?? $filePath,
                                'type' => $keptFile['type'] ?? '',
                                'uploaded_at' => $keptFile['uploaded_at'] ?? $keptFile['upload_time'] ?? '',
                                'public' => isset($keptFile['public']) ? (bool)$keptFile['public'] : true,
                                'file_type' => $ft
                            ];
                        } else {
                            $finalFilesMap[$filePath] = [
                                'original_name' => basename($filePath),
                                'name' => basename($filePath),
                                'path' => $filePath,
                                'type' => '',
                                'uploaded_at' => '',
                                'public' => true,
                                'file_type' => $ft
                            ];
                        }
                    }
                }
                
                // 2. 再加入新上傳的檔案（如果 path 已存在則跳過）
                foreach ($otherFiles as $newFile) {
                    $filePath = $newFile['path'] ?? '';
                    if ($filePath && !isset($finalFilesMap[$filePath])) {
                        if (!isset($newFile['original_name'])) {
                            $newFile['original_name'] = $newFile['name'] ?? basename($filePath);
                        }
                        $finalFilesMap[$filePath] = $newFile;
                    }
                }
                
                // 3. 轉換為數組
                $allOtherFiles = array_values($finalFilesMap);
                
                error_log("[submit] 暫存前 oldFiles count: " . count($oldOtherFiles));
                error_log("[submit] keptFiles count: " . count($keepExistingFiles));
                error_log("[submit] addedFiles count: " . count($otherFiles));
                error_log("[submit] finalFiles count: " . count($allOtherFiles));
                
                $otherFilesJson = !empty($allOtherFiles) ? json_encode($allOtherFiles, JSON_UNESCAPED_UNICODE) : null;
            } else {
                // 🔹 非編輯模式：優先使用 kept_files_json，向後兼容 existing_files + other_files[]
            $hasNewOtherFiles = !empty($otherFiles);
            
            // 🔹 【關鍵修復】處理要保留的舊檔案（優先使用 kept_files_json）
            $existingFiles = [];
            $hasUserAction = false;
            
            // 優先檢查 kept_files_json（新格式）
            if (isset($_POST['kept_files_json'])) {
                $hasUserAction = true;
                $keptFilesJson = json_decode($_POST['kept_files_json'], true);
                if (is_array($keptFilesJson) && !empty($keptFilesJson)) {
                    foreach ($keptFilesJson as $file) {
                        if (is_string($file)) {
                            $existingFiles[] = [
                                'original_name' => basename($file),
                                'name' => basename($file),
                                'path' => $file,
                                'type' => '',
                                'uploaded_at' => '',
                                'public' => true
                            ];
                        } elseif (is_array($file) && isset($file['path'])) {
                            if (isset($file['name']) && isset($file['type']) && isset($file['uploaded_at']) && isset($file['public'])) {
                                // 確保有 original_name
                                if (!isset($file['original_name'])) {
                                    $file['original_name'] = $file['name'] ?? basename($file['path']);
                                }
                                $existingFiles[] = $file;
                            } else {
                                $fileName = $file['original_name'] ?? $file['name'] ?? basename($file['path']);
                                $uploadTime = $file['uploaded_at'] ?? $file['upload_time'] ?? '';
                                $isPublic = isset($file['public']) ? (bool)$file['public'] : (isset($file['allow_download']) ? (bool)$file['allow_download'] : true);
                                $ft = isset($file['file_type']) ? trim((string)$file['file_type']) : '';
                                $existingFiles[] = [
                                    'original_name' => $fileName,
                                    'name' => $fileName,
                                    'path' => $file['path'],
                                    'type' => $file['type'] ?? '',
                                    'uploaded_at' => $uploadTime,
                                    'public' => $isPublic,
                                    'file_type' => $ft
                                ];
                            }
                        }
                    }
                }
            } elseif (isset($_POST['existing_files'])) {
                // 向後兼容：使用 existing_files（舊格式）
                $hasUserAction = true;
                if (!empty($_POST['existing_files'])) {
                    $existingFilesJson = json_decode($_POST['existing_files'], true);
                    if (is_array($existingFilesJson)) {
                        $existingFiles = $existingFilesJson;
                    }
                }
            } elseif ($isEditMode && $originalData && $originalData['prosub_other']) {
                // 🔹 【編輯模式防呆】如果用戶沒有明確操作且沒有新文件，使用原始資料中的值
                // 這樣可以避免多按一次提交資料清空
                $oldOtherFilesJson = json_decode($originalData['prosub_other'], true);
                if (is_array($oldOtherFilesJson)) {
                    $existingFiles = $oldOtherFilesJson;
                }
            }
            
            // 合併新上傳的檔案和保留的舊檔案
            // 🔹 【修復 JSON 膨脹】舊格式也要做去重，使用 path 作為唯一鍵
            $finalFilesMap = [];
            
            // 1. 先加入保留的舊檔案（從 existing_files），每筆須帶 file_type 並驗證
            foreach ($existingFiles as $existingFile) {
                $filePath = is_array($existingFile) ? ($existingFile['path'] ?? '') : $existingFile;
                if ($filePath && !isset($finalFilesMap[$filePath])) {
                    $ft = isset($existingFile['file_type']) ? trim((string)$existingFile['file_type']) : '';
                    validateFileTypeOrFail($conn, $allAllowedTypes, $ft);
                    if (is_array($existingFile)) {
                        $finalFilesMap[$filePath] = [
                            'original_name' => $existingFile['original_name'] ?? $existingFile['name'] ?? basename($filePath),
                            'name' => $existingFile['original_name'] ?? $existingFile['name'] ?? basename($filePath),
                            'path' => $existingFile['path'] ?? $filePath,
                            'type' => $existingFile['type'] ?? '',
                            'uploaded_at' => $existingFile['uploaded_at'] ?? $existingFile['upload_time'] ?? '',
                            'public' => isset($existingFile['public']) ? (bool)$existingFile['public'] : true,
                            'file_type' => $ft
                        ];
                    } else {
                        $finalFilesMap[$filePath] = [
                            'original_name' => basename($filePath),
                            'name' => basename($filePath),
                            'path' => $filePath,
                            'type' => '',
                            'uploaded_at' => '',
                            'public' => true,
                            'file_type' => $ft
                        ];
                    }
                }
            }
            
            // 2. 再加入新上傳的檔案（如果 path 已存在則跳過，避免重複）
            foreach ($otherFiles as $newFile) {
                $filePath = $newFile['path'] ?? '';
                if ($filePath && !isset($finalFilesMap[$filePath])) {
                    if (!isset($newFile['original_name'])) {
                        $newFile['original_name'] = $newFile['name'] ?? basename($filePath);
                    }
                    $finalFilesMap[$filePath] = $newFile;
                }
            }
            
            // 3. 轉換為數組
            $allOtherFiles = array_values($finalFilesMap);
            
            if (!empty($allOtherFiles)) {
                $otherFilesJson = json_encode($allOtherFiles, JSON_UNESCAPED_UNICODE);
            } elseif ($isEditMode && $originalData && $originalData['prosub_other']) {
                // 🔹 【編輯模式防呆】如果最終結果為空，但在編輯模式下原始資料有值，保留原值（不能寫 NULL）
                // 避免多按一次提交資料清空
                $otherFilesJson = $originalData['prosub_other'];
            } else {
                // 新增模式或用戶明確刪除所有檔案：允許為 null
                $otherFilesJson = null;
                }
            }
            
            // 🔹 同樣的邏輯應用於海報：如果沒有上傳新海報，保留舊海報
            if (!$posterPath && $isEditMode && $originalData && $originalData['prosub_img']) {
                $posterPath = $originalData['prosub_img'];
            }
            
            // 檢查是否被時間鎖定
            if ($isTimeLocked) {
                json_response([
                    "success" => false,
                    "message" => "已超過上傳截止時間（" . date('Y-m-d H:i', strtotime($lockTime)) . "），無法提交"
                ]);
            }
            
            // 檢查是否被手動鎖定
            $isLocked = false;
            if ($existingSubmit) {
                $existingContentJson = json_decode($existingSubmit['content_json'] ?? '{}', true);
                $isLocked = isset($existingContentJson['is_locked']) && $existingContentJson['is_locked'] === true;
            }
            
            if ($isLocked) {
                json_response([
                    "success" => false,
                    "message" => "此提交已被科辦鎖定，無法修改"
                ]);
            }
            
            // 🔹 【部分更新策略】處理簡介
            // 如果前端有傳入簡介，使用新值；否則在編輯模式下保留舊值
            if (!empty($project_intro)) {
                $contentJson['intro'] = $project_intro;
            } elseif ($isEditMode && $originalData && isset($contentJson['intro'])) {
                // 編輯模式下，如果前端沒傳簡介，保留舊值（已經從 originalData 載入）
                // 不需要額外處理，因為 $contentJson 已經從 $originalData['content_json'] 載入
            } else {
                // 新增模式且沒有簡介，設為空字串
                $contentJson['intro'] = $project_intro ?? '';
            }
            
            // 檢查是否是從退件狀態修改提交（狀態 0）
            $isResubmit = false;
            if ($isEditMode && $originalData && $originalData['prosub_status'] == 0) {
                // 這是從退件狀態重新提交，直接更新狀態為 1（審核中）
                $isResubmit = true;
            }
            
            // 如果有現有提交記錄且確認覆蓋，保存歷史記錄
            $history = [];
            if ($existingSubmit && $confirmOverride) {
                $oldContentJson = json_decode($existingSubmit['content_json'] ?? '{}', true);
                $oldHistory = $oldContentJson['history'] ?? [];
                
                // 添加歷史記錄：已取代
                $history = addHistoryRecord($oldHistory, 'replaced', $u_ID);
                
                $contentJson['history'] = $history;
                $contentJson['original_draft_id'] = $oldContentJson['original_draft_id'] ?? null;
            }
            
            $conn->beginTransaction();
            
            try {
                if ($isResubmit && $prosub_ID > 0) {
                    // 從退件狀態直接更新為提交（狀態 1，不需要再審核）
                    // 獲取現有記錄的內容以保存歷史
                    $checkStmt = $conn->prepare("SELECT content_json, prosub_img FROM prosubdata WHERE prosub_ID = ? AND team_ID = ?");
                    $checkStmt->execute([$prosub_ID, $team_ID]);
                    $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existingRecord) {
                        $oldContentJson = json_decode($existingRecord['content_json'] ?? '{}', true);
                        $oldHistory = $oldContentJson['history'] ?? [];
                        
                        // 添加重新提交的歷史記錄（覆蓋提交）
                        $contentJson['history'] = addHistoryRecord($oldHistory, 'replaced', $u_ID);
                    }
                    
                    // 🔹 【部分更新策略】確保使用正確的值（新值或舊值）
                    // 如果沒有新海報，使用原始資料中的海報路徑
                    if (!$posterPath && $isEditMode && $originalData && $originalData['prosub_img']) {
                        $posterPath = $originalData['prosub_img'];
                    }
                    // 如果沒有新多檔且沒有用戶操作，使用原始資料中的多檔
                    if ($otherFilesJson === null && $isEditMode && $originalData && $originalData['prosub_other']) {
                        $otherFilesJson = $originalData['prosub_other'];
                    }
                    
                    // 【提交時必須確實寫入所有資料到資料庫】
                    $updateFields = [
                        'prosub_img = ?',
                        'prosub_other = ?',
                        'content_json = ?',
                        'prosub_status = 1',
                        'prosub_reason = NULL',
                        'prosub_re_reason = NULL',
                        'prosub_re_u_ID = NULL',
                        'prosub_re_d = NULL',
                        'prosub_update_d = NOW()',
                        'prosub_u_ID = ?'
                    ];
                    $updateValues = [
                        $posterPath,
                        $otherFilesJson,
                        json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                        $u_ID
                    ];
                    
                    // 如果存在 prosub_intro 字段，必須寫入專題簡介
                    if ($hasIntroField) {
                        $updateFields[] = 'prosub_intro = ?';
                        $updateValues[] = $project_intro;
                    }
                    
                    $updateValues[] = $prosub_ID;
                    $updateValues[] = $team_ID;
                    
                    $updateSql = "UPDATE prosubdata SET " . implode(', ', $updateFields) . " WHERE prosub_ID = ? AND team_ID = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->execute($updateValues);
                    
                    // 添加歷史記錄到 content_json（重新提交）
                    $existingHistory = isset($contentJson['history']) ? $contentJson['history'] : [];
                    $snapshotData = [
                        'intro' => $project_intro,
                        'poster_path' => $posterPath,
                        'other_files_count' => $otherFilesJson ? count(json_decode($otherFilesJson, true)) : 0,
                        'from_status' => 0
                    ];
                    $contentJson['history'] = addHistoryRecord($existingHistory, 'submit', $u_ID, $snapshotData);
                    
                    $conn->commit();
                    
                    // 🔹 【修復】返回最新的檔案清單 JSON，確保前端能正確更新畫面
                    $finalOtherFiles = json_decode($otherFilesJson ?? '[]', true);
                    if (!is_array($finalOtherFiles)) {
                        $finalOtherFiles = [];
                    }
                    
                    json_response([
                        "success" => true,
                        "message" => "已更新資料",
                        "data" => [
                            "prosub_ID" => $prosub_ID,
                            "other_files" => $finalOtherFiles
                        ]
                    ]);
                } else {
                    // 如果已有提交記錄且確認覆蓋，則更新現有記錄
                    if ($existingSubmit && $confirmOverride) {
                        // 保存原始暫存 ID（如果有的話）
                        $oldContentJson = json_decode($existingSubmit['content_json'] ?? '{}', true);
                        if (!isset($contentJson['original_draft_id']) && isset($oldContentJson['original_draft_id'])) {
                            $contentJson['original_draft_id'] = $oldContentJson['original_draft_id'];
                        }
                        
                        // 保存舊記錄的快照到歷史記錄
                        $oldHistory = json_decode($existingSubmit['content_json'] ?? '{}', true)['history'] ?? [];
                        $oldHistory[] = [
                            'action' => 'replaced',
                            'replaced_by' => $u_ID,
                            'replaced_at' => date('Y-m-d H:i:s'),
                            'snapshot' => [
                                'intro' => json_decode($existingSubmit['content_json'] ?? '{}', true)['intro'] ?? '',
                                'image' => $existingSubmit['prosub_img'] ?? ''
                            ]
                        ];
                        $contentJson['history'] = $oldHistory;
                        
                        // 🔹 【部分更新策略】確保使用正確的值（新值或舊值）
                        // 如果沒有新海報，使用原始資料中的海報路徑
                        if (!$posterPath && $isEditMode && $originalData && $originalData['prosub_img']) {
                            $posterPath = $originalData['prosub_img'];
                        }
                        // 如果沒有新多檔且沒有用戶操作，使用原始資料中的多檔
                        if ($otherFilesJson === null && $isEditMode && $originalData && $originalData['prosub_other']) {
                            $otherFilesJson = $originalData['prosub_other'];
                        }
                        
                        // 確保 contentJson 中也包含 intro（兼容性）
                        $contentJson['intro'] = $project_intro;
                        
                        // 【提交時必須確實寫入所有資料到資料庫】
                        // 更新現有提交記錄
                        $updateFields = [
                            'prosub_img = ?',
                            'prosub_other = ?',
                            'content_json = ?',
                            'prosub_status = 1',
                            'prosub_created_d = NOW()',
                            'prosub_update_d = NOW()',
                            'prosub_u_ID = ?'
                        ];
                        $updateValues = [
                            $posterPath,
                            $otherFilesJson,
                            json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                            $u_ID
                        ];
                        
                        // 如果存在 prosub_intro 字段，必須寫入專題簡介
                        if ($hasIntroField) {
                            $updateFields[] = 'prosub_intro = ?';
                            $updateValues[] = $project_intro;
                        }
                        
                        $updateValues[] = $existingSubmit['prosub_ID'];
                        $updateValues[] = $team_ID;
                        
                        $updateSql = "UPDATE prosubdata SET " . implode(', ', $updateFields) . " WHERE prosub_ID = ? AND team_ID = ?";
                        $updateStmt = $conn->prepare($updateSql);
                        $updateStmt->execute($updateValues);
                        
                        $prosub_ID = $existingSubmit['prosub_ID'];
                        
                        // 添加歷史記錄到 content_json（覆蓋提交）
                        $existingHistory = isset($contentJson['history']) ? $contentJson['history'] : [];
                        $contentJson['history'] = addHistoryRecord($existingHistory, 'replaced', $u_ID);
                        
                        $conn->commit();
                        
                        // 🔹 【修復】返回最新的檔案清單 JSON，確保前端能正確更新畫面
                        $finalOtherFiles = json_decode($otherFilesJson ?? '[]', true);
                        if (!is_array($finalOtherFiles)) {
                            $finalOtherFiles = [];
                        }
                        
                        json_response([
                            "success" => true,
                            "message" => "提交成功（已覆蓋原有記錄）",
                            "data" => [
                                "prosub_ID" => $prosub_ID,
                                "other_files" => $finalOtherFiles
                            ]
                        ]);
                    } else {
                    // 如果原本是暫存，先將暫存狀態改為新提交
                    if ($prosub_ID > 0) {
                        // 檢查是否為暫存
                            $checkStmt = $conn->prepare("SELECT prosub_status, content_json, prosub_img FROM prosubdata WHERE prosub_ID = ? AND team_ID = ?");
                        $checkStmt->execute([$prosub_ID, $team_ID]);
                        $existingStatus = $checkStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existingStatus && $existingStatus['prosub_status'] == 4) {
                                // 保存原始暫存 ID
                                $draftContentJson = json_decode($existingStatus['content_json'] ?? '{}', true);
                                $contentJson['original_draft_id'] = $prosub_ID;
                                
                                // 添加歷史記錄（保存暫存的快照）
                                $history = $draftContentJson['history'] ?? [];
                                $history[] = [
                                    'action' => 'submitted',
                                    'from_draft_id' => $prosub_ID,
                                    'submitted_by' => $u_ID,
                                    'submitted_at' => date('Y-m-d H:i:s'),
                                    'snapshot' => [
                                        'intro' => $draftContentJson['intro'] ?? '',
                                        'image' => $existingStatus['prosub_img'] ?? ''
                                    ]
                                ];
                                $contentJson['history'] = $history;
                                
                            // 確保 contentJson 中也包含 intro（兼容性）
                            $contentJson['intro'] = $project_intro;
                                
                            // 將暫存更新為提交
                            // 如果沒有上傳新檔案，從暫存記錄中獲取舊檔案
                            if (!$otherFilesJson) {
                                $oldStmt = $conn->prepare("SELECT prosub_other FROM prosubdata WHERE prosub_ID = ? AND team_ID = ?");
                                $oldStmt->execute([$prosub_ID, $team_ID]);
                                $oldRecord = $oldStmt->fetch(PDO::FETCH_ASSOC);
                                if ($oldRecord && $oldRecord['prosub_other']) {
                                    $otherFilesJson = $oldRecord['prosub_other'];
                                }
                            }
                            
                            //  保留原始檔名（如果暫存記錄中有）
                            if (isset($draftContentJson['poster_original_name'])) {
                                $contentJson['poster_original_name'] = $draftContentJson['poster_original_name'];
                            }
                            
                            // 【提交時必須確實寫入所有資料到資料庫】
                            $updateFields = [
                                'prosub_img = ?',
                                'prosub_other = ?',
                                'content_json = ?',
                                'prosub_status = 1',
                                'prosub_created_d = NOW()',
                                'prosub_update_d = NOW()',
                                'prosub_u_ID = ?'
                            ];
                            $updateValues = [
                                $posterPath,
                                $otherFilesJson,
                                json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                                $u_ID
                            ];
                            
                            // 如果存在 prosub_intro 字段，必須寫入專題簡介
                            if ($hasIntroField) {
                                $updateFields[] = 'prosub_intro = ?';
                                $updateValues[] = $project_intro;
                            }
                            
                            $updateValues[] = $prosub_ID;
                            $updateValues[] = $team_ID;
                            
                            $updateSql = "UPDATE prosubdata SET " . implode(', ', $updateFields) . " WHERE prosub_ID = ? AND team_ID = ?";
                            $updateStmt = $conn->prepare($updateSql);
                            $updateStmt->execute($updateValues);
                            
                            // 添加歷史記錄到 content_json（從暫存提交）
                            $existingHistory = isset($contentJson['history']) ? $contentJson['history'] : [];
                            $snapshotData = [
                                'intro' => $project_intro,
                                'poster_path' => $posterPath,
                                'other_files_count' => $otherFilesJson ? count(json_decode($otherFilesJson, true)) : 0,
                                'from_draft_id' => $prosub_ID
                            ];
                            $contentJson['history'] = addHistoryRecord($existingHistory, 'submit', $u_ID, $snapshotData);
                            
                            $conn->commit();
                            
                            // 🔹 【修復】返回最新的檔案清單 JSON，確保前端能正確更新畫面
                            $finalOtherFiles = json_decode($otherFilesJson ?? '[]', true);
                            if (!is_array($finalOtherFiles)) {
                                $finalOtherFiles = [];
                            }
                            
                            // 【提交成功後，後端請回傳該筆提交記錄的主鍵 ID】
                            json_response([
                                "success" => true,
                                "message" => "提交成功",
                                "data" => [
                                    "prosub_ID" => $prosub_ID,
                                    "other_files" => $finalOtherFiles
                                ]
                            ]);
                        } else {
                            // 編輯已提交的記錄（狀態不是4），直接更新當前記錄
                            if ($prosub_ID > 0 && $originalData && $originalData['prosub_status'] != 4) {
                                // 使用前面 SELECT 的原始資料（$originalData）
                                $oldContentJson = json_decode($originalData['content_json'] ?? '{}', true);
                                $oldHistory = $oldContentJson['history'] ?? [];
                                
                                // 編輯提交視為覆蓋提交（已取代）
                                $contentJson['history'] = addHistoryRecord($oldHistory, 'replaced', $u_ID);
                                
                                // 確保 contentJson 中也包含 intro（兼容性）
                                $contentJson['intro'] = $project_intro;
                                
                                // 【編輯模式防呆】使用前面處理的 $posterPath 和 $otherFilesJson
                                // 這些值已經根據原始資料和用戶操作正確設置了（在前面處理文件時已完成）
                                
                                // 【提交時必須確實寫入所有資料到資料庫】
                                $updateFields = [
                                    'prosub_img = ?',
                                    'prosub_other = ?',
                                    'content_json = ?',
                                    'prosub_status = 1',
                                    'prosub_update_d = NOW()',
                                    'prosub_u_ID = ?'
                                ];
                                $updateValues = [
                                    $posterPath,
                                    $otherFilesJson,
                                    json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                                    $u_ID
                                ];
                                
                                // 如果存在 prosub_intro 字段，必須寫入專題簡介
                                if ($hasIntroField) {
                                    $updateFields[] = 'prosub_intro = ?';
                                    $updateValues[] = $project_intro;
                                }
                                
                                $updateValues[] = $prosub_ID;
                                $updateValues[] = $team_ID;
                                
                                $updateSql = "UPDATE prosubdata SET " . implode(', ', $updateFields) . " WHERE prosub_ID = ? AND team_ID = ?";
                                $updateStmt = $conn->prepare($updateSql);
                                $updateStmt->execute($updateValues);
                                
                                // 重新更新 content_json（包含歷史記錄）
                                $updateStmt2 = $conn->prepare("UPDATE prosubdata SET content_json = ? WHERE prosub_ID = ? AND team_ID = ?");
                                $updateStmt2->execute([json_encode($contentJson, JSON_UNESCAPED_UNICODE), $prosub_ID, $team_ID]);
                                
                                $conn->commit();
                                
                                // 🔹 【修復】返回最新的檔案清單 JSON，確保前端能正確更新畫面
                                $finalOtherFiles = json_decode($otherFilesJson ?? '[]', true);
                                if (!is_array($finalOtherFiles)) {
                                    $finalOtherFiles = [];
                                }
                                
                                json_response([
                                    "success" => true,
                                    "message" => "提交成功",
                                    "data" => [
                                        "prosub_ID" => $prosub_ID,
                                        "other_files" => $finalOtherFiles
                                    ]
                                ]);
                            }
                            
                            // 使用 upsert 邏輯：先查詢是否存在（pro_ID + team_ID 的唯一鍵）
                            $checkStmt = $conn->prepare("SELECT prosub_ID FROM prosubdata WHERE pro_ID = ? AND team_ID = ?");
                            $checkStmt->execute([$pro_ID, $team_ID]);
                            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($existing) {
                                // 已存在記錄，更新為提交狀態（覆蓋提交）
                                $prosub_ID = $existing['prosub_ID'];
                                
                                // 獲取現有歷史記錄
                                $oldStmt = $conn->prepare("SELECT content_json FROM prosubdata WHERE prosub_ID = ?");
                                $oldStmt->execute([$prosub_ID]);
                                $oldRecord = $oldStmt->fetch(PDO::FETCH_ASSOC);
                                $oldContentJson = json_decode($oldRecord['content_json'] ?? '{}', true);
                                $oldHistory = $oldContentJson['history'] ?? [];
                                
                                // 添加歷史記錄：已取代（覆蓋提交）
                                $contentJson['history'] = addHistoryRecord($oldHistory, 'replaced', $u_ID);
                                
                                // 確保 contentJson 中也包含 intro（兼容性）
                                $contentJson['intro'] = $project_intro;
                                
                                // 【提交時必須確實寫入所有資料到資料庫】
                                $updateFields = [
                                    'prosub_img = ?',
                                    'prosub_other = ?',
                                    'content_json = ?',
                                    'prosub_status = 1',
                                    'prosub_created_d = NOW()',
                                    'prosub_update_d = NOW()',
                                    'prosub_u_ID = ?'
                                ];
                                $updateValues = [
                                    $posterPath,
                                    $otherFilesJson,
                                    json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                                    $u_ID
                                ];
                                
                                // 如果存在 prosub_intro 字段，必須寫入專題簡介
                                if ($hasIntroField) {
                                    $updateFields[] = 'prosub_intro = ?';
                                    $updateValues[] = $project_intro;
                                }
                                
                                $updateValues[] = $prosub_ID;
                                $updateValues[] = $team_ID;
                                
                                $updateSql = "UPDATE prosubdata SET " . implode(', ', $updateFields) . " WHERE prosub_ID = ? AND team_ID = ?";
                                $updateStmt = $conn->prepare($updateSql);
                                $updateStmt->execute($updateValues);
                                
                                // 添加歷史記錄到 content_json（首次提交）
                                $existingHistory = isset($contentJson['history']) ? $contentJson['history'] : [];
                                // 判斷是第一次提交還是覆蓋提交
                                $isFirstSubmit = empty($existingHistory);
                                $actionType = $isFirstSubmit ? 'submitted' : 'replaced';
                                $contentJson['history'] = addHistoryRecord($existingHistory, $actionType, $u_ID);
                                
                                // 重新更新 content_json（包含歷史記錄）
                                $updateStmt2 = $conn->prepare("UPDATE prosubdata SET content_json = ? WHERE prosub_ID = ? AND team_ID = ?");
                                $updateStmt2->execute([json_encode($contentJson, JSON_UNESCAPED_UNICODE), $prosub_ID, $team_ID]);
                            } else {
                                // 🔹 【關鍵修復】允許直接提交，不需要先暫存
                                // 先查詢是否存在記錄（pro_ID + team_ID 的唯一鍵）
                                $checkStmt = $conn->prepare("SELECT prosub_ID FROM prosubdata WHERE pro_ID = ? AND team_ID = ?");
                                $checkStmt->execute([$pro_ID, $team_ID]);
                                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                                
                                if (!$existing) {
                                    // 🔹 【關鍵修復】不存在記錄，直接創建新記錄（允許直接提交）
                                    // 確保 contentJson 中也包含 intro（兼容性）
                                    $contentJson['intro'] = $project_intro;
                                    
                                    // 構建 INSERT 語句（如果有 intro 字段，則插入字段；否則存到 JSON）
                                    $insertFields = ['pro_ID', 'team_ID', 'prosub_img', 'prosub_other', 'content_json', 'prosub_status', 'prosub_u_ID', 'prosub_created_d', 'prosub_update_d', 'prosub_file_type'];
                                    $insertPlaceholders = ['?', '?', '?', '?', '?', '1', '?', 'NOW()', 'NOW()', '?']; // 狀態為 1（已提交）
                                    $insertValues = [$pro_ID, $team_ID, $posterPath, $otherFilesJson, json_encode($contentJson, JSON_UNESCAPED_UNICODE), $u_ID, $file_type];
                                    
                                    if ($hasIntroField) {
                                        $insertFields[] = 'prosub_intro';
                                        $insertPlaceholders[] = '?';
                                        $insertValues[] = $project_intro;
                                    }
                                    
                                    $insertSql = "INSERT INTO prosubdata (" . implode(', ', $insertFields) . ")
                                        VALUES (" . implode(', ', $insertPlaceholders) . ")";
                                    
                                    $insertStmt = $conn->prepare($insertSql);
                                    $insertStmt->execute($insertValues);
                                    
                                    $prosub_ID = $conn->lastInsertId();
                                    
                                    // 添加歷史記錄到 content_json（首次提交）
                                    $contentJson['history'] = addHistoryRecord([], 'submitted', $u_ID);
                                    
                                    // 重新更新 content_json（包含歷史記錄）
                                    $updateStmt2 = $conn->prepare("UPDATE prosubdata SET content_json = ? WHERE prosub_ID = ? AND team_ID = ?");
                                    $updateStmt2->execute([json_encode($contentJson, JSON_UNESCAPED_UNICODE), $prosub_ID, $team_ID]);
                                    
                                    $conn->commit();
                                    
                                    json_response([
                                        "success" => true,
                                        "message" => "提交成功",
                                        "data" => [
                                            "prosub_ID" => $prosub_ID
                                        ]
                                    ]);
                                    return;
                                }
                                
                                // 已存在記錄，更新為提交狀態
                                $prosub_ID = $existing['prosub_ID'];
                                
                                // 獲取現有歷史記錄
                                $oldStmt = $conn->prepare("SELECT content_json FROM prosubdata WHERE prosub_ID = ?");
                                $oldStmt->execute([$prosub_ID]);
                                $oldRecord = $oldStmt->fetch(PDO::FETCH_ASSOC);
                                $oldContentJson = json_decode($oldRecord['content_json'] ?? '{}', true);
                                $oldHistory = $oldContentJson['history'] ?? [];
                                
                                // 添加新的提交歷史記錄（覆蓋提交）
                                $contentJson['history'] = addHistoryRecord($oldHistory, 'replaced', $u_ID);
                                
                                // 確保 contentJson 中也包含 intro（兼容性）
                                $contentJson['intro'] = $project_intro;
                                
                                // 【提交時必須確實寫入所有資料到資料庫】
                                $updateFields = [
                                    'prosub_img = ?',
                                    'prosub_other = ?',
                                    'content_json = ?',
                                    'prosub_status = 1',
                                    'prosub_created_d = NOW()',
                                    'prosub_update_d = NOW()',
                                    'prosub_u_ID = ?',
                                    'prosub_file_type = ?'
                                ];
                                $updateValues = [
                                    $posterPath,
                                    $otherFilesJson,
                                    json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                                    $u_ID,
                                    $file_type
                                ];
                                
                                // 如果存在 prosub_intro 字段，必須寫入專題簡介
                                if ($hasIntroField) {
                                    $updateFields[] = 'prosub_intro = ?';
                                    $updateValues[] = $project_intro;
                                }
                                
                                $updateValues[] = $prosub_ID;
                                $updateValues[] = $team_ID;
                                
                                $updateSql = "UPDATE prosubdata SET " . implode(', ', $updateFields) . " WHERE prosub_ID = ? AND team_ID = ?";
                                $updateStmt = $conn->prepare($updateSql);
                                $updateStmt->execute($updateValues);
                            }
                            
                            $conn->commit();
                            
                            // 🔹 【修復】返回最新的檔案清單 JSON，確保前端能正確更新畫面
                            $finalOtherFiles = json_decode($otherFilesJson ?? '[]', true);
                            if (!is_array($finalOtherFiles)) {
                                $finalOtherFiles = [];
                            }
                            
                            // 【提交成功後，後端請回傳該筆提交記錄的主鍵 ID】
                            json_response([
                                "success" => true,
                                "message" => "提交成功",
                                "data" => [
                                    "prosub_ID" => $prosub_ID,
                                    "other_files" => $finalOtherFiles
                                ]
                            ]);
                        }
                        } else {
                            // 🔹 【關鍵修復】允許直接提交，不需要先暫存
                            // 相容舊欄位 prosub_file_type：取第一個檔案的 file_type（每檔類型已存於 prosub_other JSON）
                            $file_type = '';
                            if (!empty($otherFilesJson)) {
                                $dec = json_decode($otherFilesJson, true);
                                if (is_array($dec) && isset($dec[0]['file_type'])) {
                                    $file_type = (string)$dec[0]['file_type'];
                                }
                            }
                            // 先查詢是否存在記錄（pro_ID + team_ID 的唯一鍵）
                            $checkStmt = $conn->prepare("SELECT prosub_ID FROM prosubdata WHERE pro_ID = ? AND team_ID = ?");
                            $checkStmt->execute([$pro_ID, $team_ID]);
                            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                            
                            if (!$existing) {
                                // 🔹 【關鍵修復】不存在記錄，直接創建新記錄（允許直接提交）
                                // 確保 contentJson 中也包含 intro（兼容性）
                                $contentJson['intro'] = $project_intro;
                                
                                // 構建 INSERT 語句（如果有 intro 字段，則插入字段；否則存到 JSON）
                                $insertFields = ['pro_ID', 'team_ID', 'prosub_img', 'prosub_other', 'content_json', 'prosub_status', 'prosub_u_ID', 'prosub_created_d', 'prosub_update_d', 'prosub_file_type'];
                                $insertPlaceholders = ['?', '?', '?', '?', '?', '1', '?', 'NOW()', 'NOW()', '?']; // 狀態為 1（已提交）
                                $insertValues = [$pro_ID, $team_ID, $posterPath, $otherFilesJson, json_encode($contentJson, JSON_UNESCAPED_UNICODE), $u_ID, $file_type];
                                
                                if ($hasIntroField) {
                                    $insertFields[] = 'prosub_intro';
                                    $insertPlaceholders[] = '?';
                                    $insertValues[] = $project_intro;
                                }
                                
                                $insertSql = "INSERT INTO prosubdata (" . implode(', ', $insertFields) . ")
                                    VALUES (" . implode(', ', $insertPlaceholders) . ")";
                                
                                $insertStmt = $conn->prepare($insertSql);
                                $insertStmt->execute($insertValues);
                                
                                $prosub_ID = $conn->lastInsertId();
                                
                                // 添加歷史記錄到 content_json（首次提交）
                                $contentJson['history'] = addHistoryRecord([], 'submitted', $u_ID);
                                
                                // 重新更新 content_json（包含歷史記錄）
                                $updateStmt2 = $conn->prepare("UPDATE prosubdata SET content_json = ? WHERE prosub_ID = ? AND team_ID = ?");
                                $updateStmt2->execute([json_encode($contentJson, JSON_UNESCAPED_UNICODE), $prosub_ID, $team_ID]);
                                
                                $conn->commit();
                                
                                // 🔹 【修復】返回最新的檔案清單 JSON，確保前端能正確更新畫面
                                $finalOtherFiles = json_decode($otherFilesJson ?? '[]', true);
                                if (!is_array($finalOtherFiles)) {
                                    $finalOtherFiles = [];
                                }
                                
                                json_response([
                                    "success" => true,
                                    "message" => "提交成功",
                                    "data" => [
                                        "prosub_ID" => $prosub_ID,
                                        "other_files" => $finalOtherFiles
                                    ]
                                ]);
                                return;
                            }
                            
                            // 已存在記錄，更新為提交狀態
                            $prosub_ID = $existing['prosub_ID'];
                            
                            // 獲取現有歷史記錄
                            $oldStmt = $conn->prepare("SELECT content_json FROM prosubdata WHERE prosub_ID = ?");
                            $oldStmt->execute([$prosub_ID]);
                            $oldRecord = $oldStmt->fetch(PDO::FETCH_ASSOC);
                            $oldContentJson = json_decode($oldRecord['content_json'] ?? '{}', true);
                            $oldHistory = $oldContentJson['history'] ?? [];
                            
                            // 添加新的提交歷史記錄
                            $oldHistory[] = [
                                'action' => 'submitted',
                                'submitted_by' => $u_ID,
                                'submitted_at' => date('Y-m-d H:i:s'),
                                'snapshot' => [
                                    'intro' => $contentJson['intro'] ?? '',
                                    'image' => $posterPath
                                ]
                            ];
                            $contentJson['history'] = $oldHistory;
                            
                            // 確保 contentJson 中也包含 intro（兼容性）
                            $contentJson['intro'] = $project_intro;
                            
                            // 【提交時必須確實寫入所有資料到資料庫】
                            $updateFields = [
                                'prosub_img = ?',
                                'prosub_other = ?',
                                'content_json = ?',
                                'prosub_status = 1',
                                'prosub_created_d = NOW()',
                                'prosub_update_d = NOW()',
                                'prosub_u_ID = ?'
                            ];
                            $updateValues = [
                                $posterPath,
                                $otherFilesJson,
                                json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                                $u_ID
                            ];
                            
                            // 如果存在 prosub_intro 字段，必須寫入專題簡介
                            if ($hasIntroField) {
                                $updateFields[] = 'prosub_intro = ?';
                                $updateValues[] = $project_intro;
                            }
                            
                            $updateValues[] = $prosub_ID;
                            $updateValues[] = $team_ID;
                            
                            $updateSql = "UPDATE prosubdata SET " . implode(', ', $updateFields) . " WHERE prosub_ID = ? AND team_ID = ?";
                            $updateStmt = $conn->prepare($updateSql);
                            $updateStmt->execute($updateValues);
                            
                            $conn->commit();
                            
                            json_response([
                                "success" => true,
                                "message" => "提交成功",
                                "data" => [
                                    "prosub_ID" => $prosub_ID
                                ]
                            ]);
                        }
                    }
                }
            } catch (Exception $e) {
                $conn->rollBack();
                json_response([
                    "success" => false,
                    "message" => "提交失敗：" . $e->getMessage()
                ]);
            }
            break;

        case 'delete':
            // ====== 刪除功能（軟刪除） ======
            $prosub_ID = isset($_GET['prosub_ID']) ? (int)$_GET['prosub_ID'] : 0;
            
            if ($prosub_ID <= 0) {
                json_response([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
            }
            
            // 檢查是否被鎖定（同時獲取圖片路徑以便保存快照）
            $stmt = $conn->prepare("
                SELECT content_json, prosub_img FROM prosubdata 
                WHERE prosub_ID = ? AND team_ID = ?
            ");
            $stmt->execute([$prosub_ID, $team_ID]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                json_response([
                    "success" => false,
                    "message" => "記錄不存在或無權限"
                ]);
            }
            
            $contentJson = json_decode($record['content_json'] ?? '{}', true);
            
            // 檢查是否被鎖定
            if (isset($contentJson['is_locked']) && $contentJson['is_locked'] === true) {
                json_response([
                    "success" => false,
                    "message" => "此提交已被科辦鎖定，無法刪除"
                ]);
            }
            
            // 添加歷史記錄：已刪除
            $history = $contentJson['history'] ?? [];
            $contentJson['history'] = addHistoryRecord($history, 'deleted', $u_ID);
            
            // 軟刪除：在 content_json 中設置 is_deleted = true
            $contentJson['is_deleted'] = true;
            
            $updateStmt = $conn->prepare("
                UPDATE prosubdata 
                SET content_json = ?, prosub_update_d = NOW()
                WHERE prosub_ID = ? AND team_ID = ?
            ");
            $updateStmt->execute([
                json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                $prosub_ID,
                $team_ID
            ]);
            
            json_response([
                "success" => true,
                "message" => "刪除成功"
            ]);

        case 'get_history':
            // ====== 獲取歷史記錄（包含所有組員的操作） ======
            $prosub_ID = isset($_GET['prosub_ID']) ? (int)$_GET['prosub_ID'] : 0;
            
            if ($prosub_ID <= 0) {
                json_response([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
            }
            
            // 檢查記錄是否屬於該團隊
            $stmt = $conn->prepare("
                SELECT content_json FROM prosubdata 
                WHERE prosub_ID = ? AND team_ID = ?
            ");
            $stmt->execute([$prosub_ID, $team_ID]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                json_response([
                    "success" => false,
                    "message" => "記錄不存在或無權限"
                ]);
            }
            
            // 從 content_json 獲取歷史記錄
            $contentJson = json_decode($record['content_json'] ?? '{}', true);
            $history = $contentJson['history'] ?? [];
            
            // 獲取所有操作者的用戶名稱
            $userIDs = [];
            foreach ($history as $item) {
                // 新格式：使用 operator_id
                if (isset($item['operator_id']) && (int)$item['operator_id'] > 0) {
                    $userIDs[] = (int)$item['operator_id'];
                }
                // 舊格式：兼容舊資料
                if (isset($item['submitted_by']) && (int)$item['submitted_by'] > 0) {
                    $userIDs[] = (int)$item['submitted_by'];
                }
                if (isset($item['replaced_by']) && (int)$item['replaced_by'] > 0) {
                    $userIDs[] = (int)$item['replaced_by'];
                }
                if (isset($item['deleted_by']) && (int)$item['deleted_by'] > 0) {
                    $userIDs[] = (int)$item['deleted_by'];
                }
                if (isset($item['reset_by']) && (int)$item['reset_by'] > 0) {
                    $userIDs[] = (int)$item['reset_by'];
                }
                if (isset($item['restored_by']) && (int)$item['restored_by'] > 0) {
                    $userIDs[] = (int)$item['restored_by'];
                }
            }
            
            // 去重並獲取用戶名稱
            $userIDs = array_unique($userIDs);
            $userIDs = array_values($userIDs);
            $userNames = [];
            if (!empty($userIDs) && count($userIDs) > 0) {
                $placeholders = implode(',', array_fill(0, count($userIDs), '?'));
                $userStmt = $conn->prepare("
                    SELECT u_ID, u_name 
                    FROM userdata 
                    WHERE u_ID IN ($placeholders)
                ");
                $userStmt->execute($userIDs);
                $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($users as $user) {
                    $userNames[(int)$user['u_ID']] = $user['u_name'];
                }
            }
            
            // 為每個歷史記錄項添加用戶名稱
            foreach ($history as &$item) {
                // 新格式
                if (isset($item['operator_id'])) {
                    $item['operator_name'] = $userNames[(int)$item['operator_id']] ?? '未知用戶';
                }
                // 舊格式：兼容舊資料
                if (isset($item['submitted_by'])) {
                    $item['submitted_by_name'] = $userNames[(int)$item['submitted_by']] ?? '未知用戶';
                }
                if (isset($item['replaced_by'])) {
                    $item['replaced_by_name'] = $userNames[(int)$item['replaced_by']] ?? '未知用戶';
                }
                if (isset($item['deleted_by'])) {
                    $item['deleted_by_name'] = $userNames[(int)$item['deleted_by']] ?? '未知用戶';
                }
                if (isset($item['reset_by'])) {
                    $item['reset_by_name'] = $userNames[(int)$item['reset_by']] ?? '未知用戶';
                }
                if (isset($item['restored_by'])) {
                    $item['restored_by_name'] = $userNames[(int)$item['restored_by']] ?? '未知用戶';
                }
            }
            unset($item);
            
            json_response([
                "success" => true,
                "history" => $history,
                "original_draft_id" => $contentJson['original_draft_id'] ?? null
            ]);

        case 'reset_to_draft':
            // ====== 重置回原本的暫存 ======
            $prosub_ID = isset($_POST['prosub_ID']) ? (int)$_POST['prosub_ID'] : 0;
            
            if ($prosub_ID <= 0) {
                json_response([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
            }
            
            // 檢查記錄是否屬於該團隊
            $stmt = $conn->prepare("
                SELECT content_json, prosub_status FROM prosubdata 
                WHERE prosub_ID = ? AND team_ID = ?
            ");
            $stmt->execute([$prosub_ID, $team_ID]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                json_response([
                    "success" => false,
                    "message" => "記錄不存在或無權限"
                ]);
            }
            
            // 檢查是否被鎖定
            $contentJson = json_decode($record['content_json'] ?? '{}', true);
            if (isset($contentJson['is_locked']) && $contentJson['is_locked'] === true) {
                json_response([
                    "success" => false,
                    "message" => "此提交已被科辦鎖定，無法重置"
                ]);
            }
            
            $originalDraftId = $contentJson['original_draft_id'] ?? null;
            
            if (!$originalDraftId) {
                json_response([
                    "success" => false,
                    "message" => "找不到原始暫存記錄"
                ]);
            }
            
            // 獲取原始暫存記錄
            $draftStmt = $conn->prepare("
                SELECT prosub_img, content_json FROM prosubdata 
                WHERE prosub_ID = ? AND team_ID = ? AND prosub_status = 4
            ");
            $draftStmt->execute([$originalDraftId, $team_ID]);
            $draftRecord = $draftStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$draftRecord) {
                json_response([
                    "success" => false,
                    "message" => "原始暫存記錄不存在"
                ]);
            }
            
            $draftContentJson = json_decode($draftRecord['content_json'] ?? '{}', true);
            
            // 添加歷史記錄
            $history = $contentJson['history'] ?? [];
            $history[] = [
                'action' => 'reset_to_draft',
                'reset_by' => $u_ID,
                'reset_at' => date('Y-m-d H:i:s'),
                'reset_from_id' => $prosub_ID,
                'reset_to_id' => $originalDraftId
            ];
            $draftContentJson['history'] = $history;
            
            // 更新提交記錄為暫存狀態，並恢復原始暫存內容
            $updateStmt = $conn->prepare("
                UPDATE prosubdata 
                SET prosub_img = ?,
                    content_json = ?,
                    prosub_status = 4,
                    prosub_update_d = NOW(),
                    prosub_u_ID = ?
                WHERE prosub_ID = ? AND team_ID = ?
            ");
            $updateStmt->execute([
                $draftRecord['prosub_img'],
                json_encode($draftContentJson, JSON_UNESCAPED_UNICODE),
                $u_ID,
                $prosub_ID,
                $team_ID
            ]);
            
            json_response([
                "success" => true,
                "message" => "已重置回原始暫存",
                "prosub_ID" => $prosub_ID
            ]);

        case 'reset_to_history':
            // ====== 恢復到歷史版本 ======
            $prosub_ID = isset($_POST['prosub_ID']) ? (int)$_POST['prosub_ID'] : 0;
            $history_index = isset($_POST['history_index']) ? (int)$_POST['history_index'] : -1;
            
            if ($prosub_ID <= 0 || $history_index < 0) {
                json_response([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
            }
            
            // 檢查記錄是否屬於該團隊（同時獲取 original_draft_id 以便查找原始暫存）
            $stmt = $conn->prepare("
                SELECT content_json, prosub_status, prosub_img FROM prosubdata 
                WHERE prosub_ID = ? AND team_ID = ?
            ");
            $stmt->execute([$prosub_ID, $team_ID]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                json_response([
                    "success" => false,
                    "message" => "記錄不存在或無權限"
                ]);
            }
            
            // 檢查是否被鎖定
            $contentJson = json_decode($record['content_json'] ?? '{}', true);
            if (isset($contentJson['is_locked']) && $contentJson['is_locked'] === true) {
                json_response([
                    "success" => false,
                    "message" => "此提交已被科辦鎖定，無法恢復"
                ]);
            }
            
            $history = $contentJson['history'] ?? [];
            $originalDraftId = $contentJson['original_draft_id'] ?? null;
            
            if (empty($history) || $history_index >= count($history)) {
                json_response([
                    "success" => false,
                    "message" => "歷史記錄索引無效"
                ]);
            }
            
            // 獲取要恢復的歷史記錄
            // 注意：前端傳遞的 history_index 是在倒序排列中的索引
            // 後端需要再次反轉以匹配前端的索引邏輯
            $reversedHistory = array_reverse($history);
            
            // 檢查索引是否有效
            if ($history_index < 0 || $history_index >= count($reversedHistory)) {
                json_response([
                    "success" => false,
                    "message" => "歷史記錄索引無效：索引 {$history_index}，總數 " . count($reversedHistory)
                ]);
            }
            
            $targetHistoryItem = $reversedHistory[$history_index];
            
            // 檢查歷史記錄中是否有保存的資料快照
            // 優先使用 snapshot，如果沒有則嘗試從其他欄位獲取，最後使用當前記錄的資料
            $snapshot = [];
            
            if (isset($targetHistoryItem['snapshot']) && is_array($targetHistoryItem['snapshot'])) {
                // 有快照，直接使用
                $snapshot = $targetHistoryItem['snapshot'];
            } else {
                // 沒有快照，嘗試從歷史記錄的其他欄位中獲取資料
                // 根據 action 類型，嘗試不同的欄位
                $action = $targetHistoryItem['action'] ?? '';
                
                // 嘗試獲取簡介
                if (isset($targetHistoryItem['old_intro']) && !empty(trim($targetHistoryItem['old_intro']))) {
                    $snapshot['intro'] = $targetHistoryItem['old_intro'];
                } elseif (isset($targetHistoryItem['intro']) && !empty(trim($targetHistoryItem['intro']))) {
                    $snapshot['intro'] = $targetHistoryItem['intro'];
                } elseif ($action === 'replaced' && isset($targetHistoryItem['old_intro'])) {
                    // replaced 操作應該有 old_intro
                    $snapshot['intro'] = $targetHistoryItem['old_intro'];
                } else {
                    // 如果歷史記錄中沒有，使用當前記錄的簡介（作為默認值）
                    $snapshot['intro'] = $contentJson['intro'] ?? '';
                }
                
                // 嘗試獲取圖片
                if (isset($targetHistoryItem['old_img']) && !empty(trim($targetHistoryItem['old_img']))) {
                    $snapshot['image'] = $targetHistoryItem['old_img'];
                } elseif (isset($targetHistoryItem['image']) && !empty(trim($targetHistoryItem['image']))) {
                    $snapshot['image'] = $targetHistoryItem['image'];
                } elseif ($action === 'replaced' && isset($targetHistoryItem['old_img'])) {
                    // replaced 操作應該有 old_img
                    $snapshot['image'] = $targetHistoryItem['old_img'];
                } else {
                    // 如果歷史記錄中沒有，使用當前記錄的圖片（作為默認值）
                    $snapshot['image'] = $record['prosub_img'] ?? '';
                }
                
                // 如果還是沒有資料，嘗試從原始暫存記錄獲取
                if (empty($snapshot['intro']) && empty($snapshot['image']) && $originalDraftId) {
                    try {
                        $draftStmt = $conn->prepare("
                            SELECT content_json, prosub_img FROM prosubdata 
                            WHERE prosub_ID = ? AND team_ID = ?
                        ");
                        $draftStmt->execute([$originalDraftId, $team_ID]);
                        $draftRecord = $draftStmt->fetch(PDO::FETCH_ASSOC);
                        if ($draftRecord) {
                            $draftContentJson = json_decode($draftRecord['content_json'] ?? '{}', true);
                            if (empty($snapshot['intro']) && isset($draftContentJson['intro'])) {
                                $snapshot['intro'] = $draftContentJson['intro'];
                            }
                            if (empty($snapshot['image']) && !empty($draftRecord['prosub_img'])) {
                                $snapshot['image'] = $draftRecord['prosub_img'];
                            }
                        }
                    } catch (Exception $e) {
                        // 如果查詢失敗，繼續使用當前記錄的資料
                    }
                }
                
                // 如果還是沒有，嘗試從更早的歷史記錄中獲取
                if (empty($snapshot['intro']) && empty($snapshot['image']) && $history_index > 0) {
                    // 嘗試從前一個歷史記錄獲取
                    for ($i = $history_index - 1; $i >= 0; $i--) {
                        $prevItem = $reversedHistory[$i];
                        if (isset($prevItem['snapshot']) && is_array($prevItem['snapshot'])) {
                            if (empty($snapshot['intro']) && !empty($prevItem['snapshot']['intro'])) {
                                $snapshot['intro'] = $prevItem['snapshot']['intro'];
                            }
                            if (empty($snapshot['image']) && !empty($prevItem['snapshot']['image'])) {
                                $snapshot['image'] = $prevItem['snapshot']['image'];
                            }
                        }
                        // 如果已經獲取到資料，就停止
                        if (!empty($snapshot['intro']) || !empty($snapshot['image'])) {
                            break;
                        }
                    }
                }
                
                // 最後手段：使用當前記錄的資料（至少可以恢復到當前狀態）
                if (empty($snapshot['intro'])) {
                    $snapshot['intro'] = $contentJson['intro'] ?? '';
                }
                if (empty($snapshot['image'])) {
                    $snapshot['image'] = $record['prosub_img'] ?? '';
                }
                
                // 即使完全沒有資料，也允許恢復（恢復到空狀態）
                // 這樣至少不會報錯，用戶可以手動填寫
            }
            
            // 確保 snapshot 有必要的欄位（設置默認值）
            if (!isset($snapshot['intro'])) {
                $snapshot['intro'] = $contentJson['intro'] ?? '';
            }
            if (!isset($snapshot['image'])) {
                $snapshot['image'] = $record['prosub_img'] ?? '';
            }
            
            // 恢復資料
            $restoredContentJson = $contentJson;
            // 使用快照中的資料恢復簡介和圖片
            if (isset($snapshot['intro'])) {
                $restoredContentJson['intro'] = $snapshot['intro'];
            }
            // 注意：圖片路徑會在更新時使用 $snapshot['image']
            $restoredContentJson['restored_from_history'] = true;
            $restoredContentJson['restored_at'] = date('Y-m-d H:i:s');
            $restoredContentJson['restored_by'] = $u_ID;
            
            // 添加恢復歷史記錄
            $history[] = [
                'action' => 'restored_from_history',
                'restored_by' => $u_ID,
                'restored_at' => date('Y-m-d H:i:s'),
                'restored_from_index' => $history_index,
                'restored_from_action' => $targetHistoryItem['action']
            ];
            $restoredContentJson['history'] = $history;
            
            // 更新記錄
            $updateStmt = $conn->prepare("
                UPDATE prosubdata 
                SET content_json = ?,
                    prosub_img = ?,
                    prosub_update_d = NOW(),
                    prosub_u_ID = ?
                WHERE prosub_ID = ? AND team_ID = ?
            ");
            // 使用快照中的圖片路徑，如果沒有則使用當前記錄的圖片
            $restoredImage = isset($snapshot['image']) && !empty($snapshot['image']) 
                ? $snapshot['image'] 
                : ($record['prosub_img'] ?? '');
            
            $updateStmt->execute([
                json_encode($restoredContentJson, JSON_UNESCAPED_UNICODE),
                $restoredImage,
                $u_ID,
                $prosub_ID,
                $team_ID
            ]);
            
            json_response([
                "success" => true,
                "message" => "已恢復到此版本的資料內容",
                "prosub_ID" => $prosub_ID
            ]);

        case 'get_detail':
            // ====== 獲取提交記錄詳細資訊 ======
            // 【每次點擊檢視，都依該筆提交記錄 ID 從資料庫查資料並顯示】
            $prosub_ID = isset($_GET['prosub_ID']) ? (int)$_GET['prosub_ID'] : 0;
            
            if ($prosub_ID <= 0) {
                json_response([
                    "success" => false,
                    "message" => "參數錯誤：缺少提交記錄 ID"
                ]);
            }
            
            // 【後端 SQL 查詢邏輯：WHERE 提交記錄主鍵 = 傳入 id，JOIN team 表取得組別名，JOIN users 表取得上傳人】
            // 檢查是否有 intro 字段
            $hasIntroField = false;
            try {
                $checkStmt = $conn->prepare("SHOW COLUMNS FROM prosubdata LIKE 'prosub_intro'");
                $checkStmt->execute();
                $hasIntroField = $checkStmt->rowCount() > 0;
            } catch (Exception $e) {
                // 忽略錯誤
            }
            
            // 構建 SELECT 語句
            $selectFields = [
                'ps.prosub_img',
                'ps.prosub_other',
                'ps.content_json',
                'ps.prosub_created_d',
                'ps.prosub_update_d',
                'ps.prosub_status',
                'ps.prosub_u_ID',
                'ps.team_ID',
                't.team_project_name as team_name'
            ];
            
            if ($hasIntroField) {
                $selectFields[] = 'ps.prosub_intro';
            }
            
            // 【提交紀錄的唯一資料來源：只能來自 prosubdata 資料表，且必須以 prosub_ID 作為唯一查詢依據】
            // 【禁止：用 team_ID 查、用 pro_ID 查、用暫存資料或前端 state 填 modal】
            // SELECT * FROM prosubdata WHERE prosub_ID = :prosub_ID
            // JOIN teamdata 取組別名
            // JOIN userdata 取上傳人
            $sql = "SELECT " . implode(', ', $selectFields) . "
                FROM prosubdata ps
                INNER JOIN teamdata t ON ps.team_ID = t.team_ID
                WHERE ps.prosub_ID = ?";
            
            // 【只使用 prosub_ID 查詢，不依賴 team_ID】
            $stmt = $conn->prepare($sql);
            $stmt->execute([$prosub_ID]);
            
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                json_response([
                    "success" => false,
                    "message" => "查無此提交記錄"
                ]);
            }
            
            // 【資料來源必須來自資料庫（不可寫死）】
            // 解析 content_json 獲取專題簡介
            $contentJson = json_decode($record['content_json'] ?? '{}', true);
            
            // 優先從資料庫字段讀取 intro（如果字段存在）
            $intro = '';
            if ($hasIntroField && isset($record['prosub_intro']) && $record['prosub_intro'] !== null && $record['prosub_intro'] !== '') {
                $intro = trim($record['prosub_intro']);
            } else {
                // 從 content_json 解析出專題簡介（original_draft 或最後一次 submit）
                // 優先使用 content_json 中的 intro
                $intro = trim($contentJson['intro'] ?? '');
            }
            
            // 【組別名必須來自資料庫（JOIN team 表）】
            $teamName = $record['team_name'] ?? '';
            if (empty($teamName)) {
                // 如果 JOIN 失敗，嘗試直接查詢 teamdata
                $teamStmt = $conn->prepare("SELECT team_project_name FROM teamdata WHERE team_ID = ?");
                $teamStmt->execute([$record['team_ID']]);
                $teamRecord = $teamStmt->fetch(PDO::FETCH_ASSOC);
                $teamName = $teamRecord['team_project_name'] ?? '';
            }
            
            // 【上傳時間必須來自資料庫】
            $createdTime = $record['prosub_created_d'] ?? '';
            $updatedTime = $record['prosub_update_d'] ?? '';
            
            // 🔹 【關鍵修復】單一檔案路徑（不檢查檔案是否存在，直接返回資料庫中的路徑）
            // 原因：檔案可能暫時無法訪問，但資料庫記錄應該保留，F5後應該顯示
            $imagePath = $record['prosub_img'] ? $record['prosub_img'] : '';
            
            // 【多檔案欄位請 json_decode 後再處理，顯示為檔案清單，不可顯示 JSON】
            // 🔹 後端先 json_decode prosub_other，只返回檔名和路徑，不包含 JSON key
            $otherFiles = [];
            if (!empty($record['prosub_other'])) {
                $otherFilesJson = json_decode($record['prosub_other'], true);
                if (is_array($otherFilesJson)) {
                    // 處理新格式（包含 path, name, original_name 等）和舊格式（字符串數組）
                    foreach ($otherFilesJson as $file) {
                        $filePath = '';
                        $fileName = '';
                        
                        if (is_string($file)) {
                            // 舊格式：字符串路徑
                            $filePath = $file;
                            $fileName = basename($file);
                        } elseif (is_array($file) && isset($file['path'])) {
                            // 新格式：只提取檔名和路徑，不包含其他 JSON key（如 allow_download, public 等）
                            $filePath = $file['path'];
                            $fileName = $file['name'] ?? $file['original_name'] ?? basename($filePath);
                        }
                        
                        // 🔹 【關鍵修復】不檢查檔案是否存在，直接返回（讓前端處理檔案不存在的情況）
                        if ($filePath) {
                            // 🔹 只返回檔名和路徑，不包含其他 JSON key
                            $otherFiles[] = [
                                'path' => $filePath,
                                'name' => $fileName
                            ];
                        }
                    }
                } elseif (is_string($record['prosub_other'])) {
                    // 兼容舊格式（可能是逗號分隔的字符串）
                    $trimmedOther = trim($record['prosub_other']);
                    if (substr($trimmedOther, 0, 1) === '{' || substr($trimmedOther, 0, 1) === '[') {
                        // 這是JSON格式但解析失敗，嘗試再次解析
                        $retryJson = json_decode($trimmedOther, true);
                        if (is_array($retryJson)) {
                            foreach ($retryJson as $file) {
                                $filePath = '';
                                $fileName = '';
                                
                                if (is_string($file)) {
                                    $filePath = $file;
                                    $fileName = basename($file);
                                } elseif (is_array($file) && isset($file['path'])) {
                                    $filePath = $file['path'];
                                    $fileName = $file['name'] ?? $file['original_name'] ?? basename($filePath);
                                }
                                
                                // 🔹 【關鍵修復】不檢查檔案是否存在，直接返回
                                if ($filePath) {
                                    $otherFiles[] = [
                                        'path' => $filePath,
                                        'name' => $fileName
                                    ];
                                }
                            }
                        }
                    } else {
                        // 逗號分隔的字符串格式
                        $filePaths = array_filter(array_map('trim', explode(',', $record['prosub_other'])));
                        foreach ($filePaths as $filePath) {
                            // 🔹 【關鍵修復】不檢查檔案是否存在，直接返回
                            $otherFiles[] = [
                                'path' => $filePath,
                                'name' => basename($filePath)
                            ];
                        }
                    }
                }
            }
            
            // 獲取上傳人和更新人姓名（優化：一次查詢所有需要的用戶）
            $userIDs = [];
            if ($record['prosub_u_ID']) {
                $userIDs[] = $record['prosub_u_ID'];
            }
            
            // 檢查是否有更新（更新時間與創建時間不同，且不是同一個人）
            $hasUpdate = false;
            $updaterID = null;
            if ($record['prosub_update_d'] && $record['prosub_update_d'] != $record['prosub_created_d']) {
                // 從歷史記錄中獲取最後一次更新的用戶
                $history = $contentJson['history'] ?? [];
                $lastUpdate = null;
                foreach (array_reverse($history) as $item) {
                    if (isset($item['replaced_by']) || isset($item['submitted_by'])) {
                        $lastUpdate = $item;
                        break;
                    }
                }
                
                if ($lastUpdate) {
                    $updaterID = $lastUpdate['replaced_by'] ?? $lastUpdate['submitted_by'] ?? null;
                    if ($updaterID && $updaterID != $record['prosub_u_ID']) {
                        $hasUpdate = true;
                        if (!in_array($updaterID, $userIDs)) {
                            $userIDs[] = $updaterID;
                        }
                    }
                }
            }
            
            // 一次查詢所有用戶姓名
            $userNames = [];
            if (!empty($userIDs)) {
                $placeholders = str_repeat('?,', count($userIDs) - 1) . '?';
                $userStmt = $conn->prepare("SELECT u_ID, u_name FROM userdata WHERE u_ID IN ($placeholders)");
                $userStmt->execute($userIDs);
                $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($users as $user) {
                    $userNames[$user['u_ID']] = $user['u_name'];
                }
            }
            
            // 【JOIN users 表取得上傳人】
            // prosub_u_ID → JOIN users 取上傳人
            $uploaderName = '';
            if ($record['prosub_u_ID']) {
                // 查詢上傳人姓名
                $userStmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                $userStmt->execute([$record['prosub_u_ID']]);
                $userRecord = $userStmt->fetch(PDO::FETCH_ASSOC);
                $uploaderName = $userRecord['u_name'] ?? '';
            }
            
            // 獲取更新人（如果有更新）
            $updaterName = '';
            if ($hasUpdate && $updaterID) {
                $updaterStmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                $updaterStmt->execute([$updaterID]);
                $updaterRecord = $updaterStmt->fetch(PDO::FETCH_ASSOC);
                $updaterName = $updaterRecord['u_name'] ?? '';
            }
            
            // 【禁止使用 (無組別名)/(無簡介)/(未知) 當預設顯示】
            // 如果必要資料為空，返回明確的錯誤訊息
            if (empty($teamName)) {
                json_response([
                    "success" => false,
                    "message" => "查無此提交記錄的組別資訊"
                ]);
            }
            
            // 🔹 確保返回純 JSON，不包含任何 PHP 警告或 HTML
            json_response([
                "success" => true,
                "data" => [
                    "prosub_ID" => $prosub_ID,
                    "prosub_status" => $record['prosub_status'] ?? 1,
                    "team_name" => $teamName,
                    "intro" => $intro, // 從 content_json 解析的 intro，後端已轉成字串
                    "image_path" => $imagePath, // 海報路徑
                    "other_files" => $otherFiles, // 多檔列表（已解析，只包含 path 和 name，不包含 JSON key）
                    "created_time" => $createdTime, // 上傳時間
                    "updated_time" => $updatedTime,
                    "uploader_name" => $uploaderName, // 上傳人姓名
                    "has_update" => $hasUpdate,
                    "updater_name" => $updaterName,
                    "is_locked" => false // 從資料庫字段讀取，這裡簡化處理
                ]
            ]);

        case 'check_existing_submission':
            // ====== 檢查是否已有提交記錄（非暫存狀態） ======
            $u_ID = $_SESSION['u_ID'] ?? null;
            $role_ID = $_SESSION['role_ID'] ?? null;
            
            if (!$u_ID || $role_ID != 6) {
                json_response([
                    "success" => false,
                    "message" => "無權限訪問"
                ]);
            }
            
            // 獲取團隊 ID
            $team_ID = null;
            try {
                $teamUserField = 'team_u_ID';
                $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $checkStmt->execute();
                if (!$checkStmt->fetch()) {
                    $teamUserField = 'u_ID';
                }
                
                $stmt = $conn->prepare("
                    SELECT t.team_ID
                    FROM teamdata t
                    JOIN teammember tm ON t.team_ID = tm.team_ID
                    WHERE tm.{$teamUserField} = ? AND t.team_status = 1
                    LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $team = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($team) {
                    $team_ID = $team['team_ID'];
                }
            } catch (Exception $e) {
                json_response([
                    "success" => false,
                    "message" => "獲取團隊資訊失敗"
                ]);
            }
            
            if (!$team_ID) {
                json_response([
                    "success" => false,
                    "exists" => false
                ]);
            }
            
            // 檢查是否已有提交記錄（狀態不是4的，即非暫存狀態）
            try {
                $checkStmt = $conn->prepare("
                    SELECT prosub_ID 
                    FROM prosubdata 
                    WHERE team_ID = ? AND prosub_status != 4
                    ORDER BY prosub_created_d DESC 
                    LIMIT 1
                ");
                $checkStmt->execute([$team_ID]);
                $exists = $checkStmt->fetch() !== false;
                
                json_response([
                    "success" => true,
                    "exists" => $exists
                ]);
            } catch (Exception $e) {
                json_response([
                    "success" => false,
                    "exists" => false,
                    "message" => "檢查失敗：" . $e->getMessage()
                ]);
            }
            break;

        case 'getDraft':
            // ====== 獲取草稿（暫存記錄） ======
            $u_ID = $_SESSION['u_ID'] ?? null;
            $role_ID = $_SESSION['role_ID'] ?? null;
            
            if (!$u_ID || $role_ID != 6) {
                json_response([
                    "success" => false,
                    "message" => "無權限訪問"
                ]);
            }
            
            // 獲取團隊 ID
            $team_ID = null;
            try {
                $teamUserField = 'team_u_ID';
                $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $checkStmt->execute();
                if (!$checkStmt->fetch()) {
                    $teamUserField = 'u_ID';
                }
                
                $stmt = $conn->prepare("
                    SELECT t.team_ID
                    FROM teamdata t
                    JOIN teammember tm ON t.team_ID = tm.team_ID
                    WHERE tm.{$teamUserField} = ? AND t.team_status = 1
                    LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $team = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($team) {
                    $team_ID = $team['team_ID'];
                }
            } catch (Exception $e) {
                json_response([
                    "success" => false,
                    "message" => "獲取團隊資訊失敗"
                ]);
            }
            
            if (!$team_ID) {
                json_response([
                    "success" => false,
                    "message" => "您尚未加入任何團隊"
                ]);
            }
            
            // 🔹 【期限到後清除暫存】檢查是否已超過截止時間，如果已超過，不返回暫存資料
            $cohort_ID = null;
            $class_ID = null;
            try {
                // 獲取 cohort_ID 和 class_ID
                $enrollmentStmt = $conn->prepare("
                    SELECT cohort_ID, class_ID
                    FROM enrollmentdata
                    WHERE enroll_u_ID = ? AND enroll_status = 1
                    ORDER BY enroll_created_d DESC
                    LIMIT 1
                ");
                $enrollmentStmt->execute([$u_ID]);
                $enrollment = $enrollmentStmt->fetch(PDO::FETCH_ASSOC);
                if ($enrollment) {
                    if ($enrollment['cohort_ID']) {
                        $cohort_ID = (int)$enrollment['cohort_ID'];
                    }
                    if ($enrollment['class_ID']) {
                        $class_ID = (int)$enrollment['class_ID'];
                    }
                }
                
                // 如果從 enrollmentdata 獲取不到，從 teamdata 獲取
                if (!$cohort_ID) {
                    $teamStmt = $conn->prepare("SELECT cohort_ID FROM teamdata WHERE team_ID = ?");
                    $teamStmt->execute([$team_ID]);
                    $teamData = $teamStmt->fetch(PDO::FETCH_ASSOC);
                    if ($teamData && $teamData['cohort_ID']) {
                        $cohort_ID = (int)$teamData['cohort_ID'];
                    }
                }
            } catch (Exception $e) {
                // 忽略錯誤，繼續執行
            }
            
            // 檢查是否有正在進行的時段
            $activePeriod = null;
            if ($cohort_ID) {
                $activePeriod = checkActivePeriod($conn, $cohort_ID, $class_ID);
            }
            
            // 如果沒有正在進行的時段，檢查是否已超過截止時間
            if (!$activePeriod && $cohort_ID) {
                // 查詢最近的時段截止時間
                $deadlineStmt = $conn->prepare("
                    SELECT pro_end_d
                    FROM projectdata
                    WHERE pro_status = 1
                      AND pro_chorot_ID = ?
                      AND pro_end_d IS NOT NULL
                    ORDER BY pro_end_d DESC
                    LIMIT 1
                ");
                $deadlineStmt->execute([$cohort_ID]);
                $deadlineRecord = $deadlineStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($deadlineRecord && $deadlineRecord['pro_end_d']) {
                    // 使用資料庫 NOW() 檢查是否已超過截止時間
                    $checkStmt = $conn->prepare("SELECT NOW() > ? as is_passed");
                    $checkStmt->execute([$deadlineRecord['pro_end_d']]);
                    $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($checkResult && $checkResult['is_passed']) {
                        // 已超過截止時間，返回空資料（不帶出暫存資料）
                        json_response([
                            "success" => true,
                            "message" => "期限已過，不返回暫存資料",
                            "data" => null
                        ]);
                    }
                }
            }
            
            //  只查詢狀態為 4 的暫存記錄（已提交的不再視為草稿）
            //  以專題/團隊層級為主：查詢該團隊最新的暫存記錄（不論 pro_ID）
            try {
                // 檢查是否有 intro 字段
                $hasIntroField = false;
                try {
                    $checkStmt = $conn->prepare("SHOW COLUMNS FROM prosubdata LIKE 'prosub_intro'");
                    $checkStmt->execute();
                    $hasIntroField = $checkStmt->rowCount() > 0;
                } catch (Exception $e) {
                    // 忽略錯誤
                }
                
                $selectFields = [
                    'prosub_ID',
                    'prosub_img',
                    'prosub_other',
                    'content_json',
                    'prosub_status',
                    'prosub_created_d',
                    'prosub_update_d'
                ];
                
                if ($hasIntroField) {
                    $selectFields[] = 'prosub_intro';
                }
                
                // 🔹 【關鍵修復】只查詢狀態為 4 的暫存記錄（已提交的不再視為草稿）
                // 已提交的資料（狀態不是 4）不再視為草稿，避免回填已提交的內容到表單
                $sql = "SELECT " . implode(', ', $selectFields) . "
                    FROM prosubdata
                    WHERE team_ID = ? AND prosub_status = 4
                    ORDER BY prosub_update_d DESC, prosub_created_d DESC
                    LIMIT 1";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([$team_ID]);
                $draft = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($draft) {
                    // 🔹 暫存記錄允許資料不完整，直接返回（不檢查 is_deleted，因為狀態 4 就是暫存）
                    // 🔹 從資料庫字段讀取 intro（如果字段存在），否則從 JSON 讀取（兼容舊資料）
                    // 重要：確保 intro 字段一定有值（即使是空字串也要返回，讓前端知道有暫存記錄）
                    $intro = '';
                    $posterOriginalName = '';
                    if ($hasIntroField && isset($draft['prosub_intro']) && $draft['prosub_intro'] !== null) {
                        $intro = trim($draft['prosub_intro']);
                    } else {
                        $contentJson = json_decode($draft['content_json'] ?? '{}', true);
                        $intro = isset($contentJson['intro']) ? trim($contentJson['intro']) : '';
                    }
                    
                    //  從 content_json 讀取原始檔名（用於顯示）
                    $contentJson = json_decode($draft['content_json'] ?? '{}', true);
                    $posterOriginalName = $contentJson['poster_original_name'] ?? '';
                    
                    // 🔹 【關鍵修復】解析檔案列表（必須返回所有資料庫中的檔案，不檢查檔案是否存在）
                    // 原因：檔案可能暫時無法訪問，但資料庫記錄應該保留，F5後應該顯示
                    $otherFiles = [];
                    if ($draft['prosub_other']) {
                        $otherFilesJson = json_decode($draft['prosub_other'], true);
                        // 🔹 【除錯輸出】確保正確解析
                        error_log("[getDraft] prosub_other 原始值: " . substr($draft['prosub_other'], 0, 200));
                        error_log("[getDraft] prosub_other 解析後是否為數組: " . (is_array($otherFilesJson) ? 'yes' : 'no'));
                        if (is_array($otherFilesJson)) {
                            error_log("[getDraft] prosub_other 解析後檔案數: " . count($otherFilesJson));
                            foreach ($otherFilesJson as $file) {
                                if (is_string($file)) {
                                    // 舊格式：字符串路徑
                                    // 🔹 不檢查檔案是否存在，直接返回（讓前端處理檔案不存在的情況）
                                    $fileName = basename($file);
                                    $otherFiles[] = [
                                        'original_name' => $fileName, // 🔹 【修復檔名顯示】確保有 original_name
                                        'name' => $fileName,
                                        'path' => $file,
                                        'type' => '',
                                        'uploaded_at' => '',
                                        'public' => true
                                    ];
                                } elseif (is_array($file) && isset($file['path'])) {
                                    // 新格式或舊格式對象
                                    // 🔹 不檢查檔案是否存在，直接返回（讓前端處理檔案不存在的情況）
                                    if (isset($file['name']) && isset($file['type']) && isset($file['uploaded_at']) && isset($file['public'])) {
                                        // 新格式：確保有 original_name
                                        if (!isset($file['original_name'])) {
                                            $file['original_name'] = $file['name'] ?? basename($file['path']);
                                        }
                                        $otherFiles[] = $file;
                                    } else {
                                        // 舊格式：轉換為新格式，確保有 original_name
                                        $fileName = $file['name'] ?? $file['original_name'] ?? basename($file['path']);
                                        $otherFiles[] = [
                                            'original_name' => $fileName, // 🔹 【修復檔名顯示】確保有 original_name
                                            'name' => $fileName,
                                            'path' => $file['path'],
                                            'type' => $file['type'] ?? '',
                                            'uploaded_at' => $file['uploaded_at'] ?? $file['upload_time'] ?? '',
                                            'public' => isset($file['public']) ? (bool)$file['public'] : (isset($file['allow_download']) ? (bool)$file['allow_download'] : true)
                                        ];
                                    }
                                }
                            }
                        }
                    }
                    
                    // 🔹 【關鍵修復】海報路徑（不檢查檔案是否存在，直接返回資料庫中的路徑）
                    // 原因：檔案可能暫時無法訪問，但資料庫記錄應該保留，F5後應該顯示
                    $posterPath = $draft['prosub_img'] ?? '';
                    
                    // 🔹 確保返回所有暫存欄位，讓 F5 後所有欄位都能正確回填（像被強力膠黏住）
                    $responseData = [
                        "success" => true,
                        "message" => "找到草稿",
                        "data" => [
                            "prosub_ID" => $draft['prosub_ID'],
                            "status" => $draft['prosub_status'], // 🔹 【關鍵修復】返回狀態，讓前端能正確判斷
                            "prosub_status" => $draft['prosub_status'], // 向後兼容
                            "intro" => $intro, // 專題簡介（必填）
                            "poster_path" => $posterPath, // 海報路徑（必填）
                            "poster_original_name" => $posterOriginalName, // 海報原始檔名
                            "other_files" => $otherFiles, // 多檔列表（至少1個，必填）
                            "created_at" => $draft['prosub_created_d'],
                            "updated_at" => $draft['prosub_update_d']
                        ]
                    ];
                    // 使用 json_encode 確保正確編碼
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
                    exit;
                }
                
                // 沒有找到草稿
                json_response([
                    "success" => true,
                    "message" => "沒有草稿",
                    "data" => null
                ]);
            } catch (Exception $e) {
                json_response([
                    "success" => false,
                    "message" => "查詢草稿失敗：" . $e->getMessage()
                ]);
            }
            break;

        default:
            json_response([
                "success" => false,
                "message" => "未知的操作"
            ]);
    }

} catch (Exception $e) {
    json_response([
        "success" => false,
        "message" => "錯誤: " . $e->getMessage()
    ]);
}
