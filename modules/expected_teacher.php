<?php
session_start();
require '../includes/pdo.php';
$p = $_POST;
switch ($_GET["do"]) {
    case "get_sfd":
        $teamIds = array_column($p["tm"], "team_ID");
        $idStr = implode(",", $teamIds);
        echo json_encode(fetchAll(query("SELECT * FROM `exresultdata` WHERE `sfd_team_ID` IN ($idStr) ORDER BY `sfd_submit_d` DESC;")));
        break;
    case "add_sfd":
        if ($p["team_ID"] != "all") {
            $submit_d_OK = fetch(query("SELECT * FROM `exresultdata` WHERE sfd_submit_d = '{$p["sfd_date"]}' AND sfd_team_ID = '{$p["team_ID"]}';"));
            if ($submit_d_OK) {
                echo json_encode(["status" => "error", "message" => "該小組已存在相同截止日的預期成果，請修改截止日後再試。"]);
                break;
            }
            query("INSERT INTO `exresultdata` (`sfd_ID`, `sfd_name`, `sfd_team_ID`, `sfd_u_ID`, `sfd_submit_d`, `sfd_created_d`) VALUES (NULL, '{$p["sfd_title"]}', '{$p["team_ID"]}', '{$_SESSION["u_ID"]}', '{$p["sfd_date"]}', current_timestamp());");
            echo json_encode(["status" => "success", "message" => "新增成功"]);
        } else {
            $existTeams = [];
            $insertedTeams = [];
            // $p["all_team_ID"] 會是 Array( [0]=>[team_ID=>1...], [1]=>... )
            foreach (($p["all_team_ID"] ?? []) as $t) {

                $tid = $t["team_ID"] ?? "";
                if ($tid === "") continue;

                $submit_d_OK = fetch(query("SELECT * FROM `exresultdata` 
                                        WHERE sfd_submit_d = '{$p["sfd_date"]}' 
                                          AND sfd_team_ID = '{$tid}';"));

                if ($submit_d_OK) {
                    $existTeams[] = $tid; // 記錄哪些小組已存在
                    continue;             // 跳過不新增
                }

                query("INSERT INTO `exresultdata` (`sfd_ID`, `sfd_name`, `sfd_team_ID`, `sfd_u_ID`, `sfd_submit_d`, `sfd_created_d`) 
                   VALUES (NULL, '{$p["sfd_title"]}', '{$tid}', '{$_SESSION["u_ID"]}', '{$p["sfd_date"]}', current_timestamp());");

                $insertedTeams[] = $tid;
            }
            // 如果全部都重複 → 回傳 error
            if (count($insertedTeams) === 0) {
                echo json_encode([
                    "status" => "error",
                    "message" => "全部小組都已存在相同截止日的預期成果，請修改截止日後再試。",
                    "existTeams" => $existTeams
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            // 部分成功：有些新增、有些跳過
            if (count($existTeams) > 0) {
                echo json_encode([
                    "status" => "success",
                    "message" => "已新增部分小組，其餘小組因截止日重複而跳過。",
                    "insertedTeams" => $insertedTeams,
                    "existTeams" => $existTeams
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            // 全部成功
            echo json_encode([
                "status" => "success",
                "message" => "全部小組新增成功",
                "insertedTeams" => $insertedTeams
            ], JSON_UNESCAPED_UNICODE);
            break;
        }
        break;
    case "del_sfd":
        $today = date("Y-m-d");
        if ($p["date"] < $today) {
            echo json_encode([
                "status" => "error",
                "message" => "截止日已過，無法刪除"
            ], JSON_UNESCAPED_UNICODE);
            break;
        }
        query("DELETE FROM exresultdata WHERE `exresultdata`.`sfd_ID` = '{$p["sfd_ID"]}';");
        echo json_encode([
            "status" => "success",
            "message" => "刪除成功"
        ], JSON_UNESCAPED_UNICODE);
        break;
    case "add_sfd_auto":

        $team_ID = $p["team_ID"] ?? "";

        if (empty($team_ID)) {
            echo json_encode(["status" => "error", "message" => "缺少 team_ID"], JSON_UNESCAPED_UNICODE);
            break;
        }

        $insertCount = 0;
        $skipCount = 0;

        // 從「下個月1號」開始連續12筆
        $base = new DateTime("first day of next month");

        $dates = [];
        for ($i = 0; $i < 12; $i++) {
            $d = clone $base;
            $d->modify("+{$i} month");
            $submit_d = $d->format("Y-m-01");

            $start = clone $d;
            $start->modify("-1 month");
            $start_d = $start->format("Y-m-02");

            $dates[] = [
                "submit_d" => $submit_d,
                "start_d"  => $start_d,
            ];
        }

        foreach ($dates as $x) {

            // 避免同 team 同截止日重複
            $submit_d_OK = fetch(query("SELECT sfd_ID FROM `exresultdata`
        WHERE sfd_submit_d = '{$x["submit_d"]}'
        AND sfd_team_ID = '{$team_ID}'
        LIMIT 1"));

            if ($submit_d_OK) {
                $skipCount++;
                continue;
            }

            // sfd_name：用開始日期的月份命名
            $dt = new DateTime($x["start_d"]);
            $yy = $dt->format('y');
            $m  = (int)$dt->format('n');
            $name = "{$yy}年{$m}月";

            query("INSERT INTO `exresultdata`
        (`sfd_ID`, `sfd_name`, `sfd_team_ID`, `sfd_u_ID`, `sfd_start_d`, `sfd_submit_d`, `sfd_created_d`, `sfd_score`, `sfd_rank`, `sfd_suggest`)
        VALUES
        (NULL, '{$name}', '{$team_ID}', '{$_SESSION["u_ID"]}', '{$x["start_d"]}', '{$x["submit_d"]}', current_timestamp(), NULL, NULL, '')
        ");

            $insertCount++;
        }

        if ($skipCount == 0) {
            echo json_encode([
                "status" => "success",
                "message" => "自動產生完成，全部 {$insertCount} 筆新增成功"
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                "status" => "success",
                "message" => "自動產生完成，新增 {$insertCount} 筆，跳過 {$skipCount} 筆(因已存在相同截止日的預期成果)"
            ], JSON_UNESCAPED_UNICODE);
        }

        break;
    case "update_sfd":
        $sfd_ID = trim($p["sfd_ID"] ?? "");
        $name = trim($p["sfd_name"] ?? "");
        $date = trim($p["sfd_submit_d"] ?? "");

        if ($sfd_ID === "" || $name === "" || $date === "") {
            echo json_encode(["status" => "error", "message" => "缺少必要欄位"], JSON_UNESCAPED_UNICODE);
            break;
        }

        // 擋過去日期
        $today = date("Y-m-d");
        if ($date < $today) {
            echo json_encode(["status" => "error", "message" => "截止日不可小於今天"], JSON_UNESCAPED_UNICODE);
            break;
        }

        // 檢查同一小組是否已有相同截止日（排除自己）
        $row = fetch(query("SELECT sfd_team_ID FROM exresultdata WHERE sfd_ID = '{$sfd_ID}'"));
        if (!$row) {
            echo json_encode(["status" => "error", "message" => "找不到該筆資料"], JSON_UNESCAPED_UNICODE);
            break;
        }

        $teamId = $row["sfd_team_ID"];

        $dup = fetch(query("SELECT sfd_ID FROM exresultdata 
                        WHERE sfd_team_ID = '{$teamId}'
                          AND sfd_submit_d = '{$date}'
                          AND sfd_ID <> '{$sfd_ID}'"));
        if ($dup) {
            echo json_encode(["status" => "error", "message" => "該小組已存在相同截止日，請修改後再試"], JSON_UNESCAPED_UNICODE);
            break;
        }

        query("UPDATE exresultdata 
           SET sfd_name = '{$name}', sfd_submit_d = '{$date}'
           WHERE sfd_ID = '{$sfd_ID}'");

        echo json_encode(["status" => "success", "message" => "更新成功"], JSON_UNESCAPED_UNICODE);
        break;

    case "req_review":
        if ($p["status"] == 10) {
            $status = "";
        } else {
            $status = ",`rp_approved_d` = current_timestamp(),`rp_status` = {$p["status"]}";
        }
        query("UPDATE `reprogressdata` SET `rp_remark` = '{$p["remark"]}',`rp_approved_u_ID` = '{$_SESSION["u_ID"]}' $status WHERE `rp_team_ID` = '{$p["team_ID"]}' AND `req_ID` = '{$p["req_ID"]}';");
        break;

    case "get_AI_score":
    echo json_encode(fetchAll(query("
        SELECT DISTINCT
            e.*,
            t.team_project_name,
            c.total_teams
        FROM exresultdata e
        JOIN teammember tm 
            ON tm.team_ID = e.sfd_team_ID
        JOIN teamdata t
            ON t.team_ID = e.sfd_team_ID
        JOIN (
            SELECT 
                e2.sfd_submit_d,
                COUNT(DISTINCT e2.sfd_team_ID) AS total_teams
            FROM exresultdata e2
            GROUP BY e2.sfd_submit_d
        ) c 
            ON c.sfd_submit_d = e.sfd_submit_d
        WHERE tm.team_u_ID = '{$_SESSION["u_ID"]}'
        ORDER BY e.sfd_team_ID ASC, e.sfd_submit_d ASC
    ")), JSON_UNESCAPED_UNICODE);

    file_put_contents("debug.log", print_r("
        SELECT DISTINCT
            e.*,
            t.team_project_name,
            c.total_teams
        FROM exresultdata e
        JOIN teammember tm 
            ON tm.team_ID = e.sfd_team_ID
        JOIN teamdata t
            ON t.team_ID = e.sfd_team_ID
        JOIN (
            SELECT 
                e2.sfd_submit_d,
                COUNT(DISTINCT e2.sfd_team_ID) AS total_teams
            FROM exresultdata e2
            GROUP BY e2.sfd_submit_d
        ) c 
            ON c.sfd_submit_d = e.sfd_submit_d
        WHERE tm.team_u_ID = '{$_SESSION["u_ID"]}'
        ORDER BY e.sfd_team_ID ASC, e.sfd_submit_d ASC
    ", true));
    break;
}
