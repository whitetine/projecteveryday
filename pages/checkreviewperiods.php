<?php
session_start();
include "pdo.php";

// 可選：登入檢查
// if (!isset($_SESSION['u_ID'])) {
//     echo "<script>alert('請先登入');location.href='index.php';</script>";
//     exit;
// }

$sort = $_GET['sort'] ?? ($_POST['sort'] ?? 'created');  // created | start | end | active
$sort_qs = '?sort=' . urlencode($sort);

/* ===============================
   CRUD：新增 / 修改 / 刪除
   =============================== */
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    $sql = "INSERT INTO perioddata
        (period_start_d, period_end_d, period_title, pe_target_ID, cohort_ID, pe_created_d, pe_created_u_ID, pe_role_ID, pe_status)
        VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $_POST['period_start_d'] ?? '',
        $_POST['period_end_d'] ?? '',
        $_POST['period_title'] ?? '',
        $_POST['pe_target_ID'] ?? '',
        $_POST['cohort_ID'] ?? '',
        $_SESSION['u_ID'] ?? null,
        $_SESSION['role_ID'] ?? null,
        isset($_POST['pe_status']) ? 1 : 0
    ]);
    header("Location: checkreviewperiods.php" . $sort_qs);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'update') {
    $sql = "UPDATE perioddata
            SET period_start_d=?, period_end_d=?, period_title=?, pe_target_ID=?, cohort_ID=?, pe_status=?
            WHERE period_ID=?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $_POST['period_start_d'] ?? '',
        $_POST['period_end_d'] ?? '',
        $_POST['period_title'] ?? '',
        $_POST['pe_target_ID'] ?? '',
        $_POST['cohort_ID'] ?? '',
        isset($_POST['pe_status']) ? 1 : 0,
        $_POST['period_ID'] ?? 0
    ]);
    header("Location: checkreviewperiods.php" . $sort_qs);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $stmt = $conn->prepare("DELETE FROM perioddata WHERE period_ID=?");
    $stmt->execute([$_POST['period_ID'] ?? 0]);
    header("Location: checkreviewperiods.php" . $sort_qs);
    exit;
}

/* ===============================
   排序條件
   =============================== */
switch ($sort) {
    case 'start':
        $orderBy = 'ORDER BY p.period_start_d DESC, p.period_ID DESC';
        break;
    case 'end':
        $orderBy = 'ORDER BY p.period_end_d DESC, p.period_ID DESC';
        break;
    case 'active':
        $orderBy = 'ORDER BY p.pe_status DESC, p.pe_created_d DESC, p.period_ID DESC';
        break;
    case 'created':
    default:
        $orderBy = 'ORDER BY p.pe_created_d DESC, p.period_ID DESC';
        break;
}

/* ===============================
   取得 perioddata + cohortdata 名稱
   =============================== */
$sql = "SELECT p.period_ID, p.period_start_d, p.period_end_d, p.period_title,
               p.pe_target_ID, p.cohort_ID, p.pe_status, p.pe_created_d,
               c.cohort_name, c.year_label
        FROM perioddata p
        LEFT JOIN cohortdata c ON p.cohort_ID = c.cohort_ID
        $orderBy";
$stmt = $conn->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   取得屆別下拉選單
   =============================== */
