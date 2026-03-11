<?php
// 關閉錯誤顯示，避免輸出 HTML 錯誤訊息
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 設置錯誤處理器，確保所有錯誤都返回 JSON
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        error_log("PHP Error [{$severity}]: {$message} in {$file} on line {$line}");
        // 如果是致命錯誤，嘗試返回 JSON
        if ($severity === E_ERROR || $severity === E_PARSE || $severity === E_CORE_ERROR || $severity === E_COMPILE_ERROR) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
            }
            echo json_encode([
                'success' => false,
                'msg' => '伺服器內部錯誤: ' . $message . " (檔案: {$file}, 行號: {$line})"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    return false; // 繼續執行標準錯誤處理
});

// 註冊關閉處理器，捕獲致命錯誤
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_CORE_WARNING])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'msg' => '伺服器內部錯誤: ' . $error['message'] . " (檔案: {$error['file']}, 行號: {$error['line']})"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

session_start();
require '../includes/pdo.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['u_ID'])) {
  echo json_encode(['success'=>false,'msg'=>'no login']);
  exit;
}

try {
$uid      = $_SESSION['u_ID'];
$teamId   = (int)($_GET['team_ID'] ?? 0);
$periodId = (int)($_GET['period_ID'] ?? 0);

if ($teamId <= 0) {
  echo json_encode(['success'=>false,'msg'=>'team_ID error']);
  exit;
}

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

// 檢查 teammember 表使用哪個欄位名稱
$hasTeamUId = false;
try {
    $checkTeamUId = $conn->query("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
    $hasTeamUId = $checkTeamUId->rowCount() > 0;
} catch (Exception $e) {
    // 忽略錯誤
}

// 根據實際欄位名稱構建查詢
$userIdField = $hasTeamUId ? 'team_u_ID' : 'u_ID';

/* ---------- 僅限指導老師或管理員 ---------- */
$stRole = $conn->prepare("
  SELECT COUNT(*) FROM userrolesdata 
  WHERE $userRoleUidField=? AND role_ID IN (1, 2, 4) AND user_role_status=1
");
$stRole->execute([$uid]);
if (!$stRole->fetchColumn()) {
  echo json_encode(['success'=>false,'msg'=>'no_permission']);
  exit;
}

/* ---------- 檢查團隊是否存在 ---------- */
$chkTeam = $conn->prepare("SELECT COUNT(*) FROM teamdata WHERE team_ID=:tid AND team_status=1");
$chkTeam->execute([':tid'=>$teamId]);
if (!$chkTeam->fetchColumn()) {
  echo json_encode(['success'=>false,'msg'=>'team_not_found']);
  exit;
}

/* ---------- 取得組名 ---------- */
$stName = $conn->prepare("
  SELECT COALESCE(team_project_name, CONCAT('Team ', :tid)) 
  FROM teamdata WHERE team_ID=:tid
");
$stName->execute([':tid'=>$teamId]);
$teamName = $stName->fetchColumn() ?: ("Team ".$teamId);

/* ---------- 取得期間 ---------- */
// 檢查是否有 pe_mode 和 pe_target_ID 欄位
$hasPeMode = false;
$hasPeTargetId = false;
try {
  $checkStmt = $conn->query("SHOW COLUMNS FROM perioddata LIKE 'pe_mode'");
  $hasPeMode = $checkStmt->rowCount() > 0;
  $checkStmt2 = $conn->query("SHOW COLUMNS FROM perioddata LIKE 'pe_target_ID'");
  $hasPeTargetId = $checkStmt2->rowCount() > 0;
} catch (Exception $e) {
  // 忽略錯誤
}

$periodFields = 'period_ID, period_title, period_start_d, period_end_d';
$hasPeriodType = false;
if ($hasPeMode) {
  $periodFields .= ', pe_mode';
}
try {
  $checkPeriodType = $conn->query("SHOW COLUMNS FROM perioddata LIKE 'period_type'");
  if ($checkPeriodType->rowCount() > 0) {
    $hasPeriodType = true;
    $periodFields .= ', period_type';
  }
} catch (Exception $e) {
  $hasPeriodType = false;
}
if ($hasPeTargetId) {
  $periodFields .= ', pe_target_ID';
}

if ($periodId <= 0) {
  // 先嘗試從 perioddata 表查詢
  try {
    $p = $conn->query("
      SELECT $periodFields
      FROM perioddata WHERE pe_status=1
      ORDER BY period_start_d DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    $p = null;
  }
  
  // 如果 perioddata 沒有資料，嘗試從 periodsdata 查詢
  if (!$p) {
    $p = $conn->query("
      SELECT period_ID, period_title, period_start_d, period_end_d
      FROM periodsdata WHERE is_active=1
      ORDER BY period_start_d DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
  }

  if (!$p) {
    try {
      $p = $conn->query("
        SELECT $periodFields
        FROM perioddata ORDER BY period_start_d DESC LIMIT 1
      ")->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
      $p = null;
    }
  }
  
  if (!$p) {
    $p = $conn->query("
      SELECT period_ID, period_title, period_start_d, period_end_d
      FROM periodsdata ORDER BY period_start_d DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
  }
} else {
  // 先嘗試從 perioddata 表查詢
  try {
    $stP = $conn->prepare("
      SELECT $periodFields
      FROM perioddata WHERE period_ID=?
    ");
    $stP->execute([$periodId]);
    $p = $stP->fetch(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    $p = null;
  }
  
  // 如果 perioddata 沒有資料，嘗試從 periodsdata 查詢
  if (!$p) {
    $stP = $conn->prepare("
      SELECT period_ID, period_title, period_start_d, period_end_d
      FROM periodsdata WHERE period_ID=?
    ");
    $stP->execute([$periodId]);
    $p = $stP->fetch(PDO::FETCH_ASSOC);
  }
}

if (!$p) {
  echo json_encode(['success'=>false,'msg'=>'no_period']);
  exit;
}

$periodId    = (int)$p['period_ID'];
$periodTitle = $p['period_title'];
$periodRange = $p['period_start_d'].' ～ '.$p['period_end_d'];

// 解析互評模式
if ($hasPeriodType && isset($p['period_type']) && $p['period_type'] !== '') {
  $peMode = $p['period_type'];
} else {
  $peMode = ($hasPeMode && isset($p['pe_mode'])) ? $p['pe_mode'] : 'in';
}
$peTargetId = ($hasPeTargetId && isset($p['pe_target_ID'])) ? $p['pe_target_ID'] : 'ALL';

// 解析 pe_target_ID
function parseTeamTarget($raw) {
  $result = [
    'assign' => [],
    'receive' => [],
    'is_all' => false
  ];
  if (!$raw || strtoupper(trim($raw)) === 'ALL') {
    $result['is_all'] = true;
    return $result;
  }
  $trimmed = trim((string)$raw);
  if ($trimmed !== '' && $trimmed[0] === '{') {
    $decoded = json_decode($trimmed, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
      $result['assign'] = array_values(array_filter(array_map('strval', $decoded['assign'] ?? [])));
      $result['receive'] = array_values(array_filter(array_map('strval', $decoded['receive'] ?? [])));
      return $result;
    }
  }
  $assignList = array_filter(array_map('trim', explode(',', $raw)), function($v){ return $v !== ''; });
  $result['assign'] = array_values(array_map('strval', $assignList));
  return $result;
}

function fetchStudentsByTeamIds(PDO $conn, array $teamIds, $userIdField, $userRoleUidField) {
  $ids = array_values(array_unique(array_filter(array_map('intval', $teamIds), fn($id) => $id > 0)));
  if (empty($ids)) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $sql = "
    SELECT DISTINCT u.u_ID, u.u_name
    FROM teammember tm
    JOIN userdata u ON tm.$userIdField = u.u_ID
    JOIN userrolesdata ur ON ur.$userRoleUidField = u.u_ID
    WHERE tm.team_ID IN ($placeholders)
      AND ur.role_ID = 6
      AND ur.user_role_status = 1
      AND (tm.tm_status IS NULL OR tm.tm_status = 1)
    ORDER BY u.u_ID
  ";
  $stmt = $conn->prepare($sql);
  $stmt->execute($ids);
  return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function fetchUserNamesByIds(PDO $conn, array $userIds) {
  $ids = array_values(array_unique(array_filter(array_map('strval', $userIds), fn($id) => $id !== '')));
  if (empty($ids)) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $conn->prepare("
    SELECT u_ID, u_name
    FROM userdata
    WHERE u_ID IN ($placeholders)
  ");
  $stmt->execute($ids);
  return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function fetchTargetTeamsFromPetarget(PDO $conn, int $periodId, bool $onlyStatusOne = false, bool $hasStatusColumn = false) {
  $sql = "
    SELECT DISTINCT pe_team_ID
    FROM petargetdata
    WHERE period_ID = :pid AND pe_team_ID IS NOT NULL
  ";
  if ($onlyStatusOne && $hasStatusColumn) {
    $sql .= " AND status_ID = 1";
  }
  $sql .= " ORDER BY pe_team_ID";
  $stmt = $conn->prepare($sql);
  $stmt->execute([':pid' => $periodId]);
  return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'pe_team_ID'));
}

$targetInfo = parseTeamTarget($peTargetId);
$assignTeamIds = array_map('intval', $targetInfo['assign']);
$receiveTeamIds = array_map('intval', $targetInfo['receive']);

// 檢查 petargetdata 是否存在
$hasPetargetdata = false;
$hasPetargetStatus = false;
try {
  $checkPetarget = $conn->query("SHOW TABLES LIKE 'petargetdata'");
  $hasPetargetdata = $checkPetarget->rowCount() > 0;
  if ($hasPetargetdata) {
    $checkStatusCol = $conn->query("SHOW COLUMNS FROM petargetdata LIKE 'status_ID'");
    $hasPetargetStatus = $checkStatusCol->rowCount() > 0;
  }
} catch (Exception $e) {
  $hasPetargetdata = false;
}

$isAssignTeam = false;
$isReceiveTeam = false;
if ($peMode === 'cross') {
  $isAssignTeam = $targetInfo['is_all'] || in_array($teamId, $assignTeamIds, true);
  $isReceiveTeam = $targetInfo['is_all'] || (!empty($receiveTeamIds) ? in_array($teamId, $receiveTeamIds, true) : false);
}

/* ---------- 取得學生名單 ---------- */
$stStudents = $conn->prepare("
  SELECT u.u_ID, u.u_name
  FROM teammember tm
  JOIN userdata u ON tm.$userIdField = u.u_ID
  JOIN userrolesdata ur ON ur.$userRoleUidField = u.u_ID
  WHERE tm.team_ID=? AND ur.role_ID=6 AND ur.user_role_status=1
  ORDER BY u.u_ID
");
$stStudents->execute([$teamId]);
$students = $stStudents->fetchAll(PDO::FETCH_KEY_PAIR);
$studentIds = array_map('strval', array_keys($students));
$studentIdsSet = array_flip($studentIds);
$N = count($students);

/* ---------- 取得評分紀錄 ---------- */
// 檢查使用哪個互評紀錄表
$reviewTable = null;
$reviewerField = null;
$reviewedField = null;
$reviewCommentField = null;
$reviewCreatedField = null;
$reviewIdField = null;
$usePereviewdata = false;
$hasPetargetUId = false;

// 首先檢查 peerreview 表是否存在
$hasPeerreview = false;
try {
  $checkPeerreview = $conn->query("SHOW TABLES LIKE 'peerreview'");
  $hasPeerreview = $checkPeerreview->rowCount() > 0;
} catch (Exception $e) {
  $hasPeerreview = false;
}

// 檢查 pereviewdata 表是否存在
$hasPereviewdata = false;
try {
  $checkPereviewdata = $conn->query("SHOW TABLES LIKE 'pereviewdata'");
  $hasPereviewdata = $checkPereviewdata->rowCount() > 0;
} catch (Exception $e) {
  $hasPereviewdata = false;
}

// 決定使用哪個表（優先使用 pereviewdata）
if ($hasPereviewdata) {
  // 使用 pereviewdata 表
  $usePereviewdata = true;
  $reviewTable = 'pereviewdata';
  $reviewerField = 'pe_u_ID';
  $reviewCommentField = 'peer_comment';
  $reviewCreatedField = 'created_d';
  $reviewIdField = 'peer_ID';
  
  // 檢查 pereviewdata 表是否有 petarget_u_ID 欄位
  try {
    $checkStmt = $conn->query("SHOW COLUMNS FROM pereviewdata LIKE 'petarget_u_ID'");
    if ($checkStmt->rowCount() > 0) {
      $hasPetargetUId = true;
      $reviewedField = 'petarget_u_ID';
    } else {
      $hasPetargetUId = false;
      $reviewedField = null; // 需要通過 petargetdata 關聯
    }
  } catch (Exception $e) {
    $hasPetargetUId = false;
    $reviewedField = null;
  }
} elseif ($hasPeerreview) {
  // 使用 peerreview 表
  $reviewTable = 'peerreview';
  $reviewerField = 'review_a_u_ID';
  $reviewedField = 'review_b_u_ID';
  $reviewCommentField = 'review_comment';
  $reviewCreatedField = 'review_created_d';
  $reviewIdField = 'review_ID';
} else {
  // 兩個表都不存在，返回錯誤
  echo json_encode([
    'success' => false,
    'msg' => '找不到互評紀錄表（peerreview 或 pereviewdata）'
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// 根據互評模式構建 SQL 查詢
if ($peMode === 'cross') {
  // 團隊間互評模式
  // 判斷當前團隊是評分團隊還是被評分團隊
  $isAssignTeam = $targetInfo['is_all'] || in_array($teamId, $assignTeamIds);
  $isReceiveTeam = $targetInfo['is_all'] || (empty($receiveTeamIds) && $targetInfo['is_all']) || in_array($teamId, $receiveTeamIds);
  
  // 構建查詢條件
  $conditions = [];
  $params = [];
  
  // 如果當前團隊是評分團隊（assign），查詢該團隊成員對其他團隊的評分
  if ($isAssignTeam) {
    if (!empty($receiveTeamIds)) {
      // 有指定被評分團隊
      $placeholders = implode(',', array_fill(0, count($receiveTeamIds), '?'));
      $conditions[] = "(tma.team_ID = ? AND tmb.team_ID IN ($placeholders))";
      $params = array_merge($params, [$teamId], $receiveTeamIds);
    } else {
      // 沒有指定被評分團隊，表示所有團隊都可以被評分
      $conditions[] = "tma.team_ID = ?";
      $params[] = $teamId;
    }
  }
  
  // 如果當前團隊是被評分團隊（receive），查詢其他團隊對該團隊成員的評分
  if ($isReceiveTeam) {
    if (!empty($assignTeamIds)) {
      // 有指定評分團隊
      $placeholders = implode(',', array_fill(0, count($assignTeamIds), '?'));
      $conditions[] = "(tma.team_ID IN ($placeholders) AND tmb.team_ID = ?)";
      $params = array_merge($params, $assignTeamIds, [$teamId]);
    } else {
      // 沒有指定評分團隊，表示所有團隊都可以評分
      $conditions[] = "tmb.team_ID = ?";
      $params[] = $teamId;
    }
  }
  
  if (empty($conditions)) {
    // 當前團隊不在評分或被評分列表中，返回空結果
    $rows = [];
  } else {
    $whereClause = implode(' OR ', $conditions);
    array_unshift($params, $periodId); // period_ID 參數需要放在最前面，對應 SQL 中的第一個佔位符
    
    if ($usePereviewdata && !$hasPetargetUId) {
      // 使用 pereviewdata 表，但沒有 petarget_u_ID，需要通過 petargetdata 關聯
      $sql = "
        SELECT pr.$reviewerField AS from_id,
               NULL AS to_id,
               pr.score,
               pr.$reviewCommentField AS review_comment,
               pr.$reviewCreatedField AS review_created_d,
               pt.pe_team_ID AS target_team_id
        FROM $reviewTable pr
        JOIN teammember tma ON pr.$reviewerField = tma.$userIdField
        JOIN petargetdata pt ON pr.pe_target_ID = pt.pe_target_ID
        WHERE pr.period_ID = ? AND ($whereClause)
        ORDER BY pr.$reviewCreatedField ASC, pr.$reviewIdField ASC
      ";
    } else {
      $sql = "
        SELECT pr.$reviewerField AS from_id,
               pr.$reviewedField AS to_id,
               pr.score,
               pr.$reviewCommentField AS review_comment,
               pr.$reviewCreatedField AS review_created_d
        FROM $reviewTable pr
        JOIN teammember tma ON pr.$reviewerField = tma.$userIdField
        JOIN teammember tmb ON pr.$reviewedField = tmb.$userIdField
        WHERE pr.period_ID = ? AND ($whereClause)
        ORDER BY pr.$reviewCreatedField ASC, pr.$reviewIdField ASC
      ";
    }
    $st = $conn->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  }
} else {
  // 團隊內互評模式
  if ($usePereviewdata && !$hasPetargetUId) {
    // 使用 pereviewdata 表，但沒有 petarget_u_ID，需要通過 petargetdata 關聯
    $st = $conn->prepare("
      SELECT pr.$reviewerField AS from_id,
             NULL AS to_id,
             pr.score,
             pr.$reviewCommentField AS review_comment,
             pr.$reviewCreatedField AS review_created_d,
             pt.pe_team_ID AS target_team_id
      FROM $reviewTable pr
      JOIN teammember tma ON pr.$reviewerField=tma.$userIdField AND tma.team_ID=:tid
      JOIN petargetdata pt ON pr.pe_target_ID = pt.pe_target_ID AND pt.pe_team_ID=:tid
      WHERE pr.period_ID=:pid
      ORDER BY pr.$reviewCreatedField ASC, pr.$reviewIdField ASC
    ");
  } else {
    $st = $conn->prepare("
      SELECT pr.$reviewerField AS from_id,
             pr.$reviewedField AS to_id,
             pr.score,
             pr.$reviewCommentField AS review_comment,
             pr.$reviewCreatedField AS review_created_d
      FROM $reviewTable pr
      JOIN teammember tma ON pr.$reviewerField=tma.$userIdField AND tma.team_ID=:tid
      JOIN teammember tmb ON pr.$reviewedField=tmb.$userIdField AND tmb.team_ID=:tid
      WHERE pr.period_ID=:pid
      ORDER BY pr.$reviewCreatedField ASC, pr.$reviewIdField ASC
    ");
  }
  $st->execute([':tid'=>$teamId, ':pid'=>$periodId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

// 如果使用 pereviewdata 但上述查詢沒有取回資料，改用簡單查詢再在程式內過濾
if ($usePereviewdata && $hasPetargetUId && empty($rows)) {
  try {
    $fallback = $conn->prepare("
      SELECT pr.$reviewerField AS from_id,
             pr.$reviewedField AS to_id,
             pr.score,
             pr.$reviewCommentField AS review_comment,
             pr.$reviewCreatedField AS review_created_d
      FROM $reviewTable pr
      WHERE pr.period_ID = :pid
      ORDER BY pr.$reviewCreatedField ASC, pr.$reviewIdField ASC
    ");
    $fallback->execute([':pid' => $periodId]);
    $rows = $fallback->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    error_log("teacher_review_detail fallback 查詢失敗: ".$e->getMessage());
  }
}

// 如果 rows 仍然為空且查詢的是隊內互評，嘗試改用單純 WHERE 條件再由程式篩出本隊成員
if (($peMode === 'in') && empty($rows)) {
  try {
    $fallback = $conn->prepare("
      SELECT pr.$reviewerField AS from_id,
             pr.$reviewedField AS to_id,
             pr.score,
             pr.$reviewCommentField AS review_comment,
             pr.$reviewCreatedField AS review_created_d
      FROM $reviewTable pr
      JOIN teammember tma ON pr.$reviewerField = tma.$userIdField
      JOIN teammember tmb ON pr.$reviewedField = tmb.$userIdField
      WHERE pr.period_ID = :pid
      ORDER BY pr.$reviewCreatedField ASC, pr.$reviewIdField ASC
    ");
    $fallback->execute([':pid' => $periodId]);
    $rows = $fallback->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    error_log("teacher_review_detail team fallback 失敗: ".$e->getMessage());
  }
}

// 針對跨組互評，建立被評學生清單
$targetTeamIdsForDisplay = ($peMode === 'cross') ? [] : [$teamId];
if ($peMode === 'cross') {
  if ($isAssignTeam) {
    if (!empty($receiveTeamIds)) {
      $targetTeamIdsForDisplay = $receiveTeamIds;
    } elseif ($hasPetargetdata) {
      $targetTeamIdsForDisplay = fetchTargetTeamsFromPetarget($conn, $periodId, true, $hasPetargetStatus);
    }
  }
} else {
  $targetTeamIdsForDisplay = [$teamId];
}

$targetStudents = (!empty($targetTeamIdsForDisplay) && $peMode === 'cross')
  ? fetchStudentsByTeamIds($conn, $targetTeamIdsForDisplay, $userIdField, $userRoleUidField)
  : [];

if (empty($targetStudents) && $peMode !== 'cross') {
  $targetStudents = $students;
}

$targetIds = array_map('strval', array_keys($targetStudents));
$targetIdsSet = array_flip($targetIds);

// 根據實際評分紀錄補齊被評學生
$additionalTargetIds = [];
foreach ($rows as $r) {
  $toIdCandidate = isset($r['to_id']) ? (string)$r['to_id'] : '';
  if ($toIdCandidate !== '' && !isset($targetIdsSet[$toIdCandidate])) {
    $additionalTargetIds[$toIdCandidate] = true;
  }
}

if (!empty($additionalTargetIds)) {
  $extraNames = fetchUserNamesByIds($conn, array_keys($additionalTargetIds));
  foreach (array_keys($additionalTargetIds) as $uid) {
    if (!isset($targetIdsSet[$uid])) {
      $targetStudents[$uid] = $extraNames[$uid] ?? $uid;
      $targetIds[] = $uid;
      $targetIdsSet[$uid] = true;
    }
  }
}

if (empty($targetStudents) && $peMode !== 'cross') {
  $targetStudents = $students;
  $targetIds = $studentIds;
  $targetIdsSet = $studentIdsSet;
} elseif (empty($targetStudents)) {
  $targetIds = [];
  $targetIdsSet = [];
}

/* ---------- 取得所有學生的團隊映射（用於判斷是否需要評分） ---------- */
function fetchStudentTeamMap(PDO $conn, array $studentIds, $userIdField) {
  if (empty($studentIds)) {
    return [];
  }
  $ids = array_values(array_unique(array_filter(array_map('strval', $studentIds), fn($id) => $id !== '')));
  if (empty($ids)) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $sql = "
    SELECT tm.$userIdField AS u_ID, tm.team_ID
    FROM teammember tm
    WHERE tm.$userIdField IN ($placeholders)
      AND (tm.tm_status IS NULL OR tm.tm_status = 1)
    GROUP BY tm.$userIdField
  ";
  $stmt = $conn->prepare($sql);
  $stmt->execute($ids);
  $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  // 建立學生 ID 到團隊 ID 的映射
  $teamMap = [];
  foreach ($results as $row) {
    $uid = (string)$row['u_ID'];
    $teamMap[$uid] = (int)$row['team_ID'];
  }
  return $teamMap;
}

// 取得所有學生的團隊映射（評分人 + 被評人）
$allStudentIds = array_unique(array_merge($studentIds, $targetIds));
$studentTeamMap = fetchStudentTeamMap($conn, $allStudentIds, $userIdField);

/* ---------- 準備矩陣 ---------- */
$score = [];
$comment = [];
$didReview = [];
$recvSum = [];
$recvCnt = [];

foreach ($studentIds as $a) {
  $didReview[$a] = 0;
  foreach ($targetIds as $b) {
    $score[$a][$b] = null;
    $comment[$a][$b] = null;
  }
}

foreach ($targetIds as $b) {
  $recvSum[$b] = 0;
  $recvCnt[$b] = 0;
}

// 如果是跨組互評的評分團隊，需要查詢所有對被評學生的評分來計算平均分
$allReviewRowsForAvg = [];
if ($peMode === 'cross' && $isAssignTeam && !empty($targetIds)) {
  try {
    if ($usePereviewdata && $hasPetargetUId) {
      // 使用 pereviewdata 表且有 petarget_u_ID
      $targetIdsPlaceholder = implode(',', array_fill(0, count($targetIds), '?'));
      $avgSql = "
        SELECT pr.$reviewedField AS to_id,
               pr.score
        FROM $reviewTable pr
        JOIN teammember tmb ON pr.$reviewedField = tmb.$userIdField
        WHERE pr.period_ID = ?
          AND pr.$reviewedField IN ($targetIdsPlaceholder)
          AND (tmb.tm_status IS NULL OR tmb.tm_status = 1)
      ";
      $avgParams = array_merge([$periodId], $targetIds);
    } elseif ($usePereviewdata && !$hasPetargetUId) {
      // 使用 pereviewdata 表但沒有 petarget_u_ID，通過 petargetdata 關聯
      $targetTeamIdsPlaceholder = implode(',', array_fill(0, count($targetTeamIdsForDisplay), '?'));
      $avgSql = "
        SELECT pr.$reviewerField AS from_id,
               pr.score,
               pt.pe_team_ID AS target_team_id
        FROM $reviewTable pr
        JOIN petargetdata pt ON pr.pe_target_ID = pt.pe_target_ID
        JOIN teammember tmb ON pt.pe_team_ID = tmb.team_ID
        WHERE pr.period_ID = ?
          AND pt.pe_team_ID IN ($targetTeamIdsPlaceholder)
          AND (tmb.tm_status IS NULL OR tmb.tm_status = 1)
      ";
      $avgParams = array_merge([$periodId], $targetTeamIdsForDisplay);
    } else {
      // 使用 peerreview 表
      $targetIdsPlaceholder = implode(',', array_fill(0, count($targetIds), '?'));
      $avgSql = "
        SELECT pr.$reviewedField AS to_id,
               pr.score
        FROM $reviewTable pr
        JOIN teammember tmb ON pr.$reviewedField = tmb.$userIdField
        WHERE pr.period_ID = ?
          AND pr.$reviewedField IN ($targetIdsPlaceholder)
          AND (tmb.tm_status IS NULL OR tmb.tm_status = 1)
      ";
      $avgParams = array_merge([$periodId], $targetIds);
    }
    $avgStmt = $conn->prepare($avgSql);
    $avgStmt->execute($avgParams);
    $allReviewRowsForAvg = $avgStmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    error_log("查詢所有評分紀錄失敗（用於計算平均分）: " . $e->getMessage());
  }
}

// 使用所有評分紀錄計算平均分（跨組互評時）
if (!empty($allReviewRowsForAvg)) {
  foreach ($allReviewRowsForAvg as $r) {
    $b = isset($r['to_id']) ? (string)$r['to_id'] : '';
    if ($b === '' || $b === null) {
      // 如果是通過 petargetdata 關聯的情況，需要額外查詢被評學生
      if (isset($r['target_team_id'])) {
        continue; // 這種情況下無法精確計算，跳過
      }
      continue;
    }
    if ($b !== '' && isset($targetIdsSet[$b])) {
      $recvSum[$b] += (int)($r['score'] ?? 0);
      $recvCnt[$b]++;
    }
  }
}

foreach ($rows as $r){
  $a = isset($r['from_id']) ? (string)$r['from_id'] : '';
  $b = isset($r['to_id']) ? (string)$r['to_id'] : '';
  
  // 如果 to_id 為 NULL（使用 pereviewdata 表但沒有 petarget_u_ID），跳過
  // 因為無法確定具體被評分者，無法構建評分矩陣
  if ($b === null || $b === '') {
    // 只統計評分次數（如果評分者是當前團隊學生）
    if ($a !== '' && isset($studentIdsSet[$a])) {
      $didReview[$a]++;
    }
    continue;
  }
  
  if ($a === '' && $b === '') {
    continue;
  }

  // 統計評分者（只用於矩陣顯示）
  if ($a !== '' && isset($studentIdsSet[$a])) {
    $didReview[$a]++;
  }

  // 如果是跨組互評的評分團隊，已經用 allReviewRowsForAvg 計算平均分，這裡不再重複計算
  // 但如果是隊內互評，仍需要在這裡計算
  if ($peMode !== 'cross' || !$isAssignTeam) {
    // 統計被評者（隊內互評或非評分團隊時）
    if ($b !== '' && isset($targetIdsSet[$b])) {
      $recvSum[$b] += (int)$r['score'];
      $recvCnt[$b]++;
    }
  }

  if ($a !== '' && isset($studentIdsSet[$a]) && $b !== '' && isset($targetIdsSet[$b])) {
    $score[$a][$b] = isset($r['score']) ? (int)$r['score'] : null;
    $comment[$a][$b] = (string)$r['review_comment'];
  }
}

$avg=[];
foreach ($targetIds as $sid){
  $avg[$sid] = $recvCnt[$sid] ? round($recvSum[$sid]/$recvCnt[$sid],2) : null;
}

$reviewersDistinct = 0;
foreach ($studentIds as $sid){
  if ($didReview[$sid] > 0) $reviewersDistinct++;
}

$isComplete = ($N > 0 && $reviewersDistinct === $N);

/* ==============================
   回傳 JSON
============================== */
try {
  echo json_encode([
    'success'=>true,
    'teamName'=>$teamName,
    'teamId'=>$teamId,
    'periodId'=>$periodId,
    'periodTitle'=>$periodTitle,
    'periodRange'=>$periodRange,
    'students'=>$students,
    'studentIds'=>$studentIds,
    'targetStudents'=>$targetStudents,
    'targetIds'=>$targetIds,
    'score'=>$score,
    'comment'=>$comment,
    'avg'=>$avg,
    'didReview'=>$didReview,
    'recvCnt'=>$recvCnt,
    'notReviewed'=>array_values(array_filter($studentIds, fn($x)=>$didReview[$x]==0)),
    'N'=>$N,
    'completed'=>$isComplete,
    'peMode'=>$peMode,
    'studentTeamMap'=>$studentTeamMap
  ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
  error_log("teacher_review_detail_data.php JSON 編碼錯誤: " . $e->getMessage());
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
  }
  echo json_encode([
    'success' => false,
    'msg' => '資料處理錯誤: ' . $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
} catch (Error $e) {
  error_log("teacher_review_detail_data.php 致命錯誤: " . $e->getMessage());
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
  }
  echo json_encode([
    'success' => false,
    'msg' => '伺服器錯誤: ' . $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}
} catch (Exception $e) {
  // 捕獲所有未預期的異常
  error_log("teacher_review_detail_data.php 執行錯誤: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
  }
  echo json_encode([
    'success' => false,
    'msg' => '執行錯誤: ' . $e->getMessage() . " (檔案: {$e->getFile()}, 行號: {$e->getLine()})"
  ], JSON_UNESCAPED_UNICODE);
} catch (Error $e) {
  // 捕獲 PHP 7+ 的 Error（如 TypeError, ParseError 等）
  error_log("teacher_review_detail_data.php 致命錯誤: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
  }
  echo json_encode([
    'success' => false,
    'msg' => '致命錯誤: ' . $e->getMessage() . " (檔案: {$e->getFile()}, 行號: {$e->getLine()})"
  ], JSON_UNESCAPED_UNICODE);
}
