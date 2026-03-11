<?php
session_start();
require '../includes/pdo.php';
$p = $_POST;
function cr_json_value($v)
{
    $v = (string)($v ?? '');
    $v = str_replace('\\', '\\\\', $v); // 先處理反斜線
    $v = str_replace('"', '\\"', $v);   // 再處理雙引號
    return $v;
}

function cr_json_array_text($arr)
{
    $safe = array_map('cr_json_value', $arr);
    return '["' . implode('","', $safe) . '"]';
}
switch ($_GET["do"]) {
    // 取得當下登入者的類組ID、類組名稱、團隊名稱
    case "get_now_group":
        echo json_encode(fetchAll(query("SELECT gd.group_ID,gd.group_name,td.team_project_name,td.team_ID FROM `teammember` tm JOIN `teamdata` td JOIN `groupdata` gd ON tm.team_ID=td.team_ID AND td.group_ID=gd.group_ID WHERE tm.team_u_ID='{$_SESSION["u_ID"]}';")));
        break;
    // 取得所有基本需求，where狀態、時間
    case "get_requirement":
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
    // 搜尋當前登入者的所有組員(包含老師)
    case "get_now_teammember":
        $team_ID = fetch(query("SELECT team_ID FROM `teammember` WHERE team_u_ID='{$_SESSION["u_ID"]}' AND tm_status=1;"))["team_ID"];
        $team_member = fetchAll(query("SELECT tm.team_u_ID,ud.u_name,ur.role_ID FROM `teammember` tm JOIN `userdata` ud JOIN `userrolesdata` ur ON tm.team_u_ID=ud.u_ID AND ur.ur_u_ID=ud.u_ID WHERE tm.team_ID={$team_ID}; AND tm_status=1"));
        echo json_encode([
            "team_ID" => $team_ID,
            "team_member" => $team_member
        ]);
        break;
    // 儲存進度回報
    case "save_rp_comment":
        $sqlOK = fetch(query("SELECT * FROM `reprogressdata` WHERE req_ID = {$p['req_ID']} AND rp_team_ID = {$p['team_ID']};"));
        if ($sqlOK) {
            query("UPDATE `reprogressdata` SET `rp_comment` = '{$p["rp_comment"]}', `rp_completed_d` = CURRENT_TIMESTAMP(),`rp_status`=0,`rp_count`='{$p["rp_count"]}' WHERE `reprogressdata`.`req_ID` = {$p["req_ID"]};");
        } else {
            query("INSERT INTO `reprogressdata` (`rp_ID`, `req_ID`, `rp_team_ID`, `rp_u_ID`, `rp_count`, `rp_comment`, `rp_completed_d`, `rp_approved_d`, `rp_approved_u_ID`, `rp_remark`,`rp_status`) VALUES (NULL, '{$p["req_ID"]}', '{$p["team_ID"]}', '{$_SESSION["u_ID"]}', '{$p["rp_count"]}', '{$p["rp_comment"]}',  CURRENT_TIMESTAMP(), '', NULL, '',0);");
        }
        break;

    // 查詢專題預期成果
    case "get_Expected":
        $ids = array_column($_POST['tm'], 'team_u_ID');
        $ids_sql = "'" . implode("','", $ids) . "'";
        echo json_encode(fetchAll(query("SELECT e.*, ua.u_name AS rd_u_name_a, ub.u_name AS rd_u_name_b FROM exresultddata e LEFT JOIN userdata ua ON e.rd_u_ID_a = ua.u_ID LEFT JOIN userdata ub ON e.rd_u_ID_b = ub.u_ID WHERE e.rd_u_ID_b IN ({$ids_sql}) AND e.rd_status != 0;")));
        break;
    // 取得異動紀錄
    case "get_log":
        $ids = array_column($_POST['tm'], 'team_u_ID');
        $ids_sql = "'" . implode("','", $ids) . "'";
        echo json_encode(fetchAll(query("SELECT cr.*, ua.u_name AS cr_u_name, ur.u_name AS cr_record_name, uu.u_name AS cr_update_data_name FROM changerecordsdata cr LEFT JOIN userdata ua ON cr.cr_u_ID = ua.u_ID JOIN exresultddata e ON cr.rd_ID = e.rd_ID LEFT JOIN userdata ur ON ur.u_ID = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cr.cr_record, '$[2]')), '') LEFT JOIN userdata uu ON uu.u_ID = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cr.cr_update_data, '$[2]')), '') WHERE e.rd_u_ID_b IN ({$ids_sql}) ORDER BY cr.cr_update_d DESC;")));
        break;
    case "get_exresultdata":
        echo json_encode(fetch(query("SELECT * FROM `exresultdata` WHERE `sfd_team_ID` = '{$p["team_ID"]}'")));
        break;
    // 提交新增成果
    case "add_expected":
        $CEO_raw = $p["CEO"] ?? null;
        $CEO_sql = (is_null($CEO_raw) || trim((string)$CEO_raw) === '')
            ? "NULL"
            : "'" . addslashes((string)$CEO_raw) . "'";

        $title = addslashes((string)($p["title"] ?? ''));
        $value = addslashes((string)($p["value"] ?? ''));

        query("INSERT INTO `exresultddata`
        (`rd_ID`, `rd_title`, `rd_content`, `rd_u_ID_a`, `rd_u_ID_b`, `rd_status`, `rd_finish_d`)
        VALUES
        (NULL, '{$title}', '{$value}', {$CEO_sql}, '{$_SESSION["u_ID"]}', '1', current_timestamp());");

        $id = fetch(query("SELECT * FROM `exresultddata` ORDER BY rd_ID DESC LIMIT 1;"))["rd_ID"];

        $log_json = addslashes(cr_json_array_text([
            $p["title"] ?? '',
            $p["value"] ?? '',
            $p["CEO"] ?? ''
        ]));

        query("INSERT INTO `changerecordsdata`
        (`cr_ID`, `rd_ID`, `cr_type`, `cr_record`, `cr_update_data`, `cr_reason`, `cr_u_ID`, `cr_update_d`)
        VALUES
        (NULL, '{$id}', 'INSERT', NULL, '{$log_json}', NULL, '{$_SESSION["u_ID"]}', CURRENT_TIMESTAMP());");
        break;
    // 提交編輯成果
    case "update_expected":
        $CEO_raw = $p["rd_u_ID_a"] ?? null;
        $CEO = (is_null($CEO_raw) || trim((string)$CEO_raw) === '')
            ? "NULL"
            : "'" . addslashes((string)$CEO_raw) . "'";

        $row = fetch(query("SELECT * FROM `exresultddata` WHERE rd_ID = {$p["rd_ID"]};"));

        $old_json = addslashes(cr_json_array_text([
            $row["rd_title"] ?? '',
            $row["rd_content"] ?? '',
            $row["rd_u_ID_a"] ?? ''
        ]));

        $new_json = addslashes(cr_json_array_text([
            $p["rd_title"] ?? '',
            $p["rd_content"] ?? '',
            $p["rd_u_ID_a"] ?? ''
        ]));

        $reason = addslashes((string)($p["cr_reason"] ?? ''));
        $new_title = addslashes((string)($p["rd_title"] ?? ''));
        $new_content = addslashes((string)($p["rd_content"] ?? ''));

        query("INSERT INTO `changerecordsdata`
        (`cr_ID`, `rd_ID`, `cr_type`, `cr_record`, `cr_update_data`, `cr_reason`, `cr_u_ID`, `cr_update_d`)
        VALUES
        (NULL, '{$p["rd_ID"]}', 'UPDATE', '{$old_json}', '{$new_json}', '{$reason}', '{$_SESSION["u_ID"]}', current_timestamp());");

        query("UPDATE `exresultddata`
        SET `rd_title` = '{$new_title}', `rd_content` = '{$new_content}', `rd_u_ID_a` = $CEO, `rd_u_ID_b` = '{$_SESSION["u_ID"]}', `rd_finish_d` = current_timestamp(), `rd_status` = '{$p["rd_done"]}'
        WHERE `exresultddata`.`rd_ID` = {$p["rd_ID"]};");
        break;
    // 刪除成果
    case "delete_expected":
        $row = fetch(query("SELECT * FROM `exresultddata` WHERE rd_ID = {$p["rd_ID"]};"));

        $old_json = addslashes(cr_json_array_text([
            $row["rd_title"] ?? '',
            $row["rd_content"] ?? '',
            $row["rd_u_ID_a"] ?? ''
        ]));

        $reason = addslashes((string)($p["cr_reason"] ?? ''));

        query("INSERT INTO `changerecordsdata`
        (`cr_ID`, `rd_ID`, `cr_type`, `cr_record`, `cr_update_data`, `cr_reason`, `cr_u_ID`, `cr_update_d`)
        VALUES
        (NULL, '{$p["rd_ID"]}', 'DELETE', '{$old_json}', NULL, '{$reason}', '{$_SESSION["u_ID"]}', current_timestamp());");

        query("UPDATE `exresultddata`
        SET `rd_u_ID_b` = '{$_SESSION["u_ID"]}', `rd_status` = '0', `rd_finish_d` = current_timestamp()
        WHERE `exresultddata`.`rd_ID` = {$p["rd_ID"]};");
        break;

    case "auto_AI_score":

        // ✅ 0) 入口條件：資料庫中「任一筆」今天 > 截止日 且 sfd_score 為 NULL 才啟動
        $row = fetch(query("
        SELECT sfd_ID, sfd_team_ID, sfd_submit_d
        FROM exresultdata
        WHERE CURDATE() > sfd_submit_d
          AND sfd_score IS NULL
        ORDER BY sfd_submit_d ASC
        LIMIT 1
    "));

        if (empty($row)) {
            echo json_encode([
                "ok" => true,
                "skip" => true,
                "message" => "目前沒有任何逾期且未評分的資料，auto_AI_score 不執行"
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ✅ 1) 取得觸發的截止日
        $submitD = $row["sfd_submit_d"];

        // ✅ 2) 找出同截止日、且逾期、且未評分的所有團隊
        $dueTeams = fetchAll(query("
        SELECT sfd_team_ID
        FROM exresultdata
        WHERE sfd_submit_d = '{$submitD}'
          AND CURDATE() > sfd_submit_d
          AND sfd_score IS NULL
    "));

        if (empty($dueTeams)) {
            echo json_encode([
                "ok" => true,
                "skip" => true,
                "message" => "同截止日沒有任何需要評分的團隊"
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        $teamIDs = array_values(array_unique(array_map(fn($x) => (int)$x["sfd_team_ID"], $dueTeams)));
        $teamIDs = array_filter($teamIDs, fn($x) => $x > 0);

        if (empty($teamIDs)) {
            echo json_encode([
                "ok" => true,
                "skip" => true,
                "message" => "同截止日找不到有效的 team_ID"
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        $teamIDsSql = implode(",", $teamIDs);

        // ✅ 3) team_ID -> teamdata + groupdata：拿 group_ID / group_name / team_project_name
        $teamRows = fetchAll(query("
        SELECT 
            td.team_ID,
            td.group_ID,
            td.team_project_name,
            gd.group_name
        FROM teamdata td
        JOIN groupdata gd ON gd.group_ID = td.group_ID
        WHERE td.team_ID IN ({$teamIDsSql})
    "));

        if (empty($teamRows)) {
            echo json_encode([
                "ok" => true,
                "skip" => true,
                "message" => "找不到 teamdata / groupdata 對應資料"
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        // team 索引 + group 索引
        $teamInfoMap = [];     // [team_ID] => ...
        $groupInfoMap = [];    // [group_ID] => group_name
        $groupIDs = [];

        foreach ($teamRows as $t) {
            $tid = (int)$t["team_ID"];
            $gid = (int)$t["group_ID"];
            $teamInfoMap[$tid] = [
                "team_ID" => $tid,
                "group_ID" => $gid,
                "team_project_name" => $t["team_project_name"] ?? "",
                "group_name" => $t["group_name"] ?? ""
            ];
            $groupInfoMap[$gid] = $t["group_name"] ?? "";
            $groupIDs[] = $gid;
        }

        $groupIDs = array_values(array_unique(array_filter($groupIDs, fn($x) => $x > 0)));
        if (empty($groupIDs)) {
            echo json_encode([
                "ok" => true,
                "skip" => true,
                "message" => "找不到有效的 group_ID"
            ], JSON_UNESCAPED_UNICODE);
            break;
        }
        $groupIDsSql = implode(",", $groupIDs);

        // ✅ 4) requirementdata：只抓 req_status=1，且同類組只抓一次（因為用 group_ID 分組）
        $reqRows = fetchAll(query("
        SELECT req_ID, group_ID, req_title, req_direction, req_u_ID
        FROM requirementdata
        WHERE req_status = 1
          AND group_ID IN ({$groupIDsSql})
        ORDER BY group_ID, req_ID
    "));

        // 需求依 group_ID 分組
        $reqByGroup = []; // [group_ID] => [req...]
        foreach ($reqRows as $r) {
            $gid = (int)$r["group_ID"];
            $reqID = (int)$r["req_ID"];

            // req_u_ID 像 [6,\"張\"]：嘗試解析，失敗就原樣
            $reqCount = null;
            if (!empty($r["req_u_ID"])) {
                $tmp = json_decode($r["req_u_ID"], true);
                $reqCount = (json_last_error() === JSON_ERROR_NONE) ? $tmp : $r["req_u_ID"];
            }

            if (!isset($reqByGroup[$gid])) $reqByGroup[$gid] = [];
            $reqByGroup[$gid][] = [
                "req_ID" => $reqID,
                "req_title" => $r["req_title"] ?? "",
                "req_direction" => $r["req_direction"] ?? "",
                "req_count" => $reqCount
            ];
        }

        // ✅ 5) 建每個 group 的 req_ID 清單（抓回報用）
        $reqIdListByGroup = [];
        foreach ($reqByGroup as $gid => $list) {
            $reqIdListByGroup[$gid] = array_values(array_unique(array_map(fn($x) => (int)$x["req_ID"], $list)));
        }

        // ✅ 6) reprogressdata：抓各隊對需求的回報（rp_team_ID + req_ID）
        $rpByTeam = []; // [team_ID][req_ID] => rp
        foreach ($teamIDs as $tid) {
            $gid = $teamInfoMap[$tid]["group_ID"] ?? 0;
            $reqIds = $reqIdListByGroup[$gid] ?? [];

            if (empty($reqIds)) {
                $rpByTeam[$tid] = [];
                continue;
            }

            $reqIdsSql = implode(",", array_map("intval", $reqIds));

            $rpRows = fetchAll(query("
            SELECT req_ID, rp_team_ID, rp_count, rp_comment, rp_status
            FROM reprogressdata
            WHERE rp_team_ID = {$tid}
              AND req_ID IN ({$reqIdsSql})
        "));

            $rpByTeam[$tid] = [];
            foreach ($rpRows as $rp) {
                $rid = (int)$rp["req_ID"];
                $rpByTeam[$tid][$rid] = [
                    "rp_count" => isset($rp["rp_count"]) ? (int)$rp["rp_count"] : null,
                    "rp_comment" => $rp["rp_comment"] ?? "",
                    "rp_status" => isset($rp["rp_status"]) ? (int)$rp["rp_status"] : 0
                ];
            }
        }

        // ✅ 7) teammember(tm_status=1) -> exresultddata(rd_u_ID_b IN team_u_ID)：抓預期成果
        $expectedByTeam = []; // [team_ID] => list
        foreach ($teamIDs as $tid) {

            $members = fetchAll(query("
            SELECT team_u_ID
            FROM teammember
            WHERE team_ID = {$tid}
              AND tm_status = 1
        "));

            $uIDs = array_values(array_unique(array_filter(array_map(fn($m) => $m["team_u_ID"] ?? "", $members))));
            if (empty($uIDs)) {
                $expectedByTeam[$tid] = [];
                continue;
            }

            $uIDsSql = "'" . implode("','", array_map(fn($x) => addslashes($x), $uIDs)) . "'";

            $exRows = fetchAll(query("
                    SELECT rd_title, rd_content, rd_status
                    FROM exresultddata
                    WHERE rd_u_ID_b IN ({$uIDsSql})
                    AND rd_status <> 0
                "));

            $expectedByTeam[$tid] = [];
            foreach ($exRows as $ex) {
                $expectedByTeam[$tid][] = [
                    "rd_title" => $ex["rd_title"] ?? "",
                    "rd_content" => $ex["rd_content"] ?? "",
                    "rd_status" => isset($ex["rd_status"]) ? (int)$ex["rd_status"] : 0
                ];
            }
        }

        // ✅ 8) 組成丟給 AI 的 JSON（需求以類組去重：groups 只出現一次）
        $aiInput = [
            "deadline" => $submitD,
            "groups" => [], // 類組需求（不重複）
            "teams" => []   // 每隊資料
        ];

        foreach ($groupIDs as $gid) {
            $aiInput["groups"][] = [
                "group_ID" => $gid,
                "group_name" => $groupInfoMap[$gid] ?? "",
                "requirements" => $reqByGroup[$gid] ?? []
            ];
        }

        foreach ($teamIDs as $tid) {
            $tinfo = $teamInfoMap[$tid];
            $gid = $tinfo["group_ID"];
            $reqList = $reqByGroup[$gid] ?? [];

            // 該隊每個 req 的回報（缺資料也要補空）
            $rpList = [];
            foreach ($reqList as $r) {
                $rid = (int)$r["req_ID"];
                $rp = $rpByTeam[$tid][$rid] ?? ["rp_count" => null, "rp_comment" => "", "rp_status" => 0];
                $rpList[] = [
                    "req_ID" => $rid,
                    "rp_count" => $rp["rp_count"],
                    "rp_comment" => $rp["rp_comment"],
                    "rp_status" => $rp["rp_status"]
                ];
            }

            $aiInput["teams"][] = [
                "team_ID" => $tid,
                "team_project_name" => $tinfo["team_project_name"],
                "group_ID" => $gid,
                "group_name" => $tinfo["group_name"],
                "requirement_progress" => $rpList,
                "expected_outcomes" => $expectedByTeam[$tid] ?? []
            ];
        }

        // ✅ 9) 呼叫 OpenAI
        $env = parse_ini_file(__DIR__ . '/../tung_API_KEY/.env');
        $apiKey = $env['OPENAI_API_KEY'];
        if (empty($apiKey)) {
            http_response_code(500);
            echo json_encode([
                "ok" => false,
                "message" => "找不到 OPENAI_API_KEY，請先設定環境變數"
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        $system = "你是一位資深專題總評審委員，負責為同一屆多組專題進行AI評估與實際名次排行。\n\n"

            . "【輸入資料說明】\n"
            . "輸入 JSON 內包含：\n"
            . "- groups：每個類組的最低需求（同類組只會出現一次）\n"
            . "- teams：每個團隊的類組、需求回報、預期成果\n\n"

            . "【評估核心】\n"
            . "1. 本專題為期一年，因此評分標準必須嚴格，不能因為完成少量功能就給高分。\n"
            . "2. 評分時應以「預期成果」作為主要評分依據，重點觀察目前實際完成了哪些功能。\n"
            . "3. 必須依照專案生命週期綜合判斷整體專案完成度，例如：需求分析、系統規劃、資料庫設計、角色權限設計、功能開發、整合測試、統計分析、成果展示與文件整理等。\n"
            . "4. 評估時不可只看單一功能是否完成，而是要判斷整體系統是否接近一個完整的專題。\n"
            . "5. 評估重點是『目前整體專案完成程度』，不是未來計畫。\n\n"

            . "【評估原則】\n"
            . "1. 最低需求只作為基本門檻判斷，只要 requirement_progress.rp_status=3 即視為完成，不需根據回報內容品質加減分。\n"
            . "2. 主要評估重點是預期成果的內容、功能數量、功能完整度、功能深度與整體系統整合程度。\n"
            . "3. 功能數量多不代表專題完整，必須判斷功能之間是否形成一個完整系統。\n"
            . "4. 必須思考：目前的功能是否足以構成一個完整專題？是否還缺少關鍵功能？是否只是零散的小功能？\n"
            . "5. 即使所有已登記的預期成果都完成，也必須評估：是否真的只需要這些功能？是否仍有合理且必要的功能尚未發想或實作？\n"
            . "6. 若發現功能設計過於簡單、範圍過小、或無法支撐完整專題，分數不可給高。\n"
            . "7. 要特別注意避免學生『全部打勾』或『填寫很多項目但實際功能很少』的情況，若功能描述看似很多但實際內容不足，分數必須保守。\n"
            . "8. 若內容多為規劃而缺乏實作，score 不可過高。\n"
            . "9. 分數必須保留進步空間，禁止給 100 分；最高分只能到 99 分。\n"
            . "10. 給分必須嚴謹，不可過度寬鬆。\n"
            . "11. 所有專案都仍有進步空間，因此 suggestion 一定要提供具體建議。\n"
            . "12. suggestion 必須指出目前專題缺少哪些重要功能、哪些地方需要補強、或有哪些合理的功能可以進一步擴充。\n\n"

            . "【狀態定義】\n"
            . "- requirement_progress.rp_status：0=未完成，1=進行中，2=待審核，3=已完成。\n"
            . "- expected_outcomes.rd_status：0=未完成，1=進行中，2=待審核，3=已完成。\n"
            . "- 對最低專題要求而言，只需根據 rp_status 判斷是否完成，不需深究 rp_count 或 rp_comment 的內容品質。\n"
            . "- 若 rp_status=3，代表該最低需求已完成，不得在 suggestion 中再次要求『從零開始完成』該項。\n\n"

            . "【輸出格式（嚴格）】\n"
            . "只輸出純 JSON 陣列（禁止使用 ``` 或 ```json 包住）。第一個字元必須是 [。\n"
            . "格式：[{},{},...]\n"

            . "每個元素必須包含：\n"
            . "- team_ID\n"
            . "- score（0~99整數，禁止出現100，且評分需保守）\n"
            . "- rank（1開始，依整體專案完成度排序，不可跳號不可重複）\n"
            . "- suggestion（1~500字，必填，具體可執行，需指出可補強或新增的功能方向）\n";

        $payload = [
            "model" => "gpt-4o-mini",
            "instructions" => $system,
            "input" => json_encode($aiInput, JSON_UNESCAPED_UNICODE),
            "temperature" => 0.2,
            "store" => false
        ];
        file_put_contents("debug.log", print_r($aiInput, true), FILE_APPEND);
        $ch = curl_init("https://api.openai.com/v1/responses");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer " . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            http_response_code(500);
            echo json_encode([
                "ok" => false,
                "message" => "cURL 呼叫失敗：" . $curlErr
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        $json = json_decode($raw, true);

        if ($httpCode >= 400) {
            echo json_encode([
                "ok" => false,
                "message" => "OpenAI API 回傳錯誤",
                "http_code" => $httpCode,
                "error" => $json["error"] ?? $raw
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        // Responses API 文字回覆通常在 output_text
        $answer = "";
        if (!empty($json["output"]) && is_array($json["output"])) {
            foreach ($json["output"] as $item) {
                if (($item["type"] ?? "") === "message" && !empty($item["content"]) && is_array($item["content"])) {
                    foreach ($item["content"] as $c) {
                        if (($c["type"] ?? "") === "output_text") {
                            $answer .= ($c["text"] ?? "");
                        }
                    }
                }
            }
        }

        $answer = trim($answer);

        /* ===== 寫入資料庫 ===== */

        $clean = trim($answer);

        // 把 ```json 或 ``` 開頭/結尾去掉（容錯：有時候是 ```json、有時是 ```）
        $clean = preg_replace('/^\s*```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```\s*$/', '', $clean);

        $resultArr = json_decode($clean, true);

        if (!is_array($resultArr)) {
            echo json_encode([
                "ok" => false,
                "message" => "AI 回傳格式錯誤，無法解析 JSON",
                "raw" => $answer,
                "clean" => $clean,
                "json_error" => json_last_error_msg()
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        foreach ($resultArr as $rowAI) {

            $teamID  = (int)($rowAI["team_ID"] ?? 0);
            $score   = (int)($rowAI["score"] ?? 0);
            $rank    = (int)($rowAI["rank"] ?? 0);
            $suggest = addslashes($rowAI["suggestion"] ?? "");

            if ($teamID <= 0) continue;

            query("
            UPDATE exresultdata
            SET 
                sfd_score = {$score},
                sfd_rank = {$rank},
                sfd_suggest = '{$suggest}'
            WHERE sfd_team_ID = {$teamID}
              AND sfd_submit_d = '{$submitD}'
              AND sfd_score IS NULL
        ");
        }

        echo json_encode([
            "ok" => true,
            "deadline" => $submitD,
            "updated_count" => count($resultArr)
        ], JSON_UNESCAPED_UNICODE);

        file_put_contents("debug.log", "\nANSWER=" . $answer . "\n", FILE_APPEND);
        break;
    case "get_AI_score":
        echo json_encode(fetchAll(query("
            SELECT 
                e.*,
                c.total_teams
            FROM exresultdata e
            JOIN (
                SELECT sfd_submit_d, COUNT(*) AS total_teams
                FROM exresultdata
                GROUP BY sfd_submit_d
            ) c ON c.sfd_submit_d = e.sfd_submit_d
            WHERE e.sfd_team_ID = {$p["team_ID"]}
            ORDER BY e.sfd_submit_d ASC
        ")), JSON_UNESCAPED_UNICODE);
        break;

    case "backEditExpected":
        query("UPDATE `exresultddata` SET `rd_status` = '2' WHERE `exresultddata`.`rd_ID` = {$_POST["rd_ID"]};");
        break;
}
