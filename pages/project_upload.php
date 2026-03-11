<?php
session_start();
require '../includes/pdo.php';

// 設置時區為台灣時區，確保時間判斷正確
date_default_timezone_set('Asia/Taipei');

// 🔹 提交狀態常量定義（避免硬編碼）
define('SUBMISSION_STATUS_REJECTED', 0);    // 退件
define('SUBMISSION_STATUS_PENDING', 1);     // 未審核
define('SUBMISSION_STATUS_REVIEWING', 2);   // 審核中
define('SUBMISSION_STATUS_APPROVED', 3);    // 已通過
define('SUBMISSION_STATUS_DRAFT', 4);       // 暫存

// 檢查權限
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;
$viewID = $_GET['view'] ?? null; // 科辦端查看模式

if (!$u_ID) {
    echo '<div class="alert alert-danger">請先登入</div>';
    exit;
}

//  檢查欄位是否存在的輔助函數（用於兼容不同版本的資料表結構）
function columnExists(PDO $conn, string $table, string $column): bool {
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

// 如果有 view 參數，允許科辦端（role_ID = 1 或 2）或指導老師（role_ID = 4）訪問
if ($viewID) {
    if (!in_array($role_ID, [1, 2, 4])) {
        echo '<div class="alert alert-danger">此功能僅限主任、科辦和指導老師使用</div>';
        exit;
    }
} else {
    // 沒有 view 參數時，只有學生可以訪問
    if ($role_ID != 6) {
        echo '<div class="alert alert-danger">此頁面僅限學生使用</div>';
        exit;
    }
}

// 如果是查看模式（科辦端或指導老師），根據 viewID 獲取提交記錄
if ($viewID) {
    try {
        // 檢查是否有 intro 和 is_locked 字段
        $hasIntroField = columnExists($conn, 'prosubdata', 'prosub_intro');
        $hasLockedField = columnExists($conn, 'prosubdata', 'prosub_is_locked');
        
        // 構建 SELECT 語句
        $selectFields = [
            'ps.prosub_ID',
            'ps.prosub_img',
            'ps.prosub_other',
            'ps.content_json',
            'ps.prosub_status',
            'ps.prosub_created_d',
            'ps.prosub_update_d',
            'ps.prosub_reason',
            'ps.prosub_re_reason',
            'ps.prosub_re_d',
            'ps.team_ID'
        ];
        
        if ($hasIntroField) {
            $selectFields[] = 'ps.prosub_intro';
        }
        if ($hasLockedField) {
            $selectFields[] = 'ps.prosub_is_locked';
        }
        
        $selectFields[] = 't.team_project_name as team_name';
        
        $sql = "SELECT " . implode(', ', $selectFields) . "
            FROM prosubdata ps
            JOIN teamdata t ON ps.team_ID = t.team_ID
            WHERE ps.prosub_ID = ?
            LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$viewID]);
        $viewSubmission = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$viewSubmission) {
            echo '<div class="alert alert-danger">找不到該提交記錄</div>';
            exit;
        }
        
        // 如果是指導老師（role_ID = 4），驗證該提交記錄是否屬於他指導的團隊
        if ($role_ID == 4) {
            $teamUserField = columnExists($conn, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
            $userRoleUidField = columnExists($conn, 'userrolesdata', 'ur_u_ID') ? 'ur_u_ID' : 'u_ID';
            
            $team_ID = $viewSubmission['team_ID'];
            $checkStmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM teammember tm
                JOIN userrolesdata ur ON ur.{$userRoleUidField} = tm.{$teamUserField}
                JOIN teamdata t ON tm.team_ID = t.team_ID
                WHERE tm.{$teamUserField} = ?
                  AND tm.team_ID = ?
                  AND ur.role_ID = 4
                  AND ur.user_role_status = 1
                  AND t.team_status = 1
            ");
            $checkStmt->execute([$u_ID, $team_ID]);
            $isSupervising = $checkStmt->fetchColumn() > 0;
            
            if (!$isSupervising) {
                echo '<div class="alert alert-danger">您無權限查看此團隊的提交記錄</div>';
                exit;
            }
        }
        
        $team_ID = $viewSubmission['team_ID'];
        $team_name = $viewSubmission['team_name'] ?? '';
        $submissions = [$viewSubmission];
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">獲取提交記錄失敗</div>';
        exit;
    }
} else {
    // 學生模式：獲取學生所屬的團隊
    $team_ID = null;
    $team_name = '';

    try {
        $teamUserField = 'team_u_ID';
        $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
        $checkStmt->execute();
        if (!$checkStmt->fetch()) {
            $teamUserField = 'u_ID';
        }
        
        $stmt = $conn->prepare("
            SELECT t.team_ID, t.team_project_name as team_name
            FROM teamdata t
            JOIN teammember tm ON t.team_ID = tm.team_ID
            WHERE tm.{$teamUserField} = ? AND t.team_status = 1
            LIMIT 1
        ");
        $stmt->execute([$u_ID]);
        $team = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($team) {
            $team_ID = $team['team_ID'];
            $team_name = $team['team_name'] ?? '';
        }
    } catch (Exception $e) {
    }

    if (!$team_ID) {
        echo '<div class="alert alert-warning">您尚未加入任何團隊</div>';
        exit;
    }

    // 獲取該團隊的所有提交記錄
    try {
        // 檢查是否有 intro 和 is_locked 字段
        $hasIntroField = columnExists($conn, 'prosubdata', 'prosub_intro');
        $hasLockedField = columnExists($conn, 'prosubdata', 'prosub_is_locked');
        
        // 構建 SELECT 語句
        $selectFields = [
            'prosub_ID',
            'prosub_img',
            'prosub_other',
            'content_json',
            'prosub_status',
            'prosub_created_d',
            'prosub_update_d',
            'prosub_reason',
            'prosub_re_reason',
            'prosub_re_d',
            'team_ID'
        ];
        
        if ($hasIntroField) {
            $selectFields[] = 'prosub_intro';
        }
        if ($hasLockedField) {
            $selectFields[] = 'prosub_is_locked';
        }
        
        $sql = "SELECT " . implode(', ', $selectFields) . "
            FROM prosubdata
            WHERE team_ID = ?
            ORDER BY prosub_created_d DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$team_ID]);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $submissions = [];
    }
}

// 查找暫存記錄（狀態為 4）或查看模式
$draftSubmission = null;
$editID = $_GET['edit'] ?? null;
$isViewMode = (bool)$viewID; // 是否為查看模式（科辦端）

if ($isViewMode) {
    // 查看模式：顯示指定的提交記錄（只讀）
    $draftSubmission = $viewSubmission ?? null;
} elseif ($editID) {
    // 編輯模式：查找指定 ID 的記錄
    foreach ($submissions as $sub) {
        if ($sub['prosub_ID'] == $editID) {
            $draftSubmission = $sub;
            break;
        }
    }
} else {
    // 【問題 1 修復】頁面 GET 載入時，依 team_ID 查詢最新一筆 status=暫存 的資料
    // 已提交的資料（狀態不是 4）不再視為草稿，避免 F5 又載入舊暫存
    // 直接從資料庫查詢最新的暫存記錄，確保刷新後能載入
    try {
        // 檢查是否有 intro 和 is_locked 字段
        $hasIntroField = columnExists($conn, 'prosubdata', 'prosub_intro');
        $hasLockedField = columnExists($conn, 'prosubdata', 'prosub_is_locked');
        
        // 構建 SELECT 語句
        $selectFields = [
            'prosub_ID',
            'prosub_img',
            'prosub_other',
            'content_json',
            'prosub_status',
            'prosub_created_d',
            'prosub_update_d',
            'prosub_reason',
            'prosub_re_reason',
            'prosub_re_d',
            'team_ID'
        ];
        
        if ($hasIntroField) {
            $selectFields[] = 'prosub_intro';
        }
        if ($hasLockedField) {
            $selectFields[] = 'prosub_is_locked';
        }
        
        // 只查詢狀態為暫存的記錄（已提交的不再視為草稿）
        // 以專題/團隊層級為主：查詢該團隊最新的暫存記錄（不論 pro_ID）
        // 若該組已提交狀態存在，也不能影響暫存回填（暫存與提交資料來源分離）
        $sql = "SELECT " . implode(', ', $selectFields) . "
            FROM prosubdata
            WHERE team_ID = ? AND prosub_status = " . SUBMISSION_STATUS_DRAFT . "
            ORDER BY prosub_update_d DESC, prosub_created_d DESC
            LIMIT 1";
        
        $draftStmt = $conn->prepare($sql);
        $draftStmt->execute([$team_ID]);
        $draftRecord = $draftStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($draftRecord) {
            // 狀態 4 = 暫存，直接使用（不檢查 is_deleted）
            // 頁面載入時成功讀取暫存記錄，將用於回填表單
            $draftSubmission = $draftRecord;
            
            // 調試日誌（開發時可啟用）
            // error_log("頁面載入時查詢到暫存記錄 - prosub_ID: " . ($draftRecord['prosub_ID'] ?? 'N/A') . ", team_ID: " . $team_ID);
        } else {
            // 若該組暫存不存在，才顯示空白表單
            $draftSubmission = null;
        }
    } catch (Exception $e) {
        // 如果查詢失敗，不設置 $draftSubmission，讓表單保持空白
        // 若無草稿或資料不完整，顯示空白即可，不視為錯誤
        $draftSubmission = null;
    }
}

// 過濾出有效的提交記錄（排除正在編輯的、已刪除的、和暫存狀態的）
$validSubmissions = array_filter($submissions, function($sub) use ($editID) {
    // 如果正在編輯這條記錄，不顯示在列表中
    if ($editID && $sub['prosub_ID'] == $editID) {
        return false;
    }
    
    // 排除暫存狀態的記錄，暫存不應該顯示在提交記錄中
    $status = (int)$sub['prosub_status'];
    if ($status == SUBMISSION_STATUS_DRAFT) {
        return false;
    }
    
    // 🔹 檢查 content_json 中的 is_deleted 標記（軟刪除）
    if (!empty($sub['content_json'])) {
        $contentJson = json_decode($sub['content_json'], true);
        if (is_array($contentJson) && isset($contentJson['is_deleted']) && $contentJson['is_deleted'] === true) {
            return false; // 已刪除的記錄不顯示
        }
    }
    
    // 排除狀態 5（如果定義為已刪除狀態）
    if ($status == 5) {
        return false;
    }
    
    return true;
});

