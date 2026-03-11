<?php
// integrate.php
session_start();
$role_ID = $_SESSION["role_ID"] ?? null;
$isConvener = ($role_ID == 7);
$isOffice = ($role_ID == 2);
?>


<div class="wrap page-integrate">
  <div class="toolbar">
    <div class="toolbar-row toolbar-row-1">
      <div class="filters">
        <div class="field" >
          <label>屆別:</label>
          <select id="cohort">
            <option value="">全部</option>
          </select>
        </div>

        <div class="field">
          <label>標題:</label>
          <input id="title" type="text" placeholder="輸入標題" />
        </div>

        <div class="field">
          <label>資料類型:</label>
          <select id="format">
            <option value="全部" selected>全部</option>
            <option value="初審建議表">初審建議表</option>
            <option value="審查建議表">審查建議表</option>
            <?php if (!$isConvener): ?>
            <option value="時程表">時程表</option>
            <?php endif; ?>
          </select>
        </div>
      </div>

      <div class="actions actions-primary">
        <button class="btn" id="btnCreate" type="button">建立</button>
        <button class="btn" id="btnSearch" type="button">查詢</button>
      </div>
    </div>

    <div class="toolbar-row toolbar-row-2">
      <div class="actions actions-secondary">
        <button class="btn btn-export-history" id="btnExportHistory" type="button">匯出歷次建議</button>
        <button class="btn btn-export" id="btnExportPDF" type="button" style="display: none;">
          <i class="fa-solid fa-file-pdf me-1"></i>匯出 PDF
        </button>
        <button class="btn btn-export" id="btnExportWord" type="button" style="display: none;">
          <i class="fa-solid fa-file-word me-1"></i>匯出 Word
        </button>
      </div>
    </div>
  </div>

  <div class="table-box">
    <table class="grid">
      <colgroup>
        <col class="grid-col-check">
        <col class="grid-col-title">
        <col class="grid-col-cohort">
        <col class="grid-col-format">
        <col class="grid-col-editor">
        <col class="grid-col-actions">
      </colgroup>
      <thead>
        <tr>
          <th class="grid-th-check"><input type="checkbox" id="selectAll" title="全選"></th>
          <th class="grid-th-title">標題</th>
          <th class="grid-th-cohort">屆別</th>
          <th class="grid-th-format">資料類型</th>
          <th class="grid-th-editor">最後編輯人</th>
          <th class="grid-th-actions">操作</th>
        </tr>
      </thead>
      <tbody id="gridBody"></tbody>
    </table>
  </div>
</div>

<!-- 編輯 Modal -->
<div class="edit-modal-overlay" id="editModalOverlay">
  <div class="edit-modal">
    <div class="edit-modal-header">
      <h3 class="edit-modal-title" id="editModalTitle">編輯</h3>
      <button class="edit-modal-close" id="editModalClose">×</button>
    </div>
    <div class="edit-modal-body" id="editModalBody">
      <!-- 動態載入編輯內容 -->
    </div>
  </div>
</div>

<!-- 引入 JS / CSS -->
<!-- 確保 SweetAlert2 CSS 已載入 -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script>
  // 傳遞角色信息給 JavaScript
  window.currentUserRole = <?php echo json_encode($role_ID); ?>;
  window.isConvener = <?php echo json_encode($isConvener); ?>;
  window.isOffice = <?php echo json_encode($isOffice); ?>;
  
  // 確保 SweetAlert2 已載入（如果還沒載入，則載入）
  (function() {
    if (!window.Swal) {
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
      script.async = true;
      document.head.appendChild(script);
    }
  })();
  
  // 給 body 添加 page-integrate class，用於限定 CSS 作用域
  (function() {
    if (document.body) {
      document.body.classList.add('page-integrate');
    } else {
      // 如果 body 還沒載入，等待 DOMContentLoaded
      document.addEventListener('DOMContentLoaded', function() {
        document.body.classList.add('page-integrate');
      });
    }
  })();
</script>
<script src="js/integrate.js"></script>
<link rel="stylesheet" href="css/integrate.css">

