<?php
// 嘗試從 URL 參數取得 team_ID 和 period_ID（可能為空，因為使用 hash 路由）
$teamId = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : 0;
$periodId = isset($_GET['period_ID']) ? (int)$_GET['period_ID'] : 0;
?>
<link rel="stylesheet" href="css/teacher_review_detail.css?v=20241125">
  <div class="detail-card card-shadow hero-card mb-4">
    <div class="hero-grid">
      <div class="hero-info">
        <p class="eyebrow text-muted mb-2">組別資訊</p>
        <h4 class="mb-1" id="team-name"></h4>
        <div class="text-muted" id="period-info"></div>
      </div>
      <div class="hero-actions text-end">
        <div class="stat-pill-wrap mb-2" id="stat-badges"></div>
        <div class="action-buttons">
          <a class="btn btn-outline-secondary btn-sm ajax-link"
             id="back-link"
             href="pages/teacher_review_status.php">
            回列表
          </a>
        </div>
      </div>
    </div>
  </div>

  <div class="detail-card card-shadow mb-4" id="matrix-card">
    <div class="section-header">
      <div>
        <p class="eyebrow text-muted mb-1">矩陣檢視</p>
        <h5 class="mb-0">互評分數 / 評論</h5>
      </div>
      <div class="section-actions">
        <button id="toggleView" class="btn btn-sm btn-outline-dark">顯示評論</button>
      </div>
    </div>
    <div class="table-responsive" id="matrix-wrapper"></div>
  </div>

  <div class="detail-grid">
    <div class="detail-card card-shadow">
      <p class="eyebrow text-muted mb-1">統計</p>
      <h5 class="mb-3">被評平均分（本週）</h5>
      <div id="avg-table-wrapper"></div>
    </div>
    <div class="detail-card card-shadow">
      <p class="eyebrow text-muted mb-1">提醒</p>
      <h5 class="mb-3">本週尚未評分的學生</h5>
      <ul class="mb-0 list-unstyled" id="no-review-list"></ul>
    </div>
  </div>
  
  <!-- 錯誤訊息區域 -->
  <div id="error-message" class="alert alert-danger m-3" style="display: none;"></div>

<script>
(function(){
  function getUrlParams() {
    const hash = window.location.hash || '';
    const query = hash.split('?')[1];
    if (!query) return {};
    const params = {};
    query.split('&').forEach(param => {
      const [key, value] = param.split('=');
      if (key && value) {
        params[decodeURIComponent(key)] = decodeURIComponent(value);
      }
    });
    return params;
  }

  const params = getUrlParams();
  const teamFromHash = params.team_ID ? parseInt(params.team_ID, 10) : NaN;
  const periodFromHash = params.period_ID ? parseInt(params.period_ID, 10) : NaN;

  const fallbackTeamId = Number.isNaN(teamFromHash) ? <?= $teamId ?> : teamFromHash;
  const fallbackPeriodId = Number.isNaN(periodFromHash) ? <?= $periodId ?> : periodFromHash;

  window.TEAM_ID = fallbackTeamId && fallbackTeamId > 0 ? fallbackTeamId : 0;
  window.PERIOD_ID = fallbackPeriodId && fallbackPeriodId > 0 ? fallbackPeriodId : 0;
})();
</script>

<script src="js/teacher_review_detail.js"></script>


