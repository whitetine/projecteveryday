<!-- modules/team.php -->
<?php
global $conn;
$p = $_POST;
$do = $_GET['do'] ?? '';

// 檢查權限（主任 role_ID = 1 和 科辦 role_ID = 2）
$role_ID = $_SESSION['role_ID'] ?? null;
if (!isset($role_ID) || !in_array($role_ID, [1, 2])) {
    json_err('無權限訪問');
}

// 檢查欄位名稱（兼容不同版本的資料表結構）
function columnExists(PDO $conn, string $table, string $column): bool
{
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

function insert_teamchangelog(PDO $conn, int $tc_cohort, int $tc_team_ID, string $change_type, ?string $tc_team_name_old, ?string $tc_team_name_new, ?string $tc_teacher_old, ?string $tc_teacher_new, ?string $tc_member, string $tc_created_u_ID): void {
    $stmt = $conn->prepare("
        INSERT INTO teamchangelog (tc_cohort, tc_team_ID, change_type, tc_team_name_old, tc_team_name_new, tc_teacher_old, tc_teacher_new, tc_member, tc_created_u_ID, tc_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([
        $tc_cohort, $tc_team_ID, $change_type,
        $tc_team_name_old, $tc_team_name_new, $tc_teacher_old, $tc_teacher_new, $tc_member,
        $tc_created_u_ID
    ]);
}

switch ($do) {
    // 取得篩選選項（屆別、年級、類組、班級）
    case 'get_filter_options':
        try {
            // 取得所有有專題的屆別（含啟用 status=1 與已結案 status=3）
            $stmt = $conn->prepare("
                SELECT DISTINCT 
                    c.cohort_ID,
                    c.cohort_name,
                    c.cohort_status
                FROM cohortdata c
                INNER JOIN teamdata t ON t.cohort_ID = c.cohort_ID
                WHERE c.cohort_status IN (1, 3)
                ORDER BY c.cohort_ID DESC
            ");
            $stmt->execute();
            $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 取得所有有專題的年級（不限制 team_status）
            $stmt = $conn->prepare("
                SELECT DISTINCT 
                    e.enroll_grade
                FROM enrollmentdata e
                INNER JOIN teammember tm ON tm.{$teamUserField} = e.enroll_u_ID
                INNER JOIN teamdata t ON t.team_ID = tm.team_ID
                WHERE e.enroll_status = 1 
                  AND tm.tm_status = 1
                  AND e.enroll_grade IS NOT NULL
                  AND e.enroll_grade != ''
                ORDER BY e.enroll_grade ASC
            ");
            $stmt->execute();
            $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 取得所有有專題的類組（不限制 team_status）
            $stmt = $conn->prepare("
                SELECT DISTINCT 
                    g.group_ID,
                    g.group_name
                FROM groupdata g
                INNER JOIN teamdata t ON t.group_ID = g.group_ID
                WHERE g.group_status = 1
                ORDER BY g.group_ID ASC
            ");
            $stmt->execute();
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 取得所有有專題的班級（不限制 team_status）
            $stmt = $conn->prepare("
                SELECT DISTINCT 
                    c.c_ID,
                    c.c_name
                FROM classdata c
                INNER JOIN enrollmentdata e ON e.class_ID = c.c_ID
                INNER JOIN teammember tm ON tm.{$teamUserField} = e.enroll_u_ID
                INNER JOIN teamdata t ON t.team_ID = tm.team_ID
                WHERE e.enroll_status = 1 
                  AND tm.tm_status = 1
                ORDER BY c.c_ID ASC
            ");
            $stmt->execute();
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_ok([
                'success' => true,
                'data' => [
                    'cohorts' => $cohorts,
                    'grades' => $grades,
                    'groups' => $groups,
                    'classes' => $classes
                ]
            ]);
        } catch (Exception $e) {
            json_err('取得篩選選項失敗：' . $e->getMessage());
        }
        break;
    case 'get_cohort_options': {
            try {
                // 含啟用 status=1 與已結案 status=3
                $stmt = $conn->prepare("
            SELECT cohort_ID, cohort_name, year_label, cohort_status
            FROM cohortdata
            WHERE cohort_status IN (1, 3)
            ORDER BY year_label DESC, cohort_ID DESC
        ");
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'ok' => true,
                    'success' => true,
                    'data' => $rows
                ], JSON_UNESCAPED_UNICODE);
            } catch (Exception $e) {
                echo json_encode([
                    'ok' => false,
                    'success' => false,
                    'msg' => 'get_cohort_options 失敗：' . $e->getMessage()
                ], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }

        // 取得團隊管理資料（按類組分組）
    case 'get_team_management_data':
        try {
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
            $grade = isset($_GET['grade']) ? trim($_GET['grade']) : '';
            $group_ID = isset($_GET['group_ID']) ? (int)$_GET['group_ID'] : 0;
            $class_ID = isset($_GET['class_ID']) ? (int)$_GET['class_ID'] : 0;

            // 構建查詢條件
            $whereConditions = ['1=1'];
            $params = [];

            if ($cohort_ID > 0) {
                $whereConditions[] = 't.cohort_ID = ?';
                $params[] = $cohort_ID;
            }

            if ($group_ID > 0) {
                $whereConditions[] = 't.group_ID = ?';
                $params[] = $group_ID;
            }

            // 如果有年級或班級篩選，需要 JOIN enrollmentdata
            $needsEnrollmentJoin = !empty($grade) || $class_ID > 0;

            if ($needsEnrollmentJoin) {
                $enrollmentConditions = [];
                if (!empty($grade)) {
                    $enrollmentConditions[] = 'e.enroll_grade = ?';
                    $params[] = $grade;
                }
                if ($class_ID > 0) {
                    $enrollmentConditions[] = 'e.class_ID = ?';
                    $params[] = $class_ID;
                }
            }

            // 取得所有符合條件的類組（僅含啟用中的團隊）
            $groupSql = "
                SELECT DISTINCT 
                    g.group_ID,
                    g.group_name
                FROM groupdata g
                INNER JOIN teamdata t ON t.group_ID = g.group_ID AND t.team_status = 1
            ";

            if ($needsEnrollmentJoin) {
                $groupSql .= "
                    INNER JOIN teammember tm ON tm.team_ID = t.team_ID AND tm.tm_status = 1
                    INNER JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField} AND e.enroll_status = 1
                ";
            }

            $groupSql .= " WHERE " . implode(' AND ', $whereConditions);

            if ($needsEnrollmentJoin && !empty($enrollmentConditions)) {
                $groupSql .= " AND " . implode(' AND ', $enrollmentConditions);
            }

            $groupSql .= " ORDER BY g.group_ID ASC";

            $stmt = $conn->prepare($groupSql);
            $stmt->execute($params);
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [
                'groups' => [],
                'noTeamMembers' => []
            ];

            // 對每個類組取得團隊資料
            foreach ($groups as $group) {
                $group_ID = $group['group_ID'];

                // 取得該類組的所有團隊
                $teamSql = "
                    SELECT DISTINCT
                        t.team_ID,
                        t.team_project_name,
                        t.group_ID,
                        t.cohort_ID,
                        t.team_status,
                        c.cohort_name
                    FROM teamdata t
                    LEFT JOIN cohortdata c ON c.cohort_ID = t.cohort_ID
                ";

                $teamParams = [];
                $teamWhere = ['t.group_ID = ?', 't.team_status = 1'];
                $teamParams[] = $group_ID;

                if ($cohort_ID > 0) {
                    $teamWhere[] = 't.cohort_ID = ?';
                    $teamParams[] = $cohort_ID;
                }

                if ($needsEnrollmentJoin) {
                    $teamSql .= "
                        INNER JOIN teammember tm ON tm.team_ID = t.team_ID AND tm.tm_status = 1
                        INNER JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField} AND e.enroll_status = 1
                    ";

                    if (!empty($grade)) {
                        $teamWhere[] = 'e.enroll_grade = ?';
                        $teamParams[] = $grade;
                    }
                    if ($class_ID > 0) {
                        $teamWhere[] = 'e.class_ID = ?';
                        $teamParams[] = $class_ID;
                    }
                }

                $teamSql .= " WHERE " . implode(' AND ', $teamWhere);
                $teamSql .= " ORDER BY t.team_project_name ASC";

                $stmt = $conn->prepare($teamSql);
                $stmt->execute($teamParams);
                $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // 計算每個團隊的進度（根據基本需求）並獲取成員
                $teamsWithProgress = [];
                foreach ($teams as $team) {
                    $team_ID = $team['team_ID'];

                    // 取得團隊成員（學生 role_ID = 6 和指導老師 role_ID = 4）
                    $memberStmt = $conn->prepare("
    SELECT
        tm.{$teamUserField} AS u_ID,
        COALESCE(ud.u_name, tm.{$teamUserField}) AS u_name,
        ud.u_img,
        e.role_ID,
        r.role_name
    FROM teammember tm
    JOIN teamdata t 
        ON t.team_ID = tm.team_ID

    JOIN (
        SELECT enroll_u_ID, cohort_ID, MAX(enroll_ID) AS max_enroll_ID
        FROM enrollmentdata
        WHERE enroll_status = 1
          AND role_ID IN (4, 6)
        GROUP BY enroll_u_ID, cohort_ID
    ) em 
        ON em.enroll_u_ID = tm.{$teamUserField}
       AND em.cohort_ID   = t.cohort_ID

    JOIN enrollmentdata e
        ON e.enroll_ID = em.max_enroll_ID

    LEFT JOIN userdata ud 
        ON ud.u_ID = tm.{$teamUserField}
    LEFT JOIN roledata r 
        ON r.role_ID = e.role_ID

    WHERE tm.team_ID = ?
      AND tm.tm_status = 1

    ORDER BY e.role_ID ASC, tm.{$teamUserField}
");
                    $memberStmt->execute([$team_ID]);
                    $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);


                    // 計算進度：已完成基本需求 / 總基本需求數
                    // 總基本需求：該團隊所屬類組和屆別的所有基本需求
                    $progressStmt = $conn->prepare("
                        SELECT 
                            COUNT(DISTINCT r.req_ID) as total,
                            COUNT(DISTINCT CASE WHEN rp.rp_status = 1 AND rp.rp_team_ID = ? THEN rp.req_ID END) as completed
                        FROM requirementdata r
                        LEFT JOIN reprogressdata rp ON rp.req_ID = r.req_ID
                        WHERE r.req_status = 1
                          AND (r.cohort_ID = ? OR r.cohort_ID IS NULL)
                          AND (r.group_ID = ? OR r.group_ID IS NULL)
                    ");
                    $progressStmt->execute([$team_ID, $team['cohort_ID'], $group_ID]);
                    $progressData = $progressStmt->fetch(PDO::FETCH_ASSOC);

                    $total = (int)($progressData['total'] ?? 0);
                    $completed = (int)($progressData['completed'] ?? 0);
                    $progress = $total > 0 ? ($completed / $total) * 100 : 0;

                    $team['progress'] = round($progress, 1);
                    $team['members'] = $members; // 添加成員數據
                    $teamsWithProgress[] = $team;
                }

                // 按進度排序（降序）
                usort($teamsWithProgress, function ($a, $b) {
                    return $b['progress'] <=> $a['progress'];
                });

                // 取得類組的屆別資訊（從第一個團隊取得，或使用篩選條件）
                $groupCohortId = $cohort_ID > 0 ? $cohort_ID : (count($teamsWithProgress) > 0 ? $teamsWithProgress[0]['cohort_ID'] : null);
                $groupCohortName = null;

                if ($groupCohortId) {
                    $cohortStmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ?");
                    $cohortStmt->execute([$groupCohortId]);
                    $cohortData = $cohortStmt->fetch(PDO::FETCH_ASSOC);
                    $groupCohortName = $cohortData['cohort_name'] ?? null;
                } else if (count($teamsWithProgress) > 0 && isset($teamsWithProgress[0]['cohort_name'])) {
                    // 如果已經從 JOIN 中取得了屆別名稱
                    $groupCohortName = $teamsWithProgress[0]['cohort_name'];
                }

                if (count($teamsWithProgress) > 0) {
                    $result['groups'][] = [
                        'group_ID' => $group_ID,
                        'group_name' => $group['group_name'],
                        'cohort_ID' => $groupCohortId,
                        'cohort_name' => $groupCohortName,
                        'teams' => $teamsWithProgress
                    ];
                }
            }

            // 取得未加入團隊的學生（根據篩選條件）
            $noTeamSql = "
                SELECT DISTINCT
                    u.u_ID,
                    u.u_name,
                    u.u_img,
                    e.class_ID,
                    c.c_name as class_name,
                    e.cohort_ID,
                    co.cohort_name,
                    e.enroll_grade
                FROM userdata u
                INNER JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID 
                    AND e.enroll_status = 1
                INNER JOIN userrolesdata ur ON ur.{$userRoleUidField} = u.u_ID 
                    AND ur.role_ID = 6 
                    AND ur.user_role_status = 1
                LEFT JOIN classdata c ON c.c_ID = e.class_ID
                LEFT JOIN cohortdata co ON co.cohort_ID = e.cohort_ID
                LEFT JOIN teammember tm ON tm.{$teamUserField} = u.u_ID 
                    AND tm.tm_status = 1
                WHERE tm.team_ID IS NULL
            ";

            $noTeamParams = [];
            $noTeamWhere = [];

            if ($cohort_ID > 0) {
                $noTeamWhere[] = 'e.cohort_ID = ?';
                $noTeamParams[] = $cohort_ID;
            }
            if (!empty($grade)) {
                $noTeamWhere[] = 'e.enroll_grade = ?';
                $noTeamParams[] = $grade;
            }
            if ($class_ID > 0) {
                $noTeamWhere[] = 'e.class_ID = ?';
                $noTeamParams[] = $class_ID;
            }

            if (!empty($noTeamWhere)) {
                $noTeamSql .= " AND " . implode(' AND ', $noTeamWhere);
            }

            $noTeamSql .= " ORDER BY u.u_ID ASC";

            $stmt = $conn->prepare($noTeamSql);
            $stmt->execute($noTeamParams);
            $noTeamMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result['noTeamMembers'] = $noTeamMembers;

            // ========== 儀表板統計與每組/老師的 min/max、負載 ==========
            $cohortIds = [];
            $teacherTeamCount = []; // (u_ID, cohort_ID) => count
            $teacherNames = []; // u_ID => u_name

            foreach ($result['groups'] as &$grp) {
                foreach ($grp['teams'] as &$team) {
                    $cid = (int)($team['cohort_ID'] ?? 0);
                    if ($cid > 0) $cohortIds[$cid] = true;

                    $members = $team['members'] ?? [];
                    $teachers = array_filter($members, fn($m) => (int)($m['role_ID'] ?? 0) === 4);
                    $students = array_filter($members, fn($m) => (int)($m['role_ID'] ?? 0) === 6);
                    $studentCount = count($students);

                    foreach ($teachers as $t) {
                        $uid = $t['u_ID'] ?? '';
                        if ($uid) {
                            $key = $uid . '_' . $cid;
                            $teacherTeamCount[$key] = ($teacherTeamCount[$key] ?? 0) + 1;
                            $teacherNames[$uid] = $t['u_name'] ?? $uid;
                        }
                    }
                }
            }
            unset($grp, $team);

            // 取得屆別的人數限制（teammemberlimit）
            $memberLimitByCohort = [];
            if (count($cohortIds) > 0 && columnExists($conn, 'teammemberlimit', 'cohort_ID')) {
                $placeholders = implode(',', array_fill(0, count($cohortIds), '?'));
                $stmt = $conn->prepare("SELECT cohort_ID, min_count, max_count FROM teammemberlimit WHERE cohort_ID IN ($placeholders)");
                $stmt->execute(array_keys($cohortIds));
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $memberLimitByCohort[(int)$row['cohort_ID']] = [
                        'min_count' => (int)$row['min_count'],
                        'max_count' => (int)$row['max_count']
                    ];
                }
            }

            // 取得老師帶組上限（teacherteamlimit）
            $teacherLimitByKey = [];
            if (count($cohortIds) > 0 && columnExists($conn, 'teacherteamlimit', 'ttl_u_ID')) {
                $placeholders = implode(',', array_fill(0, count($cohortIds), '?'));
                $stmt = $conn->prepare("SELECT ttl_u_ID, cohort_ID, max_count FROM teacherteamlimit WHERE cohort_ID IN ($placeholders)");
                $stmt->execute(array_keys($cohortIds));
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $key = $row['ttl_u_ID'] . '_' . $row['cohort_ID'];
                    $teacherLimitByKey[$key] = (int)$row['max_count'];
                }
            }

            $defaultMin = 2;
            $defaultMax = 5;
            $defaultTeacherMax = 4;

            $dashboard = [
                'total_teams' => 0,
                'total_in_team_students' => 0,
                'total_no_team_students' => count($noTeamMembers),
                'total_teachers' => 0,
                'under_min_count' => 0,
                'over_max_count' => 0
            ];
            $teacherIdsSeen = [];

            foreach ($result['groups'] as &$grp) {
                foreach ($grp['teams'] as &$team) {
                    $cid = (int)($team['cohort_ID'] ?? 0);
                    $members = $team['members'] ?? [];
                    $teachers = array_filter($members, fn($m) => (int)($m['role_ID'] ?? 0) === 4);
                    $students = array_filter($members, fn($m) => (int)($m['role_ID'] ?? 0) === 6);
                    $studentCount = count($students);

                    $limit = $memberLimitByCohort[$cid] ?? null;
                    $minMember = $limit ? (int)$limit['min_count'] : $defaultMin;
                    $maxMember = $limit ? (int)$limit['max_count'] : $defaultMax;

                    $status = 'normal';
                    if ($studentCount < $minMember) {
                        $status = 'under';
                        $dashboard['under_min_count']++;
                    } elseif ($studentCount > $maxMember) {
                        $status = 'over';
                        $dashboard['over_max_count']++;
                    }

                    $team['member_count'] = $studentCount;
                    $team['min_member'] = $minMember;
                    $team['max_member'] = $maxMember;
                    $team['status'] = $status;

                    $team['teachers'] = [];
                    foreach ($teachers as $t) {
                        $uid = $t['u_ID'] ?? '';
                        if (!$uid) continue;
                        $key = $uid . '_' . $cid;
                        $teamCount = $teacherTeamCount[$key] ?? 0;
                        $maxLimit = $teacherLimitByKey[$key] ?? $defaultTeacherMax;
                        $loadStatus = 'ok';
                        if ($teamCount >= $maxLimit) $loadStatus = 'full';
                        elseif ($teamCount >= $maxLimit - 1) $loadStatus = 'warning';

                        $team['teachers'][] = [
                            'u_ID' => $uid,
                            'u_name' => $t['u_name'] ?? $teacherNames[$uid] ?? $uid,
                            'team_count' => $teamCount,
                            'max_limit' => $maxLimit,
                            'load_status' => $loadStatus
                        ];
                        if (!isset($teacherIdsSeen[$uid])) {
                            $teacherIdsSeen[$uid] = true;
                            $dashboard['total_teachers']++;
                        }
                    }

                    $dashboard['total_teams']++;
                    $dashboard['total_in_team_students'] += $studentCount;
                }
            }
            unset($grp, $team);

            $result['dashboard'] = $dashboard;

            // 當有篩選屆別時，回傳該屆別的 cohort_status（3=已結案時前端禁用修改）
            if ($cohort_ID > 0) {
                $csStmt = $conn->prepare("SELECT cohort_status FROM cohortdata WHERE cohort_ID = ?");
                $csStmt->execute([$cohort_ID]);
                $csRow = $csStmt->fetch(PDO::FETCH_ASSOC);
                $result['cohort_status'] = (int)($csRow['cohort_status'] ?? 1);
            } else {
                $result['cohort_status'] = 1;
            }

            json_ok(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            json_err('取得團隊資料失敗：' . $e->getMessage());
        }
        break;

    // 取得團隊詳情
    case 'get_team_detail':
        try {
            $team_ID = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : 0;

            if (!$team_ID) {
                json_err('缺少團隊ID');
            }

            // 取得團隊基本資訊（含 cohort_status 供前端判斷是否可修改）
            $stmt = $conn->prepare("
                SELECT 
                    t.team_ID,
                    t.team_project_name,
                    t.group_ID,
                    t.cohort_ID,
                    t.team_status,
                    g.group_name,
                    c.cohort_name,
                    c.cohort_status
                FROM teamdata t
                LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
                WHERE t.team_ID = ?
            ");
            $stmt->execute([$team_ID]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$team) {
                json_err('找不到團隊');
            }

            $stmt = $conn->prepare("
    SELECT
        tm_u.u_ID,
        COALESCE(ud.u_name, tm_u.u_ID) AS u_name,
        ud.u_img,
        COALESCE(er.role_ID, ur_pick.role_ID) AS role_ID,
        r.role_name,
        er.enroll_grade,
        er.class_ID,
        cd.c_name AS class_name
    FROM (
        SELECT tm.{$teamUserField} AS u_ID
        FROM teammember tm
        WHERE tm.team_ID = ?
          AND tm.tm_status = 1
        GROUP BY tm.{$teamUserField}
    ) tm_u
    LEFT JOIN userdata ud ON ud.u_ID = tm_u.u_ID
    LEFT JOIN (
        SELECT e1.enroll_u_ID, e1.role_ID, e1.enroll_grade, e1.class_ID
        FROM enrollmentdata e1
        INNER JOIN (
            SELECT enroll_u_ID, MAX(enroll_ID) AS max_enroll_ID
            FROM enrollmentdata
            WHERE enroll_status = 1
              AND cohort_ID = ?
              AND role_ID IN (4, 6)
            GROUP BY enroll_u_ID
        ) em ON em.max_enroll_ID = e1.enroll_ID
    ) er ON er.enroll_u_ID = tm_u.u_ID
    LEFT JOIN (
        SELECT
            ur.{$userRoleUidField} AS u_ID,
            CASE
                WHEN MAX(CASE WHEN ur.role_ID = 4 THEN 1 ELSE 0 END) = 1 THEN 4
                WHEN MAX(CASE WHEN ur.role_ID = 6 THEN 1 ELSE 0 END) = 1 THEN 6
                ELSE NULL
            END AS role_ID
        FROM userrolesdata ur
        WHERE ur.user_role_status = 1
          AND ur.role_ID IN (4, 6)
        GROUP BY ur.{$userRoleUidField}
    ) ur_pick ON ur_pick.u_ID = tm_u.u_ID
    LEFT JOIN roledata r ON r.role_ID = COALESCE(er.role_ID, ur_pick.role_ID)
    LEFT JOIN classdata cd ON cd.c_ID = er.class_ID
    WHERE COALESCE(er.role_ID, ur_pick.role_ID) IN (4, 6)
    ORDER BY COALESCE(er.role_ID, ur_pick.role_ID) ASC, tm_u.u_ID
");
            $stmt->execute([$team_ID, $team['cohort_ID']]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);


            // 計算進度（根據基本需求）
            $progressStmt = $conn->prepare("
                SELECT 
                    COUNT(DISTINCT r.req_ID) as total,
                    COUNT(DISTINCT CASE WHEN rp.rp_status = 1 AND rp.rp_team_ID = ? THEN rp.req_ID END) as completed
                FROM requirementdata r
                LEFT JOIN reprogressdata rp ON rp.req_ID = r.req_ID
                WHERE r.req_status = 1
                  AND (r.cohort_ID = ? OR r.cohort_ID IS NULL)
                  AND (r.group_ID = ? OR r.group_ID IS NULL)
            ");
            $progressStmt->execute([$team_ID, $team['cohort_ID'], $team['group_ID']]);
            $progressData = $progressStmt->fetch(PDO::FETCH_ASSOC);

            $total = (int)($progressData['total'] ?? 0);
            $completed = (int)($progressData['completed'] ?? 0);
            $progress = $total > 0 ? ($completed / $total) * 100 : 0;

            $team['members'] = $members;
            $team['progress'] = round($progress, 1);

            // 儀表板用：組員數、min/max、狀態
            $teachers = array_filter($members, fn($m) => (int)($m['role_ID'] ?? 0) === 4);
            $students = array_filter($members, fn($m) => (int)($m['role_ID'] ?? 0) === 6);
            $studentCount = count($students);
            $cid = (int)($team['cohort_ID'] ?? 0);
            $minMember = 2;
            $maxMember = 5;
            if ($cid > 0 && columnExists($conn, 'teammemberlimit', 'cohort_ID')) {
                $stmt = $conn->prepare("SELECT min_count, max_count FROM teammemberlimit WHERE cohort_ID = ? LIMIT 1");
                $stmt->execute([$cid]);
                $lim = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($lim) {
                    $minMember = (int)$lim['min_count'];
                    $maxMember = (int)$lim['max_count'];
                }
            }
            $status = 'normal';
            if ($studentCount < $minMember) $status = 'under';
            elseif ($studentCount > $maxMember) $status = 'over';
            $team['member_count'] = $studentCount;
            $team['min_member'] = $minMember;
            $team['max_member'] = $maxMember;
            $team['status'] = $status;

            json_ok(['success' => true, 'data' => $team]);
        } catch (Exception $e) {
            json_err('取得團隊詳情失敗：' . $e->getMessage());
        }
        break;

    // 取得指導老師團隊數量限制列表
    case 'get_teacher_team_limits':
        try {
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : null;

            $sql = "
                SELECT 
                    ttl.ttl_ID,
                    ttl.ttl_u_ID,
                    ttl.cohort_ID,
                    ttl.max_count,
                    u.u_name,
                    c.cohort_name
                FROM teacherteamlimit ttl
                JOIN userdata u ON u.u_ID = ttl.ttl_u_ID
                LEFT JOIN cohortdata c ON c.cohort_ID = ttl.cohort_ID
            ";

            $params = [];
            if ($cohort_ID) {
                $sql .= " WHERE ttl.cohort_ID = ?";
                $params[] = $cohort_ID;
            }

            $sql .= " ORDER BY ttl.cohort_ID DESC, u.u_name ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $limits = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 計算每個老師目前帶的團隊數量
            foreach ($limits as &$limit) {
                $teacherId = $limit['ttl_u_ID'];
                $cohortId = $limit['cohort_ID'];

                // 查詢該老師在該屆別中作為指導老師的活躍團隊數量
                $countSql = "
                    SELECT COUNT(DISTINCT t.team_ID) as current_count
                    FROM teammember tm
                    JOIN teamdata t ON t.team_ID = tm.team_ID
                    JOIN userrolesdata ur ON ur.{$userRoleUidField} = tm.{$teamUserField}
                    WHERE tm.{$teamUserField} = ?
                      AND t.cohort_ID = ?
                      AND t.team_status = 1
                      AND tm.tm_status = 1
                      AND ur.role_ID = 4
                      AND ur.user_role_status = 1
                ";

                $countStmt = $conn->prepare($countSql);
                $countStmt->execute([$teacherId, $cohortId]);
                $currentCount = (int)$countStmt->fetchColumn();

                $limit['current_count'] = $currentCount;

                // 計算狀態
                if ($currentCount >= $limit['max_count']) {
                    $limit['status'] = '已滿';
                    $limit['status_class'] = 'status-full';
                } else {
                    $limit['status'] = '可帶';
                    $limit['status_class'] = 'status-available';
                }
            }

            json_ok(['success' => true, 'data' => $limits]);
        } catch (Exception $e) {
            json_err('取得指導老師團隊數量限制失敗：' . $e->getMessage());
        }
        break;

    // 設定指導老師團隊數量限制
    case 'set_teacher_team_limit':
        try {
            $teacher_id = trim($p['teacher_id'] ?? '');
            $cohort_ID = isset($p['cohort_ID']) ? (int)$p['cohort_ID'] : 0;
            $max_count = isset($p['max_count']) ? (int)$p['max_count'] : 0;

            if (empty($teacher_id)) {
                json_err('請提供指導老師ID');
            }

            if ($cohort_ID <= 0) {
                json_err('請提供有效的屆別ID');
            }

            if ($max_count < 0) {
                json_err('團隊數量限制不能為負數');
            }

            // 檢查該老師是否是指導老師
            $stmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM userrolesdata 
                WHERE {$userRoleUidField} = ? AND role_ID = 4 AND user_role_status = 1
            ");
            $stmt->execute([$teacher_id]);
            if ($stmt->fetchColumn() == 0) {
                json_err('該用戶不是指導老師');
            }

            // 檢查是否已存在記錄
            $stmt = $conn->prepare("
                SELECT ttl_ID FROM teacherteamlimit 
                WHERE ttl_u_ID = ? AND cohort_ID = ?
            ");
            $stmt->execute([$teacher_id, $cohort_ID]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // 更新現有記錄
                $stmt = $conn->prepare("
                    UPDATE teacherteamlimit 
                    SET max_count = ?, ttl_updated_at = NOW()
                    WHERE ttl_ID = ?
                ");
                $stmt->execute([$max_count, $existing['ttl_ID']]);
            } else {
                // 新增記錄
                $stmt = $conn->prepare("
                    INSERT INTO teacherteamlimit (ttl_u_ID, cohort_ID, max_count, created_at, ttl_updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$teacher_id, $cohort_ID, $max_count]);
            }

            json_ok(['success' => true, 'message' => '設定成功']);
        } catch (Exception $e) {
            json_err('設定指導老師團隊數量限制失敗：' . $e->getMessage());
        }
        break;

    // 取得所有指導老師列表（用於設定限制）
    // 根據enrollmentdata判斷進行中的屆別和指導老師角色
    case 'get_all_teachers':
        try {
            // 查詢在進行中屆別（cohort_status = 1）中擔任指導老師（role_ID = 4）的使用者
            $sql = "
                SELECT DISTINCT
                    u.u_ID,
                    u.u_name,
                    GROUP_CONCAT(
                        DISTINCT CONCAT(e.cohort_ID, ':', c.cohort_name)
                        ORDER BY e.cohort_ID DESC
                        SEPARATOR '|'
                    ) as cohorts
                FROM userdata u
                JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID
                JOIN cohortdata c ON c.cohort_ID = e.cohort_ID
                WHERE e.role_ID = 4
                  AND e.enroll_status = 1
                  AND c.cohort_status = 1
                GROUP BY u.u_ID, u.u_name
                ORDER BY u.u_name ASC
            ";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 處理cohorts字串，轉換為陣列
            foreach ($teachers as &$teacher) {
                $cohorts = [];
                if (!empty($teacher['cohorts'])) {
                    $cohortPairs = explode('|', $teacher['cohorts']);
                    foreach ($cohortPairs as $pair) {
                        list($cohortId, $cohortName) = explode(':', $pair, 2);
                        $cohorts[] = [
                            'cohort_ID' => (int)$cohortId,
                            'cohort_name' => $cohortName
                        ];
                    }
                }
                $teacher['cohorts'] = $cohorts;
            }

            json_ok(['success' => true, 'data' => $teachers]);
        } catch (Exception $e) {
            json_err('取得指導老師列表失敗：' . $e->getMessage());
        }
        break;

    // 添加團隊成員
    case 'add_team_member':
        try {
            $team_ID = isset($p['team_ID']) ? (int)$p['team_ID'] : 0;
            $user_ID = trim($p['user_ID'] ?? '');

            if ($team_ID <= 0) {
                json_err('請提供有效的團隊ID');
            }

            if (empty($user_ID)) {
                json_err('請提供使用者ID');
            }

            $conn->beginTransaction();

            // 檢查團隊是否存在
            $stmt = $conn->prepare("SELECT team_ID, cohort_ID FROM teamdata WHERE team_ID = ? AND team_status = 1");
            $stmt->execute([$team_ID]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$team) {
                $conn->rollBack();
                json_err('團隊不存在或已停用');
            }
            // 檢查屆別是否已結案（status=3），已結案則不允許修改
            if (!empty($team['cohort_ID'])) {
                $csStmt = $conn->prepare("SELECT cohort_status FROM cohortdata WHERE cohort_ID = ?");
                $csStmt->execute([$team['cohort_ID']]);
                $csRow = $csStmt->fetch(PDO::FETCH_ASSOC);
                if ($csRow && (int)($csRow['cohort_status'] ?? 1) === 3) {
                    $conn->rollBack();
                    json_err('此屆別已結案，無法修改資料');
                }
            }

            // 檢查使用者是否已在團隊中（包括已移除的）
            $stmt = $conn->prepare("SELECT COUNT(*) FROM teammember WHERE team_ID = ? AND {$teamUserField} = ?");
            $stmt->execute([$team_ID, $user_ID]);
            if ($stmt->fetchColumn() > 0) {
                // 如果已存在但狀態為0，則恢復
                $stmt = $conn->prepare("
                    UPDATE teammember 
                    SET tm_status = 1, tm_updated_d = NOW()
                    WHERE team_ID = ? AND {$teamUserField} = ? AND tm_status = 0
                ");
                $stmt->execute([$team_ID, $user_ID]);
                if ($stmt->rowCount() > 0) {
                    insert_teamchangelog($conn, (int)$team['cohort_ID'], $team_ID, 'MEMBER_ADD', null, null, null, null, $user_ID, $u_ID);
                    $conn->commit();
                    json_ok(['success' => true, 'message' => '成員已成功加入']);
                } else {
                    $conn->rollBack();
                    json_err('該使用者已在團隊中');
                }
                return;
            }

            // 檢查使用者是否為該屆別的學生（可選驗證）
            // 這裡可以添加更多業務邏輯驗證

            // 添加成員
            $stmt = $conn->prepare("
                INSERT INTO teammember (team_ID, {$teamUserField}, tm_status, tm_updated_d)
                VALUES (?, ?, 1, NOW())
            ");
            $stmt->execute([$team_ID, $user_ID]);

            if ($stmt->rowCount() === 0) {
                $conn->rollBack();
                json_err('添加成員失敗');
            }

            insert_teamchangelog($conn, (int)$team['cohort_ID'], $team_ID, 'MEMBER_ADD', null, null, null, null, $user_ID, $u_ID);
            $conn->commit();
            json_ok(['success' => true, 'message' => '成員已成功加入']);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('添加成員失敗：' . $e->getMessage());
        }
        break;

    // 移除團隊成員
    case 'remove_team_member':
        try {
            $team_ID = isset($p['team_ID']) ? (int)$p['team_ID'] : 0;
            $user_ID = trim($p['user_ID'] ?? '');

            if ($team_ID <= 0) {
                json_err('請提供有效的團隊ID');
            }

            if (empty($user_ID)) {
                json_err('請提供使用者ID');
            }

            $conn->beginTransaction();

            // 檢查團隊所屬屆別是否已結案
            $teamStmt = $conn->prepare("SELECT t.cohort_ID, c.cohort_status FROM teamdata t LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID WHERE t.team_ID = ?");
            $teamStmt->execute([$team_ID]);
            $teamRow = $teamStmt->fetch(PDO::FETCH_ASSOC);
            if ($teamRow && !empty($teamRow['cohort_ID']) && (int)($teamRow['cohort_status'] ?? 1) === 3) {
                $conn->rollBack();
                json_err('此屆別已結案，無法修改資料');
            }

            // 檢查成員是否存在
            $stmt = $conn->prepare("SELECT COUNT(*) FROM teammember WHERE team_ID = ? AND {$teamUserField} = ? AND tm_status = 1");
            $stmt->execute([$team_ID, $user_ID]);
            if ($stmt->fetchColumn() == 0) {
                $conn->rollBack();
                json_err('該使用者不在團隊中');
            }

            // 檢查是否為指導老師
            $stmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM userrolesdata 
                WHERE {$userRoleUidField} = ? AND role_ID = 4 AND user_role_status = 1
            ");
            $stmt->execute([$user_ID]);
            $isTeacher = $stmt->fetchColumn() > 0;

            // 如果是指導老師，檢查是否為團隊的唯一指導老師
            if ($isTeacher) {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) 
                    FROM teammember tm
                    JOIN userrolesdata ur ON ur.{$userRoleUidField} = tm.{$teamUserField}
                    WHERE tm.team_ID = ? 
                      AND ur.role_ID = 4 
                      AND ur.user_role_status = 1
                      AND tm.tm_status = 1
                ");
                $stmt->execute([$team_ID]);
                $teacherCount = $stmt->fetchColumn();

                if ($teacherCount <= 1) {
                    $conn->rollBack();
                    json_err('無法移除團隊的唯一指導老師');
                }
            }

            // 移除成員（設置狀態為 0）
            $stmt = $conn->prepare("
                UPDATE teammember 
                SET tm_status = 0, tm_updated_d = NOW()
                WHERE team_ID = ? AND {$teamUserField} = ? AND tm_status = 1
            ");
            $stmt->execute([$team_ID, $user_ID]);

            if ($stmt->rowCount() === 0) {
                $conn->rollBack();
                json_err('移除失敗：找不到要移除的成員');
            }

            $teamStmt = $conn->prepare("SELECT cohort_ID FROM teamdata WHERE team_ID = ? LIMIT 1");
            $teamStmt->execute([$team_ID]);
            $teamRow = $teamStmt->fetch(PDO::FETCH_ASSOC);
            $cohort_ID = (int)($teamRow['cohort_ID'] ?? 0);
            insert_teamchangelog($conn, $cohort_ID, $team_ID, 'MEMBER_REMOVE', null, null, null, null, $user_ID, $u_ID);
            $conn->commit();
            json_ok(['success' => true, 'message' => '成員已成功移除']);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('移除成員失敗：' . $e->getMessage());
        }
        break;

    // 刪除團隊（軟刪除：team_status = 0）
    case 'delete_team':
        try {
            $team_ID = isset($p['team_ID']) ? (int)($p['team_ID'] ?? 0) : (int)($_GET['team_ID'] ?? 0);

            if ($team_ID <= 0) {
                json_err('請提供有效的團隊ID');
            }

            $stmt = $conn->prepare("SELECT team_ID, cohort_ID FROM teamdata WHERE team_ID = ? AND team_status = 1");
            $stmt->execute([$team_ID]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$team) {
                json_err('找不到該團隊或團隊已刪除');
            }

            // 檢查屆別是否已結案
            $cohortStmt = $conn->prepare("SELECT cohort_status FROM cohortdata WHERE cohort_ID = ?");
            $cohortStmt->execute([$team['cohort_ID']]);
            $cohort = $cohortStmt->fetch(PDO::FETCH_ASSOC);
            if ($cohort && (int)($cohort['cohort_status'] ?? 1) === 3) {
                json_err('此屆別已結案，無法刪除團隊');
            }

            $conn->beginTransaction();
            try {
                $stmt = $conn->prepare("UPDATE teamdata SET team_status = 0, team_update_d = NOW() WHERE team_ID = ?");
                $stmt->execute([$team_ID]);
                $conn->commit();
                json_ok(['success' => true, 'message' => '團隊已刪除']);
            } catch (Throwable $e) {
                $conn->rollBack();
                throw $e;
            }
        } catch (Throwable $e) {
            json_err('刪除團隊失敗：' . $e->getMessage());
        }
        break;

    // 取得可加入團隊的學生列表
    case 'get_available_students':
        try {
            $team_ID = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : 0;
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : null;

            if ($team_ID <= 0) {
                json_err('請提供有效的團隊ID');
            }

            // 取得團隊資訊
            $stmt = $conn->prepare("SELECT cohort_ID FROM teamdata WHERE team_ID = ?");
            $stmt->execute([$team_ID]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$team) {
                json_err('團隊不存在');
            }

            $targetCohort = $cohort_ID ?? $team['cohort_ID'];

            // 取得該屆別中未加入任何團隊的學生
            $sql = "
                SELECT DISTINCT
                    u.u_ID,
                    u.u_name,
                    u.u_img,
                    e.class_ID,
                    c.c_name as class_name,
                    e.enroll_grade
                FROM userdata u
                JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID
                JOIN userrolesdata ur ON ur.{$userRoleUidField} = u.u_ID
                LEFT JOIN classdata c ON c.c_ID = e.class_ID
                WHERE ur.role_ID = 6
                  AND ur.user_role_status = 1
                  AND e.cohort_ID = ?
                  AND e.enroll_status = 1
                  AND u.u_status = 1
                  AND u.u_ID NOT IN (
                      SELECT DISTINCT tm.{$teamUserField}
                      FROM teammember tm
                      JOIN teamdata t ON t.team_ID = tm.team_ID
                      WHERE tm.tm_status = 1
                        AND t.team_status = 1
                        AND t.cohort_ID = ?
                  )
                ORDER BY u.u_name ASC
            ";

            $stmt = $conn->prepare($sql);
            $stmt->execute([$targetCohort, $targetCohort]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_ok(['success' => true, 'data' => $students]);
        } catch (Exception $e) {
            json_err('取得可加入學生列表失敗：' . $e->getMessage());
        }
        break;

    // 搜尋學生並返回其組別資訊
    case 'search_student_team':
        try {
            $searchQuery = isset($_GET['query']) ? trim($_GET['query']) : '';

            if (empty($searchQuery)) {
                json_err('請輸入搜尋關鍵字');
            }

            // 搜尋學生（根據學號或姓名）
            $sql = "
                SELECT DISTINCT
                    u.u_ID,
                    u.u_name,
                    tm.team_ID,
                    t.team_project_name,
                    t.group_ID,
                    g.group_name,
                    t.cohort_ID,
                    c.cohort_name
                FROM userdata u
                INNER JOIN teammember tm ON tm.{$teamUserField} = u.u_ID AND tm.tm_status = 1
                INNER JOIN teamdata t ON t.team_ID = tm.team_ID AND t.team_status = 1
                INNER JOIN groupdata g ON g.group_ID = t.group_ID
                INNER JOIN cohortdata c ON c.cohort_ID = t.cohort_ID
                WHERE (u.u_ID LIKE ? OR u.u_name LIKE ?)
                  AND u.u_status = 1
                ORDER BY t.cohort_ID DESC, g.group_ID ASC, t.team_ID ASC
                LIMIT 50
            ";

            $searchPattern = '%' . $searchQuery . '%';
            $stmt = $conn->prepare($sql);
            $stmt->execute([$searchPattern, $searchPattern]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($results)) {
                json_ok([
                    'success' => true,
                    'found' => false,
                    'message' => '找不到符合條件的學生',
                    'data' => []
                ]);
            }

            // 整理結果，按組別分組
            $groupedResults = [];
            foreach ($results as $row) {
                $key = $row['group_ID'] . '_' . $row['cohort_ID'];
                if (!isset($groupedResults[$key])) {
                    $groupedResults[$key] = [
                        'group_ID' => $row['group_ID'],
                        'group_name' => $row['group_name'],
                        'cohort_ID' => $row['cohort_ID'],
                        'cohort_name' => $row['cohort_name'],
                        'teams' => []
                    ];
                }

                // 檢查團隊是否已存在
                $teamExists = false;
                foreach ($groupedResults[$key]['teams'] as &$team) {
                    if ($team['team_ID'] == $row['team_ID']) {
                        // 檢查學生是否已在團隊中
                        $studentExists = false;
                        foreach ($team['students'] as &$student) {
                            if ($student['u_ID'] == $row['u_ID']) {
                                $studentExists = true;
                                break;
                            }
                        }
                        if (!$studentExists) {
                            $team['students'][] = [
                                'u_ID' => $row['u_ID'],
                                'u_name' => $row['u_name']
                            ];
                        }
                        $teamExists = true;
                        break;
                    }
                }

                if (!$teamExists) {
                    $groupedResults[$key]['teams'][] = [
                        'team_ID' => $row['team_ID'],
                        'team_project_name' => $row['team_project_name'],
                        'students' => [
                            [
                                'u_ID' => $row['u_ID'],
                                'u_name' => $row['u_name']
                            ]
                        ]
                    ];
                }
            }

            json_ok([
                'success' => true,
                'found' => true,
                'data' => array_values($groupedResults)
            ]);
        } catch (Exception $e) {
            json_err('搜尋失敗：' . $e->getMessage());
        }
        break;

    default:
        json_err('未知的操作');
}
