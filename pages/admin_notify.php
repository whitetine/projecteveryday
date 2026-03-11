<!-- pages/admin_notify.php -->
<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$role_ID = (int)($_SESSION['role_ID'] ?? 0);
$canManage = ($role_ID === 2); // 僅系辦可管理
?>
<link rel="stylesheet" href="css/admin_notify.css?v=<?= time() ?>">
<div class="admin-notify-container" data-page-id="admin_notify" data-can-manage="<?= $canManage ? '1' : '0' ?>">
<?php if ($canManage): ?>
  <div class="admin-notify-header">
    <h2>公告管理</h2>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#notifyModal">
      新增資訊
    </button>
  </div>

  <div id="notifyList" class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>公告列表</strong>
      <button class="btn btn-sm btn-outline-secondary" onclick="loadNotifyList()">重新載入</button>
    </div>
    <div class="card-body">
      <div id="notifyListContent">
        <div class="text-center py-4">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">載入中...</span>
          </div>
          <p class="mt-2 text-muted">載入中...</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal 已由 main.php 的 modules/notify.php 提供，此處不重複 include 避免重複 ID 與初始化衝突 -->
<script src="js/admin_notify.js?v=<?= time() ?>"></script>
<?php else: ?>
  <div class="alert alert-warning d-flex align-items-center" role="alert">
    <i class="fas fa-lock fa-2x me-3"></i>
    <div>
      <strong>無權限</strong><br>
      <span class="text-muted">公告管理功能僅限系辦使用。如需發布或編輯公告，請使用系辦帳號登入。</span>
    </div>
  </div>
<?php endif; ?>
</div>
