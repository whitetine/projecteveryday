<?php
/**
 * 指導老師線上評分 API（僅限 role_ID = 4 指導老師）
 */
session_start();
// 避免 PHP Notice/Warning 輸出 HTML 導致前端 JSON 解析失敗
ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . "/../includes/pdo.php";
header("Content-Type: application/json; charset=utf-8");
date_default_timezone_set("Asia/Taipei");

$role_ID = $_SESSION["role_ID"] ?? null;
$u_ID = $_SESSION["u_ID"] ?? null;

if (!isset($u_ID) || (int)$role_ID !== 4) {
    echo json_encode(["success" => false, "msg" => "無權限，僅限指導老師使用"]);
    exit;
}

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$action = $_GET["action"] ?? $_POST["action"] ?? "";

function respond($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ========== listCohorts：啟用中的屆別 ========== */
if ($action === "listCohorts") {
    try {
        $sql = "SELECT cohort_ID, cohort_name FROM cohortdata WHERE cohort_status = 1 ORDER BY cohort_ID DESC";
        $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        respond(["success" => true, "data" => $rows]);
    } catch (Throwable $e) {
        respond(["success" => false, "msg" => "取得屆別失敗"]);
    }
}

/* ========== getTeamSuggestHistory：該團隊的 suggest 歷次建議（只顯示「該次之前」的建議表，依建立順序） ========== */
if ($action === "getTeamSuggestHistory") {
    $cohort_ID = (int)($_GET["cohort_ID"] ?? 0);
    $team_ID = (int)($_GET["team_ID"] ?? 0);
    $sf_ID = (int)($_GET["sf_ID"] ?? 0);
    if (!$cohort_ID || !$team_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    try {
        // 確認團隊屬於該屆
        $stmt = $conn->prepare("SELECT team_ID FROM teamdata WHERE team_ID = ? AND cohort_ID = ? AND team_status = 1 LIMIT 1");
        $stmt->execute([$team_ID, $cohort_ID]);
        if (!$stmt->fetch()) {
            respond(["success" => false, "msg" => "團隊不存在或非該屆"]);
        }

        // 該屆建議表依「建立順序」ASC（第一次=最舊、第二次=次之…），只顯示排在「本次」之前的
        $hasSfType = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_type'")->rowCount() > 0;
        $hasSfCohort = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_cohort'")->rowCount() > 0;
        $hasSfCreated = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_created_d'")->rowCount() > 0;
        $orderCol = $hasSfCreated ? "sf.sf_created_d ASC, sf.sf_ID ASC" : "sf.sf_ID ASC";

        if ($hasSfCohort) {
            $sqlOrder = "SELECT sf.sf_ID FROM suggestfrom sf WHERE sf.sf_cohort = ?";
            if ($hasSfType) $sqlOrder .= " AND (sf.sf_type = 'review' OR sf.sf_type IS NULL)";
            $sqlOrder .= " AND sf.sf_name IS NOT NULL AND TRIM(sf.sf_name) != '' ORDER BY " . $orderCol;
            $stmtOrder = $conn->prepare($sqlOrder);
            $stmtOrder->execute([$cohort_ID]);
        } else {
            $sqlOrder = "SELECT DISTINCT sf.sf_ID FROM suggestfrom sf INNER JOIN suggest s ON sf.sf_ID = s.sf_ID INNER JOIN teamdata t ON s.team_ID = t.team_ID
                         WHERE t.cohort_ID = ? AND sf.sf_name IS NOT NULL AND TRIM(sf.sf_name) != ''";
            if ($hasSfType) $sqlOrder .= " AND (sf.sf_type = 'review' OR sf.sf_type IS NULL)";
            $sqlOrder .= " ORDER BY " . $orderCol;
            $stmtOrder = $conn->prepare($sqlOrder);
            $stmtOrder->execute([$cohort_ID]);
        }
        $orderedSfIds = [];
        while ($r = $stmtOrder->fetch(PDO::FETCH_ASSOC)) {
            $orderedSfIds[] = (int)$r["sf_ID"];
        }

        $previousSfIds = [];
        if ($sf_ID && !empty($orderedSfIds)) {
            $pos = array_search($sf_ID, $orderedSfIds);
            if ($pos !== false && $pos > 0) {
                $previousSfIds = array_slice($orderedSfIds, 0, $pos);
            }
        }

        if (empty($previousSfIds)) {
            respond(["success" => true, "data" => []]);
        }

        $placeholders = implode(",", array_fill(0, count($previousSfIds), "?"));
        $sql = "SELECT s.suggest_ID, s.suggest_comment, s.suggest_status, sf.sf_name" . ($hasSfType ? ", sf.sf_type" : "") . "
                FROM suggest s
                LEFT JOIN suggestfrom sf ON s.sf_ID = sf.sf_ID
                WHERE s.team_ID = ? AND s.suggest_status IN (1, 2, 3, 4) AND s.sf_ID IN ($placeholders)";
        if ($hasSfType) {
            $sql .= " AND (sf.sf_type = 'review' OR sf.sf_type IS NULL)";
        }
        $sql .= " ORDER BY COALESCE(sf.sf_name,''), s.suggest_d DESC, s.suggest_ID DESC";
        $stmt = $conn->prepare($sql);
        $params = array_merge([$team_ID], $previousSfIds);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $status_code = (int)($row["suggest_status"] ?? 0);
            $is_topic = $hasSfType && isset($row["sf_type"]) && $row["sf_type"] === "topic";
            $status_text = "—";
            if ($status_code == 1) $status_text = $is_topic ? "修改" : "修改後通過";
            elseif ($status_code == 2) $status_text = "不通過";
            elseif ($status_code == 3) $status_text = "通過";
            elseif ($status_code == 4) $status_text = $is_topic ? "待確認" : "修改後複評";
            $out[] = [
                "title" => $row["sf_name"] ?? "（未命名）",
                "comment" => $row["suggest_comment"] ?? "",
                "status" => $status_text,
            ];
        }
        respond(["success" => true, "data" => $out]);
    } catch (Throwable $e) {
        respond(["success" => false, "msg" => "取得歷次建議失敗"]);
    }
}

/* ========== getTeamHistorySuggestions：該團隊歷次建議（當前老師對此團隊在該屆各評分時段的建議與分數，含本次） ========== */
if ($action === "getTeamHistorySuggestions") {
    $cohort_ID = (int)($_GET["cohort_ID"] ?? 0);
    $team_ID = (int)($_GET["team_ID"] ?? 0);
    if (!$cohort_ID || !$team_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    try {
        $tbl = "pereviewdata";
        if ($conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() === 0) {
            respond(["success" => true, "data" => []]);
        }
        $suggestCol = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggest'")->rowCount() > 0 ? 'peer_suggest' : 'peer_comment';
        $hasSfCohort = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_cohort'")->rowCount() > 0;

        $sql = "SELECT pr.sf_ID, pr.score, pr.{$suggestCol} AS suggest_text, pr.created_d, sf.sf_name
                FROM {$tbl} pr
                INNER JOIN teamdata t ON t.team_ID = pr.pe_team_ID AND t.cohort_ID = ? AND t.team_status = 1 AND pr.pe_team_ID = ?
                INNER JOIN suggestfrom sf ON sf.sf_ID = pr.sf_ID AND sf.sf_name IS NOT NULL AND TRIM(sf.sf_name) != ''";
        if ($hasSfCohort) {
            $sql .= " AND sf.sf_cohort = ?";
        }
        $sql .= " WHERE pr.pe_u_ID = ? AND (pr.petarget_u_ID IS NULL OR pr.petarget_u_ID = '')
                ORDER BY sf.sf_name, pr.created_d";

        $params = [$cohort_ID, $team_ID];
        if ($hasSfCohort) {
            $params[] = $cohort_ID;
        }
        $params[] = $u_ID;
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        respond(["success" => true, "data" => $rows]);
    } catch (Throwable $e) {
        respond(["success" => false, "msg" => "取得歷次建議失敗"]);
    }
}

/* ========== listCohortSuggestions：該屆所有建議（當前老師對該屆各團隊在各評分時段的建議與分數） ========== */
if ($action === "listCohortSuggestions") {
    $cohort_ID = (int)($_GET["cohort_ID"] ?? 0);
    if (!$cohort_ID) {
        respond(["success" => false, "msg" => "請選擇屆別"]);
    }
    try {
        $tbl = "pereviewdata";
        if ($conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() === 0) {
            respond(["success" => true, "data" => []]);
        }
        $suggestCol = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggest'")->rowCount() > 0 ? 'peer_suggest' : 'peer_comment';
        $hasSfCohort = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_cohort'")->rowCount() > 0;

        $sql = "SELECT pr.sf_ID, pr.pe_team_ID AS team_ID, pr.score, pr.{$suggestCol} AS suggest_text, pr.created_d,
                       sf.sf_name, t.team_project_name
                FROM {$tbl} pr
                INNER JOIN teamdata t ON t.team_ID = pr.pe_team_ID AND t.cohort_ID = ? AND t.team_status = 1
                INNER JOIN suggestfrom sf ON sf.sf_ID = pr.sf_ID AND sf.sf_name IS NOT NULL AND TRIM(sf.sf_name) != ''";
        if ($hasSfCohort) {
            $sql .= " AND sf.sf_cohort = ?";
        }
        $sql .= " WHERE pr.pe_u_ID = ? AND (pr.petarget_u_ID IS NULL OR pr.petarget_u_ID = '')
                ORDER BY sf.sf_name, t.team_project_name, pr.created_d";

        $params = [$cohort_ID];
        if ($hasSfCohort) {
            $params[] = $cohort_ID;
        }
        $params[] = $u_ID;
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        respond(["success" => true, "data" => $rows]);
    } catch (Throwable $e) {
        respond(["success" => false, "msg" => "取得該屆建議失敗"]);
    }
}

/* ========== listSuggestForms：該屆別可評分的審查建議表（有團隊的；含時程表綁定） ========== */
if ($action === "listSuggestForms") {
    $cohort_ID = (int)($_GET["cohort_ID"] ?? 0);
    if (!$cohort_ID) {
        respond(["success" => false, "msg" => "請選擇屆別"]);
    }
    try {
        $hasSfType = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_type'")->rowCount() > 0;
        $hasSfCohort = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_cohort'")->rowCount() > 0;

        // 若 suggestfrom 有 sf_cohort 欄位，就直接以該欄位過濾屆別，
        // 這樣只要新增新的建議表（同屆、名稱不為空），就會出現在左側評分時段清單。
        if ($hasSfCohort) {
            $hasSfCreatedCol = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_created_d'")->rowCount() > 0;
            $hasSfStatus = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_status'")->rowCount() > 0;
            $orderBy = $hasSfCreatedCol ? "sf_created_d DESC, sf_ID DESC" : "sf_ID ASC";
            $sql = "SELECT sf_ID, sf_name" . ($hasSfStatus ? ", sf_status" : "") . "
                    FROM suggestfrom
                    WHERE sf_cohort = ?
                      AND sf_name IS NOT NULL AND TRIM(sf_name) != ''";
            if ($hasSfType) {
                $sql .= " AND (sf_type = 'review' OR sf_type IS NULL)";
            }
            // 只顯示 sf_status=1（開放指導老師評分）的建議表
            if ($hasSfStatus) {
                $sql .= " AND (COALESCE(sf_status, 0) = 1)";
            }
            $sql .= " ORDER BY " . $orderBy;
            $stmt = $conn->prepare($sql);
            $stmt->execute([$cohort_ID]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 若無 sf_status 欄位，則依時程表 online_scoring_open 過濾（相容舊資料）
            $hasTiCohort = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_cohort'")->rowCount() > 0;
            $hasScoringCol = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'online_scoring_open'")->rowCount() > 0;
            if (!$hasSfStatus && $hasTiCohort && $hasScoringCol) {
                $stmtTi = $conn->prepare("SELECT tinforma_title, online_scoring_open FROM timeinformadata WHERE tinforma_cohort = ?");
                $stmtTi->execute([$cohort_ID]);
                $closedSuggestNames = [];
                while ($ti = $stmtTi->fetch(PDO::FETCH_ASSOC)) {
                    if (isset($ti["online_scoring_open"]) && (int)$ti["online_scoring_open"] === 0) {
                        $norm = trim(str_replace(["程序表", "時程表"], "結果", $ti["tinforma_title"] ?? ""));
                        if ($norm !== "") {
                            $closedSuggestNames[$norm] = true;
                        }
                    }
                }
                if (!empty($closedSuggestNames)) {
                    $rows = array_values(array_filter($rows, function ($r) use ($closedSuggestNames) {
                        return empty($closedSuggestNames[trim($r["sf_name"] ?? "")]);
                    }));
                }
            }
            // 該老師是否已送出此評分時段（送出後頁面變唯讀）
            $hasSubmitTable = $conn->query("SHOW TABLES LIKE 'teacher_scoring_submit'")->rowCount() > 0;
            if (!$hasSubmitTable) {
                try {
                    $conn->exec("CREATE TABLE IF NOT EXISTS teacher_scoring_submit (sf_ID INT NOT NULL, u_ID VARCHAR(25) NOT NULL, submitted_d DATETIME DEFAULT NULL, PRIMARY KEY (sf_ID, u_ID))");
                } catch (Throwable $e) { /* ignore */ }
            }
            $submittedSfIds = [];
            if ($u_ID) {
                try {
                    $st = $conn->prepare("SELECT sf_ID FROM teacher_scoring_submit WHERE u_ID = ?");
                    $st->execute([$u_ID]);
                    $submittedSfIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                } catch (Throwable $e) { /* ignore */ }
            }
            foreach ($rows as &$r) {
                $r["submitted_by_me"] = in_array((int)$r["sf_ID"], $submittedSfIds);
            }
            unset($r);

            respond(["success" => true, "data" => $rows]);
        }

        // 舊版資料表沒有 sf_cohort 欄位時，沿用原本的 join teamdata/suggest 邏輯
        $hasTinformaCol = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_tinforma_ID'")->rowCount() > 0;
        $hasSfCreatedCol = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_created_d'")->rowCount() > 0;
        $hasSfStatusLegacy = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_status'")->rowCount() > 0;
        $orderBy = $hasSfCreatedCol ? "sf.sf_created_d DESC, sf.sf_ID DESC" : "sf.sf_ID ASC";
        $sql = "SELECT DISTINCT sf.sf_ID, sf.sf_name
                FROM suggestfrom sf
                JOIN suggest s ON sf.sf_ID = s.sf_ID
                JOIN teamdata t ON s.team_ID = t.team_ID
                WHERE t.cohort_ID = ? AND t.team_status = 1
                  AND s.suggest_status IN (1, 2, 3, 4)
                  AND sf.sf_name IS NOT NULL AND TRIM(sf.sf_name) != ''";
        if ($hasSfType) {
            $sql .= " AND (sf.sf_type = 'review' OR sf.sf_type IS NULL)";
        }
        if ($hasSfStatusLegacy) {
            $sql .= " AND (COALESCE(sf.sf_status, 0) = 1)";
        }
        $sql .= " ORDER BY " . $orderBy;
        $stmt = $conn->prepare($sql);
        $stmt->execute([$cohort_ID]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $seen = [];
        foreach ($rows as $r) {
            $seen[(int)$r["sf_ID"]] = true;
        }
        if ($hasTinformaCol) {
            $sql2 = "SELECT DISTINCT sf.sf_ID, sf.sf_name
                     FROM suggestfrom sf
                     INNER JOIN timedata td ON td.tinforma_ID = sf.sf_tinforma_ID AND sf.sf_tinforma_ID IS NOT NULL
                     INNER JOIN teamdata t ON td.team_ID = t.team_ID
                     WHERE t.cohort_ID = ? AND t.team_status = 1
                       AND sf.sf_name IS NOT NULL AND TRIM(sf.sf_name) != ''";
            if ($hasSfType) {
                $sql2 .= " AND (sf.sf_type = 'review' OR sf.sf_type IS NULL)";
            }
            if ($hasSfStatusLegacy) {
                $sql2 .= " AND (COALESCE(sf.sf_status, 0) = 1)";
            }
            $sql2 .= " ORDER BY " . $orderBy;
            $stmt2 = $conn->prepare($sql2);
            $stmt2->execute([$cohort_ID]);
            while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)$r["sf_ID"];
                if (empty($seen[$id])) {
                    $seen[$id] = true;
                    $rows[] = $r;
                }
                }
            }
            $submittedSfIds = [];
            if ($u_ID) {
                try {
                    $conn->exec("CREATE TABLE IF NOT EXISTS teacher_scoring_submit (sf_ID INT NOT NULL, u_ID VARCHAR(25) NOT NULL, submitted_d DATETIME DEFAULT NULL, PRIMARY KEY (sf_ID, u_ID))");
                    $st = $conn->prepare("SELECT sf_ID FROM teacher_scoring_submit WHERE u_ID = ?");
                    $st->execute([$u_ID]);
                    $submittedSfIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                } catch (Throwable $e) { /* ignore */ }
            }
            foreach ($rows as &$r) {
                $r["submitted_by_me"] = in_array((int)$r["sf_ID"], $submittedSfIds);
            }
            unset($r);
        respond(["success" => true, "data" => $rows]);
    } catch (Throwable $e) {
        respond(["success" => false, "msg" => "取得建議表失敗"]);
    }
}

/* ========== submitTeacherScoring：指導老師送出評分，通知召集人，該時段變唯讀 ========== */
if ($action === "submitTeacherScoring") {
    $sf_ID = (int)($_POST["sf_ID"] ?? 0);
    if (!$sf_ID) {
        respond(["success" => false, "msg" => "缺少 sf_ID"]);
    }
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS teacher_scoring_submit (sf_ID INT NOT NULL, u_ID VARCHAR(25) NOT NULL, submitted_d DATETIME DEFAULT NULL, PRIMARY KEY (sf_ID, u_ID))");
        $stmt = $conn->prepare("INSERT INTO teacher_scoring_submit (sf_ID, u_ID, submitted_d) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE submitted_d = NOW()");
        $stmt->execute([$sf_ID, $u_ID]);

        $row = $conn->prepare("SELECT sf_name FROM suggestfrom WHERE sf_ID = ? LIMIT 1");
        $row->execute([$sf_ID]);
        $sf = $row->fetch(PDO::FETCH_ASSOC);
        $title = $sf ? $sf["sf_name"] : "評分時段";

        $msgTitle = "指導老師已送出評分：{$title}";
        $msgContent = "指導老師已完成「{$title}」的評分並送出，請前往審查建議表填寫建議。";
        $suggestUrl = "pages/suggest.php?sf_ID={$sf_ID}";
        $urlData = [["type" => "link", "url" => $suggestUrl, "label" => "查看"]];
        $msg_url = json_encode($urlData, JSON_UNESCAPED_UNICODE);

        $ins = $conn->prepare("INSERT INTO msgdata (msg_title, msg_content, msg_url, msg_type, msg_a_u_ID, msg_status, msg_start_d, msg_created_d) VALUES (?, ?, ?, 'SYSTEM_NOTICE', ?, 1, NOW(), NOW())");
        $ins->execute([$msgTitle, $msgContent, $msg_url, $u_ID]);
        $msg_ID = (int)$conn->lastInsertId();
        if ($msg_ID) {
            $convenerStmt = $conn->query("SELECT ur_u_ID FROM userrolesdata WHERE role_ID = 7 AND user_role_status = 1");
            $convenerUsers = $convenerStmt->fetchAll(PDO::FETCH_COLUMN);
            $targetIns = $conn->prepare("INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID) VALUES (?, 'USER', ?)");
            foreach ($convenerUsers as $cu) {
                if (trim($cu) !== "" && $cu !== $u_ID) {
                    $targetIns->execute([$msg_ID, $cu]);
                }
            }
        }

        respond(["success" => true, "msg" => "已送出，已通知召集人"]);
    } catch (Throwable $e) {
        error_log("submitTeacherScoring 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "送出失敗：" . $e->getMessage()]);
    }
}

/* ========== getSubmissionPeriod：繳交時段（開始時間最接近現在的一筆，截止=結束+1天） ========== */
if ($action === "getSubmissionPeriod") {
    $sf_ID = (int)($_GET["sf_ID"] ?? 0);
    $cohort_ID = (int)($_GET["cohort_ID"] ?? 0);
    if (!$sf_ID || !$cohort_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    try {
        // 1. 先從 suggestfrom 讀取此評分表的建立時間，作為關聯依據
        $hasSfCreatedCol = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_created_d'")->rowCount() > 0;
        $selectFields = "sf_name" . ($hasSfCreatedCol ? ", sf_created_d" : "");
        $stmt = $conn->prepare("SELECT {$selectFields} FROM suggestfrom WHERE sf_ID = ? LIMIT 1");
        $stmt->execute([$sf_ID]);
        $sfRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sfRow) {
            respond(["success" => true, "data" => null]);
        }

        $sf_name = $sfRow["sf_name"] ?? "";
        $title = $sf_name;
        $tinforma_ID = null;

        // 1.5 優先以「建議表名稱」對應時程表標題，使不同建議表顯示正確的時段時間
        $hasTiCohortCol = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_cohort'")->rowCount() > 0;
        if (!empty(trim($sf_name))) {
            if ($hasTiCohortCol) {
                $stmtTi = $conn->prepare("SELECT tinforma_ID, tinforma_title FROM timeinformadata WHERE tinforma_cohort = ? AND tinforma_title = ? LIMIT 1");
                $stmtTi->execute([$cohort_ID, trim($sf_name)]);
            } else {
                $stmtTi = $conn->prepare("SELECT tinforma_ID, tinforma_title FROM timeinformadata WHERE tinforma_title = ? LIMIT 1");
                $stmtTi->execute([trim($sf_name)]);
            }
            $rowTi = $stmtTi->fetch(PDO::FETCH_ASSOC);
            if (!$rowTi && $hasTiCohortCol) {
                // 若完全相符找不到，改為「建議表名稱以時程表標題開頭」取最長符合（如 1141專題 對 1141專題期中複評審查結果）
                $stmtTi = $conn->prepare("SELECT tinforma_ID, tinforma_title FROM timeinformadata WHERE tinforma_cohort = ? AND ? LIKE CONCAT(tinforma_title, '%') ORDER BY LENGTH(tinforma_title) DESC LIMIT 1");
                $stmtTi->execute([$cohort_ID, trim($sf_name)]);
                $rowTi = $stmtTi->fetch(PDO::FETCH_ASSOC);
            }
            if ($rowTi) {
                $tinforma_ID = (int)$rowTi["tinforma_ID"];
                if (!empty($rowTi["tinforma_title"])) $title = $rowTi["tinforma_title"];
            }
        }

        // 2. 若尚未取得 tinforma_ID，改依「建議表順序」對應「時程表順序」（第 i 個建議表對第 i 個時程表），避免不同時段顯示相同時間
        if (!$tinforma_ID && $hasTiCohortCol) {
            $hasSfCohort = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_cohort'")->rowCount() > 0;
            $hasSfType = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_type'")->rowCount() > 0;
            $sfOrderCol = $hasSfCreatedCol ? "sf_created_d ASC, sf_ID ASC" : "sf_ID ASC";
            if ($hasSfCohort) {
                $sqlSf = "SELECT sf_ID FROM suggestfrom WHERE sf_cohort = ? AND sf_name IS NOT NULL AND TRIM(sf_name) != ''";
                if ($hasSfType) $sqlSf .= " AND (sf_type = 'review' OR sf_type IS NULL)";
                $sqlSf .= " ORDER BY " . $sfOrderCol;
                $stmtSfList = $conn->prepare($sqlSf);
                $stmtSfList->execute([$cohort_ID]);
            } else {
                $sqlSf = "SELECT DISTINCT sf.sf_ID FROM suggestfrom sf INNER JOIN suggest s ON sf.sf_ID = s.sf_ID INNER JOIN teamdata t ON s.team_ID = t.team_ID WHERE t.cohort_ID = ? AND sf.sf_name IS NOT NULL AND TRIM(sf.sf_name) != ''";
                if ($hasSfType) $sqlSf .= " AND (sf.sf_type = 'review' OR sf.sf_type IS NULL)";
                $sqlSf .= " ORDER BY " . str_replace("sf_ID", "sf.sf_ID", str_replace("sf_created_d", "sf.sf_created_d", $sfOrderCol));
                $stmtSfList = $conn->prepare($sqlSf);
                $stmtSfList->execute([$cohort_ID]);
            }
            $sfIdsOrdered = [];
            while ($rowSf = $stmtSfList->fetch(PDO::FETCH_ASSOC)) {
                $sfIdsOrdered[] = (int)$rowSf["sf_ID"];
            }
            $hasTiCreate = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_create_d'")->rowCount() > 0;
            $sqlTi = "SELECT tinforma_ID, tinforma_title FROM timeinformadata WHERE tinforma_cohort = ? ORDER BY " . ($hasTiCreate ? "tinforma_create_d ASC" : "tinforma_ID ASC");
            $stmtTiList = $conn->prepare($sqlTi);
            $stmtTiList->execute([$cohort_ID]);
            $tiIdsOrdered = [];
            while ($rowTi = $stmtTiList->fetch(PDO::FETCH_ASSOC)) {
                $tiIdsOrdered[] = ["id" => (int)$rowTi["tinforma_ID"], "title" => $rowTi["tinforma_title"] ?? ""];
            }
            $idx = array_search($sf_ID, $sfIdsOrdered);
            if ($idx !== false && !empty($tiIdsOrdered)) {
                $useIdx = min($idx, count($tiIdsOrdered) - 1);
                $tinforma_ID = $tiIdsOrdered[$useIdx]["id"];
                if (!empty($tiIdsOrdered[$useIdx]["title"])) $title = $tiIdsOrdered[$useIdx]["title"];
            }
        }

        // 2b. 若仍無 tinforma_ID，依「建立時間最接近」從 timeinformadata 尋找（單一建議表時或順序對應失敗時）
        if (!$tinforma_ID && $hasSfCreatedCol && !empty($sfRow["sf_created_d"])) {
            $sfCreated = $sfRow["sf_created_d"];
            $hasTiCohortCol = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_cohort'")->rowCount() > 0;

            if ($hasTiCohortCol) {
                $sqlTi = "SELECT tinforma_ID, tinforma_title
                          FROM timeinformadata
                          WHERE tinforma_cohort = ?
                          ORDER BY ABS(TIMESTAMPDIFF(SECOND, tinforma_create_d, ?))
                          LIMIT 1";
                $stmtTi = $conn->prepare($sqlTi);
                $stmtTi->execute([$cohort_ID, $sfCreated]);
            } else {
                $sqlTi = "SELECT tinforma_ID, tinforma_title
                          FROM timeinformadata
                          ORDER BY ABS(TIMESTAMPDIFF(SECOND, tinforma_create_d, ?))
                          LIMIT 1";
                $stmtTi = $conn->prepare($sqlTi);
                $stmtTi->execute([$sfCreated]);
            }

            $rowTi = $stmtTi->fetch(PDO::FETCH_ASSOC);
            if ($rowTi) {
                $tinforma_ID = (int)$rowTi["tinforma_ID"];
                if (!empty($rowTi["tinforma_title"])) {
                    $title = $rowTi["tinforma_title"];
                }
            }
        }

        // 3. 若因為欄位不存在或沒有對到資料，則仍舊使用舊有的綁定/標題邏輯作為備援
        if (!$tinforma_ID) {
            $hasTinformaCol = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_tinforma_ID'")->rowCount() > 0;
            if ($hasTinformaCol) {
                $stmtSf = $conn->prepare("SELECT sf_tinforma_ID, sf_name FROM suggestfrom WHERE sf_ID = ? LIMIT 1");
                $stmtSf->execute([$sf_ID]);
                $rowSf = $stmtSf->fetch(PDO::FETCH_ASSOC);
                if ($rowSf && !empty($rowSf["sf_tinforma_ID"])) {
                    $tinforma_ID = (int)$rowSf["sf_tinforma_ID"];
                }
                if (empty($title)) {
                    $title = $rowSf["sf_name"] ?? $title;
                }
            }

            if (!$tinforma_ID && !empty($sf_name)) {
                $hasCohortCol = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_cohort'")->rowCount() > 0;
                if ($hasCohortCol) {
                    $stmtTi = $conn->prepare("SELECT tinforma_ID FROM timeinformadata WHERE tinforma_title = ? AND tinforma_cohort = ? LIMIT 1");
                    $stmtTi->execute([trim($sf_name), $cohort_ID]);
                    $rowTi = $stmtTi->fetch(PDO::FETCH_ASSOC);
                    if ($rowTi) {
                        $tinforma_ID = (int)$rowTi["tinforma_ID"];
                    }
                }
                if (!$tinforma_ID) {
                    $stmtTi = $conn->prepare("SELECT tinforma_ID FROM timeinformadata WHERE tinforma_title = ? LIMIT 1");
                    $stmtTi->execute([trim($sf_name)]);
                    $rowTi = $stmtTi->fetch(PDO::FETCH_ASSOC);
                    if ($rowTi) {
                        $tinforma_ID = (int)$rowTi["tinforma_ID"];
                    }
                }
            }
        }

        if (!$tinforma_ID) {
            respond(["success" => true, "data" => null]);
        }

        // 4. 從 timedata 取出此 tinforma_ID 之「第一筆開始時間」
        $sql = "SELECT MIN(time_start_d) AS first_start
                FROM timedata
                WHERE tinforma_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$tinforma_ID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            respond(["success" => true, "data" => null]);
        }

        // 開始時間 = 第一筆 time_start_d；截止時間 = 開始時間「當天」的 23:59
        $time_start_d = $row["first_start"] ?? null;
        $time_end_d = null;
        if ($time_start_d) {
            $dt = new DateTime($time_start_d);
            $dt->setTime(23, 59, 59);
            $time_end_d = $dt->format("Y-m-d H:i:s");
        }

        $now = new DateTime("now");
        $startDt = $time_start_d ? new DateTime($time_start_d) : null;
        $endDt = $time_end_d ? new DateTime($time_end_d) : null;
        $status = "未開始";
        if ($startDt && $endDt) {
            if ($now < $startDt) {
                $status = "未開始";
            } elseif ($now >= $startDt && $now <= $endDt) {
                $status = "進行中";
            } else {
                $status = "已結束";
            }
        }
        $formatDisplay = function ($s) {
            if (!$s) return "";
            try {
                $d = new DateTime($s);
                return $d->format("Y/m/d H:i");
            } catch (Exception $e) {
                return $s;
            }
        };
        respond([
            "success" => true,
            "data" => [
                "title" => $title ?: "評分時段",
                "time_start_d" => $time_start_d,
                "time_end_d" => $time_end_d,
                "time_end_plus1" => $time_end_d,
                "time_start_display" => $formatDisplay($time_start_d),
                "time_end_plus1_display" => $formatDisplay($time_end_d),
                "status" => $status
            ]
        ]);
    } catch (Throwable $e) {
        respond(["success" => false, "msg" => "取得繳交時段失敗"]);
    }
}

/* ========== listTeams：該建議表內的團隊列表（依時程表 sort_no 排序） ========== */
if ($action === "listTeams") {
    $sf_ID = (int)($_GET["sf_ID"] ?? 0);
    $cohort_ID = (int)($_GET["cohort_ID"] ?? 0);
    if (!$sf_ID || !$cohort_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    try {
        // 1. 先從 suggestfrom 讀取基本資訊（名稱、建立時間、是否有直接綁定 tinforma）
        $hasTinformaCol = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_tinforma_ID'")->rowCount() > 0;
        $hasSfCreatedCol = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_created_d'")->rowCount() > 0;
        $selectFields = "sf_name"
            . ($hasTinformaCol ? ", sf_tinforma_ID" : "")
            . ($hasSfCreatedCol ? ", sf_created_d" : "");

        $stmt = $conn->prepare("SELECT {$selectFields} FROM suggestfrom WHERE sf_ID = ? LIMIT 1");
        $stmt->execute([$sf_ID]);
        $sfRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sfRow) {
            respond(["success" => true, "data" => []]);
        }

        $sf_name = $sfRow["sf_name"] ?? "";
        $tinforma_ID = null;

        // 2. 優先使用 sf_tinforma_ID 綁定
        if ($hasTinformaCol && !empty($sfRow["sf_tinforma_ID"])) {
            $tinforma_ID = (int)$sfRow["sf_tinforma_ID"];
        }

        // 3. 若尚未取得 tinforma_ID，改用「建立時間最接近」的方式從 timeinformadata 尋找
        if (!$tinforma_ID && $hasSfCreatedCol && !empty($sfRow["sf_created_d"])) {
            $sfCreated = $sfRow["sf_created_d"];
            $hasTiCohortCol = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_cohort'")->rowCount() > 0;

            if ($hasTiCohortCol) {
                $sqlTi = "SELECT tinforma_ID
                          FROM timeinformadata
                          WHERE tinforma_cohort = ?
                          ORDER BY ABS(TIMESTAMPDIFF(SECOND, tinforma_create_d, ?))
                          LIMIT 1";
                $stmtTi = $conn->prepare($sqlTi);
                $stmtTi->execute([$cohort_ID, $sfCreated]);
            } else {
                $sqlTi = "SELECT tinforma_ID
                          FROM timeinformadata
                          ORDER BY ABS(TIMESTAMPDIFF(SECOND, tinforma_create_d, ?))
                          LIMIT 1";
                $stmtTi = $conn->prepare($sqlTi);
                $stmtTi->execute([$sfCreated]);
            }

            $rowTi = $stmtTi->fetch(PDO::FETCH_ASSOC);
            if ($rowTi) {
                $tinforma_ID = (int)$rowTi["tinforma_ID"];
            }
        }

        // 4. 仍未對到 tinforma_ID 時，以標題（tinforma_title）作為最後備援
        if (!$tinforma_ID && !empty($sf_name)) {
            $hasCohortCol = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_cohort'")->rowCount() > 0;
            if ($hasCohortCol) {
                $stmtTi = $conn->prepare("SELECT tinforma_ID FROM timeinformadata WHERE tinforma_title = ? AND tinforma_cohort = ? LIMIT 1");
                $stmtTi->execute([trim($sf_name), $cohort_ID]);
                $rowTi = $stmtTi->fetch(PDO::FETCH_ASSOC);
                if ($rowTi) {
                    $tinforma_ID = (int)$rowTi["tinforma_ID"];
                }
            }
            if (!$tinforma_ID) {
                $stmtTi = $conn->prepare("SELECT tinforma_ID FROM timeinformadata WHERE tinforma_title = ? LIMIT 1");
                $stmtTi->execute([trim($sf_name)]);
                $rowTi = $stmtTi->fetch(PDO::FETCH_ASSOC);
                if ($rowTi) {
                    $tinforma_ID = (int)$rowTi["tinforma_ID"];
                }
            }
        }

        $rows = [];

        // 5. 若已成功找到 tinforma_ID，則依該時程表在 timedata 中找出團隊（依 sort_no 排序）
        if ($tinforma_ID) {
            $sql = "SELECT t.team_ID, t.team_project_name, g.group_name, td.sort_no as time_sort_no
                    FROM timedata td
                    JOIN teamdata t ON td.team_ID = t.team_ID
                    LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                    WHERE td.tinforma_ID = ? AND t.cohort_ID = ? AND t.team_status = 1
                    GROUP BY t.team_ID, t.team_project_name, g.group_name, td.sort_no
                    ORDER BY td.sort_no ASC, t.team_ID";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$tinforma_ID, $cohort_ID]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 6. 若仍然沒有任何團隊（例如時程表尚未建立），則退回列出該屆別的所有啟用中團隊
        if (!$rows) {
            $sql = "SELECT t.team_ID, t.team_project_name, g.group_name
                    FROM teamdata t
                    LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                    WHERE t.cohort_ID = ? AND t.team_status = 1
                    ORDER BY g.group_ID, t.team_ID";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$cohort_ID]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        respond(["success" => true, "data" => $rows]);
    } catch (Throwable $e) {
        respond(["success" => false, "msg" => "取得團隊列表失敗"]);
    }
}

/* ========== getMyReview：取得當前老師對某團隊的評分與建議 ========== */
if ($action === "getMyReview") {
    $sf_ID = (int)($_GET["sf_ID"] ?? 0);
    $team_ID = (int)($_GET["team_ID"] ?? 0);
    if (!$sf_ID || !$team_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    try {
        $tbl = "pereviewdata";
        if ($conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() === 0) {
            respond(["success" => true, "data" => null]);
        }
        $suggestCol = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggest'")->rowCount() > 0 ? 'peer_suggest' : 'peer_comment';
        $stmt = $conn->prepare("
            SELECT score, {$suggestCol} AS suggest_text, created_d
            FROM {$tbl}
            WHERE sf_ID = ? AND pe_team_ID = ? AND pe_u_ID = ? AND (petarget_u_ID IS NULL OR petarget_u_ID = '')
            LIMIT 1
        ");
        $stmt->execute([$sf_ID, $team_ID, $u_ID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        respond(["success" => true, "data" => $row ?: null]);
    } catch (Throwable $e) {
        respond(["success" => false, "msg" => "取得評分失敗"]);
    }
}

/* ========== saveMyReview：儲存當前老師對某團隊的評分與建議 ========== */
if ($action === "saveMyReview") {
    $sf_ID = (int)($_POST["sf_ID"] ?? 0);
    $team_ID = (int)($_POST["team_ID"] ?? 0);
    $score = isset($_POST["score"]) && $_POST["score"] !== "" ? (int)$_POST["score"] : null;
    $suggest_text = trim($_POST["suggest_text"] ?? "") ?: null;
    if (!$sf_ID || !$team_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    try {
        $tbl = "pereviewdata";
        if ($conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() === 0) {
            respond(["success" => false, "msg" => "資料表不存在"]);
        }
        $suggestCol = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggest'")->rowCount() > 0 ? 'peer_suggest' : 'peer_comment';
        $sel = $conn->prepare("SELECT peer_ID FROM {$tbl} WHERE sf_ID = ? AND pe_team_ID = ? AND pe_u_ID = ? AND (petarget_u_ID IS NULL OR petarget_u_ID = '') LIMIT 1");
        $sel->execute([$sf_ID, $team_ID, $u_ID]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $conn->prepare("UPDATE {$tbl} SET score = ?, {$suggestCol} = ?, created_d = NOW() WHERE peer_ID = ?")
                ->execute([$score, $suggest_text, $row["peer_ID"]]);
        } else {
            $conn->prepare("INSERT INTO {$tbl} (sf_ID, pe_team_ID, pe_u_ID, petarget_u_ID, score, {$suggestCol}, created_d) VALUES (?, ?, ?, NULL, ?, ?, NOW())")
                ->execute([$sf_ID, $team_ID, $u_ID, $score, $suggest_text]);
        }
        respond(["success" => true, "msg" => "已儲存"]);
    } catch (Throwable $e) {
        respond(["success" => false, "msg" => "儲存失敗"]);
    }
}

/* ========== listTeamStudents：團隊內學生 ========== */
if ($action === "listTeamStudents") {
    $team_ID = (int)($_GET["team_ID"] ?? 0);
    if (!$team_ID) {
        respond(["success" => false, "msg" => "缺少 team_ID"]);
    }
    try {
        $teamUserField = "team_u_ID";
        if ($conn->query("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'")->rowCount() === 0) {
            $teamUserField = "u_ID";
        }
        $sql = "SELECT DISTINCT tm.{$teamUserField} AS u_ID, u.u_name
                FROM teammember tm
                INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
                INNER JOIN userrolesdata ur ON ur.ur_u_ID = u.u_ID AND ur.user_role_status = 1 AND ur.role_ID = 6
                WHERE tm.team_ID = ? AND tm.tm_status = 1
                ORDER BY u.u_ID";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$team_ID]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        respond(["success" => true, "data" => $rows]);
    } catch (Throwable $e) {
        respond(["success" => false, "msg" => "取得學生列表失敗"]);
    }
}

/* ========== getMyStudentReviews：當前老師對該團隊各學生的評分 ========== */
if ($action === "getMyStudentReviews") {
    $sf_ID = (int)($_GET["sf_ID"] ?? 0);
    $team_ID = (int)($_GET["team_ID"] ?? 0);
    if (!$sf_ID || !$team_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    try {
        $tbl = "pereviewdata";
        if ($conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() === 0) {
            respond(["success" => true, "data" => []]);
        }
        $suggestCol = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggesttwo'")->rowCount() > 0 ? 'peer_suggesttwo' : 'peer_suggest';
        $stmt = $conn->prepare("
            SELECT petarget_u_ID AS student_u_ID, score, {$suggestCol} AS suggest_text
            FROM {$tbl}
            WHERE sf_ID = ? AND pe_team_ID = ? AND pe_u_ID = ? AND petarget_u_ID IS NOT NULL AND petarget_u_ID != ''
        ");
        $stmt->execute([$sf_ID, $team_ID, $u_ID]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        respond(["success" => true, "data" => $rows]);
    } catch (Throwable $e) {
        respond(["success" => false, "msg" => "取得學生評分失敗"]);
    }
}

/* ========== saveMyStudentReviews：儲存當前老師對各學生的評分 ========== */
if ($action === "saveMyStudentReviews") {
    $sf_ID = (int)($_POST["sf_ID"] ?? 0);
    $team_ID = (int)($_POST["team_ID"] ?? 0);
    $reviews_json = $_POST["reviews"] ?? "[]";
    if (!$sf_ID || !$team_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    $reviews = json_decode($reviews_json, true);
    if (!is_array($reviews)) {
        respond(["success" => false, "msg" => "資料格式錯誤"]);
    }
    try {
        $tbl = "pereviewdata";
        if ($conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() === 0) {
            respond(["success" => false, "msg" => "資料表不存在"]);
        }
        $suggestCol = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggesttwo'")->rowCount() > 0 ? 'peer_suggesttwo' : 'peer_suggest';
        foreach ($reviews as $r) {
            $student_u_ID = trim($r["student_u_ID"] ?? "");
            if ($student_u_ID === "") continue;
            $score = isset($r["score"]) && $r["score"] !== "" ? (int)$r["score"] : null;
            $suggest_text = trim($r["suggest_text"] ?? "") ?: null;
            if ($score === null && $suggest_text === null) continue;
            $sel = $conn->prepare("SELECT peer_ID FROM {$tbl} WHERE sf_ID = ? AND pe_team_ID = ? AND pe_u_ID = ? AND petarget_u_ID = ? LIMIT 1");
            $sel->execute([$sf_ID, $team_ID, $u_ID, $student_u_ID]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $conn->prepare("UPDATE {$tbl} SET score = ?, {$suggestCol} = ?, created_d = NOW() WHERE peer_ID = ?")
                    ->execute([$score, $suggest_text, $row["peer_ID"]]);
            } else {
                $conn->prepare("INSERT INTO {$tbl} (sf_ID, pe_team_ID, pe_u_ID, petarget_u_ID, score, {$suggestCol}, created_d) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                    ->execute([$sf_ID, $team_ID, $u_ID, $student_u_ID, $score, $suggest_text]);
            }
        }
        respond(["success" => true, "msg" => "已儲存"]);
    } catch (Throwable $e) {
        respond(["success" => false, "msg" => "儲存失敗"]);
    }
}

respond(["success" => false, "msg" => "未知的 action"]);
