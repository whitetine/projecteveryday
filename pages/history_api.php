<?php
session_start();
require '../includes/pdo.php';

header('Content-Type: application/json; charset=utf-8');

$do = $_GET['do'] ?? $_POST['action'] ?? '';

// 公開 API（所有已登入用戶都可以訪問，或在公開模式下無需登入）
$publicAPIs = ['get_gallery', 'get_gallery_detail', 'get_all_cohorts', 'get_all_groups', 'get_team_suggest_history'];

// 學生端 API（學生 role_ID = 6 可以訪問）
$studentAPIs = ['get_student_archive_files'];

// 特殊處理的 API（在內部進行權限檢查）
$specialAPIs = ['get_cohorts'];

// 檢查是否為公開模式
$isPublic = isset($_GET['public']) && $_GET['public'] == '1';

// 檢查權限（主任 role_ID = 1 和 科辦 role_ID = 2，學生 role_ID = 6 可以訪問特定 API）
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

// 如果是公開 API，只需要登入即可（或在公開模式下無需登入）
if (in_array($do, $publicAPIs)) {
    // 公開 API，在公開模式下無需登入，否則需要已登入
    if (!$isPublic && (!isset($u_ID) || !$u_ID)) {
        echo json_encode([
            "success" => false,
            "message" => "請先登入"
        ]);
        exit;
    }
} elseif (in_array($do, $specialAPIs)) {
    // 特殊 API，在內部進行權限檢查，這裡只檢查是否已登入
    if (!isset($u_ID) || !$u_ID) {
        echo json_encode([
            "success" => false,
            "message" => "請先登入"
        ]);
        exit;
    }
} elseif (in_array($do, $studentAPIs)) {
    // 學生端 API，需要學生權限
    if (!isset($role_ID) || $role_ID != 6) {
        echo json_encode([
            "success" => false,
            "message" => "無權限"
        ]);
        exit;
    }
} else {
    // 非公開 API，需要主任或科辦權限
    if (!isset($role_ID) || !in_array($role_ID, [1, 2])) {
        echo json_encode([
            "success" => false,
            "message" => "無權限"
        ]);
        exit;
    }
}

/**
 * 中文轉拼音函數
 * 
 * 注意：此函數會嘗試使用 overtrue/pinyin 庫（如果已安裝）
 * 如果未安裝，使用簡化版本（只包含常見字的對照表）
 * 
 * 建議安裝完整拼音庫：composer require overtrue/pinyin
 * 
 * @param string $text 中文文字
 * @return string 拼音字串（小寫，不含聲調，字之間用空格分隔）
 */
function textToPinyin($text) {
    if (empty($text)) {
        return '';
    }
    
    // 嘗試使用 overtrue/pinyin 庫（如果已安裝）
    if (class_exists('\Overtrue\Pinyin\Pinyin')) {
        try {
            $pinyin = new \Overtrue\Pinyin\Pinyin();
            // 轉換為拼音，字之間用空格分隔，小寫，不含聲調
            $result = $pinyin->permalink($text, '');
            // permalink 會產生連在一起的字串，需要加上空格
            // 改用 sentence 方法
            $result = $pinyin->sentence($text, ' ', \Overtrue\Pinyin\Pinyin::NONE);
            return strtolower($result);
        } catch (Exception $e) {
            // 如果使用庫失敗，使用簡化版本
            error_log("拼音庫使用失敗: " . $e->getMessage());
        }
    }
    
    // 簡化版：只處理常見字的拼音對照（可擴充）
    static $pinyinMap = null;
    
    if ($pinyinMap === null) {
        // 常見字的拼音對照表（可擴充）
        $pinyinMap = [
            // 彙/匯/會 組（同音字）
            '彙' => 'hui', '匯' => 'hui', '會' => 'hui',
            // 專題相關常見字
            '專' => 'zhuan', '題' => 'ti', '提' => 'ti', '日' => 'ri', '總' => 'zong',
            // 可以繼續擴充更多常見字...
        ];
    }
    
    $result = '';
    $length = mb_strlen($text);
    for ($i = 0; $i < $length; $i++) {
        $char = mb_substr($text, $i, 1);
        // 如果是中文字，轉換為拼音；否則保持原樣
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $char)) {
            // 中文字
            if (isset($pinyinMap[$char])) {
                $result .= ($result ? ' ' : '') . $pinyinMap[$char];
            } else {
                // 如果不在對照表中，保持原字（實際應用中應使用完整拼音庫轉換）
                $result .= ($result ? ' ' : '') . $char;
            }
        } else {
            // 非中文字（英文、數字等）
            $result .= $char;
        }
    }
    
    return strtolower(trim($result));
}

/**
 * 同義字對照表
 * 定義同義詞群組，搜尋時會自動擴展成同義集合
 * 
 * 注意：目前使用同義字對照表來處理同音字（如彙/匯/會）
 * 後續可以升級為使用完整的拼音索引（需要在資料庫中建立拼音索引欄位，並在寫入時同步產生）
 * 
 * @return array 同義字對照表，格式：[['word1', 'word2', 'word3'], ...]
 */
function getSynonymsMap() {
    return [
        // 同音字組（彙/匯/會）
        ['彙', '匯', '會'],
        // 同音字組（提/題）
        ['提', '題'],
        // 可以繼續擴充更多同義字組...
    ];
}

/**
 * 關鍵字正規化處理函數
 * 生成所有可能的搜尋變體（原文、同義字變體）
 * 
 * @param string $keyword 原始關鍵字
 * @return array 所有搜尋關鍵字變體的陣列（包含原文和同義字變體）
 */
function normalizeSearchKeyword($keyword) {
    $result = [$keyword]; // 先加入原始關鍵字
    
    if (empty($keyword)) {
        return $result;
    }
    
    // 處理同義字對照（用於處理同義詞）
    $synonymsMap = getSynonymsMap();
    foreach ($synonymsMap as $synonymGroup) {
        // 檢查關鍵字中是否有這個群組中的任何字
        $foundChars = [];
        foreach ($synonymGroup as $char) {
            if (mb_strpos($keyword, $char) !== false) {
                $foundChars[] = $char;
            }
        }
        
        // 如果找到群組中的字，生成所有同義字的變體
        if (!empty($foundChars)) {
            foreach ($synonymGroup as $similarChar) {
                // 對每個找到的字，生成變體
                foreach ($foundChars as $foundChar) {
                    if ($similarChar !== $foundChar) {
                        // 替換所有出現的字
                        $variant = str_replace($foundChar, $similarChar, $keyword);
                        if ($variant !== $keyword && !in_array($variant, $result)) {
                            $result[] = $variant;
                        }
                    }
                }
            }
        }
    }
    
    // 去重並返回
    return array_values(array_unique($result));
}

