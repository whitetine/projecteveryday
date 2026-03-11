<?php
session_start();
require '../includes/pdo.php'; // 取得 $conn (PDO)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id     = $_POST['apply_ID'] ?? null;
  $action = $_POST['action'] ?? null;
  $isAjax = ($_POST['ajax'] ?? '') === '1';

  if ($id && in_array($action, ['approve', 'reject', 'cancel_approve'], true)) {
    if ($action === 'cancel_approve') {
      // 取消通過：將狀態改回待審核，清除審核人和審核時間
      $status = 0; // 0=待審核
      $stmt = $conn->prepare(
        "UPDATE docsubdata 
         SET dcsub_status = ?, dc_approved_u_ID = NULL, dcsub_approved_d = NULL
         WHERE sub_ID = ?"
      );
      $stmt->execute([$status, $id]);
      $statusText = '待審核';
    } else {
      $status = ($action === 'approve') ? 1 : 2; // 1=已通過, 2=退件；0=待審
      $stmt = $conn->prepare(
        "UPDATE docsubdata 
         SET dcsub_status = ?, dc_approved_u_ID = ?, dcsub_approved_d = NOW()
         WHERE sub_ID = ?"
      );
      $stmt->execute([$status, $_SESSION['u_ID'] ?? 0, $id]);
      $statusText = ($status === 1 ? '已通過' : '退件');
    }

    if ($isAjax) {
      echo json_encode(['ok' => true, 'new_status' => $status, 'status_text' => $statusText], JSON_UNESCAPED_UNICODE);
      exit;
    }
    header("Location: apply_preview.php");
    exit;
  }
  if ($isAjax) {
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

try {
  // 分頁參數
  $per = 10; // 每頁顯示數量
  $page = max(1, (int)($_GET['page'] ?? 1));
  $offset = ($page - 1) * $per;

  // 先獲取總數
  $countSql = "SELECT COUNT(*) 
                FROM docsubdata s
                LEFT JOIN docdata f ON s.doc_ID = f.doc_ID
                LEFT JOIN userdata u ON s.dcsub_u_ID = u.u_ID";
  $total = (int)$conn->query($countSql)->fetchColumn();
  
  // 計算總頁數
  $pages = max(1, (int)ceil($total / $per));
  $page = min($page, $pages); // 確保頁碼不超過總頁數
  
  // 查詢資料（含分頁）
  $sql  = "SELECT s.*, f.doc_name, u.u_name AS apply_user, f.doc_ID as file_ID
              FROM docsubdata s
              LEFT JOIN docdata f ON s.doc_ID = f.doc_ID
              LEFT JOIN userdata u ON s.dcsub_u_ID = u.u_ID
              ORDER BY s.dcsub_status ASC, s.dcsub_sub_d DESC
              LIMIT " . intval($per) . " OFFSET " . intval($offset);
  $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  $fileTypes = $conn->query("SELECT doc_ID as file_ID, doc_name as file_name FROM docdata WHERE doc_status = 1")
    ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  http_response_code(500);
  die("DB error: " . htmlspecialchars($e->getMessage()));
}
?>


<meta charset="UTF-8">
<title>申請審核列表</title>

<style>
  /* 恢復原本的簡單標題樣式，不使用紫色漸變卡片 */
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
    position: relative;
    width: 100%;
    max-width: 100%;
  }

  .page .page-header h1 {
    margin: 0 !important;
    font-size: 2.5rem !important;
    font-weight: 700 !important;
    color: #2c3e50 !important;
    text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1) !important;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  /* 版面防跑版：統一內距與最小寬度 */
  .page {
    padding: 0 30px 30px 30px !important;
    box-sizing: border-box;
    min-width: 1200px;
    max-width: 100%;
  }


  .fixed-thumb:hover {
    transform: scale(1.05);
    transition: transform 0.2s;
  }
  
  /* 圖片放大 modal */
  #imgModal {
    display: none;
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.9);
    backdrop-filter: blur(4px);
    cursor: pointer;
    align-items: center;
    justify-content: center;
    flex-direction: column;
  }
  
  .modal-content-wrapper {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    max-width: 90%;
    max-height: 90vh;
  }
  
  #imgModal img {
    margin: 0 auto;
    display: block;
    width: auto;
    max-width: 100%;
    max-height: calc(90vh - 120px);
    object-fit: contain;
    animation: zoomIn 0.3s;
    cursor: default;
    pointer-events: auto;
  }
  
  /* 操作按鈕區域樣式 */
  .modal-actions {
    pointer-events: auto;
    margin-top: 20px;
    display: flex;
    flex-direction: row;
    gap: 12px;
    justify-content: center;
    align-items: center;
    width: 100%;
  }
  
  .modal-actions .btn {
    min-width: 120px;
    padding: 12px 24px;
    font-size: 16px;
    font-weight: 600;
    border-radius: 8px;
    transition: all 0.3s ease;
    border: none;
  }
  
  .modal-actions .btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  }
  
  .modal-actions .btn-success {
    background-color: #28a745;
    color: white;
  }
  
  .modal-actions .btn-danger {
    background-color: #dc3545;
    color: white;
  }
  
  .modal-actions .btn-warning {
    background-color: #ffc107;
    color: #212529;
  }
  
  @keyframes zoomIn {
    from {
      transform: scale(0.8);
      opacity: 0;
    }
    to {
      transform: scale(1);
      opacity: 1;
    }
  }
  
  /* 分頁器樣式 */
  .pager-bar {
    background: #f4a46022 !important;
    border-top: 2px solid #f4a460 !important;
    padding: 6px 10px !important;
    text-align: center !important;
    margin-top: 0 !important;
    display: block !important;
    width: 100% !important;
    box-sizing: border-box !important;
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
      <i class="fa-solid fa-file-lines me-3" style="color: #ffc107;"></i>
      文件審核列表
    </h1>
  </div>

  <div class="card mb-4">
    <div class="card-header">
      <h5 class="mb-0">查詢</h5>

    </div>
    <div class="card-body">

      <div class="container">
        <!-- 篩選工具列 -->
        <div class="filters d-flex align-items-center gap-2 flex-nowrap">
          <input
            id="searchBox"
            class="form-control flex-grow-1 min-w-0"
            type="search"
            placeholder="🔍 搜尋文件或申請人..." />

          <select id="statusFilter" class="form-select flex-shrink-0" style="width:10%;">
            <option value="all">全部狀態</option>
            <option>待審核</option>
            <option>已通過</option>
            <option>退件</option>
          </select>

          <select id="typeFilter" class="form-select flex-shrink-0" style="width:16%;">
            <option value="all">全部表單類型</option>
            <?php foreach ($fileTypes as $f): ?>
              <option value="<?= htmlspecialchars($f['file_ID'], ENT_QUOTES) ?>">
                <?= htmlspecialchars($f['file_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

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
              <th>備註</th>
              <th>申請人</th>
              <th>時間</th>
              <th>檔案</th>
              <th>狀態</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr
                data-fileid="<?= htmlspecialchars((string)($r['doc_ID'] ?? ''), ENT_QUOTES) ?>"
                data-filename="<?= htmlspecialchars($r['dcsub_comment'] ?? '', ENT_QUOTES) ?>"
                data-applicant="<?= htmlspecialchars($r['apply_user'] ?? '', ENT_QUOTES) ?>"
                data-record-id="<?= (int)$r['sub_ID'] ?>">

                <td><?= htmlspecialchars($r['doc_name'] ?? '') ?></td>
                <td class="filename-cell"><?= htmlspecialchars($r['dcsub_comment'] ?? '') ?></td>

                <td class="applicant-cell">
                <?=htmlspecialchars($r['apply_user'] ?? ($r['dcsub_u_ID'] ?? ''))?>
                </td>

                <td><?= htmlspecialchars($r['dcsub_sub_d'] ?? '') ?></td>

                <td>
                  <?php if (!empty($r['dcsub_url']) && preg_match('/\.(jpg|jpeg|png)$/i', $r['dcsub_url'])): ?>
                    <button type="button" 
                            class="btn btn-sm btn-outline-primary"
                            onclick="showModal('<?= htmlspecialchars($r['dcsub_url'], ENT_QUOTES) ?>', <?= (int)$r['sub_ID'] ?>, <?= (int)$r['dcsub_status'] ?>, '<?= htmlspecialchars($r['dcsub_comment'] ?? '', ENT_QUOTES) ?>')">
                      <i class="fa fa-eye"></i> 查看圖檔
                    </button>
                  <?php elseif (!empty($r['dcsub_url'])): ?>
                    <a href="<?= htmlspecialchars($r['dcsub_url']) ?>" target="_blank">檔案</a>
                  <?php else: ?>
                    無
                  <?php endif; ?>
                </td>

                <td class="status-cell">
                  <?= ((int)$r['dcsub_status'] === 0 ? '待審核' : ((int)$r['dcsub_status'] === 2 ? '退件' : '已通過')) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
      <!-- 分頁器 -->
      <?php if ($total > 0): ?>
      <div class="pager-bar" id="applyPagerBar">
        <?php if ($pages > 1): ?>
          <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>">&laquo;</a>
          <?php else: ?>
            <span class="disabled">&laquo;</span>
          <?php endif; ?>
          
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <?php if ($i === $page): ?>
              <span class="active"><?= $i ?></span>
            <?php else: ?>
              <a href="?page=<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          
          <?php if ($page < $pages): ?>
            <a href="?page=<?= $page + 1 ?>">&raquo;</a>
          <?php else: ?>
            <span class="disabled">&raquo;</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="active">1</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
  </div>
</div>



<!-- 圖片放大 modal -->
<div id="imgModal" class="modal" onclick="closeModal()">
  <div class="modal-content-wrapper" onclick="event.stopPropagation();">
    <img id="modalImg">
    <!-- 操作按鈕區域 -->
    <div id="modalActions" class="modal-actions" onclick="event.stopPropagation();">
      <!-- 按鈕將由 JavaScript 動態生成 -->
    </div>
  </div>
</div>


<script>
  // 圖片放大（帶操作按鈕）
  function showModal(src, recordId, status, fileName){ 
    const modalImg = document.getElementById('modalImg');
    const imgModal = document.getElementById('imgModal');
    const modalActions = document.getElementById('modalActions');
    
    if (modalImg && imgModal) {
      modalImg.src = src; 
      imgModal.style.display = 'flex';
      
      // 儲存當前記錄資訊
      imgModal.dataset.recordId = recordId;
      imgModal.dataset.status = status;
      imgModal.dataset.fileName = fileName || '';
      
      // 根據狀態顯示操作按鈕
      if (modalActions) {
        if (status === 0) {
          // 待審核：顯示通過和退件按鈕
          modalActions.innerHTML = `
            <button class="btn btn-success" onclick="handleModalAction('approve')">
              <i class="fa fa-check"></i> 通過
            </button>
            <button class="btn btn-danger" onclick="handleModalAction('reject')">
              <i class="fa fa-times"></i> 退件
            </button>
          `;
        } else if (status === 1) {
          // 已通過：顯示取消通過按鈕
          modalActions.innerHTML = `
            <button class="btn btn-warning" onclick="handleModalAction('cancel_approve')">
              <i class="fa fa-undo"></i> 取消通過
            </button>
          `;
        } else {
          // 退件：不顯示按鈕
          modalActions.innerHTML = '';
        }
      }
    }
  }
  
  // 處理 modal 中的操作
  function handleModalAction(action) {
    const imgModal = document.getElementById('imgModal');
    if (!imgModal) return;
    
    const recordId = imgModal.dataset.recordId;
    const fileName = imgModal.dataset.fileName || '';
    
    if (!recordId) return;
    
    // 根據操作類型顯示確認對話框
    let confirmText = '';
    let confirmIcon = 'question';
    
    if (action === 'approve') {
      confirmText = `確定將「${fileName}」通過？`;
      confirmIcon = 'question';
    } else if (action === 'reject') {
      confirmText = `確定將「${fileName}」退件？`;
      confirmIcon = 'warning';
    } else if (action === 'cancel_approve') {
      confirmText = `確定要取消「${fileName}」的通過狀態？將改回待審核狀態。`;
      confirmIcon = 'warning';
    }
    
    Swal.fire({
      title: action === 'cancel_approve' ? '確認取消通過' : '確認操作',
      text: confirmText,
      icon: confirmIcon,
      showCancelButton: true,
      confirmButtonText: '確定',
      cancelButtonText: '取消',
      reverseButtons: true
    }).then(r => {
      if (!r.isConfirmed) return;
      
      // 執行操作
      fetch(APPLY_ENDPOINT, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `apply_ID=${encodeURIComponent(recordId)}&action=${encodeURIComponent(action)}&ajax=1`
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          // 更新列表中的狀態
          const tr = document.querySelector(`tr[data-record-id="${recordId}"]`);
          
          if (tr) {
            tr.querySelector('.status-cell').innerText = data.status_text;
          }
          
          // 更新 modal 中的按鈕
          if (data.new_status === 0) {
            const modalActions = document.getElementById('modalActions');
            if (modalActions) {
              modalActions.innerHTML = `
                <button class="btn btn-success" onclick="handleModalAction('approve')">
                  <i class="fa fa-check"></i> 通過
                </button>
                <button class="btn btn-danger" onclick="handleModalAction('reject')">
                  <i class="fa fa-times"></i> 退件
                </button>
              `;
            }
            imgModal.dataset.status = '0';
          } else if (data.new_status === 1) {
            const modalActions = document.getElementById('modalActions');
            if (modalActions) {
              modalActions.innerHTML = `
                <button class="btn btn-warning" onclick="handleModalAction('cancel_approve')">
                  <i class="fa fa-undo"></i> 取消通過
                </button>
              `;
            }
            imgModal.dataset.status = '1';
          } else {
            const modalActions = document.getElementById('modalActions');
            if (modalActions) {
              modalActions.innerHTML = '';
            }
            imgModal.dataset.status = '2';
          }
          
          Swal.fire('成功', `${fileName}${data.status_text}`, 'success');
          reorderTable();
          filterTable();
        } else {
          Swal.fire('失敗', '更新失敗', 'error');
        }
      })
      .catch(() => Swal.fire('錯誤', '無法連線', 'error'));
    });
  }
  
  function closeModal(){ 
    const imgModal = document.getElementById('imgModal');
    if (imgModal) {
      imgModal.style.display = 'none'; 
    }
  }

  // 搜尋＋篩選：只比對「文件名稱」與「申請人」，避免雜訊
  function filterTable(){
    const kw = document.getElementById('searchBox').value.trim().toLowerCase();
    const st = document.getElementById('statusFilter').value;
    const tp = document.getElementById('typeFilter').value;

    document.querySelectorAll('#applyTable tbody tr').forEach(tr => {
      const statusText = tr.querySelector('.status-cell')?.innerText.trim() || '';
      const fileId     = (tr.dataset.fileid || '').trim();
      const fileName   = (tr.dataset.filename || '').toLowerCase();
      const applicant  = (tr.dataset.applicant || '').toLowerCase();

      const matchKw = !kw || fileName.includes(kw) || applicant.includes(kw);
      const matchSt = (st === 'all') || (statusText === st);
      const matchTp = (tp === 'all') || (fileId === tp);

      tr.style.display = (matchKw && matchSt && matchTp) ? '' : 'none';
    });
  }
  ['searchBox','statusFilter','typeFilter'].forEach(id =>
    document.getElementById(id).addEventListener('input', filterTable)
  );
  window.addEventListener('DOMContentLoaded', filterTable);

  const APPLY_ENDPOINT = location.pathname.includes('/pages/')
    ? 'apply_preview.php'
    : 'pages/apply_preview.php';

  // 通過/退件：AJAX 更新
  function updateStatus(id, action, btn){
    const tr = btn.closest('tr');
    const name = tr.querySelector('.filename-cell')?.innerText || '';
    Swal.fire({
      title: '確認操作',
      text: (action==='approve' ? `確定將「${name}」通過？` : `確定將「${name}」退件？`),
      icon: action==='approve' ? 'question' : 'warning',
      showCancelButton: true,
      confirmButtonText: '確定',
      cancelButtonText: '取消',
      reverseButtons: true
    }).then(r=>{
      if(!r.isConfirmed) return;
      fetch(APPLY_ENDPOINT, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `apply_ID=${encodeURIComponent(id)}&action=${encodeURIComponent(action)}&ajax=1`
      })
      .then(res => res.json())
      .then(data => {
        if(data.ok){
          tr.querySelector('.status-cell').innerText = data.status_text;
          // 更新操作按鈕
          if(data.new_status === 1){
            // 已通過：顯示取消通過按鈕
            tr.querySelector('.op-cell').innerHTML = `<button class="btn btn-warning" onclick="cancelApprove(${id},this)">取消通過</button>`;
          } else if(data.new_status === 0){
            // 待審核：顯示通過和退件按鈕
            tr.querySelector('.op-cell').innerHTML = `<button class="btn btn-danger" onclick="updateStatus(${id},'reject',this)">退件</button> <button class="btn btn-success" onclick="updateStatus(${id},'approve',this)">通過</button>`;
          } else {
            // 退件：顯示 -
            tr.querySelector('.op-cell').innerText = '-';
          }
          Swal.fire('成功', `${name}${data.status_text}`, 'success');
          reorderTable();
          filterTable(); // 更新後再跑一次篩選（避免隱藏/顯示狀態錯亂）
        }else{
          Swal.fire('失敗','更新失敗','error');
        }
      })
      .catch(()=> Swal.fire('錯誤','無法連線','error'));
    });
  }

  // 取消通過：將已通過改回待審核
  function cancelApprove(id, btn){
    const tr = btn.closest('tr');
    const name = tr.querySelector('.filename-cell')?.innerText || '';
    Swal.fire({
      title: '確認取消通過',
      text: `確定要取消「${name}」的通過狀態？將改回待審核狀態。`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: '確定',
      cancelButtonText: '取消',
      reverseButtons: true
    }).then(r=>{
      if(!r.isConfirmed) return;
      fetch(APPLY_ENDPOINT, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `apply_ID=${encodeURIComponent(id)}&action=cancel_approve&ajax=1`
      })
      .then(res => res.json())
      .then(data => {
        if(data.ok){
          tr.querySelector('.status-cell').innerText = data.status_text;
          // 改回待審核：顯示通過和退件按鈕
          tr.querySelector('.op-cell').innerHTML = `<button class="btn btn-danger" onclick="updateStatus(${id},'reject',this)">退件</button> <button class="btn btn-success" onclick="updateStatus(${id},'approve',this)">通過</button>`;
          Swal.fire('成功', `${name}已改回待審核狀態`, 'success');
          reorderTable();
          filterTable(); // 更新後再跑一次篩選（避免隱藏/顯示狀態錯亂）
        }else{
          Swal.fire('失敗','更新失敗','error');
        }
      })
      .catch(()=> Swal.fire('錯誤','無法連線','error'));
    });
  }

  // 讓「待審核」在最上，次序：待審核(0)→已通過(1)→退件(2)；同狀態依時間 DESC
  function reorderTable(){
    const tbody = document.querySelector('#applyTable tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a,b) => {
      const order = {'待審核':0, '已通過':1, '退件':2};
      const sa = a.querySelector('.status-cell').innerText.trim();
      const sb = b.querySelector('.status-cell').innerText.trim();
      if (order[sa] !== order[sb]) return order[sa] - order[sb];
      // 時間欄是第 4 欄（index 3）
      const ta = new Date(a.cells[3].innerText);
      const tb = new Date(b.cells[3].innerText);
      return tb - ta; // 新→舊
    });
    rows.forEach(r => tbody.appendChild(r));
  }
</script> 

