<?php
session_start();
require_once "../includes/pdo.php";
header('Content-Type: application/json; charset=utf-8');

$role_ID = (int)($_SESSION['role_ID'] ?? 0);
$u_ID    = $_SESSION['u_ID'] ?? null;

if (!$u_ID) {
  echo json_encode(['error' => true, 'msg' => '請先登入'], JSON_UNESCAPED_UNICODE);
  exit;
}

// ✅ 允許的角色：主任(1)、科辦(2)、班導(3)、指導老師(4)、召集人(7)
$allowedRoles = [1, 2, 3, 4, 7];
if (!in_array($role_ID, $allowedRoles)) {
  echo json_encode(['error' => true, 'msg' => '此功能僅限主任、科辦、班導、指導老師、召集人使用'], JSON_UNESCAPED_UNICODE);
  exit;
}

// 只有召集人可以審核
$canReview = ($role_ID === 7);

$cohort_ID = $_SESSION['cohort_ID'] ?? 3;
$doc_ID = isset($_GET['doc_ID']) ? (int)$_GET['doc_ID'] : 0;
if ($doc_ID <= 0) {
  echo json_encode(['error' => true, 'msg' => '文件ID無效'], JSON_UNESCAPED_UNICODE);
  exit;
}

// 兼容欄位
function columnExists(PDO $conn, string $table, string $column): bool {
  try {
    $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);    
    return $stmt->rowCount() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

$parseRejectReason = function (?string $remark): ?string {
  if ($remark === null) return null;
  $remark = trim($remark);
  if ($remark === '' || strpos($remark, 'REJECT') !== 0) return null;
  // 去掉前綴 REJECTED / REJECT... 以及後面的全形或半形冒號
  $reason = preg_replace('/^REJECTED[:：]?\s*/u', '', $remark);
  $reason = trim((string)$reason);
  return $reason !== '' ? $reason : null;
};

$teamUserField = columnExists($conn, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
$userRoleUidField = columnExists($conn, 'userrolesdata', 'ur_u_ID') ? 'ur_u_ID' : 'u_ID';

try {
  // 讀取此文件的目標設定：若有限定類組(GROUP)，收件狀況僅顯示那些類組的團隊
  $targetGroups = [];
  try {
    $stmt = $conn->prepare("
      SELECT doc_target_type, doc_target_ID
      FROM document_form_targets
      WHERE doc_ID = ?
    ");
    $stmt->execute([$doc_ID]);
    $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($targets as $t) {
      $type = trim((string)($t['doc_target_type'] ?? ''));
      // 只認 GROUP（資料表若為 enum('ALL','COHORT','GROUP') 且含空字串時略過非 GROUP）
      if ($type === 'GROUP' && trim((string)$t['doc_target_ID']) !== '') {
        $targetGroups[] = trim((string)$t['doc_target_ID']);
      }
    }
    // 去重
    if (!empty($targetGroups)) {
      $targetGroups = array_values(array_unique($targetGroups));
    }
  } catch (Throwable $e) {
    // 若目標表不存在或查詢失敗，視同不限類組
    $targetGroups = [];
  }

  // 文件名稱：與 form_manage 一致，先查 document_forms（啟用），再 fallback docdata
  $stmt = $conn->prepare("SELECT doc_name FROM document_forms WHERE doc_ID = ? AND doc_status = 1");
  $stmt->execute([$doc_ID]);
  $docName = $stmt->fetchColumn();
  if ($docName === false || $docName === null) {
    $stmt = $conn->prepare("SELECT doc_name FROM docdata WHERE doc_ID = ?");
    $stmt->execute([$doc_ID]);
    $docName = $stmt->fetchColumn() ?: '未知文件';
  }

  /**
   * 根據角色過濾團隊（身分不混用：同一人有多角色時，依 session 角色只套用該身份邏輯）：
   * - 主任(1)、科辦(2)、召集人(7)：所有團隊
   * - 班導(3)：只能查看自己班級的繳交狀況
   *   - 並且在「班級繳交查看」畫面中，只顯示成員全部屬於自己負責班級的團隊（不再顯示跨班組）
   * - 指導老師(4)：只能查看自己名下團隊的繳交狀況（teammember + role 4）
   */
  $allowedTeamIds = [];
  // 班導在該屆所負責的所有 class_ID（用於後續再過濾跨班團隊）
  $tutorClassIds = [];
  
  if ($role_ID === 1 || $role_ID === 2 || $role_ID === 7) {
    // 主任、科辦、召集人：所有團隊
    if (!empty($targetGroups)) {
      // 只顯示目標類組的團隊
      $placeholders = implode(',', array_fill(0, count($targetGroups), '?'));
      $sqlTeams = "
        SELECT team_ID, team_project_name
        FROM teamdata
        WHERE cohort_ID = ?
          AND team_status = 1
          AND group_ID IN ($placeholders)
        ORDER BY team_ID ASC
      ";
      $params = array_merge([$cohort_ID], $targetGroups);
      $stmt = $conn->prepare($sqlTeams);
      $stmt->execute($params);
    } else {
      $stmt = $conn->prepare("
        SELECT team_ID, team_project_name
        FROM teamdata
        WHERE cohort_ID = ?
          AND team_status = 1
        ORDER BY team_ID ASC
      ");
      $stmt->execute([$cohort_ID]);
    }
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } elseif ($role_ID === 3) {
   // 班導：班上成員的團隊（只看自己在該屆擔任班導的班級，不做其他 fallback，避免混到非自己班級）
    // 1) 從 enrollmentdata 取得此班導在該屆負責的 class_ID 列表（role_ID = 3）
    $stmt = $conn->prepare("
      SELECT DISTINCT class_ID
      FROM enrollmentdata
      WHERE enroll_u_ID = ?
        AND cohort_ID = ?
        AND enroll_status = 1
        AND role_ID = 3
        AND class_ID IS NOT NULL
    ");
    $stmt->execute([$u_ID, $cohort_ID]);
    $classIds = array_values(array_filter(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'class_ID')));
    // 同時記錄下此班導在該屆所有負責的班級，後面再用來排除跨班團隊
    $tutorClassIds = $classIds;
    if (empty($classIds)) {
      $teams = [];
    } else {
      $placeholders = implode(',', array_fill(0, count($classIds), '?'));
      $extraGroupWhere = '';
      // 參數：cohort_ID, classIds (IN), cohort_ID；若有 targetGroups 再追加
      $params = array_merge([$cohort_ID], $classIds, [$cohort_ID]);
      if (!empty($targetGroups)) {
        $groupPlaceholders = implode(',', array_fill(0, count($targetGroups), '?'));
        $extraGroupWhere = " AND t.group_ID IN ($groupPlaceholders)";
        $params = array_merge($params, $targetGroups);
      }
      // 2) 顯示：至少一名組員在該屆 enrollment 屬於此班導負責的班級即顯示（不排除跨班團隊，避免忠/孝混組在兩邊都看不到）
      $sql = "
        SELECT DISTINCT t.team_ID, t.team_project_name
        FROM teamdata t
        JOIN teammember tm ON tm.team_ID = t.team_ID
        JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField}
          AND e.cohort_ID = ?
          AND e.enroll_status = 1
          AND e.class_ID IN ($placeholders)
        WHERE t.cohort_ID = ?
          AND t.team_status = 1
          AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
          $extraGroupWhere
        ORDER BY t.team_ID ASC
      ";
      $stmt = $conn->prepare($sql);
      $stmt->execute($params);
      $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
  } elseif ($role_ID === 4) {
    // 指導老師：僅能查看自己名下團隊的繳交狀況（teammember + role 4）。與班導身份分離，不混為一談
    $extraGroupWhere = '';
    $params = [$cohort_ID, $u_ID];
    if (!empty($targetGroups)) {
      $groupPlaceholders = implode(',', array_fill(0, count($targetGroups), '?'));
      $extraGroupWhere = " AND t.group_ID IN ($groupPlaceholders)";
      $params = array_merge($params, $targetGroups);
    }
    $sql = "
      SELECT DISTINCT t.team_ID, t.team_project_name
      FROM teamdata t
      JOIN teammember tm ON tm.team_ID = t.team_ID
      JOIN userrolesdata ur ON ur.{$userRoleUidField} = tm.{$teamUserField}
      WHERE t.cohort_ID = ?
        AND t.team_status = 1
        AND tm.{$teamUserField} = ?
        AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
        AND ur.role_ID = 4
        AND ur.user_role_status = 1
        $extraGroupWhere
      ORDER BY t.team_ID ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $teams = [];
  }

  // 做成 map：team_ID => submission（先查新版 document_submissions，再補舊版 docsubdata）
  $subMap = [];

  // 若此文件為 document_forms 啟用表單，先從 document_submissions 取「已送出」的繳交（與文件審核列表 T 一致）
  $docFormCheck = $conn->prepare("SELECT doc_ID FROM document_forms WHERE doc_ID = ? AND doc_status = 1");
  $docFormCheck->execute([$doc_ID]);
  $isDocumentForm = $docFormCheck->fetch() !== false;

  if ($isDocumentForm) {
    $sqlNew = "
      SELECT ds.sub_ID, ds.dcsub_u_ID, ds.dcsub_sub_d, ds.dcsub_approved_d, ds.dcsub_remark, tm.team_ID
      FROM document_submissions ds
      INNER JOIN teammember tm ON tm.{$teamUserField} = ds.dcsub_u_ID AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
      INNER JOIN teamdata td ON td.team_ID = tm.team_ID AND td.cohort_ID = ? AND td.team_status = 1
      WHERE ds.doc_ID = ? AND ds.dcsub_status = 1 AND ds.dcsub_sub_d IS NOT NULL
      ORDER BY ds.dcsub_sub_d DESC
    ";
    try {
      $stmtNew = $conn->prepare($sqlNew);
      $stmtNew->execute([$cohort_ID, $doc_ID]);
      $rowsNew = $stmtNew->fetchAll(PDO::FETCH_ASSOC);
      foreach ($rowsNew as $r) {
        $tid = (int)($r['team_ID'] ?? 0);
        if ($tid <= 0 || isset($subMap[$tid])) continue;
        $approved = !empty($r['dcsub_approved_d']);
        $remark = (string)($r['dcsub_remark'] ?? '');
        $rejected = ($remark !== '' && strpos($remark, 'REJECT') === 0);
        $rejectReason = $rejected ? $parseRejectReason($remark) : null;
        $st = $rejected ? 2 : ($approved ? 1 : 0);
        $subMap[$tid] = [
          'sub_ID' => (int)$r['sub_ID'],
          'dcsub_sub_d' => $r['dcsub_sub_d'],
          'dcsub_status' => $st,
          'dcsub_url' => 'pages/document_form_pdf.php?document_id=' . (int)$doc_ID . '&submission_id=' . (int)$r['sub_ID'],
          'dcsub_remark' => $remark,
          'reject_reason' => $rejectReason,
        ];
      }
    } catch (Throwable $e) {
      // 忽略，繼續用舊表
    }
  }

  /**
   * 舊表 docsubdata：取得該 doc_ID 每個 team 的「最新一筆」提交（僅在尚未有 document_submissions 的團隊或非 document_forms 時使用）
   */
  $sqlLatest = "
    SELECT 
      ds.sub_ID,
      ds.doc_ID,
      ds.dcsub_team_ID,
      ds.dcsub_u_ID,
      ds.dcsub_url,
      ds.dcsub_sub_d,
      ds.dcsub_status,
      ds.dcsub_remark,
      COALESCE(ds.dcsub_team_ID, tm.team_ID) AS team_ID
    FROM docsubdata ds
    LEFT JOIN teammember tm
      ON ds.dcsub_team_ID IS NULL
     AND tm.{$teamUserField} = ds.dcsub_u_ID
     AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
    LEFT JOIN teamdata td
      ON td.team_ID = COALESCE(ds.dcsub_team_ID, tm.team_ID)
     AND td.cohort_ID = :cohort_ID
     AND td.team_status = 1
    WHERE ds.doc_ID = :doc_ID
      AND td.team_ID IS NOT NULL
    ORDER BY COALESCE(ds.dcsub_team_ID, tm.team_ID), ds.dcsub_sub_d DESC
  ";

  try {
    $stmt = $conn->prepare($sqlLatest);
    $stmt->execute([':doc_ID'=>$doc_ID, ':cohort_ID'=>$cohort_ID]);
    $allSubs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allSubs as $sub) {
      $teamID = (int)($sub['team_ID'] ?? 0);
      if ($teamID <= 0 || isset($subMap[$teamID])) continue; // 新版已有則不覆蓋；已排序故每隊只取第一筆
      $remark = (string)($sub['dcsub_remark'] ?? '');
      $sub['reject_reason'] = $remark !== '' ? $parseRejectReason($remark) : null;
      $subMap[$teamID] = $sub;
    }
  } catch (Throwable $e) {
    // 舊表不存在或查詢失敗時忽略
  }
  
  // 調試資訊（開發時可啟用）
  if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    error_log("=== submission_view_data.php DEBUG ===");
    error_log("doc_ID: $doc_ID, cohort_ID: $cohort_ID");
    error_log("teams count: " . count($teams));
    error_log("allSubs count: " . (isset($allSubs) ? count($allSubs) : 0));
    error_log("subMap keys: " . implode(',', array_keys($subMap)));
    if (!empty($allSubs)) {
      error_log("Sample submission: " . json_encode($allSubs[0], JSON_UNESCAPED_UNICODE));
    }
  }

  $result = [];

  foreach ($teams as $t) {
    $team_ID = (int)$t['team_ID'];
    $teamName = $t['team_project_name'] ?: '未命名團隊';

    $sub = $subMap[$team_ID] ?? null;
    $isSubmitted = $sub ? 1 : 0;

    // ✅ 組員（只顯示 role=學生 6）
    $stmtM = $conn->prepare("
      SELECT DISTINCT u.u_name
      FROM teammember tm
      JOIN userdata u ON u.u_ID = tm.{$teamUserField}
      JOIN userrolesdata ur ON ur.{$userRoleUidField} = u.u_ID
      WHERE tm.team_ID = ?
        AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
        AND u.u_status IN (1,3)
        AND ur.role_ID = 6
        AND ur.user_role_status = 1
      ORDER BY u.u_name
    ");
    $stmtM->execute([$team_ID]);
    $members = $stmtM->fetchAll(PDO::FETCH_COLUMN);
    $membersStr = $members ? implode('、', $members) : '-';

    // ✅ 指導老師（role=4，可多位）
    $stmtT = $conn->prepare("
      SELECT DISTINCT u.u_name
      FROM teammember tm
      JOIN userdata u ON u.u_ID = tm.{$teamUserField}
      JOIN userrolesdata ur ON ur.{$userRoleUidField} = u.u_ID
      WHERE tm.team_ID = ?
        AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
        AND u.u_status IN (1,3)
        AND ur.role_ID = 4
        AND ur.user_role_status = 1
      ORDER BY u.u_name
    ");
    $stmtT->execute([$team_ID]);
    $teachers = $stmtT->fetchAll(PDO::FETCH_COLUMN);
    $teacherStr = $teachers ? implode('、', $teachers) : '-';

    // 獲取此隊伍所有不同的 class_ID（從學生的 enrollment）
    $stmtC = $conn->prepare("
      SELECT DISTINCT e.class_ID
      FROM teammember tm
      JOIN userrolesdata ur ON ur.{$userRoleUidField} = tm.{$teamUserField}
        AND ur.role_ID = 6 AND ur.user_role_status = 1
      JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField}
      WHERE tm.team_ID = ?
        AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
        AND e.enroll_status = 1
        AND e.cohort_ID = ?
        AND e.class_ID IS NOT NULL
      ORDER BY e.class_ID
    ");
    $stmtC->execute([$team_ID, $cohort_ID]);
    $classIds = array_values(array_filter(array_column($stmtC->fetchAll(PDO::FETCH_ASSOC), 'class_ID')));

    // 若為班導身分，且有設定負責班級，則排除「含有非自己班級」的跨班團隊，
    // 只保留所有成員皆屬於自己負責班級範圍內的團隊
    if ($role_ID === 3 && !empty($tutorClassIds)) {
      // 團隊實際出現的班級中，若有任何一個不在此班導負責的班級列表中，就跳過這個團隊
      $diff = array_diff($classIds, $tutorClassIds);
      if (!empty($diff)) {
        continue;
      }
    }

    $className = '-';
    $tutorStr = '-';

    if (!empty($classIds)) {
      // 獲取所有班級名稱，用"/"連接
      $placeholders = implode(',', array_fill(0, count($classIds), '?'));
      $stmtCN = $conn->prepare("
        SELECT c_name 
        FROM classdata 
        WHERE c_ID IN ($placeholders)
        ORDER BY c_ID
      ");
      $stmtCN->execute($classIds);
      $classNames = $stmtCN->fetchAll(PDO::FETCH_COLUMN);
      $className = $classNames ? implode('/', $classNames) : '-';

      // ✅ 班導（role=3，可多位）- 從所有班級中獲取
      $stmtTutor = $conn->prepare("
        SELECT DISTINCT u.u_name
        FROM enrollmentdata e
        JOIN userrolesdata ur ON ur.{$userRoleUidField} = e.enroll_u_ID
          AND ur.role_ID = 3 AND ur.user_role_status = 1
        JOIN userdata u ON u.u_ID = e.enroll_u_ID
        WHERE e.cohort_ID = ?
          AND e.class_ID IN ($placeholders)
          AND e.enroll_status = 1
          AND u.u_status IN (1,3)
        ORDER BY u.u_name
      ");
      $stmtTutor->execute(array_merge([$cohort_ID], $classIds));
      $tutors = $stmtTutor->fetchAll(PDO::FETCH_COLUMN);
      $tutorStr = $tutors ? implode('、', $tutors) : '-';
    }

    // 時間 / url
    $time = '-';
    $url = null;

    // 狀態
    $statusText = '未繳交';

    if ($sub) {
      $url = $sub['dcsub_url'] ?: null;
      $time = $sub['dcsub_sub_d'] ? date('Y-m-d H:i:s', strtotime($sub['dcsub_sub_d'])) : '-';

      // dcsub_status: 4=暫存, 1=已繳交(待審核/已通過/退件依審核狀態), 0=待審核, 2=已退件
      $st = $sub['dcsub_status'];
      if ($st === null || $st === '' || (int)$st === 4) $statusText = (int)$st === 4 ? '暫存' : '待審核';
      else if ((int)$st === 1) $statusText = '已通過';
      else if ((int)$st === 2) $statusText = '已退件';
      else if ((int)$st === 0) $statusText = '待審核';
      else $statusText = '待審核';
    }

    $result[] = [
      'file' => $docName,
      'team' => $teamName,
      'members' => $membersStr,
      'teacher' => $teacherStr,
      'tutor' => $tutorStr,
      'class' => $className,
      'time' => $time,
      'remark' => $sub['reject_reason'] ?? null,
      'url' => $url,
      'status' => $statusText,
      'review' => $statusText, // 保持向後兼容
      'is_submitted' => $isSubmitted,
      'sub_ID' => $sub ? (int)$sub['sub_ID'] : null,
      'team_ID' => $team_ID,
      'dcsub_status' => $sub ? (int)($sub['dcsub_status'] ?? 0) : null,
      'can_review' => $canReview // 是否可以審核（只有召集人）
    ];
  }

  echo json_encode([
    'data' => $result,
    'can_review' => $canReview // 全局標誌，方便前端使用
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  error_log('submission_view_data.php error: ' . $e->getMessage());
  echo json_encode(['error' => true, 'msg' => '查詢失敗：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
