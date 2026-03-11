<?php
// 檔案：modules/team_apply_review_api.php
session_start();
require_once __DIR__ . '/../includes/pdo.php';
require_once __DIR__ . '/../config/path.php';

$do = $_GET['do'] ?? ($_POST['do'] ?? '');

// 圖片代理：在送出 JSON header 前處理，避免 403 直接存取 uploads
if ($do === 'get_image') {
  $tap_ID = (int)($_GET['tap_ID'] ?? 0);
  if ($tap_ID <= 0) { http_response_code(400); exit; }
  if (!isset($_SESSION['u_ID'])) { http_response_code(401); exit; }
  $stmt = $conn->prepare("SELECT tap_url FROM teamapply WHERE tap_ID = ?");
  $stmt->execute([$tap_ID]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row || empty($row['tap_url'])) { http_response_code(404); exit; }
  $relPath = trim($row['tap_url']);
  if (strpos($relPath, 'http') === 0) { header('Location: ' . $relPath); exit; }
  $relPath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relPath), '/\\');
  $fullPath = BASE_PATH . DIRECTORY_SEPARATOR . $relPath;
  if (!file_exists($fullPath) || !is_readable($fullPath)) { http_response_code(404); exit; }
  $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
  $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'tiff' => 'image/tiff', 'tif' => 'image/tiff', 'ico' => 'image/x-icon', 'heic' => 'image/heic', 'avif' => 'image/avif', 'pdf' => 'application/pdf'];
  if (isset($mimes[$ext])) { header('Content-Type: ' . $mimes[$ext]); }
  header('Content-Length: ' . filesize($fullPath));
  readfile($fullPath);
  exit;
}

header('Content-Type: application/json; charset=utf-8');

function json_resp($ok, $msg, $data = []) {
  echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $data), JSON_UNESCAPED_UNICODE);
  exit;
}

function parse_submitter_comment($tap_des) {
  if (!$tap_des) return '';
  $j = json_decode($tap_des, true);
  if (is_array($j) && isset($j['comment'])) return (string)$j['comment'];
  return '';
}

function fetch_user_name_map(PDO $conn, array $ids): array {
  $ids = array_values(array_unique(array_filter($ids, fn($x)=>$x!=='' && $x!==null)));
  if (!$ids) return [];
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  // 同時支援 u_ID 與 u_account 查詢（tap_teacher 可能存帳號）
  $hasAccount = $conn->query("SHOW COLUMNS FROM userdata LIKE 'u_account'")->rowCount() > 0;
  $sql = $hasAccount
    ? "SELECT u_ID, u_name, u_account FROM userdata WHERE u_ID IN ($placeholders) OR u_account IN ($placeholders)"
    : "SELECT u_ID, u_name FROM userdata WHERE u_ID IN ($placeholders)";
  $stmt = $conn->prepare($sql);
  $stmt->execute(array_merge($ids, $hasAccount ? $ids : []));
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $map = [];
  foreach ($rows as $r) {
    $map[$r['u_ID']] = $r['u_name'];
    if (!empty($r['u_account'])) $map[$r['u_account']] = $r['u_name'];
  }
  return $map;
}

/**
 * 取提交者的屆別顯示字串：year_label + cohort_name
 * - enroll_status=1 視為有效
 * - 取最新一筆（enroll_created_d DESC）
 */
function fetch_user_cohort_label(PDO $conn, string $u_ID): array {
  if ($u_ID === '') return ['cohort_ID' => null, 'cohort_label' => ''];

  $sql = "SELECT
            e.cohort_ID,
            c.year_label,
            c.cohort_name
          FROM enrollmentdata e
          LEFT JOIN cohortdata c ON c.cohort_ID = e.cohort_ID
          WHERE e.enroll_u_ID = ?
            AND e.enroll_status = 1
          ORDER BY e.enroll_created_d DESC, e.enroll_ID DESC
          LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->execute([$u_ID]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) return ['cohort_ID' => null, 'cohort_label' => ''];
  $label = trim( $row['cohort_name'] );
  return ['cohort_ID' => $row['cohort_ID'] ?? null, 'cohort_label' => $label];
}

