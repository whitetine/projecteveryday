
<div class="work-form-page">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0">每日工作日誌</h1>
    <a href="pages/work_draft.php" class="btn btn-outline-secondary ajax-link">查看日誌</a>
  </div>

<?php
session_start();
// if (!isset($_SESSION['u_ID'])) {
//     echo "<script>alert('請先登入');location.href='../index.php';</script>";
//     exit;
// }
// 檢查是否為 AJAX 請求（jQuery load 會設定此 header）或 partial 參數
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$isPartial = isset($_GET['partial']) && $_GET['partial'] === '1';
// 檢查是否從 main.php 透過 hash 路由載入（透過檢查 referer）
$isFromMain = isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'main.php') !== false;

// 只有在非 AJAX、非 partial 且不是從 main.php 載入的情況下才重定向
// 這樣可以確保透過 main.php 的 hash 路由載入時不會被重定向
if (!$isPartial && !$isAjax && !$isFromMain) {
    header('Location: work_form.php?partial=1');
    exit;
}
$apiBase = rtrim(dirname($_SERVER['PHP_SELF']), '/'); 
?>
  <form id="work-main-form" data-api-base="<?= htmlspecialchars($apiBase, ENT_QUOTES) ?>">
    <input type="hidden" name="work_id" id="work_id">

    <div class="section-box">
      <label class="form-label">標題</label>
      <input type="text" name="work_title" id="work_title" class="form-control title-input" maxlength="2000" required>
    </div>

    <div class="section-box">
      <label class="form-label">內容</label>
      <textarea name="work_content" id="work_content" class="form-control content-area" required></textarea>
      <div class="hint mt-2 mb-3">每日僅一筆。暫存可重進修改；正式送出即結案。</div>
    </div>

    <div class="form-footer">
      <div class="d-flex gap-2" id="action-buttons">
        <button class="btn btn-secondary" type="button" id="saveBtn">暫存</button>
        <button class="btn btn-primary" type="button" id="submitBtn">正式送出</button>
      </div>
      <span class="badge bg-success align-self-center d-none" id="doneBadge">今日紀錄已結案</span>
    </div>
  </form>
</div>

<link rel="stylesheet" href="../css/work-form.css">
<script src="../js/work-form.js"></script>