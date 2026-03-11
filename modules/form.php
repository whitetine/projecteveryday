<?php

/**
 * 表單管理模組
 * 處理表單的 CRUD 操作
 */

// 確保 session 已啟動（如果 api.php 已經啟動則不會重複啟動）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 載入 Composer 自動載入器（用於 PDF 解析庫等依賴）
// 注意：如果 Composer 依賴未正確安裝，這裡會失敗，所以改為可選載入
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    try {
        // 使用 @ 抑制可能的輸出
        @require_once __DIR__ . '/../vendor/autoload.php';
    } catch (Throwable $e) {
        // Composer 自動載入失敗，記錄錯誤但不中斷執行
        // 只有在需要 PDF 解析功能時才會用到 Composer 依賴
        error_log('警告：Composer 自動載入失敗: ' . $e->getMessage());
        // 不拋出異常，讓程式繼續執行
    }
}

global $conn;
// 處理 JSON 請求：如果 Content-Type 是 application/json，從 php://input 讀取
$p = $_POST;
if (empty($p) && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $jsonInput = file_get_contents('php://input');
    if ($jsonInput) {
        $decoded = json_decode($jsonInput, true);
        if (is_array($decoded)) {
            $p = $decoded;
        }
    }
}
$do = $_GET['do'] ?? '';
$u_ID = $_SESSION['u_ID'] ?? null;

// 檢查是否為科辦或主任 (role_ID=1, 2)
function checkFormAdminPermission()
{
    global $conn;

    // 檢查資料庫連線
    if (!isset($conn)) {
        error_log('checkFormAdminPermission: $conn 未設定');
        json_err('資料庫連線失敗', 'DB_ERROR', 500);
    }

    // 檢查 session 是否啟動
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $u_ID = $_SESSION['u_ID'] ?? null;
    error_log('checkFormAdminPermission: u_ID = ' . ($u_ID ?? 'null'));

    if (!$u_ID) {
        error_log('checkFormAdminPermission: 沒有 u_ID，返回 401');
        json_err('請先登入', 'NOT_LOGGED_IN', 401);
    }

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM userrolesdata 
            WHERE ur_u_ID = ? AND role_ID IN (1, 2) AND user_role_status = 1
        ");
        $stmt->execute([$u_ID]);
        $count = $stmt->fetchColumn();
        error_log('checkFormAdminPermission: 權限查詢結果 = ' . $count);

        if (!$count) {
            error_log('checkFormAdminPermission: 沒有管理員權限，返回 403');
            json_err('此功能僅限主任和科辦使用', 'NO_PERMISSION', 403);
        }
        return $u_ID;
    } catch (PDOException $e) {
        error_log('checkFormAdminPermission PDO 錯誤: ' . $e->getMessage() . ' | 檔案: ' . $e->getFile() . ' | 行號: ' . $e->getLine());
        json_err('資料庫查詢失敗：' . $e->getMessage(), 'DB_ERROR', 500);
    } catch (Throwable $e) {
        error_log('checkFormAdminPermission 錯誤: ' . $e->getMessage() . ' | 檔案: ' . $e->getFile() . ' | 行號: ' . $e->getLine());
        json_err('權限檢查失敗：' . $e->getMessage());
    }
}

