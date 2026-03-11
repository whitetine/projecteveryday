<?php
session_start();
require '../includes/pdo.php';

// 檢查權限（只有班導 role_ID = 3 可以訪問）
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

if (!$u_ID) {
    echo '<div class="alert alert-danger">請先登入</div>';
    exit;
}

if ($role_ID != 3) {
    echo '<div class="alert alert-danger">此頁面僅限班導使用</div>';
    exit;
}

// 檢查欄位名稱（包含不同版本的資料表結構）
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

// 獲取班導管理的班級ID列表
$classIds = [];
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT class_ID
        FROM enrollmentdata
        WHERE enroll_u_ID = ? AND enroll_status = 1
    ");
    $stmt->execute([$u_ID]);
    $classIds = array_values(array_filter(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'class_ID')));
} catch (Exception $e) {
    $classIds = [];
}

// 獲取屬於這些班級的團隊ID列表
$teamIds = [];
if (!empty($classIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $stmt = $conn->prepare("
            SELECT DISTINCT t.team_ID
            FROM teamdata t
            JOIN teammember tm ON tm.team_ID = t.team_ID
            JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField}
            WHERE t.team_status = 1
              AND e.class_ID IN ($placeholders)
              AND e.enroll_status = 1
        ");
        $stmt->execute($classIds);
        $teamIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'team_ID');
    } catch (Exception $e) {
        $teamIds = [];
    }
}

