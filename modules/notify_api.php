<?php
/**
 * 公告管理 API 模組
 * 處理公告的 CRUD 操作
 */

global $conn;
// 檢查函數是否已存在（api.php 已經載入了 utils.php）
if (!function_exists('json_ok')) {
    require_once __DIR__ . '/../includes/utils.php';
}
ob_start();
ob_clean();

$p = $_POST;
$do = $_GET['do'] ?? '';
$u_ID = $_SESSION['u_ID'] ?? null;
$role_ID = $_SESSION['role_ID'] ?? null;

// 檢查權限：僅系辦(role=2)可管理公告；get_marquee_announcements、get_user_announcements 允許所有登入用戶
$manageOps = ['notify_save', 'notify_update', 'notify_delete', 'notify_publish', 'notify_list', 'notify_detail'];
if (in_array($do, $manageOps) && (int)$role_ID !== 2) {
    json_err('權限不足，僅限系辦使用');
}

// 處理檔案上傳
function handleFileUpload($files, $uploadDir, $allowedTypes = []) {
    $uploadedFiles = [];
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    if (empty($files) || !isset($files['name'])) {
        return $uploadedFiles;
    }
    
    // 處理多檔案上傳
    $fileCount = is_array($files['name']) ? count($files['name']) : 1;
    
    for ($i = 0; $i < $fileCount; $i++) {
        $fileName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $fileTmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $fileError = is_array($files['error']) ? $files['error'][$i] : $files['error'];
        $fileSize = is_array($files['size']) ? $files['size'][$i] : $files['size'];
        
        if ($fileError !== UPLOAD_ERR_OK || $fileSize === 0) {
            continue;
        }
        
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $newFileName = uniqid() . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . '/' . $newFileName;
        
        if (move_uploaded_file($fileTmp, $targetPath)) {
            $uploadedFiles[] = [
                'name' => $fileName,
                'path' => str_replace(__DIR__ . '/../', '', $targetPath),
                'type' => $ext
            ];
        }
    }
    
    return $uploadedFiles;
}

