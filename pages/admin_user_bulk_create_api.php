<?php
// 批量匯入帳號 API
// 依序處理：userdata → userrolesdata → enrollmentdata

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $conn;

if (!isset($conn) || !$conn) {
    json_err('資料庫連線失敗');
}

$role_ID = $_SESSION['role_ID'] ?? 0;
if (!in_array($role_ID, [1, 2])) {
    json_err('此功能僅限主任與科辦使用');
}

$data = read_json_body();
$rows = $data['users'] ?? null;

if (!is_array($rows)) {
    json_err('缺少 users 陣列');
}

$success = 0;
$failed = 0;
$skippedExisting = 0;
$details = [];

foreach ($rows as $idx => $row) {
    $lineNo = $idx + 1;

    $u_id    = trim($row['u_id']    ?? '');
    $u_name  = trim($row['u_name']  ?? '');
    $u_gmail = trim($row['u_gmail'] ?? '');
    $u_status= $row['u_status']     ?? 1;

    $roleKey    = trim($row['role_key']    ?? '');
    $cohortKey  = trim($row['cohort_key']  ?? '');
    $classKey   = trim($row['class_key']   ?? '');
    $gradeRaw   = trim($row['enroll_grade'] ?? '');
    $enrollGrade= $gradeRaw === '' ? null : (int)$gradeRaw;

    if ($u_id === '' || $u_name === '') {
        $failed++;
        $details[] = "第 {$lineNo} 行：帳號或姓名為空，已略過";
        continue;
    }

    try {
        // 檢查帳號是否已存在
        $stmt = $conn->prepare("SELECT COUNT(*) FROM userdata WHERE u_ID = ?");
        $stmt->execute([$u_id]);
        if ($stmt->fetchColumn() > 0) {
            $skippedExisting++;
            $details[] = "第 {$lineNo} 行：帳號 {$u_id} 已存在，略過";
            continue;
        }

        // 1️⃣ 新增 userdata
        $defaultPwHash = password_hash($u_id, PASSWORD_DEFAULT);

        $sqlUser = "INSERT INTO userdata
                (u_ID, u_pw, u_name, u_gmail, u_status, u_created_d)
            VALUES
                (:id, :pw, :name, :gmail, :status, NOW())";
        $stmtUser = $conn->prepare($sqlUser);
        $okUser = $stmtUser->execute([
            ':id'     => $u_id,
            ':pw'     => $defaultPwHash,
            ':name'   => $u_name,
            ':gmail'  => $u_gmail,
            ':status' => $u_status ?: 1,
        ]);

        if (!$okUser) {
            $failed++;
            $details[] = "第 {$lineNo} 行：新增 userdata 失敗";
            continue;
        }

        // 2️⃣ 設定角色（userrolesdata）
        if ($roleKey !== '') {
            $roleID = null;

            if (ctype_digit($roleKey)) {
                // 直接當 role_ID
                $stmtRole = $conn->prepare("SELECT role_ID FROM roledata WHERE role_ID = ? AND role_status = 1");
                $stmtRole->execute([$roleKey]);
                $rowRole = $stmtRole->fetch(PDO::FETCH_ASSOC);
                if ($rowRole) {
                    $roleID = (int)$rowRole['role_ID'];
                }
            } else {
                // 用名稱找
                $stmtRole = $conn->prepare("SELECT role_ID FROM roledata WHERE role_name = ? AND role_status = 1");
                $stmtRole->execute([$roleKey]);
                $rowRole = $stmtRole->fetch(PDO::FETCH_ASSOC);
                if ($rowRole) {
                    $roleID = (int)$rowRole['role_ID'];
                }
            }

            if ($roleID !== null) {
                // 先把原本該 user 的同角色設為停用，再新增 / 啟用
                $conn->prepare("UPDATE userrolesdata 
                                SET user_role_status = 0 
                                WHERE ur_u_ID = ? AND role_ID = ?")
                     ->execute([$u_id, $roleID]);

                $sqlUr = "INSERT INTO userrolesdata (ur_u_ID, role_ID, user_role_status)
                          VALUES (:uid, :rid, 1)";
                $stmtUr = $conn->prepare($sqlUr);
                $stmtUr->execute([
                    ':uid' => $u_id,
                    ':rid' => $roleID,
                ]);
            } else {
                $details[] = "第 {$lineNo} 行：找不到角色「{$roleKey}」，略過角色設定";
            }
        }

        // 3️⃣ 建立 enrollmentdata（屆別 / 班級）
        // 【規則】同一個 enroll_u_ID 在 enrollmentdata 永遠只允許 1 筆資料
        // 學生從 110 → 111：只能 UPDATE，不能 INSERT
        if ($cohortKey !== '' || $classKey !== '' || $enrollGrade !== null) {
            $cohortID = null;
            $classID  = null;

            if ($cohortKey !== '') {
                if (ctype_digit($cohortKey)) {
                    $stmtCh = $conn->prepare("SELECT cohort_ID FROM cohortdata WHERE cohort_ID = ?");
                    $stmtCh->execute([$cohortKey]);
                    $rowCh = $stmtCh->fetch(PDO::FETCH_ASSOC);
                    if ($rowCh) $cohortID = (int)$rowCh['cohort_ID'];
                } else {
                    $stmtCh = $conn->prepare("SELECT cohort_ID FROM cohortdata WHERE cohort_name = ?");
                    $stmtCh->execute([$cohortKey]);
                    $rowCh = $stmtCh->fetch(PDO::FETCH_ASSOC);
                    if ($rowCh) $cohortID = (int)$rowCh['cohort_ID'];
                }
            }

            if ($classKey !== '') {
                if (ctype_digit($classKey)) {
                    $stmtCl = $conn->prepare("SELECT c_ID FROM classdata WHERE c_ID = ?");
                    $stmtCl->execute([$classKey]);
                    $rowCl = $stmtCl->fetch(PDO::FETCH_ASSOC);
                    if ($rowCl) $classID = (int)$rowCl['c_ID'];
                } else {
                    $stmtCl = $conn->prepare("SELECT c_ID FROM classdata WHERE c_name = ?");
                    $stmtCl->execute([$classKey]);
                    $rowCl = $stmtCl->fetch(PDO::FETCH_ASSOC);
                    if ($rowCl) $classID = (int)$rowCl['c_ID'];
                }
            }

            // 檢查是否已有 enrollmentdata 記錄（不限制 enroll_status，因為每個用戶只有1筆）
            $stmtEnroll = $conn->prepare("SELECT enroll_ID FROM enrollmentdata WHERE enroll_u_ID = ? LIMIT 1");
            $stmtEnroll->execute([$u_id]);
            $existingEnroll = $stmtEnroll->fetch(PDO::FETCH_ASSOC);

            if ($existingEnroll) {
                // 【規則】如果已有記錄，更新現有記錄（不新增）
                $updateFields = [];
                $updateParams = [];

                if ($cohortID !== null) {
                    $updateFields[] = "cohort_ID = ?";
                    $updateParams[] = $cohortID;
                }

                if ($classID !== null) {
                    $updateFields[] = "class_ID = ?";
                    $updateParams[] = $classID;
                }

                if ($enrollGrade !== null) {
                    $updateFields[] = "enroll_grade = ?";
                    $updateParams[] = $enrollGrade;
                }

                // 確保 enroll_status = 1（啟用狀態）
                $updateFields[] = "enroll_status = 1";

                if (!empty($updateFields)) {
                    $updateParams[] = $existingEnroll['enroll_ID'];
                    $sqlEn = "UPDATE enrollmentdata SET " . implode(', ', $updateFields) . " WHERE enroll_ID = ?";
                    $stmtEn = $conn->prepare($sqlEn);
                    $stmtEn->execute($updateParams);
                }
            } else {
                // 【規則】只有在完全沒有 enrollment 記錄時，才建立新記錄（新用戶）
                $sqlEn = "INSERT INTO enrollmentdata
                            (enroll_u_ID, cohort_ID, class_ID, enroll_grade, enroll_status, enroll_created_d)
                          VALUES
                            (:uid, :cohort, :class, :grade, 1, NOW())";
                $stmtEn = $conn->prepare($sqlEn);
                $stmtEn->execute([
                    ':uid'    => $u_id,
                    ':cohort' => $cohortID,
                    ':class'  => $classID,
                    ':grade'  => $enrollGrade,
                ]);
            }
        }

        $success++;

    } catch (Exception $e) {
        $failed++;
        $details[] = "第 {$lineNo} 行：例外錯誤 - " . $e->getMessage();
    }
}

json_ok([
    'msg'              => '批量匯入完成',
    'success'          => $success,
    'failed'           => $failed,
    'skipped_existing' => $skippedExisting,
    'details'          => $details, // 之後如果要顯示詳細錯誤可以用
]);