// 獲取鎖定時間（從 projectdata 獲取截止時間）
$lockTime = null;
$isTimeLocked = false;
try {
    $lockStmt = $conn->prepare("
        SELECT p.pro_end_d 
        FROM projectdata p
        INNER JOIN teamdata t ON p.pro_chorot_ID = t.cohort_ID
        WHERE t.team_ID = ? AND p.pro_status = 1 AND p.pro_end_d IS NOT NULL
        ORDER BY p.pro_created_d DESC, p.pro_ID DESC 
        LIMIT 1
    ");
    $lockStmt->execute([$team_ID]);
    $lockRecord = $lockStmt->fetch(PDO::FETCH_ASSOC);
    if ($lockRecord && $lockRecord['pro_end_d']) {
        $lockTime = $lockRecord['pro_end_d'];
        $lockDateTime = new DateTime($lockTime);
        $now = new DateTime();
        $isTimeLocked = $now > $lockDateTime;
    }
} catch (Exception $e) {
    // 忽略錯誤，繼續執行
}

// 獲取簡介內容和鎖定狀態
$introContent = '';
$isLocked = false;
$currentSubmission = null;

// 🔹 【完全動態查詢】從 projectdata 表查詢繳交時段資料（統一資料來源，不寫死任何值）
// 注意：$activePeriods 將在下方（左右兩欄之前）統一查詢，供整個頁面使用
?>
<!-- CSS 預載入 -->
<link rel="stylesheet" href="../css/project_upload.css?v=<?= time() ?>" id="projectUploadCSS" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="../css/project_upload.css?v=<?= time() ?>"></noscript>
<style>
/* SweetAlert 視窗：在本頁面不要整個背景變黑，只顯示提示框 */
.swal2-container {
    background: transparent !important;
}

/* 強制防止跑版（內聯備援，避免 CSS 快取導致仍用舊版 min-width） */
body { overflow-x: hidden !important; }
.project-upload-page {
    min-width: 0 !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
    box-sizing: border-box !important;
}
.project-upload-page .upload-card,
.project-upload-page .preview-card,
.project-upload-page .card,
.project-upload-page .row { max-width: 100% !important; min-width: 0 !important; }
</style>

<div class="project-upload-page" style="min-width:0;max-width:100%;overflow-x:hidden;box-sizing:border-box;">
    <header class="page-header">
        <h2 class="page-title"><?= $isViewMode ? '查看專題提交記錄' : ($editID ? '編輯專題提交' : '專題上傳') ?></h2>
        <?php if ($editID || $isViewMode): ?>
        <button type="button" class="btn-back-page" onclick="window.history.back();" title="回到前一頁">
            <i class="fa-solid fa-arrow-left"></i> 回到前一頁
        </button>
        <?php endif; ?>
    </header>
    
    <?php 
    // 🔹 檢查該組是否已有提交記錄（非暫存狀態）
    $hasSubmitted = false;
    $latestSubmittedRecord = null;
    
    if (!$isViewMode && !$editID && !empty($validSubmissions)) {
        $hasSubmitted = true;
        $latestSubmittedRecord = reset($validSubmissions); // 獲取最新的提交記錄
    }
    
    // 🔹 【優先讀取已提交記錄的資料】如果有已提交記錄，優先顯示已提交記錄的內容
    // 期限內：顯示已提交資料（可編輯），超過期限：顯示已提交資料（只讀）
    if (!$isViewMode && !$editID && $latestSubmittedRecord) {
        $latestStatus = (int)($latestSubmittedRecord['prosub_status'] ?? SUBMISSION_STATUS_DRAFT);
        if ($latestStatus != SUBMISSION_STATUS_DRAFT) {
            // 從已提交記錄讀取簡介
            if (isset($latestSubmittedRecord['prosub_intro']) && $latestSubmittedRecord['prosub_intro'] !== null && $latestSubmittedRecord['prosub_intro'] !== '') {
                $introContent = trim($latestSubmittedRecord['prosub_intro']);
            } else {
                $contentJson = json_decode($latestSubmittedRecord['content_json'] ?? '{}', true);
                if (is_array($contentJson) && isset($contentJson['intro']) && $contentJson['intro'] !== null && $contentJson['intro'] !== '') {
                    $introContent = trim($contentJson['intro']);
                } else {
                    $introContent = ''; // 確保有值（即使是空字串）
                }
            }
            // 更新 currentSubmission 為已提交的記錄
            $currentSubmission = $latestSubmittedRecord;
        }
    }
    
    // 🔹 【如果有暫存記錄且已提交記錄的簡介為空，讀取暫存記錄的資料】
    // 特別處理退件狀態：如果退件記錄的簡介為空，則從暫存記錄讀取
    // 只在沒有已提交記錄，或已提交記錄是暫存狀態，或已提交記錄的簡介為空時，才讀取暫存記錄
    if (empty($introContent) && $draftSubmission && (!$latestSubmittedRecord || ($latestSubmittedRecord && (int)($latestSubmittedRecord['prosub_status'] ?? SUBMISSION_STATUS_DRAFT) == SUBMISSION_STATUS_DRAFT) || ($latestSubmittedRecord && (int)($latestSubmittedRecord['prosub_status'] ?? SUBMISSION_STATUS_DRAFT) == SUBMISSION_STATUS_REJECTED))) {
        // 優先從資料庫字段讀取 intro（如果字段存在）
        if (isset($draftSubmission['prosub_intro']) && $draftSubmission['prosub_intro'] !== null && $draftSubmission['prosub_intro'] !== '') {
            $introContent = trim($draftSubmission['prosub_intro']);
        } else {
            // 兼容舊資料：如果字段不存在或為空，嘗試從 JSON 讀取（僅用於顯示，不用於流程判斷）
            $contentJson = json_decode($draftSubmission['content_json'] ?? '{}', true);
            if (is_array($contentJson) && isset($contentJson['intro']) && $contentJson['intro'] !== null && $contentJson['intro'] !== '') {
                $introContent = trim($contentJson['intro']);
            } else {
                $introContent = '';
            }
        }
        
        // 從資料庫字段讀取 is_locked（如果字段存在）
        if (isset($draftSubmission['prosub_is_locked'])) {
            $isLocked = (bool)$draftSubmission['prosub_is_locked'];
        } else {
            // 兼容舊資料：如果字段不存在，嘗試從 JSON 讀取（僅用於顯示，不用於流程判斷）
            $contentJson = json_decode($draftSubmission['content_json'] ?? '{}', true);
            $isLocked = isset($contentJson['is_locked']) && $contentJson['is_locked'] === true;
        }
        
        $currentSubmission = $draftSubmission;
    }
    
    // 🔹 【退件狀態特殊處理】如果退件記錄的簡介為空，再次嘗試從該記錄的 content_json 中查找
    // 退件記錄應該還保留著原來的簡介數據，如果簡介字段為空，可能是數據存儲在 content_json 中
    if (empty($introContent) && $latestSubmittedRecord && (int)($latestSubmittedRecord['prosub_status'] ?? SUBMISSION_STATUS_DRAFT) == SUBMISSION_STATUS_REJECTED) {
        // 再次檢查 content_json 中是否有簡介（可能之前讀取時遺漏了）
        $contentJson = json_decode($latestSubmittedRecord['content_json'] ?? '{}', true);
        if (is_array($contentJson) && isset($contentJson['intro']) && $contentJson['intro'] !== null && $contentJson['intro'] !== '') {
            $introContent = trim($contentJson['intro']);
        }
    }
    
    // 🔹 基於期限判斷是否只讀
    // 新的規則：只要在期限內都可以修改（不論提交狀態）
    // 只有超過期限時才設為只讀
    $isSubmittedReadonly = false;
    $readonlyReason = ''; // 只讀原因
    
    // 注意：這裡先設置為 false，實際的只讀判斷會在下方基於 $isFinalLocked 進行
    // $isFinalLocked 會在計算 $hasActivePeriodNow 後確定
    ?>
    
    <?php if (!$isViewMode && !$editID && $hasSubmitted): 
        $status = (int)$latestSubmittedRecord['prosub_status'];
        $statusText = '';
        $statusClass = 'secondary';
        $statusIcon = 'fa-check-circle';
        $statusColor = '#667eea';
        $showSubmissionCard = true; // 是否顯示提交卡片
        
        // 狀態對應文字和樣式
        if ($status == SUBMISSION_STATUS_REJECTED) {
            // 退件狀態：顯示不同的提示信息
            $statusText = '已退件';
            $statusClass = 'danger';
            $statusIcon = 'fa-times-circle';
            $statusColor = '#dc3545';
        } elseif ($status == SUBMISSION_STATUS_PENDING) {
            $statusText = '已提交';
            $statusClass = 'primary';
            $statusIcon = 'fa-paper-plane';
            $statusColor = '#667eea';
        } elseif ($status == SUBMISSION_STATUS_REVIEWING) {
            $statusText = '審核中';
            $statusClass = 'warning';
            $statusIcon = 'fa-hourglass-half';
            $statusColor = '#ffc107';
        } elseif ($status == SUBMISSION_STATUS_APPROVED) {
            $statusText = '已通過';
            $statusClass = 'success';
            $statusIcon = 'fa-check-circle';
            $statusColor = '#28a745';
        } else {
            $statusText = '已提交';
            $statusClass = 'primary';
            $statusIcon = 'fa-paper-plane';
            $statusColor = '#667eea';
        }
        
        // 格式化提交時間（退件狀態優先使用退件時間）
        $submitTime = '';
        $timeField = '';
        if ($status == SUBMISSION_STATUS_REJECTED && !empty($latestSubmittedRecord['prosub_re_d'])) {
            // 退件狀態：優先使用退件時間
            $timeField = $latestSubmittedRecord['prosub_re_d'];
        } elseif (!empty($latestSubmittedRecord['prosub_created_d'])) {
            // 其他狀態：使用提交時間
            $timeField = $latestSubmittedRecord['prosub_created_d'];
        }
        
        if (!empty($timeField)) {
            try {
                $submitDateTime = new DateTime($timeField);
                $submitTime = $submitDateTime->format('Y-m-d H:i');
            } catch (Exception $e) {
                $submitTime = $timeField;
            }
        }
    ?>
    <!-- 🔹 已上傳專題提示 - 美化版 -->
    <div class="submission-notice-card mb-3" style="
        background: linear-gradient(135deg, #f8f9ff 0%, #f0f4ff 100%);
        border: none;
        border-radius: 12px;
        padding: 16px 20px;
        box-shadow: 0 3px 15px rgba(102, 126, 234, 0.12);
        position: relative;
        overflow: hidden;
        border-left: 4px solid <?= $statusColor ?>;
    ">
        <!-- 背景裝飾 -->
        <div style="
            position: absolute;
            top: -40px;
            right: -40px;
            width: 150px;
            height: 150px;
            background: radial-gradient(circle, rgba(102, 126, 234, 0.08) 0%, transparent 70%);
            border-radius: 50%;
        "></div>
        
        <div class="d-flex align-items-start position-relative">
            <!-- 左側圖標 -->
            <div style="
                width: 48px;
                height: 48px;
                background: linear-gradient(135deg, <?= $statusColor ?> 0%, <?= $statusColor ?>dd 100%);
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-right: 16px;
                flex-shrink: 0;
                box-shadow: 0 3px 10px rgba(102, 126, 234, 0.2);
            ">
                <i class="fa-solid <?= $statusIcon ?> fa-lg text-white"></i>
            </div>
            
            <!-- 中間內容 -->
            <div class="flex-grow-1">
                <h5 style="
                    font-size: 16px;
                    font-weight: 700;
                    color: #2d3748;
                    margin: 0 0 10px 0;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                ">
                    <?php if ($status == SUBMISSION_STATUS_REJECTED): ?>
                        <span style="color: <?= $statusColor ?>;">您的專題已被退件</span>
                    <?php else: ?>
                        <span style="color: <?= $statusColor ?>;">本組已上傳專題</span>
                    <?php endif; ?>
                    <span class="badge" style="
                        background: linear-gradient(135deg, <?= $statusColor ?> 0%, <?= $statusColor ?>dd 100%);
                        color: white;
                        padding: 4px 12px;
                        border-radius: 6px;
                        font-size: 12px;
                        font-weight: 600;
                        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
                    "><?= htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') ?></span>
                </h5>
                
                <?php if ($status == SUBMISSION_STATUS_REJECTED): ?>
                    <!-- 退件狀態的特殊提示 -->
                    <?php 
                    // 🔹 優先從 prosub_re_reason 字段讀取退件原因（科辦填寫的原因）
                    $rejectReason = $latestSubmittedRecord['prosub_re_reason'] ?? '';
                    // 如果 prosub_re_reason 為空，嘗試從 prosub_reason 讀取（兼容舊資料）
                    if (empty($rejectReason)) {
                        $rejectReason = $latestSubmittedRecord['prosub_reason'] ?? '';
                    }
                    // 如果還是為空，嘗試從 content_json 讀取（最後的備用方案）
                    if (empty($rejectReason)) {
                        $contentJson = json_decode($latestSubmittedRecord['content_json'] ?? '{}', true);
                        $rejectReason = $contentJson['reject_reason'] ?? $contentJson['re_reason'] ?? '';
                    }
                    ?>
                    <div style="
                        background: white;
                        border-radius: 10px;
                        padding: 12px 16px;
                        margin-bottom: 10px;
                        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
                    ">
                        <p style="
                            margin: 0 0 8px 0;
                            font-size: 14px;
                            color: #4a5568;
                            line-height: 1.5;
                        ">
                            <i class="fa-solid fa-exclamation-triangle me-2" style="color: <?= $statusColor ?>; font-size: 13px;"></i>
                            退件時間：<strong style="color: #2d3748;"><?= htmlspecialchars($submitTime, ENT_QUOTES, 'UTF-8') ?></strong>
                        </p>
                        <?php if (!empty($rejectReason)): ?>
                        <p style="
                            margin: 8px 0 0 0;
                            font-size: 14px;
                            color: #dc3545;
                            line-height: 1.5;
                            font-weight: 600;
                        ">
                            <i class="fa-solid fa-comment-dots me-2" style="color: <?= $statusColor ?>; font-size: 13px;"></i>
                            退件原因：<?= htmlspecialchars($rejectReason, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <p style="
                        margin: 0;
                        font-size: 13px;
                        color: #718096;
                        line-height: 1.5;
                        display: flex;
                        align-items: center;
                        gap: 6px;
                    ">
                        <i class="fa-solid fa-info-circle" style="color: #a0aec0; font-size: 12px;"></i>
                        <span>請根據退件原因修改專題內容後，重新提交。</span>
                    </p>
                <?php else: ?>
                    <!-- 其他狀態的顯示 -->
                    <div style="
                        background: white;
                        border-radius: 10px;
                        padding: 12px 16px;
                        margin-bottom: 10px;
                        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
                    ">
                        <p style="
                            margin: 0;
                            font-size: 14px;
                            color: #4a5568;
                            line-height: 1.5;
                        ">
                            <i class="fa-solid fa-calendar-check me-2" style="color: <?= $statusColor ?>; font-size: 13px;"></i>
                            提交時間：<strong style="color: #2d3748;"><?= htmlspecialchars($submitTime, ENT_QUOTES, 'UTF-8') ?></strong>
                        </p>
                    </div>
                    
                    <?php if ($status == SUBMISSION_STATUS_PENDING || $status == SUBMISSION_STATUS_REVIEWING): ?>
                    <p style="
                        margin: 0;
                        font-size: 13px;
                        color: #718096;
                        line-height: 1.5;
                        display: flex;
                        align-items: center;
                        gap: 6px;
                    ">
                        <i class="fa-solid fa-info-circle" style="color: #a0aec0; font-size: 12px;"></i>
                        <span>您可以在下方「提交記錄」區塊查看詳細資訊，或點擊「編輯」按鈕進行修改。</span>
                    </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php 
    // 🔹 【完全動態查詢】從 projectdata 表查詢繳交時段資料（提升到上層作用域，供整個頁面使用）
    $activePeriods = [];
    $hasActivePeriodNow = false; // 是否有正在進行中的時段
    
    if (!$isViewMode && !$editID) {
        try {
            // 獲取當前登入學生的 cohort_ID 和 class_ID
            // 【規則】同一個 enroll_u_ID 在 enrollmentdata 永遠只允許 1 筆資料
            $studentCohort_ID = null;
            $studentClass_ID = null;
            if ($u_ID && $role_ID == 6) {
                $enrollmentStmt = $conn->prepare("
                    SELECT cohort_ID, class_ID
                    FROM enrollmentdata
                    WHERE enroll_u_ID = ?
                    LIMIT 1
                ");
                $enrollmentStmt->execute([$u_ID]);
                $enrollment = $enrollmentStmt->fetch(PDO::FETCH_ASSOC);
                if ($enrollment) {
                    if ($enrollment['cohort_ID']) {
                        $studentCohort_ID = (int)$enrollment['cohort_ID'];
                    }
                    if ($enrollment['class_ID']) {
                        $studentClass_ID = (int)$enrollment['class_ID'];
                    }
                }
            }
            
            // 🔹 【統一查詢邏輯】查詢 projectdata 表：使用資料庫 NOW() 判斷時間，與提交驗證邏輯完全一致
            // 🔹 【關鍵】只查詢該學生所屬學級（pro_chorot_ID）的時段
            if ($studentCohort_ID) {
                $periodStmt = $conn->prepare("
                    SELECT pro_ID, pro_title, pro_start_d, pro_end_d, pro_des, pro_chorot_ID
                    FROM projectdata
                    WHERE pro_status = 1
                      AND pro_chorot_ID = ?
                      AND pro_start_d IS NOT NULL
                      AND pro_end_d IS NOT NULL
                      AND NOW() BETWEEN pro_start_d AND pro_end_d
                    ORDER BY pro_start_d DESC, pro_ID DESC
                ");
                $periodStmt->execute([$studentCohort_ID]);
                $allPeriods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // 過濾符合班級條件的資料
                foreach ($allPeriods as $period) {
                    $pro_des = $period['pro_des'] ?? null;
                    
                    // 如果 pro_des 為 NULL、空值，或無 class_ID 欄位 → 所有學生可見
                    if (empty($pro_des)) {
                        $activePeriods[] = $period;
                        continue;
                    }
                    
                    // 解析 JSON
                    $desData = json_decode($pro_des, true);
                    if (!is_array($desData)) {
                        // JSON 解析失敗，視為所有學生可見
                        $activePeriods[] = $period;
                        continue;
                    }
                    
                    // 檢查是否有 class_ID 欄位
                    if (!isset($desData['class_ID']) || !is_array($desData['class_ID'])) {
                        // 無 class_ID 欄位或不是陣列，視為所有學生可見
                        $activePeriods[] = $period;
                        continue;
                    }
                    
                    // 如果有 class_ID 陣列，檢查學生的 class_ID 是否在陣列內
                    $classIDs = array_map('intval', $desData['class_ID']);
                    if (empty($classIDs)) {
                        // 陣列為空，視為所有學生可見
                        $activePeriods[] = $period;
                        continue;
                    }
                    
                    // 如果學生的 class_ID 在陣列內，才顯示
                    if ($studentClass_ID && in_array($studentClass_ID, $classIDs, true)) {
                        $activePeriods[] = $period;
                    }
                }
            }
            
            // 🔹 【計算是否有正在進行中的時段】用於判斷是否允許上傳
            // 因為查詢已使用 NOW() BETWEEN pro_start_d AND pro_end_d，所以 $activePeriods 中的都是正在進行中的時段
            if (!empty($activePeriods)) {
                $hasActivePeriodNow = true;
            }
        } catch (Exception $e) {
            error_log("查詢繳交時段失敗: " . $e->getMessage());
        }
    }
    
    // 🔹 【最終鎖定狀態】基於 $activePeriods 判斷（完全動態，不寫死）
    // 時間鎖定或手動鎖定或沒有開放時段 → 鎖定
    $isFinalLocked = $isTimeLocked || $isLocked || (!$isViewMode && !$editID && !$hasActivePeriodNow);
    
    // 🔹 【統一時間狀態判斷】用於顯示提示信息（使用資料庫 NOW() 判斷）
    // 狀態：尚未開始（now < start_time）、開放中（start_time <= now <= end_time）、已截止（now > end_time）
    $timeStatus = null; // 'not_started', 'open', 'closed'
    $timeStatusMessage = '';
    $timeStatusStartTime = null;
    $timeStatusEndTime = null;
    
    // 🔹 【全局期限判斷】用於顯示提示信息（無論是否有提交記錄都需要判斷）
    $globalDeadlineTime = null;
    $globalIsDeadlinePassed = false;
    
    if (!$isViewMode && !$editID) {
        // 🔹 【統一期限來源】優先使用 activePeriods 中的時間（與頁面顯示的「繳交時段」一致）
        // 如果沒有正在進行的時段，再使用 projectdata.pro_end_d（「歷屆專題管理」頁面設定的統一期限）
        $deadlineTime = null;
        $isDeadlinePassed = false;
        
        // 優先：從 activePeriods 中獲取時間狀態
        if (!empty($activePeriods)) {
            // 使用資料庫 NOW() 進行精確比較
            $nowCheckStmt = $conn->prepare("SELECT NOW() as db_now");
            $nowCheckStmt->execute();
            $nowResult = $nowCheckStmt->fetch(PDO::FETCH_ASSOC);
            $dbNow = $nowResult['db_now'] ?? null;
            
            if ($dbNow) {
                try {
                    $timezone = new DateTimeZone('Asia/Taipei');
                    $dbNowObj = new DateTime($dbNow, $timezone);
                    
                    // 找出最近的時段（優先：正在進行中的時段）
                    $currentPeriod = null;
                    $nearestPeriod = null;
                    $nearestDiff = null;
                    
                    foreach ($activePeriods as $period) {
                        $startTime = $period['pro_start_d'] ?? null;
                        $endTime = $period['pro_end_d'] ?? null;
                        
                        if ($startTime && $endTime) {
                            try {
                                $startDateTime = new DateTime($startTime, $timezone);
                                $endDateTime = new DateTime($endTime, $timezone);
                                
                                // 如果當前時間在時段內，使用該時段
                                if ($dbNowObj >= $startDateTime && $dbNowObj < $endDateTime) {
                                    $currentPeriod = $period;
                                    $deadlineTime = $endTime;
                                    $isDeadlinePassed = false;
                                    $globalDeadlineTime = $endTime;
                                    $globalIsDeadlinePassed = false;
                                    $timeStatus = 'open';
                                    $timeStatusStartTime = $startTime;
                                    $timeStatusEndTime = $endTime;
                                    break;
                                }
                                
                                // 記錄最近的時段（用於判斷尚未開始或已截止）
                                if ($dbNowObj < $startDateTime) {
                                    $diff = $startDateTime->getTimestamp() - $dbNowObj->getTimestamp();
                                    if ($nearestDiff === null || $diff < $nearestDiff) {
                                        $nearestDiff = $diff;
                                        $nearestPeriod = $period;
                                    }
                                } elseif ($dbNowObj >= $endDateTime) {
                                    $diff = $dbNowObj->getTimestamp() - $endDateTime->getTimestamp();
                                    if ($nearestDiff === null || $diff < $nearestDiff) {
                                        $nearestDiff = $diff;
                                        $nearestPeriod = $period;
                                    }
                                }
                            } catch (Exception $e) {
                                // 忽略解析錯誤
                            }
                        }
                    }
                    
                    // 如果沒有正在進行的時段，判斷最近的時段狀態
                    if (!$currentPeriod && $nearestPeriod) {
                        $startTime = $nearestPeriod['pro_start_d'] ?? null;
                        $endTime = $nearestPeriod['pro_end_d'] ?? null;
                        
                        if ($startTime && $endTime) {
                            try {
                                $startDateTime = new DateTime($startTime, $timezone);
                                $endDateTime = new DateTime($endTime, $timezone);
                                
                                if ($dbNowObj < $startDateTime) {
                                    $timeStatus = 'not_started';
                                    $timeStatusStartTime = $startTime;
                                    $timeStatusEndTime = $endTime;
                                } elseif ($dbNowObj >= $endDateTime) {
                                    $timeStatus = 'closed';
                                    $timeStatusStartTime = $startTime;
                                    $timeStatusEndTime = $endTime;
                                    $deadlineTime = $endTime;
                                    $isDeadlinePassed = true;
                                    $globalDeadlineTime = $endTime;
                                    $globalIsDeadlinePassed = true;
                                }
                            } catch (Exception $e) {
                                // 忽略解析錯誤
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("時間狀態判斷錯誤: " . $e->getMessage());
                }
            }
        }
        
        // 備用：如果沒有找到時段，使用 projectdata.pro_end_d
        if (!$deadlineTime && empty($activePeriods)) {
            $deadlineStmt = $conn->prepare("
                SELECT p.pro_start_d, p.pro_end_d
                FROM projectdata p
                INNER JOIN teamdata t ON p.pro_chorot_ID = t.cohort_ID
                WHERE t.team_ID = ? AND p.pro_status = 1 AND p.pro_end_d IS NOT NULL
                ORDER BY p.pro_created_d DESC, p.pro_ID DESC 
                LIMIT 1
            ");
            $deadlineStmt->execute([$team_ID]);
            $deadlineRecord = $deadlineStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($deadlineRecord) {
                $startTime = $deadlineRecord['pro_start_d'] ?? null;
                $endTime = $deadlineRecord['pro_end_d'] ?? null;
                
                if ($endTime) {
                    $deadlineTime = $endTime;
                    $globalDeadlineTime = $endTime;
                    
                    // 使用資料庫 NOW() 進行精確比較
                    $deadlineCheckStmt = $conn->prepare("
                        SELECT 
                            CASE WHEN NOW() < ? THEN 'not_started'
                                 WHEN NOW() >= ? AND NOW() <= ? THEN 'open'
                                 WHEN NOW() > ? THEN 'closed'
                                 ELSE 'unknown' END as status
                    ");
                    if ($startTime) {
                        $deadlineCheckStmt->execute([$startTime, $startTime, $endTime, $endTime]);
                    } else {
                        // 如果沒有開始時間，只判斷是否已截止
                        $deadlineCheckStmt = $conn->prepare("
                            SELECT CASE WHEN NOW() > ? THEN 'closed' ELSE 'open' END as status
                        ");
                        $deadlineCheckStmt->execute([$endTime]);
                    }
                    $checkResult = $deadlineCheckStmt->fetch(PDO::FETCH_ASSOC);
                    $status = $checkResult['status'] ?? 'unknown';
                    
                    if ($status === 'closed') {
                        $isDeadlinePassed = true;
                        $globalIsDeadlinePassed = true;
                        $timeStatus = 'closed';
                        $timeStatusStartTime = $startTime;
                        $timeStatusEndTime = $endTime;
                    } elseif ($status === 'not_started') {
                        $isDeadlinePassed = false;
                        $globalIsDeadlinePassed = false;
                        $timeStatus = 'not_started';
                        $timeStatusStartTime = $startTime;
                        $timeStatusEndTime = $endTime;
                    } elseif ($status === 'open') {
                        $isDeadlinePassed = false;
                        $globalIsDeadlinePassed = false;
                        $timeStatus = 'open';
                        $timeStatusStartTime = $startTime;
                        $timeStatusEndTime = $endTime;
                    }
                }
            }
        }
        
        // 🔹 【期限到後清除暫存】如果已超過截止時間，清除暫存資料，讓頁面顯示為空白
        if ($globalIsDeadlinePassed && $draftSubmission) {
            $draftSubmission = null;
            // 如果 $introContent 是從暫存記錄讀取的，也要清除
            // 只有在沒有已提交記錄，或已提交記錄是暫存狀態時，才清除 $introContent
            if (!$latestSubmittedRecord || ($latestSubmittedRecord && (int)($latestSubmittedRecord['prosub_status'] ?? SUBMISSION_STATUS_DRAFT) == SUBMISSION_STATUS_DRAFT)) {
                $introContent = '';
            }
        }
        
        // 根據時間狀態設置提示信息
        if ($timeStatus === 'not_started') {
            $timeStatusMessage = '繳交尚未開始，請於開放時間內上傳。科辦會設定統一的上傳期限。';
        } elseif ($timeStatus === 'closed') {
            $timeStatusMessage = '繳交時間已截止，科辦進入審核中。';
        }
        
        // 🔹 【新邏輯】期限內維持學生僅能繳交一次，一旦繳交，科辦隨時都可以審核
        // 通過→無法修改，退件→重新修改上傳
        if ($latestSubmittedRecord) {
            $currentStatus = (int)($latestSubmittedRecord['prosub_status'] ?? SUBMISSION_STATUS_DRAFT);
            
            // 期限內：一旦提交（狀態不是暫存），就不能再修改（除非是退件狀態）
            // 通過狀態：無法修改
            // 退件狀態：可以重新修改上傳
            if ($currentStatus == SUBMISSION_STATUS_APPROVED) {
                // 通過狀態：無法修改
                $isSubmittedReadonly = true;
                $readonlyReason = '此提交已通過審核，無法修改。';
            } elseif ($currentStatus == SUBMISSION_STATUS_REJECTED) {
                // 退件狀態：可以重新修改上傳
                $isSubmittedReadonly = false;
            } elseif ($currentStatus != SUBMISSION_STATUS_DRAFT) {
                // 其他已提交狀態（如未審核、審核中等）：期限內一旦提交就不能再修改
                $isSubmittedReadonly = true;
                $readonlyReason = '您已提交專題，期限內僅能繳交一次。如需修改，請等待科辦審核結果。';
            }
        }
    }
    
    // 🔹 【修復】判斷是否已繳交（用於正確設置只讀狀態）
    $isActuallySubmitted = false;
    if (!empty($latestSubmittedRecord) && !$isViewMode && !$editID) {
        $latestStatus = (int)($latestSubmittedRecord['prosub_status'] ?? SUBMISSION_STATUS_DRAFT);
        // 只有狀態不是暫存才算已繳交
        $isActuallySubmitted = ($latestStatus != SUBMISSION_STATUS_DRAFT);
    }
    
    // 如果手動鎖定，也要設為只讀
    if ($isLocked) {
        $isSubmittedReadonly = true;
        $readonlyReason = '此提交已被科辦鎖定，無法修改。';
    } elseif ($editID && $draftSubmission && !$isViewMode) {
        // 🔹 編輯模式：該筆記錄已提交（狀態為已提交/審核中/已通過）時，簡介改為只讀；退件可再編輯
        $s = (int)($draftSubmission['prosub_status'] ?? SUBMISSION_STATUS_DRAFT);
        if ($s === SUBMISSION_STATUS_PENDING || $s === SUBMISSION_STATUS_REVIEWING || $s === SUBMISSION_STATUS_APPROVED) {
            $isSubmittedReadonly = true;
            if ($readonlyReason === '') $readonlyReason = '您已提交專題，期限內僅能繳交一次。如需修改，請等待科辦審核結果。';
        } elseif ($s === SUBMISSION_STATUS_REJECTED) {
            $isSubmittedReadonly = false;
        }
    } elseif (!$isViewMode && !$editID && $globalIsDeadlinePassed && !$isActuallySubmitted) {
        // 🔹 【修復】未繳交 + 已超過截止時間 → 設為只讀
        $isSubmittedReadonly = true;
        $readonlyReason = '時間已截止，無法繳交，請聯繫科辦。';
    } elseif (!$isViewMode && !$editID && !$hasActivePeriodNow && $timeStatus !== null) {
        // 根據時間狀態設置只讀和提示信息
        $isSubmittedReadonly = true;
        if (!empty($timeStatusMessage)) {
            $readonlyReason = $timeStatusMessage;
        } else {
            $readonlyReason = '目前沒有開放上傳時段，科辦會設定統一的上傳期限。';
        }
    }
    ?>
    
    <?php 
    // 🔹 【判斷是否應該合併顯示】當沒有開放繳交時段或時間截止時，合併顯示一個共同消息
    $shouldMergeDisplay = false;
    if (!$isViewMode && !$editID) {
        // 情況1：沒有開放繳交時段
        if (empty($activePeriods) && !$hasActivePeriodNow) {
            $shouldMergeDisplay = true;
        }
        // 情況2：時間已截止（且沒有已提交記錄）
        elseif ($globalIsDeadlinePassed && !$isActuallySubmitted) {
            $shouldMergeDisplay = true;
        }
    }
    ?>
    
    <?php if (!$isViewMode && !$editID): ?>
    <!-- 左右兩欄布局（僅在非查看、非編輯模式顯示） -->
    <div class="row g-4">
        <?php if (!$shouldMergeDisplay): ?>
        <!-- 左側：繳交時段列表（只有在有開放時段時才顯示） -->
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fa-solid fa-calendar-days"></i> 繳交時段</h5>
                </div>
                <div class="card-body p-0">
                    <div id="deadlineList" class="deadline-list">
                        <?php
                        // 🔹 【使用上層作用域的 $activePeriods】不再重複查詢
                        // 日期格式化函數
                        $formatDate = function($dateStr) {
                            if (!$dateStr) return '';
                            try {
                                $date = new DateTime($dateStr);
                                return $date->format('Y/m/d H:i');
                            } catch (Exception $e) {
                                return '';
                            }
                        };
                        
                        // 動態產生繳交時段卡片
                        if (!empty($activePeriods)):
                            // 確保時區設置正確
                            try {
                                $timezone = new DateTimeZone('Asia/Taipei');
                            } catch (Exception $e) {
                                $timezone = new DateTimeZone(date_default_timezone_get());
                            }
                            $now = new DateTime('now', $timezone);
                        ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($activePeriods as $period): 
                                $startTime = $period['pro_start_d'] ?? null;
                                $endTime = $period['pro_end_d'] ?? null;
                                
                                // 判斷狀態
                            $statusClass = 'secondary';
                            $statusText = '未開始';
                            
                                if ($startTime && $endTime) {
                                try {
                                    $startDateTime = new DateTime($startTime, $timezone);
                                        $endDateTime = new DateTime($endTime, $timezone);
                                        
                                    if ($now < $startDateTime) {
                                        $statusClass = 'secondary';
                                        $statusText = '未開始';
                                        } else if ($now >= $endDateTime) {
                                        $statusClass = 'secondary';
                                        $statusText = '已結束';
                                    } else {
                                        $statusClass = 'success';
                                        $statusText = '進行中';
                                    }
                                } catch (Exception $e) {
                                        $statusClass = 'secondary';
                                    $statusText = '進行中';
                                }
                            }
                            
                                // 完全使用資料庫的 pro_title（不可寫死）
                                $displayTitle = htmlspecialchars($period['pro_title'] ?? '', ENT_QUOTES, 'UTF-8');
                            ?>
                            <div class="list-group-item deadline-item active" style="cursor: pointer;">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1" style="font-weight: 600;"><?= $displayTitle ?></h6>
                                        <?php if ($startTime): ?>
                                        <small class="text-muted d-block">
                                            <i class="fa-solid fa-calendar-check me-1"></i>開放時間：<?= $formatDate($startTime) ?>
                                        </small>
                                        <?php endif; ?>
                                        <?php if ($endTime): ?>
                                        <small class="text-muted d-block <?= $startTime ? 'mt-1' : '' ?>">
                                            <i class="fa-solid fa-calendar-times me-1"></i>截止時間：<?= $formatDate($endTime) ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                    <span class="badge bg-<?= $statusClass ?> ms-2"><?= htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center p-3 text-muted">
                            <i class="fa-solid fa-calendar-xmark"></i>
                            <?php if ($timeStatus === 'not_started'): ?>
                                <p class="mb-0 mt-2">繳交尚未開始，請於開放時間內上傳。</p>
                                <small class="d-block mt-2">科辦會設定統一的上傳期限</small>
                            <?php elseif ($timeStatus === 'closed'): ?>
                                <p class="mb-0 mt-2">繳交時間已截止，科辦進入審核中。</p>
                            <?php else: ?>
                                <p class="mb-0 mt-2">目前沒有繳交時段</p>
                                <small class="d-block mt-2">科辦會設定統一的上傳期限</small>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 右側：上傳表單（合併顯示時佔滿全寬） -->
        <div class="<?= $shouldMergeDisplay ? 'col-12' : 'col-md-9' ?>">
    <?php endif; ?>
    
    <!-- 上傳區卡片 -->
    <div class="card upload-card shadow-sm">
        <div class="card-header bg-primary text-white">
            <strong><?= $isViewMode ? '查看資料' : '上傳區' ?></strong>
        </div>
        <div class="card-body">
            <?php 
            // 🔹 【沒有開放繳交時段或時間截止】使用與 $shouldMergeDisplay 相同的判斷條件
            $shouldShowForm = !$shouldMergeDisplay;
            ?>
            
            <?php if (!$shouldShowForm): ?>
            <!-- 沒有開放繳交時段或時間截止時，只顯示提示信息（合併顯示） -->
            <div class="text-center p-4 text-muted">
                <i class="fa-solid fa-calendar-xmark" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                <p class="mb-2" style="font-size: 1.1rem;">目前沒有開放的繳交區</p>
                <small class="d-block">科辦會設定統一的上傳期限</small>
            </div>
            <?php else: ?>
            <form id="uploadProjectForm" <?= $isViewMode ? 'onsubmit="return false;"' : '' ?>>
                <input type="hidden" id="prosubID" name="prosub_ID" value="<?= ($latestSubmittedRecord ? $latestSubmittedRecord['prosub_ID'] : (($draftSubmission && !$globalIsDeadlinePassed) ? $draftSubmission['prosub_ID'] : '')) ?>">
                
                <!-- 團隊名稱 -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">團隊名稱：</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($team_name, ENT_QUOTES, 'UTF-8') ?>" readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                    </div>
                </div>
                
                <!-- 鎖定提示 -->
                <?php if (!$isViewMode && !$editID && $timeStatus !== null && $timeStatus !== 'open'): ?>
                <div class="alert alert-<?= $timeStatus === 'closed' ? 'warning' : 'info' ?> mb-3">
                    <i class="fa-solid fa-<?= $timeStatus === 'closed' ? 'clock' : 'exclamation-circle' ?>"></i> 
                    <?= htmlspecialchars($timeStatusMessage ?: '目前沒有開放上傳時段，科辦會設定統一的上傳期限', ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php elseif (!$isViewMode && !$editID && !empty($activePeriods)):
                    // 檢查是否有正在進行中的時段
                    $hasActivePeriodNow = false;
                    try {
                        $timezone = new DateTimeZone('Asia/Taipei');
                    } catch (Exception $e) {
                        $timezone = new DateTimeZone(date_default_timezone_get());
                    }
                    $now = new DateTime('now', $timezone);
                    
                    foreach ($activePeriods as $period) {
                        $startTime = $period['pro_start_d'] ?? null;
                        $endTime = $period['pro_end_d'] ?? null;
                        
                        if ($startTime && $endTime) {
                            try {
                                $startDateTime = new DateTime($startTime, $timezone);
                                $endDateTime = new DateTime($endTime, $timezone);
                                
                                if ($now >= $startDateTime && $now < $endDateTime) {
                                    $hasActivePeriodNow = true;
                                    break;
                                }
                            } catch (Exception $e) {
                                // 忽略解析錯誤
                            }
                        }
                    }
                    
                    if (!$hasActivePeriodNow && $timeStatus !== null):
                        // 使用統一時間狀態判斷結果
                        // $timeStatus 和 $timeStatusMessage 已在上面統一計算
                    endif;
                ?>
                <?php if (!$hasActivePeriodNow && $timeStatus !== null && !empty($timeStatusMessage)): ?>
                <div class="alert alert-<?= $timeStatus === 'closed' ? 'warning' : 'info' ?> mb-3">
                    <i class="fa-solid fa-<?= $timeStatus === 'closed' ? 'clock' : 'exclamation-circle' ?>"></i> 
                    <?= htmlspecialchars($timeStatusMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php endif; ?>
                <?php elseif (!$isViewMode && !$editID && !empty($activePeriods) && $hasActivePeriodNow): 
                    // 🔹 【完全動態】有時段且正在進行中，顯示截止時間提示
                    $currentPeriod = null;
                    try {
                        $timezone = new DateTimeZone('Asia/Taipei');
                    } catch (Exception $e) {
                        $timezone = new DateTimeZone(date_default_timezone_get());
                    }
                    $now = new DateTime('now', $timezone);
                    
                    foreach ($activePeriods as $period) {
                        $pStartTime = $period['pro_start_d'] ?? null;
                        $pEndTime = $period['pro_end_d'] ?? null;
                        
                        if ($pStartTime && $pEndTime) {
                            try {
                                $startDateTime = new DateTime($pStartTime, $timezone);
                                $endDateTime = new DateTime($pEndTime, $timezone);
                                
                                if ($now >= $startDateTime && $now < $endDateTime) {
                                    $currentPeriod = $period;
                                    break;
                                }
                            } catch (Exception $e) {
                                // 忽略解析錯誤
                            }
                        }
                    }
                    
                    // 如果有正在進行中的時段，顯示截止時間提示
                    if ($currentPeriod && $currentPeriod['pro_end_d']):
                        $endTime = $currentPeriod['pro_end_d'];
                ?>
                <div class="alert alert-info mb-3">
                    <i class="fa-solid fa-calendar-times"></i> 上傳截止時間：<?= htmlspecialchars(date('Y-m-d H:i', strtotime($endTime)), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php endif; ?>
                <?php elseif ($isTimeLocked): ?>
                <div class="alert alert-danger mb-3">
                    <i class="fa-solid fa-clock"></i> 已超過上傳截止時間（<?= htmlspecialchars(date('Y-m-d H:i', strtotime($lockTime)), ENT_QUOTES, 'UTF-8') ?>），無法修改上傳
                </div>
                <?php elseif ($isLocked): ?>
                <div class="alert alert-warning mb-3">
                    <i class="fa-solid fa-lock"></i> 此提交已被科辦鎖定，無法修改
                </div>
                <?php elseif ($editID && $draftSubmission && (int)$draftSubmission['prosub_status'] >= 1): ?>
                <div class="alert alert-warning mb-3">
                    <i class="fa-solid fa-exclamation-triangle"></i> 此組已完成上傳，重新提交將覆蓋原內容
                </div>
                <?php elseif ($lockTime): ?>
                <div class="alert alert-info mb-3">
                    <i class="fa-solid fa-calendar"></i> 上傳截止時間：<?= htmlspecialchars(date('Y-m-d H:i', strtotime($lockTime)), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php endif; ?>
                
                <!-- 專題簡介 -->
                <div class="mb-4">
                    <label class="form-label" for="projectIntro">專題簡介：</label>
                    <textarea 
                        name="project_intro" 
                        id="projectIntro" 
                        class="form-control"
                        rows="4"
                        placeholder="請輸入專題簡介..."
                        style="cursor: <?= ($isViewMode || $isFinalLocked || $isSubmittedReadonly) ? 'not-allowed' : 'text' ?>; background-color: <?= ($isViewMode || $isFinalLocked || $isSubmittedReadonly) ? '#f8f9fa' : 'white' ?>;"
                        <?= ($isViewMode || $isFinalLocked || $isSubmittedReadonly) ? 'readonly' : '' ?>
                        <?= (!$isViewMode && !$isSubmittedReadonly) ? 'required' : '' ?>
                        data-initial-value="<?= htmlspecialchars($introContent ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    ><?= htmlspecialchars($introContent ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    <?php if ($draftSubmission && $introContent && !$isSubmittedReadonly && !$latestSubmittedRecord && !$globalIsDeadlinePassed): ?>
                        <!-- 頁面載入時已從暫存資料回填專題簡介（只有暫存記錄時才顯示，且未超過截止時間） -->
                        <input type="hidden" id="prosubID" name="prosub_ID" value="<?= htmlspecialchars($draftSubmission['prosub_ID'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                    <?php if ($latestSubmittedRecord): ?>
                        <!-- 已提交記錄的ID（期限內可重新提交，超過期限只讀） -->
                        <input type="hidden" id="prosubID" name="prosub_ID" value="<?= htmlspecialchars($latestSubmittedRecord['prosub_ID'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                </div>

                <!-- 上傳海報和多個檔案（同一列左右布局） -->
                <div class="row mb-4">
                    <!-- 左側：上傳海報 -->
                    <div class="col-md-6 mb-3 mb-md-0">
                        <div class="file-upload-section upload-area">
                            <label class="form-label">上傳海報：</label>
                            <div class="file-upload-row">
                        <?php 
                        // 🔹 先定義變數，避免在 HTML 屬性中使用未定義變數
                        $displayFileName = '未選擇任何檔案';
                        $posterPath = '';
                        $hasPoster = false;
                        
                        // 檢查是否有海報檔案
                        // 🔹 優先顯示已提交記錄的海報（不論是否只讀）
                        if (!$isViewMode && !$editID && $latestSubmittedRecord && $latestSubmittedRecord['prosub_img']) {
                            // 已提交記錄的海報（期限內顯示，超過期限也只讀顯示）
                            $posterPath = $latestSubmittedRecord['prosub_img'];
                            $hasPoster = true;
                            $contentJson = json_decode($latestSubmittedRecord['content_json'] ?? '{}', true);
                            $displayFileName = $contentJson['poster_original_name'] ?? basename($posterPath);
                        } elseif ($isViewMode && $draftSubmission && $draftSubmission['prosub_img']) {
                            $posterPath = $draftSubmission['prosub_img'];
                            $hasPoster = true;
                            // 從 content_json 讀取原始檔名
                            $contentJson = json_decode($draftSubmission['content_json'] ?? '{}', true);
                            $displayFileName = $contentJson['poster_original_name'] ?? basename($posterPath);
                        } elseif ($editID && $draftSubmission && $draftSubmission['prosub_img']) {
                            $posterPath = $draftSubmission['prosub_img'];
                            $hasPoster = true;
                            // 從 content_json 讀取原始檔名
                            $contentJson = json_decode($draftSubmission['content_json'] ?? '{}', true);
                            $displayFileName = $contentJson['poster_original_name'] ?? basename($posterPath);
                        } elseif (!$editID && $draftSubmission && $draftSubmission['prosub_status'] == SUBMISSION_STATUS_DRAFT && !$globalIsDeadlinePassed && $draftSubmission['prosub_img']) {
                            // 🔹 【期限到後清除暫存】如果已超過截止時間，不顯示暫存的海報
                            $posterPath = $draftSubmission['prosub_img'];
                            $hasPoster = true;
                            // 從 content_json 讀取原始檔名
                            $contentJson = json_decode($draftSubmission['content_json'] ?? '{}', true);
                            $displayFileName = $contentJson['poster_original_name'] ?? basename($posterPath);
                        }
                        ?>
                        <?php if (!$isViewMode && !$isSubmittedReadonly): ?>
                        <!-- 🔹 使用 label 包裹按鈕，確保點擊有效 -->
                        <label for="posterFileInput" class="btn btn-primary" id="selectFileBtn" style="cursor: pointer; pointer-events: auto;" <?= $isFinalLocked ? 'onclick="return false;"' : '' ?>>
                            選擇檔案
                        </label>
                        <?php endif; ?>
                        <div class="d-flex align-items-center gap-2 flex-grow-1" style="min-width: 0;">
                            <button type="button" class="btn btn-secondary <?= $hasPoster ? 'has-file' : '' ?>" id="noFileBtn" <?= $hasPoster ? '' : 'disabled' ?> style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; flex: 1;">
                                <?php 
                                //  顯示原始檔名（若存在），否則顯示目前可用檔名
                                echo htmlspecialchars($displayFileName, ENT_QUOTES, 'UTF-8');
                                ?>
                            </button>
                            <?php if ($hasPoster && $posterPath): 
                                // 🔹 直接使用 prosub_img 的值（已包含資料夾的相對路徑），不添加 ../ 前綴
                                $isPDF = strtolower(pathinfo($posterPath, PATHINFO_EXTENSION)) === 'pdf';
                            ?>
                            <button type="button" class="btn btn-info btn-sm" id="previewPosterBtn" 
                                    data-poster-path="<?= htmlspecialchars($posterPath, ENT_QUOTES, 'UTF-8') ?>"
                                    data-is-pdf="<?= $isPDF ? '1' : '0' ?>"
                                    title="預覽海報"
                                    style="width: 80px; height: 32px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                預覽
                            </button>
                            <?php endif; ?>
                        </div>
                            </div>
                            <?php if (!$isViewMode && !$isSubmittedReadonly): ?>
                            <!-- 🔹 確保 input 可訪問，只在唯讀檢視模式禁用 -->
                            <input type="file" id="posterFileInput" name="poster" accept="image/*,application/pdf" style="display: none; pointer-events: auto !important;" <?= ($isViewMode || $isFinalLocked) ? 'disabled' : '' ?>>
                            <small class="form-text text-muted">請上傳直式海報（建議高度大於寬度）</small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 右側：上傳多個檔案 -->
                    <div class="col-md-6">
                        <div class="file-upload-section upload-area">
                        <label class="form-label">上傳多個檔案：</label>
                            <?php if (!$isViewMode && !$isSubmittedReadonly): ?>
                            <!-- 檔案類型改為每列各自下拉選單，此共用選單隱藏保留 id 供 JS 選項過濾用 -->
                            <div class="mb-2" id="fileTypeSelectWrap" style="display: none;">
                                <label for="fileTypeSelect" class="form-label">檔案類型：</label>
                                <select id="fileTypeSelect" name="file_type" class="form-select form-select-sm" <?= ($isViewMode || $isFinalLocked) ? 'disabled' : '' ?>>
                                    <option value="report">成果書</option>
                                    <option value="ppt">PPT</option>
                                    <option value="word">Word</option>
                                    <option value="poster">海報</option>
                                    <option value="other">其他</option>
                                </select>
                            </div>
                            <?php endif; ?>
                    <?php if (!$isViewMode && !$isSubmittedReadonly): ?>
                    <div class="file-upload-row mb-2">
                        <!-- 🔹 使用 label 包裹按鈕，確保點擊有效，for 屬性對應 otherFilesInput -->
                        <label for="otherFilesInput" class="btn btn-primary" id="selectMultipleFilesBtn" style="cursor: pointer; pointer-events: auto;" <?= $isFinalLocked ? 'onclick="return false;"' : '' ?>>
                            <i class="fa-solid fa-folder-open"></i> 選擇多個檔案
                        </label>
                        <!-- 🔹 確保 input 可訪問，只在唯讀檢視模式禁用，使用固定 id: otherFilesInput -->
                        <input
                            type="file"
                            id="otherFilesInput"
                            name="other_files[]"
                            multiple
                            accept=".pdf,application/pdf,.ppt,.pptx,.doc,.docx,.jpg,.jpeg,.png,.gif,.bmp,.webp"
                            hidden
                            <?= ($isViewMode || $isFinalLocked) ? 'disabled' : '' ?>>
                    </div>
                    <small class="form-text text-muted">
                        可同時選擇多個檔案上傳，支援 PDF、PPT、Word、圖片等常見檔案格式。
                    </small>
                    <?php endif; ?>
                    
                    <!-- 已暫存檔案列表（顯示從資料庫載入的已暫存檔案） -->
                    <!-- 編輯模式或一般模式（有暫存資料）：由 PHP 渲染；一般模式（無暫存資料）：由前端 JavaScript 透過 getDraft API 載入並渲染 -->
                    <!-- 重要：data-rendered-by 屬性用於標記由誰渲染，避免重複渲染 -->
                    <!-- 🔹 已提交時顯示已提交的檔案，否則隱藏已上傳檔案列表，只顯示新選擇的檔案 -->
                    <?php 
                    // 檢查是否有其他檔案
                    // 已提交狀態：顯示已提交記錄的檔案
                    // 編輯模式或一般模式（有暫存資料）：顯示已上傳的檔案（由 PHP 渲染）
                    // 編輯模式：無論狀態如何都顯示；一般模式：只有暫存狀態（status=4）才顯示
                    // 🔹 【修復清除全部】確保 prosub_other 不為 null 且不為空字符串，才渲染文件列表
                    // 🔹 優先顯示已提交記錄的檔案（不論是否只讀）
                    $hasOtherFiles = false;
                    if (!$isViewMode && !$editID && $latestSubmittedRecord && isset($latestSubmittedRecord['prosub_other']) && $latestSubmittedRecord['prosub_other'] !== null && $latestSubmittedRecord['prosub_other'] !== '' && trim($latestSubmittedRecord['prosub_other']) !== '') {
                        // 已提交記錄的檔案（期限內顯示，超過期限也只讀顯示）
                        $hasOtherFiles = true;
                        $draftSubmission = $latestSubmittedRecord; // 使用已提交記錄來顯示檔案
                    } else {
                        // 🔹 【期限到後清除暫存】如果已超過截止時間，不顯示暫存的檔案
                        $hasOtherFiles = ($editID || (!$editID && $draftSubmission && $draftSubmission['prosub_status'] == SUBMISSION_STATUS_DRAFT && !$globalIsDeadlinePassed)) 
                                         && $draftSubmission 
                                         && isset($draftSubmission['prosub_other']) 
                                         && $draftSubmission['prosub_other'] !== null 
                                         && $draftSubmission['prosub_other'] !== '' 
                                         && trim($draftSubmission['prosub_other']) !== '';
                    }
                    ?>
                    <div id="uploadedFilesList" class="uploaded-files-list mt-3" data-rendered-by="<?= $hasOtherFiles ? 'php' : 'js' ?>" style="<?= $hasOtherFiles ? 'margin-top: 8px; border: 1px solid #e0e0e0; border-radius: 6px; padding: 10px; background: #ffffff;' : 'display: none !important;' ?>">
                    <?php
                    if ($hasOtherFiles): 
                        $otherFiles = [];
                        $otherFilesJson = json_decode($draftSubmission['prosub_other'], true);
                        if (is_array($otherFilesJson)) {
                            // 處理新格式（包含 name, path, type, uploaded_at, public）和舊格式
                            foreach ($otherFilesJson as $file) {
                                if (is_string($file)) {
                                    // 舊格式：字符串路徑
                                    $fileName = basename($file);
                                    $otherFiles[] = [
                                        'original_name' => $fileName, // 🔹 【修復檔名顯示】確保有 original_name
                                        'name' => $fileName,
                                        'path' => $file,
                                        'type' => '',
                                        'uploaded_at' => '',
                                        'public' => true
                                    ];
                                } elseif (is_array($file)) {
                                    // 檢查是否為新格式（包含 name, path, type, uploaded_at, public）
                                    if (isset($file['name']) && isset($file['path']) && isset($file['type']) && isset($file['uploaded_at']) && isset($file['public'])) {
                                        // 新格式：確保有 original_name
                                        if (!isset($file['original_name'])) {
                                            $file['original_name'] = $file['name'] ?? basename($file['path']);
                                        }
                                        $otherFiles[] = $file;
                                    } elseif (isset($file['path'])) {
                                        // 舊格式：轉換為新格式（保留 file_type 供下拉顯示）
                                        $fileName = $file['original_name'] ?? $file['name'] ?? basename($file['path']);
                                        $uploadTime = $file['uploaded_at'] ?? $file['upload_time'] ?? '';
                                        $isPublic = isset($file['public']) ? (bool)$file['public'] : (isset($file['allow_download']) ? (bool)$file['allow_download'] : true);
                                        
                                        $otherFiles[] = [
                                            'original_name' => $fileName,
                                            'name' => $fileName,
                                            'path' => $file['path'],
                                            'type' => $file['type'] ?? '',
                                            'uploaded_at' => $uploadTime,
                                            'public' => $isPublic,
                                            'file_type' => $file['file_type'] ?? ''
                                        ];
                                    }
                                }
                            }
                        } elseif (is_string($draftSubmission['prosub_other'])) {
                            // 兼容舊格式（可能是逗號分隔的字符串）
                            // 檢查是否為JSON格式（以{或[開頭），如果是則嘗試重試解析
                            $trimmedOther = trim($draftSubmission['prosub_other']);
                            if (substr($trimmedOther, 0, 1) === '{' || substr($trimmedOther, 0, 1) === '[') {
                                // 這是JSON格式但第一次解析失敗，嘗試再次解析
                                $retryJson = json_decode($trimmedOther, true);
                                if (is_array($retryJson)) {
                                    // 如果重試解析成功，重新處理
                                    foreach ($retryJson as $file) {
                                        if (is_string($file)) {
                                            // 舊格式：字符串路徑
                                            $otherFiles[] = [
                                                'name' => basename($file),
                                                'path' => $file,
                                                'type' => '',
                                                'uploaded_at' => '',
                                                'public' => true
                                            ];
                                        } elseif (is_array($file)) {
                                            // 檢查是否為新格式（包含 name, path, type, uploaded_at, public）
                                            if (isset($file['name']) && isset($file['path']) && isset($file['type']) && isset($file['uploaded_at']) && isset($file['public'])) {
                                                // 新格式：直接使用
                                                $otherFiles[] = $file;
                                            } elseif (isset($file['path'])) {
                                                // 舊格式：轉換為新格式
                                                $fileName = $file['original_name'] ?? $file['name'] ?? basename($file['path']);
                                                $uploadTime = $file['uploaded_at'] ?? $file['upload_time'] ?? '';
                                                $isPublic = isset($file['public']) ? (bool)$file['public'] : (isset($file['allow_download']) ? (bool)$file['allow_download'] : true);
                                                
                                            $otherFiles[] = [
                                                'original_name' => $fileName,
                                                'name' => $fileName,
                                                'path' => $file['path'],
                                                'type' => $file['type'] ?? '',
                                                'uploaded_at' => $uploadTime,
                                                'public' => $isPublic,
                                                'file_type' => $file['file_type'] ?? ''
                                            ];
                                            }
                                        }
                                    }
                                }
                                // 如果重試解析仍然失敗，$otherFiles 保持為空（避免顯示JSON字符串）
                            } else {
                                // 逗號分隔的字符串格式
                                $filePaths = array_filter(array_map('trim', explode(',', $draftSubmission['prosub_other'])));
                                foreach ($filePaths as $filePath) {
                                    $otherFiles[] = [
                                        'original_name' => basename($filePath),
                                        'name' => basename($filePath),
                                        'path' => $filePath,
                                        'type' => '',
                                        'uploaded_at' => '',
                                        'public' => true,
                                        'file_type' => ''
                                    ];
                                }
                            }
                        }
                        
                        if (!empty($otherFiles)):
                        // 檔案類型下拉只顯示科辦開放的類型（與 JS 一致；排除 poster、other）
                        $fileTypeLabels = ['report' => '成果書', 'ppt' => 'PPT', 'word' => 'Word'];
                        $allowedFileTypesForDropdown = [];
                        foreach ($activePeriods as $p) {
                            $types = isset($p['allow_file_types']) ? (is_string($p['allow_file_types']) ? json_decode($p['allow_file_types'], true) : $p['allow_file_types']) : [];
                            if (is_array($types)) {
                                foreach ($types as $t) {
                                    if ($t !== 'poster' && $t !== 'other') {
                                        $allowedFileTypesForDropdown[$t] = true;
                                    }
                                }
                            }
                        }
                        $allowedFileTypesForDropdown = array_keys($allowedFileTypesForDropdown);
                        foreach ($otherFiles as $index => $file):
                                $filePath = is_array($file) ? $file['path'] : $file;
                                $fileName = isset($file['original_name']) ? $file['original_name'] : (isset($file['name']) ? $file['name'] : basename($filePath));
                                $fileTypeValue = isset($file['file_type']) ? $file['file_type'] : '';
                                $fullFilePath = $filePath ? '../' . $filePath : '';
                                $fileExists = $filePath && file_exists($fullFilePath);
                                $isPDF = $filePath && strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'pdf';
                                $isImage = $filePath && preg_match('/\.(jpg|jpeg|png|gif|bmp|webp)$/i', $filePath);
                                $fileKey = htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8');
                        ?>
                        <div class="uploaded-file-item d-flex align-items-center justify-content-between py-2" 
                             data-file-path="<?= $fileKey ?>" 
                             data-file-index="<?= $index ?>" 
                             data-file-info="<?= htmlspecialchars(json_encode($file, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                             style="border-bottom: 1px solid #000; min-width: 0;">
                            <div class="d-flex align-items-center flex-grow-1" style="min-width: 0; overflow: hidden;">
                                <i class="fa-solid fa-file-pdf me-2" style="color: #28a745; font-size: 18px; flex-shrink: 0;"></i>
                                <span class="file-name text-truncate" style="color: #000; flex: 1;"><?= htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="d-flex gap-2 align-items-center">
                                <?php if (!$isViewMode && !$isFinalLocked && !$isSubmittedReadonly): ?>
                                <select class="file-type-select form-select form-select-sm" data-file-key="<?= $fileKey ?>" data-row-type="existing" style="min-width: 100px;" required>
                                    <option value="">請選擇</option>
                                    <?php foreach ($allowedFileTypesForDropdown as $ft): 
                                        $label = isset($fileTypeLabels[$ft]) ? $fileTypeLabels[$ft] : $ft;
                                        $sel = ($fileTypeValue === $ft) ? ' selected' : '';
                                    ?>
                                    <option value="<?= htmlspecialchars($ft, ENT_QUOTES, 'UTF-8') ?>"<?= $sel ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                                <?php if ($fileExists): ?>
                                <button type="button" class="btn btn-sm preview-file-btn" 
                                        data-file-path="<?= $fileKey ?>"
                                        data-is-pdf="<?= $isPDF ? '1' : '0' ?>"
                                        data-is-image="<?= $isImage ? '1' : '0' ?>"
                                        title="預覽檔案"
                                        style="background-color: #87CEEB; border: none; border-radius: 4px; padding: 6px 12px; color: #000; width: 80px; height: 32px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    預覽
                                </button>
                                <a href="<?= htmlspecialchars($fullFilePath, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-sm" download title="下載"
                                   style="background-color: #28a745; border: none; border-radius: 4px; padding: 6px 12px; color: white; text-decoration: none; width: 80px; height: 32px; display: inline-flex; align-items: center; justify-content: center;">
                                    下載
                                </a>
                                <?php endif; ?>
                                <?php if (!$isViewMode && !$isFinalLocked && !$isSubmittedReadonly): ?>
                                <button type="button" class="btn btn-danger btn-sm remove-file-btn" data-file-index="<?= $index ?>" title="刪除">
                                    <i class="fa-solid fa-trash"></i> 刪除
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php
                            endforeach;
                        else:
                            // 如果沒有檔案，顯示提示訊息（不是 JSON）
                        ?>
                        <div class="text-muted text-center p-3">
                            <i class="fa-solid fa-file"></i> 尚未上傳檔案
                        </div>
                        <?php
                        endif;
                        ?>
                    <?php else: ?>
                        <!-- 一般模式（無暫存資料）：由前端 JavaScript 透過 getDraft API 載入並渲染已暫存檔案 -->
                        <!-- 若前端也未載入到資料，會顯示空白（不顯示 JSON） -->
                    <?php endif; ?>
                    </div>
                    
                    <!-- 新選擇的檔案列表（尚未上傳） -->
                    <?php if (!$isViewMode && !$isSubmittedReadonly): ?>
                    <!-- 🔹 使用用戶提供的 HTML 結構 -->
                    <div id="otherFilesEmpty" class="text-muted mt-2">
                        <i class="fa-regular fa-file"></i> 尚未上傳檔案
                    </div>
                    <div id="otherFilesPanel" class="mt-3" style="display:none;">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <div id="otherFilesSummary" class="text-primary small flex-grow-1"></div>
                            <button type="button" id="btnClearOtherFiles" class="btn btn-sm btn-dark flex-shrink-0">清除全部</button>
                            </div>
                        <!-- 🔹 【UI优化】文件列表容器：添加最大高度和滚动功能，避免占据太多垂直空间 -->
                        <div id="otherFilesList" style="max-height: 400px; overflow-y: auto; overflow-x: hidden;"></div>
                    </div>
                    <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- 結束：上傳海報和多個檔案（同一列左右布局） -->

                <!-- 提交按鈕 -->
                <?php if (!$isViewMode): ?>
                <div class="text-center mt-4">
                    <?php 
                    // 🔹 【修復】正確判斷繳交狀態顯示規則
                    // 1️⃣ 狀態判斷條件：
                    //    - 是否已繳交：檢查 latestSubmittedRecord 是否存在且 prosub_status != 暫存（非暫存）
                    //    - 是否已超過截止時間：$globalIsDeadlinePassed
                    
                    $isActuallySubmitted = false;
                    if (!empty($latestSubmittedRecord) && !$editID) {
                        $latestStatus = (int)($latestSubmittedRecord['prosub_status'] ?? SUBMISSION_STATUS_DRAFT);
                        // 只有狀態不是暫存才算已繳交
                        $isActuallySubmitted = ($latestStatus != SUBMISSION_STATUS_DRAFT);
                    }
                    
                    $isDeadlinePassed = $globalIsDeadlinePassed;
                    $showSubmitButtons = true;
                    
                    // 2️⃣ 顯示對應規則
                    if ($isActuallySubmitted) {
                        // A. 已繳交（不論是否超過截止時間）
                        // 🔹 退件狀態不顯示"該組已完成繳交"，因為上方已有退件提示卡片
                        $latestStatus = (int)($latestSubmittedRecord['prosub_status'] ?? SUBMISSION_STATUS_DRAFT);
                        if ($latestStatus != SUBMISSION_STATUS_REJECTED) {
                            // 只有非退件狀態才顯示"該組已完成繳交"
                            ?>
                            <div class="alert alert-info mb-3">
                                <i class="fa-solid fa-info-circle"></i> 該組已完成繳交
                            </div>
                            <?php
                        }
                        if ($isDeadlinePassed) {
                            // 如果已截止，也顯示額外提示
                            ?>
                            <div class="alert alert-warning mb-3">
                                <i class="fa-solid fa-clock"></i> 繳交時間已截止，科辦進入審核中。
                            </div>
                            <?php
                        }
                        // 已繳交時，按鈕顯示邏輯由 $isSubmittedReadonly 控制（見下方）
                    } elseif (!$isDeadlinePassed) {
                        // B. 未繳交 ＋ 尚未截止
                        ?>
                        <div class="alert alert-warning mb-3">
                            <i class="fa-solid fa-exclamation-triangle"></i> 尚未繳交，請於截止時間前完成提交
                        </div>
                        <?php
                        // 顯示提交按鈕（由 $isSubmittedReadonly 控制）
                    } else {
                        // C. 未繳交 ＋ 已超過截止時間
                        ?>
                        <div class="alert alert-danger mb-3">
                            <i class="fa-solid fa-ban"></i> <strong>時間已截止，無法繳交，請聯繫科辦</strong>
                        </div>
                        <?php
                        // 隱藏所有提交／暫存／上傳相關按鈕
                        $showSubmitButtons = false;
                        // 強制設為只讀
                        $isSubmittedReadonly = true;
                    }
                    ?>
                    
                    <?php if ($showSubmitButtons && !$isSubmittedReadonly): ?>
                        <!-- 期限內：顯示暫存和提交按鈕 -->
                        <?php if (!$editID): ?>
                        <!-- 🔹 新增模式顯示暫存按鈕 -->
                        <button type="button" class="btn btn-warning btn-lg px-4 me-2" id="saveDraftBtn" <?= $isFinalLocked ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-save"></i> 暫存
                        </button>
                        <?php endif; ?>
                        <!-- 🔹 編輯模式和新增模式都顯示提交按鈕 -->
                        <button type="button" class="btn btn-primary btn-lg px-4" id="submitBtn" <?= $isFinalLocked ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-upload"></i> <?= ($isActuallySubmitted && !$editID) ? '重新提交' : '提交' ?>
                        </button>
                        <p class="mt-2 mb-0 text-muted" style="font-size: 14px;">
                            ※ 送出申請前，請先按「暫存」儲存最新內容，再按「提交」送出申請。
                        </p>
                    <?php endif; ?>
                </div>
                
                <!-- 🔹 暫存提示（僅在非查看模式顯示，且未完成提交時顯示） -->
                <?php if (!$isViewMode && !$isActuallySubmitted): 
                    // 🔹 檢查是否有暫存資料（狀態為暫存且 draftSubmission 存在）
                    // 🔹 必須同時滿足：有 draftSubmission 且狀態為暫存，且未超過截止時間
                    $hasDraftData = false;
                    if ($draftSubmission && isset($draftSubmission['prosub_status']) && !$globalIsDeadlinePassed) {
                        $draftStatus = (int)$draftSubmission['prosub_status'];
                        // 只有狀態為暫存才顯示
                        if ($draftStatus === SUBMISSION_STATUS_DRAFT) {
                            $hasDraftData = true;
                        }
                    }
                ?>
                <div id="draftStatusAlert" style="display: <?= $hasDraftData ? 'flex' : 'none' ?>; background-color: #d4edda; border: 1px solid #28a745; border-radius: 4px; padding: 8px 12px; margin-top: 12px; margin-bottom: 0; font-size: 18px; line-height: 1.5; color: #155724; align-items: center;">
                    <i class="fa-solid fa-info-circle" style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; background-color: #28a745; color: white; border-radius: 50%; font-size: 14px; margin-right: 8px; flex-shrink: 0;"></i>
                    <span>已暫存</span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
    </div>


    
    <?php if (!$isViewMode && !$editID): ?>
        </div> <!-- 結束右側 col-md-9 -->
    </div> <!-- 結束 row g-4 -->
    <?php endif; ?>

</div>

<script>
    // 🔹 提交狀態常量定義（與PHP端保持一致）
    window.SUBMISSION_STATUS = {
        REJECTED: <?= SUBMISSION_STATUS_REJECTED ?>,
        PENDING: <?= SUBMISSION_STATUS_PENDING ?>,
        REVIEWING: <?= SUBMISSION_STATUS_REVIEWING ?>,
        APPROVED: <?= SUBMISSION_STATUS_APPROVED ?>,
        DRAFT: <?= SUBMISSION_STATUS_DRAFT ?>
    };
    
    <?php
    // 供學生端使用的時段資訊：不再包含 allow_file_types，檔案類型改由副檔名自動判斷
    $activePeriodsForJs = $activePeriods;
    ?>
    window.PROJECT_UPLOAD_CONFIG = {
        u_ID: '<?= htmlspecialchars($u_ID ?? '', ENT_QUOTES) ?>',
        team_ID: <?= $team_ID ?>,
        team_name: '<?= htmlspecialchars($team_name, ENT_QUOTES) ?>',
        role_ID: <?= $role_ID ?>,
        draft_ID: <?= $draftSubmission ? $draftSubmission['prosub_ID'] : 'null' ?>,
        activePeriods: <?= !empty($activePeriodsForJs) ? json_encode($activePeriodsForJs, JSON_UNESCAPED_UNICODE) : '[]' ?>,
        hasActivePeriodNow: <?= $hasActivePeriodNow ? 'true' : 'false' ?>,
        isSubmittedReadonly: <?= $isSubmittedReadonly ? 'true' : 'false' ?>,
        isEditMode: <?= $editID ? 'true' : 'false' ?>,
        isDeadlinePassed: <?= $globalIsDeadlinePassed ? 'true' : 'false' ?>
    };
    
    <?php
    // 🔹 【修復編輯頁多檔案顯示】編輯模式下，輸出 existing files JSON 給 JS
    if ($editID && $draftSubmission && isset($draftSubmission['prosub_other'])) {
        // $draftSubmission['prosub_other'] 可能是 JSON 字串或 NULL
        $existingOtherFilesJson = $draftSubmission['prosub_other'];
        
        // 保險：如果是空字串就變 []
        if ($existingOtherFilesJson === null || trim($existingOtherFilesJson) === '') {
            $existingOtherFilesJson = '[]';
        }
        
        // 🔹 重點：不要用 json_encode() 再包一次，會變成「字串型 JSON」
        // other_files 若本來就是 JSON（資料庫存的），直接輸出原 JSON 即可
        // 但需要確保是有效的 JSON，如果不是則轉換
        $decoded = json_decode($existingOtherFilesJson, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // 是有效的 JSON 陣列，直接輸出（但要用 json_encode 確保安全）
            echo "window.__EXISTING_OTHER_FILES__ = " . json_encode($decoded, JSON_UNESCAPED_UNICODE) . ";\n";
        } else {
            // 不是有效 JSON，可能是舊格式或其他格式，轉換為空陣列
            echo "window.__EXISTING_OTHER_FILES__ = [];\n";
        }
        echo "console.log('[PHP] existing other_files count =', Array.isArray(window.__EXISTING_OTHER_FILES__) ? window.__EXISTING_OTHER_FILES__.length : 'not array');\n";
    } else {
        // 非編輯模式或沒有資料，輸出空陣列
        echo "window.__EXISTING_OTHER_FILES__ = [];\n";
    }
    ?>
</script>
<script>
// 初始化 project_upload 頁面的函數
function initProjectUpload() {
    const content = document.querySelector('.project-upload-page');
    if (content) content.style.visibility = 'visible';
    
    //  檢查 PHP 端是否已經渲染了繳交時段內容
    const deadlineList = document.getElementById('deadlineList');
    const hasPhpContent = deadlineList && deadlineList.querySelector('.list-group-item') !== null;
    
    // 如果 ProjectUpload 已經載入
    if (typeof window.ProjectUpload !== 'undefined' && window.ProjectUpload.init) {
        // 先初始化整體功能（事件監聽等，只綁一次）
        window.ProjectUpload.init();
        //  只有當 PHP 端沒有渲染內容時，才調用 loadSchedule（避免重複渲染）
        if (!hasPhpContent && window.ProjectUpload.loadSchedule) {
            window.ProjectUpload.loadSchedule();
        }
    } else {
        // 如果還沒載入，等待一下再試
        let attempts = 0;
        const maxAttempts = 20; // 最多嘗試 20 次（約 1 秒）
        const checkInterval = setInterval(function() {
            attempts++;
            if (typeof window.ProjectUpload !== 'undefined' && window.ProjectUpload.init) {
                clearInterval(checkInterval);
                // 先初始化整體功能
                window.ProjectUpload.init();
                //  只有當 PHP 端沒有渲染內容時，才調用 loadSchedule（避免重複渲染）
                const deadlineList = document.getElementById('deadlineList');
                const hasPhpContent = deadlineList && deadlineList.querySelector('.list-group-item') !== null;
                if (!hasPhpContent && window.ProjectUpload.loadSchedule) {
                    window.ProjectUpload.loadSchedule();
                }
            } else if (attempts >= maxAttempts) {
                clearInterval(checkInterval);
                // 如果還是載入不了，且 PHP 端沒有內容，才顯示無數據提示
                const deadlineList = document.getElementById('deadlineList');
                if (deadlineList && !deadlineList.querySelector('.list-group-item')) {
                    deadlineList.innerHTML = `
                        <div class="text-center p-3 text-muted">
                            <i class="fa-solid fa-calendar-xmark"></i>
                            <p class="mb-0 mt-2">目前沒有繳交時段</p>
                            <small class="d-block mt-2">科辦會設定統一的上傳期限</small>
                        </div>
                    `;
                    // 注意：JavaScript 中的提示應由 PHP 端統一處理，這裡僅作為後備顯示
                }
            }
        }, 50);
    }
}

// 動態設置 CSS 和 JS 路徑
(function() {
    const getBasePath = function() {
        const path = window.location.pathname;
        if (path.includes('/main.php')) {
            return path.substring(0, path.indexOf('/main.php') + 1);
        }
        if (path.includes('/pages/')) {
            return path.substring(0, path.indexOf('/pages/') + 1);
        }
        return '../';
    };
    
    const basePath = getBasePath();
    
    const cssLink = document.getElementById('projectUploadCSS');
    if (cssLink) {
        cssLink.href = basePath + (basePath.endsWith('/') ? '' : '/') + 'css/project_upload.css?v=<?= time() ?>';
        cssLink.media = 'all';
    }
    
    // 檢查 ProjectUpload 是否已經載入
    if (typeof window.ProjectUpload !== 'undefined') {
        // 如果已經載入，直接初始化
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initProjectUpload);
        } else {
            setTimeout(initProjectUpload, 0);
        }
    } else {
        // 如果還沒載入，創建新的 script 標籤
        const script = document.createElement('script');
        script.src = basePath + (basePath.endsWith('/') ? '' : '/') + 'js/project_upload.js?v=<?= time() ?>';
        script.onload = function() {
            initProjectUpload();
        };
        script.onerror = function() {
            // 如果載入失敗，至少關閉 loading 狀態
            const deadlineList = document.getElementById('deadlineList');
            if (deadlineList) {
                deadlineList.innerHTML = `
                    <div class="text-center p-3 text-muted">
                        <i class="fa-solid fa-calendar-xmark"></i>
                        <p class="mb-0 mt-2">目前沒有繳交時段</p>
                        <small class="d-block mt-2">科辦會設定統一的上傳期限</small>
                    </div>
                `;
            }
        };
        document.head.appendChild(script);
    }
})();

// 監聽 page:loaded 事件（當頁面通過 AJAX 載入完成時）
document.addEventListener('page:loaded', function(e) {
    const path = e.detail ? e.detail.path : '';
    if (path && path.includes('project_upload.php')) {
        // 延遲一下確保 DOM 和腳本都已載入
        setTimeout(function() {
            //  initProjectUpload() 和 init() 內部已經會檢查 PHP 內容，不再重複調用 loadSchedule
            initProjectUpload();
            // ⭐ 重新執行表單狀態檢查（如果可用）
            if (typeof window.checkStudentFormStatus === 'function') {
                window.checkStudentFormStatus(function(shouldRedirect, redirectUrl) {
                    if (shouldRedirect) {
                        location.href = redirectUrl;
                    }
                });
            }
        }, 100);
    }
});

// 同時監聽 jQuery 事件（向後兼容）
if (typeof $ !== 'undefined') {
    $(document).on('pageLoaded', function(e, path) {
        if (path && path.includes('project_upload.php')) {
            setTimeout(function() {
                //  initProjectUpload() 和 init() 內部已經會檢查 PHP 內容，不再重複調用 loadSchedule
                initProjectUpload();
                // ⭐ 重新執行表單狀態檢查（如果可用）
                if (typeof window.checkStudentFormStatus === 'function') {
                    window.checkStudentFormStatus(function(shouldRedirect, redirectUrl) {
                        if (shouldRedirect) {
                            location.href = redirectUrl;
                        }
                    });
                }
            }, 100);
        }
    });
}

function editSubmission(id) {
    if (!id) return;
    
    // 檢查該記錄是否已提交（狀態不是4=暫存）
    fetch('pages/upload_api.php?do=get_detail&prosub_ID=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                const status = parseInt(data.data.status || data.data.prosub_status || window.SUBMISSION_STATUS.DRAFT);
                
                // 如果已提交（狀態不是暫存），詢問是否覆蓋
                if (status !== window.SUBMISSION_STATUS.DRAFT) {
                    if (confirm('您先前已繳交此專題，是否要覆蓋原有內容？\n\n點擊「確定」將進入編輯模式，修改後提交會覆蓋原有資料。')) {
                        window.location.href = 'pages/project_upload.php?edit=' + id;
                    }
                } else {
                    // 暫存狀態，直接編輯
                    window.location.href = 'pages/project_upload.php?edit=' + id;
                }
            } else {
                // 如果無法獲取狀態，直接進入編輯（向後兼容）
                window.location.href = 'pages/project_upload.php?edit=' + id;
            }
        })
        .catch(error => {
            console.error('檢查提交狀態錯誤:', error);
            // 發生錯誤時，直接進入編輯（向後兼容）
            window.location.href = 'pages/project_upload.php?edit=' + id;
        });
}

function editSubmittedRecord() {
    // 獲取已提交記錄的ID
    const prosubID = document.getElementById('prosubID');
    if (prosubID && prosubID.value) {
        editSubmission(prosubID.value);
    } else {
        alert('無法找到提交記錄ID');
    }
}

// 防止重複點擊
let isViewingDetail = false;

function viewSubmissionDetail(id) {
    if (!id || isViewingDetail) return;
    
    if (window.ProjectUpload && window.ProjectUpload.viewSubmissionDetail) {
        window.ProjectUpload.viewSubmissionDetail(id);
    } else {
        isViewingDetail = true;
        
        // 顯示載入提示（使用 requestAnimationFrame 確保流暢）
        const loadingModal = document.createElement('div');
        loadingModal.className = 'modal fade';
        loadingModal.style.cssText = 'display: block; z-index: 1055;';
        loadingModal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-body text-center p-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">載入中...</span>
                        </div>
                        <p class="mt-3 mb-0">載入中...</p>
                    </div>
                </div>
            </div>
        `;
        const loadingBackdrop = document.createElement('div');
        loadingBackdrop.className = 'modal-backdrop fade show';
        loadingBackdrop.style.cssText = 'z-index: 1050;';
        
        document.body.appendChild(loadingBackdrop);
        document.body.appendChild(loadingModal);
        document.body.classList.add('modal-open');
        document.body.style.overflow = 'hidden';
        
        // 如果 ProjectUpload 還沒載入，直接調用 API
        fetch('pages/upload_api.php?do=get_detail&prosub_ID=' + id)
            .then(response => response.json())
            .then(data => {
                // 使用 requestAnimationFrame 確保流暢移除載入提示
                requestAnimationFrame(() => {
                    if (document.body.contains(loadingModal)) {
                        loadingModal.classList.remove('show');
                        setTimeout(() => {
                            if (document.body.contains(loadingModal)) {
                                document.body.removeChild(loadingModal);
                            }
                            if (document.body.contains(loadingBackdrop)) {
                                document.body.removeChild(loadingBackdrop);
                            }
                            document.body.classList.remove('modal-open');
                            document.body.style.overflow = '';
                        }, 150);
                    }
                });
                
                if (data.success) {
                    // 定義 escapeHtml 函數
                    function escapeHtml(text) {
                        if (!text) return '';
                        const div = document.createElement('div');
                        div.textContent = text;
                        return div.innerHTML;
                    }
                    
                    // 🔹 修復：API 返回的數據在 data.data 中
                    const detailData = data.data || data;
                    
                    const teamName = detailData.team_name || '（無組別名）';
                    const intro = detailData.intro || '（無簡介）';
                    const createdTime = detailData.created_time ? new Date(detailData.created_time).toLocaleString('zh-TW') : '';
                    const updatedTime = detailData.updated_time ? new Date(detailData.updated_time).toLocaleString('zh-TW') : '';
                    const hasUpdate = detailData.has_update || false;
                    const uploaderName = detailData.uploader_name || '（未知）';
                    const updaterName = detailData.updater_name || '';
                    // 🔹 修復海報路徑：使用絕對路徑（從網站根目錄開始）
                    // 獲取網站根目錄路徑
                    const getBasePath = function() {
                        const path = window.location.pathname || '';
                        if (path.includes('/main.php')) {
                            return path.substring(0, path.indexOf('/main.php') + 1);
                        }
                        if (path.includes('/pages/')) {
                            return path.substring(0, path.indexOf('/pages/') + 1);
                        }
                        return '/';
                    };
                    
                    const basePath = getBasePath();
                    // 如果 image_path 已經是絕對路徑（以 / 開頭），直接使用；否則從根目錄開始
                    let imagePath = '';
                    if (detailData.image_path) {
                        if (detailData.image_path.startsWith('/')) {
                            imagePath = detailData.image_path;
                        } else {
                            // 移除可能的 ../ 前綴，確保從根目錄開始
                            const cleanPath = detailData.image_path.replace(/^\.\.\//, '');
                            imagePath = basePath + (basePath.endsWith('/') ? '' : '/') + cleanPath;
                        }
                    }
                    
                    // 🔹 輸出海報 URL 以便調試
                    console.log('posterUrl:', imagePath);
                    console.log('原始 image_path:', detailData.image_path);
                    console.log('basePath:', basePath);
                    
                    const isPDF = imagePath.toLowerCase().endsWith('.pdf');
                    const otherFiles = detailData.other_files || [];
                    
                    console.log('其他檔案:', otherFiles); // 調試用
                    
                    let detailHtml = `
                        <div class="submission-detail">
                            <div class="detail-section">
                                <h5><i class="fa-solid fa-users"></i> 組別名</h5>
                                <div class="detail-content">${teamName}</div>
                            </div>
                            <div class="detail-section">
                                <h5><i class="fa-solid fa-file-text"></i> 專題簡介</h5>
                                <div class="detail-content">${intro.replace(/\n/g, '<br>')}</div>
                            </div>
                            <div class="detail-section">
                                <h5><i class="fa-solid fa-clock"></i> 上傳時間</h5>
                                <div class="detail-content">${createdTime}</div>
                            </div>
                            <div class="detail-section">
                                <h5><i class="fa-solid fa-user"></i> 上傳人</h5>
                                <div class="detail-content">${uploaderName}</div>
                            </div>
                            ${hasUpdate && updatedTime ? `
                            <div class="detail-section">
                                <h5><i class="fa-solid fa-clock-rotate-left"></i> 更新時間</h5>
                                <div class="detail-content">${updatedTime}</div>
                            </div>
                            ${updaterName ? `
                            <div class="detail-section">
                                <h5><i class="fa-solid fa-user-pen"></i> 更新人</h5>
                                <div class="detail-content">${updaterName}</div>
                            </div>
                            ` : ''}
                            ` : ''}
                            ${imagePath ? `
                            <div class="detail-section">
                                <h5><i class="fa-solid fa-image"></i> 上傳海報</h5>
                                <div class="detail-content">
                                    <div class="detail-image-container" style="position: relative; min-height: 200px;">
                                        ${isPDF ? 
                                            `<div style="position: relative; width: 100%; height: 500px;">
                                                <iframe src="${imagePath}" type="application/pdf" class="detail-pdf" frameborder="0" style="width: 100%; height: 100%;" onload="this.style.opacity='1'; const loader = this.parentElement.querySelector('#pdfLoader'); if (loader) { loader.style.opacity='0'; setTimeout(() => loader.remove(), 300); }"></iframe>
                                                <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #f8f9fa; display: flex; align-items: center; justify-content: center; pointer-events: none; transition: opacity 0.3s;" id="pdfLoader">
                                                    <div class="spinner-border text-primary" role="status"></div>
                                                </div>
                                            </div>` :
                                            `<img src="${imagePath}" alt="上傳檔案" class="detail-image" loading="lazy" style="opacity: 0; transition: opacity 0.3s;" 
                                                onload="this.style.opacity='1'; const spinner = this.nextElementSibling; if (spinner) spinner.remove();" 
                                                onerror="const spinner = this.nextElementSibling; if (spinner) spinner.remove(); const container = this.parentElement; container.innerHTML='<div style=\\'text-align: center; padding: 20px; color: #dc3545;\\'><i class=\\'fa-solid fa-exclamation-triangle\\'></i><p class=\\'mt-2 mb-0\\'>海報載入失敗</p></div>';">
                                            <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #f8f9fa; display: flex; align-items: center; justify-content: center; pointer-events: none;">
                                                <div class="spinner-border text-primary" role="status"></div>
                                            </div>`
                                        }
                                    </div>
                                </div>
                            </div>
                            ` : ''}
                            ${otherFiles.length > 0 ? `
                            <div class="detail-section">
                                <h5><i class="fa-solid fa-files"></i> 其他檔案 (${otherFiles.length})</h5>
                                <div class="detail-content">
                                    <div class="list-group">
                                        ${otherFiles.map((file, index) => {
                                            // 支持新格式（對象）和舊格式（字符串路徑）
                                            const filePath = typeof file === 'string' ? file : (file.path || '');
                                            const fileName = typeof file === 'string' ? file.split('/').pop() : (file.name || file.original_name || file.path.split('/').pop() || '');
                                            const fileType = typeof file === 'string' ? '' : (file.type || '');
                                            const uploadTime = typeof file === 'string' ? '' : (file.uploaded_at || file.upload_time || '');
                                            
                                            const fileUrl = '../' + filePath;
                                            const fileExtension = fileName.split('.').pop()?.toLowerCase() || '';
                                            let fileIcon = 'fa-file';
                                            
                                            // 根據檔案類型選擇圖標
                                            if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(fileExtension)) {
                                                fileIcon = 'fa-file-image';
                                            } else if (['pdf'].includes(fileExtension)) {
                                                fileIcon = 'fa-file-pdf';
                                            } else if (['doc', 'docx'].includes(fileExtension)) {
                                                fileIcon = 'fa-file-word';
                                            } else if (['xls', 'xlsx'].includes(fileExtension)) {
                                                fileIcon = 'fa-file-excel';
                                            } else if (['ppt', 'pptx'].includes(fileExtension)) {
                                                fileIcon = 'fa-file-powerpoint';
                                            } else if (['zip', 'rar', '7z'].includes(fileExtension)) {
                                                fileIcon = 'fa-file-archive';
                                            }
                                            
                                            return `
                                                <div class="list-group-item">
                                                    <div class="d-flex align-items-center justify-content-between">
                                                        <div class="d-flex align-items-center flex-grow-1 flex-column align-items-start">
                                                            <div class="d-flex align-items-center">
                                                            <i class="fa-solid ${fileIcon} me-2 text-primary"></i>
                                                            <span class="text-break">${escapeHtml(fileName)}</span>
                                                            </div>
                                                            ${uploadTime ? `
                                                            <small class="text-muted ms-4 mt-1">
                                                                上傳時間：${new Date(uploadTime).toLocaleString('zh-TW')}
                                                            </small>
                                                            ` : ''}
                                                        </div>
                                                        <a href="${escapeHtml(fileUrl)}" target="_blank" class="btn btn-sm btn-outline-primary ms-2" download>
                                                            <i class="fa-solid fa-download"></i> 下載
                                                        </a>
                                                    </div>
                                                </div>
                                            `;
                                        }).join('')}
                                    </div>
                                </div>
                            </div>
                            ` : ''}
                        </div>
                    `;
                    
                    // 使用 requestAnimationFrame 確保流暢顯示模態框
                    requestAnimationFrame(() => {
                        // 顯示詳細資訊模態框
                        const modal = document.createElement('div');
                        modal.className = 'modal fade';
                        modal.setAttribute('tabindex', '-1');
                        modal.setAttribute('aria-labelledby', 'detailModalLabel');
                        modal.setAttribute('aria-hidden', 'true');
                        modal.style.cssText = 'display: block; z-index: 1055;';
                        modal.innerHTML = `
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="detailModalLabel">提交記錄詳細資訊</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                                        ${detailHtml}
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
                                        <button type="button" class="btn btn-primary" onclick="editSubmission(${id}); window.location.href='pages/project_upload.php?edit=' + ${id};">編輯</button>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        const backdrop = document.createElement('div');
                        backdrop.className = 'modal-backdrop fade';
                        backdrop.style.cssText = 'z-index: 1050;';
                        
                        document.body.appendChild(backdrop);
                        document.body.appendChild(modal);
                        document.body.classList.add('modal-open');
                        document.body.style.overflow = 'hidden';
                        
                        // 使用 requestAnimationFrame 觸發動畫
                        requestAnimationFrame(() => {
                            backdrop.classList.add('show');
                            modal.classList.add('show');
                            
                            // PDF iframe 載入完成或失敗後移除載入提示
                            if (isPDF) {
                                const iframe = modal.querySelector('iframe');
                                if (iframe) {
                                    // 成功載入
                                    iframe.addEventListener('load', function() {
                                        const loader = modal.querySelector('#pdfLoader');
                                        if (loader) {
                                            loader.style.opacity = '0';
                                            setTimeout(() => loader.remove(), 300);
                                        }
                                    });
                                    
                                    // 載入失敗（iframe 可能不會觸發 error，但設置超時處理）
                                    setTimeout(() => {
                                        const loader = modal.querySelector('#pdfLoader');
                                        if (loader && loader.style.opacity !== '0') {
                                            // 如果 5 秒後還在顯示 spinner，可能是載入失敗
                                            loader.style.opacity = '0';
                                            setTimeout(() => {
                                                if (loader.parentElement) {
                                                    loader.remove();
                                                    const container = iframe.parentElement;
                                                    if (container && !container.querySelector('.text-danger')) {
                                                        container.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;"><i class="fa-solid fa-exclamation-triangle"></i><p class="mt-2 mb-0">海報載入失敗</p></div>';
                                                    }
                                                }
                                            }, 300);
                                        }
                                    }, 5000);
                                }
                            } else {
                                // 圖片載入：onerror 已在 HTML 中處理，這裡添加超時備用處理
                                const img = modal.querySelector('.detail-image');
                                if (img) {
                                    setTimeout(() => {
                                        if (img.style.opacity === '0' || img.style.opacity === '') {
                                            // 如果 5 秒後圖片還是透明的，可能是載入失敗
                                            const spinner = img.nextElementSibling;
                                            if (spinner) {
                                                spinner.remove();
                                                const container = img.parentElement;
                                                if (container && !container.querySelector('.text-danger')) {
                                                    container.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;"><i class="fa-solid fa-exclamation-triangle"></i><p class="mt-2 mb-0">海報載入失敗</p></div>';
                                                }
                                            }
                                        }
                                    }, 5000);
                                }
                            }
                        });
                        
                        // 簡化關閉邏輯
                        const closeModal = function() {
                            backdrop.classList.remove('show');
                            modal.classList.remove('show');
                            
                            setTimeout(() => {
                                if (document.body.contains(modal)) {
                                    document.body.removeChild(modal);
                                }
                                if (document.body.contains(backdrop)) {
                                    document.body.removeChild(backdrop);
                                }
                                document.body.classList.remove('modal-open');
                                document.body.style.overflow = '';
                                isViewingDetail = false;
                            }, 300);
                        };
                        
                        // 綁定關閉事件
                        const closeBtn = modal.querySelector('.btn-close');
                        const closeFooterBtn = modal.querySelector('.modal-footer .btn-secondary');
                        if (closeBtn) {
                            closeBtn.addEventListener('click', closeModal);
                        }
                        if (closeFooterBtn) {
                            closeFooterBtn.addEventListener('click', closeModal);
                        }
                        backdrop.addEventListener('click', closeModal);
                        
                        modal.addEventListener('click', function(e) {
                            if (e.target === modal) {
                                closeModal();
                            }
                        });
                        
                        // ESC 鍵關閉
                        const escHandler = function(e) {
                            if (e.key === 'Escape') {
                                closeModal();
                                document.removeEventListener('keydown', escHandler);
                            }
                        };
                        document.addEventListener('keydown', escHandler);
                    });
                } else {
                    isViewingDetail = false;
                    alert(data.message || '獲取詳細資訊失敗');
                }
            })
            .catch(error => {
                console.error('獲取詳細資訊錯誤:', error);
                isViewingDetail = false;
                requestAnimationFrame(() => {
                    if (document.body.contains(loadingModal)) {
                        loadingModal.classList.remove('show');
                        setTimeout(() => {
                            if (document.body.contains(loadingModal)) {
                                document.body.removeChild(loadingModal);
                            }
                            if (document.body.contains(loadingBackdrop)) {
                                document.body.removeChild(loadingBackdrop);
                            }
                            document.body.classList.remove('modal-open');
                            document.body.style.overflow = '';
                        }, 150);
                    }
                });
                alert('獲取詳細資訊失敗，請稍後再試');
            });
    }
}

function deleteSubmission(id) {
    if (!id) return;
        if (window.ProjectUpload && window.ProjectUpload.deleteSubmission) {
            window.ProjectUpload.deleteSubmission(id);
        } else {
            // 如果 ProjectUpload 還沒載入，直接調用 API
        if (confirm('確定要刪除這筆記錄嗎？')) {
            fetch('pages/upload_api.php?do=delete&prosub_ID=' + id, {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('刪除成功！');
                    window.location.reload();
                } else {
                    alert(data.message || '刪除失敗，請稍後再試');
                }
            })
            .catch(error => {
                console.error('刪除錯誤:', error);
                alert('刪除失敗，請稍後再試');
            });
        }
    }
}

function showHistory(id) {
    if (!id) return;
    if (window.ProjectUpload && window.ProjectUpload.showHistory) {
        window.ProjectUpload.showHistory(id);
    } else {
        // 如果 ProjectUpload 還沒載入，直接調用 API
        fetch('pages/upload_api.php?do=get_history&prosub_ID=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const history = data.history || [];
                    let historyHtml = '<div class="history-list">';
                    
                    if (history.length === 0) {
                        historyHtml += '<p class="text-muted text-center p-4 mb-0">尚無歷史提交紀錄</p>';
                    } else {
                        historyHtml += '<div class="history-list" style="display: flex; flex-direction: column; gap: 12px;">';
                        // 按時間倒序排列（最新的在前）
                        const sortedHistory = [...history].reverse();
                        sortedHistory.forEach((item) => {
                            // 只顯示已送出/已取代/已刪除
                            const action = item.action;
                            if (!['submitted', 'replaced', 'deleted'].includes(action)) {
                                return; // 跳過其他類型的記錄
                            }
                            
                            const actionText = {
                                'submitted': '已送出',
                                'replaced': '已取代',
                                'deleted': '已刪除'
                            }[action] || action;
                            
                            const userName = item.submitted_by_name || item.replaced_by_name || item.deleted_by_name || item.operator_name || '';
                            const userId = item.submitted_by || item.replaced_by || item.deleted_by || item.operator_id || '';
                            const user = userName || (userId ? `用戶ID: ${userId}` : '未知');
                            
                            // 簡單格式：提交人｜動作類型
                            historyHtml += `
                                <div style="padding: 12px 16px; border-bottom: 1px solid #e9ecef; font-size: 16px; color: #495057;">
                                    ${user}｜${actionText}
                                        </div>
                            `;
                        });
                        historyHtml += '</div>';
                    }
                    
                    historyHtml += '</div>';
                    
                    // 顯示歷史記錄模態框
                    const modal = document.createElement('div');
                    modal.className = 'modal fade';
                    modal.setAttribute('tabindex', '-1');
                    modal.setAttribute('aria-labelledby', 'historyModalLabel');
                    modal.setAttribute('aria-hidden', 'true');
                    modal.style.display = 'none';
                    modal.innerHTML = `
                        <div class="modal-dialog" style="max-width: 55%; width: 55%;">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="historyModalLabel">歷史提交紀錄</h5>
                                    <button type="button" class="btn-close" aria-label="Close"></button>
                                </div>
                                <div class="modal-body" style="min-height: 300px; max-height: 65vh; overflow-y: auto;">
                                    ${historyHtml}
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary">關閉</button>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    document.body.appendChild(modal);
                    
                    // 手動處理關閉功能（確保無論 Bootstrap 是否可用都能關閉）
                    const closeModal = function(e) {
                        if (e) {
                            e.preventDefault();
                            e.stopPropagation();
                        }
                        modal.style.display = 'none';
                        modal.classList.remove('show');
                        document.body.classList.remove('modal-open');
                        const backdrop = document.querySelector('.modal-backdrop');
                        if (backdrop) {
                            backdrop.remove();
                        }
                        if (document.body.contains(modal)) {
                            document.body.removeChild(modal);
                        }
                    };
                    
                    // 嘗試使用 Bootstrap Modal（如果可用）
                    let bsModal = null;
                    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                        try {
                            bsModal = new bootstrap.Modal(modal);
                            bsModal.show();
                            
                            // 在 Bootstrap Modal 顯示後綁定關閉按鈕事件
                            setTimeout(function() {
                                const closeBtn = modal.querySelector('.btn-close');
                                const closeFooterBtn = modal.querySelector('.modal-footer .btn');
                                
                                if (closeBtn) {
                                    closeBtn.onclick = function(e) {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        if (bsModal) {
                                            bsModal.hide();
                                        } else {
                                            closeModal(e);
                                        }
                                    };
                                }
                                if (closeFooterBtn) {
                                    closeFooterBtn.onclick = function(e) {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        if (bsModal) {
                                            bsModal.hide();
                                        } else {
                                            closeModal(e);
                                        }
                                    };
                                }
                            }, 100);
                            
                            // 如果 Bootstrap Modal 可用，使用它的事件
                            modal.addEventListener('hidden.bs.modal', function() {
                                closeModal();
                            });
                        } catch (e) {
                            console.warn('Bootstrap Modal 初始化失敗，使用手動關閉:', e);
                            // 手動顯示模態框
                            modal.style.display = 'block';
                            modal.classList.add('show');
                            document.body.classList.add('modal-open');
                            const backdrop = document.createElement('div');
                            backdrop.className = 'modal-backdrop fade show';
                            document.body.appendChild(backdrop);
                            backdrop.addEventListener('click', closeModal);
                            
                            // 綁定關閉按鈕事件
                            const closeBtn = modal.querySelector('.btn-close');
                            const closeFooterBtn = modal.querySelector('.modal-footer .btn');
                            
                            if (closeBtn) {
                                closeBtn.onclick = function(e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    closeModal(e);
                                };
                            }
                            if (closeFooterBtn) {
                                closeFooterBtn.onclick = function(e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    closeModal(e);
                                };
                            }
                        }
                    } else {
                        // 如果 Bootstrap 不可用，手動顯示模態框
                        modal.style.display = 'block';
                        modal.classList.add('show');
                        document.body.classList.add('modal-open');
                        const backdrop = document.createElement('div');
                        backdrop.className = 'modal-backdrop fade show';
                        document.body.appendChild(backdrop);
                        backdrop.addEventListener('click', closeModal);
                        
                        // 綁定關閉按鈕事件
                        const closeBtn = modal.querySelector('.btn-close');
                        const closeFooterBtn = modal.querySelector('.modal-footer .btn');
                        
                        if (closeBtn) {
                            closeBtn.onclick = function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                closeModal(e);
                            };
                        }
                        if (closeFooterBtn) {
                            closeFooterBtn.onclick = function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                closeModal(e);
                            };
                        }
                    }
                    
                    // 點擊背景關閉
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            if (bsModal) {
                                bsModal.hide();
                            } else {
                                closeModal(e);
                            }
                        }
                    });
                } else {
                    alert(data.message || '獲取歷史記錄失敗');
                }
            })
            .catch(error => {
                console.error('獲取歷史記錄錯誤:', error);
                alert('獲取歷史記錄失敗，請稍後再試');
            });
    }
}

function resetToDraft(id) {
    if (!id) return;
    if (window.ProjectUpload && window.ProjectUpload.resetToDraft) {
        window.ProjectUpload.resetToDraft(id);
    } else {
        // 如果 ProjectUpload 還沒載入，直接調用 API
        if (confirm('確定要重置回原本的暫存嗎？這將恢復到提交前的暫存狀態。')) {
            const formData = new FormData();
            formData.append('prosub_ID', id);
            
            fetch('pages/upload_api.php?do=reset_to_draft', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('已重置回原始暫存！');
                    window.location.reload();
                } else {
                    alert(data.message || '重置失敗，請稍後再試');
                }
            })
            .catch(error => {
                console.error('重置錯誤:', error);
                alert('重置失敗，請稍後再試');
            });
        }
    }
}

//  預覽檔案功能（支援多檔案預覽）
(function() {
    // 監聽預覽按鈕點擊事件
    document.addEventListener('click', function(e) {
        // 處理多檔案預覽按鈕
        if (e.target.closest('.preview-file-btn')) {
            const btn = e.target.closest('.preview-file-btn');
            const filePath = btn.getAttribute('data-file-path');
            const isPDF = btn.getAttribute('data-is-pdf') === '1';
            const isImage = btn.getAttribute('data-is-image') === '1';
            
            if (!filePath) return;
            
            // 容錯處理：檢查檔案是否存在（前端檢查）
            const fullPath = '../' + filePath;
            
            let modalContent = '';
            let modalTitle = '檔案預覽';
            
            if (isPDF) {
                modalContent = `<div style="position: relative; width: 100%; height: 80vh;">
                    <iframe src="${fullPath}" type="application/pdf" frameborder="0" style="width: 100%; height: 100%;" onload="this.style.opacity='1';"></iframe>
                    <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #f8f9fa; display: flex; align-items: center; justify-content: center; pointer-events: none; transition: opacity 0.3s;" id="pdfLoader">
                        <div class="spinner-border text-primary" role="status"></div>
                    </div>
                </div>`;
                modalTitle = 'PDF 預覽';
            } else if (isImage) {
                modalContent = `<img src="${fullPath}" alt="檔案預覽" style="max-width: 100%; max-height: 80vh; display: block; margin: 0 auto;" 
                      onerror="this.parentElement.querySelector('.modal-body').innerHTML='<div class=\'text-center text-muted p-4\'><i class=\'fa-solid fa-exclamation-triangle\'></i><p class=\'mt-2\'>無法載入圖片</p></div>';"
                      onload="this.style.opacity='1'; this.nextElementSibling && this.nextElementSibling.remove();">
                <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #f8f9fa; display: flex; align-items: center; justify-content: center; pointer-events: none;">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>`;
                modalTitle = '圖片預覽';
            } else {
                modalContent = `<div class="text-center text-muted p-4">
                    <i class="fa-solid fa-file" style="font-size: 48px; margin-bottom: 15px;"></i>
                    <p>此檔案類型不支援預覽，請下載後查看</p>
                    <a href="${fullPath}" target="_blank" class="btn btn-primary mt-2" download>
                        <i class="fa-solid fa-download"></i> 下載檔案
                    </a>
                </div>`;
                modalTitle = '檔案預覽';
            }
            
            // 創建預覽 Modal
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'filePreviewModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            modal.style.cssText = 'display: block; z-index: 1055;';
            
            modal.innerHTML = `
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="filePreviewModalLabel">${modalTitle}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-center" style="position: relative; min-height: 400px; padding: 20px;">
                            ${modalContent}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
                        </div>
                    </div>
                </div>
            `;
            
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade';
            backdrop.style.cssText = 'z-index: 1050;';
            
            document.body.appendChild(backdrop);
            document.body.appendChild(modal);
            document.body.classList.add('modal-open');
            document.body.style.overflow = 'hidden';
            
            // 觸發動畫
            requestAnimationFrame(() => {
                backdrop.classList.add('show');
                modal.classList.add('show');
                
                // PDF iframe 載入完成後移除載入提示
                if (isPDF) {
                    const iframe = modal.querySelector('iframe');
                    if (iframe) {
                        iframe.addEventListener('load', function() {
                            const loader = modal.querySelector('#pdfLoader');
                            if (loader) {
                                loader.style.opacity = '0';
                                setTimeout(() => loader.remove(), 300);
                            }
                        });
                    }
                }
            });
            
            // 關閉邏輯
            const closeModal = function() {
                backdrop.classList.remove('show');
                modal.classList.remove('show');
                
                setTimeout(() => {
                    if (document.body.contains(modal)) {
                        document.body.removeChild(modal);
                    }
                    if (document.body.contains(backdrop)) {
                        document.body.removeChild(backdrop);
                    }
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                }, 300);
            };
            
            // 綁定關閉事件
            const closeBtn = modal.querySelector('.btn-close');
            const closeFooterBtn = modal.querySelector('.modal-footer .btn-secondary');
            if (closeBtn) {
                closeBtn.addEventListener('click', closeModal);
            }
            if (closeFooterBtn) {
                closeFooterBtn.addEventListener('click', closeModal);
            }
            backdrop.addEventListener('click', closeModal);
            
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
            
            // ESC 鍵關閉
            const escHandler = function(e) {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
            return;
        }
        
        // 保留原有的海報預覽功能（如果還需要）
        if (e.target.closest('#previewPosterBtn')) {
            const btn = e.target.closest('#previewPosterBtn');
            const posterPath = btn.getAttribute('data-poster-path');
            const isPDF = btn.getAttribute('data-is-pdf') === '1';
            
            if (!posterPath) return;
            
            // 🔹 直接使用 prosub_img 的值（已包含資料夾的相對路徑），不添加 ../ 前綴
            const fullPath = posterPath;
            
            // 創建預覽 Modal
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'posterPreviewModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            modal.style.cssText = 'display: block; z-index: 1055;';
            
            const modalContent = isPDF ? 
                `<div style="position: relative; width: 100%; height: 80vh;">
                    <iframe src="${fullPath}" type="application/pdf" frameborder="0" style="width: 100%; height: 100%;" onload="this.style.opacity='1';"></iframe>
                    <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #f8f9fa; display: flex; align-items: center; justify-content: center; pointer-events: none; transition: opacity 0.3s;" id="pdfLoader">
                        <div class="spinner-border text-primary" role="status"></div>
                    </div>
                </div>` :
                `<img src="${fullPath}" alt="海報預覽" style="max-width: 100%; max-height: 80vh; display: block; margin: 0 auto;" 
                      onerror="this.parentElement.querySelector('.modal-body').innerHTML='<div class=\'text-center text-muted p-4\'><i class=\'fa-solid fa-exclamation-triangle\'></i><p class=\'mt-2\'>無法載入圖片</p></div>';"
                      onload="this.style.opacity='1'; this.nextElementSibling && this.nextElementSibling.remove();">
                <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #f8f9fa; display: flex; align-items: center; justify-content: center; pointer-events: none;">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>`;
            
            modal.innerHTML = `
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="posterPreviewModalLabel">海報預覽</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-center" style="position: relative; min-height: 400px; padding: 20px;">
                            ${modalContent}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
                        </div>
                    </div>
                </div>
            `;
            
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade';
            backdrop.style.cssText = 'z-index: 1050;';
            
            document.body.appendChild(backdrop);
            document.body.appendChild(modal);
            document.body.classList.add('modal-open');
            document.body.style.overflow = 'hidden';
            
            // 觸發動畫
            requestAnimationFrame(() => {
                backdrop.classList.add('show');
                modal.classList.add('show');
                
                // PDF iframe 載入完成後移除載入提示
                if (isPDF) {
                    const iframe = modal.querySelector('iframe');
                    if (iframe) {
                        iframe.addEventListener('load', function() {
                            const loader = modal.querySelector('#pdfLoader');
                            if (loader) {
                                loader.style.opacity = '0';
                                setTimeout(() => loader.remove(), 300);
                            }
                        });
                    }
                }
            });
            
            // 關閉邏輯
            const closeModal = function() {
                backdrop.classList.remove('show');
                modal.classList.remove('show');
                
                setTimeout(() => {
                    if (document.body.contains(modal)) {
                        document.body.removeChild(modal);
                    }
                    if (document.body.contains(backdrop)) {
                        document.body.removeChild(backdrop);
                    }
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                }, 300);
            };
            
            // 綁定關閉事件
            const closeBtn = modal.querySelector('.btn-close');
            const closeFooterBtn = modal.querySelector('.modal-footer .btn-secondary');
            if (closeBtn) {
                closeBtn.addEventListener('click', closeModal);
            }
            if (closeFooterBtn) {
                closeFooterBtn.addEventListener('click', closeModal);
            }
            backdrop.addEventListener('click', closeModal);
            
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
            
            // ESC 鍵關閉
            const escHandler = function(e) {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        }
    });
})();
</script>