try {
    switch ($do) {
        case 'create':
        case 'update':
            // ====== 新增或更新專題時段（使用 projectdata 表，加強防呆驗證） ======
            $pro_ID = isset($_POST['pro_ID']) ? (int)$_POST['pro_ID'] : 0;
            $pro_start_d = isset($_POST['pro_start_d']) ? trim($_POST['pro_start_d']) : '';
            $pro_end_d = isset($_POST['pro_end_d']) ? trim($_POST['pro_end_d']) : '';
            $pro_title = isset($_POST['pro_title']) ? trim($_POST['pro_title']) : '';
            $cohort_primary = isset($_POST['cohort_primary']) ? trim($_POST['cohort_primary']) : '';
            $class_ID = isset($_POST['class_ID']) ? trim($_POST['class_ID']) : '';
            $allowFileTypesRaw = isset($_POST['allow_file_types']) ? trim($_POST['allow_file_types']) : '';
            $allowFileTypesJson = $allowFileTypesRaw !== '' ? $allowFileTypesRaw : null;
            
            // 驗證必填欄位（詳細檢查）
            if (!$pro_start_d) {
                echo json_encode([
                    "success" => false,
                    "message" => "請選擇開始日期"
                ]);
                exit;
            }
            
            if (!$pro_end_d) {
                echo json_encode([
                    "success" => false,
                    "message" => "請選擇結束日期"
                ]);
                exit;
            }
            
            if (!$pro_title || trim($pro_title) === '') {
                echo json_encode([
                    "success" => false,
                    "message" => "請輸入標題"
                ]);
                exit;
            }
            
            if (mb_strlen(trim($pro_title)) > 200) {
                echo json_encode([
                    "success" => false,
                    "message" => "標題長度不能超過200個字元"
                ]);
                exit;
            }
            
            if (!$cohort_primary || (int)$cohort_primary <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "請選擇屆別"
                ]);
                exit;
            }
            
            // 驗證屆別是否存在
            $cohortCheckStmt = $conn->prepare("SELECT cohort_ID FROM cohortdata WHERE cohort_ID = ? AND cohort_status = 1");
            $cohortCheckStmt->execute([(int)$cohort_primary]);
            if (!$cohortCheckStmt->fetch()) {
                echo json_encode([
                    "success" => false,
                    "message" => "所選擇的屆別不存在或已停用"
                ]);
                exit;
            }
            
            // 驗證日期格式和邏輯
            $startDateTime = DateTime::createFromFormat('Y-m-d\TH:i', $pro_start_d);
            $endDateTime = DateTime::createFromFormat('Y-m-d\TH:i', $pro_end_d);
            
            if (!$startDateTime) {
                echo json_encode([
                    "success" => false,
                    "message" => "開始日期格式錯誤，請重新選擇"
                ]);
                exit;
            }
            
            if (!$endDateTime) {
                echo json_encode([
                    "success" => false,
                    "message" => "結束日期格式錯誤，請重新選擇"
                ]);
                exit;
            }
            
            // 驗證日期邏輯
            if ($endDateTime <= $startDateTime) {
                echo json_encode([
                    "success" => false,
                    "message" => "結束時間必須晚於開始時間"
                ]);
                exit;
            }
            
            // 檢查時間間隔是否合理（至少1分鐘）
            $interval = $startDateTime->diff($endDateTime);
            if ($interval->days == 0 && $interval->h == 0 && $interval->i < 1) {
                echo json_encode([
                    "success" => false,
                    "message" => "開始時間和結束時間至少需間隔1分鐘"
                ]);
                exit;
            }
            
            // 將目標設定儲存在 pro_des 欄位（JSON 格式）
            $targetSettings = [
                'class_ID' => $class_ID ? explode(',', $class_ID) : []
            ];
            
            $u_ID = $_SESSION['u_ID'] ?? null;
            $cohort_ID = (int)$cohort_primary;
            
            if ($do === 'create' || $pro_ID <= 0) {
                // 🔹 【新增一筆記錄】確保只新增一筆，不寫死任何值
                // 使用事務確保原子性操作
                try {
                    $conn->beginTransaction();
                    
                    $stmt = $conn->prepare("
                        INSERT INTO projectdata (
                            pro_chorot_ID, pro_title, pro_des, pro_start_d, pro_end_d, 
                            pro_status, pro_created_u_ID, pro_created_d, allow_file_types
                        ) VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), ?)
                    ");
                    $stmt->execute([
                        $cohort_ID,
                        $pro_title,
                        json_encode($targetSettings, JSON_UNESCAPED_UNICODE),
                        $startDateTime->format('Y-m-d H:i:s'),
                        $endDateTime->format('Y-m-d H:i:s'),
                        $u_ID,
                        $allowFileTypesJson
                    ]);
                    
                    $conn->commit();
                    
                    echo json_encode([
                        "success" => true,
                        "message" => "已新增專題時段"
                    ]);
                } catch (Exception $e) {
                    $conn->rollBack();
                    echo json_encode([
                        "success" => false,
                        "message" => "新增失敗：" . $e->getMessage()
                    ]);
                    exit;
                }
            } else {
                // 更新
                $stmt = $conn->prepare("
                    UPDATE projectdata 
                    SET pro_chorot_ID = ?,
                        pro_title = ?,
                        pro_des = ?,
                        pro_start_d = ?,
                        pro_end_d = ?,
                        allow_file_types = ?
                    WHERE pro_ID = ?
                ");
                $stmt->execute([
                    $cohort_ID,
                    $pro_title,
                    json_encode($targetSettings, JSON_UNESCAPED_UNICODE),
                    $startDateTime->format('Y-m-d H:i:s'),
                    $endDateTime->format('Y-m-d H:i:s'),
                    $allowFileTypesJson,
                    $pro_ID
                ]);
                
                echo json_encode([
                    "success" => true,
                    "message" => "已更新專題時段"
                ]);
            }
            break;
            
        case 'get_periods':
            // ====== 獲取所有專題時段列表 ======
            $stmt = $conn->query("
                SELECT 
                    p.pro_ID,
                    p.pro_chorot_ID as cohort_ID,
                    c.cohort_name,
                    p.pro_title,
                    p.pro_start_d,
                    p.pro_end_d,
                    p.pro_des,
                    p.pro_status,
                    p.pro_created_d,
                    p.allow_file_types
                FROM projectdata p
                LEFT JOIN cohortdata c ON p.pro_chorot_ID = c.cohort_ID
                WHERE p.pro_status = 1
                ORDER BY p.pro_created_d DESC
            ");
            $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 解析 pro_des 中的目標設定
            foreach ($periods as &$period) {
                $targetSettings = json_decode($period['pro_des'] ?? '{}', true);
                $period['class_ID'] = $targetSettings['class_ID'] ?? [];
                // 解析 allow_file_types JSON（若存在）
                if (!empty($period['allow_file_types'])) {
                    $aft = json_decode($period['allow_file_types'], true);
                    $period['allow_file_types'] = is_array($aft) ? array_values($aft) : [];
                } else {
                    $period['allow_file_types'] = [];
                }
            }
            
            echo json_encode([
                "success" => true,
                "data" => $periods
            ]);
            break;
            
        case 'get_all_periods':
            // ====== 獲取所有專題時段列表（包括已停用的） ======
            $stmt = $conn->query("
                SELECT 
                    p.pro_ID,
                    p.pro_chorot_ID as cohort_ID,
                    c.cohort_name,
                    p.pro_title,
                    p.pro_start_d,
                    p.pro_end_d,
                    p.pro_des,
                    p.pro_status,
                    p.pro_created_d,
                    u.u_name as creator_name
                FROM projectdata p
                LEFT JOIN cohortdata c ON p.pro_chorot_ID = c.cohort_ID
                LEFT JOIN userdata u ON p.pro_created_by = u.u_ID
                ORDER BY p.pro_created_d DESC
            ");
            $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $periods
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'get_recent_periods':
            // ====== 獲取近期開放的專題時段（顯示所有記錄，支援篩選） ======
            // 獲取篩選參數
            $filterCohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            $filterStatus = isset($_GET['status']) ? (int)$_GET['status'] : -1; // -1 表示不過濾
            
            try {
                // 構建 WHERE 條件
                $whereConditions = [];
                $params = [];
                
                // 狀態篩選：如果指定了狀態，則過濾；否則顯示所有狀態
                if ($filterStatus >= 0) {
                    $whereConditions[] = "p.pro_status = ?";
                    $params[] = $filterStatus;
                }
                
                // 屆別篩選
                if ($filterCohort_ID > 0) {
                    $whereConditions[] = "p.pro_chorot_ID = ?";
                    $params[] = $filterCohort_ID;
                }
                
                // 基本條件：必須有開始時間
                $whereConditions[] = "p.pro_start_d IS NOT NULL";
                
                $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
                
                // 顯示最近開放的時段（按建立時間排序，顯示最近建立的）
                $sql = "
                    SELECT 
                        p.pro_ID,
                        p.pro_chorot_ID as cohort_ID,
                        c.cohort_name,
                        p.pro_title,
                        p.pro_start_d,
                        p.pro_end_d,
                        p.pro_des,
                        p.pro_status,
                        p.pro_created_d,
                        u.u_name as creator_name
                    FROM projectdata p
                    LEFT JOIN cohortdata c ON p.pro_chorot_ID = c.cohort_ID
                    LEFT JOIN userdata u ON p.pro_created_u_ID = u.u_ID
                    {$whereClause}
                    ORDER BY 
                      CASE 
                        WHEN p.pro_end_d >= NOW() AND p.pro_start_d <= NOW() THEN 0  -- 進行中
                        WHEN p.pro_start_d > NOW() THEN 1  -- 尚未開始
                        ELSE 2  -- 已結束
                      END,
                      ABS(TIMESTAMPDIFF(SECOND, NOW(), p.pro_start_d)) ASC,
                      p.pro_created_d DESC
                ";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // 解析 pro_des 中的目標設定，並獲取班級名稱
                foreach ($periods as &$period) {
                    $targetSettings = json_decode($period['pro_des'] ?? '{}', true);
                    $classIDs = $targetSettings['class_ID'] ?? [];
                    $period['class_ID'] = $classIDs;
                    
                    // 如果有選擇班級，查詢班級名稱
                    $period['class_names'] = [];
                    if (!empty($classIDs) && is_array($classIDs)) {
                        $classIDs = array_filter(array_map('intval', $classIDs));
                        if (!empty($classIDs)) {
                            $placeholders = implode(',', array_fill(0, count($classIDs), '?'));
                            $classStmt = $conn->prepare("
                                SELECT c_ID as class_ID, c_name as class_name
                                FROM classdata
                                WHERE c_ID IN ($placeholders)
                                ORDER BY c_name ASC
                            ");
                            $classStmt->execute($classIDs);
                            $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
                            $period['class_names'] = array_column($classes, 'class_name');
                        }
                    }
                }
                
                echo json_encode([
                    "success" => true,
                    "data" => $periods
                ], JSON_UNESCAPED_UNICODE);
            } catch (Exception $e) {
                error_log("get_recent_periods 錯誤: " . $e->getMessage());
                echo json_encode([
                    "success" => false,
                    "message" => "載入失敗：" . $e->getMessage(),
                    "data" => []
                ], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'delete_period':
            // ====== 刪除專題時段（整筆資料刪除） ======
            $pro_ID = isset($_POST['pro_ID']) ? (int)$_POST['pro_ID'] : 0;
            
            if ($pro_ID <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
                exit;
            }
            
            // 檢查記錄是否存在
            $stmt = $conn->prepare("SELECT pro_ID FROM projectdata WHERE pro_ID = ?");
            $stmt->execute([$pro_ID]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                echo json_encode([
                    "success" => false,
                    "message" => "記錄不存在"
                ]);
                exit;
            }
            
            // 整筆資料刪除：使用 DELETE 語句
            $stmt = $conn->prepare("DELETE FROM projectdata WHERE pro_ID = ?");
            $stmt->execute([$pro_ID]);
            
            echo json_encode([
                "success" => true,
                "message" => "已刪除專題時段"
            ]);
            break;
            
        case 'toggle_period_status':
            // ====== 切換時段狀態（啟用/停用） ======
            $pro_ID = isset($_POST['pro_ID']) ? (int)$_POST['pro_ID'] : 0;
            
            if ($pro_ID <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
                exit;
            }
            
            // 獲取當前狀態
            $stmt = $conn->prepare("SELECT pro_status FROM projectdata WHERE pro_ID = ?");
            $stmt->execute([$pro_ID]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                echo json_encode([
                    "success" => false,
                    "message" => "找不到該時段"
                ]);
                exit;
            }
            
            // 切換狀態（1 變 0，0 變 1）
            $newStatus = $record['pro_status'] == 1 ? 0 : 1;
            $statusText = $newStatus == 1 ? '啟用' : '停用';
            
            // 更新狀態
            $updateStmt = $conn->prepare("UPDATE projectdata SET pro_status = ? WHERE pro_ID = ?");
            $updateStmt->execute([$newStatus, $pro_ID]);
            
            echo json_encode([
                "success" => true,
                "message" => "已{$statusText}時段",
                "new_status" => $newStatus
            ]);
            break;
            
        case 'get_cohorts':
            // ====== 獲取屆別列表（根據 enrollmentdata 權限檢查，只顯示啟用狀態的屆別） ======
            // 確認 session 變數存在
            if (!isset($u_ID) || !isset($role_ID)) {
                echo json_encode([
                    "success" => false,
                    "message" => "請先登入",
                    "data" => []
                ]);
                break;
            }
            
            // 主任（role_ID = 1）和科辦（role_ID = 2）：顯示所有啟用的屆別
            if (in_array($role_ID, [1, 2])) {
                $stmt = $conn->prepare("
                    SELECT 
                        cohort_ID,
                        cohort_name,
                        COALESCE(year_label, CONCAT(cohort_ID, '級')) as year_label
                    FROM cohortdata
                    WHERE cohort_status = 1
                    ORDER BY cohort_ID DESC
                ");
                $stmt->execute();
                $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($cohorts)) {
                    echo json_encode([
                        "success" => false,
                        "message" => "目前尚無啟用屆別",
                        "data" => []
                    ]);
                } else {
                    echo json_encode([
                        "success" => true,
                        "data" => $cohorts
                    ]);
                }
            } else {
                // 其他角色：根據 enrollmentdata 中的記錄來篩選
                // 【規則】同一個 enroll_u_ID 在 enrollmentdata 永遠只允許 1 筆資料
                // 只顯示該用戶在 enrollmentdata 中有記錄，且 role_ID 匹配的屆別
                $stmt = $conn->prepare("
                    SELECT DISTINCT
                        c.cohort_ID,
                        c.cohort_name,
                        COALESCE(c.year_label, CONCAT(c.cohort_ID, '級')) as year_label
                    FROM cohortdata c
                    INNER JOIN enrollmentdata e ON c.cohort_ID = e.cohort_ID
                    WHERE e.enroll_u_ID = ?
                      AND e.role_ID = ?
                      AND e.enroll_status = 1
                      AND c.cohort_status = 1
                    ORDER BY c.cohort_ID DESC
                ");
                $stmt->execute([$u_ID, $role_ID]);
                $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($cohorts)) {
                    echo json_encode([
                        "success" => false,
                        "message" => "目前尚無啟用屆別",
                        "data" => []
                    ]);
                } else {
                    echo json_encode([
                        "success" => true,
                        "data" => $cohorts
                    ]);
                }
            }
            break;
            
        case 'get_all_classes':
            // ====== 獲取所有班級列表（從資料庫 classdata 表，不依賴屆別） ======
            $stmt = $conn->query("
                SELECT 
                    c_ID as class_ID,
                    c_name as class_name
                FROM classdata
                ORDER BY c_name DESC
            ");
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $classes
            ]);
            break;
            
        case 'get_classes':
            // ====== 獲取班級列表（根據屆別，從資料庫 classdata 表） ======
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            
            if ($cohort_ID <= 0) {
                echo json_encode([
                    "success" => true,
                    "data" => []
                ]);
                exit;
            }
            
            // 檢查 teammember 表的用戶欄位名稱（兼容不同版本）
            $teamUserField = 'u_ID';
            try {
                $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $checkStmt->execute();
                if ($checkStmt->rowCount() > 0) {
                    $teamUserField = 'team_u_ID';
                }
            } catch (Exception $e) {
                // 使用默認值
            }
            
            // 從 enrollmentdata 和 classdata 獲取該屆別的班級
            // 因為 teamdata 沒有 class_ID，需要透過 enrollmentdata 來關聯
            $stmt = $conn->prepare("
                SELECT DISTINCT
                    c.c_ID as class_ID,
                    c.c_name as class_name
                FROM classdata c
                INNER JOIN enrollmentdata e ON c.c_ID = e.class_ID
                INNER JOIN teammember tm ON e.enroll_u_ID = tm.{$teamUserField}
                INNER JOIN teamdata t ON tm.team_ID = t.team_ID
                WHERE e.cohort_ID = ? 
                  AND e.enroll_status = 1
                  AND t.cohort_ID = ?
                  AND t.team_status = 1
                  AND (tm.tm_status IS NULL OR tm.tm_status = 1)
                ORDER BY c.c_name ASC
            ");
            $stmt->execute([$cohort_ID, $cohort_ID]);
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $classes
            ]);
            break;
            
        case 'get_teams':
            // ====== 獲取團隊列表 ======
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            $class_ID = isset($_GET['class_ID']) ? trim($_GET['class_ID']) : '';
            
            if ($cohort_ID <= 0) {
                echo json_encode([
                    "success" => true,
                    "data" => []
                ]);
                exit;
            }
            
            $where = ['t.cohort_ID = ?', 't.team_status = 1'];
            $params = [$cohort_ID];
            
            if ($class_ID) {
                $classIDs = array_filter(array_map('intval', explode(',', $class_ID)));
                if (!empty($classIDs)) {
                    $placeholders = implode(',', array_fill(0, count($classIDs), '?'));
                    $where[] = "t.class_ID IN ($placeholders)";
                    $params = array_merge($params, $classIDs);
                }
            }
            
            $sql = "
                SELECT 
                    t.team_ID,
                    t.team_project_name
                FROM teamdata t
                WHERE " . implode(' AND ', $where) . "
                ORDER BY t.team_project_name ASC
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $teams
            ]);
            break;
            
        case 'get_list':
            // ====== 獲取歷屆專題列表（從 prosubdata 表中獲取狀態為 3=通過 的記錄） ======
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            $group_ID = isset($_GET['group_ID']) ? (int)$_GET['group_ID'] : 0;
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';
            
            $where = ['ps.prosub_status = 3']; // 只顯示通過的專題
            $params = [];
            
            if ($search) {
                $where[] = "t.team_project_name LIKE ?";
                $params[] = "%{$search}%";
            }
            
            if ($cohort_ID > 0) {
                $where[] = "t.cohort_ID = ?";
                $params[] = $cohort_ID;
            }
            
            if ($group_ID > 0) {
                $where[] = "t.group_ID = ?";
                $params[] = $group_ID;
            }
            
            $sql = "
                SELECT 
                    ps.prosub_ID,
                    ps.team_ID,
                    ps.prosub_img as hp_poster,
                    ps.prosub_other,
                    ps.content_json,
                    ps.prosub_u_ID,
                    ps.prosub_created_d as hp_created_d,
                    ps.prosub_update_d as hp_update_d,
                    t.team_project_name as hp_project_name,
                    t.group_ID as hp_group_ID,
                    g.group_name as hp_group_name,
                    t.cohort_ID as hp_cohort_ID,
                    c.cohort_name as hp_cohort_name
                FROM prosubdata ps
                INNER JOIN teamdata t ON ps.team_ID = t.team_ID
                LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                WHERE " . implode(' AND ', $where) . " 
                ORDER BY ps.prosub_update_d DESC, ps.prosub_created_d DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 過濾已刪除的記錄和根據狀態篩選
            $filteredResults = [];
            foreach ($results as $row) {
                $contentJson = json_decode($row['content_json'] ?? '{}', true);
                
                // 排除已刪除的記錄
                if (isset($contentJson['is_deleted']) && $contentJson['is_deleted']) {
                    continue;
                }
                
                // 狀態篩選：根據 content_json 中的 history_status（已上架/未上架）
                // 如果沒有 history_status，預設為未上架(0)
                // 只有科辦在"歷屆專題管理"頁面按下"上架"時，history_status 才會是 1
                $historyStatus = isset($contentJson['history_status']) ? (int)$contentJson['history_status'] : 0;
                
                // 預設只顯示已上架的專題（歷屆成果管理頁面要求）
                // 如果 status 參數為 'all'，則顯示所有已通過的專題（管理端查看用）
                if ($status === '') {
                    // 預設：只顯示已上架
                    if ($historyStatus != 1) {
                        continue;
                    }
                } elseif ($status === 'all') {
                    // 顯示所有已通過的專題（管理端用）
                    // 不進行過濾
                } else {
                    // 根據 status 參數篩選
                    if ($status == '1' && $historyStatus != 1) {
                        continue; // 要顯示啟用，但這個是停用
                    }
                    if ($status == '0' && $historyStatus != 0) {
                        continue; // 要顯示停用，但這個是啟用
                    }
                }
                
                // 搜尋簡介（如果有的話）
                if ($search) {
                    $intro = $contentJson['intro'] ?? '';
                    if (stripos($row['hp_project_name'], $search) === false && 
                        stripos($intro, $search) === false) {
                        continue;
                    }
                }
                
                $filteredResults[] = $row;
            }
            $results = $filteredResults;
            
            // 處理每個專題的資料
            $projects = [];
            
            // 檢查 teammember 表的用戶欄位名稱（兼容不同版本）
            $teamUserField = 'u_ID';
            try {
                $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $checkStmt->execute();
                if ($checkStmt->fetch()) {
                    $teamUserField = 'team_u_ID';
                }
            } catch (Exception $e) {
                // 使用默認值
            }
            
            // 檢查 userrolesdata 表的欄位名稱（只檢查一次，重用）
            $userRoleUidField = 'ur_u_ID'; // 預設為 ur_u_ID（根據資料庫結構）
            try {
                $checkRoleStmt = $conn->prepare("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'");
                $checkRoleStmt->execute();
                if (!$checkRoleStmt->fetch()) {
                    // 如果沒有 ur_u_ID，檢查是否有 user_u_ID
                    $checkRoleStmt2 = $conn->prepare("SHOW COLUMNS FROM userrolesdata LIKE 'user_u_ID'");
                    $checkRoleStmt2->execute();
                    if ($checkRoleStmt2->fetch()) {
                        $userRoleUidField = 'user_u_ID';
                    } else {
                        // 如果都沒有，使用 u_ID
                        $userRoleUidField = 'u_ID';
                    }
                }
            } catch (Exception $e) {
                // 使用默認值 ur_u_ID
                $userRoleUidField = 'ur_u_ID';
            }
            
            foreach ($results as $row) {
                $contentJson = json_decode($row['content_json'] ?? '{}', true);
                
                // 從 projectdata 獲取該學年度的統一期限
                $unifiedDeadline = null;
                if ($row['hp_cohort_ID']) {
                    try {
                        $deadlineStmt = $conn->prepare("
                            SELECT pro_end_d 
                            FROM projectdata 
                            WHERE pro_chorot_ID = ? AND pro_status = 1 AND pro_end_d IS NOT NULL
                            ORDER BY pro_created_d DESC
                            LIMIT 1
                        ");
                        $deadlineStmt->execute([$row['hp_cohort_ID']]);
                        $deadlineRecord = $deadlineStmt->fetch(PDO::FETCH_ASSOC);
                        if ($deadlineRecord && $deadlineRecord['pro_end_d']) {
                            $unifiedDeadline = $deadlineRecord['pro_end_d'];
                        }
                    } catch (Exception $e) {
                        // 忽略錯誤
                    }
                }
                
                // 獲取組員信息（學生）
                $teamMembers = [];
                if (!empty($row['team_ID'])) {
                    try {
                        $memberStmt = $conn->prepare("
                            SELECT u.u_ID, u.u_name
                            FROM teammember tm
                            INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
                            WHERE tm.team_ID = ?
                            -- 歷屆顯示需包含「停用(0)」與「正常(1)」的成員，避免少人
                            AND (tm.tm_status IS NULL OR tm.tm_status IN (0, 1))
                            -- 以「是否曾是學生(role=6)」為主；若 userrolesdata 缺資料，也要能顯示
                            AND (
                                EXISTS (
                                    SELECT 1
                                    FROM userrolesdata ur
                                    WHERE ur.{$userRoleUidField} = u.u_ID
                                      AND ur.role_ID = 6
                                )
                                OR NOT EXISTS (
                                    SELECT 1
                                    FROM userrolesdata ur2
                                    WHERE ur2.{$userRoleUidField} = u.u_ID
                                )
                            )
                            ORDER BY u.u_name
                        ");
                        $memberStmt->execute([$row['team_ID']]);
                        $teamMembers = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
                        // 調試：記錄查詢結果
                        if (empty($teamMembers)) {
                            error_log("獲取組員為空 (team_ID: {$row['team_ID']}, teamUserField: {$teamUserField}, userRoleUidField: {$userRoleUidField})");
                        }
                    } catch (Exception $e) {
                        // 記錄錯誤但不中斷流程
                        error_log("獲取組員失敗 (team_ID: {$row['team_ID']}, teamUserField: {$teamUserField}, userRoleUidField: {$userRoleUidField}): " . $e->getMessage());
                    }
                }
                
                // 獲取指導老師
                $teamTeachers = [];
                if (!empty($row['team_ID'])) {
                    try {
                        $teacherStmt = $conn->prepare("
                            SELECT u.u_ID, u.u_name
                            FROM teammember tm
                            INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
                            WHERE tm.team_ID = ?
                            AND (tm.tm_status IS NULL OR tm.tm_status IN (0, 1))
                            AND EXISTS (
                                SELECT 1
                                FROM userrolesdata ur
                                WHERE ur.{$userRoleUidField} = u.u_ID
                                  AND ur.role_ID = 4
                            )
                            ORDER BY u.u_name
                        ");
                        $teacherStmt->execute([$row['team_ID']]);
                        $teamTeachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);
                        // 調試：記錄查詢結果
                        if (empty($teamTeachers)) {
                            error_log("獲取指導老師為空 (team_ID: {$row['team_ID']}, teamUserField: {$teamUserField}, userRoleUidField: {$userRoleUidField})");
                        }
                    } catch (Exception $e) {
                        // 記錄錯誤但不中斷流程
                        error_log("獲取指導老師失敗 (team_ID: {$row['team_ID']}, teamUserField: {$teamUserField}, userRoleUidField: {$userRoleUidField}): " . $e->getMessage());
                    }
                }
                
                // 處理 prosub_other（多檔列表），確保每個檔案都有 allow_download 欄位
                // 🔹 【統一使用 allow_download 欄位】根據用戶要求，使用 allow_download
                $otherFiles = [];
                if (!empty($row['prosub_other'])) {
                    $otherFilesJson = json_decode($row['prosub_other'], true);
                    if (is_array($otherFilesJson)) {
                        foreach ($otherFilesJson as $index => $file) {
                            if (is_string($file)) {
                                // 舊格式：字符串路徑，轉換為新格式，預設 allow_download=0（不開放，需要手動開啟）
                                $otherFiles[] = [
                                    'index' => $index,
                                    'path' => $file,
                                    'name' => basename($file),
                                    'allow_download' => 0 // 舊格式默認不開放，需要手動開啟
                                ];
                            } elseif (is_array($file)) {
                                // 新格式：從資料庫讀取 allow_download 值，如果沒有則預設為 0（關閉，需要手動開啟）
                                $filePath = $file['path'] ?? '';
                                $fileName = $file['name'] ?? $file['original_name'] ?? basename($filePath);
                                // 🔹 【統一使用 allow_download】兼容舊的 allow 欄位
                                $allow_download = 0;
                                if (isset($file['allow_download'])) {
                                    $allow_download = (int)$file['allow_download'];
                                } elseif (isset($file['allow'])) {
                                    // 兼容舊的 allow 欄位
                                    $allow_download = (int)$file['allow'];
                                }
                                
                                $otherFiles[] = [
                                    'index' => $index,
                                    'path' => $filePath,
                                    'name' => $fileName,
                                    'allow_download' => $allow_download
                                ];
                            }
                        }
                    }
                }
                
                // 獲取上傳人姓名
                $uploaderName = '';
                if (!empty($row['prosub_u_ID'])) {
                    try {
                        $userStmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                        $userStmt->execute([$row['prosub_u_ID']]);
                        $userRecord = $userStmt->fetch(PDO::FETCH_ASSOC);
                        $uploaderName = $userRecord['u_name'] ?? '';
                    } catch (Exception $e) {
                        // 忽略錯誤
                    }
                }
                
                $projects[] = [
                    'hp_ID' => $row['prosub_ID'], // 使用 prosub_ID 作為識別
                    'prosub_ID' => $row['prosub_ID'],
                    'team_ID' => $row['team_ID'],
                    'hp_project_name' => $row['hp_project_name'],
                    'hp_poster' => $row['hp_poster'],
                    'hp_intro' => $contentJson['intro'] ?? '', // 從 content_json 解析 intro
                    'hp_group_ID' => $row['hp_group_ID'],
                    'hp_group_name' => $row['hp_group_name'],
                    'hp_cohort_ID' => $row['hp_cohort_ID'],
                    'hp_cohort_name' => $row['hp_cohort_name'],
                    'other_files' => $otherFiles, // 多檔案列表（包含 allow 欄位）
                    'content_json' => $row['content_json'], // 保留 content_json 供前端使用
                    'hp_upload_deadline' => $unifiedDeadline, // 統一期限從 projectdata 獲取
                    'hp_is_locked' => isset($contentJson['is_locked']) && $contentJson['is_locked'] ? 1 : 0,
                    'hp_status' => 1, // 因為查詢條件已經限定 prosub_status = 3（通過），所以這裡固定為 1（通過）
                    'hp_created_d' => $row['hp_created_d'],
                    'hp_update_d' => $row['hp_update_d'],
                    'hp_uploader_name' => $uploaderName, // 上傳人姓名
                    'other_files' => $otherFiles, // 多檔列表（已解析，不包含 JSON key）
                    'content_json' => $row['content_json'], // 添加 content_json 字段，用於前端判斷是否已上架
                    'team_members' => $teamMembers, // 組員列表（學生）
                    'team_teachers' => $teamTeachers // 指導老師列表
                ];
            }
            
            echo json_encode([
                "success" => true,
                "data" => $projects,
                "count" => count($projects)
            ]);
            break;
            
        case 'get_deadlines':
            // ====== 獲取所有學年度的統一繳交期限 ======
            $deadlineStmt = $conn->query("
                SELECT DISTINCT 
                    p.pro_chorot_ID as cohort_ID,
                    c.cohort_name,
                    p.pro_end_d as deadline
                FROM projectdata p
                LEFT JOIN cohortdata c ON p.pro_chorot_ID = c.cohort_ID
                WHERE p.pro_status = 1 AND p.pro_end_d IS NOT NULL
                ORDER BY p.pro_end_d DESC
            ");
            $deadlines = $deadlineStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "deadlines" => $deadlines
            ]);
            break;
            
        case 'set_deadline':
            // ====== 設定統一上傳期限（更新 projectdata 的 pro_end_d，所有團隊共用） ======
            $prosub_ID = isset($_POST['hp_ID']) ? (int)$_POST['hp_ID'] : 0;
            $deadline = isset($_POST['deadline']) ? trim($_POST['deadline']) : '';
            
            if ($prosub_ID <= 0 || !$deadline) {
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
            
            // 獲取該提交記錄對應的 pro_ID 和 cohort_ID
            $stmt = $conn->prepare("
                SELECT ps.pro_ID, t.cohort_ID
                FROM prosubdata ps
                INNER JOIN teamdata t ON ps.team_ID = t.team_ID
                WHERE ps.prosub_ID = ?
            ");
            $stmt->execute([$prosub_ID]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record || !$record['pro_ID'] || !$record['cohort_ID']) {
                echo json_encode([
                    "success" => false,
                    "message" => "找不到對應的專題資料"
                ]);
                exit;
            }
            
            // 更新 projectdata 的 pro_end_d（統一期限，所有該學年度的團隊共用）
            $updateStmt = $conn->prepare("
                UPDATE projectdata 
                SET pro_end_d = ?
                WHERE pro_chorot_ID = ? AND pro_status = 1
            ");
            $updateStmt->execute([
                $deadlineDateTime->format('Y-m-d H:i:s'),
                $record['cohort_ID']
            ]);
            
            echo json_encode([
                "success" => true,
                "message" => "統一上傳期限設定成功（所有該學年度的團隊共用此期限）"
            ]);
            break;
            
        case 'lock':
            // ====== 鎖定/解鎖專題（儲存在 content_json 中） ======
            $prosub_ID = isset($_POST['hp_ID']) ? (int)$_POST['hp_ID'] : 0;
            $isLocked = isset($_POST['is_locked']) ? (int)$_POST['is_locked'] : 1;
            
            if ($prosub_ID <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
                exit;
            }
            
            // 獲取現有的 content_json
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
            $contentJson['is_locked'] = (bool)$isLocked;
            
            // 更新 content_json
            $updateStmt = $conn->prepare("
                UPDATE prosubdata 
                SET content_json = ?,
                    prosub_update_d = NOW()
                WHERE prosub_ID = ?
            ");
            $updateStmt->execute([
                json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                $prosub_ID
            ]);
            
            echo json_encode([
                "success" => true,
                "message" => $isLocked ? "已鎖定" : "已解鎖"
            ]);
            break;
            
        case 'check_deadlines':
            // ====== 檢查並自動鎖定過期的專題 ======
            $now = date('Y-m-d H:i:s');
            
            // 找出已過期且未鎖定的專題（狀態為 3=通過）
            $checkStmt = $conn->prepare("
                SELECT prosub_ID, content_json 
                FROM prosubdata 
                WHERE prosub_status = 3
                AND content_json IS NOT NULL
                AND JSON_EXTRACT(content_json, '$.upload_deadline') IS NOT NULL
                AND JSON_EXTRACT(content_json, '$.upload_deadline') < ?
                AND (JSON_EXTRACT(content_json, '$.is_locked') IS NULL OR JSON_EXTRACT(content_json, '$.is_locked') = false)
                AND (JSON_EXTRACT(content_json, '$.history_status') IS NULL OR JSON_EXTRACT(content_json, '$.history_status') = 1)
            ");
            $checkStmt->execute([$now]);
            $expiredProjects = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $lockedCount = 0;
            foreach ($expiredProjects as $project) {
                $contentJson = json_decode($project['content_json'] ?? '{}', true);
                $contentJson['is_locked'] = true;
                
                // 更新 prosubdata
                $updateStmt = $conn->prepare("
                    UPDATE prosubdata 
                    SET content_json = ?,
                        prosub_update_d = NOW()
                    WHERE prosub_ID = ?
                ");
                $updateStmt->execute([
                    json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                    $project['prosub_ID']
                ]);
                
                $lockedCount++;
            }
            
            echo json_encode([
                "success" => true,
                "message" => "已檢查並鎖定 {$lockedCount} 個過期專題"
            ]);
            break;
            
        case 'toggle_multi_file_download':
            // ====== 切換多檔案下載狀態（儲存在 content_json 中） ======
            $prosub_ID = isset($_POST['hp_ID']) ? (int)$_POST['hp_ID'] : 0;
            $allowDownload = isset($_POST['allow_download']) ? (int)$_POST['allow_download'] : 0;
            
            if ($prosub_ID <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
                exit;
            }
            
            // 獲取現有的 content_json
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
            
            // 檢查是否已上架（只有已上架才能切換）
            $historyStatus = $contentJson['history_status'] ?? 0;
            if ($historyStatus != 1) {
                echo json_encode([
                    "success" => false,
                    "message" => "未上架不可設定"
                ]);
                exit;
            }
            
            // 檢查當前狀態，避免重複寫入相同狀態
            $currentAllowDownload = $contentJson['allow_multi_file_download'] ?? false;
            if (($currentAllowDownload === true || $currentAllowDownload === 1) && $allowDownload == 1) {
                echo json_encode([
                    "success" => false,
                    "message" => "狀態未變更（已是開放下載）"
                ]);
                exit;
            }
            if (($currentAllowDownload === false || $currentAllowDownload === 0 || $currentAllowDownload === null) && $allowDownload == 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "狀態未變更（已是不開放）"
                ]);
                exit;
            }
            
            $contentJson['allow_multi_file_download'] = (bool)$allowDownload;
            
            // 更新 content_json
            $updateStmt = $conn->prepare("
                UPDATE prosubdata 
                SET content_json = ?,
                    prosub_update_d = NOW()
                WHERE prosub_ID = ?
            ");
            $updateStmt->execute([
                json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                $prosub_ID
            ]);
            
            echo json_encode([
                "success" => true,
                "message" => $allowDownload == 1 ? "已開放下載" : "已停止下載"
            ]);
            break;
            
        case 'update_status':
            // ====== 更新專題狀態（啟用/停用，儲存在 content_json 中） ======
            $prosub_ID = isset($_POST['hp_ID']) ? (int)$_POST['hp_ID'] : 0;
            $status = isset($_POST['status']) ? (int)$_POST['status'] : 0;
            
            if ($prosub_ID <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
                exit;
            }
            
            // 獲取現有的 content_json 與 team_ID（上架時需同步更新 teamdata）
            $stmt = $conn->prepare("SELECT content_json, team_ID FROM prosubdata WHERE prosub_ID = ?");
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
            
            // 防呆：如果狀態沒變更，不重複執行
            $currentStatus = isset($contentJson['history_status']) ? (int)$contentJson['history_status'] : 0;
            if ($currentStatus == $status) {
                echo json_encode([
                    "success" => false,
                    "message" => "狀態未變更"
                ]);
                exit;
            }
            
            $contentJson['history_status'] = $status;
            
            // 上架時：prosubdata 設為已結案(3)，並同步 teamdata.team_status = 3
            $updateFields = "content_json = ?, prosub_update_d = NOW()";
            $updateParams = [json_encode($contentJson, JSON_UNESCAPED_UNICODE), $prosub_ID];
            if ($status == 1) {
                $updateFields .= ", prosub_status = 3";
            }
            $updateStmt = $conn->prepare("
                UPDATE prosubdata 
                SET {$updateFields}
                WHERE prosub_ID = ?
            ");
            $updateStmt->execute($updateParams);
            
            if ($status == 1 && !empty($record['team_ID'])) {
                $teamUpdateStmt = $conn->prepare("UPDATE teamdata SET team_status = 3, team_update_d = NOW() WHERE team_ID = ?");
                $teamUpdateStmt->execute([$record['team_ID']]);
            }
            
            echo json_encode([
                "success" => true,
                "message" => $status == 1 ? "已上架" : "已下架"
            ]);
            break;
            
        case 'batch_publish':
            // ====== 一併上架所有通過的專題（時間截止後） ======
            $now = date('Y-m-d H:i:s');
            
            // 找出所有通過的專題（prosub_status = 3），且時間已截止的
            // 需要檢查該專題所屬學年度的統一截止時間（projectdata.pro_end_d）
            $stmt = $conn->prepare("
                SELECT 
                    ps.prosub_ID,
                    ps.team_ID,
                    ps.content_json,
                    t.cohort_ID,
                    p.pro_end_d
                FROM prosubdata ps
                INNER JOIN teamdata t ON ps.team_ID = t.team_ID
                LEFT JOIN projectdata p ON p.pro_chorot_ID = t.cohort_ID AND p.pro_status = 1
                WHERE ps.prosub_status = 3
                AND (JSON_EXTRACT(ps.content_json, '$.is_deleted') IS NULL OR JSON_EXTRACT(ps.content_json, '$.is_deleted') = false)
                AND (
                    JSON_EXTRACT(ps.content_json, '$.history_status') IS NULL 
                    OR JSON_EXTRACT(ps.content_json, '$.history_status') = 0
                )
                AND p.pro_end_d IS NOT NULL
                AND p.pro_end_d < ?
                ORDER BY ps.prosub_ID
            ");
            $stmt->execute([$now]);
            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $publishedCount = 0;
            $skippedCount = 0;
            $teamUpdateStmt = $conn->prepare("UPDATE teamdata SET team_status = 3, team_update_d = NOW() WHERE team_ID = ?");
            foreach ($projects as $project) {
                $contentJson = json_decode($project['content_json'] ?? '{}', true);
                
                // 防呆：如果已是已上架，跳過
                $currentStatus = isset($contentJson['history_status']) ? (int)$contentJson['history_status'] : 0;
                if ($currentStatus == 1) {
                    $skippedCount++;
                    continue;
                }
                
                $contentJson['history_status'] = 1; // 設為啟用
                
                // 更新 prosubdata：content_json、已結案(prosub_status=3)
                $updateStmt = $conn->prepare("
                    UPDATE prosubdata 
                    SET content_json = ?,
                        prosub_status = 3,
                        prosub_update_d = NOW()
                    WHERE prosub_ID = ?
                ");
                $updateStmt->execute([
                    json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                    $project['prosub_ID']
                ]);
                
                // 同步 teamdata 狀態為已結案(3)
                if (!empty($project['team_ID'])) {
                    $teamUpdateStmt->execute([$project['team_ID']]);
                }
                $publishedCount++;
            }
            
            $message = "已上架 {$publishedCount} 個專題";
            if ($skippedCount > 0) {
                $message .= "，{$skippedCount} 個已是已上架狀態";
            }
            
            echo json_encode([
                "success" => true,
                "message" => $message,
                "count" => $publishedCount
            ]);
            break;
            
        case 'batch_publish_selected':
            // ====== 批量上架選中的專題 ======
            $prosubIdsJson = isset($_POST['prosub_ids']) ? $_POST['prosub_ids'] : '[]';
            $prosubIds = json_decode($prosubIdsJson, true);
            
            if (!is_array($prosubIds) || count($prosubIds) === 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "請選擇至少一個專題"
                ]);
                exit;
            }
            
            // 驗證所有 ID 都是整數
            $prosubIds = array_filter(array_map('intval', $prosubIds), function($id) {
                return $id > 0;
            });
            
            if (count($prosubIds) === 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "無效的專題ID"
                ]);
                exit;
            }
            
            $placeholders = implode(',', array_fill(0, count($prosubIds), '?'));
            
            // 查詢選中的專題（必須是已通過的專題），含 team_ID 以同步 teamdata
            $stmt = $conn->prepare("
                SELECT 
                    ps.prosub_ID,
                    ps.team_ID,
                    ps.content_json
                FROM prosubdata ps
                WHERE ps.prosub_ID IN ({$placeholders})
                AND ps.prosub_status = 3
                AND (JSON_EXTRACT(ps.content_json, '$.is_deleted') IS NULL OR JSON_EXTRACT(ps.content_json, '$.is_deleted') = false)
            ");
            $stmt->execute($prosubIds);
            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $publishedCount = 0;
            $skippedCount = 0;
            $teamUpdateStmt = $conn->prepare("UPDATE teamdata SET team_status = 3, team_update_d = NOW() WHERE team_ID = ?");
            
            foreach ($projects as $project) {
                $contentJson = json_decode($project['content_json'] ?? '{}', true);
                
                // 防呆：檢查當前狀態，如果已是已上架，跳過
                $currentStatus = isset($contentJson['history_status']) ? (int)$contentJson['history_status'] : 0;
                if ($currentStatus == 1) {
                    $skippedCount++;
                    continue; // 跳過，不更新
                }
                
                // 只更新未上架的資料
                $contentJson['history_status'] = 1; // 設為啟用
                
                // 更新 prosubdata：content_json、已結案(prosub_status=3)（後端防呆：只更新未上架的資料）
                $updateStmt = $conn->prepare("
                    UPDATE prosubdata 
                    SET content_json = ?,
                        prosub_status = 3,
                        prosub_update_d = NOW()
                    WHERE prosub_ID = ?
                    AND (JSON_EXTRACT(content_json, '$.history_status') IS NULL 
                         OR JSON_EXTRACT(content_json, '$.history_status') = 0)
                ");
                $updateStmt->execute([
                    json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                    $project['prosub_ID']
                ]);
                
                // 檢查是否有實際更新（affected_rows）
                if ($updateStmt->rowCount() > 0) {
                    $publishedCount++;
                    // 同步 teamdata 狀態為已結案(3)
                    if (!empty($project['team_ID'])) {
                        $teamUpdateStmt->execute([$project['team_ID']]);
                    }
                } else {
                    $skippedCount++;
                }
            }
            
            // 如果全部都是已上架
            if ($publishedCount === 0 && $skippedCount > 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "所選資料皆已上架，無需再次上架"
                ]);
                exit;
            }
            
            $message = "已上架 {$publishedCount} 個專題";
            if ($skippedCount > 0) {
                $message .= "，{$skippedCount} 個已是已上架狀態";
            }
            
            echo json_encode([
                "success" => true,
                "message" => $message,
                "count" => $publishedCount
            ]);
            break;
            
        case 'batch_unpublish_selected':
            // ====== 批量下架選中的專題 ======
            $prosubIdsJson = isset($_POST['prosub_ids']) ? $_POST['prosub_ids'] : '[]';
            $prosubIds = json_decode($prosubIdsJson, true);
            
            if (!is_array($prosubIds) || count($prosubIds) === 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "請選擇至少一個專題"
                ]);
                exit;
            }
            
            // 驗證所有 ID 都是整數
            $prosubIds = array_filter(array_map('intval', $prosubIds), function($id) {
                return $id > 0;
            });
            
            if (count($prosubIds) === 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "無效的專題ID"
                ]);
                exit;
            }
            
            $placeholders = implode(',', array_fill(0, count($prosubIds), '?'));
            
            // 查詢選中的專題（必須是已通過的專題）
            $stmt = $conn->prepare("
                SELECT 
                    ps.prosub_ID,
                    ps.content_json
                FROM prosubdata ps
                WHERE ps.prosub_ID IN ({$placeholders})
                AND ps.prosub_status = 3
                AND (JSON_EXTRACT(ps.content_json, '$.is_deleted') IS NULL OR JSON_EXTRACT(ps.content_json, '$.is_deleted') = false)
            ");
            $stmt->execute($prosubIds);
            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $unpublishedCount = 0;
            $skippedCount = 0;
            
            foreach ($projects as $project) {
                $contentJson = json_decode($project['content_json'] ?? '{}', true);
                
                // 防呆：檢查當前狀態，如果已是未上架，跳過
                $currentStatus = isset($contentJson['history_status']) ? (int)$contentJson['history_status'] : 0;
                if ($currentStatus == 0) {
                    $skippedCount++;
                    continue; // 跳過，不更新
                }
                
                // 只下架已上架的資料
                $contentJson['history_status'] = 0; // 設為未上架
                
                // 更新 content_json（後端防呆：只更新已上架的資料）
                $updateStmt = $conn->prepare("
                    UPDATE prosubdata 
                    SET content_json = ?,
                        prosub_update_d = NOW()
                    WHERE prosub_ID = ?
                    AND JSON_EXTRACT(content_json, '$.history_status') = 1
                ");
                $updateStmt->execute([
                    json_encode($contentJson, JSON_UNESCAPED_UNICODE),
                    $project['prosub_ID']
                ]);
                
                // 檢查是否有實際更新（affected_rows）
                if ($updateStmt->rowCount() > 0) {
                    $unpublishedCount++;
                } else {
                    $skippedCount++;
                }
            }
            
            // 如果全部都是未上架
            if ($unpublishedCount === 0 && $skippedCount > 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "所選資料皆未上架，無法進行下架"
                ]);
                exit;
            }
            
            $message = "已下架 {$unpublishedCount} 個專題";
            if ($skippedCount > 0) {
                $message .= "，{$skippedCount} 個已是未上架狀態";
            }
            
            echo json_encode([
                "success" => true,
                "message" => $message,
                "count" => $unpublishedCount
            ]);
            break;
            
        case 'sync_published_team_status':
            // ====== 同步已上架專題的 teamdata 狀態（補齊舊資料：已上架 = 已結案，team_status 設為 3） ======
            $syncStmt = $conn->prepare("
                UPDATE teamdata t
                INNER JOIN prosubdata ps ON ps.team_ID = t.team_ID
                SET t.team_status = 3, t.team_update_d = NOW()
                WHERE ps.prosub_status = 3
                AND COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(ps.content_json, '$.history_status')) AS UNSIGNED), 0) = 1
                AND (t.team_status IS NULL OR t.team_status != 3)
            ");
            $syncStmt->execute();
            $synced = $syncStmt->rowCount();
            echo json_encode([
                "success" => true,
                "message" => "已同步 {$synced} 筆團隊狀態為已結案",
                "count" => $synced
            ]);
            break;
            
        case 'toggle_file_download':
            // ====== 切換單個檔案的下載開關 ======
            // 🔹 【統一使用 allow_download 欄位】根據用戶要求，使用 allow_download 而不是 allow
            // 🔹 【2026-03】依照需求：同一「檔案類型」一起開放／關閉（例如：全部成果報告、全部簡報）
            $prosub_ID = isset($_POST['prosub_ID']) ? (int)$_POST['prosub_ID'] : 0;
            $fileIndex = isset($_POST['file_index']) ? (int)$_POST['file_index'] : -1;
            $allow_download = isset($_POST['allow_download']) ? (int)$_POST['allow_download'] : (isset($_POST['allow']) ? (int)$_POST['allow'] : 0);
            
            if ($prosub_ID <= 0 || $fileIndex < 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
                exit;
            }
            
            // 獲取現有的 content_json 和 prosub_other
            $stmt = $conn->prepare("SELECT content_json, prosub_other FROM prosubdata WHERE prosub_ID = ?");
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
            
            // 🔹 供下載開關可隨時修改，不限制需先上架
            
            // 處理 prosub_other
            $otherFilesJson = json_decode($record['prosub_other'] ?? '[]', true);
            if (!is_array($otherFilesJson)) {
                $otherFilesJson = [];
            }
            
            // 檢查索引是否有效
            if (!isset($otherFilesJson[$fileIndex])) {
                echo json_encode([
                    "success" => false,
                    "message" => "檔案索引不存在"
                ]);
                exit;
            }
            
            $file = $otherFilesJson[$fileIndex];
            
            // 轉換舊格式為新格式（確保有 allow_download 欄位）
            if (is_string($file)) {
                // 舊格式（字符串）：轉為新格式，預設 allow_download=0（不開放，需要手動開啟）
                $file = [
                    'path' => $file,
                    'name' => basename($file),
                    'allow_download' => 0
                ];
            } elseif (is_array($file)) {
                // 確保有必要的欄位
                if (!isset($file['path'])) {
                    $file['path'] = $file['name'] ?? '';
                }
                if (!isset($file['name'])) {
                    $file['name'] = basename($file['path']);
                }
                // 🔹 【統一使用 allow_download】兼容舊的 allow 欄位，但優先使用 allow_download
                if (!isset($file['allow_download'])) {
                    // 如果沒有 allow_download，檢查是否有舊的 allow 欄位（兼容性）
                    if (isset($file['allow'])) {
                        $file['allow_download'] = (int)$file['allow'];
                        // 移除舊的 allow 欄位，統一使用 allow_download
                        unset($file['allow']);
                    } else {
                        // 新格式沒有 allow_download 欄位，設為 0（關閉狀態，需要手動開啟）
                        $file['allow_download'] = 0;
                    }
                }
            }
            
            // 防呆：如果狀態沒變更，不重複寫入（從資料庫讀取實際值）
            $currentAllowDownload = isset($file['allow_download']) ? (int)$file['allow_download'] : 0;
            if ($currentAllowDownload == $allow_download) {
                echo json_encode([
                    "success" => false,
                    "message" => "狀態未變更"
                ]);
                exit;
            }
            
            // 更新目前這一組、這一個檔案的 allow_download
            $file['allow_download'] = $allow_download;
            // 同步 public 欄位（若存在），避免前後端判斷不一致
            if (is_array($file)) {
                $file['public'] = (bool)$allow_download;
            }
            $otherFilesJson[$fileIndex] = $file;
            
            // 先寫回目前這一筆資料
            $updateStmt = $conn->prepare("
                UPDATE prosubdata 
                SET prosub_other = ?,
                    prosub_update_d = NOW()
                WHERE prosub_ID = ?
            ");
            $updateStmt->execute([
                json_encode($otherFilesJson, JSON_UNESCAPED_UNICODE),
                $prosub_ID
            ]);

            // ====== 依「檔案類型 type」對所有歷屆成果一併套用設定 ======
            // 需求說明：如果科辦開放「成果報告書」就全部成果報告一起開放；PPT 類型同理
            $targetType = is_array($file) && !empty($file['type']) ? trim((string)$file['type']) : '';
            if ($targetType !== '') {
                try {
                    // 取出所有有上傳其它檔案的成果，逐一掃描 type 相同的檔案
                    $allStmt = $conn->prepare("
                        SELECT prosub_ID, prosub_other 
                        FROM prosubdata 
                        WHERE prosub_other IS NOT NULL 
                          AND prosub_other <> ''
                    ");
                    $allStmt->execute();
                    while ($row = $allStmt->fetch(PDO::FETCH_ASSOC)) {
                        $rowProsubId = (int)$row['prosub_ID'];
                        $rowOther = json_decode($row['prosub_other'] ?? '[]', true);
                        if (!is_array($rowOther) || !$rowOther) {
                            continue;
                        }

                        $changed = false;
                        foreach ($rowOther as $idx => $f) {
                            // 統一成陣列格式
                            if (is_string($f)) {
                                // 舊格式沒有 type，跳過，不自動套用
                                continue;
                            }
                            if (!is_array($f)) {
                                continue;
                            }
                            $fileType = isset($f['type']) ? trim((string)$f['type']) : '';
                            if ($fileType === '' || $fileType !== $targetType) {
                                continue;
                            }

                            // 只要 type 一樣，就同步 allow_download / public 狀態
                            $current = isset($f['allow_download']) ? (int)$f['allow_download'] : null;
                            if ($current === $allow_download) {
                                continue;
                            }
                            $f['allow_download'] = $allow_download;
                            $f['public'] = (bool)$allow_download;
                            $rowOther[$idx] = $f;
                            $changed = true;
                        }

                        if ($changed) {
                            $upd = $conn->prepare("
                                UPDATE prosubdata
                                SET prosub_other = ?,
                                    prosub_update_d = NOW()
                                WHERE prosub_ID = ?
                            ");
                            $upd->execute([
                                json_encode($rowOther, JSON_UNESCAPED_UNICODE),
                                $rowProsubId
                            ]);
                        }
                    }
                } catch (Throwable $e) {
                    // 若批次更新失敗，不影響目前這一筆的結果，只記錄 log
                    error_log('toggle_file_download batch by type failed: ' . $e->getMessage());
                }
            }
            
            echo json_encode([
                "success" => true,
                "message" => $allow_download == 1 ? "已開放下載" : "已停止下載"
            ]);
            break;
            
        case 'get_all_cohorts':
            // ====== 獲取所有啟用的屆別（不寫死，從資料庫讀取） ======
            // 只返回啟用狀態的屆別（cohort_status = 1），不限制是否有已上架專題
            $stmt = $conn->prepare("
                SELECT 
                    cohort_ID, 
                    cohort_name,
                    COALESCE(year_label, CONCAT(cohort_ID, '級')) as year_label
                FROM cohortdata
                WHERE cohort_status = 1
                ORDER BY cohort_ID DESC
            ");
            $stmt->execute();
            $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $cohorts
            ]);
            break;
            
        case 'get_all_groups':
            // ====== 獲取所有類組 ======
            $stmt = $conn->query("
                SELECT group_ID, group_name
                FROM groupdata
                ORDER BY group_name ASC
            ");
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                "success" => true,
                "data" => $groups
            ]);
            break;

        case 'get_cohort_file_types':
            // ====== 整屆下載設定：取得該屆所有出現的檔案類型及開放數量 ======
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            if ($cohort_ID <= 0) {
                echo json_encode(['success' => false, 'message' => '請選擇學年度', 'data' => []]);
                exit;
            }
            $stmt = $conn->prepare("
                SELECT ps.prosub_ID, ps.prosub_other
                FROM prosubdata ps
                INNER JOIN teamdata t ON ps.team_ID = t.team_ID
                WHERE t.cohort_ID = ? AND ps.prosub_status = 3
                  AND ps.prosub_other IS NOT NULL AND ps.prosub_other <> '' AND ps.prosub_other <> '[]'
            ");
            $stmt->execute([$cohort_ID]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $fileTypeLabels = ['report' => '成果書', 'ppt' => 'PPT', 'word' => 'Word'];
            $agg = [];
            foreach ($rows as $row) {
                $other = json_decode($row['prosub_other'] ?? '[]', true);
                if (!is_array($other)) continue;
                foreach ($other as $f) {
                    $ft = '';
                    if (is_array($f) && isset($f['file_type']) && trim((string)$f['file_type']) !== '') {
                        $ft = trim((string)$f['file_type']);
                    }
                    if ($ft === '') continue;
                    if (!isset($agg[$ft])) {
                        $agg[$ft] = ['total' => 0, 'allowed' => 0];
                    }
                    $agg[$ft]['total']++;
                    $ad = 0;
                    if (isset($f['allow_download'])) $ad = (int)$f['allow_download'];
                    elseif (isset($f['allow'])) $ad = (int)$f['allow'];
                    elseif (isset($f['public']) && $f['public']) $ad = 1;
                    if ($ad === 1) $agg[$ft]['allowed']++;
                }
            }
            $data = [];
            foreach ($agg as $file_type => $counts) {
                $data[] = [
                    'file_type' => $file_type,
                    'label' => isset($fileTypeLabels[$file_type]) ? $fileTypeLabels[$file_type] : $file_type,
                    'total' => (int)$counts['total'],
                    'allowed' => (int)$counts['allowed']
                ];
            }
            usort($data, function ($a, $b) {
                $order = ['report' => 0, 'ppt' => 1, 'word' => 2];
                $oa = isset($order[$a['file_type']]) ? $order[$a['file_type']] : 99;
                $ob = isset($order[$b['file_type']]) ? $order[$b['file_type']] : 99;
                return $oa - $ob;
            });
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'batch_set_download_by_cohort_and_file_type':
            // ====== 整屆下載設定：一併開放或一併不開放某檔案類型 ======
            $cohort_ID = isset($_POST['cohort_ID']) ? (int)$_POST['cohort_ID'] : 0;
            $file_type = isset($_POST['file_type']) ? trim((string)$_POST['file_type']) : '';
            $allow_download = isset($_POST['allow_download']) ? (int)$_POST['allow_download'] : 0;
            if ($cohort_ID <= 0 || $file_type === '') {
                echo json_encode(['success' => false, 'message' => '參數錯誤']);
                exit;
            }
            $allow_download = $allow_download === 1 ? 1 : 0;
            $stmt = $conn->prepare("
                SELECT ps.prosub_ID, ps.prosub_other
                FROM prosubdata ps
                INNER JOIN teamdata t ON ps.team_ID = t.team_ID
                WHERE t.cohort_ID = ? AND ps.prosub_status = 3
                  AND ps.prosub_other IS NOT NULL AND ps.prosub_other <> '' AND ps.prosub_other <> '[]'
            ");
            $stmt->execute([$cohort_ID]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $updated = 0;
            foreach ($rows as $row) {
                $other = json_decode($row['prosub_other'] ?? '[]', true);
                if (!is_array($other)) continue;
                $changed = false;
                foreach ($other as $idx => $f) {
                    if (!is_array($f)) continue;
                    $ft = isset($f['file_type']) ? trim((string)$f['file_type']) : '';
                    if ($ft !== $file_type) continue;
                    $cur = isset($f['allow_download']) ? (int)$f['allow_download'] : (isset($f['allow']) ? (int)$f['allow'] : 0);
                    if ($cur === $allow_download) continue;
                    // 同步三個欄位：allow_download / allow / public，確保所有舊版/新版判斷都一致
                    $other[$idx]['allow_download'] = $allow_download;
                    $other[$idx]['allow'] = $allow_download;
                    $other[$idx]['public'] = (bool)$allow_download;
                    $changed = true;
                }
                if ($changed) {
                    $upd = $conn->prepare("UPDATE prosubdata SET prosub_other = ?, prosub_update_d = NOW() WHERE prosub_ID = ?");
                    $upd->execute([json_encode($other, JSON_UNESCAPED_UNICODE), $row['prosub_ID']]);
                    $updated++;
                }
            }
            echo json_encode([
                'success' => true,
                'message' => $allow_download ? '已一併開放該類型檔案' : '已一併不開放該類型檔案',
                'updated' => $updated
            ]);
            break;
            
        case 'get_submissions_with_files':
            // ====== 獲取成果列表（含檔案資訊） ======
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            $group_ID = isset($_GET['group_ID']) ? (int)$_GET['group_ID'] : 0;
            
            // 構建查詢條件
            // 只顯示已審核通過(3)的成果
            // 排除：0=退件, 1=未審核/重新審核中, 2=申請修改中, 4=暫存
            // 注意：狀態 1 是未審核狀態，當成果被取消通過後會變成 1，此時不應該顯示
            $whereConditions = ['ps.prosub_status = 3'];
            $params = [];
            
            if ($cohort_ID > 0) {
                $whereConditions[] = 't.cohort_ID = ?';
                $params[] = $cohort_ID;
            }
            
            if ($group_ID > 0) {
                $whereConditions[] = 't.group_ID = ?';
                $params[] = $group_ID;
            }
            
            if ($search) {
                $whereConditions[] = '(t.team_project_name LIKE ? OR pd.pro_title LIKE ?)';
                $searchParam = '%' . $search . '%';
                $params[] = $searchParam;
                $params[] = $searchParam;
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            // 查詢成果資料
            $sql = "
                SELECT 
                    ps.prosub_ID,
                    ps.team_ID,
                    ps.prosub_other,
                    ps.prosub_status,
                    t.team_project_name,
                    t.cohort_ID,
                    t.group_ID,
                    c.cohort_name,
                    c.year_label,
                    g.group_name
                FROM prosubdata ps
                INNER JOIN teamdata t ON ps.team_ID = t.team_ID
                LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                LEFT JOIN projectdata pd ON ps.pro_ID = pd.pro_ID
                WHERE {$whereClause}
                ORDER BY ps.prosub_created_d DESC
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 處理每個成果的檔案資訊（從 prosub_other 讀取，計算摘要資訊）
            $result = [];
            foreach ($submissions as $sub) {
                $totalFiles = 0;
                $publicFiles = 0;
                $restrictedFiles = 0;
                
                // 從 prosub_other 讀取文件列表並計算摘要
                if ($sub['prosub_other']) {
                    $otherFilesJson = json_decode($sub['prosub_other'], true);
                    if (is_array($otherFilesJson)) {
                        foreach ($otherFilesJson as $file) {
                            if (is_string($file)) {
                                // 舊格式：字符串路徑，預設為公開
                                $totalFiles++;
                                $publicFiles++;
                            } elseif (is_array($file) && isset($file['path'])) {
                                // 新格式：包含 name, path, type, uploaded_at, public
                                $totalFiles++;
                                $isPublic = isset($file['public']) ? (bool)$file['public'] : (isset($file['allow_download']) ? (bool)$file['allow_download'] : true);
                                if ($isPublic) {
                                    $publicFiles++;
                                } else {
                                    $restrictedFiles++;
                                }
                            }
                        }
                    }
                }
                
                $result[] = [
                    'prosub_ID' => $sub['prosub_ID'],
                    'team_ID' => $sub['team_ID'],
                    'team_name' => $sub['team_project_name'] ?? '未命名團隊',
                    'cohort_name' => $sub['cohort_name'] . ($sub['year_label'] ? ' (' . $sub['year_label'] . ')' : ''),
                    'group_name' => $sub['group_name'],
                    'file_summary' => [
                        'total' => $totalFiles,
                        'public' => $publicFiles,
                        'restricted' => $restrictedFiles
                    ]
                ];
            }
            
            echo json_encode([
                "success" => true,
                "submissions" => $result
            ]);
            break;

        case 'get_submission_files':
            // ====== 獲取單個成果的完整檔案列表（用於「查看檔案」按鈕） ======
            $prosub_ID = isset($_GET['prosub_ID']) ? (int)$_GET['prosub_ID'] : 0;
            
            if ($prosub_ID <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "參數錯誤"
                ]);
                exit;
            }
            
            // 查詢成果資料
            $stmt = $conn->prepare("
                SELECT 
                    ps.prosub_ID,
                    ps.prosub_other,
                    t.team_project_name
                FROM prosubdata ps
                INNER JOIN teamdata t ON ps.team_ID = t.team_ID
                WHERE ps.prosub_ID = ?
            ");
            $stmt->execute([$prosub_ID]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$submission) {
                echo json_encode([
                    "success" => false,
                    "message" => "成果不存在"
                ]);
                exit;
            }
            
            $files = [];
            
            // 🔹 從 prosub_other 讀取文件列表，返回完整資訊（包含 fid 和 public 狀態）
            if (!empty($submission['prosub_other'])) {
                $otherFilesJson = json_decode($submission['prosub_other'], true);
                if (is_array($otherFilesJson)) {
                    foreach ($otherFilesJson as $index => $file) {
                        // 生成 fid（使用索引格式 file_0, file_1 等）
                        $fid = 'file_' . $index;
                        
                        if (is_string($file)) {
                            // 舊格式：字符串路徑
                            $files[] = [
                                'fid' => $fid,
                                'path' => $file,
                                'name' => basename($file),
                                'public' => true, // 舊格式默認開放
                                'uploaded_at' => ''
                            ];
                        } elseif (is_array($file) && isset($file['path'])) {
                            // 新格式：包含所有資訊
                            $fileName = $file['name'] ?? $file['original_name'] ?? basename($file['path']);
                            // 檢查 public 狀態（兼容 allow_download）
                            $isPublic = isset($file['public']) ? (bool)$file['public'] : 
                                       (isset($file['allow_download']) ? (bool)$file['allow_download'] : true);
                            
                            $files[] = [
                                'fid' => $file['fid'] ?? $fid, // 如果已有 fid 則使用，否則使用索引格式
                                'path' => $file['path'],
                                'name' => $fileName,
                                'type' => $file['type'] ?? '',
                                'uploaded_at' => $file['uploaded_at'] ?? $file['upload_time'] ?? '',
                                'public' => $isPublic
                            ];
                        }
                    }
                }
            }
            
            // 🔹 確保返回純 JSON
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                "success" => true,
                "prosub_ID" => $prosub_ID,
                "team_name" => $submission['team_project_name'] ?? '未命名團隊',
                "files" => $files // 多檔列表（已解析，只包含 path 和 name，不包含 JSON key）
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_student_archive_files':
            // ====== 學生端：獲取可下載的成果檔案列表（只返回 visible=true 且 allowDownload=true 的檔案，不包含 diskPath） ======
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            
            // 構建查詢條件：只顯示已審核通過(3)的成果
            // 排除：0=退件, 1=未審核/重新審核中, 2=申請修改中, 4=暫存
            $whereConditions = ['ps.prosub_status = 3'];
            $params = [];
            
            if ($cohort_ID > 0) {
                $whereConditions[] = 't.cohort_ID = ?';
                $params[] = $cohort_ID;
            }
            
            if ($search) {
                $whereConditions[] = 't.team_project_name LIKE ?';
                $searchParam = '%' . $search . '%';
                $params[] = $searchParam;
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            // 查詢成果資料
            $sql = "
                SELECT 
                    ps.prosub_ID,
                    ps.team_ID,
                    ps.prosub_other,
                    ps.prosub_status,
                    t.team_project_name,
                    t.cohort_ID,
                    t.group_ID,
                    c.cohort_name,
                    c.year_label,
                    g.group_name
                FROM prosubdata ps
                INNER JOIN teamdata t ON ps.team_ID = t.team_ID
                LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                LEFT JOIN projectdata pd ON ps.pro_ID = pd.pro_ID
                WHERE {$whereClause}
                  AND (pd.pro_status IS NULL OR pd.pro_status = 1)
                ORDER BY ps.prosub_created_d DESC
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 處理每個成果的檔案資訊（從 prosub_other 讀取，只返回 public=true 的檔案）
            $result = [];
            $debugInfo = []; // 調試信息（僅在開發環境顯示）
            foreach ($submissions as $sub) {
                $downloadableFiles = [];
                $subDebug = [
                    'prosub_ID' => $sub['prosub_ID'],
                    'team_name' => $sub['team_project_name'] ?? '未命名',
                    'has_prosub_other' => !empty($sub['prosub_other']),
                    'total_files' => 0,
                    'public_files' => 0
                ];
                
                // 從 prosub_other 讀取文件列表
                if ($sub['prosub_other']) {
                    $otherFilesJson = json_decode($sub['prosub_other'], true);
                    if (is_array($otherFilesJson)) {
                        $subDebug['total_files'] = count($otherFilesJson);
                        foreach ($otherFilesJson as $index => $file) {
                            $isPublic = true;
                            $filePath = '';
                            $fileName = '';
                            $uploadTime = '';
                            
                            if (is_string($file)) {
                                // 舊格式：字符串路徑，預設公開
                                $filePath = $file;
                                $fileName = basename($file);
                                $isPublic = true;
                            } elseif (is_array($file)) {
                                // 新格式：可能是多種結構
                                // 優先使用 path 字段
                                if (isset($file['path'])) {
                                    $filePath = $file['path'];
                                    $fileName = $file['name'] ?? $file['original_name'] ?? basename($filePath);
                                } elseif (isset($file['stored']) && isset($file['path'])) {
                                    // 如果有 stored 和 path，組合完整路徑
                                    $filePath = rtrim($file['path'], '/') . '/' . $file['stored'];
                                    $fileName = $file['name'] ?? basename($filePath);
                                } elseif (isset($file['name'])) {
                                    // 如果只有 name，嘗試從 name 構建路徑（舊格式兼容）
                                    // 檢查是否為 project_other_files 格式
                                    $fileName = $file['name'];
                                    if (preg_match('/^other_\d+_\d+_\d+_[a-f0-9]+\./', $fileName)) {
                                        // 格式：other_{team_ID}_{timestamp}_{index}_{uniqid}.{ext}
                                        $filePath = 'uploads/project_other_files/' . $fileName;
                                    } else {
                                        // 無法確定路徑，跳過此文件
                                        continue;
                                    }
                                } else {
                                    // 無法識別的格式，跳過
                                    continue;
                                }
                                
                                $uploadTime = $file['uploaded_at'] ?? $file['upload_time'] ?? '';
                                // 兼容 allow_download（舊格式）和 public（新格式）
                                // 如果都沒有設置，預設為 true（公開）
                                if (isset($file['public'])) {
                                    $isPublic = (bool)$file['public'];
                                } elseif (isset($file['allow_download'])) {
                                    $isPublic = (bool)$file['allow_download'];
                                } elseif (isset($file['allowDownload'])) {
                                    $isPublic = (bool)$file['allowDownload'];
                                } else {
                                    // 預設為公開（向後兼容）
                                    $isPublic = true;
                                }
                            }
                            
                            // 只返回 public=true 且有有效路徑的檔案（學生端只能看到公開的檔案）
                            if ($isPublic && $filePath) {
                                $subDebug['public_files']++;
                                $downloadableFiles[] = [
                                    'fid' => 'file_' . $index,
                                    'name' => $fileName,
                                    'path' => $filePath,
                                    'type' => is_array($file) ? ($file['type'] ?? '') : '',
                                    'uploaded_at' => $uploadTime
                                ];
                            }
                        }
                    } else {
                        $subDebug['json_decode_failed'] = true;
                    }
                }
                
                // 只有當有可下載檔案時才加入結果
                if (!empty($downloadableFiles)) {
                    $result[] = [
                        'prosub_ID' => $sub['prosub_ID'],
                        'team_ID' => $sub['team_ID'],
                        'team_name' => $sub['team_project_name'] ?? '未命名團隊',
                        'cohort_name' => ($sub['cohort_name'] ?? '') . ($sub['year_label'] ? ' (' . $sub['year_label'] . ')' : ''),
                        'cohort_ID' => $sub['cohort_ID'],
                        'group_name' => $sub['group_name'] ?? '',
                        'files' => $downloadableFiles
                    ];
                } else {
                    // 記錄沒有可下載檔案的原因
                    $debugInfo[] = $subDebug;
                }
            }
            
            $response = [
                "success" => true,
                "submissions" => $result
            ];
            
            // 在開發環境添加調試信息
            if (isset($_GET['debug']) || (isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1']))) {
                $response['debug'] = [
                    'total_submissions_found' => count($submissions),
                    'submissions_with_downloadable_files' => count($result),
                    'submissions_without_downloadable_files' => $debugInfo
                ];
            }
            
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            break;

        case 'export_downloadable_json':
            // ====== 導出所有開放下載的成果檔案為 JSON 格式（管理端和學生端都可使用） ======
            // 檢查權限：管理端（role_ID 1, 2）或學生端（role_ID 6）
            if (!isset($role_ID) || !in_array($role_ID, [1, 2, 6])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    "success" => false,
                    "message" => "無權限"
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // 構建查詢條件：只顯示已審核通過(3)的成果
            // 排除：0=退件, 1=未審核/重新審核中, 2=申請修改中, 4=暫存
            $whereConditions = ['ps.prosub_status = 3'];
            $params = [];
            
            // 查詢成果資料
            $sql = "
                SELECT 
                    ps.prosub_ID,
                    ps.team_ID,
                    ps.prosub_other,
                    ps.prosub_status,
                    ps.prosub_created_d,
                    t.team_project_name,
                    t.cohort_ID,
                    t.group_ID,
                    c.cohort_name,
                    c.year_label,
                    g.group_name
                FROM prosubdata ps
                INNER JOIN teamdata t ON ps.team_ID = t.team_ID
                LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                LEFT JOIN projectdata pd ON ps.pro_ID = pd.pro_ID
                WHERE " . implode(' AND ', $whereConditions) . "
                  AND (pd.pro_status IS NULL OR pd.pro_status = 1)
                ORDER BY ps.prosub_created_d DESC
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 處理每個成果的檔案資訊（只返回 public=true 的檔案）
            $result = [];
            foreach ($submissions as $sub) {
                $downloadableFiles = [];
                
                // 從 prosub_other 讀取文件列表
                if ($sub['prosub_other']) {
                    $otherFilesJson = json_decode($sub['prosub_other'], true);
                    if (is_array($otherFilesJson)) {
                        foreach ($otherFilesJson as $index => $file) {
                            $isPublic = true;
                            $filePath = '';
                            $fileName = '';
                            $uploadTime = '';
                            $fileSize = 0;
                            
                            if (is_string($file)) {
                                // 舊格式：字符串路徑（預設不開放，不顯示）
                                continue; // 跳過舊格式，不顯示
                            } elseif (is_array($file) && isset($file['path'])) {
                                // 新格式：包含 name, path, type, uploaded_at, allow_download
                                $filePath = $file['path'];
                                $fileName = $file['name'] ?? $file['original_name'] ?? basename($filePath);
                                $uploadTime = $file['uploaded_at'] ?? $file['upload_time'] ?? '';
                                $fileSize = isset($file['size']) ? (int)$file['size'] : 0;
                                // 🔹 【統一使用 allow_download 欄位】根據用戶要求，只返回 allow_download = 1 的檔案
                                $allow_download = 0;
                                if (isset($file['allow_download'])) {
                                    $allow_download = (int)$file['allow_download'];
                                } elseif (isset($file['allow'])) {
                                    // 兼容舊的 allow 欄位
                                    $allow_download = (int)$file['allow'];
                                } elseif (isset($file['public'])) {
                                    // 兼容舊的 public 欄位
                                    $allow_download = (bool)$file['public'] ? 1 : 0;
                                }
                                // 如果都沒有，預設為 0（不開放）
                            } else {
                                continue; // 無法識別的格式，跳過
                            }
                            
                            // 只返回 allow_download = 1 的檔案
                            if ($allow_download == 1 && $filePath) {
                                $downloadableFiles[] = [
                                    'fid' => 'file_' . $index,
                                    'name' => $fileName,
                                    'path' => $filePath,
                                    'type' => is_array($file) ? ($file['type'] ?? '') : '',
                                    'size' => $fileSize,
                                    'uploaded_at' => $uploadTime
                                ];
                            }
                        }
                    }
                }
                
                // 只有當有可下載檔案時才加入結果
                if (!empty($downloadableFiles)) {
                    $result[] = [
                        'prosub_ID' => (int)$sub['prosub_ID'],
                        'team_ID' => (int)$sub['team_ID'],
                        'team_name' => $sub['team_project_name'] ?? '未命名團隊',
                        'cohort_name' => $sub['cohort_name'] ?? '',
                        'cohort_ID' => (int)$sub['cohort_ID'],
                        'year_label' => $sub['year_label'] ?? '',
                        'group_name' => $sub['group_name'] ?? '',
                        'group_ID' => (int)$sub['group_ID'],
                        'created_at' => $sub['prosub_created_d'] ?? '',
                        'files' => $downloadableFiles
                    ];
                }
            }
            
            // 設置響應頭，直接下載 JSON 文件
            $filename = '歷屆成果開放下載資料_' . date('Y-m-d_His') . '.json';
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            
            // 輸出格式化的 JSON
            echo json_encode([
                'export_date' => date('Y-m-d H:i:s'),
                'total_submissions' => count($result),
                'total_files' => array_sum(array_map(function($sub) { return count($sub['files']); }, $result)),
                'submissions' => $result
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
            break;

        case 'get_gallery':
            // ====== 獲取已上架的專題列表（所有角色瀏覽用，只顯示 history_status = 1 的專題） ======
            $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            $group_ID = isset($_GET['group_ID']) ? (int)$_GET['group_ID'] : 0;
            
            // 檢查 teammember 表的用戶欄位名稱（提前檢查，後續使用）
            $teamUserField = 'u_ID';
            try {
                $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $checkStmt->execute();
                if ($checkStmt->fetch()) {
                    $teamUserField = 'team_u_ID';
                }
            } catch (Exception $e) {
                // 使用默認值
            }
            
            // 檢查 userrolesdata 表的欄位名稱（提前檢查，後續使用）
            $userRoleUidField = 'ur_u_ID'; // 預設為 ur_u_ID（根據資料庫結構）
            try {
                $checkRoleStmt = $conn->prepare("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'");
                $checkRoleStmt->execute();
                if (!$checkRoleStmt->fetch()) {
                    // 如果沒有 ur_u_ID，檢查是否有 user_u_ID
                    $checkRoleStmt2 = $conn->prepare("SHOW COLUMNS FROM userrolesdata LIKE 'user_u_ID'");
                    $checkRoleStmt2->execute();
                    if ($checkRoleStmt2->fetch()) {
                        $userRoleUidField = 'user_u_ID';
                    } else {
                        // 如果都沒有，使用 u_ID
                        $userRoleUidField = 'u_ID';
                    }
                }
            } catch (Exception $e) {
                // 使用默認值 ur_u_ID
                $userRoleUidField = 'ur_u_ID';
            }
            
            // 只獲取已通過且已上架的專題
            // 注意：必須同時滿足 prosub_status = 3（通過）和 history_status = 1（已上架）
            $where = [
                'ps.prosub_status = 3', // 只顯示通過的專題
                "(JSON_EXTRACT(ps.content_json, '$.is_deleted') IS NULL OR JSON_EXTRACT(ps.content_json, '$.is_deleted') = false)", // 排除已刪除的
                "JSON_EXTRACT(ps.content_json, '$.history_status') = 1" // 只顯示已上架的專題（必須明確為1）
            ];
            $params = [];
            
            // 智慧搜尋：多欄位模糊搜尋（OR 條件）
            if ($keyword) {
                $keywordConditions = [];
                
                /**
                 * 智慧搜尋：整合拼音索引和同義字對照
                 * 
                 * 1. 同音字：使用拼音索引欄位（pinyin_index）
                 *    - 將使用者輸入轉成拼音
                 *    - 比對資料庫中的拼音索引欄位
                 *    - 這樣「總彙/總匯/總會」會自然命中（因為拼音都是 zong hui）
                 * 
                 * 2. 同義字：使用同義字對照表（synonyms）
                 *    - 擴展使用者輸入為同義字集合
                 *    - 同時比對專題名稱、簡介、團隊名稱、指導老師
                 * 
                 * 注意：拼音索引欄位可以存在 content_json.pinyin_index 中
                 * 或者在寫入時新增 team_project_name_pinyin 等欄位
                 */
                
                // 1. 生成拼音索引（用於同音字匹配）
                $pinyinKeyword = textToPinyin($keyword);
                
                // 2. 生成同義字變體（用於同義詞匹配）
                $searchKeywords = normalizeSearchKeyword($keyword);
                
                // 3. 專題名稱和簡介使用所有變體搜尋（OR 條件）
                $projectNameConditions = [];
                $introConditions = [];
                
                // 3.1 原文匹配（包含同義字變體）
                foreach ($searchKeywords as $searchKw) {
                    // 完整關鍵字匹配（LIKE %keyword%）
                    $projectNameConditions[] = "t.team_project_name LIKE ?";
                    $introConditions[] = "JSON_EXTRACT(ps.content_json, '$.intro') LIKE ?";
                    $params[] = "%{$searchKw}%";
                    $params[] = "%{$searchKw}%";
                }
                
                // 3.2 拼音索引匹配（用於同音字）
                // 注意：由於資料庫中可能還沒有拼音索引欄位，這裡先使用原文比對
                // 完整的拼音索引功能需要在寫入時產生拼音索引並存入資料庫
                // 目前先使用同義字對照表來處理常見的同音字
                // 後續可以升級為：在寫入時產生拼音索引，並在搜尋時比對拼音索引欄位
                
                // 1. 專題名稱（project_name）- 模糊搜尋（支援同義字、拼音索引）
                if (!empty($projectNameConditions)) {
                    $keywordConditions[] = "(" . implode(" OR ", $projectNameConditions) . ")";
                }
                
                // 2. 專題簡介（project_intro）- 模糊搜尋（支援同義字、拼音索引）
                if (!empty($introConditions)) {
                    $keywordConditions[] = "(" . implode(" OR ", $introConditions) . ")";
                }
                
                // 3. 團隊名稱（team_name）- 與專題名稱相同，已在上面處理
                // 實際上專題名稱就是團隊名稱（t.team_project_name），所以不需要重複
                
                // 4. 指導老師姓名（teacher_name）- 使用 EXISTS 子查詢
                $keywordConditions[] = "EXISTS (
                    SELECT 1
                    FROM teammember tm_teacher
                    INNER JOIN userdata u_teacher ON tm_teacher.{$teamUserField} = u_teacher.u_ID
                    WHERE tm_teacher.team_ID = ps.team_ID
                    AND (tm_teacher.tm_status IS NULL OR tm_teacher.tm_status IN (0, 1))
                    AND u_teacher.u_name LIKE ?
                    AND EXISTS (
                        SELECT 1
                        FROM userrolesdata ur_teacher
                        WHERE ur_teacher.{$userRoleUidField} = u_teacher.u_ID
                        AND ur_teacher.role_ID = 4
                    )
                )";
                $params[] = "%{$keyword}%";
                
                // 5. 學級（grade）- 如果關鍵字是純數字，則比對學級
                // 使用 EXISTS 子查詢，從 teammember -> enrollmentdata 查詢學級
                if (preg_match('/^\d+$/', $keyword)) {
                    $keywordConditions[] = "EXISTS (
                        SELECT 1
                        FROM teammember tm_grade
                        INNER JOIN enrollmentdata e_grade ON tm_grade.{$teamUserField} = e_grade.enroll_u_ID
                        WHERE tm_grade.team_ID = ps.team_ID
                        AND (tm_grade.tm_status IS NULL OR tm_grade.tm_status IN (0, 1))
                        AND e_grade.enroll_status = 1
                        AND e_grade.enroll_grade = ?
                    )";
                    $params[] = (int)$keyword;
                }
                
                // 使用 OR 連接所有條件（只要任一欄位符合就顯示該專題成果）
                if (!empty($keywordConditions)) {
                    $where[] = "(" . implode(" OR ", $keywordConditions) . ")";
                }
            }
            
            if ($cohort_ID > 0) {
                // 🔹 【修復】使用 CAST 確保類型匹配，避免 108級(cohort_ID=1) 錯誤匹配到 110級(cohort_ID=3)
                $where[] = "CAST(t.cohort_ID AS CHAR) = CAST(? AS CHAR)";
                $params[] = $cohort_ID;
            }
            
            if ($group_ID > 0) {
                $where[] = "t.group_ID = ?";
                $params[] = $group_ID;
            }
            
            $sql = "
                SELECT DISTINCT
                    ps.prosub_ID,
                    ps.team_ID,
                    ps.prosub_img as hp_poster,
                    ps.content_json,
                    ps.prosub_update_d as hp_update_d,
                    ps.prosub_created_d as hp_created_d,
                    t.team_project_name as hp_project_name,
                    t.group_ID as hp_group_ID,
                    g.group_name as hp_group_name,
                    t.cohort_ID as hp_cohort_ID,
                    COALESCE(c.cohort_name, CONCAT(t.cohort_ID, '級')) as hp_cohort_name,
                    c.year_label as hp_year_label
                FROM prosubdata ps
                INNER JOIN teamdata t ON ps.team_ID = t.team_ID
                LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                LEFT JOIN cohortdata c ON CAST(t.cohort_ID AS CHAR) = CAST(c.cohort_ID AS CHAR) 
                    AND c.cohort_status = 1 
                    AND t.cohort_ID IS NOT NULL
                WHERE " . implode(' AND ', $where) . " 
                ORDER BY ps.prosub_update_d DESC, ps.prosub_created_d DESC
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 再次確認過濾（雙重檢查，確保資料正確）
            $filteredResults = [];
            foreach ($results as $row) {
                // 處理 content_json
                $contentJsonStr = $row['content_json'] ?? '{}';
                if (empty($contentJsonStr)) {
                    $contentJsonStr = '{}';
                }
                
                $contentJson = json_decode($contentJsonStr, true);
                if (!is_array($contentJson)) {
                    $contentJson = [];
                }
                
                // 排除已刪除的記錄（雙重檢查）
                if (isset($contentJson['is_deleted']) && ($contentJson['is_deleted'] === true || $contentJson['is_deleted'] === 1 || $contentJson['is_deleted'] === '1')) {
                    continue;
                }
                
                // 只保留已上架的專題（history_status = 1）（雙重檢查）
                // 必須明確設置為 1 才算已上架，其他任何情況（不存在、0、null、false等）都視為未上架
                $historyStatus = isset($contentJson['history_status']) ? (int)$contentJson['history_status'] : 0;
                if ($historyStatus !== 1) {
                    continue; // 跳過未上架的專題（包括通過但還沒上架的）
                }
                
                $filteredResults[] = $row;
            }
            $results = $filteredResults;
            
            $projects = [];
            foreach ($results as $row) {
                $contentJson = json_decode($row['content_json'] ?? '{}', true);
                
                // 最終確認 history_status = 1（三重檢查，確保資料正確）
                // 必須明確設置為 1 才算已上架，其他任何情況都視為未上架
                $historyStatus = isset($contentJson['history_status']) ? (int)$contentJson['history_status'] : 0;
                if ($historyStatus !== 1) {
                    continue; // 跳過未上架的專題（包括通過但還沒上架的）
                }
                
                // 獲取組員信息（學生）
                $teamMembers = [];
                if (!empty($row['team_ID'])) {
                    try {
                        $memberStmt = $conn->prepare("
                            SELECT u.u_ID, u.u_name
                            FROM teammember tm
                            INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
                            WHERE tm.team_ID = ?
                            AND (tm.tm_status IS NULL OR tm.tm_status IN (0, 1))
                            AND (
                                EXISTS (
                                    SELECT 1
                                    FROM userrolesdata ur
                                    WHERE ur.{$userRoleUidField} = u.u_ID
                                      AND ur.role_ID = 6
                                )
                                OR NOT EXISTS (
                                    SELECT 1
                                    FROM userrolesdata ur2
                                    WHERE ur2.{$userRoleUidField} = u.u_ID
                                )
                            )
                            ORDER BY u.u_name
                        ");
                        $memberStmt->execute([$row['team_ID']]);
                        $teamMembers = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        // 記錄錯誤但不中斷流程
                        error_log("獲取組員失敗 (team_ID: {$row['team_ID']}): " . $e->getMessage());
                    }
                }
                
                // 獲取指導老師
                $teamTeachers = [];
                if (!empty($row['team_ID'])) {
                    try {
                        $teacherStmt = $conn->prepare("
                            SELECT u.u_ID, u.u_name
                            FROM teammember tm
                            INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
                            WHERE tm.team_ID = ?
                            AND (tm.tm_status IS NULL OR tm.tm_status IN (0, 1))
                            AND EXISTS (
                                SELECT 1
                                FROM userrolesdata ur
                                WHERE ur.{$userRoleUidField} = u.u_ID
                                  AND ur.role_ID = 4
                            )
                            ORDER BY u.u_name
                        ");
                        $teacherStmt->execute([$row['team_ID']]);
                        $teamTeachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        // 記錄錯誤但不中斷流程
                        error_log("獲取指導老師失敗 (team_ID: {$row['team_ID']}): " . $e->getMessage());
                    }
                }
                
                // 顯示完整專題成果資訊
                // 🔹 【修復】確保學級名稱正確顯示（從資料庫 cohortdata 表動態讀取，不寫死）
                // 先驗證 JOIN 獲取的 cohort_name 是否對應正確的 cohort_ID
                $cohortName = '';
                $actualCohortId = !empty($row['hp_cohort_ID']) ? (string)$row['hp_cohort_ID'] : '';
                
                if (!empty($actualCohortId)) {
                    // 如果 JOIN 獲取的 cohort_name 存在，驗證它是否與 cohort_ID 匹配
                    $joinedCohortName = $row['hp_cohort_name'] ?? '';
                    $joinedYearLabel = $row['hp_year_label'] ?? '';
                    
                    // 重新查詢 cohortdata 表，確保獲取正確的學級資訊
                    try {
                        // 🔹 【修復】使用 CAST 確保類型匹配，避免 108級(cohort_ID=1) 錯誤匹配到 110級(cohort_ID=3)
                        $cohortCheckStmt = $conn->prepare("
                            SELECT cohort_ID, cohort_name, year_label
                            FROM cohortdata
                            WHERE CAST(cohort_ID AS CHAR) = CAST(? AS CHAR) AND cohort_status = 1
                            LIMIT 1
                        ");
                        $cohortCheckStmt->execute([$actualCohortId]);
                        $cohortInfo = $cohortCheckStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($cohortInfo) {
                            // 使用查詢獲取的正確學級資訊
                            if (!empty($cohortInfo['cohort_name']) && $cohortInfo['cohort_name'] !== $actualCohortId . '級') {
                                $cohortName = $cohortInfo['cohort_name'];
                            } elseif (!empty($cohortInfo['year_label'])) {
                                $cohortName = $cohortInfo['year_label'] . '級';
                            } else {
                                $cohortName = $actualCohortId . '級';
                            }
                        } else {
                            // 如果 cohortdata 表中沒有找到，使用默認格式
                            $cohortName = $actualCohortId . '級';
                        }
                    } catch (Exception $e) {
                        // 如果查詢失敗，使用默認格式
                        error_log("查詢 cohortdata 失敗 (cohort_ID: {$actualCohortId}): " . $e->getMessage());
                        $cohortName = $actualCohortId . '級';
                    }
                }
                
                $projects[] = [
                    'prosub_ID' => $row['prosub_ID'],
                    'team_ID' => $row['team_ID'],
                    'hp_project_name' => $row['hp_project_name'],
                    'hp_poster' => $row['hp_poster'],
                    'hp_intro' => $contentJson['intro'] ?? '',
                    'hp_group_ID' => $row['hp_group_ID'] ?? null,
                    'hp_group_name' => $row['hp_group_name'] ?? '',
                    'hp_cohort_ID' => $row['hp_cohort_ID'] ?? null, // 從 teamdata 表獲取，確保正確
                    'hp_cohort_name' => $cohortName, // 動態從資料庫讀取，不寫死
                    'hp_update_d' => $row['hp_update_d'],
                    'team_members' => $teamMembers, // 組員列表（學生）
                    'team_teachers' => $teamTeachers // 指導老師列表
                ];
            }
            
            echo json_encode([
                "success" => true,
                "projects" => $projects,
                "count" => count($projects)
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'get_gallery_detail':
            // ====== 獲取專題詳情（所有角色查看用） ======
            $prosub_ID = isset($_GET['prosub_ID']) ? (int)$_GET['prosub_ID'] : 0;
            
            if ($prosub_ID <= 0) {
                echo json_encode([
                    "success" => false,
                    "message" => "無效的專題ID"
                ]);
                exit;
            }
            
            $sql = "
                SELECT 
                    ps.prosub_ID,
                    ps.team_ID,
                    ps.prosub_img as hp_poster,
                    ps.content_json,
                    ps.prosub_update_d as hp_update_d,
                    ps.prosub_created_d as hp_created_d,
                    t.team_project_name as hp_project_name,
                    t.group_ID as hp_group_ID,
                    g.group_name as hp_group_name,
                    t.cohort_ID as hp_cohort_ID,
                    COALESCE(c.cohort_name, CONCAT(t.cohort_ID, '級')) as hp_cohort_name,
                    c.year_label as hp_year_label
                FROM prosubdata ps
                INNER JOIN teamdata t ON ps.team_ID = t.team_ID
                LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                LEFT JOIN cohortdata c ON CAST(t.cohort_ID AS CHAR) = CAST(c.cohort_ID AS CHAR) 
                    AND c.cohort_status = 1 
                    AND t.cohort_ID IS NOT NULL
                WHERE ps.prosub_ID = ? 
                AND ps.prosub_status = 3
                AND (JSON_EXTRACT(ps.content_json, '$.is_deleted') IS NULL OR JSON_EXTRACT(ps.content_json, '$.is_deleted') = false)
                AND (JSON_EXTRACT(ps.content_json, '$.history_status') = 1)
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$prosub_ID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                echo json_encode([
                    "success" => false,
                    "message" => "找不到專題資料"
                ]);
                exit;
            }
            
            $contentJson = json_decode($row['content_json'] ?? '{}', true);
            
            // 檢查 teammember 表的用戶欄位名稱
            $teamUserField = 'u_ID';
            try {
                $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $checkStmt->execute();
                if ($checkStmt->fetch()) {
                    $teamUserField = 'team_u_ID';
                }
            } catch (Exception $e) {
                // 使用默認值
            }
            
            // 檢查 userrolesdata 表的欄位名稱（只檢查一次，重用）
            $userRoleUidField = 'ur_u_ID'; // 預設為 ur_u_ID（根據資料庫結構）
            try {
                $checkRoleStmt = $conn->prepare("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'");
                $checkRoleStmt->execute();
                if (!$checkRoleStmt->fetch()) {
                    // 如果沒有 ur_u_ID，檢查是否有 user_u_ID
                    $checkRoleStmt2 = $conn->prepare("SHOW COLUMNS FROM userrolesdata LIKE 'user_u_ID'");
                    $checkRoleStmt2->execute();
                    if ($checkRoleStmt2->fetch()) {
                        $userRoleUidField = 'user_u_ID';
                    } else {
                        // 如果都沒有，使用 u_ID
                        $userRoleUidField = 'u_ID';
                    }
                }
            } catch (Exception $e) {
                // 使用默認值 ur_u_ID
                $userRoleUidField = 'ur_u_ID';
            }
            
            // 獲取組員信息（學生）
            $teamMembers = [];
            if (!empty($row['team_ID'])) {
                try {
                    $memberStmt = $conn->prepare("
                        SELECT u.u_ID, u.u_name
                        FROM teammember tm
                        INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
                        WHERE tm.team_ID = ?
                        AND (tm.tm_status IS NULL OR tm.tm_status IN (0, 1))
                        AND (
                            EXISTS (
                                SELECT 1
                                FROM userrolesdata ur
                                WHERE ur.{$userRoleUidField} = u.u_ID
                                  AND ur.role_ID = 6
                            )
                            OR NOT EXISTS (
                                SELECT 1
                                FROM userrolesdata ur2
                                WHERE ur2.{$userRoleUidField} = u.u_ID
                            )
                        )
                        ORDER BY u.u_name
                    ");
                    $memberStmt->execute([$row['team_ID']]);
                    $teamMembers = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    // 記錄錯誤但不中斷流程
                    error_log("獲取組員失敗 (team_ID: {$row['team_ID']}): " . $e->getMessage());
                }
            }
            
            // 獲取指導老師
            $teamTeachers = [];
            if (!empty($row['team_ID'])) {
                try {
                    $teacherStmt = $conn->prepare("
                        SELECT u.u_ID, u.u_name
                        FROM teammember tm
                        INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
                        WHERE tm.team_ID = ?
                        AND (tm.tm_status IS NULL OR tm.tm_status IN (0, 1))
                        AND EXISTS (
                            SELECT 1
                            FROM userrolesdata ur
                            WHERE ur.{$userRoleUidField} = u.u_ID
                              AND ur.role_ID = 4
                        )
                        ORDER BY u.u_name
                    ");
                    $teacherStmt->execute([$row['team_ID']]);
                    $teamTeachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    // 記錄錯誤但不中斷流程
                    error_log("獲取指導老師失敗 (team_ID: {$row['team_ID']}): " . $e->getMessage());
                }
            }
            
            // 🔹 【修復】確保學級名稱正確顯示（從資料庫 cohortdata 表動態讀取，不寫死）
            // 先驗證 JOIN 獲取的 cohort_name 是否對應正確的 cohort_ID
            $cohortName = '';
            $actualCohortId = !empty($row['hp_cohort_ID']) ? (string)$row['hp_cohort_ID'] : '';
            
            if (!empty($actualCohortId)) {
                // 如果 JOIN 獲取的 cohort_name 存在，驗證它是否與 cohort_ID 匹配
                $joinedCohortName = $row['hp_cohort_name'] ?? '';
                $joinedYearLabel = $row['hp_year_label'] ?? '';
                
                // 重新查詢 cohortdata 表，確保獲取正確的學級資訊
                try {
                    // 🔹 【修復】使用 CAST 確保類型匹配，避免 108級(cohort_ID=1) 錯誤匹配到 110級(cohort_ID=3)
                    $cohortCheckStmt = $conn->prepare("
                        SELECT cohort_ID, cohort_name, year_label
                        FROM cohortdata
                        WHERE CAST(cohort_ID AS CHAR) = CAST(? AS CHAR) AND cohort_status = 1
                        LIMIT 1
                    ");
                    $cohortCheckStmt->execute([$actualCohortId]);
                    $cohortInfo = $cohortCheckStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($cohortInfo) {
                        // 使用查詢獲取的正確學級資訊
                        if (!empty($cohortInfo['cohort_name']) && $cohortInfo['cohort_name'] !== $actualCohortId . '級') {
                            $cohortName = $cohortInfo['cohort_name'];
                        } elseif (!empty($cohortInfo['year_label'])) {
                            $cohortName = $cohortInfo['year_label'] . '級';
                        } else {
                            $cohortName = $actualCohortId . '級';
                        }
                    } else {
                        // 如果 cohortdata 表中沒有找到，使用默認格式
                        $cohortName = $actualCohortId . '級';
                    }
                } catch (Exception $e) {
                    // 如果查詢失敗，使用默認格式
                    error_log("查詢 cohortdata 失敗 (cohort_ID: {$actualCohortId}): " . $e->getMessage());
                    $cohortName = $actualCohortId . '級';
                }
            }
            
            echo json_encode([
                "success" => true,
                "project" => [
                    'prosub_ID' => $row['prosub_ID'],
                    'team_ID' => $row['team_ID'],
                    'hp_project_name' => $row['hp_project_name'],
                    'hp_poster' => $row['hp_poster'],
                    'hp_intro' => $contentJson['intro'] ?? '',
                    'hp_group_name' => $row['hp_group_name'] ?? '',
                    'content_json' => $row['content_json'], // 添加 content_json 字段，用於前端判斷是否允許多檔案下載
                    'hp_cohort_ID' => $row['hp_cohort_ID'] ?? null, // 從 teamdata 表獲取，確保正確
                    'hp_cohort_name' => $cohortName, // 動態從資料庫讀取，不寫死
                    'hp_update_d' => $row['hp_update_d'],
                    'team_members' => $teamMembers, // 組員列表（學生）
                    'team_teachers' => $teamTeachers // 指導老師列表
                ]
            ]);
            break;

        case 'get_team_suggest_history':
            // ====== 歷屆專題用：該團隊在該屆的歷次建議（建議表標題、建議內容、審查結果） ======
            $team_ID = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : 0;
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            if ($team_ID <= 0 || $cohort_ID <= 0) {
                echo json_encode(["success" => false, "message" => "缺少 team_ID 或 cohort_ID"]);
                exit;
            }
            try {
                $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                // 確認團隊屬於該屆（歷屆專題只顯示已通過的，團隊可能 team_status=3）
                $stmtCheck = $conn->prepare("SELECT team_ID FROM teamdata WHERE team_ID = ? AND cohort_ID = ? LIMIT 1");
                $stmtCheck->execute([$team_ID, $cohort_ID]);
                if (!$stmtCheck->fetch()) {
                    echo json_encode(["success" => false, "message" => "團隊不存在或非該屆"]);
                    exit;
                }
                $hasSfType = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_type'")->rowCount() > 0;
                $hasSfCohort = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_cohort'")->rowCount() > 0;
                $hasSfCreated = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_created_d'")->rowCount() > 0;
                $orderCol = $hasSfCreated ? "sf.sf_created_d ASC, sf.sf_ID ASC" : "sf.sf_ID ASC";
                if ($hasSfCohort) {
                    $sqlOrder = "SELECT sf.sf_ID FROM suggestfrom sf WHERE sf.sf_cohort = ?";
                    if ($hasSfType) $sqlOrder .= " AND (sf.sf_type = 'review' OR sf.sf_type IS NULL)";
                    $sqlOrder .= " AND sf.sf_name IS NOT NULL AND TRIM(sf.sf_name) != '' ORDER BY " . $orderCol;
                    $stmtOrder = $conn->prepare($sqlOrder);
                    $stmtOrder->execute([$cohort_ID]);
                } else {
                    $sqlOrder = "SELECT DISTINCT sf.sf_ID FROM suggestfrom sf INNER JOIN suggest s ON sf.sf_ID = s.sf_ID INNER JOIN teamdata t ON s.team_ID = t.team_ID
                                 WHERE t.cohort_ID = ? AND sf.sf_name IS NOT NULL AND TRIM(sf.sf_name) != ''";
                    if ($hasSfType) $sqlOrder .= " AND (sf.sf_type = 'review' OR sf.sf_type IS NULL)";
                    $sqlOrder .= " ORDER BY " . $orderCol;
                    $stmtOrder = $conn->prepare($sqlOrder);
                    $stmtOrder->execute([$cohort_ID]);
                }
                $orderedSfIds = [];
                while ($r = $stmtOrder->fetch(PDO::FETCH_ASSOC)) {
                    $orderedSfIds[] = (int)$r["sf_ID"];
                }
                if (empty($orderedSfIds)) {
                    echo json_encode(["success" => true, "data" => []], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $placeholders = implode(",", array_fill(0, count($orderedSfIds), "?"));
                $orderBySf = $hasSfCreated ? "sf.sf_created_d ASC, sf.sf_ID ASC" : "sf.sf_ID ASC";
                $sql = "SELECT s.suggest_ID, s.suggest_comment, s.suggest_status, sf.sf_name" . ($hasSfType ? ", sf.sf_type" : "") . "
                        FROM suggest s
                        LEFT JOIN suggestfrom sf ON s.sf_ID = sf.sf_ID
                        WHERE s.team_ID = ? AND s.suggest_status IN (1, 2, 3, 4) AND s.sf_ID IN ($placeholders)";
                if ($hasSfType) {
                    $sql .= " AND (sf.sf_type = 'review' OR sf.sf_type IS NULL)";
                }
                $sql .= " ORDER BY " . $orderBySf;
                $stmt = $conn->prepare($sql);
                $stmt->execute(array_merge([$team_ID], $orderedSfIds));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $out = [];
                foreach ($rows as $row) {
                    $status_code = (int)($row["suggest_status"] ?? 0);
                    $is_topic = $hasSfType && isset($row["sf_type"]) && $row["sf_type"] === "topic";
                    $status_text = "—";
                    if ($status_code == 1) $status_text = $is_topic ? "修改" : "修改後通過";
                    elseif ($status_code == 2) $status_text = "不通過";
                    elseif ($status_code == 3) $status_text = "通過";
                    elseif ($status_code == 4) $status_text = $is_topic ? "待確認" : "修改後複評";
                    $out[] = [
                        "title" => $row["sf_name"] ?? "（未命名）",
                        "comment" => $row["suggest_comment"] ?? "",
                        "status" => $status_text,
                    ];
                }
                echo json_encode(["success" => true, "data" => $out], JSON_UNESCAPED_UNICODE);
            } catch (Exception $e) {
                error_log("get_team_suggest_history: " . $e->getMessage());
                echo json_encode(["success" => false, "message" => "取得歷次建議失敗"], JSON_UNESCAPED_UNICODE);
            }
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
