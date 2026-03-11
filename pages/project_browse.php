<?php
session_start();
require '../includes/pdo.php';
//
// 檢查是否為公開模式
$isPublic = isset($_GET['public']) && $_GET['public'] == '1';
$isIframe = isset($_GET['iframe']) && $_GET['iframe'] == '1';
$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';

// 檢查權限（公開模式或已登入用戶都可以訪問）
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

if (!$isPublic && !$u_ID) {
    echo '<div class="alert alert-danger">請先登入</div>';
    exit;
}
?>
<!-- 最優先的樣式，確保背景是白色 - 必須在最前面 -->
<style id="project-browse-bg-fix">
    /* 使用最高優先級選擇器，確保覆蓋所有樣式 */
    html body #content.project-browse-page,
    html body #content:has(.project-browse-container),
    html body #content .project-browse-container,
    body #content.project-browse-page,
    body #content:has(.project-browse-container),
    body #content .project-browse-container,
    #content.project-browse-page,
    #content:has(.project-browse-container),
    #content .project-browse-container,
    .project-browse-container {
        background: #ffffff !important;
        background-color: #ffffff !important;
        background-image: none !important;
    }
    
    /* 確保 #content 本身也是白色 */
    #content {
        background: #ffffff !important;
        background-color: #ffffff !important;
        background-image: none !important;
    }
</style>
<!-- 最優先的樣式，確保背景是白色 -->
<style>
    /* 強制設置所有相關元素的背景為白色 - 最高優先級 */
    html,
    html body,
    body,
    body #content,
    html body #content,
    html body #content .project-browse-container,
    html body .project-browse-container,
    body #content:has(.project-browse-container),
    body #content .project-browse-container,
    #content,
    #content .project-browse-container,
    .project-browse-container {
        background: #ffffff !important;
        background-color: #ffffff !important;
        background-image: none !important;
    }
    
    /* 覆蓋所有可能的選擇器 */
    * {
        --project-browse-bg: #ffffff !important;
    }
    
    /* 確保 body 和 html 的背景也是白色 */
    body:has(#content .project-browse-container),
    html:has(body #content .project-browse-container) {
        background: #ffffff !important;
        background-color: #ffffff !important;
    }
</style>
<script>
window.PROJECT_BASE = "../";
</script>
<!-- CSS 直接載入，防止閃爍 -->
<?php if ($isEmbed): ?>
<link rel="stylesheet" href="css/project_browse.css?v=<?= time() ?>">
<?php else: ?>
<link rel="stylesheet" href="../css/project_browse.css?v=<?= time() ?>">
<?php endif; ?>
<?php if ($isIframe || $isEmbed): ?>
<style>
    <?php if ($isIframe): ?>
    body {
        margin: 0;
        padding: 0;
        overflow-x: hidden;
        background: #ffffff !important;
    }
    <?php endif; ?>
    .project-browse-container {
        padding: 0 !important;
        margin: 0 !important;
        background: #ffffff !important;
        min-height: auto !important;
    }
    .browse-header {
        margin-bottom: 20px !important;
        border-radius: 0 !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        padding: 20px 30px !important;
    }
    .project-display-section {
        padding: 20px !important;
        border-radius: 0 !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
    }
</style>
<?php endif; ?>
<style>
    /* 防止頁面閃爍 - 關鍵樣式內聯，立即生效 */
    .project-browse-container {
        opacity: 1 !important;
        visibility: visible !important;
        transition: none !important;
    }
    
    /* 移除父容器的留白 */
    #content:has(.project-browse-container) {
        padding: 0 !important;
        margin-left: var(--sidebar-w) !important;
        background: #ffffff !important;
    }
    
    /* 如果瀏覽器不支持 :has()，使用類名方式 */
    #content.project-browse-page {
        padding: 0 !important;
        background: #ffffff !important;
    }
    
    /* 確保 project-browse-container 背景是白色 */
    .project-browse-container {
        background: #ffffff !important;
    }
    
    /* 強制覆蓋所有可能的背景設定 */
    body:has(.project-browse-container),
    body .project-browse-container,
    #content:has(.project-browse-container),
    #content .project-browse-container {
        background: #ffffff !important;
        background-color: #ffffff !important;
        background-image: none !important;
    }
    
    /* 確保霓虹橫幅正常顯示 */
    .neon-banner,
    .neon-banner-wrapper {
        display: block !important;
    }
</style>

