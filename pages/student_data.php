<?php
session_start();
require '../includes/pdo.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['u_ID'])) {
  echo json_encode(['success' => false, 'msg' => 'no login']);
  exit;
}

$uid = $_SESSION['u_ID'];
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 檢查 teammember 表使用哪個欄位名稱
$teamUserField = 'u_ID';
try {
  $checkStmt = $conn->query("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
  if ($checkStmt->rowCount() > 0) {
    $teamUserField = 'team_u_ID';
  }
} catch (Exception $e) {
  error_log("檢查 teammember 欄位失敗: " . $e->getMessage());
}

// 檢查 userrolesdata 表使用哪個欄位名稱
$userRoleUidField = 'u_ID';
try {
  $checkStmt = $conn->query("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'");
  if ($checkStmt->rowCount() > 0) {
    $userRoleUidField = 'ur_u_ID';
  }
} catch (Exception $e) {
  error_log("檢查 userrolesdata 欄位失敗: " . $e->getMessage());
}

try {
  // 取得當前用戶的組別ID
  $stTeam = $conn->prepare("
    SELECT team_ID 
    FROM teammember 
    WHERE {$teamUserField} = ? 
    AND (tm_status IS NULL OR tm_status = 1)
    ORDER BY tm_updated_d DESC
    LIMIT 1
  ");
  $stTeam->execute([$uid]);
  $teamId = $stTeam->fetchColumn();

  if (!$teamId) {
    echo json_encode([
      'success' => false,
      'msg' => 'no_team',
      'teamName' => null,
      'members' => []
    ]);
    exit;
  }

  // 取得組別名稱
  $stName = $conn->prepare("
    SELECT COALESCE(team_project_name, CONCAT('Team ', :tid)) as team_name
    FROM teamdata 
    WHERE team_ID = :tid AND team_status = 1
  ");
  $stName->execute([':tid' => $teamId]);
  $teamResult = $stName->fetch(PDO::FETCH_ASSOC);
  $teamName = $teamResult ? ($teamResult['team_name'] ?: "Team {$teamId}") : "Team {$teamId}";

  // 取得組別成員（僅學生角色，排除自己）
  $stMembers = $conn->prepare("
    SELECT 
      u.u_ID,
      u.u_name,
      u.u_gmail,
      u.u_img,
      u.u_profile
    FROM teammember tm
    JOIN userdata u ON tm.{$teamUserField} = u.u_ID
    JOIN userrolesdata ur ON ur.{$userRoleUidField} = u.u_ID
    WHERE tm.team_ID = ? 
    AND ur.role_ID = 6 
    AND ur.user_role_status = 1
    AND (tm.tm_status IS NULL OR tm.tm_status = 1)
    AND u.u_ID != ?
    ORDER BY u.u_ID ASC
  ");
  $stMembers->execute([$teamId, $uid]);
  $members = $stMembers->fetchAll(PDO::FETCH_ASSOC);

  // 處理成員數據
  $memberList = [];
  foreach ($members as $member) {
    $memberList[] = [
      'u_ID' => $member['u_ID'],
      'u_name' => $member['u_name'] ?: $member['u_ID'],
      'u_email' => $member['u_gmail'] ?: '',
      'u_img' => $member['u_img'] ?: null,
      'u_profile' => $member['u_profile'] ?: ''
    ];
  }

  // 取得指導老師（role_ID = 4）
  $stTeachers = $conn->prepare("
    SELECT 
      u.u_ID,
      u.u_name,
      u.u_gmail,
      u.u_img,
      u.u_profile
    FROM teammember tm
    JOIN userdata u ON tm.{$teamUserField} = u.u_ID
    JOIN userrolesdata ur ON ur.{$userRoleUidField} = u.u_ID
    WHERE tm.team_ID = ? 
    AND ur.role_ID = 4 
    AND ur.user_role_status = 1
    AND (tm.tm_status IS NULL OR tm.tm_status = 1)
    ORDER BY u.u_ID ASC
  ");
  $stTeachers->execute([$teamId]);
  $teachers = $stTeachers->fetchAll(PDO::FETCH_ASSOC);

  // 處理指導老師數據
  $teacherList = [];
  foreach ($teachers as $teacher) {
    $teacherList[] = [
      'u_ID' => $teacher['u_ID'],
      'u_name' => $teacher['u_name'] ?: $teacher['u_ID'],
      'u_email' => $teacher['u_gmail'] ?: '',
      'u_img' => $teacher['u_img'] ?: null,
      'u_profile' => $teacher['u_profile'] ?: ''
    ];
  }

  echo json_encode([
    'success' => true,
    'teamName' => $teamName,
    'teamId' => $teamId,
    'members' => $memberList,
    'teachers' => $teacherList
  ]);

} catch (Exception $e) {
  error_log("student_data.php 錯誤: " . $e->getMessage());
  echo json_encode([
    'success' => false,
    'msg' => 'error: ' . $e->getMessage()
  ]);
}
?>

