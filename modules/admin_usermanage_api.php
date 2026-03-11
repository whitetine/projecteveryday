<?php

/**
 * 帳號管理 API 模組
 * 處理用戶管理的所有後端邏輯
 */

// 暫時開啟錯誤顯示（除錯用）
ini_set('display_errors', '0'); // 或直接註解掉
error_reporting(E_ALL);


global $conn;
// 確保輸出緩衝正確處理（與 api.php 的設置一致）
if (ob_get_level()) {
    ob_clean();
}
$do = $_GET['do'] ?? '';
$role_ID = $_SESSION['role_ID'] ?? null;

// 確保 $conn 存在
if (!isset($conn) || !$conn) {
    json_err('資料庫連線失敗');
}

// 權限檢查
if (!in_array($role_ID, [1, 2])) {
    json_err('您沒有權限訪問此頁面');
}

/**
 * 將資料查詢集中在單一函式，方便 AJAX 使用與前後端分離
 */
function loadUserManageData(PDO $conn, array $filters): array
{
    $search = trim($filters['search'] ?? '');
    $role_filter = $filters['role_filter'] ?? '';
    $status_filter = $filters['status_filter'] ?? '';
    $cohort_filter = $filters['cohort_filter'] ?? '';
    $class_filter = $filters['class_filter'] ?? '';

    // 檢查欄位名稱（兼容不同版本的資料表結構）
    $colsTm = $conn->query("SHOW COLUMNS FROM teammember")->fetchAll(PDO::FETCH_COLUMN);
    $teamUserField = in_array('team_u_ID', $colsTm) ? 'team_u_ID' : 'u_ID';

    // 檢查 teacherteamlimit 表是否存在（若不存在則不查詢團隊限制，避免 SQL 錯誤）
    $hasTeacherTeamLimit = false;
    try {
        $conn->query("SELECT 1 FROM teacherteamlimit LIMIT 1");
        $hasTeacherTeamLimit = true;
    } catch (Exception $e) {
        // 表不存在，使用簡化查詢
    }

    $teamLimitSelect = $hasTeacherTeamLimit
        ? "CASE 
                WHEN EXISTS (
                    SELECT 1 FROM teacherteamlimit ttl 
                    WHERE ttl.ttl_u_ID = u.u_ID 
                    AND EXISTS (
                        SELECT 1 FROM cohortdata cd 
                        WHERE cd.cohort_ID = ttl.cohort_ID 
                        AND cd.cohort_status = 1
                    )
                ) THEN 1 
                ELSE 0 
            END AS has_team_limit,
            CASE 
                WHEN EXISTS (
                    SELECT 1 FROM teacherteamlimit ttl 
                    INNER JOIN cohortdata cd ON cd.cohort_ID = ttl.cohort_ID AND cd.cohort_status = 1
                    WHERE ttl.ttl_u_ID = u.u_ID
                    AND (
                        SELECT COUNT(DISTINCT tm.team_ID)
                        FROM teammember tm
                        INNER JOIN teamdata t ON t.team_ID = tm.team_ID
                        WHERE tm.{$teamUserField} = u.u_ID
                        AND t.cohort_ID = ttl.cohort_ID
                        AND tm.tm_status = 1
                        AND t.team_status = 1
                    ) >= ttl.max_count
                ) THEN 1 
                ELSE 0 
            END AS team_limit_reached"
        : "0 AS has_team_limit, 0 AS team_limit_reached";

    $sql = "SELECT 
            u.u_ID,
            u.u_name,
            u.u_gmail,
            u.u_profile,
            u.u_img,
            u.u_status,
            MAX(r.role_ID)   AS role_ID,
            MAX(r.role_name) AS role_name,
            s.status_ID,
            s.status_name,
            MAX(c.c_ID)      AS c_ID,
            MAX(c.c_name)    AS class_name,
            MAX(e.cohort_ID) AS cohort_ID,
            MAX(ch.cohort_name) AS cohort_name,
            MAX(e.enroll_grade) AS enroll_grade,
            {$teamLimitSelect}
        FROM userdata u
        LEFT JOIN statusdata s 
              ON s.status_ID = u.u_status
        LEFT JOIN userrolesdata ur 
              ON u.u_ID = ur.ur_u_ID 
             AND ur.user_role_status = 1
        LEFT JOIN roledata r 
              ON ur.role_ID = r.role_ID
        LEFT JOIN enrollmentdata e 
              ON e.enroll_u_ID = u.u_ID 
             AND e.enroll_status = 1
        LEFT JOIN classdata c 
              ON c.c_ID = e.class_ID
        LEFT JOIN cohortdata ch 
              ON ch.cohort_ID = e.cohort_ID
        WHERE 1=1
          AND NOT EXISTS (
              SELECT 1
              FROM userrolesdata ur_sys
              WHERE ur_sys.ur_u_ID = u.u_ID
                AND ur_sys.user_role_status = 1
                AND ur_sys.role_ID = 0
          )";


    $params = [];

    if ($search) {
        $sql .= " AND (u.u_ID LIKE ? OR u.u_name LIKE ? OR u.u_gmail LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    if ($role_filter) {
        $sql .= " AND EXISTS (
            SELECT 1 
            FROM userrolesdata ur 
            WHERE ur.ur_u_ID = u.u_ID 
            AND ur.role_ID = ? 
            AND ur.user_role_status = 1
        )";
        $params[] = $role_filter;
    }

    if ($status_filter !== '') {
        $sql .= " AND u.u_status = ?";
        $params[] = $status_filter;
    }

    if ($cohort_filter) {
        $sql .= " AND EXISTS (
            SELECT 1 
            FROM enrollmentdata e 
            WHERE e.enroll_u_ID = u.u_ID 
            AND e.cohort_ID = ? 
            AND e.enroll_status = 1
        )";
        $params[] = $cohort_filter;
    }

    if ($class_filter !== '') {
        $sql .= " AND EXISTS (
            SELECT 1 
            FROM enrollmentdata e 
            WHERE e.enroll_u_ID = u.u_ID 
            AND e.class_ID = ? 
            AND e.enroll_status = 1
        )";
        $params[] = $class_filter;
    }

    $sql .= " GROUP BY u.u_ID, u.u_name, u.u_gmail, u.u_profile, u.u_img, u.u_status, s.status_ID, s.status_name";
    $sql .= " ORDER BY u.u_ID ASC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [
            'ok' => false,
            'msg' => $e->getMessage(),
            'sql' => $sql,
        ];
    }

    // 老師名單（不受篩選影響，供指導老師負載表格使用）
    $teachersForLoad = [];
    try {
        $teacherSql = "SELECT u.u_ID, u.u_name
            FROM userdata u
            INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID AND ur.role_ID = 4 AND ur.user_role_status = 1
            WHERE NOT EXISTS (
                SELECT 1 FROM userrolesdata ur_sys
                WHERE ur_sys.ur_u_ID = u.u_ID AND ur_sys.user_role_status = 1 AND ur_sys.role_ID = 0
            )
            ORDER BY u.u_name, u.u_ID";
        $teachersForLoad = $conn->query($teacherSql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // 忽略
    }

    try {
        // 角色列表：排除 role_ID = 0
        $roles = $conn->query("
            SELECT role_ID, role_name
            FROM roledata
            WHERE role_status = 1
              AND role_ID <> 0
            ORDER BY role_ID
        ")->fetchAll(PDO::FETCH_ASSOC);

        // 依登入者角色過濾（科辦只能看到 3/4/6）
        $loginRole = $_SESSION['role_ID'] ?? 0;
        if ($loginRole == 2) {
            $roles = array_values(array_filter($roles, function ($r) {
                return in_array((int)$r['role_ID'], [3, 4, 6]);
            }));
        }
        $statuses = $conn->query("SELECT * FROM statusdata")->fetchAll(PDO::FETCH_ASSOC);
        $cohorts = $conn->query("SELECT * FROM cohortdata ORDER BY cohort_ID DESC")->fetchAll(PDO::FETCH_ASSOC);
        $classes = $conn->query("SELECT * FROM classdata ORDER BY c_ID")->fetchAll(PDO::FETCH_ASSOC);

        $classStatsSql = "SELECT 
            c.c_ID,
            c.c_name,
            ch.cohort_ID,
            ch.cohort_name,
            e.enroll_grade,
            COUNT(DISTINCT CASE WHEN ur.role_ID = 6 THEN e.enroll_u_ID END) as student_count
        FROM classdata c
        LEFT JOIN enrollmentdata e ON e.class_ID = c.c_ID AND e.enroll_status = 1
        LEFT JOIN cohortdata ch ON ch.cohort_ID = e.cohort_ID
        LEFT JOIN userrolesdata ur ON e.enroll_u_ID = ur.ur_u_ID AND ur.user_role_status = 1 AND ur.role_ID = 6
        WHERE ur.role_ID = 6 OR e.enroll_u_ID IS NULL
        GROUP BY c.c_ID, c.c_name, ch.cohort_ID, ch.cohort_name, e.enroll_grade
        HAVING COUNT(DISTINCT CASE WHEN ur.role_ID = 6 THEN e.enroll_u_ID END) > 0 OR c.c_ID IS NOT NULL
        ORDER BY ch.cohort_ID DESC, c.c_ID, e.enroll_grade";
        $classStats = $conn->query($classStatsSql)->fetchAll(PDO::FETCH_ASSOC);

        $totalAllUsers = $conn->query("SELECT COUNT(*) as total FROM userdata u   WHERE NOT EXISTS (  SELECT 1 FROM userrolesdata ur_sys  WHERE ur_sys.ur_u_ID = u.u_ID AND ur_sys.user_role_status = 1 AND ur_sys.role_ID = 0)")->fetch(PDO::FETCH_ASSOC)['total'];
        $statusStatsSql = "SELECT 
            u.u_status,
        s.status_name,
        COUNT(*) AS count
    FROM userdata u
    LEFT JOIN statusdata s ON s.status_ID = u.u_status
    WHERE NOT EXISTS (
        SELECT 1
        FROM userrolesdata ur_sys
        WHERE ur_sys.ur_u_ID = u.u_ID
          AND ur_sys.user_role_status = 1
          AND ur_sys.role_ID = 0
    )
    GROUP BY u.u_status, s.status_name
    ORDER BY u.u_status";
        $statusStats = $conn->query($statusStatsSql)->fetchAll(PDO::FETCH_ASSOC);

        $statusCohortStatsSql = "SELECT 
            u.u_status,
            ch.cohort_ID,
            ch.cohort_name,
            COUNT(DISTINCT u.u_ID) as count
        FROM userdata u
        LEFT JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID AND e.enroll_status = 1
        LEFT JOIN cohortdata ch ON ch.cohort_ID = e.cohort_ID
        LEFT JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID AND ur.user_role_status = 1 AND ur.role_ID = 6
        WHERE ur.role_ID = 6
        GROUP BY u.u_status, ch.cohort_ID, ch.cohort_name
        HAVING u.u_status IS NOT NULL AND ch.cohort_ID IS NOT NULL
        ORDER BY u.u_status, ch.cohort_ID DESC";
        $statusCohortStats = $conn->query($statusCohortStatsSql)->fetchAll(PDO::FETCH_ASSOC);

        $cohortTotalSql = "SELECT 
            ch.cohort_ID,
            ch.cohort_name,
            COUNT(DISTINCT u.u_ID) as total_count
        FROM cohortdata ch
        LEFT JOIN enrollmentdata e ON e.cohort_ID = ch.cohort_ID AND e.enroll_status = 1
        LEFT JOIN userdata u ON u.u_ID = e.enroll_u_ID
        LEFT JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID AND ur.user_role_status = 1 AND ur.role_ID = 6
        WHERE ur.role_ID = 6
        GROUP BY ch.cohort_ID, ch.cohort_name
        ORDER BY ch.cohort_ID DESC";
        $cohortTotals = $conn->query($cohortTotalSql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [
            'ok' => false,
            'msg' => '查詢統計資料時發生錯誤: ' . $e->getMessage(),
        ];
    }

    $cohortTotalMap = [];
    foreach ($cohortTotals as $total) {
        $cohortTotalMap[$total['cohort_ID']] = $total['total_count'];
    }

    // 老師 × 屆別帶組數（供指導老師負載表格使用）
    $teacherLoadByCohort = [];
    try {
        $colsTm = $conn->query("SHOW COLUMNS FROM teammember")->fetchAll(PDO::FETCH_COLUMN);
        $teamUserField = in_array('team_u_ID', $colsTm) ? 'team_u_ID' : 'u_ID';

        $teacherCountSql = "SELECT 
            tm.{$teamUserField} AS u_ID,
            t.cohort_ID,
            COUNT(DISTINCT tm.team_ID) AS team_count
        FROM teammember tm
        INNER JOIN teamdata t ON tm.team_ID = t.team_ID
        INNER JOIN userrolesdata ur ON ur.ur_u_ID = tm.{$teamUserField} AND ur.role_ID = 4 AND ur.user_role_status = 1
        WHERE (tm.tm_status = 1 OR tm.tm_status IS NULL)
          AND (t.team_status = 1 OR t.team_status IS NULL)
        GROUP BY tm.{$teamUserField}, t.cohort_ID";
        $teacherCountRows = $conn->query($teacherCountSql)->fetchAll(PDO::FETCH_ASSOC);

        $limitMap = [];
        try {
            $limitRows = $conn->query("
                SELECT ttl_u_ID, cohort_ID, max_count 
                FROM teacherteamlimit ttl
                INNER JOIN cohortdata cd ON cd.cohort_ID = ttl.cohort_ID AND cd.cohort_status = 1
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($limitRows as $r) {
                $limitMap[$r['ttl_u_ID'] . '_' . $r['cohort_ID']] = (int)$r['max_count'];
            }
        } catch (Exception $e) {
            // teacherteamlimit 可能不存在
        }

        $defaultMax = 6;
        foreach ($teacherCountRows as $row) {
            $uid = $row['u_ID'];
            $cid = $row['cohort_ID'];
            $count = (int)$row['team_count'];
            $max = $limitMap[$uid . '_' . $cid] ?? $defaultMax;
            if (!isset($teacherLoadByCohort[$uid])) {
                $teacherLoadByCohort[$uid] = [];
            }
            $teacherLoadByCohort[$uid][$cid] = [
                'count' => $count,
                'max' => $max,
                'full' => $count >= $max,
            ];
        }
    } catch (Exception $e) {
        // 忽略錯誤，teacherLoadByCohort 為空
    }

    // 指導老師在 enrollmentdata 的屆別開放狀態（有記錄 = 開放，無記錄 = 未開放）
    $teacherEnrollmentByCohort = [];
    try {
        $enrollSql = "SELECT e.enroll_u_ID, e.cohort_ID 
            FROM enrollmentdata e
            INNER JOIN userrolesdata ur ON e.enroll_u_ID = ur.ur_u_ID AND ur.role_ID = 4 AND ur.user_role_status = 1
            WHERE e.enroll_status = 1";
        $enrollRows = $conn->query($enrollSql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($enrollRows as $r) {
            $uid = $r['enroll_u_ID'];
            $cid = $r['cohort_ID'];
            if (!isset($teacherEnrollmentByCohort[$uid])) {
                $teacherEnrollmentByCohort[$uid] = [];
            }
            $teacherEnrollmentByCohort[$uid][$cid] = true;
        }
    } catch (Exception $e) {
        // 忽略
    }

    $statusCohortData = [];
    foreach ($statusCohortStats as $stat) {
        $statusId = $stat['u_status'];
        if (!isset($statusCohortData[$statusId])) {
            $statusCohortData[$statusId] = [];
        }
        $stat['cohort_total'] = isset($cohortTotalMap[$stat['cohort_ID']]) ? $cohortTotalMap[$stat['cohort_ID']] : 0;
        $statusCohortData[$statusId][] = $stat;
    }

    return [
        'ok' => true,
        'users' => $users,
        'roles' => $roles,
        'statuses' => $statuses,
        'cohorts' => $cohorts,
        'classes' => $classes,
        'classStats' => $classStats,
        'statusStats' => $statusStats,
        'statusCohortData' => $statusCohortData,
        'teacherLoadByCohort' => $teacherLoadByCohort,
        'teacherEnrollmentByCohort' => $teacherEnrollmentByCohort,
        'teachersForLoad' => $teachersForLoad,
        'totalAllUsers' => $totalAllUsers,
        'filters' => [
            'search' => $search,
            'role_filter' => $role_filter,
            'status_filter' => $status_filter,
            'cohort_filter' => $cohort_filter,
            'class_filter' => $class_filter,
        ],
    ];
}

// 註冊錯誤處理器，確保所有錯誤都能返回 JSON
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        error_log("PHP Error [{$severity}]: {$message} in {$file} on line {$line}");
        // 如果是致命錯誤，嘗試返回 JSON
        if ($severity === E_ERROR || $severity === E_PARSE || $severity === E_CORE_ERROR || $severity === E_COMPILE_ERROR) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
            }
            echo json_encode([
                'ok' => false,
                'code' => 'FATAL_ERROR',
                'msg' => '伺服器內部錯誤: ' . $message . " (檔案: {$file}, 行號: {$line})"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    return false; // 繼續執行標準錯誤處理
});

// 註冊關閉處理器，捕獲致命錯誤
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_CORE_WARNING])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'ok' => false,
            'code' => 'FATAL_ERROR',
            'msg' => '伺服器內部錯誤: ' . $error['message'] . " (檔案: {$error['file']}, 行號: {$error['line']})"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

// 處理不同的 API 請求
try {
    switch ($do) {
        case 'get_user_manage_data':
            $filters = [
                'search' => $_GET['search'] ?? '',
                'role_filter' => $_GET['role_filter'] ?? '',
                'status_filter' => $_GET['status_filter'] ?? '',
                'cohort_filter' => $_GET['cohort_filter'] ?? '',
                'class_filter' => $_GET['class_filter'] ?? '',
            ];

            $data = loadUserManageData($conn, $filters);

            if (!$data['ok']) {
                json_err($data['msg'] ?? '查詢錯誤');
            }

            // 移除 ok 鍵，因為 json_ok 會自動添加
            unset($data['ok']);
            json_ok($data);
            break;

        default:
            json_err('未知的操作: ' . $do);
    }
} catch (Exception $e) {
    // 捕獲所有未預期的錯誤，確保返回 JSON
    $errorMsg = $e->getMessage();
    $errorFile = $e->getFile();
    $errorLine = $e->getLine();
    error_log("admin_usermanage_api.php 錯誤: {$errorMsg} in {$errorFile} on line {$errorLine}");
    error_log("Stack trace: " . $e->getTraceAsString());
    json_err('伺服器內部錯誤: ' . $errorMsg . " (檔案: {$errorFile}, 行號: {$errorLine})");
} catch (Error $e) {
    // 捕獲 PHP 7+ 的 Error（如 TypeError, ParseError 等）
    $errorMsg = $e->getMessage();
    $errorFile = $e->getFile();
    $errorLine = $e->getLine();
    error_log("admin_usermanage_api.php 致命錯誤: {$errorMsg} in {$errorFile} on line {$errorLine}");
    error_log("Stack trace: " . $e->getTraceAsString());
    json_err('伺服器內部錯誤: ' . $errorMsg . " (檔案: {$errorFile}, 行號: {$errorLine})");
}