<div class="project-browse-container" style="background: #ffffff !important; background-color: #ffffff !important; background-image: none !important;">
    <!-- 頁面標題 -->
    <div class="browse-header" style="width: 100%; margin: 0; padding: 0; position: relative; z-index: 10; display: flex; align-items: center; justify-content: center;">
        <div class="neon-banner-wrapper" style="position: relative; width: 100%; max-width: 100%; padding: 28px 40px; display: flex; align-items: center; justify-content: center;">
            <!-- 霓虹發光橫幅 -->
            <div class="neon-banner" style="position: relative; background: linear-gradient(135deg, #2d1b4e 0%, #1a0d2e 100%); padding: 24px 48px; border-radius: 12px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.1); border: 2px solid transparent; width: 100%; max-width: 100%; overflow: hidden;">
                <!-- 霓虹發光邊框 - 從粉紅色（左上）到藍色（右下） -->
                <div class="neon-border-wrapper" style="position: absolute; inset: -2px; border-radius: 12px; z-index: -1; padding: 2px; background: linear-gradient(135deg, #ff00ff 0%, #ff00ff 25%, #00ffff 75%, #00ffff 100%); filter: blur(2px); opacity: 0.9; animation: neonGlow 2s ease-in-out infinite alternate;">
                    <div style="width: 100%; height: 100%; background: linear-gradient(135deg, #2d1b4e 0%, #1a0d2e 100%); border-radius: 10px;"></div>
                </div>
                <div class="neon-border-strong" style="position: absolute; inset: -1px; border-radius: 12px; z-index: -1; padding: 1px; background: linear-gradient(135deg, #ff00ff 0%, #ff00ff 25%, #00ffff 75%, #00ffff 100%);">
                    <div style="width: 100%; height: 100%; background: linear-gradient(135deg, #2d1b4e 0%, #1a0d2e 100%); border-radius: 11px;"></div>
                </div>
                
                <h1 class="browse-title" style="margin: 0; position: relative; z-index: 2; display: flex; align-items: center; gap: 16px; color: #ffffff; font-size: 36px; font-weight: 800; letter-spacing: 2px; text-shadow: 0 0 20px rgba(255, 255, 255, 0.5), 0 0 40px rgba(255, 0, 255, 0.3); justify-content: center;">
                    <i class="fa-solid fa-graduation-cap" style="color: #ffffff; font-size: 32px; filter: drop-shadow(0 0 10px rgba(255, 255, 255, 0.8));"></i>
                    <span>歷屆專題瀏覽</span>
                </h1>
            </div>
        </div>
    </div>

    <!-- 搜尋與篩選區域 -->
    <div class="search-filter-section" style="opacity: 0 !important; visibility: hidden !important;">
        <div class="search-filter-header">
            <i class="fa-solid fa-filter"></i>
            <span>搜尋與篩選</span>
        </div>
        <div class="search-filter-content">
            <div class="search-group" style="flex: 1; min-width: 300px;">
                <label for="searchInput">
                    <i class="fa-solid fa-magnifying-glass"></i> 搜尋專題
                </label>
                <input 
                    type="text" 
                    id="searchInput" 
                    class="filter-select" 
                    placeholder="輸入專題名稱或關鍵字"
                    autocomplete="off"
                >
                <small class="search-hint" style="margin-top: 4px; color: #666; font-size: 14px;">
                    <i class="fa-solid fa-lightbulb"></i> 提示：可搜尋專題名稱或簡介內容，關鍵字不需要完全匹配
                </small>
            </div>
            <div class="search-group">
                <label for="cohortFilter">學級</label>
                <select id="cohortFilter" class="filter-select">
                    <option value="0">全部學級</option>
                </select>
            </div>
            <div class="search-actions">
                <button type="button" class="btn-clear" id="clearFiltersBtn">
                    <i class="fa-solid fa-rotate-left"></i> 清除篩選
                </button>
            </div>
        </div>
    </div>

    <!-- 專題展示區域 -->
    <div class="project-display-section">
        <div id="projectDisplayArea">
            <!-- 初始為空，由 JavaScript 直接載入內容 -->
        </div>
    </div>
</div>

<script>
    // 設置配置
    window.PROJECT_BROWSE_CONFIG = {
        u_ID: '<?= htmlspecialchars($u_ID ?? '', ENT_QUOTES) ?>',
        role_ID: <?= $role_ID ?? 'null' ?>,
        isPublic: <?= $isPublic ? 'true' : 'false' ?>
    };
    
    // 如果是 iframe 模式，通知父頁面調整高度
    if (window.parent && window.parent !== window) {
        function notifyHeight() {
            const height = document.documentElement.scrollHeight || document.body.scrollHeight;
            window.parent.postMessage({ type: 'resizeBrowseIframe', height: height }, '*');
        }
        setTimeout(notifyHeight, 100);
        window.addEventListener('resize', notifyHeight);
        // 監聽內容變化
        const observer = new MutationObserver(notifyHeight);
        observer.observe(document.body, { childList: true, subtree: true, attributes: true });
    }
</script>
    <?php if ($isEmbed): ?>
    <script src="js/project_browse.js?v=<?= time() ?>"></script>
    <?php else: ?>
    <script src="/js/project_browse.js?v=<?= time() ?>"></script>
    <?php endif; ?>
<script>
    // 初始化頁面 - 優化版本，避免閃爍
    (function() {
        const container = document.querySelector('.project-browse-container');
        if (!container) return;
        
        // 🔹 【關鍵修復】容器保持可見，不隱藏
        container.style.opacity = '1';
        container.style.visibility = 'visible';
        
        function initPage() {
            // 初始化功能
            if (typeof window.ProjectBrowse !== 'undefined' && typeof window.ProjectBrowse.init === 'function') {
                window.ProjectBrowse.init();
            } else {
                // 如果 ProjectBrowse 還沒載入，等待一下再試
                const maxRetries = 10;
                let retries = 0;
                const checkAndInit = function() {
                    if (typeof window.ProjectBrowse !== 'undefined' && typeof window.ProjectBrowse.init === 'function') {
                        window.ProjectBrowse.init();
                    } else if (retries < maxRetries) {
                        retries++;
                        setTimeout(checkAndInit, 50);
                    }
                };
                checkAndInit();
            }
        }
        
        // 🔹 【關鍵修復】立即初始化，不等待延遲
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                initPage();
            });
        } else {
            initPage();
        }
    })();
</script>

