<?php
session_start();
require '../includes/pdo.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['u_ID'])) {
  echo json_encode(['success' => false, 'msg' => '尚未登入']);
  exit;
}

// 檢查權限：只有班導師可以訪問
$role_ID = $_SESSION['role_ID'] ?? null;
if ($role_ID != 3) {
  echo json_encode(['success' => false, 'msg' => '無權限']);
  exit;
}

$uid = $_SESSION['u_ID'];
$cohort_ID = $_SESSION['cohort_ID'] ?? null; // 獲取當前選擇的屆別
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 檢查欄位名稱
$teamUserField = 'u_ID';
$userRoleUidField = 'u_ID';

try {
  $checkStmt = $conn->query("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
  if ($checkStmt->rowCount() > 0) {
    $teamUserField = 'team_u_ID';
  }
} catch (Exception $e) {
  error_log("檢查 teammember 欄位失敗: " . $e->getMessage());
}

try {
  $checkStmt = $conn->query("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'");
  if ($checkStmt->rowCount() > 0) {
    $userRoleUidField = 'ur_u_ID';
  }
} catch (Exception $e) {
  error_log("檢查 userrolesdata 欄位失敗: " . $e->getMessage());
}

try {
  // 取得班導師管理的屆別：優先使用 session 的 cohort_ID，否則從 enrollment 取一屆
  $cohort_ID = $_SESSION['cohort_ID'] ?? null;
  if ($cohort_ID === null || $cohort_ID === '') {
    $stmt = $conn->prepare("
      SELECT DISTINCT e.cohort_ID
      FROM enrollmentdata e
      INNER JOIN cohortdata c ON c.cohort_ID = e.cohort_ID
      WHERE e.enroll_u_ID = ? AND e.enroll_status = 1 AND e.role_ID = 3 AND c.cohort_status = 1
      LIMIT 1
    ");
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cohort_ID = $row ? (int)$row['cohort_ID'] : null;
  } else {
    $cohort_ID = (int)$cohort_ID;
  }

  if ($cohort_ID === null) {
    echo json_encode([
      'success' => true,
      'no_class' => true,
      'unjoined_count' => 0,
      'total_students' => 0,
      'groups' => [],
      'all_joined' => true,
      'msg' => '尚無可用的專題屆別'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // 班導師管理的班級（保留供日後若需依班級篩選時使用，統計改為該屆全部）
  $sql = "
    SELECT DISTINCT e.class_ID
    FROM enrollmentdata e
    INNER JOIN cohortdata c ON c.cohort_ID = e.cohort_ID
    WHERE e.enroll_u_ID = ?
      AND e.enroll_status = 1
      AND e.role_ID = 3
      AND c.cohort_status = 1
      AND e.cohort_ID = ?
  ";
  $stmt = $conn->prepare($sql);
  $stmt->execute([$uid, $cohort_ID]);
  $classIds = array_values(array_filter(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'class_ID')));
  $placeholders = count($classIds) > 0 ? implode(',', array_fill(0, count($classIds), '?')) : '0';
  $classPlaceholders = $placeholders;

  // 獲取該屆全部學生（role_ID = 6），用於統計與比例（含班級名稱供未加入名單顯示）
  $sql = "
    SELECT DISTINCT u.u_ID, u.u_name, u.u_img,
      (SELECT c.c_name FROM enrollmentdata e2 
       JOIN classdata c ON c.c_ID = e2.class_ID 
       WHERE e2.enroll_u_ID = u.u_ID AND e2.cohort_ID = ? AND e2.enroll_status = 1 
       LIMIT 1) as class_name
    FROM userdata u
    JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID
    JOIN cohortdata c ON c.cohort_ID = e.cohort_ID
    JOIN userrolesdata ur ON ur.{$userRoleUidField} = u.u_ID
    WHERE e.cohort_ID = ?
      AND e.enroll_status = 1
      AND c.cohort_status = 1
      AND ur.role_ID = 6
      AND ur.user_role_status = 1
      AND u.u_status = 1
    ORDER BY u.u_ID
  ";
  $stmt = $conn->prepare($sql);
  $stmt->execute([$cohort_ID, $cohort_ID]);
  $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $totalStudents = count($allStudents);

  // 獲取該屆已加入團隊的學生
  $sql = "
    SELECT DISTINCT tm.{$teamUserField} as u_ID
    FROM teammember tm
    JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField}
    JOIN cohortdata c ON c.cohort_ID = e.cohort_ID
    JOIN teamdata t ON t.team_ID = tm.team_ID AND t.cohort_ID = ?
    JOIN userrolesdata ur ON ur.{$userRoleUidField} = tm.{$teamUserField}
    JOIN userdata u ON u.u_ID = tm.{$teamUserField}
    WHERE e.cohort_ID = ?
      AND e.enroll_status = 1
      AND c.cohort_status = 1
      AND t.team_status = 1
      AND (tm.tm_status IS NULL OR tm.tm_status = 1)
      AND ur.role_ID = 6
      AND ur.user_role_status = 1
      AND u.u_status = 1
  ";
  $stmt = $conn->prepare($sql);
  $stmt->execute([$cohort_ID, $cohort_ID]);
  $joinedStudentIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'u_ID');
  $joinedCount = count($joinedStudentIds);
  $unjoinedCount = max(0, $totalStudents - $joinedCount);

  // 獲取該屆各類組的統計（該屆全部學生）
  $sql = "
    SELECT 
      g.group_ID,
      g.group_name,
      COUNT(DISTINCT tm.{$teamUserField}) as student_count
    FROM groupdata g
    INNER JOIN teamdata t ON t.group_ID = g.group_ID AND t.team_status = 1 AND t.cohort_ID = ?
    INNER JOIN teammember tm ON tm.team_ID = t.team_ID
      AND (tm.tm_status IS NULL OR tm.tm_status = 1)
    INNER JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField}
      AND e.enroll_status = 1 AND e.cohort_ID = ?
    INNER JOIN cohortdata c ON c.cohort_ID = e.cohort_ID
      AND c.cohort_status = 1
    INNER JOIN userrolesdata ur ON ur.{$userRoleUidField} = tm.{$teamUserField}
      AND ur.role_ID = 6
      AND ur.user_role_status = 1
    WHERE g.group_status = 1
      AND g.group_name <> 'ukn'
    GROUP BY g.group_ID, g.group_name
    HAVING student_count > 0
    ORDER BY student_count DESC, g.group_ID
  ";
  $stmt = $conn->prepare($sql);
  $stmt->execute([$cohort_ID, $cohort_ID]);
  $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // 如果請求未加入的學生列表
  $show_unjoined = $_GET['show_unjoined'] ?? null;
  if ($show_unjoined === '1') {
    // 獲取未加入團隊的學生列表
    $unjoinedStudents = [];
    foreach ($allStudents as $student) {
      if (!in_array($student['u_ID'], $joinedStudentIds)) {
        $unjoinedStudents[] = [
          'u_ID' => $student['u_ID'],
          'u_name' => $student['u_name'] ?: $student['u_ID'],
          'u_img' => $student['u_img'],
          'class_name' => $student['class_name'] ?? ''
        ];
      }
    }
    
    echo json_encode([
      'success' => true,
      'students' => $unjoinedStudents,
      'type' => 'unjoined'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
  
  // 如果請求特定類組的團隊詳情
  $group_ID = $_GET['group_ID'] ?? null;
  if ($group_ID) {
    $group_ID = (int)$group_ID;

    // 該屆此類組的所有團隊（不再限制班導師班級）
    $sql = "
      SELECT DISTINCT t.team_ID
      FROM teamdata t
      WHERE t.group_ID = ?
        AND t.cohort_ID = ?
        AND t.team_status = 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$group_ID, $cohort_ID]);
    $relevantTeamIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'team_ID');

    if (empty($relevantTeamIds)) {
      echo json_encode([
        'success' => true,
        'teams' => [],
        'type' => 'teams'
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $teamPlaceholders = implode(',', array_fill(0, count($relevantTeamIds), '?'));

    $sql = "
      SELECT 
        t.team_ID,
        COALESCE(t.team_project_name, CONCAT('Team ', t.team_ID)) as team_name,
        u.u_ID,
        u.u_name,
        u.u_img,
        (SELECT c.c_name FROM enrollmentdata e 
         JOIN classdata c ON c.c_ID = e.class_ID 
         WHERE e.enroll_u_ID = u.u_ID AND e.cohort_ID = ? AND e.enroll_status = 1 
         LIMIT 1) as class_name
      FROM teamdata t
      JOIN teammember tm ON tm.team_ID = t.team_ID
        AND (tm.tm_status IS NULL OR tm.tm_status = 1)
      JOIN userdata u ON u.u_ID = tm.{$teamUserField}
        AND (u.u_status IS NULL OR u.u_status = 1)
      WHERE t.team_ID IN ($teamPlaceholders)
        AND t.team_status = 1
        AND EXISTS (
          SELECT 1 FROM userrolesdata ur 
          WHERE ur.{$userRoleUidField} = u.u_ID 
          AND ur.role_ID = 6 
          AND ur.user_role_status = 1
        )
      ORDER BY t.team_ID, u.u_ID
    ";

    $params = array_merge([$cohort_ID], $relevantTeamIds);
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $teamMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $teams = [];
    foreach ($teamMembers as $member) {
      $teamId = $member['team_ID'];
      if (!isset($teams[$teamId])) {
        $teams[$teamId] = [
          'team_ID' => $teamId,
          'team_name' => $member['team_name'],
          'members' => []
        ];
      }
      $teams[$teamId]['members'][] = [
        'u_ID' => $member['u_ID'],
        'u_name' => $member['u_name'] ?: $member['u_ID'],
        'u_img' => $member['u_img'],
        'class_name' => $member['class_name'] ?? ''
      ];
    }
    $filteredTeams = array_values($teams);

    // 查詢每個團隊的指導老師
    $teamPlaceholdersForTeachers = implode(',', array_fill(0, count($relevantTeamIds), '?'));
    $stmt = $conn->prepare("
      SELECT 
        tm.team_ID,
        u.u_ID,
        u.u_name
      FROM teammember tm
      JOIN userdata u ON u.u_ID = tm.{$teamUserField}
      JOIN userrolesdata ur ON ur.{$userRoleUidField} = u.u_ID
      WHERE tm.team_ID IN ($teamPlaceholdersForTeachers)
        AND (tm.tm_status IS NULL OR tm.tm_status = 1)
        AND ur.role_ID = 4
        AND ur.user_role_status = 1
        AND (u.u_status IS NULL OR u.u_status = 1)
      ORDER BY tm.team_ID, u.u_ID
    ");
    $stmt->execute($relevantTeamIds);
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $teacherMap = [];
    foreach ($teachers as $teacher) {
      $teamId = $teacher['team_ID'];
      if (!isset($teacherMap[$teamId])) {
        $teacherMap[$teamId] = [];
      }
      $teacherMap[$teamId][] = [
        'u_ID' => $teacher['u_ID'],
        'u_name' => $teacher['u_name'] ?: $teacher['u_ID']
      ];
    }

    foreach ($filteredTeams as &$team) {
      $teamId = $team['team_ID'];
      $team['teachers'] = $teacherMap[$teamId] ?? [];
    }
    unset($team);

    echo json_encode([
      'success' => true,
      'teams' => array_values($filteredTeams),
      'type' => 'teams'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // 返回統計資料
  echo json_encode([
    'success' => true,
    'unjoined_count' => $unjoinedCount,
    'total_students' => $totalStudents,
    'joined_count' => $joinedCount,
    'all_joined' => $unjoinedCount === 0,
    'groups' => $groups
  ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
  error_log("class_data.php 錯誤: " . $e->getMessage());
  echo json_encode([
    'success' => false,
    'msg' => 'error: ' . $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}
?>

