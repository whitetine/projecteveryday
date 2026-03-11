<?php
session_start();
require_once "../includes/pdo.php";

// 處理狀態更新請求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
  header('Content-Type: application/json; charset=utf-8');
  $role_ID = $_SESSION['role_ID'] ?? null;
  $u_ID    = $_SESSION['u_ID'] ?? null;

  if (!$u_ID) {
    echo json_encode(['error' => true, 'msg' => '請先登入'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ✅ 允許的角色：主任(1)、科辦(2)、班導(3)、指導老師(4)、召集人(7)
  $allowedRoles = [1, 2, 3, 4, 7];
  if (!in_array((int)$role_ID, $allowedRoles)) {
    echo json_encode(['error' => true, 'msg' => '此功能僅限主任、科辦、班導、指導老師、召集人使用'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ✅ 只有召集人可以更新狀態
  if ((int)$role_ID !== 7) {
    echo json_encode(['error' => true, 'msg' => '此功能僅限召集人使用'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $sub_ID = isset($_POST['sub_ID']) ? (int)$_POST['sub_ID'] : 0;
  $status = isset($_POST['status']) ? (int)$_POST['status'] : null;

  if ($sub_ID <= 0) {
    echo json_encode(['error' => true, 'msg' => '提交ID無效'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($status === null || !in_array($status, [1, 2], true)) {
    echo json_encode(['error' => true, 'msg' => '狀態值無效（1=通過, 2=退件）'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  try {
    // 檢查該提交是否存在
    $stmt = $conn->prepare("SELECT sub_ID FROM docsubdata WHERE sub_ID = ?");
    $stmt->execute([$sub_ID]);
    if (!$stmt->fetch()) {
      echo json_encode(['error' => true, 'msg' => '找不到該提交記錄'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    // 更新狀態（包含審核備註）
    $remark = isset($_POST['remark']) ? trim($_POST['remark']) : null;
    if ($remark !== null && $remark !== '') {
      $stmt = $conn->prepare("
        UPDATE docsubdata 
        SET dcsub_status = ?, 
            dc_approved_u_ID = ?, 
            dcsub_approved_d = NOW(),
            dcsub_remark = ?
        WHERE sub_ID = ?
      ");
      $stmt->execute([$status, $u_ID, $remark, $sub_ID]);
    } else {
      $stmt = $conn->prepare("
        UPDATE docsubdata 
        SET dcsub_status = ?, 
            dc_approved_u_ID = ?, 
            dcsub_approved_d = NOW()
        WHERE sub_ID = ?
      ");
      $stmt->execute([$status, $u_ID, $sub_ID]);
    }

    $statusText = ($status === 1) ? '通過' : '退件';
    
    echo json_encode([
      'success' => true, 
      'msg' => '狀態已更新',
      'status' => $status,
      'status_text' => $statusText
    ], JSON_UNESCAPED_UNICODE);
    exit;

  } catch (Throwable $e) {
    error_log('submission_view.php update_status error: ' . $e->getMessage());
    echo json_encode(['error' => true, 'msg' => '更新失敗：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

// 如果不是 POST 請求，繼續顯示頁面
header('Content-Type: text/html; charset=utf-8');

// 檢查頁面訪問權限
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

if (!$u_ID) {
  echo '<div class="alert alert-danger">請先登入</div>';
  exit;
}

// ✅ 允許的角色：主任(1)、科辦(2)、班導(3)、指導老師(4)、召集人(7)
$allowedRoles = [1, 2, 3, 4, 7];
if (!in_array((int)$role_ID, $allowedRoles)) {
  echo '<div class="alert alert-danger">此頁面僅限主任、科辦、班導、指導老師、召集人使用</div>';
  exit;
}

$cohort_ID = $_SESSION['cohort_ID'] ?? 3;

// 列顯示控制：
// - 指導老師頁面 (role=4)：隱藏「指導老師」欄（因為使用者即為指導老師）
// - 班導師頁面 (role=3)：隱藏「班級」欄（因為班導只會看到自己班級）並隱藏「班導」欄
$showTutorCol   = ((int)$role_ID !== 3);
$showTeacherCol = ((int)$role_ID !== 4);
$showClassCol   = ((int)$role_ID !== 3); 
$baseCols = 5; // 文件名稱、團隊名稱、組員、繳交時間、操作
$colCount = $baseCols + ($showTutorCol ? 1 : 0) + ($showTeacherCol ? 1 : 0) + ($showClassCol ? 1 : 0);

// 與 form_manage.php 一致：只列出「啟用」文件（同一張表 document_forms、同一狀態欄位 doc_status=1）
$sql = "
  SELECT doc_ID, doc_name
  FROM document_forms
  WHERE doc_status = 1
  ORDER BY doc_created_d DESC, doc_ID ASC
";
$stmt = $conn->prepare($sql);
$stmt->execute();
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$docIds = array_column($docs, 'doc_ID');
$requestDocID = isset($_GET['doc_id']) ? (int)$_GET['doc_id'] : (isset($_GET['doc_ID']) ? (int)$_GET['doc_ID'] : 0);
$docIdValid = ($requestDocID > 0 && in_array($requestDocID, $docIds));
$defaultDocID = $docIdValid ? $requestDocID : ($docs[0]['doc_ID'] ?? '');
$docIdInvalidMessage = ($requestDocID > 0 && !$docIdValid) ? '此文件目前未開放查看繳交狀況' : '';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8" />
  <title>繳交狀態一覽</title>
  <link rel="stylesheet" href="css/submission_view.css">
  <style>
    /* 確保 SweetAlert 退件備註視窗永遠顯示在預覽視窗之上，不會被 PDF 覆蓋 */
    .swal2-container {
      z-index: 11000 !important;
    }
    /* 確保退件原因視窗顯示在預覽視窗 (z-index: 10005) 之上 */
    #submissionRejectReasonModal {
      z-index: 10500 !important;
    }
  </style>
</head>
<body>

<div class="wrap">

  <div class="toolbar">
    <div class="toolbar-left">
      <label for="itemSelect">選擇文件：</label>

      <select id="itemSelect">
        <?php if (empty($docs)): ?>
          <option value="">（此梯次尚未設定文件）</option>
        <?php else: ?>
          <?php foreach ($docs as $d): ?>
            <option value="<?= (int)$d['doc_ID'] ?>" <?= ($d['doc_ID']==$defaultDocID ? 'selected' : '') ?>>
              <?= htmlspecialchars($d['doc_name']) ?>
            </option>
          <?php endforeach; ?>
        <?php endif; ?>
      </select>

      <!-- 篩選：下拉選單 -->
      <span class="filter-box">
        <label for="filterStatus">篩選狀態：</label>
        <select id="filterStatus" style="padding: 4px 8px; font-size: 15px;">
          <option value="all" selected>全部</option>
          <option value="submitted">已繳交</option>
          <option value="not_submitted">未繳交</option>
        </select>
      </span>
    </div>

    <div class="toolbar-right"></div>
  </div>

  <div class="table-box">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:13%;">文件名稱</th>
          <th style="width:13%;">團隊名稱</th>
          <th style="width:18%;">組員</th>
          <?php if ($showTeacherCol): ?>
            <th style="width:12%;">指導老師</th>
          <?php endif; ?>
          <?php if ($showTutorCol): ?>
            <th style="width:10%;">班導</th>
          <?php endif; ?>
          <?php if ($showClassCol): ?>
            <th style="width:5%;">班級</th>
          <?php endif; ?>
          <th style="width:13%;">繳交時間</th>
          <th style="width:6%;">操作</th>
        </tr>
      </thead>

      <tbody id="tableBody"></tbody>
    </table>
  </div>

</div>

<!-- 單一 PDF 預覽視窗（點「查看」直接顯示，不跳頁，樣式與資料審核頁相同） -->
<div class="modal fade" id="submissionPreviewModal" data-bs-backdrop="false" tabindex="-1"
  aria-labelledby="submissionPreviewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: 1000px; width: 85%;">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="submissionPreviewModalLabel">申請內容預覽</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
      </div>
      <div class="modal-body" style="height: 65vh; max-height: 70vh; padding: 0;">
        <iframe id="submissionPreviewFrame" title="申請內容 PDF" style="width: 100%; height: 100%; border: none; display: block;"></iframe>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
        <button type="button" class="btn btn-danger d-none" id="submissionBtnReject">退件</button>
        <button type="button" class="btn btn-success d-none" id="submissionBtnApprove">通過</button>
      </div>
    </div>
  </div>
</div>

<!-- 收件狀況頁面的退件原因輸入窗 -->
<div class="modal fade" id="submissionRejectReasonModal" tabindex="-1" aria-labelledby="submissionRejectReasonLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 480px;">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="submissionRejectReasonLabel">退件原因</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
      </div>
      <div class="modal-body">
        <label for="submissionRejectReasonTextarea" class="form-label">請輸入退件原因：</label>
        <textarea id="submissionRejectReasonTextarea" class="form-control" rows="4"
          placeholder="例如：內容未填寫完整、請補充說明某一欄位…"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" id="btnSubmissionRejectReasonConfirm">送出退件</button>
      </div>
    </div>
  </div>
</div>

<script>
  window.__COHORT_ID__ = <?= (int)$cohort_ID ?>;
  window.__DOC_ID_INVALID_MSG__ = <?= json_encode($docIdInvalidMessage, JSON_UNESCAPED_UNICODE) ?>;
  window.__SHOW_TUTOR_COL__ = <?= $showTutorCol ? 'true' : 'false' ?>;
  window.__SHOW_TEACHER_COL__ = <?= $showTeacherCol ? 'true' : 'false' ?>;
  window.__SHOW_CLASS_COL__ = <?= $showClassCol ? 'true' : 'false' ?>;
  window.__TABLE_COLSPAN__ = <?= (int)$colCount ?>;
  window.__ROLE_ID__ = <?= (int)$role_ID ?>;
</script>

<script>
(function () {
  // hash/動態載入頁面時，script tag 常常不會執行，用動態載入最穩
  function loadScript(src, cb){
    if (document.querySelector('script[data-src="' + src + '"]')) {
      cb && cb();
      return;
    }
    var s = document.createElement("script");
    s.src = src;
    s.setAttribute("data-src", src);
    s.onload = function(){ cb && cb(); };
    s.onerror = function(){ console.error("載入失敗：", src); };
    document.body.appendChild(s);
  }

  // 頁面內容插入後才初始化：動態載入時 DOM 已就緒，用 setTimeout(0) 確保插入完成後執行
  loadScript("js/submission_view.js?v=20260310", function(){
    setTimeout(function(){
      if (window.initSubmissionView) window.initSubmissionView();
      else console.error("initSubmissionView 不存在（submission_view.js 可能沒載到）");
    }, 0);
  });
})();
</script>

</body>
</html>