// 獲取這些團隊的詳細資訊
$teams = [];
if (!empty($teamIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $stmt = $conn->prepare("
            SELECT DISTINCT 
                t.team_ID,
                t.team_project_name,
                t.cohort_ID,
                c.cohort_name,
                g.group_name
            FROM teamdata t
            LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
            LEFT JOIN groupdata g ON t.group_ID = g.group_ID
            WHERE t.team_ID IN ($placeholders)
              AND t.team_status = 1
            ORDER BY t.cohort_ID DESC, t.team_ID
        ");
        $stmt->execute($teamIds);
        $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $teams = [];
    }
}

// 獲取所有團隊的提交記錄
$allSubmissions = [];
foreach ($teams as $team) {
    $team_ID = $team['team_ID'];
    try {
        $stmt = $conn->prepare("
            SELECT 
                ps.prosub_ID,
                ps.prosub_img,
                ps.prosub_status,
                ps.prosub_created_d,
                ps.prosub_update_d,
                ps.prosub_reason,
                ps.prosub_re_reason,
                ps.content_json,
                ps.prosub_u_ID,
                ps.team_ID,
                u.u_name as submitter_name
            FROM prosubdata ps
            LEFT JOIN userdata u ON ps.prosub_u_ID = u.u_ID
            WHERE ps.team_ID = ? AND ps.prosub_status != 4
            ORDER BY ps.prosub_created_d DESC
        ");
        $stmt->execute([$team_ID]);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 處理每筆記錄
        foreach ($submissions as $sub) {
            $contentJson = json_decode($sub['content_json'] ?? '{}', true);
            $sub['is_deleted'] = isset($contentJson['is_deleted']) && $contentJson['is_deleted'] === true;
            $sub['intro'] = $contentJson['intro'] ?? '';
            $sub['team_name'] = $team['team_project_name'];
            $sub['cohort_name'] = $team['cohort_name'];
            $sub['group_name'] = $team['group_name'];
            $allSubmissions[] = $sub;
        }
    } catch (Exception $e) {
        // 跳過錯誤的團隊
    }
}

// 按提交時間排序（最新的在前）
usort($allSubmissions, function($a, $b) {
    $timeA = strtotime($a['prosub_created_d'] ?? '1970-01-01');
    $timeB = strtotime($b['prosub_created_d'] ?? '1970-01-01');
    return $timeB - $timeA;
});
?>
<!-- CSS 預載入，防止跑版 -->
<link rel="stylesheet" href="../css/project_submission_review.css?v=<?= time() ?>" id="classTeacherSubmissionsCSS" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="../css/project_submission_review.css?v=<?= time() ?>"></noscript>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
        /* 只針對此頁面容器，不影響全局 */
        .teacher-submissions-container {
            padding: 0 !important; /* 移除內邊距，讓標題橫幅貼齊邊緣 */
            max-width: 100% !important;
            width: 100%;
            margin: 0 !important;
            min-height: auto;
            box-sizing: border-box;
            overflow-x: hidden;
            position: relative;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            /* 移除 min-height: 100vh，避免超出父容器 */
        }
        
        .page-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 0 30px 30px 30px !important; /* 左右留邊距，底部留間距 */
            padding: 28px 35px 28px 50px; /* 增加左側內邊距，使圖示不貼齊邊框 */
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; /* 固定漸變顏色，防止改變 */
            border-radius: 16px !important; /* 長方形圓角 */
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.25);
            position: relative;
            overflow: hidden;
            width: calc(100% - 60px) !important; /* 確保寬度正確 */
            max-width: 100%;
            box-sizing: border-box;
            word-wrap: break-word;
            height: 80px !important; /* 固定高度 */
            min-height: 80px !important;
            max-height: 80px !important;
            border-bottom: 3px solid #ffc107; /* 底部黃色線條 */
            left: 0;
            right: 0;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 3s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
        
        .page-title {
            font-size: 28px !important; /* 固定字體大小 */
            padding-left: 20px; /* 增加左側內邊距，使圖示不貼齊邊框 */
            font-weight: 700 !important;
            color: white !important;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            z-index: 1;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            margin: 0;
            word-wrap: break-word;
            white-space: nowrap; /* 防止文字換行 */
            flex-shrink: 0; /* 防止標題被壓縮 */
            line-height: 1.2; /* 固定行高 */
            transform: none !important; /* 防止縮放時被變形 */
            zoom: 1 !important; /* 防止瀏覽器縮放影響 */
        }

        .page-title i {
            color: #ffc107 !important; 
            font-size: 32px !important; 
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
            margin-right: 15px; /* 增加右邊距，使圖示與文字之間有適當間距 */
            flex-shrink: 0; /* 防止圖被壓縮 */
            width: 32px !important; 
            height: 32px !important; 
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .page-description {
            font-size: 14px;
            opacity: 0.9;
            word-wrap: break-word;
            position: relative;
            z-index: 1;
            margin-top: 8px;
        }
        
        .submissions-section {
            background: white;
            border-radius: 16px !important; 
            padding: 24px;
            margin: 0 30px 30px 30px !important; 
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            width: calc(100% - 60px); 
            max-width: 100%;
            box-sizing: border-box;
            overflow-x: hidden;
            overflow-y: visible;
        }
        
        .submissions-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .submissions-title {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
            word-wrap: break-word;
        }
        
        .submission-count {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            color: white;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            margin-left: 12px;
            box-shadow: 0 2px 8px rgba(255, 193, 7, 0.3);
        }
        
        /* 網格視圖（卡片模式） */
        .submission-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            padding: 16px 0;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            overflow: hidden;
        }
        
        .submission-item {
            background: white;
            border: 1px solid rgba(0, 0, 0, 0.05);
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            position: relative;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            min-width: 0; /* 防止 grid 項目溢出 */
        }
        
        .submission-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            opacity: 0;
            transition: opacity 0.3s;
            z-index: 1;
        }
        
        .submission-item::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(102, 126, 234, 0.05) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .submission-item:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 32px rgba(102, 126, 234, 0.15);
            border-color: rgba(102, 126, 234, 0.2);
        }
        
        .submission-item:hover::before {
            opacity: 1;
        }
        
        .submission-item:hover::after {
            opacity: 1;
        }
        
        .submission-item.deleted {
            opacity: 0.5;
            filter: grayscale(50%);
        }
        
        .submission-status {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            z-index: 10;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            white-space: nowrap;
            letter-spacing: 0.3px;
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
        }
        
        .status-deleted {
            background: #9ca3af;
            color: white;
        }
        
        /* 海報預覽區域 */
        .submission-poster-wrapper {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 220px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: default;
        }
        
        .submission-poster-wrapper::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 50%, rgba(255,255,255,0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(255,255,255,0.1) 0%, transparent 50%);
            z-index: 1;
        }
        
        .submission-preview {
            width: 100%;
            height: 100%;
            object-fit: cover;
            background: #f0f0f0;
            transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            z-index: 0;
            cursor: pointer;
        }
        
        .submission-item:hover .submission-preview {
            transform: scale(1.05);
        }
        
        .submission-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        
        .submission-preview object {
            width: 100%;
            height: 100%;
            display: block;
        }
        
        .pdf-poster,
        .no-poster {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            z-index: 0;
        }
        
        .pdf-poster i,
        .no-poster i {
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.2));
            animation: float 3s ease-in-out infinite;
            color: white;
            font-size: 48px;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        /* 卡片內容區域 */
        .submission-content {
            padding: 20px;
            position: relative;
            z-index: 1;
            background: white;
        }
        
        .submission-team-name {
            font-size: 18px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 12px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            line-height: 1.5;
            position: relative;
            padding-left: 14px;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        .submission-team-name::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 18px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 2px;
        }
        
        .submission-team-meta {
            font-size: 13px;
            color: #718096;
            margin-bottom: 14px;
            line-height: 1.5;
            word-wrap: break-word;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .submission-team-meta::before {
            content: '•';
            color: #cbd5e0;
            font-size: 16px;
        }
        
        .submission-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 16px;
        }
        
        .submission-info-item {
            font-size: 13px;
            color: #4a5568;
            line-height: 1.6;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        .submission-info-label {
            color: #667eea;
            font-weight: 600;
            min-width: 65px;
            flex-shrink: 0;
            font-size: 13px;
        }
        
        .submission-info-value {
            flex: 1;
            word-break: break-word;
            color: #4a5568;
            line-height: 1.5;
        }
        
        .submission-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-top: 0;
            padding-top: 16px;
            border-top: 1px solid #f0f0f0;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .submission-actions .btn {
            font-size: 13px;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.2s ease;
            white-space: nowrap;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .submission-actions .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 2px 6px rgba(102, 126, 234, 0.25);
        }
        
        .submission-actions .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.35);
        }
        
        .submission-actions .btn-primary:active {
            transform: translateY(0);
        }
        
        .submission-actions .btn-info {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            box-shadow: 0 2px 6px rgba(23, 162, 184, 0.25);
        }
        
        .submission-actions .btn-info:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(23, 162, 184, 0.35);
        }
        
        .submission-actions .btn-info:active {
            transform: translateY(0);
        }
        
        @media (max-width: 1200px) {
            .submission-list {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 20px;
            }
        }
        
        @media (max-width: 768px) {
            .teacher-submissions-container {
                padding: 0;
                overflow-x: hidden;
            }
            
            .page-header {
                padding: 20px;
                margin-bottom: 20px;
                border-radius: 12px;
            }
            
            .page-title {
                font-size: 24px;
            }
            
            .submissions-section {
                padding: 20px;
                border-radius: 12px;
            }
            
            .submission-list {
                grid-template-columns: 1fr;
                gap: 20px;
                padding: 10px 0;
            }
        }
        
        @media (max-width: 576px) {
            .page-header {
                padding: 15px;
                margin-bottom: 15px;
            }
            
            .page-title {
                font-size: 20px;
            }
            
            .page-title i {
                font-size: 24px;
            }
            
            .submissions-section {
                padding: 15px;
            }
            
            .submission-count {
                font-size: 12px;
                padding: 4px 12px;
                margin-left: 8px;
            }
        }
        
        /* 確保所有內容都不會超出容器並防止縮放影響 */
        .teacher-submissions-container * {
            max-width: 100%;
            -webkit-transform: none !important;
            transform: none !important;
            zoom: 1 !important;
        }
        
        /* 防止標題橫幅在縮放時改變樣式 */
        .page-header {
            -webkit-transform: scale(1) !important;
            transform: scale(1) !important;
            -webkit-backface-visibility: hidden;
            backface-visibility: hidden;
        }
        
        /* 允許特定動畫元素 */
        .submission-card:hover {
            transform: translateY(-3px) !important;
        }
        
        /* 防止文字溢出 */
        .submission-team-name,
        .submission-info-value,
        .submission-team-meta {
            word-wrap: break-word;
            overflow-wrap: break-word;
            hyphens: auto;
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
        
        .no-teams-message {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            color: #856404;
        }
    </style>

<div class="teacher-submissions-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fa-solid fa-file-alt"></i>
                查看班級歷屆繳交
            </h1>
        </div>
        
        <div class="submissions-section">
            <?php if (empty($classIds)): ?>
            <div class="no-teams-message">
                <i class="fa-solid fa-info-circle"></i>
                <strong>目前沒有管理的班級</strong>
                <p style="margin: 10px 0 0 0;">您目前尚未被指派為任何班級的班導，或班級資料尚未建立。</p>
            </div>
            <?php endif; ?>
            
            <div class="submissions-header">
                <div class="submissions-title">
                    <i class="fa-solid fa-list"></i> 專題繳交記錄
                    <span class="submission-count">共<?= count($allSubmissions) ?>筆</span>
                </div>
            </div>
            
            <?php if (empty($allSubmissions)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-inbox"></i>
                <p>目前沒有繳交記錄</p>
                <?php if (!empty($teams)): ?>
                <p style="margin-top: 10px; font-size: 14px; color: #999;">您管理的班級團隊尚未繳交任何專題資料</p>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="submission-list">
                <?php foreach ($allSubmissions as $sub): 
                    $status = (int)$sub['prosub_status'];
                    $isDeleted = (bool)$sub['is_deleted'];
                    
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
                    $isPDF = false;
                    if ($imagePath) {
                        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
                        $isPDF = ($ext === 'pdf');
                    }
                    $fullImagePath = $imagePath;
                    if ($imagePath && !preg_match('/^https?:\/\//', $imagePath) && !preg_match('/^\//', $imagePath)) {
                        $fullImagePath = '../' . ltrim($imagePath, '/');
                    }
                    $submitTime = '無';
                    if (!empty($sub['prosub_created_d'])) {
                        try {
                            $submitTime = date('Y/m/d H:i:s', strtotime($sub['prosub_created_d']));
                        } catch (Exception $e) {
                            $submitTime = $sub['prosub_created_d'];
                        }
                    }
                    $submitterName = htmlspecialchars($sub['submitter_name'] ?? '未知', ENT_QUOTES, 'UTF-8');
                    $teamName = htmlspecialchars($sub['team_name'] ?? '未知團隊', ENT_QUOTES, 'UTF-8');
                    $cohortName = htmlspecialchars($sub['cohort_name'] ?? '', ENT_QUOTES, 'UTF-8');
                    $groupName = htmlspecialchars($sub['group_name'] ?? '', ENT_QUOTES, 'UTF-8');
                    $intro = htmlspecialchars($sub['intro'] ?? '', ENT_QUOTES, 'UTF-8');
                ?>
                <div class="submission-item <?= $isDeleted ? 'deleted' : '' ?>" 
                     data-prosub-id="<?= $sub['prosub_ID'] ?>"
                     data-group-name="<?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?>"
                     data-intro="<?= htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') ?>"
                     data-submit-time="<?= htmlspecialchars($submitTime, ENT_QUOTES, 'UTF-8') ?>"
                     data-submitter="<?= htmlspecialchars($submitterName, ENT_QUOTES, 'UTF-8') ?>"
                     data-image-path="<?= htmlspecialchars($fullImagePath, ENT_QUOTES, 'UTF-8') ?>"
                     data-is-pdf="<?= $isPDF ? 'true' : 'false' ?>">
                    <span class="submission-status <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                    
                    <div class="submission-poster-wrapper">
                        <?php if (!empty($imagePath)): ?>
                            <?php if ($isPDF): ?>
                                <div class="submission-preview pdf-poster">
                                    <i class="fa-solid fa-file-pdf"></i>
                                </div>
                            <?php else: ?>
                                <img src="<?= htmlspecialchars($fullImagePath, ENT_QUOTES) ?>" alt="預覽" class="submission-preview" loading="lazy" onerror="this.onerror=null; this.style.display='none'; this.parentElement.innerHTML='<div class=\'no-poster\'><i class=\'fa-solid fa-image\'></i></div>';">
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="submission-preview no-poster">
                                <i class="fa-solid fa-image"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="submission-content">
                        <h4 class="submission-team-name"><?= $teamName ?></h4>
                        
                        <div class="submission-team-meta">
                            <?= $cohortName ? $cohortName . ' · ' : '' ?><?= $groupName ?>
                        </div>
                        
                        <div class="submission-info">
                            <?php if (!empty($intro)): ?>
                            <div class="submission-info-item">
                                <span class="submission-info-label">簡介：</span>
                                <span class="submission-info-value"><?= $intro ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="submission-info-item">
                                <span class="submission-info-label">上傳時間：</span>
                                <span class="submission-info-value"><?= $submitTime ?></span>
                            </div>
                            
                            <div class="submission-info-item">
                                <span class="submission-info-label">上傳人：</span>
                                <span class="submission-info-value"><?= $submitterName ?></span>
                            </div>
                        </div>
                        
                        <div class="submission-actions">
                            <button type="button" class="btn btn-primary" onclick="viewSubmissionDetail(<?= $sub['prosub_ID'] ?>)">
                                <i class="fa-solid fa-eye"></i> 查看
                            </button>
                            
                            <?php if (!empty($imagePath)): ?>
                            <button type="button" class="btn btn-info" onclick="downloadImage('<?= htmlspecialchars($imagePath, ENT_QUOTES) ?>', '<?= htmlspecialchars(basename($imagePath) ?: 'submission_file', ENT_QUOTES) ?>')">
                                <i class="fa-solid fa-download"></i> 下載
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // 查看詳情（顯示模態框，與科辦端一致）
        function viewSubmissionDetail(prosub_ID) {
            if (!prosub_ID) return;
            
            // 從當前頁面的提交記錄中獲取資料
            const submissionItem = document.querySelector(`[data-prosub-id="${prosub_ID}"]`);
            if (!submissionItem) {
                alert('找不到該提交記錄');
                return;
            }
            
            // 獲取資料
            const groupName = submissionItem.getAttribute('data-group-name') || '';
            const intro = submissionItem.getAttribute('data-intro') || '';
            const submitTime = submissionItem.getAttribute('data-submit-time') || '';
            const submitter = submissionItem.getAttribute('data-submitter') || '';
            const imagePath = submissionItem.getAttribute('data-image-path') || '';
            const isPDF = submissionItem.getAttribute('data-is-pdf') === 'true';
            
            // 創建模態框
            const modal = document.createElement('div');
            modal.className = 'submission-modal';
            modal.id = 'submissionDetailModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'submissionDetailModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1050; display: flex; align-items: center; justify-content: center;';
            
            // 創建背景遮罩
            const backdrop = document.createElement('div');
            backdrop.className = 'submission-modal-backdrop';
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1040;';
            
            modal.innerHTML = `
                <div class="submission-modal-dialog" style="max-width: 900px; width: 90%;">
                    <div class="submission-modal-content" style="border-radius: 12px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.2); background: white;">
                        <div class="submission-modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 12px 12px 0 0; border: none; padding: 20px; display: flex; justify-content: space-between; align-items: center;">
                            <h5 class="submission-modal-title" id="submissionDetailModalLabel" style="font-weight: 600; font-size: 20px; margin: 0;">
                                <i class="fa-solid fa-file-alt"></i> 提交詳情
                            </h5>
                            <button type="button" class="submission-modal-close" aria-label="Close" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">&times;</button>
                        </div>
                        <div class="submission-modal-body" style="padding: 30px; max-height: 70vh; overflow-y: auto;">
                            <div class="submission-modal-row" style="display: flex; flex-wrap: wrap; gap: 20px;">
                                <div class="submission-modal-col" style="flex: 1; min-width: 300px; margin-bottom: 20px;">
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
                                </div>
                                <div class="submission-modal-col" style="flex: 1; min-width: 300px;">
                                    <label class="detail-label" style="margin-bottom: 15px; display: block;">圖檔預覽：</label>
                                    <div class="detail-preview" style="border: 2px solid #e9ecef; border-radius: 8px; padding: 15px; background: #f8f9fa; min-height: 300px; display: flex; align-items: center; justify-content: center;">
                                        ${imagePath ? 
                                            (isPDF ? 
                                                `<object data="${escapeHtml(imagePath)}" type="application/pdf" style="width: 100%; height: 400px; border-radius: 4px;"></object>` :
                                                `<img src="${escapeHtml(imagePath)}" alt="預覽" style="max-width: 100%; max-height: 400px; object-fit: contain; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); cursor: pointer;" onclick="viewImageModal('${escapeHtml(imagePath)}', ${isPDF})">`
                                            ) :
                                            '<div style="color: #6c757d; text-align: center;"><i class="fa-solid fa-file" style="font-size: 48px;"></i><p style="margin-top: 10px;">無圖檔</p></div>'
                                        }
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="submission-modal-footer" style="border-top: 1px solid #e9ecef; padding: 20px; border-radius: 0 0 12px 12px; text-align: right;">
                            <button type="button" class="submission-modal-btn-close" style="border-radius: 8px; padding: 10px 24px; background: #6c757d; color: white; border: none; cursor: pointer; font-size: 14px;">關閉</button>
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
                .detail-value[style*="white-space: pre-wrap"] {
                    white-space: pre-wrap !important;
                    word-wrap: break-word !important;
                }
                .submission-modal-close:hover {
                    opacity: 0.7;
                }
                .submission-modal-btn-close:hover {
                    background: #5a6268 !important;
                }
            `;
            document.head.appendChild(style);
            
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
                    if (document.head.contains(style)) {
                        document.head.removeChild(style);
                    }
                    document.body.style.overflow = '';
                }, 150);
            };
            
            // 綁定關閉事件
            const closeBtn = modal.querySelector('.submission-modal-close');
            const closeFooterBtn = modal.querySelector('.submission-modal-btn-close');
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
        
        // 查看圖檔模態框
        function viewImageModal(imagePath, isPDF) {
            const modal = document.createElement('div');
            modal.className = 'submission-image-modal';
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1050; display: flex; align-items: center; justify-content: center;';
            
            const backdrop = document.createElement('div');
            backdrop.className = 'submission-image-modal-backdrop';
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1040;';
            
            modal.innerHTML = `
                <div class="submission-image-modal-dialog" style="max-width: 90vw; width: 90%; position: relative;">
                    <div class="submission-image-modal-content" style="background: transparent; border: none;">
                        <div class="submission-image-modal-header" style="border: none; padding: 10px; text-align: right;">
                            <button type="button" class="submission-image-modal-close" aria-label="Close" style="background: none; border: none; color: white; font-size: 32px; cursor: pointer; padding: 0; width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; line-height: 1;">&times;</button>
                        </div>
                        <div class="submission-image-modal-body" style="padding: 20px; text-align: center;">
                            ${isPDF ? 
                                `<object data="${escapeHtml(imagePath)}" type="application/pdf" style="width: 100%; height: 80vh; border: none;"></object>` :
                                `<img src="${escapeHtml(imagePath)}" alt="預覽" style="max-width: 100%; max-height: 80vh; object-fit: contain; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">`
                            }
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
            
            const closeBtn = modal.querySelector('.submission-image-modal-close');
            if (closeBtn) closeBtn.onclick = closeModal;
            backdrop.onclick = closeModal;
            modal.onclick = function(e) {
                if (e.target === modal) closeModal();
            };
            
            const escHandler = function(e) {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        }
        
        // HTML 轉義函數
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // 下載圖檔
        function downloadImage(imagePath, fileName) {
            if (!imagePath) {
                alert('無圖檔可下載');
                return;
            }
            
            try {
                let fullImagePath = imagePath;
                if (!imagePath.startsWith('http://') && !imagePath.startsWith('https://') && !imagePath.startsWith('/')) {
                    fullImagePath = '../' + imagePath.replace(/^\.\.\//, '');
                }
                
                const link = document.createElement('a');
                link.href = fullImagePath;
                link.download = fileName || 'submission_file';
                link.target = '_blank';
                link.style.display = 'none';
                
                document.body.appendChild(link);
                link.click();
                
                setTimeout(() => {
                    if (document.body.contains(link)) {
                        document.body.removeChild(link);
                    }
                }, 100);
            } catch (error) {
                console.error('下載失敗:', error);
                try {
                    let fullImagePath = imagePath;
                    if (!imagePath.startsWith('http://') && !imagePath.startsWith('https://') && !imagePath.startsWith('/')) {
                        fullImagePath = '../' + imagePath.replace(/^\.\.\//, '');
                    }
                    window.open(fullImagePath, '_blank');
                } catch (e) {
                    alert('下載失敗，請嘗試右鍵點擊預覽圖檔選擇「另存為」');
                }
            }
        }
    </script>
</div>

