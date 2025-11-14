
<div class="work-form-page">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0">每日工作日誌</h1>
    <a href="pages/work_draft.php" class="btn btn-outline-secondary ajax-link">查看日誌</a>
  </div>

  <?php $apiBase = rtrim(dirname($_SERVER['PHP_SELF']), '/'); ?>
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

<link rel="stylesheet" href="../css/pages/work-form.css">
<script src="../js/work-form.js"></script>