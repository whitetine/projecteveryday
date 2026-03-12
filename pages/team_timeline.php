<?php


// pages/team_timeline.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/pdo.php';

if (!isset($_SESSION['u_ID'])) {
  echo '<div class="alert alert-danger m-3">請先登入</div>';
  exit;
}

$u_ID    = $_SESSION['u_ID'];
$role_ID = intval($_SESSION['role_ID'] ?? 0);

function columnExistsLocal($table, $column) {
  global $conn;
  try {
    $st = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$column]);
    return $st->rowCount() > 0;
  } catch (Exception $e) {
    return false;
  }
}

function enrichTeamsWithDetails($conn, $teams, $cohorts = []) {
  if (empty($teams)) return $teams;
  $tmCol = columnExistsLocal('teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
  $urCol = columnExistsLocal('userrolesdata', 'ur_u_ID') ? 'ur_u_ID' : 'u_ID';
  $hasTimeline = (bool)$conn->query("SHOW TABLES LIKE 'team_timeline'")->fetch();
  $hasAccount = columnExistsLocal('userdata', 'u_account');
  $cohortMap = [];
  foreach ($cohorts as $c) {
    $cohortMap[(int)$c['cohort_ID']] = $c['cohort_name'] ?: ($c['year_label'] ?? '') ?: ('屆別 ' . $c['cohort_ID']);
  }
  foreach ($teams as &$t) {
    $tid = (int)$t['team_ID'];
    $members = [];
    $teachers = [];
    if ($hasAccount) {
      $stmt = $conn->prepare("
        SELECT u.u_ID, u.u_name, u.u_account, ur.role_ID
        FROM teammember tm
        JOIN userdata u ON u.u_ID = tm.{$tmCol}
        LEFT JOIN userrolesdata ur ON ur.{$urCol} = tm.{$tmCol} AND ur.user_role_status = 1
        WHERE tm.team_ID = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)
      ");
    } else {
      $stmt = $conn->prepare("
        SELECT u.u_ID, u.u_name, ur.role_ID
        FROM teammember tm
        JOIN userdata u ON u.u_ID = tm.{$tmCol}
        LEFT JOIN userrolesdata ur ON ur.{$urCol} = tm.{$tmCol} AND ur.user_role_status = 1
        WHERE tm.team_ID = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)
      ");
    }
    $stmt->execute([$tid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
      $isTeacher = ((int)($m['role_ID'] ?? 0) === 4);
      $name = trim((string)($m['u_name'] ?? ''));
      if ($isTeacher) {
        if ($name !== '') {
          $teachers[] = $name;
        }
        continue;
      }
      if ($name === '') continue;
      $studentId = '';
      if ($hasAccount && !empty($m['u_account'])) {
        $studentId = (string)$m['u_account'];
      } elseif (!empty($m['u_ID'])) {
        $studentId = (string)$m['u_ID'];
      }
      $display = $studentId !== '' ? "{$name} {$studentId}" : $name;
      $members[] = $display;
    }
    $t['members_display'] = implode('、', $members) ?: '—';
    $t['teacher_display'] = $teachers ? implode('、', $teachers) : '—';
    $t['cohort_label'] = $cohortMap[(int)($t['cohort_ID'] ?? 0)] ?? '—';
    $t['event_total'] = 0;
    $t['event_stats_display'] = '0';
    if ($hasTimeline) {
      $st = $conn->prepare("SELECT event_type, COUNT(*) as cnt FROM team_timeline WHERE team_ID = ? GROUP BY event_type");
      $st->execute([$tid]);
      $stats = [];
      $total = 0;
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rawType = strtoupper(trim((string)($r['event_type'] ?? '')));
        if ($rawType === '') continue;
        $label = match ($rawType) {
          'TEAM'      => '組別',
          'MEETING'   => '會議',
          'MILESTONE' => '里程碑',
          'FORM'      => '表單',
          'REVIEW'    => '審核',
          'DOC'       => '文件',
          'STATUS'    => '狀態',
          default     => (string)($r['event_type'] ?? $rawType),
        };
        $count = (int)$r['cnt'];
        $stats[] = $label . '：' . $count;
        $total += $count;
      }
      $t['event_total'] = $total;
      $t['event_stats_display'] = implode('、', $stats) ?: '0';
    }
  }
  unset($t);
  return $teams;
}

// ---------- 1) 決定 team_ID ----------
$team_ID = null;

// 學生：只能看自己組
if ($role_ID === 6) {
  // 你如果有 tm_status 的規則（例如 1=有效），可把條件補上
  $stmt = $conn->prepare("SELECT team_ID FROM teammember WHERE team_u_ID = ? LIMIT 1");
  $stmt->execute([$u_ID]);
  $team_ID = $stmt->fetchColumn();

  if (!$team_ID) {
    echo '<div class="alert alert-warning m-3">你目前尚未加入任何組別</div>';
    exit;
  }
} else {
  // 非學生：若提供 team_ID 則顯示該組時間線；若沒提供則顯示組別清單供選擇
  $team_ID = intval($_GET['team_ID'] ?? 0);
  if ($team_ID <= 0) {
    $cohort_ID = $_GET['cohort_ID'] ?? $_SESSION['cohort_ID'] ?? null;
    if ($cohort_ID !== null && $cohort_ID !== '') {
      $cohort_ID = (int)$cohort_ID;
    }

    // 系辦(2)、主任(1)：顯示該屆別所有組別，可透過清單選擇進入看哪組的時間軸
    $isOffice = in_array($role_ID, [1, 2], true);
    if ($isOffice) {
      // 取得啟用屆別列表
      $cohorts = $conn->query("SELECT cohort_ID, cohort_name, year_label FROM cohortdata WHERE cohort_status = 1 ORDER BY cohort_ID DESC")->fetchAll(PDO::FETCH_ASSOC);
      if (empty($cohorts)) {
        echo '<div class="alert alert-warning m-3">尚無啟用屆別</div>';
        exit;
      }
      // 若未指定屆別，取第一個（最新）
      if ($cohort_ID === null || $cohort_ID === '') {
        $cohort_ID = (int)$cohorts[0]['cohort_ID'];
      }
      $cohort_label = '';
      foreach ($cohorts as $c) {
        if ((int)$c['cohort_ID'] === $cohort_ID) {
          $cohort_label = $c['cohort_name'] ?: ($c['year_label'] ?? '') ?: ('屆別 ' . $cohort_ID);
          break;
        }
      }
      if (!$cohort_label) $cohort_label = '屆別 ' . $cohort_ID;

      // 該屆別所有組別（team_status=1）
      $stmt = $conn->prepare("
        SELECT team_ID, COALESCE(team_project_name, CONCAT('團隊 ', team_ID)) AS team_name, cohort_ID
        FROM teamdata
        WHERE cohort_ID = ? AND team_status = 1
        ORDER BY team_ID
      ");
      $stmt->execute([$cohort_ID]);
      $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $teams = enrichTeamsWithDetails($conn, $teams, $cohorts);

      echo '<div class="m-3">';
      echo '<style>
        .team-timeline-table{width:100%;border-collapse:collapse;min-width:720px;}
        .team-timeline-table th,.team-timeline-table td{border-bottom:1px solid #d1d5db;padding:10px 10px;text-align:center;vertical-align:middle;font-size:1.02rem;}
        .team-timeline-table th{background:#fafafa;color:#6b7280;font-weight:700;}
        .team-timeline-table tbody tr:hover td{background:#fbfdff;}
        .team-timeline-table .col-name{text-align:left;font-weight:600;color:#111827;}
        .team-timeline-table .col-actions{text-align:center;white-space:nowrap;}
      </style>';
      echo '<div class="d-flex align-items-center gap-3 flex-wrap mb-4">';
      echo '<h4 class="mb-0">組別時間軸 · ' . htmlspecialchars($cohort_label) . '</h4>';
      echo '<div class="d-flex align-items-center gap-2">';
      echo '<label class="form-label mb-0">選擇屆別：</label>';
      echo '<select class="form-select form-select-sm" style="width:auto;min-width:140px" onchange="var v=this.value; if(v) window.location.hash=\'pages/team_timeline.php?cohort_ID=\'+v">';
      foreach ($cohorts as $c) {
        $cid = (int)$c['cohort_ID'];
        $clabel = htmlspecialchars($c['cohort_name'] ?: ($c['year_label'] ?? '') ?: ('屆別 ' . $cid));
        $sel = ($cid === $cohort_ID) ? ' selected' : '';
        echo '<option value="' . $cid . '"' . $sel . '>' . $clabel . '</option>';
      }
      echo '</select>';
      echo '</div>';
      echo '</div>';
      if (empty($teams)) {
        echo '<div class="alert alert-info">此屆別尚無組別</div>';
      } else {
        echo '<p class="text-muted mb-2">請選擇要查看時間線的組別：</p>';
        echo '<div class="table-responsive"><table class="team-timeline-table">';
        echo '<thead><tr>';
        echo '<th>名稱</th><th>組員</th><th>指導老師</th><th>事件統計</th><th>事件總數</th><th></th></tr></thead><tbody>';
        foreach ($teams as $t) {
          $link = 'pages/team_timeline.php?team_ID=' . intval($t['team_ID']);
          echo '<tr>';
          echo '<td class="col-name">' . htmlspecialchars($t['team_name'] ?: ('Team #' . $t['team_ID'])) . '</td>';
          echo '<td>' . htmlspecialchars($t['members_display'] ?? '—') . '</td>';
          echo '<td>' . htmlspecialchars($t['teacher_display'] ?? '—') . '</td>';
          echo '<td>' . htmlspecialchars($t['event_stats_display'] ?? '0') . '</td>';
          echo '<td>' . (int)($t['event_total'] ?? 0) . '</td>';
          echo '<td class="col-actions"><a class="btn btn-sm btn-outline-primary ajax-link" href="' . htmlspecialchars($link) . '">進入</a></td>';
          echo '</tr>';
        }
        echo '</tbody></table></div>';
      }
      echo '</div>';
      exit;
    }

    // 指導老師：列出該老師所帶的團隊
    if (!$cohort_ID) {
      $s2 = $conn->prepare("SELECT cohort_ID FROM enrollmentdata WHERE enroll_u_ID = ? AND enroll_status = 1 LIMIT 1");
      $s2->execute([$u_ID]);
      $cohort_ID = $s2->fetchColumn();
    }

    $teamUserField = columnExistsLocal('teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
    $userRoleUidField = columnExistsLocal('userrolesdata', 'ur_u_ID') ? 'ur_u_ID' : 'u_ID';

    $params = [$u_ID];
    $sql = "
        SELECT DISTINCT 
            t.team_ID,
            COALESCE(t.team_project_name, CONCAT('團隊 ', t.team_ID)) AS team_name,
            t.cohort_ID
        FROM teammember tm
        JOIN teamdata t ON tm.team_ID = t.team_ID
        JOIN userrolesdata ur ON ur.{$userRoleUidField} = tm.{$teamUserField}
        WHERE tm.{$teamUserField} = ?
          AND ur.role_ID = 4
          AND ur.user_role_status = 1
          AND t.team_status = 1
          AND (tm.tm_status IS NULL OR tm.tm_status = 1)
    ";
    if ($cohort_ID !== null && $cohort_ID !== '') {
      $sql .= " AND t.cohort_ID = ?";
      $params[] = (int)$cohort_ID;
    }
    $sql .= " ORDER BY t.team_ID";

    $s3 = $conn->prepare($sql);
    $s3->execute($params);
    $teams = $s3->fetchAll(PDO::FETCH_ASSOC);

    if (empty($teams)) {
      echo '<div class="alert alert-warning m-3">您目前沒有帶領任何組別</div>';
      exit;
    }

    $cohortsForTeacher = $conn->query("SELECT cohort_ID, cohort_name, year_label FROM cohortdata WHERE cohort_status IN (1, 3) ORDER BY cohort_ID DESC")->fetchAll(PDO::FETCH_ASSOC);
    $teams = enrichTeamsWithDetails($conn, $teams, $cohortsForTeacher);

    echo '<div class="m-3">';
    echo '<h4>請選擇要查看時間線的組別</h4>';
    echo '<div class="table-responsive"><table class="team-timeline-table">';
    echo '<thead><tr><th>名稱</th><th>組員</th><th>指導老師</th><th>事件統計</th><th>事件總數</th><th></th></tr></thead><tbody>';
    foreach ($teams as $t) {
      $link = 'pages/team_timeline.php?team_ID=' . intval($t['team_ID']);
      echo '<tr>';
      echo '<td class="col-name">' . htmlspecialchars($t['team_name'] ?: ('Team #' . $t['team_ID'])) . '</td>';
      echo '<td>' . htmlspecialchars($t['members_display'] ?? '—') . '</td>';
      echo '<td>' . htmlspecialchars($t['teacher_display'] ?? '—') . '</td>';
      echo '<td>' . htmlspecialchars($t['event_stats_display'] ?? '0') . '</td>';
      echo '<td>' . (int)($t['event_total'] ?? 0) . '</td>';
      echo '<td class="col-actions"><a class="btn btn-sm btn-outline-primary ajax-link" href="' . htmlspecialchars($link) . '">進入</a></td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '</div>';
    exit;
  }
}

// ---------- 2) 取組名 ----------
$stmt = $conn->prepare("SELECT team_project_name FROM teamdata WHERE team_ID = ?");
$stmt->execute([$team_ID]);
$team_name = $stmt->fetchColumn() ?: ('Team #' . $team_ID);

// ---------- 3) 取時間線資料 ----------
$stmt = $conn->prepare("
  SELECT
    timeline_ID, event_type, subject_title, action_type,
    event_title, event_desc,
    ref_table, ref_ID, route_key,
    event_datetime, created_by
  FROM team_timeline
  WHERE team_ID = ?
  ORDER BY event_datetime DESC, timeline_ID DESC
");
$stmt->execute([$team_ID]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------- 4) 連結 mapping（A: 程式內寫死版） ----------
function buildLink($route_key, $ref_table, $ref_ID) {
  global $team_ID;

  $id = (int)$ref_ID;
  $rk = trim((string)$route_key);
  $tb = trim((string)$ref_table);

  // 1) 會議：直接帶到該組、該次會議的會議頁
  if ($tb === 'meetingdata' || $rk === 'meeting') {
    $tid = (int)$team_ID;
    if ($tid > 0 && $id > 0) {
      return "main.php#pages/meeting.php?team_ID={$tid}&m_ID={$id}";
    }
  }

  // 2) 組別異動：帶到組別異動頁（該頁本身已有列表與詳細）
  if ($tb === 'teamchangelog' || $rk === 'team_change') {
    return "main.php#pages/team_change.php";
  }

  // 3) 專題申請：帶到審核頁（系辦）或學生申請頁；這裡先統一帶審核頁
  if ($tb === 'teamapply' || $rk === 'team_apply') {
    return "main.php#pages/team_apply_review.php";
  }

  // 4) 待辦事項：帶到學生版待辦事項頁（S_requirement_mailes_task.php）
  if ($tb === 'taskdata' || $rk === 'view_task') {
    return "main.php#pages/S_requirement_mailes_task.php";
  }

  // 5) 其他：保留原本通用 router
  $rkEnc = urlencode($rk);
  $tbEnc = urlencode($tb);
  return "main.php#view?rk={$rkEnc}&table={$tbEnc}&id={$id}";
}

// ---------- 5) 樣式類別 ----------
function typeBadge($event_type) {
  $t = strtoupper(trim($event_type));
  return match($t) {
    'TEAM'   => 'badge bg-primary',
    'MEETING'=> 'badge bg-success',
    'FORM'   => 'badge bg-warning text-dark',
    'REVIEW' => 'badge bg-purple',
    'DOC'    => 'badge bg-info text-dark',
    'STATUS' => 'badge bg-danger',
    default  => 'badge bg-secondary'
  };
}

// 統整各類型次數
$typeCounts = [];
foreach ($rows as $r) {
  $t = strtoupper(trim($r['event_type'] ?? ''));
  if ($t) $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
}
$totalEvents = count($rows);
?>
<style>
/* Timeline：左右交替 + 中央線 */
.team-timeline-wrap{ padding: 1rem; max-width: 900px; margin: 0 auto; }
.team-timeline-head{ display:flex; align-items:flex-end; justify-content:space-between; gap:1rem; margin-bottom: 1rem; flex-wrap: wrap; }
.team-timeline-title{ margin:0; font-weight:800; }
.team-timeline-sub{ color:#374151; font-size: 1.1rem; }
.team-timeline-sub .team-name{ font-weight: 600; }

.timeline-type-summary{
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 1.25rem;
  padding: 10px 14px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
}
.timeline-type-summary .type-badge{
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 10px;
  border-radius: 4px;
  font-size: 13px;
  font-weight: 600;
  color: #374151;
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  cursor: pointer;
  user-select: none;
  transition: background .15s, border-color .15s;
}
.timeline-type-summary .type-badge:hover{
  background: #f3f4f6;
  border-color: #d1d5db;
}
.timeline-type-summary .type-badge.active{
  background: #dbeafe;
  border-color: #2563eb;
  color: #1d4ed8;
}
.timeline-type-summary .type-badge .type-count{
  color: #6b7280;
  font-size: 12px;
  font-weight: 500;
}
.timeline-type-summary .type-badge.active .type-count{
  color: #2563eb;
}

.timeline-search-row{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin-bottom:.75rem;
  flex-wrap:wrap;
}
.timeline-search-input{
  flex:1;
  min-width:220px;
  max-width:360px;
}
.timeline-search-input input{
  width:100%;
  padding:8px 10px;
  border-radius:6px;
  border:1px solid #d1d5db;
  font-size:14px;
}
.timeline-pager{
  display:flex;
  justify-content:flex-end;
  align-items:center;
  gap:6px;
  margin-top:10px;
  font-size:13px;
}
.timeline-page-btn{
  border:1px solid #d1d5db;
  background:#fff;
  border-radius:999px;
  padding:2px 8px;
  cursor:pointer;
}
.timeline-page-btn.active{
  background:#2563eb;
  border-color:#2563eb;
  color:#fff;
}

.timeline{
  position: relative;
  margin-top: 1rem;
  padding: 0 0 0 0;
}
.timeline:before{
  content:"";
  position:absolute;
  left: 50%;
  transform: translateX(-50%);
  top: 0;
  bottom: 0;
  width: 2px;
  background: rgba(0,0,0,.15);
}
.t-item{
  position: relative;
  padding: 12px 0;
  display: grid;
  grid-template-columns: 1fr 24px 1fr;
  gap: 0 12px;
  align-items: start;
  max-width: 100%;
}
.t-item:nth-child(odd) .t-card{ grid-column: 1; }
.t-item:nth-child(odd) .t-dot{ grid-column: 2; }
.t-item:nth-child(even) .t-card{ grid-column: 3; }
.t-item:nth-child(even) .t-dot{ grid-column: 2; }
.t-dot{
  position: relative;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: #fff;
  border: 3px solid rgba(0,0,0,.35);
  flex-shrink: 0;
  margin-top: 18px;
}
.t-card{
  background:#fff;
  border: 1px solid rgba(0,0,0,.08);
  border-radius: 14px;
  padding: 12px 14px;
  box-shadow: 0 6px 18px rgba(0,0,0,.04);
}
.t-top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:.75rem;
  flex-wrap: wrap;
}
.t-time{
  font-size:.88rem;
  color:#6c757d;
  white-space: nowrap;
}
.t-link{
  text-decoration:none;
  font-weight: 800;
}
.t-desc{
  margin: .35rem 0 0;
  color:#495057;
  font-size: .95rem;
  white-space: pre-wrap;
}
@media (max-width: 640px){
  .t-item{ grid-template-columns: 24px 1fr !important; }
  .t-item:nth-child(odd) .t-card,
  .t-item:nth-child(even) .t-card{ grid-column: 2 !important; }
  .t-item .t-dot{ grid-column: 1 !important; }
  .timeline:before{ left: 12px; transform: none; }
  .t-dot{ margin-top: 18px; }
}
.bg-purple{ background:#6f42c1; }
</style>

<div class="team-timeline-wrap">
  <div class="team-timeline-head">
    <div style="display:flex;flex-direction:row;align-items:center;gap:12px;flex-wrap:wrap;">
      <a class="btn btn-sm btn-outline-secondary ajax-link" href="#pages/meeting.php" style="margin-right:8px;padding:6px 10px;border-radius:6px;">← 返回選擇團隊</a>
      <div>
        <h3 class="team-timeline-title">📍 組別時間線</h3>
        <div class="team-timeline-sub">
          組別：<span class="team-name"><?= htmlspecialchars($team_name) ?></span>
        </div>
      </div>
    </div>
    <div class="text-muted small">
      共 <?= count($rows) ?> 筆事件
    </div>
  </div>

  <?php if (!empty($rows)): ?>
  <div class="timeline-search-row">
    <div class="timeline-type-summary" id="timelineTypeSummary">
      <?php if ($totalEvents > 0): ?>
        <span class="type-badge type-all active" role="button" tabindex="0" data-filter-type="" title="顯示全部事件">
          全部
          <span class="type-count"><?= (int)$totalEvents ?></span>
        </span>
      <?php endif; ?>
      <?php foreach ($typeCounts as $t => $cnt): ?>
        <?php $typeClass = 'type-' . strtolower(preg_replace('/[^a-z0-9]/i', '', $t) ?: 'other'); ?>
        <span class="type-badge <?= htmlspecialchars($typeClass) ?>" role="button" tabindex="0" data-filter-type="<?= htmlspecialchars($t) ?>" title="點擊只看此類型">
          <?= htmlspecialchars($t) ?>
          <span class="type-count"><?= (int)$cnt ?></span>
        </span>
      <?php endforeach; ?>
    </div>
    <div class="timeline-search-input">
      <input type="text" id="timelineSearchInput" placeholder="搜尋事件標題 / 說明 / ref..." />
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="alert alert-info">這個組別目前沒有任何時間線紀錄</div>
  <?php else: ?>
    <div class="timeline" id="timelineList">
      <?php foreach ($rows as $r): ?>
        <?php
          $link = buildLink($r['route_key'], $r['ref_table'], $r['ref_ID']);
          $badge = typeBadge($r['event_type']);
          $dt = $r['event_datetime'] ? date('Y-m-d H:i', strtotime($r['event_datetime'])) : '';
          $eventTypeNorm = strtoupper(trim($r['event_type'] ?? ''));
        ?>
        <div class="t-item" data-event-type="<?= htmlspecialchars($eventTypeNorm) ?>">
          <div class="t-dot"></div>
          <div class="t-card">
            <div class="t-top">
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="<?= $badge ?>"><?= htmlspecialchars($r['event_type']) ?></span>
                <a class="t-link" href="<?= htmlspecialchars($link) ?>">
                  <?= htmlspecialchars($r['event_title'] ?: ($r['subject_title'] . ' ' . $r['action_type'])) ?>
                </a>
              </div>
              <div class="t-time"><?= htmlspecialchars($dt) ?></div>
            </div>
            <?php if (!empty($r['event_desc'])): ?>
              <div class="t-desc"><?= htmlspecialchars($r['event_desc']) ?></div>
            <?php endif; ?>
            <div class="mt-2 text-muted small">
              ref: <?= htmlspecialchars($r['ref_table']) ?> #<?= intval($r['ref_ID']) ?>
              <?php if (!empty($r['created_by'])): ?>
                · by <?= htmlspecialchars($r['created_by']) ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="timeline-pager" id="timelinePager" style="display:none;"></div>
  <?php endif; ?>
</div>
<script>
(function(){
  var summary = document.getElementById('timelineTypeSummary');
  var list = document.getElementById('timelineList');
  var pager = document.getElementById('timelinePager');
  if (!list) return;
  var items = Array.prototype.slice.call(list.querySelectorAll('.t-item'));
  if (!items.length) return;

  var badges = summary ? summary.querySelectorAll('.type-badge[data-filter-type]') : [];
  var searchInput = document.getElementById('timelineSearchInput');
  var currentFilter = '';
  var currentSearch = '';
  var pageSize = 15;
  var currentPage = 1;

  function getItemText(el){
    var title = el.querySelector('.t-link');
    var desc = el.querySelector('.t-desc');
    var meta = el.querySelector('.small');
    return [
      title ? title.textContent : '',
      desc ? desc.textContent : '',
      meta ? meta.textContent : ''
    ].join(' ').toLowerCase();
  }

  var cacheText = new Map();
  items.forEach(function(it){ cacheText.set(it, getItemText(it)); });

  function applyFilter(){
    var filtered = [];
    var typeUpper = (currentFilter || '').toUpperCase();
    var kw = (currentSearch || '').trim().toLowerCase();

    items.forEach(function(el){
      var t = (el.getAttribute('data-event-type') || '').toUpperCase();
      var okType = !typeUpper || t === typeUpper;
      var okSearch = !kw || (cacheText.get(el) || '').indexOf(kw) !== -1;
      if (okType && okSearch) filtered.push(el);
    });

    var totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
    if (currentPage > totalPages) currentPage = totalPages;

    items.forEach(function(el){ el.style.display = 'none'; });
    filtered.forEach(function(el, idx){
      var p = Math.floor(idx / pageSize) + 1;
      if (p === currentPage) el.style.display = '';
    });

    if (pager) {
      if (filtered.length <= pageSize) {
        pager.style.display = 'none';
        pager.innerHTML = '';
      } else {
        pager.style.display = 'flex';
        var html = '';
        if (currentPage > 1) {
          html += '<button class="timeline-page-btn" data-page="' + (currentPage-1) + '">‹</button>';
        }
        var start = Math.max(1, currentPage - 2);
        var end = Math.min(totalPages, start + 4);
        for (var p = start; p <= end; p++) {
          html += '<button class="timeline-page-btn' + (p===currentPage?' active':'') + '" data-page="' + p + '">' + p + '</button>';
        }
        if (currentPage < totalPages) {
          html += '<button class="timeline-page-btn" data-page="' + (currentPage+1) + '">›</button>';
        }
        pager.innerHTML = html;
      }
    }
  }

  function setFilter(type) {
    currentFilter = type;
    if (badges && badges.length) {
      badges.forEach(function(b){
        b.classList.toggle('active', (b.getAttribute('data-filter-type') || '') === type);
      });
    }
    currentPage = 1;
    applyFilter();
  }

  if (badges && badges.length) {
    badges.forEach(function(b){
      b.addEventListener('click', function(){
        var type = b.getAttribute('data-filter-type') || '';
        setFilter(type);
      });
      b.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); b.click(); } });
    });
  }

  if (searchInput) {
    var timer = null;
    searchInput.addEventListener('input', function(){
      currentSearch = searchInput.value || '';
      clearTimeout(timer);
      timer = setTimeout(function(){
        currentPage = 1;
        applyFilter();
      }, 250);
    });
  }

  if (pager) {
    pager.addEventListener('click', function(e){
      var btn = e.target.closest('.timeline-page-btn');
      if (!btn) return;
      var p = parseInt(btn.getAttribute('data-page') || '1', 10);
      if (!p || p === currentPage) return;
      currentPage = p;
      applyFilter();
    });
  }

  applyFilter();
})();
</script>
