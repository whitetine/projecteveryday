<?php
session_start();
require '../includes/pdo.php';
date_default_timezone_set('Asia/Taipei');

// 若以網址直接開啟本檔（非經 main.php AJAX 載入），自動導回主框架，保留科辦側邊欄
if (
  $_SERVER['REQUEST_METHOD'] === 'GET'
  && (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest')
) {
  $redirect = '../main.php#pages/apply_preview_list.php';
  header('Location: ' . $redirect);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = $_POST['apply_ID'] ?? null;
  $action = $_POST['action'] ?? null;
  $isAjax = ($_POST['ajax'] ?? '') === '1';

  if ($id && in_array($action, ['approve', 'reject', 'cancel_approve'], true)) {
    try {
      $u_ID = $_SESSION['u_ID'] ?? 0;

      if ($action === 'cancel_approve') {
        // 取消通過：清除審核人和審核時間，改回待審核
        $stmt = $conn->prepare("
                    UPDATE document_submissions
                    SET dc_approved_u_ID = NULL, dcsub_approved_d = NULL, dcsub_remark = NULL
                    WHERE sub_ID = ?
                ");
        $stmt->execute([$id]);
        $status = 0;
        $statusText = '待審核';
      } elseif ($action === 'approve') {
        // 通過：設定審核人和審核時間
        $stmt = $conn->prepare("
                    UPDATE document_submissions
                    SET dc_approved_u_ID = ?, dcsub_approved_d = NOW(), dcsub_remark = NULL
                    WHERE sub_ID = ?
                ");
        $stmt->execute([$u_ID, $id]);
        $status = 1;
        $statusText = '已通過';
      } else { // rejectF
        // 退件：清除審核時間，並在備註中標記為退件＋原因
        $reason = trim($_POST['reject_reason'] ?? '');
        $remark = 'REJECTED' . ($reason !== '' ? '：' . $reason : '');
        $stmt = $conn->prepare("
                    UPDATE document_submissions
                    SET dc_approved_u_ID = ?, dcsub_approved_d = NULL, dcsub_remark = ?
                    WHERE sub_ID = ?
                ");
        $stmt->execute([$u_ID, $remark, $id]);
        $status = 2;
        $statusText = '退件';
      }

      if ($isAjax) {
        echo json_encode(['ok' => true, 'new_status' => $status, 'status_text' => $statusText], JSON_UNESCAPED_UNICODE);
        exit;
      }
      header("Location: apply_preview_list.php");
      exit;
    } catch (Throwable $e) {
      if ($isAjax) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
  }
  if ($isAjax) {
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

try {
  $per = 10;
  $page = max(1, (int) ($_GET['page'] ?? 1));
  $offset = ($page - 1) * $per;

  // 科辦端列表：只顯示「學生已正式送出」的申請
  // 規則：必須為 submitted 狀態 (或 1)，且有實際提交時間 dcsub_sub_d
  // 同時排除 dcsub_u_ID=0 的幽靈紀錄（僅顯示有效申請人）
  $whereSubmitted = "(s.dcsub_status IN ('submitted', 1) AND s.dcsub_sub_d IS NOT NULL)";
  $countSql = "SELECT COUNT(*) FROM document_submissions s
                 INNER JOIN document_forms f ON s.doc_ID = f.doc_ID
                 LEFT JOIN userdata u ON s.dcsub_u_ID = u.u_ID
                 LEFT JOIN teammember tm ON tm.team_u_ID = s.dcsub_u_ID AND tm.tm_status = 1
                 LEFT JOIN teamdata td ON td.team_ID = tm.team_ID
                 WHERE s.dcsub_u_ID > 0 AND " . $whereSubmitted;
  $total = (int) $conn->query($countSql)->fetchColumn();
  $pages = max(1, (int) ceil($total / $per));
  $page = min($page, $pages);

  $sql = "SELECT s.sub_ID, s.doc_ID, s.dcsub_u_ID, s.dcsub_status, s.dcsub_sub_d, s.dcsub_answers, s.attach_path,
                   s.dcsub_approved_d, s.dc_approved_u_ID, s.dcsub_remark,
                   f.doc_name,
                   u.u_name AS apply_user,
                   td.team_project_name AS team_name
            FROM document_submissions s
            INNER JOIN document_forms f ON s.doc_ID = f.doc_ID
            LEFT JOIN userdata u ON s.dcsub_u_ID = u.u_ID
            LEFT JOIN teammember tm ON tm.team_u_ID = s.dcsub_u_ID AND tm.tm_status = 1
            LEFT JOIN teamdata td ON td.team_ID = tm.team_ID
            WHERE s.dcsub_u_ID > 0 AND " . $whereSubmitted . "
            ORDER BY (CASE 
                WHEN s.dcsub_approved_d IS NULL AND (s.dcsub_remark IS NULL OR s.dcsub_remark != 'REJECTED') THEN 0
                WHEN s.dcsub_approved_d IS NOT NULL THEN 1
                ELSE 2
            END), COALESCE(s.dcsub_sub_d, '1970-01-01') DESC, s.sub_ID DESC
            LIMIT " . intval($per) . " OFFSET " . intval($offset);
  $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  $fileTypes = $conn->query("SELECT doc_ID as file_ID, doc_name as file_name FROM document_forms WHERE doc_status = 1")
    ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  http_response_code(500);
  error_log("apply_preview_list.php DB error: " . $e->getMessage());
  die("DB error: " . htmlspecialchars($e->getMessage()) . " (Line: " . $e->getLine() . ")");
} catch (Throwable $e) {
  http_response_code(500);
  error_log("apply_preview_list.php error: " . $e->getMessage());
  die("Error: " . htmlspecialchars($e->getMessage()) . " (Line: " . $e->getLine() . ")");
}
?>
<meta charset="UTF-8">
<title>申請審核列表</title>

<style>
  .page .page-header {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 16px;
    margin: 0 0 24px 0 !important;
    padding: 1.5rem 0 !important;
    background: transparent !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    border-bottom: 3px solid #ffc107 !important;
    width: 100%;
    max-width: 100%;
  }

  .page .page-header h1 {
    margin: 0 !important;
    font-size: 2rem !important;
    font-weight: 700 !important;
    color: #2c3e50 !important;
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .page {
    padding: 0 30px 30px 30px !important;
    box-sizing: border-box;
    min-width: 900px;
  }

  .pager-bar {
    background: #f4a46022 !important;
    border-top: 2px solid #f4a460 !important;
    padding: 6px 10px !important;
    text-align: center !important;
    margin-top: 0 !important;
    display: block !important;
    width: 100% !important;
  }

  .pager-bar a,
  .pager-bar span {
    display: inline-block;
    padding: 2px 6px;
    margin: 0 2px;
    text-decoration: none;
    color: #444;
    border-radius: 3px;
    font-size: 14px;
  }

  .pager-bar a:hover {
    background: #ffe2c2;
  }

  .pager-bar .active {
    background: #f4a460;
    color: #fff;
    font-weight: 700;
  }

  .pager-bar .disabled {
    color: #aaa;
    pointer-events: none;
  }
</style>

<div class="page">
  <div class="page-header mb-4">
    <h1 class="mb-0 d-flex align-items-center">
      <i class="fa-solid fa-clipboard-check me-3" style="color: #ffc107;"></i>
      資料審核
    </h1>
  </div>

  <div class="card mb-4">
    <div class="card-header">
      <h5 class="mb-0">查詢</h5>
    </div>
    <div class="card-body">
      <div class="filters d-flex align-items-center gap-2 flex-nowrap">
        <input id="searchBox" class="form-control flex-grow-1 min-w-0" type="search" placeholder="🔍 搜尋表單或申請人..." />
        <select id="statusFilter" class="form-select flex-shrink-0" style="width:10%;">
          <option value="all">全部狀態</option>
          <option>待審核</option>
          <option>已通過</option>
          <option>退件</option>
        </select>
        <select id="typeFilter" class="form-select flex-shrink-0" style="width:20%;">
          <option value="all">全部表單類型</option>
          <?php foreach ($fileTypes as $f): ?>
            <option value="<?= htmlspecialchars((string) $f['file_ID'], ENT_QUOTES) ?>">
              <?= htmlspecialchars($f['file_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 text-center table-clean" id="applyTable">
          <thead>
            <tr>
              <th>表單名稱</th>
              <th>申請人</th>
              <th>提交時間</th>
              <th>查看</th>
              <th>狀態</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($total === 0): ?>
              <tr>
                <td colspan="5" class="text-muted py-4">目前沒有已提交的申請；學生繳交後會顯示於此列表。</td>
              </tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
              // 根據 dcsub_approved_d 和 dcsub_remark 判斷狀態
              $approved_d = $r['dcsub_approved_d'] ?? null;
              $remark = $r['dcsub_remark'] ?? null;
              $isRejected = ($remark !== null && $remark !== '' && strpos((string)$remark, 'REJECT') === 0);
              $isApproved = !empty($approved_d);

              if ($isRejected) {
                $st = 2;
                $statusText = '退件';
              } elseif ($isApproved) {
                $st = 1;
                $statusText = '已通過';
              } else {
                $st = 0;
                $statusText = '待審核';
              }

              $docId = (int) $r['doc_ID'];
              $subId = (int) $r['sub_ID'];
              $teamName = trim((string)($r['team_name'] ?? ''));
              ?>
              <tr data-fileid="<?= htmlspecialchars((string) ($r['doc_ID'] ?? ''), ENT_QUOTES) ?>"
                data-applicant="<?= htmlspecialchars($teamName !== '' ? $teamName : ($r['apply_user'] ?? $r['dcsub_u_ID'] ?? ''), ENT_QUOTES) ?>"
                data-record-id="<?= (int) $r['sub_ID'] ?>">
                <td><?= htmlspecialchars($r['doc_name'] ?? '') ?></td>
                <td class="applicant-cell">
                  <?= htmlspecialchars($teamName !== '' ? $teamName : ($r['apply_user'] ?? $r['dcsub_u_ID'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td><?= htmlspecialchars($r['dcsub_sub_d'] ?? '') ?></td>
                <td>
                  <div class="d-flex justify-content-center gap-2 flex-wrap">
                    <a href="#" data-doc-id="<?= $docId ?>" data-sub-id="<?= $subId ?>"
                      class="btn btn-sm btn-outline-primary btn-preview-pdf">
                      <i class="fa fa-file-pdf"></i> 查看
                    </a>
                    <?php if ($st === 0): ?>
                      <button type="button" class="btn btn-sm btn-outline-warning btn-remind-teacher"
                        data-doc-id="<?= $docId ?>" data-sub-id="<?= $subId ?>">
                        <i class="fa-solid fa-bell"></i> 提醒老師
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="status-cell"><?= $statusText ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($total > 0): ?>
        <div class="pager-bar" id="applyPagerBar">
          <?php if ($pages > 1): ?>
            <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">&laquo;</a><?php else: ?><span
                class="disabled">&laquo;</span><?php endif; ?>
            <?php for ($i = 1; $i <= $pages; $i++): ?>
              <?php if ($i === $page): ?><span class="active"><?= $i ?></span><?php else: ?><a
                  href="?page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $pages): ?><a href="?page=<?= $page + 1 ?>">&raquo;</a><?php else: ?><span
                class="disabled">&raquo;</span><?php endif; ?>
          <?php else: ?>
            <span class="active">1</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- 科辦查看學生繳交：單一預覽視窗 + 通過／退件按鈕（不遮蔽背景） -->
<div class="modal fade" id="previewPdfModal" data-bs-backdrop="false" tabindex="-1"
  aria-labelledby="previewPdfModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: 1000px; width: 85%;">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="previewPdfModalLabel">申請內容預覽</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
      </div>
      <div class="modal-body" style="height: 65vh; max-height: 70vh; padding: 0;">
        <iframe id="previewPdfFrame" title="申請內容 PDF" style="width: 100%; height: 100%; border: none; display: block;"></iframe>
              </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
        <button type="button" class="btn btn-danger" id="btnRejectFromModal">退件</button>
        <button type="button" class="btn btn-success" id="btnApproveFromModal">通過</button>
              </div>
            </div>
          </div>
        </div>

<!-- 科辦退件原因輸入窗 -->
<div class="modal fade" id="rejectReasonModal" tabindex="-1" aria-labelledby="rejectReasonLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 480px;">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="rejectReasonLabel">退件原因</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
          </div>
      <div class="modal-body">
        <label for="rejectReasonTextarea" class="form-label">請輸入退件原因：</label>
        <textarea id="rejectReasonTextarea" class="form-control" rows="4"
          placeholder="例如：內容未填寫完整、請補充說明某一欄位…"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" id="btnRejectReasonConfirm">送出退件</button>
      </div>
    </div>
  </div>
</div>

<script>
  const APPLY_ENDPOINT = location.pathname.includes('/pages/') ? 'apply_preview_list.php' : 'pages/apply_preview_list.php';
  const previewModal = document.getElementById('previewPdfModal');
  const previewPdfFrame = document.getElementById('previewPdfFrame');
  const previewTitleEl = document.getElementById('previewPdfModalLabel');
  const btnApproveFromModal = document.getElementById('btnApproveFromModal');
  const btnRejectFromModal = document.getElementById('btnRejectFromModal');
  // 若未來需要「取消通過」可再加一顆按鈕
  const btnCancelApproveFromModal = document.getElementById('btnCancelApproveFromModal');
  const rejectReasonModal = document.getElementById('rejectReasonModal');
  const rejectReasonTextarea = document.getElementById('rejectReasonTextarea');
  const btnRejectReasonConfirm = document.getElementById('btnRejectReasonConfirm');
  let currentSubIdInModal = null;

  // 將 Modal 移至 body 避免被 #content 的堆疊上下文編排限制（被導航欄遮擋）
  // 先檢查 body 是否已有舊的同名 modal 並移除，避免重複
  const oldModal = document.querySelector('body > #previewPdfModal');
  if (oldModal && oldModal !== previewModal) {
    oldModal.remove();
  }

  if (previewModal && previewModal.parentElement !== document.body) {
    document.body.appendChild(previewModal);
    // 同時確保其 z-index 高於導航欄 (1030) 與其他元件
    previewModal.style.zIndex = '10005';
  }

  // 將退件原因 Modal 也移至 body 並確保其 z-index 更高
  if (rejectReasonModal && rejectReasonModal.parentElement !== document.body) {
    document.body.appendChild(rejectReasonModal);
    rejectReasonModal.style.zIndex = '10010';
  }

  function updateStatus(id, action, btn) {
    const tr = btn.closest('tr');
    const name = tr.querySelector('.applicant-cell')?.innerText || '';
    // 退件一律走自訂「退件原因」視窗，其餘操作才用一般確認
    if (action === 'reject') {
      currentSubIdInModal = id;
      if (rejectReasonTextarea) rejectReasonTextarea.value = '';
      if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(rejectReasonModal).show();
      } else if (typeof $ !== 'undefined' && $.fn.modal) {
        $(rejectReasonModal).modal('show');
      }
      return;
    }

    Swal.fire({
      title: '確認操作',
      text: `確定將此申請通過？`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: '確定',
      cancelButtonText: '取消',
      reverseButtons: true
    }).then(r => {
      if (!r.isConfirmed) return;
      fetch(APPLY_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `apply_ID=${encodeURIComponent(id)}&action=${encodeURIComponent(action)}&ajax=1`
      })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            tr.querySelector('.status-cell').innerText = data.status_text;
            const remindBtn = tr.querySelector('.btn-remind-teacher');
            if (remindBtn) {
              remindBtn.style.display = (data.status_text === '待審核') ? '' : 'none';
            }
            Swal.fire({ icon: 'success', title: '成功', text: data.status_text, timer: 1500, showConfirmButton: false });
            filterTable();
          } else {
            Swal.fire('失敗', data.msg || '更新失敗', 'error');
          }
        })
        .catch(() => Swal.fire('錯誤', '無法連線', 'error'));
    });
  }

  function cancelApprove(id, btn) {
    const tr = btn.closest('tr');
    Swal.fire({
      title: '確認取消通過',
      text: '確定要取消通過狀態？將改回待審核。',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: '確定',
      cancelButtonText: '取消',
      reverseButtons: true
    }).then(r => {
      if (!r.isConfirmed) return;
      fetch(APPLY_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `apply_ID=${encodeURIComponent(id)}&action=cancel_approve&ajax=1`
      })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            tr.querySelector('.status-cell').innerText = data.status_text;
            const remindBtn = tr.querySelector('.btn-remind-teacher');
            if (remindBtn) {
              remindBtn.style.display = (data.status_text === '待審核') ? '' : 'none';
            }
            Swal.fire({ icon: 'success', title: '成功', text: '已改回待審核', timer: 1500, showConfirmButton: false });
            filterTable();
          } else {
            Swal.fire('失敗', data.msg || '更新失敗', 'error');
          }
        })
        .catch(() => Swal.fire('錯誤', '無法連線', 'error'));
    });
  }

  function filterTable() {
    const kw = (document.getElementById('searchBox').value || '').trim().toLowerCase();
    const st = document.getElementById('statusFilter').value;
    const tp = document.getElementById('typeFilter').value;
    document.querySelectorAll('#applyTable tbody tr').forEach(tr => {
      const statusText = tr.querySelector('.status-cell')?.innerText.trim() || '';
      const fileId = (tr.dataset.fileid || '').trim();
      const applicant = (tr.dataset.applicant || tr.querySelector('.applicant-cell')?.innerText || '').toLowerCase();
      const docName = (tr.cells[0]?.innerText || '').toLowerCase();
      const matchKw = !kw || docName.includes(kw) || applicant.includes(kw);
      const matchSt = (st === 'all') || (statusText === st);
      const matchTp = (tp === 'all') || (fileId === tp);
      tr.style.display = (matchKw && matchSt && matchTp) ? '' : 'none';
    });
  }
  ['searchBox', 'statusFilter', 'typeFilter'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', filterTable);
  });
  ['searchBox', 'statusFilter', 'typeFilter'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', filterTable);
  });
  window.addEventListener('DOMContentLoaded', filterTable);

  function openPreviewModal(docId, subId) {
    if (!docId || !subId || !previewModal || !previewPdfFrame) return;
    currentSubIdInModal = subId;

    const tr = document.querySelector('#applyTable tr[data-record-id="' + subId + '"]');
    const docName = tr ? (tr.cells[0]?.innerText || '') : '';
    const applicant = tr ? (tr.querySelector('.applicant-cell')?.innerText || '') : '';
    const statusText = tr ? (tr.querySelector('.status-cell')?.innerText.trim() || '') : '';

    if (previewTitleEl) {
      let title = docName || '申請內容預覽';
      if (applicant) title += ' - ' + applicant;
      previewTitleEl.textContent = title;
    }

    const base = (location.pathname || '').replace(/\/[^/]*$/, '') || '';
    const prefix = base ? base + '/' : '/';
    const urlOriginal = prefix + 'pages/download_document_form_original_pdf.php?doc_ID=' +
      encodeURIComponent(docId) + '&submission_id=' + encodeURIComponent(subId) + '#zoom=70';
    previewPdfFrame.src = urlOriginal;

    const isPending = statusText === '待審核';
    const isApproved = statusText === '已通過';
    const isRejected = statusText === '退件';

    if (btnApproveFromModal) btnApproveFromModal.style.display = isPending ? '' : 'none';
    if (btnRejectFromModal) btnRejectFromModal.style.display = (isPending || isApproved) ? '' : 'none';
    if (btnCancelApproveFromModal) btnCancelApproveFromModal.style.display = isApproved ? '' : 'none';

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
      const modalInstance = bootstrap.Modal.getOrCreateInstance(previewModal);
      modalInstance.show();
    } else if (typeof $ !== 'undefined' && $.fn.modal) {
      $(previewModal).modal('show');
    } else {
      previewModal.style.display = 'block';
      previewModal.classList.add('show');
    }
  }

  document.addEventListener('click', function (e) {
    const previewBtn = e.target.closest('.btn-preview-pdf');
    if (previewBtn) {
    e.preventDefault();
      const docId = previewBtn.getAttribute('data-doc-id');
      const subId = previewBtn.getAttribute('data-sub-id');
    if (!docId || !subId) return;
      openPreviewModal(docId, subId);
      return;
    }

    const remindBtn = e.target.closest('.btn-remind-teacher');
    if (remindBtn) {
      e.preventDefault();
      const docId = remindBtn.getAttribute('data-doc-id');
      const subId = remindBtn.getAttribute('data-sub-id');
      const tr = remindBtn.closest('tr');
      const docName = tr ? (tr.cells[0]?.innerText || '') : '';
      const teamOrApplicant = tr ? (tr.dataset.applicant || tr.querySelector('.applicant-cell')?.innerText || '') : '';

      // 先做假功能：提示 + console 資訊，後續可串正式通知
      console.log('[提醒老師]', { docId, subId, docName, teamOrApplicant });
      Swal.fire({
        icon: 'success',
        title: '已送出提醒（測試）',
        text: (docName ? docName + ' - ' : '') + (teamOrApplicant || '該組'),
        timer: 2000,
        showConfirmButton: false
      });
      return;
    }
  });

  function handleModalAction(action) {
    var subId = currentSubIdInModal;
    if (!subId) return;
    var tr = document.querySelector('#applyTable tr[data-record-id="' + subId + '"]');
    if (!tr) return;

    var title = '', text = '', icon = '';
    if (action === 'approve') {
      title = '確認通過';
      text = '該文件須由指導老師審核是否通過，請確認是否同意讓該組通過。';
      icon = 'question';
    } else if (action === 'reject') {
      if (rejectReasonTextarea) rejectReasonTextarea.value = '';
      if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(rejectReasonModal).show();
      } else {
        $(rejectReasonModal).modal('show');
      }
      return;
    } else if (action === 'cancel_approve') {
      title = '確認取消通過';
      text = '確定要取消通過狀態？將改回待審核。';
      icon = 'warning';
    } else {
      return;
    }

    Swal.fire({
      title: title,
      text: text,
      icon: icon,
      showCancelButton: true,
      confirmButtonText: '確定',
      cancelButtonText: '取消',
      reverseButtons: true
    }).then(function (r) {
      if (!r.isConfirmed) return;
      fetch(APPLY_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'apply_ID=' + encodeURIComponent(subId) + '&action=' + encodeURIComponent(action) + '&ajax=1'
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.ok) {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
              bootstrap.Modal.getInstance(previewModal).hide();
            } else if (typeof $ !== 'undefined' && $.fn.modal) {
              $(previewModal).modal('hide');
            } else {
              previewModal.style.display = 'none';
              previewModal.classList.remove('show');
            }
            if (tr.querySelector('.status-cell')) {
              tr.querySelector('.status-cell').innerText = data.status_text;
            }
            Swal.fire({ icon: 'success', title: '成功', text: data.status_text, timer: 1500, showConfirmButton: false });
            filterTable();
          } else {
            Swal.fire('失敗', data.msg || '更新失敗', 'error');
          }
        })
        .catch(function () { Swal.fire('錯誤', '無法連線', 'error'); });
    });
  }

  if (btnApproveFromModal) {
    btnApproveFromModal.addEventListener('click', function () {
      handleModalAction('approve');
    });
  }
  if (btnRejectFromModal) {
    btnRejectFromModal.addEventListener('click', function () {
      handleModalAction('reject');
    });
  }
  if (btnCancelApproveFromModal) {
    btnCancelApproveFromModal.addEventListener('click', function () {
      handleModalAction('cancel_approve');
    });
  }

  if (btnRejectReasonConfirm) {
    btnRejectReasonConfirm.addEventListener('click', function () {
      var subId = currentSubIdInModal;
      var reason = (rejectReasonTextarea.value || '').trim();
      var tr = document.querySelector('#applyTable tr[data-record-id="' + subId + '"]');

      fetch(APPLY_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'apply_ID=' + encodeURIComponent(subId) + '&action=reject&reject_reason=' + encodeURIComponent(reason) + '&ajax=1'
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.ok) {
            // 隱藏兩個 Modal
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
              bootstrap.Modal.getInstance(rejectReasonModal).hide();
              bootstrap.Modal.getInstance(previewModal).hide();
            } else {
              $(rejectReasonModal).modal('hide');
              $(previewModal).modal('hide');
            }

            if (tr && tr.querySelector('.status-cell')) {
              tr.querySelector('.status-cell').innerText = data.status_text;
            }
            Swal.fire({ icon: 'success', title: '已退件', text: data.status_text, timer: 1500, showConfirmButton: false });
            filterTable();
          } else {
            Swal.fire('失敗', data.msg || '更新失敗', 'error');
          }
        })
        .catch(function () { Swal.fire('錯誤', '無法連線', 'error'); });
    });
  }

  // 從 submission_view（收件狀況）點「查看」連過來時，URL 帶 doc_id 與 sub_id，直接自動開啟預覽視窗。
  // ✅ 只在首次載入時開啟一次，開啟後會把 hash 改回純頁面路徑，避免重新整理又自動跳出。
  (function () {
    var loc = (window.top && window.top.location) ? window.top.location : window.location;
    var hash = (loc.hash || '').slice(1);
    var q = hash.indexOf('?');
    if (q < 0) return;
    var params = hash.slice(q + 1).split('&');
    var docId = null, subId = null;
    for (var i = 0; i < params.length; i++) {
      var p = params[i].split('=');
      if (p[0] === 'doc_id' && p[1]) docId = decodeURIComponent(p[1].replace(/\+/g, ' '));
      if (p[0] === 'sub_id' && p[1]) subId = decodeURIComponent(p[1].replace(/\+/g, ' '));
    }
    if (docId && subId) {
      setTimeout(function () {
        openPreviewModal(docId, subId);
        // 開啟後清除 doc_id / sub_id 參數，避免 F5 一直自動彈出
        try {
          if (typeof history !== 'undefined' && history.replaceState) {
            var base = loc.pathname + (loc.search || '');
            history.replaceState(null, '', base + '#pages/apply_preview_list.php');
          } else {
            loc.hash = '#pages/apply_preview_list.php';
          }
        } catch (e) { /* ignore */ }
      }, 50);
    }
  })();
</script>