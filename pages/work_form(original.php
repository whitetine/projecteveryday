<?php
session_start();
require '../includes/pdo.php';


// if (!isset($_SESSION['u_ID'])) {
//     header("Location: index.php?msg=" . urlencode("請先登入"));
//     exit;
// }
date_default_timezone_set('Asia/Taipei');

$u_ID  = $_SESSION['u_ID'];
$TABLE = 'workdata';

$st = $conn->prepare("UPDATE `$TABLE`
                      SET work_status = 3
                      WHERE u_ID = ? AND work_status = 1 AND DATE(work_created_d) < CURDATE()");
$st->execute([$u_ID]);

$st = $conn->prepare("SELECT * FROM `$TABLE`
                      WHERE u_ID = ? AND DATE(work_created_d) = CURDATE()
                      ORDER BY work_ID DESC LIMIT 1");
$st->execute([$u_ID]);
$today = $st->fetch(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
$readOnly = $today && intval($today['work_status']) === 3;
$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title>每日工作日誌</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
  .hint{font-size:.9rem;color:#6c757d}
  .textarea-fixed{
    width:100%;height:240px;min-height:240px;max-height:240px;
    overflow-y:auto;overflow-x:hidden;resize:none;
    white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;line-height:1.6;
  }
  /* 檔案上傳列：input 撐滿，按鈕靠右 */
.file-row{
  display: flex;
  align-items: center;
  gap: .5rem;
  width: 100%;
}
.file-row .file-input{
  flex: 1 1 auto;      /* 撐滿到按鈕前 */
  min-width: 0;        /* 避免在 flex 內超出 */
  width: auto !important; /* 覆蓋舊的固定寬度設定 */
}

</style>
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">每日工作日誌</h3>
    <a href="work_draft.php" class="btn btn-outline-secondary">查看日誌</a>
  </div>

  <div class="card">
    <div class="card-body">
      <form action="work_save.php" method="post" enctype="multipart/form-data" id="work-main-form">
        <input type="hidden" name="work_id" value="<?= h($today['work_ID'] ?? '') ?>">

        <div class="mb-3">
          <label class="form-label">標題</label>
          <input type="text" name="work_title" class="form-control" maxlength="2000"
                 value="<?= h($today['work_title'] ?? '') ?>" <?= $readOnly?'readonly':'' ?> required>
        </div>

        <div class="mb-3">
          <label class="form-label">內容</label>
          <textarea name="work_content" class="form-control textarea-fixed" <?= $readOnly?'readonly':'' ?>required><?= h($today['work_content'] ?? '') ?></textarea>
          <div class="hint mt-1">每日僅一筆。暫存可重進修改；正式送出或過期即結案。</div>
        </div>

        <div class="mb-3">
        <label class="form-label mb-1">上傳檔案（選填，最大 50MB）</label>

<div class="file-row">
  <input type="file" name="work_file"
         class="form-control file-input" <?= $readOnly?'disabled':'' ?>>
  <button type="button" id="btn-clear-file"
          class="btn btn-sm btn-outline-secondary"
          <?= $readOnly?'disabled':'' ?>>
    清空選擇檔案
  </button>
</div>

<?php if(!empty($today['work_url'])): ?>
  <!-- 目前檔案 + 移除現有檔案：同一行，移除靠右（你原本的需求） -->
  <div class="mt-2 d-flex align-items-center justify-content-between flex-wrap">
    <div class="me-2">
      暫存檔案：
      <a href="<?= h($today['work_url']) ?>" target="_blank"><?= h(basename($today['work_url'])) ?></a>
    </div>
    <?php if(!$readOnly): ?>
      <button type="button" id="btn-remove-file" class="btn btn-sm btn-outline-danger">移除暫存檔案</button>
    <?php endif; ?>
  </div>
<?php endif; ?>

          <div class="hint mt-1">超過 50MB 請把雲端連結放在內容區。</div>
        </div>

        <!-- 動作按鈕：靠左 -->
        <div class="d-flex gap-2">
          <?php if(!$readOnly): ?>
            <button class="btn btn-secondary" type="submit" name="action" value="draft">暫存</button>
            <button class="btn btn-primary"   type="submit" name="action" value="submit">正式送出</button>
          <?php else: ?>
            <span class="badge bg-success align-self-center">今日紀錄已結案</span>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

</div>

<?php if(!$readOnly && !empty($today['work_ID']) && !empty($today['work_url'])): ?>
<form id="remove-file-form" action="work_save.php" method="post" class="d-none">
  <input type="hidden" name="action" value="remove_file">
  <input type="hidden" name="work_id" value="<?= h($today['work_ID']) ?>">
</form>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const fileInput = document.querySelector('input[name="work_file"]');
  const clearBtn  = document.getElementById('btn-clear-file');
  const rmBtn     = document.getElementById('btn-remove-file');
  const form      = document.getElementById('work-main-form');

  //伺服器訊息彈窗
  <?php if (!empty($msg)): ?>
  Swal.fire({ icon:'info', title:'提示', text: <?= json_encode($msg, JSON_UNESCAPED_UNICODE) ?>, confirmButtonText:'知道了' });
  <?php endif; ?>

  //清空尚未上傳的檔案
  function updateClearState(){
    if (!clearBtn || !fileInput) return;
    const hasFile = fileInput.files && fileInput.files.length > 0;
    clearBtn.disabled = fileInput.disabled || !hasFile;
  }
  clearBtn && clearBtn.addEventListener('click', function(){
    try { fileInput.value=''; fileInput.dispatchEvent(new Event('change')); } catch {}
    updateClearState();
    Swal.fire({icon:'success', title:'已清空選擇', timer:900, showConfirmButton:false});
  });
  fileInput && fileInput.addEventListener('change', updateClearState);
  updateClearState();

  //前端限制：>50MB 擋下
  form && form.addEventListener('submit', function(e){
    const f = fileInput && fileInput.files && fileInput.files[0];
    if (f && f.size > 50 * 1024 * 1024) {
      e.preventDefault();
      Swal.fire({icon:'warning', title:'檔案過大', text:'檔案超過 50MB，請清空選擇或把雲端連結貼在內容區。'});
    }
  });

  //移除現有檔案（與目前檔案同一行靠右）
  rmBtn && rmBtn.addEventListener('click', function(){
    Swal.fire({
      icon:'question',
      title:'確定要移除目前已上傳的檔案嗎？',
      showCancelButton:true,
      cancelButtonText:'取消',
      confirmButtonText:'移除',
      confirmButtonColor:'#dc3545',
      reverseButtons: true,  //交換左右位置：取消在左、移除在右
      focusCancel: true      //預設聚焦「取消」
    }).then((res)=>{ if(res.isConfirmed){ document.getElementById('remove-file-form').submit(); }});
  });
});
</script>
</body>
</html>
