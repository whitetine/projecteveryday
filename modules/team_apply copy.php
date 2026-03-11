<?php

/**
 * 專題申請表後端 API 模組
 */

global $conn;
$p = $_POST;
$do = $_GET['do'] ?? '';
$u_ID = $_SESSION['u_ID'] ?? null;

// 發送退件郵件通知
function sendRejectionEmail($to, $studentName, $projectName, $remark)
{
    // 使用 Google Apps Script API 發送郵件
    // $url="https://script.google.com/macros/s/AKfycbyLLkHxyGhJkllgpztDzcXPcp_IKXL_GS2lnOGDegOAQplqQMVU0EA4LF4ZPDrrkfyb/exec";
       $url="";
    $subject = '專題申請退件通知(不好意思這是測試郵件，你如果收到了，是全國優秀大專青年發的，不好意思!)';
    $message = "親愛的 {$studentName} 同學：\n\n";
    $message .= "您的專題申請「{$projectName}」已被退件。\n\n";
    if (!empty($remark)) {
        $message .= "退件原因：{$remark}\n\n";
    }
    $message .= "請重新檢查申請資料並重新提交。\n\n";
    $message .= "此為系統自動發送，請勿直接回覆。";

    $data = [
        'to' => $to,
        'subject' => $subject,
        'message' => $message
    ];

    $options = [
        "http" => [
            "method" => "POST",
            "header" => "Content-type: application/x-www-form-urlencoded",
            "content" => http_build_query($data),
            "timeout" => 10
        ]
    ];

    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
    // 不拋出錯誤，避免影響主要流程
}

