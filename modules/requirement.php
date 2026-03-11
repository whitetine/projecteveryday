<?php
session_start();
require '../includes/pdo.php';
$p = $_POST;
function req_json_response($arr)
{
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
switch ($_GET["do"]) {
    // 各種取得所有資料
    // T取得所有類組->requirement.php
    case "get_all_group":
        echo json_encode(fetchAll(query("SELECT * FROM `groupdata` WHERE group_status=1;")));
        break;
    // T搜尋團隊->progess.php
    case "select_team":
        $teamIDArray = fetchAll(query("SELECT team_ID FROM `teammember` WHERE u_ID = '{$_SESSION["u_ID"]}';"));
        $ids = array_column($teamIDArray, 'team_ID');
        $teamIDString = implode(',', $ids);
        $teamname = fetchAll(query("
            SELECT td.team_project_name,td.team_ID
            FROM teammember tm
            JOIN teamdata td ON tm.team_ID = td.team_ID
            WHERE tm.team_ID IN ($teamIDString)
            GROUP BY td.team_project_name;
        "));
        echo json_encode($teamname);
        break;
    // 搜尋屆別以供選擇=>requirement.php
    case "get_cohort":
        echo json_encode(fetchAll(query("SELECT * FROM `cohortdata` WHERE cohort_status=1 ORDER BY year_label DESC;")));
        break;
    // 基本需求編輯頁面(所有資料)
    case "get_req_ch":
        echo json_encode(fetchAll(query("SELECT req.*,cd.cohort_name,gd.group_name,ud.u_name FROM `requirementdata` req JOIN `cohortdata` cd JOIN `groupdata` gd JOIN `userdata` ud ON req.cohort_ID=cd.cohort_ID AND req.group_ID=gd.group_ID AND req.req_u_ID=ud.u_ID ORDER BY  req.req_status DESC ,req.group_ID, cohort_ID DESC,req_ID;")));
        break;
    case "req_del":
        query("UPDATE `requirementdata` SET `req_status` = '{$p["number"]}' WHERE `requirementdata`.`req_ID` = {$p["ID"]};");
        break;
    case "new_requirement_all": //T新增進度到資料庫
        if ($p["count2"] == "" && $p["count3"] == "") {
            $count_json = "[]";
        } else {
            $count_json = json_encode([
                $p["count2"] ?? "",
                $p["count3"] ?? "",
            ], JSON_UNESCAPED_UNICODE);
        }
        if ($p["req_end_d"] == "") {
            $p["req_end_d"] = 'null';
        } else {
            $p["req_end_d"] = "'{$p["req_end_d"]}'";
        }
        if ($p["req_ID"] != '') {
            query("UPDATE `requirementdata` SET `cohort_ID` = '{$p["cohort_ID"]}', `group_ID` = '{$p["group_ID"]}',`req_title` = '{$p["req_title"]}', `req_direction` = '{$p["req_direction"]}', `req_count` = '{$count_json}', `edit_u_ID` = '{$_SESSION["u_ID"]}', `color_hex` = '{$p["color_hex"]}', `req_update_d` = current_timestamp() WHERE `requirementdata`.`req_ID` = {$p["req_ID"]};");
        } else {
            query("INSERT INTO `requirementdata` (`req_ID`, `cohort_ID`, `group_ID`,  `req_title`, `req_direction`, `req_count`, `req_u_ID`, `color_hex`, `req_status`, `req_created_d`) VALUES (NULL, '{$p["cohort_ID"]}', '{$p["group_ID"]}',  '{$p["req_title"]}', '{$p["req_direction"]}', '{$count_json}', '{$_SESSION["u_ID"]}', '{$p["color_hex"]}', '1', current_timestamp());");
        }
        break;

    case "get_copy_history_req_list":
        // 抓 cohort_status=1 的屆別，且有 requirementdata 資料的最低要求
        // 若整屆都沒有 req，就不顯示
        echo json_encode(fetchAll(query("
        SELECT 
            req.req_ID,
            req.cohort_ID,
            cd.cohort_name,
            cd.year_label,
            req.group_ID,
            gd.group_name,
            req.req_title,
            req.req_direction,
            req.req_count,
            req.color_hex,
            req.req_status
        FROM requirementdata req
        JOIN cohortdata cd ON req.cohort_ID = cd.cohort_ID
        JOIN groupdata gd ON req.group_ID = gd.group_ID
        WHERE cd.cohort_status = 1
          AND req.req_status = 1
        ORDER BY cd.year_label DESC, req.group_ID ASC, req.req_ID ASC
    ")));
        break;
    case "get_copy_target_cohorts":
        // 不顯示已過結束時間的屆別；NULL 視為未結束
        echo json_encode(fetchAll(query("
        SELECT cohort_ID, year_label, cohort_name, cohort_start_d, cohort_end_d
        FROM cohortdata
        WHERE cohort_status = 1
          AND (cohort_end_d IS NULL OR cohort_end_d = '0000-00-00' OR cohort_end_d >= CURDATE())
        ORDER BY year_label DESC
    ")));
        break;
    case "copy_history_requirements":
        $target_cohort_ID = (int)($p["target_cohort_ID"] ?? 0);
        $req_ids = $p["req_ids"] ?? [];

        if ($target_cohort_ID <= 0) {
            req_json_response([
                "ok" => false,
                "msg" => "目標屆別無效"
            ]);
        }

        if (!is_array($req_ids) || count($req_ids) === 0) {
            req_json_response([
                "ok" => false,
                "msg" => "請至少選擇一筆要複製的資料"
            ]);
        }

        $req_ids = array_map('intval', $req_ids);
        $req_ids = array_filter($req_ids, function ($v) {
            return $v > 0;
        });

        if (count($req_ids) === 0) {
            req_json_response([
                "ok" => false,
                "msg" => "來源資料無效"
            ]);
        }

        $id_str = implode(",", $req_ids);

        $sourceRows = fetchAll(query("
        SELECT 
            req_ID,
            group_ID,
            req_title,
            req_direction,
            req_count,
            color_hex,
            req_status
        FROM requirementdata
        WHERE req_ID IN ($id_str)
    "));

        if (!$sourceRows || count($sourceRows) === 0) {
            req_json_response([
                "ok" => false,
                "msg" => "找不到要複製的來源資料"
            ]);
        }

        $inserted_count = 0;

        foreach ($sourceRows as $row) {
            $cohort_ID = $target_cohort_ID;
            $group_ID = (int)$row["group_ID"];
            $req_title = addslashes($row["req_title"] ?? "");
            $req_direction = addslashes($row["req_direction"] ?? "");
            $req_count = addslashes($row["req_count"] ?? "[]");
            $color_hex = addslashes($row["color_hex"] ?? "#FFEE66");
            $req_status = (int)($row["req_status"] ?? 1);

            query("
            INSERT INTO `requirementdata`
            (`req_ID`, `cohort_ID`, `group_ID`, `req_title`, `req_direction`, `req_count`, `req_u_ID`, `color_hex`, `req_status`, `req_created_d`)
            VALUES
            (NULL, '{$cohort_ID}', '{$group_ID}', '{$req_title}', '{$req_direction}', '{$req_count}', '{$_SESSION["u_ID"]}', '{$color_hex}', '{$req_status}', current_timestamp())
        ");
            $inserted_count++;
        }

        req_json_response([
            "ok" => true,
            "inserted_count" => $inserted_count
        ]);
        break;
    case "check_need_auto_copy_modal":
        // 找 requirementdata 最後一筆新增資料（最大 req_ID）
        $lastReq = fetch(query("
        SELECT req.req_ID, req.cohort_ID, cd.cohort_end_d
        FROM requirementdata req
        JOIN cohortdata cd ON req.cohort_ID = cd.cohort_ID
        ORDER BY req.req_ID DESC
        LIMIT 1
    "));

        if (!$lastReq) {
            req_json_response([
                "need_auto_open" => 0
            ]);
        }

        $maxCohort = fetch(query("
        SELECT cohort_ID, cohort_end_d
        FROM cohortdata
        ORDER BY cohort_ID DESC
        LIMIT 1
    "));

        if (!$maxCohort) {
            req_json_response([
                "need_auto_open" => 0
            ]);
        }

        $lastReqCohortID = (int)$lastReq["cohort_ID"];
        $maxCohortID = (int)$maxCohort["cohort_ID"];
        $cohortEnd = $lastReq["cohort_end_d"];

        $need_auto_open = 0;

        // 條件：
        // 1. 最後一筆 req 所屬 cohort_ID 是目前 cohortdata 中最大的 cohort_ID
        // 2. 該屆已超過結束時間
        // 3. cohort_end_d 不是 NULL
        if (
            $lastReqCohortID === $maxCohortID &&
            !empty($cohortEnd) &&
            $cohortEnd !== '0000-00-00' &&
            strtotime($cohortEnd) < strtotime(date('Y-m-d'))
        ) {
            $need_auto_open = 1;
        }

        req_json_response([
            "need_auto_open" => $need_auto_open
        ]);
        break;
}
