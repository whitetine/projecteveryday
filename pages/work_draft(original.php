<?php
session_start();
require '../includes/pdo.php';

// if (!isset($_SESSION['u_ID'])) {
//     echo "<script>alert('請先登入');location.href='index.php';</script>";
//     exit;
// }
date_default_timezone_set('Asia/Taipei');

$u_ID  = $_SESSION['u_ID'];
$TABLE = 'workdata';
$msg   = $_GET['msg'] ?? '';

// helpers
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fmtDt($dt){ return $dt ? date('Y-m-d H:i', strtotime($dt)) : ''; }
function toDate($s,$def){ $t = strtotime($s ?? ''); return $t ? date('Y-m-d',$t) : $def; }
function dayEnd($d){ return date('Y-m-d 23:59:59', strtotime($d ?: date('Y-m-d'))); }
function nameOf($uid, $map){ return ($map[$uid] ?? '') ?: $uid; }

// 篩選參數
$who   = $_GET['who']  ?? 'me';               // me | team | <u_ID>
$from  = toDate($_GET['from'] ?? null, date('Y-m-01'));
$to    = toDate($_GET['to']   ?? null, date('Y-m-d'));
if (strtotime($from) > strtotime($to)) { $tmp=$from; $from=$to; $to=$tmp; }

// === 分頁參數 ===
$per    = 10;                                   // 每頁 10 筆（可調）
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per;

// 產生保留篩選的查詢字串
function qs_keep($extra = []) {
    $base = [
        'who'  => $_GET['who']  ?? 'me',
        'from' => $_GET['from'] ?? '',
        'to'   => $_GET['to']   ?? '',
    ];
    return http_build_query(array_merge($base, $extra));
}

// 進頁把前幾天仍是暫存(1)結案(3)（保險）
$st = $conn->prepare("UPDATE `$TABLE`
                      SET work_status = 3
                      WHERE u_ID = ? AND work_status = 1 AND DATE(work_created_d) < CURDATE()");
$st->execute([$u_ID]);

// 取今日暫存（理論上 1 筆）
$st = $conn->prepare("SELECT work_ID, work_title, work_content, work_url, work_status,
                             work_created_d, work_update_d
                      FROM `$TABLE`
                      WHERE u_ID = ? AND work_status = 1
                      ORDER BY work_created_d DESC LIMIT 1");
$st->execute([$u_ID]);
$draft = $st->fetch(PDO::FETCH_ASSOC);

// ===== 只抓「同隊有效學生」（role_ID=6 且 user_role_status=1），並帶回姓名 =====
$teamMemberIDs = [];
$userNameMap   = [];
$my_team_id    = null;

try {
    // 找自己最新 team_ID
    $st = $conn->prepare("SELECT team_ID
                          FROM teammember
                          WHERE u_ID = ?
                          ORDER BY m_update_d DESC
                          LIMIT 1");
    $st->execute([$u_ID]);
    $my_team_id = $st->fetchColumn();

    if ($my_team_id !== false && $my_team_id !== null && trim((string)$my_team_id) !== '') {
        // 只撈「同隊 + 有效學生」
        $st = $conn->prepare("
            SELECT tm.u_ID, COALESCE(ud.u_name, tm.u_ID) AS u_name
            FROM teammember tm
            JOIN userrolesdata ur 
                  ON ur.u_ID = tm.u_ID
                 AND ur.role_ID = 6
                 AND ur.user_role_status = 1
            LEFT JOIN userdata ud ON ud.u_ID = tm.u_ID
            WHERE tm.team_ID = ?
            GROUP BY tm.u_ID, ud.u_name
            ORDER BY COALESCE(ud.u_name, tm.u_ID)
        ");
        $st->execute([$my_team_id]);

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $uid = $r['u_ID'];
            $teamMemberIDs[]   = $uid;
            $userNameMap[$uid] = $r['u_name'];
        }

        // 自己若也是有效學生，補進名單與姓名映射（避免空名單時無法選）
        $chk = $conn->prepare("
            SELECT 1 FROM userrolesdata 
            WHERE u_ID = ? AND role_ID = 6 AND user_role_status = 1
            LIMIT 1
        ");
        $chk->execute([$u_ID]);
        if ($chk->fetchColumn()) {
            if (!in_array($u_ID, $teamMemberIDs, true)) $teamMemberIDs[] = $u_ID;
            if (!isset($userNameMap[$u_ID])) $userNameMap[$u_ID] = $u_ID;
        }
    }
} catch (Throwable $e) { /* 忽略錯誤 */ }

$teamMemberIDs = array_values(array_unique($teamMemberIDs));

// 依篩選抓資料（含計數 + 分頁）
$rows = [];
$total = 0;
$showAuthor = false;

if ($who === 'me') {
    // 計數
    $st = $conn->prepare("SELECT COUNT(*) 
                          FROM `$TABLE`
                          WHERE u_ID=? AND work_status=3
                                AND work_created_d BETWEEN ? AND ?");
    $st->execute([$u_ID, $from.' 00:00:00', dayEnd($to)]);
    $total = (int)$st->fetchColumn();

    // 分頁查詢（全部使用位置參數）
    $sql = "SELECT work_ID, u_ID, work_title, work_content, work_url, work_status,
                   work_created_d, work_update_d
            FROM `$TABLE`
            WHERE u_ID=? AND work_status=3
                  AND work_created_d BETWEEN ? AND ?
            ORDER BY work_created_d DESC
            LIMIT ? OFFSET ?";
    $st = $conn->prepare($sql);
    $st->bindValue(1, $u_ID, PDO::PARAM_STR);
    $st->bindValue(2, $from.' 00:00:00', PDO::PARAM_STR);
    $st->bindValue(3, dayEnd($to), PDO::PARAM_STR);
    $st->bindValue(4, (int)$per, PDO::PARAM_INT);
    $st->bindValue(5, (int)$offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

} elseif ($who === 'team') {
    if ($teamMemberIDs) {
        $in = implode(',', array_fill(0, count($teamMemberIDs), '?'));

        // 計數（僅有效學生）
        $sqlCnt = "SELECT COUNT(*)
                   FROM `$TABLE` w
                   JOIN userrolesdata ur 
                        ON ur.u_ID = w.u_ID
                       AND ur.role_ID = 6
                       AND ur.user_role_status = 1
                   WHERE w.u_ID IN ($in)
                     AND w.work_status = 3
                     AND w.work_created_d BETWEEN ? AND ?";
        $st = $conn->prepare($sqlCnt);
        $st->execute(array_merge($teamMemberIDs, [$from.' 00:00:00', dayEnd($to)]));
        $total = (int)$st->fetchColumn();

        // 分頁查詢
        $sql = "SELECT w.work_ID, w.u_ID, w.work_title, w.work_content, w.work_url, w.work_status,
                       w.work_created_d, w.work_update_d
                FROM `$TABLE` w
                JOIN userrolesdata ur 
                      ON ur.u_ID = w.u_ID
                     AND ur.role_ID = 6
                     AND ur.user_role_status = 1
                WHERE w.u_ID IN ($in)
                  AND w.work_status = 3
                  AND w.work_created_d BETWEEN ? AND ?
                ORDER BY w.work_created_d DESC
                LIMIT ? OFFSET ?";
        $st = $conn->prepare($sql);

        $i = 1;
        foreach ($teamMemberIDs as $uidIn) $st->bindValue($i++, $uidIn, PDO::PARAM_STR);
        $st->bindValue($i++, $from.' 00:00:00', PDO::PARAM_STR);
        $st->bindValue($i++, dayEnd($to),       PDO::PARAM_STR);
        $st->bindValue($i++, (int)$per,         PDO::PARAM_INT);
        $st->bindValue($i++, (int)$offset,      PDO::PARAM_INT);

        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $showAuthor = true;

} else {
    // 指定同學必須是「同隊有效學生」
    $ok = false;
    if ($my_team_id) {
        $st = $conn->prepare("
            SELECT 1
            FROM teammember tm
            JOIN userrolesdata ur
                  ON ur.u_ID = tm.u_ID
                 AND ur.role_ID = 6
                 AND ur.user_role_status = 1
            WHERE tm.team_ID = ?
              AND tm.u_ID = ?
            LIMIT 1
        ");
        $st->execute([$my_team_id, $who]);
        $ok = (bool)$st->fetchColumn();
    }
    if (!$ok) {
        header("Location: work_draft.php?who=me&from=".urlencode($from)."&to=".urlencode($to));
        exit;
    }

    // 計數
    $st = $conn->prepare("SELECT COUNT(*)
                          FROM `$TABLE`
                          WHERE u_ID=? AND work_status=3
                                AND work_created_d BETWEEN ? AND ?");
    $st->execute([$who, $from.' 00:00:00', dayEnd($to)]);
    $total = (int)$st->fetchColumn();

    // 分頁查詢
    $sql = "SELECT work_ID, u_ID, work_title, work_content, work_url, work_status,
                   work_created_d, work_update_d
            FROM `$TABLE`
            WHERE u_ID=? AND work_status=3
                  AND work_created_d BETWEEN ? AND ?
            ORDER BY work_created_d DESC
            LIMIT ? OFFSET ?";
    $st = $conn->prepare($sql);
    $st->bindValue(1, $who, PDO::PARAM_STR);
    $st->bindValue(2, $from.' 00:00:00', PDO::PARAM_STR);
    $st->bindValue(3, dayEnd($to),       PDO::PARAM_STR);
    $st->bindValue(4, (int)$per,         PDO::PARAM_INT);
    $st->bindValue(5, (int)$offset,      PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $showAuthor = true;
}

// 計算總頁數
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages); // 避免超出範圍
?>



<!DOCTYPE html>
<html lang="zh-Hant">


<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
.table td,.table th{vertical-align:middle}
.content-preview{max-width:520px; max-height:100px; overflow:auto; white-space:pre-wrap; word-break:break-word;}
.badge-today{font-weight:600}
.filter-row .form-select,.filter-row .form-control{min-width:180px}
.title-preview{
  max-height:72px; overflow-y:auto; overflow-x:hidden;
  white-space:pre-wrap; word-break:break-word; overflow-wrap:anywhere;
}
.pager-bar{
  background:#f4a46022; border-top:2px solid #f4a460; padding:6px 10px; text-align:center;
}
.pager-bar a, .pager-bar span{
  display:inline-block; padding:2px 6px; margin:0 2px; text-decoration:none; color:#444;
  border-radius:3px; font-size:14px;
}
.pager-bar a:hover{ background:#ffe2c2; }
.pager-bar .active{ background:#f4a460; color:#fff; font-weight:700; }
.pager-bar .disabled{ color:#aaa; pointer-events:none; }
</style>
<!-- </head>
<body class="bg-light"> -->
<!-- <div class="container py-4"> -->
<div id="adminFileApp" class="container my-4">
  <h1 class="mb-4">我的日誌紀錄</h1>

  <!-- <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">我的日誌紀錄</h4> -->
    <a class="btn btn-outline-secondary" href="work_form.php">回日誌填寫</a>
  </div>

  <!-- 篩選列 -->
  <form class="card mb-3" method="get">
    <div class="card-body filter-row d-flex flex-wrap align-items-end gap-3">
      <div>
        <label class="form-label mb-1">查看對象</label>
        <select name="who" class="form-select">
          <option value="me" <?= $who==='me'?'selected':'' ?>>我的日誌（自己）</option>
          <?php if (count($teamMemberIDs) > 1 || (count($teamMemberIDs) === 1 && $teamMemberIDs[0] !== $u_ID)): ?>
            <option value="team" <?= $who==='team'?'selected':'' ?>>本團隊 - 學生全部</option>
            <?php foreach($teamMemberIDs as $uid): if ($uid === $u_ID) continue; ?>
              <option value="<?= h($uid) ?>" <?= $who===$uid?'selected':'' ?>>
                <?= h(nameOf($uid,$userNameMap).' ('.$uid.')') ?>
              </option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>
      <div>
        <label class="form-label mb-1">起始日期</label>
        <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
      </div>
      <div>
        <label class="form-label mb-1">結束日期</label>
        <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
      </div>
      <div class="ms-auto">
        <button class="btn btn-primary">套用篩選</button>
      </div>
    </div>
  </form>

  <?php if($msg): ?><div class="alert alert-info"><?= h($msg) ?></div><?php endif; ?>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:160px">建立時間</th>
              <?php if($showAuthor): ?><th style="width:160px">提交者</th><?php endif; ?>
              <th style="width:260px">標題</th>
              <th>內容預覽</th>
              <th style="width:170px">附件</th>
              <th style="width:120px">狀態</th>
              <th style="width:140px">查看全部內容</th>
            </tr>
          </thead>
          <tbody>

            <?php if($who==='me' && $draft && $page===1): // 只有第1頁置頂暫存 ?>
              <tr class="table-warning">
                <td><?= h(fmtDt($draft['work_created_d'])) ?></td>
                <?php if($showAuthor): ?><td><?= h(nameOf($u_ID,$userNameMap)) ?></td><?php endif; ?>
                <td><div class="title-preview"><?= h($draft['work_title']) ?></div></td>
                <td><div class="content-preview">暫存</div></td>
                <td>
                  <?php if(!empty($draft['work_url'])): ?>
                    <a href="<?= h($draft['work_url']) ?>" target="_blank" class="link-primary">附件</a>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge bg-warning text-dark">暫存</span>
                  <span class="badge bg-info-subtle text-dark badge-today">今日</span>
                </td>
                <td>
                  <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-sm btn-outline-primary" href="work_form.php">繼續編輯</a>
                    <form action="work_save.php" method="post"
                          onsubmit="return confirm('確認正式送出並結案？送出後不可修改');">
                      <input type="hidden" name="action" value="submit">
                      <input type="hidden" name="work_id" value="<?= (int)$draft['work_ID'] ?>">
                      <button class="btn btn-sm btn-primary" type="submit">一鍵送出</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endif; ?>

            <?php if(empty($rows) && empty($draft)): ?>
              <tr><td colspan="<?= $showAuthor?7:6 ?>" class="text-center text-muted py-4">查無資料</td></tr>
            <?php else: foreach($rows as $r): ?>
              <tr>
                <td><?= h(fmtDt($r['work_created_d'])) ?></td>
                <?php if($showAuthor): ?><td><?= h(nameOf($r['u_ID'] ?? '', $userNameMap)) ?></td><?php endif; ?>
                <td><div class="title-preview"><?= h($r['work_title']) ?></div></td>
                <td><div class="content-preview"><?= h($r['work_content']) ?></div></td>
                <td>
                  <?php if(!empty($r['work_url'])): ?>
                    <a href="<?= h($r['work_url']) ?>" target="_blank" class="link-primary">附件</a>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td><span class="badge bg-success">已送出</span></td>
                <td>
                  <button class="btn btn-sm btn-outline-secondary"
                          data-bs-toggle="modal"
                          data-bs-target="#viewModal"
                          data-id="<?= (int)$r['work_ID'] ?>"
                          data-title="<?= h($r['work_title']) ?>"
                          data-content="<?= h($r['work_content']) ?>"
                          data-file="<?= h(!empty($r['work_url']) ? $r['work_url'] : '') ?>"
                          data-created="<?= h(fmtDt($r['work_created_d'])) ?>"
                          data-author="<?= h(nameOf($r['u_ID'] ?? '', $userNameMap)) ?>">
                    查看
                  </button>
                </td>
              </tr>
            <?php endforeach; endif; ?>

          </tbody>
        </table>
      </div>

      <!-- 分頁列 -->
      <div class="pager-bar">
        <?php if($pages > 1): ?>
          <?php if($page > 1): ?>
            <a href="?<?= qs_keep(['page'=>$page-1]) ?>">&laquo;</a>
          <?php else: ?>
            <span class="disabled">&laquo;</span>
          <?php endif; ?>

          <?php for($i=1; $i<=$pages; $i++): ?>
            <?php if($i == $page): ?>
              <span class="active"><?= $i ?></span>
            <?php else: ?>
              <a href="?<?= qs_keep(['page'=>$i]) ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          <?php if($page < $pages): ?>
            <a href="?<?= qs_keep(['page'=>$page+1]) ?>">&raquo;</a>
          <?php else: ?>
            <span class="disabled">&raquo;</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="disabled">1</span>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<!-- 查看全文 Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">日誌內容</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2 text-muted" id="vm-meta"></div>
        <h5 id="vm-title" class="mb-3"></h5>
        <pre id="vm-content" class="mb-3" style="white-space:pre-wrap;word-break:break-word;"></pre>
        <div id="vm-file" class="mt-2"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('viewModal');
  modal.addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget; if (!btn) return;

    const title   = btn.getAttribute('data-title')   || '';
    const content = btn.getAttribute('data-content') || '';
    const file    = btn.getAttribute('data-file')    || '';
    const created = btn.getAttribute('data-created') || '';
    const author  = btn.getAttribute('data-author')  || '';

    modal.querySelector('#vm-meta').textContent =
      (author ? ('提交者：' + author + '　') : '') + (created ? ('建立時間：' + created) : '');
    modal.querySelector('#vm-title').textContent   = title;
    modal.querySelector('#vm-content').textContent = content;

    const fileWrap = modal.querySelector('#vm-file');
    fileWrap.innerHTML = '';
    if (file) {
      const a = document.createElement('a');
      a.href = file; a.target = '_blank';
      a.textContent = '下載附件：' + (file.split('/').pop());
      fileWrap.appendChild(a);
    } else {
      fileWrap.textContent = '附件：—';
    }
  });
});
</script>
</html>