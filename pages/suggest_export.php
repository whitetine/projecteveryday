<?php
session_start();
require "../includes/pdo.php";
date_default_timezone_set("Asia/Taipei");

/* ==========================================
   權限：
   - 主任 (role_ID = 1) 和 科辦 (role_ID = 2)：可以瀏覽和下載
   - 其他用戶（學生、老師、班導等）：可以瀏覽和下載（收到通知的用戶都可以訪問）
========================================== */
$role_ID = $_SESSION["role_ID"] ?? null;

// 權限檢查：所有已登入用戶都可以訪問此頁面（包括收到通知的用戶）
if (!isset($role_ID)) {
    die("請先登入");
}

$cohort_ID = $_GET["cohort_ID"] ?? 0;
$group_ID = $_GET["group_ID"] ?? 0;

if (!$cohort_ID) {
    die("缺少參數");
}

// 處理 group_ID="all" 的情況
$is_all_groups = ($group_ID === "all" || $group_ID === "0" || empty($group_ID));

// 檢查欄位名稱
function columnExists(PDO $conn, string $table, string $column): bool {
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$teamUserField = columnExists($conn, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
$userRoleUidField = columnExists($conn, 'userrolesdata', 'ur_u_ID') ? 'ur_u_ID' : 'u_ID';

// 取得類組名稱
if ($is_all_groups) {
    $group_name = '全部';
} else {
    $stmt = $conn->prepare("SELECT group_name FROM groupdata WHERE group_ID = ?");
    $stmt->execute([$group_ID]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    $group_name = $group['group_name'] ?? '';
}

// 取得屆別名稱
$stmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ?");
$stmt->execute([$cohort_ID]);
$cohort = $stmt->fetch(PDO::FETCH_ASSOC);
$cohort_name = $cohort['cohort_name'] ?? '';

// 取得標題：優先使用 URL 參數傳來的標題，如果沒有才使用資料庫的 sf_name
$page_title = '';
$user_title = $_GET["title"] ?? "";

if (!empty($user_title) && trim($user_title) !== '') {
    // 使用使用者輸入的標題
    $page_title = trim($user_title);
} else {
    // 從該屆別和類組的所有建議中取得最新的標題（通過 suggestfrom 表關聯）
    if ($is_all_groups) {
        $stmt = $conn->prepare("
            SELECT sf.sf_name 
            FROM suggest s
            JOIN suggestfrom sf ON s.sf_ID = sf.sf_ID
            JOIN teamdata t ON s.team_ID = t.team_ID
            WHERE t.cohort_ID = ? 
              AND s.suggest_status IN (1, 2, 3, 4)
              AND sf.sf_name IS NOT NULL
              AND TRIM(sf.sf_name) != ''
            ORDER BY s.suggest_d DESC, s.suggest_ID DESC
            LIMIT 1
        ");
        $stmt->execute([$cohort_ID]);
    } else {
        $stmt = $conn->prepare("
            SELECT sf.sf_name 
            FROM suggest s
            JOIN suggestfrom sf ON s.sf_ID = sf.sf_ID
            JOIN teamdata t ON s.team_ID = t.team_ID
            WHERE t.cohort_ID = ? 
              AND t.group_ID = ?
              AND s.suggest_status IN (1, 2, 3, 4)
              AND sf.sf_name IS NOT NULL
              AND TRIM(sf.sf_name) != ''
            ORDER BY s.suggest_d DESC, s.suggest_ID DESC
            LIMIT 1
        ");
        $stmt->execute([$cohort_ID, $group_ID]);
    }
    $title_result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($title_result && isset($title_result['sf_name']) && trim($title_result['sf_name']) !== '') {
        $page_title = trim($title_result['sf_name']);
    }
}

// 依 suggestfrom.sf_type 決定表頭：topic=初審建議，review=審查結果
$result_column_label = '審查結果';
if (!empty($page_title)) {
    try {
        $chk = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_type'");
        if ($chk && $chk->rowCount() > 0) {
            $st = $conn->prepare("SELECT sf_type FROM suggestfrom WHERE sf_name = ? LIMIT 1");
            $st->execute([$page_title]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['sf_type']) && $row['sf_type'] === 'topic') {
                $result_column_label = '初審建議';
            }
        }
    } catch (Throwable $e) { /* 保持審查結果 */ }
}

// 檢查是否有指定要匯出的團隊ID列表
$team_ids_param = $_GET["team_ids"] ?? "";
$team_ids = [];
if (!empty($team_ids_param)) {
    $team_ids = json_decode($team_ids_param, true);
    if (!is_array($team_ids)) {
        $team_ids = [];
    }
}

// 獲取該屆別時間最近的時程表 ID（用於獲取時程表的 sort_no）
// 使用建議表的建立時間來找到時間最近的時程表
$latestTinformaId = null;
try {
    if (!empty($page_title)) {
        // 獲取建議表的建立時間
        $getSuggestCreatedSql = "SELECT sf_created_d FROM suggestfrom WHERE sf_name = ? LIMIT 1";
        $getSuggestCreatedStmt = $conn->prepare($getSuggestCreatedSql);
        $getSuggestCreatedStmt->execute([$page_title]);
        $suggestCreatedData = $getSuggestCreatedStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($suggestCreatedData && isset($suggestCreatedData['sf_created_d'])) {
            $suggest_created_d = $suggestCreatedData['sf_created_d'];
            
            // 使用建議表的建立時間，找到時間最近的時程表（在該時間之前或最接近）
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
                $latestTinformaId = $latestTinforma['tinforma_ID'];
            }
        }
    }
    
    // 如果找不到，使用最新的時程表作為 fallback
    if (!$latestTinformaId) {
        $getLatestTinformaSql = "SELECT tinforma_ID FROM timeinformadata 
                                 WHERE tinforma_ID IN (
                                     SELECT DISTINCT td.tinforma_ID 
                                     FROM timedata td
                                     JOIN teamdata t ON td.team_ID = t.team_ID
                                     WHERE t.cohort_ID = ?
                                 )
                                 ORDER BY COALESCE(tinforma_update_d, tinforma_create_d) DESC
                                 LIMIT 1";
        $getLatestTinformaStmt = $conn->prepare($getLatestTinformaSql);
        $getLatestTinformaStmt->execute([$cohort_ID]);
        $latestTinforma = $getLatestTinformaStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($latestTinforma && isset($latestTinforma['tinforma_ID'])) {
            $latestTinformaId = $latestTinforma['tinforma_ID'];
        }
    }
} catch (Exception $e) {
    error_log("獲取時程表 ID 失敗: " . $e->getMessage());
}

// 取得該屆別和類組的團隊（如果指定了團隊ID列表，只取得這些團隊）
if (!empty($team_ids) && count($team_ids) > 0) {
    // 只取得指定的團隊
    $placeholders = implode(',', array_fill(0, count($team_ids), '?'));
    if ($is_all_groups) {
        // 如果是全部類組，不限制 group_ID
        if ($latestTinformaId) {
            $sql = "SELECT 
                        t.team_ID,
                        t.team_project_name,
                        td.sort_no as time_sort_no
                    FROM teamdata t
                    LEFT JOIN timedata td ON td.team_ID = t.team_ID 
                        AND td.tinforma_ID = ?
                    WHERE t.cohort_ID = ?
                      AND t.team_status = 1
                      AND t.team_ID IN ($placeholders)
                    ORDER BY t.team_ID";
            $params = array_merge([$latestTinformaId, $cohort_ID], $team_ids);
        } else {
            $sql = "SELECT 
                        t.team_ID,
                        t.team_project_name,
                        NULL as time_sort_no
                    FROM teamdata t
                    WHERE t.cohort_ID = ?
                      AND t.team_status = 1
                      AND t.team_ID IN ($placeholders)
                    ORDER BY t.team_ID";
            $params = array_merge([$cohort_ID], $team_ids);
        }
    } else {
        // 指定類組
        if ($latestTinformaId) {
            $sql = "SELECT 
                        t.team_ID,
                        t.team_project_name,
                        td.sort_no as time_sort_no
                    FROM teamdata t
                    LEFT JOIN timedata td ON td.team_ID = t.team_ID 
                        AND td.tinforma_ID = ?
                    WHERE t.cohort_ID = ?
                      AND t.group_ID = ?
                      AND t.team_status = 1
                      AND t.team_ID IN ($placeholders)
                    ORDER BY t.team_ID";
            $params = array_merge([$latestTinformaId, $cohort_ID, $group_ID], $team_ids);
        } else {
            $sql = "SELECT 
                        t.team_ID,
                        t.team_project_name,
                        NULL as time_sort_no
                    FROM teamdata t
                    WHERE t.cohort_ID = ?
                      AND t.group_ID = ?
                      AND t.team_status = 1
                      AND t.team_ID IN ($placeholders)
                    ORDER BY t.team_ID";
            $params = array_merge([$cohort_ID, $group_ID], $team_ids);
        }
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // 取得該屆別和類組的所有團隊
    if ($is_all_groups) {
        // 如果是全部類組，取得該屆別的所有團隊
        if ($latestTinformaId) {
            $sql = "SELECT 
                        t.team_ID,
                        t.team_project_name,
                        td.sort_no as time_sort_no
                    FROM teamdata t
                    LEFT JOIN timedata td ON td.team_ID = t.team_ID 
                        AND td.tinforma_ID = ?
                    WHERE t.cohort_ID = ?
                      AND t.team_status = 1
                    ORDER BY t.group_ID, t.team_ID";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$latestTinformaId, $cohort_ID]);
        } else {
            $sql = "SELECT 
                        t.team_ID,
                        t.team_project_name,
                        NULL as time_sort_no
                    FROM teamdata t
                    WHERE t.cohort_ID = ?
                      AND t.team_status = 1
                    ORDER BY t.group_ID, t.team_ID";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$cohort_ID]);
        }
    } else {
        // 指定類組
        if ($latestTinformaId) {
            $sql = "SELECT 
                        t.team_ID,
                        t.team_project_name,
                        td.sort_no as time_sort_no
                    FROM teamdata t
                    LEFT JOIN timedata td ON td.team_ID = t.team_ID 
                        AND td.tinforma_ID = ?
                    WHERE t.cohort_ID = ?
                      AND t.group_ID = ?
                      AND t.team_status = 1
                    ORDER BY t.team_ID";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$latestTinformaId, $cohort_ID, $group_ID]);
        } else {
            $sql = "SELECT 
                        t.team_ID,
                        t.team_project_name,
                        NULL as time_sort_no
                    FROM teamdata t
                    WHERE t.cohort_ID = ?
                      AND t.group_ID = ?
                      AND t.team_status = 1
                    ORDER BY t.team_ID";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$cohort_ID, $group_ID]);
        }
    }
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 如果有提供 team_order 參數，按照指定順序排序
$team_order = $_GET["team_order"] ?? "";

// 取得每個團隊的成員和建議
$teamData = [];
foreach ($teams as $team) {
    $team_ID = $team['team_ID'];
    $time_sort_no = $team['time_sort_no'] ?? null;
    
    // 取得團隊成員（只取得學生，role_ID = 6）
    $sql = "SELECT 
                tm.{$teamUserField} AS u_ID,
                COALESCE(ud.u_name, tm.{$teamUserField}) AS u_name
            FROM teammember tm
            JOIN userrolesdata ur 
                  ON ur.{$userRoleUidField} = tm.{$teamUserField}
                 AND ur.role_ID = 6
                 AND ur.user_role_status = 1
            LEFT JOIN userdata ud ON ud.u_ID = tm.{$teamUserField}
            WHERE tm.team_ID = ?
              AND tm.tm_status = 1
            GROUP BY tm.{$teamUserField}, ud.u_name
            ORDER BY tm.{$teamUserField}";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$team_ID]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 取得最新建議（包含標題和狀態）
    // 如果有指定標題，使用標題來過濾建議
    $suggest_comment = '';
    $suggest_status = null;
    $suggest_name = null;
    $suggest_ID = null;
    $suggest_sort_no = null; // 新增：儲存 suggest_sort_no 用於排序
    
    if (!empty($page_title)) {
        // 使用標題來過濾建議
        $sql = "SELECT s.suggest_ID, s.suggest_comment, s.suggest_status, s.suggest_sort_no, sf.sf_name
                FROM suggest s
                LEFT JOIN suggestfrom sf ON s.sf_ID = sf.sf_ID
                WHERE s.team_ID = ? 
                  AND s.suggest_status IN (1, 2, 3, 4)
                  AND sf.sf_name = ?
                ORDER BY s.suggest_d DESC, s.suggest_ID DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$team_ID, $page_title]);
    } else {
        // 沒有指定標題，取得最新的建議
        $sql = "SELECT s.suggest_ID, s.suggest_comment, s.suggest_status, s.suggest_sort_no, sf.sf_name
                FROM suggest s
                LEFT JOIN suggestfrom sf ON s.sf_ID = sf.sf_ID
                WHERE s.team_ID = ? 
                  AND s.suggest_status IN (1, 2, 3, 4)
                ORDER BY s.suggest_d DESC, s.suggest_ID DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$team_ID]);
    }
    
    $suggest = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($suggest) {
        $suggest_comment = $suggest['suggest_comment'] ?? '';
        $suggest_status = $suggest['suggest_status'] ?? null;
        $suggest_name = $suggest['sf_name'] ?? null;
        $suggest_ID = $suggest['suggest_ID'] ?? null;
        $suggest_sort_no = $suggest['suggest_sort_no'] ?? null;
    }
    
    // 狀態對應文字
    $status_text = '—';
    $is_topic = ($result_column_label === '初審建議');
    if ($suggest_status == 1) {
        $status_text = $is_topic ? '修改' : '修改後通過';
    } elseif ($suggest_status == 2) {
        $status_text = '不通過';
    } elseif ($suggest_status == 3) {
        $status_text = '通過';
    } elseif ($suggest_status == 4) {
        $status_text = $is_topic ? '待確認' : '修改後複評';
    }
    
    // 計算實際使用的排序值：優先使用 suggest_sort_no，如果為空或 0，則使用 time_sort_no
    $final_sort_no = null;
    if ($suggest_sort_no !== null && $suggest_sort_no !== '' && $suggest_sort_no > 0) {
        $final_sort_no = (int)$suggest_sort_no;
    } elseif ($time_sort_no !== null && $time_sort_no !== '' && $time_sort_no > 0) {
        $final_sort_no = (int)$time_sort_no;
    }
    
    $teamData[] = [
        'team_ID' => $team_ID,
        'project_name' => $team['team_project_name'],
        'members' => $members,
        'suggest' => $suggest_comment,
        'status' => $status_text,
        'status_code' => $suggest_status, // 儲存狀態代碼，用於判斷顏色
        'suggest_name' => $suggest_name,
        'final_sort_no' => $final_sort_no // 儲存最終排序值
    ];
}

// 如果有提供 team_order 參數，按照指定順序排序（選擇"全部"時，使用各組各自的排序順序）
if (!empty($team_order)) {
    $order_array = json_decode($team_order, true);
    if (is_array($order_array) && count($order_array) > 0) {
        // 建立順序映射
        $order_map = array_flip($order_array);
        // 按照指定順序排序
        usort($teamData, function($a, $b) use ($order_map) {
            $a_id = (int)$a['team_ID'];
            $b_id = (int)$b['team_ID'];
            $a_pos = isset($order_map[$a_id]) ? $order_map[$a_id] : 9999;
            $b_pos = isset($order_map[$b_id]) ? $order_map[$b_id] : 9999;
            return $a_pos <=> $b_pos;
        });
    }
} else {
    // 如果沒有提供 team_order，使用 COALESCE(suggest_sort_no, time_sort_no) 進行排序
    usort($teamData, function($a, $b) {
        $sortNoA = $a['final_sort_no'];
        $sortNoB = $b['final_sort_no'];
        
        // 如果兩個都有有效的排序值，按數值排序
        if ($sortNoA !== null && $sortNoB !== null) {
            return $sortNoA <=> $sortNoB;
        }
        // 如果只有一個有有效的排序值，有排序值的優先
        if ($sortNoA !== null) return -1;
        if ($sortNoB !== null) return 1;
        // 如果兩個都沒有有效的排序值，保持原始順序（按 team_ID）
        return $a['team_ID'] <=> $b['team_ID'];
    });
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <style>
    :root {
        --kai: "DFKai-SB", "BiauKai", "標楷體", "Microsoft JhengHei", serif;
    }

    @media print {
        @page {
            size: A4 landscape;
            margin: 1cm;
        }
    }

    body{
        font-family: var(--kai);
        color:#111;
        margin:0;
        padding:0;
        background:#fff;
    }

    /* 讓整個內容置中，產生跟圖2一樣的留白 */
    #report{
        width: 90%;
        max-width: 1000px;
        margin: 25px auto;
    }

    /* 標題：大、置中、與表格有明顯距離 */
    .title{
        font-family: var(--kai);
        text-align:center;
        font-weight:700;
        font-size:24px;
        letter-spacing:.06em;
        margin: 10px 0 25px;
    }

    /* 表格外框（很重要，圖2就是這種） */
    .table-wrapper{
        border: 2px solid #000;
        padding: 0;
        margin-top: 10px;
    }

    table{
        width:100%;
        border-collapse:collapse;
        table-layout:fixed;
        font-size:18px;
    }

     th, td{
         border:1px solid #000;
         padding:10px 12px;
     }

     thead th{
         background:#cfe3f6;
         text-align:center;
         vertical-align:middle;
         font-weight:700;
         font-family: var(--kai);
     }

     /* 欄位寬度（依照圖2調整過） */
     .col-title{ width:25%; text-align:center; vertical-align:middle; }
     .col-members{ width:23%; text-align:center; vertical-align:middle; }
     .col-result{ width:12%; text-align:center; vertical-align:middle; }
     .col-comment{ width:40%; text-align:left; line-height:1.6; vertical-align:top; }
     
     /* 確保題目、組員、審查結果都置中 */
     .project-name, .members, .review-result {
         text-align: center;
         vertical-align: middle;
     }
     
     /* 審查結果狀態樣式 */
     .review-result.status-1 {
         background-color: #d4edda; /* 修改後通過 - 綠色 */
         border-color: #28a745;
         color: #155724;
         font-weight: 700;
         padding: 4px 8px;
         border-radius: 4px;
     }
     .review-result.status-2 {
         background-color: #f8d7da; /* 不通過 - 紅色 */
         border-color: #dc3545;
         color: #721c24;
         font-weight: 700;
         padding: 4px 8px;
         border-radius: 4px;
     }
     .review-result.status-3 {
         background-color: #ffffff; /* 通過 - 白色 */
         border-color: #6c757d;
         color: #212529;
         font-weight: 700;
         padding: 4px 8px;
         border-radius: 4px;
     }
     .review-result.status-4 {
         background-color: #fff3cd; /* 修改後複評 - 黃色 */
         border-color: #ffc107;
         color: #856404;
         font-weight: 700;
         padding: 4px 8px;
         border-radius: 4px;
     }

    /* 成員欄位換行整齊 */
    .members-list{
        white-space:pre-line;
        line-height:1.5;
    }

    /* 無建議提示 */
    .no-suggest{
        color:#666;
        font-style:italic;
    }
</style>

</head>
<body>
    <div class="header">
        <?php if (!empty($page_title)): ?>
            <div class="title"><?php echo htmlspecialchars($page_title); ?></div>
        <?php else: ?>
            <div class="title" style="color: #999; font-style: italic;">（尚未設定標題）</div>
        <?php endif; ?>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>題目</th>
                <th>組員</th>
                <th><?php echo htmlspecialchars($result_column_label); ?></th>
                <th>審查意見</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($teamData as $team): ?>
            <tr>
                <td class="project-name col-title"><?php echo htmlspecialchars($team['project_name']); ?></td>
                <td class="members col-members">
                    <?php 
                    $memberList = [];
                    foreach ($team['members'] as $member) {
                        $memberList[] = htmlspecialchars($member['u_ID'] . ' ' . $member['u_name']);
                    }
                    echo implode('<br>', $memberList);
                    ?>
                </td>
                <td class="review-result col-result <?php echo isset($team['status_code']) ? 'status-' . $team['status_code'] : ''; ?>"><?php echo htmlspecialchars($team['status']); ?></td>
                <td class="suggest col-comment">
                    <?php 
                    if (!empty($team['suggest'])) {
                        // 保持換行，讓每個編號項目都在單獨的一行
                        $suggest_text = $team['suggest'];
                        // 確保編號格式正確（數字. 後面有空格）
                        $suggest_text = preg_replace('/(\d+)\.\s*/', '$1. ', $suggest_text);
                        // 使用 nl2br 將換行符號轉換為 <br> 標籤
                        echo nl2br(htmlspecialchars($suggest_text));
                    } else {
                        echo '<span class="no-suggest">（無審查意見）</span>';
                    }
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        window.onload = function() {
            // 生成檔案名稱
            const pageTitle = '<?php echo htmlspecialchars($page_title, ENT_QUOTES); ?>';
            const fileName = (pageTitle || '建議') + '_' + new Date().toISOString().slice(0, 10) + '.pdf';
            
            // 取得要轉換的元素
            const element = document.body;
            
            // 設定選項
            const opt = {
                margin: [10, 10, 10, 10],
                filename: fileName,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { 
                    scale: 2,
                    useCORS: true,
                    letterRendering: true,
                    logging: false
                },
                jsPDF: { 
                    unit: 'mm', 
                    format: 'a4', 
                    orientation: 'landscape' 
                }
            };
            
            // 生成並下載 PDF
            html2pdf().set(opt).from(element).save().then(function() {
                // PDF 下載完成後，可以選擇關閉視窗或顯示訊息
                // window.close(); // 可選：自動關閉視窗
            }).catch(function(error) {
                console.error('PDF 生成失敗:', error);
                alert('PDF 生成失敗，請使用瀏覽器的列印功能（Ctrl+P）');
            });
        };
    </script>
</body>
</html>

