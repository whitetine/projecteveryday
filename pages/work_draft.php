<?php
// 确保 session 正确启动
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role_ID = $_SESSION['role_ID'] ?? null;
$isTeacher = ((int)$role_ID === 4);


// 檢查是否為 AJAX 請求（jQuery load 會設定此 header）或 partial 參數
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$isPartial = isset($_GET['partial']) && $_GET['partial'] === '1';
// 檢查是否從 main.php 透過 hash 路由載入（透過檢查 referer）
$isFromMain = isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'main.php') !== false;

// 只有在非 AJAX、非 partial 且不是從 main.php 載入的情況下才重定向
// 這樣可以確保透過 main.php 的 hash 路由載入時不會被重定向
if (!$isPartial && !$isAjax && !$isFromMain) {
    header('Location: ../main.php#pages/work_draft.php');
    exit;
}
?>

<section class="section">
  <div class="section-header">
    <h1 class="page-title"><?= $isTeacher ? '查看學生工作日誌' : '我的日誌紀錄' ?></h1>
  
  </div>

  <div class="section-body">
    <div class="work-draft-page">
      <?php if (!$isTeacher): ?>
        <div class="text-end mb-3">
          <a href="pages/work_form.php" class="btn btn-outline-secondary ajax-link">回日誌填寫</a>
        </div>
      <?php endif; ?>

<!-- 篩選區 -->
<form id="filter-form" class="card mb-3" method="get"
      data-role="<?= htmlspecialchars($role_ID ?? '', ENT_QUOTES, 'UTF-8'); ?>"
      data-is-teacher="<?= $isTeacher ? '1' : '0'; ?>">
  <div class="card-body filter-row d-flex flex-wrap align-items-end gap-3">
    <?php if ($isTeacher): ?>
      <div>
        <label class="form-label mb-1">查看對象</label>
        <select name="team" class="form-select" data-teacher-team>
          <option value="">載入中...</option>
        </select>
      </div>
      <div>
        <label class="form-label mb-1">學生</label>
        <select name="who" class="form-select" data-teacher-student disabled>
          <option value="">請先選擇團隊</option>
        </select>
      </div>
    <?php else: ?>
      <div>
        <label class="form-label mb-1">查看對象</label>
        <select name="who" class="form-select">
          <!-- 選項由 JS 依後端資料動態載入 -->
        </select>
      </div>
    <?php endif; ?>
    <div>
      <label class="form-label mb-1">起始日期</label>
      <input type="date" name="from" class="form-control">
    </div>
    <div>
      <label class="form-label mb-1">結束日期</label>
      <input type="date" name="to" class="form-control">
    </div>
    <div class="ms-auto">
      <button class="btn btn-primary" type="submit">套用篩選</button>
    </div>
  </div>
</form>

<!-- 視圖切換器 -->
<div class="d-flex justify-content-end mb-3" id="view-toggle-container">
  <div class="view-toggle-switch">
    <button type="button" class="view-toggle-btn active" data-view="timeline" id="view-toggle-timeline">
      <i class="fas fa-route"></i>
    </button>
    <button type="button" class="view-toggle-btn" data-view="table" id="view-toggle-table">
      <i class="fas fa-bars"></i>
    </button>
  </div>
</div>

<!-- 資料表 -->
<div class="card">
  <div class="card-body p-0">
    <!-- 時間軸視圖（CodePen 風格） -->
    <div class="view-container view-timeline" id="view-timeline">
      <div class="timeline-container" id="work-timeline-container">
        <div class="timeline-line"></div>
        <div class="timeline-items" id="work-timeline-items">
          <div class="text-center text-muted py-4">載入中...</div>
        </div>
      </div>
    </div>

    <!-- 表格視圖 -->
    <div class="view-container view-table d-none" id="view-table">
      <div class="table-responsive">
        <table class="table table-striped table-hover mb-0" id="work-table">
          <thead>
            <tr id="work-thead-row"></tr>
          </thead>
          <tbody id="work-table-body">
            <tr>
              <td colspan="5" class="text-center text-muted py-4">載入中...</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="pager-bar" id="pager-bar">
      <span class="disabled">1</span>
    </div>
  </div>
</div>
    </div>
  </div>
</section>

<!-- 查看 Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">日誌內容</h5>
        <button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <h5 id="vm-title"></h5>
        <p class="text-muted" id="vm-date"></p>
        <pre id="vm-content"></pre>
      </div>
    </div>
  </div>
</div>

<!-- 留言 Modal -->
<div class="modal fade" id="commentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">留言</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="cmn-list" class="border rounded p-2 mb-2"></div>
        <textarea id="cmn-text" class="form-control mb-2" rows="3" placeholder="輸入留言..."></textarea>
        <button id="cmn-submit" class="btn btn-primary" type="button">送出留言</button>
      </div>
    </div>
  </div>
</div>

<link rel="stylesheet" href="css/work-draft.css?v=<?= time() ?>" id="workDraftCSS">

<script>
// 動態設置 CSS 和 JS 路徑（解決 AJAX 載入時的相對路徑問題）
(function() {
    const getBasePath = function() {
        const path = window.location.pathname;
        if (path.includes('/main.php')) {
            return path.substring(0, path.indexOf('/main.php') + 1);
        }
        if (path.includes('/pages/')) {
            return path.substring(0, path.indexOf('/pages/') + 1);
        }
        return '';
    };
    
    const basePath = getBasePath();
    
    // 更新 CSS 路徑
    const cssLink = document.getElementById('workDraftCSS');
    if (cssLink) {
        cssLink.href = basePath + (basePath.endsWith('/') ? '' : '/') + 'css/work-draft.css?v=<?= time() ?>';
        cssLink.media = 'all';
    }
    
    // 動態載入 JS（避免重複載入）
    const jsPath = basePath + (basePath.endsWith('/') ? '' : '/') + 'js/work-draft.js?v=<?= time() ?>';
    const existingScript = document.querySelector(`script[data-src="${jsPath}"]`);
    
    if (!existingScript) {
        const script = document.createElement('script');
        script.src = jsPath;
        script.dataset.src = jsPath;
        script.onerror = function() {
            console.error('無法載入 work-draft.js，路徑:', jsPath);
        };
        script.onload = function() {
            console.log('work-draft.js 載入成功');
            // JS 檔案會自動初始化
            if (typeof window.initWorkDraft === 'function') {
                setTimeout(() => {
                    window.initWorkDraft();
                }, 100);
            }
        };
        document.head.appendChild(script);
    } else if (typeof window.initWorkDraft === 'function') {
        // 腳本已載入，直接調用初始化函數
        setTimeout(() => {
            window.initWorkDraft();
        }, 100);
    }
})();
</script>
