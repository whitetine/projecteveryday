<?php
session_start();
require "../includes/pdo.php";
header("Content-Type: application/json; charset=utf-8");

date_default_timezone_set("Asia/Taipei");

/* ==========================================
   權限：主任 (role_ID = 1)、科辦 (role_ID = 2) 和 召集人 (role_ID = 7)
========================================== */
$role_ID = $_SESSION["role_ID"] ?? null;
if (!isset($role_ID) || !in_array($role_ID, [1, 2, 7])) {
    echo json_encode(["success" => false, "msg" => "無權限"]);
    exit;
}

$u_ID = $_SESSION["u_ID"];
$action = $_GET["action"] ?? $_POST["action"] ?? "";

/* 回傳格式統一 */
function respond($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

/* PDO 錯誤 */
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * 檢查：若為召集人且該建議表已送交科辦(sf_sent_to_office=1)，或該召集人已發布過，則不允許再編輯
 */
function is_convener_form_locked(PDO $conn, $role_ID, $sf_ID, $u_ID = null) {
    if ((int)$role_ID !== 7 || !$sf_ID) {
        return false;
    }
    try {
        $hasCol = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_sent_to_office'")->rowCount() > 0;
        if (!$hasCol) {
            return false;
        }
        $stmt = $conn->prepare("SELECT sf_sent_to_office FROM suggestfrom WHERE sf_ID = ? LIMIT 1");
        $stmt->execute([$sf_ID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $val = trim((string)($row["sf_sent_to_office"] ?? ""));
        if ($val === "1") {
            return true;
        }
        if ($u_ID !== null) {
            $hasSentTable = $conn->query("SHOW TABLES LIKE 'suggest_convener_sent'")->rowCount() > 0;
            if ($hasSentTable) {
                $chk = $conn->prepare("SELECT 1 FROM suggest_convener_sent WHERE sf_ID = ? AND u_ID = ? LIMIT 1");
                $chk->execute([$sf_ID, $u_ID]);
                if ($chk->fetch()) {
                    return true;
                }
            }
        }
        return false;
    } catch (Throwable $e) {
        error_log("is_convener_form_locked 檢查失敗: " . $e->getMessage());
        return false;
    }
}

/**
 * 取得當屆召集人 u_ID 列表（enrollmentdata：cohort_ID + role_ID=7 + enroll_status=1）
 */
function get_cohort_convener_u_ids(PDO $conn, $cohort_ID) {
    if (!$cohort_ID) {
        return [];
    }
    try {
        $hasRole = $conn->query("SHOW COLUMNS FROM enrollmentdata LIKE 'role_ID'")->rowCount() > 0;
        $hasStatus = $conn->query("SHOW COLUMNS FROM enrollmentdata LIKE 'enroll_status'")->rowCount() > 0;
        if (!$hasRole) {
            return [];
        }
        $sql = "SELECT DISTINCT enroll_u_ID FROM enrollmentdata WHERE cohort_ID = ? AND role_ID = 7";
        if ($hasStatus) {
            $sql .= " AND enroll_status = 1";
        }
        $stmt = $conn->prepare($sql);
        $stmt->execute([$cohort_ID]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log("get_cohort_convener_u_ids 失敗: " . $e->getMessage());
        return [];
    }
}

/**
 * 召集人是否可編輯此建議表：須在當屆召集人名單且尚未送交（或未全部送交且本人未送交）
 * 回傳 ["ok"=>true] 或 ["ok"=>false, "msg"=>"原因"]
 */
function convener_can_edit_suggest_form(PDO $conn, $sf_ID, $u_ID) {
    if (!$sf_ID || $u_ID === null || $u_ID === '') {
        return ["ok" => false, "msg" => "參數錯誤"];
    }
    try {
        $stmt = $conn->prepare("SELECT sf_cohort FROM suggestfrom WHERE sf_ID = ? LIMIT 1");
        $stmt->execute([$sf_ID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ["ok" => false, "msg" => "找不到建議表"];
        }
        $cohort = isset($row["sf_cohort"]) && $row["sf_cohort"] !== null && $row["sf_cohort"] !== '' ? (int)$row["sf_cohort"] : null;
        if ($cohort === null) {
            return ["ok" => true];
        }
        $conveners = get_cohort_convener_u_ids($conn, $cohort);
        if (!in_array($u_ID, $conveners, true)) {
            return ["ok" => false, "msg" => "您不在當屆召集人名單中，無法填寫。"];
        }
        if (is_convener_form_locked($conn, 7, $sf_ID, $u_ID)) {
            return ["ok" => false, "msg" => "此建議表已送交科辦或您已送交過，召集人僅可查看，不能再編輯。"];
        }
        return ["ok" => true];
    } catch (Throwable $e) {
        error_log("convener_can_edit_suggest_form 失敗: " . $e->getMessage());
        return ["ok" => false, "msg" => "權限檢查失敗"];
    }
}

/**
 * 取得召集人所屬類組 ID（userrolesdata.ur_group_ID）
 * 僅當 role_ID=7 且該使用者有 ur_group_ID 時回傳，否則回傳 null。
 * 用於限制系統組召集人只看到系統軟體開發組、商管組召集人只看到商務網站經營組。
 */
function get_convener_group_id(PDO $conn, $u_ID) {
    if (!$u_ID) {
        return null;
    }
    try {
        $hasCol = $conn->query("SHOW COLUMNS FROM userrolesdata LIKE 'ur_group_ID'")->rowCount() > 0;
        if (!$hasCol) {
            return null;
        }
        $stmt = $conn->prepare("SELECT ur_group_ID FROM userrolesdata WHERE ur_u_ID = ? AND role_ID = 7 AND user_role_status = 1 LIMIT 1");
        $stmt->execute([$u_ID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $v = isset($row["ur_group_ID"]) ? $row["ur_group_ID"] : null;
        return ($v === null || $v === '') ? null : (int)$v;
    } catch (Throwable $e) {
        error_log("get_convener_group_id 失敗: " . $e->getMessage());
        return null;
    }
}

/* ==========================================
   函式：整理多行文字 → 多筆建議
========================================== */
function normalize_multi_line($text) {
    $lines = preg_split("/\r\n|\r|\n/", $text);
    $result = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === "") continue;

        // 只剩數字 / 點 / 空白 → 略過
        if (preg_match('/^[0-9\.\s]+$/', $line)) continue;

        // 去掉前面編號 1. 2 3) ...
        $line = preg_replace('/^\s*\d+[\.\、\)\:]\s*/u', '', $line);

        if ($line === "") continue;

        // 結尾沒有標點 → 自動補 「。」
        $last = mb_substr($line, -1);
        if (!in_array($last, ["。", ".", "?", "？", "！", "!"])) {
            $line .= "。";
        }

        $result[] = $line;
    }

    return $result;  // 回傳陣列，每個是「一筆建議」
}

/* ==========================================
   action: listCohorts
   取得啟用中屆別
========================================== */
if ($action === "listCohorts") {

    $sql = "SELECT cohort_ID, cohort_name
            FROM cohortdata
            WHERE cohort_status = 1
            ORDER BY cohort_ID DESC";

    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    respond(["success" => true, "data" => $rows]);
}

/* ==========================================
   action: listGroups
   取得該屆的類組列表
========================================== */
if ($action === "listGroups") {

    $cohort_ID = $_GET["cohort_ID"] ?? 0;
    
    if (!$cohort_ID) {
        respond(["success" => false, "msg" => "缺少屆別參數"]);
    }

    $sql = "SELECT DISTINCT 
                g.group_ID,
                g.group_name
            FROM groupdata g
            JOIN teamdata t ON t.group_ID = g.group_ID
            WHERE t.cohort_ID = ?
              AND t.team_status = 1
              AND g.group_status = 1";
    $params = [$cohort_ID];
    // 召集人（role_ID=7）僅能看見其所屬類組：系統組(ur_group_ID=1) 或 商管組(ur_group_ID=2)
    if ((int)$role_ID === 7) {
        $convener_group_id = get_convener_group_id($conn, $u_ID);
        if ($convener_group_id !== null) {
            $sql .= " AND g.group_ID = ?";
            $params[] = $convener_group_id;
        }
    }
    $sql .= " ORDER BY g.group_ID";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond(["success" => true, "data" => $rows]);
}

/* ==========================================
   action: listTypes
   取得啟用中的類型列表
========================================== */
if ($action === "listTypes") {

    $sql = "SELECT type_ID, type_value
            FROM typedata
            WHERE type_status = 1
            ORDER BY type_ID";

    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    respond(["success" => true, "data" => $rows]);
}

/* ==========================================
   action: listCohortTeachers
   取得該屆別所有指導老師（role_ID=4）
   權限：主任、科辦、召集人
========================================== */
if ($action === "listCohortTeachers") {
    $cohort_ID = (int)($_GET["cohort_ID"] ?? 0);
    if (!$cohort_ID) {
        respond(["success" => false, "msg" => "缺少屆別參數"]);
    }
    try {
        $sql = "
            SELECT DISTINCT u.u_ID, u.u_name
            FROM userdata u
            JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID
            WHERE e.cohort_ID = ?
              AND e.role_ID = 4
              AND e.enroll_status = 1
            ORDER BY u.u_name ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$cohort_ID]);
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        respond(["success" => true, "data" => $teachers]);
    } catch (Throwable $e) {
        error_log("listCohortTeachers 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "取得指導老師列表失敗"]);
    }
}

/* ==========================================
   action: getTeacherReviews
   取得某組別在建議表下，各老師的評分與建議（從 pereviewdata，peer_suggest = 組別建議）
========================================== */
if ($action === "getTeacherReviews") {
    $sf_ID = (int)($_GET["sf_ID"] ?? 0);
    $team_ID = (int)($_GET["team_ID"] ?? 0);
    if (!$sf_ID || !$team_ID) {
        respond(["success" => false, "msg" => "缺少 sf_ID 或 team_ID"]);
    }
    try {
        $tbl = "pereviewdata";
        $exists = $conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() > 0;
        if (!$exists) {
            respond(["success" => true, "data" => []]);
        }
        $hasSfId = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'sf_ID'")->rowCount() > 0;
        $hasPeTeamId = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'pe_team_ID'")->rowCount() > 0;
        if (!$hasSfId || !$hasPeTeamId) {
            respond(["success" => true, "data" => []]);
        }
        $suggestCol = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggest'")->rowCount() > 0 ? 'peer_suggest' : 'peer_comment';
        $stmt = $conn->prepare("
            SELECT p.pe_u_ID AS teacher_u_ID, p.score, p.{$suggestCol} AS suggest_text, p.created_d,
                   COALESCE(u.u_name, p.pe_u_ID) AS teacher_name
            FROM {$tbl} p
            LEFT JOIN userdata u ON u.u_ID = p.pe_u_ID
            WHERE p.sf_ID = ? AND p.pe_team_ID = ? AND (p.petarget_u_ID IS NULL OR p.petarget_u_ID = '')
            ORDER BY p.pe_u_ID
        ");
        $stmt->execute([$sf_ID, $team_ID]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        respond(["success" => true, "data" => $rows]);
    } catch (Throwable $e) {
        error_log("getTeacherReviews 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "取得老師評分失敗"]);
    }
}

/* ==========================================
   action: getTeacherReviewsBatch
   一次取得多個組別在建議表下各老師的評分與建議，回傳 { "team_ID": [ {...}, ... ], ... }
========================================== */
if ($action === "getTeacherReviewsBatch") {
    $sf_ID = (int)($_GET["sf_ID"] ?? 0);
    $team_IDs_raw = $_GET["team_IDs"] ?? "";
    if (!$sf_ID) {
        respond(["success" => false, "msg" => "缺少 sf_ID"]);
    }
    $team_IDs = array_filter(array_map("intval", preg_split('/[\s,]+/', $team_IDs_raw, -1, PREG_SPLIT_NO_EMPTY)));
    if (count($team_IDs) === 0) {
        respond(["success" => true, "data" => (object)[]]);
    }
    try {
        $tbl = "pereviewdata";
        $exists = $conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() > 0;
        if (!$exists) {
            respond(["success" => true, "data" => (object)[]]);
        }
        $hasSfId = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'sf_ID'")->rowCount() > 0;
        $hasPeTeamId = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'pe_team_ID'")->rowCount() > 0;
        if (!$hasSfId || !$hasPeTeamId) {
            respond(["success" => true, "data" => (object)[]]);
        }
        $suggestCol = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggest'")->rowCount() > 0 ? 'peer_suggest' : 'peer_comment';
        $stmt = $conn->prepare("
            SELECT p.pe_team_ID AS team_ID, p.pe_u_ID AS teacher_u_ID, p.score, p.{$suggestCol} AS suggest_text, p.created_d,
                   COALESCE(u.u_name, p.pe_u_ID) AS teacher_name
            FROM {$tbl} p
            LEFT JOIN userdata u ON u.u_ID = p.pe_u_ID
            WHERE p.sf_ID = ? AND p.pe_team_ID IN (" . implode(",", array_fill(0, count($team_IDs), "?")) . ") AND (p.petarget_u_ID IS NULL OR p.petarget_u_ID = '')
            ORDER BY p.pe_team_ID, p.pe_u_ID
        ");
        $stmt->execute(array_merge([$sf_ID], $team_IDs));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byTeam = [];
        foreach ($team_IDs as $tid) {
            $byTeam[(string)$tid] = [];
        }
        foreach ($rows as $r) {
            $tid = (string)($r["team_ID"] ?? "");
            unset($r["team_ID"]);
            if ($tid !== "" && isset($byTeam[$tid])) {
                $byTeam[$tid][] = $r;
            }
        }
        respond(["success" => true, "data" => $byTeam]);
    } catch (Throwable $e) {
        error_log("getTeacherReviewsBatch 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "取得老師評分失敗"]);
    }
}

/* ==========================================
   action: getTeacherReviewsWithStudents
   取得某組別的「指導老師對團隊的建議」+「個別學生的建議」，供下拉表格顯示
   回傳 { team: [ { teacher_name, score, suggest_text }, ... ], students: [ { teacher_name, student_name, score, suggest_text }, ... ] }
========================================== */
if ($action === "getTeacherReviewsWithStudents") {
    $sf_ID = (int)($_GET["sf_ID"] ?? 0);
    $team_ID = (int)($_GET["team_ID"] ?? 0);
    if (!$sf_ID || !$team_ID) {
        respond(["success" => false, "msg" => "缺少 sf_ID 或 team_ID"]);
    }
    try {
        $tbl = "pereviewdata";
        $exists = $conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() > 0;
        if (!$exists) {
            respond(["success" => true, "data" => ["team" => [], "students" => []]]);
        }
        $hasSfId = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'sf_ID'")->rowCount() > 0;
        $hasPeTeamId = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'pe_team_ID'")->rowCount() > 0;
        $hasPetarget = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'petarget_u_ID'")->rowCount() > 0;
        if (!$hasSfId || !$hasPeTeamId) {
            respond(["success" => true, "data" => ["team" => [], "students" => []]]);
        }
        $teamCol = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggest'")->rowCount() > 0 ? 'peer_suggest' : 'peer_comment';
        $studentCol = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggesttwo'")->rowCount() > 0 ? 'peer_suggesttwo' : $teamCol;

        // 指導老師對團隊的建議（petarget_u_ID 為空）
        $stmtTeam = $conn->prepare("
            SELECT p.pe_u_ID AS teacher_u_ID, p.score, p.{$teamCol} AS suggest_text,
                   COALESCE(u.u_name, p.pe_u_ID) AS teacher_name
            FROM {$tbl} p
            LEFT JOIN userdata u ON u.u_ID = p.pe_u_ID
            WHERE p.sf_ID = ? AND p.pe_team_ID = ? AND (p.petarget_u_ID IS NULL OR p.petarget_u_ID = '')
            ORDER BY p.pe_u_ID
        ");
        $stmtTeam->execute([$sf_ID, $team_ID]);
        $teamRows = $stmtTeam->fetchAll(PDO::FETCH_ASSOC);
        $teamList = [];
        foreach ($teamRows as $r) {
            if ((isset($r["suggest_text"]) && trim($r["suggest_text"] ?? '') !== '') || (isset($r["score"]) && $r["score"] !== null && $r["score"] !== '')) {
                $teamList[] = [
                    "teacher_name" => $r["teacher_name"] ?? $r["teacher_u_ID"] ?? '',
                    "score" => $r["score"],
                    "suggest_text" => isset($r["suggest_text"]) ? trim($r["suggest_text"]) : ''
                ];
            }
        }

        $studentList = [];
        if ($hasPetarget) {
            $stmtStu = $conn->prepare("
                SELECT p.pe_u_ID AS teacher_u_ID, p.petarget_u_ID AS student_u_ID, p.score, p.{$studentCol} AS suggest_text,
                       COALESCE(ut.u_name, p.pe_u_ID) AS teacher_name,
                       COALESCE(us.u_name, p.petarget_u_ID) AS student_name
                FROM {$tbl} p
                LEFT JOIN userdata ut ON ut.u_ID = p.pe_u_ID
                LEFT JOIN userdata us ON us.u_ID = p.petarget_u_ID
                WHERE p.sf_ID = ? AND p.pe_team_ID = ? AND p.petarget_u_ID IS NOT NULL AND p.petarget_u_ID != ''
                ORDER BY p.pe_u_ID, p.petarget_u_ID
            ");
            $stmtStu->execute([$sf_ID, $team_ID]);
            $stuRows = $stmtStu->fetchAll(PDO::FETCH_ASSOC);
            foreach ($stuRows as $r) {
                if ((isset($r["suggest_text"]) && trim($r["suggest_text"] ?? '') !== '') || (isset($r["score"]) && $r["score"] !== null && $r["score"] !== '')) {
                    $studentList[] = [
                        "teacher_name" => $r["teacher_name"] ?? $r["teacher_u_ID"] ?? '',
                        "student_name" => $r["student_name"] ?? $r["student_u_ID"] ?? '',
                        "score" => $r["score"],
                        "suggest_text" => isset($r["suggest_text"]) ? trim($r["suggest_text"]) : ''
                    ];
                }
            }
        }
        respond(["success" => true, "data" => ["team" => $teamList, "students" => $studentList]]);
    } catch (Throwable $e) {
        error_log("getTeacherReviewsWithStudents 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "取得建議失敗"]);
    }
}

/* ==========================================
   action: saveTeacherReviews
   儲存各老師對某組別的評分與建議（科辦/召集人代為輸入），寫入 pereviewdata
   peer_suggest = 組別建議
========================================== */
if ($action === "saveTeacherReviews") {
    $sf_ID = (int)($_POST["sf_ID"] ?? 0);
    $team_ID = (int)($_POST["team_ID"] ?? 0);
    $reviews_json = $_POST["reviews"] ?? "[]";
    if (!$sf_ID || !$team_ID) {
        respond(["success" => false, "msg" => "缺少 sf_ID 或 team_ID"]);
    }
    $reviews = json_decode($reviews_json, true);
    if (!is_array($reviews)) {
        respond(["success" => false, "msg" => "資料格式錯誤"]);
    }
    try {
        $tbl = "pereviewdata";
        $exists = $conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() > 0;
        if (!$exists) {
            respond(["success" => false, "msg" => "資料表 pereviewdata 不存在"]);
        }
        $hasSfId = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'sf_ID'")->rowCount() > 0;
        $hasPeTeamId = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'pe_team_ID'")->rowCount() > 0;
        if (!$hasSfId || !$hasPeTeamId) {
            respond(["success" => false, "msg" => "pereviewdata 缺少 sf_ID 或 pe_team_ID 欄位"]);
        }
        $suggestCol = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggest'")->rowCount() > 0 ? 'peer_suggest' : 'peer_comment';
        foreach ($reviews as $r) {
            $teacher_u_ID = trim($r["teacher_u_ID"] ?? "");
            if ($teacher_u_ID === "") continue;
            $score = isset($r["score"]) && $r["score"] !== "" ? (int)$r["score"] : null;
            $suggest_text = trim($r["suggest_text"] ?? "") ?: null;
            $sel = $conn->prepare("SELECT peer_ID FROM {$tbl} WHERE sf_ID = ? AND pe_team_ID = ? AND pe_u_ID = ? AND (petarget_u_ID IS NULL OR petarget_u_ID = '') LIMIT 1");
            $sel->execute([$sf_ID, $team_ID, $teacher_u_ID]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $conn->prepare("UPDATE {$tbl} SET score = ?, {$suggestCol} = ?, created_d = NOW() WHERE peer_ID = ?")
                    ->execute([$score, $suggest_text, $row["peer_ID"]]);
            } else {
                $conn->prepare("INSERT INTO {$tbl} (sf_ID, pe_team_ID, pe_u_ID, petarget_u_ID, score, {$suggestCol}, created_d) VALUES (?, ?, ?, NULL, ?, ?, NOW())")
                    ->execute([$sf_ID, $team_ID, $teacher_u_ID, $score, $suggest_text]);
            }
        }
        respond(["success" => true, "msg" => "已儲存"]);
    } catch (Throwable $e) {
        error_log("saveTeacherReviews 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "儲存失敗：" . $e->getMessage()]);
    }
}

/* ==========================================
   action: listTeamStudents
   取得組別內所有學生（role_ID=6）
========================================== */
if ($action === "listTeamStudents") {
    $team_ID = (int)($_GET["team_ID"] ?? 0);
    if (!$team_ID) {
        respond(["success" => false, "msg" => "缺少 team_ID"]);
    }
    try {
        $teamUserField = $conn->query("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'")->rowCount() > 0 ? 'team_u_ID' : 'u_ID';
        $sql = "
            SELECT DISTINCT u.u_ID, u.u_name
            FROM teammember tm
            JOIN userdata u ON u.u_ID = tm.{$teamUserField}
            JOIN teamdata t ON t.team_ID = tm.team_ID
            JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField} AND e.cohort_ID = t.cohort_ID AND e.enroll_status = 1
            WHERE tm.team_ID = ? AND tm.tm_status = 1
              AND e.role_ID = 6
            ORDER BY u.u_name ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$team_ID]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        respond(["success" => true, "data" => $students]);
    } catch (Throwable $e) {
        error_log("listTeamStudents 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "取得組別學生失敗"]);
    }
}

/* ==========================================
   action: getStudentReviews
   取得某組別、某老師對各學生的評分與建議（從 pereviewdata，peer_suggesttwo = 個別學生建議）
   若未傳 teacher_u_ID 則回傳該組別所有老師的學生評分（keyed by teacher_u_ID）
========================================== */
if ($action === "getStudentReviews") {
    $sf_ID = (int)($_GET["sf_ID"] ?? 0);
    $team_ID = (int)($_GET["team_ID"] ?? 0);
    $teacher_u_ID = trim($_GET["teacher_u_ID"] ?? "");
    if (!$sf_ID || !$team_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    try {
        $tbl = "pereviewdata";
        $exists = $conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() > 0;
        if (!$exists) {
            respond(["success" => true, "data" => []]);
        }
        $hasSfId = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'sf_ID'")->rowCount() > 0;
        $hasPeTeamId = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'pe_team_ID'")->rowCount() > 0;
        $hasPetarget = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'petarget_u_ID'")->rowCount() > 0;
        if (!$hasSfId || !$hasPeTeamId || !$hasPetarget) {
            respond(["success" => true, "data" => $teacher_u_ID !== "" ? [] : (object)[]]);
        }
        $suggestCol = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggesttwo'")->rowCount() > 0 ? 'peer_suggesttwo' : ($conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggest'")->rowCount() > 0 ? 'peer_suggest' : 'peer_comment');
        if ($teacher_u_ID !== "") {
            $stmt = $conn->prepare("
                SELECT petarget_u_ID AS student_u_ID, score, {$suggestCol} AS suggest_text
                FROM {$tbl}
                WHERE sf_ID = ? AND pe_team_ID = ? AND pe_u_ID = ? AND petarget_u_ID IS NOT NULL AND petarget_u_ID != ''
            ");
            $stmt->execute([$sf_ID, $team_ID, $teacher_u_ID]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            respond(["success" => true, "data" => $rows]);
        } else {
            $stmt = $conn->prepare("
                SELECT pe_u_ID AS teacher_u_ID, petarget_u_ID AS student_u_ID, score, {$suggestCol} AS suggest_text
                FROM {$tbl}
                WHERE sf_ID = ? AND pe_team_ID = ? AND petarget_u_ID IS NOT NULL AND petarget_u_ID != ''
            ");
            $stmt->execute([$sf_ID, $team_ID]);
            $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $byTeacher = [];
            foreach ($all as $r) {
                $tid = $r["teacher_u_ID"];
                if (!isset($byTeacher[$tid])) $byTeacher[$tid] = [];
                $byTeacher[$tid][] = ["student_u_ID" => $r["student_u_ID"], "score" => $r["score"], "suggest_text" => $r["suggest_text"] ?? ""];
            }
            respond(["success" => true, "data" => $byTeacher]);
        }
    } catch (Throwable $e) {
        error_log("getStudentReviews 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "取得學生評分失敗"]);
    }
}

/* ==========================================
   action: saveStudentReviews
   儲存老師對各學生的評分與建議（僅有輸入才存入），寫入 pereviewdata
   peer_suggesttwo = 個別學生建議
========================================== */
if ($action === "saveStudentReviews") {
    $sf_ID = (int)($_POST["sf_ID"] ?? 0);
    $team_ID = (int)($_POST["team_ID"] ?? 0);
    $teacher_u_ID = trim($_POST["teacher_u_ID"] ?? "");
    $reviews_json = $_POST["reviews"] ?? "[]";
    if (!$sf_ID || !$team_ID || $teacher_u_ID === "") {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    $reviews = json_decode($reviews_json, true);
    if (!is_array($reviews)) {
        respond(["success" => false, "msg" => "資料格式錯誤"]);
    }
    try {
        $tbl = "pereviewdata";
        $exists = $conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() > 0;
        if (!$exists) {
            respond(["success" => false, "msg" => "資料表 pereviewdata 不存在"]);
        }
        $hasSfId = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'sf_ID'")->rowCount() > 0;
        $hasPeTeamId = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'pe_team_ID'")->rowCount() > 0;
        $hasPetarget = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'petarget_u_ID'")->rowCount() > 0;
        if (!$hasSfId || !$hasPeTeamId || !$hasPetarget) {
            respond(["success" => false, "msg" => "pereviewdata 缺少 sf_ID、pe_team_ID 或 petarget_u_ID 欄位"]);
        }
        $suggestCol = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggesttwo'")->rowCount() > 0 ? 'peer_suggesttwo' : ($conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'peer_suggest'")->rowCount() > 0 ? 'peer_suggest' : 'peer_comment');
        foreach ($reviews as $r) {
            $student_u_ID = trim($r["student_u_ID"] ?? "");
            if ($student_u_ID === "") continue;
            $score = isset($r["score"]) && $r["score"] !== "" ? (int)$r["score"] : null;
            $suggest_text = trim($r["suggest_text"] ?? "") ?: null;
            if ($score === null && $suggest_text === null) continue;
            $sel = $conn->prepare("SELECT peer_ID FROM {$tbl} WHERE sf_ID = ? AND pe_team_ID = ? AND pe_u_ID = ? AND petarget_u_ID = ? LIMIT 1");
            $sel->execute([$sf_ID, $team_ID, $teacher_u_ID, $student_u_ID]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $conn->prepare("UPDATE {$tbl} SET score = ?, {$suggestCol} = ?, created_d = NOW() WHERE peer_ID = ?")
                    ->execute([$score, $suggest_text, $row["peer_ID"]]);
            } else {
                $conn->prepare("INSERT INTO {$tbl} (sf_ID, pe_team_ID, pe_u_ID, petarget_u_ID, score, {$suggestCol}, created_d) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                    ->execute([$sf_ID, $team_ID, $teacher_u_ID, $student_u_ID, $score, $suggest_text]);
            }
        }
        respond(["success" => true, "msg" => "已儲存"]);
    } catch (Throwable $e) {
        error_log("saveStudentReviews 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "儲存失敗：" . $e->getMessage()]);
    }
}

/* ==========================================
   action: getSuggestFormInfo
   依 sf_ID 回傳建議表類型與綁定時程（供前端依 sf_type 顯示/隱藏審查欄位、是否綁 tinforma）
========================================== */
if ($action === "getSuggestFormInfo") {
    $sf_ID = $_GET["sf_ID"] ?? 0;
    if (!$sf_ID) {
        respond(["success" => false, "msg" => "缺少 sf_ID"]);
    }
    try {
        $hasSfType = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_type'")->rowCount() > 0;
        $hasTinforma = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_tinforma_ID'")->rowCount() > 0;
        $hasSfCohort = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_cohort'")->rowCount() > 0;
        $hasSentToOffice = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_sent_to_office'")->rowCount() > 0;
        $cols = "sf_ID, sf_name";
        if ($hasSfType) $cols .= ", sf_type";
        if ($hasTinforma) $cols .= ", sf_tinforma_ID";
        if ($hasSfCohort) $cols .= ", sf_cohort";
        if ($hasSentToOffice) $cols .= ", sf_sent_to_office";
        $stmt = $conn->prepare("SELECT {$cols} FROM suggestfrom WHERE sf_ID = ? LIMIT 1");
        $stmt->execute([$sf_ID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            respond(["success" => false, "msg" => "找不到該建議表"]);
        }
        $sf_type = isset($row["sf_type"]) && $row["sf_type"] !== '' ? $row["sf_type"] : "review";
        $tinforma_ID = ($hasTinforma && isset($row["sf_tinforma_ID"]) && $row["sf_tinforma_ID"] !== null && $row["sf_tinforma_ID"] !== '') ? (int)$row["sf_tinforma_ID"] : null;
        $sf_cohort = ($hasSfCohort && isset($row["sf_cohort"]) && $row["sf_cohort"] !== null && $row["sf_cohort"] !== '') ? (int)$row["sf_cohort"] : null;
        $sent_to_office_raw = ($hasSentToOffice && isset($row["sf_sent_to_office"])) ? (string)$row["sf_sent_to_office"] : "0";
        $sent_to_office = trim($sent_to_office_raw) === "" ? "0" : $sent_to_office_raw;
        $out = [
            "sf_ID" => (int)$row["sf_ID"],
            "sf_name" => $row["sf_name"],
            "sf_type" => $sf_type,
            "tinforma_ID" => $tinforma_ID,
            "sf_sent_to_office" => $sent_to_office
        ];
        if ($sf_cohort !== null) $out["sf_cohort"] = $sf_cohort;
        if ((int)$role_ID === 7 && $sf_cohort !== null) {
            $conveners = get_cohort_convener_u_ids($conn, $sf_cohort);
            $out["is_convener_in_cohort"] = in_array($u_ID, $conveners, true);
            $hasSentTable = $conn->query("SHOW TABLES LIKE 'suggest_convener_sent'")->rowCount() > 0;
            $out["convener_has_sent"] = false;
            if ($hasSentTable) {
                $chk = $conn->prepare("SELECT 1 FROM suggest_convener_sent WHERE sf_ID = ? AND u_ID = ? LIMIT 1");
                $chk->execute([$sf_ID, $u_ID]);
                $out["convener_has_sent"] = (bool)$chk->fetch();
            }
        }
        respond(["success" => true, "data" => $out]);
    } catch (Throwable $e) {
        error_log("getSuggestFormInfo 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "取得建議表資訊失敗"]);
    }
}

/* ==========================================
   action: getTeamCohort
   取得組別所屬屆別（供評分彈窗依「建議表屆別」或「組別屆別」顯示當屆指導老師）
========================================== */
if ($action === "getTeamCohort") {
    $team_ID = (int)($_GET["team_ID"] ?? 0);
    if (!$team_ID) {
        respond(["success" => false, "msg" => "缺少 team_ID"]);
    }
    try {
        $stmt = $conn->prepare("SELECT cohort_ID FROM teamdata WHERE team_ID = ? AND team_status = 1 LIMIT 1");
        $stmt->execute([$team_ID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row["cohort_ID"])) {
            respond(["success" => true, "data" => ["cohort_ID" => null]]);
        }
        respond(["success" => true, "data" => ["cohort_ID" => (int)$row["cohort_ID"]]]);
    } catch (Throwable $e) {
        respond(["success" => false, "msg" => "取得組別屆別失敗"]);
    }
}

/* ==========================================
   action: listTeams
   取得該屆和類組的組別。依 suggestfrom.sf_type 決定是否回傳時程 sort_no：review 必綁 tinforma，topic 不綁。
   系統不得以是否存在時程表來判斷類型。
========================================== */
if ($action === "listTeams") {

    $cohort_ID = $_GET["cohort_ID"] ?? 0;
    $group_ID = $_GET["group_ID"] ?? 0;
    $title = $_GET["title"] ?? "";
    $sf_ID_param = isset($_GET["sf_ID"]) ? (int)$_GET["sf_ID"] : 0;
    $from_integrate = isset($_GET["from_integrate"]) && $_GET["from_integrate"] === "1";

    if (!$cohort_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }

    // 召集人僅能看其所屬類組的組別（系統組=1 或 商管組=2，依 userrolesdata.ur_group_ID）
    $convener_group_id = null;
    if ((int)$role_ID === 7) {
        $convener_group_id = get_convener_group_id($conn, $u_ID);
    }

    $includeScheduleSortNo = false;
    $latestTinformaId = null;
    $sf_type = 'review'; // 預設審查建議表；有 sf_ID/title 時會依 suggestfrom.sf_type 覆寫

    try {
        $hasSfType = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_type'")->rowCount() > 0;
        $hasTinformaCol = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_tinforma_ID'")->rowCount() > 0;
    } catch (Throwable $e) {
        $hasSfType = false;
        $hasTinformaCol = false;
    }

    if ($sf_ID_param > 0) {
        $cols = "sf_ID";
        if ($hasSfType) $cols .= ", sf_type";
        if ($hasTinformaCol) $cols .= ", sf_tinforma_ID";
        $stmt = $conn->prepare("SELECT {$cols} FROM suggestfrom WHERE sf_ID = ? LIMIT 1");
        $stmt->execute([$sf_ID_param]);
        $sfRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($sfRow) {
            $sf_type = isset($sfRow["sf_type"]) && $sfRow["sf_type"] !== '' ? $sfRow["sf_type"] : "review";
            if ($sf_type === "review") {
                if ($hasTinformaCol && isset($sfRow["sf_tinforma_ID"]) && $sfRow["sf_tinforma_ID"] !== null && $sfRow["sf_tinforma_ID"] !== '') {
                    $latestTinformaId = (int)$sfRow["sf_tinforma_ID"];
                    $includeScheduleSortNo = true;
                } else {
                    $tinformaStmt = $conn->prepare("
                        SELECT ti.tinforma_ID FROM timeinformadata ti
                        JOIN timedata td ON ti.tinforma_ID = td.tinforma_ID
                        JOIN teamdata t ON td.team_ID = t.team_ID
                        WHERE t.cohort_ID = ?
                        ORDER BY COALESCE(ti.tinforma_update_d, ti.tinforma_create_d) DESC
                        LIMIT 1
                    ");
                    $tinformaStmt->execute([$cohort_ID]);
                    $tr = $tinformaStmt->fetch(PDO::FETCH_ASSOC);
                    if ($tr) {
                        $latestTinformaId = (int)$tr["tinforma_ID"];
                        $includeScheduleSortNo = true;
                    }
                }
            }
        }
    } elseif ($title !== '') {
        $cols = "sf_ID";
        if ($hasSfType) $cols .= ", sf_type";
        if ($hasTinformaCol) $cols .= ", sf_tinforma_ID";
        $stmt = $conn->prepare("SELECT {$cols} FROM suggestfrom WHERE sf_name = ? LIMIT 1");
        $stmt->execute([$title]);
        $sfRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($sfRow) {
            $sf_type = isset($sfRow["sf_type"]) && $sfRow["sf_type"] !== '' ? $sfRow["sf_type"] : "review";
            if ($sf_type === "review") {
                if ($hasTinformaCol && isset($sfRow["sf_tinforma_ID"]) && $sfRow["sf_tinforma_ID"] !== null && $sfRow["sf_tinforma_ID"] !== '') {
                    $latestTinformaId = (int)$sfRow["sf_tinforma_ID"];
                    $includeScheduleSortNo = true;
                } else {
                    $tinformaStmt = $conn->prepare("
                        SELECT ti.tinforma_ID FROM timeinformadata ti
                        JOIN timedata td ON ti.tinforma_ID = td.tinforma_ID
                        JOIN teamdata t ON td.team_ID = t.team_ID
                        WHERE t.cohort_ID = ?
                        ORDER BY COALESCE(ti.tinforma_update_d, ti.tinforma_create_d) DESC
                        LIMIT 1
                    ");
                    $tinformaStmt->execute([$cohort_ID]);
                    $tr = $tinformaStmt->fetch(PDO::FETCH_ASSOC);
                    if ($tr) {
                        $latestTinformaId = (int)$tr["tinforma_ID"];
                        $includeScheduleSortNo = true;
                    }
                }
            }
        }
    }

    // review 且 from_integrate：只返回該時程表中的組別；否則依 includeScheduleSortNo 決定是否帶 time_sort_no
    if ($from_integrate && $latestTinformaId) {
        // 召集人僅能看其所屬類組
        $effective_group = ($convener_group_id !== null) ? $convener_group_id : (($group_ID && $group_ID !== 'all' && $group_ID !== '0') ? $group_ID : null);
        // 從 integrate 建立之審查建議表：只返回時程表中的組別
            if (!$group_ID || $group_ID === "all" || $group_ID === "0") {
                $sql = "SELECT 
                            t.team_ID,
                            t.team_project_name,
                            g.group_name,
                            td.sort_no as time_sort_no
                        FROM timedata td
                        JOIN teamdata t ON td.team_ID = t.team_ID
                        JOIN groupdata g ON t.group_ID = g.group_ID
                        WHERE td.tinforma_ID = ?
                          AND t.cohort_ID = ?
                          AND t.team_status = 1";
                if ($effective_group !== null) {
                    $sql .= " AND t.group_ID = ?";
                }
                $sql .= "
                        GROUP BY t.team_ID, t.team_project_name, g.group_name, td.sort_no
                        ORDER BY g.group_ID, td.sort_no ASC, t.team_ID";
                
                $stmt = $conn->prepare($sql);
                if ($effective_group !== null) {
                    $stmt->execute([$latestTinformaId, $cohort_ID, $effective_group]);
                } else {
                    $stmt->execute([$latestTinformaId, $cohort_ID]);
                }
            } else {
                $sql = "SELECT 
                            t.team_ID,
                            t.team_project_name,
                            g.group_name,
                            td.sort_no as time_sort_no
                        FROM timedata td
                        JOIN teamdata t ON td.team_ID = t.team_ID
                        JOIN groupdata g ON t.group_ID = g.group_ID
                        WHERE td.tinforma_ID = ?
                          AND t.cohort_ID = ?
                          AND t.group_ID = ?
                          AND t.team_status = 1
                        GROUP BY t.team_ID, t.team_project_name, g.group_name, td.sort_no
                        ORDER BY td.sort_no ASC, t.team_ID";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([$latestTinformaId, $cohort_ID, $effective_group]);
            }
    } elseif ($from_integrate && $sf_type === 'review') {
        // 從 integrate 進入之審查建議表但找不到時程表：改走下方「依屆別/類組」回傳組別，不回傳空
        // （避免召集人看不到任何組別，仍可填寫建議）
        $latestTinformaId = null;
        $includeScheduleSortNo = false;
    }
    if (!$from_integrate || !$latestTinformaId) {
    // 非 from_integrate，或 from_integrate 但無時程表：按屆別/類組返回組別
        // 召集人僅能看其所屬類組
        $effective_group = ($convener_group_id !== null) ? $convener_group_id : (($group_ID && $group_ID !== 'all' && $group_ID !== '0') ? $group_ID : null);
        // 如果 group_ID 為空、0 或 "all"，則返回該屆所有組別
        if (!$group_ID || $group_ID === "all" || $group_ID === "0") {
            if ($includeScheduleSortNo && $latestTinformaId) {
                $sql = "SELECT 
                            t.team_ID,
                            t.team_project_name,
                            g.group_name,
                            td.sort_no as time_sort_no
                        FROM teamdata t
                        JOIN groupdata g ON t.group_ID = g.group_ID
                        LEFT JOIN timedata td ON td.team_ID = t.team_ID 
                            AND td.tinforma_ID = ?
                        WHERE t.cohort_ID = ?
                          AND t.team_status = 1";
                if ($effective_group !== null) {
                    $sql .= " AND t.group_ID = ?";
                }
                $sql .= "
                        ORDER BY g.group_ID, t.team_ID";
                
                $stmt = $conn->prepare($sql);
                if ($effective_group !== null) {
                    $stmt->execute([$latestTinformaId, $cohort_ID, $effective_group]);
                } else {
                    $stmt->execute([$latestTinformaId, $cohort_ID]);
                }
            } else {
                $sql = "SELECT 
                            t.team_ID,
                            t.team_project_name,
                            g.group_name
                        FROM teamdata t
                        JOIN groupdata g ON t.group_ID = g.group_ID
                        WHERE t.cohort_ID = ?
                          AND t.team_status = 1";
                if ($effective_group !== null) {
                    $sql .= " AND t.group_ID = ?";
                }
                $sql .= "
                        ORDER BY g.group_ID, t.team_ID";
                
                $stmt = $conn->prepare($sql);
                if ($effective_group !== null) {
                    $stmt->execute([$cohort_ID, $effective_group]);
                } else {
                    $stmt->execute([$cohort_ID]);
                }
            }
        } else {
            if ($includeScheduleSortNo && $latestTinformaId) {
                $sql = "SELECT 
                            t.team_ID,
                            t.team_project_name,
                            g.group_name,
                            td.sort_no as time_sort_no
                        FROM teamdata t
                        JOIN groupdata g ON t.group_ID = g.group_ID
                        LEFT JOIN timedata td ON td.team_ID = t.team_ID 
                            AND td.tinforma_ID = ?
                        WHERE t.cohort_ID = ?
                          AND t.group_ID = ?
                          AND t.team_status = 1
                        ORDER BY t.team_ID";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([$latestTinformaId, $cohort_ID, $effective_group]);
            } else {
                $sql = "SELECT 
                            t.team_ID,
                            t.team_project_name,
                            g.group_name
                        FROM teamdata t
                        JOIN groupdata g ON t.group_ID = g.group_ID
                        WHERE t.cohort_ID = ?
                          AND t.group_ID = ?
                          AND t.team_status = 1
                        ORDER BY t.team_ID";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([$cohort_ID, $effective_group]);
            }
        }
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 調試：記錄返回的數據（僅在開發環境）
    if ($includeScheduleSortNo && $latestTinformaId) {
        error_log("listTeams 返回數據（包含 time_sort_no，tinforma_ID={$latestTinformaId}）: " . json_encode(array_slice($rows, 0, 3), JSON_UNESCAPED_UNICODE));
    }

    respond(["success" => true, "data" => $rows]);
}

/* ==========================================
   action: listTitles
   取得該屆別和類組的所有已使用過的標題（去重）（如果 group_ID 為空或 "all"，則返回該屆所有標題）
========================================== */
if ($action === "listTitles") {
    
    try {
        $cohort_ID = $_GET["cohort_ID"] ?? 0;
        $group_ID = $_GET["group_ID"] ?? 0;
        
        if (!$cohort_ID) {
            respond(["success" => false, "msg" => "缺少參數"]);
        }
        
        // 如果 group_ID 為空、0 或 "all"，則返回該屆所有標題
        if (!$group_ID || $group_ID === "all" || $group_ID === "0") {
            $sql = "SELECT DISTINCT sf.sf_ID, sf.sf_name 
                    FROM suggestfrom sf
                    JOIN suggest s ON sf.sf_ID = s.sf_ID
                    JOIN teamdata t ON s.team_ID = t.team_ID
                    WHERE t.cohort_ID = ? 
                      AND s.suggest_status IN (1, 2, 3, 4)
                      AND sf.sf_name IS NOT NULL
                      AND TRIM(sf.sf_name) != ''
                    ORDER BY sf.sf_update_d DESC, sf.sf_created_d DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$cohort_ID]);
        } else {
            // 從 suggestfrom 表取得該屆別和類組的所有已使用過的標題（去重）
            $sql = "SELECT DISTINCT sf.sf_ID, sf.sf_name 
                    FROM suggestfrom sf
                    JOIN suggest s ON sf.sf_ID = s.sf_ID
                    JOIN teamdata t ON s.team_ID = t.team_ID
                    WHERE t.cohort_ID = ? 
                      AND t.group_ID = ?
                      AND s.suggest_status IN (1, 2, 3, 4)
                      AND sf.sf_name IS NOT NULL
                      AND TRIM(sf.sf_name) != ''
                    ORDER BY sf.sf_update_d DESC, sf.sf_created_d DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$cohort_ID, $group_ID]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 提取標題陣列
        $titles = array_map(function($row) {
            return trim($row['sf_name']);
        }, $rows);
        
        // 去重並過濾空值
        $titles = array_values(array_unique(array_filter($titles)));
        
        respond(["success" => true, "data" => $titles]);
        
    } catch (Throwable $e) {
        error_log("listTitles 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "載入標題列表失敗"]);
    }
}

/* ==========================================
   action: getTitleInfo
   取得標題的資訊（最新更新時間、組別數量）（如果 group_ID 為空或 "all"，則返回該屆所有組別的資訊）
========================================== */
if ($action === "getTitleInfo") {
    
    try {
        $cohort_ID = $_GET["cohort_ID"] ?? 0;
        $group_ID = $_GET["group_ID"] ?? 0;
        $title = $_GET["title"] ?? "";
        
        if (!$cohort_ID || !$title) {
            respond(["success" => false, "msg" => "缺少參數"]);
        }
        
        // 如果 group_ID 為空、0 或 "all"，則返回該屆所有組別的資訊
        if (!$group_ID || $group_ID === "all" || $group_ID === "0") {
            $sql = "SELECT 
                        sf.sf_update_d as latest_date,
                        COUNT(DISTINCT s.team_ID) as team_count
                    FROM suggestfrom sf
                    JOIN suggest s ON sf.sf_ID = s.sf_ID
                    JOIN teamdata t ON s.team_ID = t.team_ID
                    WHERE t.cohort_ID = ? 
                      AND sf.sf_name = ?
                      AND s.suggest_status IN (1, 2, 3, 4)
                    GROUP BY sf.sf_ID, sf.sf_update_d";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$cohort_ID, $title]);
        } else {
            // 取得該標題的最新更新時間和組別數量（通過 suggestfrom 表關聯）
            $sql = "SELECT 
                        sf.sf_update_d as latest_date,
                        COUNT(DISTINCT s.team_ID) as team_count
                    FROM suggestfrom sf
                    JOIN suggest s ON sf.sf_ID = s.sf_ID
                    JOIN teamdata t ON s.team_ID = t.team_ID
                    WHERE t.cohort_ID = ? 
                      AND t.group_ID = ?
                      AND sf.sf_name = ?
                      AND s.suggest_status IN (1, 2, 3, 4)
                    GROUP BY sf.sf_ID, sf.sf_update_d";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$cohort_ID, $group_ID, $title]);
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        respond([
            "success" => true, 
            "data" => [
                "latest_date" => $result['latest_date'] ?? null,
                "team_count" => (int)($result['team_count'] ?? 0)
            ]
        ]);
        
    } catch (Throwable $e) {
        error_log("getTitleInfo 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "取得標題資訊失敗"]);
    }
}

/* ==========================================
   action: checkAllTeamsHaveSuggest
   檢查該屆別和類組的所有組別是否都有建議
========================================== */
if ($action === "checkAllTeamsHaveSuggest") {
    
    $cohort_ID = $_GET["cohort_ID"] ?? 0;
    $group_ID = $_GET["group_ID"] ?? 0;
    $title = $_GET["title"] ?? "";
    
    if (!$cohort_ID || !$group_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    
    // 取得該屆別和類組的所有組別
    $sql = "SELECT t.team_ID, t.team_project_name
            FROM teamdata t
            WHERE t.cohort_ID = ?
              AND t.group_ID = ?
              AND t.team_status = 1
            ORDER BY t.team_ID";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$cohort_ID, $group_ID]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($teams) === 0) {
        respond(["success" => false, "msg" => "沒有找到任何組別"]);
    }
    
    // 如果沒有提供標題，無法檢查
    if (trim($title) === "") {
        respond(["success" => false, "msg" => "請先輸入標題"]);
    }
    
    // 取得標題對應的 sf_ID
    $sf_ID = null;
    $checkTitleSql = "SELECT sf_ID FROM suggestfrom WHERE sf_name = ? LIMIT 1";
    $checkTitleStmt = $conn->prepare($checkTitleSql);
    $checkTitleStmt->execute([$title]);
    $titleResult = $checkTitleStmt->fetch(PDO::FETCH_ASSOC);
    if ($titleResult) {
        $sf_ID = $titleResult['sf_ID'];
    }
    
    // 檢查每個組別是否有該標題的建議，且建議內容不為空
    $teamsWithoutSuggest = [];
    foreach ($teams as $team) {
        $team_ID = $team['team_ID'];
        
        // 檢查是否有該標題的建議，且建議內容不為空
        if ($sf_ID) {
            $sql = "SELECT s.suggest_comment 
                    FROM suggest s
                    WHERE s.team_ID = ? 
                      AND s.sf_ID = ?
                      AND s.suggest_status IN (1, 2, 3, 4)
                    ORDER BY s.suggest_ID DESC
                    LIMIT 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$team_ID, $sf_ID]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // 如果沒有找到 sf_ID，檢查是否有符合標題名稱的建議（舊資料）
            $sql = "SELECT s.suggest_comment 
                    FROM suggest s
                    WHERE s.team_ID = ? 
                      AND s.suggest_name = ?
                      AND s.suggest_status IN (1, 2, 3, 4)
                    ORDER BY s.suggest_ID DESC
                    LIMIT 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$team_ID, $title]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // 如果沒有建議，或建議內容為空，則加入未填寫列表
        if (!$result || trim($result['suggest_comment']) === "") {
            $teamsWithoutSuggest[] = $team['team_project_name'];
        }
    }
    
    if (count($teamsWithoutSuggest) > 0) {
        respond([
            "success" => false, 
            "msg" => "以下組別尚未填寫建議：" . implode("、", $teamsWithoutSuggest),
            "teamsWithoutSuggest" => $teamsWithoutSuggest
        ]);
    }
    
    respond(["success" => true, "msg" => "所有組別都已填寫建議"]);
}

/* ==========================================
   action: listSuggests
   取得某組別所有建議（多筆）
========================================== */
if ($action === "listSuggests") {

    $team_ID = $_GET["team_ID"] ?? 0;

    if (!$team_ID) {
        respond(["success" => false, "msg" => "缺少 team_ID"]);
    }

    try {
        $hasSuggestScore = $conn->query("SHOW COLUMNS FROM suggest LIKE 'suggest_score'")->rowCount() > 0;
        if (!$hasSuggestScore) {
            try {
                $conn->exec("ALTER TABLE suggest ADD COLUMN suggest_score DECIMAL(5,2) DEFAULT NULL COMMENT '召集人填寫的評分或覆寫指導老師平均分' AFTER suggest_sort_no");
                $hasSuggestScore = true;
            } catch (Throwable $e) {
                error_log("listSuggests 添加 suggest_score 失敗: " . $e->getMessage());
            }
        }
        $tbl = "pereviewdata";
        $hasPereview = $conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() > 0;
        $scoreCol = $hasSuggestScore ? ", s.suggest_score" : "";
        $teacherAvgSub = "";
        if ($hasPereview && $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'score'")->rowCount() > 0) {
            $teacherAvgSub = ", (SELECT AVG(p.score) FROM {$tbl} p WHERE p.sf_ID = s.sf_ID AND p.pe_team_ID = s.team_ID AND (p.petarget_u_ID IS NULL OR p.petarget_u_ID = '') AND p.score IS NOT NULL) AS teacher_avg";
        }
        $sql = "SELECT 
                s.suggest_ID,
                s.sf_ID,
                s.suggest_u_ID,
                s.team_ID,
                s.suggest_comment,
                s.suggest_d,
                s.suggest_status,
                s.suggest_sort_no
                {$scoreCol}
                {$teacherAvgSub},
                sf.sf_name as suggest_name
            FROM suggest s
            LEFT JOIN suggestfrom sf ON s.sf_ID = sf.sf_ID
            WHERE s.team_ID = ?
              AND s.suggest_status IN (1, 2, 3, 4)
            ORDER BY s.suggest_ID DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$team_ID]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond(["success" => true, "data" => $rows]);
    } catch (Throwable $e) {
        error_log("listSuggests 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "取得建議列表失敗"]);
    }
}

/* ==========================================
   action: getSfIdByTitle
   根據標題獲取 sf_ID
========================================== */
if ($action === "getSfIdByTitle") {
    
    $cohort_ID = $_GET["cohort_ID"] ?? 0;
    $group_ID = $_GET["group_ID"] ?? 0;
    $title = $_GET["title"] ?? "";
    
    if (!$cohort_ID || !$title) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    
    try {
        if (!$group_ID || $group_ID === "all" || $group_ID === "0") {
            $sql = "SELECT sf_ID FROM suggestfrom 
                   WHERE sf_name = ? 
                   AND sf_ID IN (
                       SELECT DISTINCT s.sf_ID 
                       FROM suggest s
                       JOIN teamdata t ON s.team_ID = t.team_ID
                       WHERE t.cohort_ID = ?
                   )
                   LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$title, $cohort_ID]);
        } else {
            $sql = "SELECT sf_ID FROM suggestfrom 
                   WHERE sf_name = ? 
                   AND sf_ID IN (
                       SELECT DISTINCT s.sf_ID 
                       FROM suggest s
                       JOIN teamdata t ON s.team_ID = t.team_ID
                       WHERE t.cohort_ID = ? AND t.group_ID = ?
                   )
                   LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$title, $cohort_ID, $group_ID]);
        }
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && isset($result['sf_ID'])) {
            respond(["success" => true, "data" => ["sf_ID" => $result['sf_ID']]]);
        } else {
            respond(["success" => false, "msg" => "找不到該標題的建議表"]);
        }
    } catch (Throwable $e) {
        error_log("getSfIdByTitle 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "取得 sf_ID 失敗"]);
    }
}

/* ==========================================
   action: sendSuggestToOffice
   召集人存檔後「發送給科辦」：
   - 僅當屆召集人（enrollmentdata cohort_ID + role_ID=7 + enroll_status=1）可送交
   - 記錄於 suggest_convener_sent；若當屆所有召集人都已送交則 sf_sent_to_office=1，否則為已送交者帳號（逗號分隔）
========================================== */
if ($action === "sendSuggestToOffice") {
    if ((int)$role_ID !== 7) {
        respond(["success" => false, "msg" => "僅召集人可發送給科辦"]);
    }
    $sf_ID = (int)($_POST["sf_ID"] ?? 0);
    $title = trim($_POST["title"] ?? "");
    $cohort_ID = (int)($_POST["cohort_ID"] ?? 0);
    if (!$sf_ID || !$title || !$cohort_ID) {
        respond(["success" => false, "msg" => "缺少參數 sf_ID、title 或 cohort_ID"]);
    }
    try {
        $row = $conn->prepare("SELECT sf_ID, sf_name, sf_type, sf_cohort FROM suggestfrom WHERE sf_ID = ? LIMIT 1");
        $row->execute([$sf_ID]);
        $sf = $row->fetch(PDO::FETCH_ASSOC);
        if (!$sf || $sf["sf_name"] !== $title) {
            respond(["success" => false, "msg" => "建議表不存在或標題不符"]);
        }
        $form_cohort = isset($sf["sf_cohort"]) && $sf["sf_cohort"] !== null && $sf["sf_cohort"] !== '' ? (int)$sf["sf_cohort"] : $cohort_ID;
        $conveners = get_cohort_convener_u_ids($conn, $form_cohort);
        if (!in_array($u_ID, $conveners, true)) {
            respond(["success" => false, "msg" => "您不在當屆召集人名單中，無法送交。"]);
        }
        if (is_convener_form_locked($conn, $role_ID, $sf_ID, $u_ID)) {
            respond(["success" => false, "msg" => "您已送交過此建議表，無法重複送交。"]);
        }

        $hasCol = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_sent_to_office'")->rowCount() > 0;
        if (!$hasCol) {
            $conn->exec("ALTER TABLE suggestfrom ADD COLUMN sf_sent_to_office VARCHAR(200) DEFAULT '0'");
        } else {
            $col = $conn->query("SHOW COLUMNS FROM suggestfrom WHERE Field = 'sf_sent_to_office'")->fetch(PDO::FETCH_ASSOC);
            if ($col && (stripos($col["Type"] ?? "", "varchar") === false && stripos($col["Type"] ?? "", "char") === false)) {
                $conn->exec("ALTER TABLE suggestfrom MODIFY sf_sent_to_office VARCHAR(200) DEFAULT '0'");
            }
        }

        $hasSentTable = $conn->query("SHOW TABLES LIKE 'suggest_convener_sent'")->rowCount() > 0;
        if (!$hasSentTable) {
            $conn->exec("CREATE TABLE suggest_convener_sent (sf_ID INT NOT NULL, u_ID VARCHAR(50) NOT NULL, sent_d DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (sf_ID, u_ID))");
        }
        $insSent = $conn->prepare("INSERT IGNORE INTO suggest_convener_sent (sf_ID, u_ID, sent_d) VALUES (?, ?, NOW())");
        $insSent->execute([$sf_ID, $u_ID]);

        $countStmt = $conn->prepare("SELECT COUNT(*) FROM suggest_convener_sent WHERE sf_ID = ?");
        $countStmt->execute([$sf_ID]);
        $sent_count = (int)$countStmt->fetchColumn();
        $total_conveners = count($conveners);
        if ($sent_count >= $total_conveners && $total_conveners > 0) {
            $newVal = "1";
        } else {
            $listStmt = $conn->prepare("SELECT u_ID FROM suggest_convener_sent WHERE sf_ID = ? ORDER BY sent_d ASC");
            $listStmt->execute([$sf_ID]);
            $newVal = implode(",", $listStmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $conn->prepare("UPDATE suggestfrom SET sf_sent_to_office = ? WHERE sf_ID = ?")->execute([$newVal, $sf_ID]);

        $stmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ? AND cohort_status = 1");
        $stmt->execute([$cohort_ID]);
        $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
        $cohort_name = $cohort ? $cohort["cohort_name"] : "該屆";
        $suggestUrl = "pages/suggest_export.php?cohort_ID={$cohort_ID}&title=" . urlencode($title);
        $msgTitle = "建議表已送交科辦：{$title}";
        $msgContent = "{$cohort_name} 的建議表「{$title}」已由召集人填寫完成並送交科辦，請前往查看。";
        $urlData = [["type" => "link", "url" => $suggestUrl, "label" => "查看"]];
        $msg_url = json_encode($urlData, JSON_UNESCAPED_UNICODE);

        $ins = $conn->prepare("INSERT INTO msgdata (msg_title, msg_content, msg_url, msg_type, msg_a_u_ID, msg_status, msg_start_d, msg_created_d) VALUES (?, ?, ?, 'SYSTEM_NOTICE', ?, 1, NOW(), NOW())");
        $ins->execute([$msgTitle, $msgContent, $msg_url, $u_ID]);
        $msg_ID = (int)$conn->lastInsertId();
        if (!$msg_ID) {
            respond(["success" => false, "msg" => "建立通知失敗"]);
        }

        $officeStmt = $conn->query("SELECT ur_u_ID FROM userrolesdata WHERE role_ID = 2 AND user_role_status = 1");
        $officeUsers = $officeStmt->fetchAll(PDO::FETCH_COLUMN);
        $targetIns = $conn->prepare("INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID) VALUES (?, 'USER', ?)");
        foreach ($officeUsers as $ou) {
            if (trim($ou) !== "" && $ou !== $u_ID) {
                $targetIns->execute([$msg_ID, $ou]);
            }
        }

        respond(["success" => true, "msg" => "已送交科辦並已通知科辦"]);
    } catch (Throwable $e) {
        error_log("sendSuggestToOffice 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "發送失敗：" . $e->getMessage()]);
    }
}

/* ==========================================
   action: listSuggestsBySfId
   根據 sf_ID 獲取所有建議（用於載入不在時程表中的組別）
========================================== */
if ($action === "listSuggestsBySfId") {
    
    $sf_ID = $_GET["sf_ID"] ?? 0;
    
    if (!$sf_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    
    try {
        $sql = "SELECT DISTINCT
                    s.team_ID,
                    s.suggest_ID,
                    s.suggest_sort_no
                FROM suggest s
                WHERE s.sf_ID = ?
                  AND s.suggest_status IN (1, 2, 3, 4)";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$sf_ID]);
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        respond(["success" => true, "data" => $rows]);
    } catch (Throwable $e) {
        error_log("listSuggestsBySfId 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "取得建議列表失敗"]);
    }
}

/* ==========================================
   action: getTeamInfo
   獲取組別詳細資訊
========================================== */
if ($action === "getTeamInfo") {
    
    $team_ID = $_GET["team_ID"] ?? 0;
    
    if (!$team_ID) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    
    try {
        $sql = "SELECT 
                    t.team_ID,
                    t.team_project_name,
                    t.group_ID,
                    g.group_name
                FROM teamdata t
                JOIN groupdata g ON t.group_ID = g.group_ID
                WHERE t.team_ID = ? AND t.team_status = 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$team_ID]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            respond(["success" => true, "data" => $result]);
        } else {
            respond(["success" => false, "msg" => "找不到該組別"]);
        }
    } catch (Throwable $e) {
        error_log("getTeamInfo 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "取得組別資訊失敗"]);
    }
}

/* ==========================================
   action: addSuggest
   多行 → 單筆建議（合併）
========================================== */
if ($action === "addSuggest") {

    $team_ID = $_POST["team_ID"] ?? 0;
    $content = $_POST["content"] ?? "";
    $suggest_name = $_POST["suggest_name"] ?? "";
    $suggest_status = $_POST["suggest_status"] ?? null;
    $suggest_score = isset($_POST["suggest_score"]) && $_POST["suggest_score"] !== "" ? (float)$_POST["suggest_score"] : null;

    if (!$team_ID || trim($content) === "") {
        respond(["success" => false, "msg" => "參數錯誤"]);
    }

    // 目前改由前端控制：僅召集人能「新增初審建議表」，
    // 但主任與科辦仍可在既有初審建議表下編輯各組別建議。
    // 後端不再依 sf_type 限制新增/編輯建議，避免科辦無法存檔。

    // 驗證：檢查內容是否只包含數字
    $lines = preg_split("/\r\n|\r|\n/", $content);
    $hasValidContent = false;
    
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        if ($trimmedLine === "") continue;
        
        // 去除編號（如：1. 2) 3、等）
        $lineContent = preg_replace('/^\s*\d+[\.\、\)\:]\s*/u', '', $trimmedLine);
        $lineContent = preg_replace('/^\s*\d+\s+/u', '', $lineContent);
        $lineContent = trim($lineContent);
        
        // 如果去除編號後還有內容，檢查是否不只包含數字
        if ($lineContent !== "") {
            // 去除所有標點符號和空白，檢查是否只剩數字
            $contentWithoutPunctuation = preg_replace('/[\.\、\)\:，。！？\s]/u', '', $lineContent);
            if ($contentWithoutPunctuation !== "" && !preg_match('/^\d+$/u', $contentWithoutPunctuation)) {
                $hasValidContent = true;
                break;
            }
        }
    }
    
    // 如果所有行都只包含數字，返回錯誤訊息
    if (!$hasValidContent) {
        // 獲取組別名稱
        $sql = "SELECT team_project_name FROM teamdata WHERE team_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$team_ID]);
        $team = $stmt->fetch(PDO::FETCH_ASSOC);
        $teamName = $team ? $team['team_project_name'] : "組別 ID: " . $team_ID;
        
        respond(["success" => false, "msg" => "「" . $teamName . "」的建議內容無法只輸入數字，請輸入文字說明"]);
    }

    // 處理多行內容：忽略只有編號的行，然後重新編號
    $validLines = [];
    
    // 第一步：過濾掉只有編號的行和空行
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        
        // 空行跳過
        if ($trimmedLine === "") continue;
        
        // 檢查是否只有編號（如：1. 或 2 或 . 或 2) 等）
        // 匹配：開頭是數字+標點符號，後面只有空白或沒有內容
        // 也匹配：只有數字+小數點（如：2.2 但沒有其他內容）
        if (preg_match('/^\s*\d+[\.\、\)\:]\s*$/u', $trimmedLine) || 
            preg_match('/^\s*\d+\s*$/u', $trimmedLine) ||
            preg_match('/^\s*[\.\、\)\:]\s*$/u', $trimmedLine) ||
            // 匹配像 "2.2" 這種只有數字和小數點的（但沒有其他文字）
            preg_match('/^\s*\d+\.\d+\s*$/u', $trimmedLine)) {
            continue; // 跳過只有編號的行
        }
        
        // 保留有效行（包括原始格式）
        $validLines[] = $line;
    }
    
    if (count($validLines) == 0) {
        respond(["success" => false, "msg" => "內容無有效建議"]);
    }
    
    // 第二步：重新編號，將每行開頭的編號替換為新的連續編號
    $cleanLines = [];
    $newNumber = 1;
    
    foreach ($validLines as $line) {
        $trimmedLine = trim($line);
        $content = "";
        
        // 使用 preg_match 精確捕獲內容部分
        // 模式1：數字 + 點 + 空白 + 內容（如：1. 測試）
        if (preg_match('/^\s*\d+\.\s+(.+)$/u', $trimmedLine, $matches)) {
            $content = trim($matches[1]);
        }
        // 模式2：數字 + 其他標點 + 空白 + 內容（如：1) 測試、1: 測試）
        elseif (preg_match('/^\s*\d+[\)\:]\s+(.+)$/u', $trimmedLine, $matches)) {
            $content = trim($matches[1]);
        }
        // 模式3：數字 + 頓號 + 內容（如：1、測試）
        elseif (preg_match('/^\s*\d+、\s*(.+)$/u', $trimmedLine, $matches)) {
            $content = trim($matches[1]);
        }
        // 模式4：數字 + 空白 + 內容（沒有標點，如：1 測試）
        elseif (preg_match('/^\s*\d+\s+(.+)$/u', $trimmedLine, $matches)) {
            $content = trim($matches[1]);
        }
        // 如果沒有匹配到任何編號模式，直接使用原內容
        else {
            $content = $trimmedLine;
        }
        
        // 確保有內容（去掉編號後不能為空）
        $content = trim($content);
        // 過濾掉：空內容、只有標點、只有數字、只有數字+標點
        // 特別檢查：如果內容是 "2." 這種格式，也要過濾掉
        if ($content !== "" && 
            $content !== "." && 
            !preg_match('/^\d+\.?\s*$/', $content) &&
            !preg_match('/^[\.\、\)\:]\s*$/', $content) &&
            !preg_match('/^\d+[\.\、\)\:]\s*$/', $content) &&
            // 額外檢查：確保不是只有數字+點（如 "2."）
            !preg_match('/^\d+\.$/', $content)) {
            // 加上新編號
            $cleanLines[] = $newNumber . ". " . $content;
            $newNumber++;
        }
    }
    
    if (count($cleanLines) == 0) {
        respond(["success" => false, "msg" => "內容無有效建議"]);
    }
    
    // 合併所有行，用換行符連接
    $finalContent = implode("\n", $cleanLines);
    
    // 去除最後多餘的換行
    $finalContent = rtrim($finalContent, "\n\r");

    // 驗證 suggest_status（新增時必須有狀態，如果沒有選擇則使用預設值 1）
    if ($suggest_status !== null && $suggest_status !== "") {
        $suggest_status = (int)$suggest_status;
        if (!in_array($suggest_status, [1, 2, 3, 4])) {
            $suggest_status = 1; // 預設值：修改後通過
        }
    } else {
        // 如果沒有選擇審查結果，使用預設值 1（修改後通過）
        $suggest_status = 1;
    }
    
    // 檢查或創建 suggestfrom 記錄
    $sf_ID = null;
    if (trim($suggest_name) !== "") {
        // 先檢查是否已存在相同標題的 suggestfrom 記錄
        $checkSql = "SELECT sf_ID FROM suggestfrom WHERE sf_name = ? LIMIT 1";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->execute([$suggest_name]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // 使用已存在的 sf_ID
            $sf_ID = $existing['sf_ID'];
            // 召集人：須在當屆召集人名單且未送交過才可編輯
            if ((int)$role_ID === 7) {
                $can = convener_can_edit_suggest_form($conn, $sf_ID, $u_ID);
                if (!$can["ok"]) {
                    respond(["success" => false, "msg" => $can["msg"]]);
                }
            }
            // 更新 suggestfrom 的更新時間
            $updateSql = "UPDATE suggestfrom SET sf_update_d = NOW() WHERE sf_ID = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([$sf_ID]);
        } else {
            // 創建新的 suggestfrom 記錄
            $insertSql = "INSERT INTO suggestfrom (sf_name, sf_u_ID, sf_created_d, sf_update_d) 
                          VALUES (?, ?, NOW(), NOW())";
            $insertStmt = $conn->prepare($insertSql);
            $insertStmt->execute([$suggest_name, $u_ID]);
            $sf_ID = $conn->lastInsertId();
        }
    }
    
    // 若前端有傳入 suggest_sort_no（使用者畫面上的順序），以該值為準；否則才用時程表或 MAX+1 當預設
    $suggest_sort_no = isset($_POST["suggest_sort_no"]) ? (int)$_POST["suggest_sort_no"] : 0;
    if ($suggest_sort_no <= 0) {
        $suggest_sort_no = null;
    }
    
    // 檢查該組別在該標題下是否已有建議（判斷是否為第一次新增）
    $checkExistingSql = "SELECT COUNT(*) as count FROM suggest 
                         WHERE sf_ID = ? AND team_ID = ? AND suggest_status IN (1, 2, 3, 4)";
    $checkExistingStmt = $conn->prepare($checkExistingSql);
    $checkExistingStmt->execute([$sf_ID, $team_ID]);
    $existingResult = $checkExistingStmt->fetch(PDO::FETCH_ASSOC);
    $isFirstTime = ($existingResult['count'] == 0);
    
    // 若未傳入順序且為第一次新增，從時程表獲取排序順序；無法從時程表取得則用該標題下最大 suggest_sort_no + 1
    if ($suggest_sort_no === null && $isFirstTime && $sf_ID) {
        try {
            // 獲取該組別的 cohort_ID
            $getCohortSql = "SELECT cohort_ID FROM teamdata WHERE team_ID = ?";
            $getCohortStmt = $conn->prepare($getCohortSql);
            $getCohortStmt->execute([$team_ID]);
            $teamData = $getCohortStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($teamData && isset($teamData['cohort_ID'])) {
                $cohort_ID = $teamData['cohort_ID'];
                
                // 獲取建議表的建立時間
                $getSuggestCreatedSql = "SELECT sf_created_d FROM suggestfrom WHERE sf_ID = ?";
                $getSuggestCreatedStmt = $conn->prepare($getSuggestCreatedSql);
                $getSuggestCreatedStmt->execute([$sf_ID]);
                $suggestCreatedData = $getSuggestCreatedStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($suggestCreatedData && isset($suggestCreatedData['sf_created_d'])) {
                    $suggest_created_d = $suggestCreatedData['sf_created_d'];
                    
                    // 使用建議表的建立時間，找到時間最近的時程表（在該時間之前或最接近）
                    // 先獲取該屆別在建議表建立時間之前或最接近的時程表 ID
                    $getLatestTinformaSql = "SELECT tinforma_ID FROM timeinformadata 
                                             WHERE tinforma_ID IN (
                                                 SELECT DISTINCT td.tinforma_ID 
                                                 FROM timedata td
                                                 JOIN teamdata t ON td.team_ID = t.team_ID
                                                 WHERE t.cohort_ID = ?
                                             )
                                             AND tinforma_create_d <= ?
                                             ORDER BY tinforma_create_d DESC
                                             LIMIT 1";
                    $getLatestTinformaStmt = $conn->prepare($getLatestTinformaSql);
                    $getLatestTinformaStmt->execute([$cohort_ID, $suggest_created_d]);
                    $latestTinforma = $getLatestTinformaStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($latestTinforma && isset($latestTinforma['tinforma_ID'])) {
                        $tinforma_ID = $latestTinforma['tinforma_ID'];
                        
                        // 獲取該組別在該時程表中的 sort_no
                        $getSortNoSql = "SELECT sort_no FROM timedata 
                                         WHERE tinforma_ID = ? AND team_ID = ? 
                                         ORDER BY sort_no ASC
                                         LIMIT 1";
                        $getSortNoStmt = $conn->prepare($getSortNoSql);
                        $getSortNoStmt->execute([$tinforma_ID, $team_ID]);
                        $sortNoData = $getSortNoStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($sortNoData && isset($sortNoData['sort_no']) && $sortNoData['sort_no'] !== null) {
                            $sortNoFromSchedule = (int)$sortNoData['sort_no'];
                            // 只使用大於 0 的 sort_no（0 不是有效的排序值）
                            if ($sortNoFromSchedule > 0) {
                                $suggest_sort_no = $sortNoFromSchedule;
                                error_log("首次新增建議：組別 {$team_ID} 從時程表獲取 sort_no = {$suggest_sort_no}（建議表建立時間：{$suggest_created_d}，時程表 ID：{$tinforma_ID}）");
                            }
                        }
                    }
                    
                    // 如果無法從時程表獲取 sort_no（組別不在時程表中），則根據該標題下現有組別的最大 suggest_sort_no 來設置
                    if ($suggest_sort_no === null) {
                        // 獲取該標題下現有組別的最大 suggest_sort_no
                        $getMaxSortNoSql = "SELECT MAX(suggest_sort_no) as max_sort_no FROM suggest 
                                           WHERE sf_ID = ? AND suggest_status IN (1, 2, 3, 4) 
                                           AND suggest_sort_no IS NOT NULL AND suggest_sort_no > 0";
                        $getMaxSortNoStmt = $conn->prepare($getMaxSortNoSql);
                        $getMaxSortNoStmt->execute([$sf_ID]);
                        $maxSortNoData = $getMaxSortNoStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($maxSortNoData && isset($maxSortNoData['max_sort_no']) && $maxSortNoData['max_sort_no'] !== null) {
                            $suggest_sort_no = (int)$maxSortNoData['max_sort_no'] + 1;
                            error_log("首次新增建議：組別 {$team_ID} 不在時程表中，使用該標題下最大 suggest_sort_no + 1 = {$suggest_sort_no}");
                        } else {
                            // 如果該標題下沒有任何組別，設置為 1
                            $suggest_sort_no = 1;
                            error_log("首次新增建議：組別 {$team_ID} 不在時程表中，且該標題下沒有其他組別，設置 suggest_sort_no = 1");
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("從時程表獲取排序順序失敗: " . $e->getMessage());
            // 如果獲取失敗，嘗試使用該標題下現有組別的最大 suggest_sort_no
            try {
                $getMaxSortNoSql = "SELECT MAX(suggest_sort_no) as max_sort_no FROM suggest 
                                   WHERE sf_ID = ? AND suggest_status IN (1, 2, 3, 4) 
                                   AND suggest_sort_no IS NOT NULL AND suggest_sort_no > 0";
                $getMaxSortNoStmt = $conn->prepare($getMaxSortNoSql);
                $getMaxSortNoStmt->execute([$sf_ID]);
                $maxSortNoData = $getMaxSortNoStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($maxSortNoData && isset($maxSortNoData['max_sort_no']) && $maxSortNoData['max_sort_no'] !== null) {
                    $suggest_sort_no = (int)$maxSortNoData['max_sort_no'] + 1;
                    error_log("首次新增建議：組別 {$team_ID} 從時程表獲取失敗，使用該標題下最大 suggest_sort_no + 1 = {$suggest_sort_no}");
                } else {
                    $suggest_sort_no = 1;
                    error_log("首次新增建議：組別 {$team_ID} 從時程表獲取失敗，且該標題下沒有其他組別，設置 suggest_sort_no = 1");
                }
            } catch (Exception $e2) {
                error_log("獲取最大 suggest_sort_no 失敗: " . $e2->getMessage());
                // 如果都失敗，設置為 1
                $suggest_sort_no = 1;
            }
        }
    }
    
    $hasSuggestScoreCol = $conn->query("SHOW COLUMNS FROM suggest LIKE 'suggest_score'")->rowCount() > 0;
    // 插入 suggest 記錄
    if ($suggest_sort_no !== null) {
        if ($hasSuggestScoreCol) {
            $sql = "INSERT INTO suggest
                    (sf_ID, suggest_u_ID, team_ID, suggest_comment, suggest_d, suggest_status, suggest_sort_no, suggest_score)
                    VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$sf_ID, $u_ID, $team_ID, $finalContent, $suggest_status, $suggest_sort_no, $suggest_score]);
        } else {
            $sql = "INSERT INTO suggest
                    (sf_ID, suggest_u_ID, team_ID, suggest_comment, suggest_d, suggest_status, suggest_sort_no)
                    VALUES (?, ?, ?, ?, NOW(), ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$sf_ID, $u_ID, $team_ID, $finalContent, $suggest_status, $suggest_sort_no]);
        }
    } else {
        if ($hasSuggestScoreCol) {
            $sql = "INSERT INTO suggest
                    (sf_ID, suggest_u_ID, team_ID, suggest_comment, suggest_d, suggest_status, suggest_score)
                    VALUES (?, ?, ?, ?, NOW(), ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$sf_ID, $u_ID, $team_ID, $finalContent, $suggest_status, $suggest_score]);
        } else {
            $sql = "INSERT INTO suggest
                    (sf_ID, suggest_u_ID, team_ID, suggest_comment, suggest_d, suggest_status)
                    VALUES (?, ?, ?, ?, NOW(), ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$sf_ID, $u_ID, $team_ID, $finalContent, $suggest_status]);
        }
    }

    $suggestId = $conn->lastInsertId();

    respond(["success" => true, "msg" => "已新增建議", "suggest_ID" => $suggestId]);
}

/* ==========================================
   action: updateSuggest
   更新建議
========================================== */
if ($action === "updateSuggest") {

    $suggest_ID = $_POST["suggest_ID"] ?? 0;
    $team_ID = $_POST["team_ID"] ?? 0;
    $content = $_POST["content"] ?? "";
    $suggest_name = $_POST["suggest_name"] ?? "";
    $suggest_status = $_POST["suggest_status"] ?? null;
    $suggest_score = isset($_POST["suggest_score"]) && $_POST["suggest_score"] !== "" ? (float)$_POST["suggest_score"] : null;

    if (!$suggest_ID || !$team_ID || trim($content) === "") {
        respond(["success" => false, "msg" => "參數錯誤"]);
    }

    // 驗證：檢查內容是否只包含數字
    $lines = preg_split("/\r\n|\r|\n/", $content);
    $hasValidContent = false;
    
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        if ($trimmedLine === "") continue;
        
        // 去除編號（如：1. 2) 3、等）
        $lineContent = preg_replace('/^\s*\d+[\.\、\)\:]\s*/u', '', $trimmedLine);
        $lineContent = preg_replace('/^\s*\d+\s+/u', '', $lineContent);
        $lineContent = trim($lineContent);
        
        // 如果去除編號後還有內容，檢查是否不只包含數字
        if ($lineContent !== "") {
            // 去除所有標點符號和空白，檢查是否只剩數字
            $contentWithoutPunctuation = preg_replace('/[\.\、\)\:，。！？\s]/u', '', $lineContent);
            if ($contentWithoutPunctuation !== "" && !preg_match('/^\d+$/u', $contentWithoutPunctuation)) {
                $hasValidContent = true;
                break;
            }
        }
    }
    
    // 如果所有行都只包含數字，返回錯誤訊息
    if (!$hasValidContent) {
        // 獲取組別名稱
        $sql = "SELECT team_project_name FROM teamdata WHERE team_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$team_ID]);
        $team = $stmt->fetch(PDO::FETCH_ASSOC);
        $teamName = $team ? $team['team_project_name'] : "組別 ID: " . $team_ID;
        
        respond(["success" => false, "msg" => "「" . $teamName . "」的建議內容無法只輸入數字，請輸入文字說明"]);
    }

    // 處理多行內容：忽略只有編號的行，然後重新編號
    $validLines = [];
    
    // 第一步：過濾掉只有編號的行和空行
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        
        // 空行跳過
        if ($trimmedLine === "") continue;
        
        // 檢查是否只有編號（如：1. 或 2 或 . 或 2) 等）
        // 也匹配：只有數字+小數點（如：2.2 但沒有其他內容）
        if (preg_match('/^\s*\d+[\.\、\)\:]\s*$/u', $trimmedLine) || 
            preg_match('/^\s*\d+\s*$/u', $trimmedLine) ||
            preg_match('/^\s*[\.\、\)\:]\s*$/u', $trimmedLine) ||
            // 匹配像 "2.2" 這種只有數字和小數點的（但沒有其他文字）
            preg_match('/^\s*\d+\.\d+\s*$/u', $trimmedLine)) {
            continue; // 跳過只有編號的行
        }
        
        // 保留有效行（包括原始格式）
        $validLines[] = $line;
    }
    
    if (count($validLines) == 0) {
        respond(["success" => false, "msg" => "內容無有效建議"]);
    }
    
    // 第二步：重新編號，將每行開頭的編號替換為新的連續編號
    $cleanLines = [];
    $newNumber = 1;
    
    foreach ($validLines as $line) {
        $trimmedLine = trim($line);
        $content = "";
        
        // 使用 preg_match 精確捕獲內容部分
        if (preg_match('/^\s*\d+\.\s+(.+)$/u', $trimmedLine, $matches)) {
            $content = trim($matches[1]);
        }
        elseif (preg_match('/^\s*\d+[\)\:]\s+(.+)$/u', $trimmedLine, $matches)) {
            $content = trim($matches[1]);
        }
        elseif (preg_match('/^\s*\d+、\s*(.+)$/u', $trimmedLine, $matches)) {
            $content = trim($matches[1]);
        }
        elseif (preg_match('/^\s*\d+\s+(.+)$/u', $trimmedLine, $matches)) {
            $content = trim($matches[1]);
        }
        else {
            $content = $trimmedLine;
        }
        
        // 確保有內容（去掉編號後不能為空）
        $content = trim($content);
        // 過濾掉：空內容、只有標點、只有數字、只有數字+標點
        // 特別檢查：如果內容是 "2." 這種格式，也要過濾掉
        if ($content !== "" && 
            $content !== "." && 
            !preg_match('/^\d+\.?\s*$/', $content) &&
            !preg_match('/^[\.\、\)\:]\s*$/', $content) &&
            !preg_match('/^\d+[\.\、\)\:]\s*$/', $content) &&
            // 額外檢查：確保不是只有數字+點（如 "2."）
            !preg_match('/^\d+\.$/', $content)) {
            $cleanLines[] = $newNumber . ". " . $content;
            $newNumber++;
        }
    }
    
    if (count($cleanLines) == 0) {
        respond(["success" => false, "msg" => "內容無有效建議"]);
    }
    
    // 合併所有行，用換行符連接
    $finalContent = implode("\n", $cleanLines);
    $finalContent = rtrim($finalContent, "\n\r");

    // 驗證 suggest_status
    if ($suggest_status !== null && $suggest_status !== "") {
        $suggest_status = (int)$suggest_status;
        if (!in_array($suggest_status, [1, 2, 3, 4])) {
            $suggest_status = null; // 不更新狀態
        }
    } else {
        $suggest_status = null; // 不更新狀態
    }
    
    // 獲取當前建議的舊 sf_ID，並檢查是否已送交科辦（召集人不可再編輯）
    $oldSfIdStmt = $conn->prepare("SELECT sf_ID FROM suggest WHERE suggest_ID = ?");
    $oldSfIdStmt->execute([$suggest_ID]);
    $oldSuggest = $oldSfIdStmt->fetch(PDO::FETCH_ASSOC);
    $old_sf_ID = $oldSuggest ? $oldSuggest['sf_ID'] : null;
    if ($old_sf_ID && is_convener_form_locked($conn, $role_ID, $old_sf_ID, $u_ID)) {
        respond(["success" => false, "msg" => "此建議表已送交科辦，召集人僅可查看，不能再新增或編輯。"]);
    }
    
    // 檢查或創建 suggestfrom 記錄
    $sf_ID = null;
    if (trim($suggest_name) !== "") {
        // 先檢查是否已存在相同標題的 suggestfrom 記錄
        $checkSql = "SELECT sf_ID FROM suggestfrom WHERE sf_name = ? LIMIT 1";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->execute([$suggest_name]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // 使用已存在的 sf_ID
            $sf_ID = $existing['sf_ID'];
            // 更新 suggestfrom 的更新時間
            $updateSql = "UPDATE suggestfrom SET sf_update_d = NOW() WHERE sf_ID = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([$sf_ID]);
        } else {
            // 新標題不存在，檢查是否可以更新舊的 suggestfrom 記錄
            if ($old_sf_ID) {
                // 檢查是否有其他組別使用這個 sf_ID（除了當前建議）
                $checkOtherTeamsStmt = $conn->prepare("
                    SELECT COUNT(*) as count 
                    FROM suggest 
                    WHERE sf_ID = ? AND suggest_ID != ? AND suggest_status IN (1, 2, 3, 4)
                ");
                $checkOtherTeamsStmt->execute([$old_sf_ID, $suggest_ID]);
                $otherTeamsResult = $checkOtherTeamsStmt->fetch(PDO::FETCH_ASSOC);
                
                // 獲取舊的 suggestfrom 記錄的標題
                $getOldTitleStmt = $conn->prepare("SELECT sf_name FROM suggestfrom WHERE sf_ID = ?");
                $getOldTitleStmt->execute([$old_sf_ID]);
                $oldTitleResult = $getOldTitleStmt->fetch(PDO::FETCH_ASSOC);
                $oldTitle = $oldTitleResult ? $oldTitleResult['sf_name'] : null;
                
                // 如果標題改變了，且舊的 sf_ID 存在，統一更新所有使用這個 sf_ID 的建議的標題
                if ($oldTitle && $oldTitle !== $suggest_name) {
                    // 更新舊的 suggestfrom 記錄的標題（這樣所有使用這個 sf_ID 的建議都會顯示新標題）
                    $updateTitleSql = "UPDATE suggestfrom SET sf_name = ?, sf_update_d = NOW() WHERE sf_ID = ?";
                    $updateTitleStmt = $conn->prepare($updateTitleSql);
                    $updateTitleStmt->execute([$suggest_name, $old_sf_ID]);
                    $sf_ID = $old_sf_ID;
                } else {
                    // 標題沒有改變，或者舊標題不存在，使用舊的 sf_ID
                    $sf_ID = $old_sf_ID;
                }
            } else {
                // 沒有舊的 sf_ID，創建新的 suggestfrom 記錄
                $insertSql = "INSERT INTO suggestfrom (sf_name, sf_u_ID, sf_created_d, sf_update_d) 
                              VALUES (?, ?, NOW(), NOW())";
                $insertStmt = $conn->prepare($insertSql);
                $insertStmt->execute([$suggest_name, $u_ID]);
                $sf_ID = $conn->lastInsertId();
            }
        }
    }
    
    // 召集人：須在當屆召集人名單且未送交過才可編輯目標建議表
    if ((int)$role_ID === 7 && $sf_ID) {
        $can = convener_can_edit_suggest_form($conn, $sf_ID, $u_ID);
        if (!$can["ok"]) {
            respond(["success" => false, "msg" => $can["msg"]]);
        }
    }
    
    $hasSuggestScoreCol = $conn->query("SHOW COLUMNS FROM suggest LIKE 'suggest_score'")->rowCount() > 0;
    // 更新 suggest 資料（同時更新 suggest_u_ID 以記錄最後編輯人）
    if ($suggest_status !== null) {
        if ($hasSuggestScoreCol) {
            $sql = "UPDATE suggest 
                    SET sf_ID = ?, suggest_u_ID = ?, suggest_comment = ?, suggest_status = ?, suggest_score = ?, suggest_d = NOW() 
                    WHERE suggest_ID = ? AND team_ID = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$sf_ID, $u_ID, $finalContent, $suggest_status, $suggest_score, $suggest_ID, $team_ID]);
        } else {
            $sql = "UPDATE suggest 
                    SET sf_ID = ?, suggest_u_ID = ?, suggest_comment = ?, suggest_status = ?, suggest_d = NOW() 
                    WHERE suggest_ID = ? AND team_ID = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$sf_ID, $u_ID, $finalContent, $suggest_status, $suggest_ID, $team_ID]);
        }
    } else {
        if ($hasSuggestScoreCol) {
            $sql = "UPDATE suggest 
                    SET sf_ID = ?, suggest_u_ID = ?, suggest_comment = ?, suggest_score = ?, suggest_d = NOW() 
                    WHERE suggest_ID = ? AND team_ID = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$sf_ID, $u_ID, $finalContent, $suggest_score, $suggest_ID, $team_ID]);
        } else {
            $sql = "UPDATE suggest 
                    SET sf_ID = ?, suggest_u_ID = ?, suggest_comment = ?, suggest_d = NOW() 
                    WHERE suggest_ID = ? AND team_ID = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$sf_ID, $u_ID, $finalContent, $suggest_ID, $team_ID]);
        }
    }

    respond(["success" => true, "msg" => "已更新建議", "suggest_ID" => $suggest_ID]);
}

/* ==========================================
   action: deleteSuggest
   刪除該筆建議，並一併刪除 pereviewdata 中此建議表+組別的所有評分（含組別與個別學生評分）
========================================== */
if ($action === "deleteSuggest") {

    $sid = (int)($_POST["suggest_ID"] ?? 0);

    if (!$sid) respond(["success" => false, "msg" => "參數錯誤"]);

    try {
        $row = $conn->prepare("SELECT sf_ID, team_ID FROM suggest WHERE suggest_ID = ? LIMIT 1");
        $row->execute([$sid]);
        $suggestRow = $row->fetch(PDO::FETCH_ASSOC);
        if ($suggestRow) {
            $sf_ID = (int)$suggestRow["sf_ID"];
            if ((int)$role_ID === 7 && $sf_ID) {
                $can = convener_can_edit_suggest_form($conn, $sf_ID, $u_ID);
                if (!$can["ok"]) {
                    respond(["success" => false, "msg" => $can["msg"]]);
                }
            }
            $team_ID = (int)$suggestRow["team_ID"];
            if ($sf_ID && $team_ID) {
                $tbl = "pereviewdata";
                $exists = $conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() > 0;
                if ($exists) {
                    $hasSf = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'sf_ID'")->rowCount() > 0;
                    $hasPeTeam = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'pe_team_ID'")->rowCount() > 0;
                    if ($hasSf && $hasPeTeam) {
                        $delPereview = $conn->prepare("DELETE FROM {$tbl} WHERE sf_ID = ? AND pe_team_ID = ?");
                        $delPereview->execute([$sf_ID, $team_ID]);
                    }
                }
            }
        }
        $stmt = $conn->prepare("DELETE FROM suggest WHERE suggest_ID = ?");
        $stmt->execute([$sid]);
        respond(["success" => true, "msg" => "已刪除"]);
    } catch (Throwable $e) {
        error_log("deleteSuggest 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "刪除失敗"]);
    }
}

/* ==========================================
   action: deleteTeamFromSuggestForm
   從建議表移除組別：刪除該組別在此建議表下的建議紀錄與所有評分（含個別學生評分）
========================================== */
if ($action === "deleteTeamFromSuggestForm") {
    $sf_ID = (int)($_POST["sf_ID"] ?? $_GET["sf_ID"] ?? 0);
    $team_ID = (int)($_POST["team_ID"] ?? $_GET["team_ID"] ?? 0);
    if (!$sf_ID || !$team_ID) {
        respond(["success" => false, "msg" => "缺少 sf_ID 或 team_ID"]);
    }
    if ((int)$role_ID === 7) {
        $can = convener_can_edit_suggest_form($conn, $sf_ID, $u_ID);
        if (!$can["ok"]) {
            respond(["success" => false, "msg" => $can["msg"]]);
        }
    }
    try {
        $tbl = "pereviewdata";
        $exists = $conn->query("SHOW TABLES LIKE '{$tbl}'")->rowCount() > 0;
        if ($exists) {
            $hasSf = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'sf_ID'")->rowCount() > 0;
            $hasPeTeam = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'pe_team_ID'")->rowCount() > 0;
            if ($hasSf && $hasPeTeam) {
                $delPereview = $conn->prepare("DELETE FROM {$tbl} WHERE sf_ID = ? AND pe_team_ID = ?");
                $delPereview->execute([$sf_ID, $team_ID]);
            }
        }
        $delSuggest = $conn->prepare("DELETE FROM suggest WHERE sf_ID = ? AND team_ID = ?");
        $delSuggest->execute([$sf_ID, $team_ID]);
        respond(["success" => true, "msg" => "已移除組別"]);
    } catch (Throwable $e) {
        error_log("deleteTeamFromSuggestForm 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "移除失敗"]);
    }
}

/* ==========================================
   action: updateSuggestSortNo
   更新建議表的排序順序（suggest_sort_no）
========================================== */
if ($action === "updateSuggestSortNo") {
    
    $cohort_ID = $_POST["cohort_ID"] ?? 0;
    $title = $_POST["title"] ?? "";
    $team_orders_raw = $_POST["team_orders"] ?? "";
    // 前端以 JSON 傳入，需解碼為陣列
    $team_orders = is_string($team_orders_raw) ? (json_decode($team_orders_raw, true) ?? []) : (is_array($team_orders_raw) ? $team_orders_raw : []);
    
    if (!$cohort_ID || !$title) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    
    if (!is_array($team_orders) || count($team_orders) === 0) {
        respond(["success" => false, "msg" => "組別順序為空"]);
    }
    
    try {
        // 僅阻擋召集人，科辦仍可調整排序
        $checkTitleSql = "SELECT sf_ID FROM suggestfrom WHERE sf_name = ? LIMIT 1";
        $checkTitleStmt = $conn->prepare($checkTitleSql);
        $checkTitleStmt->execute([$title]);
        $titleResult = $checkTitleStmt->fetch(PDO::FETCH_ASSOC);
        if ($titleResult) {
            $locked_sf_ID = $titleResult['sf_ID'];
            if ((int)$role_ID === 7 && $locked_sf_ID) {
                $can = convener_can_edit_suggest_form($conn, $locked_sf_ID, $u_ID);
                if (!$can["ok"]) {
                    respond(["success" => false, "msg" => $can["msg"]]);
                }
            }
        }
        // 獲取標題對應的 sf_ID
        $sf_ID = null;
        $checkTitleSql = "SELECT sf_ID FROM suggestfrom WHERE sf_name = ? LIMIT 1";
        $checkTitleStmt = $conn->prepare($checkTitleSql);
        $checkTitleStmt->execute([$title]);
        $titleResult = $checkTitleStmt->fetch(PDO::FETCH_ASSOC);
        if ($titleResult) {
            $sf_ID = $titleResult['sf_ID'];
        } else {
            respond(["success" => false, "msg" => "找不到該標題"]);
        }
        
        // 開始事務
        $conn->beginTransaction();
        
        // 先清理該標題下所有 suggest_sort_no 為 0 的記錄（設為 NULL）
        $cleanZeroSql = "UPDATE suggest s
                        JOIN teamdata t ON s.team_ID = t.team_ID
                        SET s.suggest_sort_no = NULL
                        WHERE s.sf_ID = ?
                          AND s.suggest_sort_no = 0
                          AND t.cohort_ID = ?
                          AND s.suggest_status IN (1, 2, 3, 4)";
        $cleanZeroStmt = $conn->prepare($cleanZeroSql);
        $cleanZeroStmt->execute([$sf_ID, $cohort_ID]);
        $cleanedCount = $cleanZeroStmt->rowCount();
        if ($cleanedCount > 0) {
            error_log("清理了 {$cleanedCount} 筆 suggest_sort_no 為 0 的記錄");
        }
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($team_orders as $order) {
            $team_ID = isset($order['team_ID']) ? (int)$order['team_ID'] : 0;
            $sort_no = isset($order['sort_no']) ? (int)$order['sort_no'] : 0;
            
            if (!$team_ID || $sort_no <= 0) {
                $errorCount++;
                continue;
            }
            
            // 更新該組別在該標題下的建議的 suggest_sort_no
            // 只更新該組別在該屆別下的最新建議（suggest_status IN (1,2,3,4)）
            $updateSql = "UPDATE suggest s
                         JOIN teamdata t ON s.team_ID = t.team_ID
                         SET s.suggest_sort_no = ?
                         WHERE s.sf_ID = ?
                           AND s.team_ID = ?
                           AND t.cohort_ID = ?
                           AND s.suggest_status IN (1, 2, 3, 4)
                         ORDER BY s.suggest_ID DESC
                         LIMIT 1";
            
            $updateStmt = $conn->prepare($updateSql);
            $updateResult = $updateStmt->execute([$sort_no, $sf_ID, $team_ID, $cohort_ID]);
            
            if ($updateResult) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }
        
        // 提交事務
        $conn->commit();
        
        respond([
            "success" => true, 
            "msg" => "已更新排序順序",
            "success_count" => $successCount,
            "error_count" => $errorCount
        ]);
        
    } catch (Exception $e) {
        // 回滾事務
        $conn->rollBack();
        error_log("updateSuggestSortNo 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "更新排序順序失敗：" . $e->getMessage()]);
    }
}

/* ==========================================
   action: fixZeroSortNo
   修復 suggest_sort_no 為 0 的記錄（將 0 設為 NULL）
========================================== */
if ($action === "fixZeroSortNo") {
    
    $cohort_ID = $_POST["cohort_ID"] ?? $_GET["cohort_ID"] ?? 0;
    $title = $_POST["title"] ?? $_GET["title"] ?? "";
    
    if (!$cohort_ID || !$title) {
        respond(["success" => false, "msg" => "缺少參數"]);
    }
    
    try {
        // 獲取標題對應的 sf_ID
        $sf_ID = null;
        $checkTitleSql = "SELECT sf_ID FROM suggestfrom WHERE sf_name = ? LIMIT 1";
        $checkTitleStmt = $conn->prepare($checkTitleSql);
        $checkTitleStmt->execute([$title]);
        $titleResult = $checkTitleStmt->fetch(PDO::FETCH_ASSOC);
        if ($titleResult) {
            $sf_ID = $titleResult['sf_ID'];
        } else {
            respond(["success" => false, "msg" => "找不到該標題"]);
        }
        
        // 將該標題下所有 suggest_sort_no 為 0 的記錄設為 NULL
        $updateSql = "UPDATE suggest s
                     JOIN teamdata t ON s.team_ID = t.team_ID
                     SET s.suggest_sort_no = NULL
                     WHERE s.sf_ID = ?
                       AND s.suggest_sort_no = 0
                       AND t.cohort_ID = ?
                       AND s.suggest_status IN (1, 2, 3, 4)";
        
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$sf_ID, $cohort_ID]);
        $affectedRows = $updateStmt->rowCount();
        
        respond([
            "success" => true, 
            "msg" => "已修復 {$affectedRows} 筆記錄",
            "affected_rows" => $affectedRows
        ]);
        
    } catch (Exception $e) {
        error_log("fixZeroSortNo 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "修復失敗：" . $e->getMessage()]);
    }
}

/* ==========================================
   action: initSuggestSortNo
   初始化舊資料的 suggest_sort_no（依 suggest_d 補成連號）
   規則：同一個 sf_ID，依 suggest_d ASC 補 1,2,3...
   可以通過 sf_ID 或 title 來初始化
========================================== */
if ($action === "initSuggestSortNo") {
    
    $sf_ID = $_POST["sf_ID"] ?? $_GET["sf_ID"] ?? 0;
    $title = $_POST["title"] ?? $_GET["title"] ?? "";
    
    // 如果沒有 sf_ID 但有 title，通過 title 獲取 sf_ID
    if (!$sf_ID && $title) {
        $checkTitleSql = "SELECT sf_ID FROM suggestfrom WHERE sf_name = ? LIMIT 1";
        $checkTitleStmt = $conn->prepare($checkTitleSql);
        $checkTitleStmt->execute([$title]);
        $titleResult = $checkTitleStmt->fetch(PDO::FETCH_ASSOC);
        if ($titleResult) {
            $sf_ID = $titleResult['sf_ID'];
        }
    }
    
    if (!$sf_ID) {
        respond(["success" => false, "msg" => "缺少 sf_ID 或 title 參數"]);
    }
    
    try {
        // 開始事務
        $conn->beginTransaction();
        
        // 獲取該 sf_ID 下所有建議，按 suggest_d ASC 排序
        $selectSql = "SELECT suggest_ID, suggest_d 
                      FROM suggest 
                      WHERE sf_ID = ? 
                        AND suggest_status IN (1, 2, 3, 4)
                      ORDER BY suggest_d ASC";
        $selectStmt = $conn->prepare($selectSql);
        $selectStmt->execute([$sf_ID]);
        $suggests = $selectStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($suggests) === 0) {
            $conn->rollBack();
            respond(["success" => false, "msg" => "找不到該標題下的建議"]);
        }
        
        $updatedCount = 0;
        $sortNo = 1;
        
        // 依建立時間順序，補上連號的 suggest_sort_no
        foreach ($suggests as $suggest) {
            $suggest_ID = $suggest['suggest_ID'];
            
            $updateSql = "UPDATE suggest SET suggest_sort_no = ? WHERE suggest_ID = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([$sortNo, $suggest_ID]);
            
            $updatedCount++;
            $sortNo++;
        }
        
        // 提交事務
        $conn->commit();
        
        respond([
            "success" => true, 
            "msg" => "已初始化 {$updatedCount} 筆記錄的 suggest_sort_no",
            "updated_count" => $updatedCount
        ]);
        
    } catch (Exception $e) {
        // 回滾事務
        $conn->rollBack();
        error_log("initSuggestSortNo 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "初始化失敗：" . $e->getMessage()]);
    }
}

/* ==========================================
   action: initAllSuggestSortNo
   初始化所有舊資料的 suggest_sort_no（依 suggest_d 補成連號）
   規則：同一個 sf_ID，依 suggest_d ASC 補 1,2,3...
========================================== */
if ($action === "initAllSuggestSortNo") {
    
    try {
        // 開始事務
        $conn->beginTransaction();
        
        // 獲取所有 sf_ID
        $sfIdsSql = "SELECT DISTINCT sf_ID FROM suggest WHERE suggest_status IN (1, 2, 3, 4)";
        $sfIdsStmt = $conn->prepare($sfIdsSql);
        $sfIdsStmt->execute();
        $sfIds = $sfIdsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $totalUpdated = 0;
        $processedSfIds = 0;
        
        foreach ($sfIds as $sf_ID) {
            // 獲取該 sf_ID 下所有建議，按 suggest_d ASC 排序
            $selectSql = "SELECT suggest_ID, suggest_d 
                          FROM suggest 
                          WHERE sf_ID = ? 
                            AND suggest_status IN (1, 2, 3, 4)
                          ORDER BY suggest_d ASC";
            $selectStmt = $conn->prepare($selectSql);
            $selectStmt->execute([$sf_ID]);
            $suggests = $selectStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($suggests) === 0) {
                continue;
            }
            
            $sortNo = 1;
            
            // 依建立時間順序，補上連號的 suggest_sort_no
            foreach ($suggests as $suggest) {
                $suggest_ID = $suggest['suggest_ID'];
                
                $updateSql = "UPDATE suggest SET suggest_sort_no = ? WHERE suggest_ID = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->execute([$sortNo, $suggest_ID]);
                
                $totalUpdated++;
                $sortNo++;
            }
            
            $processedSfIds++;
        }
        
        // 提交事務
        $conn->commit();
        
        respond([
            "success" => true, 
            "msg" => "已初始化 {$totalUpdated} 筆記錄的 suggest_sort_no（共處理 {$processedSfIds} 個標題）",
            "updated_count" => $totalUpdated,
            "processed_sf_ids" => $processedSfIds
        ]);
        
    } catch (Exception $e) {
        // 回滾事務
        $conn->rollBack();
        error_log("initAllSuggestSortNo 錯誤: " . $e->getMessage());
        respond(["success" => false, "msg" => "初始化失敗：" . $e->getMessage()]);
    }
}

/* ==========================================
   action 不存在
========================================== */
respond(["success" => false, "msg" => "未知 action"]);
