<?php
session_start();
require '../includes/pdo.php';

// 檢查權限（只有學生 role_ID = 6 可以訪問）
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

if (!$u_ID) {
    echo '<div class="alert alert-danger">請先登入</div>';
    exit;
}

if ($role_ID != 6) {
    echo '<div class="alert alert-danger">此頁面僅限學生使用</div>';
    exit;
}

// 獲取學生所屬的團隊
$team_ID = null;
$team_name = '';

try {
    // 檢查 teammember 表結構（兼容兩種版本）
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
    // 如果查詢失敗，保持為 null
}

if (!$team_ID) {
    echo '<div class="alert alert-warning">您尚未加入任何團隊</div>';
    exit;
}
?>
<!-- CSS 預載入，防止跑版 -->
<link rel="stylesheet" href="css/project_upload.css?v=<?= time() ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="css/project_upload.css?v=<?= time() ?>"></noscript>

<div class="project-upload-container">
    <!-- 團隊標題橫幅 -->
    <div class="team-banner">
        團隊: <?= htmlspecialchars($team_name, ENT_QUOTES, 'UTF-8') ?>
            </div>

    <form id="uploadProjectForm">
        <!-- 專題簡介區域 -->
        <div class="project-intro-section">
            <label class="section-label">專題簡介</label>
            <textarea 
                name="project_intro" 
                id="projectIntro" 
                class="intro-textarea" 
                placeholder="請輸入專題簡介..."
                required
            ></textarea>
          </div>

        <!-- 文件上傳區域 -->
        <div class="upload-section">
            <div class="upload-controls">
                <div class="file-select-area">
                    <button type="button" class="btn-select-file" id="selectFileBtn">
                        <i class="fa-solid fa-file-upload"></i> 選擇檔案
</button>
                    <input type="file" id="posterFileInput" name="poster" accept="image/*" style="display: none;" required>
                    <div class="file-status" id="fileStatus">沒有選擇檔案</div>
</div>
  </div>
            <div class="upload-instruction">
                請上傳直式海報(高度至少為寬度的1.2倍)
              </div>

            <!-- 預覽縮放控制 -->
            <div class="preview-controls">
                <span class="zoom-label">預覽縮放:</span>
                <div class="zoom-controls">
                    <i class="fa-solid fa-magnifying-glass-minus zoom-icon" id="zoomOutBtn"></i>
                    <input type="range" id="zoomSlider" class="zoom-slider" min="50" max="200" value="100">
                    <i class="fa-solid fa-magnifying-glass-plus zoom-icon" id="zoomInBtn"></i>
                    <span class="zoom-percentage" id="zoomPercentage">100%</span>
  </div>
</div>

            <!-- 預覽區域 -->
            <div class="preview-container" id="previewArea">
                <div class="preview-empty">
                    <i class="fa-solid fa-image"></i>
                    <p>預覽區域</p>
            </div>
          </div>
        </div>

        <!-- 底部操作按鈕 -->
        <div class="upload-actions">
            <button type="button" class="btn-action btn-reset" id="resetBtn">
                <i class="fa-solid fa-rotate-left"></i> 重置
    </button>
            <button type="button" class="btn-action btn-remove" id="removeBtn">
                <i class="fa-solid fa-trash"></i> 移除
    </button>
            <button type="submit" class="btn-action btn-submit">
                <i class="fa-solid fa-upload"></i> 提交
    </button>
  </div>
    </form>
  </div>

<script>
    // 設置配置
    window.PROJECT_UPLOAD_CONFIG = {
        u_ID: '<?= htmlspecialchars($u_ID ?? '', ENT_QUOTES) ?>',
        team_ID: <?= $team_ID ?>,
        team_name: '<?= htmlspecialchars($team_name, ENT_QUOTES) ?>',
        role_ID: <?= $role_ID ?>
    };
</script>
<script src="js/project_upload.js?v=<?= time() ?>"></script>
<script>
    // 初始化頁面
    (function() {
        function initPage() {
            const content = document.querySelector('.project-upload-container');
            if (content) {
                content.style.visibility = 'visible';
            }
            
            // 設置文件選擇按鈕
            const selectFileBtn = document.getElementById('selectFileBtn');
            const fileInput = document.getElementById('posterFileInput');
            if (selectFileBtn && fileInput) {
                selectFileBtn.addEventListener('click', function() {
                    fileInput.click();
                });
            }
            
            // 初始化功能
            if (typeof window.ProjectUpload !== 'undefined' && typeof window.ProjectUpload.init === 'function') {
                window.ProjectUpload.init();
            } else {
                setTimeout(initPage, 50);
            }
        }
        
        if (document.readyState === 'loading') {
            window.addEventListener('load', initPage);
        } else {
            initPage();
        }
    })();
</script>
