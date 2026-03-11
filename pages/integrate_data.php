<?php
session_start();
require "../includes/pdo.php";
header("Content-Type: application/json; charset=utf-8");

date_default_timezone_set("Asia/Taipei");

/* ==========================================
   權限：主任 (role_ID = 1)、科辦 (role_ID = 2) 和 召集人 (role_ID = 7)
========================================== */
$role_ID = $_SESSION["role_ID"] ?? null;
if (!isset($role_ID) || !in_array($role_ID, [1, 2, 7])) {
    respond(["ok" => false, "msg" => "無權限"]);
}

// 判斷是否為召集人
$isConvener = ($role_ID == 7);
$isOffice = ($role_ID == 2);

// 獲取當前用戶的角色名稱（從 session 獲取）
$role_name = $_SESSION["role_name"] ?? "系統";

$u_ID = $_SESSION["u_ID"];
$action = $_GET["action"] ?? $_POST["action"] ?? "";

/* 回傳格式統一（確保永遠回傳乾淨 JSON） */
if (!function_exists('respond')) {
    function respond(array $arr) {
        // 清掉所有輸出 buffer，避免 Notice / Warning 混入
        while (ob_get_level()) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header("Content-Type: application/json; charset=utf-8");
        }
        echo json_encode($arr, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* PDO 錯誤 */
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ==========================================
   action: getCohorts
   取得啟用中屆別
========================================== */
if ($action === "getCohorts") {
    try {
        $sql = "SELECT cohort_ID, cohort_name, year_label
                FROM cohortdata
                WHERE cohort_status = 1
                ORDER BY cohort_ID DESC";
        
        $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        respond(["ok" => true, "data" => $rows]);
    } catch (Throwable $e) {
        respond(["ok" => false, "msg" => "獲取屆別失敗：" . $e->getMessage()]);
    }
}

/* ==========================================
   action: searchTitles
   根據屆別和格式查詢標題列表
========================================== */
if ($action === "searchTitles") {
    try {
        $cohort_ID = $_GET["cohort_ID"] ?? null;
        $format = $_GET["format"] ?? "";
        $keyword = $_GET["keyword"] ?? "";
        
        $titles = [];
        
        // 時程表或全部（召集人不能看到時程表）
        if (($format === "時程表" || $format === "全部") && !$isConvener) {
            // 檢查是否有 tinforma_title 欄位
            $hasTitleField = false;
            try {
                $checkStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_title'");
                $hasTitleField = $checkStmt->rowCount() > 0;
            } catch (Throwable $e) {
                $hasTitleField = false;
            }
            
            if ($hasTitleField) {
                // 通過 timedata 關聯到 teamdata，再關聯到 cohort_ID
                $sql = "SELECT DISTINCT ti.tinforma_title as title
                        FROM timeinformadata ti
                        JOIN timedata td ON ti.tinforma_ID = td.tinforma_ID
                        JOIN teamdata t ON td.team_ID = t.team_ID
                        WHERE t.team_status = 1
                          AND ti.tinforma_title IS NOT NULL
                          AND TRIM(ti.tinforma_title) != ''";
                
                $params = [];
                if ($cohort_ID) {
                    $sql .= " AND t.cohort_ID = ?";
                    $params[] = $cohort_ID;
                }
                
                if ($keyword) {
                    $sql .= " AND ti.tinforma_title LIKE ?";
                    $params[] = "%" . $keyword . "%";
                }
                
                $sql .= " ORDER BY COALESCE(ti.tinforma_update_d, ti.tinforma_create_d) DESC";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                $scheduleTitles = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $titles = array_merge($titles, $scheduleTitles);
            }
        }
        
        // 審查建議表、題目初審建議表或全部（以 sf_type 區分：review=審查建議表, topic=題目初審建議表）
        $suggestFormatFilter = null;
        if ($format === "審查建議表") {
            $suggestFormatFilter = "review";
        } elseif ($format === "初審建議表" || $format === "初審建議表") {
            $suggestFormatFilter = "topic";
        }
        if ($suggestFormatFilter !== null || $format === "全部") {
            $hasSfType = false;
            $hasSfSentToOffice = false;
            try {
                $checkSfType = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_type'");
                $hasSfType = $checkSfType->rowCount() > 0;
                $checkSent = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_sent_to_office'");
                $hasSfSentToOffice = $checkSent->rowCount() > 0;
                if (!$hasSfSentToOffice) {
                    try {
                        $conn->exec("ALTER TABLE suggestfrom ADD COLUMN sf_sent_to_office TINYINT(1) DEFAULT 0 COMMENT '0=未送交科辦(僅召集人可見) 1=已送交科辦'");
                        $hasSfSentToOffice = true;
                    } catch (Throwable $e) {
                        error_log('integrate_data searchTitles 添加 sf_sent_to_office 失敗: ' . $e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                $hasSfType = false;
                $hasSfSentToOffice = false;
            }
            $sql = "SELECT DISTINCT sf.sf_name as title
                    FROM suggestfrom sf
                    JOIN suggest s ON sf.sf_ID = s.sf_ID
                    JOIN teamdata t ON s.team_ID = t.team_ID
                    WHERE s.suggest_status IN (1, 2, 3, 4)
                      AND t.team_status = 1
                      AND sf.sf_name IS NOT NULL
                      AND TRIM(sf.sf_name) != ''";
            if ($hasSfType && $suggestFormatFilter !== null) {
                $sql .= " AND (sf.sf_type = ? OR (sf.sf_type IS NULL AND ? = 'review'))";
            }
            // 科辦：審查建議表只顯示「已送交科辦」的（sf_sent_to_office 為 1 或召集人帳號）；0/空僅召集人可見
            if ($isOffice && $hasSfSentToOffice) {
                $sql .= " AND (sf.sf_type != 'review' OR sf.sf_type IS NULL OR (TRIM(COALESCE(sf.sf_sent_to_office,'')) NOT IN ('','0')))";
            }
            $params = [];
            if ($cohort_ID) {
                $sql .= " AND t.cohort_ID = ?";
                $params[] = $cohort_ID;
            }
            if ($keyword) {
                $sql .= " AND sf.sf_name LIKE ?";
                $params[] = "%" . $keyword . "%";
            }
            if ($hasSfType && $suggestFormatFilter !== null) {
                $params[] = $suggestFormatFilter;
                $params[] = $suggestFormatFilter;
            }
            $sql .= " ORDER BY sf.sf_update_d DESC, sf.sf_created_d DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $suggestTitles = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $titles = array_merge($titles, $suggestTitles);
        }
        
        // 去重並過濾空值
        $titles = array_values(array_unique(array_filter($titles)));
        
        respond(["ok" => true, "data" => $titles]);
    } catch (Throwable $e) {
        respond(["ok" => false, "msg" => "查詢標題失敗：" . $e->getMessage()]);
    }
}

/* ==========================================
   action: create
   建立時程表或建議表
========================================== */
if ($action === "create") {
    try {
        $cohort_ID = $_POST["cohort"] ?? null;
        $title = trim($_POST["title"] ?? "");
        $format = $_POST["format"] ?? "";
        
        if (!$cohort_ID || !$title || !$format) {
            respond(["ok" => false, "msg" => "請填寫完整資料"]);
        }
        
        if ($format === "全部") {
            respond(["ok" => false, "msg" => "資料類型為「全部」時無法建立資料，僅供查詢"]);
        }
        
        // 科辦的權限只能建立時程表；審查建議表、初審建議表僅能察看與編輯
        if ($isOffice && ($format === "審查建議表" || $format === "初審建議表")) {
            respond(["ok" => false, "msg" => "科辦的權限只能建立時程表"]);
        }
        
        // 驗證屆別是否存在
        $stmt = $conn->prepare("SELECT cohort_ID FROM cohortdata WHERE cohort_ID = ? AND cohort_status = 1");
        $stmt->execute([$cohort_ID]);
        if (!$stmt->fetch()) {
            respond(["ok" => false, "msg" => "屆別不存在或已停用"]);
        }
        
        $conn->beginTransaction();
        
        if ($format === "時程表") {
            // 時程表只有科辦可以建立
            if ($isConvener) {
                respond(["ok" => false, "msg" => "時程表只有科辦可以建立"]);
            }
            
            // 檢查是否有 tinforma_title 欄位
            $hasTitleField = false;
            try {
                $checkStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_title'");
                $hasTitleField = $checkStmt->rowCount() > 0;
            } catch (Throwable $e) {
                // 如果沒有欄位，嘗試添加
                try {
                    $conn->exec("ALTER TABLE timeinformadata ADD COLUMN tinforma_title VARCHAR(255) DEFAULT NULL COMMENT '時程表標題' AFTER tinforma_ID");
                    $hasTitleField = true;
                } catch (Throwable $e2) {
                    $hasTitleField = false;
                }
            }
            
            if (!$hasTitleField) {
                $conn->rollBack();
                respond(["ok" => false, "msg" => "時程表標題欄位不存在且無法創建"]);
            }
            
            // 檢查是否有屆別欄位
            $hasCohortField = false;
            try {
                $checkCohortStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_cohort'");
                $hasCohortField = $checkCohortStmt->rowCount() > 0;
            } catch (Throwable $e) {
                // 嘗試添加屆別欄位
                try {
                    $conn->exec("ALTER TABLE timeinformadata ADD COLUMN tinforma_cohort INT DEFAULT NULL COMMENT '屆別' AFTER tinforma_title");
                    $hasCohortField = true;
                } catch (Throwable $e2) {
                    $hasCohortField = false;
                }
            }
            
            // 檢查是否有建立人欄位
            $hasCreateUserField = false;
            try {
                $checkCreateStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_create_u_ID'");
                $hasCreateUserField = $checkCreateStmt->rowCount() > 0;
            } catch (Throwable $e) {
                // 嘗試添加建立人欄位
                try {
                    $conn->exec("ALTER TABLE timeinformadata ADD COLUMN tinforma_create_u_ID VARCHAR(25) DEFAULT NULL COMMENT '建立人' AFTER tinforma_title");
                    $hasCreateUserField = true;
                } catch (Throwable $e2) {
                    $hasCreateUserField = false;
                }
            }
            
            // 檢查是否有建立時角色ID欄位
            $hasCreateRoleField = false;
            try {
                $checkRoleStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_create_role_ID'");
                $hasCreateRoleField = $checkRoleStmt->rowCount() > 0;
            } catch (Throwable $e) {
                // 嘗試添加建立時角色ID欄位
                try {
                    $conn->exec("ALTER TABLE timeinformadata ADD COLUMN tinforma_create_role_ID INT DEFAULT NULL COMMENT '建立時的角色ID' AFTER tinforma_create_u_ID");
                    $hasCreateRoleField = true;
                } catch (Throwable $e2) {
                    $hasCreateRoleField = false;
                }
            }
            
            // 檢查是否已存在相同標題的時程表（如果有屆別欄位，也要檢查屆別）
            if ($hasCohortField) {
                $checkStmt = $conn->prepare("SELECT tinforma_ID FROM timeinformadata WHERE tinforma_title = ? AND tinforma_cohort = ? LIMIT 1");
                $checkStmt->execute([$title, $cohort_ID]);
            } else {
                $checkStmt = $conn->prepare("SELECT tinforma_ID FROM timeinformadata WHERE tinforma_title = ? LIMIT 1");
                $checkStmt->execute([$title]);
            }
            if ($checkStmt->fetch()) {
                $conn->rollBack();
                respond(["ok" => false, "msg" => "該屆別下已存在相同標題的時程表"]);
            }
            
            // 創建新的時程表
            // 時程表一定是科辦建立的，必須記錄建立人
            $insertFields = ["tinforma_title", "tinforma_content"];
            $insertValues = [$title, ""];
            
            // 如果字段不存在，先創建字段
            if (!$hasCreateUserField) {
                try {
                    $conn->exec("ALTER TABLE timeinformadata ADD COLUMN tinforma_create_u_ID VARCHAR(25) DEFAULT NULL COMMENT '建立人' AFTER tinforma_title");
                    $hasCreateUserField = true;
                } catch (Throwable $e2) {
                    // 如果創建失敗，記錄錯誤但繼續執行
                    error_log("無法創建 tinforma_create_u_ID 欄位: " . $e2->getMessage());
                }
            }
            
            // 記錄建立人（當前登錄的科辦用戶）
            if ($hasCreateUserField) {
                $insertFields[] = "tinforma_create_u_ID";
                $insertValues[] = $u_ID;
            }
            
            // 記錄建立時的角色ID（從 session 獲取）
            if ($hasCreateRoleField && $role_ID) {
                $insertFields[] = "tinforma_create_role_ID";
                $insertValues[] = $role_ID;
            }
            
            if ($hasCohortField) {
                $insertFields[] = "tinforma_cohort";
                $insertValues[] = $cohort_ID;
            }
            
            $fieldsStr = implode(", ", $insertFields);
            $placeholders = str_repeat("?,", count($insertValues) - 1) . "?";
            $stmt = $conn->prepare("INSERT INTO timeinformadata ({$fieldsStr}) VALUES ({$placeholders})");
            $stmt->execute($insertValues);
            $tinforma_ID = $conn->lastInsertId();
            
            $conn->commit();
            respond(["ok" => true, "msg" => "時程表建立成功", "id" => $tinforma_ID]);
            
        } elseif ($format === "審查建議表" || $format === "初審建議表" || $format === "初審建議表") {
            // 初審建議表只有召集人可以建立
            if ($format === "初審建議表" && !$isConvener) {
                $conn->rollBack();
                respond(["ok" => false, "msg" => "只有召集人可以建立初審建議表"]);
            }
            // sf_type: review=審查建議表（必綁 tinforma）, topic=題目初審建議表/初審建議表（不綁 tinforma）
            $sf_type = ($format === "審查建議表") ? "review" : "topic";

            // 檢查 sf_type 欄位，若不存在則新增以確保建立時能正確寫入類型
            $hasSfType = false;
            try {
                $checkSfType = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_type'");
                $hasSfType = $checkSfType->rowCount() > 0;
                if (!$hasSfType) {
                    $conn->exec("ALTER TABLE suggestfrom ADD COLUMN sf_type VARCHAR(20) DEFAULT NULL COMMENT 'review=審查建議表,topic=初審建議表' AFTER sf_name");
                    $hasSfType = true;
                }
            } catch (Throwable $e) {
                $hasSfType = false;
            }
            
            // 審查建議表必綁 tinforma：檢查/建立 sf_tinforma_ID 欄位並取得該屆最新時程表
            $tinforma_ID = null;
            if ($sf_type === "review") {
                try {
                    $checkTinforma = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_tinforma_ID'");
                    if ($checkTinforma->rowCount() === 0) {
                        try {
                            $conn->exec("ALTER TABLE suggestfrom ADD COLUMN sf_tinforma_ID INT DEFAULT NULL COMMENT '綁定時程表ID' AFTER sf_type");
                        } catch (Throwable $e2) { /* 忽略 */ }
                    }
                    $checkTinforma = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_tinforma_ID'");
                    if ($checkTinforma->rowCount() > 0) {
                        $tinformaStmt = $conn->prepare("
                            SELECT ti.tinforma_ID FROM timeinformadata ti
                            JOIN timedata td ON ti.tinforma_ID = td.tinforma_ID
                            JOIN teamdata t ON td.team_ID = t.team_ID
                            WHERE t.cohort_ID = ?
                            ORDER BY COALESCE(ti.tinforma_update_d, ti.tinforma_create_d) DESC
                            LIMIT 1
                        ");
                        $tinformaStmt->execute([$cohort_ID]);
                        $row = $tinformaStmt->fetch(PDO::FETCH_ASSOC);
                        $tinforma_ID = $row ? (int)$row["tinforma_ID"] : null;
                    }
                } catch (Throwable $e) {
                    $tinforma_ID = null;
                }
            }
            
            // 檢查是否有屆別欄位
            $hasCohortField = false;
            try {
                $checkCohortStmt = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_cohort'");
                $hasCohortField = $checkCohortStmt->rowCount() > 0;
            } catch (Throwable $e) {
                try {
                    $conn->exec("ALTER TABLE suggestfrom ADD COLUMN sf_cohort INT DEFAULT NULL COMMENT '屆別' AFTER sf_ID");
                    $hasCohortField = true;
                } catch (Throwable $e2) {
                    $hasCohortField = false;
                }
            }
            
            // 檢查是否有建立時角色ID欄位
            $hasCreateRoleField = false;
            try {
                $checkRoleStmt = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_create_role_ID'");
                $hasCreateRoleField = $checkRoleStmt->rowCount() > 0;
            } catch (Throwable $e) {
                try {
                    $conn->exec("ALTER TABLE suggestfrom ADD COLUMN sf_create_role_ID INT DEFAULT NULL COMMENT '建立時的角色ID' AFTER sf_u_ID");
                    $hasCreateRoleField = true;
                } catch (Throwable $e2) {
                    $hasCreateRoleField = false;
                }
            }
            
            // 檢查是否已存在相同標題的建議表（如果有屆別欄位，也要檢查屆別）
            if ($hasCohortField) {
                $checkStmt = $conn->prepare("SELECT sf_ID FROM suggestfrom WHERE sf_name = ? AND sf_cohort = ? LIMIT 1");
                $checkStmt->execute([$title, $cohort_ID]);
            } else {
                $checkStmt = $conn->prepare("SELECT sf_ID FROM suggestfrom WHERE sf_name = ? LIMIT 1");
                $checkStmt->execute([$title]);
            }
            if ($checkStmt->fetch()) {
                $conn->rollBack();
                respond(["ok" => false, "msg" => "該屆別下已存在相同標題的建議表"]);
            }
            
            // 審查建議表可選擇綁定時程表：有綁定則團隊名單來自時程表；未綁定則團隊名單來自該屆/類組（與題目初審建議表相同）
            if ($sf_type === "review" && $tinforma_ID === null) {
                // 不再強制要求：允許建立不綁時程表的審查建議表，團隊將依屆別/類組列出
                // （若需依時程表順序與團隊，請先建立該屆的時程表後再建立建議表）
            }
            
            // 創建新的建議表
            $insertFields = ["sf_name", "sf_u_ID"];
            $insertValues = [$title, $u_ID];
            
            if ($hasSfType) {
                $insertFields[] = "sf_type";
                $insertValues[] = $sf_type;
            }
            
            $hasTinformaField = false;
            try {
                $checkTinformaCol = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_tinforma_ID'");
                $hasTinformaField = $checkTinformaCol->rowCount() > 0;
            } catch (Throwable $e) {
                $hasTinformaField = false;
            }
            if ($hasTinformaField && $tinforma_ID !== null) {
                $insertFields[] = "sf_tinforma_ID";
                $insertValues[] = $tinforma_ID;
            }
            
            if ($hasCreateRoleField && $role_ID) {
                $insertFields[] = "sf_create_role_ID";
                $insertValues[] = $role_ID;
            }
            
            if ($hasCohortField) {
                $insertFields[] = "sf_cohort";
                $insertValues[] = $cohort_ID;
            }
            
            $insertFields[] = "sf_created_d";
            $insertFields[] = "sf_update_d";
            
            $fieldsStr = implode(", ", $insertFields);
            $valuePlaceholders = str_repeat("?,", count($insertValues));
            $valuePlaceholders = rtrim($valuePlaceholders, ",");
            $placeholders = $valuePlaceholders . ", NOW(), NOW()";
            $stmt = $conn->prepare("INSERT INTO suggestfrom ({$fieldsStr}) VALUES ({$placeholders})");
            $stmt->execute($insertValues);
            $sf_ID = $conn->lastInsertId();
            
            $conn->commit();
            $msg = ($format === "審查建議表") ? "審查建議表建立成功" : "初審建議表建立成功";
            respond(["ok" => true, "msg" => $msg, "id" => $sf_ID]);
        } else {
            $conn->rollBack();
            respond(["ok" => false, "msg" => "未知的資料類型（請選擇：初審建議表、審查建議表或時程表）"]);
        }
        
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        respond(["ok" => false, "msg" => "建立失敗：" . $e->getMessage()]);
    }
}

/* ==========================================
   action: list
   查詢列表（不建立資料）
   建立人顯示為角色名稱（從 userrolesdata 和 roledata 關聯查詢）
========================================== */
if ($action === "list") {
    try {
        $cohort_ID = $_GET["cohort"] ?? null;
        $title = trim($_GET["title"] ?? "");
        $format = $_GET["format"] ?? "";
        
        $results = [];
        
        // 檢查 userrolesdata 表使用哪個欄位名稱
        $userRoleUidField = 'u_ID';
        try {
            $checkStmt = $conn->query("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'");
            if ($checkStmt->rowCount() > 0) {
                $userRoleUidField = 'ur_u_ID';
            }
        } catch (Throwable $e) {
            $userRoleUidField = 'u_ID';
        }
        
        // 時程表或全部（召集人不能看到時程表）
        if (($format === "時程表" || $format === "全部" || $format === "") && !$isConvener) {
            // 檢查是否有 tinforma_title 欄位
            $hasTitleField = false;
            try {
                $checkStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_title'");
                $hasTitleField = $checkStmt->rowCount() > 0;
            } catch (Throwable $e) {
                $hasTitleField = false;
            }
            
            if ($hasTitleField) {
                // 檢查是否有建立人和編輯人欄位
                $hasCreateUserField = false;
                $hasCreateRoleField = false;
                $hasUpdateUserField = false;
                $hasCohortField = false;
                try {
                    $checkCreateStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_create_u_ID'");
                    $hasCreateUserField = $checkCreateStmt->rowCount() > 0;
                    $checkCreateRoleStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_create_role_ID'");
                    $hasCreateRoleField = $checkCreateRoleStmt->rowCount() > 0;
                    $checkUpdateStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_update_u_ID'");
                    $hasUpdateUserField = $checkUpdateStmt->rowCount() > 0;
                    $checkCohortStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_cohort'");
                    $hasCohortField = $checkCohortStmt->rowCount() > 0;
                } catch (Throwable $e) {
                    $hasCreateUserField = false;
                    $hasCreateRoleField = false;
                    $hasUpdateUserField = false;
                    $hasCohortField = false;
                }
                
                // 優化查詢：如果有屆別欄位，優先使用；否則使用關聯查詢
                // 初始化參數數組
                $params = [];
                
                if ($hasCohortField) {
                    // 直接使用 tinforma_cohort 欄位
                    $sql = "SELECT 
                                ti.tinforma_ID,
                                ti.tinforma_title as title,
                                ti.tinforma_cohort as cohort_ID,
                                c.cohort_name,
                                '時程表' as format_type,
                                ti.tinforma_create_d,
                                COALESCE(ti.tinforma_update_d, ti.tinforma_create_d) as update_date";
                    
                    // 建立人：顯示用戶姓名（從 userdata 表獲取）
                    // 如果找不到建立人，使用當前登錄的科辦用戶（時程表一定是科辦建立的）
                    if ($hasCreateUserField) {
                        $sql .= ", COALESCE((
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ti.tinforma_create_u_ID
                        ), (
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ?
                        ), '系統') as creator";
                        // 將當前登錄用戶ID加入參數
                        $params[] = $u_ID;
                    } else {
                        // 如果字段不存在，使用當前登錄的科辦用戶
                        $sql .= ", COALESCE((
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ?
                        ), '系統') as creator";
                        $params[] = $u_ID;
                    }
                    
                    // 最後編輯人：顯示用戶姓名（從 userdata 表獲取）
                    // 如果找不到編輯人，使用建立人或當前登錄的科辦用戶
                    if ($hasUpdateUserField) {
                        $sql .= ", COALESCE((
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ti.tinforma_update_u_ID
                        )";
                        if ($hasCreateUserField) {
                            $sql .= ", (
                                SELECT u.u_name 
                                FROM userdata u
                                WHERE u.u_ID = ti.tinforma_create_u_ID
                            )";
                        }
                        $sql .= ", (
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ?
                        ), '系統') as editor";
                        $params[] = $u_ID;
                    } else if ($hasCreateUserField) {
                        $sql .= ", COALESCE((
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ti.tinforma_create_u_ID
                        ), (
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ?
                        ), '系統') as editor";
                        $params[] = $u_ID;
                    } else {
                        $sql .= ", COALESCE((
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ?
                        ), '系統') as editor";
                        $params[] = $u_ID;
                    }
                    
                    $sql .= " FROM timeinformadata ti
                            LEFT JOIN cohortdata c ON ti.tinforma_cohort = c.cohort_ID
                            WHERE ti.tinforma_title IS NOT NULL
                              AND TRIM(ti.tinforma_title) != ''";
                    
                    if ($cohort_ID) {
                        $sql .= " AND ti.tinforma_cohort = ?";
                        $params[] = $cohort_ID;
                    }
                    
                    if ($title) {
                        $sql .= " AND ti.tinforma_title LIKE ?";
                        $params[] = "%" . $title . "%";
                    }
                    
                    $sql .= " GROUP BY ti.tinforma_ID
                              ORDER BY update_date DESC";
                } else {
                    // 使用關聯查詢（原有邏輯）
                    $sql = "SELECT 
                                ti.tinforma_ID,
                                ti.tinforma_title as title,
                                MIN(t.cohort_ID) as cohort_ID,
                                MIN(c.cohort_name) as cohort_name,
                                '時程表' as format_type,
                                ti.tinforma_create_d,
                                COALESCE(ti.tinforma_update_d, ti.tinforma_create_d) as update_date";
                    
                    // 建立人：顯示用戶姓名（從 userdata 表獲取）
                    // 如果找不到建立人，使用當前登錄的科辦用戶（時程表一定是科辦建立的）
                    if ($hasCreateUserField) {
                        $sql .= ", COALESCE((
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ti.tinforma_create_u_ID
                        ), (
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ?
                        ), '系統') as creator";
                        // 將當前登錄用戶ID加入參數
                        $params[] = $u_ID;
                    } else {
                        // 如果字段不存在，使用當前登錄的科辦用戶
                        $sql .= ", COALESCE((
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ?
                        ), '系統') as creator";
                        $params[] = $u_ID;
                    }
                    
                    // 最後編輯人：顯示用戶姓名（從 userdata 表獲取）
                    // 如果找不到編輯人，使用建立人或當前登錄的科辦用戶
                    if ($hasUpdateUserField) {
                        $sql .= ", COALESCE((
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ti.tinforma_update_u_ID
                        )";
                        if ($hasCreateUserField) {
                            $sql .= ", (
                                SELECT u.u_name 
                                FROM userdata u
                                WHERE u.u_ID = ti.tinforma_create_u_ID
                            )";
                        }
                        $sql .= ", (
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ?
                        ), '系統') as editor";
                        $params[] = $u_ID;
                    } else if ($hasCreateUserField) {
                        $sql .= ", COALESCE((
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ti.tinforma_create_u_ID
                        ), (
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ?
                        ), '系統') as editor";
                        $params[] = $u_ID;
                    } else {
                        $sql .= ", COALESCE((
                            SELECT u.u_name 
                            FROM userdata u
                            WHERE u.u_ID = ?
                        ), '系統') as editor";
                        $params[] = $u_ID;
                    }
                    
                    $sql .= " FROM timeinformadata ti
                            INNER JOIN timedata td ON ti.tinforma_ID = td.tinforma_ID
                            INNER JOIN teamdata t ON td.team_ID = t.team_ID
                            LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                            WHERE t.team_status = 1
                              AND ti.tinforma_title IS NOT NULL
                              AND TRIM(ti.tinforma_title) != ''";
                    
                    if ($cohort_ID) {
                        $sql .= " AND t.cohort_ID = ?";
                        $params[] = $cohort_ID;
                    }
                    
                    if ($title) {
                        $sql .= " AND ti.tinforma_title LIKE ?";
                        $params[] = "%" . $title . "%";
                    }
                    
                    $sql .= " GROUP BY ti.tinforma_ID
                              ORDER BY update_date DESC";
                }
                
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                $scheduleRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($scheduleRows as $row) {
                    // 獲取建立時間
                    $createTime = null;
                    if (isset($row["tinforma_create_d"]) && $row["tinforma_create_d"] !== null && $row["tinforma_create_d"] !== '') {
                        $timestamp = strtotime($row["tinforma_create_d"]);
                        if ($timestamp !== false && $timestamp > 0) {
                            $createTime = date('Y-m-d H:i', $timestamp);
                        }
                    }
                    
                    // 獲取編輯時間
                    $editTime = null;
                    if (isset($row["update_date"]) && $row["update_date"] !== null && $row["update_date"] !== '') {
                        $timestamp = strtotime($row["update_date"]);
                        if ($timestamp !== false && $timestamp > 0) {
                            $editTime = date('Y-m-d H:i', $timestamp);
                        }
                    }
                    
                    $results[] = [
                        "id" => $row["tinforma_ID"],
                        "title" => $row["title"],
                        "cohort" => $row["cohort_name"] ?: $row["cohort_ID"],
                        "cohort_ID" => $row["cohort_ID"],
                        "format" => "時程表",
                        "creator" => $row["creator"] ?: "系統",
                        "create_time" => $createTime,
                        "editor" => $row["editor"] ?: ($row["creator"] ?: "系統"),
                        "edit_time" => $editTime
                    ];
                }
            }
        }
        
        // 審查建議表、題目初審建議表或全部（以 sf_type 區分，不依時程表判斷類型）
        $suggestFormatFilter = null;
        if ($format === "審查建議表") {
            $suggestFormatFilter = "review";
        } elseif ($format === "初審建議表" || $format === "初審建議表") {
            $suggestFormatFilter = "topic";
        }
        if ($suggestFormatFilter !== null || $format === "全部" || $format === "") {
            // 檢查是否有屆別、sf_type、sf_sent_to_office 欄位
            $hasCohortField = false;
            $hasCreateRoleField = false;
            $hasSfType = false;
            $hasSfSentToOffice = false;
            try {
                $checkCohortStmt = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_cohort'");
                $hasCohortField = $checkCohortStmt->rowCount() > 0;
                $checkCreateRoleStmt = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_create_role_ID'");
                $hasCreateRoleField = $checkCreateRoleStmt->rowCount() > 0;
                $checkSfType = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_type'");
                $hasSfType = $checkSfType->rowCount() > 0;
                $checkSent = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_sent_to_office'");
                $hasSfSentToOffice = $checkSent->rowCount() > 0;
                if (!$hasSfSentToOffice) {
                    try {
                        $conn->exec("ALTER TABLE suggestfrom ADD COLUMN sf_sent_to_office TINYINT(1) DEFAULT 0 COMMENT '0=未送交科辦(僅召集人可見) 1=已送交科辦'");
                        $hasSfSentToOffice = true;
                    } catch (Throwable $e2) {
                        error_log('integrate_data list 添加 sf_sent_to_office 失敗: ' . $e2->getMessage());
                    }
                }
            } catch (Throwable $e) {
                $hasCohortField = false;
                $hasCreateRoleField = false;
                $hasSfType = false;
                $hasSfSentToOffice = false;
            }
            
            // 顯示用：依 sf_type 回傳 審查建議表 / 題目初審建議表（NULL 視為 review 以相容舊資料）
            $formatTypeExpr = $hasSfType
                ? "COALESCE(NULLIF(sf.sf_type,''), 'review')"
                : "'review'";
            
            // 如果有屆別欄位，優先使用；否則使用關聯查詢
            if ($hasCohortField) {
                // 直接使用 sf_cohort 欄位
                $sql = "SELECT 
                            sf.sf_ID,
                            sf.sf_name as title,
                            sf.sf_cohort as cohort_ID,
                            c.cohort_name,
                            (CASE WHEN " . $formatTypeExpr . " = 'topic' THEN '初審建議表' ELSE '審查建議表' END) as format_type,
                            sf.sf_created_d,
                            COALESCE((
                                SELECT u.u_name 
                                FROM userdata u
                                WHERE u.u_ID = sf.sf_u_ID
                            ), '系統') as creator,
                            COALESCE((
                                SELECT u2.u_name 
                                FROM suggest s2 
                                JOIN userdata u2 ON s2.suggest_u_ID = u2.u_ID
                                WHERE s2.sf_ID = sf.sf_ID 
                                  AND s2.suggest_status IN (1, 2, 3, 4)
                                ORDER BY s2.suggest_d DESC
                                LIMIT 1
                            ), (
                                SELECT u.u_name 
                                FROM userdata u
                                WHERE u.u_ID = sf.sf_u_ID
                            ), '系統') as editor,
                            COALESCE(
                                (SELECT MAX(s2.suggest_d) FROM suggest s2 WHERE s2.sf_ID = sf.sf_ID AND s2.suggest_status IN (1, 2, 3, 4)),
                                sf.sf_update_d,
                                sf.sf_created_d
                            ) as update_date
                        FROM suggestfrom sf
                        LEFT JOIN cohortdata c ON sf.sf_cohort = c.cohort_ID
                        WHERE sf.sf_name IS NOT NULL
                          AND TRIM(sf.sf_name) != ''";
                
                $params = [];
                if ($cohort_ID) {
                    $sql .= " AND sf.sf_cohort = ?";
                    $params[] = $cohort_ID;
                }
                if ($title) {
                    $sql .= " AND sf.sf_name LIKE ?";
                    $params[] = "%" . $title . "%";
                }
                if ($hasSfType && $suggestFormatFilter !== null) {
                    $sql .= " AND (sf.sf_type = ? OR (sf.sf_type IS NULL AND ? = 'review'))";
                    $params[] = $suggestFormatFilter;
                    $params[] = $suggestFormatFilter;
                }
                // 科辦：審查建議表只顯示「已送交科辦」的（sf_sent_to_office 為 1 或召集人帳號）；0/空僅召集人可見
                if ($isOffice && $hasSfSentToOffice) {
                    if ($hasSfType) {
                        $sql .= " AND (sf.sf_type != 'review' OR sf.sf_type IS NULL OR (TRIM(COALESCE(sf.sf_sent_to_office,'')) NOT IN ('','0')))";
                    } else {
                        $sql .= " AND (TRIM(COALESCE(sf.sf_sent_to_office,'')) NOT IN ('','0'))";
                    }
                }
                $sql .= " GROUP BY sf.sf_ID, sf.sf_name, sf.sf_cohort, c.cohort_name, sf.sf_u_ID, sf.sf_update_d, sf.sf_created_d" . ($hasSfType ? ", sf.sf_type" : "") . "
                          ORDER BY update_date DESC";
            } else {
                // 使用關聯查詢（原有邏輯）
                $sql = "SELECT 
                            sf.sf_ID,
                            sf.sf_name as title,
                            MIN(t.cohort_ID) as cohort_ID,
                            MIN(c.cohort_name) as cohort_name,
                            (CASE WHEN " . $formatTypeExpr . " = 'topic' THEN '初審建議表' ELSE '審查建議表' END) as format_type,
                            sf.sf_created_d,
                            COALESCE((
                                SELECT u.u_name 
                                FROM userdata u
                                WHERE u.u_ID = sf.sf_u_ID
                            ), '系統') as creator,
                            COALESCE((
                                SELECT u2.u_name 
                                FROM suggest s2 
                                JOIN userdata u2 ON s2.suggest_u_ID = u2.u_ID
                                WHERE s2.sf_ID = sf.sf_ID 
                                  AND s2.suggest_status IN (1, 2, 3, 4)
                                ORDER BY s2.suggest_d DESC
                                LIMIT 1
                            ), (
                                SELECT u.u_name 
                                FROM userdata u
                                WHERE u.u_ID = sf.sf_u_ID
                            ), '系統') as editor,
                            COALESCE(
                                (SELECT MAX(s2.suggest_d) FROM suggest s2 WHERE s2.sf_ID = sf.sf_ID AND s2.suggest_status IN (1, 2, 3, 4)),
                                sf.sf_update_d,
                                sf.sf_created_d
                            ) as update_date
                        FROM suggestfrom sf
                        INNER JOIN suggest s ON sf.sf_ID = s.sf_ID
                        INNER JOIN teamdata t ON s.team_ID = t.team_ID
                        LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                        WHERE s.suggest_status IN (1, 2, 3, 4)
                          AND t.team_status = 1
                          AND sf.sf_name IS NOT NULL
                          AND TRIM(sf.sf_name) != ''";
                
                $params = [];
                if ($cohort_ID) {
                    $sql .= " AND t.cohort_ID = ?";
                    $params[] = $cohort_ID;
                }
                if ($title) {
                    $sql .= " AND sf.sf_name LIKE ?";
                    $params[] = "%" . $title . "%";
                }
                if ($hasSfType && $suggestFormatFilter !== null) {
                    $sql .= " AND (sf.sf_type = ? OR (sf.sf_type IS NULL AND ? = 'review'))";
                    $params[] = $suggestFormatFilter;
                    $params[] = $suggestFormatFilter;
                }
                // 科辦：審查建議表只顯示「已送交科辦」的（sf_sent_to_office 為 1 或召集人帳號）；0/空僅召集人可見
                if ($isOffice && $hasSfSentToOffice) {
                    if ($hasSfType) {
                        $sql .= " AND (sf.sf_type != 'review' OR sf.sf_type IS NULL OR (TRIM(COALESCE(sf.sf_sent_to_office,'')) NOT IN ('','0')))";
                    } else {
                        $sql .= " AND (TRIM(COALESCE(sf.sf_sent_to_office,'')) NOT IN ('','0'))";
                    }
                }
                $sql .= " GROUP BY sf.sf_ID, sf.sf_name, sf.sf_u_ID, sf.sf_update_d, sf.sf_created_d" . ($hasSfType ? ", sf.sf_type" : "") . "
                          ORDER BY update_date DESC";
            }
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $suggestRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($suggestRows as $row) {
                $createTime = null;
                if (isset($row["sf_created_d"]) && $row["sf_created_d"] !== null && $row["sf_created_d"] !== '') {
                    $timestamp = strtotime($row["sf_created_d"]);
                    if ($timestamp !== false && $timestamp > 0) {
                        $createTime = date('Y-m-d H:i', $timestamp);
                    }
                }
                $formatType = isset($row["format_type"]) ? $row["format_type"] : "審查建議表";
                $results[] = [
                    "id" => $row["sf_ID"],
                    "title" => $row["title"],
                    "cohort" => $row["cohort_name"] ?: $row["cohort_ID"],
                    "cohort_ID" => $row["cohort_ID"],
                    "format" => $formatType,
                    "creator" => $row["creator"] ?: "系統",
                    "create_time" => $createTime,
                    "editor" => $row["editor"] ?: ($row["creator"] ?: "系統"),
                    "edit_time" => (isset($row["update_date"]) && $row["update_date"] !== null && $row["update_date"] !== '') 
                        ? (($timestamp = strtotime($row["update_date"])) !== false && $timestamp > 0 
                            ? date('Y-m-d H:i', $timestamp) 
                            : null) 
                        : null
                ];
            }
        }
        
        // 如果指定了格式，過濾結果
        if ($format && $format !== "全部") {
            $results = array_filter($results, function($r) use ($format) {
                return $r["format"] === $format;
            });
        }
        
        // 重新索引陣列
        $results = array_values($results);
        
        // 依建立時間排序（新到舊），非依資料類型
        usort($results, function ($a, $b) {
            $ta = $a["create_time"] ?? "";
            $tb = $b["create_time"] ?? "";
            if ($ta === $tb) return 0;
            if ($ta === "") return 1;
            if ($tb === "") return -1;
            return strcmp($tb, $ta);
        });
        
        // 前端表格不顯示建立人，自回傳資料中移除
        foreach ($results as &$item) {
            unset($item["creator"], $item["create_time"]);
        }
        unset($item);
        
        respond(["ok" => true, "data" => $results]);
        
    } catch (Throwable $e) {
        respond(["ok" => false, "msg" => "查詢失敗：" . $e->getMessage()]);
    }
}

/* ==========================================
   action: publish
   發佈所選的建議表或時程表，通知當屆所有人（由 modules/suggest_schedule.php 處理）
========================================== */
if ($action === "publish") {
    // 這裡開啟錯誤顯示，方便除錯（正式環境可再關閉或改成記錄到 log）
    @ini_set('display_errors', '1');
    error_reporting(E_ALL);

    require __DIR__ . "/../modules/suggest_schedule.php";
    exit;
}

/* ==========================================
   action 不存在
========================================== */
respond(["ok" => false, "msg" => "未知 action"]);
