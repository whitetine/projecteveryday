<?php
// modules/team_apply_admin_api.php
session_start();

require_once __DIR__ . '/../includes/pdo.php';
require_once __DIR__ . '/../includes/utils.php'; // 必須要有 json_ok/json_err

header('Content-Type: application/json; charset=utf-8');

// if (empty($_SESSION['u_ID'])) json_err('請先登入', 'NO_LOGIN', 401);
// $u_ID = (int)$_SESSION['u_ID'];
// $currentCohort = $_SESSION['cohort_ID'] ?? 0;

// $roleCheck = $conn->prepare("
//   SELECT COUNT(*)
//   FROM enrollmentdata
//   WHERE enroll_u_ID = ?
//     AND role_ID = 2
//     AND cohort_ID = ?
//     AND enroll_status = 1
// ");

// $roleCheck->execute([$u_ID, $currentCohort]);

// if (!(int)$roleCheck->fetchColumn()) json_err('無權限，需為科辦', 'NO_PERMISSION', 403);

$do = $_GET['do'] ?? ($_POST['do'] ?? '');

try {
  switch ($do) {

    // ✅ 你問的：屆別選項
    case 'get_cohort_options': {
      $rows = $conn->query("
        SELECT cohort_ID, cohort_name, cohort_status
        FROM cohortdata
        ORDER BY cohort_ID DESC
      ")->fetchAll(PDO::FETCH_ASSOC);

      json_ok(['cohorts' => $rows]);
    }

    case 'admin_get_forms': {
      $rows = $conn->query("SELECT * FROM teamapplyform ORDER BY taf_ID DESC")
                   ->fetchAll(PDO::FETCH_ASSOC);
      json_ok(['forms' => $rows]);
    }

    case 'admin_save_form': {
      $p = $_POST;

      $taf_ID = (int)($p['taf_ID'] ?? 0);
      $title  = trim($p['taf_title'] ?? '');
      $ttl    = (int)($p['taf_ttl'] ?? 0);
      $status = (int)($p['taf_status'] ?? 1);
      $note   = trim($p['taf_note'] ?? '');

      // ⚠️ 你前端是 multiple：formEdit.cohorts
      // 我們用 cohorts[] 來收，沒傳就 fallback taf_cohort_ID
      $cohorts = $p['cohorts'] ?? null;
      if (is_string($cohorts)) {
        // 有些人會送 "108,109" 這種
        $cohorts = array_filter(array_map('trim', explode(',', $cohorts)));
      }
      if (!is_array($cohorts) || !count($cohorts)) {
        $single = (int)($p['taf_cohort_ID'] ?? 0);
        if ($single > 0) $cohorts = [$single];
      }

      $min = (int)($p['min_count'] ?? 1);
      $max = (int)($p['max_count'] ?? 4);

      if ($title === '') json_err('請填寫表單名稱');
      if (!$cohorts || !count($cohorts)) json_err('請至少選擇一個屆別');
      if ($min < 1 || $max < 1) json_err('人數不得小於 1');
      if ($min > $max) json_err('最小人數不可大於最大人數');

      $conn->beginTransaction();

      try {
        $created = 0;
        $updated = 0;
        $newIDs  = [];

        foreach ($cohorts as $cohort_ID_raw) {
          $cohort_ID = (int)$cohort_ID_raw;
          if ($cohort_ID <= 0) continue;

          // 1) upsert teammemberlimit by cohort
          $chk = $conn->prepare("SELECT ttm_ID FROM teammemberlimit WHERE cohort_ID = ? LIMIT 1");
          $chk->execute([$cohort_ID]);
          $ttm_ID = (int)($chk->fetchColumn() ?: 0);

          if ($ttm_ID > 0) {
            $uStmt = $conn->prepare("
              UPDATE teammemberlimit
              SET min_count = ?, max_count = ?, ttm_update_at = NOW()
              WHERE ttm_ID = ?
            ");
            $uStmt->execute([$min, $max, $ttm_ID]);
          } else {
            $iStmt = $conn->prepare("
              INSERT INTO teammemberlimit (cohort_ID, min_count, max_count, ttm_created_at, ttm_update_at)
              VALUES (?, ?, ?, NOW(), NOW())
            ");
            $iStmt->execute([$cohort_ID, $min, $max]);
            $ttm_ID = (int)$conn->lastInsertId();
          }

          // 2) upsert teamapplyform (如果 taf_ID>0 就更新同一筆；否則為每屆建立新表單)
          if ($taf_ID > 0) {
            $stmt = $conn->prepare("
              UPDATE teamapplyform
              SET taf_title = ?,
                  taf_cohort_ID = ?,
                  taf_ttl = ?,
                  taf_ttm_ID = ?,
                  taf_status = ?,
                  taf_note = ?,
                  taf_updated_d = NOW()
              WHERE taf_ID = ?
            ");
            $stmt->execute([$title, $cohort_ID, $ttl, $ttm_ID, $status, $note, $taf_ID]);
            $updated++;
          } else {
            $stmt = $conn->prepare("
              INSERT INTO teamapplyform
                (taf_title, taf_cohort_ID, taf_ttl, taf_ttm_ID, taf_status, taf_note, taf_created_d, taf_updated_d)
              VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$title, $cohort_ID, $ttl, $ttm_ID, $status, $note]);
            $newID = (int)$conn->lastInsertId();
            $newIDs[] = $newID;
            $created++;
          }
        }

        $conn->commit();

        json_ok([
          'msg' => $taf_ID > 0 ? "已更新（{$updated}筆）" : "已建立（{$created}筆）",
          'created' => $created,
          'updated' => $updated,
          'taf_IDs' => $newIDs
        ]);

      } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        json_err('儲存失敗：' . $e->getMessage());
      }
    }

    case 'admin_get_controls': {
      $taf_ID = (int)($_GET['taf_ID'] ?? 0);
      if ($taf_ID <= 0) json_err('taf_ID 不正確');

      $stmt = $conn->prepare("
        SELECT * FROM teamapplycontrol
        WHERE tpc_taf_ID = ?
        ORDER BY tpc_ID ASC
      ");
      $stmt->execute([$taf_ID]);
      json_ok(['controls' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'admin_save_control': {
      $p = $_POST;
      $tpc_ID      = (int)($p['tpc_ID'] ?? 0);
      $tpc_name    = trim($p['tpc_name'] ?? '');
      $tpc_require = (int)($p['tpc_require'] ?? 0);
      $tpc_show    = (int)($p['tpc_show'] ?? 0);
      $tpc_taf_ID  = (int)($p['tpc_taf_ID'] ?? 0);

      if ($tpc_name === '' || $tpc_taf_ID <= 0) json_err('參數不完整');

      if ($tpc_ID > 0) {
        $stmt = $conn->prepare("
          UPDATE teamapplycontrol
          SET tpc_name=?, tpc_require=?, tpc_show=?, tpc_status=1
          WHERE tpc_ID=?
        ");
        $stmt->execute([$tpc_name, $tpc_require, $tpc_show, $tpc_ID]);
        json_ok(['msg' => '已更新']);
      } else {
        $stmt = $conn->prepare("
          INSERT INTO teamapplycontrol (tpc_taf_ID, tpc_name, tpc_require, tpc_show, tpc_status, tpc_created_d)
          VALUES (?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$tpc_taf_ID, $tpc_name, $tpc_require, $tpc_show]);
        json_ok(['msg' => '已新增', 'tpc_ID' => (int)$conn->lastInsertId()]);
      }
    }
case 'admin_delete_control': {
  $tpc_ID = (int)($_POST['tpc_ID'] ?? 0);
  if ($tpc_ID <= 0) json_err('tpc_ID 不正確');

  // 軟刪除：設為停用
  $stmt = $conn->prepare("UPDATE teamapplycontrol SET tpc_status = 0 WHERE tpc_ID = ?");
  $stmt->execute([$tpc_ID]);

  json_ok(['msg' => '已移除']);
  break;
}
    case 'admin_get_limits': {
      $rows = $conn->query("SELECT * FROM teammemberlimit ORDER BY ttm_ID ASC")
                   ->fetchAll(PDO::FETCH_ASSOC);
      json_ok(['limits' => $rows]);
    }

    case 'admin_save_limit': {
      $p = $_POST;
      $ttm_ID = (int)($p['ttm_ID'] ?? 0);
      $cohort = (int)($p['cohort_ID'] ?? 0);
      $min    = (int)($p['min_count'] ?? 1);
      $max    = (int)($p['max_count'] ?? 4);

      if ($cohort <= 0) json_err('請選擇屆別');
      if ($min < 1 || $max < 1) json_err('人數不得小於 1');
      if ($min > $max) json_err('最小人數不可大於最大人數');

      if ($ttm_ID > 0) {
        $stmt = $conn->prepare("
          UPDATE teammemberlimit
          SET cohort_ID=?, min_count=?, max_count=?, ttm_update_at=NOW()
          WHERE ttm_ID=?
        ");
        $stmt->execute([$cohort, $min, $max, $ttm_ID]);
        json_ok(['msg' => '已更新']);
      } else {
        $stmt = $conn->prepare("
          INSERT INTO teammemberlimit (cohort_ID, min_count, max_count, ttm_created_at, ttm_update_at)
          VALUES (?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$cohort, $min, $max]);
        json_ok(['msg' => '已新增', 'ttm_ID' => (int)$conn->lastInsertId()]);
      }
    }

    case 'admin_get_table_comments': {
      $table = trim($_GET['table'] ?? 'teamapply');
      if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) json_err('table 名稱不正確');

      $stmt = $conn->prepare("
        SELECT COLUMN_NAME, COLUMN_COMMENT
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION
      ");
      $stmt->execute([$table]);
      json_ok(['columns' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    default:
      json_err('未知操作：' . $do);
  }
} catch (Exception $e) {
  json_err('API 例外：' . $e->getMessage());
}
