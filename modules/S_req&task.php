<?php
session_start();
require '../includes/pdo.php';
$p = $_POST;
switch ($_GET["do"]) {
    // 取得當下登入者的類組ID、類組名稱、團隊名稱**
    case "get_now_group":
        echo json_encode(fetch(query("SELECT gd.group_ID,gd.group_name,td.team_project_name FROM `teammember` tm JOIN `teamdata` td JOIN `groupdata` gd ON tm.team_ID=td.team_ID AND td.group_ID=gd.group_ID WHERE tm.team_u_ID='{$_SESSION["u_ID"]}';")));
        break;
    // 搜尋當前登入者的所有組員(包含老師)
    case "get_now_teammember":
        $team_ID = fetch(query("SELECT team_ID FROM `teammember` WHERE team_u_ID='{$_SESSION["u_ID"]}' AND tm_status=1;"))["team_ID"];
        $team_member = fetchAll(query("SELECT tm.team_u_ID,ud.u_name,ur.role_ID FROM `teammember` tm JOIN `userdata` ud JOIN `userrolesdata` ur ON tm.team_u_ID=ud.u_ID AND ur.ur_u_ID=ud.u_ID WHERE tm.team_ID={$team_ID} AND tm_status=1"));
        echo json_encode([
            "team_ID" => $team_ID,
            "team_member" => $team_member
        ]);
        break;
    // 取得所有基本需求，where狀態、時間**
    case "get_requirement":
        file_put_contents("debug.log", print_r($p, true));
        $c = $_SESSION["year_label"];
        $cohort = $_SESSION["cohort_ID"];
        $teamID = (int)($p["now_team_ID"] ?? 0);
        $groupID = (int)($p["g"]["ID"] ?? 0);
        $req = fetchAll(query("SELECT r.*,u.u_name,CASE WHEN rp.rp_status IS NULL THEN 0 ELSE rp.rp_status END AS status FROM requirementdata AS r LEFT JOIN (SELECT rp1.* FROM reprogressdata AS rp1 INNER JOIN (SELECT req_ID,rp_team_ID,MAX(rp_ID) AS max_rp_ID FROM reprogressdata WHERE rp_team_ID={$teamID} GROUP BY req_ID,rp_team_ID) AS t ON t.req_ID=rp1.req_ID AND t.rp_team_ID=rp1.rp_team_ID AND t.max_rp_ID=rp1.rp_ID) AS rp ON rp.req_ID=r.req_ID LEFT JOIN userdata AS u ON u.u_ID=rp.rp_u_ID WHERE r.req_status=1 AND r.group_ID={$groupID} AND r.cohort_ID='{$cohort}' ORDER BY status;"));        // $req = fetchAll(query("SELECT r.*,u.u_name,CASE WHEN rp.rp_status IS NULL THEN 0 ELSE rp.rp_status END AS status FROM `requirementdata` AS r LEFT JOIN (SELECT rp1.* FROM `reprogressdata` AS rp1 INNER JOIN (SELECT req_ID,MAX(rp_ID) AS max_rp_ID FROM `reprogressdata` GROUP BY req_ID) AS t ON t.req_ID=rp1.req_ID AND t.max_rp_ID=rp1.rp_ID) AS rp ON rp.req_ID=r.req_ID LEFT JOIN `userdata` AS u ON u.u_ID=rp.rp_u_ID WHERE r.req_status=1 AND r.group_ID='{$p["g"]["ID"]}' AND r.cohort_ID='$cohort' ORDER BY `status`;"));
        $rp = fetchAll(query("SELECT rd.*,ud.u_name FROM `reprogressdata` rd JOIN `userdata` ud ON rd.rp_u_ID = ud.u_ID  WHERE rp_team_ID={$p["now_team_ID"]};"));
        echo json_encode(
            [
                "cohort" => $c,
                "req" => $req,
                "rp" => $rp
            ]
        );
        break;
    // 取得該團隊的任務
    case "get_task":
        echo json_encode(fetchAll(query("SELECT td.*, creator.u_name AS creator_name, finisher.u_name AS done_name FROM taskdata td LEFT JOIN userdata creator ON td.task_u_ID = creator.u_ID LEFT JOIN userdata finisher ON td.task_done_u_ID = finisher.u_ID WHERE td.task_team_ID = {$p["team_ID"]} ORDER BY td.task_status, td.task_priority DESC,td.task_end_d DESC")));
        break;

    case "new_task_submit":
        file_put_contents("debug.log",print_r($p,true));
        // 取得 cohort_ID
        $cohort = substr($_SESSION["u_ID"], 0, 3);
        $cohort_ID = fetch(query("SELECT cohort_ID FROM cohortdata WHERE year_label={$cohort}"))["cohort_ID"];
        // 1️⃣ 空字串處理函式
        function toNull($v)
        {
            return ($v === "" ? "NULL" : "'$v'");
        }
        // 2️⃣ 各欄位套用
        $title = toNull($p["form"]["title"]);
        $desc = toNull($p["form"]["desc"]);
        $start_d = toNull($p["form"]["start_d"]);
        $end_d = toNull($p["form"]["end_d"]);
        if ($start_d == "0000-00-00 00:00:00") {
            $start_d = null;
        }
        if ($end_d == "0000-00-00 00:00:00") {
            $end_d = null;
        }
        if ($p["form"]["req_ID"]) {
            $req_ID = $p["form"]["req_ID"];
        } else {
            $req_ID = "NULL";
        }
        $selectTask = toNull($p["form"]["who_task"]);   // task_done_u_ID
        $team = toNull($p["now_team_ID"]);
        $status = ($p["form"]["who_task"] ? '1' : '0');
        // 3️⃣ SQL
        $sql = "INSERT INTO taskdata 
        (task_ID, task_team_ID, task_u_ID, task_cohort_ID, ms_ID, rd_ID,
         task_title, task_desc, task_start_d, task_end_d, task_done_u_ID, 
         task_done_d, task_status, task_priority, task_created_d)
        VALUES(
            NULL,
            $team,
            '{$_SESSION["u_ID"]}',
            '$cohort_ID',
            NULL,
            $req_ID,
            $title,
            $desc,
            $start_d,
            $end_d,
            $selectTask,
            NULL,
            $status,
            {$p["form"]["priority"]},
            CURRENT_TIMESTAMP())";
            file_put_contents("debug.log",print_r($sql,true));
        query($sql);
        break;

    case "edit_task_submit":
        // 取得 cohort_ID
        $cohort = substr($_SESSION["u_ID"], 0, 3);
        $cohort_ID = fetch(query("SELECT cohort_ID FROM cohortdata WHERE year_label={$cohort}"))["cohort_ID"];
        // 空字串處理函式
        function toNull($v)
        {
            return ($v === "" ? "NULL" : "'$v'");
        }
        // 各欄位套用
        if ($p["form"]["select1"] == "miles") {
            $ms_ID = $p["form"]["select2"]; // 有值
            $req_ID = "NULL";               // 無值
        } else if ($p["form"]["select1"] == "req") {
            $req_ID = $p["form"]["select2"];
            $ms_ID = "NULL";
        } else {
            $req_ID = "NULL";
            $ms_ID = "NULL";
        }
        $title = toNull($p["form"]["title"]);
        $desc = toNull($p["form"]["desc"]);
        $start_d = toNull($p["form"]["start_d"]);
        $end_d = toNull($p["form"]["end_d"]);
        $select1 = toNull($p["form"]["select1"]);
        $select2 = toNull($p["form"]["select2"]);
        $selectTask = toNull($p["form"]["who_task"]); // task_done_u_ID
        $team = toNull($p["now_team_ID"]);
        $status = ($p["form"]["who_task"] ? '1' : '0');
        // UPDATE
        $sql = "
    UPDATE taskdata SET
        task_team_ID    = $team,
        task_u_ID       = '{$_SESSION["u_ID"]}',
        task_cohort_ID  = '$cohort_ID',
        ms_ID           = $ms_ID,
        req_ID          = $req_ID,
        task_title      = $title,
        task_desc       = $desc,
        task_start_d    = $start_d,
        task_end_d      = $end_d,
        task_done_u_ID  = $selectTask,
        task_priority   = {$p["form"]["priority"]},
        task_status     = $status
    WHERE task_ID = '{$p["id"]}'";
        query($sql);
        break;

    case "del_task_submit":
        query("DELETE FROM `taskdata` WHERE `taskdata`.`task_ID` = {$p["id"]};");
        break;

    case "take_task":
        query("UPDATE `taskdata` SET `task_done_u_ID` = '{$_SESSION["u_ID"]}', `task_status` = '{$p["status"]}', `task_done_d` = CURRENT_TIMESTAMP() WHERE `taskdata`.`task_ID` = {$p["id"]};");
        break;
    // case "req_return_submit":
    //     query("INSERT INTO `reprogressdata` (`rp_ID`, `req_ID`, `rp_team_ID`, `rp_u_ID`, `rp_status`, `rp_completed_d`, `rp_approved_d`, `rp_approved_u_ID`, `rp_comment`) VALUES (NULL, '{$p["req_ID"]}', '{$p["now_team_ID"]}', '{$_SESSION["u_ID"]}', '1', CURRENT_TIMESTAMP(), NULL, NULL, '{$p["form"]["rp_comment"]}');");
    //     break;
    case "req_return_click":
        query("INSERT INTO `reprogressdata` (`rp_ID`, `req_ID`, `rp_team_ID`, `rp_u_ID`, `rp_status`, `rp_completed_d`, `rp_approved_d`, `rp_approved_u_ID`,`rp_comment`,`rp_count`) VALUES (NULL, '{$p["req_ID"]}', '{$p["now_team_ID"]}', '{$_SESSION["u_ID"]}', '1', current_timestamp(), NULL, NULL, '{$p["text"]}', '{$p["count"]}');");
        break;

    case "req_return_click_again":
        query("UPDATE `reprogressdata` SET `rp_u_ID` = '{$_SESSION["u_ID"]}', `rp_count` = '{$p["count"]}', `rp_comment` = '{$p["text"]}', `rp_status` = '1', `rp_completed_d` = CURRENT_TIMESTAMP() WHERE `reprogressdata`.`rp_ID` = {$p["rp_ID"]};");
        break;

    case "get_exresultdata":
        $ids = array_column($_POST['tm'], 'team_u_ID');
        $ids_sql = "'" . implode("','", $ids) . "'";
        echo json_encode([
            "exresultdata" => fetchAll(query("SELECT e.*, ua.u_name AS rd_u_name_a, ub.u_name AS rd_u_name_b FROM exresultddata e LEFT JOIN userdata ua ON e.rd_u_ID_a = ua.u_ID LEFT JOIN userdata ub ON e.rd_u_ID_b = ub.u_ID WHERE e.rd_u_ID_b IN ({$ids_sql}) AND e.rd_status != 0;")),
            "cohort" => $_SESSION["year_label"]
        ]);
        break;
}
