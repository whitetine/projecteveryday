<?php
session_start();
require '../includes/pdo.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['u_ID'])) {
    echo json_encode(['success' => false, 'msg' => '尚未登入']);
    exit;
}
$uid = $_SESSION['u_ID'];
// 確保 u_ID 是字串格式
$uid = (string)$uid;
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 調試：記錄當前使用者ID
error_log("當前使用者ID: '$uid' (類型: " . gettype($uid) . ")");

// 檢查 userrolesdata 表使用哪個欄位名稱
$userRoleUidField = 'u_ID';
try {
    $checkStmt = $conn->query("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'");
    if ($checkStmt->rowCount() > 0) {
        $userRoleUidField = 'ur_u_ID';
    }
} catch (Exception $e) {
    // 如果檢查失敗，使用預設值 u_ID
    error_log("檢查 userrolesdata 欄位失敗: " . $e->getMessage());
}

/* 驗證是否為啟用中的指導老師或管理員（role_ID: 1=主任, 2=科辦, 4=指導老師） */
$stRole = $conn->prepare("
    SELECT COUNT(*) FROM userrolesdata
    WHERE $userRoleUidField=? AND role_ID IN (1, 2, 4) AND user_role_status=1
");
$stRole->execute([$uid]);
if (!$stRole->fetchColumn()) {
    echo json_encode(['success' => false, 'msg' => '無權限']);
    exit;
}

/* 取得全部週次 - 優先使用 perioddata 表 */
$periodFields = 'period_ID, period_title, period_start_d, period_end_d';
$periodTable = 'perioddata';
$periodActiveField = 'pe_status';
$hasPeriodType = false;

// 檢查 perioddata 表是否存在
$usePerioddata = false;
$hasPeMode = false;
$hasPeTargetId = false;
$hasCreatedUserId = false;
$hasPeStatus = false;

try {
  $testStmt = $conn->query("SHOW TABLES LIKE 'perioddata'");
  if ($testStmt->rowCount() > 0) {
    $usePerioddata = true;
    // 表存在後才檢查欄位
    try {
      $checkStmt = $conn->query("SHOW COLUMNS FROM perioddata LIKE 'pe_mode'");
      $hasPeMode = $checkStmt->rowCount() > 0;
      $checkPeriodType = $conn->query("SHOW COLUMNS FROM perioddata LIKE 'period_type'");
      $hasPeriodType = $checkPeriodType->rowCount() > 0;
      $checkStmt2 = $conn->query("SHOW COLUMNS FROM perioddata LIKE 'pe_target_ID'");
      $hasPeTargetId = $checkStmt2->rowCount() > 0;
      $checkStmt3 = $conn->query("SHOW COLUMNS FROM perioddata LIKE 'pe_created_u_ID'");
      $hasCreatedUserId = $checkStmt3->rowCount() > 0;
      $checkStmt4 = $conn->query("SHOW COLUMNS FROM perioddata LIKE 'pe_status'");
      $hasPeStatus = $checkStmt4->rowCount() > 0;
      
      if ($hasPeriodType) {
        $periodFields .= ', period_type';
      } elseif ($hasPeMode) {
        $periodFields .= ', pe_mode';
      }
      if ($hasPeTargetId) {
        $periodFields .= ', pe_target_ID';
      }
      
      // 如果沒有 pe_status 欄位，使用預設值 1
      if (!$hasPeStatus) {
        $periodActiveField = '1'; // 使用常數值，在 SELECT 中會加上 "as is_active"
      }
    } catch (Exception $e) {
      error_log("檢查 perioddata 欄位失敗: " . $e->getMessage());
    }
  }
} catch (Exception $e) {
  error_log("檢查 perioddata 表失敗: " . $e->getMessage());
  $usePerioddata = false;
}

// 如果 perioddata 表不存在，使用 periodsdata 表
if (!$usePerioddata) {
  $periodTable = 'periodsdata';
  $periodActiveField = 'is_active';
  try {
    $checkPeriodType = $conn->query("SHOW COLUMNS FROM periodsdata LIKE 'period_type'");
    $hasPeriodType = $checkPeriodType->rowCount() > 0;
    if ($hasPeriodType && strpos($periodFields, 'period_type') === false) {
      $periodFields .= ', period_type';
    }
  } catch (Exception $e) {
    $hasPeriodType = false;
  }
  // 檢查 periodsdata 表是否有 pe_created_u_ID 欄位
  try {
    $checkStmt = $conn->query("SHOW COLUMNS FROM periodsdata LIKE 'pe_created_u_ID'");
    $hasCreatedUserId = $checkStmt->rowCount() > 0;
  } catch (Exception $e) {
    $hasCreatedUserId = false;
  }
}

// 構建查詢，根據使用者過濾（只顯示自己新增的評分時段）
$whereClause = '';
$queryParams = [];

// 確保 u_ID 是字串格式
$uid = (string)$uid;

// 如果有 pe_created_u_ID 欄位，根據使用者過濾（只顯示自己新增的時段）
if ($hasCreatedUserId && $uid) {
  // 清理使用者ID（去除前後空格）
  $uid = trim($uid);
  $whereClause = ' WHERE pe_created_u_ID = ?';
  $queryParams[] = $uid;
  error_log("使用 pe_created_u_ID 過濾，使用者ID: '$uid' (長度: " . strlen($uid) . ", 類型: " . gettype($uid) . ")");
} else {
  error_log("不使用 pe_created_u_ID 過濾 - hasCreatedUserId: " . ($hasCreatedUserId ? 'true' : 'false') . ", uid: '$uid'");
}

try {
  // 構建 SELECT 語句，正確處理 is_active 欄位
  // 如果沒有 pe_status 欄位，使用常數值 1
  $isActiveField = ($usePerioddata && !$hasPeStatus) ? "1 as is_active" : "$periodActiveField as is_active";
  $sql = "
    SELECT $periodFields, $isActiveField
    FROM $periodTable
    $whereClause
    ORDER BY period_start_d DESC
  ";
  
  // 調試：記錄查詢資訊
  error_log("查詢評分時段 - 表: $periodTable, WHERE: $whereClause, 使用者: $uid, hasPeStatus: " . ($hasPeStatus ? 'true' : 'false') . ", SQL: $sql");
  
  if (!empty($queryParams)) {
    $stmt = $conn->prepare($sql);
    $stmt->execute($queryParams);
    $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("查詢參數: " . implode(', ', $queryParams));
  } else {
    $periods = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  }
  
  // 調試：記錄查詢結果
  error_log("查詢結果數量: " . count($periods));
  if (count($periods) > 0) {
    error_log("第一個時段資料: " . json_encode($periods[0], JSON_UNESCAPED_UNICODE));
  }
  
} catch (Exception $e) {
  // 如果查詢失敗，返回錯誤
  error_log("查詢評分時段失敗: " . $e->getMessage());
  echo json_encode([
      'success' => false,
      'msg' => '查詢評分時段失敗：' . $e->getMessage(),
      'debug' => [
          'table' => $periodTable,
          'where' => $whereClause,
          'uid' => $uid,
          'hasCreatedUserId' => $hasCreatedUserId,
          'usePerioddata' => $usePerioddata,
          'sql' => $sql ?? 'N/A',
          'queryParams' => $queryParams
      ]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if (empty($periods)) {
    // 如果根據使用者過濾後沒有資料，顯示友善的提示訊息
    echo json_encode([
        'success' => true,
        'periods' => [],
        'active' => null,
        'period_ID' => null,
        'rows' => [],
        'msg' => '尚未建立時段'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 判斷選取週次 */
$selectedPeriodId = null;
$active = null;

foreach ($periods as $p) {
    if ((int)$p['is_active'] === 1) {
        $active = $p;
        break;
    }
}

if (isset($_GET['period_ID']) && ctype_digit($_GET['period_ID'])) {
    $pid = (int)$_GET['period_ID'];
    foreach ($periods as $p) {
        if ((int)$p['period_ID'] === $pid) {
            $selectedPeriodId = $pid;
            $active = $p;
            break;
        }
    }
}

if ($selectedPeriodId === null) {
    if ($active) {
        $selectedPeriodId = (int)$active['period_ID'];
    } elseif (!empty($periods)) {
        $selectedPeriodId = (int)$periods[0]['period_ID'];
        $active = $periods[0];
    } else {
        // 即使沒有 periods，也要返回空資料而不是錯誤
        echo json_encode([
            'success' => true,
            'periods' => [],
            'active' => null,
            'period_ID' => null,
            'rows' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* 取得該評分時段指定的團隊 */
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

// 取得當前選取的評分時段資訊
$currentPeriod = null;
foreach ($periods as $p) {
    if ((int)$p['period_ID'] === $selectedPeriodId) {
        $currentPeriod = $p;
        break;
    }
}

$peMode = 'in';
$peTargetId = 'ALL';
if ($currentPeriod) {
    if ($hasPeriodType && isset($currentPeriod['period_type']) && $currentPeriod['period_type'] !== '') {
        $peMode = $currentPeriod['period_type'];
    } elseif ($hasPeMode && isset($currentPeriod['pe_mode']) && $currentPeriod['pe_mode'] !== '') {
        $peMode = $currentPeriod['pe_mode'];
    }
    $peTargetId = ($hasPeTargetId && isset($currentPeriod['pe_target_ID'])) ? $currentPeriod['pe_target_ID'] : 'ALL';
}

$targetInfo = parseTeamTarget($peTargetId);

// 檢查 petargetdata 表是否存在
$hasPetargetdata = false;
$hasPetargetStatus = false;
try {
  $checkPetarget = $conn->query("SHOW TABLES LIKE 'petargetdata'");
  $hasPetargetdata = $checkPetarget->rowCount() > 0;
  if ($hasPetargetdata) {
    try {
      $checkStatusCol = $conn->query("SHOW COLUMNS FROM petargetdata LIKE 'status_ID'");
      $hasPetargetStatus = $checkStatusCol->rowCount() > 0;
    } catch (Exception $e) {
      $hasPetargetStatus = false;
    }
  }
} catch (Exception $e) {
  // 忽略錯誤
}

// 從當前選取的評分時段獲取指定的團隊
$teams = [];
if ($selectedPeriodId) {
  if ($hasPetargetdata) {
    try {
      $statusFilterSql = '';
      $statusSelect = 'NULL AS status_ID';
      if ($peMode === 'cross') {
        if ($hasPetargetStatus) {
          $statusFilterSql = " AND pt.status_ID = 1";
          $statusSelect = 'pt.status_ID AS status_ID';
        } else {
          $statusSelect = '1 AS status_ID';
        }
      } else { // peMode === 'in'
        $statusSelect = '0 AS status_ID';
      }
      $sql = "
        SELECT DISTINCT pt.pe_team_ID AS team_ID,
               COALESCE(td.team_project_name, CONCAT('Team ', pt.pe_team_ID)) AS team_name,
               {$statusSelect}
        FROM petargetdata pt
        LEFT JOIN teamdata td ON td.team_ID = pt.pe_team_ID
        WHERE pt.period_ID = ? AND pt.pe_team_ID IS NOT NULL{$statusFilterSql}
        ORDER BY pt.pe_team_ID
      ";
      $stPetarget = $conn->prepare($sql);
      $stPetarget->execute([$selectedPeriodId]);
      $teams = $stPetarget->fetchAll(PDO::FETCH_ASSOC);
      error_log("從 petargetdata 表獲取團隊 - period_ID: $selectedPeriodId, 找到團隊數: " . count($teams));
    } catch (Exception $e) {
      error_log("從 petargetdata 表獲取團隊失敗: " . $e->getMessage());
    }
  }

  // 如果沒有查到資料，且 pe_target_ID 有指定，依 assign 列表補上
  if (empty($teams) && !$targetInfo['is_all'] && !empty($targetInfo['assign'])) {
    $fallbackTeams = fetchTeamsByIds($conn, $targetInfo['assign']);
    // 根據模式設定預設狀態
    $defaultStatus = ($peMode === 'cross') ? 1 : 0;
    $teams = array_map(function($team) use ($defaultStatus) {
      $team['status_ID'] = $defaultStatus;
      return $team;
    }, $fallbackTeams);
    error_log("從 pe_target_ID assign 解析獲取團隊 - 找到團隊數: " . count($teams));
  }

  if (empty($teams) && $targetInfo['is_all']) {
    error_log("pe_target_ID 為 ALL，但 petargetdata 表沒有資料，無法顯示所有團隊");
  }
}

// 調試：記錄查詢結果
error_log("查詢團隊 - period_ID: $selectedPeriodId, 找到團隊數: " . count($teams));

// 如果沒有找到團隊，返回空陣列而不是錯誤
if (empty($teams)) {
    error_log("沒有找到該評分時段指定的團隊 - period_ID: $selectedPeriodId");
    // 即使沒有團隊，也要返回 periods 資料
    echo json_encode([
        'success'    => true,
        'periods'    => $periods,
        'active'     => $active,
        'period_ID'  => $selectedPeriodId,
        'rows'       => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 該組學生數 */
// 使用動態欄位名稱
$stMember = $conn->prepare("
  SELECT COUNT(*)
  FROM teammember tm
  JOIN userrolesdata ur ON ur.$userRoleUidField = tm.$userIdField
  WHERE tm.team_ID = ?
    AND ur.role_ID = 6
    AND ur.user_role_status = 1
    AND (tm.tm_status IS NULL OR tm.tm_status = 1)
");

// 解析 pe_target_ID 的函數
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

function fetchTeamsByIds(PDO $conn, array $teamIds) {
  $ids = array_values(array_unique(array_filter(array_map('intval', $teamIds), fn($id) => $id > 0)));
  if (empty($ids)) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $sql = "
    SELECT DISTINCT td.team_ID,
           COALESCE(td.team_project_name, CONCAT('Team ', td.team_ID)) AS team_name
    FROM teamdata td
    WHERE td.team_ID IN ($placeholders)
    ORDER BY td.team_ID
  ";
  $stmt = $conn->prepare($sql);
  $stmt->execute($ids);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* 已評分的學生 - 根據互評模式動態構建查詢 */
function buildReviewQuery($conn, $teamId, $periodId, $peMode, $targetInfo, $userIdField = 'u_ID', $userRoleUidField = 'u_ID') {
  // 檢查使用哪個互評紀錄表
  $reviewTable = 'peerreview';
  $reviewerField = 'review_a_u_ID';
  $reviewedField = 'review_b_u_ID';
  $usePereviewdata = false;
  $hasPetargetUId = false;
  
  try {
    $testStmt = $conn->query("SHOW TABLES LIKE 'pereviewdata'");
    if ($testStmt->rowCount() > 0) {
      // 檢查 pereviewdata 表是否有 petarget_u_ID 欄位
      $checkStmt = $conn->query("SHOW COLUMNS FROM pereviewdata LIKE 'petarget_u_ID'");
      if ($checkStmt->rowCount() > 0) {
        $usePereviewdata = true;
        $hasPetargetUId = true;
        $reviewTable = 'pereviewdata';
        $reviewerField = 'pe_u_ID';
        $reviewedField = 'petarget_u_ID';
      } else {
        // pereviewdata 表存在但沒有 petarget_u_ID，需要通過 petargetdata 關聯
        $usePereviewdata = true;
        $reviewTable = 'pereviewdata';
        $reviewerField = 'pe_u_ID';
      }
    }
  } catch (Exception $e) {
    // 如果檢查失敗，使用預設的 peerreview 表
    error_log("檢查互評紀錄表失敗: " . $e->getMessage());
  }
  if ($peMode === 'cross') {
    // 團隊間互評模式：只統計該團隊成員作為評分者的紀錄
    $assignIds = array_map('intval', $targetInfo['assign']);
    $isAssignTeam = $targetInfo['is_all'] || in_array($teamId, $assignIds, true);

    if (!$isAssignTeam) {
      return ['sql' => 'SELECT 0 as cnt', 'params' => []];
    }

    $params = [$teamId];
    $targetFilter = '';
    if (!$targetInfo['is_all'] && !empty($targetInfo['receive'])) {
      $receiveTeamIds = array_map('intval', $targetInfo['receive']);
      if (!empty($receiveTeamIds)) {
        $placeholders = implode(',', array_fill(0, count($receiveTeamIds), '?'));
        $targetFilter = " AND tmb.team_ID IN ($placeholders)";
        $params = array_merge($params, $receiveTeamIds);
      }
    }

    array_unshift($params, $periodId);

    $sql = "
      SELECT COUNT(DISTINCT pr.$reviewerField) as cnt
      FROM $reviewTable pr
      JOIN teammember tma ON pr.$reviewerField = tma.$userIdField
      JOIN teammember tmb ON pr.$reviewedField = tmb.$userIdField
      JOIN userrolesdata ura ON ura.$userRoleUidField = pr.$reviewerField
      WHERE pr.period_ID = ?
        AND tma.team_ID = ?{$targetFilter}
        AND ura.role_ID = 6
        AND ura.user_role_status = 1
        AND (tma.tm_status IS NULL OR tma.tm_status = 1)
        AND (tmb.tm_status IS NULL OR tmb.tm_status = 1)
    ";

    return ['sql' => $sql, 'params' => $params];
  } else {
    // 團隊內互評模式
    if ($usePereviewdata && !$hasPetargetUId) {
      // 使用 pereviewdata 表，但沒有 petarget_u_ID 欄位，需要通過 petargetdata 關聯
      // 這種情況下，我們只能統計該團隊的評分者數量，無法精確統計被評分者
      $sql = "
        SELECT COUNT(DISTINCT pr.$reviewerField) as cnt
        FROM $reviewTable pr
        JOIN teammember tma ON pr.$reviewerField = tma.$userIdField AND tma.team_ID = ?
        JOIN petargetdata pt ON pr.pe_target_ID = pt.pe_target_ID AND pt.pe_team_ID = ?
        JOIN userrolesdata ura ON ura.$userRoleUidField = pr.$reviewerField
        WHERE pr.period_ID = ?
          AND ura.role_ID = 6
          AND ura.user_role_status = 1
          AND (tma.tm_status IS NULL OR tma.tm_status = 1)
      ";
      return ['sql' => $sql, 'params' => [$teamId, $teamId, $periodId]];
    } else {
      // 使用 peerreview 表或 pereviewdata 表（有 petarget_u_ID）
      $sql = "
        SELECT COUNT(DISTINCT pr.$reviewerField) as cnt
        FROM $reviewTable pr
        JOIN teammember tma ON pr.$reviewerField = tma.$userIdField AND tma.team_ID = ?
        JOIN teammember tmb ON pr.$reviewedField = tmb.$userIdField AND tmb.team_ID = ?
        JOIN userrolesdata ura ON ura.$userRoleUidField = pr.$reviewerField
        WHERE pr.period_ID = ?
          AND ura.role_ID = 6
          AND ura.user_role_status = 1
          AND (tma.tm_status IS NULL OR tma.tm_status = 1)
          AND (tmb.tm_status IS NULL OR tmb.tm_status = 1)
      ";
      return ['sql' => $sql, 'params' => [$teamId, $teamId, $periodId]];
    }
  }
}

// $currentPeriod, $peMode, $peTargetId, $targetInfo 已在前面定義，這裡不需要重複

$rows = [];
foreach ($teams as $t) {
    $tid = (int)$t['team_ID'];
    $teamStatusId = isset($t['status_ID']) ? (int)$t['status_ID'] : ($peMode === 'cross' ? 1 : 0);

    $stMember->execute([$tid]);
    $expectedResult = $stMember->fetchColumn();
    $expected = $expectedResult !== false && $expectedResult !== null ? (int)$expectedResult : 0;

    // 根據互評模式構建查詢
    $queryInfo = buildReviewQuery($conn, $tid, $selectedPeriodId, $peMode, $targetInfo, $userIdField, $userRoleUidField);
    
    // 確保即使沒有評分資料也返回 0
    $actual = 0;
    try {
        $stActual = $conn->prepare($queryInfo['sql']);
        $stActual->execute($queryInfo['params']);
        $result = $stActual->fetchColumn();
        $actual = $result !== false && $result !== null ? (int)$result : 0;
        // 調試資訊
        error_log("查詢評分人數 (team_ID: $tid, period_ID: $selectedPeriodId, peMode: $peMode): 結果=$actual, SQL=" . $queryInfo['sql'] . ", 參數=" . json_encode($queryInfo['params']));
    } catch (Exception $e) {
        // 如果查詢失敗，設為 0
        $actual = 0;
        error_log("查詢評分人數失敗 (team_ID: $tid, period_ID: $selectedPeriodId): " . $e->getMessage() . ", SQL=" . $queryInfo['sql'] . ", 參數=" . json_encode($queryInfo['params']));
    }

    // 即使沒有評分資料也要顯示團隊資料
    $rows[] = [
        'team_ID'     => $tid,
        'team_name'   => $t['team_name'],
        'expected'    => $expected,
        'actual'      => $actual,
        'is_complete' => ($expected > 0 && $actual === $expected),
        'status_ID'   => $teamStatusId
    ];
}

// 確保返回的資料包含所有必要欄位
$response = [
    'success'    => true,
    'periods'    => $periods,
    'active'     => $active,
    'period_ID'  => $selectedPeriodId,
    'rows'       => $rows,
    'meta'       => [
        'current_user' => $uid,
        'period_count' => count($periods),
        'team_count'   => count($teams)
    ]
];

// 調試：記錄返回的資料
error_log("返回資料 - periods數量: " . count($periods) . ", rows數量: " . count($rows) . ", selectedPeriodId: $selectedPeriodId");

echo json_encode($response, JSON_UNESCAPED_UNICODE);