// 發送一般通知郵件（共用 Google Apps Script）
function sendStudentEmailGeneric(string $to, string $subject, string $message): void
{
    // 同一組 GAS 服務端點
    $url = "https://script.google.com/macros/s/AKfycbyLLkHxyGhJkllgpztDzcXPcp_IKXL_GS2lnOGDegOAQplqQMVU0EA4LF4ZPDrrkfyb/exec";

    $data = [
        'to' => $to,
        'subject' => $subject,
        'message' => $message
    ];

    $options = [
        "http" => [
            "method" => "POST",
            "header" => "Content-type: application/x-www-form-urlencoded",
            "content" => http_build_query($data),
            "timeout" => 10
        ]
    ];

    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
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

// 檢查是否為科辦或主任 (role_ID=1, 2)
function checkOfficePermission()
{
    global $conn;
    $u_ID = $_SESSION['u_ID'] ?? null;
    if (!$u_ID) {
        json_err('請先登入', 'NOT_LOGGED_IN', 401);
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM userrolesdata 
        WHERE ur_u_ID = ? AND role_ID IN (1, 2) AND user_role_status = 1
    ");
    $stmt->execute([$u_ID]);
    if (!$stmt->fetchColumn()) {
        json_err('此功能僅限主任和科辦使用', 'NO_PERMISSION', 403);
    }
    return $u_ID;
}

switch ($do) {
    // 獲取指導老師列表
    case 'get_teachers':
        try {
            $sql = "
                SELECT DISTINCT u.u_ID, u.u_name
                FROM userdata u
                INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                WHERE ur.role_ID = 4 
                  AND ur.user_role_status = 1
                  AND u.u_status = 1
                ORDER BY u.u_name
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($teachers)) {
                json_err('目前沒有可用的指導老師');
            } else {
                json_ok(['teachers' => $teachers]);
            }
        } catch (Throwable $e) {
            json_err('獲取指導老師列表失敗：' . $e->getMessage());
        }
        break;

    // 根據學號查詢學生資訊
    case 'get_student_info':
        try {
            $student_id = trim($p['student_id'] ?? '');
            if (empty($student_id)) {
                json_err('請輸入學號');
            }

            $sql = "
                SELECT u.u_ID, u.u_name, u.u_status
                FROM userdata u
                INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                WHERE u.u_ID = ? 
                  AND ur.role_ID = 6 
                  AND ur.user_role_status = 1
                  AND u.u_status = 1
                LIMIT 1
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                json_err('找不到該學號的學生，或該學生狀態異常');
            }

            // 檢查該學生是否已有團隊
            $teamUserField = 'team_u_ID';
            $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $teamUserField = 'u_ID';
            }

            $sql = "
                SELECT COUNT(*) 
                FROM teammember tm
                INNER JOIN teamdata t ON tm.team_ID = t.team_ID
                WHERE tm.{$teamUserField} = ? AND t.team_status = 1
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$student_id]);
            $hasTeam = $stmt->fetchColumn() > 0;

            if ($hasTeam) {
                json_err('該學生已有團隊，無法重複加入');
            }

            // 檢查該學生是否有正在審核中的申請（作為申請者或被申請的成員）
            // 1. 檢查是否為申請者本人
            $stmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM teamapply
                WHERE tap_u_ID = ? AND tap_status = 1
            ");
            $stmt->execute([$student_id]);
            $isApplicant = $stmt->fetchColumn() > 0;

            // 2. 檢查是否在 tap_member 中（被申請的成員）
            $stmt = $conn->prepare("
                SELECT tap_ID, tap_member
                FROM teamapply
                WHERE tap_status = 1
            ");
            $stmt->execute();
            $pendingApplications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $isMember = false;
            foreach ($pendingApplications as $app) {
                $member_ids = json_decode($app['tap_member'] ?? '[]', true);
                if (is_array($member_ids) && in_array($student_id, $member_ids)) {
                    $isMember = true;
                    break;
                }
            }

            if ($isApplicant || $isMember) {
                json_err('該學生已有正在審核中的申請，無法重複加入其他組別');
            }

            json_ok(['student' => $student]);
        } catch (Throwable $e) {
            json_err('查詢學生資訊失敗：' . $e->getMessage());
        }
        break;

    // 提交專題申請
    case 'submit_application':
        try {
            $u_ID = checkStudentPermission();

            $teacher_id = trim($p['teacher_id'] ?? '');
            $co_teacher_id = trim($p['co_teacher_id'] ?? '');
            $group_id = trim($p['group_id'] ?? '');
            $project_name = trim($p['project_name'] ?? '');
            $comment = trim($p['comment'] ?? '');
            $member_ids = json_decode($p['member_ids'] ?? '[]', true);

            if (empty($teacher_id)) {
                json_err('請選擇指導老師');
            }
            if (empty($group_id)) {
                json_err('請選擇類組');
            }
            if (empty($project_name)) {
                json_err('請輸入專題名稱');
            }
            if (empty($member_ids) || !is_array($member_ids)) {
                json_err('請至少添加一個團隊成員');
            }

            // 驗證副指導老師（可選）
            if (!empty($co_teacher_id)) {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) 
                    FROM userdata u
                    JOIN userrolesdata ur ON ur.ur_u_ID = u.u_ID
                    WHERE u.u_ID = ? 
                      AND ur.role_ID = 4 
                      AND ur.user_role_status = 1
                      AND u.u_status = 1
                ");
                $stmt->execute([$co_teacher_id]);
                if ($stmt->fetchColumn() == 0) {
                    json_err('副指導老師不存在或未啟用');
                }
            }

            // 驗證類組是否存在且啟用
            $stmt = $conn->prepare("
                SELECT group_ID, group_name 
                FROM groupdata 
                WHERE group_ID = ? AND group_status = 1
            ");
            $stmt->execute([$group_id]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$group) {
                json_err('選擇的類組不存在或已停用');
            }

            // 檢查成員數量限制（最多3個學生，不包括申請人）
            $maxMembers = 3;
            $memberCount = count($member_ids);
            if ($memberCount > $maxMembers) {
                json_err("團隊成員數量超過限制，最多只能有 {$maxMembers} 個成員（不包括申請人）");
            }

            // 檢查申請者是否已在成員列表中
            if (!in_array($u_ID, $member_ids)) {
                $member_ids[] = $u_ID;
            }

            // 檢查所有成員是否都沒有團隊
            $teamUserField = 'team_u_ID';
            $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $teamUserField = 'u_ID';
            }

            foreach ($member_ids as $member_id) {
                // 檢查是否已有團隊
                $sql = "
                    SELECT COUNT(*) 
                    FROM teammember tm
                    INNER JOIN teamdata t ON tm.team_ID = t.team_ID
                    WHERE tm.{$teamUserField} = ? AND t.team_status = 1
                ";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$member_id]);
                if ($stmt->fetchColumn() > 0) {
                    json_err("成員 {$member_id} 已有團隊，無法重複申請");
                }

                // 檢查是否有正在審核中的申請（作為申請者）
                $stmt = $conn->prepare("
                    SELECT COUNT(*) 
                    FROM teamapply
                    WHERE tap_u_ID = ? AND tap_status = 1
                ");
                $stmt->execute([$member_id]);
                if ($stmt->fetchColumn() > 0) {
                    json_err("成員 {$member_id} 已有正在審核中的申請，無法重複加入其他組別");
                }

                // 檢查是否在 tap_member 中（被申請的成員）
                $stmt = $conn->prepare("
                    SELECT tap_ID, tap_member
                    FROM teamapply
                    WHERE tap_status = 1
                ");
                $stmt->execute();
                $pendingApplications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($pendingApplications as $app) {
                    $app_member_ids = json_decode($app['tap_member'] ?? '[]', true);
                    if (is_array($app_member_ids) && in_array($member_id, $app_member_ids)) {
                        json_err("成員 {$member_id} 已有正在審核中的申請，無法重複加入其他組別");
                    }
                }
            }

            // 處理圖片上傳
            $imageUrl = null;
            if (!empty($_FILES['apply_image']['name']) && $_FILES['apply_image']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['apply_image'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    json_err('圖片格式只接受 JPG、PNG、WebP');
                }

                // 驗證 MIME 類型
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
                if (!in_array($mime, $allowedMime)) {
                    json_err('檔案格式不正確');
                }

                // 建立上傳資料夾
                $uploadDir = dirname(__DIR__) . '/uploads/team_apply/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }

                // 生成檔名
                $safeUid = preg_replace('/[^A-Za-z0-9_\-]/', '', $u_ID);
                $newName = 'apply_' . $safeUid . '_' . time() . '.' . $ext;
                $destPath = $uploadDir . $newName;

                if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                    json_err('圖片上傳失敗');
                }

                $imageUrl = 'uploads/team_apply/' . $newName;
            } else {
                json_err('請上傳專題申請表照片');
            }

            // 將成員列表存入 JSON 格式
            $memberJson = json_encode($member_ids, JSON_UNESCAPED_UNICODE);

            // 插入申請記錄到 teamapply
            $conn->beginTransaction();

            // 檢查資料表是否有 tap_group 欄位
            $hasGroupField = false;
            try {
                $stmt = $conn->query("SHOW COLUMNS FROM teamapply LIKE 'tap_group'");
                $hasGroupField = $stmt->fetch() !== false;
            } catch (Exception $e) {
                // 忽略錯誤
            }

            // 統一處理 tap_des 內容（包含備註與副指導老師）
            $tapDesPayload = [];
            if ($comment !== '') {
                $tapDesPayload['comment'] = $comment;
            }
            if (!empty($co_teacher_id)) {
                $tapDesPayload['co_teacher_id'] = $co_teacher_id;
            }

            if ($hasGroupField) {
                $tapDesValue = $tapDesPayload
                    ? json_encode($tapDesPayload, JSON_UNESCAPED_UNICODE)
                    : $comment;

                $stmt = $conn->prepare("
                    INSERT INTO teamapply (
                        tap_name, tap_member, tap_teacher, tap_group, tap_url, tap_des, 
                        tap_status, tap_u_ID, tap_update_d
                    ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW())
                ");
                $stmt->execute([
                    $project_name,
                    $memberJson,
                    $teacher_id,
                    $group_id,
                    $imageUrl,
                    $tapDesValue,
                    $u_ID
                ]);
            } else {
                // 如果沒有 tap_group 欄位，將類組 ID 存在 tap_des 的 JSON 中
                $tapDesPayload['group_id'] = $group_id;
                $tapDesValue = json_encode($tapDesPayload, JSON_UNESCAPED_UNICODE);

                $stmt = $conn->prepare("
                    INSERT INTO teamapply (
                        tap_name, tap_member, tap_teacher, tap_url, tap_des, 
                        tap_status, tap_u_ID, tap_update_d
                    ) VALUES (?, ?, ?, ?, ?, 0, ?, NOW())
                ");
                $stmt->execute([
                    $project_name,
                    $memberJson,
                    $teacher_id,
                    $imageUrl,
                    $tapDesValue,
                    $u_ID
                ]);
            }
            $tap_ID = $conn->lastInsertId();

            // 提交後狀態設為 1（待審核）
            $stmt = $conn->prepare("UPDATE teamapply SET tap_status = 1 WHERE tap_ID = ?");
            $stmt->execute([$tap_ID]);

            // 創建通知給科辦（role_ID=2）
            $stmt = $conn->prepare("
                SELECT ur_u_ID 
                FROM userrolesdata 
                WHERE role_ID = 2 AND user_role_status = 1
            ");
            $stmt->execute();
            $officeUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($officeUsers)) {
                // 創建通知
                $stmt = $conn->prepare("
                    INSERT INTO msgdata (
                        msg_title, msg_content, msg_type, msg_a_u_ID, 
                        msg_status, msg_start_d, msg_created_d
                    ) VALUES (
                        '專題申請通知', 
                        CONCAT('學生 ', (SELECT u_name FROM userdata WHERE u_ID = ?), ' 提交了專題申請表，請前往審核。'),
                        'SYSTEM_NOTICE',
                        'system',
                        1,
                        NOW(),
                        NOW()
                    )
                ");
                $stmt->execute([$u_ID]);
                $msg_ID = $conn->lastInsertId();

                // 為每個科辦用戶創建通知目標
                foreach ($officeUsers as $officeUID) {
                    $stmt = $conn->prepare("
                        INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID)
                        VALUES (?, 'USER', ?)
                    ");
                    $stmt->execute([$msg_ID, $officeUID]);
                }
            }

            $conn->commit();

            json_ok(['message' => '申請已提交，請等待科辦審核', 'tap_ID' => $tap_ID]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('提交申請失敗：' . $e->getMessage());
        }
        break;

    // 獲取待審核申請列表（科辦）
    case 'get_pending_applications':
        try {
            checkOfficePermission();
            
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            
            $sql = "
                SELECT 
                    ta.tap_ID,
                    ta.tap_name,
                    ta.tap_member,
                    ta.tap_teacher,
                    ta.tap_url,
                    ta.tap_des,
                    ta.tap_status,
                    ta.tap_u_ID,
                    ta.tap_update_d,
                    ta.tap_remark,
                    ta.tap_rp_d,
                    ta.tap_rp_u_ID,
                    u.u_name as submitter_name
                FROM teamapply ta
                INNER JOIN userdata u ON ta.tap_u_ID = u.u_ID
                WHERE ta.tap_status IN (1, 3)
            ";
            
            $params = [];
            
            // 如果指定了屆別，需要根據成員的屆別來篩選
            if ($cohort_ID > 0) {
                $sql .= " AND EXISTS (
                    SELECT 1 
                    FROM userdata u2
                    INNER JOIN enrollmentdata e ON e.enroll_u_ID = u2.u_ID
                    WHERE JSON_CONTAINS(ta.tap_member, JSON_QUOTE(u2.u_ID))
                      AND e.cohort_ID = ?
                      AND e.enroll_status = 1
                )";
                $params[] = $cohort_ID;
            }
            // 如果沒有指定屆別，顯示所有屆別的申請（包括已結束的）
            // 不需要額外的篩選條件
            
            // 按時間降序排列（最新的在前）
            $sql .= " ORDER BY ta.tap_update_d DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 解析每個申請的 JSON 資料
            foreach ($applications as &$app) {
                // 解析成員列表 JSON
                $member_ids = json_decode($app['tap_member'], true);
                if (!is_array($member_ids)) {
                    $member_ids = [];
                }
                $app['member_ids'] = $member_ids;
                $app['project_name'] = $app['tap_name'];
                $app['teacher_id'] = $app['tap_teacher'];
                $app['user_comment'] = $app['tap_des'] ?? '';
                $app['dcsub_url'] = $app['tap_url'] ?? '';
                $app['dcsub_u_ID'] = $app['tap_u_ID'];
                $app['dcsub_sub_d'] = $app['tap_update_d'];
                $app['sub_ID'] = $app['tap_ID']; // 為了兼容前端
                $app['review_remark'] = $app['tap_remark'];

                // 獲取成員姓名
                if (!empty($member_ids)) {
                    $placeholders = str_repeat('?,', count($member_ids) - 1) . '?';
                    $sql = "SELECT u_ID, u_name FROM userdata WHERE u_ID IN ($placeholders)";
                    $stmt2 = $conn->prepare($sql);
                    $stmt2->execute($member_ids);
                    $members = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                    $app['members'] = $members;
                    
                    // 檢查 teammember 表的欄位名稱（兼容不同版本）
                    $teamMemberField = 'team_u_ID';
                    try {
                        $stmt_check = $conn->prepare("SHOW COLUMNS FROM teammember LIKE ?");
                        $stmt_check->execute(['team_u_ID']);
                        if ($stmt_check->rowCount() === 0) {
                            // 如果沒有 team_u_ID，嘗試使用 u_ID
                            $stmt_check->execute(['u_ID']);
                            if ($stmt_check->rowCount() > 0) {
                                $teamMemberField = 'u_ID';
                            }
                        }
                    } catch (Exception $e) {
                        // 預設使用 team_u_ID
                        $teamMemberField = 'team_u_ID';
                    }
                    
                    // 查詢已通過的成員數量（在 teammember 表中的成員）
                    $sql_approved = "SELECT COUNT(DISTINCT tm.{$teamMemberField}) as approved_count
                                     FROM teammember tm
                                     WHERE tm.{$teamMemberField} IN ($placeholders)";
                    $stmt3 = $conn->prepare($sql_approved);
                    $stmt3->execute($member_ids);
                    $approved_result = $stmt3->fetch(PDO::FETCH_ASSOC);
                    $app['approved_count'] = (int)($approved_result['approved_count'] ?? 0);
                    $app['total_count'] = count($member_ids);
                } else {
                    $app['members'] = [];
                    $app['approved_count'] = 0;
                    $app['total_count'] = 0;
                }

                // 獲取指導老師姓名
                if (!empty($app['teacher_id'])) {
                    $stmt2 = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                    $stmt2->execute([$app['teacher_id']]);
                    $teacher = $stmt2->fetch(PDO::FETCH_ASSOC);
                    $app['teacher_name'] = $teacher['u_name'] ?? $app['teacher_id'];
                }
                
                // 判斷申請是否屬於已結束的屆別（唯讀狀態）
                $app['is_readonly'] = false;
                if (!empty($member_ids)) {
                    // 檢查成員所屬的屆別狀態
                    $placeholders_cohort = str_repeat('?,', count($member_ids) - 1) . '?';
                    $sql_cohort = "
                        SELECT DISTINCT c.cohort_status
                        FROM enrollmentdata e
                        INNER JOIN cohortdata c ON c.cohort_ID = e.cohort_ID
                        WHERE e.enroll_u_ID IN ($placeholders_cohort)
                          AND e.enroll_status = 1
                    ";
                    $stmt_cohort = $conn->prepare($sql_cohort);
                    $stmt_cohort->execute($member_ids);
                    $cohort_statuses = $stmt_cohort->fetchAll(PDO::FETCH_COLUMN);
                    
                    // 如果所有成員都屬於已結束的屆別（cohort_status = 0），則設為唯讀
                    if (!empty($cohort_statuses) && !in_array(1, $cohort_statuses)) {
                        $app['is_readonly'] = true;
                    }
                }

                // 獲取類組資訊
                $group_ID = null;

                // 檢查是否有 tap_group 欄位
                $hasGroupField = false;
                try {
                    $stmt2 = $conn->query("SHOW COLUMNS FROM teamapply LIKE 'tap_group'");
                    $hasGroupField = $stmt2->fetch() !== false;
                } catch (Exception $e) {
                    // 忽略錯誤
                }

                if ($hasGroupField && !empty($app['tap_group'])) {
                    $group_ID = $app['tap_group'];
                } else {
                    // 從 tap_des 的 JSON 中取得類組 ID
                    $desData = json_decode($app['tap_des'] ?? '{}', true);
                    if (is_array($desData) && isset($desData['group_id'])) {
                        $group_ID = $desData['group_id'];
                    }
                }

                if ($group_ID) {
                    $stmt2 = $conn->prepare("SELECT group_ID, group_name FROM groupdata WHERE group_ID = ?");
                    $stmt2->execute([$group_ID]);
                    $group = $stmt2->fetch(PDO::FETCH_ASSOC);
                    if ($group) {
                        $app['group_name'] = $group['group_name'];
                        $app['group_ID'] = $group['group_ID'];
                    }
                }

                // 處理說明文字（如果 tap_des 是 JSON，提取 comment）
                $comment = $app['tap_des'] ?? '';
                $desData = json_decode($comment, true);
                if (is_array($desData) && isset($desData['comment'])) {
                    $app['user_comment'] = $desData['comment'];
                }
                if (is_array($desData) && !empty($desData['co_teacher_id'])) {
                    $app['co_teacher_id'] = $desData['co_teacher_id'];
                    $stmt2 = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                    $stmt2->execute([$desData['co_teacher_id']]);
                    $coTeacher = $stmt2->fetch(PDO::FETCH_ASSOC);
                    if ($coTeacher) {
                        $app['co_teacher_name'] = $coTeacher['u_name'];
                    }
                }
            }

            json_ok(['applications' => $applications]);
        } catch (Throwable $e) {
            json_err('獲取申請列表失敗：' . $e->getMessage());
        }
        break;

    // 審核申請（通過/退件）
    case 'review_application':
        try {
            $reviewer_ID = checkOfficePermission();

            // 兼容 tap_ID / sub_ID
            $tap_ID = isset($p['tap_ID']) ? (int)$p['tap_ID'] : (isset($p['sub_ID']) ? (int)$p['sub_ID'] : 0);
            $action = trim($p['action'] ?? '');            // approve / reject / save_remark
            $remark = trim($p['remark'] ?? '');

            if ($tap_ID <= 0) {
                json_err('申請ID無效');
            }
            if (!in_array($action, ['approve', 'reject', 'save_remark'], true)) {
                json_err('操作無效');
            }

            // 讀取申請資料
            $stmt = $conn->prepare("SELECT * FROM teamapply WHERE tap_ID = ?");
            $stmt->execute([$tap_ID]);
            $application = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$application) {
                json_err('找不到該申請');
            }

            /**
             * ① 單純「儲存審核備註」
             *    只更新 tap_remark，不改 tap_status
             */
            if ($action === 'save_remark') {
                $stmt = $conn->prepare("
                UPDATE teamapply
                   SET tap_remark   = :remark,
                       tap_rp_u_ID  = :reviewer_ID,
                       tap_update_d = NOW()
                 WHERE tap_ID      = :tap_ID
            ");
                $stmt->execute([
                    ':remark'      => $remark,
                    ':reviewer_ID' => $reviewer_ID,
                    ':tap_ID'      => $tap_ID,
                ]);

                json_ok(['msg' => '審核備註已儲存']);
            }

            /**
             * ② 以下才是原本通過 / 退件流程
             *    只有「待審核（tap_status = 1）」才能改狀態
             */
            if ((int)$application['tap_status'] !== 1) {
                json_err('該申請已被處理');
            }

            // 解析成員、基本資料
            $member_ids = json_decode($application['tap_member'], true);
            if (!is_array($member_ids)) {
                $member_ids = [];
            }

            $teacher_id   = $application['tap_teacher'];
            $project_name = $application['tap_name'];
            $submitter_ID = $application['tap_u_ID'];
            $imageUrl     = $application['tap_url'];

            $conn->beginTransaction();

            if ($action === 'approve') {
                // ===== 通過：建立團隊 =====
                if (empty($teacher_id) || empty($member_ids) || empty($project_name)) {
                    throw new Exception('申請資料不完整');
                }

                // 取得當前啟用屆別
                $stmt = $conn->prepare("
                SELECT cohort_ID 
                  FROM cohortdata 
                 WHERE cohort_status = 1 
              ORDER BY cohort_ID DESC 
                 LIMIT 1
            ");
                $stmt->execute();
                $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
                $cohort_ID = $cohort['cohort_ID'] ?? null;

                // 取得類組
                $group_ID = null;

                // 先看有沒有 tap_group
                $hasGroupField = false;
                try {
                    $stmt = $conn->query("SHOW COLUMNS FROM teamapply LIKE 'tap_group'");
                    $hasGroupField = $stmt->fetch() !== false;
                } catch (Exception $e) {
                    // ignore
                }

                if ($hasGroupField && !empty($application['tap_group'])) {
                    $group_ID = $application['tap_group'];
                } else {
                    // 從 tap_des JSON 抓 group_id
                    $desData = json_decode($application['tap_des'] ?? '{}', true);
                    if (is_array($desData) && isset($desData['group_id'])) {
                        $group_ID = $desData['group_id'];
                    }
                }

                // 還是沒有就抓第一個啟用類組當預設
                if (!$group_ID) {
                    $stmt = $conn->prepare("
                    SELECT group_ID 
                      FROM groupdata 
                     WHERE group_status = 1 
                  ORDER BY group_ID 
                     LIMIT 1
                ");
                    $stmt->execute();
                    $group = $stmt->fetch(PDO::FETCH_ASSOC);
                    $group_ID = $group['group_ID'] ?? null;
                }

                if (!$group_ID) {
                    throw new Exception('沒有可用的類組');
                }

                // 建立 teamdata
                $stmt = $conn->prepare("
                INSERT INTO teamdata (
                    group_ID, team_project_name, cohort_ID, 
                    team_status, team_update_d, team_url
                ) VALUES (?, ?, ?, 1, NOW(), ?)
            ");
                $stmt->execute([$group_ID, $project_name, $cohort_ID, $imageUrl]);
                $team_ID = $conn->lastInsertId();

                // teammember 欄位名稱兼容
                $teamUserField = 'team_u_ID';
                $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $stmt->execute();
                if (!$stmt->fetch()) {
                    $teamUserField = 'u_ID';
                }

                // 加入學生成員
                foreach ($member_ids as $member_id) {
                    $stmt = $conn->prepare("
                    INSERT INTO teammember (team_ID, {$teamUserField}, tm_status, tm_updated_d, tm_url)
                    VALUES (?, ?, 1, NOW(), ?)
                ");
                    $stmt->execute([$team_ID, $member_id, $imageUrl]);
                }

                // 加入指導老師
                $stmt = $conn->prepare("
                INSERT INTO teammember (team_ID, {$teamUserField}, tm_status, tm_updated_d, tm_url)
                VALUES (?, ?, 1, NOW(), ?)
            ");
                $stmt->execute([$team_ID, $teacher_id, $imageUrl]);

                // 更新申請狀態為「通過」
$stmt = $conn->prepare("
    UPDATE teamapply 
       SET tap_status   = 3,
           tap_rp_u_ID  = ?,
           tap_rp_d     = NOW(),
           tap_remark   = ?,   -- ✅ 通過時也順便寫入備註
           tap_update_d = NOW()
     WHERE tap_ID       = ?
");
$stmt->execute([$reviewer_ID, $remark, $tap_ID]);

                // 發通知給成員＋老師
                $allMembers = array_merge($member_ids, [$teacher_id]);
                $stmt = $conn->prepare("
                INSERT INTO msgdata (
                    msg_title, msg_content, msg_type, msg_a_u_ID, 
                    msg_status, msg_start_d, msg_created_d
                ) VALUES (
                    '專題申請通過通知', 
                    CONCAT('您的專題申請「', ?, '」已通過審核，團隊已成功建立。'),
                    'SYSTEM_NOTICE',
                    'system',
                    1,
                    NOW(),
                    NOW()
                )
            ");
                $stmt->execute([$project_name]);
                $msg_ID = $conn->lastInsertId();

                foreach ($allMembers as $memberUID) {
                    $stmt = $conn->prepare("
                    INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID)
                    VALUES (?, 'USER', ?)
                ");
                    $stmt->execute([$msg_ID, $memberUID]);
                }

                // 找第一個流程對應表單（如果有）
            //     $form_ID = null;
            //     $stmt = $conn->prepare("
            //     SELECT ff.form_ID 
            //       FROM formflowdata ff
            //       JOIN formdata f ON ff.form_ID = f.form_ID
            //      WHERE ff.ff_order   = 1 
            //        AND ff.ff_enabled = 1 
            //        AND f.form_status = 1
            //   ORDER BY ff.ff_order ASC
            //      LIMIT 1
            // ");
            //     $stmt->execute();
            //     $form = $stmt->fetch(PDO::FETCH_ASSOC);
            //     if ($form) {
            //         $form_ID = $form['form_ID'];
            //     }
            } else {
                // ===== 退件 =====
               $newDes = $application['tap_des'] ?? '';

                $stmt = $conn->prepare("
                    UPDATE teamapply 
                       SET tap_status   = 2,
                           tap_rp_u_ID  = ?,
                           tap_rp_d     = NOW(),
                           tap_des      = ?,          -- 保留學生原本備註（有需要可一起改）
                           tap_remark   = ?,          -- ✅ 審核退件原因
                           tap_update_d = NOW()
                     WHERE tap_ID      = ?
                ");
                $stmt->execute([
                    $reviewer_ID,
                    $newDes,
                    $remark,
                    $tap_ID
                ]);

                // 取提交者資料（含 email）
                $submitter_ID = $application['tap_u_ID'];
                $stmt = $conn->prepare("SELECT u_name, u_gmail FROM userdata WHERE u_ID = ?");
                $stmt->execute([$submitter_ID]);
                $submitter = $stmt->fetch(PDO::FETCH_ASSOC);

                // 發 Gmail（若有）
                if ($submitter && !empty($submitter['u_gmail'])) {
                    sendRejectionEmail($submitter['u_gmail'], $submitter['u_name'], $project_name, $remark);
                }

                // 系統通知
                $stmt = $conn->prepare("
                    INSERT INTO msgdata (
                        msg_title, msg_content, msg_type, msg_a_u_ID, 
                        msg_status, msg_start_d, msg_created_d
                    ) VALUES (
                        '專題申請退件通知', 
                        CONCAT('您的專題申請已被退件。', IF(? != '', CONCAT('退件原因：', ?), '')),
                        'SYSTEM_NOTICE',
                        'system',
                        1,
                        NOW(),
                        NOW()
                    )
                ");
                $stmt->execute([$remark, $remark]);
                $msg_ID = $conn->lastInsertId();

                $stmt = $conn->prepare("
                    INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID)
                    VALUES (?, 'USER', ?)
                ");
                $stmt->execute([$msg_ID, $submitter_ID]);
            }

            $conn->commit();

            $responseData = [
                'message' => $action === 'approve' ? '申請已通過，團隊已建立' : '申請已退件'
            ];

            // if ($action === 'approve') {
            //     $responseData['team_ID'] = $team_ID;
            //     if (!empty($form_ID)) {
            //         $responseData['form_ID'] = $form_ID;
            //     }
            // }

            json_ok($responseData);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('審核失敗：' . $e->getMessage());
        }
        break;


    case 'get_my_application':
        try {
            $u_ID = checkStudentPermission();

            // 查詢該學生的申請記錄（按時間倒序，取最新的）
            // 包括：申請者本人 或 被申請的成員
            $stmt = $conn->prepare("
                SELECT 
                    tap_ID,
                    tap_name,
                    tap_member,
                    tap_teacher,
                    tap_url,
                    tap_des,
                    tap_status,
                    tap_update_d,
                    tap_rp_u_ID,
                    tap_rp_d,
                    tap_remark,
                    tap_u_ID
                FROM teamapply
                WHERE tap_u_ID = ? AND tap_status IN (1, 2)
                ORDER BY tap_update_d DESC
                LIMIT 1
            ");
            $stmt->execute([$u_ID]);
            $application = $stmt->fetch(PDO::FETCH_ASSOC);

            // 如果沒有找到，檢查是否在 tap_member 中（被申請的成員）
            if (!$application) {
                $stmt = $conn->prepare("
                    SELECT 
                        tap_ID,
                        tap_name,
                        tap_member,
                        tap_teacher,
                        tap_url,
                        tap_des,
                        tap_status,
                        tap_update_d,
                        tap_rp_u_ID,
                        tap_rp_d,
                        tap_remark,
                        tap_u_ID
                    FROM teamapply
                    WHERE tap_status IN (1, 2)
                    ORDER BY tap_update_d DESC
                ");
                $stmt->execute();
                $allApplications = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($allApplications as $app) {
                    $member_ids = json_decode($app['tap_member'] ?? '[]', true);
                    if (is_array($member_ids) && in_array($u_ID, $member_ids)) {
                        $application = $app;
                        break;
                    }
                }
            }

            if (!$application) {
                json_ok(['application' => null]);
            }

            // 解析成員列表
            $member_ids = json_decode($application['tap_member'] ?? '[]', true);
            $members = [];
            if (is_array($member_ids)) {
                $placeholders = str_repeat('?,', count($member_ids) - 1) . '?';
                $stmt = $conn->prepare("SELECT u_ID, u_name FROM userdata WHERE u_ID IN ($placeholders)");
                $stmt->execute($member_ids);
                $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // 獲取指導老師資訊
            $teacher = null;
            if (!empty($application['tap_teacher'])) {
                $stmt = $conn->prepare("SELECT u_ID, u_name FROM userdata WHERE u_ID = ?");
                $stmt->execute([$application['tap_teacher']]);
                $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            // 副指導老師（存放於 tap_des JSON）
            $coTeacherId = null;
            $coTeacher = null;

            // 獲取類組資訊
            $group = null;
            $group_ID = null;

            // 檢查是否有 tap_group 欄位
            $hasGroupField = false;
            try {
                $stmt = $conn->query("SHOW COLUMNS FROM teamapply LIKE 'tap_group'");
                $hasGroupField = $stmt->fetch() !== false;
            } catch (Exception $e) {
                // 忽略錯誤
            }

            if ($hasGroupField && !empty($application['tap_group'])) {
                $group_ID = $application['tap_group'];
            } else {
                // 從 tap_des 的 JSON 中取得類組 ID
                $desData = json_decode($application['tap_des'] ?? '{}', true);
                if (is_array($desData) && isset($desData['group_id'])) {
                    $group_ID = $desData['group_id'];
                }
            }

            if ($group_ID) {
                $stmt = $conn->prepare("SELECT group_ID, group_name FROM groupdata WHERE group_ID = ?");
                $stmt->execute([$group_ID]);
                $group = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // 獲取審核人資訊
            $reviewer = null;
            if (!empty($application['tap_rp_u_ID'])) {
                $stmt = $conn->prepare("SELECT u_ID, u_name FROM userdata WHERE u_ID = ?");
                $stmt->execute([$application['tap_rp_u_ID']]);
                $reviewer = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // 處理說明文字（如果 tap_des 是 JSON，提取 comment）
            $comment = $application['tap_des'] ?? '';
            $desData = json_decode($comment, true);
            if (is_array($desData)) {
                if (isset($desData['comment'])) {
                    $comment = $desData['comment'];
                }
                if (!empty($desData['co_teacher_id'])) {
                    $coTeacherId = $desData['co_teacher_id'];
                }
            }

            if (!empty($coTeacherId)) {
                $stmt = $conn->prepare("SELECT u_ID, u_name FROM userdata WHERE u_ID = ?");
                $stmt->execute([$coTeacherId]);
                $coTeacher = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            json_ok([
                'application' => [
                    'tap_ID' => $application['tap_ID'],
                    'tap_name' => $application['tap_name'],
                    'tap_url' => $application['tap_url'],
                    'tap_des' => $comment,
                    'tap_remark'    => $application['tap_remark'] ?? '',
                    'tap_status' => (int)$application['tap_status'],
                    'tap_update_d' => $application['tap_update_d'],
                    'tap_rp_d' => $application['tap_rp_d'],
                    'members' => $members,
                    'teacher' => $teacher,
                    'co_teacher' => $coTeacher,
                    'co_teacher_id' => $coTeacherId,
                    'group' => $group,
                    'reviewer' => $reviewer
                ]
            ]);
        } catch (Throwable $e) {
            json_err('查詢申請資料失敗：' . $e->getMessage());
        }
        break;

    // 獲取指導老師帶的團隊列表
    case 'get_teacher_teams':
        try {
            checkOfficePermission();
            
            $teacher_id = trim($p['teacher_id'] ?? $_GET['teacher_id'] ?? '');
            if (empty($teacher_id)) {
                json_err('請提供指導老師ID');
            }

            // 檢查欄位名稱
            $colsTm = $conn->query("SHOW COLUMNS FROM teammember")->fetchAll(PDO::FETCH_COLUMN);
            $teamUserField = in_array('team_u_ID', $colsTm) ? 'team_u_ID' : 'u_ID';

            // 查詢該指導老師帶的所有團隊
            // 先確認該用戶是指導老師（role_ID = 4）
            $checkTeacher = $conn->prepare("
                SELECT COUNT(*) 
                FROM userrolesdata 
                WHERE ur_u_ID = ? AND role_ID = 4 AND user_role_status = 1
            ");
            $checkTeacher->execute([$teacher_id]);
            if ($checkTeacher->fetchColumn() == 0) {
                json_err('該用戶不是指導老師');
            }

            // 查詢該指導老師帶的所有正在進行中的團隊（不限屆別）
            $sql = "
                SELECT DISTINCT
                    t.team_ID,
                    t.team_project_name,
                    t.group_ID,
                    t.cohort_ID,
                    t.team_status,
                    t.team_update_d,
                    g.group_name,
                    c.cohort_name
                FROM teammember tm
                JOIN teamdata t ON t.team_ID = tm.team_ID
                LEFT JOIN groupdata g ON g.group_ID = t.group_ID
                LEFT JOIN cohortdata c ON c.cohort_ID = t.cohort_ID
                WHERE tm.{$teamUserField} = ?
                  AND tm.tm_status = 1
                  AND t.team_status = 1
                ORDER BY t.cohort_ID DESC, t.team_update_d DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$teacher_id]);
            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 記錄查詢結果用於調試
            error_log("查詢指導老師 {$teacher_id} 的團隊，找到 " . count($teams) . " 個團隊");

            // 為每個團隊獲取成員資訊
            foreach ($teams as &$team) {
                $teamId = $team['team_ID'];
                // 查詢團隊成員
                $sqlMembers = "
                    SELECT DISTINCT
                        u.u_ID,
                        u.u_name
                    FROM teammember tm
                    JOIN userdata u ON u.u_ID = tm.{$teamUserField}
                    WHERE tm.team_ID = ?
                      AND tm.tm_status = 1
                    ORDER BY u.u_name
                ";
                $stmtMembers = $conn->prepare($sqlMembers);
                $stmtMembers->execute([$teamId]);
                $allMembers = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);
                
                // 分離學生和指導老師
                $team['students'] = [];
                $team['teachers'] = [];
                foreach ($allMembers as $member) {
                    // 查詢該成員的角色
                    $roleStmt = $conn->prepare("
                        SELECT role_ID 
                        FROM userrolesdata 
                        WHERE ur_u_ID = ? AND user_role_status = 1
                        ORDER BY role_ID
                    ");
                    $roleStmt->execute([$member['u_ID']]);
                    $roles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $memberInfo = [
                        'u_ID' => $member['u_ID'],
                        'u_name' => $member['u_name']
                    ];
                    
                    // 如果是指導老師（role_ID = 4）
                    if (in_array(4, $roles)) {
                        $team['teachers'][] = $memberInfo;
                    }
                    // 如果是學生（role_ID = 6）
                    if (in_array(6, $roles)) {
                        $team['students'][] = $memberInfo;
                    }
                }
            }

            // 獲取指導老師姓名
            $stmt = $conn->prepare("SELECT u_ID, u_name FROM userdata WHERE u_ID = ?");
            $stmt->execute([$teacher_id]);
            $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

            json_ok([
                'teacher' => $teacher,
                'teams' => $teams
            ]);
        } catch (Throwable $e) {
            json_err('查詢指導老師團隊失敗：' . $e->getMessage());
        }
        break;

    // 獲取正在進行中的屆別
    case 'get_active_cohorts':
        try {
            $sql = "
                SELECT cohort_ID, cohort_name, year_label
                FROM cohortdata
                WHERE cohort_status = 1
                ORDER BY cohort_ID DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_ok(['cohorts' => $cohorts]);
        } catch (Exception $e) {
            json_err('取得屆別列表失敗：' . $e->getMessage());
        }
        break;

    // 獲取指定屆別中role為6的學生數量
    case 'get_student_count_by_cohort':
        try {
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            
            if ($cohort_ID > 0) {
                // 查詢指定屆別中role為6的學生數量
                $sql = "
                    SELECT COUNT(DISTINCT u.u_ID) as count
                    FROM userdata u
                    INNER JOIN userrolesdata ur ON ur.ur_u_ID = u.u_ID
                    INNER JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID
                    WHERE ur.role_ID = 6
                      AND ur.user_role_status = 1
                      AND e.cohort_ID = ?
                      AND e.enroll_status = 1
                ";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$cohort_ID]);
            } else {
                // 查詢所有屆別中role為6的學生數量（包括已結束的）
                $sql = "
                    SELECT COUNT(DISTINCT u.u_ID) as count
                    FROM userdata u
                    INNER JOIN userrolesdata ur ON ur.ur_u_ID = u.u_ID
                    INNER JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID
                    WHERE ur.role_ID = 6
                      AND ur.user_role_status = 1
                      AND e.enroll_status = 1
                ";
                $stmt = $conn->prepare($sql);
                $stmt->execute();
            }
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($result['count'] ?? 0);
            
            json_ok(['count' => $count]);
        } catch (Exception $e) {
            json_err('取得學生數量失敗：' . $e->getMessage());
        }
        break;

    // 獲取已通過的團隊數量（根據屆別）
    case 'get_approved_teams_count_by_cohort':
        try {
            // 檢查欄位名稱（兼容不同版本的資料表結構）
            $teamUserField = 'team_u_ID';
            try {
                $stmt_check = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $stmt_check->execute();
                if ($stmt_check->rowCount() === 0) {
                    $teamUserField = 'u_ID';
                }
            } catch (Exception $e) {
                $teamUserField = 'team_u_ID';
            }
            
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            
            // 計算已通過的團隊數量（team_status = 1）
            // 與 team_manage.php 的算法一致：查詢 teamdata 表中 team_status = 1 的團隊
            $sql = "
                SELECT COUNT(DISTINCT t.team_ID) as count
                FROM teamdata t
                WHERE t.team_status = 1
            ";
            
            $params = [];
            if ($cohort_ID > 0) {
                $sql .= " AND t.cohort_ID = ?";
                $params[] = $cohort_ID;
            }
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($result['count'] ?? 0);
            
            json_ok(['count' => $count]);
        } catch (Exception $e) {
            json_err('取得已通過團隊數量失敗：' . $e->getMessage());
        }
        break;

    // 獲取未加入組別的學生數量
    case 'get_no_team_students_count':
        try {
            // 檢查欄位名稱
            $teamUserField = 'team_u_ID';
            try {
                $stmt_check = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $stmt_check->execute();
                if ($stmt_check->rowCount() === 0) {
                    $teamUserField = 'u_ID';
                }
            } catch (Exception $e) {
                $teamUserField = 'team_u_ID';
            }
            
            $userRoleUidField = 'ur_u_ID';
            try {
                $stmt_check = $conn->prepare("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'");
                $stmt_check->execute();
                if ($stmt_check->rowCount() === 0) {
                    $userRoleUidField = 'u_ID';
                }
            } catch (Exception $e) {
                $userRoleUidField = 'ur_u_ID';
            }
            
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            
            $sql = "
                SELECT COUNT(DISTINCT u.u_ID) as count
                FROM userdata u
                INNER JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID 
                    AND e.enroll_status = 1
                INNER JOIN userrolesdata ur ON ur.{$userRoleUidField} = u.u_ID 
                    AND ur.role_ID = 6 
                    AND ur.user_role_status = 1
                LEFT JOIN teammember tm ON tm.{$teamUserField} = u.u_ID 
                    AND tm.tm_status = 1
                WHERE tm.team_ID IS NULL
            ";
            
            $params = [];
            if ($cohort_ID > 0) {
                $sql .= " AND e.cohort_ID = ?";
                $params[] = $cohort_ID;
            }
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($result['count'] ?? 0);
            
            json_ok(['count' => $count]);
        } catch (Exception $e) {
            json_err('取得未加入組別學生數量失敗：' . $e->getMessage());
        }
        break;

    // 獲取未加入組別的學生列表
    case 'get_no_team_students':
        try {
            // 檢查欄位名稱
            // 檢查欄位名稱
            $teamUserField = 'team_u_ID';
            try {
                $stmt_check = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $stmt_check->execute();
                if ($stmt_check->rowCount() === 0) {
                    $teamUserField = 'u_ID';
                }
            } catch (Exception $e) {
                $teamUserField = 'team_u_ID';
            }
            
            $userRoleUidField = 'ur_u_ID';
            try {
                $stmt_check = $conn->prepare("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'");
                $stmt_check->execute();
                if ($stmt_check->rowCount() === 0) {
                    $userRoleUidField = 'u_ID';
                }
            } catch (Exception $e) {
                $userRoleUidField = 'ur_u_ID';
            }
            
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            
            $sql = "
                SELECT DISTINCT
                    u.u_ID,
                    u.u_name,
                    u.u_gmail,
                    u.u_img,
                    e.class_ID,
                    c.c_name as class_name,
                    e.cohort_ID,
                    co.cohort_name,
                    e.enroll_grade
                FROM userdata u
                INNER JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID 
                    AND e.enroll_status = 1
                INNER JOIN userrolesdata ur ON ur.{$userRoleUidField} = u.u_ID 
                    AND ur.role_ID = 6 
                    AND ur.user_role_status = 1
                LEFT JOIN classdata c ON c.c_ID = e.class_ID
                LEFT JOIN cohortdata co ON co.cohort_ID = e.cohort_ID
                LEFT JOIN teammember tm ON tm.{$teamUserField} = u.u_ID 
                    AND tm.tm_status = 1
                WHERE tm.team_ID IS NULL
            ";
            
            $params = [];
            if ($cohort_ID > 0) {
                $sql .= " AND e.cohort_ID = ?";
                $params[] = $cohort_ID;
            }
            
            $sql .= " ORDER BY u.u_ID ASC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_ok(['students' => $students]);
        } catch (Exception $e) {
            json_err('取得未加入組別學生列表失敗：' . $e->getMessage());
        }
        break;

    // 發送通知至單一學生 Gmail
    case 'notify_student_gmail':
        try {
            // 僅允許主任/科辦
            $role_ID = $_SESSION['role_ID'] ?? null;
            if (!in_array($role_ID, [1, 2])) {
                json_err('無權限執行此操作');
            }

            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true) ?: [];
            $studentId = trim($payload['student_id'] ?? '');
            $subject = trim($payload['subject'] ?? '尚未加入團隊提醒');
            $content = trim($payload['message'] ?? '您尚未加入團隊，請盡速於系統完成組隊或向老師回覆目前進度。此信為系統通知，請勿直接回覆。');

            if ($studentId === '') {
                json_err('缺少學生 ID');
            }

            $stmt = $conn->prepare("SELECT u_name, u_gmail FROM userdata WHERE u_ID = ?");
            $stmt->execute([$studentId]);
            $stu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$stu) {
                json_err('找不到該學生');
            }
            $to = $stu['u_gmail'] ?? '';
            if ($to === '') {
                json_err('該學生未設定 Gmail');
            }

            // 寄送郵件
            sendStudentEmailGeneric($to, $subject, $content);
            json_ok(['message' => '通知已寄出', 'email' => $to]);
        } catch (Exception $e) {
            json_err('寄送通知失敗：' . $e->getMessage());
        }
        break;
}
