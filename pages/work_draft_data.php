<?php
// 确保 session 正确启动
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require '../includes/pdo.php';

// 设置响应头（在检查之前设置，确保错误响应也能正确返回 JSON）
header('Content-Type: application/json; charset=utf-8');

// 检查 session 是否存在
if (!isset($_SESSION['u_ID']) || empty($_SESSION['u_ID'])) {
    http_response_code(401);
    echo json_encode([
        'ok' => false, 
        'error' => 'not_login',
        'message' => '請先登入'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

date_default_timezone_set('Asia/Taipei');

$u_id  = $_SESSION['u_ID'];
$role_ID = $_SESSION['role_ID'] ?? null;
$cohort_ID = $_SESSION['cohort_ID'] ?? null; // 獲取當前選擇的屆別
$isTeacher = ((int)$role_ID === 4);
$TABLE = 'workdata';

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

function columnExists(PDO $conn, string $table, string $column): bool {
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$teamUserField = columnExists($conn, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
$userRoleUidField = columnExists($conn, 'userrolesdata', 'ur_u_ID') ? 'ur_u_ID' : 'u_ID';

// 安全取得 action
$action = $_REQUEST['action'] ?? 'list';

// ===== 取得同隊成員 =====
function getTeamInfo(PDO $conn, string $u_id, string $teamUserField, string $userRoleUidField): array {
    $teamMemberIDs = [];
    $userNameMap   = [];
    $my_team_id    = null;

    try {
        $st = $conn->prepare("
            SELECT team_ID 
            FROM teammember 
            WHERE {$teamUserField}=? 
            ORDER BY tm_updated_d DESC 
            LIMIT 1
        ");
        $st->execute([$u_id]);
        $my_team_id = $st->fetchColumn();
    } catch (Throwable $e) {
        // 若沒有團隊相關資料表，直接回傳空集合即可（不影響「自己」的清單）
        return [
            'team_ids' => [],
            'name_map' => []
        ];
    }

    if ($my_team_id) {
        try {
            $st = $conn->prepare("
                SELECT 
                    tm.{$teamUserField} AS member_id, 
                    COALESCE(ud.u_name, tm.{$teamUserField}) AS u_name
                FROM teammember tm
                LEFT JOIN userdata ud 
                    ON ud.u_ID = tm.{$teamUserField}
                JOIN userrolesdata ur 
                    ON ur.{$userRoleUidField} = tm.{$teamUserField} 
                   AND ur.role_ID = 6 
                   AND ur.user_role_status = 1
                WHERE tm.team_ID = ? 
                  AND tm.tm_status = 1
                ORDER BY COALESCE(ud.u_name, tm.team_u_ID)
            ");
            $st->execute([$my_team_id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $teamMemberIDs[] = $r['member_id'];
                $userNameMap[$r['member_id']] = $r['u_name'];
            }
        } catch (Throwable $e) {
            // 若關聯表缺失，視為沒有團隊成員
            $teamMemberIDs = [];
            $userNameMap   = [];
        }
    }

    return [
        'team_ids' => $teamMemberIDs,
        'name_map' => $userNameMap
    ];
}

function getTeamStudents(PDO $conn, int $teamId, string $teamUserField, string $userRoleUidField): array {
    try {
        $stmt = $conn->prepare("
            SELECT tm.{$teamUserField} AS student_id,
                   COALESCE(ud.u_name, tm.{$teamUserField}) AS student_name
            FROM teammember tm
            JOIN userrolesdata ur
                  ON ur.{$userRoleUidField} = tm.{$teamUserField}
                 AND ur.role_ID = 6
                 AND ur.user_role_status = 1
            LEFT JOIN userdata ud
                   ON ud.u_ID = tm.{$teamUserField}
            WHERE tm.team_ID = ?
              AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
            ORDER BY student_name
        ");
        $stmt->execute([$teamId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id'   => $row['student_id'],
                'name' => $row['student_name'],
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function getTeacherTeams(PDO $conn, string $u_id, string $teamUserField, string $userRoleUidField, ?int $cohort_ID = null): array {
    try {
        // 先确认用户是指导老师
        $checkStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM userrolesdata 
            WHERE {$userRoleUidField} = ? AND role_ID = 4 AND user_role_status = 1
        ");
        $checkStmt->execute([$u_id]);
        if ($checkStmt->fetchColumn() == 0) {
            return [];
        }
        
        // 查询指导老师指导的所有团队（指导老师也在 teammember 表中）
        // 如果提供了 cohort_ID，则只查询该届别的团队
        $sql = "
            SELECT DISTINCT tm.team_ID,
                   COALESCE(td.team_project_name, CONCAT('Team ', tm.team_ID)) AS team_name
            FROM teammember tm
            LEFT JOIN teamdata td ON td.team_ID = tm.team_ID
            WHERE tm.{$teamUserField} = ?
              AND (td.team_status = 1 OR td.team_status IS NULL)
        ";
        
        $params = [$u_id];
        if ($cohort_ID !== null && $cohort_ID !== '') {
            $sql .= " AND td.cohort_ID = ?";
            $params[] = (int)$cohort_ID;
        }
        
        $sql .= " ORDER BY tm.team_ID";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $teams = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log("getTeacherTeams error: " . $e->getMessage());
        return [];
    }

    foreach ($teams as &$team) {
        $teamId = (int)$team['team_ID'];
        $team['students'] = getTeamStudents($conn, $teamId, $teamUserField, $userRoleUidField);
    }
    unset($team);

    return $teams;
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

    // 只更新 comment 欄位，不更新 work_update_d（避免改變原始日期）
    $st = $conn->prepare("UPDATE $TABLE SET comment=? WHERE work_ID=?");
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
    $teacherTeams = [];
    $selectedTeamId = null;
    $filterTeamValue = null;
    try {
        // 取得同隊資訊
        $teamInfo      = getTeamInfo($conn, $u_id, $teamUserField, $userRoleUidField);
        $teamMemberIDs = $teamInfo['team_ids'];
        $userNameMap   = $teamInfo['name_map'];

        $teacherTeams = $isTeacher ? getTeacherTeams($conn, $u_id, $teamUserField, $userRoleUidField, $cohort_ID) : [];
        $selectedTeamId = null;
        if ($isTeacher) {
            if (!empty($teacherTeams)) {
                $validTeamIds = array_map(fn($t) => (string)$t['team_ID'], $teacherTeams);
                $selectedTeamId = $_GET['team'] ?? (string)$teacherTeams[0]['team_ID'];
                if (!in_array((string)$selectedTeamId, $validTeamIds, true)) {
                    $selectedTeamId = (string)$teacherTeams[0]['team_ID'];
                }
                $teamMemberIDs = [];
                $userNameMap = [];
                foreach ($teacherTeams as $team) {
                    if ((string)$team['team_ID'] === (string)$selectedTeamId) {
                        foreach ($team['students'] as $stu) {
                            $teamMemberIDs[] = $stu['id'];
                            $userNameMap[$stu['id']] = $stu['name'];
                        }
                        break;
                    }
                }
            } else {
                $selectedTeamId = null;
                $teamMemberIDs = [];
                $userNameMap = [];
            }
        }
        $filterTeamValue = $isTeacher ? $selectedTeamId : null;

        // 篩選參數
        $whoDefault = $isTeacher ? 'team' : 'me';
    $who  = $_GET['who']  ?? $whoDefault;
    $from = toDate($_GET['from'] ?? null, date('Y-m-01'));
    $to   = toDate($_GET['to']   ?? null, date('Y-m-d'));

    if (strtotime($from) > strtotime($to)) {
        [$from, $to] = [$to, $from];
    }

    if ($isTeacher && $who !== 'team') {
        if (empty($teamMemberIDs) || !in_array($who, $teamMemberIDs, true)) {
            $who = 'team';
        }
    }

    $per    = 10;
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $per;

    $rows       = [];
    $showAuthor = false;
    $total      = 0;
    $pages      = 1;

    // 查詢邏輯（同時支援 work_u_ID 和 u_ID）
    $userField = 'work_u_ID';
    $dateField = 'work_update_d';
    
    // 先檢查資料表結構
    try {
        $testSt = $conn->query("SHOW COLUMNS FROM $TABLE LIKE 'work_u_ID'");
        if ($testSt->rowCount() === 0) {
            $userField = 'u_ID';
        }
    } catch (Exception $e) {
        $userField = 'u_ID';
    }
    
    try {
        $testSt = $conn->query("SHOW COLUMNS FROM $TABLE LIKE 'work_created_d'");
        if ($testSt->rowCount() > 0) {
            $dateField = 'work_created_d';
        }
    } catch (Exception $e) {
        // 使用預設的 work_update_d
    }

    if ($who === 'me') {
        // 自己：可看暫存+送出
        $st = $conn->prepare("
            SELECT COUNT(*) 
            FROM $TABLE 
            WHERE $userField=? 
              AND work_status IN(1,3) 
              AND ($dateField BETWEEN ? AND ? OR work_update_d BETWEEN ? AND ?)
        ");
        $st->execute([$u_id, $from . ' 00:00:00', dayEnd($to), $from . ' 00:00:00', dayEnd($to)]);
        $total = (int)$st->fetchColumn();

        $st = $conn->prepare("
            SELECT * 
            FROM $TABLE 
            WHERE $userField=? 
              AND work_status IN(1,3) 
              AND ($dateField BETWEEN ? AND ? OR work_update_d BETWEEN ? AND ?)
            ORDER BY work_update_d DESC, $dateField DESC
            LIMIT ? OFFSET ?
        ");
        $st->bindValue(1, $u_id);
        $st->bindValue(2, $from . ' 00:00:00');
        $st->bindValue(3, dayEnd($to));
        $st->bindValue(4, $from . ' 00:00:00');
        $st->bindValue(5, dayEnd($to));
        $st->bindValue(6, (int)$per, PDO::PARAM_INT);
        $st->bindValue(7, (int)$offset, PDO::PARAM_INT);
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
            $params[] = $from . ' 00:00:00';
            $params[] = dayEnd($to);

            $st = $conn->prepare("
                SELECT COUNT(*) 
                FROM $TABLE 
                WHERE $userField IN ($in) 
                  AND work_status=3 
                  AND ($dateField BETWEEN ? AND ? OR work_update_d BETWEEN ? AND ?)
            ");
            $st->execute($params);
            $total = (int)$st->fetchColumn();

            $sql = "
                SELECT * 
                FROM $TABLE 
                WHERE $userField IN ($in) 
                  AND work_status=3 
                  AND ($dateField BETWEEN ? AND ? OR work_update_d BETWEEN ? AND ?)
                ORDER BY work_update_d DESC, $dateField DESC
                LIMIT ? OFFSET ?
            ";
            $st = $conn->prepare($sql);
            $i = 1;
            foreach ($teamMemberIDs as $uidIn) {
                $st->bindValue($i++, $uidIn);
            }
            $st->bindValue($i++, $from . ' 00:00:00');
            $st->bindValue($i++, dayEnd($to));
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
            WHERE $userField=? 
              AND work_status=3 
              AND ($dateField BETWEEN ? AND ? OR work_update_d BETWEEN ? AND ?)
        ");
        $st->execute([$who, $from . ' 00:00:00', dayEnd($to), $from . ' 00:00:00', dayEnd($to)]);
        $total = (int)$st->fetchColumn();

        $st = $conn->prepare("
            SELECT * 
            FROM $TABLE 
            WHERE $userField=? 
              AND work_status=3 
              AND ($dateField BETWEEN ? AND ? OR work_update_d BETWEEN ? AND ?)
            ORDER BY work_update_d DESC, $dateField DESC
            LIMIT ? OFFSET ?
        ");
        $st->bindValue(1, $who);
        $st->bindValue(2, $from . ' 00:00:00');
        $st->bindValue(3, dayEnd($to));
        $st->bindValue(4, $from . ' 00:00:00');
        $st->bindValue(5, dayEnd($to));
        $st->bindValue(6, (int)$per, PDO::PARAM_INT);
        $st->bindValue(7, (int)$offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $showAuthor = true;
    }

    $pages = max(1, (int)ceil($total / $per));
    $page  = min($page, $pages);

    // 整理資料給前端
    $rowsOut = [];
    foreach ($rows as $r) {
        $userID = $r['work_u_ID'] ?? $r['u_ID'] ?? '';
        $updateDt = $r['work_update_d'] ?? $r['work_created_d'] ?? '';
        
        $rowsOut[] = [
            'work_ID'        => (int)$r['work_ID'],
            'work_title'     => $r['work_title'] ?? '',
            'work_content'   => $r['work_content'] ?? '',
            'work_status'    => (int)$r['work_status'],
            'work_update_dt' => fmtDt($updateDt),
            'work_u_ID'      => $userID,
            'author_name'    => nameOf($userID, $userNameMap),
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
                'team' => $filterTeamValue,
            ],
            'teamMembers' => $teamMembersOut,
            'me'          => $u_id,
            'isTeacher'   => $isTeacher,
            'teacherTeams' => $teacherTeams,
            'teacherSelectedTeam' => $selectedTeamId,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        // 記錄錯誤到日誌
        error_log("work_draft_data.php error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        // 不丟 HTTP 錯誤碼，維持 200 讓前端能解析 JSON
        echo json_encode([
            'ok'    => false,
            'error' => 'server_error',
            'msg'   => '伺服器錯誤：' . $e->getMessage(),
            'trace' => (defined('DEBUG') && DEBUG) ? $e->getTraceAsString() : null
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 未知 action
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown_action'], JSON_UNESCAPED_UNICODE);
exit;
