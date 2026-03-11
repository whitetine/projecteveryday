<?php
global $conn;
$p  = $_POST;
$do = $_GET['do'] ?? '';

switch ($do) {
    // 搜尋自己所屬團隊
    case 'select_team':
        $u = $_SESSION["u_ID"];
        $teamIDArray = fetchAll(query("SELECT team_ID FROM teammember WHERE u_ID = '{$u}';"));
        $t_ID = array_column($teamIDArray, 'team_ID');
        if (!$t_ID) { echo json_encode([]); break; }
        $teamIDString = implode(',', array_map('intval', $t_ID));
        $teamName = fetchAll(query("
            SELECT td.team_project_name, td.team_ID
            FROM teammember tm
            JOIN teamdata td ON tm.team_ID = td.team_ID
            WHERE tm.team_ID IN ($teamIDString)
            GROUP BY td.team_project_name;
        "));
        echo json_encode($teamName);
        break;

    // 抓啟用類組
    case 'select_group':
        echo json_encode(fetchAll(query("SELECT * FROM groupdata WHERE group_status=1;")));
        break;

    // 新增進度（沿用你原本的欄位名稱）
    case 'new_progress_all':
        $count = [];
        if (isset($p["count_one"], $p["count_two"], $p["count_three"])) {
            for ($i = 0; $i < count($p["count_one"]); $i++) {
                $one = $p["count_one"][$i] ?? '';
                $two = $p["count_two"][$i] ?? '';
                $three = $p["count_three"][$i] ?? '';
                if ($one !== '' || $two !== '' || $three !== '') $count[] = [$one, $two, $three];
            }
        }
        $count_json = json_encode($count, JSON_UNESCAPED_UNICODE);
        query("INSERT INTO progressdata
              (progress_ID, group_ID, progress_title, progress_describe, progress_count, u_ID, progress_status, progress_created_d, progress_end_d)
              VALUES (NULL, '{$p["ID"]}', '{$p["title"]}', '{$p["describe"]}', '{$count_json}', '{$_SESSION["u_ID"]}', '1', CURRENT_TIMESTAMP, '{$p["deadline"]}')");
        // 原本沒有回傳，這裡也不多做輸出
        break;
}
