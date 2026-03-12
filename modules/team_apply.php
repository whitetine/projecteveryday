<!-- modules/team_apply.php -->
<?php
/**
 * 專題申請表後端 API 模組 (Refactored)
 */
global $conn;
$p = $_POST;
$do = $_GET['do'] ?? '';
$u_ID = $_SESSION['u_ID'] ?? null;

// --- 工具函式區 ---

// 1. 通用權限檢查
function require_role($roles, $err_msg = '無權限執行此操作') {
    global $conn, $u_ID;
    if (!$u_ID) json_err('請先登入', 'NOT_LOGGED_IN', 401);
    
    $roles = is_array($roles) ? $roles : [$roles];
    $in  = str_repeat('?,', count($roles) - 1) . '?';
    $sql = "SELECT COUNT(*) FROM userrolesdata WHERE ur_u_ID = ? AND role_ID IN ($in) AND user_role_status = 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$u_ID], $roles));
    
    if (!$stmt->fetchColumn()) json_err($err_msg, 'NO_PERMISSION', 403);
    return $u_ID;
}

// 2. 通用 GAS 郵件發送 (合併了 sendRejectionEmail 與 sendStudentEmailGeneric)
// function send_gas_email($to, $subject, $message) {
//     if (empty($to)) return;
//     $url = "https://script.google.com/macros/s/AKfycbyLLkHxyGhJkllgpztDzcXPcp_IKXL_GS2lnOGDegOAQplqQMVU0EA4LF4ZPDrrkfyb/exec";
//     $context = stream_context_create([
//         "http" => [
//             "method" => "POST",
//             "header" => "Content-type: application/x-www-form-urlencoded",
//             "content" => http_build_query(['to' => $to, 'subject' => $subject, 'message' => $message]),
//             "timeout" => 5 // 縮短 timeout 避免卡住
//         ]
//     ]);
//     @file_get_contents($url, false, $context);
// }

// 3. 檢查資料表欄位 (解決 u_ID / team_u_ID 版本差異)
function get_col_name($table, $col_check, $default = 'u_ID') {
    global $conn;
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col_check'");
        return $stmt->fetch() ? $col_check : $default;
    } catch (Exception $e) { return $default; }
}

// 4. 系統通知寫入
function add_sys_msg($title, $content, $target_uids) {
    global $conn;
    if (empty($target_uids)) return;
    
    $stmt = $conn->prepare("INSERT INTO msgdata (msg_title, msg_content, msg_type, msg_a_u_ID, msg_status, msg_start_d, msg_created_d) VALUES (?, ?, 'SYSTEM_NOTICE', 'system', 1, NOW(), NOW())");
    $stmt->execute([$title, $content]);
    $msg_ID = $conn->lastInsertId();

    $stmt = $conn->prepare("INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID) VALUES (?, 'USER', ?)");
    foreach ((array)$target_uids as $uid) {
        $stmt->execute([$msg_ID, $uid]);
    }
}

// 5. 專題申請：寄送 Gmail 通知（使用 Apps Script Webhook）
function send_team_apply_mail(array $payload) {
    // 這個 URL 與 forgot_password2.php 相同，由你在 Apps Script 處理 HTML 與按鈕
    $url = 'https://script.google.com/macros/s/AKfycbwtvjxzfFbuZvDNsPtMIyQpGuvK5Eg24lD5x_DDlLVmpaxLgAdP7sSTRslJu5rmsgE2/exec';
    if (empty($payload['to'])) return;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    // 不阻擋主流程，錯誤直接忽略或寫 log 即可
}

// --- API 邏輯區 ---

