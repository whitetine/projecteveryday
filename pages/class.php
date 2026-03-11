<?php
session_start();
if (!isset($_SESSION['u_ID'])) {
  echo "<script>alert('請先登入');location.href='login.php';</script>";
  exit;
}

// 檢查權限：只有班導師可以訪問
$role_ID = $_SESSION['role_ID'] ?? null;
if ($role_ID != 3) {
  echo '<div class="alert alert-danger">您沒有權限訪問此頁面</div>';
  exit;
}
?>
<link rel="stylesheet" href="css/class.css?v=<?= time() ?>">

<div class="container-fluid class-page">
  <h3 class="mb-4">班級團隊管理</h3>

  <!-- 第一個區塊：未加入團隊統計 -->
  <div class="statistics-card mb-4">
    <div class="statistics-content">
      <div class="statistics-text">
        <h4 id="statistics-title" class="mb-0">載入中...</h4>
        <p class="pie-chart-hint">顯示該屆全部學生。點擊右側圓餅圖區塊，可查看對應學生名單</p>
      </div>
      <div class="pie-chart-container">
        <canvas id="groupPieChart"></canvas>
      </div>
    </div>
  </div>

  <!-- 第二個區塊：類組團隊列表（可收合） -->
  <div class="teams-section" id="teamsSection" style="display: none;">
    <div class="teams-header" id="teamsHeader">
      <h5 class="mb-0">
        <span class="me-2" id="teamsToggleIcon">-</span>
        <span id="selectedGroupName">類組團隊</span>
      </h5>
    </div>
    <div class="teams-content" id="teamsContent">
      <div id="teamsList" class="teams-list">
        <!-- 團隊列表將在這裡動態生成 -->
      </div>
    </div>
  </div>
</div>

<!-- Chart.js -->
<script>
  // 動態載入 Chart.js 和 datalabels 插件
  (function() {
    let chartLoaded = false;
    let datalabelsLoaded = false;
    
    function checkAndInit() {
      if (chartLoaded && datalabelsLoaded) {
        // Chart.js 和 datalabels 都載入完成後，初始化頁面
        if (typeof window.loadClassPageScript === 'function') {
          window.loadClassPageScript();
        }
      }
    }
    
    // 載入 Chart.js
    if (typeof Chart === 'undefined') {
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
      script.onload = function() {
        chartLoaded = true;
        checkAndInit();
      };
      script.onerror = function() {
        console.error('Chart.js 載入失敗');
      };
      document.head.appendChild(script);
    } else {
      chartLoaded = true;
    }
    
    // 載入 chartjs-plugin-datalabels
    if (typeof ChartDataLabels === 'undefined') {
      const datalabelsScript = document.createElement('script');
      datalabelsScript.src = 'https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js';
      datalabelsScript.onload = function() {
        datalabelsLoaded = true;
        checkAndInit();
      };
      datalabelsScript.onerror = function() {
        console.error('chartjs-plugin-datalabels 載入失敗');
        // 即使插件載入失敗，也繼續初始化（不顯示標籤）
        datalabelsLoaded = true;
        checkAndInit();
      };
      document.head.appendChild(datalabelsScript);
    } else {
      datalabelsLoaded = true;
    }
    
    // 如果都已載入，直接初始化
    if (chartLoaded && datalabelsLoaded) {
      setTimeout(function() {
        checkAndInit();
      }, 100);
    }
  })();
</script>
<script src="js/class.js?v=<?= time() ?>"></script>