try {

  // ------------------------------------------------------------
  // 0) 表單列表：給審核頁第一層選擇用
  // ------------------------------------------------------------
  if ($do === 'get_forms') {
    $sql = "SELECT taf_ID, taf_title, taf_cohort_ID, taf_status, taf_updated_d
            FROM teamapplyform
            WHERE taf_status IN (1, 0)
            ORDER BY taf_cohort_ID DESC, taf_ID DESC";
    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
      $stmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ? LIMIT 1");
      $stmt->execute([(int)$r['taf_cohort_ID']]);
      $cohortName = (string)($stmt->fetchColumn() ?: '');
      $r['cohort_label'] = $cohortName !== '' ? $cohortName : ('屆別ID ' . (int)$r['taf_cohort_ID']);
      $st = (int)($r['taf_status'] ?? 0);
      $r['taf_status_label'] = $st === 1 ? '啟用' : ($st === 0 ? '停用' : ('狀態' . $st));

      // 統計：該屆別下 待審核(1)、退件(2)、已組隊(3) 數量
      $cid = (int)($r['taf_cohort_ID'] ?? 0);
      if ($cid > 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM teamapply t
          WHERE t.tap_status = 1
          AND EXISTS (SELECT 1 FROM enrollmentdata e WHERE e.enroll_u_ID = t.tap_u_ID AND e.enroll_status = 1 AND e.cohort_ID = ?)");
        $stmt->execute([$cid]);
        $r['pending_count'] = (int)$stmt->fetchColumn();
        $stmt = $conn->prepare("SELECT COUNT(*) FROM teamapply t
          WHERE t.tap_status = 2
          AND EXISTS (SELECT 1 FROM enrollmentdata e WHERE e.enroll_u_ID = t.tap_u_ID AND e.enroll_status = 1 AND e.cohort_ID = ?)");
        $stmt->execute([$cid]);
        $r['rejected_count'] = (int)$stmt->fetchColumn();
        $stmt = $conn->prepare("SELECT COUNT(*) FROM teamapply t
          WHERE t.tap_status = 3
          AND EXISTS (SELECT 1 FROM enrollmentdata e WHERE e.enroll_u_ID = t.tap_u_ID AND e.enroll_status = 1 AND e.cohort_ID = ?)");
        $stmt->execute([$cid]);
        $r['total_count'] = (int)$stmt->fetchColumn();
      } else {
        $r['pending_count'] = 0;
        $r['rejected_count'] = 0;
        $r['total_count'] = 0;
      }
    }

    json_resp(true, 'success', ['forms' => $rows]);
  }

  // ------------------------------------------------------------
  // 0b) 已刪除表單列表（taf_status=2）
  // ------------------------------------------------------------
  if ($do === 'get_deleted_forms') {
    $sql = "SELECT taf_ID, taf_title, taf_cohort_ID, taf_status, taf_updated_d
            FROM teamapplyform
            WHERE taf_status = 2
            ORDER BY taf_ID DESC";
    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
      $stmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ? LIMIT 1");
      $stmt->execute([(int)$r['taf_cohort_ID']]);
      $cohortName = (string)($stmt->fetchColumn() ?: '');
      $r['cohort_label'] = $cohortName !== '' ? $cohortName : ('屆別ID ' . (int)$r['taf_cohort_ID']);

      $cid = (int)($r['taf_cohort_ID'] ?? 0);
      if ($cid > 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM teamapply t
          WHERE t.tap_taf_ID = ? AND t.tap_status = 1");
        $stmt->execute([(int)$r['taf_ID']]);
        $r['pending_count'] = (int)$stmt->fetchColumn();
        $stmt = $conn->prepare("SELECT COUNT(*) FROM teamapply t
          WHERE t.tap_taf_ID = ? AND t.tap_status = 2");
        $stmt->execute([(int)$r['taf_ID']]);
        $r['rejected_count'] = (int)$stmt->fetchColumn();
        $stmt = $conn->prepare("SELECT COUNT(*) FROM teamapply t
          WHERE t.tap_taf_ID = ? AND t.tap_status = 3");
        $stmt->execute([(int)$r['taf_ID']]);
        $r['total_count'] = (int)$stmt->fetchColumn();
      } else {
        $r['pending_count'] = 0;
        $r['rejected_count'] = 0;
        $r['total_count'] = 0;
      }
    }

    json_resp(true, 'success', ['forms' => $rows]);
  }

  // ------------------------------------------------------------
  // 0c) 復原已刪除的申請單（taf_status 2 → 1）
  // ------------------------------------------------------------
  if ($do === 'restore_form') {
    $taf_ID = (int)($_POST['taf_ID'] ?? 0);
    if ($taf_ID <= 0) json_resp(false, 'taf_ID 不正確');
    $stmt = $conn->prepare("UPDATE teamapplyform SET taf_status = 1, taf_updated_d = NOW() WHERE taf_ID = ? AND taf_status = 2");
    $stmt->execute([$taf_ID]);
    if ($stmt->rowCount() === 0) json_resp(false, '找不到該表單或非刪除狀態');
    json_resp(true, '已復原申請單');
  }

  // ------------------------------------------------------------
  // A) 清單：只顯示 1/2/3，不顯示 0
  // 排序：1(申請中) → 2(退件) → 3(已組隊)
  // ------------------------------------------------------------
  if ($do === 'get_list') {
    $keyword = trim($_GET['keyword'] ?? '');
    $status  = $_GET['status'] ?? 'all';
    $cohortFilter = (int)($_GET['cohort_ID'] ?? 0);

    $sql = "SELECT
              t.tap_ID,
              t.tap_name,
              t.tap_member,
              t.tap_teacher,
              t.tap_u_ID,
              t.tap_status,
              t.tap_rp_d,
              t.tap_update_d
            FROM teamapply t
            WHERE t.tap_status <> 0"; // ✅ 封存(0)不顯示

    $params = [];

    // 狀態篩選
    if ($status !== 'all' && $status !== '') {
      $sql .= " AND t.tap_status = ?";
      $params[] = (int)$status;
    } else {
      // all 只顯示 1/2/3（其實上面已排除 0，這裡再保險一次）
      $sql .= " AND t.tap_status IN (1,2,3)";
    }

    // 關鍵字搜尋（團隊名/提交者ID/老師欄位）
    if ($keyword !== '') {
      $sql .= " AND (t.tap_name LIKE ? OR t.tap_u_ID LIKE ? OR t.tap_teacher LIKE ?)";
      $k = "%{$keyword}%";
      array_push($params, $k, $k, $k);
    }

    // 依「選擇的表單屆別」過濾申請單
    if ($cohortFilter > 0) {
      $sql .= " AND EXISTS (
        SELECT 1
        FROM enrollmentdata e
        WHERE e.enroll_u_ID = t.tap_u_ID
          AND e.enroll_status = 1
          AND e.cohort_ID = ?
      )";
      $params[] = $cohortFilter;
    }

    // ✅ 排序：1 → 2 → 3 → 時間 DESC
    $sql .= " ORDER BY
      CASE t.tap_status
        WHEN 1 THEN 1
        WHEN 2 THEN 2
        WHEN 3 THEN 3
        ELSE 9
      END,
      t.tap_update_d DESC,
      t.tap_ID DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 收集所有會用到的 user_id（提交者 + members + 指導老師）
    $allIds = [];
    foreach ($list as $it) {
      if (!empty($it['tap_u_ID'])) $allIds[] = $it['tap_u_ID'];
      if (!empty($it['tap_teacher'])) $allIds[] = $it['tap_teacher'];
      $mem = json_decode($it['tap_member'] ?? '[]', true);
      if (is_array($mem)) foreach ($mem as $uid) $allIds[] = $uid;
    }
    $nameMap = fetch_user_name_map($conn, $allIds);

    foreach ($list as &$it) {
      $mem = json_decode($it['tap_member'] ?? '[]', true);
      $mem = is_array($mem) ? $mem : [];

      $memNames = [];
      foreach ($mem as $uid) $memNames[] = $nameMap[$uid] ?? $uid;

      $teaRaw = $it['tap_teacher'] ?? '';
      $teacherName = $nameMap[$teaRaw] ?? $teaRaw;

      $st = (int)($it['tap_status'] ?? 1);
      $it['teacher_name'] = $teacherName;
      $it['members_names'] = $memNames;
      $it['members_names_text'] = implode('、', $memNames);

      // 申請時間：你目前用 tap_rp_d / tap_update_d
      $it['apply_time'] = $it['tap_rp_d'] ?: ($it['tap_update_d'] ?: '');

      // ✅ 屆別：由 enrollmentdata/cohortdata
      $co = fetch_user_cohort_label($conn, (string)($it['tap_u_ID'] ?? ''));
      $it['cohort_ID'] = $co['cohort_ID'];
      $it['cohort_label'] = $co['cohort_label'] ?: '-';

      // 狀態顯示
      if ($st === 2) {
        $it['status_label'] = '退件';
        $it['opacity'] = 0.6;
      } elseif ($st === 3) {
        $it['status_label'] = '已組隊';
        $it['opacity'] = 0.6;
      } else {
        $it['status_label'] = '申請中';
        $it['opacity'] = 1;
      }
    }

    json_resp(true, 'success', ['list' => $list]);
  }

  // ------------------------------------------------------------
  // B) 詳情：查看才拉圖、備註、tap_remark + 屆別
  // ------------------------------------------------------------
  if ($do === 'get_detail') {
    $tap_ID = (int)($_GET['tap_ID'] ?? 0);
    if ($tap_ID <= 0) json_resp(false, 'tap_ID 不正確');

    $stmt = $conn->prepare("SELECT t.* FROM teamapply t WHERE t.tap_ID = ?");
    $stmt->execute([$tap_ID]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) json_resp(false, '找不到資料');

    $mem = json_decode($t['tap_member'] ?? '[]', true);
    $mem = is_array($mem) ? $mem : [];

    $ids = $mem;
    if (!empty($t['tap_u_ID'])) $ids[] = $t['tap_u_ID'];
    if (!empty($t['tap_teacher'])) $ids[] = $t['tap_teacher'];

    $nameMap = fetch_user_name_map($conn, $ids);

    $teacherName = $nameMap[$t['tap_teacher']] ?? ($t['tap_teacher'] ?? '');
    $membersNames = array_map(fn($uid)=>($nameMap[$uid] ?? $uid), $mem);

    $co = fetch_user_cohort_label($conn, (string)($t['tap_u_ID'] ?? ''));
    $cohort_ID = $co['cohort_ID'];

    // 指導老師目前帶組數與申請中組數、帶組上限
    $teacherId = (string)($t['tap_teacher'] ?? '');
    $teacherCurrent = 0;
    $teacherPending = 0;
    $teacherMax = null;

    if ($teacherId !== '' && $cohort_ID) {
      // tm_team 欄位名稱
      $tm_col = $conn->query("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'")->fetch() ? 'team_u_ID' : 'u_ID';

      // 已通過的實際帶組數（team_status = 1）
      $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT td.team_ID) AS cnt
        FROM teammember tm
        JOIN teamdata td ON td.team_ID = tm.team_ID
        WHERE tm.$tm_col = ? AND td.cohort_ID = ? AND td.team_status = 1 AND (tm.tm_status IS NULL OR tm.tm_status = 1)
      ");
      $stmt->execute([$teacherId, $cohort_ID]);
      $teacherCurrent = (int)$stmt->fetchColumn();

      // 尚在審核中的申請數量（tap_status = 1）
      $stmt = $conn->prepare("
        SELECT COUNT(*) FROM teamapply tap
        JOIN enrollmentdata ed ON tap.tap_u_ID = ed.enroll_u_ID
        WHERE tap.tap_teacher = ? AND tap.tap_status = 1
          AND ed.cohort_ID = ? AND ed.enroll_status = 1
      ");
      $stmt->execute([$teacherId, $cohort_ID]);
      $teacherPending = (int)$stmt->fetchColumn();

      // 帶組上限：teacherteamlimit > taf_ttl > 預設 3
      $teacherMax = null;
      try {
        $chk = $conn->query("SHOW TABLES LIKE 'teacherteamlimit'");
        if ($chk && $chk->rowCount() > 0) {
          $stmt = $conn->prepare("SELECT max_count FROM teacherteamlimit WHERE ttl_u_ID = ? AND cohort_ID = ? LIMIT 1");
          $stmt->execute([$teacherId, $cohort_ID]);
          $val = $stmt->fetchColumn();
          if ($val !== false && $val !== null && $val !== '') {
            $teacherMax = (int)$val;
          }
        }
      } catch (Exception $e) {}

      if ($teacherMax === null) {
        $defaultMax = 3;
        try {
          $chk = $conn->query("SHOW COLUMNS FROM teamapplyform LIKE 'taf_ttl'");
          if ($chk && $chk->rowCount() > 0) {
            $tafId = (int)($t['tap_taf_ID'] ?? 0);
            $ttl = null;
            if ($tafId > 0) {
              $stmt = $conn->prepare("SELECT taf_ttl FROM teamapplyform WHERE taf_ID = ? LIMIT 1");
              $stmt->execute([$tafId]);
              $ttl = $stmt->fetchColumn();
            }
            if ($ttl === null || $ttl === '') {
              $stmt = $conn->prepare("SELECT taf_ttl FROM teamapplyform WHERE taf_cohort_ID = ? AND taf_status IN (1,0) ORDER BY taf_ID DESC LIMIT 1");
              $stmt->execute([$cohort_ID]);
              $ttl = $stmt->fetchColumn();
            }
            if ($ttl !== false && $ttl !== null && $ttl !== '') {
              $v = (int)preg_replace('/[^0-9]/', '', (string)$ttl);
              if ($v > 0) $defaultMax = $v;
            }
          }
        } catch (Exception $e) {}
        $teacherMax = $defaultMax;
      }
    }

    $detail = [
      'tap_ID' => $t['tap_ID'],
      'tap_name' => $t['tap_name'] ?? '',
      'tap_status' => (int)($t['tap_status'] ?? 1),

      'tap_u_ID' => $t['tap_u_ID'] ?? '',
      'submitter_name' => $nameMap[$t['tap_u_ID']] ?? ($t['tap_u_ID'] ?? ''),

      // ✅ 屆別
      'cohort_ID' => $cohort_ID,
      'cohort_label' => $co['cohort_label'] ?: '-',

      'teacher_id' => $teacherId,
      'teacher_name' => $teacherName,
      'members_names' => $membersNames,

      'teacher_current_count' => $teacherCurrent,
      'teacher_pending_count' => $teacherPending,
      'teacher_max_count' => $teacherMax,

      'image_url' => $t['tap_url'] ?? '',
      'submitter_comment' => parse_submitter_comment($t['tap_des'] ?? ''),

      'tap_remark' => $t['tap_remark'] ?? '',
      'apply_time' => $t['tap_rp_d'] ?: ($t['tap_update_d'] ?: ''),
    ];

    json_resp(true, 'success', ['detail' => $detail]);
  }

  // ------------------------------------------------------------
  // C) 儲存審核備註
  // ------------------------------------------------------------
  if ($do === 'save_remark') {
    $tap_ID = (int)($_POST['tap_ID'] ?? 0);
    $remark = trim($_POST['tap_remark'] ?? '');
    if ($tap_ID <= 0) json_resp(false, 'tap_ID 不正確');

    $stmt = $conn->prepare("UPDATE teamapply SET tap_remark = ?, tap_update_d = NOW() WHERE tap_ID = ?");
    $stmt->execute([$remark, $tap_ID]);

    json_resp(true, '已儲存備註');
  }

  // ------------------------------------------------------------
  // D) 通過 / 退件
  // - 通過：3
  // - 退件：2  （✅ 依你最新規則）
  // ------------------------------------------------------------
  if ($do === 'approve') {
    $tap_ID = (int)($_POST['tap_ID'] ?? 0);
    if ($tap_ID <= 0) json_resp(false, 'tap_ID 不正確');

    // 權限檢查：科辦或主任
    $reviewer = $_SESSION['u_ID'] ?? null;
    $stmt = $conn->prepare("SELECT COUNT(*) FROM userrolesdata WHERE ur_u_ID = ? AND role_ID IN (1,2) AND user_role_status = 1");
    $stmt->execute([$reviewer]);
    if (!$stmt->fetchColumn()) json_resp(false, '無權限執行此操作');

    // 取得申請單
    $stmt = $conn->prepare("SELECT * FROM teamapply WHERE tap_ID = ?");
    $stmt->execute([$tap_ID]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) json_resp(false, '找不到資料');

    $conn->beginTransaction();
    try {
      // 取得提交者屆別
      $co = fetch_user_cohort_label($conn, (string)($app['tap_u_ID'] ?? ''));
      $cohort = $co['cohort_ID'] ?? null;

      // 類組
      $des = json_decode($app['tap_des'] ?? '{}', true);
      $g_id = $app['tap_group'] ?? $des['group_id'] ?? null;
      if (!$g_id) {
        $g_id = $conn->query("SELECT group_ID FROM groupdata WHERE group_status=1 LIMIT 1")->fetchColumn();
      }

      // 建立 Team
      $stmt = $conn->prepare("INSERT INTO teamdata (group_ID, team_project_name, cohort_ID, team_status, team_update_d, team_url) VALUES (?, ?, ?, 1, NOW(), ?)");
      $stmt->execute([$g_id, $app['tap_name'], $cohort, $app['tap_url']]);
      $team_ID = $conn->lastInsertId();

      // 決定 teammember 的 user 欄位
      $col = $conn->query("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'")->fetch() ? 'team_u_ID' : 'u_ID';

      // 建立 Team Member：學生 + 申請者 + 老師（若需要）
      $members = json_decode($app['tap_member'] ?? '[]', true) ?: [];
      // 確保提交者在名單內
      if (!in_array($app['tap_u_ID'], $members)) $members[] = $app['tap_u_ID'];

      $stmt = $conn->prepare("INSERT INTO teammember (team_ID, $col, tm_status, tm_updated_d, tm_url) VALUES (?, ?, 1, NOW(), ?)");
      foreach ($members as $uid) {
        $stmt->execute([$team_ID, $uid, $app['tap_url']]);
      }

      // 若要將老師也加入 teammember（系統中老師以同表方式紀錄帶組），則加入老師
      if (!empty($app['tap_teacher'])) {
        try {
          $stmt->execute([$team_ID, $app['tap_teacher'], $app['tap_url']]);
        } catch (Exception $e) {
          // 若失敗（重複鍵等），忽略
        }
      }

      // 更新申請單狀態
      $conn->prepare("UPDATE teamapply SET tap_status = 3, tap_rp_u_ID = ?, tap_rp_d = NOW(), tap_update_d = NOW() WHERE tap_ID = ?")->execute([$reviewer, $tap_ID]);

      // 通知
      if (function_exists('add_sys_msg')) {
        add_sys_msg('專題申請通過', "您的專題「{$app['tap_name']}」已通過審核。", $members);
      }

      $conn->commit();
      json_resp(true, '已通過（已組隊）');
    } catch (Exception $e) {
      $conn->rollBack();
      throw $e;
    }
  }

  if ($do === 'reject') {
    $tap_ID = (int)($_POST['tap_ID'] ?? 0);
    if ($tap_ID <= 0) json_resp(false, 'tap_ID 不正確');
    $conn->prepare("UPDATE teamapply SET tap_status = 2, tap_update_d = NOW() WHERE tap_ID = ?")->execute([$tap_ID]);
    json_resp(true, '已退件');
  }

  // ------------------------------------------------------------
  // E) 取得指導老師帶組統計（含目前帶組數、設定上限、成員限制）
  // ------------------------------------------------------------
  if ($do === 'get_teacher_stats') {
    $cohort_ID = (int)($_GET['cohort_ID'] ?? 0);
    $taf_ID = (int)($_GET['taf_ID'] ?? 0);
    if ($cohort_ID <= 0) json_resp(false, '請提供屆別');

    $tm_col = $conn->query("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'")->fetch() ? 'team_u_ID' : 'u_ID';

    // 該屆別所有指導老師：必須在 enrollmentdata 中有此屆別且 role_ID=4（指導老師）的紀錄才顯示
    $chkRole = $conn->query("SHOW COLUMNS FROM enrollmentdata LIKE 'role_ID'");
    if ($chkRole && $chkRole->rowCount() > 0) {
      $sql = "SELECT DISTINCT u.u_ID, u.u_name
              FROM enrollmentdata e
              JOIN userdata u ON u.u_ID = e.enroll_u_ID
              WHERE e.cohort_ID = ? AND e.enroll_status = 1 AND e.role_ID = 4
                AND (u.u_status = 1 OR u.u_status IS NULL)
              ORDER BY u.u_name";
      $stmt = $conn->prepare($sql);
      $stmt->execute([$cohort_ID]);
      $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
      // 若 enrollmentdata 無 role_ID 欄位，退回用 userrolesdata（全域指導老師）
      $ur_col = $conn->query("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'")->fetch() ? 'ur_u_ID' : 'u_ID';
      $sql = "SELECT DISTINCT u.u_ID, u.u_name
              FROM userdata u
              JOIN userrolesdata ur ON u.u_ID = ur.$ur_col
              WHERE ur.role_ID = 4 AND ur.user_role_status = 1 AND (u.u_status = 1 OR u.u_status IS NULL)
              ORDER BY u.u_name";
      $teachers = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    $hasTtl = false;
    try {
      $chk = $conn->query("SHOW TABLES LIKE 'teacherteamlimit'");
      $hasTtl = $chk && $chk->rowCount() > 0;
    } catch (Exception $e) {}

    $limitsMap = [];
    if ($hasTtl) {
      $stmt = $conn->prepare("SELECT ttl_u_ID, max_count FROM teacherteamlimit WHERE cohort_ID = ?");
      $stmt->execute([$cohort_ID]);
      while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $limitsMap[$r['ttl_u_ID']] = (int)$r['max_count'];
      }
    }

    // 預設 max_count：1) teacherteamlimit 個別設定 2) 所選表單 taf_ttl 3) 該屆別任一表單 taf_ttl 4) 預設 3
    $defaultMaxCount = 3;
    try {
      $chk = $conn->query("SHOW COLUMNS FROM teamapplyform LIKE 'taf_ttl'");
      if ($chk && $chk->rowCount() > 0) {
        $ttl = null;
        if ($taf_ID > 0) {
          $stmt = $conn->prepare("SELECT taf_ttl FROM teamapplyform WHERE taf_ID = ? LIMIT 1");
          $stmt->execute([$taf_ID]);
          $ttl = $stmt->fetchColumn();
        }
        if (($ttl === false || $ttl === null || $ttl === '') && $cohort_ID > 0) {
          $stmt = $conn->prepare("SELECT taf_ttl FROM teamapplyform WHERE taf_cohort_ID = ? AND taf_status IN (1,0) ORDER BY taf_ID DESC LIMIT 1");
          $stmt->execute([$cohort_ID]);
          $ttl = $stmt->fetchColumn();
        }
        if ($ttl !== false && $ttl !== null && $ttl !== '') {
          $v = (int)preg_replace('/[^0-9]/', '', (string)$ttl);
          if ($v > 0) $defaultMaxCount = $v;
        }
      }
    } catch (Exception $e) {}

    // 成員限制（teammemberlimit by cohort）
    $memberMin = 1;
    $memberMax = 4;
    try {
      $chk = $conn->query("SHOW COLUMNS FROM teammemberlimit LIKE 'cohort_ID'");
      if ($chk && $chk->rowCount() > 0) {
        $stmt = $conn->prepare("SELECT min_count, max_count FROM teammemberlimit WHERE cohort_ID = ? LIMIT 1");
        $stmt->execute([$cohort_ID]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
          $memberMin = (int)($row['min_count'] ?? 1);
          $memberMax = (int)($row['max_count'] ?? 4);
        }
      } else {
        $stmt = $conn->prepare("SELECT taf_ttm_ID FROM teamapplyform WHERE taf_cohort_ID = ? AND taf_status = 1 ORDER BY taf_ID DESC LIMIT 1");
        $stmt->execute([$cohort_ID]);
        $ttmId = (int)($stmt->fetchColumn() ?: 0);
        if ($ttmId > 0) {
          $stmt = $conn->prepare("SELECT min_count, max_count FROM teammemberlimit WHERE ttm_ID = ? LIMIT 1");
          $stmt->execute([$ttmId]);
          if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $memberMin = (int)($row['min_count'] ?? 1);
            $memberMax = (int)($row['max_count'] ?? 4);
          }
        }
      }
    } catch (Exception $e) {}

    $result = [];
    foreach ($teachers as $t) {
      $uid = $t['u_ID'];

      $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT t.team_ID) as cnt
        FROM teammember tm
        JOIN teamdata t ON t.team_ID = tm.team_ID
        WHERE tm.$tm_col = ? AND t.cohort_ID = ? AND t.team_status = 1 AND tm.tm_status = 1
      ");
      $stmt->execute([$uid, $cohort_ID]);
      $current_count = (int)$stmt->fetchColumn();

      $stmt = $conn->prepare("
        SELECT COUNT(*) FROM teamapply tap
        JOIN enrollmentdata ed ON tap.tap_u_ID = ed.enroll_u_ID
        WHERE tap.tap_teacher = ? AND tap.tap_status = 1 AND ed.cohort_ID = ? AND ed.enroll_status = 1
      ");
      $stmt->execute([$uid, $cohort_ID]);
      $apply_count = (int)$stmt->fetchColumn();

      // max_count：優先 teacherteamlimit，無則用 teamapplyform.taf_ttl，再無則預設 3
      $maxCount = $limitsMap[$uid] ?? null;
      if ($maxCount === null) {
        $maxCount = $defaultMaxCount;
      }

      $result[] = [
        'u_ID' => $uid,
        'u_name' => $t['u_name'],
        'current_count' => $current_count,
        'apply_count' => $apply_count,
        'max_count' => $maxCount,
        'member_min' => $memberMin,
        'member_max' => $memberMax,
      ];
    }

    json_resp(true, 'success', ['teachers' => $result]);
  }

  // ------------------------------------------------------------
  // F0) 取得整屆類組比例（group 分布）
  // ------------------------------------------------------------
  if ($do === 'get_group_distribution') {
    $cohort_ID = (int)($_GET['cohort_ID'] ?? 0);
    if ($cohort_ID <= 0) json_resp(false, '請提供屆別');

    // 取出啟用中的類組，並計算該屆別中每個類組的組數（team_status=1 或 3 視為有效）
    $sql = "
      SELECT 
        g.group_ID,
        g.group_name,
        g.group_status,
        COUNT(DISTINCT t.team_ID) AS team_count
      FROM groupdata g
      LEFT JOIN teamdata t 
        ON t.group_ID = g.group_ID 
       AND t.cohort_ID = :cohort_ID
       AND t.team_status IN (1,3)
      WHERE g.group_status IN (0,1)
      GROUP BY g.group_ID, g.group_name, g.group_status
      ORDER BY g.group_status DESC, g.group_ID ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':cohort_ID' => $cohort_ID]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $groups = [];
    $total = 0;
    foreach ($rows as $r) {
      $cnt = (int)($r['team_count'] ?? 0);
      $total += $cnt;
      $groups[] = [
        'group_ID' => (int)$r['group_ID'],
        'group_name' => $r['group_name'] ?: ('類組 ' . $r['group_ID']),
        'group_status' => (int)($r['group_status'] ?? 0),
        'team_count' => $cnt,
      ];
    }

    json_resp(true, 'success', [
      'groups' => $groups,
      'total_teams' => $total,
    ]);
  }

  // ------------------------------------------------------------
  // F) 設定指導老師帶組上限
  // ------------------------------------------------------------
  if ($do === 'set_teacher_team_limit') {
    $teacher_id = trim($_POST['teacher_id'] ?? '');
    $cohort_ID = (int)($_POST['cohort_ID'] ?? 0);
    $max_count = (int)($_POST['max_count'] ?? 0);

    if (empty($teacher_id)) json_resp(false, '請提供指導老師');
    if ($cohort_ID <= 0) json_resp(false, '請提供屆別');
    if ($max_count < 0) json_resp(false, '帶組數不能為負數');

    $chk = $conn->query("SHOW TABLES LIKE 'teacherteamlimit'");
    if (!$chk || $chk->rowCount() === 0) json_resp(false, '系統尚未啟用帶組限制功能');

    $ur_col = $conn->query("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'")->fetch() ? 'ur_u_ID' : 'u_ID';
    $stmt = $conn->prepare("SELECT COUNT(*) FROM userrolesdata WHERE $ur_col = ? AND role_ID = 4 AND user_role_status = 1");
    $stmt->execute([$teacher_id]);
    if (!$stmt->fetchColumn()) json_resp(false, '該用戶不是指導老師');

    $stmt = $conn->prepare("SELECT ttl_ID FROM teacherteamlimit WHERE ttl_u_ID = ? AND cohort_ID = ?");
    $stmt->execute([$teacher_id, $cohort_ID]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
      $hasTtlUpdated = $conn->query("SHOW COLUMNS FROM teacherteamlimit LIKE 'ttl_updated_at'")->rowCount() > 0;
      $sql = $hasTtlUpdated
        ? "UPDATE teacherteamlimit SET max_count = ?, ttl_updated_at = NOW() WHERE ttl_ID = ?"
        : "UPDATE teacherteamlimit SET max_count = ? WHERE ttl_ID = ?";
      $stmt = $conn->prepare($sql);
      $stmt->execute($hasTtlUpdated ? [$max_count, $existing['ttl_ID']] : [$max_count, $existing['ttl_ID']]);
    } else {
      $hasCreated = $conn->query("SHOW COLUMNS FROM teacherteamlimit LIKE 'created_at'")->rowCount() > 0;
      $hasTtlCreated = $conn->query("SHOW COLUMNS FROM teacherteamlimit LIKE 'ttl_created_at'")->rowCount() > 0;
      if ($hasTtlCreated || $hasCreated) {
        $stmt = $conn->prepare("INSERT INTO teacherteamlimit (ttl_u_ID, cohort_ID, max_count, " . ($hasTtlCreated ? 'ttl_created_at' : 'created_at') . ") VALUES (?, ?, ?, NOW())");
      } else {
        $stmt = $conn->prepare("INSERT INTO teacherteamlimit (ttl_u_ID, cohort_ID, max_count) VALUES (?, ?, ?)");
      }
      $stmt->execute([$teacher_id, $cohort_ID, $max_count]);
    }

    json_resp(true, '設定成功');
  }

  // ------------------------------------------------------------
  // F2) 批量設定指導老師帶組上限
  // ------------------------------------------------------------
  if ($do === 'batch_set_teacher_team_limit') {
    $cohort_ID = (int)($_POST['cohort_ID'] ?? 0);
    $max_count = (int)($_POST['max_count'] ?? 0);
    $teacher_ids = $_POST['teacher_ids'] ?? [];

    if ($cohort_ID <= 0) json_resp(false, '請提供屆別');
    if ($max_count < 0) json_resp(false, '帶組數不能為負數');
    if (!is_array($teacher_ids) || empty($teacher_ids)) json_resp(false, '請選擇要設定的老師');

    $chk = $conn->query("SHOW TABLES LIKE 'teacherteamlimit'");
    if (!$chk || $chk->rowCount() === 0) json_resp(false, '系統尚未啟用帶組限制功能');

    $ur_col = $conn->query("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'")->fetch() ? 'ur_u_ID' : 'u_ID';
    $updated = 0;
    foreach ($teacher_ids as $tid) {
      $tid = trim($tid);
      if (empty($tid)) continue;
      $stmt = $conn->prepare("SELECT COUNT(*) FROM userrolesdata WHERE $ur_col = ? AND role_ID = 4 AND user_role_status = 1");
      $stmt->execute([$tid]);
      if (!$stmt->fetchColumn()) continue;

      $stmt = $conn->prepare("SELECT ttl_ID FROM teacherteamlimit WHERE ttl_u_ID = ? AND cohort_ID = ?");
      $stmt->execute([$tid, $cohort_ID]);
      $existing = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($existing) {
        $hasTtlUpdated = $conn->query("SHOW COLUMNS FROM teacherteamlimit LIKE 'ttl_updated_at'")->rowCount() > 0;
        $sql = $hasTtlUpdated ? "UPDATE teacherteamlimit SET max_count = ?, ttl_updated_at = NOW() WHERE ttl_ID = ?" : "UPDATE teacherteamlimit SET max_count = ? WHERE ttl_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute($hasTtlUpdated ? [$max_count, $existing['ttl_ID']] : [$max_count, $existing['ttl_ID']]);
      } else {
        $hasCreated = $conn->query("SHOW COLUMNS FROM teacherteamlimit LIKE 'created_at'")->rowCount() > 0;
        $hasTtlCreated = $conn->query("SHOW COLUMNS FROM teacherteamlimit LIKE 'ttl_created_at'")->rowCount() > 0;
        if ($hasTtlCreated || $hasCreated) {
          $stmt = $conn->prepare("INSERT INTO teacherteamlimit (ttl_u_ID, cohort_ID, max_count, " . ($hasTtlCreated ? 'ttl_created_at' : 'created_at') . ") VALUES (?, ?, ?, NOW())");
        } else {
          $stmt = $conn->prepare("INSERT INTO teacherteamlimit (ttl_u_ID, cohort_ID, max_count) VALUES (?, ?, ?)");
        }
        $stmt->execute([$tid, $cohort_ID, $max_count]);
      }
      $updated++;
    }

    json_resp(true, "已更新 {$updated} 位老師的帶組上限");
  }

  // ------------------------------------------------------------
  // G) 設定屆別成員限制（每組最少/最多人數）
  // ------------------------------------------------------------
  if ($do === 'set_member_limit') {
    $cohort_ID = (int)($_POST['cohort_ID'] ?? 0);
    $min_count = (int)($_POST['min_count'] ?? 1);
    $max_count = (int)($_POST['max_count'] ?? 4);

    if ($cohort_ID <= 0) json_resp(false, '請提供屆別');
    if ($min_count < 1 || $max_count < 1) json_resp(false, '人數不得小於 1');
    if ($min_count > $max_count) json_resp(false, '最小人數不可大於最大人數');

    $chk = $conn->query("SHOW TABLES LIKE 'teammemberlimit'");
    if (!$chk || $chk->rowCount() === 0) json_resp(false, '系統尚未啟用成員限制功能');

    $stmt = $conn->prepare("SELECT ttm_ID FROM teammemberlimit WHERE cohort_ID = ? LIMIT 1");
    $stmt->execute([$cohort_ID]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $hasTtmUpdate = $conn->query("SHOW COLUMNS FROM teammemberlimit LIKE 'ttm_update_at'")->rowCount() > 0;
    if ($existing) {
      $sql = $hasTtmUpdate
        ? "UPDATE teammemberlimit SET min_count = ?, max_count = ?, ttm_update_at = NOW() WHERE ttm_ID = ?"
        : "UPDATE teammemberlimit SET min_count = ?, max_count = ? WHERE ttm_ID = ?";
      $stmt = $conn->prepare($sql);
      $stmt->execute($hasTtmUpdate ? [$min_count, $max_count, $existing['ttm_ID']] : [$min_count, $max_count, $existing['ttm_ID']]);
    } else {
      $hasTtmCreated = $conn->query("SHOW COLUMNS FROM teammemberlimit LIKE 'ttm_created_at'")->rowCount() > 0;
      $cols = $hasTtmCreated ? '(cohort_ID, min_count, max_count, ttm_created_at, ttm_update_at)' : '(cohort_ID, min_count, max_count)';
      $vals = $hasTtmCreated ? '(?, ?, ?, NOW(), NOW())' : '(?, ?, ?)';
      $stmt = $conn->prepare("INSERT INTO teammemberlimit $cols VALUES $vals");
      $stmt->execute($hasTtmCreated ? [$cohort_ID, $min_count, $max_count] : [$cohort_ID, $min_count, $max_count]);
    }

    json_resp(true, '已儲存');
  }

  json_resp(false, '未知操作');

} catch (Exception $e) {
  json_resp(false, $e->getMessage());
}