try {
    switch ($do) {
        // 獲取指導老師列表
        case 'get_teachers':
            // 1. 找出當前登入使用者的屆別 (cohort_ID)
            $user_cohort = null;
            // 嘗試從學籍資料表 (enrollmentdata) 抓取該使用者的最新屆別
            $stmt = $conn->prepare("SELECT cohort_ID FROM enrollmentdata WHERE enroll_u_ID = ? ORDER BY enroll_ID DESC LIMIT 1");
            $stmt->execute([$u_ID]);
            $user_cohort = $stmt->fetchColumn();

            // 如果找不到 (例如是管理者或特殊帳號)，則預設抓取系統最新的開啟屆別
            if (!$user_cohort) {
                $user_cohort = $conn->query("SELECT cohort_ID FROM cohortdata WHERE cohort_status=1 ORDER BY cohort_ID DESC LIMIT 1")->fetchColumn();
            }

            // 2. 獲取 teammember 資料表的正確欄位名稱 (相容 u_ID 或 team_u_ID)
            $tm_col = get_col_name('teammember', 'team_u_ID');

            // 3. 查詢老師列表並包含統計數據
            // led_count: 已成立且狀態為 1 的隊伍
            // apply_count: 申請中 (status=1) 且申請人屬於同一屆別
            $sql = "SELECT DISTINCT u.u_ID, u.u_name,
                    (
                        SELECT COUNT(*)
                        FROM teammember tm
                        JOIN teamdata t ON tm.team_ID = t.team_ID
                        WHERE tm.$tm_col = u.u_ID
                          AND t.team_status = 1
                          AND t.cohort_ID = ?
                          AND tm.tm_status = 1
                    ) as led_count,
                    (
                        SELECT COUNT(*)
                        FROM teamapply tap
                        JOIN enrollmentdata ed ON tap.tap_u_ID = ed.enroll_u_ID
                        WHERE tap.tap_teacher = u.u_ID
                          AND tap.tap_status = 1
                          AND ed.cohort_ID = ?
                    ) as apply_count
                    FROM userdata u 
                    JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID 
                    WHERE ur.role_ID = 4 AND ur.user_role_status = 1 AND u.u_status = 1 
                    ORDER BY u.u_name";
            
            $stmt = $conn->prepare($sql);
            // 傳入兩次 $user_cohort，分別給兩個子查詢使用
            $stmt->execute([$user_cohort, $user_cohort]);
            $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_ok(['teachers' => $teachers ?: []]);
            break;
        case 'get_apply_form_config':
            require_role(6);

            // 1) 使用者屆別（用 enrollmentdata 最新有效）
            $stmt = $conn->prepare("
                SELECT cohort_ID
                FROM enrollmentdata
                WHERE enroll_u_ID=? AND enroll_status=1
                ORDER BY enroll_ID DESC
                LIMIT 1
            ");
            $stmt->execute([$u_ID]);
            $cohort_ID = (int)($stmt->fetchColumn() ?: 0);
            if (!$cohort_ID) json_err('找不到你的屆別');

            // 2) 取得該屆開放的申請表（teamapplyform）
            $stmt = $conn->prepare("
                SELECT taf_ID, taf_title, taf_cohort_ID, taf_ttl, taf_ttm_ID, taf_note
                FROM teamapplyform
                WHERE taf_status=1 AND taf_cohort_ID=?
                ORDER BY taf_ID DESC
                LIMIT 1
            ");
            $stmt->execute([$cohort_ID]);
            $taf = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$taf) json_err('本屆尚未開放申請表，請洽系辦');

            // 3) 組員限制（teammemberlimit）
            $stmt = $conn->prepare("SELECT min_count, max_count FROM teammemberlimit WHERE ttm_ID=? LIMIT 1");
            $stmt->execute([(int)$taf['taf_ttm_ID']]);
            $ttm = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['min_count' => 1, 'max_count' => 4];

            // 4) 欄位控制（teamapplycontrol）
            $stmt = $conn->prepare("
                SELECT tpc_name, tpc_require, tpc_show
                FROM teamapplycontrol
                WHERE tpc_taf_ID=? AND tpc_status=1
            ");
            $stmt->execute([(int)$taf['taf_ID']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 支援的前台欄位 key（team_apply.php 使用）
            $supportedFields = [
                'tap_name',
                'tap_teacher',
                'tap_teacher_2',
                'tap_teacher_3',
                'tap_group',
                'tap_co_teacher',
                'tap_member',
                'tap_url',
                'tap_des'
            ];

            // 規則：
            // - 若有任何控制項資料，預設欄位都隱藏，僅顯示設定清單中的欄位。
            // - 若尚未設定任何控制項，為了相容舊資料，全部欄位預設顯示。
            $hasAnyControl = count($rows) > 0;
            $fieldMap = [];
            foreach ($supportedFields as $fieldKey) {
                $fieldMap[$fieldKey] = [
                    'show' => !$hasAnyControl,
                    'require' => false
                ];
            }

            $ctrlNames = [];
            foreach ($rows as $r) {
                $k = trim((string)$r['tpc_name']);
                if ($k === '' || !isset($fieldMap[$k])) continue;
                $ctrlNames[] = $k;
                $fieldMap[$k] = [
                    'show' => (int)$r['tpc_show'] === 1,
                    'require' => (int)$r['tpc_require'] === 1
                ];
            }
            // 提交檔案(tap_url)：若控制項中未加入此欄位，預設顯示（避免遺漏）
            if ($hasAnyControl && !in_array('tap_url', $ctrlNames)) {
                $fieldMap['tap_url']['show'] = true;
            }

            $cohort_label = '';
            $stmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ? LIMIT 1");
            $stmt->execute([$cohort_ID]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $cohort_label = trim($row['cohort_name'] ?? '');

            $applicant_class_label = '';
            $stmt = $conn->prepare("SELECT e.enroll_grade, c.c_name FROM enrollmentdata e LEFT JOIN classdata c ON e.class_ID = c.c_ID WHERE e.enroll_u_ID = ? AND e.cohort_ID = ? AND e.enroll_status = 1 ORDER BY e.enroll_ID DESC LIMIT 1");
            $stmt->execute([$u_ID, $cohort_ID]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $grade = trim($row['enroll_grade'] ?? '');
                $cname = trim($row['c_name'] ?? '');
                $applicant_class_label = '資' . $grade . $cname;
            }

            json_ok([
                'cohort_ID' => $cohort_ID,
                'cohort_label' => $cohort_label,
                'taf' => [
                    'taf_ID' => (int)$taf['taf_ID'],
                    'title' => $taf['taf_title'],
                    'cohort_label' => $cohort_label,
                    'applicant_class_label' => $applicant_class_label,
                    'teacher_max_default' => (int)$taf['taf_ttl'],
                    'min_member' => (int)$ttm['min_count'],
                    'max_member' => (int)$ttm['max_count'],
                    'note' => $taf['taf_note'] ?? ''
                ],
                'fields' => $fieldMap
            ]);
            break;

        // 根據學號查詢學生資訊 (含重複檢查)
        case 'get_student_info':
            $sid = trim($p['student_id'] ?? '');
            if (!$sid) json_err('請輸入學號');
            $cohort_ID = (int)($p['cohort_ID'] ?? 0);
            if (!$cohort_ID) {
                $stmt = $conn->prepare("SELECT cohort_ID FROM enrollmentdata WHERE enroll_u_ID=? AND enroll_status=1 ORDER BY enroll_ID DESC LIMIT 1");
                $stmt->execute([$u_ID]);
                $cohort_ID = (int)($stmt->fetchColumn() ?: 0);
            }

            // 查學生基本資料
            $stmt = $conn->prepare("SELECT u.u_ID, u.u_name, u.u_status FROM userdata u 
                                    JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID 
                                    WHERE u.u_ID = ? AND ur.role_ID = 6 AND ur.user_role_status = 1 AND u.u_status = 1 LIMIT 1");
            $stmt->execute([$sid]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) json_err('找不到該學號的學生');

            // 班級：資 + enroll_grade + classdata.c_name
            $class_label = '';
            $stmt = $conn->prepare("SELECT e.enroll_grade, c.c_name FROM enrollmentdata e LEFT JOIN classdata c ON e.class_ID = c.c_ID WHERE e.enroll_u_ID = ? AND e.cohort_ID = ? AND e.enroll_status = 1 ORDER BY e.enroll_ID DESC LIMIT 1");
            $stmt->execute([$sid, $cohort_ID]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $grade = trim($row['enroll_grade'] ?? '');
                $cname = trim($row['c_name'] ?? '');
                $class_label = '資' . $grade . $cname;
            }
            $student['class_label'] = $class_label;

            // 檢查是否已有團隊（僅計 tm_status=1，status=0 不擋）
            $col = get_col_name('teammember', 'team_u_ID');
            $stmt = $conn->prepare("SELECT COUNT(*) FROM teammember tm JOIN teamdata t ON tm.team_ID = t.team_ID WHERE tm.$col = ? AND t.team_status = 1 AND tm.tm_status = 1");
            $stmt->execute([$sid]);
            if ($stmt->fetchColumn()) json_err('該學生已有團隊');

            // 檢查審核中
            $stmt = $conn->prepare("SELECT tap_member FROM teamapply WHERE (tap_u_ID = ? OR tap_member LIKE ?) AND tap_status = 1");
            $stmt->execute([$sid, "%\"$sid\"%"]);
            if ($stmt->fetchColumn()) json_err('該學生已有審核中的申請');

            json_ok(['student' => $student]);
            break;

        // 暫存專題申請 (status=4)
        case 'save_draft':
            require_role(6);
            $t_id = trim($p['teacher_id'] ?? '');
            $g_id = trim($p['group_id'] ?? '');
            $p_name = trim($p['project_name'] ?? '');
            $m_ids = json_decode($p['member_ids'] ?? '[]', true);
            if (!is_array($m_ids)) $m_ids = [];
            $co_t_id = trim($p['co_teacher_id'] ?? '');
            $t2_id = trim($p['teacher_id_2'] ?? '');
            $t3_id = trim($p['teacher_id_3'] ?? '');
            $comment = trim($p['comment'] ?? '');
            $draft_tap_ID = (int)($p['tap_ID'] ?? 0);

            $stmt = $conn->prepare("SELECT cohort_ID FROM enrollmentdata WHERE enroll_u_ID = ? AND enroll_status = 1 ORDER BY enroll_ID DESC LIMIT 1");
            $stmt->execute([$u_ID]);
            $cohort_ID = (int)$stmt->fetchColumn();
            if (!$cohort_ID) json_err('找不到你的屆別');

            $stmt = $conn->prepare("SELECT taf_ID, taf_ttl, taf_ttm_ID FROM teamapplyform WHERE taf_status=1 AND taf_cohort_ID=? ORDER BY taf_ID DESC LIMIT 1");
            $stmt->execute([$cohort_ID]);
            $tafCfg = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tafCfg) json_err('本屆尚未開放申請表');

            $taf_ID = (int)($tafCfg['taf_ID'] ?? 0);
            if ($taf_ID <= 0) json_err('找不到申請表設定');

            $imgUrl = '';
            if (!empty($_FILES['apply_image']['name'])) {
                $f = $_FILES['apply_image'];
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                $allowedImgExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'ico', 'heic', 'avif'];
                if (in_array($ext, $allowedImgExt)) {
                    $path = 'uploads/team_apply/apply_' . preg_replace('/\W/', '', $u_ID) . '_' . time() . '.' . $ext;
                    if (!is_dir(dirname(__DIR__ . '/../' . $path))) mkdir(dirname(__DIR__ . '/../' . $path), 0775, true);
                    if (move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $path)) $imgUrl = $path;
                }
            }

            $desArr = [];
            if ($comment) $desArr['comment'] = $comment;
            if ($co_t_id) $desArr['co_teacher_id'] = $co_t_id;
            if ($t2_id) $desArr['teacher_2_id'] = $t2_id;
            if ($t3_id) $desArr['teacher_3_id'] = $t3_id;
            $hasTapGroup = get_col_name('teamapply', 'tap_group', '') === 'tap_group';
            if (!$hasTapGroup) $desArr['group_id'] = $g_id;
            $desJson = $desArr ? json_encode($desArr, JSON_UNESCAPED_UNICODE) : '';
            $memJson = json_encode($m_ids, JSON_UNESCAPED_UNICODE);

            if (!in_array($u_ID, $m_ids, true)) $m_ids[] = $u_ID;

            $conn->beginTransaction();
            try {
                $existing = null;
                if ($draft_tap_ID > 0) {
                    $stmt = $conn->prepare("SELECT tap_ID, tap_u_ID FROM teamapply WHERE tap_ID = ? AND tap_status = 4");
                    $stmt->execute([$draft_tap_ID]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($existing && $existing['tap_u_ID'] !== $u_ID) $existing = null;
                }

                if ($existing) {
                    $sets = ["tap_name=?", "tap_member=?", "tap_teacher=?", "tap_url=?", "tap_des=?", "tap_update_d=NOW()"];
                    $params = [$p_name, $memJson, $t_id, $imgUrl, $desJson];
                    if ($hasTapGroup) { array_splice($sets, 3, 0, ["tap_group=?"]); array_splice($params, 3, 0, [$g_id]); }
                    $params[] = $draft_tap_ID;
                    $conn->prepare("UPDATE teamapply SET " . implode(', ', $sets) . " WHERE tap_ID = ?")->execute($params);
                    $tap_ID = $draft_tap_ID;
                } else {
                    $sql = "INSERT INTO teamapply (tap_taf_ID, tap_name, tap_member, tap_teacher, " . ($hasTapGroup ? "tap_group, " : "") . "tap_url, tap_des, tap_status, tap_u_ID, tap_update_d, tap_rp_d) VALUES (?, ?, ?, ?, " . ($hasTapGroup ? "?, " : "") . "?, ?, 4, ?, NOW(), NOW())";
                    $params = $hasTapGroup ? [$taf_ID, $p_name, $memJson, $t_id, $g_id, $imgUrl, $desJson, $u_ID] : [$taf_ID, $p_name, $memJson, $t_id, $imgUrl, $desJson, $u_ID];
                    $conn->prepare($sql)->execute($params);
                    $tap_ID = $conn->lastInsertId();
                }
                $conn->commit();
                json_ok(['message' => '暫存成功', 'tap_ID' => $tap_ID]);
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;

        // 提交專題申請
        case 'submit_application':
            require_role(6); // 限學生

            // --- 1. 參數驗證 ---
            $t_id = trim($p['teacher_id'] ?? '');
            $g_id = trim($p['group_id'] ?? '');
            $p_name = trim($p['project_name'] ?? '');
            $m_ids = json_decode($p['member_ids'] ?? '[]', true);
            if (!is_array($m_ids)) $m_ids = [];
            $co_t_id = trim($p['co_teacher_id'] ?? '');
            $t2_id = trim($p['teacher_id_2'] ?? '');
            $t3_id = trim($p['teacher_id_3'] ?? '');
            $comment = trim($p['comment'] ?? '');

            // --- 1.5 取得當前屆別與申請表設定（用以檢查老師/組員上限） ---
            $stmt = $conn->prepare("SELECT cohort_ID FROM enrollmentdata WHERE enroll_u_ID = ? AND enroll_status = 1 ORDER BY enroll_ID DESC LIMIT 1");
            $stmt->execute([$u_ID]);
            $cohort_ID = (int)$stmt->fetchColumn();
            if (!$cohort_ID) json_err('找不到你的屆別');

            $stmt = $conn->prepare("SELECT taf_ID, taf_ttl, taf_ttm_ID FROM teamapplyform WHERE taf_status=1 AND taf_cohort_ID=? ORDER BY taf_ID DESC LIMIT 1");
            $stmt->execute([$cohort_ID]);
            $tafCfg = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tafCfg) json_err('本屆尚未開放申請表，請洽系辦');

            // 取得組員限制
            $stmt = $conn->prepare("SELECT min_count, max_count FROM teammemberlimit WHERE ttm_ID=? LIMIT 1");
            $stmt->execute([(int)$tafCfg['taf_ttm_ID']]);
            $ttm = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['min_count'=>1,'max_count'=>4];

            // 欄位控制（若有控制資料，前台欄位即以控制資料為準）
            $stmt = $conn->prepare("
                SELECT tpc_name, tpc_require, tpc_show
                FROM teamapplycontrol
                WHERE tpc_taf_ID=? AND tpc_status=1
            ");
            $stmt->execute([(int)$tafCfg['taf_ID']]);
            $ctrlRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $hasAnyControl = count($ctrlRows) > 0;

            $fieldMap = [
                'tap_name' => ['show' => !$hasAnyControl, 'require' => false],
                'tap_teacher' => ['show' => !$hasAnyControl, 'require' => false],
                'tap_teacher_2' => ['show' => !$hasAnyControl, 'require' => false],
                'tap_teacher_3' => ['show' => !$hasAnyControl, 'require' => false],
                'tap_group' => ['show' => !$hasAnyControl, 'require' => false],
                'tap_co_teacher' => ['show' => !$hasAnyControl, 'require' => false],
                'tap_member' => ['show' => !$hasAnyControl, 'require' => false],
                'tap_url' => ['show' => !$hasAnyControl, 'require' => false],
                'tap_des' => ['show' => !$hasAnyControl, 'require' => false],
            ];
            $ctrlNames = [];
            foreach ($ctrlRows as $r) {
                $k = trim((string)$r['tpc_name']);
                if ($k === '' || !isset($fieldMap[$k])) continue;
                $ctrlNames[] = $k;
                $fieldMap[$k] = [
                    'show' => (int)$r['tpc_show'] === 1,
                    'require' => (int)$r['tpc_require'] === 1,
                ];
            }
            if ($hasAnyControl && !in_array('tap_url', $ctrlNames)) {
                $fieldMap['tap_url']['show'] = true;
            }

            // 顯示且必填才驗證（符合 admin 控制）
            if ($fieldMap['tap_name']['show'] && $fieldMap['tap_name']['require'] && $p_name === '') {
                json_err('請填寫專題名稱');
            }
            if ($fieldMap['tap_teacher']['show'] && $fieldMap['tap_teacher']['require'] && $t_id === '') {
                json_err('請選擇指導老師');
            }
            if ($fieldMap['tap_group']['show'] && $fieldMap['tap_group']['require'] && $g_id === '') {
                json_err('請選擇類組');
            }

            // 業務規則：指導老師仍為必要欄位（審核通過後需建立師生 teammember 關聯）
            if ($t_id === '') {
                json_err('請選擇指導老師');
            }

            // 組員欄位若不顯示，至少保留申請者本人
            if (!($fieldMap['tap_member']['show'])) {
                $m_ids = [$u_ID];
            } else {
                if (!in_array($u_ID, $m_ids, true)) $m_ids[] = $u_ID; // 確保申請人在名單內
                if (count($m_ids) < (int)$ttm['min_count'] || count($m_ids) > (int)$ttm['max_count']) {
                    json_err(sprintf('組員數量需介於 %d 到 %d 人', (int)$ttm['min_count'], (int)$ttm['max_count']));
                }
            }

            // --- 2. 檢查老師帶組上限（僅計 tm_status=1）---
            $tm_col = get_col_name('teammember', 'team_u_ID');
            $stmt = $conn->prepare("SELECT COUNT(*) FROM teammember tm JOIN teamdata t ON tm.team_ID = t.team_ID WHERE tm.$tm_col = ? AND t.team_status = 1 AND t.cohort_ID = ? AND tm.tm_status = 1");
            $stmt->execute([$t_id, $cohort_ID]);
            $led_count = (int)$stmt->fetchColumn();

            $stmt = $conn->prepare("SELECT COUNT(*) FROM teamapply tap JOIN enrollmentdata ed ON tap.tap_u_ID = ed.enroll_u_ID WHERE tap.tap_teacher = ? AND tap.tap_status = 1 AND ed.cohort_ID = ?");
            $stmt->execute([$t_id, $cohort_ID]);
            $apply_count = (int)$stmt->fetchColumn();

            $teacher_max = (int)$tafCfg['taf_ttl'];
            if ($teacher_max > 0 && $led_count >= $teacher_max) json_err('該指導老師已達帶組上限，請選擇其他老師');

            // --- 3. 檢查是否已有申請紀錄（包含退件/封存）
            // 狀態約定：
            // 0：封存（歷史紀錄）
            // 1：審核中
            // 2：退件
            // 3：已通過
            // 4：暫存
            // 這裡取出「最新一筆」做後續判斷，讓退件(2/0) 可以用 UPDATE 方式重新送出
            $stmt = $conn->prepare("SELECT tap_ID, tap_status, tap_url FROM teamapply WHERE tap_u_ID = ? ORDER BY tap_update_d DESC, tap_ID DESC LIMIT 1");
            $stmt->execute([$u_ID]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $existStatus = (int)$existing['tap_status'];
                if ($existStatus === 1) {
                    // 審核中禁止再次送出
                    json_err('您已有「審核中」的申請，請耐心等候結果，勿重複提交。');
                }
                if ($existStatus === 3) {
                    // 已通過禁止再次送出
                    json_err('您的專題申請「已通過」，無法再次提交。');
                }
            }
            // 允許可被覆寫再送出的狀態：暫存(4)、退件(2)、封存(0)
            $isDraftSubmit = $existing && in_array((int)$existing['tap_status'], [0, 2, 4], true);
            $draftTapId = $isDraftSubmit ? (int)$existing['tap_ID'] : 0;

            // --- 5. 圖片上傳 ---
            // 規則：
            // - 若本次有上傳新檔，覆蓋原有 tap_url
            // - 若本次未上傳，但舊資料已有 tap_url，沿用舊值（避免退件後被迫重傳）
            $imgUrl = '';
            if (!empty($_FILES['apply_image']['name'])) {
                $f = $_FILES['apply_image'];
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                $allowedImgExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'ico', 'heic', 'avif'];
                if (!in_array($ext, $allowedImgExt)) json_err('不支援的圖片格式，請使用 jpg、png、gif、webp、bmp、tiff、ico、heic、avif');

                $path = 'uploads/team_apply/apply_' . preg_replace('/\W/', '', $u_ID) . '_' . time() . '.' . $ext;
                if (!is_dir(dirname(__DIR__ . '/../' . $path))) mkdir(dirname(__DIR__ . '/../' . $path), 0775, true);
                if (move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $path)) $imgUrl = $path;
            } elseif ($isDraftSubmit && !empty($existing['tap_url'])) {
                // 沒有重新上傳，但舊紀錄已有檔案 → 沿用
                $imgUrl = $existing['tap_url'];
            }
            if ($fieldMap['tap_url']['show'] && $fieldMap['tap_url']['require'] && !$imgUrl) {
                json_err('請上傳申請表照片');
            }

            // --- 6. 寫入資料 ---
            $desArr = [];
            if ($comment) $desArr['comment'] = $comment;
            if ($co_t_id) $desArr['co_teacher_id'] = $co_t_id;
            if ($t2_id) $desArr['teacher_2_id'] = $t2_id;
            if ($t3_id) $desArr['teacher_3_id'] = $t3_id;
            $hasTapGroup = get_col_name('teamapply', 'tap_group', '') === 'tap_group';
            if (!$hasTapGroup) $desArr['group_id'] = $g_id;

            $desJson = $desArr ? json_encode($desArr, JSON_UNESCAPED_UNICODE) : '';
            $memJson = json_encode($m_ids, JSON_UNESCAPED_UNICODE);

            $taf_ID = (int)($tafCfg['taf_ID'] ?? 0);
            if ($taf_ID <= 0) json_err('找不到申請表設定');

            $conn->beginTransaction();
            try {
                if ($isDraftSubmit && $draftTapId > 0) {
                    // 重新送出暫存 / 退件 / 封存的申請，統一用 UPDATE 寫回同一筆 tap_ID
                    $sets = ["tap_name=?", "tap_member=?", "tap_teacher=?", "tap_url=?", "tap_des=?", "tap_status=1", "tap_update_d=NOW()"];
                    $params = [$p_name, $memJson, $t_id, $imgUrl, $desJson, $draftTapId];
                    if ($hasTapGroup) { array_splice($sets, 3, 0, ["tap_group=?"]); array_splice($params, 3, 0, [$g_id]); }
                    $conn->prepare("UPDATE teamapply SET " . implode(', ', $sets) . " WHERE tap_ID = ?")->execute($params);
                    $tap_ID = $draftTapId;
                } else {
                    $sql = "INSERT INTO teamapply (
                                tap_taf_ID, tap_name, tap_member, tap_teacher, " . ($hasTapGroup ? "tap_group, " : "") . "
                                tap_url, tap_des, tap_status, tap_u_ID, tap_update_d, tap_rp_d
                            ) VALUES (
                                ?, ?, ?, ?, " . ($hasTapGroup ? "?, " : "") . "
                                ?, ?, 1, ?, NOW(), NOW()
                            )";

                    $params = $hasTapGroup
                        ? [$taf_ID, $p_name, $memJson, $t_id, $g_id, $imgUrl, $desJson, $u_ID]
                        : [$taf_ID, $p_name, $memJson, $t_id, $imgUrl, $desJson, $u_ID];

                    $stmt = $conn->prepare($sql);
                    $stmt->execute($params);
                    $tap_ID = $conn->lastInsertId();
                }

                if (function_exists('add_sys_msg')) {
                    $officeUIDs = $conn->query("SELECT ur_u_ID FROM userrolesdata WHERE role_ID = 2 AND user_role_status = 1")->fetchAll(PDO::FETCH_COLUMN);
                    add_sys_msg('專題申請通知', "學生 $u_ID 提交了申請，請前往審核。", $officeUIDs);
                }

                $conn->commit();

                // --- 寄送 Gmail 通知給指導老師與提交人 ---
                try {
                    // 查指導老師與提交人 email / 姓名
                    $stmt = $conn->prepare("SELECT u_ID, u_name, u_gmail FROM userdata WHERE u_ID IN (?, ?)");
                    $stmt->execute([$t_id, $u_ID]);
                    $teacherEmail = '';
                    $teacherName = '';
                    $studentEmail = '';
                    $studentName = '';
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if ($row['u_ID'] === $t_id) {
                            $teacherEmail = $row['u_gmail'] ?? '';
                            $teacherName = $row['u_name'] ?? $t_id;
                        } elseif ($row['u_ID'] === $u_ID) {
                            $studentEmail = $row['u_gmail'] ?? '';
                            $studentName = $row['u_name'] ?? $u_ID;
                        }
                    }

                    // 產生一鍵審核連結（approve/reject）
                    $host = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
                    $basePath = rtrim(dirname($_SERVER['REQUEST_URI'] ?? '/'), '/');
                    $baseUrl = $host . $basePath . '/';

                    $secret = getenv('TEAM_APPLY_MAIL_SECRET') ?: 'change_this_team_apply_secret';
                    $approveToken = hash_hmac('sha256', "approve|$tap_ID", $secret);
                    $rejectToken = hash_hmac('sha256', "reject|$tap_ID", $secret);

                    $approveUrl = $baseUrl . "team_apply_mail_action.php?action=approve&tap_ID={$tap_ID}&token={$approveToken}";
                    $rejectUrl  = $baseUrl . "team_apply_mail_action.php?action=reject&tap_ID={$tap_ID}&token={$rejectToken}";

                    // 組員文字
                    $memberNames = [];
                    if (!empty($m_ids)) {
                        $in = implode(',', array_fill(0, count($m_ids), '?'));
                        $st2 = $conn->prepare("SELECT u_ID, u_name FROM userdata WHERE u_ID IN ($in)");
                        $st2->execute($m_ids);
                        while ($r2 = $st2->fetch(PDO::FETCH_ASSOC)) {
                            $memberNames[] = ($r2['u_name'] ?? $r2['u_ID']) . '(' . $r2['u_ID'] . ')';
                        }
                    }
                    $memberText = implode('、', $memberNames);

                    // 給指導老師的 email
                    if (!empty($teacherEmail)) {
                        send_team_apply_mail([
                            'type'         => 'TEAM_APPLY_NOTIFY_TEACHER',
                            'to'           => $teacherEmail,
                            'teacher_id'   => $t_id,
                            'teacher_name' => $teacherName,
                            'student_id'   => $u_ID,
                            'student_name' => $studentName,
                            'project_name' => $p_name,
                            'members'      => $memberText,
                            'approve_url'  => $approveUrl,
                            'reject_url'   => $rejectUrl,
                        ]);
                    }

                    // 給學生的確認信（可選）
                    if (!empty($studentEmail)) {
                        send_team_apply_mail([
                            'type'         => 'TEAM_APPLY_NOTIFY_STUDENT',
                            'to'           => $studentEmail,
                            'student_id'   => $u_ID,
                            'student_name' => $studentName,
                            'teacher_id'   => $t_id,
                            'teacher_name' => $teacherName,
                            'project_name' => $p_name,
                            'members'      => $memberText,
                        ]);
                    }
                } catch (Exception $e) {
                    // 寄信錯誤不影響主流程
                    error_log('team_apply mail error: ' . $e->getMessage());
                }

                json_ok(['message' => '提交成功，等待審核中', 'tap_ID' => $tap_ID]);

            } catch (Exception $e) {
                $conn->rollBack();
                if(file_exists(__DIR__ . '/../' . $imgUrl)) unlink(__DIR__ . '/../' . $imgUrl);
                throw $e;
            }
            break;

        // 審核申請 (通過/退件)
        case 'review_application':
            $reviewer = require_role([1, 2]); // 主任或科辦
            $tap_ID = (int)($p['tap_ID'] ?? $p['sub_ID'] ?? 0);
            $action = $p['action'] ?? '';
            $remark = trim($p['remark'] ?? '');
            
            $app = $conn->query("SELECT * FROM teamapply WHERE tap_ID = $tap_ID")->fetch(PDO::FETCH_ASSOC);
            if (!$app) json_err('找不到申請');

            // 單純存備註
            if ($action === 'save_remark') {
                $conn->prepare("UPDATE teamapply SET tap_remark = ?, tap_rp_u_ID = ?, tap_update_d = NOW() WHERE tap_ID = ?")->execute([$remark, $reviewer, $tap_ID]);
                json_ok(['msg' => '備註已儲存']);
            }

            if ($app['tap_status'] != 1) json_err('此申請已處理過');

            $conn->beginTransaction();
            try {
                if ($action === 'approve') {
                    // 1. 取得屆別與類組
                    $cohort = $conn->query("SELECT cohort_ID FROM cohortdata WHERE cohort_status=1 ORDER BY cohort_ID DESC LIMIT 1")->fetchColumn();
                    $g_id = $app['tap_group'] ?? json_decode($app['tap_des'] ?? '{}', true)['group_id'] ?? $conn->query("SELECT group_ID FROM groupdata WHERE group_status=1 LIMIT 1")->fetchColumn();
                    
                    // 2. 建立 Team
                    $stmt = $conn->prepare("INSERT INTO teamdata (group_ID, team_project_name, cohort_ID, team_status, team_update_d, team_url) VALUES (?, ?, ?, 1, NOW(), ?)");
                    $stmt->execute([$g_id, $app['tap_name'], $cohort, $app['tap_url']]);
                    $team_ID = $conn->lastInsertId();

                    // 3. 建立 Team Member
                    $col = get_col_name('teammember', 'team_u_ID');
                    $members = array_merge(json_decode($app['tap_member'], true) ?: [], [$app['tap_teacher']]);
                    $stmt = $conn->prepare("INSERT INTO teammember (team_ID, $col, tm_status, tm_updated_d, tm_url) VALUES (?, ?, 1, NOW(), ?)");
                    foreach ($members as $uid) $stmt->execute([$team_ID, $uid, $app['tap_url']]);

                    // 4. 更新申請單
                    $conn->prepare("UPDATE teamapply SET tap_status=3, tap_rp_u_ID=?, tap_rp_d=NOW(), tap_remark=?, tap_update_d=NOW() WHERE tap_ID=?")->execute([$reviewer, $remark, $tap_ID]);

                    // 5. 通知
                    add_sys_msg('專題申請通過', "您的專題「{$app['tap_name']}」已通過審核。", $members);

                    json_ok(['message' => '申請已通過，團隊已建立']);

                } elseif ($action === 'reject') {
                    // 更新狀態
                    $conn->prepare("UPDATE teamapply SET tap_status=2, tap_rp_u_ID=?, tap_rp_d=NOW(), tap_remark=?, tap_update_d=NOW() WHERE tap_ID=?")->execute([$reviewer, $remark, $tap_ID]);
                    
                    // 通知與 Email
                    $stu = $conn->query("SELECT u_name, u_gmail FROM userdata WHERE u_ID = '{$app['tap_u_ID']}'")->fetch(PDO::FETCH_ASSOC);
                    add_sys_msg('專題申請退件', "您的申請已被退件。原因：$remark", [$app['tap_u_ID']]);
                    // if ($stu['u_gmail']) send_gas_email($stu['u_gmail'], '專題申請退件通知', "同學 {$stu['u_name']} 您好，您的專題申請已被退件。\n原因：$remark");

                    json_ok(['message' => '已退件']);
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;

        // 查詢目前使用者的申請 (整合了 get_my_application 與唯讀檢查)
        case 'get_my_application':
            require_role(6);
            // 找自己提交的 或 自己是被邀請成員的
            $sql = "SELECT * FROM teamapply WHERE (tap_u_ID = ? OR tap_member LIKE ?) AND tap_status IN (1, 2, 3, 4) ORDER BY tap_update_d DESC LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$u_ID, "%\"$u_ID\"%"]);
            $app = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($app) {
                $cohort_ID = 0;
                $stmt = $conn->prepare("SELECT taf_cohort_ID FROM teamapplyform WHERE taf_ID = ? LIMIT 1");
                $stmt->execute([(int)($app['tap_taf_ID'] ?? 0)]);
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $cohort_ID = (int)$row['taf_cohort_ID'];

                $getClassLabel = function($uid) use ($conn, $cohort_ID) {
                    $class_label = '';
                    if ($cohort_ID && $uid) {
                        $stmt = $conn->prepare("SELECT e.enroll_grade, c.c_name FROM enrollmentdata e LEFT JOIN classdata c ON e.class_ID = c.c_ID WHERE e.enroll_u_ID = ? AND e.cohort_ID = ? AND e.enroll_status = 1 ORDER BY e.enroll_ID DESC LIMIT 1");
                        $stmt->execute([$uid, $cohort_ID]);
                        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $grade = trim($row['enroll_grade'] ?? '');
                            $cname = trim($row['c_name'] ?? '');
                            $class_label = '資' . $grade . $cname;
                        }
                    }
                    return $class_label;
                };

                $stmt = $conn->prepare("SELECT u_ID, u_name FROM userdata WHERE u_ID = ? LIMIT 1");
                $stmt->execute([$app['tap_u_ID']]);
                $app['applicant'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['u_ID' => $app['tap_u_ID'], 'u_name' => ''];
                $app['applicant']['class_label'] = $getClassLabel($app['tap_u_ID']);

                $m_ids = json_decode($app['tap_member'], true) ?: [];
                $app['members'] = [];
                if (!empty($m_ids)) {
                    foreach ($m_ids as $uid) {
                        $stmt = $conn->prepare("SELECT u_ID, u_name FROM userdata WHERE u_ID = ? LIMIT 1");
                        $stmt->execute([$uid]);
                        $m = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (!$m) continue;
                        $m['class_label'] = $getClassLabel($uid);
                        $app['members'][] = $m;
                    }
                }
                
                $t_id = $app['tap_teacher'];
                $app['teacher'] = $conn->query("SELECT u_ID, u_name FROM userdata WHERE u_ID = '$t_id'")->fetch(PDO::FETCH_ASSOC);
                
                // 解析 group 與 des（group_id 可能存於 tap_group 或 tap_des JSON）
                $des = json_decode($app['tap_des'] ?? '{}', true);
                $gid = $app['tap_group'] ?? $des['group_id'] ?? null;
                if ($gid) {
                    $gr = $conn->query("SELECT group_ID, group_name FROM groupdata WHERE group_ID = " . (int)$gid)->fetch(PDO::FETCH_ASSOC);
                    if ($gr) $app['group'] = $gr;
                }
                $app['tap_group'] = $gid; // 確保前端 fallback 可用（無 tap_group 欄位時從 tap_des 取得）
                
                // 處理舊版資料結構
                $app['tap_des'] = is_array($des) ? ($des['comment'] ?? '') : $app['tap_des'];
                $app['co_teacher_id'] = $des['co_teacher_id'] ?? null;
                $app['teacher_2_id'] = $des['teacher_2_id'] ?? null;
                $app['teacher_3_id'] = $des['teacher_3_id'] ?? null;
                if (!empty($app['teacher_2_id'])) {
                    $stmt = $conn->prepare("SELECT u_ID, u_name FROM userdata WHERE u_ID = ? LIMIT 1");
                    $stmt->execute([$app['teacher_2_id']]);
                    $app['teacher_2'] = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                if (!empty($app['teacher_3_id'])) {
                    $stmt = $conn->prepare("SELECT u_ID, u_name FROM userdata WHERE u_ID = ? LIMIT 1");
                    $stmt->execute([$app['teacher_3_id']]);
                    $app['teacher_3'] = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            }
            json_ok(['application' => $app]);
            break;
            
        // 獲取待審核列表 (科辦)
        case 'get_pending_applications':
            require_role([1, 2]);
            $sql = "SELECT ta.*, u.u_name as submitter_name FROM teamapply ta JOIN userdata u ON ta.tap_u_ID = u.u_ID WHERE ta.tap_status IN (1, 3) ORDER BY ta.tap_update_d DESC";
            $apps = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            // 批次補資料 (這裡稍微保留原邏輯但簡化)
            foreach ($apps as &$row) {
                $m_ids = json_decode($row['tap_member'], true) ?: [];
                $row['member_ids'] = $m_ids;
                // 這裡為了效能，前端可以用 member_ids 再去要名字，或是後端簡單處理
                if ($m_ids) {
                    $idsStr = implode(',', array_map([$conn, 'quote'], $m_ids));
                    $row['members'] = $conn->query("SELECT u_ID, u_name FROM userdata WHERE u_ID IN ($idsStr)")->fetchAll(PDO::FETCH_ASSOC);
                }
                
                // 類組與老師
                $des = json_decode($row['tap_des'] ?? '{}', true);
                $gid = $row['tap_group'] ?? $des['group_id'] ?? null;
                if ($gid) $row['group_name'] = $conn->query("SELECT group_name FROM groupdata WHERE group_ID = '$gid'")->fetchColumn();
                $row['teacher_name'] = $conn->query("SELECT u_name FROM userdata WHERE u_ID = '{$row['tap_teacher']}'")->fetchColumn();
                $row['user_comment'] = is_array($des) ? ($des['comment'] ?? '') : $row['tap_des'];
            }
            json_ok(['applications' => $apps]);
            break;

        // 其他功能 (老師團隊、未分組學生等) - 保持原樣但使用 Helper 簡化

        default:
             // 保留其他較小的 GET case，或是直接忽略
             break;
    }
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    json_err($e->getMessage());
}