<?php
session_start();
require '../includes/pdo.php';

// 檢查權限（只有主任 role_ID = 1 和 科辦 role_ID = 2 可以訪問）
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

if (!$u_ID) {
    echo '<div class="alert alert-danger">請先登入</div>';
    exit;
}

if (!in_array($role_ID, [1, 2])) {
    echo '<div class="alert alert-danger">此頁面僅限主任和科辦使用</div>';
    exit;
}
?>
<!-- CSS 預載入，防止跑版 -->
<link rel="stylesheet" href="css/review_modify.css?v=<?= time() ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="css/review_modify.css?v=<?= time() ?>"></noscript>

<div class="review-modify-container">
    <div class="review-modify-header">
        <h1 class="review-modify-title">審核修改申請</h1>
            </div>

    <!-- 搜尋與篩選區域 -->
    <div class="search-filter-section">
        <div class="search-filter-header">
            <i class="fa-solid fa-chevron-down"></i> 搜尋與篩選
</div>
        <div class="search-filter-content">
            <div class="search-group">
                <label>搜尋專題名稱</label>
                <input type="text" id="searchInput" placeholder="輸入專題名稱或團隊名稱..." class="search-input">
  </div>
            <div class="search-group">
                <label>學年度</label>
                <select id="cohortSelect" class="filter-select">
                    <option value="">全部</option>
                </select>
          </div>
            <div class="search-group">
                <label>類組篩選</label>
                <select id="groupSelect" class="filter-select">
                    <option value="">全部</option>
                </select>
              </div>
            <div class="search-group">
                <label>狀態</label>
                <select id="statusSelect" class="filter-select">
                    <option value="">全部</option>
                    <option value="0">待審核</option>
                    <option value="1">已批准</option>
                    <option value="2">已拒絕</option>
                </select>
    </div>
            <div class="search-actions">
                <button class="btn-search" id="searchBtn">Q 搜尋</button>
                <button class="btn-clear" id="clearBtn">× 清除</button>
  </div>
</div>
          </div>

    <!-- 修改申請列表 -->
    <div class="modification-list" id="modificationList">
        <div class="loading-state" id="loadingState">
            <i class="fa-solid fa-spinner fa-spin"></i> 載入中...
        </div>
</div>

    <!-- 空狀態 -->
    <div class="empty-state" id="emptyState" style="display: none;">
        <i class="fa-solid fa-folder-open"></i>
        <p>目前沒有待審核的修改申請</p>
    </div>
  </div>

<!-- 審核 Modal -->
<div id="reviewModal" class="review-modal" style="display: none;">
    <div class="review-modal-content">
        <div class="review-modal-header">
            <h3 id="modalTitle">審核修改申請</h3>
            <button class="review-modal-close" onclick="closeReviewModal()">
                <i class="fa-solid fa-times"></i>
</button>
</div>
        <div class="review-modal-body" id="modalBody">
            <!-- 動態載入內容 -->
    </div>
        <div class="review-modal-footer">
            <button class="btn-action btn-reject" onclick="rejectModification()">
                <i class="fa-solid fa-times"></i> 拒絕
</button>
            <button class="btn-action btn-approve" onclick="approveModification()">
                <i class="fa-solid fa-check"></i> 批准
</button>
</div>
  </div>
              </div>

<script>
    // 設置配置
    window.REVIEW_MODIFY_CONFIG = {
        u_ID: '<?= htmlspecialchars($u_ID ?? '', ENT_QUOTES) ?>',
        role_ID: <?= $role_ID ?>
    };
</script>
<script src="js/review_modify.js?v=<?= time() ?>"></script>
<script>
    // 初始化頁面
    (function() {
        function initPage() {
            const content = document.querySelector('.review-modify-container');
            if (content) {
                content.style.visibility = 'visible';
            }
            
            // 初始化功能
            if (typeof window.ReviewModify !== 'undefined') {
                // 載入待審核的修改申請列表
                if (typeof window.ReviewModify.loadPendingModifications === 'function') {
                    window.ReviewModify.loadPendingModifications();
                }
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
