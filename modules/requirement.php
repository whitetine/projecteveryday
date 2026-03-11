<?php
session_start();
require '../includes/pdo.php';
$p = $_POST;
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
    case "new_requirement_all"://T新增進度到資料庫
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
}
?>