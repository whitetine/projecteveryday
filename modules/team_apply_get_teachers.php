<?php
// modules/team_apply_get_teachers.php (或 api.php 的一部分)
session_start();
require_once __DIR__ . '/../includes/pdo.php';

// 確保有登入
if (!isset($_SESSION['u_ID'])) {
    json_err('請先登入');
    exit;
}

$u_ID = $_SESSION['u_ID'];

try {
    // ============================================================
    // 0️⃣ 關鍵修改：先找出學生（使用者）目前所屬的「最大屆別 (Current Cohort)」
    //    根據您提供的 enrollmendata 圖片，我們找該學生最大的 cohort_ID
    // ============================================================
    $stmt = $conn->prepare("
        SELECT MAX(cohort_ID) 
        FROM enrollmentdata 
        WHERE enroll_u_ID = ? 
          AND enroll_status = 1 
          -- AND role_ID = 6  <-- 如果需要限定只看學生身分可加這行
    ");
    $stmt->execute([$u_ID]);
    $current_cohort_ID = $stmt->fetchColumn();

    if (!$current_cohort_ID) {
        // 如果學生根本沒有學籍資料，就回傳空陣列或錯誤
        json_ok(['teachers' => [], 'msg' => '找不到您的屆別資訊']);
        exit;
    }

    // ============================================================
    // 1️⃣ 抓老師基本資料
    //    條件：
    //    1. role_ID = 4 (老師)
    //    2. enroll_status = 1 (在職)
    //    3. cohort_ID = 學生的最大屆別 (確保老師也有在這一屆授課)
    // ============================================================
    $sqlTeachers = "
        SELECT DISTINCT u.u_ID, u.u_name
        FROM userdata u
        JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID
        WHERE e.role_ID = 4
          AND e.enroll_status = 1
          AND e.cohort_ID = ?  /* 這裡帶入剛剛查到的 current_cohort_ID */
        ORDER BY u.u_name
    ";

    $stmt = $conn->prepare($sqlTeachers);
    $stmt->execute([$current_cohort_ID]);
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$teachers) {
        json_ok(['teachers' => []]);
        exit;
    }

    // 建立一個老師 ID 列表，優化後續查詢 (選用)
    // $teacherIds = array_column($teachers, 'u_ID');

    // ============================================================
    // 2️⃣ 計算「審核中」團隊數 (Pending)
    //    邏輯：計算 teamapply 表中，老師是審核者的數量
    //    嚴謹做法：應該也要確認發起申請的學生是否屬於同一屆，或者只算 status=1
    // ============================================================
    $sqlPending = "
        SELECT tap_teacher AS teacher_id, COUNT(*) AS pending_cnt
        FROM teamapply
        WHERE tap_status = 1
        /* 如果 teamapply 沒有 cohort_ID 欄位，我們通常假設 status=1 就是當屆的。
           但如果想更嚴謹，可以用 tap_u_ID (申請人) 去 join enrollmentdata 檢查屆別，
           不過通常只要檢查 tap_status = 1 即可。
        */
        GROUP BY tap_teacher
    ";
    
    // 注意：這裡如果只抓該屆老師，其實不用 WHERE 過濾老師，
    // 因為最後我們是用 foreach ($teachers) 去 map 資料，沒在名單內的老師資料會自然被忽略。
    $stmt = $conn->prepare($sqlPending);
    $stmt->execute();

    $pendingMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pendingMap[$row['teacher_id']] = (int)$row['pending_cnt'];
    }

    // ============================================================
    // 3️⃣ 計算「已成團」團隊數 (Confirmed) - 這是您最在意的地方
    //    條件：
    //    1. teammember 關聯出老師
    //    2. teamdata 狀態有效 (team_status = 1)
    //    3. ★★★ teamdata.cohort_ID 必須等於學生的 current_cohort_ID ★★★
    // ============================================================
    $sqlTeams = "
        SELECT tm.team_u_ID AS teacher_id,
               COUNT(DISTINCT tm.team_ID) AS team_cnt
        FROM teammember tm
        JOIN teamdata t ON t.team_ID = tm.team_ID
        WHERE t.team_status = 1
          AND t.cohort_ID = ?  /* 這裡帶入 current_cohort_ID，只計算這一屆的組別！ */
        GROUP BY tm.team_u_ID
    ";

    $stmt = $conn->prepare($sqlTeams);
    $stmt->execute([$current_cohort_ID]);

    $teamMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $teamMap[$row['teacher_id']] = (int)$row['team_cnt'];
    }

    // ============================================================
    // 4️⃣ 整合資料
    // ============================================================
    foreach ($teachers as &$t) {
        $id = $t['u_ID'];
        // 如果該老師 ID 在 pendingMap 有值就用，否則為 0
        $t['pending_count'] = $pendingMap[$id] ?? 0;
        // 如果該老師 ID 在 teamMap 有值就用，否則為 0
        $t['team_count']    = $teamMap[$id] ?? 0;
    }

    json_ok(['teachers' => $teachers]);

} catch (Exception $e) {
    json_err('載入老師列表失敗：' . $e->getMessage());
}
?>