$cohortStmt = $conn->prepare("
    SELECT cohort_ID, cohort_name, year_label
    FROM cohortdata
    ORDER BY cohort_ID ASC
");
$cohortStmt->execute();
$cohorts = $cohortStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   建立時間排序序號
   =============================== */
$rankByCreated = [];
if ($rows) {
    $tmp = $rows;
    usort($tmp, function($a, $b){
        $c = strcmp($a['pe_created_d'], $b['pe_created_d']); // 舊→新
        if ($c !== 0) return $c;
        return $a['period_ID'] <=> $b['period_ID'];
    });
    $rank = 1;
    foreach ($tmp as $r) $rankByCreated[$r['period_ID']] = $rank++;
}

// HTML escape
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title>評分時段管理</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>.table td, .table th { vertical-align: middle; }</style>
</head>
<body class="p-3">
<div class="container">
  <h3 class="mb-3">評分時段管理</h3>

  <!-- 排序 -->
  <form method="get" class="mb-3">
    <label class="form-label">排序條件：</label>
    <select name="sort" onchange="this.form.submit()" class="form-select d-inline w-auto">
      <option value="created" <?= $sort==='created'?'selected':'' ?>>建立時間(預設)</option>
      <option value="start"   <?= $sort==='start'  ?'selected':'' ?>>開始日</option>
      <option value="end"     <?= $sort==='end'    ?'selected':'' ?>>結束日</option>
      <option value="active"  <?= $sort==='active' ?'selected':'' ?>>啟用狀態</option>
    </select>
  </form>

  <!-- 新增/更新表單 -->
  <div class="card mb-4">
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="action" id="form_action" value="create">
        <input type="hidden" name="period_ID" id="period_ID">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">

        <div class="col-md-3">
          <label class="form-label">開始日</label>
          <input type="date" class="form-control" name="period_start_d" id="period_start_d" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">結束日</label>
          <input type="date" class="form-control" name="period_end_d" id="period_end_d" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">標題</label>
          <input type="text" class="form-control" name="period_title" id="period_title" placeholder="例如：第一週" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">指定團隊</label>
          <input type="text" class="form-control" name="pe_target_ID" id="pe_target_ID" placeholder="例如：全部" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">屆別</label>
          <select class="form-select" name="cohort_ID" id="cohort_ID" required>
            <option value="">請選擇屆別</option>
            <?php foreach ($cohorts as $c): ?>
              <option value="<?= h($c['cohort_ID']) ?>">
                <?= h($c['cohort_name']) ?> (<?= h($c['year_label']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="pe_status" id="pe_status">
            <label class="form-check-label" for="pe_status">啟用</label>
          </div>
        </div>
        <div class="col-12">
          <button class="btn btn-primary" type="submit" id="submitBtn">新增</button>
          <button class="btn btn-secondary" type="button" onclick="resetForm()">清空</button>
        </div>
      </form>
    </div>
  </div>

  <!-- 資料表 -->
  <div class="table-responsive">
    <table class="table table-bordered table-striped">
      <thead class="table-light">
        <tr>
          <th style="width:80px">序號</th>
          <th style="width:130px">開始日</th>
          <th style="width:130px">結束日</th>
          <th>標題</th>
          <th>指定團隊</th>
          <th>屆別名稱</th>
          <th style="width:90px">啟用</th>
          <th style="width:210px">建立時間</th>
          <th style="width:220px">操作</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h($rankByCreated[$r['period_ID']] ?? '') ?></td>
          <td><?= h($r['period_start_d']) ?></td>
          <td><?= h($r['period_end_d']) ?></td>
          <td><?= h($r['period_title']) ?></td>
          <td><?= h($r['pe_target_ID']) ?></td>
          <td><?= h($r['cohort_name'] ?? '未設定') ?> (<?= h($r['year_label'] ?? '-') ?>)</td>
          <td>
            <?= $r['pe_status']
              ? '<span class="badge text-bg-success">是</span>'
              : '<span class="badge text-bg-secondary">否</span>' ?>
          </td>
          <td><?= h($r['pe_created_d']) ?></td>
          <td>
            <button class="btn btn-sm btn-outline-primary me-1"
              onclick='editRow(<?= json_encode($r, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>編輯</button>

            <form method="post" class="d-inline" onsubmit="return confirm('確定刪除？');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="period_ID" value="<?= h($r['period_ID']) ?>">
              <input type="hidden" name="sort" value="<?= h($sort) ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit">刪除</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
          <tr><td colspan="9" class="text-center text-muted">尚無資料</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function editRow(row){
  document.getElementById('form_action').value = 'update';
  document.getElementById('submitBtn').innerText = '更新';
  document.getElementById('period_ID').value      = row.period_ID;
  document.getElementById('period_start_d').value = row.period_start_d || '';
  document.getElementById('period_end_d').value   = row.period_end_d   || '';
  document.getElementById('period_title').value   = row.period_title   || '';
  document.getElementById('pe_target_ID').value   = row.pe_target_ID   || '';
  document.getElementById('cohort_ID').value      = row.cohort_ID      || '';
  document.getElementById('pe_status').checked    = (row.pe_status == 1);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm(){
  document.getElementById('form_action').value = 'create';
  document.getElementById('submitBtn').innerText = '新增';
  document.getElementById('period_ID').value = '';
  document.getElementById('period_start_d').value = '';
  document.getElementById('period_end_d').value = '';
  document.getElementById('period_title').value = '';
  document.getElementById('pe_target_ID').value = '';
  document.getElementById('cohort_ID').value = '';
  document.getElementById('pe_status').checked = false;
}
</script>
</body>
</html>
