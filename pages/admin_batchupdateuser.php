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
            // 【規則】enrollmentdata 是歷史紀錄表，同一用戶在不同屆別可以有多筆記錄
            // 學生從 110 → 111：保留 110 的記錄（設為 enroll_status=0），新增或更新 111 的記錄（enroll_status=1）
            $cohort_ID = isset($userData['cohort_id']) && $userData['cohort_id'] !== '' ? intval($userData['cohort_id']) : null;
            $c_ID = isset($userData['class_id']) && $userData['class_id'] !== '' ? intval($userData['class_id']) : null;
            $grade = isset($userData['grade']) && $userData['grade'] !== '' ? intval($userData['grade']) : null;

            if ($cohort_ID !== null || $c_ID !== null || $grade !== null) {
                // 確定要使用的 cohort_ID
                if ($cohort_ID !== null && $cohort_ID > 0) {
                    $final_cohort_ID = $cohort_ID;
                } else {
                    $cohortStmt = $conn->query("SELECT cohort_ID FROM cohortdata WHERE cohort_status = 1 ORDER BY cohort_ID DESC LIMIT 1");
                    $cohort = $cohortStmt->fetch(PDO::FETCH_ASSOC);
                    $final_cohort_ID = $cohort ? $cohort['cohort_ID'] : 1;
                }
                
                // 查找使用者當前的 enrollment 記錄（enroll_status=1）
                $stmt = $conn->prepare("SELECT enroll_ID, cohort_ID, class_ID, enroll_grade FROM enrollmentdata WHERE enroll_u_ID = ? AND enroll_status = 1 LIMIT 1");
                $stmt->execute([$u_ID]);
                $currentEnroll = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // 如果要更新 cohort_ID，需要檢查當前屆別的 prosubdata 狀態
                if ($currentEnroll && $final_cohort_ID != $currentEnroll['cohort_ID'] && $final_cohort_ID > $currentEnroll['cohort_ID']) {
                    $teamUserField = 'team_u_ID';
                    $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                    $checkStmt->execute();
                    if (!$checkStmt->fetch()) {
                        $teamUserField = 'u_ID';
                    }
                    
                    $prosubStmt = $conn->prepare("
                        SELECT ps.prosub_status
                        FROM teammember tm
                        INNER JOIN teamdata t ON tm.team_ID = t.team_ID
                        INNER JOIN prosubdata ps ON t.team_ID = ps.team_ID
                        WHERE tm.{$teamUserField} = ?
                          AND t.cohort_ID = ?
                          AND t.team_status = 1
                          AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
                        ORDER BY ps.prosub_created_d DESC
                        LIMIT 1
                    ");
                    $prosubStmt->execute([$u_ID, $currentEnroll['cohort_ID']]);
                    $prosubResult = $prosubStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($prosubResult) {
                        $prosubStatus = (int)$prosubResult['prosub_status'];
                        // 【規則】prosub_status：3=已結案（不允許下一屆）、2=不通過異常（允許下一屆）。先只管這兩個。
                        if ($prosubStatus === 3) {
                            $errors[] = "使用者 {$u_ID}: 該學生在 {$currentEnroll['cohort_ID']} 屆的專題已結案（狀態=3），無法更新到下一屆";
                            continue; // 跳過這個用戶
                        }
                        // 2=不通過異常 或其他狀態 → 允許更新到下一屆
                    }
                }
                
                // 如果用戶要更新到新的屆別，處理歷史記錄
                if ($currentEnroll && $final_cohort_ID != $currentEnroll['cohort_ID']) {
                    // 1. 將舊的記錄設為停用（保留歷史記錄）
                    $stmt = $conn->prepare("UPDATE enrollmentdata SET enroll_status = 0 WHERE enroll_ID = ?");
                    $stmt->execute([$currentEnroll['enroll_ID']]);
                    
                    // 2. 檢查新屆別是否已有記錄
                    $stmt = $conn->prepare("SELECT enroll_ID FROM enrollmentdata WHERE enroll_u_ID = ? AND cohort_ID = ? LIMIT 1");
                    $stmt->execute([$u_ID, $final_cohort_ID]);
                    $existingNewEnroll = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existingNewEnroll) {
                        // 如果已有新屆別的記錄，UPDATE 它
                        $updateFields = [];
                        $updateParams = [];
                        
                        if ($c_ID !== null) {
                            $updateFields[] = "class_ID = ?";
                            $updateParams[] = $c_ID > 0 ? $c_ID : null;
                        }
                        
                        if ($grade !== null) {
                            $updateFields[] = "enroll_grade = ?";
                            $updateParams[] = $grade > 0 ? $grade : null;
                        }
                        
                        $updateFields[] = "enroll_status = 1";
                        
                        if (!empty($updateFields)) {
                            $updateParams[] = $existingNewEnroll['enroll_ID'];
                            $stmt = $conn->prepare("UPDATE enrollmentdata SET " . implode(', ', $updateFields) . " WHERE enroll_ID = ?");
                            $stmt->execute($updateParams);
                        }
                    } else {
                        // 如果沒有新屆別的記錄，INSERT 新記錄
                        $stmt = $conn->prepare("INSERT INTO enrollmentdata (enroll_u_ID, cohort_ID, class_ID, enroll_grade, enroll_status, enroll_created_d) VALUES (?,?,?,?,1,NOW())");
                        $stmt->execute([
                            $u_ID, 
                            $final_cohort_ID, 
                            ($c_ID !== null && $c_ID > 0) ? $c_ID : null,
                            ($grade !== null && $grade > 0) ? $grade : null
                        ]);
                    }
                } elseif ($currentEnroll) {
                    // 如果沒有改變屆別，只更新其他欄位
                    $updateFields = [];
                    $updateParams = [];
                    
                    if ($c_ID !== null) {
                        $updateFields[] = "class_ID = ?";
                        $updateParams[] = $c_ID > 0 ? $c_ID : null;
                    }
                    
                    if ($grade !== null) {
                        $updateFields[] = "enroll_grade = ?";
                        $updateParams[] = $grade > 0 ? $grade : null;
                    }
                    
                    if (!empty($updateFields)) {
                        $updateParams[] = $currentEnroll['enroll_ID'];
                        $stmt = $conn->prepare("UPDATE enrollmentdata SET " . implode(', ', $updateFields) . " WHERE enroll_ID = ?");
                        $stmt->execute($updateParams);
                    }
                } else {
                    // 如果完全沒有 enrollment 記錄，建立新記錄（新用戶）
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
