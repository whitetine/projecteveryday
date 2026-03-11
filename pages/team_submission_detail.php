<?php
session_start();
require '../includes/pdo.php';

// 檢查權限（主任 role_ID = 1 和 科辦 role_ID = 2）
$role_ID = $_SESSION['role_ID'] ?? null;
if (!isset($role_ID) || !in_array($role_ID, [1, 2])) {
    echo '<div class="alert alert-danger">您沒有權限訪問此頁面</div>';
    exit;
}

$team_ID = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : 0;
if ($team_ID <= 0) {
    echo '<div class="alert alert-danger">無效的團隊ID</div>';
    exit;
}

// 獲取團隊資訊
$teamInfo = null;
try {
    $stmt = $conn->prepare("
        SELECT 
            t.team_ID,
            t.team_project_name,
            t.cohort_ID,
            c.cohort_name,
            g.group_name
        FROM teamdata t
        LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
        LEFT JOIN groupdata g ON t.group_ID = g.group_ID
        WHERE t.team_ID = ? AND t.team_status = 1
    ");
    $stmt->execute([$team_ID]);
    $teamInfo = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo '<div class="alert alert-danger">獲取團隊資訊失敗</div>';
    exit;
}

// 獲取團隊成員資訊（用於生成申請表）
$teamMembers = [];
if ($teamInfo) {
    try {
        $teamUserField = 'team_u_ID';
        $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $teamUserField = 'u_ID';
        }
        
        $stmt = $conn->prepare("
            SELECT 
                u.u_ID,
                u.u_name,
                u.u_account as student_id,
                e.class_ID,
                c.c_name as class_name
            FROM teammember tm
            INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
            LEFT JOIN enrollmentdata e ON u.u_ID = e.enroll_u_ID AND e.cohort_ID = ?
            LEFT JOIN classdata c ON e.class_ID = c.c_ID
            WHERE tm.team_ID = ? 
              AND tm.tm_status = 1
              AND EXISTS (
                  SELECT 1 FROM userrolesdata ur 
                  WHERE ur.ur_u_ID = tm.{$teamUserField} 
                    AND ur.role_ID = 6 
                    AND ur.user_role_status = 1
              )
            ORDER BY tm.{$teamUserField}
        ");
        $stmt->execute([$teamInfo['cohort_ID'], $team_ID]);
        $teamMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("獲取團隊成員資訊失敗: " . $e->getMessage());
    }
}

if (!$teamInfo) {
    echo '<div class="alert alert-danger">找不到該團隊</div>';
    exit;
}

// 獲取專題資訊（用於設定截止時間）
$projectInfo = null;
try {
    $stmt = $conn->prepare("
        SELECT 
            p.pro_ID,
            p.pro_end_d
        FROM projectdata p
        INNER JOIN teamdata t ON p.pro_chorot_ID = t.cohort_ID
        WHERE t.team_ID = ? AND p.pro_status = 1
        ORDER BY p.pro_created_d DESC
        LIMIT 1
    ");
    $stmt->execute([$team_ID]);
    $projectInfo = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // 如果沒有專題資訊，繼續執行
    error_log("獲取專題資訊失敗: " . $e->getMessage());
}

// 🔹 【修改邏輯】學生一提交，科辦隨時都可以審核（移除期限檢查）
// 保留變數以維持前端顯示邏輯的兼容性
$isDeadlinePassed = true;  // 設為 true，允許隨時審核
$deadlineTime = null;

// 獲取該團隊的所有提交記錄（排除暫存）
$submissions = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            ps.prosub_ID,
            ps.prosub_img,
            ps.prosub_other,
            ps.prosub_status,
            ps.prosub_created_d,
            ps.prosub_update_d,
            ps.prosub_reason,
            ps.prosub_re_reason,
            ps.content_json,
            ps.prosub_u_ID,
            u.u_name as submitter_name
        FROM prosubdata ps
        LEFT JOIN userdata u ON ps.prosub_u_ID = u.u_ID
        WHERE ps.team_ID = ? AND ps.prosub_status != 4
        ORDER BY ps.prosub_created_d DESC
    ");
    $stmt->execute([$team_ID]);
    $rawSubmissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 處理每筆記錄，從 content_json 中判斷是否已刪除，並獲取更新者名稱
    $userIDs = [];
    foreach ($rawSubmissions as $sub) {
        if ($sub['prosub_u_ID']) {
            $userIDs[] = $sub['prosub_u_ID'];
        }
        // 從 content_json 中獲取更新者 ID
        $contentJson = json_decode($sub['content_json'] ?? '{}', true);
        if (isset($contentJson['updated_by'])) {
            $userIDs[] = $contentJson['updated_by'];
        }
    }
    
    // 批量獲取用戶名稱
    $userNames = [];
    if (!empty($userIDs)) {
        $userIDs = array_unique($userIDs);
        $placeholders = implode(',', array_fill(0, count($userIDs), '?'));
        $userStmt = $conn->prepare("SELECT u_ID, u_name FROM userdata WHERE u_ID IN ($placeholders)");
        $userStmt->execute($userIDs);
        $userResults = $userStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($userResults as $user) {
            $userNames[$user['u_ID']] = $user['u_name'];
        }
    }
    
    // 處理每筆記錄
    foreach ($rawSubmissions as $sub) {
        $contentJson = json_decode($sub['content_json'] ?? '{}', true);
        $sub['is_deleted'] = isset($contentJson['is_deleted']) && $contentJson['is_deleted'] === true;
        
        // 獲取更新者名稱
        $updaterID = $contentJson['updated_by'] ?? null;
        if ($updaterID && isset($userNames[$updaterID])) {
            $sub['updater_name'] = $userNames[$updaterID];
        } else {
            $sub['updater_name'] = null;
        }
        
        // 確保 submitter_name 有值
        if (!$sub['submitter_name'] && $sub['prosub_u_ID'] && isset($userNames[$sub['prosub_u_ID']])) {
            $sub['submitter_name'] = $userNames[$sub['prosub_u_ID']];
        }
        
        $submissions[] = $sub;
    }
} catch (Exception $e) {
    error_log("獲取提交記錄錯誤: " . $e->getMessage());
    $submissions = [];
}
?>
<!-- CSS 預載入，防止跑版 -->
<link rel="stylesheet" href="../css/project_submission_review.css?v=<?= time() ?>" id="teamSubmissionDetailCSS" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="../css/project_submission_review.css?v=<?= time() ?>"></noscript>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- html2pdf 庫 -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        .team-detail-container {
            padding: 20px;
            max-width: 100%;
            width: 100%;
            margin: 0;
            min-height: auto;
            box-sizing: border-box;
        }
        
        .team-detail-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .team-detail-title {
            font-size: 24px;
            font-weight: bold;
            margin: 0;
        }
        
        .team-info {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .btn-back {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 8px 20px;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-back:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            text-decoration: none;
        }
        
        .deadline-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .deadline-section label {
            font-weight: bold;
            margin-bottom: 10px;
            display: block;
        }
        
        .deadline-input-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .deadline-input-group input {
            flex: 1;
            max-width: 300px;
        }
        
        .submissions-section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            width: 100%;
            box-sizing: border-box;
            margin-top: 20px;
        }
        
        .submissions-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .submissions-title {
            font-size: 20px;
            font-weight: bold;
            color: #333;
        }
        
        .submission-count {
            color: #6c757d;
            font-size: 14px;
        }
        
        .submission-list {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            overflow-x: auto;
            padding-bottom: 10px;
        }
        
        .submission-item {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border: 2px solid #e9ecef;
            border-radius: 16px;
            padding: 15px;
            transition: all 0.3s ease;
            position: relative;
            min-width: 320px;
            max-width: 400px;
            width: 100%;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .submission-item:not(.deleted):hover {
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.2);
            transform: translateY(-4px);
            border-color: #667eea;
        }
        
        .submission-item.deleted {
            opacity: 0.3;
            filter: grayscale(50%);
            background: #f5f5f5;
        }
        
        .submission-status {
            position: absolute;
            top: 8px;
            left: 8px;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            z-index: 10;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            white-space: nowrap;
        }
        
        .status-pending {
            background: #f97316;
            color: white;
        }
        
        .status-approved {
            background: #28a745;
            color: white;
        }
        
        .status-rejected {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            color: white;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
        }
        
        .status-deleted {
            background: #9ca3af;
            color: white;
        }
        
        .submission-content {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 25px;
        }
        
        .submission-preview {
            width: 100%;
            max-height: 180px;
            min-height: 180px;
            overflow: hidden;
            border-radius: 8px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #e9ecef;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }
        
        .submission-preview:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            transform: scale(1.02);
        }
        
        .submission-preview::after {
            content: '點擊查看大圖';
            position: absolute;
            bottom: 8px;
            right: 8px;
            background: rgba(0, 0, 0, 0.6);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .submission-preview:hover::after {
            opacity: 1;
        }
        
        .submission-preview img {
            max-width: 100%;
            max-height: 180px;
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .submission-preview object {
            width: 100%;
            height: 180px;
        }
        
        .submission-info {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 10px;
        }
        
        .submission-info-item {
            display: flex;
            flex-direction: column;
            gap: 3px;
            padding: 6px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .submission-info-item:last-child {
            border-bottom: none;
        }
        
        .submission-info-label {
            font-weight: 600;
            color: #495057;
            font-size: 12px;
            margin-bottom: 2px;
            white-space: nowrap;
        }
        
        .submission-info-value {
            color: #6c757d;
            font-size: 13px;
            word-wrap: break-word;
            word-break: break-word;
            line-height: 1.4;
            overflow-wrap: break-word;
        }
        
        .submission-group-name {
            font-weight: 600;
            color: #667eea;
            font-size: 15px;
            margin: 0;
            word-wrap: break-word;
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .submission-file-name {
            font-weight: 600;
            color: #333;
            margin: 0;
            font-size: 13px;
            word-wrap: break-word;
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .submission-intro {
            color: #495057;
            font-size: 12px;
            line-height: 1.5;
            margin: 0;
            word-wrap: break-word;
            word-break: break-word;
            overflow-wrap: break-word;
            white-space: pre-wrap;
            max-height: 60px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .submission-time {
            color: #6c757d;
            margin: 0;
            font-size: 12px;
            white-space: nowrap;
        }
        
        .submission-submitter {
            color: #6c757d;
            margin: 0;
            font-size: 12px;
            word-wrap: break-word;
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .submission-files-list {
            max-height: 120px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 6px;
            background: #f8f9fa;
        }
        
        .submission-file-item {
            transition: background-color 0.2s;
        }
        
        .submission-file-item:hover {
            background-color: #ffffff;
            border-radius: 4px;
            padding-left: 8px;
            padding-right: 8px;
        }
        
        .submission-file-item:last-child {
            border-bottom: none;
        }
        
        .submission-file-name {
            transition: color 0.2s;
        }
        
        .submission-file-item:hover .submission-file-name {
            color: #667eea;
        }
        
        .submission-actions {
            display: flex;
            gap: 6px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        
        .submission-actions .btn {
            flex: 0 0 auto;
            font-size: 12px;
            padding: 6px 12px;
            min-width: auto;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            white-space: nowrap;
            overflow: visible;
            text-overflow: clip;
        }
        
        .submission-actions .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .submission-actions .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
        }
        
        .submission-actions .btn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            border: none;
            color: white;
        }
        
        .submission-actions .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        
        .submission-actions .btn-info {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            border: none;
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
    </style>

<div class="team-detail-container">
        <div class="team-detail-header">
            <div>
                <h1 class="team-detail-title">
                    <i class="fa-solid fa-list"></i> <?= htmlspecialchars($teamInfo['team_project_name']) ?>
                </h1>
                <div class="team-info">
                    <?= htmlspecialchars($teamInfo['cohort_name'] ?? '') ?> · <?= htmlspecialchars($teamInfo['group_name'] ?? '') ?>
                </div>
            </div>
            <a href="javascript:void(0);" onclick="goBackToList()" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i> 返回列表
            </a>
        </div>
        
        <div class="submissions-section">
            <div class="submissions-header">
                <div>
                    <h2 class="submissions-title">提交記錄</h2>
                    <div class="submission-count">共 <?= count($submissions) ?> 筆</div>
                </div>
            </div>
            
            <?php if (empty($submissions)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-inbox"></i>
                <p>此團隊尚無提交記錄</p>
            </div>
            <?php else: ?>
            <div class="submission-list">
                <?php foreach ($submissions as $sub): 
                    $status = (int)$sub['prosub_status'];
                    $isDeleted = (bool)$sub['is_deleted'];
                    
                    // 🔹 【期限檢查】使用循環外部已獲取的期限信息（避免重複查詢）
                    // $isDeadlinePassed 已在循環外部統一獲取
                    
                    // 狀態文字和樣式
                    $statusText = '';
                    $statusClass = '';
                    if ($isDeleted) {
                        $statusText = '已刪除';
                        $statusClass = 'status-deleted';
                    } elseif ($status === 0) {
                        $statusText = '退件';
                        $statusClass = 'status-rejected';
                    } elseif ($status === 1) {
                        $statusText = '未審核';
                        $statusClass = 'status-pending';
                    } elseif ($status === 3) {
                        $statusText = '通過';
                        $statusClass = 'status-approved';
                    } else {
                        $statusText = '未知';
                        $statusClass = 'status-pending';
                    }
                    
                    $imagePath = $sub['prosub_img'] ?? '';
                    $isPDF = $imagePath && strtolower(pathinfo($imagePath, PATHINFO_EXTENSION)) === 'pdf';
                    $fileName = $imagePath ? basename($imagePath) : '無檔案';
                    $submitTime = $sub['prosub_created_d'] ? date('Y/m/d 下午H:i:s', strtotime($sub['prosub_created_d'])) : '無';
                    $submitterName = $sub['submitter_name'] ?? '未知';
                    
                    // 解析多個檔案列表（在設置 data 屬性之前）
                    $otherFiles = [];
                    if (!empty($sub['prosub_other'])) {
                        $otherFilesJson = json_decode($sub['prosub_other'], true);
                        if (is_array($otherFilesJson)) {
                            foreach ($otherFilesJson as $file) {
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
                                        $fileNameItem = $file['original_name'] ?? $file['name'] ?? basename($file['path']);
                                        $uploadTime = $file['uploaded_at'] ?? $file['upload_time'] ?? '';
                                        $isPublic = isset($file['public']) ? (bool)$file['public'] : (isset($file['allow_download']) ? (bool)$file['allow_download'] : true);
                                        
                                        $otherFiles[] = [
                                            'name' => $fileNameItem,
                                            'path' => $file['path'],
                                            'type' => $file['type'] ?? '',
                                            'uploaded_at' => $uploadTime,
                                            'public' => $isPublic
                                        ];
                                    }
                                }
                            }
                        } elseif (is_string($sub['prosub_other'])) {
                            // 兼容舊格式（可能是逗號分隔的字符串）
                            $trimmedOther = trim($sub['prosub_other']);
                            if (substr($trimmedOther, 0, 1) === '{' || substr($trimmedOther, 0, 1) === '[') {
                                // JSON 格式但解析失敗，嘗試再次解析
                                $retryJson = json_decode($trimmedOther, true);
                                if (is_array($retryJson)) {
                                    foreach ($retryJson as $file) {
                                        if (is_string($file)) {
                                            $otherFiles[] = [
                                                'name' => basename($file),
                                                'path' => $file,
                                                'type' => '',
                                                'uploaded_at' => '',
                                                'public' => true
                                            ];
                                        } elseif (is_array($file) && isset($file['path'])) {
                                            $fileNameItem = $file['original_name'] ?? $file['name'] ?? basename($file['path']);
                                            $uploadTime = $file['uploaded_at'] ?? $file['upload_time'] ?? '';
                                            $isPublic = isset($file['public']) ? (bool)$file['public'] : (isset($file['allow_download']) ? (bool)$file['allow_download'] : true);
                                            
                                            $otherFiles[] = [
                                                'name' => $fileNameItem,
                                                'path' => $file['path'],
                                                'type' => $file['type'] ?? '',
                                                'uploaded_at' => $uploadTime,
                                                'public' => $isPublic
                                            ];
                                        }
                                    }
                                }
                            } else {
                                // 逗號分隔的字符串格式
                                $filePaths = array_filter(array_map('trim', explode(',', $sub['prosub_other'])));
                                foreach ($filePaths as $filePath) {
                                    $otherFiles[] = [
                                        'name' => basename($filePath),
                                        'path' => $filePath,
                                        'type' => '',
                                        'uploaded_at' => '',
                                        'public' => true
                                    ];
                                }
                            }
                        }
                    }
                ?>
                <div class="submission-item <?= $isDeleted ? 'deleted' : '' ?>" data-prosub-id="<?= $sub['prosub_ID'] ?>" data-other-files="<?= htmlspecialchars(json_encode($otherFiles, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="submission-status <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                    
                    <div class="submission-content">
                        <!-- 組別名 -->
                        <div class="submission-info-item">
                            <span class="submission-info-label">組別：</span>
                            <p class="submission-group-name"><?= htmlspecialchars($teamInfo['group_name'] ?? '') ?></p>
                        </div>
                        
                        <!-- 查看圖檔 -->
                        <?php if ($imagePath): 
                            // 確保圖片路徑正確（如果是相對路徑，加上前綴）
                            $fullImagePath = $imagePath;
                            if (!preg_match('/^https?:\/\//', $imagePath) && !preg_match('/^\//', $imagePath)) {
                                // 如果是相對路徑，檢查是否需要加上 ../
                                $fullImagePath = '../' . ltrim($imagePath, '/');
                            }
                        ?>
                        <div class="submission-preview" onclick="viewImageModal('<?= htmlspecialchars($fullImagePath) ?>', <?= $isPDF ? 'true' : 'false' ?>)">
                            <?php if ($isPDF): ?>
                                <object data="<?= htmlspecialchars($fullImagePath) ?>" type="application/pdf" class="preview-object"></object>
                            <?php else: ?>
                                <img src="<?= htmlspecialchars($fullImagePath) ?>" alt="預覽" class="preview-image" loading="lazy" onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'200\'%3E%3Crect fill=\'%23f0f0f0\' width=\'200\' height=\'200\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\' font-family=\'Arial\' font-size=\'14\'%3E圖片載入失敗%3C/text%3E%3C/svg%3E';">
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="submission-preview" style="background: linear-gradient(135deg, #f0f0f0 0%, #e0e0e0 100%);">
                            <div style="text-align: center; color: #999;">
                                <i class="fa-solid fa-file" style="font-size: 48px; margin-bottom: 15px;"></i>
                                <p style="margin: 0; font-size: 14px;">無圖檔</p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="submission-info">
                            <!-- 簡介 -->
                            <?php 
                            $contentJson = json_decode($sub['content_json'] ?? '{}', true);
                            $intro = $contentJson['intro'] ?? '';
                            if ($intro): 
                            ?>
                            <div class="submission-info-item">
                                <span class="submission-info-label">簡介：</span>
                                <p class="submission-intro"><?= htmlspecialchars($intro) ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <!-- 上傳時間 -->
                            <div class="submission-info-item">
                                <span class="submission-info-label">上傳時間：</span>
                                <p class="submission-time"><?= htmlspecialchars($submitTime) ?></p>
                            </div>
                            
                            <!-- 上傳人 -->
                            <div class="submission-info-item">
                                <span class="submission-info-label">上傳人：</span>
                                <p class="submission-submitter"><?= htmlspecialchars($submitterName) ?></p>
                            </div>
                            
                            <!-- 多個檔案列表 -->
                            <?php if (!empty($otherFiles)): 
                            ?>
                            <div class="submission-info-item">
                                <span class="submission-info-label">其他檔案 (<?= count($otherFiles) ?>)：</span>
                                <div class="submission-files-list" style="margin-top: 8px;">
                                    <?php foreach ($otherFiles as $index => $file): 
                                        $filePath = $file['path'] ?? '';
                                        $fileName = $file['name'] ?? basename($filePath);
                                        $fullFilePath = $filePath ? '../' . $filePath : '';
                                        $fileExists = $filePath && file_exists($fullFilePath);
                                        $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                                        
                                        // 根據檔案類型選擇圖標
                                        $fileIcon = 'fa-file';
                                        if (in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'])) {
                                            $fileIcon = 'fa-file-image';
                                        } elseif ($fileExtension === 'pdf') {
                                            $fileIcon = 'fa-file-pdf';
                                        } elseif (in_array($fileExtension, ['doc', 'docx'])) {
                                            $fileIcon = 'fa-file-word';
                                        } elseif (in_array($fileExtension, ['xls', 'xlsx'])) {
                                            $fileIcon = 'fa-file-excel';
                                        } elseif (in_array($fileExtension, ['ppt', 'pptx'])) {
                                            $fileIcon = 'fa-file-powerpoint';
                                        } elseif (in_array($fileExtension, ['zip', 'rar', '7z'])) {
                                            $fileIcon = 'fa-file-archive';
                                        }
                                    ?>
                                    <div class="submission-file-item" style="display: flex; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px solid #f0f0f0;">
                                        <i class="fa-solid <?= $fileIcon ?> me-2" style="color: #667eea; font-size: 14px;"></i>
                                        <span class="submission-file-name" style="flex: 1; font-size: 13px; color: #495057; word-break: break-word;"><?= htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($fileExists): 
                                            $isImageFile = in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']);
                                            $isPDFFile = $fileExtension === 'pdf';
                                            $canPreview = $isImageFile || $isPDFFile;
                                        ?>
                                        <div class="d-flex gap-2" style="flex-shrink: 0;">
                                            <?php if ($canPreview): ?>
                                            <button type="button" class="btn btn-sm btn-info" onclick="viewImageModal('<?= htmlspecialchars($fullFilePath, ENT_QUOTES, 'UTF-8') ?>', <?= $isPDFFile ? 'true' : 'false' ?>)" style="padding: 4px 12px; font-size: 12px;">
                                                <i class="fa-solid fa-eye"></i> 查看
                                            </button>
                                            <?php endif; ?>
                                            <a href="<?= htmlspecialchars($fullFilePath, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-sm btn-outline-primary" download style="padding: 4px 12px; font-size: 12px;">
                                                <i class="fa-solid fa-download"></i> 下載
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="submission-actions">
                        <?php if ($isDeleted): ?>
                            <button type="button" class="btn btn-primary" onclick="restoreSubmission(<?= $sub['prosub_ID'] ?>)">
                                <i class="fa-solid fa-undo"></i> 恢復
                            </button>
                        <?php else: ?>
                            <!-- 🔹 【新邏輯】科辦隨時都可以審核（一旦學生繳交，不論期限是否截止） -->
                            <?php if ($status === 1): ?>
                                <button type="button" class="btn btn-success" onclick="reviewSubmission(<?= $sub['prosub_ID'] ?>, 'approve')">
                                    <i class="fa-solid fa-check"></i> 通過
                                </button>
                                <button type="button" class="btn btn-warning" onclick="reviewSubmission(<?= $sub['prosub_ID'] ?>, 'reject')">
                                    <i class="fa-solid fa-times"></i> 退件
                                </button>
                            <?php elseif ($status === 3): ?>
                                <button type="button" class="btn btn-warning" onclick="reviewSubmission(<?= $sub['prosub_ID'] ?>, 'cancel_approve')">
                                    <i class="fa-solid fa-undo"></i> 取消通過
                                </button>
                            <?php elseif ($status === 0): ?>
                                <button type="button" class="btn btn-warning" onclick="reviewSubmission(<?= $sub['prosub_ID'] ?>, 'cancel_reject')">
                                    <i class="fa-solid fa-undo"></i> 取消退件
                                </button>
                            <?php endif; ?>
                            
                            <div class="d-flex gap-2" style="flex-wrap: wrap;">
                                <button type="button" class="btn btn-primary" onclick="viewSubmissionDetail(<?= $sub['prosub_ID'] ?>)">
                                    <i class="fa-solid fa-eye"></i> 查看詳細資料
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        const API_BASE = 'pages/project_submission_review_api.php';
        const team_ID = <?= $team_ID ?>;
        const pro_ID = <?= $projectInfo && $projectInfo['pro_ID'] ? $projectInfo['pro_ID'] : 'null' ?>;
        
        /**
         * 返回列表頁面（專題提交審核頁面）
         */
        function goBackToList() {
            // 統一使用 hash 路由返回專題提交審核頁面（main.php 架構）
            // 檢查當前是否在 main.php 中
            const pathname = window.location.pathname;
            
            if (pathname.includes('main.php')) {
                // 在 main.php 中，使用 hash 路由
                window.location.hash = 'pages/project_submission_review.php';
            } else {
                // 不在 main.php 中，跳轉到 main.php 並設置 hash
                window.location.href = '../main.php#pages/project_submission_review.php';
            }
        }
        
        /**
         * 儲存補交截止時間
         */
        async function saveDeadline() {
            if (!pro_ID) {
                await showAlertDialog('無法設定補交截止時間：找不到專題資料', 'error');
                return;
            }
            
            const deadlineInput = document.getElementById('deadlineInput');
            const newDeadline = deadlineInput.value;
            
            if (!newDeadline) {
                await showAlertDialog('請選擇補交截止時間', 'warning');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('pro_ID', pro_ID);
                formData.append('deadline', newDeadline);
                
                const response = await fetch(`${API_BASE}?do=set_deadline`, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    await showAlertDialog('補交截止時間設定成功！', 'success');
                } else {
                    await showAlertDialog('設定失敗：' + (data.message || '未知錯誤'), 'error');
                }
            } catch (error) {
                console.error('設定補交截止時間失敗:', error);
                await showAlertDialog('設定失敗，請稍後再試', 'error');
            }
        }
        
        /**
         * 自定義提示框（替換 alert）
         * @param {string} message - 提示訊息
         * @param {string} type - 類型：'success', 'error', 'info', 'warning'（默認 'info'）
         * @param {number} autoClose - 自動關閉時間（毫秒），0 表示不自動關閉（默認 0）
         * @returns {Promise<void>}
         */
        function showAlertDialog(message, type = 'info', autoClose = 0) {
            return new Promise((resolve) => {
                const modal = document.createElement('div');
                modal.className = 'modal fade';
                modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center;';
                
                const backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop fade show';
                backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1054;';
                
                const typeConfig = {
                    'success': { bg: 'linear-gradient(135deg, #28a745 0%, #20c997 100%)', icon: 'fa-check-circle' },
                    'error': { bg: 'linear-gradient(135deg, #dc3545 0%, #c82333 100%)', icon: 'fa-exclamation-circle' },
                    'warning': { bg: 'linear-gradient(135deg, #ffc107 0%, #ff9800 100%)', icon: 'fa-exclamation-triangle' },
                    'info': { bg: 'linear-gradient(135deg, #17a2b8 0%, #138496 100%)', icon: 'fa-info-circle' }
                };
                
                const config = typeConfig[type] || typeConfig['info'];
                
                const iconColor = type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : type === 'warning' ? '#ffc107' : '#7eb8da';
                modal.innerHTML = `
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content" style="background: #fff; border-radius: 14px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,0.15); padding: 36px 32px; text-align: center; min-width: 360px;">
                            <div style="width: 72px; height: 72px; margin: 0 auto 20px; border: 2px solid ${iconColor}; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <i class="fa-solid ${config.icon}" style="font-size: 32px; color: ${iconColor};"></i>
                            </div>
                            <h5 class="modal-title" style="font-weight: 600; font-size: 20px; color: #333; margin: 0 0 12px;">提示</h5>
                            <p style="font-size: 17px; color: #333; line-height: 1.5; margin: 0 0 28px;">${escapeHtml(message).replace(/\n/g, '<br>')}</p>
                            <div style="display: flex; justify-content: center;">
                                <button type="button" class="btn confirm-btn" style="border-radius: 8px; padding: 12px 28px; font-weight: 600; min-width: 100px; font-size: 16px; background: #667eea; color: #fff; border: none;">確定</button>
                            </div>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(backdrop);
                document.body.appendChild(modal);
                document.body.style.overflow = 'hidden';
                
                requestAnimationFrame(() => {
                    backdrop.style.opacity = '1';
                    modal.style.opacity = '1';
                });
                
                function closeModal() {
                    backdrop.style.opacity = '0';
                    modal.style.opacity = '0';
                    setTimeout(() => {
                        if (document.body.contains(modal)) document.body.removeChild(modal);
                        if (document.body.contains(backdrop)) document.body.removeChild(backdrop);
                        document.body.style.overflow = '';
                        resolve();
                    }, 150);
                }
                
                const confirmBtn = modal.querySelector('.confirm-btn');
                confirmBtn.onclick = closeModal;
                backdrop.onclick = closeModal;
                
                const escHandler = (e) => {
                    if (e.key === 'Escape') {
                        closeModal();
                        document.removeEventListener('keydown', escHandler);
                    }
                };
                document.addEventListener('keydown', escHandler);
                
                if (autoClose > 0) {
                    setTimeout(closeModal, autoClose);
                }
                
                setTimeout(() => confirmBtn.focus(), 100);
            });
        }
        
        /**
         * 自定義確認對話框
         */
        function showConfirmDialog(message, title = '確認') {
            return new Promise((resolve) => {
                const modal = document.createElement('div');
                modal.className = 'modal fade';
                modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center;';
                
                const backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop fade show';
                backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1054;';
                
                modal.innerHTML = `
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content" style="background: #fff; border-radius: 14px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,0.15); padding: 36px 32px; text-align: center; min-width: 360px;">
                            <div style="width: 72px; height: 72px; margin: 0 auto 20px; border: 2px solid #7eb8da; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <span style="font-size: 36px; font-weight: 600; color: #7eb8da;">?</span>
                            </div>
                            <h5 class="modal-title" style="font-weight: 600; font-size: 20px; color: #333; margin: 0 0 12px;">${escapeHtml(title)}</h5>
                            <p style="font-size: 17px; color: #333; line-height: 1.5; margin: 0 0 28px;">${escapeHtml(message).replace(/\n/g, '<br>')}</p>
                            <div style="display: flex; justify-content: center; gap: 16px;">
                                <button type="button" class="btn cancel-btn" style="border-radius: 8px; padding: 12px 28px; font-weight: 600; min-width: 100px; font-size: 16px; background: #5a6268; color: #fff; border: none;">取消</button>
                                <button type="button" class="btn confirm-btn" style="border-radius: 8px; padding: 12px 28px; font-weight: 600; min-width: 100px; font-size: 16px; background: #667eea; color: #fff; border: none;">確定</button>
                            </div>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(backdrop);
                document.body.appendChild(modal);
                document.body.style.overflow = 'hidden';
                
                requestAnimationFrame(() => {
                    backdrop.style.opacity = '1';
                    modal.style.opacity = '1';
                });
                
                const closeModal = function() {
                    backdrop.style.opacity = '0';
                    modal.style.opacity = '0';
                    setTimeout(() => {
                        if (document.body.contains(modal)) document.body.removeChild(modal);
                        if (document.body.contains(backdrop)) document.body.removeChild(backdrop);
                        document.body.style.overflow = '';
                    }, 150);
                };
                
                const confirmBtn = modal.querySelector('.confirm-btn');
                const cancelBtn = modal.querySelector('.cancel-btn');
                confirmBtn.onclick = () => { closeModal(); resolve(true); };
                cancelBtn.onclick = () => { closeModal(); resolve(false); };
                backdrop.onclick = () => { closeModal(); resolve(false); };
                
                const escHandler = (e) => {
                    if (e.key === 'Escape') {
                        closeModal();
                        resolve(false);
                        document.removeEventListener('keydown', escHandler);
                    }
                };
                document.addEventListener('keydown', escHandler);
            });
        }
        
        /**
         * 自定義輸入對話框
         */
        function showPromptDialog(message, title = '輸入', defaultValue = '') {
            return new Promise((resolve) => {
                const modal = document.createElement('div');
                modal.className = 'modal fade';
                modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center;';
                
                const backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop fade show';
                backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1054;';
                
                modal.innerHTML = `
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content" style="background: #fff; border-radius: 14px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,0.15); padding: 36px 32px; text-align: center; min-width: 360px;">
                            <div style="width: 72px; height: 72px; margin: 0 auto 20px; border: 2px solid #7eb8da; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <span style="font-size: 36px; font-weight: 600; color: #7eb8da;">?</span>
                            </div>
                            <h5 class="modal-title" style="font-weight: 600; font-size: 20px; color: #333; margin: 0 0 20px;">${escapeHtml(title)}</h5>
                            <div style="text-align: left; margin-bottom: 28px;">
                                <label style="font-size: 17px; color: #333; margin-bottom: 10px; display: block;">${escapeHtml(message)}</label>
                                <input type="text" class="form-control prompt-input" value="${escapeHtml(defaultValue)}" style="border-radius: 8px; padding: 12px; font-size: 16px; border: 2px solid #e9ecef; width: 100%;" autofocus>
                            </div>
                            <div style="display: flex; justify-content: center; gap: 16px;">
                                <button type="button" class="btn cancel-btn" style="border-radius: 8px; padding: 12px 28px; font-weight: 600; min-width: 100px; font-size: 16px; background: #5a6268; color: #fff; border: none;">取消</button>
                                <button type="button" class="btn confirm-btn" style="border-radius: 8px; padding: 12px 28px; font-weight: 600; min-width: 100px; font-size: 16px; background: #667eea; color: #fff; border: none;">確定</button>
                            </div>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(backdrop);
                document.body.appendChild(modal);
                document.body.style.overflow = 'hidden';
                
                const input = modal.querySelector('.prompt-input');
                
                requestAnimationFrame(() => {
                    backdrop.style.opacity = '1';
                    modal.style.opacity = '1';
                    if (input) input.focus();
                });
                
                const closeModal = function() {
                    backdrop.style.opacity = '0';
                    modal.style.opacity = '0';
                    setTimeout(() => {
                        if (document.body.contains(modal)) document.body.removeChild(modal);
                        if (document.body.contains(backdrop)) document.body.removeChild(backdrop);
                        document.body.style.overflow = '';
                    }, 150);
                };
                
                const confirmBtn = modal.querySelector('.confirm-btn');
                const cancelBtn = modal.querySelector('.cancel-btn');
                
                const handleConfirm = () => {
                    const value = input ? input.value : '';
                    closeModal();
                    resolve(value);
                };
                
                const handleCancel = () => {
                    closeModal();
                    resolve(null);
                };
                
                confirmBtn.onclick = handleConfirm;
                cancelBtn.onclick = handleCancel;
                backdrop.onclick = handleCancel;
                
                if (input) {
                    input.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            handleConfirm();
                        } else if (e.key === 'Escape') {
                            e.preventDefault();
                            handleCancel();
                        }
                    });
                }
                
                const escHandler = (e) => {
                    if (e.key === 'Escape') {
                        handleCancel();
                        document.removeEventListener('keydown', escHandler);
                    }
                };
                document.addEventListener('keydown', escHandler);
            });
        }
        
        /**
         * 審核提交
         * 規則（減少視窗，即時更新）：
         * - 通過 / 取消通過：只跳「確認視窗」一次，成功後直接更新畫面
         * - 取消退件：直接執行，不跳視窗，成功後立即更新畫面
         * - 退件：只跳「輸入退件原因」視窗一次（除非沒填才會額外警告）
         */
        async function reviewSubmission(prosub_ID, action) {
            const actionText = {
                'approve': '通過',
                'reject': '退件',
                'cancel_approve': '取消通過',
                'cancel_reject': '取消退件'
            }[action] || '操作';
            
            let reason = '';
            
            if (action === 'reject') {
                // 退件：只顯示「輸入退件原因」這個視窗
                reason = await showPromptDialog('請輸入退件原因：', '退件原因');
                if (reason === null) return; // 用戶取消
                if (!reason.trim()) {
                    // 沒填才額外提醒一次
                    await showAlertDialog('請輸入退件原因', 'warning');
                    return;
                }
            } else if (action === 'approve' || action === 'cancel_approve') {
                // 通過 / 取消通過：顯示確認視窗
                const confirmed = await showConfirmDialog(`確定要${actionText}此提交嗎？`, '確認操作');
                if (!confirmed) {
                    return;
                }
            }
            // cancel_reject：直接執行，不顯示確認視窗
            
            // 樂觀更新：對於取消通過等操作，確認後立即更新 UI，讓用戶感覺更靈敏
            let optimisticUpdateDone = false;
            if (action === 'cancel_approve' || action === 'cancel_reject') {
                try {
                    // 立即更新 UI（樂觀更新），確保在對話框關閉後立即執行
                    // 使用 requestAnimationFrame 確保 DOM 已準備好
                    requestAnimationFrame(() => {
                        try {
                            updateSubmissionStatusInDetail(prosub_ID, action);
                            optimisticUpdateDone = true;
                            console.log(`樂觀更新完成: prosub_ID=${prosub_ID}, action=${action}`);
                        } catch (e) {
                            console.error('樂觀更新執行失敗:', e);
                        }
                    });
                } catch (e) {
                    console.warn('樂觀更新設置失敗，將等待 API 回應:', e);
                }
            }
            
            try {
                const formData = new FormData();
                formData.append('prosub_ID', prosub_ID);
                formData.append('action', action);
                formData.append('reason', reason);
                
                const response = await fetch(`${API_BASE}?do=review`, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // 無論是否已經樂觀更新過，都再次確認更新（作為保險，確保 UI 正確）
                    // 使用 requestAnimationFrame 確保更新在下一幀執行
                    requestAnimationFrame(() => {
                        try {
                            updateSubmissionStatusInDetail(prosub_ID, action);
                            console.log(`API 成功後更新完成: prosub_ID=${prosub_ID}, action=${action}`);
                        } catch (e) {
                            console.error('API 成功後更新失敗，強制重新整理頁面:', e);
                            location.reload();
                        }
                    });
                    // 不顯示成功提示，因為 UI 已經更新，用戶可以看到變化
                } else {
                    // API 失敗，如果已經樂觀更新過，需要回滾
                    if (optimisticUpdateDone) {
                        console.warn('API 失敗，回滾樂觀更新');
                        location.reload();
                    }
                    await showAlertDialog(`${actionText}失敗：${data.message || '未知錯誤'}`, 'error');
                }
            } catch (error) {
                console.error('審核失敗:', error);
                // API 請求失敗，如果已經樂觀更新過，需要回滾
                if (optimisticUpdateDone) {
                    console.warn('API 請求失敗，回滾樂觀更新');
                    location.reload();
                }
                await showAlertDialog(`${actionText}失敗，請稍後再試`, 'error');
            }
        }

        /**
         * 在團隊提交詳情頁即時更新某一筆提交卡片的狀態與按鈕
         * @param {number} prosub_ID 
         * @param {'approve'|'reject'|'cancel_approve'|'cancel_reject'} action 
         */
        function updateSubmissionStatusInDetail(prosub_ID, action) {
            // 使用更精確的選擇器，嘗試多種方式查找元素
            let item = document.querySelector(`.submission-item[data-prosub-id="${prosub_ID}"]`);
            if (!item) {
                // 如果找不到，嘗試使用屬性選擇器
                item = document.querySelector(`[data-prosub-id="${prosub_ID}"]`);
            }
            if (!item) {
                console.error(`找不到提交項目 prosub_ID=${prosub_ID}，嘗試重新整理頁面`);
                console.log('當前頁面中的所有 submission-item:', document.querySelectorAll('.submission-item'));
                location.reload();
                return;
            }
            
            const statusSpan = item.querySelector('.submission-status');
            const actionsContainer = item.querySelector('.submission-actions');
            if (!statusSpan) {
                console.error(`找不到狀態元素 prosub_ID=${prosub_ID}`);
                console.log('item 內容:', item.innerHTML.substring(0, 200));
                location.reload();
                return;
            }
            if (!actionsContainer) {
                console.error(`找不到操作容器 prosub_ID=${prosub_ID}`);
                location.reload();
                return;
            }

            // 先移除所有可能的狀態樣式類別
            statusSpan.classList.remove('status-approved', 'status-rejected', 'status-pending', 'status-deleted');

            // 依照動作決定新的狀態與按鈕
            let newStatusText = '';
            let newStatusClass = '';
            let actionsHtml = '';
            const id = prosub_ID;

            if (action === 'approve') {
                newStatusText = '通過';
                newStatusClass = 'status-approved';
                actionsHtml = `
                    <button type="button" class="btn btn-warning" onclick="reviewSubmission(${id}, 'cancel_approve')">
                        <i class="fa-solid fa-undo"></i> 取消通過
                    </button>
                    <div class="d-flex gap-2" style="flex-wrap: wrap;">
                        <button type="button" class="btn btn-primary" onclick="viewSubmissionDetail(${id})">
                            <i class="fa-solid fa-eye"></i> 查看詳細資料
                        </button>
                    </div>
                `;
            } else if (action === 'reject') {
                newStatusText = '退件';
                newStatusClass = 'status-rejected';
                actionsHtml = `
                    <button type="button" class="btn btn-warning" onclick="reviewSubmission(${id}, 'cancel_reject')">
                        <i class="fa-solid fa-undo"></i> 取消退件
                    </button>
                    <div class="d-flex gap-2" style="flex-wrap: wrap;">
                        <button type="button" class="btn btn-primary" onclick="viewSubmissionDetail(${id})">
                            <i class="fa-solid fa-eye"></i> 查看詳細資料
                        </button>
                    </div>
                `;
            } else if (action === 'cancel_approve' || action === 'cancel_reject') {
                newStatusText = '未審核';
                newStatusClass = 'status-pending';
                actionsHtml = `
                    <button type="button" class="btn btn-success" onclick="reviewSubmission(${id}, 'approve')">
                        <i class="fa-solid fa-check"></i> 通過
                    </button>
                    <button type="button" class="btn btn-warning" onclick="reviewSubmission(${id}, 'reject')">
                        <i class="fa-solid fa-times"></i> 退件
                    </button>
                    <div class="d-flex gap-2" style="flex-wrap: wrap;">
                        <button type="button" class="btn btn-primary" onclick="viewSubmissionDetail(${id})">
                            <i class="fa-solid fa-eye"></i> 查看詳細資料
                        </button>
                    </div>
                `;
            } else {
                console.warn(`未知的審核動作: ${action}`);
                return;
            }

            // 立即更新狀態文字和樣式（同步操作，確保即時顯示）
            statusSpan.textContent = newStatusText;
            statusSpan.className = `submission-status ${newStatusClass}`;
            
            // 立即更新操作按鈕
            actionsContainer.innerHTML = actionsHtml;
            
            // 確保項目不是刪除狀態
            item.classList.remove('deleted');
            
            // 對於取消通過操作，添加視覺反饋，讓用戶感覺更靈敏
            if (action === 'cancel_approve' || action === 'cancel_reject') {
                // 添加短暫的過渡效果
                item.style.transition = 'all 0.2s ease';
                item.style.opacity = '0.95';
                setTimeout(() => {
                    item.style.opacity = '1';
                }, 100);
            }
            
            // 強制瀏覽器重新渲染（確保視覺更新立即生效）
            void item.offsetHeight; // 觸發重排
            
            console.log(`成功更新提交項目 prosub_ID=${prosub_ID} 的狀態為: ${newStatusText} (動作: ${action})`);
        }
        
        /**
         * 刪除提交
         */
        async function deleteSubmission(prosub_ID) {
            const confirmed = await showConfirmDialog('確定要刪除此提交嗎？刪除後可以恢復。', '確認刪除');
            if (!confirmed) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('prosub_ID', prosub_ID);
                
                const response = await fetch(`${API_BASE}?do=delete`, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    await showAlertDialog('已刪除', 'success');
                    location.reload();
                } else {
                    await showAlertDialog('刪除失敗：' + (data.message || '未知錯誤'), 'error');
                }
            } catch (error) {
                console.error('刪除失敗:', error);
                await showAlertDialog('刪除失敗，請稍後再試', 'error');
            }
        }
        
        /**
         * 恢復提交
         */
        async function restoreSubmission(prosub_ID) {
            const confirmed = await showConfirmDialog('確定要恢復此提交嗎？', '確認恢復');
            if (!confirmed) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('prosub_ID', prosub_ID);
                
                const response = await fetch(`${API_BASE}?do=restore`, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    await showAlertDialog('已恢復', 'success');
                    location.reload();
                } else {
                    await showAlertDialog('恢復失敗：' + (data.message || '未知錯誤'), 'error');
                }
            } catch (error) {
                console.error('恢復失敗:', error);
                await showAlertDialog('恢復失敗，請稍後再試', 'error');
            }
        }
        
        /**
         * 查看提交詳情（顯示模態框）
         */
        function viewSubmissionDetail(prosub_ID) {
            if (!prosub_ID) return;
            
            // 從當前頁面的提交記錄中獲取資料
            const submissionItem = document.querySelector(`[data-prosub-id="${prosub_ID}"]`);
            if (!submissionItem) {
                showAlertDialog('找不到該提交記錄', 'error');
                return;
            }
            
            // 獲取資料
            const groupName = submissionItem.querySelector('.submission-group-name')?.textContent || '';
            const intro = submissionItem.querySelector('.submission-intro')?.textContent || '';
            const submitTime = submissionItem.querySelector('.submission-time')?.textContent || '';
            const submitter = submissionItem.querySelector('.submission-submitter')?.textContent || '';
            const previewElement = submissionItem.querySelector('.submission-preview');
            const imagePath = previewElement?.querySelector('img')?.src || previewElement?.querySelector('object')?.data || '';
            const isPDF = previewElement?.querySelector('object') !== null;
            
            // 獲取多個檔案列表
            let otherFiles = [];
            try {
                const otherFilesData = submissionItem.getAttribute('data-other-files');
                if (otherFilesData) {
                    otherFiles = JSON.parse(otherFilesData);
                }
            } catch (e) {
                console.error('解析檔案列表失敗:', e);
            }
            
            // 創建模態框
            const modal = document.createElement('div');
            modal.className = 'submission-detail-modal';
            modal.id = 'submissionDetailModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'submissionDetailModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1050; display: flex; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box; overflow: auto; opacity: 0;';
            
            // 創建背景遮罩
            const backdrop = document.createElement('div');
            backdrop.className = 'submission-detail-modal-backdrop';
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1040; opacity: 0;';
            
            modal.innerHTML = `
                <div class="submission-detail-modal-dialog" style="max-width: min(900px, calc(100vw - 40px)); width: 100%; margin: auto; flex-shrink: 0;">
                    <div class="submission-detail-modal-content" style="border-radius: 12px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.2); background: white; overflow: hidden;">
                        <div class="submission-detail-modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 12px 12px 0 0; border: none; padding: 20px; display: flex; justify-content: space-between; align-items: center;">
                            <h5 class="submission-detail-modal-title" id="submissionDetailModalLabel" style="font-weight: 600; font-size: 20px; margin: 0;">
                                <i class="fa-solid fa-file-alt"></i> 提交詳情
                            </h5>
                            <button type="button" class="submission-detail-modal-close" aria-label="Close" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">&times;</button>
                        </div>
                        <div class="submission-detail-modal-body" style="padding: 30px; max-height: 70vh; overflow-y: auto; overflow-x: auto;">
                            <div class="submission-detail-modal-row" style="display: flex; flex-wrap: wrap; gap: 20px; min-width: 0;">
                                <div class="submission-detail-modal-col" style="flex: 1 1 300px; min-width: 0; margin-bottom: 20px;">
                                    <div class="detail-item">
                                        <label class="detail-label">組別：</label>
                                        <div class="detail-value">${escapeHtml(groupName)}</div>
                                    </div>
                                    <div class="detail-item">
                                        <label class="detail-label">上傳時間：</label>
                                        <div class="detail-value">${escapeHtml(submitTime)}</div>
                                    </div>
                                    <div class="detail-item">
                                        <label class="detail-label">上傳人：</label>
                                        <div class="detail-value">${escapeHtml(submitter)}</div>
                                    </div>
                                    ${intro ? `
                                    <div class="detail-item">
                                        <label class="detail-label">簡介：</label>
                                        <div class="detail-value" style="line-height: 1.6; color: #495057; white-space: pre-wrap; word-wrap: break-word;">${escapeHtml(intro)}</div>
                                    </div>
                                    ` : ''}
                                    ${otherFiles && otherFiles.length > 0 ? `
                                    <div class="detail-item">
                                        <label class="detail-label">其他檔案 (${otherFiles.length})：</label>
                                        <div class="detail-value">
                                            <div class="list-group" style="margin-top: 8px;">
                                                ${otherFiles.map((file) => {
                                                    const filePath = file.path || '';
                                                    const fileName = file.name || file.original_name || (filePath ? filePath.split('/').pop() : '');
                                                    const fileUrl = filePath ? '../' + filePath : '';
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
                                                    
                                                    // 判斷是否可以預覽（圖片和PDF）
                                                    const isImageFile = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(fileExtension);
                                                    const isPDFFile = fileExtension === 'pdf';
                                                    const canPreview = isImageFile || isPDFFile;
                                                    
                                                    return `
                                                        <div class="list-group-item" style="display: flex; align-items: center; justify-content: space-between; padding: 12px;">
                                                            <div class="d-flex align-items-center flex-grow-1">
                                                                <i class="fa-solid ${fileIcon} me-2" style="color: #667eea; font-size: 16px;"></i>
                                                                <span style="word-break: break-word; color: #495057;">${escapeHtml(fileName)}</span>
                                                            </div>
                                                            <div class="d-flex gap-2" style="flex-shrink: 0;">
                                                                ${canPreview ? `
                                                                <button type="button" class="btn btn-sm btn-info" onclick="viewImageModal('${escapeHtml(fileUrl)}', ${isPDFFile})" style="flex-shrink: 0;">
                                                                    <i class="fa-solid fa-eye"></i> 查看
                                                                </button>
                                                                ` : ''}
                                                                <a href="${escapeHtml(fileUrl)}" target="_blank" class="btn btn-sm btn-outline-primary" download style="flex-shrink: 0;">
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
                                <div class="submission-detail-modal-col submission-detail-preview-col" style="flex: 1 1 300px; min-width: 0; max-width: 100%;">
                                    <label class="detail-label" style="margin-bottom: 15px; display: block;">圖檔預覽：</label>
                                    <div class="detail-preview" style="border: 2px solid #e9ecef; border-radius: 8px; padding: 15px; background: #f8f9fa; min-height: 300px; display: flex; align-items: center; justify-content: center; max-width: 100%; overflow: hidden;">
                                        ${imagePath ? 
                                            (isPDF ? 
                                                `<object data="${escapeHtml(imagePath)}" type="application/pdf" style="width: 100%; max-width: 100%; height: 400px; border-radius: 4px;"></object>` :
                                                `<img src="${escapeHtml(imagePath)}" alt="預覽" style="max-width: 100%; max-height: 400px; object-fit: contain; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">`
                                            ) :
                                            '<div style="color: #6c757d; text-align: center;"><i class="fa-solid fa-file" style="font-size: 48px;"></i><p style="margin-top: 10px;">無圖檔</p></div>'
                                        }
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="submission-detail-modal-footer" style="border-top: 1px solid #e9ecef; padding: 20px; border-radius: 0 0 12px 12px; text-align: right;">
                            <button type="button" class="submission-detail-modal-btn-close" style="border-radius: 8px; padding: 10px 24px; background: #6c757d; color: white; border: none; cursor: pointer; font-size: 14px;">關閉</button>
                        </div>
                    </div>
                </div>
            `;
            
            // 添加樣式
            const style = document.createElement('style');
            style.textContent = `
                .detail-item {
                    margin-bottom: 20px;
                    padding-bottom: 15px;
                    border-bottom: 1px solid #f0f0f0;
                }
                .detail-item:last-child {
                    border-bottom: none;
                    margin-bottom: 0;
                    padding-bottom: 0;
                }
                .detail-label {
                    font-weight: 600;
                    color: #495057;
                    font-size: 14px;
                    margin-bottom: 8px;
                    display: block;
                }
                .detail-value {
                    color: #6c757d;
                    font-size: 15px;
                    line-height: 1.6;
                }
                .detail-value .list-group {
                    border-radius: 8px;
                    overflow: hidden;
                }
                .detail-value .list-group-item {
                    border-left: none;
                    border-right: none;
                    transition: background-color 0.2s;
                }
                .detail-value .list-group-item:first-child {
                    border-top: none;
                }
                .detail-value .list-group-item:last-child {
                    border-bottom: none;
                }
                .detail-value .list-group-item:hover {
                    background-color: #f8f9fa;
                }
                .submission-detail-modal-close:hover {
                    opacity: 0.7;
                }
                .submission-detail-modal-btn-close:hover {
                    background: #5a6268 !important;
                }
            `;
            document.head.appendChild(style);
            
            // 添加到頁面（先隱藏，等排版完成後再顯示，避免第一次點擊跑版）
            document.body.appendChild(backdrop);
            document.body.appendChild(modal);
            document.body.style.overflow = 'hidden';
            document.body.classList.add('modal-open');
            
            // 強制完成排版後再顯示（解決第一次點擊跑版、第二次才正常的問題）
            requestAnimationFrame(() => {
                void modal.offsetHeight; // 強制 reflow
                requestAnimationFrame(() => {
                    backdrop.style.opacity = '1';
                    modal.style.opacity = '1';
                });
            });
            
            // 關閉函數
            const closeModal = function() {
                backdrop.style.opacity = '0';
                modal.style.opacity = '0';
                
                setTimeout(() => {
                    if (document.body.contains(modal)) {
                        document.body.removeChild(modal);
                    }
                    if (document.body.contains(backdrop)) {
                        document.body.removeChild(backdrop);
                    }
                    if (document.head.contains(style)) {
                        document.head.removeChild(style);
                    }
                    document.body.style.overflow = '';
                    document.body.classList.remove('modal-open');
                }, 150);
            };
            
            // 綁定關閉事件
            const closeBtn = modal.querySelector('.submission-detail-modal-close');
            const closeFooterBtn = modal.querySelector('.submission-detail-modal-btn-close');
            if (closeBtn) closeBtn.onclick = closeModal;
            if (closeFooterBtn) closeFooterBtn.onclick = closeModal;
            backdrop.onclick = closeModal;
            modal.onclick = function(e) {
                if (e.target === modal) closeModal();
            };
            
            // ESC 鍵關閉
            const escHandler = function(e) {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        }
        
        /**
         * 查看圖檔模態框
         */
        function viewImageModal(imagePath, isPDF) {
            // 創建模態框
            const modal = document.createElement('div');
            modal.className = 'submission-image-modal';
            modal.id = 'imageModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'imageModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1050; display: flex; align-items: center; justify-content: center;';
            
            // 創建背景遮罩
            const backdrop = document.createElement('div');
            backdrop.className = 'submission-image-modal-backdrop';
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1040;';
            
            modal.innerHTML = `
                <div class="submission-image-modal-dialog" style="max-width: 90vw; width: 90%; position: relative;">
                    <div class="submission-image-modal-content" style="background: transparent; border: none;">
                        <div class="submission-image-modal-body" style="padding: 20px; text-align: center;">
                            ${isPDF ? 
                                `<object data="${escapeHtml(imagePath)}" type="application/pdf" style="width: 100%; height: 80vh; border: none;"></object>` :
                                `<img src="${escapeHtml(imagePath)}" alt="預覽" style="max-width: 100%; max-height: 80vh; object-fit: contain; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">`
                            }
                        </div>
                        <div class="submission-image-modal-footer" style="border: none; padding: 15px; text-align: center; position: absolute; bottom: 0; left: 0; right: 0; z-index: 1051;">
                            <button type="button" class="submission-image-modal-close-btn" style="background: rgba(0,0,0,0.7); border: 2px solid rgba(255,255,255,0.8); color: white; padding: 10px 30px; border-radius: 25px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; box-shadow: 0 2px 8px rgba(0,0,0,0.3);">
                                <i class="fa-solid fa-times me-2"></i>關閉
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // 添加到頁面
            document.body.appendChild(backdrop);
            document.body.appendChild(modal);
            document.body.style.overflow = 'hidden';
            
            // 顯示動畫
            requestAnimationFrame(() => {
                backdrop.style.opacity = '1';
                modal.style.opacity = '1';
            });
            
            // 關閉函數
            const closeModal = function() {
                backdrop.style.opacity = '0';
                modal.style.opacity = '0';
                
                setTimeout(() => {
                    if (document.body.contains(modal)) {
                        document.body.removeChild(modal);
                    }
                    if (document.body.contains(backdrop)) {
                        document.body.removeChild(backdrop);
                    }
                    document.body.style.overflow = '';
                }, 150);
            };
            
            // 綁定關閉事件
            const closeFooterBtn = modal.querySelector('.submission-image-modal-close-btn');
            if (closeFooterBtn) {
                closeFooterBtn.onclick = closeModal;
                // 添加懸停效果
                closeFooterBtn.onmouseenter = function() {
                    this.style.background = 'rgba(220,53,69,0.9)';
                    this.style.borderColor = 'rgba(255,255,255,1)';
                    this.style.transform = 'translateY(-2px)';
                };
                closeFooterBtn.onmouseleave = function() {
                    this.style.background = 'rgba(0,0,0,0.7)';
                    this.style.borderColor = 'rgba(255,255,255,0.8)';
                    this.style.transform = 'translateY(0)';
                };
            }
            backdrop.onclick = closeModal;
            modal.onclick = function(e) {
                // 點擊圖片或PDF內容區域時不關閉（只有點擊背景才關閉）
                if (e.target === modal || e.target === backdrop) {
                    closeModal();
                }
            };
            
            // ESC 鍵關閉
            const escHandler = function(e) {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        }
        
        /**
         * HTML 轉義函數
         */
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        /**
         * 下載申請表（海報）
         */
        async function downloadImage(imagePath, fileName) {
            try {
                // 獲取團隊資訊（從頁面中獲取）
                const teamProjectName = document.querySelector('.team-detail-title')?.textContent?.trim() || '專題名稱';
                const teamInfo = document.querySelector('.team-info')?.textContent?.trim() || '';
                
                // 從API獲取團隊成員資訊
                let teamMembers = [];
                try {
                    const response = await fetch(`${API_BASE}?do=get_team_members&team_ID=${team_ID}`);
                    const data = await response.json();
                    if (data.success && data.members) {
                        teamMembers = data.members;
                    }
                } catch (e) {
                    console.warn('無法獲取團隊成員資訊:', e);
                }
                
                // 生成申請表HTML
                const formHTML = generateApplicationFormHTML(teamProjectName, teamMembers);
                
                // 使用html2pdf生成PDF
                if (typeof html2pdf === 'undefined') {
                    // 動態載入html2pdf庫
                    const script = document.createElement('script');
                    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
                    script.onload = () => {
                        exportApplicationFormPDF(formHTML, teamProjectName);
                    };
                    script.onerror = () => {
                        showAlertDialog('無法載入PDF生成庫，請稍後再試', 'error');
                    };
                    document.head.appendChild(script);
                } else {
                    exportApplicationFormPDF(formHTML, teamProjectName);
                }
            } catch (error) {
                console.error('下載失敗:', error);
                await showAlertDialog('下載失敗，請稍後再試', 'error');
            }
        }
        
        /**
         * 生成申請表HTML
         */
        function generateApplicationFormHTML(projectName, members) {
            // 確保最多5個成員
            const displayMembers = members.slice(0, 5);
            while (displayMembers.length < 5) {
                displayMembers.push({ class_name: '', student_id: '', u_name: '' });
            }
            
            return `
                <div style="font-family: 'Microsoft JhengHei', '微軟正黑體', Arial, sans-serif; padding: 20px; max-width: 800px; margin: 0 auto;">
                    <div style="text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 30px; padding-bottom: 10px; border-bottom: 2px solid #000;">
                        康寧學校財團法人康寧大學資管科專題指導申請單
                    </div>
                    
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                        <tr>
                            <td style="border: 1px solid #000; padding: 10px; font-weight: bold; text-align: center; width: 120px; background-color: #f9f9f9;">專題名稱</td>
                            <td style="border: 1px solid #000; padding: 10px; min-height: 40px;">${escapeHtml(projectName)}</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #000; padding: 10px; font-weight: bold; text-align: center; background-color: #f9f9f9; vertical-align: top;" rowspan="6">組員</td>
                            <td style="border: 1px solid #000; padding: 0;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr>
                                            <th style="border: 1px solid #000; padding: 8px; background-color: #f0f0f0; font-weight: bold; text-align: center; width: 33.33%;">班級</th>
                                            <th style="border: 1px solid #000; padding: 8px; background-color: #f0f0f0; font-weight: bold; text-align: center; width: 33.33%;">學號</th>
                                            <th style="border: 1px solid #000; padding: 8px; background-color: #f0f0f0; font-weight: bold; text-align: center; width: 33.33%;">姓名</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${displayMembers.map(m => `
                                            <tr>
                                                <td style="border: 1px solid #000; padding: 8px; text-align: center;">${escapeHtml(m.class_name || '')}</td>
                                                <td style="border: 1px solid #000; padding: 8px; text-align: center;">${escapeHtml(m.student_id || '')}</td>
                                                <td style="border: 1px solid #000; padding: 8px; text-align: center;">${escapeHtml(m.u_name || '')}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </table>
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                        <tr>
                            <td style="border: 1px solid #000; padding: 10px; font-weight: bold; text-align: center; background-color: #f9f9f9; width: 120px;">指導老師簽名</td>
                            <td style="border: 1px solid #000; padding: 10px; min-height: 60px;"></td>
                        </tr>
                    </table>
                    
                    <div style="margin-top: 20px; font-size: 12px; color: #333; line-height: 1.6;">
                        註:專題組隊請於 年 月 日前經專題指導老師簽名送至科助處理才算完成,逾期將不再受理專題組隊。
                    </div>
                </div>
            `;
        }
        
        /**
         * 匯出申請表為PDF
         */
        function exportApplicationFormPDF(formHTML, projectName) {
            // 創建臨時容器
            const wrapper = document.createElement('div');
            wrapper.style.position = 'absolute';
            wrapper.style.left = '-9999px';
            wrapper.style.width = '210mm';
            wrapper.innerHTML = formHTML;
            document.body.appendChild(wrapper);
            
            const opt = {
                margin: [10, 10, 10, 10],
                filename: `專題指導申請單_${escapeHtml(projectName)}_${new Date().toISOString().slice(0, 10)}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { 
                    scale: 2,
                    useCORS: true,
                    logging: false,
                    scrollY: 0
                },
                jsPDF: { 
                    unit: 'mm', 
                    format: 'a4', 
                    orientation: 'portrait',
                    compress: true
                }
            };
            
            html2pdf().set(opt).from(wrapper).save().then(() => {
                // 清理臨時元素
                if (document.body.contains(wrapper)) {
                    document.body.removeChild(wrapper);
                }
            }).catch((error) => {
                console.error('PDF生成失敗:', error);
                showAlertDialog('PDF生成失敗，請稍後再試', 'error');
                if (document.body.contains(wrapper)) {
                    document.body.removeChild(wrapper);
                }
            });
        }
        
        // 確保所有函數都在全局作用域
        window.downloadImage = downloadImage;
        window.reviewSubmission = reviewSubmission;
        window.updateSubmissionStatusInDetail = updateSubmissionStatusInDetail;
        window.restoreSubmission = restoreSubmission;
        window.viewSubmissionDetail = viewSubmissionDetail;
        window.viewImageModal = viewImageModal;
        window.showConfirmDialog = showConfirmDialog;
        window.showPromptDialog = showPromptDialog;
        window.showAlertDialog = showAlertDialog;
        
        // 防止重複打開歷史記錄模態框
        let isViewingHistory = false;
        
        /**
         * 顯示歷史修改紀錄（彈出模態框）
         */
        async function showHistoryModal(prosubID) {
            if (!prosubID || isViewingHistory) return;
            isViewingHistory = true;

            // 移除舊的模態框（如果存在）
            const oldModal = document.getElementById('historyModal');
            const oldBackdrop = document.querySelector('.modal-backdrop.history-backdrop');
            if (oldModal) oldModal.remove();
            if (oldBackdrop) oldBackdrop.remove();

            // 創建 backdrop
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade history-backdrop';
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1040;';
            
            // 創建模態框
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'historyModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'historyModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1050; display: flex; align-items: center; justify-content: center;';
            
            // 設置載入狀態的內容
            modal.innerHTML = `
                <div class="modal-dialog modal-lg" style="max-width: 1500px;">
                    <div class="modal-content">
                        <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                            <h5 class="modal-title" id="historyModalLabel">
                                <i class="fa-solid fa-clock-rotate-left"></i> 歷史修改紀錄
                            </h5>
                            <button type="button" class="btn-close btn-close-white" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" style="min-height: 300px; max-height: 70vh; overflow-y: auto; padding: 20px;">
                            <div class="text-center p-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">載入中...</span>
                                </div>
                                <p class="mt-3 mb-0">載入中...</p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" style="border-radius: 50px;">關閉</button>
                        </div>
                    </div>
                </div>
            `;
            
            // 添加到 DOM
            document.body.appendChild(backdrop);
            document.body.appendChild(modal);
            document.body.style.overflow = 'hidden';
            
            // 立即顯示
            requestAnimationFrame(() => {
                backdrop.style.opacity = '1';
                modal.style.opacity = '1';
            });

            // 預先定義關閉函數
            const closeModal = function() {
                backdrop.style.opacity = '0';
                modal.style.opacity = '0';
                
                setTimeout(() => {
                    if (document.body.contains(modal)) {
                        document.body.removeChild(modal);
                    }
                    if (document.body.contains(backdrop)) {
                        document.body.removeChild(backdrop);
                    }
                    document.body.style.overflow = '';
                    isViewingHistory = false;
                }, 150);
            };
            
            // 綁定關閉事件
            const closeBtn = modal.querySelector('.btn-close');
            const closeFooterBtn = modal.querySelector('.modal-footer .btn-secondary');
            if (closeBtn) closeBtn.onclick = closeModal;
            if (closeFooterBtn) closeFooterBtn.onclick = closeModal;
            backdrop.onclick = closeModal;
            modal.onclick = function(e) {
                if (e.target === modal) closeModal();
            };
            
            // ESC 鍵關閉
            const escHandler = function(e) {
                if (e.key === 'Escape') closeModal();
            };
            document.addEventListener('keydown', escHandler, { once: true });

            try {
                const response = await fetch(`${API_BASE}?do=get_history&prosub_ID=${prosubID}`);
                const data = await response.json();
                
                const modalBody = modal.querySelector('.modal-body');
                if (!modalBody) {
                    isViewingHistory = false;
                    return;
                }
                
                if (data.success) {
                    const history = data.history || [];
                    
                    // 預先定義映射
                    const actionMap = {
                        'submitted': '送出',
                        'replaced': '取代',
                        'deleted': '刪除',
                        'reset_to_draft': '重置回暫存',
                        'restored_from_history': '從歷史恢復'
                    };
                    
                    // 使用 DocumentFragment 批量創建元素
                    const fragment = document.createDocumentFragment();
                    
                    if (history.length === 0) {
                        const p = document.createElement('p');
                        p.className = 'text-muted text-center p-4 mb-0';
                        p.textContent = '尚無歷史記錄';
                        fragment.appendChild(p);
                    } else {
                        const historyGrid = document.createElement('div');
                        historyGrid.className = 'history-cards-grid';
                        historyGrid.style.cssText = 'display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px;';
                        
                        // 按時間倒序排列（最新的在前）
                        const sortedHistory = [...history].reverse();
                        
                        sortedHistory.forEach((item, index) => {
                            const card = document.createElement('div');
                            card.className = 'history-card';
                            card.style.cssText = 'background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: all 0.3s;';
                            
                            // 操作類型標籤
                            const actionBadge = document.createElement('div');
                            actionBadge.className = 'history-action-badge';
                            const actionText = actionMap[item.action] || item.action;
                            const badgeColors = {
                                'submitted': { bg: '#d4edda', color: '#155724' },
                                'replaced': { bg: '#cfe2ff', color: '#084298' },
                                'deleted': { bg: '#f8d7da', color: '#721c24' },
                                'reset_to_draft': { bg: '#fff3cd', color: '#856404' },
                                'restored_from_history': { bg: '#e7d4ff', color: '#6f42c1' }
                            };
                            const badgeColor = badgeColors[item.action] || { bg: '#e2e3e5', color: '#383d41' };
                            actionBadge.style.cssText = `display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; background: ${badgeColor.bg}; color: ${badgeColor.color}; margin-bottom: 12px;`;
                            actionBadge.textContent = actionText;
                            card.appendChild(actionBadge);
                            
                            // 修改時間
                            const time = item.submitted_at || item.replaced_at || item.deleted_at || item.reset_at || item.restored_at || '';
                            if (time) {
                                const timeDiv = document.createElement('div');
                                timeDiv.className = 'history-time';
                                timeDiv.style.cssText = 'margin-bottom: 8px;';
                                const timeLabel = document.createElement('span');
                                timeLabel.style.cssText = 'font-weight: 600; color: #495057; font-size: 13px;';
                                timeLabel.textContent = '修改時間：';
                                const timeValue = document.createElement('span');
                                timeValue.style.cssText = 'color: #6c757d; font-size: 13px;';
                                timeValue.textContent = new Date(time).toLocaleString('zh-TW');
                                timeDiv.appendChild(timeLabel);
                                timeDiv.appendChild(timeValue);
                                card.appendChild(timeDiv);
                            }
                            
                            // 修改人
                            const user = item.submitted_by || item.replaced_by || item.deleted_by || item.reset_by || item.restored_by || '';
                            if (user) {
                                const userDiv = document.createElement('div');
                                userDiv.className = 'history-user';
                                userDiv.style.cssText = 'margin-bottom: 12px;';
                                const userLabel = document.createElement('span');
                                userLabel.style.cssText = 'font-weight: 600; color: #495057; font-size: 13px;';
                                userLabel.textContent = '修改人：';
                                const userValue = document.createElement('span');
                                userValue.style.cssText = 'color: #6c757d; font-size: 13px;';
                                userValue.textContent = user;
                                userDiv.appendChild(userLabel);
                                userDiv.appendChild(userValue);
                                card.appendChild(userDiv);
                            }
                            
                            // 恢復到此版本按鈕已移除
                            
                            historyGrid.appendChild(card);
                        });
                        
                        fragment.appendChild(historyGrid);
                    }
                    
                    // 一次性更新 DOM
                    modalBody.innerHTML = '';
                    modalBody.appendChild(fragment);
                } else {
                    modalBody.innerHTML = `<p class="text-danger text-center p-4 mb-0">${escapeHtml(data.message || '獲取歷史記錄失敗')}</p>`;
                }
            } catch (error) {
                console.error('獲取歷史記錄錯誤:', error);
                const modalBody = modal.querySelector('.modal-body');
                if (modalBody) {
                    modalBody.innerHTML = '<p class="text-danger text-center p-4 mb-0">獲取歷史記錄失敗，請稍後再試</p>';
                }
            }
        }
        
        /**
         * 轉義 HTML（用於防止 XSS）
         */
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</div>

