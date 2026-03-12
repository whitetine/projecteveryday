<?php
session_start();
if (!isset($_SESSION['u_ID'])) {
  echo "<script>alert('請先登入');location.href='login.php';</script>";
  exit;
}
?>
<link rel="stylesheet" href="css/student.css">

<div class="container-fluid student-page">
  <h3 class="mb-4" id="student-team-title">組別成員</h3>

  <!-- 專題日總彙標題和成員列表 -->
  <div class="team-group-wrapper">
    <div class="project-summary-header">
      <i class="fas fa-users"></i>
      <span class="project-summary-title" id="student-team-name">專題日總彙</span>
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
