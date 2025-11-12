<?php
session_start();
require '../includes/pdo.php';

// 檢查權限
$role_ID = $_SESSION['role_ID'] ?? null;
if (!in_array($role_ID, [1, 2])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '您沒有權限訪問此頁面']);
    exit;
}

header('Content-Type: application/json');

try {
    $conn->beginTransaction();

    // 獲取使用者ID列表
    $u_IDs = $_POST['u_IDs'] ?? '';
    if (!$u_IDs) {
        throw new Exception('缺少使用者ID參數');
    }

    $userIds = explode(',', $u_IDs);
    $updatedCount = 0;
    $errors = [];

    foreach ($userIds as $u_ID) {
        $u_ID = trim($u_ID);
        if (!$u_ID) continue;

        try {
            // 獲取該使用者的資料
            $users = $_POST['users'] ?? [];
            if (!isset($users[$u_ID])) {
                continue;
            }

            $userData = $users[$u_ID];
            
            // 更新基本資料
            $updateFields = [];
            $updateParams = [];

            if (isset($userData['name']) && $userData['name'] !== '') {
                $updateFields[] = "u_name = ?";
                $updateParams[] = trim($userData['name']);
            }

            if (isset($userData['gmail'])) {
                $updateFields[] = "u_gmail = ?";
                $updateParams[] = trim($userData['gmail']);
            }

            if (isset($userData['profile'])) {
                $updateFields[] = "u_profile = ?";
                $updateParams[] = trim($userData['profile']);
            }

            if (isset($userData['password']) && $userData['password'] !== '') {
                $updateFields[] = "u_password = ?";
                $updateParams[] = trim($userData['password']); // 注意：這裡應該要加密
            }

            if (isset($userData['status_id']) && $userData['status_id'] !== '') {
                $updateFields[] = "u_status = ?";
                $updateParams[] = intval($userData['status_id']);
            }

            if (!empty($updateFields)) {
                $updateParams[] = $u_ID;
                $stmt = $conn->prepare("UPDATE userdata SET " . implode(', ', $updateFields) . " WHERE u_ID = ?");
                $stmt->execute($updateParams);
            }

            // 處理頭貼
            if (isset($_FILES['users']['tmp_name'][$u_ID]['avatar']) && $_FILES['users']['tmp_name'][$u_ID]['avatar']) {
                $avatarFile = $_FILES['users']['tmp_name'][$u_ID]['avatar'];
                $avatarName = $_FILES['users']['name'][$u_ID]['avatar'];
                $avatarSize = $_FILES['users']['size'][$u_ID]['avatar'];
                
                if ($avatarSize > 0 && $avatarSize <= 5 * 1024 * 1024) {
                    $ext = pathinfo($avatarName, PATHINFO_EXTENSION);
                    $newFileName = $u_ID . '_' . time() . '.' . $ext;
                    $uploadPath = '../headshot/' . $newFileName;
                    
                    if (move_uploaded_file($avatarFile, $uploadPath)) {
                        // 刪除舊頭貼
                        $stmt = $conn->prepare("SELECT u_img FROM userdata WHERE u_ID = ?");
                        $stmt->execute([$u_ID]);
                        $oldImg = $stmt->fetchColumn();
                        if ($oldImg && file_exists('../headshot/' . $oldImg)) {
                            unlink('../headshot/' . $oldImg);
                        }
                        
                        $stmt = $conn->prepare("UPDATE userdata SET u_img = ? WHERE u_ID = ?");
                        $stmt->execute([$newFileName, $u_ID]);
                    }
                }
            } elseif (isset($userData['clear_avatar']) && $userData['clear_avatar'] === '1') {
                $stmt = $conn->prepare("SELECT u_img FROM userdata WHERE u_ID = ?");
                $stmt->execute([$u_ID]);
                $oldImg = $stmt->fetchColumn();
                if ($oldImg && file_exists('../headshot/' . $oldImg)) {
                    unlink('../headshot/' . $oldImg);
                }
                $stmt = $conn->prepare("UPDATE userdata SET u_img = NULL WHERE u_ID = ?");
                $stmt->execute([$u_ID]);
            }

            // 更新角色
            if (isset($userData['role_id']) && $userData['role_id'] !== '') {
                $role_ID_update = intval($userData['role_id']);
                
                // 先停用所有現有角色
                $stmt = $conn->prepare("UPDATE userrolesdata SET user_role_status = 0 WHERE ur_u_ID = ?");
                $stmt->execute([$u_ID]);
                
                // 檢查是否已有該角色
                $stmt = $conn->prepare("SELECT * FROM userrolesdata WHERE ur_u_ID = ? AND role_ID = ?");
                $stmt->execute([$u_ID, $role_ID_update]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    // 啟用現有角色
                    $stmt = $conn->prepare("UPDATE userrolesdata SET user_role_status = 1 WHERE ur_u_ID = ? AND role_ID = ?");
                    $stmt->execute([$u_ID, $role_ID_update]);
                } else {
                    // 建立新角色關聯
                    $stmt = $conn->prepare("INSERT INTO userrolesdata (ur_u_ID, role_ID, user_role_status) VALUES (?,?,1)");
                    $stmt->execute([$u_ID, $role_ID_update]);
                }
            }

            // 更新學籍資料
            $cohort_ID = isset($userData['cohort_id']) && $userData['cohort_id'] !== '' ? intval($userData['cohort_id']) : null;
            $c_ID = isset($userData['class_id']) && $userData['class_id'] !== '' ? intval($userData['class_id']) : null;
            $grade = isset($userData['grade']) && $userData['grade'] !== '' ? intval($userData['grade']) : null;

            if ($cohort_ID !== null || $c_ID !== null || $grade !== null) {
                // 查找現有 enrollment 記錄
                $stmt = $conn->prepare("SELECT enroll_ID, cohort_ID, class_ID, enroll_grade FROM enrollmentdata WHERE enroll_u_ID = ? AND enroll_status = 1 ORDER BY enroll_created_d DESC LIMIT 1");
                $stmt->execute([$u_ID]);
                $enroll = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // 確定要使用的 cohort_ID
                if ($cohort_ID !== null && $cohort_ID > 0) {
                    $final_cohort_ID = $cohort_ID;
                } elseif ($enroll && $enroll['cohort_ID']) {
                    $final_cohort_ID = $enroll['cohort_ID'];
                } else {
                    $cohortStmt = $conn->query("SELECT cohort_ID FROM cohortdata WHERE cohort_status = 1 ORDER BY cohort_ID DESC LIMIT 1");
                    $cohort = $cohortStmt->fetch(PDO::FETCH_ASSOC);
                    $final_cohort_ID = $cohort ? $cohort['cohort_ID'] : 1;
                }
                
                if ($enroll) {
                    // 更新現有記錄
                    $updateFields = [];
                    $updateParams = [];
                    
                    if ($c_ID !== null) {
                        $updateFields[] = "class_ID = ?";
                        $updateParams[] = $c_ID > 0 ? $c_ID : null;
                    }
                    
                    if ($cohort_ID !== null && $cohort_ID > 0) {
                        $updateFields[] = "cohort_ID = ?";
                        $updateParams[] = $cohort_ID;
                    }
                    
                    if ($grade !== null) {
                        $updateFields[] = "enroll_grade = ?";
                        $updateParams[] = $grade > 0 ? $grade : null;
                    }
                    
                    if (!empty($updateFields)) {
                        $updateParams[] = $enroll['enroll_ID'];
                        $stmt = $conn->prepare("UPDATE enrollmentdata SET " . implode(', ', $updateFields) . " WHERE enroll_ID = ?");
                        $stmt->execute($updateParams);
                    }
                } else {
                    // 建立新記錄
                    if ($final_cohort_ID) {
                        $stmt = $conn->prepare("INSERT INTO enrollmentdata (enroll_u_ID, cohort_ID, class_ID, enroll_grade, enroll_status, enroll_created_d) VALUES (?,?,?,?,1,NOW())");
                        $stmt->execute([
                            $u_ID, 
                            $final_cohort_ID, 
                            ($c_ID !== null && $c_ID > 0) ? $c_ID : null,
                            ($grade !== null && $grade > 0) ? $grade : null
                        ]);
                    }
                }
            }

            $updatedCount++;
        } catch (Exception $e) {
            $errors[] = "使用者 {$u_ID}: " . $e->getMessage();
        }
    }

    $conn->commit();
    
    $message = "成功批量修改 {$updatedCount} 位使用者的資料";
    if (!empty($errors)) {
        $message .= "，但有 " . count($errors) . " 個錯誤：" . implode('; ', $errors);
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '批量修改失敗：' . $e->getMessage()
    ]);
}
?>
