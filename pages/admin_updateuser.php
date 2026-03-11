<?php
// pages/admin_updateuser.php
session_start();
require_once '../includes/pdo.php';

// 檢查權限（主任 role_ID = 1 和 科辦 role_ID = 2）
$role_ID = $_SESSION['role_ID'] ?? null;
if (!isset($role_ID) || !in_array($role_ID, [1, 2])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => '無權限訪問']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new Exception('Invalid method');
  }

  $u_ID     = $_POST['u_ID'] ?? '';
  if ($u_ID === '') throw new Exception('缺少使用者ID');

  // 先查舊頭貼
  $stmt = $conn->prepare("SELECT u_img FROM userdata WHERE u_ID = ?");
  $stmt->execute([$u_ID]);
  $current = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$current) throw new Exception('找不到使用者');

  // 取表單值（與前端名稱對齊）
  $u_name   = trim($_POST['name'] ?? '');
  $u_gmail  = trim($_POST['gmail'] ?? '');
  $u_profile= trim($_POST['profile'] ?? '');
  $c_ID     = isset($_POST['class_id'])  ? intval($_POST['class_id'])  : null;
  $cohort_ID= isset($_POST['cohort_id'])  ? intval($_POST['cohort_id'])  : null;
  $grade    = isset($_POST['grade'])      ? intval($_POST['grade'])     : null;
  $role_ID  = isset($_POST['role_id'])   ? intval($_POST['role_id'])   : null;
  $u_status = isset($_POST['status_id']) ? intval($_POST['status_id']) : null;
  $password = trim($_POST['password'] ?? '');
  $clear    = ($_POST['clear_avatar'] ?? '0') === '1';

  // 頭貼處理
  $new_img = null;
  if ($clear) {
    // 清除
    $new_img = null;
  } elseif (!empty($_FILES['avatar']['name'])) {
    // 上傳新檔
    $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
      throw new Exception('頭貼只接受 jpg / png / webp');
    }
    // 確保檔名沒有空格和特殊字元
    $safeUid = preg_replace('/[^A-Za-z0-9_\-]/', '', $u_ID);
    $new_img = 'u_img_' . $safeUid . '_' . time() . '.' . $ext;
    $destDir = dirname(__DIR__) . '/headshot';
    if (!is_dir($destDir)) mkdir($destDir, 0775, true);
    $destPath = $destDir . '/' . $new_img;
    if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $destPath)) {
      throw new Exception('頭貼上傳失敗');
    }
  }

  // 組更新 SQL
  $sets = [];
  $params = [];

  if ($u_name !== '')         { $sets[]='u_name = ?';     $params[]=$u_name; }
  $sets[] = 'u_gmail = ?';      $params[] = $u_gmail;
  $sets[] = 'u_profile = ?';    $params[] = $u_profile;
  // 注意：userdata 表中沒有 class_ID 欄位，班級通過 enrollmentdata 表管理（見下方處理）
  if ($u_status !== null)     { $sets[]='u_status = ?';   $params[]=$u_status; }
  if ($password !== '')       { $sets[]='u_password = ?'; $params[]=$password; } // 你原本就是明碼，維持不動
  if ($clear)                 { $sets[]='u_img = NULL'; }
  elseif ($new_img)           { $sets[]='u_img = ?';      $params[]=$new_img; }

  $conn->beginTransaction();

  if ($sets) {
    $sql = "UPDATE userdata SET ".implode(',', $sets)." WHERE u_ID = ?";
    $params[] = $u_ID;
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
  }

  // 角色關聯（單一角色）
  if ($role_ID !== null) {
    // 先檢查該用戶是否已經有這個 role_ID 的記錄
    $stmt = $conn->prepare("SELECT COUNT(*) FROM userrolesdata WHERE ur_u_ID = ? AND role_ID = ?");
    $stmt->execute([$u_ID, $role_ID]);
    $roleExists = $stmt->fetchColumn() > 0;

    if ($roleExists) {
      // 如果該角色記錄已存在，只更新狀態為啟用
      $stmt = $conn->prepare("UPDATE userrolesdata SET user_role_status = 1 WHERE ur_u_ID = ? AND role_ID = ?");
      $stmt->execute([$u_ID, $role_ID]);
      
      // 將該用戶的其他角色設為停用（確保只有一個啟用角色）
      $stmt = $conn->prepare("UPDATE userrolesdata SET user_role_status = 0 WHERE ur_u_ID = ? AND role_ID != ?");
      $stmt->execute([$u_ID, $role_ID]);
    } else {
      // 如果該角色記錄不存在，先將該用戶所有角色設為停用
      $stmt = $conn->prepare("UPDATE userrolesdata SET user_role_status = 0 WHERE ur_u_ID = ?");
      $stmt->execute([$u_ID]);
      
      // 然後插入新角色關聯
      $stmt = $conn->prepare("INSERT INTO userrolesdata (ur_u_ID, role_ID, user_role_status) VALUES (?,?,1)");
      $stmt->execute([$u_ID, $role_ID]);
    }
  }
  
  // 學籍關聯處理（班級、學級、年級）
  // 【規則】enrollmentdata 是歷史紀錄表，同一用戶在不同屆別可以有多筆記錄
  // 學生從 110 → 111：保留 110 的記錄（設為 enroll_status=0），新增或更新 111 的記錄（enroll_status=1）
  
  // 確定要使用的 cohort_ID（優先使用表單提交的值）
  if ($cohort_ID !== null && $cohort_ID > 0) {
    $final_cohort_ID = $cohort_ID;
  } else {
    // 獲取當前啟用的 cohort（或最新的 cohort）
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
    // 檢查該用戶在當前屆別的 prosubdata 狀態
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
        throw new Exception("該學生在 {$currentEnroll['cohort_ID']} 屆的專題已結案（狀態=3），無法更新到下一屆");
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

  $conn->commit();

  // 刪除舊圖（若清除或替換）
  if (($clear || $new_img) && !empty($current['u_img'])) {
    @unlink(dirname(__DIR__).'/headshot/'.$current['u_img']);
  }

  echo json_encode(['ok'=>true, 'msg'=>'更新完成']);
} catch (Throwable $e) {
  if ($conn && $conn->inTransaction()) $conn->rollBack();
  http_response_code(400);
  echo json_encode(['ok'=>false, 'msg'=>$e->getMessage()]);
}
