<?php
/**
 * 通知系統 API 模組
 * 
 * 修改記錄：
 * 2025-11-16 - 創建通知系統API
 *   改動內容：獲取用戶通知列表，更新通知數量
 *   相關功能：通知中心顯示
 *   方式：從 msgdata 和 msgtargetdata 表查詢通知
 */

global $conn;
$p = $_POST;
$do = $_GET['do'] ?? '';
$u_ID = $_SESSION['u_ID'] ?? null;

switch ($do) {
    // 獲取用戶通知列表
    case 'get_notifications':
        if (!$u_ID) {
            json_err('請先登入', 'NOT_LOGGED_IN', 401);
        }
        
        try {
            // 獲取發送給當前用戶的未讀通知
            // 包括：目標為該用戶的通知，或目標為ALL的通知
            // 只返回未讀的通知（點擊後會消失，未點擊的會一直顯示）
            $sql = "
                SELECT DISTINCT
                    m.msg_ID,
                    m.msg_title,
                    m.msg_content,
                    m.msg_url,
                    m.msg_type,
                    m.msg_start_d,
                    m.msg_created_d,
                    m.msg_a_u_ID,
                    0 as is_read
                FROM msgdata m
                INNER JOIN msgtargetdata mt ON m.msg_ID = mt.msg_ID
                LEFT JOIN msgreaddata mr ON m.msg_ID = mr.msg_ID AND mr.read_u_ID = ?
                WHERE m.msg_status = 1 -- 已發布
                  AND m.msg_type IN ('SYSTEM_NOTICE', 'REMINDER') -- 只顯示系統通知和提醒類型
                  AND (m.msg_start_d IS NULL OR m.msg_start_d <= NOW())
                  AND (m.msg_end_d IS NULL OR m.msg_end_d >= NOW())
                  AND (
                      mt.msg_target_type = 'ALL' 
                      OR (mt.msg_target_type = 'USER' AND mt.msg_target_ID = ?)
                      OR (mt.msg_target_type = 'COHORT' AND mt.msg_target_ID = ? AND m.msg_a_u_ID != ?) -- 排除發送者自己
                      OR (mt.msg_target_type = 'CLASS' AND mt.msg_target_ID = ?)
                      OR (mt.msg_target_type = 'TEAM' AND mt.msg_target_ID = ?)
                  )
                  AND mr.msg_ID IS NULL
                ORDER BY m.msg_created_d DESC
                LIMIT 50
            ";
            
            // 獲取用戶的屆別、班級、團隊資訊
            $cohort_ID = null;
            $class_ID = null;
            $team_ID = null;
            
            try {
                // 先嘗試從 enrollmentdata 表獲取（正確的數據源）
                $stmt = $conn->prepare("
                    SELECT cohort_ID, class_ID 
                    FROM enrollmentdata 
                    WHERE enroll_u_ID = ? AND enroll_status = 1 
                    ORDER BY enroll_created_d DESC 
                    LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($enrollment) {
                    $cohort_ID = $enrollment['cohort_ID'];
                    $class_ID = $enrollment['class_ID'];
                } else {
                    // 如果 enrollmentdata 沒有資料，嘗試從 studentdata 獲取（兼容舊版本）
                    $stmt = $conn->prepare("SELECT cohort_ID, class_ID FROM studentdata WHERE u_ID = ?");
                    $stmt->execute([$u_ID]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($student) {
                        $cohort_ID = $student['cohort_ID'];
                        $class_ID = $student['class_ID'];
                    }
                }
            } catch (Throwable $e) {
                // 如果查詢失敗，繼續執行
            }
            
            // 取得學生的團隊 ID（兼容 teammember 欄位 team_u_ID / u_ID）
            $teamMemberUidField = 'u_ID';
            try {
                $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $stmt->execute();
                if ($stmt->rowCount() > 0) $teamMemberUidField = 'team_u_ID';
            } catch (Throwable $e) { /* ignore */ }
            try {
                $stmt = $conn->prepare("
                    SELECT tm.team_ID 
                    FROM teammember tm
                    JOIN teamdata td ON tm.team_ID = td.team_ID
                    WHERE tm.{$teamMemberUidField} = ? AND (tm.tm_status = 1 OR tm.tm_status IS NULL) AND td.team_status = 1
                    ORDER BY td.team_created_d DESC LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $team = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($team) {
                    $team_ID = $team['team_ID'];
                }
            } catch (Throwable $e) {
                // 如果查詢失敗，繼續執行
            }
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$u_ID, $u_ID, $cohort_ID, $u_ID, $class_ID, $team_ID]); // 添加 $u_ID 用於排除發送者
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 解析 msg_url JSON 並添加到每個通知中
            foreach ($notifications as &$notif) {
                if (!empty($notif['msg_url'])) {
                    $notif['urls'] = json_decode($notif['msg_url'], true) ?: [];
                } else {
                    $notif['urls'] = [];
                }
            }
            
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($notifications, JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status'=>'error','message'=>'載入通知失敗：'.$e->getMessage()]);
            exit;
        }
        break;
    
    // 獲取未讀通知數量
    case 'get_notification_count':
        if (!$u_ID) {
            json_ok(['count' => 0]);
            exit;
        }
        
        try {
            // 獲取用戶的屆別、班級、團隊資訊
            $cohort_ID = null;
            $class_ID = null;
            $team_ID = null;
            
            try {
                // 先嘗試從 enrollmentdata 表獲取（正確的數據源）
                $stmt = $conn->prepare("
                    SELECT cohort_ID, class_ID 
                    FROM enrollmentdata 
                    WHERE enroll_u_ID = ? AND enroll_status = 1 
                    ORDER BY enroll_created_d DESC 
                    LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($enrollment) {
                    $cohort_ID = $enrollment['cohort_ID'];
                    $class_ID = $enrollment['class_ID'];
                } else {
                    // 如果 enrollmentdata 沒有資料，嘗試從 studentdata 獲取（兼容舊版本）
                    $stmt = $conn->prepare("SELECT cohort_ID, class_ID FROM studentdata WHERE u_ID = ?");
                    $stmt->execute([$u_ID]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($student) {
                        $cohort_ID = $student['cohort_ID'];
                        $class_ID = $student['class_ID'];
                    }
                }
            } catch (Throwable $e) {
                // 如果查詢失敗，繼續執行
            }
            
            // 取得學生的團隊 ID（兼容 teammember 欄位 team_u_ID / u_ID）
            $teamMemberUidField = 'u_ID';
            try {
                $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $stmt->execute();
                if ($stmt->rowCount() > 0) $teamMemberUidField = 'team_u_ID';
            } catch (Throwable $e) { /* ignore */ }
            try {
                $stmt = $conn->prepare("
                    SELECT tm.team_ID 
                    FROM teammember tm
                    JOIN teamdata td ON tm.team_ID = td.team_ID
                    WHERE tm.{$teamMemberUidField} = ? AND (tm.tm_status = 1 OR tm.tm_status IS NULL) AND td.team_status = 1
                    ORDER BY td.team_created_d DESC LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $team = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($team) {
                    $team_ID = $team['team_ID'];
                }
            } catch (Throwable $e) {
                // 如果查詢失敗，繼續執行
            }
            
            $sql = "
                SELECT COUNT(DISTINCT m.msg_ID) as unread_count
                FROM msgdata m
                INNER JOIN msgtargetdata mt ON m.msg_ID = mt.msg_ID
                LEFT JOIN msgreaddata mr ON m.msg_ID = mr.msg_ID AND mr.read_u_ID = ?
                WHERE m.msg_status = 1 -- 已發布
                  AND m.msg_type IN ('SYSTEM_NOTICE', 'REMINDER') -- 只顯示系統通知和提醒類型
                  AND (m.msg_start_d IS NULL OR m.msg_start_d <= NOW())
                  AND (m.msg_end_d IS NULL OR m.msg_end_d >= NOW())
                  AND (
                      mt.msg_target_type = 'ALL' 
                      OR (mt.msg_target_type = 'USER' AND mt.msg_target_ID = ?)
                      OR (mt.msg_target_type = 'COHORT' AND mt.msg_target_ID = ? AND m.msg_a_u_ID != ?) -- 排除發送者自己
                      OR (mt.msg_target_type = 'CLASS' AND mt.msg_target_ID = ?)
                      OR (mt.msg_target_type = 'TEAM' AND mt.msg_target_ID = ?)
                  )
                  AND mr.msg_ID IS NULL
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$u_ID, $u_ID, $cohort_ID, $u_ID, $class_ID, $team_ID]); // 添加 $u_ID 用於排除發送者
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($result['unread_count'] ?? 0);
            
            json_ok(['count' => $count]);
            exit;
        } catch (Throwable $e) {
            json_ok(['count' => 0]);
            exit;
        }
        break;
    
    // 標記通知為已讀
    case 'mark_notification_read':
        if (!$u_ID) {
            json_err('請先登入', 'NOT_LOGGED_IN', 401);
        }
        
        $msg_ID = isset($p['msg_ID']) ? (int)$p['msg_ID'] : 0;
        if ($msg_ID <= 0) {
            json_err('通知ID無效');
        }
        
        try {
            // 檢查是否已讀
            $stmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM msgreaddata 
                WHERE msg_ID = ? AND read_u_ID = ?
            ");
            $stmt->execute([$msg_ID, $u_ID]);
            
            if (!$stmt->fetchColumn()) {
                // 如果未讀，則插入已讀記錄
                $stmt = $conn->prepare("
                    INSERT INTO msgreaddata (msg_ID, read_u_ID, msg_read_d)
                    VALUES (?, ?, NOW())
                ");
                $stmt->execute([$msg_ID, $u_ID]);
            }
            
            json_ok(['message' => '已標記為已讀']);
        } catch (Throwable $e) {
            json_err('操作失敗：'.$e->getMessage());
        }
        break;
    
    // 通知班導：科辦發送通知給學生的班導
    case 'notify_class_teacher':
        // 檢查權限：只有科辦（role_ID = 2）可以發送
        if (!$u_ID) {
            json_err('請先登入', 'NOT_LOGGED_IN', 401);
        }
        
        $role_ID = $_SESSION['role_ID'] ?? null;
        if ($role_ID != 2) {
            json_err('無權限：只有科辦可以發送通知給班導', 'FORBIDDEN', 403);
        }
        
        // 獲取 POST 資料（JSON格式）
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!isset($data['student_ids']) || !is_array($data['student_ids']) || empty($data['student_ids'])) {
            json_err('請提供學生ID列表');
        }
        
        $studentIds = array_map('trim', $data['student_ids']);
        $studentIds = array_filter($studentIds);
        
        if (empty($studentIds)) {
            json_err('學生ID列表不能為空');
        }
        
        try {
            // 檢查欄位名稱（兼容不同版本）
            $userRoleUidField = 'u_ID';
            try {
                $checkStmt = $conn->query("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'");
                if ($checkStmt->rowCount() > 0) {
                    $userRoleUidField = 'ur_u_ID';
                }
            } catch (Exception $e) {
                // 忽略錯誤，使用預設值
            }
            
            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            
            // 獲取學生的班級資訊
            $stmt = $conn->prepare("
                SELECT DISTINCT
                    u.u_ID,
                    u.u_name,
                    e.class_ID,
                    c.c_name as class_name,
                    e.cohort_ID,
                    co.cohort_name
                FROM userdata u
                JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID AND e.enroll_status = 1
                LEFT JOIN classdata c ON c.c_ID = e.class_ID
                LEFT JOIN cohortdata co ON co.cohort_ID = e.cohort_ID
                WHERE u.u_ID IN ($placeholders)
                  AND EXISTS (
                      SELECT 1 FROM userrolesdata ur 
                      WHERE ur.{$userRoleUidField} = u.u_ID 
                      AND ur.role_ID = 6 
                      AND ur.user_role_status = 1
                  )
            ");
            $stmt->execute($studentIds);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($students)) {
                json_err('找不到符合條件的學生');
            }
            
            // 按屆別和班級分組學生（關鍵：需要同時匹配 cohort_ID 和 class_ID）
            $groupedStudents = [];
            foreach ($students as $student) {
                $classId = $student['class_ID'];
                $cohortId = $student['cohort_ID'];
                
                if ($classId && $cohortId) {
                    $key = $cohortId . '_' . $classId;
                    if (!isset($groupedStudents[$key])) {
                        $groupedStudents[$key] = [
                            'class_ID' => $classId,
                            'class_name' => $student['class_name'] ?? '',
                            'cohort_ID' => $cohortId,
                            'cohort_name' => $student['cohort_name'] ?? '',
                            'students' => []
                        ];
                    }
                    $groupedStudents[$key]['students'][] = $student;
                }
            }
            
            if (empty($groupedStudents)) {
                json_err('學生沒有完整的屆別和班級資訊');
            }
            
            // 查找每個屆別+班級組合的班導（role_ID = 3）
            // 重要：必須同時匹配 cohort_ID 和 class_ID
            $teacherNotifications = [];
            foreach ($groupedStudents as $key => $groupInfo) {
                $classId = $groupInfo['class_ID'];
                $cohortId = $groupInfo['cohort_ID'];
                
                // 查找該屆別和班級的班導
                // 邏輯：在 enrollmentdata 中查找相同的 cohort_ID 和 class_ID，且 role_ID = 3 的記錄
                $stmt = $conn->prepare("
                    SELECT DISTINCT 
                        e.enroll_u_ID as teacher_id, 
                        u.u_name as teacher_name
                    FROM enrollmentdata e
                    JOIN userrolesdata ur ON ur.{$userRoleUidField} = e.enroll_u_ID
                    JOIN userdata u ON u.u_ID = e.enroll_u_ID
                    WHERE e.cohort_ID = ?
                      AND e.class_ID = ?
                      AND e.role_ID = 3
                      AND e.enroll_status = 1
                      AND ur.role_ID = 3
                      AND ur.user_role_status = 1
                      AND u.u_status = 1
                    LIMIT 1
                ");
                $stmt->execute([$cohortId, $classId]);
                $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($teacher) {
                    $teacherNotifications[] = [
                        'teacher_id' => $teacher['teacher_id'],
                        'teacher_name' => $teacher['teacher_name'],
                        'class_ID' => $classId,
                        'class_name' => $groupInfo['class_name'],
                        'cohort_ID' => $cohortId,
                        'cohort_name' => $groupInfo['cohort_name'],
                        'students' => $groupInfo['students']
                    ];
                }
            }
            
            if (empty($teacherNotifications)) {
                json_err('找不到相關班級的班導');
            }
            
            // 為每個班導創建通知
            $notifiedTeachers = [];
            $notifiedCount = 0;
            
            foreach ($teacherNotifications as $notif) {
                $teacherId = $notif['teacher_id'];
                $studentList = array_map(function($s) {
                    return $s['u_name'] . '（' . $s['u_ID'] . '）';
                }, $notif['students']);
                
                $studentNames = implode('、', $studentList);
                $studentCount = count($notif['students']);
                
                // 創建通知標題和內容
                $title = "未加入團隊學生通知";
                $content = "您好，該班級有 {$studentCount} 位學生尚未加入團隊：\n\n" . $studentNames;
                $content .= "\n\n請協助提醒學生加入團隊。";
                
                // 插入到 msgdata 表
                $stmt = $conn->prepare("
                    INSERT INTO msgdata 
                    (msg_title, msg_content, msg_type, msg_a_u_ID, msg_status, msg_start_d, msg_created_d)
                    VALUES (?, ?, 'SYSTEM_NOTICE', ?, 1, NOW(), NOW())
                ");
                $stmt->execute([$title, $content, $u_ID]);
                $msgId = $conn->lastInsertId();
                
                // 插入目標對象（發送給該班導）
                $stmt = $conn->prepare("
                    INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID)
                    VALUES (?, 'USER', ?)
                ");
                $stmt->execute([$msgId, $teacherId]);
                
                $notifiedTeachers[] = $notif['teacher_name'];
                $notifiedCount++;
            }
            
            $message = "已成功發送通知給 {$notifiedCount} 位班導：" . implode('、', $notifiedTeachers);
            
            json_ok([
                'message' => $message,
                'notified_count' => $notifiedCount,
                'teachers' => $notifiedTeachers
            ]);
            
        } catch (Throwable $e) {
            error_log('通知班導失敗: ' . $e->getMessage());
            json_err('發送通知失敗：' . $e->getMessage());
        }
        break;
    
    // 獲取所有通知（包括已讀和未讀）
    case 'get_all_notifications':
        if (!$u_ID) {
            json_err('請先登入', 'NOT_LOGGED_IN', 401);
        }
        
        try {
            // 獲取用戶的屆別、班級、團隊資訊
            $cohort_ID = null;
            $class_ID = null;
            $team_ID = null;
            
            try {
                // 先嘗試從 enrollmentdata 表獲取（正確的數據源）
                $stmt = $conn->prepare("
                    SELECT cohort_ID, class_ID 
                    FROM enrollmentdata 
                    WHERE enroll_u_ID = ? AND enroll_status = 1 
                    ORDER BY enroll_created_d DESC 
                    LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($enrollment) {
                    $cohort_ID = $enrollment['cohort_ID'];
                    $class_ID = $enrollment['class_ID'];
                } else {
                    // 如果 enrollmentdata 沒有資料，嘗試從 studentdata 獲取（兼容舊版本）
                    $stmt = $conn->prepare("SELECT cohort_ID, class_ID FROM studentdata WHERE u_ID = ?");
                    $stmt->execute([$u_ID]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($student) {
                        $cohort_ID = $student['cohort_ID'];
                        $class_ID = $student['class_ID'];
                    }
                }
            } catch (Throwable $e) {
                // 如果查詢失敗，繼續執行
            }
            
            // 取得學生的團隊 ID（兼容 teammember 欄位 team_u_ID / u_ID）
            $teamMemberUidField = 'u_ID';
            try {
                $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $stmt->execute();
                if ($stmt->rowCount() > 0) $teamMemberUidField = 'team_u_ID';
            } catch (Throwable $e) { /* ignore */ }
            try {
                $stmt = $conn->prepare("
                    SELECT tm.team_ID 
                    FROM teammember tm
                    JOIN teamdata td ON tm.team_ID = td.team_ID
                    WHERE tm.{$teamMemberUidField} = ? AND (tm.tm_status = 1 OR tm.tm_status IS NULL) AND td.team_status = 1
                    ORDER BY td.team_created_d DESC LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $team = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($team) {
                    $team_ID = $team['team_ID'];
                }
            } catch (Throwable $e) {
                // 如果查詢失敗，繼續執行
            }
            
            $sql = "
                SELECT DISTINCT
                    m.msg_ID,
                    m.msg_title,
                    m.msg_content,
                    m.msg_url,
                    m.msg_type,
                    m.msg_start_d,
                    m.msg_created_d,
                    m.msg_a_u_ID,
                    u.u_name as sender_name,
                    CASE WHEN mr.msg_ID IS NOT NULL THEN 1 ELSE 0 END as is_read,
                    mr.msg_read_d as read_date
                FROM msgdata m
                INNER JOIN msgtargetdata mt ON m.msg_ID = mt.msg_ID
                LEFT JOIN userdata u ON m.msg_a_u_ID = u.u_ID
                LEFT JOIN msgreaddata mr ON m.msg_ID = mr.msg_ID AND mr.read_u_ID = ?
                WHERE m.msg_status = 1
                  AND (m.msg_start_d IS NULL OR m.msg_start_d <= NOW())
                  AND (m.msg_end_d IS NULL OR m.msg_end_d >= NOW())
                  AND (
                      mt.msg_target_type = 'ALL' 
                      OR (mt.msg_target_type = 'USER' AND mt.msg_target_ID = ?)
                      OR (mt.msg_target_type = 'COHORT' AND mt.msg_target_ID = ? AND m.msg_a_u_ID != ?) -- 排除發送者自己
                      OR (mt.msg_target_type = 'CLASS' AND mt.msg_target_ID = ?)
                      OR (mt.msg_target_type = 'TEAM' AND mt.msg_target_ID = ?)
                  )
                ORDER BY m.msg_created_d DESC
                LIMIT 200
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$u_ID, $u_ID, $cohort_ID, $u_ID, $class_ID, $team_ID]); // 添加 $u_ID 用於排除發送者
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 解析 msg_url JSON 並添加到每個通知中
            foreach ($notifications as &$notif) {
                if (!empty($notif['msg_url'])) {
                    $notif['urls'] = json_decode($notif['msg_url'], true) ?: [];
                } else {
                    $notif['urls'] = [];
                }
            }
            
            // 轉換 is_read 為整數
            foreach ($notifications as &$notif) {
                $notif['is_read'] = (int)$notif['is_read'];
            }
            
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($notifications, JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status'=>'error','message'=>'載入通知失敗：'.$e->getMessage()]);
            exit;
        }
        break;
    
    // 標記通知為未讀
    case 'mark_notification_unread':
        if (!$u_ID) {
            json_err('請先登入', 'NOT_LOGGED_IN', 401);
        }
        
        $msg_ID = isset($p['msg_ID']) ? (int)$p['msg_ID'] : 0;
        if ($msg_ID <= 0) {
            json_err('通知ID無效');
        }
        
        try {
            // 刪除已讀記錄
            $stmt = $conn->prepare("
                DELETE FROM msgreaddata 
                WHERE msg_ID = ? AND read_u_ID = ?
            ");
            $stmt->execute([$msg_ID, $u_ID]);
            
            json_ok(['message' => '已標記為未讀']);
        } catch (Throwable $e) {
            json_err('操作失敗：'.$e->getMessage());
        }
        break;
    
    // 刪除通知（從 msgreaddata 中刪除，實際上是取消已讀記錄）
    case 'delete_notification':
        if (!$u_ID) {
            json_err('請先登入', 'NOT_LOGGED_IN', 401);
        }
        
        $msg_ID = isset($p['msg_ID']) ? (int)$p['msg_ID'] : 0;
        if ($msg_ID <= 0) {
            json_err('通知ID無效');
        }
        
        try {
            // 刪除已讀記錄（這樣通知會重新出現在未讀列表中）
            // 或者可以選擇完全隱藏通知，但這裡我們採用刪除已讀記錄的方式
            $stmt = $conn->prepare("
                DELETE FROM msgreaddata 
                WHERE msg_ID = ? AND read_u_ID = ?
            ");
            $stmt->execute([$msg_ID, $u_ID]);
            
            // 如果用戶想要完全隱藏通知，可以添加一個新的表來記錄隱藏的通知
            // 這裡先採用刪除已讀記錄的方式，讓通知重新變為未讀
            
            json_ok(['message' => '已刪除通知']);
        } catch (Throwable $e) {
            json_err('操作失敗：'.$e->getMessage());
        }
        break;
    
    // 發送時程表通知給當屆所有人
    case 'send_schedule_notification':
        // 檢查權限：只有主任（role_ID = 1）和科辦（role_ID = 2）可以發送
        if (!$u_ID) {
            json_err('請先登入', 'NOT_LOGGED_IN', 401);
        }
        
        $role_ID = $_SESSION['role_ID'] ?? null;
        if (!in_array($role_ID, [1, 2])) {
            json_err('無權限：只有主任和科辦可以發送時程表通知', 'FORBIDDEN', 403);
        }
        
        // 獲取 POST 資料（JSON格式）
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!isset($data['title']) || empty(trim($data['title']))) {
            json_err('請提供時程表標題');
        }
        
        if (!isset($data['cohort_ID']) || empty($data['cohort_ID'])) {
            json_err('請提供屆別ID');
        }
        
        $title = trim($data['title']);
        $cohort_ID = (int)$data['cohort_ID'];
        
        try {
            // 驗證屆別是否存在
            $stmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ? AND cohort_status = 1");
            $stmt->execute([$cohort_ID]);
            $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$cohort) {
                json_err('找不到指定的屆別或屆別已停用');
            }
            
            $cohort_name = $cohort['cohort_name'];
            
            // 嘗試獲取 tinforma_ID（如果存在）
            $tinforma_ID = null;
            try {
                $checkStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_title'");
                $hasTitleField = $checkStmt->rowCount() > 0;
                
                if ($hasTitleField) {
                    $stmt = $conn->prepare("
                        SELECT tinforma_ID 
                        FROM timeinformadata 
                        WHERE tinforma_title = ? 
                        ORDER BY COALESCE(tinforma_update_d, tinforma_create_d) DESC 
                        LIMIT 1
                    ");
                    $stmt->execute([$title]);
                } else {
                    $stmt = $conn->prepare("
                        SELECT tinforma_ID 
                        FROM timeinformadata 
                        WHERE tinforma_content LIKE ? OR tinforma_content = ? 
                        ORDER BY tinforma_create_d DESC 
                        LIMIT 1
                    ");
                    $stmt->execute(["%" . $title . "%", $title]);
                }
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $tinforma_ID = $result['tinforma_ID'];
                }
            } catch (Throwable $e) {
                // 如果查詢失敗，繼續執行（使用標題）
            }
            
            // 構建時程表管理頁面URL（跳轉到科辦匯出的頁面，自動選擇對應的屆別和時程表）
            $scheduleUrl = "pages/schedule_manage.php?cohort_ID={$cohort_ID}";
            if ($tinforma_ID) {
                $scheduleUrl .= "&tinforma_ID={$tinforma_ID}";
            } else {
                $scheduleUrl .= "&title=" . urlencode($title);
            }
            
            // 創建通知標題和內容
            $msgTitle = "時程表通知：{$title}";
            $msgContent = "{$cohort_name} 的時程表「{$title}」已發布。";
            
            // 構建 msg_url JSON 陣列（包含時程表連結）
            $urlData = [
                [
                    'type' => 'link',
                    'url' => $scheduleUrl,
                    'label' => '查看'
                ]
            ];
            $msg_url = json_encode($urlData, JSON_UNESCAPED_UNICODE);
            
            // 插入到 msgdata 表
            $stmt = $conn->prepare("
                INSERT INTO msgdata 
                (msg_title, msg_content, msg_url, msg_type, msg_a_u_ID, msg_status, msg_start_d, msg_created_d)
                VALUES (?, ?, ?, 'SYSTEM_NOTICE', ?, 1, NOW(), NOW())
            ");
            $stmt->execute([$msgTitle, $msgContent, $msg_url, $u_ID]);
            $msg_ID = $conn->lastInsertId();
            
            if (!$msg_ID) {
                json_err('創建通知失敗');
            }
            
            // 插入目標對象（發送給該屆別的所有人）
            // 使用 COHORT 類型，目標ID為 cohort_ID
            $stmt = $conn->prepare("
                INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID)
                VALUES (?, 'COHORT', ?)
            ");
            $stmt->execute([$msg_ID, $cohort_ID]);
            
            json_ok([
                'message' => "通知已成功發送給 {$cohort_name} 的所有人",
                'msg_ID' => $msg_ID,
                'cohort_name' => $cohort_name
            ]);
            
        } catch (Throwable $e) {
            error_log('發送時程表通知失敗: ' . $e->getMessage());
            json_err('發送通知失敗：' . $e->getMessage());
        }
        break;
    
    // 發送建議表通知給當屆所有人（除了自己）
    case 'send_suggest_notification':
        // 檢查權限：只有主任（role_ID = 1）和科辦（role_ID = 2）可以發送
        if (!$u_ID) {
            json_err('請先登入', 'NOT_LOGGED_IN', 401);
        }
        
        $role_ID = $_SESSION['role_ID'] ?? null;
        if (!in_array($role_ID, [1, 2])) {
            json_err('無權限：只有主任和科辦可以發送建議表通知', 'FORBIDDEN', 403);
        }
        
        // 獲取 POST 資料（JSON格式）
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!isset($data['title']) || empty(trim($data['title']))) {
            json_err('請提供建議表標題');
        }
        
        if (!isset($data['cohort_ID']) || empty($data['cohort_ID'])) {
            json_err('請提供屆別ID');
        }
        
        $title = trim($data['title']);
        $cohort_ID = (int)$data['cohort_ID'];
        $group_ID = isset($data['group_ID']) ? (int)$data['group_ID'] : null;
        
        try {
            // 驗證屆別是否存在
            $stmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ? AND cohort_status = 1");
            $stmt->execute([$cohort_ID]);
            $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$cohort) {
                json_err('找不到指定的屆別或屆別已停用');
            }
            
            $cohort_name = $cohort['cohort_name'];
            
            // 構建建議表匯出頁面URL（跳轉到匯出頁面）
            $suggestUrl = "pages/suggest_export.php?cohort_ID={$cohort_ID}";
            if ($group_ID) {
                $suggestUrl .= "&group_ID={$group_ID}";
            }
            $suggestUrl .= "&title=" . urlencode($title);
            
            // 創建通知標題和內容
            $msgTitle = "建議表通知：{$title}";
            $msgContent = "{$cohort_name} 的建議表「{$title}」已發布。";
            
            // 構建 msg_url JSON 陣列（包含建議表連結）
            $urlData = [
                [
                    'type' => 'link',
                    'url' => $suggestUrl,
                    'label' => '查看'
                ]
            ];
            $msg_url = json_encode($urlData, JSON_UNESCAPED_UNICODE);
            
            // 插入到 msgdata 表
            $stmt = $conn->prepare("
                INSERT INTO msgdata 
                (msg_title, msg_content, msg_url, msg_type, msg_a_u_ID, msg_status, msg_start_d, msg_created_d)
                VALUES (?, ?, ?, 'SYSTEM_NOTICE', ?, 1, NOW(), NOW())
            ");
            $stmt->execute([$msgTitle, $msgContent, $msg_url, $u_ID]);
            $msg_ID = $conn->lastInsertId();
            
            if (!$msg_ID) {
                json_err('創建通知失敗');
            }
            
            // 插入目標對象（發送給該屆別的所有人）
            // 使用 COHORT 類型，目標ID為 cohort_ID
            // 注意：通知系統會自動排除發送者自己（因為 msg_a_u_ID = 發送者ID，在查詢時會過濾掉）
            $stmt = $conn->prepare("
                INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID)
                VALUES (?, 'COHORT', ?)
            ");
            $stmt->execute([$msg_ID, $cohort_ID]);
            
            json_ok([
                'message' => "通知已成功發送給 {$cohort_name} 的所有人",
                'msg_ID' => $msg_ID,
                'cohort_name' => $cohort_name
            ]);
            
        } catch (Throwable $e) {
            error_log('發送建議表通知失敗: ' . $e->getMessage());
            json_err('發送通知失敗：' . $e->getMessage());
        }
        break;

    // 繳交提醒：發送給該團隊的學生（最新消息 + Gmail）
    case 'send_submission_remind':
        if (!$u_ID) {
            json_err('請先登入', 'NOT_LOGGED_IN', 401);
        }
        $role_ID = (int)($_SESSION['role_ID'] ?? 0);
        $allowedRoles = [1, 2, 3, 4, 7]; // 主任、科辦、班導、指導老師、召集人
        if (!in_array($role_ID, $allowedRoles)) {
            json_err('無權限：僅主任、科辦、班導、指導老師、召集人可發送繳交提醒', 'FORBIDDEN', 403);
        }
        $team_ID = isset($p['team_ID']) ? (int)$p['team_ID'] : 0;
        $doc_ID = isset($p['doc_ID']) ? (int)$p['doc_ID'] : 0;
        if ($team_ID <= 0 || $doc_ID <= 0) {
            json_err('請提供有效的團隊 ID 與文件 ID');
        }
        // Gmail 發送函式（與 suggest_schedule / team_apply 相同 GAS）
        if (!function_exists('sendMailViaGas')) {
            function sendMailViaGas(string $to, string $subject, string $message): array
            {
                if (trim($to) === '') return ['ok' => false, 'msg' => '收件人為空'];
                $url = "https://script.google.com/macros/s/AKfycbyLLkHxyGhJkllgpztDzcXPcp_IKXL_GS2lnOGDegOAQplqQMVU0EA4LF4ZPDrrkfyb/exec";
                $data = ['to' => $to, 'subject' => $subject, 'message' => $message];
                $options = [
                    'http' => [
                        'method'  => 'POST',
                        'header'  => 'Content-type: application/x-www-form-urlencoded',
                        'content' => http_build_query($data),
                        'timeout' => 20,
                    ],
                ];
                $ctx = stream_context_create($options);
                $res = @file_get_contents($url, false, $ctx);
                if ($res === false) return ['ok' => false, 'msg' => '無法連線到 GAS'];
                $decoded = json_decode($res, true);
                if (!is_array($decoded)) return ['ok' => false, 'msg' => 'GAS 回傳非 JSON'];
                return [
                    'ok'  => !empty($decoded['ok']),
                    'msg' => isset($decoded['msg']) ? (string)$decoded['msg'] : (isset($decoded['message']) ? (string)$decoded['message'] : ''),
                ];
            }
        }
        try {
            // 檔名
            $stmt = $conn->prepare("SELECT doc_name FROM document_forms WHERE doc_ID = ? AND doc_status = 1");
            $stmt->execute([$doc_ID]);
            $docName = $stmt->fetchColumn();
            if ($docName === false || $docName === null) {
                $stmt = $conn->prepare("SELECT doc_name FROM docdata WHERE doc_ID = ?");
                $stmt->execute([$doc_ID]);
                $docName = $stmt->fetchColumn() ?: '該文件';
            }
            $docName = $docName ?: '該文件';

            // 團隊成員欄位（兼容 team_u_ID / u_ID）
            $teamUserField = 'u_ID';
            try {
                $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $stmt->execute();
                if ($stmt->rowCount() > 0) $teamUserField = 'team_u_ID';
            } catch (Throwable $e) { /* ignore */ }
            $userRoleUidField = 'u_ID';
            try {
                $stmt = $conn->query("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'");
                if ($stmt && $stmt->rowCount() > 0) $userRoleUidField = 'ur_u_ID';
            } catch (Throwable $e) { /* ignore */ }

            // 該團隊的學生（role=6）含 u_ID, u_name, u_gmail
            $stmt = $conn->prepare("
                SELECT DISTINCT u.u_ID, u.u_name, u.u_gmail
                FROM teammember tm
                JOIN userdata u ON u.u_ID = tm.{$teamUserField}
                JOIN userrolesdata ur ON ur.{$userRoleUidField} = u.u_ID
                WHERE tm.team_ID = ?
                  AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
                  AND u.u_status IN (1, 3)
                  AND ur.role_ID = 6
                  AND ur.user_role_status = 1
            ");
            $stmt->execute([$team_ID]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($students)) {
                json_err('此團隊沒有符合條件的學生成員');
            }

            $msgTitle = "繳交提醒：{$docName}";
            $msgContent = "您的團隊尚未繳交「{$docName}」，請儘快至系統完成繳交。";
            $linkUrl = "main.php#pages/submission_view.php";
            $msg_url = json_encode([['type' => 'link', 'url' => $linkUrl, 'label' => '前往繳交']], JSON_UNESCAPED_UNICODE);

            $stmt = $conn->prepare("
                INSERT INTO msgdata
                (msg_title, msg_content, msg_url, msg_type, msg_a_u_ID, msg_status, msg_start_d, msg_created_d)
                VALUES (?, ?, ?, 'REMINDER', ?, 1, NOW(), NOW())
            ");
            $stmt->execute([$msgTitle, $msgContent, $msg_url, $u_ID]);
            $msg_ID = (int)$conn->lastInsertId();
            if ($msg_ID <= 0) {
                json_err('建立通知失敗');
            }
            // 改為對每位學生插入一筆 USER 目標，確保「最新消息」一定能收到（不依賴 TEAM 查詢）
            $stmtTarget = $conn->prepare("
                INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID) VALUES (?, 'USER', ?)
            ");
            foreach ($students as $row) {
                $stmtTarget->execute([$msg_ID, $row['u_ID']]);
            }

            $mailSent = 0;
            $mailFail = 0;
            foreach ($students as $row) {
                $email = isset($row['u_gmail']) ? trim($row['u_gmail']) : '';
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                $body = "{$row['u_name']} 您好，\n\n{$msgContent}\n\n請登入系統查看：{$linkUrl}\n\n---\n專題日總彙系統";
                $result = sendMailViaGas($email, $msgTitle, $body);
                if (!empty($result['ok'])) {
                    $mailSent++;
                } else {
                    $mailFail++;
                }
                usleep(300000);
            }

            $message = "已發送提醒至最新消息給該團隊學生";
            if ($mailSent > 0) {
                $message .= "，並已寄送 {$mailSent} 封 Gmail";
                if ($mailFail > 0) $message .= "（{$mailFail} 封失敗）";
            }
            json_ok([
                'message'    => $message,
                'mail_sent'  => $mailSent,
                'mail_fail'  => $mailFail,
                'team_count' => count($students),
            ]);
        } catch (Throwable $e) {
            error_log('send_submission_remind: ' . $e->getMessage());
            json_err('發送提醒失敗：' . $e->getMessage());
        }
        break;
}

