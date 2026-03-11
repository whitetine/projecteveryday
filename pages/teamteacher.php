
<div class="container py-4">

    <h2 class="mb-4 fw-bold">我指導的組別</h2>

    <!-- 📌 里程碑達成率圖表（暫時隱藏） -->
    <!--
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h4 class="fw-bold mb-3">📌 里程碑達成率</h4>
            <canvas id="milestoneChart" height="150"></canvas>
        </div>
    </div>
    -->

    <!-- 我指導的小組 -->
    <h4 class="fw-bold mb-3"></h4>
    <div id="groupCards" class="row"></div>

    <!-- 最新需求動態（暫時隱藏） -->
    <!--
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h4 class="fw-bold mb-3">🧱 最新需求動態</h4>
            <div id="latestActions"></div>
        </div>
    </div>
    -->

</div>

<!-- 載入 CSS -->
<link rel="stylesheet" href="css/teamteacher.css">

<!-- 載入 Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- 載入 JS -->
<script src="js/teamteacher.js"></script>
