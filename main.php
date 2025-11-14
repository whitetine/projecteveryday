    <?php include "head.php" ?>

    <?php
    session_start();
    include("includes/pdo.php");

    if (!isset($_SESSION['u_ID'])) {
      echo "<script>alert('請先登入!');location.href='index.php';</script>";
      exit;
    }
    
    $user_name = $_SESSION['u_name'] ?? '未登入';
    $role_name = $_SESSION['role_name'] ?? '無';
    $role_ID = $_SESSION['role_ID'] ?? null;
    $isAdmin = in_array($role_ID, [1, 2]);
    ?>
    <!DOCTYPE html>
    <html lang="zh-Hant">

    <head>
      <meta charset="UTF-8">
      <title>專題日總彙 - 首頁</title>
      <style>

      </style>
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">


    </head>

    <body class="sb-nav-fixed <?= $isAdmin ? 'admin-mode' : 'user-mode' ?>">
      <?php include "nav.php"; ?>


      <div id="layoutSidenav">
        <div id="layoutSidenav_nav" class="<?= $isAdmin ? 'admin-sidenav-container' : '' ?>">
          <nav class="sb-sidenav accordion <?= $isAdmin ? 'sb-sidenav-dark admin-sidenav' : 'sb-sidenav-light' ?>" id="sidenavAccordion">
            <?php include "sidebar.php"; ?>
          </nav>
        </div>
        <main id="content" class="container-fluid py-4"><!-- .load() 塞子頁面 --></main>


      </div>
      <!-- 通知 Modal -->
      <div class="modal fade" id="bell_box">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">通知中心<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button></div>

            <div class="modal-body">
              <p>📌 7/10 上傳檔案截止</p>
              <p>📌 7/15 提交報表</p>
            </div>
          </div>
        </div>
      </div>

    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
  .preview-pane { width:100%; max-width:640px; margin:10px auto 0; }
  .preview-box  { margin:0 auto; }
  .preview-img  { width:100%; height:auto; object-fit:contain; border:1px solid #ddd; border-radius:8px; display:block; }
</style>


<?php include "modules/notify.php"; ?>
<script src="js/work-draft.js"></script>
<script src="js/work-form.js"></script>
<!-- 再載你的 app.js（最後） -->
<script src="js/app.js"></script>

    </body>

    </html>