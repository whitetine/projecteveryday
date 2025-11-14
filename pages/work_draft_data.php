<?php
session_start();
require '../includes/pdo.php';

if (!isset($_SESSION['u_ID'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'not_login'], JSON_UNESCAPED_UNICODE);
    exit;
}

date_default_timezone_set('Asia/Taipei');

$u_id  = $_SESSION['u_ID'];
$TABLE = 'workdata';

header('Content-Type: application/json; charset=utf-8');

// ===== Helper =====
function fmtDt($dt) {
    return $dt ? date('Y-m-d H:i', strtotime($dt)) : '';
}

function toDate($s, $def) {
    $t = strtotime($s ?? '');
    return $t ? date('Y-m-d', $t) : $def;
}

function dayEnd($d) {
    return date('Y-m-d 23:59:59', strtotime($d ?: date('Y-m-d')));
}

function nameOf($uid, $map) {
    return ($map[$uid] ?? '') ?: $uid;
}

function uid_name_map(PDO $conn, array $uids): array {
    $uids = array_values(array_unique(array_filter($uids, fn($x) => $x !== '' && $x !== 'system')));
    if (!$uids) return [];
    $in = implode(',', array_fill(0, count($uids), '?'));
    $st = $conn->prepare("SELECT u_ID, COALESCE(u_name,u_ID) AS name FROM userdata WHERE u_ID IN ($in)");
    $st->execute($uids);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[$r['u_ID']] = $r['name'];
    }
    return $map;
}

// 安全取得 action
$action = $_REQUEST['action'] ?? 'list';

