<?php
session_start();
if (!isset($_SESSION['u_ID'])) {
    echo "<script>alert('請先登入');location.href='../index.php';</script>";
    exit;
}
$role_ID = $_SESSION['role_ID'] ?? 0;
if (!in_array($role_ID, [1, 2])) {
    echo "<script>alert('此頁面僅限主任和科辦使用');location.href='../main.php';</script>";
    exit;
}
?>

<div id="statusManagementContent" class="status-management-container">

    <!-- 頁首 -->
    <div class="page-header d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="page-title mb-1">
                <i class="fa-solid fa-toggle-on me-2" style="color:#ffc107;"></i>狀態設定
            </h1>
            <p class="text-muted mb-0">管理帳號狀態名稱、顏色與顯示規則</p>
        </div>
        <div>
            <button class="btn btn-warning btn-sm me-2" id="btnStatusCreate">
                <i class="fa-solid fa-plus me-1"></i>新增狀態
            </button>
            <button class="btn btn-outline-secondary btn-sm" id="btnStatusReset">
                <i class="fa-solid fa-rotate-left me-1"></i>還原預設
            </button>
        </div>
    </div>

    <!-- 狀態一覽卡片：這裡之後用 JS 動態塞多筆 row -->
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <span class="fw-bold">
                    <i class="fa-solid fa-list-ul me-2 text-warning"></i>狀態一覽
                </span>
                <span class="text-muted small ms-2">拖曳左側圖示即可調整顯示順序</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="status-list" id="statusList">
                <!-- 這裡先留空，之後用 JS 根據 API  render -->
            </div>
        </div>
    </div>

    <!-- 右側預覽 / 說明區你可以之後再加一張卡片 -->

</div>

<!-- 🔹 SweetAlert 用的模板：不直接顯示，放 template 裡 -->
<template id="statusEditTemplate">
  <div class="status-edit-modal">
    <div class="status-edit-section mb-3">
      <div class="section-title">
        <i class="fa-solid fa-pen-to-square me-2"></i>基本設定
      </div>
      <div class="row g-3">
        <div class="col-md-8">
          <label class="form-label">狀態名稱 <span class="text-danger">*</span></label>
          <input id="sw_status_name" class="form-control form-control-sm" placeholder="例如：專題進行中">
        </div>
        <div class="col-md-4">
          <label class="form-label">代碼</label>
          <input id="sw_status_code" class="form-control form-control-sm" placeholder="IN_PROGRESS">
          <div class="form-text small">選填，程式判斷用</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">分類</label>
          <select id="sw_status_category" class="form-select form-select-sm">
            <option value="progress">專題進度</option>
            <option value="academic">學籍狀態</option>
            <option value="other">其他</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">顯示顏色</label>
          <select id="sw_badge_color" class="form-select form-select-sm">
            <option value="primary">藍色（primary）</option>
            <option value="success">綠色（success）</option>
            <option value="warning">黃色（warning）</option>
            <option value="danger">紅色（danger）</option>
            <option value="secondary">灰色（secondary）</option>
          </select>
        </div>
      </div>
    </div>

    <hr class="status-edit-divider">

    <div class="status-edit-section">
      <div class="section-title">
        <i class="fa-solid fa-sliders me-2"></i>顯示與預設
      </div>
      <div class="row g-2">
        <div class="col-12">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="sw_enabled" checked>
            <label class="form-check-label" for="sw_enabled">啟用此狀態</label>
          </div>
        </div>
        <div class="col-12">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="sw_show_filter" checked>
            <label class="form-check-label" for="sw_show_filter">顯示於「帳號管理」的狀態篩選</label>
          </div>
        </div>
        <div class="col-12">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="sw_show_dashboard" checked>
            <label class="form-check-label" for="sw_show_dashboard">顯示於狀態統計儀表板</label>
          </div>
        </div>
        <div class="col-12">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="sw_is_default">
            <label class="form-check-label" for="sw_is_default">設定為「新建帳號」預設狀態</label>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script src="../js/admin_statusmanage.js?v=<?= time() ?>"></script>
