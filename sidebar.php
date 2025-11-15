<?php
$user_ID = $_SESSION['u_ID'] ?? null;
$user_img = $_SESSION['u_img'] ?? null;
$user_name = $_SESSION['u_name'] ?? null;
$role_name = $_SESSION['role_name'] ?? null;
$role_ID = $_SESSION['role_ID'] ?? null;
$isAdmin = in_array($role_ID, [1, 2]);


    if (!isset($_SESSION['u_ID'])) {
      echo "<script>alert('請先登入!');location.href='index.php';</script>";
      exit;
    }
?>

<div class="sb-sidenav-menu <?= $isAdmin ? 'admin-sidebar' : '' ?>">
  <?php if ($isAdmin): ?>

    <!-- 功能選單 -->
    <a class="nav-link ajax-link" href="pages/admin_usermanage.php">
      <i class="fa-solid fa-users-gear"></i><span>帳號管理</span>
    </a>
    <a class="nav-link ajax-link" href="pages/group_manage.php">
      <i class="fa-solid fa-layer-group"></i><span>類組管理</span>
    </a>
       <!-- <a class="nav-link ajax-link" href="pages/admin_file.php">
      <i class="fa-solid fa-file-lines"></i><span>文件管理</span></a> -->
 <a class="nav-link ajax-link" href="#">
      <i class="fa-solid fa-bullhorn"></i><span>最新消息</span></a>
       <a class="nav-link ajax-link" href="pages/file.php">
      <i class="fa-solid fa-folder-open"></i><span>文件管理(更新)</span></a>
       <a class="nav-link ajax-link" href="pages/apply.php">
      <i class="fa-solid fa-file-arrow-up"></i><span>申請文件上傳</span></a>
       <a class="nav-link ajax-link" href="pages/teacher_review_status.php">
      <i class="fa-solid fa-star"></i><span>互評(status)</span></a>
          <a class="nav-link ajax-link" href="pages/work_draft.php">
      <i class="fa-solid fa-file-pen"></i><span>work_draft</span></a>
          <a class="nav-link ajax-link" href="pages/work_save.php">
      <i class="fa-solid fa-floppy-disk"></i><span>work_save</span></a>

          <a class="nav-link ajax-link" href="pages/work_form.php">
      <i class="fa-solid fa-file-circle-plus"></i><span>work_form</span></a>

          <a class="nav-link ajax-link" href="pages/apply_preview.php">
      <i class="fa-solid fa-eye"></i><span>apply_preview</span></a>
       <!-- <a class="nav-link ajax-link" href="pages/admin_notify.php">
      <i class="fa-solid fa-file-lines"></i><span>notify</span></a> -->
      <?php elseif ($role_ID == 6): ?>
 <a class="nav-link ajax-link" href="pages/apply.php">
      <i class="fa-solid fa-file-arrow-up"></i><span>文件管理</span>
    </a>

    
          <a class="nav-link ajax-link" href="pages/work_form.php">
      <i class="fa-solid fa-file-circle-plus"></i><span>work_form</span></a>
    <?php elseif ($role_ID == 4): ?>
      
       <a class="nav-link ajax-link" href="pages/teacher_review_status.php">
      <i class="fa-solid fa-star"></i><span>互評(status)</span></a>
          <a class="nav-link ajax-link" href="pages/teacher_review_detail.php">
      <i class="fa-solid fa-list-check"></i><span>互評(detail)</span></a>
          <a class="nav-link ajax-link" href="pages/milestone.php">
      <i class="fa-solid fa-flag-checkered"></i><span>里程碑管理</span></a>
    <?php endif; ?>
   
</div>