// 檢查是否為學生 (role_ID=6)
function checkStudentPermission()
{
    global $conn;
    $u_ID = $_SESSION['u_ID'] ?? null;
    if (!$u_ID) {
        json_err('請先登入', 'NOT_LOGGED_IN', 401);
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM userrolesdata 
        WHERE ur_u_ID = ? AND role_ID = 6 AND user_role_status = 1
    ");
    $stmt->execute([$u_ID]);
    if (!$stmt->fetchColumn()) {
        json_err('此功能僅限學生使用', 'NO_PERMISSION', 403);
    }
    return $u_ID;
}

switch ($do) {
    // 獲取所有表單列表（科辦）
    case 'get_forms':
        try {
            // 檢查資料庫連線
            if (!isset($conn)) {
                error_log('get_forms: $conn 未設定');
                json_err('資料庫連線失敗', 'DB_ERROR', 500);
            }

            // 先嘗試查詢，如果權限檢查失敗會拋出異常
            try {
                checkFormAdminPermission();
            } catch (Exception $permError) {
                error_log('get_forms 權限檢查失敗: ' . $permError->getMessage());
                // 權限檢查失敗會直接 json_err，不會到這裡
                throw $permError;
            }

            $sql = "
                SELECT 
                    form_ID, form_name, form_des, form_category, form_status,
                    form_start_d, form_end_d, form_created_d, form_updated_d,
                    form_created_u_ID, form_updated_u_ID
                FROM formdata
                ORDER BY form_created_d DESC
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log('get_forms: prepare 失敗');
                json_err('SQL 準備失敗', 'SQL_ERROR', 500);
            }

            $stmt->execute();
            $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_ok(['forms' => $forms]);
        } catch (PDOException $e) {
            error_log('get_forms PDO 錯誤: ' . $e->getMessage() . ' | 檔案: ' . $e->getFile() . ' | 行號: ' . $e->getLine());
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            error_log('get_forms 錯誤: ' . $e->getMessage() . ' | 檔案: ' . $e->getFile() . ' | 行號: ' . $e->getLine() . ' | 堆疊: ' . $e->getTraceAsString());
            json_err('獲取表單列表失敗：' . $e->getMessage());
        }
        break;

    // 獲取單個表單詳情（包含題目）
    case 'get_form_detail':
        try {
            $form_ID = isset($p['form_ID']) ? (int)$p['form_ID'] : (isset($_GET['form_ID']) ? (int)$_GET['form_ID'] : 0);

            if ($form_ID <= 0) {
                json_err('表單ID無效');
            }

            // 獲取表單基本資訊
            $stmt = $conn->prepare("
                SELECT * FROM formdata WHERE form_ID = ?
            ");
            $stmt->execute([$form_ID]);
            $form = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$form) {
                json_err('找不到該表單');
            }

            // 獲取表單題目
            $stmt = $conn->prepare("
                SELECT * FROM formquestiondata 
                WHERE form_ID = ? 
                ORDER BY fq_order ASC
            ");
            $stmt->execute([$form_ID]);
            $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 解析選項 JSON 和選項來源
            foreach ($questions as &$q) {
                if (!empty($q['fq_options'])) {
                    $q['fq_options'] = json_decode($q['fq_options'], true);
                }

                // 檢查是否有 option_source 欄位
                $hasOptionSource = false;
                try {
                    $stmt2 = $conn->query("SHOW COLUMNS FROM formquestiondata LIKE 'fq_option_source'");
                    $hasOptionSource = $stmt2->fetch() !== false;
                } catch (Exception $e) {
                    // 忽略錯誤
                }

                if ($hasOptionSource) {
                    $q['option_source'] = $q['fq_option_source'] ?? 'manual';
                    // 檢查是否有 option_field 欄位
                    try {
                        $stmt2 = $conn->query("SHOW COLUMNS FROM formquestiondata LIKE 'fq_option_field'");
                        $hasOptionField = $stmt2->fetch() !== false;
                        if ($hasOptionField) {
                            $q['option_field'] = $q['fq_option_field'] ?? 'default';
                        } else {
                            // 從 fq_remark 中解析 option_field
                            $remarkData = json_decode($q['fq_remark'] ?? '{}', true);
                            $q['option_field'] = $remarkData['option_field'] ?? 'default';
                        }
                    } catch (Exception $e) {
                        $q['option_field'] = 'default';
                    }
                } else {
                    // 從 fq_remark 中解析 option_source 和 option_field
                    $remarkData = json_decode($q['fq_remark'] ?? '{}', true);
                    if (is_array($remarkData) && isset($remarkData['option_source'])) {
                        $q['option_source'] = $remarkData['option_source'];
                        $q['option_field'] = $remarkData['option_field'] ?? 'default';
                        $q['fq_remark'] = $remarkData['remark'] ?? '';
                    } else {
                        $q['option_source'] = 'manual';
                        $q['option_field'] = 'default';
                    }
                }
            }

            // 獲取目標對象資料
            $targets = [
                'ft_group' => null,
                'ft_cohort_from' => null,
                'ft_cohort_to' => null,
                'ft_remark' => null
            ];
            try {
                $stmt = $conn->prepare("
                    SELECT ft_group, ft_cohort_from, ft_cohort_to, ft_remark
                    FROM formtargetdata
                    WHERE form_ID = ?
                    LIMIT 1
                ");
                $stmt->execute([$form_ID]);
                $targetData = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($targetData) {
                    $targets = $targetData;
                }
            } catch (Exception $e) {
                // 如果表不存在或查詢失敗，使用預設值
                error_log('獲取目標對象資料失敗: ' . $e->getMessage());
            }

            $form['questions'] = $questions;
            $form['targets'] = $targets;

            json_ok(['form' => $form]);
        } catch (Throwable $e) {
            json_err('獲取表單詳情失敗：' . $e->getMessage());
        }
        break;

    // 獲取學生資料（用於自動填入表單）
    case 'get_student_data_for_form':
        try {
            $u_ID = $_SESSION['u_ID'] ?? null;
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }

            // 獲取學生基本資料
            $stmt = $conn->prepare("
                SELECT 
                    u.u_ID,
                    u.u_name,
                    u.u_gmail,
                    u.u_profile
                FROM userdata u
                WHERE u.u_ID = ?
            ");
            $stmt->execute([$u_ID]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                json_err('找不到使用者資料');
            }

            // 獲取學籍資料（當前屆別）
            $stmt = $conn->prepare("
                SELECT 
                    e.enroll_grade,
                    c.c_ID as class_ID,
                    c.c_name as class_name,
                    ch.cohort_ID,
                    ch.cohort_name
                FROM enrollmentdata e
                LEFT JOIN classdata c ON e.class_ID = c.c_ID
                LEFT JOIN cohortdata ch ON e.cohort_ID = ch.cohort_ID
                WHERE e.enroll_u_ID = ? AND e.enroll_status = 1
                ORDER BY e.cohort_ID DESC
                LIMIT 1
            ");
            $stmt->execute([$u_ID]);
            $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

            // 獲取團隊資料（如果有的話）
            $team_ID = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : null;
            $teamData = null;

            if ($team_ID) {
                $stmt = $conn->prepare("
                    SELECT 
                        t.team_ID,
                        t.team_project_name,
                        g.group_ID,
                        g.group_name
                    FROM teamdata t
                    LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                    WHERE t.team_ID = ? AND t.team_status = 1
                ");
                $stmt->execute([$team_ID]);
                $teamData = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                // 如果沒有指定團隊，嘗試獲取學生所屬的團隊
                $teamUserField = 'team_u_ID';
                $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $stmt->execute();
                if (!$stmt->fetch()) {
                    $teamUserField = 'u_ID';
                }

                $stmt = $conn->prepare("
                    SELECT 
                        t.team_ID,
                        t.team_project_name,
                        g.group_ID,
                        g.group_name
                    FROM teammember tm
                    INNER JOIN teamdata t ON tm.team_ID = t.team_ID
                    LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                    WHERE tm.{$teamUserField} = ? AND tm.tm_status = 1 AND t.team_status = 1
                    ORDER BY t.team_update_d DESC
                    LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $teamData = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // 組合學生資料
            $studentData = [
                'u_ID' => $user['u_ID'],
                'u_name' => $user['u_name'],
                'u_gmail' => $user['u_gmail'] ?? '',
                'u_profile' => $user['u_profile'] ?? '',
                'class_ID' => $enrollment['class_ID'] ?? null,
                'class_name' => $enrollment['class_name'] ?? '',
                'cohort_ID' => $enrollment['cohort_ID'] ?? null,
                'cohort_name' => $enrollment['cohort_name'] ?? '',
                'enroll_grade' => $enrollment['enroll_grade'] ?? null,
                'team_ID' => $teamData['team_ID'] ?? null,
                'team_project_name' => $teamData['team_project_name'] ?? '',
                'group_ID' => $teamData['group_ID'] ?? null,
                'group_name' => $teamData['group_name'] ?? ''
            ];

            json_ok(['student_data' => $studentData]);
        } catch (Throwable $e) {
            error_log('get_student_data_for_form 錯誤: ' . $e->getMessage());
            json_err('獲取學生資料失敗：' . $e->getMessage());
        }
        break;

    // 使用 AI 智能匹配題目並自動填入
    case 'auto_fill_form_with_ai':
        try {
            $u_ID = $_SESSION['u_ID'] ?? null;
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }

            $form_ID = isset($p['form_ID']) ? (int)$p['form_ID'] : 0;
            if ($form_ID <= 0) {
                json_err('表單ID無效');
            }

            // 獲取表單題目（包含選項）
            $stmt = $conn->prepare("
                SELECT fq_ID, fq_title, fq_type, fq_options, fq_placeholder
                FROM formquestiondata 
                WHERE form_ID = ? 
                ORDER BY fq_order ASC
            ");
            $stmt->execute([$form_ID]);
            $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 解析選項 JSON
            foreach ($questions as &$q) {
                if (!empty($q['fq_options'])) {
                    $q['fq_options'] = json_decode($q['fq_options'], true);
                }
            }
            unset($q);

            // 獲取學生資料
            $stmt = $conn->prepare("
                SELECT 
                    u.u_ID, u.u_name, u.u_gmail, u.u_profile,
                    e.enroll_grade,
                    c.c_ID as class_ID, c.c_name as class_name,
                    ch.cohort_ID, ch.cohort_name,
                    t.team_ID, t.team_project_name,
                    g.group_ID, g.group_name
                FROM userdata u
                LEFT JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID AND e.enroll_status = 1
                LEFT JOIN classdata c ON e.class_ID = c.c_ID
                LEFT JOIN cohortdata ch ON e.cohort_ID = ch.cohort_ID
                LEFT JOIN teammember tm ON tm.team_u_ID = u.u_ID AND tm.tm_status = 1
                LEFT JOIN teamdata t ON tm.team_ID = t.team_ID AND t.team_status = 1
                LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                WHERE u.u_ID = ?
                ORDER BY e.cohort_ID DESC, t.team_update_d DESC
                LIMIT 1
            ");
            $stmt->execute([$u_ID]);
            $studentData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$studentData) {
                json_err('找不到學生資料');
            }

            // 準備資料庫資料（用於 AI 匹配）
            // 同時提供中文鍵名和資料庫欄位名，以確保匹配函數能正確工作
            $dbData = [
                // 中文鍵名（用於 AI 匹配和顯示）
                '學號' => $studentData['u_ID'],
                '姓名' => $studentData['u_name'],
                '名字' => $studentData['u_name'],
                'email' => $studentData['u_gmail'] ?? '',
                '電子郵件' => $studentData['u_gmail'] ?? '',
                '信箱' => $studentData['u_gmail'] ?? '',
                '班級' => $studentData['class_name'] ?? '',
                '班級ID' => $studentData['class_ID'] ?? null,
                '屆別' => $studentData['cohort_name'] ?? '',
                '屆別ID' => $studentData['cohort_ID'] ?? null,
                '年級' => $studentData['enroll_grade'] ?? null,
                '專題名稱' => $studentData['team_project_name'] ?? '',
                '專題' => $studentData['team_project_name'] ?? '',
                '類組' => $studentData['group_name'] ?? '',
                '團隊ID' => $studentData['team_ID'] ?? null,
                // 資料庫欄位名（用於基本匹配函數）
                'u_ID' => $studentData['u_ID'],
                'u_name' => $studentData['u_name'],
                'u_gmail' => $studentData['u_gmail'] ?? '',
                'class_name' => $studentData['class_name'] ?? '',
                'class_ID' => $studentData['class_ID'] ?? null,
                'cohort_name' => $studentData['cohort_name'] ?? '',
                'cohort_ID' => $studentData['cohort_ID'] ?? null,
                'enroll_grade' => $studentData['enroll_grade'] ?? null,
                'team_project_name' => $studentData['team_project_name'] ?? '',
                'group_name' => $studentData['group_name'] ?? '',
                'team_ID' => $studentData['team_ID'] ?? null
            ];

            // 使用 AI 匹配題目和資料
            $autoFillResults = matchQuestionsWithData($questions, $dbData);

            json_ok([
                'auto_fill' => $autoFillResults,
                'student_data' => $studentData
            ]);
        } catch (Throwable $e) {
            error_log('auto_fill_form_with_ai 錯誤: ' . $e->getMessage());
            json_err('自動填入失敗：' . $e->getMessage());
        }
        break;

    // 創建或更新表單
    case 'save_form':
        try {
            $admin_ID = checkFormAdminPermission();

            $form_ID = isset($p['form_ID']) ? (int)$p['form_ID'] : 0;
            $form_name = trim($p['form_name'] ?? '');
            $form_des = trim($p['form_des'] ?? '');
            $form_category = trim($p['form_category'] ?? '');
            $form_status = isset($p['form_status']) ? (int)$p['form_status'] : 1;
            $form_start_d = !empty($p['form_start_d']) ? $p['form_start_d'] : null;
            $form_end_d = !empty($p['form_end_d']) ? $p['form_end_d'] : null;
            $form_remark = trim($p['form_remark'] ?? '');
            $questions = json_decode($p['questions'] ?? '[]', true);
            $targets = json_decode($p['targets'] ?? '{}', true);

            if (empty($form_name)) {
                json_err('請輸入表單名稱');
            }

            if (!is_array($questions)) {
                json_err('題目資料格式錯誤');
            }

            $conn->beginTransaction();

            if ($form_ID > 0) {
                // 更新表單
                $stmt = $conn->prepare("
                    UPDATE formdata SET
                        form_name = ?,
                        form_des = ?,
                        form_category = ?,
                        form_status = ?,
                        form_start_d = ?,
                        form_end_d = ?,
                        form_remark = ?,
                        form_updated_d = NOW(),
                        form_updated_u_ID = ?
                    WHERE form_ID = ?
                ");
                $stmt->execute([
                    $form_name,
                    $form_des,
                    $form_category,
                    $form_status,
                    $form_start_d ?: null,
                    $form_end_d ?: null,
                    $form_remark,
                    $admin_ID,
                    $form_ID
                ]);
            } else {
                // 創建新表單
                $stmt = $conn->prepare("
                    INSERT INTO formdata (
                        form_name, form_des, form_category, form_status,
                        form_start_d, form_end_d, form_created_u_ID, form_created_d,
                        form_remark
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ");
                $stmt->execute([
                    $form_name,
                    $form_des,
                    $form_category,
                    $form_status,
                    $form_start_d ?: null,
                    $form_end_d ?: null,
                    $admin_ID,
                    $form_remark
                ]);
                $form_ID = $conn->lastInsertId();
            }

            // 刪除舊題目
            $stmt = $conn->prepare("DELETE FROM formquestiondata WHERE form_ID = ?");
            $stmt->execute([$form_ID]);

            // 插入新題目
            foreach ($questions as $index => $q) {
                $fq_title = trim($q['fq_title'] ?? '');
                $fq_type = trim($q['fq_type'] ?? 'short_text');
                $fq_required = isset($q['fq_required']) ? (int)$q['fq_required'] : 1;
                $fq_placeholder = trim($q['fq_placeholder'] ?? '');
                $fq_remark = trim($q['fq_remark'] ?? '');
                $option_source = trim($q['option_source'] ?? 'manual');
                $option_field = trim($q['option_field'] ?? 'default');

                if (empty($fq_title)) continue;

                // 處理選項
                $fq_options = null;
                if (in_array($fq_type, ['select', 'radio', 'checkbox'], true)) {
                    if ($option_source === 'manual') {
                        // 手動輸入的選項
                        $fq_options = !empty($q['fq_options']) ? json_encode($q['fq_options'], JSON_UNESCAPED_UNICODE) : null;
                    } else {
                        // 從資料庫載入選項（使用與 get_form_options 相同的邏輯）
                        $options = [];
                        try {
                            switch ($option_source) {
                                case 'classes':
                                    // 動態載入班級選項（只顯示存在的班級）
                                    switch ($option_field) {
                                        case 'id':
                                            $stmt2 = $conn->prepare("SELECT DISTINCT c_ID as value, c_ID as label FROM classdata WHERE c_ID IS NOT NULL ORDER BY c_ID");
                                            break;
                                        case 'both':
                                            $stmt2 = $conn->prepare("SELECT DISTINCT c_ID as value, CONCAT(c_ID, ' - ', c_name) as label FROM classdata WHERE c_ID IS NOT NULL ORDER BY c_ID");
                                            break;
                                        default:
                                            // 預設顯示名稱，如果名稱為空則顯示 ID
                                            $stmt2 = $conn->prepare("SELECT DISTINCT c_ID as value, COALESCE(NULLIF(c_name, ''), c_ID) as label FROM classdata WHERE c_ID IS NOT NULL ORDER BY c_ID");
                                    }
                                    $stmt2->execute();
                                    $options = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                                    break;
                                case 'cohorts':
                                    switch ($option_field) {
                                        case 'id':
                                            $stmt2 = $conn->prepare("SELECT cohort_ID as value, cohort_ID as label FROM cohortdata WHERE cohort_status = 1 ORDER BY cohort_ID DESC");
                                            break;
                                        case 'both':
                                            $stmt2 = $conn->prepare("SELECT cohort_ID as value, CONCAT(cohort_ID, ' - ', cohort_name) as label FROM cohortdata WHERE cohort_status = 1 ORDER BY cohort_ID DESC");
                                            break;
                                        default:
                                            // 預設顯示名稱，如果名稱為空則顯示 ID
                                            $stmt2 = $conn->prepare("SELECT cohort_ID as value, COALESCE(NULLIF(cohort_name, ''), cohort_ID) as label FROM cohortdata WHERE cohort_status = 1 ORDER BY cohort_ID DESC");
                                    }
                                    $stmt2->execute();
                                    $options = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                                    break;
                                case 'groups':
                                    switch ($option_field) {
                                        case 'id':
                                            $stmt2 = $conn->prepare("SELECT group_ID as value, group_ID as label FROM groupdata WHERE group_status = 1 ORDER BY group_ID");
                                            break;
                                        case 'both':
                                            $stmt2 = $conn->prepare("SELECT group_ID as value, CONCAT(group_ID, ' - ', group_name) as label FROM groupdata WHERE group_status = 1 ORDER BY group_ID");
                                            break;
                                        default:
                                            // 預設顯示名稱，如果名稱為空則顯示 ID
                                            $stmt2 = $conn->prepare("SELECT group_ID as value, COALESCE(NULLIF(group_name, ''), group_ID) as label FROM groupdata WHERE group_status = 1 ORDER BY group_ID");
                                    }
                                    $stmt2->execute();
                                    $options = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                                    break;
                                case 'students':
                                    $cohort_ID = null;
                                    $stmt2 = $conn->prepare("SELECT cohort_ID FROM cohortdata WHERE cohort_status = 1 ORDER BY cohort_ID DESC LIMIT 1");
                                    $stmt2->execute();
                                    $cohort = $stmt2->fetch(PDO::FETCH_ASSOC);
                                    if ($cohort) {
                                        $cohort_ID = $cohort['cohort_ID'];
                                        switch ($option_field) {
                                            case 'id':
                                                $stmt2 = $conn->prepare("
                                                    SELECT DISTINCT u.u_ID as value, u.u_ID as label
                                                    FROM userdata u
                                                    INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                                                    INNER JOIN enrollmentdata e ON u.u_ID = e.enroll_u_ID
                                                    WHERE ur.role_ID = 6 
                                                      AND ur.user_role_status = 1
                                                      AND u.u_status = 1
                                                      AND e.cohort_ID = ?
                                                    ORDER BY u.u_ID
                                                ");
                                                break;
                                            case 'name':
                                                $stmt2 = $conn->prepare("
                                                    SELECT DISTINCT u.u_ID as value, u.u_name as label
                                                    FROM userdata u
                                                    INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                                                    INNER JOIN enrollmentdata e ON u.u_ID = e.enroll_u_ID
                                                    WHERE ur.role_ID = 6 
                                                      AND ur.user_role_status = 1
                                                      AND u.u_status = 1
                                                      AND e.cohort_ID = ?
                                                    ORDER BY u.u_name
                                                ");
                                                break;
                                            default:
                                                $stmt2 = $conn->prepare("
                                                    SELECT DISTINCT u.u_ID as value, CONCAT(u.u_ID, ' - ', u.u_name) as label
                                                    FROM userdata u
                                                    INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                                                    INNER JOIN enrollmentdata e ON u.u_ID = e.enroll_u_ID
                                                    WHERE ur.role_ID = 6 
                                                      AND ur.user_role_status = 1
                                                      AND u.u_status = 1
                                                      AND e.cohort_ID = ?
                                                    ORDER BY u.u_ID
                                                ");
                                        }
                                        $stmt2->execute([$cohort_ID]);
                                        $options = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                                    }
                                    break;
                                case 'teachers':
                                    switch ($option_field) {
                                        case 'id':
                                            $stmt2 = $conn->prepare("
                                                SELECT DISTINCT u.u_ID as value, u.u_ID as label
                                                FROM userdata u
                                                INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                                                WHERE ur.role_ID = 4 
                                                  AND ur.user_role_status = 1
                                                  AND u.u_status = 1
                                                ORDER BY u.u_ID
                                            ");
                                            break;
                                        case 'name':
                                            $stmt2 = $conn->prepare("
                                                SELECT DISTINCT u.u_ID as value, u.u_name as label
                                                FROM userdata u
                                                INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                                                WHERE ur.role_ID = 4 
                                                  AND ur.user_role_status = 1
                                                  AND u.u_status = 1
                                                ORDER BY u.u_name
                                            ");
                                            break;
                                        default:
                                            $stmt2 = $conn->prepare("
                                                SELECT DISTINCT u.u_ID as value, CONCAT(u.u_ID, ' - ', u.u_name) as label
                                                FROM userdata u
                                                INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                                                WHERE ur.role_ID = 4 
                                                  AND ur.user_role_status = 1
                                                  AND u.u_status = 1
                                                ORDER BY u.u_name
                                            ");
                                    }
                                    $stmt2->execute();
                                    $options = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                                    break;
                                case 'teams':
                                    // 動態載入團隊選項（只顯示當前屆別、啟用且有成員的團隊）
                                    $cohort_ID = null;
                                    $stmt2 = $conn->prepare("SELECT cohort_ID FROM cohortdata WHERE cohort_status = 1 ORDER BY cohort_ID DESC LIMIT 1");
                                    $stmt2->execute();
                                    $cohort = $stmt2->fetch(PDO::FETCH_ASSOC);
                                    if ($cohort) {
                                        $cohort_ID = $cohort['cohort_ID'];

                                        // 檢查 teammember 表結構
                                        $teamUserField = 'team_u_ID';
                                        $stmtCheck = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                                        $stmtCheck->execute();
                                        if (!$stmtCheck->fetch()) {
                                            $teamUserField = 'u_ID';
                                        }

                                        switch ($option_field) {
                                            case 'id':
                                                $stmt2 = $conn->prepare("
                                                    SELECT DISTINCT t.team_ID as value, t.team_ID as label
                                                    FROM teamdata t
                                                    WHERE t.cohort_ID = ? 
                                                      AND t.team_status = 1
                                                      AND EXISTS (
                                                          SELECT 1 FROM teammember tm 
                                                          WHERE tm.team_ID = t.team_ID 
                                                            AND tm.tm_status = 1
                                                      )
                                                    ORDER BY t.team_ID
                                                ");
                                                break;
                                            case 'both':
                                                $stmt2 = $conn->prepare("
                                                    SELECT DISTINCT t.team_ID as value, CONCAT(t.team_ID, ' - ', t.team_project_name) as label
                                                    FROM teamdata t
                                                    WHERE t.cohort_ID = ? 
                                                      AND t.team_status = 1
                                                      AND EXISTS (
                                                          SELECT 1 FROM teammember tm 
                                                          WHERE tm.team_ID = t.team_ID 
                                                            AND tm.tm_status = 1
                                                      )
                                                    ORDER BY t.team_ID
                                                ");
                                                break;
                                            default:
                                                $stmt2 = $conn->prepare("
                                                    SELECT DISTINCT t.team_ID as value, t.team_project_name as label
                                                    FROM teamdata t
                                                    WHERE t.cohort_ID = ? 
                                                      AND t.team_status = 1
                                                      AND EXISTS (
                                                          SELECT 1 FROM teammember tm 
                                                          WHERE tm.team_ID = t.team_ID 
                                                            AND tm.tm_status = 1
                                                      )
                                                    ORDER BY t.team_ID
                                                ");
                                        }
                                        $stmt2->execute([$cohort_ID]);
                                        $options = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                                    }
                                    break;
                            }

                            // 將選項轉換為簡單的字串陣列（只保留 label）
                            $optionLabels = array_map(function ($opt) {
                                return $opt['label'];
                            }, $options);

                            if (!empty($optionLabels)) {
                                $fq_options = json_encode($optionLabels, JSON_UNESCAPED_UNICODE);
                            }
                        } catch (Exception $e) {
                            // 如果載入失敗，記錄錯誤但繼續
                            error_log('載入選項失敗: ' . $e->getMessage());
                        }
                    }
                }

                // 檢查是否有 option_source 欄位
                $hasOptionSource = false;
                try {
                    $stmt2 = $conn->query("SHOW COLUMNS FROM formquestiondata LIKE 'fq_option_source'");
                    $hasOptionSource = $stmt2->fetch() !== false;
                } catch (Exception $e) {
                    // 忽略錯誤
                }

                if ($hasOptionSource) {
                    $stmt = $conn->prepare("
                        INSERT INTO formquestiondata (
                            form_ID, fq_order, fq_title, fq_type, fq_required,
                            fq_placeholder, fq_options, fq_remark, fq_option_source
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $form_ID,
                        $index + 1,
                        $fq_title,
                        $fq_type,
                        $fq_required,
                        $fq_placeholder ?: null,
                        $fq_options,
                        $fq_remark ?: null,
                        $option_source
                    ]);
                } else {
                    // 如果沒有 option_source 欄位，將來源資訊存在 fq_remark 中（JSON格式）
                    $remarkData = [
                        'remark' => $fq_remark,
                        'option_source' => $option_source,
                        'option_field' => $option_field
                    ];
                    $remarkJson = json_encode($remarkData, JSON_UNESCAPED_UNICODE);

                    $stmt = $conn->prepare("
                        INSERT INTO formquestiondata (
                            form_ID, fq_order, fq_title, fq_type, fq_required,
                            fq_placeholder, fq_options, fq_remark
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $form_ID,
                        $index + 1,
                        $fq_title,
                        $fq_type,
                        $fq_required,
                        $fq_placeholder ?: null,
                        $fq_options,
                        $remarkJson
                    ]);
                }
            }

            // 處理目標對象資料
            try {
                // 刪除舊的目標對象資料
                $stmt = $conn->prepare("DELETE FROM formtargetdata WHERE form_ID = ?");
                $stmt->execute([$form_ID]);

                // 插入新的目標對象資料
                $ft_group = null;
                if (!empty($targets['ft_group'])) {
                    $ft_group = trim($targets['ft_group']);
                }
                $ft_cohort_from = !empty($targets['ft_cohort_from']) ? (int)$targets['ft_cohort_from'] : null;
                $ft_cohort_to = !empty($targets['ft_cohort_to']) ? (int)$targets['ft_cohort_to'] : null;
                $ft_remark = !empty($targets['ft_remark']) ? trim($targets['ft_remark']) : null;

                // 只有當至少有一個欄位有值時才插入
                if ($ft_group !== null || $ft_cohort_from !== null || $ft_cohort_to !== null || $ft_remark !== null) {
                    $stmt = $conn->prepare("
                        INSERT INTO formtargetdata (form_ID, ft_group, ft_cohort_from, ft_cohort_to, ft_remark)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$form_ID, $ft_group, $ft_cohort_from, $ft_cohort_to, $ft_remark]);
                }
            } catch (Exception $e) {
                // 如果表不存在，記錄錯誤但不中斷流程
                error_log('儲存目標對象資料失敗: ' . $e->getMessage());
            }

            $conn->commit();
            json_ok(['message' => $form_ID > 0 ? '表單已更新' : '表單已創建', 'form_ID' => $form_ID]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('保存表單失敗：' . $e->getMessage());
        }
        break;

    // 刪除表單
    case 'delete_form':
        try {
            checkFormAdminPermission();

            $form_ID = isset($p['form_ID']) ? (int)$p['form_ID'] : 0;

            if ($form_ID <= 0) {
                json_err('表單ID無效');
            }

            // 檢查是否有提交記錄
            $stmt = $conn->prepare("SELECT COUNT(*) FROM formsubdata WHERE form_ID = ?");
            $stmt->execute([$form_ID]);
            if ($stmt->fetchColumn() > 0) {
                json_err('該表單已有提交記錄，無法刪除');
            }

            $conn->beginTransaction();

            // 刪除題目
            $stmt = $conn->prepare("DELETE FROM formquestiondata WHERE form_ID = ?");
            $stmt->execute([$form_ID]);

            // 刪除表單
            $stmt = $conn->prepare("DELETE FROM formdata WHERE form_ID = ?");
            $stmt->execute([$form_ID]);

            $conn->commit();
            json_ok(['message' => '表單已刪除']);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('刪除表單失敗：' . $e->getMessage());
        }
        break;

    // 獲取學生可填寫的表單列表
    case 'get_available_forms':
        try {
            $u_ID = checkStudentPermission();

            // 獲取當前時間
            $now = date('Y-m-d H:i:s');

            // 獲取開放中的表單
            $sql = "
                SELECT 
                    form_ID, form_name, form_des, form_category,
                    form_start_d, form_end_d
                FROM formdata
                WHERE form_status = 1
                  AND (form_start_d IS NULL OR form_start_d <= ?)
                  AND (form_end_d IS NULL OR form_end_d >= ?)
                ORDER BY form_created_d DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$now, $now]);
            $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 檢查每個表單是否已提交
            foreach ($forms as &$form) {
                $stmt = $conn->prepare("
                    SELECT fs_ID, fs_status, fs_submitted_d
                    FROM formsubdata
                    WHERE form_ID = ? AND fs_u_ID = ?
                    ORDER BY fs_submitted_d DESC
                    LIMIT 1
                ");
                $stmt->execute([$form['form_ID'], $u_ID]);
                $submission = $stmt->fetch(PDO::FETCH_ASSOC);
                $form['submitted'] = $submission !== false;
                $form['submission_status'] = $submission ? (int)$submission['fs_status'] : null;
                $form['submission_id'] = $submission ? $submission['fs_ID'] : null;
            }

            json_ok(['forms' => $forms]);
        } catch (Throwable $e) {
            json_err('獲取表單列表失敗：' . $e->getMessage());
        }
        break;

    // 提交表單答案
    case 'submit_form':
        try {
            $u_ID = checkStudentPermission();

            $form_ID = isset($p['form_ID']) ? (int)$p['form_ID'] : 0;
            $fs_ID = isset($p['fs_ID']) ? (int)$p['fs_ID'] : 0; // 如果是編輯已提交的表單
            $answers = json_decode($p['answers'] ?? '[]', true);
            $team_ID = isset($p['team_ID']) ? (int)$p['team_ID'] : null;
            $is_draft = isset($p['is_draft']) ? (int)$p['is_draft'] : 0; // 1=暫存, 0=正式提交

            if ($form_ID <= 0) {
                json_err('表單ID無效');
            }

            if (!is_array($answers)) {
                json_err('答案資料格式錯誤');
            }

            // 驗證表單是否存在且開放
            $stmt = $conn->prepare("
                SELECT * FROM formdata 
                WHERE form_ID = ? AND form_status = 1
            ");
            $stmt->execute([$form_ID]);
            $form = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$form) {
                json_err('表單不存在或已關閉');
            }

            // 獲取表單題目
            $stmt = $conn->prepare("
                SELECT * FROM formquestiondata 
                WHERE form_ID = ? 
                ORDER BY fq_order ASC
            ");
            $stmt->execute([$form_ID]);
            $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 只有正式提交時才驗證必填題目
            if ($is_draft == 0) {
                foreach ($questions as $q) {
                    if ($q['fq_required'] == 1) {
                        $found = false;
                        foreach ($answers as $ans) {
                            if ($ans['fq_ID'] == $q['fq_ID']) {
                                $value = trim($ans['fa_value'] ?? '');
                                if (!empty($value)) {
                                    $found = true;
                                    break;
                                }
                            }
                        }
                        if (!$found) {
                            json_err("題目「{$q['fq_title']}」為必填項目");
                        }
                    }
                }
            }

            $conn->beginTransaction();

            // 檢查是否已有團隊的表單提交記錄（團隊共享編輯）
            if ($team_ID && $fs_ID == 0) {
                $stmt = $conn->prepare("
                    SELECT fs_ID FROM formsubdata 
                    WHERE form_ID = ? AND fs_team_ID = ?
                    ORDER BY fs_created_d DESC
                    LIMIT 1
                ");
                $stmt->execute([$form_ID, $team_ID]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $fs_ID = $existing['fs_ID'];
                }
            }

            if ($fs_ID > 0) {
                // 更新已提交的表單（檢查權限：提交者或同團隊的學生成員）
                $stmt = $conn->prepare("
                    SELECT fs.fs_ID, fs.fs_team_ID, fs.fs_u_ID
                    FROM formsubdata fs
                    WHERE fs.fs_ID = ?
                ");
                $stmt->execute([$fs_ID]);
                $existingSubmission = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existingSubmission) {
                    throw new Exception('找不到該提交記錄');
                }

                // 檢查權限：提交者本人或同團隊的學生成員（排除指導老師）
                $hasPermission = false;
                if ($existingSubmission['fs_u_ID'] == $u_ID) {
                    $hasPermission = true;
                } elseif ($team_ID && $existingSubmission['fs_team_ID'] == $team_ID) {
                    // 檢查是否為團隊成員且不是指導老師
                    $teamUserField = 'team_u_ID';
                    $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                    $stmt->execute();
                    if (!$stmt->fetch()) {
                        $teamUserField = 'u_ID';
                    }

                    // 檢查是否為團隊成員
                    $stmt = $conn->prepare("
                        SELECT COUNT(*) 
                        FROM teammember 
                        WHERE team_ID = ? AND {$teamUserField} = ? AND tm_status = 1
                    ");
                    $stmt->execute([$team_ID, $u_ID]);
                    $isMember = $stmt->fetchColumn() > 0;

                    if ($isMember) {
                        // 檢查是否為指導老師（role_ID = 4）
                        $stmt = $conn->prepare("
                            SELECT COUNT(*) 
                            FROM userrolesdata 
                            WHERE ur_u_ID = ? AND role_ID = 4 AND user_role_status = 1
                        ");
                        $stmt->execute([$u_ID]);
                        $isTeacher = $stmt->fetchColumn() > 0;

                        if (!$isTeacher) {
                            $hasPermission = true;
                        }
                    }
                }

                if (!$hasPermission) {
                    throw new Exception('您沒有權限編輯此表單提交');
                }

                // 更新提交時間和狀態
                $stmt = $conn->prepare("
                    UPDATE formsubdata SET
                        fs_submitted_d = NOW(),
                        fs_status = ?
                    WHERE fs_ID = ?
                ");
                $stmt->execute([$is_draft, $fs_ID]);

                // 刪除舊答案
                $stmt = $conn->prepare("DELETE FROM formanswerdata WHERE fs_ID = ?");
                $stmt->execute([$fs_ID]);
            } else {
                // 創建新提交記錄
                $stmt = $conn->prepare("
                    INSERT INTO formsubdata (
                        form_ID, fs_u_ID, fs_team_ID, fs_status,
                        fs_created_d, fs_submitted_d
                    ) VALUES (?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$form_ID, $u_ID, $team_ID, $is_draft]);
                $fs_ID = $conn->lastInsertId();
            }

            // 插入答案
            foreach ($answers as $ans) {
                $fq_ID = isset($ans['fq_ID']) ? (int)$ans['fq_ID'] : 0;
                $fa_value = is_array($ans['fa_value'])
                    ? json_encode($ans['fa_value'], JSON_UNESCAPED_UNICODE)
                    : trim($ans['fa_value'] ?? '');

                if ($fq_ID <= 0 || empty($fa_value)) continue;

                $stmt = $conn->prepare("
                    INSERT INTO formanswerdata (fs_ID, fq_ID, fa_value)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$fs_ID, $fq_ID, $fa_value]);
            }

            // 不再更新流程步驟（已移除 team_flow_step 相關邏輯）

            $conn->commit();
            $message = $is_draft == 1 ? '表單已暫存' : '表單已提交';
            json_ok(['message' => $message, 'fs_ID' => $fs_ID, 'is_draft' => $is_draft]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('提交表單失敗：' . $e->getMessage());
        }
        break;

    // 獲取已提交的表單答案
    case 'get_form_submission':
        try {
            $fs_ID = isset($p['fs_ID']) ? (int)$p['fs_ID'] : (isset($_GET['fs_ID']) ? (int)$_GET['fs_ID'] : 0);
            $u_ID = $_SESSION['u_ID'] ?? null;

            if ($fs_ID <= 0) {
                json_err('提交ID無效');
            }

            // 獲取提交記錄
            $stmt = $conn->prepare("
                SELECT fs.*, f.form_name, f.form_category
                FROM formsubdata fs
                INNER JOIN formdata f ON fs.form_ID = f.form_ID
                WHERE fs.fs_ID = ?
            ");
            $stmt->execute([$fs_ID]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$submission) {
                json_err('找不到該提交記錄');
            }

            // 檢查權限（只有提交者或管理員可以查看）
            $isAdmin = false;
            if ($u_ID) {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) 
                    FROM userrolesdata 
                    WHERE ur_u_ID = ? AND role_ID IN (1, 2) AND user_role_status = 1
                ");
                $stmt->execute([$u_ID]);
                $isAdmin = $stmt->fetchColumn() > 0;
            }

            if (!$isAdmin && $submission['fs_u_ID'] !== $u_ID) {
                json_err('無權限查看此提交記錄', 'NO_PERMISSION', 403);
            }

            // 獲取答案
            $stmt = $conn->prepare("
                SELECT fa.*, fq.fq_title, fq.fq_type
                FROM formanswerdata fa
                INNER JOIN formquestiondata fq ON fa.fq_ID = fq.fq_ID
                WHERE fa.fs_ID = ?
                ORDER BY fq.fq_order ASC
            ");
            $stmt->execute([$fs_ID]);
            $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 解析答案（如果是 JSON）
            foreach ($answers as &$ans) {
                if (in_array($ans['fq_type'], ['select', 'radio', 'checkbox'])) {
                    $decoded = json_decode($ans['fa_value'], true);
                    if (is_array($decoded)) {
                        $ans['fa_value'] = $decoded;
                    }
                }
            }

            $submission['answers'] = $answers;

            json_ok(['submission' => $submission]);
        } catch (Throwable $e) {
            json_err('獲取提交記錄失敗：' . $e->getMessage());
        }
        break;

    // 獲取團隊的表單提交記錄
    case 'get_team_form_submission':
        try {
            $u_ID = checkStudentPermission();
            $form_ID = isset($p['form_ID']) ? (int)$p['form_ID'] : (isset($_GET['form_ID']) ? (int)$_GET['form_ID'] : 0);
            $team_ID = isset($p['team_ID']) ? (int)$p['team_ID'] : (isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : 0);

            if ($form_ID <= 0 || $team_ID <= 0) {
                json_err('表單ID或團隊ID無效');
            }

            // 檢查用戶是否在該團隊中
            $teamUserField = 'team_u_ID';
            $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $teamUserField = 'u_ID';
            }

            $stmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM teammember 
                WHERE team_ID = ? AND {$teamUserField} = ? AND tm_status = 1
            ");
            $stmt->execute([$team_ID, $u_ID]);
            if ($stmt->fetchColumn() == 0) {
                json_err('您不是該團隊的成員');
            }

            // 查找該團隊對該表單的提交記錄（最新的）
            $stmt = $conn->prepare("
                SELECT fs.*, f.form_name, f.form_category, f.form_updated_d
                FROM formsubdata fs
                INNER JOIN formdata f ON fs.form_ID = f.form_ID
                WHERE fs.form_ID = ? AND fs.fs_team_ID = ?
                ORDER BY fs.fs_created_d DESC
                LIMIT 1
            ");
            $stmt->execute([$form_ID, $team_ID]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$submission) {
                json_ok(['submission' => null]);
            }

            // 獲取答案
            $stmt = $conn->prepare("
                SELECT fa.*, fq.fq_title, fq.fq_type
                FROM formanswerdata fa
                INNER JOIN formquestiondata fq ON fa.fq_ID = fq.fq_ID
                WHERE fa.fs_ID = ?
                ORDER BY fq.fq_order ASC
            ");
            $stmt->execute([$submission['fs_ID']]);
            $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 解析答案（如果是 JSON）
            foreach ($answers as &$ans) {
                if (in_array($ans['fq_type'], ['checkbox', 'select'])) {
                    $decoded = json_decode($ans['fa_value'], true);
                    if (is_array($decoded)) {
                        $ans['fa_value'] = $decoded;
                    }
                }
            }
            unset($ans);

            // 檢查表是否有審核欄位
            $stmt = $conn->query("SHOW COLUMNS FROM formsubdata LIKE 'fs_review_status'");
            $hasReviewStatus = $stmt->fetch() !== false;

            $submissionData = [
                'fs_ID' => $submission['fs_ID'],
                'form_ID' => $submission['form_ID'],
                'form_name' => $submission['form_name'],
                'form_category' => $submission['form_category'],
                'fs_status' => $submission['fs_status'],
                'fs_submitted_d' => $submission['fs_submitted_d'],
                'fs_created_d' => $submission['fs_created_d'],
                'answers' => $answers
            ];
            
            // 如果有審核欄位，添加審核狀態資訊
            if ($hasReviewStatus) {
                $submissionData['fs_review_status'] = $submission['fs_review_status'] ?? null;
                $submissionData['fs_review_remark'] = $submission['fs_review_remark'] ?? null;
            } else {
                // 如果沒有審核欄位，嘗試從 fs_remark 解析
                $remark = $submission['fs_remark'] ?? '';
                if ($remark) {
                    $remarkData = json_decode($remark, true);
                    if (is_array($remarkData)) {
                        $submissionData['fs_review_status'] = $remarkData['review_status'] ?? null;
                        $submissionData['fs_review_remark'] = $remarkData['review_remark'] ?? null;
                    } else {
                        $submissionData['fs_review_status'] = null;
                        $submissionData['fs_review_remark'] = null;
                    }
                } else {
                    $submissionData['fs_review_status'] = null;
                    $submissionData['fs_review_remark'] = null;
                }
            }
            
            // 檢查表單是否有更新（新增題目或修改）
            $needsResubmit = false;
            $form_updated_d = $submission['form_updated_d'] ?? null;
            if ($form_updated_d) {
                $submittedTime = $submission['fs_submitted_d'] ?? $submission['fs_created_d'] ?? null;
                if ($submittedTime) {
                    $formUpdated = strtotime($form_updated_d);
                    $submitted = strtotime($submittedTime);
                    // 如果表單更新時間晚於提交時間，需要重新提交
                    if ($formUpdated > $submitted) {
                        $needsResubmit = true;
                    } else {
                        // 檢查是否有新增題目
                        $stmt = $conn->prepare("
                            SELECT COUNT(DISTINCT fa.fq_ID) as submitted_count
                            FROM formanswerdata fa
                            WHERE fa.fs_ID = ?
                        ");
                        $stmt->execute([$submission['fs_ID']]);
                        $submittedCount = $stmt->fetchColumn();
                        
                        $stmt = $conn->prepare("
                            SELECT COUNT(*) as current_count
                            FROM formquestiondata
                            WHERE form_ID = ?
                        ");
                        $stmt->execute([$form_ID]);
                        $currentCount = $stmt->fetchColumn();
                        
                        // 如果當前題目數量多於已提交的題目數量，需要重新提交
                        if ($currentCount > $submittedCount) {
                            $needsResubmit = true;
                        }
                    }
                }
            }
            
            $submissionData['needs_resubmit'] = $needsResubmit;
            $submissionData['form_updated_d'] = $form_updated_d;

            json_ok(['submission' => $submissionData]);
        } catch (Throwable $e) {
            json_err('獲取團隊提交記錄失敗：' . $e->getMessage());
        }
        break;

    // 獲取選項資料（用於表單題目的選項來源）
    // 注意：此 API 允許所有登入用戶使用，因為學生填寫表單時也需要動態載入選項
    case 'get_form_options':
        try {
            // 檢查是否已登入（但不限制角色）
            $u_ID = $_SESSION['u_ID'] ?? null;
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }

            $option_type = trim($p['option_type'] ?? $_GET['option_type'] ?? '');
            $option_field = trim($p['option_field'] ?? $_GET['option_field'] ?? 'default');

            if (empty($option_type)) {
                json_err('選項類型不能為空');
            }

            $options = [];

            switch ($option_type) {
                case 'classes':
                    // 班級選項（動態載入，只顯示存在的班級）
                    switch ($option_field) {
                        case 'id':
                            $stmt = $conn->prepare("SELECT DISTINCT c_ID as value, c_ID as label FROM classdata WHERE c_ID IS NOT NULL ORDER BY c_ID");
                            break;
                        case 'both':
                            $stmt = $conn->prepare("SELECT DISTINCT c_ID as value, CONCAT(c_ID, ' - ', c_name) as label FROM classdata WHERE c_ID IS NOT NULL ORDER BY c_ID");
                            break;
                        case 'name':
                            // 顯示名稱
                            $stmt = $conn->prepare("SELECT DISTINCT c_ID as value, COALESCE(NULLIF(c_name, ''), c_ID) as label FROM classdata WHERE c_ID IS NOT NULL ORDER BY c_ID");
                            break;
                        default: // 'default'
                            // 預設顯示名稱，如果名稱為空則顯示 ID
                            $stmt = $conn->prepare("SELECT DISTINCT c_ID as value, COALESCE(NULLIF(c_name, ''), c_ID) as label FROM classdata WHERE c_ID IS NOT NULL ORDER BY c_ID");
                    }
                    $stmt->execute();
                    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'cohorts':
                    // 屆別選項（動態載入，只顯示啟用的屆別）
                    switch ($option_field) {
                        case 'id':
                            $stmt = $conn->prepare("SELECT DISTINCT cohort_ID as value, cohort_ID as label FROM cohortdata WHERE cohort_status = 1 AND cohort_ID IS NOT NULL ORDER BY cohort_ID DESC");
                            break;
                        case 'both':
                            $stmt = $conn->prepare("SELECT DISTINCT cohort_ID as value, CONCAT(cohort_ID, ' - ', cohort_name) as label FROM cohortdata WHERE cohort_status = 1 AND cohort_ID IS NOT NULL ORDER BY cohort_ID DESC");
                            break;
                        case 'name':
                            // 顯示名稱
                            $stmt = $conn->prepare("SELECT DISTINCT cohort_ID as value, COALESCE(NULLIF(cohort_name, ''), cohort_ID) as label FROM cohortdata WHERE cohort_status = 1 AND cohort_ID IS NOT NULL ORDER BY cohort_ID DESC");
                            break;
                        default: // 'default'
                            // 預設顯示名稱，如果名稱為空則顯示 ID
                            $stmt = $conn->prepare("SELECT DISTINCT cohort_ID as value, COALESCE(NULLIF(cohort_name, ''), cohort_ID) as label FROM cohortdata WHERE cohort_status = 1 AND cohort_ID IS NOT NULL ORDER BY cohort_ID DESC");
                    }
                    $stmt->execute();
                    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'groups':
                    // 類組選項（動態載入，只顯示啟用的類組）
                    switch ($option_field) {
                        case 'id':
                            $stmt = $conn->prepare("SELECT DISTINCT group_ID as value, group_ID as label FROM groupdata WHERE group_status = 1 AND group_ID IS NOT NULL ORDER BY group_ID");
                            break;
                        case 'both':
                            $stmt = $conn->prepare("SELECT DISTINCT group_ID as value, CONCAT(group_ID, ' - ', group_name) as label FROM groupdata WHERE group_status = 1 AND group_ID IS NOT NULL ORDER BY group_ID");
                            break;
                        case 'name':
                            // 顯示名稱
                            $stmt = $conn->prepare("SELECT DISTINCT group_ID as value, COALESCE(NULLIF(group_name, ''), group_ID) as label FROM groupdata WHERE group_status = 1 AND group_ID IS NOT NULL ORDER BY group_ID");
                            break;
                        default: // 'default'
                            // 預設顯示名稱，如果名稱為空則顯示 ID
                            $stmt = $conn->prepare("SELECT DISTINCT group_ID as value, COALESCE(NULLIF(group_name, ''), group_ID) as label FROM groupdata WHERE group_status = 1 AND group_ID IS NOT NULL ORDER BY group_ID");
                    }
                    $stmt->execute();
                    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'students':
                    // 學生選項（當前屆別的學生）
                    $cohort_ID = null;
                    $stmt = $conn->prepare("SELECT cohort_ID FROM cohortdata WHERE cohort_status = 1 ORDER BY cohort_ID DESC LIMIT 1");
                    $stmt->execute();
                    $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($cohort) {
                        $cohort_ID = $cohort['cohort_ID'];
                    }

                    if ($cohort_ID) {
                        switch ($option_field) {
                            case 'id':
                                $stmt = $conn->prepare("
                                    SELECT DISTINCT u.u_ID as value, u.u_ID as label
                                    FROM userdata u
                                    INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                                    INNER JOIN enrollmentdata e ON u.u_ID = e.enroll_u_ID
                                    WHERE ur.role_ID = 6 
                                      AND ur.user_role_status = 1
                                      AND u.u_status = 1
                                      AND e.cohort_ID = ?
                                    ORDER BY u.u_ID
                                ");
                                break;
                            case 'name':
                                $stmt = $conn->prepare("
                                    SELECT DISTINCT u.u_ID as value, u.u_name as label
                                    FROM userdata u
                                    INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                                    INNER JOIN enrollmentdata e ON u.u_ID = e.enroll_u_ID
                                    WHERE ur.role_ID = 6 
                                      AND ur.user_role_status = 1
                                      AND u.u_status = 1
                                      AND e.cohort_ID = ?
                                    ORDER BY u.u_name
                                ");
                                break;
                            default: // 'default'
                                $stmt = $conn->prepare("
                                    SELECT DISTINCT u.u_ID as value, CONCAT(u.u_ID, ' - ', u.u_name) as label
                                    FROM userdata u
                                    INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                                    INNER JOIN enrollmentdata e ON u.u_ID = e.enroll_u_ID
                                    WHERE ur.role_ID = 6 
                                      AND ur.user_role_status = 1
                                      AND u.u_status = 1
                                      AND e.cohort_ID = ?
                                    ORDER BY u.u_ID
                                ");
                        }
                        $stmt->execute([$cohort_ID]);
                        $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                    break;

                case 'teachers':
                    // 指導老師選項
                    switch ($option_field) {
                        case 'id':
                            $stmt = $conn->prepare("
                                SELECT DISTINCT u.u_ID as value, u.u_ID as label
                                FROM userdata u
                                INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                                WHERE ur.role_ID = 4 
                                  AND ur.user_role_status = 1
                                  AND u.u_status = 1
                                ORDER BY u.u_ID
                            ");
                            break;
                        case 'name':
                            $stmt = $conn->prepare("
                                SELECT DISTINCT u.u_ID as value, u.u_name as label
                                FROM userdata u
                                INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                                WHERE ur.role_ID = 4 
                                  AND ur.user_role_status = 1
                                  AND u.u_status = 1
                                ORDER BY u.u_name
                            ");
                            break;
                        default: // 'default'
                            $stmt = $conn->prepare("
                                SELECT DISTINCT u.u_ID as value, CONCAT(u.u_ID, ' - ', u.u_name) as label
                                FROM userdata u
                                INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                                WHERE ur.role_ID = 4 
                                  AND ur.user_role_status = 1
                                  AND u.u_status = 1
                                ORDER BY u.u_name
                            ");
                    }
                    $stmt->execute();
                    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'teams':
                    // 團隊選項（當前屆別的團隊）- 動態載入，只顯示啟用且存在的團隊
                    $cohort_ID = null;
                    $stmt = $conn->prepare("SELECT cohort_ID FROM cohortdata WHERE cohort_status = 1 ORDER BY cohort_ID DESC LIMIT 1");
                    $stmt->execute();
                    $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($cohort) {
                        $cohort_ID = $cohort['cohort_ID'];
                    }

                    if ($cohort_ID) {
                        switch ($option_field) {
                            case 'id':
                                $stmt = $conn->prepare("
                                    SELECT DISTINCT t.team_ID as value, t.team_ID as label
                                    FROM teamdata t
                                    WHERE t.cohort_ID = ? 
                                      AND t.team_status = 1
                                      AND EXISTS (
                                          SELECT 1 FROM teammember tm 
                                          WHERE tm.team_ID = t.team_ID 
                                            AND tm.tm_status = 1
                                      )
                                    ORDER BY t.team_ID
                                ");
                                break;
                            case 'both':
                                $stmt = $conn->prepare("
                                    SELECT DISTINCT t.team_ID as value, CONCAT(t.team_ID, ' - ', t.team_project_name) as label
                                    FROM teamdata t
                                    WHERE t.cohort_ID = ? 
                                      AND t.team_status = 1
                                      AND EXISTS (
                                          SELECT 1 FROM teammember tm 
                                          WHERE tm.team_ID = t.team_ID 
                                            AND tm.tm_status = 1
                                      )
                                    ORDER BY t.team_ID
                                ");
                                break;
                            default: // 'default'
                                $stmt = $conn->prepare("
                                    SELECT DISTINCT t.team_ID as value, t.team_project_name as label
                                    FROM teamdata t
                                    WHERE t.cohort_ID = ? 
                                      AND t.team_status = 1
                                      AND EXISTS (
                                          SELECT 1 FROM teammember tm 
                                          WHERE tm.team_ID = t.team_ID 
                                            AND tm.tm_status = 1
                                      )
                                    ORDER BY t.team_ID
                                ");
                        }
                        $stmt->execute([$cohort_ID]);
                        $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                    break;

                default:
                    json_err('不支援的選項類型');
            }

            json_ok(['options' => $options]);
        } catch (Throwable $e) {
            json_err('獲取選項資料失敗：' . $e->getMessage());
        }
        break;

    // 獲取所有可用的選項類型列表
    case 'get_option_types':
        try {
            checkFormAdminPermission();

            $optionTypes = [
                ['value' => 'classes', 'label' => '班級'],
                ['value' => 'cohorts', 'label' => '屆別'],
                ['value' => 'groups', 'label' => '類組'],
                ['value' => 'students', 'label' => '學生（當前屆別）'],
                ['value' => 'teachers', 'label' => '指導老師'],
                ['value' => 'teams', 'label' => '團隊（當前屆別）']
            ];

            json_ok(['option_types' => $optionTypes]);
        } catch (Throwable $e) {
            json_err('獲取選項類型失敗：' . $e->getMessage());
        }
        break;

    // ==================== 表單流程管理 ====================

    // 獲取表單流程列表
    case 'get_form_flows':
        try {
            // 檢查資料庫連線
            if (!isset($conn)) {
                error_log('get_form_flows: $conn 未設定');
                json_err('資料庫連線失敗', 'DB_ERROR', 500);
            }

            $sql = "
                SELECT 
                    ff.ff_ID,
                    ff.ff_order,
                    ff.form_ID,
                    ff.ff_name,
                    ff.ff_enabled,
                    f.form_name
                FROM formflowdata ff
                LEFT JOIN formdata f ON ff.form_ID = f.form_ID
                ORDER BY ff.ff_order ASC
            ";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $errorInfo = $conn->errorInfo();
                error_log('get_form_flows: prepare 失敗 - ' . print_r($errorInfo, true));
                json_err('SQL 準備失敗: ' . ($errorInfo[2] ?? '未知錯誤'), 'SQL_ERROR', 500);
            }

            $stmt->execute();
            $flows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 重新計算順序號：只有啟用的流程才有順序號（1, 2, 3...），停用的顯示X
            $enabledOrder = 1;
            foreach ($flows as &$flow) {
                if ($flow['ff_enabled'] == 1) {
                    // 啟用的流程：使用新的順序號
                    $flow['display_order'] = $enabledOrder;
                    $enabledOrder++;
                } else {
                    // 停用的流程：顯示X
                    $flow['display_order'] = 'X';
                }
            }
            unset($flow);

            json_ok(['flows' => $flows]);
        } catch (PDOException $e) {
            error_log('get_form_flows PDO 錯誤: ' . $e->getMessage() . ' | 檔案: ' . $e->getFile() . ' | 行號: ' . $e->getLine());
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            error_log('get_form_flows 錯誤: ' . $e->getMessage() . ' | 檔案: ' . $e->getFile() . ' | 行號: ' . $e->getLine() . ' | 堆疊: ' . $e->getTraceAsString());
            json_err('獲取流程列表失敗：' . $e->getMessage());
        }
        break;

    // 按類組獲取表單流程列表
    case 'get_form_flows_by_group':
        try {
            $group_ID = isset($_GET['group_ID']) ? (int)$_GET['group_ID'] : 0;
            
            if ($group_ID <= 0) {
                json_err('請選擇類組');
            }

            // 查詢該類組的所有流程，使用 flowgrouptypedata 表
            $sql = "
                SELECT 
                    ff.ff_ID,
                    fgt.fgt_order,
                    ff.form_ID,
                    ff.ff_name,
                    ff.ff_enabled,
                    f.form_name,
                    fgt.group_ID,
                    fgt.fgt_status_ID
                FROM flowgrouptypedata fgt
                JOIN formflowdata ff ON fgt.ff_ID = ff.ff_ID
                LEFT JOIN formdata f ON ff.form_ID = f.form_ID
                WHERE fgt.group_ID = ?
                ORDER BY fgt.fgt_order ASC
            ";

            $stmt = $conn->prepare($sql);
            $stmt->execute([$group_ID]);
            $flows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 重新計算順序號：只有啟用的流程才有順序號（1, 2, 3...），停用的顯示X
            $enabledOrder = 1;
            foreach ($flows as &$flow) {
                if ($flow['ff_enabled'] == 1 && $flow['fgt_status_ID'] == 1) {
                    // 啟用的流程：使用新的順序號
                    $flow['display_order'] = $enabledOrder;
                    $enabledOrder++;
                } else {
                    // 停用的流程：顯示X
                    $flow['display_order'] = 'X';
                }
            }
            unset($flow);

            json_ok(['flows' => $flows, 'group_ID' => $group_ID]);
        } catch (PDOException $e) {
            error_log('get_form_flows_by_group PDO 錯誤: ' . $e->getMessage());
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            error_log('get_form_flows_by_group 錯誤: ' . $e->getMessage());
            json_err('獲取流程列表失敗：' . $e->getMessage());
        }
        break;

    // 獲取流程詳情（包含類組信息）
    case 'get_form_flow_detail':
        try {
            $ff_ID = isset($p['ff_ID']) ? (int)$p['ff_ID'] : (isset($_GET['ff_ID']) ? (int)$_GET['ff_ID'] : 0);
            $group_ID = isset($_GET['group_ID']) ? (int)$_GET['group_ID'] : null;

            if ($ff_ID <= 0) {
                json_err('流程ID無效');
            }

            // 獲取流程基本信息
            $stmt = $conn->prepare("
                SELECT * FROM formflowdata WHERE ff_ID = ?
            ");
            $stmt->execute([$ff_ID]);
            $flow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$flow) {
                json_err('找不到該流程');
            }

            // 如果指定了類組ID，獲取該類組的順序信息
            if ($group_ID > 0) {
                $stmt = $conn->prepare("
                    SELECT fgt_order, fgt_status_ID 
                    FROM flowgrouptypedata 
                    WHERE ff_ID = ? AND group_ID = ?
                ");
                $stmt->execute([$ff_ID, $group_ID]);
                $groupFlow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($groupFlow) {
                    $flow['group_ID'] = $group_ID;
                    $flow['fgt_order'] = $groupFlow['fgt_order'];
                    $flow['fgt_status_ID'] = $groupFlow['fgt_status_ID'];
                }
            } else {
                // 如果沒有指定類組，獲取第一個類組的信息（用於顯示）
                $stmt = $conn->prepare("
                    SELECT group_ID, fgt_order, fgt_status_ID 
                    FROM flowgrouptypedata 
                    WHERE ff_ID = ?
                    ORDER BY fgt_order ASC
                    LIMIT 1
                ");
                $stmt->execute([$ff_ID]);
                $groupFlow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($groupFlow) {
                    $flow['group_ID'] = $groupFlow['group_ID'];
                    $flow['fgt_order'] = $groupFlow['fgt_order'];
                    $flow['fgt_status_ID'] = $groupFlow['fgt_status_ID'];
                }
            }

            json_ok(['flow' => $flow]);
        } catch (Throwable $e) {
            json_err('獲取流程詳情失敗：' . $e->getMessage());
        }
        break;

    // 儲存流程（新增或更新）
    case 'save_form_flow':
        try {
            $admin_ID = checkFormAdminPermission();

            $ff_ID = isset($p['ff_ID']) ? (int)$p['ff_ID'] : 0;
            $form_ID = isset($p['form_ID']) ? (int)$p['form_ID'] : 0;
            $ff_name = trim($p['ff_name'] ?? '');
            $ff_enabled = isset($p['ff_enabled']) ? (int)$p['ff_enabled'] : 1;
            $group_ID = isset($p['group_ID']) ? (int)$p['group_ID'] : 0;

            if ($form_ID <= 0) {
                json_err('請選擇表單');
            }

            if (empty($ff_name)) {
                json_err('請輸入流程名稱');
            }

            if ($group_ID <= 0) {
                json_err('請選擇類組');
            }

            $conn->beginTransaction();

            if ($ff_ID > 0) {
                // 更新流程基本信息
                $stmt = $conn->prepare("
                    UPDATE formflowdata SET
                        form_ID = ?,
                        ff_name = ?,
                        ff_enabled = ?
                    WHERE ff_ID = ?
                ");
                $stmt->execute([$form_ID, $ff_name, $ff_enabled, $ff_ID]);

                // 檢查該流程是否已存在於指定類組中
                $checkStmt = $conn->prepare("SELECT fgt_ID FROM flowgrouptypedata WHERE ff_ID = ? AND group_ID = ?");
                $checkStmt->execute([$ff_ID, $group_ID]);
                $exists = $checkStmt->fetch();

                if (!$exists) {
                    // 如果不存在，為該類組創建流程順序記錄
                    $stmt = $conn->prepare("SELECT MAX(fgt_order) FROM flowgrouptypedata WHERE group_ID = ?");
                    $stmt->execute([$group_ID]);
                    $maxGroupOrder = $stmt->fetchColumn() ?: 0;
                    $newGroupOrder = $maxGroupOrder + 1;

                    $stmt = $conn->prepare("
                        INSERT INTO flowgrouptypedata (ff_ID, group_ID, fgt_order, fgt_status_ID)
                        VALUES (?, ?, ?, 1)
                    ");
                    $stmt->execute([$ff_ID, $group_ID, $newGroupOrder]);
                }
            } else {
                // 新增 - 獲取最大順序（用於 formflowdata 表）
                $stmt = $conn->prepare("SELECT MAX(ff_order) FROM formflowdata");
                $stmt->execute();
                $maxOrder = $stmt->fetchColumn() ?: 0;
                $newOrder = $maxOrder + 1;

                $stmt = $conn->prepare("
                    INSERT INTO formflowdata (form_ID, ff_name, ff_enabled, ff_order)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$form_ID, $ff_name, $ff_enabled, $newOrder]);
                $ff_ID = $conn->lastInsertId();

                // 為指定的類組創建流程順序記錄
                // 獲取該類組的最大順序
                $stmt = $conn->prepare("SELECT MAX(fgt_order) FROM flowgrouptypedata WHERE group_ID = ?");
                $stmt->execute([$group_ID]);
                $maxGroupOrder = $stmt->fetchColumn() ?: 0;
                $newGroupOrder = $maxGroupOrder + 1;

                // 檢查是否已存在該組合的記錄（理論上不應該存在，因為是新流程）
                $checkStmt = $conn->prepare("SELECT fgt_ID FROM flowgrouptypedata WHERE ff_ID = ? AND group_ID = ?");
                $checkStmt->execute([$ff_ID, $group_ID]);
                if (!$checkStmt->fetch()) {
                    // 為該類組創建流程順序記錄
                    $stmt = $conn->prepare("
                        INSERT INTO flowgrouptypedata (ff_ID, group_ID, fgt_order, fgt_status_ID)
                        VALUES (?, ?, ?, 1)
                    ");
                    $stmt->execute([$ff_ID, $group_ID, $newGroupOrder]);
                }
            }

            $conn->commit();
            json_ok(['message' => '儲存成功']);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('儲存失敗：' . $e->getMessage());
        }
        break;

    // 更新流程順序（按類組）
    case 'update_flow_order':
        try {
            checkFormAdminPermission();

            // 獲取類組ID
            $group_ID = isset($p['group_ID']) ? (int)$p['group_ID'] : (isset($_GET['group_ID']) ? (int)$_GET['group_ID'] : 0);
            
            if ($group_ID <= 0) {
                json_err('請選擇類組');
            }

            // 如果 $p['orders'] 已經是陣列（JSON 請求已解析），直接使用
            // 如果是字串（表單請求），則需要 json_decode
            if (isset($p['orders'])) {
                if (is_array($p['orders'])) {
                    $orders = $p['orders'];
                } else {
                    $orders = json_decode($p['orders'], true);
                }
            } else {
                $orders = [];
            }

            if (!is_array($orders) || empty($orders)) {
                json_err('順序資料格式錯誤');
            }

            $conn->beginTransaction();

            // 更新 flowgrouptypedata 表中的順序
            foreach ($orders as $order) {
                $ff_ID = (int)($order['ff_ID'] ?? 0);
                $fgt_order = (int)($order['ff_order'] ?? 0); // 這裡使用 ff_order 作為 fgt_order

                if ($ff_ID > 0 && $fgt_order > 0) {
                    // 檢查是否已存在該組合的記錄
                    $checkStmt = $conn->prepare("
                        SELECT fgt_ID FROM flowgrouptypedata 
                        WHERE ff_ID = ? AND group_ID = ?
                    ");
                    $checkStmt->execute([$ff_ID, $group_ID]);
                    $exists = $checkStmt->fetch();

                    if ($exists) {
                        // 更新現有記錄
                        $stmt = $conn->prepare("
                            UPDATE flowgrouptypedata 
                            SET fgt_order = ? 
                            WHERE ff_ID = ? AND group_ID = ?
                        ");
                        $stmt->execute([$fgt_order, $ff_ID, $group_ID]);
                    } else {
                        // 如果不存在，創建新記錄
                        $stmt = $conn->prepare("
                            INSERT INTO flowgrouptypedata (ff_ID, group_ID, fgt_order, fgt_status_ID)
                            VALUES (?, ?, ?, 1)
                        ");
                        $stmt->execute([$ff_ID, $group_ID, $fgt_order]);
                    }
                }
            }

            $conn->commit();
            json_ok(['message' => '順序更新成功']);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('更新順序失敗：' . $e->getMessage());
        }
        break;

    // 切換流程啟用狀態
    case 'toggle_form_flow':
        try {
            checkFormAdminPermission();

            $ff_ID = isset($p['ff_ID']) ? (int)$p['ff_ID'] : 0;
            $ff_enabled = isset($p['ff_enabled']) ? (int)$p['ff_enabled'] : 0;

            if ($ff_ID <= 0) {
                json_err('流程ID無效');
            }

            $conn->beginTransaction();

            // 更新流程狀態
            $stmt = $conn->prepare("
                UPDATE formflowdata SET ff_enabled = ? WHERE ff_ID = ?
            ");
            $stmt->execute([$ff_enabled, $ff_ID]);

            // 重新計算啟用流程的順序號（從1開始）
            $stmt = $conn->prepare("
                SELECT ff_ID FROM formflowdata 
                WHERE ff_enabled = 1 
                ORDER BY ff_order ASC
            ");
            $stmt->execute();
            $enabledFlows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // 更新啟用流程的順序號
            foreach ($enabledFlows as $index => $flowId) {
                $newOrder = $index + 1;
                $stmt = $conn->prepare("
                    UPDATE formflowdata SET ff_order = ? WHERE ff_ID = ?
                ");
                $stmt->execute([$newOrder, $flowId]);
            }

            $conn->commit();
            json_ok(['message' => '狀態更新成功']);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('更新狀態失敗：' . $e->getMessage());
        }
        break;

    // 刪除流程
    case 'delete_form_flow':
        try {
            checkFormAdminPermission();

            $ff_ID = isset($p['ff_ID']) ? (int)$p['ff_ID'] : 0;

            if ($ff_ID <= 0) {
                json_err('流程ID無效');
            }

            $stmt = $conn->prepare("DELETE FROM formflowdata WHERE ff_ID = ?");
            $stmt->execute([$ff_ID]);

            // 重新排序
            $stmt = $conn->prepare("SELECT ff_ID FROM formflowdata ORDER BY ff_order ASC");
            $stmt->execute();
            $flows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $conn->beginTransaction();
            foreach ($flows as $index => $id) {
                $stmt = $conn->prepare("UPDATE formflowdata SET ff_order = ? WHERE ff_ID = ?");
                $stmt->execute([$index + 1, $id]);
            }
            $conn->commit();

            json_ok(['message' => '刪除成功']);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('刪除失敗：' . $e->getMessage());
        }
        break;

    // 獲取團隊當前應該填寫的表單（根據流程步驟）
    case 'get_team_current_form':
        try {
            $u_ID = $_SESSION['u_ID'] ?? null;
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }

            $team_ID = isset($p['team_ID']) ? (int)$p['team_ID'] : (isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : 0);

            // 如果沒有提供 team_ID，嘗試從 session 中獲取（僅對學生角色）
            if ($team_ID <= 0) {
                $role_ID = $_SESSION['role_ID'] ?? null;
                // 只有學生角色才需要自動獲取 team_ID
                if ($role_ID == 6) {
                    // 檢查 teammember 表結構（兼容兩種版本）
                    $teamUserField = 'team_u_ID';
                    try {
                        $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                        $stmt->execute();
                        if (!$stmt->fetch()) {
                            $teamUserField = 'u_ID';
                        }
                    } catch (Exception $e) {
                        $teamUserField = 'u_ID';
                    }

                    // 獲取學生的團隊ID
                    $stmt = $conn->prepare("
                        SELECT t.team_ID 
                        FROM teammember tm
                        INNER JOIN teamdata t ON tm.team_ID = t.team_ID
                        WHERE tm.{$teamUserField} = ? AND t.team_status = 1
                        LIMIT 1
                    ");
                    $stmt->execute([$u_ID]);
                    $team = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($team) {
                        $team_ID = (int)$team['team_ID'];
                    }
                }
            }

            if ($team_ID <= 0) {
                json_err('團隊ID無效或您尚未加入任何團隊');
            }

            // 檢查團隊是否存在
            $stmt = $conn->prepare("SELECT team_ID FROM teamdata WHERE team_ID = ?");
            $stmt->execute([$team_ID]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$team) {
                json_err('找不到該團隊');
            }

            // 不再使用 team_flow_step 和 formflowdata，直接返回無表單需要處理
            json_ok(['form' => null, 'message' => '目前沒有需要填寫的表單', 'needs_attention' => false]);
        } catch (Throwable $e) {
            json_err('獲取當前表單失敗：' . $e->getMessage());
        }
        break;

    // 獲取團隊的所有流程步驟（用於顯示進度）
    case 'get_team_flow_progress':
        try {
            $team_ID = isset($p['team_ID']) ? (int)$p['team_ID'] : (isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : 0);

            if ($team_ID <= 0) {
                json_err('團隊ID無效');
            }

            // 檢查團隊是否存在
            $stmt = $conn->prepare("SELECT team_ID FROM teamdata WHERE team_ID = ?");
            $stmt->execute([$team_ID]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$team) {
                json_err('找不到該團隊');
            }

            // 不再使用 team_flow_step 和 formflowdata，直接返回空結果
            json_ok(['flows' => [], 'current_step' => 1]);
        } catch (Throwable $e) {
            json_err('獲取流程進度失敗：' . $e->getMessage());
        }
        break;

    // 匯出表單為 PDF（獲取匯出頁面 URL）
    case 'get_form_export_url':
        try {
            $fs_ID = isset($p['fs_ID']) ? (int)$p['fs_ID'] : (isset($_GET['fs_ID']) ? (int)$_GET['fs_ID'] : 0);
            $u_ID = $_SESSION['u_ID'] ?? null;

            if ($fs_ID <= 0) {
                json_err('提交ID無效');
            }

            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }

            // 檢查權限（提交者、同團隊成員或管理員可以查看）
            $stmt = $conn->prepare("
                SELECT fs_u_ID, fs_team_ID FROM formsubdata WHERE fs_ID = ?
            ");
            $stmt->execute([$fs_ID]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$submission) {
                json_err('找不到該提交記錄');
            }

            $isAdmin = false;
            if ($role_ID = $_SESSION['role_ID'] ?? null) {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) 
                    FROM userrolesdata 
                    WHERE ur_u_ID = ? AND role_ID IN (1, 2) AND user_role_status = 1
                ");
                $stmt->execute([$u_ID]);
                $isAdmin = $stmt->fetchColumn() > 0;
            }

            $isSubmitter = ($submission['fs_u_ID'] === $u_ID);
            $isTeamMember = false;

            // 如果是團隊表單，檢查是否為團隊成員
            if (!$isAdmin && !$isSubmitter && $submission['fs_team_ID']) {
                $teamUserField = 'team_u_ID';
                $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $stmt->execute();
                if (!$stmt->fetch()) {
                    $teamUserField = 'u_ID';
                }

                $stmt = $conn->prepare("
                    SELECT COUNT(*) 
                    FROM teammember 
                    WHERE team_ID = ? AND {$teamUserField} = ? AND tm_status = 1
                ");
                $stmt->execute([$submission['fs_team_ID'], $u_ID]);
                $isTeamMember = $stmt->fetchColumn() > 0;
            }

            if (!$isAdmin && !$isSubmitter && !$isTeamMember) {
                json_err('無權限查看此提交記錄', 'NO_PERMISSION', 403);
            }

            json_ok(['export_url' => 'pages/form_export.php?fs_ID=' . $fs_ID]);
        } catch (Throwable $e) {
            json_err('獲取匯出URL失敗：' . $e->getMessage());
        }
        break;

    // 獲取團隊對應的表單（專題初審單）
    case 'get_team_form':
        try {
            $u_ID = checkStudentPermission();

            $team_ID = isset($p['team_ID']) ? (int)$p['team_ID'] : (isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : 0);

            if ($team_ID <= 0) {
                json_err('團隊ID無效');
            }

            // 檢查用戶是否在該團隊中
            $teamUserField = 'team_u_ID';
            $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $teamUserField = 'u_ID';
            }

            $stmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM teammember 
                WHERE team_ID = ? AND {$teamUserField} = ? AND tm_status = 1
            ");
            $stmt->execute([$team_ID, $u_ID]);
            if ($stmt->fetchColumn() == 0) {
                json_err('您不是該團隊的成員');
            }

            // 查找專題初審單（form_category = '專題初審單'）
            $stmt = $conn->prepare("
                SELECT form_ID, form_name, form_des, form_category
                FROM formdata
                WHERE form_category = '專題初審單' AND form_status = 1
                ORDER BY form_created_d DESC
                LIMIT 1
            ");
            $stmt->execute();
            $form = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$form) {
                json_err('目前沒有可用的專題初審單');
            }

            // 檢查是否已提交
            $stmt = $conn->prepare("
                SELECT fs_ID, fs_status, fs_submitted_d
                FROM formsubdata
                WHERE form_ID = ? AND fs_team_ID = ?
                ORDER BY fs_submitted_d DESC
                LIMIT 1
            ");
            $stmt->execute([$form['form_ID'], $team_ID]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);

            $form['submitted'] = $submission !== false;
            $form['submission_id'] = $submission ? $submission['fs_ID'] : null;
            $form['submission_status'] = $submission ? (int)$submission['fs_status'] : null;

            json_ok(['form' => $form]);
        } catch (Throwable $e) {
            json_err('獲取表單失敗：' . $e->getMessage());
        }
        break;

    // 測試 Google Gemini API 連線
    case 'test_gemini_connection':
        try {
            checkFormAdminPermission();

            // 讀取 Google API Key
            $googleApiKey = getenv('GOOGLE_API_KEY');
            if (empty($googleApiKey)) {
                $configFile = __DIR__ . '/../includes/ai_config.php';
                if (file_exists($configFile)) {
                    $config = include $configFile;
                    $googleApiKey = $config['google_api_key'] ?? '';
                }
            }

            if (empty($googleApiKey)) {
                json_err('API Key 未設定，請檢查 includes/ai_config.php');
            }

            $configFile = __DIR__ . '/../includes/ai_config.php';
            $config = file_exists($configFile) ? include $configFile : [];
            $model = $config['google_model'] ?? 'gemini-1.5-flash-latest';

            $result = testGoogleGeminiConnection($googleApiKey, $model);

            if ($result['success']) {
                json_ok([
                    'message' => '✅ API 連線成功！',
                    'details' => $result['message']
                ]);
            } else {
                json_err('❌ API 連線失敗：' . $result['message']);
            }
        } catch (Throwable $e) {
            json_err('測試失敗：' . $e->getMessage());
        }
        break;

    // 識別表單題目（從上傳的文件中）
    case 'recognize_form_questions':
        try {
            // 記錄請求資訊
            error_log('recognize_form_questions: 開始處理請求');
            error_log('POST 資料: ' . print_r($_POST, true));
            error_log('FILES 資料: ' . print_r($_FILES, true));

            checkFormAdminPermission();

            // 檢查是否有上傳文件
            if (empty($_FILES['file']['name'])) {
                error_log('recognize_form_questions: 沒有上傳文件');
                json_err('請選擇要上傳的文件');
            }

            $file = $_FILES['file'];
            $fileName = $file['name'];
            $fileTmp = $file['tmp_name'];
            $fileSize = $file['size'];
            $fileError = $file['error'];

            error_log("recognize_form_questions: 文件資訊 - 名稱: $fileName, 大小: $fileSize, 錯誤碼: $fileError");

            if ($fileError !== UPLOAD_ERR_OK) {
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => '文件大小超過 php.ini 中的 upload_max_filesize 設定',
                    UPLOAD_ERR_FORM_SIZE => '文件大小超過表單中的 MAX_FILE_SIZE 設定',
                    UPLOAD_ERR_PARTIAL => '文件只有部分被上傳',
                    UPLOAD_ERR_NO_FILE => '沒有文件被上傳',
                    UPLOAD_ERR_NO_TMP_DIR => '找不到臨時資料夾',
                    UPLOAD_ERR_CANT_WRITE => '文件寫入失敗',
                    UPLOAD_ERR_EXTENSION => 'PHP 擴展阻止了文件上傳'
                ];
                $errorMsg = $errorMessages[$fileError] ?? "文件上傳失敗 (錯誤碼: $fileError)";
                error_log("recognize_form_questions: 上傳錯誤 - $errorMsg");
                json_err($errorMsg);
            }

            // 檢查文件類型
            $allowedTypes = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg'];
            $fileType = mime_content_type($fileTmp);
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (!in_array($fileType, $allowedTypes) && !in_array($fileExt, ['pdf', 'png', 'jpg', 'jpeg'])) {
                json_err('僅支援 PDF、PNG、JPG 格式');
            }

            // 讀取文件內容
            $fileContent = file_get_contents($fileTmp);

            // 識別題目
            error_log('========== 開始識別題目 ==========');
            error_log('檔案名稱: ' . $fileName);
            error_log('檔案大小: ' . $fileSize . ' bytes');
            error_log('檔案類型: ' . $fileExt);
            error_log('檔案內容長度: ' . strlen($fileContent) . ' bytes');

            try {
                $questions = recognizeQuestionsFromFile($fileContent, $fileExt);
                error_log('識別函數返回: ' . count($questions) . ' 個題目');
            } catch (Exception $e) {
                error_log('❌ 識別過程發生異常: ' . $e->getMessage());
                error_log('異常堆疊: ' . $e->getTraceAsString());
                throw $e; // 重新拋出異常，讓外層 catch 處理
            }

            if (empty($questions)) {
                error_log('❌ 識別結果為空');

                // 檢查是否有配置 AI（用於提供更準確的錯誤訊息）
                $googleApiKey = getenv('GOOGLE_API_KEY');
                if (empty($googleApiKey)) {
                    $configFile = __DIR__ . '/../includes/ai_config.php';
                    if (file_exists($configFile)) {
                        $config = include $configFile;
                        $googleApiKey = $config['google_api_key'] ?? '';
                    }
                }
                $hasAI = !empty($googleApiKey);

                // 提供更詳細的錯誤訊息
                $errorMsg = '無法識別題目。';
                if ($fileExt === 'pdf') {
                    if ($hasAI) {
                        $errorMsg .= '可能原因：1) PDF 是掃描版（圖片）且 AI 識別失敗 2) PDF 格式特殊無法解析';
                        $errorMsg .= '。建議：請確認 PDF 是文字型（非掃描版），或手動新增題目。';
                    } else {
                        $errorMsg .= '可能原因：1) PDF 是掃描版（圖片）無法提取文字 2) 系統未安裝 PDF 文字提取工具';
                        $errorMsg .= '。建議：請確認 PDF 是文字型（非掃描版），或設定 AI API Key 以支援掃描版 PDF，或手動新增題目。';
                    }
                } else {
                    if ($hasAI) {
                        $errorMsg .= '圖片格式需要 AI 識別，但識別失敗。';
                        $errorMsg .= '建議：請檢查圖片清晰度，或手動新增題目。';
                    } else {
                        $errorMsg .= '圖片格式需要 AI 識別，但未設定 AI API Key。';
                        $errorMsg .= '建議：請設定 AI API Key 以支援圖片識別，或手動新增題目。';
                    }
                }

                json_err($errorMsg);
            }

            error_log('識別成功: 找到 ' . count($questions) . ' 個題目');
            json_ok([
                'questions' => $questions,
                'message' => '成功識別 ' . count($questions) . ' 個題目'
            ]);
        } catch (Throwable $e) {
            // 記錄詳細錯誤信息以便調試
            $errorDetails = [
                'message' => $e->getMessage(),
                'file' => $fileName ?? 'unknown',
                'size' => $fileSize ?? 0,
                'type' => $fileExt ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ];
            error_log('識別題目錯誤: ' . json_encode($errorDetails, JSON_UNESCAPED_UNICODE));
            json_err('識別失敗：' . $e->getMessage());
        }
        break;

    case 'fill_template_with_recognized_data':
        try {
            checkFormAdminPermission();

            // 檢查是否有上傳文件
            if (empty($_FILES['template_file']['name']) || empty($_FILES['form_file']['name'])) {
                json_err('請上傳標準範本文件和要填寫的表單文件');
            }

            $templateFile = $_FILES['template_file'];
            $formFile = $_FILES['form_file'];

            // 檢查文件類型
            $templateExt = strtolower(pathinfo($templateFile['name'], PATHINFO_EXTENSION));
            $formExt = strtolower(pathinfo($formFile['name'], PATHINFO_EXTENSION));

            if (!in_array($templateExt, ['pdf', 'docx', 'doc'])) {
                json_err('標準範本僅支援 PDF、DOCX、DOC 格式');
            }

            if (!in_array($formExt, ['pdf', 'png', 'jpg', 'jpeg'])) {
                json_err('表單文件僅支援 PDF、PNG、JPG 格式');
            }

            // 讀取文件內容
            $templateContent = file_get_contents($templateFile['tmp_name']);
            $formContent = file_get_contents($formFile['tmp_name']);

            // 步驟 1: OCR + AI 識別表單內容
            error_log('開始識別表單內容...');
            $recognizedData = recognizeFormContentWithAI($formContent, $formExt);

            if (empty($recognizedData)) {
                json_err('無法識別表單內容，請確認文件清晰且包含可識別的文字');
            }

            // 步驟 2: 將識別出的內容填入標準範本
            error_log('開始填入標準範本...');
            $filledTemplate = fillTemplateWithData($templateContent, $templateExt, $recognizedData);

            if (empty($filledTemplate)) {
                json_err('填入範本失敗');
            }

            // 步驟 3: 儲存填好的文件並返回
            $outputFileName = 'filled_' . uniqid() . '.' . $templateExt;
            $outputDir = __DIR__ . '/../uploads/filled_templates/';
            if (!is_dir($outputDir)) {
                if (!mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
                    throw new Exception('無法建立輸出目錄');
                }
            }
            $outputPath = $outputDir . $outputFileName;
            if (file_put_contents($outputPath, $filledTemplate) === false) {
                throw new Exception('無法儲存填好的文件');
            }

            json_ok([
                'file_url' => 'uploads/filled_templates/' . $outputFileName,
                'file_name' => $outputFileName,
                'recognized_data' => $recognizedData,
                'message' => '成功識別並填入範本'
            ]);
        } catch (Throwable $e) {
            error_log('填入範本錯誤: ' . $e->getMessage());
            error_log('堆疊: ' . $e->getTraceAsString());
            json_err('處理失敗：' . $e->getMessage());
        }
        break;

    case 'get_user_data_for_template':
        try {
            // 獲取用戶資料預覽（用於前端顯示）
            $u_ID = $_SESSION['u_ID'] ?? null;
            if (!$u_ID) {
                json_err('請先登入');
            }

            $targetUserID = $u_ID;
            $targetTeamID = null;

            // 先檢查當前用戶是否為學生（role_ID = 6）
            $stmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM userrolesdata 
                WHERE ur_u_ID = ? AND role_ID = 6 AND user_role_status = 1
            ");
            $stmt->execute([$u_ID]);
            $isStudent = $stmt->fetchColumn() > 0;

            // 檢查是否為管理員
            $role_ID = $_SESSION['role_ID'] ?? null;
            $isAdmin = in_array($role_ID, [1, 2]);

            // 開放式功能：任何人都可以使用
            // 如果當前用戶是學生，直接使用學生的資料
            // 如果管理員指定了 team_ID 或 u_ID，使用指定的
            // 否則，使用當前登入者的資料（即使是管理員也可以使用自己的資料）
            if ($isStudent) {
                // 學生直接使用自己的資料，不需要額外處理
                // $targetUserID 已經是 $u_ID，繼續使用
            } elseif ($isAdmin && isset($_POST['team_ID']) && !empty($_POST['team_ID'])) {
                $targetTeamID = (int)$_POST['team_ID'];
                // 如果只提供了 team_ID，需要獲取團隊的第一個成員作為 u_ID
                if (empty($_POST['u_ID'])) {
                    $teamUserField = 'team_u_ID';
                    $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                    $checkStmt->execute();
                    if (!$checkStmt->fetch()) {
                        $teamUserField = 'u_ID';
                    }
                    $stmt = $conn->prepare("
                        SELECT {$teamUserField} as u_ID
                        FROM teammember
                        WHERE team_ID = ? AND tm_status = 1
                        ORDER BY {$teamUserField}
                        LIMIT 1
                    ");
                    $stmt->execute([$targetTeamID]);
                    $firstMember = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($firstMember) {
                        $targetUserID = $firstMember['u_ID'];
                    } else {
                        json_err('該團隊沒有成員');
                    }
                } else {
                    $targetUserID = $_POST['u_ID'];
                }
            } elseif (isset($_POST['u_ID']) && !empty($_POST['u_ID']) && $isAdmin) {
                // 管理員指定了特定的學生
                $targetUserID = $_POST['u_ID'];
            } else {
                // 開放式功能：使用當前登入者的資料（不管是學生還是管理員）
                // $targetUserID 已經是 $u_ID，繼續使用
                // 這樣即使是管理員也可以使用自己的資料來測試功能
            }

            $userData = getUserDataForTemplate($targetUserID, $targetTeamID);

            if (empty($userData)) {
                json_err('無法獲取用戶資料');
            }

            // 格式化返回資料（用於前端顯示）
            json_ok([
                'user_data' => [
                    'u_ID' => $userData['student_id'] ?? '',
                    'u_name' => $userData['student_name'] ?? '',
                    'class_name' => $userData['class'] ?? '',
                    'team_project_name' => $userData['team_name'] ?? $userData['project_title'] ?? '',
                    'advisor_name' => $userData['advisor'] ?? '',
                    'team_members' => $userData['team_members'] ?? ''
                ]
            ]);
        } catch (Throwable $e) {
            error_log('獲取用戶資料預覽錯誤: ' . $e->getMessage());
            json_err('獲取用戶資料失敗：' . $e->getMessage());
        }
        break;

    case 'fill_template_with_user_data':
        try {
            // 這個功能允許學生和管理員使用
            // 學生：使用自己的資料
            // 管理員：可以指定 team_ID 或 u_ID

            $u_ID = $_SESSION['u_ID'] ?? null;
            if (!$u_ID) {
                json_err('請先登入');
            }

            // 檢查是否有上傳標準範本文件
            if (empty($_FILES['template_file']['name'])) {
                json_err('請上傳標準範本文件');
            }

            $templateFile = $_FILES['template_file'];
            $templateExt = strtolower(pathinfo($templateFile['name'], PATHINFO_EXTENSION));

            if (!in_array($templateExt, ['pdf', 'docx', 'doc'])) {
                json_err('標準範本僅支援 PDF、DOCX、DOC 格式');
            }

            // 讀取範本內容
            $templateContent = file_get_contents($templateFile['tmp_name']);

            // 獲取要填入的 team_ID（管理員可以指定，學生使用自己的團隊）
            $targetTeamID = null;
            $targetUserID = $u_ID;

            // 先檢查當前用戶是否為學生（role_ID = 6）
            $stmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM userrolesdata 
                WHERE ur_u_ID = ? AND role_ID = 6 AND user_role_status = 1
            ");
            $stmt->execute([$u_ID]);
            $isStudent = $stmt->fetchColumn() > 0;

            // 檢查是否為管理員
            $role_ID = $_SESSION['role_ID'] ?? null;
            $isAdmin = in_array($role_ID, [1, 2]);

            // 開放式功能：任何人都可以使用
            // 如果當前用戶是學生，直接使用學生的資料
            // 如果管理員指定了 team_ID 或 u_ID，使用指定的
            // 否則，使用當前登入者的資料（即使是管理員也可以使用自己的資料）
            if ($isStudent) {
                // 學生直接使用自己的資料，不需要額外處理
                // $targetUserID 已經是 $u_ID，繼續使用
            } elseif ($isAdmin && isset($_POST['team_ID']) && !empty($_POST['team_ID'])) {
                $targetTeamID = (int)$_POST['team_ID'];
                // 如果只提供了 team_ID，需要獲取團隊的第一個成員作為 u_ID
                if (empty($_POST['u_ID'])) {
                    $teamUserField = 'team_u_ID';
                    $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                    $checkStmt->execute();
                    if (!$checkStmt->fetch()) {
                        $teamUserField = 'u_ID';
                    }
                    $stmt = $conn->prepare("
                        SELECT {$teamUserField} as u_ID
                        FROM teammember
                        WHERE team_ID = ? AND tm_status = 1
                        ORDER BY {$teamUserField}
                        LIMIT 1
                    ");
                    $stmt->execute([$targetTeamID]);
                    $firstMember = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($firstMember) {
                        $targetUserID = $firstMember['u_ID'];
                    } else {
                        json_err('該團隊沒有成員');
                    }
                } else {
                    $targetUserID = $_POST['u_ID'];
                }
            } elseif (isset($_POST['u_ID']) && !empty($_POST['u_ID']) && $isAdmin) {
                // 管理員指定了特定的學生
                $targetUserID = $_POST['u_ID'];
            } else {
                // 開放式功能：使用當前登入者的資料（不管是學生還是管理員）
                // $targetUserID 已經是 $u_ID，繼續使用
                // 這樣即使是管理員也可以使用自己的資料來測試功能
            }

            // 步驟 1: 從資料庫獲取用戶資料
            error_log('開始獲取用戶資料...');
            $userData = getUserDataForTemplate($targetUserID, $targetTeamID);

            if (empty($userData)) {
                json_err('無法獲取用戶資料，請確認用戶有團隊資訊');
            }

            // 步驟 2: 將資料填入標準範本
            error_log('開始填入標準範本...');
            $filledTemplate = fillTemplateWithData($templateContent, $templateExt, $userData);

            if (empty($filledTemplate)) {
                json_err('填入範本失敗');
            }

            // 步驟 3: 儲存填好的文件並返回
            $outputFileName = 'filled_' . uniqid() . '.' . $templateExt;
            $outputDir = __DIR__ . '/../uploads/filled_templates/';
            if (!is_dir($outputDir)) {
                if (!mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
                    throw new Exception('無法建立輸出目錄');
                }
            }
            $outputPath = $outputDir . $outputFileName;
            if (file_put_contents($outputPath, $filledTemplate) === false) {
                throw new Exception('無法儲存填好的文件');
            }

            json_ok([
                'file_url' => 'uploads/filled_templates/' . $outputFileName,
                'file_name' => $outputFileName,
                'user_data' => $userData,
                'message' => '成功使用用戶資料填入範本'
            ]);
        } catch (Throwable $e) {
            error_log('使用用戶資料填入範本錯誤: ' . $e->getMessage());
            error_log('堆疊: ' . $e->getTraceAsString());
            json_err('處理失敗：' . $e->getMessage());
        }
        break;

    default:
        json_err('Unknown action: ' . $do);
}

/**
 * 從資料庫獲取用戶資料並轉換為標準欄位格式（用於填入範本）
 * 
 * @param string $u_ID 用戶ID
 * @param int|null $team_ID 團隊ID（可選，如果提供則使用該團隊的資料）
 * @return array 標準欄位 JSON（如：student_name, student_id, dept, advisor, project_title, team_members, class）
 */
function getUserDataForTemplate($u_ID, $team_ID = null)
{
    global $conn;

    if (empty($u_ID)) {
        return [];
    }

    // 獲取用戶基本資料
    $stmt = $conn->prepare("
        SELECT 
            u.u_ID,
            u.u_name,
            u.u_gmail
        FROM userdata u
        WHERE u.u_ID = ?
    ");
    $stmt->execute([$u_ID]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return [];
    }

    // 獲取學籍資料（當前屆別）
    $stmt = $conn->prepare("
        SELECT 
            e.enroll_grade,
            c.c_ID as class_ID,
            c.c_name as class_name,
            ch.cohort_ID,
            ch.cohort_name
        FROM enrollmentdata e
        LEFT JOIN classdata c ON e.class_ID = c.c_ID
        LEFT JOIN cohortdata ch ON e.cohort_ID = ch.cohort_ID
        WHERE e.enroll_u_ID = ? AND e.enroll_status = 1
        ORDER BY e.cohort_ID DESC
        LIMIT 1
    ");
    $stmt->execute([$u_ID]);
    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

    // 獲取團隊資料
    $teamUserField = 'team_u_ID';
    $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $teamUserField = 'u_ID';
    }

    if ($team_ID) {
        // 使用指定的團隊ID
        $stmt = $conn->prepare("
            SELECT 
                t.team_ID,
                t.team_project_name,
                g.group_ID,
                g.group_name,
                t.cohort_ID
            FROM teamdata t
            LEFT JOIN groupdata g ON t.group_ID = g.group_ID
            WHERE t.team_ID = ? AND t.team_status = 1
        ");
        $stmt->execute([$team_ID]);
        $teamData = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // 獲取用戶所屬的團隊
        $stmt = $conn->prepare("
            SELECT 
                t.team_ID,
                t.team_project_name,
                g.group_ID,
                g.group_name,
                t.cohort_ID
            FROM teammember tm
            INNER JOIN teamdata t ON tm.team_ID = t.team_ID
            LEFT JOIN groupdata g ON t.group_ID = g.group_ID
            WHERE tm.{$teamUserField} = ? AND tm.tm_status = 1 AND t.team_status = 1
            ORDER BY t.team_update_d DESC
            LIMIT 1
        ");
        $stmt->execute([$u_ID]);
        $teamData = $stmt->fetch(PDO::FETCH_ASSOC);
        $team_ID = $teamData['team_ID'] ?? null;
    }

    // 獲取團隊成員（如果有的話）
    $teamMembers = [];
    if ($team_ID) {
        $stmt = $conn->prepare("
            SELECT 
                u.u_ID,
                u.u_name,
                e.class_ID,
                c.c_name as class_name
            FROM teammember tm
            INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
            LEFT JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID AND e.enroll_status = 1
            LEFT JOIN classdata c ON e.class_ID = c.c_ID
            WHERE tm.team_ID = ? AND tm.tm_status = 1
            ORDER BY u.u_name
        ");
        $stmt->execute([$team_ID]);
        $teamMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 獲取指導老師（從團隊資料中獲取，如果有 advisor 欄位）
    $advisor = null;
    $advisorName = null;
    if ($team_ID) {
        // 檢查 teamdata 是否有 advisor 欄位
        $stmt = $conn->prepare("SHOW COLUMNS FROM teamdata LIKE 'advisor%'");
        $stmt->execute();
        $hasAdvisorField = $stmt->fetch() !== false;

        if ($hasAdvisorField) {
            $stmt = $conn->prepare("SELECT advisor FROM teamdata WHERE team_ID = ?");
            $stmt->execute([$team_ID]);
            $advisorResult = $stmt->fetch(PDO::FETCH_ASSOC);
            $advisor = $advisorResult['advisor'] ?? null;

            if ($advisor) {
                $stmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                $stmt->execute([$advisor]);
                $advisorUser = $stmt->fetch(PDO::FETCH_ASSOC);
                $advisorName = $advisorUser['u_name'] ?? $advisor;
            }
        } else {
            // 如果沒有 advisor 欄位，嘗試從其他關聯表獲取（根據實際資料庫結構調整）
            // 這裡可以根據實際需求擴充
        }
    }

    // 組合標準欄位格式
    $data = [
        'student_name' => $user['u_name'] ?? '',
        'student_id' => $user['u_ID'] ?? '',
        'dept' => $enrollment['class_name'] ?? '',
        'class' => $enrollment['class_name'] ?? '',
        'cohort' => $enrollment['cohort_name'] ?? $enrollment['cohort_ID'] ?? '',
        'group' => $teamData['group_name'] ?? '',
        'team_name' => $teamData['team_project_name'] ?? '',
        'project_title' => $teamData['team_project_name'] ?? '',
        'advisor' => $advisorName ?? '',
        'submission_date' => date('Y-m-d')
    ];

    // 組合團隊成員資訊（格式：學號 - 姓名）
    if (!empty($teamMembers)) {
        $membersList = [];
        foreach ($teamMembers as $member) {
            $memberInfo = ($member['u_ID'] ?? '') . ' - ' . ($member['u_name'] ?? '');
            if (!empty($member['class_name'])) {
                $memberInfo = ($member['class_name'] ?? '') . ' ' . $memberInfo;
            }
            $membersList[] = $memberInfo;
        }
        $data['team_members'] = implode("\n", $membersList);
    } else {
        $data['team_members'] = '';
    }

    return $data;
}

/**
 * 使用 OCR + AI 識別表單內容並轉換為標準欄位 JSON
 * 
 * @param string $fileContent 文件內容
 * @param string $fileExt 文件擴展名
 * @return array 標準欄位 JSON（如：student_name, student_id, dept, advisor, project_title, reason）
 */
function recognizeFormContentWithAI($fileContent, $fileExt)
{
    // 讀取 Google API Key
    $googleApiKey = getenv('GOOGLE_API_KEY');
    if (empty($googleApiKey)) {
        $configFile = __DIR__ . '/../includes/ai_config.php';
        if (file_exists($configFile)) {
            $config = include $configFile;
            $googleApiKey = $config['google_api_key'] ?? '';
        }
    }

    if (empty($googleApiKey)) {
        throw new Exception('未設定 Google API Key，無法使用 AI 識別功能');
    }

    // 步驟 1: OCR 提取文字
    $text = '';
    if ($fileExt === 'pdf') {
        $text = extractTextFromPDF($fileContent);
    }

    // 如果基本提取失敗，使用 AI OCR
    if (empty($text)) {
        $mimeType = $fileExt === 'pdf' ? 'application/pdf' : (in_array($fileExt, ['png', 'jpg', 'jpeg']) ? 'image/' . $fileExt : 'application/pdf');
        $text = extractTextWithOCR($fileContent, $mimeType, $googleApiKey);
    }

    if (empty($text)) {
        throw new Exception('無法從文件中提取文字，請確認文件清晰且包含可識別的文字');
    }

    // 步驟 2: 使用 AI 將 OCR 文字轉換為標準欄位 JSON
    $configFile = __DIR__ . '/../includes/ai_config.php';
    $config = file_exists($configFile) ? include $configFile : [];
    $model = $config['google_model'] ?? 'gemini-1.5-flash-latest';

    // 定義標準欄位 schema
    $standardFields = [
        'student_name' => '學生姓名',
        'student_id' => '學號',
        'dept' => '系級',
        'advisor' => '指導老師',
        'project_title' => '申請主題/專題名稱',
        'reason' => '申請理由',
        'class' => '班級',
        'cohort' => '屆別',
        'group' => '類組',
        'team_name' => '團隊名稱',
        'submission_date' => '提交日期'
    ];

    // 構建 prompt
    $prompt = "你是一個專業的表單內容識別專家。請仔細分析以下 OCR 提取的文字內容，並找出對應的欄位資訊。\n\n";
    $prompt .= "**標準欄位定義：**\n";
    foreach ($standardFields as $key => $label) {
        $prompt .= "- {$key}: {$label}\n";
    }
    $prompt .= "\n**OCR 文字內容：**\n";
    $prompt .= $text . "\n\n";
    $prompt .= "**任務要求：**\n";
    $prompt .= "1. 仔細分析 OCR 文字，找出每個標準欄位對應的值\n";
    $prompt .= "2. 欄位名稱可能有多種寫法（如「姓名」、「申請人姓名」、「學生姓名」都對應 student_name）\n";
    $prompt .= "3. 如果找不到某個欄位，請將該欄位設為 null\n";
    $prompt .= "4. 只輸出 JSON 格式，不要其他文字\n";
    $prompt .= "5. JSON 格式如下：\n";
    $prompt .= "{\n";
    $prompt .= "  \"student_name\": \"值或null\",\n";
    $prompt .= "  \"student_id\": \"值或null\",\n";
    $prompt .= "  \"dept\": \"值或null\",\n";
    $prompt .= "  \"advisor\": \"值或null\",\n";
    $prompt .= "  \"project_title\": \"值或null\",\n";
    $prompt .= "  \"reason\": \"值或null\",\n";
    $prompt .= "  \"class\": \"值或null\",\n";
    $prompt .= "  \"cohort\": \"值或null\",\n";
    $prompt .= "  \"group\": \"值或null\",\n";
    $prompt .= "  \"team_name\": \"值或null\",\n";
    $prompt .= "  \"submission_date\": \"值或null\"\n";
    $prompt .= "}\n";

    // 調用 Gemini API
    $apiVersion = getGeminiApiVersion($model);
    $url = "https://generativelanguage.googleapis.com/{$apiVersion}/models/{$model}:generateContent?key=" . urlencode($googleApiKey);

    $parts = [];
    $parts[] = ['text' => $prompt];

    // 如果文件是圖片或 PDF，也傳送文件內容給 AI
    $mimeType = $fileExt === 'pdf' ? 'application/pdf' : (in_array($fileExt, ['png', 'jpg', 'jpeg']) ? 'image/' . $fileExt : 'application/pdf');
    if (strpos($mimeType, 'image/') === 0 || $mimeType === 'application/pdf') {
        $base64 = base64_encode($fileContent);
        $parts[] = [
            'inline_data' => [
                'mime_type' => $mimeType,
                'data' => $base64
            ]
        ];
    }

    $data = [
        'contents' => [['parts' => $parts]],
        'generationConfig' => [
            'temperature' => 0.3,
            'maxOutputTokens' => 2000,
            'responseMimeType' => 'application/json'
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('AI API 連線錯誤: ' . $curlError);
    }

    if ($httpCode !== 200 || !$response) {
        $errorMsg = 'AI API 請求失敗 (HTTP ' . $httpCode . ')';
        if ($response) {
            $errorData = json_decode($response, true);
            if (isset($errorData['error']['message'])) {
                $errorMsg .= ': ' . $errorData['error']['message'];
            }
        }
        throw new Exception($errorMsg);
    }

    $result = json_decode($response, true);
    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception('AI 回應格式錯誤');
    }

    $jsonText = $result['candidates'][0]['content']['parts'][0]['text'];
    // 清理可能的 markdown 代碼塊標記
    $jsonText = preg_replace('/```json\s*/', '', $jsonText);
    $jsonText = preg_replace('/```\s*/', '', $jsonText);
    $jsonText = trim($jsonText);

    $recognizedData = json_decode($jsonText, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('無法解析 AI 回應為 JSON: ' . json_last_error_msg());
    }

    error_log('AI 識別結果: ' . json_encode($recognizedData, JSON_UNESCAPED_UNICODE));

    return $recognizedData;
}

/**
 * 將識別出的資料填入標準範本（Word/PDF）
 * 
 * @param string $templateContent 範本文件內容
 * @param string $templateExt 範本文件擴展名
 * @param array $data 要填入的資料（標準欄位 JSON）
 * @return string 填好的文件內容（二進制）
 */
function fillTemplateWithData($templateContent, $templateExt, $data)
{
    if ($templateExt === 'pdf') {
        return fillPdfTemplate($templateContent, $data);
    } elseif (in_array($templateExt, ['docx', 'doc'])) {
        return fillWordTemplate($templateContent, $data);
    } else {
        throw new Exception('不支援的範本格式: ' . $templateExt);
    }
}

/**
 * 填入 PDF 範本
 * 注意：PDF 填入需要根據實際範本結構來實作
 * 這裡提供一個基本框架，實際使用時需要根據範本調整
 */
function fillPdfTemplate($templateContent, $data)
{
    // 暫時返回原範本，並記錄識別出的資料
    // 實際使用時，可以：
    // 1. 使用 FPDI + FPDM 來填入 fillable PDF 表單
    // 2. 使用 TCPDF 或 FPDF 來在指定位置填入文字
    // 3. 使用 AI 來識別範本中的欄位位置並填入

    error_log('PDF 範本填入功能需要根據實際範本結構來實作');
    error_log('識別出的資料: ' . json_encode($data, JSON_UNESCAPED_UNICODE));

    // 暫時返回原範本（實際使用時需要填入資料）
    // 這裡可以實作具體的 PDF 填入邏輯
    return $templateContent;
}

/**
 * 填入 Word 範本
 * 使用 PHPWord 或類似的庫來填入 Word 範本
 */
function fillWordTemplate($templateContent, $data)
{
    // 確保 Composer autoload 已載入
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }

    // 檢查是否有 PHPWord 庫（使用完整的類名檢查）
    $templateProcessorClass = 'PhpOffice\PhpWord\TemplateProcessor';
    if (!class_exists($templateProcessorClass)) {
        // 如果沒有 PHPWord，嘗試使用簡單的文字替換（僅適用於簡單範本）
        error_log('未安裝 PHPWord 庫，使用簡單文字替換');
        return fillWordTemplateSimple($templateContent, $data);
    }

    try {
        // 將範本內容寫入臨時文件（PHPWord 需要文件路徑）
        $tempTemplateFile = tempnam(sys_get_temp_dir(), 'template_');
        file_put_contents($tempTemplateFile, $templateContent);

        // 再次確認類存在後才實例化
        if (!class_exists($templateProcessorClass)) {
            throw new Exception('PHPWord TemplateProcessor 類不存在');
        }

        $templateProcessor = new $templateProcessorClass($tempTemplateFile);

        // 填入欄位（假設 Word 範本中使用 {{field_name}} 作為占位符）
        // 也支援其他常見格式：${field_name}、[field_name] 等
        $placeholderFormats = [
            '{{%s}}',
            '${%s}',
            '[%s]',
            '{%s}'
        ];

        foreach ($data as $key => $value) {
            if ($value !== null && $value !== '') {
                $valueStr = (string)$value;
                // 嘗試多種占位符格式
                foreach ($placeholderFormats as $format) {
                    $placeholder = sprintf($format, $key);
                    $templateProcessor->setValue($placeholder, $valueStr);
                }
            }
        }

        // 輸出到臨時文件
        $tempOutputFile = tempnam(sys_get_temp_dir(), 'filled_template_');
        $templateProcessor->saveAs($tempOutputFile);
        $filledContent = file_get_contents($tempOutputFile);

        // 清理臨時文件
        unlink($tempTemplateFile);
        unlink($tempOutputFile);

        return $filledContent;
    } catch (Exception $e) {
        error_log('填入 Word 範本錯誤: ' . $e->getMessage());
        // 如果 PHPWord 失敗，嘗試簡單文字替換
        try {
            return fillWordTemplateSimple($templateContent, $data);
        } catch (Exception $e2) {
            throw new Exception('填入 Word 範本失敗: ' . $e->getMessage() . ' / ' . $e2->getMessage());
        }
    }
}

/**
 * 簡單的 Word 範本填入（使用文字替換，僅適用於簡單範本）
 */
function fillWordTemplateSimple($templateContent, $data)
{
    // 這是一個簡化版本，僅適用於文字型範本
    // 對於複雜的 Word 格式，建議使用 PHPWord

    $filledContent = $templateContent;

    // 支援多種占位符格式
    $placeholderFormats = [
        '{{%s}}',
        '${%s}',
        '[%s]',
        '{%s}',
        '{{ %s }}',
        '${ %s }',
        '[ %s ]',
        '{ %s }'
    ];

    foreach ($data as $key => $value) {
        if ($value !== null && $value !== '') {
            $valueStr = (string)$value;
            foreach ($placeholderFormats as $format) {
                $placeholder = sprintf($format, $key);
                $filledContent = str_replace($placeholder, $valueStr, $filledContent);
            }
        }
    }

    return $filledContent;
}

// 根據模型選擇正確的 API 版本
// 統一使用 v1beta API，避免版本判斷複雜化
function getGeminiApiVersion($model)
{
    return 'v1beta';
}

// 測試 Google Gemini API 連線
function testGoogleGeminiConnection($apiKey, $model = 'gemini-1.5-flash-latest')
{
    if (empty($apiKey)) {
        return ['success' => false, 'message' => 'API Key 未設定'];
    }

    // 簡單的測試請求
    $testData = [
        'contents' => [
            [
                'parts' => [
                    ['text' => '請回答：1+1等於多少？']
                ]
            ]
        ],
        'generationConfig' => [
            'maxOutputTokens' => 50  // 增加 token 數量，避免被截斷
        ]
    ];

    // 根據模型選擇正確的 API 版本
    $apiVersion = getGeminiApiVersion($model);
    $url = "https://generativelanguage.googleapis.com/{$apiVersion}/models/{$model}:generateContent?key=" . urlencode($apiKey);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($testData),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'message' => '連線錯誤: ' . $curlError];
    }

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return ['success' => true, 'message' => 'API 連線正常'];
        } elseif (isset($result['candidates'][0]['finishReason'])) {
            // 檢查 finishReason
            $finishReason = $result['candidates'][0]['finishReason'];
            if ($finishReason === 'MAX_TOKENS') {
                return ['success' => true, 'message' => 'API 連線正常（回應被截斷，但連線成功）'];
            } else {
                return ['success' => false, 'message' => 'API 回應異常，finishReason: ' . $finishReason];
            }
        } else {
            return ['success' => false, 'message' => 'API 回應格式異常'];
        }
    } elseif ($httpCode === 429) {
        // 配額限制
        $errorData = json_decode($response, true);
        $errorMsg = '超過 API 配額限制（每分鐘 10 次請求）';
        if (isset($errorData['error']['message'])) {
            $errorMsg .= ': ' . $errorData['error']['message'];
        }
        return ['success' => false, 'message' => $errorMsg];
    } else {
        $errorData = json_decode($response, true);
        $errorMsg = 'HTTP ' . $httpCode;
        if (isset($errorData['error']['message'])) {
            $errorMsg .= ': ' . $errorData['error']['message'];
        }
        return ['success' => false, 'message' => $errorMsg];
    }
}

// 從文件中識別題目的函數（流程：掃描 PDF → OCR → 文字 → 識別題目）
function recognizeQuestionsFromFile($fileContent, $fileExt)
{
    $questions = [];

    try {
        // 讀取 Google API Key（從環境變數或配置檔案）
        $googleApiKey = getenv('GOOGLE_API_KEY');
        if (empty($googleApiKey)) {
            // 嘗試從配置檔案讀取
            $configFile = __DIR__ . '/../includes/ai_config.php';
            if (file_exists($configFile)) {
                $config = include $configFile;
                $googleApiKey = $config['google_api_key'] ?? '';
            }
        }

        // 自動判斷是否使用 AI（如果 API Key 存在）
        $useAI = !empty($googleApiKey);

        $text = ''; // 用於儲存OCR提取的文字

        // 步驟 1：對於 PDF 和圖片，先嘗試基本文字提取（適用於文字型PDF）
        if ($fileExt === 'pdf') {
            $text = extractTextFromPDF($fileContent);
            error_log('PDF 文字提取結果: ' . (empty($text) ? '失敗（可能是掃描版）' : '成功，長度 ' . strlen($text) . ' 字元'));
        }

        // 步驟 2：如果基本提取失敗（掃描版PDF或圖片），且 AI 可用，使用 OCR 提取文字
        if (empty($text) && $useAI && ($fileExt === 'pdf' || in_array($fileExt, ['png', 'jpg', 'jpeg']))) {
            error_log('開始使用 AI OCR 提取文字...');

            // 讀取配置
            $configFile = __DIR__ . '/../includes/ai_config.php';
            $config = file_exists($configFile) ? include $configFile : [];
            $model = $config['google_model'] ?? 'gemini-1.5-flash-latest';

            // 測試 API 連線
            $connectionResult = testGoogleGeminiConnection($googleApiKey, $model);

            if ($connectionResult['success']) {
                try {
                    // 使用 AI 進行 OCR 提取文字
                    if ($fileExt === 'pdf') {
                        $mimeType = 'application/pdf';
                    } else {
                        $mimeType = 'image/' . ($fileExt === 'jpg' ? 'jpeg' : $fileExt);
                    }

                    $fileSizeMB = strlen($fileContent) / (1024 * 1024);
                    if ($fileSizeMB > 20) {
                        error_log('檔案太大（' . round($fileSizeMB, 2) . 'MB），跳過 OCR');
                    } else {
                        $text = extractTextWithOCR($fileContent, $mimeType, $googleApiKey);
                        if (!empty($text)) {
                            error_log('✅ OCR 提取成功，長度: ' . strlen($text) . ' 字元');
                        } else {
                            error_log('OCR 提取結果為空');
                        }
                    }
                } catch (Exception $e) {
                    $errorMsg = $e->getMessage();
                    error_log('OCR 錯誤: ' . $errorMsg);
                    if (strpos($errorMsg, 'API key') !== false || strpos($errorMsg, 'invalid') !== false) {
                        error_log('⚠️ API Key 可能無效或過期');
                    } elseif (strpos($errorMsg, 'quota') !== false || strpos($errorMsg, '429') !== false) {
                        error_log('⚠️ API 配額已用完');
                    } elseif (strpos($errorMsg, 'timeout') !== false) {
                        error_log('⚠️ API 請求超時');
                    }
                }
            } else {
                error_log('AI 連線失敗: ' . $connectionResult['message']);
            }
        }

        // 步驟 3：從提取的文字中識別題目
        if (!empty($text)) {
            error_log('開始從文字中識別題目... 文字長度=' . strlen($text));
            error_log('文字預覽（前500字元）: ' . mb_substr($text, 0, 500));

            // 先嘗試用 Gemini 直接找題目（如果有 API Key）
            if ($useAI) {
                try {
                    error_log('嘗試使用 Gemini AI 識別題目...');
                    // 這裡用純文字模式即可，不再當成 PDF / image
                    $questions = recognizeWithGoogleGemini($text, 'text/plain', $googleApiKey);
                    if (!empty($questions)) {
                        error_log('✅ Gemini 識別題目成功：' . count($questions) . ' 題');
                    } else {
                        error_log('⚠️ Gemini 識別結果為空');
                    }
                } catch (Exception $e) {
                    error_log('❌ Gemini 識別題目錯誤：' . $e->getMessage());
                    error_log('錯誤堆疊: ' . $e->getTraceAsString());
                    $questions = [];
                }
            } else {
                error_log('⚠️ AI 不可用，跳過 Gemini 識別');
            }

            // 如果 AI 失敗或沒回東西，再退回基本規則
            if (empty($questions)) {
                error_log('嘗試使用基本規則識別...');
                $questions = recognizeQuestionsBasic($text, 'text');
                if (!empty($questions)) {
                    error_log('✅ 基本規則識別成功，找到 ' . count($questions) . ' 個題目');
                } else {
                    error_log('❌ 基本規則識別結果為空');
                    // 記錄文字片段以便調試
                    error_log('文字片段（用於調試）: ' . mb_substr($text, 0, 1000));
                }
            }
        } else {
            error_log('⚠️ 無法提取文字，嘗試直接從 PDF 識別...');
            // 如果完全抽不出文字（OCR / PDF 解析都失敗）
            if ($fileExt === 'pdf') {
                $questions = recognizeQuestionsBasic($fileContent, $fileExt);
                if (!empty($questions)) {
                    error_log('✅ 基本規則識別成功，找到 ' . count($questions) . ' 個題目（直接從 PDF）');
                } else {
                    error_log('❌ 直接從 PDF 識別也失敗');
                }
            }
        }

        return $questions;
    } catch (Exception $e) {
        // 發生錯誤時，記錄錯誤並返回空陣列
        error_log('識別題目錯誤: ' . $e->getMessage());
        return [];
    }
}

// 使用 Google Gemini API 進行 OCR 提取文字（支援 PDF 和圖片）
function extractTextWithOCR($content, $mimeType, $apiKey)
{
    // 讀取配置
    $configFile = __DIR__ . '/../includes/ai_config.php';
    $config = file_exists($configFile) ? include $configFile : [];
    $model = $config['google_model'] ?? 'gemini-1.5-flash-latest';
    $timeout = $config['google_timeout'] ?? 60;

    $prompt = "請從以下文件/圖片中提取所有文字內容。\n\n";
    $prompt .= "要求：\n";
    $prompt .= "1. 提取所有可見的文字（包括標題、題目、選項等）\n";
    $prompt .= "2. 保持原有的格式和結構（保留換行、空格等）\n";
    $prompt .= "3. 如果有多個欄位或表格，請保持其相對位置\n";
    $prompt .= "4. 只回傳提取的文字內容，不要添加任何說明或註解\n\n";
    $prompt .= "請直接回傳提取的文字內容：\n";

    // 構建請求內容
    $parts = [];

    // 添加文字提示
    $parts[] = ['text' => $prompt];

    // 添加文件內容
    if (strpos($mimeType, 'image/') === 0 || $mimeType === 'application/pdf') {
        // 檢查檔案大小（Gemini API 限制：20MB）
        $fileSize = strlen($content);
        if ($fileSize > 20 * 1024 * 1024) {
            throw new Exception('檔案太大，請上傳小於 20MB 的檔案');
        }

        $base64 = base64_encode($content);
        $parts[] = [
            'inline_data' => [
                'mime_type' => $mimeType,
                'data' => $base64
            ]
        ];
    } else {
        // 文字內容
        $textContent = is_string($content) ? mb_substr($content, 0, 30000) : '';
        $parts[] = ['text' => "文件內容：\n" . $textContent];
    }

    $data = [
        'contents' => [
            [
                'parts' => $parts
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.1, // 降低溫度以獲得更準確的文字提取
            'maxOutputTokens' => 4000, // 增加 token 數量以提取更多文字
        ]
    ];

    // 構建 API URL
    $apiVersion = getGeminiApiVersion($model);
    $url = "https://generativelanguage.googleapis.com/{$apiVersion}/models/{$model}:generateContent?key=" . urlencode($apiKey);

    // 調試：記錄請求信息
    $apiKeyPrefix = substr($apiKey, 0, 10) . '...';
    error_log('Google Gemini OCR 請求: 模型=' . $model . ', API 版本=' . $apiVersion . ', API Key=' . $apiKeyPrefix . ', MIME=' . $mimeType);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log('Google Gemini OCR CURL 錯誤: ' . $curlError);
        throw new Exception('Google Gemini OCR 連線錯誤: ' . $curlError);
    }

    if ($httpCode !== 200 || !$response) {
        $errorMsg = 'Google Gemini OCR 請求失敗 (HTTP ' . $httpCode . ')';
        if ($response) {
            $errorData = json_decode($response, true);
            if (isset($errorData['error']['message'])) {
                $errorMsg .= ': ' . $errorData['error']['message'];
            } elseif (isset($errorData['error'])) {
                $errorMsg .= ': ' . json_encode($errorData['error'], JSON_UNESCAPED_UNICODE);
            } else {
                $errorMsg .= ': ' . substr(strip_tags($response), 0, 200);
            }
            error_log('Google Gemini OCR 錯誤回應: ' . substr($response, 0, 500));
        } else {
            $errorMsg .= ' (無回應)';
        }
        error_log('Google Gemini OCR 錯誤: ' . $errorMsg);
        throw new Exception($errorMsg);
    }

    $result = json_decode($response, true);

    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception('Google Gemini OCR 回應格式錯誤');
    }

    $extractedText = $result['candidates'][0]['content']['parts'][0]['text'];

    // 清理提取的文字（移除可能的 markdown 格式）
    $extractedText = preg_replace('/```[\w]*\n?/', '', $extractedText);
    $extractedText = preg_replace('/```\n?/', '', $extractedText);
    $extractedText = trim($extractedText);

    return $extractedText;
}

// 使用 Google Gemini API 識別題目（支援文字和圖片）
function recognizeWithGoogleGemini($content, $mimeType, $apiKey)
{
    // 讀取配置
    $configFile = __DIR__ . '/../includes/ai_config.php';
    $config = file_exists($configFile) ? include $configFile : [];
    $model = $config['google_model'] ?? 'gemini-1.5-flash-latest';
    $timeout = $config['google_timeout'] ?? 60;

    $prompt = "請分析以下表單內容，識別出所有的題目（問題）。\n\n";
    $prompt .= "要求：\n";
    $prompt .= "1. 找出所有題目（通常以數字開頭，如「1.」、「一、」等，或包含問號的句子）\n";
    $prompt .= "2. 判斷每個題目的類型：\n";
    $prompt .= "   - short_text: 短文字（如姓名、電話等）\n";
    $prompt .= "   - long_text: 長文字（如說明、描述等）\n";
    $prompt .= "   - number: 數字\n";
    $prompt .= "   - date: 日期\n";
    $prompt .= "   - select/radio: 單選題（有選項）\n";
    $prompt .= "   - checkbox: 複選題（有選項，可多選）\n";
    $prompt .= "3. 判斷是否為必填題目\n";
    $prompt .= "4. 如果有選項，請列出選項\n\n";
    $prompt .= "請以 JSON 格式回傳，格式如下（必須是有效的 JSON 陣列）：\n";
    $prompt .= "[\n";
    $prompt .= "  {\"title\": \"題目文字\", \"type\": \"long_text\", \"required\": true, \"options\": []},\n";
    $prompt .= "  ...\n";
    $prompt .= "]\n\n";
    $prompt .= "重要：請只回傳 JSON 陣列，不要包含任何其他文字或 markdown 格式。\n\n";

    // 構建請求內容
    $parts = [];

    // 添加文字提示
    $parts[] = ['text' => $prompt];

    // 如果是圖片或 PDF，添加圖片內容
    if (strpos($mimeType, 'image/') === 0 || $mimeType === 'application/pdf') {
        // 檢查檔案大小（Gemini API 限制：20MB）
        $fileSize = strlen($content);
        if ($fileSize > 20 * 1024 * 1024) {
            throw new Exception('檔案太大，請上傳小於 20MB 的檔案');
        }

        $base64 = base64_encode($content);
        $parts[] = [
            'inline_data' => [
                'mime_type' => $mimeType,
                'data' => $base64
            ]
        ];
    } else {
        // 文字內容（text/plain 或其他文字格式）
        $textContent = is_string($content) ? $content : '';

        // Gemini API 的文字長度限制約為 30,000 字元，但我們可以嘗試更多
        // 如果文字太長，截取前面部分並加上提示
        $maxLength = 30000;
        if (mb_strlen($textContent) > $maxLength) {
            error_log('⚠️ 文字內容過長（' . mb_strlen($textContent) . ' 字元），截取前 ' . $maxLength . ' 字元');
            $textContent = mb_substr($textContent, 0, $maxLength) . "\n\n[注意：文字內容已截斷，僅顯示前 " . $maxLength . " 字元]";
        }

        $parts[] = ['text' => "表單內容：\n" . $textContent];
        error_log('發送給 Gemini 的文字長度: ' . mb_strlen($textContent) . ' 字元');
    }

    $data = [
        'contents' => [
            [
                'parts' => $parts
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.3,
            'maxOutputTokens' => 2000,
            'responseMimeType' => 'application/json'
        ]
    ];

    // 構建 API URL
    $apiVersion = getGeminiApiVersion($model);
    $url = "https://generativelanguage.googleapis.com/{$apiVersion}/models/{$model}:generateContent?key=" . urlencode($apiKey);

    // 調試：記錄請求信息（不記錄完整 API Key）
    $apiKeyPrefix = substr($apiKey, 0, 10) . '...';
    error_log('Google Gemini API 請求: 模型=' . $model . ', API 版本=' . $apiVersion . ', API Key=' . $apiKeyPrefix . ', MIME=' . $mimeType);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log('Google Gemini API CURL 錯誤: ' . $curlError);
        throw new Exception('Google Gemini API 連線錯誤: ' . $curlError);
    }

    if ($httpCode !== 200 || !$response) {
        $errorMsg = 'Google Gemini API 請求失敗 (HTTP ' . $httpCode . ')';
        if ($response) {
            $errorData = json_decode($response, true);
            if (isset($errorData['error']['message'])) {
                $errorMsg .= ': ' . $errorData['error']['message'];
                // 如果是 API Key 錯誤，提供更明確的提示
                if (
                    strpos($errorData['error']['message'], 'API key') !== false ||
                    strpos($errorData['error']['message'], 'invalid') !== false ||
                    strpos($errorData['error']['message'], 'permission') !== false
                ) {
                    $errorMsg .= ' (請檢查 API Key 是否正確且有效)';
                }
            } elseif (isset($errorData['error'])) {
                $errorMsg .= ': ' . json_encode($errorData['error'], JSON_UNESCAPED_UNICODE);
            } else {
                // 如果不是 JSON，可能是 HTML 錯誤頁面
                $errorMsg .= ': ' . substr(strip_tags($response), 0, 200);
            }
            error_log('Google Gemini API 錯誤回應: ' . substr($response, 0, 500));
        } else {
            $errorMsg .= ' (無回應)';
        }
        error_log('Google Gemini API 錯誤: ' . $errorMsg);
        throw new Exception($errorMsg);
    }

    $result = json_decode($response, true);

    // 檢查回應格式
    if (!isset($result['candidates'][0])) {
        error_log('Google Gemini API 回應：沒有 candidates');
        error_log('完整回應: ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        throw new Exception('Google Gemini API 回應格式錯誤：沒有 candidates');
    }

    $candidate = $result['candidates'][0];

    // 檢查是否有錯誤
    if (isset($candidate['finishReason']) && $candidate['finishReason'] !== 'STOP') {
        $finishReason = $candidate['finishReason'];
        error_log('⚠️ Gemini 回應 finishReason: ' . $finishReason);
        if ($finishReason === 'SAFETY') {
            throw new Exception('Google Gemini API 因安全原因拒絕回應');
        } elseif ($finishReason === 'MAX_TOKENS') {
            error_log('⚠️ 回應被截斷（MAX_TOKENS），但繼續處理');
        }
    }

    if (!isset($candidate['content']['parts'][0]['text'])) {
        error_log('Google Gemini API 回應：沒有文字內容');
        error_log('完整回應: ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        throw new Exception('Google Gemini API 回應格式錯誤：沒有文字內容');
    }

    $contentText = $candidate['content']['parts'][0]['text'];
    error_log('Gemini 原始回應長度: ' . strlen($contentText) . ' 字元');
    error_log('Gemini 原始回應預覽: ' . mb_substr($contentText, 0, 500));

    // 嘗試提取 JSON（可能包含在 markdown 代碼塊中）
    $jsonText = $contentText;
    if (preg_match('/```json\s*(\[.*?\])\s*```/s', $contentText, $matches)) {
        $jsonText = $matches[1];
        error_log('從 markdown json 代碼塊提取 JSON');
    } elseif (preg_match('/```\s*(\[.*?\])\s*```/s', $contentText, $matches)) {
        $jsonText = $matches[1];
        error_log('從 markdown 代碼塊提取 JSON');
    } elseif (preg_match('/(\[.*\])/s', $contentText, $matches)) {
        $jsonText = $matches[1];
        error_log('從文字中提取 JSON 陣列');
    }

    $jsonData = json_decode($jsonText, true);

    // 如果 JSON 解析失敗，記錄詳細資訊
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('❌ JSON 解析失敗: ' . json_last_error_msg());
        error_log('嘗試解析的文字: ' . mb_substr($jsonText, 0, 1000));
        throw new Exception('無法解析 AI 回應為 JSON: ' . json_last_error_msg() . '。回應預覽: ' . mb_substr($contentText, 0, 200));
    }

    // 處理可能的 JSON 物件格式（如果 API 返回的是 {"questions": [...]}）
    if (isset($jsonData['questions'])) {
        $jsonData = $jsonData['questions'];
        error_log('從 JSON 物件中提取 questions 陣列');
    }

    if (!is_array($jsonData)) {
        error_log('❌ 解析後的資料不是陣列');
        error_log('解析後的資料類型: ' . gettype($jsonData));
        error_log('解析後的資料: ' . json_encode($jsonData, JSON_UNESCAPED_UNICODE));
        throw new Exception('無法解析 AI 回應: 回應不是有效的 JSON 陣列。回應預覽: ' . mb_substr($contentText, 0, 200));
    }

    error_log('✅ 成功解析 AI 回應，找到 ' . count($jsonData) . ' 個題目');

    return $jsonData;
}


// 基本規則識別（針對 Word 表格轉 PDF 的表單格式）
// 針對結構化表單（如專題申請表）進行優化識別
function recognizeQuestionsBasic($fileContent, $fileExt)
{
    $questions = [];
    $text = '';

    // 提取文本
    if ($fileExt === 'pdf') {
        $text = extractTextFromPDF($fileContent);
    } elseif ($fileExt === 'text') {
        // 如果已經是純文字（從 OCR 提取的），直接使用
        $text = is_string($fileContent) ? $fileContent : '';
    } else {
        // 圖片無法直接提取文本，返回空陣列
        // 純程式碼無法處理圖片，需要 OCR 或 AI
        error_log('基本規則識別：圖片格式需要 OCR，無法直接處理');
        return [];
    }

    if (empty($text)) {
        if ($fileExt === 'pdf') {
            error_log('基本規則識別：無法從 PDF 提取文字，可能是掃描版 PDF');
        } else {
            error_log('基本規則識別：文字內容為空');
        }
        return [];
    }

    error_log('基本規則識別：成功提取文字，長度: ' . strlen($text) . ' 字元');

    // 清理文本（保留換行，只清理多餘空格）
    $text = preg_replace('/\r\n|\r/', "\n", $text); // 統一換行符
    $text = preg_replace('/\n{3,}/', "\n\n", $text); // 移除多餘空行
    $text = preg_replace('/[ \t]+/', ' ', $text); // 將多個空格/製表符合併為一個（但保留換行）
    $text = preg_replace('/\n[ \t]+/', "\n", $text); // 移除行首空格
    $text = preg_replace('/[ \t]+\n/', "\n", $text); // 移除行尾空格

    // 調試：記錄清理後的文字前 500 字元
    error_log('清理後文字預覽: ' . mb_substr($text, 0, 500));

    // 針對 Word 表格轉 PDF 的表單格式進行識別
    // 這種表單通常有：標籤（如「專題名稱」）、編號題目（如「1. 企業名稱」）、選項等

    // 方法 1：識別編號題目（最常見）
    // 格式：1. 題目、1) 題目、1、題目、1.專題目的 等（支援無空格）
    // 針對 Word 轉 PDF 的格式優化
    $patterns = [
        // 數字開頭（1. 題目、1) 題目、1、題目、1.專題目的）
        // 改進：支援無空格的情況，如 "1.專題目的"
        // 改進：支援 Word 表格格式，可能有多個空格或製表符
        '/(?:^|\n|\s)(\d+)[\.\)、]\s*([^：:：\n]+?)(?:[：:：]|\(|（|$|\n)/u',
        // 中文數字（一、題目、一. 題目）
        '/(?:^|\n|\s)([一二三四五六七八九十]+)[\.、]\s*([^：:：\n]+?)(?:[：:：]|$|\n)/u',
        // 字母開頭（A. 題目、A) 題目）
        '/(?:^|\n|\s)([A-Za-z])[\.\)、]\s*([^：:：\n]+?)(?:[：:：]|$|\n)/u',
        // Word 表格格式：可能有多個空格或製表符分隔
        '/(?:^|\n)(\d+)[\.\)、]\s+([^\n]+?)(?:\s*[：:：]|\s*\(|\s*（|$|\n)/u',
    ];

    $foundTitles = [];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (isset($match[2])) {
                    $title = trim($match[2]);
                    // 移除常見的後綴和括號內容（如 "專題目的(系統、研究等想解決什麼問題?)"）
                    $title = preg_replace('/[：:：。，,]$/', '', $title);
                    // 移除括號及其內容（但保留題目本身）
                    $title = preg_replace('/[\(（].*?[\)）]/u', '', $title);
                    $title = trim($title);

                    if (mb_strlen($title) > 2 && mb_strlen($title) < 200) {
                        $foundTitles[] = $title;
                        error_log('找到編號題目: ' . $title);
                    }
                }
            }
        }
    }

    error_log('方法 1 找到 ' . count($foundTitles) . ' 個編號題目');

    // 方法 2：識別冒號結尾的標籤（表格表單常見）
    // 格式：專題名稱：、企業名稱：等
    $labelCount = 0;
    if (preg_match_all('/([^：:：\n]+?)[：:：]\s*(?:\n|$)/u', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $label = trim($match[1]);
            // 過濾明顯不是題目的文字
            if (mb_strlen($label) > 2 && mb_strlen($label) < 50) {
                // 跳過常見的非題目文字
                if (!preg_match('/^(頁|第|共|總|項|條|款|章|節|目錄|索引|附錄|表格|表單|申請單|簽名|日期|年月日|班級|學號|姓名|組員|指導老師)/u', $label)) {
                    $foundTitles[] = $label;
                    $labelCount++;
                    error_log('找到標籤題目: ' . $label);
                }
            }
        }
    }
    error_log('方法 2 找到 ' . $labelCount . ' 個標籤題目');

    // 方法 3：識別問號結尾的句子（可能是問題）
    if (preg_match_all('/([^？?\n]+?[？?])/u', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $title = trim($match[1]);
            if (mb_strlen($title) > 5 && mb_strlen($title) < 200) {
                $foundTitles[] = $title;
            }
        }
    }

    // 方法 4：識別「請...」開頭的指令（常見於表單說明）
    // 注意：過濾掉「請簡要說明」這種說明文字
    if (preg_match_all('/(請[^：:：\n]+?)(?:[：:：]|$)/u', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $title = trim($match[1]);
            // 過濾掉說明文字
            if (
                mb_strpos($title, '簡要說明') === false &&
                mb_strpos($title, '填寫') === false &&
                mb_strlen($title) > 3 && mb_strlen($title) < 100
            ) {
                $foundTitles[] = $title;
            }
        }
    }

    // 去重並建立題目陣列
    // 使用更嚴格的去重：不僅要字串相同，還要考慮相似度
    $uniqueTitles = [];
    foreach ($foundTitles as $title) {
        $isDuplicate = false;
        foreach ($uniqueTitles as $existing) {
            // 如果兩個標題非常相似（只差幾個字），視為重複
            $similarity = 0;
            similar_text($title, $existing, $similarity);
            if ($similarity > 90) {
                $isDuplicate = true;
                error_log('跳過重複題目（相似度 ' . round($similarity, 1) . '%）: ' . $title . ' (已有: ' . $existing . ')');
                break;
            }
        }
        if (!$isDuplicate) {
            $uniqueTitles[] = $title;
        }
    }
    $foundTitles = $uniqueTitles;

    // 過濾明顯不是題目的文字
    $filteredTitles = [];
    $skipPatterns = [
        '/^(頁|第|共|總|項|條|款|章|節|目錄|索引|附錄|表格|表單|申請單)/u',
        '/^(班級|學號|姓名|組員|指導老師|簽名|日期|年月日|中華民國)/u',
        '/^(請簡要說明|請說明|請填寫|請輸入|請選擇)/u', // 這些是說明文字，不是題目
        '/^(指導老師簽名|簽名|簽章)/u', // 簽名欄位不是題目
        '/^(專題規劃|表單名稱|表單分類|說明內容)/u', // 表單標題不是題目
    ];

    foreach ($foundTitles as $title) {
        // 跳過明顯不是題目的文字
        $shouldSkip = false;
        foreach ($skipPatterns as $pattern) {
            if (preg_match($pattern, $title)) {
                $shouldSkip = true;
                error_log('跳過（符合過濾規則）: ' . $title);
                break;
            }
        }
        if ($shouldSkip) {
            continue;
        }

        // 跳過太短或只是標點符號
        $cleanTitle = trim($title, '。，、；：：');
        if (mb_strlen($cleanTitle) < 3) {
            error_log('跳過（太短）: ' . $title);
            continue;
        }

        // 跳過只有數字或符號的
        if (preg_match('/^[\d\s\.\)、]+$/', $cleanTitle)) {
            error_log('跳過（只有數字符號）: ' . $title);
            continue;
        }

        // 跳過明顯是頁碼或編號的（如 "第 1 頁"、"共 10 頁"）
        if (preg_match('/^(第|共).*[頁張]/u', $cleanTitle)) {
            error_log('跳過（頁碼）: ' . $title);
            continue;
        }

        // 跳過只有冒號或標點符號結尾的短文字（可能是標籤而非題目）
        if (mb_strlen($cleanTitle) <= 5 && preg_match('/[：:：]$/u', $cleanTitle)) {
            // 但保留較長的標籤（可能是真正的題目）
            if (mb_strlen($cleanTitle) <= 5) {
                error_log('跳過（短標籤）: ' . $title);
                continue;
            }
        }

        // 額外驗證：確保題目包含中文字或有意義的內容
        // 跳過純英文單字（除非是專有名詞如 PEST、SWOT）
        if (!preg_match('/[\x{4E00}-\x{9FFF}]/u', $cleanTitle)) {
            // 如果不是常見的專有名詞，跳過
            $knownTerms = ['PEST', 'SWOT', 'BCG', '4P'];
            $isKnownTerm = false;
            foreach ($knownTerms as $term) {
                if (mb_stripos($cleanTitle, $term) !== false) {
                    $isKnownTerm = true;
                    break;
                }
            }
            if (!$isKnownTerm && mb_strlen($cleanTitle) < 10) {
                error_log('跳過（純英文且太短）: ' . $title);
                continue;
            }
        }

        // 驗證：確保題目看起來像真正的表單欄位
        // 跳過看起來像頁眉頁腳或裝飾性文字的內容
        if (preg_match('/^(第|共|總|頁|張|項|條|款|章|節)/u', $cleanTitle) && mb_strlen($cleanTitle) < 10) {
            error_log('跳過（頁眉頁腳）: ' . $title);
            continue;
        }

        $filteredTitles[] = $title;
        error_log('✅ 通過過濾的題目: ' . $title);
    }

    error_log('過濾後剩餘 ' . count($filteredTitles) . ' 個題目');

    // 針對 Word 表格格式的特殊處理
    // 識別選項（如：新產品企劃書、行銷活動企劃書等）
    $optionPatterns = [
        '/新產品企劃書/u',
        '/行銷活動企劃書/u',
        '/廣告企劃書/u',
        '/促銷企劃書/u',
        '/PEST/u',
        '/競爭市場分析/u',
        '/SWOT/u',
        '/BCG矩陣/u',
        '/4P組合/u',
    ];

    foreach ($filteredTitles as $title) {
        // 判斷題目類型（基於關鍵字和上下文）
        $type = 'long_text'; // 預設為長文字
        $options = [];

        // 檢查是否為選擇題（有選項的題目）
        $isChoiceQuestion = false;
        $optionKeywords = ['單選', '複選', '選擇', '選項', '勾選', '企業種類', '行銷企劃工具'];

        foreach ($optionKeywords as $keyword) {
            if (mb_strpos($title, $keyword) !== false) {
                $isChoiceQuestion = true;

                // 嘗試從文字中提取選項
                // 尋找題目後面的選項列表
                $titlePos = mb_strpos($text, $title);
                if ($titlePos !== false) {
                    $afterTitle = mb_substr($text, $titlePos + mb_strlen($title), 500);
                    // 尋找常見的選項格式
                    foreach ($optionPatterns as $optPattern) {
                        if (preg_match($optPattern, $afterTitle)) {
                            preg_match_all($optPattern, $afterTitle, $optMatches);
                            foreach ($optMatches[0] as $opt) {
                                if (!in_array($opt, $options)) {
                                    $options[] = $opt;
                                }
                            }
                        }
                    }
                }

                // 判斷單選或複選
                if (mb_strpos($title, '複選') !== false || mb_strpos($title, '多選') !== false) {
                    $type = 'checkbox';
                } else {
                    $type = 'radio';
                }
                break;
            }
        }

        // 如果沒有找到選項，根據關鍵字判斷類型
        if (!$isChoiceQuestion) {
            // 短文字（姓名、電話、地址等）
            if (preg_match('/企業名稱|專題名稱|姓名|名稱|名字|電話|手機|地址|郵箱|信箱|email|Email|聯絡|聯繫/u', $title)) {
                $type = 'short_text';
            }
            // 日期
            elseif (preg_match('/日期|時間|年月日|出生日期|開始日期|結束日期/u', $title)) {
                $type = 'date';
            }
            // 數字
            elseif (preg_match('/數量|個數|人數|金額|價格|費用|次數/u', $title)) {
                $type = 'number';
            }
            // 長文字（說明、描述、背景等）
            elseif (preg_match('/背景|動機|目的|功能|架構|工具|成果|結論|貢獻|規劃|說明|描述|簡述/u', $title)) {
                $type = 'long_text';
            }
        }

        // 判斷是否必填
        $required = true; // 預設必填
        if (preg_match('/選填|可選|非必填|非必要|可不填/u', $title)) {
            $required = false;
        }

        // 如果題目包含「其他」，通常是複選題的選項
        if (mb_strpos($title, '其他') !== false && empty($options)) {
            $type = 'long_text'; // 其他選項通常是文字輸入
        }

        $questions[] = [
            'title' => $title,
            'type' => $type,
            'required' => $required,
            'options' => $options
        ];
    }

    error_log('基本規則識別完成：找到 ' . count($questions) . ' 個題目');

    // 如果沒有找到任何題目，記錄詳細資訊以便調試
    if (empty($questions)) {
        error_log('警告：未找到任何題目');
        error_log('過濾前找到的標題數量: ' . count($foundTitles));
        error_log('過濾後標題數量: ' . count($filteredTitles));
        if (!empty($filteredTitles)) {
            error_log('過濾後的標題範例: ' . implode(', ', array_slice($filteredTitles, 0, 5)));
        }
    }

    return $questions;
}

// 從 PDF 提取文本（簡單版本，需要安裝 PDF 解析庫才能完整實現）
function extractTextFromPDF($fileContent)
{
    // 使用絕對路徑，避免路徑問題
    $tempDir = sys_get_temp_dir();
    $tempFile = $tempDir . DIRECTORY_SEPARATOR . 'pdf_' . uniqid() . '.pdf';

    error_log('extractTextFromPDF: 臨時檔案路徑: ' . $tempFile);
    error_log('extractTextFromPDF: 檔案內容長度: ' . strlen($fileContent) . ' bytes');

    // 確保可以寫入臨時檔案
    $writeResult = @file_put_contents($tempFile, $fileContent);
    if ($writeResult === false) {
        error_log('❌ extractTextFromPDF: 無法寫入臨時檔案: ' . $tempFile);
        error_log('   臨時目錄: ' . $tempDir);
        error_log('   目錄可寫: ' . (is_writable($tempDir) ? '是' : '否'));
        return ''; // 返回空字串，讓其他方法嘗試
    }

    error_log('extractTextFromPDF: 臨時檔案寫入成功，大小: ' . filesize($tempFile) . ' bytes');

    $text = '';

    // 方法 1：優先使用 PHP PDF 解析庫（更可靠，特別是對 Word 轉 PDF）
    // 檢查是否已安裝 smalot/pdfparser
    if (class_exists('\Smalot\PdfParser\Parser')) {
        try {
            error_log('嘗試使用 smalot/pdfparser 提取文字（優先方法）...');
            error_log('臨時檔案路徑: ' . $tempFile);
            error_log('檔案大小: ' . filesize($tempFile) . ' bytes');

            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($tempFile);
            $text = $pdf->getText();

            if (!empty($text)) {
                error_log('✅ PDF 文字提取成功（使用 smalot/pdfparser），長度: ' . strlen($text) . ' 字元');
                error_log('文字預覽（前200字元）: ' . mb_substr($text, 0, 200));
            } else {
                error_log('⚠️ smalot/pdfparser 提取結果為空');
                // 嘗試獲取頁數，確認 PDF 是否正確解析
                try {
                    $pages = $pdf->getPages();
                    error_log('PDF 頁數: ' . count($pages));
                    if (count($pages) > 0) {
                        error_log('⚠️ PDF 有 ' . count($pages) . ' 頁，但無法提取文字（可能是掃描版或圖片 PDF）');
                    }
                } catch (Exception $pageError) {
                    error_log('無法獲取 PDF 頁數: ' . $pageError->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log('❌ PDF 解析錯誤（smalot/pdfparser）: ' . $e->getMessage());
            error_log('錯誤類型: ' . get_class($e));
            error_log('錯誤堆疊: ' . $e->getTraceAsString());
            // 不中斷，繼續嘗試其他方法
        } catch (Throwable $e) {
            error_log('❌ PDF 解析嚴重錯誤（smalot/pdfparser）: ' . $e->getMessage());
            error_log('錯誤類型: ' . get_class($e));
            error_log('錯誤堆疊: ' . $e->getTraceAsString());
        }
    } else {
        error_log('⚠️ smalot/pdfparser 未安裝，無法使用 PHP PDF 解析庫');
        error_log('請執行: composer install');
    }

    // 方法 2：如果 smalot/pdfparser 失敗，嘗試使用 pdftotext（Linux/Unix/Mac）
    if (empty($text) && function_exists('shell_exec')) {
        // 檢查 pdftotext 是否可用
        $whichCmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? 'where' : 'which';
        $pdftotextPath = @shell_exec("$whichCmd pdftotext 2>&1");

        if (!empty($pdftotextPath) && strpos($pdftotextPath, 'not found') === false) {
            error_log('嘗試使用 pdftotext 提取文字...');
            // 使用 pdftotext 提取文字（保留版面格式，指定 UTF-8 編碼）
            $cmd = "pdftotext -layout -enc UTF-8 \"$tempFile\" - 2>&1";
            $output = @shell_exec($cmd);
            if (!empty($output)) {
                // 嘗試檢測和轉換編碼
                if (!mb_check_encoding($output, 'UTF-8')) {
                    $output = mb_convert_encoding($output, 'UTF-8', 'auto');
                }
                $text = $output;
                error_log('✅ PDF 文字提取成功（使用 pdftotext），長度: ' . strlen($text) . ' 字元');
                error_log('文字預覽（前200字元）: ' . mb_substr($text, 0, 200));
            } else {
                error_log('⚠️ pdftotext 執行成功但無輸出');
            }
        } else {
            error_log('⚠️ pdftotext 未安裝（Windows 環境通常沒有）');
        }
    }

    // 方法 3：如果以上都失敗，嘗試簡單的文字提取（僅適用於文字型 PDF）
    if (empty($text)) {
        error_log('嘗試使用簡單文字提取方法...');
        // 嘗試直接從 PDF 二進位內容中提取可讀文字
        // 這是一個簡單的方法，僅適用於文字型 PDF（非掃描版）
        preg_match_all('/\((.*?)\)/s', $fileContent, $matches);
        if (!empty($matches[1])) {
            $text = implode(' ', $matches[1]);
            // 清理提取的文字
            $text = preg_replace('/[^\x20-\x7E\x{4E00}-\x{9FFF}]/u', ' ', $text);
            $text = preg_replace('/\s+/', ' ', $text);
            if (strlen($text) > 50) { // 如果提取到足夠的文字
                error_log('✅ PDF 文字提取成功（使用簡單提取），長度: ' . strlen($text) . ' 字元');
                error_log('文字預覽（前200字元）: ' . mb_substr($text, 0, 200));
            } else {
                error_log('⚠️ 簡單提取方法提取的文字太少（' . strlen($text) . ' 字元），可能是掃描版 PDF');
                $text = '';
            }
        } else {
            error_log('⚠️ 簡單提取方法無法找到文字內容');
        }
    }

    // 清理臨時檔案
    if (file_exists($tempFile)) {
        @unlink($tempFile);
        error_log('extractTextFromPDF: 臨時檔案已刪除');
    }

    if (empty($text)) {
        error_log('❌ 警告：無法從 PDF 提取文字');
        error_log('可能原因：');
        error_log('  1) 這是掃描版 PDF（圖片），需要 OCR');
        error_log('  2) PDF 格式特殊，無法用標準方法解析');
        error_log('  3) PDF 檔案損壞或格式異常');
        error_log('  4) 系統未安裝 pdftotext（Windows 環境通常沒有）');
        error_log('  5) smalot/pdfparser 解析失敗');
        error_log('  6) 臨時檔案路徑或權限問題');
    } else {
        error_log('✅ PDF 文字提取完成，總長度: ' . strlen($text) . ' 字元');
    }

    return $text;
}

/**
 * 使用 AI 智能匹配題目和資料庫資料
 * @param array $questions 表單題目陣列
 * @param array $dbData 資料庫中的學生資料
 * @return array 匹配結果，格式：[fq_ID => value]
 */
function matchQuestionsWithData($questions, $dbData)
{
    global $conn;

    $results = [];

    // 讀取 Google API Key
    $googleApiKey = getenv('GOOGLE_API_KEY');
    if (empty($googleApiKey)) {
        $configFile = __DIR__ . '/../includes/ai_config.php';
        if (file_exists($configFile)) {
            $config = include $configFile;
            $googleApiKey = $config['google_api_key'] ?? '';
        }
    }

    // 如果沒有 AI API Key，使用基本規則匹配
    if (empty($googleApiKey)) {
        return matchQuestionsWithDataBasic($questions, $dbData);
    }

    // 準備題目列表給 AI（包含更詳細的資訊）
    $questionsText = '';
    foreach ($questions as $q) {
        $qTitle = $q['fq_title'] ?? '';
        $qType = $q['fq_type'] ?? '';
        $qPlaceholder = $q['fq_placeholder'] ?? '';
        $qOptions = '';

        if (in_array($qType, ['select', 'radio', 'checkbox']) && !empty($q['fq_options'])) {
            $options = is_array($q['fq_options']) ? $q['fq_options'] : [];
            $qOptions = '選項: ' . implode(', ', array_slice($options, 0, 10));
            if (count($options) > 10) {
                $qOptions .= '... (共 ' . count($options) . ' 個選項)';
            }
        }

        $questionsText .= "- 題目ID: {$q['fq_ID']}\n";
        $questionsText .= "  題目: {$qTitle}\n";
        $questionsText .= "  類型: {$qType}\n";
        if ($qPlaceholder) {
            $questionsText .= "  提示: {$qPlaceholder}\n";
        }
        if ($qOptions) {
            $questionsText .= "  {$qOptions}\n";
        }
        $questionsText .= "\n";
    }

    // 準備資料庫資料給 AI（包含更多可能的匹配關鍵字）
    $dataText = '';
    foreach ($dbData as $key => $value) {
        if ($value !== null && $value !== '') {
            $dataText .= "- {$key}: {$value}\n";
        }
    }

    // 構建更詳細的 AI 提示
    $prompt = "你是一個智能表單自動填入助手。請仔細分析每個題目的語意，從提供的資料庫資料中找出最匹配的資料並自動填入。

## 題目列表：
{$questionsText}

## 可用的資料庫資料：
{$dataText}

## 任務說明：
1. 分析每個題目的語意和上下文
2. 判斷題目詢問的內容是否對應到資料庫中的某個欄位
3. 對於選擇題，檢查資料庫值是否在選項列表中
4. 只匹配高信心度的題目（confidence >= 0.7）

## 匹配規則：
- 「姓名」、「名字」、「姓名」→ 對應「姓名」欄位
- 「學號」、「學生編號」、「帳號」→ 對應「學號」欄位
- 「班級」、「就讀班級」→ 對應「班級」欄位
- 「屆別」、「學年」→ 對應「屆別」欄位
- 「年級」→ 對應「年級」欄位
- 「專題」、「專題名稱」→ 對應「專題名稱」欄位
- 「類組」→ 對應「類組」欄位
- 「email」、「電子郵件」、「信箱」→ 對應「email」欄位

## 輸出格式（必須是有效的 JSON）：
{
  \"matches\": [
    {
      \"fq_ID\": 題目ID（數字）,
      \"field\": \"資料欄位名稱（如：姓名、學號）\",
      \"value\": \"要填入的值（必須是字串）\",
      \"confidence\": 0.95（0-1之間的數字，表示匹配信心度）,
      \"reason\": \"匹配原因說明\"
    }
  ]
}

## 重要注意事項：
1. 只匹配明顯相關的題目（confidence >= 0.7）
2. 如果題目是選擇題（select/radio/checkbox），value 必須完全匹配選項中的某個值
3. 如果找不到匹配，不要包含該題目
4. 必須返回有效的 JSON 格式，不要包含任何 markdown 代碼塊標記
5. 如果題目已經有預設值或提示，優先考慮這些資訊";

    try {
        $configFile = __DIR__ . '/../includes/ai_config.php';
        $config = file_exists($configFile) ? include $configFile : [];
        $model = $config['google_model'] ?? 'gemini-2.0-flash';

        // 使用 Google Gemini API 進行文本對話
        $apiVersion = getGeminiApiVersion($model);
        $url = "https://generativelanguage.googleapis.com/{$apiVersion}/models/{$model}:generateContent?key=" . urlencode($googleApiKey);

        $requestData = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'maxOutputTokens' => 2000,
                'temperature' => 0.3
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('API 請求失敗，HTTP ' . $httpCode);
        }

        $result = json_decode($response, true);
        if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            throw new Exception('API 回應格式錯誤');
        }

        $aiResponse = $result['candidates'][0]['content']['parts'][0]['text'];

        // 嘗試從回應中提取 JSON（可能包含 markdown 代碼塊）
        $jsonMatch = null;
        if (preg_match('/```json\s*(\{.*?\})\s*```/s', $aiResponse, $matches)) {
            $jsonMatch = json_decode($matches[1], true);
        } elseif (preg_match('/\{.*\}/s', $aiResponse, $matches)) {
            $jsonMatch = json_decode($matches[0], true);
        } else {
            $jsonMatch = json_decode($aiResponse, true);
        }

        // 解析 AI 回應
        if ($jsonMatch && isset($jsonMatch['matches'])) {
            foreach ($jsonMatch['matches'] as $match) {
                if (
                    isset($match['fq_ID']) && isset($match['value']) &&
                    isset($match['confidence']) && $match['confidence'] >= 0.7
                ) {
                    $fq_ID = (int)$match['fq_ID'];
                    $value = (string)$match['value'];
                    $field = $match['field'] ?? '';
                    $reason = $match['reason'] ?? '';

                    // 驗證值是否有效
                    $question = null;
                    foreach ($questions as $q) {
                        if ($q['fq_ID'] == $fq_ID) {
                            $question = $q;
                            break;
                        }
                    }

                    if ($question) {
                        $matched = false;

                        // 如果是選擇題，檢查值是否在選項中
                        if (in_array($question['fq_type'], ['select', 'radio', 'checkbox'])) {
                            $options = is_array($question['fq_options']) ? $question['fq_options'] : [];

                            // 精確匹配
                            if (in_array($value, $options)) {
                                $results[$fq_ID] = $value;
                                $matched = true;
                            } else {
                                // 嘗試模糊匹配（部分匹配）
                                foreach ($options as $option) {
                                    if (
                                        mb_strpos($option, $value) !== false ||
                                        mb_strpos($value, $option) !== false ||
                                        mb_strtolower($option) === mb_strtolower($value)
                                    ) {
                                        $results[$fq_ID] = $option; // 使用選項中的完整值
                                        $matched = true;
                                        break;
                                    }
                                }
                            }
                        } else {
                            // 非選擇題，直接填入
                            $results[$fq_ID] = $value;
                            $matched = true;
                        }

                        if ($matched) {
                            error_log("AI 匹配成功 - 題目ID: {$fq_ID}, 欄位: {$field}, 值: {$value}, 信心度: {$match['confidence']}, 原因: {$reason}");
                        }
                    }
                }
            }
        } else {
            error_log('AI 回應格式錯誤或沒有匹配結果: ' . substr($aiResponse, 0, 500));
        }
    } catch (Exception $e) {
        error_log('AI 匹配失敗，使用基本規則: ' . $e->getMessage());
        // AI 失敗時，回退到基本規則
        return matchQuestionsWithDataBasic($questions, $dbData);
    }

    // 如果 AI 沒有匹配到足夠的結果，補充基本規則匹配
    if (count($results) < count($questions) * 0.3) {
        $basicResults = matchQuestionsWithDataBasic($questions, $dbData);
        $results = array_merge($basicResults, $results);
    }

    return $results;
}

/**
 * 使用基本規則匹配題目和資料
 * @param array $questions 表單題目陣列
 * @param array $dbData 資料庫中的學生資料
 * @return array 匹配結果
 */
function matchQuestionsWithDataBasic($questions, $dbData)
{
    $results = [];

    // 關鍵字映射表（擴展更多可能的關鍵字）
    $keywordMap = [
        'u_ID' => ['學號', '學生編號', '帳號', 'student_id', 'student id', 'id', '編號', '學號：', '學號:', 'student number'],
        'u_name' => ['姓名', '名字', 'name', '姓名：', '姓名:', '您的姓名', '請輸入姓名'],
        'u_gmail' => ['email', '電子郵件', '信箱', 'e-mail', 'email：', 'email:', '電子郵件：', '電子郵件:', '信箱：', '信箱:'],
        'class_name' => ['班級', 'class', '就讀班級', '班級：', '班級:', '所屬班級', '班級名稱'],
        'cohort_name' => ['屆別', 'cohort', '學年', '屆別：', '屆別:', '所屬屆別', '學年度'],
        'enroll_grade' => ['年級', 'grade', '年級：', '年級:', '就讀年級'],
        'team_project_name' => ['專題', 'project', '專題名稱', '專題：', '專題:', '專題題目', 'project name'],
        'group_name' => ['類組', 'group', '類組：', '類組:', '所屬類組', '專題類組']
    ];

    foreach ($questions as $q) {
        $title = mb_strtolower(trim($q['fq_title'] ?? ''));
        $placeholder = mb_strtolower(trim($q['fq_placeholder'] ?? ''));
        $matched = false;

        // 組合題目和提示文字進行匹配
        $searchText = $title . ' ' . $placeholder;

        // 嘗試匹配關鍵字
        foreach ($keywordMap as $dbKey => $keywords) {
            foreach ($keywords as $keyword) {
                $keywordLower = mb_strtolower($keyword);
                // 檢查題目或提示中是否包含關鍵字
                if (mb_strpos($searchText, $keywordLower) !== false) {
                    $value = $dbData[$dbKey] ?? null;
                    if ($value !== null && $value !== '') {
                        $valueStr = (string)$value;

                        // 如果是選擇題，檢查值是否在選項中
                        if (in_array($q['fq_type'], ['select', 'radio', 'checkbox'])) {
                            $options = is_array($q['fq_options']) ? $q['fq_options'] : [];

                            if (!empty($options)) {
                                // 精確匹配
                                if (in_array($valueStr, $options)) {
                                    $results[$q['fq_ID']] = $valueStr;
                                    $matched = true;
                                    break;
                                }

                                // 模糊匹配（部分包含）
                                foreach ($options as $option) {
                                    $optionStr = (string)$option;
                                    if (
                                        mb_strpos($optionStr, $valueStr) !== false ||
                                        mb_strpos($valueStr, $optionStr) !== false ||
                                        mb_strtolower($optionStr) === mb_strtolower($valueStr)
                                    ) {
                                        $results[$q['fq_ID']] = $optionStr;
                                        $matched = true;
                                        break 2; // 跳出兩層循環
                                    }
                                }
                            }
                        } else {
                            // 非選擇題，直接填入
                            $results[$q['fq_ID']] = $valueStr;
                            $matched = true;
                            break;
                        }
                    }
                }
            }
            if ($matched) break;
        }
    }

    return $results;
}
