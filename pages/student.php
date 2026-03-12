<?php
session_start();
if (!isset($_SESSION['u_ID'])) {
  echo "<script>alert('請先登入');location.href='login.php';</script>";
  exit;
}

// 直接在頁面載入時從資料庫抓取組別名稱
require '../includes/pdo.php';

$uid = $_SESSION['u_ID'];
$teamName = '組別名稱載入中…';

try {
  // 判斷 teammember 使用哪個欄位（相容 team_u_ID / u_ID）
  $teamUserField = 'u_ID';
  $checkStmt = $conn->query("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
  if ($checkStmt && $checkStmt->rowCount() > 0) {
    $teamUserField = 'team_u_ID';
  }

  // 找出這位使用者最近參與的組別
  $stTeam = $conn->prepare("
    SELECT team_ID 
    FROM teammember 
    WHERE {$teamUserField} = ? 
      AND (tm_status IS NULL OR tm_status = 1)
    ORDER BY tm_updated_d DESC
    LIMIT 1
  ");
  $stTeam->execute([$uid]);
  $teamId = $stTeam->fetchColumn();

  if ($teamId) {
    // 從 teamdata 取得專題名稱（不限制 team_status，已結案也要顯示名稱）
    $stName = $conn->prepare("
      SELECT COALESCE(team_project_name, CONCAT('Team ', :tid)) AS team_name
      FROM teamdata
      WHERE team_ID = :tid
      LIMIT 1
    ");
    $stName->execute([':tid' => $teamId]);
    $row = $stName->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['team_name'])) {
      $teamName = $row['team_name'];
    } else {
      $teamName = 'Team ' . $teamId;
    }
  } else {
    $teamName = '尚未加入任何組別';
  }
} catch (Throwable $e) {
  // 若查詢失敗就保留預設文字，但不影響頁面顯示
}
?>
<link rel="stylesheet" href="css/student.css">

<div class="container-fluid student-page">
  <h3 class="mb-4" id="student-team-title">
    <?= htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8') ?>
  </h3>

  <!-- 專題日總彙標題和成員列表 -->
  <div class="team-group-wrapper">
    <div class="project-summary-header">
      <i class="fas fa-users"></i>
      <span class="project-summary-title" id="student-team-name">
        <?= htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8') ?>
      </span>
    </div>
    <div id="members-container" class="team-members-container">
      <div class="text-center p-5 text-muted">
        <i class="fas fa-spinner fa-spin fa-3x mb-3"></i>
        <p>載入中...</p>
      </div>
    </div>
  </div>
</div>

<script src="js/student.js"></script>