switch ($do) {
    case 'notify_save':
        ob_clean(); // 清除之前的輸出
        try {
            $title = trim($p['title'] ?? '');
            if (empty($title)) {
                ob_clean();
                json_err('請輸入資訊名稱');
            }
            
            $content = trim($p['content'] ?? '');
            $link = trim($p['link'] ?? '');
            // 新增時預設為未發布（status = 0），需要手動發布
            $status = 0; // 預設未發布
            $priority = (int)($p['priority'] ?? 0);
            // 限制優先級範圍為 0-10
            if ($priority < 0) $priority = 0;
            if ($priority > 10) $priority = 10;
            $msg_type = $p['msg_type'] ?? 'ANNOUNCEMENT';
            
            // 驗證 msg_type
            if (!in_array($msg_type, ['ANNOUNCEMENT', 'SYSTEM_NOTICE', 'REMINDER'])) {
                $msg_type = 'ANNOUNCEMENT';
            }
            
            // 處理日期時間
            $start_d = null;
            $end_d = null;
            
            if (!empty($p['start_dt'])) {
                $start_d = date('Y-m-d H:i:s', strtotime($p['start_dt']));
            }
            
            if (!empty($p['end_dt'])) {
                $end_d = date('Y-m-d H:i:s', strtotime($p['end_dt']));
            }
            
            // 處理檔案上傳
            $urlData = [];
            
            // 處理圖片上傳
            if (!empty($_FILES['images']['name'])) {
                $images = handleFileUpload($_FILES['images'], __DIR__ . '/../uploads/notify/images', ['jpg', 'jpeg', 'png', 'gif']);
                foreach ($images as $img) {
                    $urlData[] = [
                        'type' => 'image',
                        'url' => $img['path'],
                        'name' => $img['name']
                    ];
                }
            }
            
            // 處理附件上傳
            if (!empty($_FILES['files']['name'])) {
                $files = handleFileUpload($_FILES['files'], __DIR__ . '/../uploads/notify/files');
                foreach ($files as $file) {
                    $urlData[] = [
                        'type' => 'file',
                        'url' => $file['path'],
                        'name' => $file['name']
                    ];
                }
            }
            
            // 如果有連結，加入 urlData
            if (!empty($link)) {
                $urlData[] = [
                    'type' => 'link',
                    'url' => $link
                ];
            }
            
            $msg_url = !empty($urlData) ? json_encode($urlData, JSON_UNESCAPED_UNICODE) : null;
            
            $conn->beginTransaction();
            
            // 插入公告資料
            $stmt = $conn->prepare("
                INSERT INTO msgdata (
                    msg_title, msg_content, msg_url, msg_a_u_ID, 
                    priority, msg_type, msg_status, msg_start_d, msg_end_d, msg_created_d
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $title,
                $content ?: null,
                $msg_url,
                $u_ID,
                $priority,
                $msg_type,
                $status,
                $start_d,
                $end_d
            ]);
            
            $msg_ID = $conn->lastInsertId();
            
            // 處理目標對象
            // 檢查是否有目標對象設定
            $targetAll = isset($p['target_all_groups']) && ($p['target_all_groups'] == 'on' || $p['target_all_groups'] == '1');
            $targetType = $p['target_type'] ?? null;
            $targetID = trim($p['target_ID'] ?? $p['target_id'] ?? '');
            
            if ($targetAll || empty($targetType) || empty($targetID)) {
                // 預設為 ALL（所有用戶）
                $targetStmt = $conn->prepare("
                    INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID)
                    VALUES (?, 'ALL', '')
                ");
                $targetStmt->execute([$msg_ID]);
            } else if (!empty($targetType) && !empty($targetID) && in_array($targetType, ['COHORT', 'CLASS', 'TEAM', 'USER'])) {
                // 處理多個 ID（逗號分隔）
                $targetIDs = explode(',', $targetID);
                foreach ($targetIDs as $id) {
                    $id = trim($id);
                    if (!empty($id)) {
                        $targetStmt = $conn->prepare("
                            INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID)
                            VALUES (?, ?, ?)
                        ");
                        $targetStmt->execute([$msg_ID, $targetType, $id]);
                    }
                }
            } else {
                // 如果格式不正確，預設為 ALL
                $targetStmt = $conn->prepare("
                    INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID)
                    VALUES (?, 'ALL', '')
                ");
                $targetStmt->execute([$msg_ID]);
            }
            
            $conn->commit();
            
            ob_clean(); // 清除所有輸出
            json_ok([
                'msg_ID' => $msg_ID,
                'message' => '公告新增成功'
            ]);
            
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            ob_clean();
            json_err('新增失敗：' . $e->getMessage());
        }
        break;
        
    case 'notify_list':
        ob_clean(); // 清除之前的輸出
        try {
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            
            // 獲取總數
            $countStmt = $conn->query("SELECT COUNT(*) as total FROM msgdata");
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // 獲取列表
            // 注意：LIMIT 和 OFFSET 在 PDO 中需要使用整數值，不能直接使用參數綁定
            $limit = (int)$limit;
            $offset = (int)$offset;
            $stmt = $conn->prepare("
                SELECT 
                    m.msg_ID,
                    m.msg_title,
                    m.msg_content,
                    m.msg_url,
                    m.msg_type,
                    m.msg_status,
                    m.priority,
                    m.msg_start_d,
                    m.msg_end_d,
                    m.msg_created_d,
                    m.msg_a_u_ID,
                    u.u_name as creator_name
                FROM msgdata m
                LEFT JOIN userdata u ON m.msg_a_u_ID = u.u_ID
                ORDER BY m.msg_created_d DESC
                LIMIT {$limit} OFFSET {$offset}
            ");
            
            $stmt->execute();
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 解析 msg_url JSON
            foreach ($list as &$item) {
                if (!empty($item['msg_url'])) {
                    $item['urls'] = json_decode($item['msg_url'], true) ?: [];
                } else {
                    $item['urls'] = [];
                }
            }
            
            ob_clean(); // 清除所有輸出
            json_ok([
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ]);
            
        } catch (Throwable $e) {
            ob_clean();
            json_err('載入列表失敗：' . $e->getMessage());
        }
        break;
        
    case 'notify_detail':
        try {
            $msg_ID = (int)($_GET['msg_ID'] ?? 0);
            
            if ($msg_ID <= 0) {
                json_err('無效的公告 ID');
            }
            
            $stmt = $conn->prepare("
                SELECT 
                    m.*,
                    u.u_name as creator_name
                FROM msgdata m
                LEFT JOIN userdata u ON m.msg_a_u_ID = u.u_ID
                WHERE m.msg_ID = ?
            ");
            
            $stmt->execute([$msg_ID]);
            $detail = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$detail) {
                json_err('找不到該公告');
            }
            
            // 解析 msg_url
            if (!empty($detail['msg_url'])) {
                $detail['urls'] = json_decode($detail['msg_url'], true) ?: [];
            } else {
                $detail['urls'] = [];
            }
            
            // 獲取目標對象
            $targetStmt = $conn->prepare("
                SELECT msg_target_type, msg_target_ID
                FROM msgtargetdata
                WHERE msg_ID = ?
            ");
            $targetStmt->execute([$msg_ID]);
            $targets = $targetStmt->fetchAll(PDO::FETCH_ASSOC);
            $detail['targets'] = $targets;
            
            json_ok(['detail' => $detail]);
            
        } catch (Throwable $e) {
            json_err('載入詳情失敗：' . $e->getMessage());
        }
        break;
        
    case 'notify_update':
        try {
            $msg_ID = (int)($p['msg_ID'] ?? 0);
            
            if ($msg_ID <= 0) {
                json_err('無效的公告 ID');
            }
            
            $title = trim($p['title'] ?? '');
            if (empty($title)) {
                json_err('請輸入資訊名稱');
            }
            
            $content = trim($p['content'] ?? '');
            $link = trim($p['link'] ?? '');
            $status = (int)($p['status'] ?? 1);
            $priority = (int)($p['priority'] ?? 0);
            $msg_type = $p['msg_type'] ?? 'ANNOUNCEMENT';
            
            if (!in_array($msg_type, ['ANNOUNCEMENT', 'SYSTEM_NOTICE', 'REMINDER'])) {
                $msg_type = 'ANNOUNCEMENT';
            }
            
            $start_d = null;
            $end_d = null;
            
            if (!empty($p['start_dt'])) {
                $start_d = date('Y-m-d H:i:s', strtotime($p['start_dt']));
            }
            
            if (!empty($p['end_dt'])) {
                $end_d = date('Y-m-d H:i:s', strtotime($p['end_dt']));
            }
            
            // 處理檔案上傳（保留現有檔案，只添加新檔案）
            $existingUrls = [];
            $stmt = $conn->prepare("SELECT msg_url FROM msgdata WHERE msg_ID = ?");
            $stmt->execute([$msg_ID]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!empty($existing['msg_url'])) {
                $existingUrls = json_decode($existing['msg_url'], true) ?: [];
            }
            
            $urlData = $existingUrls;
            
            // 處理新上傳的圖片
            if (!empty($_FILES['images']['name'])) {
                $images = handleFileUpload($_FILES['images'], __DIR__ . '/../uploads/notify/images', ['jpg', 'jpeg', 'png', 'gif']);
                foreach ($images as $img) {
                    $urlData[] = [
                        'type' => 'image',
                        'url' => $img['path'],
                        'name' => $img['name']
                    ];
                }
            }
            
            // 處理新上傳的附件
            if (!empty($_FILES['files']['name'])) {
                $files = handleFileUpload($_FILES['files'], __DIR__ . '/../uploads/notify/files');
                foreach ($files as $file) {
                    $urlData[] = [
                        'type' => 'file',
                        'url' => $file['path'],
                        'name' => $file['name']
                    ];
                }
            }
            
            // 如果有連結，加入 urlData（如果不存在）
            if (!empty($link)) {
                $hasLink = false;
                foreach ($urlData as $item) {
                    if ($item['type'] === 'link') {
                        $item['url'] = $link;
                        $hasLink = true;
                        break;
                    }
                }
                if (!$hasLink) {
                    $urlData[] = ['type' => 'link', 'url' => $link];
                }
            }
            
            $msg_url = !empty($urlData) ? json_encode($urlData, JSON_UNESCAPED_UNICODE) : null;
            
            $conn->beginTransaction();
            
            $stmt = $conn->prepare("
                UPDATE msgdata SET
                    msg_title = ?,
                    msg_content = ?,
                    msg_url = ?,
                    priority = ?,
                    msg_type = ?,
                    msg_status = ?,
                    msg_start_d = ?,
                    msg_end_d = ?
                WHERE msg_ID = ?
            ");
            
            $stmt->execute([
                $title,
                $content ?: null,
                $msg_url,
                $priority,
                $msg_type,
                $status,
                $start_d,
                $end_d,
                $msg_ID
            ]);
            
            $conn->commit();
            
            json_ok(['message' => '公告更新成功']);
            
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('更新失敗：' . $e->getMessage());
        }
        break;
        
    case 'notify_delete':
        try {
            $msg_ID = (int)($p['msg_ID'] ?? $_GET['msg_ID'] ?? 0);
            
            if ($msg_ID <= 0) {
                json_err('無效的公告 ID');
            }
            
            $conn->beginTransaction();
            
            // 刪除目標對象
            $stmt = $conn->prepare("DELETE FROM msgtargetdata WHERE msg_ID = ?");
            $stmt->execute([$msg_ID]);
            
            // 刪除公告
            $stmt = $conn->prepare("DELETE FROM msgdata WHERE msg_ID = ?");
            $stmt->execute([$msg_ID]);
            
            $conn->commit();
            
            json_ok(['message' => '公告刪除成功']);
            
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('刪除失敗：' . $e->getMessage());
        }
        break;
        
    case 'get_marquee_announcements':
        // 此 API 允許所有登入用戶訪問
        ob_clean(); // 清除之前的輸出
        try {
            if (!$u_ID) {
                json_ok(['announcements' => []]); // 未登入則不顯示跑馬燈
            }
            
            // 獲取用戶的屆別、班級、團隊資訊
            $cohort_ID = null;
            $class_ID = null;
            $team_ID = null;
            
            // 獲取屆別和班級（使用 enrollmentdata 表）
            try {
                $stmt = $conn->prepare("
                    SELECT e.cohort_ID, e.class_ID 
                    FROM enrollmentdata e
                    WHERE e.enroll_u_ID = ? AND e.enroll_status = 1
                    ORDER BY e.cohort_ID DESC LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($enrollment) {
                    $cohort_ID = $enrollment['cohort_ID'];
                    $class_ID = $enrollment['class_ID'];
                }
            } catch (Throwable $e) {
                // 如果查詢失敗，繼續執行
            }
            
            // 獲取團隊ID（檢查欄位名稱）
            try {
                // 檢查欄位名稱（兼容不同版本的資料表結構）
                $colsTm = $conn->query("SHOW COLUMNS FROM teammember")->fetchAll(PDO::FETCH_COLUMN);
                $teamUserField = in_array('team_u_ID', $colsTm) ? 'team_u_ID' : 'u_ID';
                
                $stmt = $conn->prepare("
                    SELECT tm.team_ID 
                    FROM teammember tm
                    JOIN teamdata td ON tm.team_ID = td.team_ID
                    WHERE tm.{$teamUserField} = ? AND tm.tm_status = 1 AND td.team_status = 1
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
                    m.priority,
                    m.msg_start_d,
                    m.msg_end_d
                FROM msgdata m
                INNER JOIN msgtargetdata mt ON m.msg_ID = mt.msg_ID
                WHERE m.msg_status = 1 -- 已發布
                  AND m.msg_type = 'ANNOUNCEMENT' -- 只顯示公告類型（跑馬燈）
                  AND (m.msg_start_d IS NULL OR m.msg_start_d <= NOW())
                  AND (m.msg_end_d IS NULL OR m.msg_end_d >= NOW())
                  AND (
                      mt.msg_target_type = 'ALL'
                      OR (mt.msg_target_type = 'USER' AND mt.msg_target_ID = ?)
                      OR (mt.msg_target_type = 'COHORT' AND mt.msg_target_ID = ?)
                      OR (mt.msg_target_type = 'CLASS' AND mt.msg_target_ID = ?)
                      OR (mt.msg_target_type = 'TEAM' AND mt.msg_target_ID = ?)
                  )
                ORDER BY m.priority DESC, m.msg_created_d DESC
                LIMIT 10
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$u_ID, $cohort_ID, $class_ID, $team_ID]);
            $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ob_clean(); // 再次清除輸出
            json_ok(['announcements' => $announcements]);
            
        } catch (Throwable $e) {
            ob_clean();
            json_err('載入跑馬燈公告失敗：' . $e->getMessage());
        }
        break;
        
    case 'get_user_announcements':
        // 獲取用戶可見的公告列表（允許所有登入用戶訪問）
        ob_clean();
        try {
            if (!$u_ID) {
                json_ok(['announcements' => [], 'total' => 0]);
            }
            
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            $msg_type = $_GET['msg_type'] ?? null; // 可選：篩選類型
            
            // 獲取用戶的屆別、班級、團隊資訊
            $cohort_ID = null;
            $class_ID = null;
            $team_ID = null;
            
            // 獲取屆別和班級（使用 enrollmentdata 表）
            try {
                $stmt = $conn->prepare("
                    SELECT e.cohort_ID, e.class_ID 
                    FROM enrollmentdata e
                    WHERE e.enroll_u_ID = ? AND e.enroll_status = 1
                    ORDER BY e.cohort_ID DESC LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($enrollment) {
                    $cohort_ID = $enrollment['cohort_ID'];
                    $class_ID = $enrollment['class_ID'];
                }
            } catch (Throwable $e) {
                // 如果查詢失敗，繼續執行
            }
            
            // 獲取團隊ID（檢查欄位名稱）
            try {
                // 檢查欄位名稱（兼容不同版本的資料表結構）
                $colsTm = $conn->query("SHOW COLUMNS FROM teammember")->fetchAll(PDO::FETCH_COLUMN);
                $teamUserField = in_array('team_u_ID', $colsTm) ? 'team_u_ID' : 'u_ID';
                
                $stmt = $conn->prepare("
                    SELECT tm.team_ID 
                    FROM teammember tm
                    JOIN teamdata td ON tm.team_ID = td.team_ID
                    WHERE tm.{$teamUserField} = ? AND tm.tm_status = 1 AND td.team_status = 1
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
            
            // 構建查詢條件
            $typeCondition = '';
            if ($msg_type && in_array($msg_type, ['ANNOUNCEMENT', 'SYSTEM_NOTICE', 'REMINDER'])) {
                $typeCondition = "AND m.msg_type = :msg_type";
            }
            
            // 獲取總數
            $countSql = "
                SELECT COUNT(DISTINCT m.msg_ID) as total
                FROM msgdata m
                INNER JOIN msgtargetdata mt ON m.msg_ID = mt.msg_ID
                WHERE m.msg_status = 1
                  AND (m.msg_start_d IS NULL OR m.msg_start_d <= NOW())
                  AND (m.msg_end_d IS NULL OR m.msg_end_d >= NOW())
                  $typeCondition
                  AND (
                      mt.msg_target_type = 'ALL'
                      OR (mt.msg_target_type = 'USER' AND mt.msg_target_ID = :u_ID)
                      OR (mt.msg_target_type = 'COHORT' AND mt.msg_target_ID = :cohort_ID)
                      OR (mt.msg_target_type = 'CLASS' AND mt.msg_target_ID = :class_ID)
                      OR (mt.msg_target_type = 'TEAM' AND mt.msg_target_ID = :team_ID)
                  )
            ";
            $countStmt = $conn->prepare($countSql);
            $countParams = [
                ':u_ID' => $u_ID,
                ':cohort_ID' => $cohort_ID,
                ':class_ID' => $class_ID,
                ':team_ID' => $team_ID
            ];
            if ($msg_type) {
                $countParams[':msg_type'] = $msg_type;
            }
            $countStmt->execute($countParams);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // 獲取列表
            $sql = "
                SELECT DISTINCT
                    m.msg_ID,
                    m.msg_title,
                    m.msg_content,
                    m.msg_url,
                    m.msg_type,
                    m.priority,
                    m.msg_start_d,
                    m.msg_end_d,
                    m.msg_created_d,
                    u.u_name as creator_name
                FROM msgdata m
                INNER JOIN msgtargetdata mt ON m.msg_ID = mt.msg_ID
                LEFT JOIN userdata u ON m.msg_a_u_ID = u.u_ID
                WHERE m.msg_status = 1
                  AND (m.msg_start_d IS NULL OR m.msg_start_d <= NOW())
                  AND (m.msg_end_d IS NULL OR m.msg_end_d >= NOW())
                  $typeCondition
                  AND (
                      mt.msg_target_type = 'ALL'
                      OR (mt.msg_target_type = 'USER' AND mt.msg_target_ID = :u_ID)
                      OR (mt.msg_target_type = 'COHORT' AND mt.msg_target_ID = :cohort_ID)
                      OR (mt.msg_target_type = 'CLASS' AND mt.msg_target_ID = :class_ID)
                      OR (mt.msg_target_type = 'TEAM' AND mt.msg_target_ID = :team_ID)
                  )
                ORDER BY m.priority DESC, m.msg_created_d DESC
                LIMIT :limit OFFSET :offset
            ";
            $stmt = $conn->prepare($sql);
            $params = [
                ':u_ID' => $u_ID,
                ':cohort_ID' => $cohort_ID,
                ':class_ID' => $class_ID,
                ':team_ID' => $team_ID,
                ':limit' => $limit,
                ':offset' => $offset
            ];
            if ($msg_type) {
                $params[':msg_type'] = $msg_type;
            }
            $stmt->execute($params);
            $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 解析 msg_url JSON 和檢查已讀狀態
            foreach ($announcements as &$item) {
                if (!empty($item['msg_url'])) {
                    $item['urls'] = json_decode($item['msg_url'], true) ?: [];
                } else {
                    $item['urls'] = [];
                }
                
                // 檢查是否已讀
                $readStmt = $conn->prepare("SELECT msg_read_d FROM msgreaddata WHERE msg_ID = ? AND read_u_ID = ?");
                $readStmt->execute([$item['msg_ID'], $u_ID]);
                $readRecord = $readStmt->fetch(PDO::FETCH_ASSOC);
                $item['is_read'] = !empty($readRecord);
                $item['read_time'] = $readRecord['msg_read_d'] ?? null;
            }
            
            ob_clean();
            json_ok([
                'announcements' => $announcements,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ]);
            
        } catch (Throwable $e) {
            ob_clean();
            json_err('載入公告列表失敗：' . $e->getMessage());
        }
        break;
        
    case 'mark_announcement_read':
        // 標記公告為已讀
        ob_clean();
        try {
            if (!$u_ID) {
                json_err('請先登入');
            }
            
            $msg_ID = (int)($_GET['msg_ID'] ?? $p['msg_ID'] ?? 0);
            if ($msg_ID <= 0) {
                json_err('無效的公告 ID');
            }
            
            // 檢查是否已存在讀取記錄
            $checkStmt = $conn->prepare("SELECT msg_read_d FROM msgreaddata WHERE msg_ID = ? AND read_u_ID = ?");
            $checkStmt->execute([$msg_ID, $u_ID]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // 更新讀取時間
                $stmt = $conn->prepare("UPDATE msgreaddata SET msg_read_d = NOW() WHERE msg_ID = ? AND read_u_ID = ?");
                $stmt->execute([$msg_ID, $u_ID]);
            } else {
                // 插入新記錄
                $stmt = $conn->prepare("INSERT INTO msgreaddata (msg_ID, read_u_ID, msg_read_d) VALUES (?, ?, NOW())");
                $stmt->execute([$msg_ID, $u_ID]);
            }
            
            ob_clean();
            json_ok(['message' => '已標記為已讀']);
            
        } catch (Throwable $e) {
            ob_clean();
            json_err('標記失敗：' . $e->getMessage());
        }
        break;
        
    case 'notify_publish':
        // 發布公告/通知（僅系辦）
        ob_clean();
        if ((int)$role_ID !== 2) {
            json_err('權限不足，僅限系辦使用');
        }
        
        try {
            $msg_ID = (int)($p['msg_ID'] ?? $_GET['msg_ID'] ?? 0);
            
            if ($msg_ID <= 0) {
                ob_clean();
                json_err('無效的公告 ID');
            }
            
            // 更新狀態為已發布
            $stmt = $conn->prepare("UPDATE msgdata SET msg_status = 1 WHERE msg_ID = ?");
            $stmt->execute([$msg_ID]);
            
            ob_clean();
            json_ok(['message' => '發布成功']);
            
        } catch (Throwable $e) {
            ob_clean();
            json_err('發布失敗：' . $e->getMessage());
        }
        break;
        
    default:
        json_err('未知的操作');
}

