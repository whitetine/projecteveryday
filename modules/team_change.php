<?php
// modules/team_change.php - 組別異動紀錄中心 API
global $conn;
$u_ID = $_SESSION['u_ID'] ?? null;
$role_ID = (int)($_SESSION['role_ID'] ?? 0);

if (!$u_ID) {
    json_err('請先登入');
}

require_once __DIR__ . '/team_timeline_helper.php';

// Gmail 發送（與 forgot_password / suggest_schedule 相同 GAS 端點）
if (!function_exists('sendMailViaGas')) {
    function sendMailViaGas(string $to, string $subject, string $message): array {
        if (trim($to) === '') return ['ok' => false, 'msg' => '收件人為空'];
        $url = "https://script.google.com/macros/s/AKfycbyLLkHxyGhJkllgpztDzcXPcp_IKXL_GS2lnOGDegOAQplqQMVU0EA4LF4ZPDrrkfyb/exec";
        $data = ['to' => $to, 'subject' => $subject, 'message' => $message];
        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query($data),
                'timeout' => 20,
            ],
        ];
        $ctx = stream_context_create($options);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) return ['ok' => false, 'msg' => '無法連線到 GAS'];
        $decoded = json_decode($res, true);
        if (!is_array($decoded)) return ['ok' => false, 'msg' => 'GAS 回傳非 JSON'];
        return [
            'ok'  => !empty($decoded['ok']),
            'msg' => isset($decoded['msg']) ? (string)$decoded['msg'] : (isset($decoded['message']) ? (string)$decoded['message'] : ''),
        ];
    }
}

/**
 * 通過/退件時：通知該組別、指導老師、班導（系統通知 + Gmail）
 */
