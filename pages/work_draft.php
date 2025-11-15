<?php
session_start();
if (!isset($_SESSION['u_ID'])) {
    echo "<script>alert('請先登入');location.href='../index.php';</script>";
    exit;
}
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

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">我的日誌紀錄</h4>
  <a href="pages/work_form.php" class="btn btn-outline-secondary ajax-link">回日誌填寫</a>
</div>

  <!-- 篩選區 -->
  <form id="filter-form" class="card mb-3" method="get">
    <div class="card-body filter-row d-flex flex-wrap align-items-end gap-3">
      <div>
        <label class="form-label mb-1">查看對象</label>
        <select name="who" class="form-select">
          <!-- 選項由 JS 依後端資料動態載入 -->
        </select>
      </div>
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

  <!-- 資料表 -->
  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0" id="work-table">
          <thead class="table-light">
            <tr id="work-thead-row"></tr>
          </thead>
          <tbody id="work-table-body">
            <tr>
              <td colspan="7" class="text-center text-muted py-4">載入中...</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pager-bar" id="pager-bar">
        <span class="disabled">1</span>
      </div>
    </div>
  </div>


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
        <button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="cmn-list" class="border rounded p-2 mb-2"></div>
        <textarea id="cmn-text" class="form-control mb-2" rows="3" placeholder="輸入留言..."></textarea>
        <button id="cmn-submit" class="btn btn-primary" type="button">送出留言</button>
      </div>
    </div>
  </div>
</div>

<link rel="stylesheet" href="../css/work-draft.css">
<script src="../js/work-draft.js"></script>
