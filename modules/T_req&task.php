<?php
session_start();
require '../includes/pdo.php';
$p = $_POST;

switch ($_GET["do"]) {

    // 取得該團隊的任務
    case "get_task":
        echo json_encode(fetchAll(query("
            SELECT 
                td.*,
                creator.u_name AS creator_name,
                finisher.u_name AS done_name
            FROM taskdata td
            LEFT JOIN userdata creator ON td.task_u_ID = creator.u_ID
            LEFT JOIN userdata finisher ON td.task_done_u_ID = finisher.u_ID
            WHERE td.task_team_ID = {$p["team_ID"]}
            ORDER BY td.task_status, td.task_priority DESC, td.task_end_d DESC
        ")));
        break;

    // 搜尋當前登入老師的所有組員(包含老師)
    case "get_now_teammember":
        $teamRows = fetchAll(query("
            SELECT td.team_ID
            FROM teammember tm
            JOIN teamdata td ON tm.team_ID = td.team_ID
            WHERE tm.team_u_ID = '{$_SESSION["u_ID"]}'
              AND tm.tm_status = 1
              AND td.cohort_ID = {$_SESSION["cohort_ID"]}
              AND td.team_status = 1
        "));
        $team_IDs = array_unique(array_column($teamRows, "team_ID"));

        if (!$team_IDs) {
            echo json_encode([
                "team_IDs" => [],
                "team_member" => []
            ]);
            exit;
        }

        $ids_sql = implode(",", array_map('intval', $team_IDs));

        $team_IDmembers = fetchAll(query("
            SELECT tm.team_ID, td.team_project_name
            FROM teammember tm
            JOIN teamdata td ON td.team_ID = tm.team_ID
            WHERE td.team_status = 1
              AND tm.team_ID IN ({$ids_sql})
              AND tm.tm_status = 1
            GROUP BY tm.team_ID
        "));

        $team_member = fetchAll(query("
            SELECT 
                tm.team_ID,
                tm.team_u_ID,
                ud.u_name,
                ur.role_ID,
                td.team_project_name
            FROM teammember tm
            JOIN userdata ud ON tm.team_u_ID = ud.u_ID
            JOIN teamdata td ON td.team_ID = tm.team_ID
            JOIN userrolesdata ur ON ur.ur_u_ID = ud.u_ID
            WHERE td.team_status = 1
              AND tm.team_ID IN ({$ids_sql})
              AND tm.tm_status = 1
        "));

        echo json_encode([
            "team_IDs" => $team_IDmembers,
            "team_member" => $team_member
        ]);
        break;

    // 取得所有基本需求
    case "get_requirement":
        $req = fetchAll(query("
            SELECT 
                r.*,
                u.u_name,
                td.type_value,
                CASE WHEN rp.rp_status IS NULL THEN 0 ELSE rp.rp_status END AS status
            FROM requirementdata AS r
            LEFT JOIN (
                SELECT rp1.*
                FROM reprogressdata AS rp1
                INNER JOIN (
                    SELECT req_ID, rp_team_ID, MAX(rp_ID) AS max_rp_ID
                    FROM reprogressdata
                    WHERE rp_team_ID = '{$p["now_team_ID"]}'
                    GROUP BY req_ID, rp_team_ID
                ) AS t
                    ON t.req_ID = rp1.req_ID
                   AND t.rp_team_ID = rp1.rp_team_ID
                   AND t.max_rp_ID = rp1.rp_ID
            ) AS rp ON rp.req_ID = r.req_ID
            LEFT JOIN userdata AS u ON u.u_ID = rp.rp_u_ID
            LEFT JOIN typedata AS td ON td.type_ID = r.type_ID
            WHERE r.req_status = 1
              AND r.group_ID = '{$p["ID"]}'
              AND r.cohort_ID = '{$p["cohort"]}'
            ORDER BY status
        "));

        $rp = fetchAll(query("
            SELECT rd.*, ud.u_name
            FROM reprogressdata rd
            JOIN userdata ud ON rd.rp_u_ID = ud.u_ID
            WHERE rd.rp_team_ID = {$p["now_team_ID"]}
        "));

        echo json_encode([
            "req" => $req,
            "rp" => $rp
        ]);
        break;

    // 取得當下登入老師的類組ID、類組名稱、團隊名稱
    case "get_now_group":
        echo json_encode(fetch(query("
            SELECT 
                gd.group_ID,
                gd.group_name,
                td.team_project_name,
                td.cohort_ID
            FROM teammember tm
            JOIN teamdata td ON tm.team_ID = td.team_ID
            JOIN groupdata gd ON td.group_ID = gd.group_ID
            WHERE tm.team_u_ID = '{$_SESSION["u_ID"]}'
              AND td.team_status = 1
            LIMIT 1
        ")));
        break;

    // 新增待辦事項
    case "new_task_submit":
        function toNull($v)
        {
            return ($v === "" || $v === null) ? "NULL" : "'" . addslashes($v) . "'";
        }

        $title   = toNull($p["form"]["title"] ?? null);
        $desc    = toNull($p["form"]["desc"] ?? null);
        $start_d = toNull($p["form"]["start_d"] ?? null);
        $end_d   = toNull($p["form"]["end_d"] ?? null);

        $req_ID = !empty($p["form"]["req_ID"]) ? intval($p["form"]["req_ID"]) : "NULL";
        $selectTask = toNull($p["form"]["who_task"] ?? null);
        $team = intval($p["now_team_ID"]);
        $status = !empty($p["form"]["who_task"]) ? 1 : 0;
        $priority = intval($p["form"]["priority"] ?? 1);
        $cohort_ID = intval($_SESSION["cohort_ID"]);

        query("
            INSERT INTO taskdata (
                task_ID,
                task_team_ID,
                task_u_ID,
                task_cohort_ID,
                ms_ID,
                rd_ID,
                task_title,
                task_desc,
                task_start_d,
                task_end_d,
                task_done_u_ID,
                task_done_d,
                task_status,
                task_priority,
                task_created_d
            ) VALUES (
                NULL,
                {$team},
                '{$_SESSION["u_ID"]}',
                '{$cohort_ID}',
                NULL,
                {$req_ID},
                {$title},
                {$desc},
                {$start_d},
                {$end_d},
                {$selectTask},
                NULL,
                {$status},
                {$priority},
                CURRENT_TIMESTAMP()
            )
        ");
        break;

    // 編輯待辦事項
    case "edit_task_submit":
        function toNullEdit($v)
        {
            return ($v === "" || $v === null) ? "NULL" : "'" . addslashes($v) . "'";
        }

        $req_ID = !empty($p["form"]["req_ID"]) ? intval($p["form"]["req_ID"]) : "NULL";
        $title   = toNullEdit($p["form"]["title"] ?? null);
        $desc    = toNullEdit($p["form"]["desc"] ?? null);
        $start_d = toNullEdit($p["form"]["start_d"] ?? null);
        $end_d   = toNullEdit($p["form"]["end_d"] ?? null);
        $selectTask = toNullEdit($p["form"]["who_task"] ?? null);
        $team = intval($p["now_team_ID"]);
        $status = !empty($p["form"]["who_task"]) ? 1 : 0;
        $priority = intval($p["form"]["priority"] ?? 1);
        $cohort_ID = intval($_SESSION["cohort_ID"]);
        $id = intval($p["id"]);

        query("
            UPDATE taskdata SET
                task_team_ID   = {$team},
                task_u_ID      = '{$_SESSION["u_ID"]}',
                task_cohort_ID = '{$cohort_ID}',
                ms_ID          = NULL,
                rd_ID          = {$req_ID},
                task_title     = {$title},
                task_desc      = {$desc},
                task_start_d   = {$start_d},
                task_end_d     = {$end_d},
                task_done_u_ID = {$selectTask},
                task_priority  = {$priority},
                task_status    = {$status}
            WHERE task_ID = {$id}
        ");
        break;

    // 刪除待辦事項
    case "del_task_submit":
        query("DELETE FROM taskdata WHERE task_ID = " . intval($p["id"]));
        break;

    // 接下 / 放棄 / 完成待辦事項
    case "take_task":
        $id = intval($p["id"]);
        $status = intval($p["status"]);

        if ($status === 0) {
            query("
                UPDATE taskdata
                SET task_done_u_ID = NULL,
                    task_status = 0,
                    task_done_d = NULL
                WHERE task_ID = {$id}
            ");
        } else {
            query("
                UPDATE taskdata
                SET task_done_u_ID = '{$_SESSION["u_ID"]}',
                    task_status = '{$status}',
                    task_done_d = CURRENT_TIMESTAMP()
                WHERE task_ID = {$id}
            ");
        }
        break;

    // 取得對應預期成果
    case "get_exresultdata":
        $team_ID = intval($p["team_ID"]);

        $ids = fetchAll(query("
            SELECT team_u_ID
            FROM teammember
            WHERE team_ID = {$team_ID}
              AND tm_status = 1
        "));

        $user_ids = array_column($ids, 'team_u_ID');

        if (!$user_ids) {
            echo json_encode([
                "exresultdata" => [],
                "cohort" => $_SESSION["year_label"]
            ]);
            exit;
        }

        $ids_sql = "'" . implode("','", array_map('addslashes', $user_ids)) . "'";

        echo json_encode([
            "exresultdata" => fetchAll(query("
                SELECT 
                    e.*,
                    ua.u_name AS rd_u_name_a,
                    ub.u_name AS rd_u_name_b
                FROM exresultddata e
                LEFT JOIN userdata ua ON e.rd_u_ID_a = ua.u_ID
                LEFT JOIN userdata ub ON e.rd_u_ID_b = ub.u_ID
                WHERE e.rd_u_ID_b IN ({$ids_sql})
                  AND e.rd_status != 0
            ")),
            "cohort" => $_SESSION["year_label"]
        ]);
        break;

    case "req_return_click":
        query("
            UPDATE reprogressdata
            SET rp_status = '{$p["status"]}'
            WHERE req_ID = {$p["req_ID"]}
        ");
        break;

    case "req_return_OKclick":
        query("
            UPDATE reprogressdata
            SET rp_status = '3',
                rp_approved_d = CURRENT_TIMESTAMP(),
                rp_remark = '" . addslashes($p["rp_remark"]) . "'
            WHERE rp_ID = {$p["rp_ID"]}
        ");
        break;

    case "req_return_NotOKclick":
        query("
            UPDATE reprogressdata
            SET rp_status = '2',
                rp_approved_d = CURRENT_TIMESTAMP(),
                rp_remark = '" . addslashes($p["rp_remark"]) . "'
            WHERE rp_ID = {$p["rp_ID"]}
        ");
        break;
    case "system_new_task":
        file_put_contents("debug.log",print_r($_POST,true));
        header("Content-Type: application/json; charset=utf-8");

        $sf_ID = isset($_GET["sf_ID"]) ? (int)$_GET["sf_ID"] : 0;

        if (!$sf_ID) {
            echo json_encode([
                "ok" => false,
                "msg" => "缺少 sf_ID"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            // 先抓 suggestfrom 的標題 sf_name
            $stmt = $conn->prepare("
                SELECT sf_name, sf_cohort
                FROM suggestfrom
                WHERE sf_ID = ?
                LIMIT 1
        ");
            $stmt->execute([$sf_ID]);
            $sfRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sfRow) {
                echo json_encode([
                    "ok" => false,
                    "msg" => "找不到 suggestfrom 資料"
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $sf_name = "來源：" . $sfRow["sf_name"];
            $sf_cohort = $sfRow["sf_cohort"];

            // 再抓這次 sf_ID 底下所有建議
            $stmt = $conn->prepare("
            SELECT sf_ID, suggest_ID, suggest_u_ID, team_ID, suggest_comment
            FROM suggest
            WHERE sf_ID = ?
              AND suggest_comment IS NOT NULL
              AND TRIM(suggest_comment) != ''
            ORDER BY suggest_sort_no ASC, suggest_ID ASC
        ");
            $stmt->execute([$sf_ID]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                echo json_encode([
                    "ok" => false,
                    "msg" => "此 sf_ID 沒有建議資料"
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $insertStmt = $conn->prepare("
                    INSERT INTO taskdata
                    (
                        task_team_ID,
                        task_u_ID,
                        task_cohort_ID,
                        task_title,
                        task_desc,
                        task_status,
                        task_priority,
                        task_created_d
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, 0, 6, CURRENT_TIMESTAMP()
                    )
                ");

            $insertCount = 0;
            $debugData = [];

            foreach ($rows as $row) {
                $team_ID = $row["team_ID"];
                $suggest_u_ID = $row["suggest_u_ID"];
                $suggest_comment = trim($row["suggest_comment"]);

                if ($suggest_comment === '') {
                    continue;
                }

                // 依照 1. 2. 3. 4. 這種格式切開
                preg_match_all('/(?:^|\n)\s*\d+\.\s*(.*?)(?=(?:\n\s*\d+\.|\z))/su', $suggest_comment, $matches);

                $items = [];
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $item) {
                        $item = trim($item);
                        if ($item !== '') {
                            $items[] = $item;
                        }
                    }
                }

                // 如果切不到，就整段當成一筆
                if (empty($items)) {
                    $items[] = $suggest_comment;
                }

                foreach ($items as $itemTitle) {
                    $insertStmt->execute([
                        $team_ID,
                        $suggest_u_ID,
                        $sf_cohort,
                        $itemTitle,
                        $sf_name
                    ]);
                    $insertCount++;

                    $debugData[] = [
                        "sf_ID" => $sf_ID,
                        "team_ID" => $team_ID,
                        "task_u_ID" => $suggest_u_ID,
                        "task_title" => $itemTitle,
                        "task_desc" => $sf_name
                    ];
                }
            }

            echo json_encode([
                "ok" => true,
                "msg" => "成功新增 {$insertCount} 筆任務",
                "count" => $insertCount,
                "data" => $debugData
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            echo json_encode([
                "ok" => false,
                "msg" => "system_new_task 執行失敗：" . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        break;
}