function team_change_notify_status_result($conn, $changelog, $status, $tmCol, $urCol, $actor_u_ID) {
    $team_ID = (int)($changelog['tc_team_ID'] ?? 0);
    $cohort_ID = (int)($changelog['tc_cohort'] ?? 0);
    if ($team_ID <= 0) return;

    $typeLabels = ['TEAM_RENAME' => '專題題目變更', 'TEACHER_CHANGE' => '指導老師變更', 'MEMBER_ADD' => '組員新增', 'MEMBER_REMOVE' => '組員退組', 'MEMBER_CHANGE' => '組員異動'];
    $typeLabel = $typeLabels[trim($changelog['change_type'] ?? '')] ?? '異動';
    $teamName = '';
    $stmt = $conn->prepare("SELECT team_project_name FROM teamdata WHERE team_ID = ? LIMIT 1");
    $stmt->execute([$team_ID]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $teamName = $row['team_project_name'] ?? "團隊 #{$team_ID}";

    $statusLabel = ($status === 3) ? '通過' : '退件';
    $msgTitle = "組別異動審核結果：{$typeLabel}（{$teamName}）{$statusLabel}";
    $msgContent = "您的組別異動申請「{$typeLabel}」已審核{$statusLabel}，請至組別異動紀錄頁面查看。";
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    $teamChangeUrl = rtrim($base . $scriptDir, '/') . '/main.php#pages/team_change.php';
    $urlData = [['type' => 'link', 'url' => $teamChangeUrl, 'label' => '查看組別異動紀錄']];
    $msg_url = json_encode($urlData, JSON_UNESCAPED_UNICODE);

    $recipients = [];
    // 該組別所有人（學生 + 指導老師）
    $stmt = $conn->prepare("SELECT tm.{$tmCol} as u_ID FROM teammember tm WHERE tm.team_ID = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)");
    $stmt->execute([$team_ID]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $recipients[(int)$r['u_ID']] = true;
    // 班導
    $stmt = $conn->prepare("
        SELECT DISTINCT e2.enroll_u_ID FROM teammember tm
        JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$tmCol} AND e.cohort_ID = ? AND e.enroll_status = 1
        JOIN enrollmentdata e2 ON e2.class_ID = e.class_ID AND e2.role_ID = 3 AND e2.enroll_status = 1
        WHERE tm.team_ID = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)
    ");
    $stmt->execute([$cohort_ID, $team_ID]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $recipients[(int)$r['enroll_u_ID']] = true;

    $stmtMsg = $conn->prepare("
        INSERT INTO msgdata (msg_title, msg_content, msg_url, msg_type, msg_a_u_ID, msg_status, msg_start_d, msg_created_d)
        VALUES (?, ?, ?, 'SYSTEM_NOTICE', ?, 1, NOW(), NOW())
    ");
    $stmtTarget = $conn->prepare("INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID) VALUES (?, 'USER', ?)");
    foreach (array_keys($recipients) as $targetUid) {
        if ($targetUid <= 0) continue;
        $stmtMsg->execute([$msgTitle, $msgContent, $msg_url, $actor_u_ID]);
        $msg_ID = $conn->lastInsertId();
        if ($msg_ID) $stmtTarget->execute([$msg_ID, $targetUid]);

        $userStmt = $conn->prepare("SELECT u_name, u_gmail FROM userdata WHERE u_ID = ? LIMIT 1");
        $userStmt->execute([$targetUid]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($user && !empty($user['u_gmail']) && filter_var(trim($user['u_gmail']), FILTER_VALIDATE_EMAIL)) {
            $body = "{$user['u_name']} 您好，\n\n{$msgContent}\n\n請登入系統查看：{$teamChangeUrl}\n\n---\n專題日總彙系統";
            sendMailViaGas(trim($user['u_gmail']), $msgTitle, $body);
            usleep(200000);
        }
    }
}

$tmCol = $conn->query("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'")->fetch() ? 'team_u_ID' : 'u_ID';
$urCol = $conn->query("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'")->fetch() ? 'ur_u_ID' : 'u_ID';
$hasTcUReason = (bool)$conn->query("SHOW COLUMNS FROM teamchangelog LIKE 'tc_u_reason'")->fetch();
$do = $_GET['do'] ?? $_POST['do'] ?? '';

switch ($do) {
    // 學生：取得自己團隊的異動紀錄
    case 'get_my_team_changelog':
        if ($role_ID !== 6) {
            json_err('僅限學生使用');
        }
        $team_ID = (int)($_GET['team_ID'] ?? 0);
        if ($team_ID <= 0) {
            json_err('請提供有效的團隊ID');
        }

        $stmt = $conn->prepare("SELECT 1 FROM teammember tm JOIN teamdata t ON tm.team_ID = t.team_ID 
            WHERE tm.team_ID = ? AND tm.$tmCol = ? AND t.team_status = 1 AND (tm.tm_status IS NULL OR tm.tm_status = 1) LIMIT 1");
        $stmt->execute([$team_ID, $u_ID]);
        if (!$stmt->fetch()) {
            json_err('您不屬於此組別');
        }

        $tcUReasonCol = $hasTcUReason ? ', tc.tc_u_reason' : '';
        $hasTcAttachment = (bool)$conn->query("SHOW COLUMNS FROM teamchangelog LIKE 'tc_attachment'")->fetch();
        $tcAttachmentCol = $hasTcAttachment ? ', tc.tc_attachment' : '';
        $stmt = $conn->prepare("
            SELECT tc.tc_ID, tc.tc_cohort, tc.tc_team_ID, tc.change_type, tc.tc_status,
                   tc.tc_team_name_old, tc.tc_team_name_new, tc.tc_teacher_old, tc.tc_teacher_new, tc.tc_member, tc.tc_reason{$tcUReasonCol}{$tcAttachmentCol}, tc.tc_created_u_ID, tc.tc_created_d,
                   c.cohort_name, td.team_project_name
            FROM teamchangelog tc
            LEFT JOIN cohortdata c ON c.cohort_ID = tc.tc_cohort
            LEFT JOIN teamdata td ON td.team_ID = tc.tc_team_ID
            WHERE tc.tc_team_ID = ?
            ORDER BY tc.tc_created_d DESC 
            LIMIT 100
        ");
        $stmt->execute([$team_ID]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            if (!empty($r['tc_member'])) {
                $nameStmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ? LIMIT 1");
                $nameStmt->execute([$r['tc_member']]);
                $r['tc_member_display'] = $nameStmt->fetchColumn() ?: $r['tc_member'];
            }
            if (!empty($r['tc_created_u_ID'])) {
                $nameStmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ? LIMIT 1");
                $nameStmt->execute([$r['tc_created_u_ID']]);
                $r['tc_created_name'] = $nameStmt->fetchColumn() ?: $r['tc_created_u_ID'];
            }
        }

        json_ok(['changes' => $rows]);
        break;

    // 科辦/主任/老師：取得異動紀錄列表（含篩選）
    case 'get_changelog_list':
        if (!in_array($role_ID, [1, 2, 4])) {
            json_err('無權限');
        }

        $cohort_ID = (int)($_GET['cohort_ID'] ?? 0);
        if ($role_ID === 4 && $cohort_ID <= 0 && !empty($_SESSION['cohort_ID'])) {
            $cohort_ID = (int)$_SESSION['cohort_ID'];
        }
        $change_type = trim($_GET['change_type'] ?? '');
        $team_search = trim($_GET['team_search'] ?? '');
        $team_ID = (int)($_GET['team_ID'] ?? 0);

        $where = ['1=1'];
        $params = [];

        if ($cohort_ID > 0) {
            $where[] = 'tc.tc_cohort = ?';
            $params[] = $cohort_ID;
        }
        if ($change_type !== '') {
            $where[] = 'tc.change_type = ?';
            $params[] = $change_type;
        }
        if ($team_ID > 0) {
            $where[] = 'tc.tc_team_ID = ?';
            $params[] = $team_ID;
        } elseif ($team_search !== '') {
            $where[] = '(td.team_project_name LIKE ? OR td.team_ID = ?)';
            $params[] = '%' . $team_search . '%';
            $params[] = (is_numeric($team_search) ? (int)$team_search : 0);
        }

        // 指導老師：僅能看自己指導的團隊，且依 selectrole 的屆別篩選
        if ($role_ID === 4) {
            $teacherCohort = !empty($_SESSION['cohort_ID']) ? (int)$_SESSION['cohort_ID'] : 0;
            $stmt = $conn->prepare("
                SELECT DISTINCT tm.team_ID FROM teammember tm
                JOIN teamdata td ON td.team_ID = tm.team_ID AND td.team_status = 1
                JOIN userrolesdata ur ON ur.$urCol = tm.$tmCol AND ur.role_ID = 4 AND ur.user_role_status = 1
                WHERE tm.$tmCol = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)
                " . ($teacherCohort > 0 ? " AND td.cohort_ID = ?" : "")
            );
            $stmt->execute($teacherCohort > 0 ? [$u_ID, $teacherCohort] : [$u_ID]);
            $myTeamIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'team_ID');
            if (empty($myTeamIds)) {
                json_ok(['changes' => [], 'stats' => ['TEAM_RENAME' => 0, 'TEACHER_CHANGE' => 0, 'MEMBER_ADD' => 0, 'MEMBER_REMOVE' => 0]]);
                break;
            }
            $placeholders = implode(',', array_fill(0, count($myTeamIds), '?'));
            $where[] = "tc.tc_team_ID IN ($placeholders)";
            $params = array_merge($params, $myTeamIds);
        }

        $tcUReasonCol = $hasTcUReason ? ', tc.tc_u_reason' : '';
        $hasTcAttachment = (bool)$conn->query("SHOW COLUMNS FROM teamchangelog LIKE 'tc_attachment'")->fetch();
        $tcAttachmentCol = $hasTcAttachment ? ', tc.tc_attachment' : '';
        $sql = "
            SELECT tc.tc_ID, tc.tc_cohort, tc.tc_team_ID, tc.change_type, tc.tc_status,
                   tc.tc_team_name_old, tc.tc_team_name_new, tc.tc_teacher_old, tc.tc_teacher_new, tc.tc_member, tc.tc_reason{$tcUReasonCol}{$tcAttachmentCol},
                   tc.tc_created_u_ID, tc.tc_created_d,
                   c.cohort_name, td.team_project_name
            FROM teamchangelog tc
            LEFT JOIN cohortdata c ON c.cohort_ID = tc.tc_cohort
            LEFT JOIN teamdata td ON td.team_ID = tc.tc_team_ID
            WHERE " . implode(' AND ', $where) . "
            ORDER BY tc.tc_created_d DESC
            LIMIT 200
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            if (!empty($r['tc_member'])) {
                $nameStmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ? LIMIT 1");
                $nameStmt->execute([$r['tc_member']]);
                $r['tc_member_display'] = $nameStmt->fetchColumn() ?: $r['tc_member'];
            }
            if (!empty($r['tc_created_u_ID'])) {
                $nameStmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ? LIMIT 1");
                $nameStmt->execute([$r['tc_created_u_ID']]);
                $r['tc_created_name'] = $nameStmt->fetchColumn() ?: $r['tc_created_u_ID'];
            }
        }

        json_ok(['changes' => $rows]);
        break;

    // 科辦/主任/老師：取得本屆異動統計
    case 'get_changelog_stats':
        if (!in_array($role_ID, [1, 2, 4])) {
            json_err('無權限');
        }

        $cohort_ID = (int)($_GET['cohort_ID'] ?? 0);
        if ($role_ID === 4 && $cohort_ID <= 0 && !empty($_SESSION['cohort_ID'])) {
            $cohort_ID = (int)$_SESSION['cohort_ID'];
        }
        $where = ['1=1'];
        $params = [];

        if ($cohort_ID > 0) {
            $where[] = 'tc_cohort = ?';
            $params[] = $cohort_ID;
        }

        if ($role_ID === 4) {
            $teacherCohort = !empty($_SESSION['cohort_ID']) ? (int)$_SESSION['cohort_ID'] : 0;
            $stmt = $conn->prepare("
                SELECT DISTINCT tm.team_ID FROM teammember tm
                JOIN teamdata td ON td.team_ID = tm.team_ID AND td.team_status = 1
                JOIN userrolesdata ur ON ur.$urCol = tm.$tmCol AND ur.role_ID = 4 AND ur.user_role_status = 1
                WHERE tm.$tmCol = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)
                " . ($teacherCohort > 0 ? " AND td.cohort_ID = ?" : "")
            );
            $stmt->execute($teacherCohort > 0 ? [$u_ID, $teacherCohort] : [$u_ID]);
            $myTeamIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'team_ID');
            if (empty($myTeamIds)) {
                json_ok(['stats' => ['TEAM_RENAME' => 0, 'TEACHER_CHANGE' => 0, 'MEMBER_ADD' => 0, 'MEMBER_REMOVE' => 0, 'PENDING' => 0]]);
                break;
            }
            $placeholders = implode(',', array_fill(0, count($myTeamIds), '?'));
            $where[] = "tc_team_ID IN ($placeholders)";
            $params = array_merge($params, $myTeamIds);
        }

        $stats = ['TEAM_RENAME' => 0, 'TEACHER_CHANGE' => 0, 'MEMBER_ADD' => 0, 'MEMBER_REMOVE' => 0, 'PENDING' => 0];

        $sql = "SELECT change_type, COUNT(*) as cnt FROM teamchangelog WHERE " . implode(' AND ', $where) . " GROUP BY change_type";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stats[$r['change_type']] = (int)$r['cnt'];
        }

        $wherePending = $where;
        $wherePending[] = 'tc_status = 1';
        $sqlPending = "SELECT COUNT(*) FROM teamchangelog WHERE " . implode(' AND ', $wherePending);
        $stmt = $conn->prepare($sqlPending);
        $stmt->execute($params);
        $stats['PENDING'] = (int)$stmt->fetchColumn();

        json_ok(['stats' => $stats]);
        break;

    // 科辦/主任/老師：取得篩選選項（屆別、團隊）
    case 'get_changelog_filter_options':
        if (!in_array($role_ID, [1, 2, 4])) {
            json_err('無權限');
        }

        $cohort_ID = (int)($_GET['cohort_ID'] ?? 0);
        if ($role_ID === 4 && $cohort_ID <= 0 && !empty($_SESSION['cohort_ID'])) {
            $cohort_ID = (int)$_SESSION['cohort_ID'];
        }

        $cohorts = [];
        $stmt = $conn->prepare("
            SELECT DISTINCT c.cohort_ID, c.cohort_name, c.year_label
            FROM cohortdata c
            INNER JOIN teamchangelog tc ON tc.tc_cohort = c.cohort_ID
            WHERE c.cohort_status IN (1, 3)
            ORDER BY c.cohort_ID DESC
        ");
        $stmt->execute();
        $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $teams = [];
        if ($cohort_ID > 0) {
            $teamWhere = "td.cohort_ID = ?";
            $teamParams = [$cohort_ID];
            if ($role_ID === 4) {
                $teacherCohort = !empty($_SESSION['cohort_ID']) ? (int)$_SESSION['cohort_ID'] : 0;
                $stmt = $conn->prepare("
                    SELECT DISTINCT tm.team_ID FROM teammember tm
                    JOIN teamdata td2 ON td2.team_ID = tm.team_ID AND td2.team_status = 1
                    JOIN userrolesdata ur ON ur.$urCol = tm.$tmCol AND ur.role_ID = 4 AND ur.user_role_status = 1
                    WHERE tm.$tmCol = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)
                    " . ($teacherCohort > 0 ? " AND td2.cohort_ID = ?" : "")
                );
                $stmt->execute($teacherCohort > 0 ? [$u_ID, $teacherCohort] : [$u_ID]);
                $myTeamIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'team_ID');
                if (!empty($myTeamIds)) {
                    $placeholders = implode(',', array_fill(0, count($myTeamIds), '?'));
                    $teamWhere .= " AND td.team_ID IN ($placeholders)";
                    $teamParams = array_merge($teamParams, $myTeamIds);
                } else {
                    $teamWhere .= " AND 1=0";
                }
            }
            $stmt = $conn->prepare("
                SELECT td.team_ID, td.team_project_name
                FROM teamdata td
                WHERE $teamWhere AND td.team_status = 1
                ORDER BY td.team_project_name
            ");
            $stmt->execute($teamParams);
            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        json_ok(['cohorts' => $cohorts, 'teams' => $teams]);
        break;

    // 科辦、主任：更新異動狀態（通過/退件）
    // tc_status: 0=退件, 1=提交申請(審核中), 3=通過
    case 'update_changelog_status':
        if (!in_array($role_ID, [1, 2])) {
            json_err('僅科辦、主任可操作');
        }

        $tc_ID = (int)($_POST['tc_ID'] ?? 0);
        $status = (int)($_POST['status'] ?? 0);

        if ($tc_ID <= 0) {
            json_err('無效的異動編號');
        }
        if (!in_array($status, [0, 3])) {
            json_err('狀態須為 3(通過) 或 0(退件)');
        }

        $stmt = $conn->prepare("SELECT * FROM teamchangelog WHERE tc_ID = ? LIMIT 1");
        $stmt->execute([$tc_ID]);
        $changelog = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$changelog) {
            json_err('找不到該異動紀錄');
        }
        if ((int)($changelog['tc_status'] ?? 1) !== 1) {
            json_err('僅能審核「審核中」的紀錄');
        }

        $tc_u_reason = $hasTcUReason ? mb_substr(trim($_POST['tc_u_reason'] ?? ''), 0, 300) : '';

        $conn->beginTransaction();
        try {
            if ($hasTcUReason) {
                $stmt = $conn->prepare("UPDATE teamchangelog SET tc_status = ?, tc_u_reason = ? WHERE tc_ID = ?");
                $stmt->execute([$status, $tc_u_reason ?: null, $tc_ID]);
            } else {
                $stmt = $conn->prepare("UPDATE teamchangelog SET tc_status = ? WHERE tc_ID = ?");
                $stmt->execute([$status, $tc_ID]);
            }

            // 通過時：依 change_type 更新 teamdata / teammember（已成立的組別用 UPDATE）
            if ($status === 3) {
                $team_ID = (int)($changelog['tc_team_ID'] ?? 0);
                $change_type = trim($changelog['change_type'] ?? '');

                if ($team_ID > 0) {
                    if ($change_type === 'TEAM_RENAME' && !empty($changelog['tc_team_name_new'])) {
                        $stmt = $conn->prepare("UPDATE teamdata SET team_project_name = ?, team_update_d = NOW() WHERE team_ID = ?");
                        $stmt->execute([trim($changelog['tc_team_name_new']), $team_ID]);
                    } elseif ($change_type === 'TEACHER_CHANGE') {
                        // 舊老師：從 teammember 找出對應 u_ID 後 UPDATE tm_status=0
                        $oldTeacherName = trim($changelog['tc_teacher_old'] ?? '');
                        if ($oldTeacherName !== '') {
                            $oldStmt = $conn->prepare("
                                SELECT tm.$tmCol FROM teammember tm
                                JOIN userdata u ON u.u_ID = tm.$tmCol
                                WHERE tm.team_ID = ? AND u.u_name = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)
                                LIMIT 1
                            ");
                            $oldStmt->execute([$team_ID, $oldTeacherName]);
                            $oldTeacherId = $oldStmt->fetchColumn();
                            if ($oldTeacherId) {
                                $upd = $conn->prepare("UPDATE teammember SET tm_status = 0, tm_updated_d = NOW() WHERE team_ID = ? AND $tmCol = ? AND (tm_status IS NULL OR tm_status = 1)");
                                $upd->execute([$team_ID, $oldTeacherId]);
                            }
                        }
                        // 新老師：tc_teacher_new 可能為 u_ID 或 u_name，解析後 INSERT
                        $newVal = trim($changelog['tc_teacher_new'] ?? '');
                        if ($newVal !== '') {
                            $newTeacherId = null;
                            $stmtU = $conn->prepare("SELECT u_ID FROM userdata WHERE u_ID = ? LIMIT 1");
                            $stmtU->execute([$newVal]);
                            $newTeacherId = $stmtU->fetchColumn();
                            if (!$newTeacherId) {
                                $stmtN = $conn->prepare("SELECT u_ID FROM userdata WHERE u_name = ? LIMIT 1");
                                $stmtN->execute([$newVal]);
                                $newTeacherId = $stmtN->fetchColumn();
                            }
                            if ($newTeacherId) {
                                $chk = $conn->prepare("SELECT COUNT(*) FROM teammember WHERE team_ID = ? AND $tmCol = ?");
                                $chk->execute([$team_ID, $newTeacherId]);
                                if ($chk->fetchColumn() == 0) {
                                    $ins = $conn->prepare("INSERT INTO teammember (team_ID, $tmCol, tm_status, tm_updated_d) VALUES (?, ?, 1, NOW())");
                                    $ins->execute([$team_ID, $newTeacherId]);
                                }
                            }
                        }
                    } elseif ($change_type === 'MEMBER_ADD' || $change_type === 'MEMBER_CHANGE') {
                        $memberId = trim($changelog['tc_member'] ?? '');
                        if ($memberId !== '') {
                            $chk = $conn->prepare("SELECT COUNT(*) FROM teammember WHERE team_ID = ? AND $tmCol = ?");
                            $chk->execute([$team_ID, $memberId]);
                            if ($chk->fetchColumn() == 0) {
                                $ins = $conn->prepare("INSERT INTO teammember (team_ID, $tmCol, tm_status, tm_updated_d) VALUES (?, ?, 1, NOW())");
                                $ins->execute([$team_ID, $memberId]);
                            }
                        }
                    } elseif ($change_type === 'MEMBER_REMOVE') {
                        $memberId = trim($changelog['tc_member'] ?? '');
                        if ($memberId !== '') {
                            $upd = $conn->prepare("UPDATE teammember SET tm_status = 0, tm_updated_d = NOW() WHERE team_ID = ? AND $tmCol = ? AND (tm_status IS NULL OR tm_status = 1)");
                            $upd->execute([$team_ID, $memberId]);
                        }
                    }
                }
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            json_err('操作失敗：' . $e->getMessage());
        }

        // 通過/退件：通知該組別、指導老師、班導（系統通知 + Gmail）
        team_change_notify_status_result($conn, $changelog, $status, $tmCol, $urCol, $u_ID);

        // 寫入時間軸：審核結果
        $team_ID = (int)($changelog['tc_team_ID'] ?? 0);
        $typeLabels = ['TEAM_RENAME' => '專題題目變更', 'TEACHER_CHANGE' => '指導老師變更', 'MEMBER_ADD' => '組員新增', 'MEMBER_REMOVE' => '組員退組', 'MEMBER_CHANGE' => '組員異動'];
        $typeLabel = $typeLabels[trim($changelog['change_type'] ?? '')] ?? '異動';
        $actionLabel = $status === 3 ? '通過' : '退件';
        $reasonForTimeline = $hasTcUReason ? ($tc_u_reason ?: ($changelog['tc_u_reason'] ?? '')) : ($changelog['tc_reason'] ?? '');

        if ($team_ID > 0) {
            team_timeline_add_event(
                $conn,
                $team_ID,
                '組別異動',
                "審核{$actionLabel}",
                $typeLabel,
                "{$typeLabel} {$actionLabel}",
                (string)$reasonForTimeline,
                'teamchangelog',
                $tc_ID,
                'team_change',
                null,
                $u_ID
            );
        }

        json_ok(['message' => $status === 3 ? '已通過' : '已退件']);
        break;

    // 科辦、主任：編輯異動紀錄（修改狀態、審核人備註）
    case 'edit_changelog':
        if (!in_array($role_ID, [1, 2])) {
            json_err('僅科辦、主任可操作');
        }

        $tc_ID = (int)($_POST['tc_ID'] ?? 0);
        $status = (int)($_POST['status'] ?? -1);
        $tc_u_reason = $hasTcUReason ? mb_substr(trim($_POST['tc_u_reason'] ?? ''), 0, 300) : '';

        if ($tc_ID <= 0) {
            json_err('無效的異動編號');
        }
        if (!in_array($status, [0, 1, 3])) {
            json_err('狀態須為 0(退件)、1(審核中) 或 3(通過)');
        }

        $stmt = $conn->prepare("SELECT * FROM teamchangelog WHERE tc_ID = ? LIMIT 1");
        $stmt->execute([$tc_ID]);
        $changelog = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$changelog) {
            json_err('找不到該異動紀錄');
        }

        $oldStatus = (int)($changelog['tc_status'] ?? 1);
        $conn->beginTransaction();
        try {
            if ($hasTcUReason) {
                $stmt = $conn->prepare("UPDATE teamchangelog SET tc_status = ?, tc_u_reason = ? WHERE tc_ID = ?");
                $stmt->execute([$status, $tc_u_reason ?: null, $tc_ID]);
            } else {
                $stmt = $conn->prepare("UPDATE teamchangelog SET tc_status = ? WHERE tc_ID = ?");
                $stmt->execute([$status, $tc_ID]);
            }

            // 當從「通過」改為「退件」時，還原已套用的異動至初始狀態
            if ($status === 0 && $oldStatus === 3) {
                $team_ID = (int)($changelog['tc_team_ID'] ?? 0);
                $change_type = trim($changelog['change_type'] ?? '');

                if ($team_ID > 0) {
                    if ($change_type === 'TEAM_RENAME' && !empty($changelog['tc_team_name_old'])) {
                        $stmt = $conn->prepare("UPDATE teamdata SET team_project_name = ?, team_update_d = NOW() WHERE team_ID = ?");
                        $stmt->execute([trim($changelog['tc_team_name_old']), $team_ID]);
                    } elseif ($change_type === 'TEACHER_CHANGE') {
                        // 還原：移除新老師，恢復舊老師
                        $newVal = trim($changelog['tc_teacher_new'] ?? '');
                        if ($newVal !== '') {
                            $newTeacherId = null;
                            $stmtU = $conn->prepare("SELECT u_ID FROM userdata WHERE u_ID = ? LIMIT 1");
                            $stmtU->execute([$newVal]);
                            $newTeacherId = $stmtU->fetchColumn();
                            if (!$newTeacherId) {
                                $stmtN = $conn->prepare("SELECT u_ID FROM userdata WHERE u_name = ? LIMIT 1");
                                $stmtN->execute([$newVal]);
                                $newTeacherId = $stmtN->fetchColumn();
                            }
                            if ($newTeacherId) {
                                $upd = $conn->prepare("UPDATE teammember SET tm_status = 0, tm_updated_d = NOW() WHERE team_ID = ? AND $tmCol = ? AND (tm_status IS NULL OR tm_status = 1)");
                                $upd->execute([$team_ID, $newTeacherId]);
                            }
                        }
                        $oldTeacherName = trim($changelog['tc_teacher_old'] ?? '');
                        if ($oldTeacherName !== '') {
                            $oldStmt = $conn->prepare("
                                SELECT tm.$tmCol FROM teammember tm
                                JOIN userdata u ON u.u_ID = tm.$tmCol
                                WHERE tm.team_ID = ? AND u.u_name = ? AND (tm.tm_status = 0)
                                LIMIT 1
                            ");
                            $oldStmt->execute([$team_ID, $oldTeacherName]);
                            $oldTeacherId = $oldStmt->fetchColumn();
                            if ($oldTeacherId) {
                                $upd = $conn->prepare("UPDATE teammember SET tm_status = 1, tm_updated_d = NOW() WHERE team_ID = ? AND $tmCol = ?");
                                $upd->execute([$team_ID, $oldTeacherId]);
                            }
                        }
                    } elseif ($change_type === 'MEMBER_ADD' || $change_type === 'MEMBER_CHANGE') {
                        $memberId = trim($changelog['tc_member'] ?? '');
                        if ($memberId !== '') {
                            $upd = $conn->prepare("UPDATE teammember SET tm_status = 0, tm_updated_d = NOW() WHERE team_ID = ? AND $tmCol = ? AND (tm_status IS NULL OR tm_status = 1)");
                            $upd->execute([$team_ID, $memberId]);
                        }
                    } elseif ($change_type === 'MEMBER_REMOVE') {
                        $memberId = trim($changelog['tc_member'] ?? '');
                        if ($memberId !== '') {
                            $upd = $conn->prepare("UPDATE teammember SET tm_status = 1, tm_updated_d = NOW() WHERE team_ID = ? AND $tmCol = ? AND tm_status = 0");
                            $upd->execute([$team_ID, $memberId]);
                        }
                    }
                }
            }

            // 當改為「通過」且原為「審核中」或「退件」時，套用異動至 teamdata/teammember
            if ($status === 3 && $oldStatus !== 3) {
                $team_ID = (int)($changelog['tc_team_ID'] ?? 0);
                $change_type = trim($changelog['change_type'] ?? '');

                if ($team_ID > 0) {
                    if ($change_type === 'TEAM_RENAME' && !empty($changelog['tc_team_name_new'])) {
                        $stmt = $conn->prepare("UPDATE teamdata SET team_project_name = ?, team_update_d = NOW() WHERE team_ID = ?");
                        $stmt->execute([trim($changelog['tc_team_name_new']), $team_ID]);
                    } elseif ($change_type === 'TEACHER_CHANGE') {
                        $oldTeacherName = trim($changelog['tc_teacher_old'] ?? '');
                        if ($oldTeacherName !== '') {
                            $oldStmt = $conn->prepare("
                                SELECT tm.$tmCol FROM teammember tm
                                JOIN userdata u ON u.u_ID = tm.$tmCol
                                WHERE tm.team_ID = ? AND u.u_name = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)
                                LIMIT 1
                            ");
                            $oldStmt->execute([$team_ID, $oldTeacherName]);
                            $oldTeacherId = $oldStmt->fetchColumn();
                            if ($oldTeacherId) {
                                $upd = $conn->prepare("UPDATE teammember SET tm_status = 0, tm_updated_d = NOW() WHERE team_ID = ? AND $tmCol = ? AND (tm_status IS NULL OR tm_status = 1)");
                                $upd->execute([$team_ID, $oldTeacherId]);
                            }
                        }
                        $newVal = trim($changelog['tc_teacher_new'] ?? '');
                        if ($newVal !== '') {
                            $newTeacherId = null;
                            $stmtU = $conn->prepare("SELECT u_ID FROM userdata WHERE u_ID = ? LIMIT 1");
                            $stmtU->execute([$newVal]);
                            $newTeacherId = $stmtU->fetchColumn();
                            if (!$newTeacherId) {
                                $stmtN = $conn->prepare("SELECT u_ID FROM userdata WHERE u_name = ? LIMIT 1");
                                $stmtN->execute([$newVal]);
                                $newTeacherId = $stmtN->fetchColumn();
                            }
                            if ($newTeacherId) {
                                $chk = $conn->prepare("SELECT COUNT(*) FROM teammember WHERE team_ID = ? AND $tmCol = ?");
                                $chk->execute([$team_ID, $newTeacherId]);
                                if ($chk->fetchColumn() == 0) {
                                    $ins = $conn->prepare("INSERT INTO teammember (team_ID, $tmCol, tm_status, tm_updated_d) VALUES (?, ?, 1, NOW())");
                                    $ins->execute([$team_ID, $newTeacherId]);
                                }
                            }
                        }
                    } elseif ($change_type === 'MEMBER_ADD' || $change_type === 'MEMBER_CHANGE') {
                        $memberId = trim($changelog['tc_member'] ?? '');
                        if ($memberId !== '') {
                            $chk = $conn->prepare("SELECT COUNT(*) FROM teammember WHERE team_ID = ? AND $tmCol = ?");
                            $chk->execute([$team_ID, $memberId]);
                            if ($chk->fetchColumn() == 0) {
                                $ins = $conn->prepare("INSERT INTO teammember (team_ID, $tmCol, tm_status, tm_updated_d) VALUES (?, ?, 1, NOW())");
                                $ins->execute([$team_ID, $memberId]);
                            }
                        }
                    } elseif ($change_type === 'MEMBER_REMOVE') {
                        $memberId = trim($changelog['tc_member'] ?? '');
                        if ($memberId !== '') {
                            $upd = $conn->prepare("UPDATE teammember SET tm_status = 0, tm_updated_d = NOW() WHERE team_ID = ? AND $tmCol = ? AND (tm_status IS NULL OR tm_status = 1)");
                            $upd->execute([$team_ID, $memberId]);
                        }
                    }
                }
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            json_err('操作失敗：' . $e->getMessage());
        }

        // 改為通過或退件時：通知該組別、指導老師、班導（系統通知 + Gmail）
        if (in_array($status, [0, 3])) {
            team_change_notify_status_result($conn, $changelog, $status, $tmCol, $urCol, $u_ID);
        }

        json_ok(['message' => '已更新']);
        break;

    // 系辦：取得可選屆別（用於新增申請單）
    case 'get_active_cohorts_for_form':
        if ($role_ID !== 2) {
            json_err('僅系辦可操作');
        }
        $stmt = $conn->prepare("
            SELECT cohort_ID, cohort_name, year_label
            FROM cohortdata
            WHERE cohort_status IN (1, 3)
            ORDER BY cohort_ID DESC
        ");
        $stmt->execute();
        $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_ok(['cohorts' => $cohorts]);
        break;

    // 系辦：新增申請單（可設定開放填寫時間、截止時間）
    case 'create_team_change_form':
        if ($role_ID !== 2) {
            json_err('僅系辦可新增申請單');
        }
        $hasTeamChangeForm = (bool)$conn->query("SHOW TABLES LIKE 'teamchangeform'")->fetch();
        if (!$hasTeamChangeForm) {
            json_err('teamchangeform 資料表不存在，請先建立');
        }
        $tcf_cohort_ID = (int)($_POST['tcf_cohort_ID'] ?? 0);
        $tcf_change_type = trim($_POST['tcf_change_type'] ?? '');
        $tcf_name = trim($_POST['tcf_name'] ?? '');
        $tcf_open_d = trim($_POST['tcf_open_d'] ?? '');
        $tcf_close_d = trim($_POST['tcf_close_d'] ?? '');

        if ($tcf_cohort_ID <= 0) json_err('請選擇屆別');
        if (!in_array($tcf_change_type, ['TEAM_RENAME', 'TEACHER_CHANGE', 'MEMBER_ADD', 'MEMBER_REMOVE', 'MEMBER_CHANGE'])) {
            json_err('請選擇異動類型');
        }
        $typeNames = ['TEAM_RENAME' => '專題題目變更', 'TEACHER_CHANGE' => '指導老師變更', 'MEMBER_ADD' => '組員新增', 'MEMBER_REMOVE' => '組員退組', 'MEMBER_CHANGE' => '組員異動'];
        if ($tcf_name === '') $tcf_name = $typeNames[$tcf_change_type] . '申請單';

        $openVal = ($tcf_open_d !== '') ? $tcf_open_d : null;
        $closeVal = ($tcf_close_d !== '') ? $tcf_close_d : null;
        if ($openVal && $closeVal && strtotime($openVal) > strtotime($closeVal)) {
            json_err('開放時間不可晚於截止時間');
        }

        $cols = $conn->query("SHOW COLUMNS FROM teamchangeform")->fetchAll(PDO::FETCH_COLUMN);
        $hasOpen = in_array('tcf_open_d', $cols);
        $hasClose = in_array('tcf_close_d', $cols);

        $insCols = 'tcf_cohort_ID, tcf_change_type, tcf_name, tcf_status';
        $insVals = "?, ?, ?, 1";
        $insParams = [$tcf_cohort_ID, $tcf_change_type, mb_substr($tcf_name, 0, 100)];
        if ($hasOpen) { $insCols .= ', tcf_open_d'; $insVals .= ', ?'; $insParams[] = $openVal; }
        if ($hasClose) { $insCols .= ', tcf_close_d'; $insVals .= ', ?'; $insParams[] = $closeVal; }

        $stmt = $conn->prepare("INSERT INTO teamchangeform ($insCols) VALUES ($insVals)");
        $stmt->execute($insParams);
        json_ok(['message' => '申請單已建立', 'tcf_ID' => (int)$conn->lastInsertId()]);
        break;

    // 學生：取得可申請的異動表單（依 teamchangeform：開放/截止、屆別、使用者自訂名稱）
    case 'get_available_change_forms':
        if ($role_ID !== 6) {
            json_err('僅限學生使用');
        }
        $team_ID = (int)($_GET['team_ID'] ?? 0);
        if ($team_ID <= 0) {
            json_ok(['forms' => []]);
            break;
        }
        $stmt = $conn->prepare("SELECT 1 FROM teammember tm JOIN teamdata t ON tm.team_ID = t.team_ID 
            WHERE tm.team_ID = ? AND tm.$tmCol = ? AND t.team_status = 1 AND (tm.tm_status IS NULL OR tm.tm_status = 1) LIMIT 1");
        $stmt->execute([$team_ID, $u_ID]);
        if (!$stmt->fetch()) {
            json_err('您不屬於此組別');
        }
        $teamStmt = $conn->prepare("SELECT cohort_ID FROM teamdata WHERE team_ID = ? LIMIT 1");
        $teamStmt->execute([$team_ID]);
        $teamRow = $teamStmt->fetch(PDO::FETCH_ASSOC);
        $cohort_ID = (int)($teamRow['cohort_ID'] ?? 0);
        if ($cohort_ID <= 0) {
            json_ok(['forms' => []]);
            break;
        }
        $hasTeamChangeForm = (bool)$conn->query("SHOW TABLES LIKE 'teamchangeform'")->fetch();
        if (!$hasTeamChangeForm) {
            $fallback = [
                ['tcf_ID' => 0, 'tcf_name' => '專題題目變更申請單', 'tcf_change_type' => 'TEAM_RENAME'],
                ['tcf_ID' => 0, 'tcf_name' => '專題指導老師變更申請單', 'tcf_change_type' => 'TEACHER_CHANGE'],
                ['tcf_ID' => 0, 'tcf_name' => '專題組員異動(新增)申請單', 'tcf_change_type' => 'MEMBER_ADD'],
                ['tcf_ID' => 0, 'tcf_name' => '專題組員異動(退組)申請單', 'tcf_change_type' => 'MEMBER_REMOVE']
            ];
            json_ok(['forms' => $fallback]);
            break;
        }
        $stmt = $conn->prepare("
            SELECT tcf_ID, tcf_name, tcf_change_type
            FROM teamchangeform
            WHERE tcf_status = 1
              AND tcf_cohort_ID = ?
              AND (tcf_open_d IS NULL OR tcf_open_d <= NOW())
              AND (tcf_close_d IS NULL OR tcf_close_d >= NOW())
            ORDER BY tcf_change_type, tcf_ID
        ");
        $stmt->execute([$cohort_ID]);
        $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_ok(['forms' => $forms]);
        break;

    case 'get_team_change_form_data':
        if ($role_ID !== 6) {
            json_err('僅限學生使用');
        }
        $team_ID = (int)($_GET['team_ID'] ?? 0);
        if ($team_ID <= 0) {
            json_err('請提供有效的團隊ID');
        }

        $urCol = $conn->query("SHOW COLUMNS FROM userrolesdata LIKE 'ur_u_ID'")->fetch() ? 'ur_u_ID' : 'u_ID';

        $stmt = $conn->prepare("SELECT 1 FROM teammember tm JOIN teamdata t ON tm.team_ID = t.team_ID 
            WHERE tm.team_ID = ? AND tm.$tmCol = ? AND t.team_status = 1 AND (tm.tm_status IS NULL OR tm.tm_status = 1) LIMIT 1");
        $stmt->execute([$team_ID, $u_ID]);
        if (!$stmt->fetch()) {
            json_err('您不屬於此組別');
        }

        $stmt = $conn->prepare("SELECT t.team_project_name, t.cohort_ID FROM teamdata t WHERE t.team_ID = ? LIMIT 1");
        $stmt->execute([$team_ID]);
        $team = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$team) {
            json_err('團隊不存在');
        }

        $cohort_ID = (int)($team['cohort_ID'] ?? 0);

        $members = [];
        $teacher = null;
        $stmt = $conn->prepare("
            SELECT tm.$tmCol as u_ID, u.u_name, ur.role_ID
            FROM teammember tm
            JOIN userdata u ON u.u_ID = tm.$tmCol
            LEFT JOIN userrolesdata ur ON ur.$urCol = tm.$tmCol AND ur.user_role_status = 1
            WHERE tm.team_ID = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)
        ");
        $stmt->execute([$team_ID]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            if ((int)($m['role_ID'] ?? 0) === 4) {
                $teacher = ['u_ID' => $m['u_ID'], 'u_name' => $m['u_name']];
            } else {
                $members[] = ['u_ID' => $m['u_ID'], 'u_name' => $m['u_name']];
            }
        }

        $teachers = [];
        if ($cohort_ID) {
            $stmt = $conn->prepare("
                SELECT DISTINCT u.u_ID, u.u_name
                FROM userdata u
                JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID
                JOIN userrolesdata ur ON ur.$urCol = u.u_ID AND ur.role_ID = 4 AND ur.user_role_status = 1
                WHERE e.cohort_ID = ? AND e.role_ID = 4 AND e.enroll_status = 1 AND u.u_status = 1
                ORDER BY u.u_name
            ");
            $stmt->execute([$cohort_ID]);
            $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        json_ok([
            'team_project_name' => $team['team_project_name'] ?? '',
            'teacher' => $teacher,
            'members' => $members,
            'teachers' => $teachers
        ]);
        break;

    // 學生：取得可新增的組員候選（同屆、尚未加入團隊的學生）
    case 'get_available_students_for_add':
        if ($role_ID !== 6) {
            json_err('僅限學生使用');
        }
        $team_ID = (int)($_GET['team_ID'] ?? 0);
        if ($team_ID <= 0) {
            json_err('請提供有效的團隊ID');
        }

        $stmt = $conn->prepare("SELECT t.cohort_ID FROM teamdata t WHERE t.team_ID = ? AND t.team_status = 1 LIMIT 1");
        $stmt->execute([$team_ID]);
        $team = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$team) {
            json_err('團隊不存在');
        }
        $cohort_ID = (int)($team['cohort_ID'] ?? 0);
        if ($cohort_ID <= 0) {
            json_ok(['students' => []]);
            break;
        }

        $stmt = $conn->prepare("
            SELECT u.u_ID, u.u_name
            FROM userdata u
            INNER JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID AND e.cohort_ID = ? AND e.enroll_status = 1 AND e.role_ID = 6
            INNER JOIN userrolesdata ur ON ur.$urCol = u.u_ID AND ur.role_ID = 6 AND ur.user_role_status = 1
            WHERE u.u_status = 1
              AND u.u_ID NOT IN (
                SELECT tm.$tmCol FROM teammember tm
                INNER JOIN teamdata td ON tm.team_ID = td.team_ID AND td.cohort_ID = ? AND (td.team_status = 1 OR td.team_status = 3)
                WHERE tm.tm_status = 1 OR tm.tm_status IS NULL
              )
            ORDER BY u.u_name
        ");
        $stmt->execute([$cohort_ID, $cohort_ID]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        json_ok(['students' => $students]);
        break;

    // 學生：提交變更申請
    case 'submit_change_application':
        if ($role_ID !== 6) {
            json_err('僅限學生使用');
        }
        $team_ID = (int)($_POST['team_ID'] ?? 0);
        $change_type = trim($_POST['change_type'] ?? '');
        if ($team_ID <= 0 || !in_array($change_type, ['TEAM_RENAME', 'TEACHER_CHANGE', 'MEMBER_ADD', 'MEMBER_REMOVE', 'MEMBER_CHANGE'])) {
            json_err('參數錯誤');
        }

        $stmt = $conn->prepare("SELECT t.cohort_ID, t.team_project_name FROM teamdata t WHERE t.team_ID = ? AND t.team_status = 1 LIMIT 1");
        $stmt->execute([$team_ID]);
        $team = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$team) {
            json_err('團隊不存在');
        }

        $stmt = $conn->prepare("SELECT 1 FROM teammember tm WHERE tm.team_ID = ? AND tm.$tmCol = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1) LIMIT 1");
        $stmt->execute([$team_ID, $u_ID]);
        if (!$stmt->fetch()) {
            json_err('您不屬於此組別');
        }

        $cohort_ID = (int)($team['cohort_ID'] ?? 0);

        // 時間驗證：若有 teamchangeform，須有開放中的申請單才能提交（未到開放時間或已過截止時間不可提交）
        $hasTeamChangeForm = (bool)$conn->query("SHOW TABLES LIKE 'teamchangeform'")->fetch();
        if ($hasTeamChangeForm && $cohort_ID > 0) {
            $chkStmt = $conn->prepare("
                SELECT 1 FROM teamchangeform
                WHERE tcf_status = 1 AND tcf_cohort_ID = ? AND tcf_change_type = ?
                  AND (tcf_open_d IS NULL OR tcf_open_d <= NOW())
                  AND (tcf_close_d IS NULL OR tcf_close_d >= NOW())
                LIMIT 1
            ");
            $chkStmt->execute([$cohort_ID, $change_type]);
            if (!$chkStmt->fetch()) {
                json_err('該類型申請單目前未開放填寫，請於開放時間內提交');
            }
        }

        $tc_team_name_old = null;
        $tc_team_name_new = null;
        $tc_teacher_old = null;
        $tc_teacher_new = null;
        $tc_member = null;

        if ($change_type === 'TEAM_RENAME') {
            $tc_team_name_old = trim($team['team_project_name'] ?? '');
            $tc_team_name_new = trim($_POST['tc_team_name_new'] ?? '');
            if ($tc_team_name_new === '') {
                json_err('請填寫新專題題目');
            }
        } elseif ($change_type === 'TEACHER_CHANGE') {
            $teacherStmt = $conn->prepare("
                SELECT u.u_ID, u.u_name FROM teammember tm
                JOIN userdata u ON u.u_ID = tm.$tmCol
                JOIN userrolesdata ur ON ur.$urCol = tm.$tmCol AND ur.role_ID = 4 AND ur.user_role_status = 1
                WHERE tm.team_ID = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1) LIMIT 1
            ");
            $teacherStmt->execute([$team_ID]);
            $oldTeacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
            $tc_teacher_old = $oldTeacher['u_name'] ?? '';
            $newTeacherId = trim($_POST['tc_teacher_new'] ?? '');
            if ($newTeacherId === '') {
                json_err('請選擇新指導老師');
            }
            $nameStmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ? LIMIT 1");
            $nameStmt->execute([$newTeacherId]);
            $tc_teacher_new = $nameStmt->fetchColumn() ?: $newTeacherId;
        } elseif ($change_type === 'MEMBER_ADD' || $change_type === 'MEMBER_CHANGE') {
            $tc_member = trim($_POST['tc_member'] ?? '');
            if ($tc_member === '') {
                json_err('請選擇要新增的組員');
            }
        } elseif ($change_type === 'MEMBER_REMOVE') {
            $tc_member = trim($_POST['tc_member'] ?? '');
            if ($tc_member === '') {
                json_err('請選擇要退出的組員');
            }
            $stmt = $conn->prepare("SELECT 1 FROM teammember tm WHERE tm.team_ID = ? AND tm.$tmCol = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1) LIMIT 1");
            $stmt->execute([$team_ID, $tc_member]);
            if (!$stmt->fetch()) {
                json_err('該成員不屬於此組別');
            }
        }

        $tc_reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 500);
        $tc_attachment = null;
        if (!empty($_FILES['attachment']['tmp_name']) && is_uploaded_file($_FILES['attachment']['tmp_name'])) {
            $f = $_FILES['attachment'];
            $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION)) ?: 'jpg';
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) $ext = 'jpg';
            $uploadDir = __DIR__ . '/../uploads/team_change';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $safeName = 'tc_' . $u_ID . '_' . time() . '.' . $ext;
            $fullPath = $uploadDir . '/' . $safeName;
            if (move_uploaded_file($f['tmp_name'], $fullPath)) {
                $tc_attachment = 'uploads/team_change/' . $safeName;
            }
        }
        $hasTcAttachment = (bool)$conn->query("SHOW COLUMNS FROM teamchangelog LIKE 'tc_attachment'")->fetch();
        if ($hasTcAttachment && $tc_attachment) {
            $stmt = $conn->prepare("
                INSERT INTO teamchangelog (tc_cohort, tc_team_ID, change_type, tc_team_name_old, tc_team_name_new, tc_teacher_old, tc_teacher_new, tc_member, tc_reason, tc_attachment, tc_created_u_ID, tc_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$cohort_ID, $team_ID, $change_type, $tc_team_name_old, $tc_team_name_new, $tc_teacher_old, $tc_teacher_new, $tc_member, $tc_reason, $tc_attachment, $u_ID]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO teamchangelog (tc_cohort, tc_team_ID, change_type, tc_team_name_old, tc_team_name_new, tc_teacher_old, tc_teacher_new, tc_member, tc_reason, tc_created_u_ID, tc_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$cohort_ID, $team_ID, $change_type, $tc_team_name_old, $tc_team_name_new, $tc_teacher_old, $tc_teacher_new, $tc_member, $tc_reason, $u_ID]);
        }

        $newTcId = (int)$conn->lastInsertId();

        $typeLabels = ['TEAM_RENAME' => '專題題目變更', 'TEACHER_CHANGE' => '指導老師變更', 'MEMBER_ADD' => '組員新增', 'MEMBER_REMOVE' => '組員退組', 'MEMBER_CHANGE' => '組員異動'];
        $typeLabel = $typeLabels[$change_type] ?? '異動';
        $creatorStmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ? LIMIT 1");
        $creatorStmt->execute([$u_ID]);
        $creatorName = $creatorStmt->fetchColumn() ?: $u_ID;
        $teamName = $team['team_project_name'] ?? "團隊 #{$team_ID}";

        $recipients = [];
        // 該組別所有人（學生 + 指導老師）
        $stmt = $conn->prepare("SELECT tm.{$tmCol} as u_ID FROM teammember tm WHERE tm.team_ID = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)");
        $stmt->execute([$team_ID]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $recipients[(int)$r['u_ID']] = true;
        // 班導
        $stmt = $conn->prepare("
            SELECT DISTINCT e2.enroll_u_ID FROM teammember tm
            JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$tmCol} AND e.cohort_ID = ? AND e.enroll_status = 1
            JOIN enrollmentdata e2 ON e2.class_ID = e.class_ID AND e2.role_ID = 3 AND e2.enroll_status = 1
            WHERE tm.team_ID = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)
        ");
        $stmt->execute([$cohort_ID, $team_ID]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $recipients[(int)$r['enroll_u_ID']] = true;
        // 科辦、主任
        $stmt = $conn->prepare("SELECT ur.{$urCol} as u_ID FROM userrolesdata ur WHERE ur.role_ID IN (1, 2) AND ur.user_role_status = 1");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $recipients[(int)$r['u_ID']] = true;

        $msgTitle = "組別異動申請：{$typeLabel}（{$teamName}）";
        $msgContent = "學生 {$creatorName} 已送出「{$typeLabel}」申請，請前往組別異動紀錄頁面審核。";
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        $teamChangeUrl = rtrim($base . $scriptDir, '/') . '/main.php#pages/team_change.php';
        $urlData = [['type' => 'link', 'url' => $teamChangeUrl, 'label' => '查看組別異動紀錄']];
        $msg_url = json_encode($urlData, JSON_UNESCAPED_UNICODE);

        $stmtMsg = $conn->prepare("
            INSERT INTO msgdata (msg_title, msg_content, msg_url, msg_type, msg_a_u_ID, msg_status, msg_start_d, msg_created_d)
            VALUES (?, ?, ?, 'SYSTEM_NOTICE', ?, 1, NOW(), NOW())
        ");
        $stmtTarget = $conn->prepare("INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID) VALUES (?, 'USER', ?)");
        foreach (array_keys($recipients) as $targetUid) {
            if ($targetUid <= 0) continue;
            $userStmt = $conn->prepare("SELECT u_name, u_gmail FROM userdata WHERE u_ID = ? LIMIT 1");
            $userStmt->execute([$targetUid]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) continue;

            $stmtMsg->execute([$msgTitle, $msgContent, $msg_url, $u_ID]);
            $msg_ID = $conn->lastInsertId();
            if ($msg_ID) $stmtTarget->execute([$msg_ID, $targetUid]);

            if (!empty($user['u_gmail']) && filter_var(trim($user['u_gmail']), FILTER_VALIDATE_EMAIL)) {
                $emailBody = "{$user['u_name']} 您好，\n\n{$msgContent}\n\n請登入系統查看：{$teamChangeUrl}\n\n---\n專題日總彙系統";
                sendMailViaGas(trim($user['u_gmail']), $msgTitle, $emailBody);
                usleep(200000);
            }
        }

        // 寫入時間軸：送出組別異動申請
        team_timeline_add_event(
            $conn,
            $team_ID,
            '組別異動',
            '送出申請',
            $typeLabel,
            "送出{$typeLabel}申請",
            $tc_reason,
            'teamchangelog',
            $newTcId,
            'team_change',
            null,
            $u_ID
        );

        json_ok(['message' => '申請已送出，請等待審核。組別成員、科辦、主任、班導與指導老師將收到通知。']);
        break;

    // 學生：重新申請（退件後編輯並再次提交，使用 UPDATE）
    case 'reapply_changelog':
        if ($role_ID !== 6) {
            json_err('僅限學生使用');
        }
        $tc_ID = (int)($_POST['tc_ID'] ?? 0);
        if ($tc_ID <= 0) {
            json_err('無效的異動編號');
        }

        $stmt = $conn->prepare("SELECT * FROM teamchangelog WHERE tc_ID = ? AND tc_created_u_ID = ? LIMIT 1");
        $stmt->execute([$tc_ID, $u_ID]);
        $changelog = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$changelog) {
            json_err('找不到該異動紀錄或無權限重新申請');
        }
        if ((int)($changelog['tc_status'] ?? 1) !== 0) {
            json_err('僅能重新申請「退件」的紀錄');
        }

        $team_ID = (int)($changelog['tc_team_ID'] ?? 0);
        $change_type = trim($changelog['change_type'] ?? '');
        $cohort_ID = (int)($changelog['tc_cohort'] ?? 0);
        if (!in_array($change_type, ['TEAM_RENAME', 'TEACHER_CHANGE', 'MEMBER_ADD', 'MEMBER_REMOVE', 'MEMBER_CHANGE'])) {
            json_err('不支援的異動類型');
        }

        // 時間驗證：若有 teamchangeform，須有開放中的申請單才能重新申請
        $hasTeamChangeForm = (bool)$conn->query("SHOW TABLES LIKE 'teamchangeform'")->fetch();
        if ($hasTeamChangeForm && $cohort_ID > 0) {
            $chkStmt = $conn->prepare("
                SELECT 1 FROM teamchangeform
                WHERE tcf_status = 1 AND tcf_cohort_ID = ? AND tcf_change_type = ?
                  AND (tcf_open_d IS NULL OR tcf_open_d <= NOW())
                  AND (tcf_close_d IS NULL OR tcf_close_d >= NOW())
                LIMIT 1
            ");
            $chkStmt->execute([$cohort_ID, $change_type]);
            if (!$chkStmt->fetch()) {
                json_err('該類型申請單目前未開放填寫，請於開放時間內提交');
            }
        }

        $stmt = $conn->prepare("SELECT 1 FROM teammember tm WHERE tm.team_ID = ? AND tm.$tmCol = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1) LIMIT 1");
        $stmt->execute([$team_ID, $u_ID]);
        if (!$stmt->fetch()) {
            json_err('您不屬於此組別');
        }

        $tc_team_name_new = null;
        $tc_teacher_new = null;
        $tc_member = null;

        if ($change_type === 'TEAM_RENAME') {
            $tc_team_name_new = trim($_POST['tc_team_name_new'] ?? '');
            if ($tc_team_name_new === '') {
                json_err('請填寫新專題題目');
            }
        } elseif ($change_type === 'TEACHER_CHANGE') {
            $newTeacherId = trim($_POST['tc_teacher_new'] ?? '');
            if ($newTeacherId === '') {
                json_err('請選擇新指導老師');
            }
            $nameStmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ? LIMIT 1");
            $nameStmt->execute([$newTeacherId]);
            $tc_teacher_new = $nameStmt->fetchColumn() ?: $newTeacherId;
        } elseif ($change_type === 'MEMBER_ADD' || $change_type === 'MEMBER_CHANGE') {
            $tc_member = trim($_POST['tc_member'] ?? '');
            if ($tc_member === '') {
                json_err('請選擇要新增的組員');
            }
        } elseif ($change_type === 'MEMBER_REMOVE') {
            $tc_member = trim($_POST['tc_member'] ?? '');
            if ($tc_member === '') {
                json_err('請選擇要退出的組員');
            }
            $stmt = $conn->prepare("SELECT 1 FROM teammember tm WHERE tm.team_ID = ? AND tm.$tmCol = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1) LIMIT 1");
            $stmt->execute([$team_ID, $tc_member]);
            if (!$stmt->fetch()) {
                json_err('該成員不屬於此組別');
            }
        }

        $tc_reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 500);

        $conn->beginTransaction();
        try {
            if ($change_type === 'TEAM_RENAME') {
                $stmt = $conn->prepare("UPDATE teamchangelog SET tc_team_name_new = ?, tc_reason = ?, tc_status = 1 WHERE tc_ID = ?");
                $stmt->execute([$tc_team_name_new, $tc_reason, $tc_ID]);
            } elseif ($change_type === 'TEACHER_CHANGE') {
                $stmt = $conn->prepare("UPDATE teamchangelog SET tc_teacher_new = ?, tc_reason = ?, tc_status = 1 WHERE tc_ID = ?");
                $stmt->execute([$tc_teacher_new, $tc_reason, $tc_ID]);
            } elseif (in_array($change_type, ['MEMBER_ADD', 'MEMBER_REMOVE', 'MEMBER_CHANGE'])) {
                $stmt = $conn->prepare("UPDATE teamchangelog SET tc_member = ?, tc_reason = ?, tc_status = 1 WHERE tc_ID = ?");
                $stmt->execute([$tc_member, $tc_reason, $tc_ID]);
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            json_err('操作失敗：' . $e->getMessage());
        }

        json_ok(['message' => '已重新送出申請，請等待審核。']);
        break;

    default:
        json_err('Unknown action');
}
