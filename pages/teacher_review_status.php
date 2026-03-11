<?php
session_start();
if (!isset($_SESSION['u_ID'])) {
  echo "<script>alert('請先登入');location.href='login.php';</script>";
  exit;
}
?>
<link rel="stylesheet" href="css/teacher_review_status.css?v=<?= time() ?>">

<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="mb-0">查看組別互評結果</h3>

  <!-- 標題選單 -->
  <form id="periodForm" class="d-flex align-items-center flex-nowrap">
    <label class="mb-0 me-2 text-muted text-nowrap">請選擇時段：</label>
    <select id="periodSelect" name="period_ID" class="form-select form-select-sm" style="min-width: 300px;"></select>
  </form>
</div>

<div id="periodInfo" class="text-end mb-2 small text-muted"></div>

<table class="table table-sm align-middle table-clean">
  <thead>
    <tr>
      <th>組別</th>
      <th>應有筆數（學生數）</th>
      <th>已完成（本週已評分學生數）</th>
      <th>狀態</th>
      <th>動作</th>
    </tr>
  </thead>
  <tbody id="reviewStatusBody"></tbody>
</table>

<script src="js/teacher_review_status.js"></script>
