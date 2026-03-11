<?php
session_start();
require '../includes/pdo.php';

// 檢查權限（科辦角色 ID = 2）
$role_ID = $_SESSION['role_ID'] ?? null;
if (!isset($role_ID) || !in_array($role_ID, [1, 2])) {
  echo '<div class="alert alert-danger">您沒有權限訪問此頁面</div>';
  exit;
}

$user_name = $_SESSION['u_name'] ?? '科辦人員';
?>
<link rel="stylesheet" href="../css/office.css?v=<?= time() ?>">

<div class="office-container">
  <!-- 背景動畫層 -->
  <div class="office-background">
    <div class="gradient-orb orb-1"></div>
    <div class="gradient-orb orb-2"></div>
    <div class="gradient-orb orb-3"></div>
  </div>

  <!-- 主內容 -->
  <div class="office-content">
    <!-- 歡迎區塊 -->
    <div class="welcome-section">
      <div class="welcome-card">
        <div class="welcome-icon">
          <i class="fa-solid fa-building"></i>
        </div>
        <h1 class="welcome-title">科辦管理系統</h1>
        <p class="welcome-subtitle">歡迎回來，<?= htmlspecialchars($user_name) ?></p>
        <div class="welcome-divider"></div>
      </div>
    </div>

    <!-- 功能卡片區 -->
    <div class="function-grid">
      <!-- 申請審核 -->
      <div class="function-card" data-href="pages/apply.php">
        <div class="card-icon">
          <i class="fa-solid fa-file-check"></i>
        </div>
        <h3 class="card-title">申請審核</h3>
        <p class="card-desc">審核學生提交的申請文件</p>
        <div class="card-arrow">
          <i class="fa-solid fa-arrow-right"></i>
        </div>
      </div>

      <!-- 工作日誌 -->
      <div class="function-card" data-href="pages/work_draft.php">
        <div class="card-icon">
          <i class="fa-solid fa-file-pen"></i>
        </div>
        <h3 class="card-title">工作日誌</h3>
        <p class="card-desc">查看所有工作日誌記錄</p>
        <div class="card-arrow">
          <i class="fa-solid fa-arrow-right"></i>
        </div>
      </div>

      <!-- 統計報表 -->
      <div class="function-card" data-href="#">
        <div class="card-icon">
          <i class="fa-solid fa-chart-line"></i>
        </div>
        <h3 class="card-title">統計報表</h3>
        <p class="card-desc">查看系統統計與分析</p>
        <div class="card-arrow">
          <i class="fa-solid fa-arrow-right"></i>
        </div>
      </div>
    </div>

    <!-- 快速統計區 -->
    <div class="stats-section">
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fa-solid fa-file"></i>
        </div>
        <div class="stat-content">
          <h4 class="stat-number" id="totalFiles">0</h4>
          <p class="stat-label">總文件數</p>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon">
          <i class="fa-solid fa-clock"></i>
        </div>
        <div class="stat-content">
          <h4 class="stat-number" id="pendingFiles">0</h4>
          <p class="stat-label">待審核文件</p>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon">
          <i class="fa-solid fa-check-circle"></i>
        </div>
        <div class="stat-content">
          <h4 class="stat-number" id="approvedFiles">0</h4>
          <p class="stat-label">已審核文件</p>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon">
          <i class="fa-solid fa-users"></i>
        </div>
        <div class="stat-content">
          <h4 class="stat-number" id="totalUsers">0</h4>
          <p class="stat-label">總用戶數</p>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // 功能卡片點擊事件
  document.querySelectorAll('.function-card').forEach(card => {
    card.addEventListener('click', function() {
      const href = this.dataset.href;
      if (href && href !== '#') {
        if (href.startsWith('pages/')) {
          location.hash = href;
        } else {
          window.location.href = href;
        }
      }
    });

    // 懸停效果
    card.addEventListener('mouseenter', function() {
      this.style.transform = 'translateY(-10px) scale(1.02)';
    });

    card.addEventListener('mouseleave', function() {
      this.style.transform = 'translateY(0) scale(1)';
    });
  });

  // 載入統計數據（這裡可以連接實際的 API）
  loadStatistics();
});

async function loadStatistics() {
  try {
    // 這裡可以連接實際的 API 來獲取統計數據
    // 暫時使用模擬數據
    setTimeout(() => {
      document.getElementById('totalFiles').textContent = '128';
      document.getElementById('pendingFiles').textContent = '12';
      document.getElementById('approvedFiles').textContent = '116';
      document.getElementById('totalUsers').textContent = '256';
    }, 500);
  } catch (error) {
    console.error('載入統計數據失敗:', error);
  }
}
</script>