// ===== 取得同隊成員 =====
function getTeamInfo(PDO $conn, string $u_id): array {
    $teamMemberIDs = [];
    $userNameMap   = [];
    $my_team_id    = null;

    $st = $conn->prepare("
        SELECT team_ID 
        FROM teammember 
        WHERE team_u_ID=? 
        ORDER BY tm_updated_d DESC 
        LIMIT 1
    ");
    $st->execute([$u_id]);
    $my_team_id = $st->fetchColumn();

    if ($my_team_id) {
        $st = $conn->prepare("
            SELECT 
                tm.team_u_ID, 
                COALESCE(ud.u_name, tm.team_u_ID) AS u_name
            FROM teammember tm
            LEFT JOIN userdata ud 
                ON ud.u_ID = tm.team_u_ID
            JOIN userrolesdata ur 
                ON ur.ur_u_ID = tm.team_u_ID 
               AND ur.role_ID = 6 
               AND ur.user_role_status = 1
            WHERE tm.team_ID = ? 
              AND tm.tm_status = 1
            ORDER BY COALESCE(ud.u_name, tm.team_u_ID)
        ");
        $st->execute([$my_team_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $teamMemberIDs[] = $r['team_u_ID'];
            $userNameMap[$r['team_u_ID']] = $r['u_name'];
        }
    }

    return [
        'team_ids' => $teamMemberIDs,
        'name_map' => $userNameMap
    ];
}

// ====== Action: get_comments =====
if ($action === 'get_comments') {
    $work_id = (int)($_POST['work_id'] ?? 0);
    if (!$work_id) {
        echo json_encode(['ok' => false, 'error' => 'invalid_work_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $st = $conn->prepare("SELECT comment FROM $TABLE WHERE work_ID=?");
    $st->execute([$work_id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $comments = json_decode($r['comment'] ?? '[]', true) ?: [];
    $uids     = array_map(fn($c) => $c['uid'] ?? '', $comments);
    $map      = uid_name_map($conn, $uids);

    foreach ($comments as &$c) {
        $c['name'] = $map[$c['uid']] ?? $c['uid'];
    }

    echo json_encode(['ok' => true, 'comments' => $comments], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== Action: add_comment =====
if ($action === 'add_comment') {
    $work_id = (int)($_POST['work_id'] ?? 0);
    $text    = trim($_POST['text'] ?? '');
    $uid     = $u_id;

    if (!$work_id || $text === '') {
        echo json_encode(['ok' => false, 'error' => 'invalid_param'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $st = $conn->prepare("SELECT comment FROM $TABLE WHERE work_ID=?");
    $st->execute([$work_id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $comments = json_decode($r['comment'] ?? '[]', true) ?: [];
    $comments[] = [
        'uid'  => $uid,
        'text' => $text,
        'at'   => date('Y-m-d H:i:s')
    ];

    $st = $conn->prepare("UPDATE $TABLE SET comment=?, work_update_d=NOW() WHERE work_ID=?");
    $st->execute([json_encode($comments, JSON_UNESCAPED_UNICODE), $work_id]);

    $uids = array_column($comments, 'uid');
    $map  = uid_name_map($conn, $uids);
    foreach ($comments as &$c) {
        $c['name'] = $map[$c['uid']] ?? $c['uid'];
    }

    echo json_encode(['ok' => true, 'comments' => $comments], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== Action: list（預設） =====
if ($action === 'list') {
    // 取得同隊資訊
    $teamInfo      = getTeamInfo($conn, $u_id);
    $teamMemberIDs = $teamInfo['team_ids'];
    $userNameMap   = $teamInfo['name_map'];

    // 篩選參數
    $who  = $_GET['who']  ?? 'me';
    $from = toDate($_GET['from'] ?? null, date('Y-m-01'));
    $to   = toDate($_GET['to']   ?? null, date('Y-m-d'));

    if (strtotime($from) > strtotime($to)) {
        [$from, $to] = [$to, $from];
    }

    $per    = 10;
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $per;

    $rows       = [];
    $showAuthor = false;
    $total      = 0;
    $pages      = 1;

    // 查詢邏輯
    if ($who === 'me') {
        // 自己：可看暫存+送出
        $st = $conn->prepare("
            SELECT COUNT(*) 
            FROM $TABLE 
            WHERE work_u_ID=? 
              AND work_status IN(1,3) 
              AND work_update_d BETWEEN ? AND ?
        ");
        $st->execute([$u_id, $from . ' 00:00:00', dayEnd($to)]);
        $total = (int)$st->fetchColumn();

        $st = $conn->prepare("
            SELECT * 
            FROM $TABLE 
            WHERE work_u_ID=? 
              AND work_status IN(1,3) 
              AND work_update_d BETWEEN ? AND ? 
            ORDER BY work_update_d DESC 
            LIMIT ? OFFSET ?
        ");
        $st->bindValue(1, $u_id);
        $st->bindValue(2, $from . ' 00:00:00');
        $st->bindValue(3, dayEnd($to));
        $st->bindValue(4, (int)$per, PDO::PARAM_INT);
        $st->bindValue(5, (int)$offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $showAuthor = false;

    } elseif ($who === 'team') {
        // 同隊：只能看送出
        if ($teamMemberIDs) {
            $in = implode(',', array_fill(0, count($teamMemberIDs), '?'));

            $params   = $teamMemberIDs;
            $params[] = $from . ' 00:00:00';
            $params[] = dayEnd($to);

            $st = $conn->prepare("
                SELECT COUNT(*) 
                FROM $TABLE 
                WHERE work_u_ID IN ($in) 
                  AND work_status=3 
                  AND work_update_d BETWEEN ? AND ?
            ");
            $st->execute($params);
            $total = (int)$st->fetchColumn();

            $sql = "
                SELECT * 
                FROM $TABLE 
                WHERE work_u_ID IN ($in) 
                  AND work_status=3 
                  AND work_update_d BETWEEN ? AND ? 
                ORDER BY work_update_d DESC 
                LIMIT ? OFFSET ?
            ";
            $st = $conn->prepare($sql);
            $i = 1;
            foreach ($teamMemberIDs as $uidIn) {
                $st->bindValue($i++, $uidIn);
            }
            $st->bindValue($i++, $from . ' 00:00:00');
            $st->bindValue($i++, dayEnd($to));
            $st->bindValue($i++, (int)$per, PDO::PARAM_INT);
            $st->bindValue($i++, (int)$offset, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $total = 0;
            $rows  = [];
        }
        $showAuthor = true;

    } else {
        // 單一同學：只能看送出
        $st = $conn->prepare("
            SELECT COUNT(*) 
            FROM $TABLE 
            WHERE work_u_ID=? 
              AND work_status=3 
              AND work_update_d BETWEEN ? AND ?
        ");
        $st->execute([$who, $from . ' 00:00:00', dayEnd($to)]);
        $total = (int)$st->fetchColumn();

        $st = $conn->prepare("
            SELECT * 
            FROM $TABLE 
            WHERE work_u_ID=? 
              AND work_status=3 
              AND work_update_d BETWEEN ? AND ? 
            ORDER BY work_update_d DESC 
            LIMIT ? OFFSET ?
        ");
        $st->bindValue(1, $who);
        $st->bindValue(2, $from . ' 00:00:00');
        $st->bindValue(3, dayEnd($to));
        $st->bindValue(4, (int)$per, PDO::PARAM_INT);
        $st->bindValue(5, (int)$offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $showAuthor = true;
    }

    $pages = max(1, (int)ceil($total / $per));
    $page  = min($page, $pages);

    // 整理資料給前端
    $rowsOut = [];
    foreach ($rows as $r) {
        $rowsOut[] = [
            'work_ID'        => (int)$r['work_ID'],
            'work_title'     => $r['work_title'] ?? '',
            'work_content'   => $r['work_content'] ?? '',
            'work_status'    => (int)$r['work_status'],
            'work_update_dt' => fmtDt($r['work_update_d'] ?? ''),
            'work_u_ID'      => $r['work_u_ID'],
            'author_name'    => nameOf($r['work_u_ID'], $userNameMap),
        ];
    }

    // teamMembers 給前端組 select
    $teamMembersOut = [];
    foreach ($teamMemberIDs as $id) {
        $teamMembersOut[] = [
            'id'   => $id,
            'name' => $userNameMap[$id] ?? $id,
        ];
    }

    echo json_encode([
        'ok'          => true,
        'rows'        => $rowsOut,
        'page'        => $page,
        'pages'       => $pages,
        'total'       => $total,
        'showAuthor'  => $showAuthor,
        'filter'      => [
            'who'  => $who,
            'from' => $from,
            'to'   => $to,
        ],
        'teamMembers' => $teamMembersOut,
        'me'          => $u_id,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 未知 action
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown_action'], JSON_UNESCAPED_UNICODE);
exit;