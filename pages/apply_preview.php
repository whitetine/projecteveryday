<?php
session_start();
require '../includes/pdo.php'; // 取得 $conn (PDO)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id     = $_POST['apply_ID'] ?? null;
  $action = $_POST['action'] ?? null;
  $isAjax = ($_POST['ajax'] ?? '') === '1';

  if ($id && in_array($action, ['approve', 'reject'], true)) {
    $status = ($action === 'approve') ? 3 : 2; // 3=已通過, 2=退件
    // 這裡用 applydata（和下面 SELECT 一致）
    $stmt = $conn->prepare(
      "UPDATE applydata 
       SET apply_status = ?, apply_b_u_ID = ?, approved_d = NOW()
       WHERE apply_ID = ?"
    );
    $stmt->execute([$status, $_SESSION['u_ID'] ?? 0, $id]);

    if ($isAjax) {
      echo json_encode(['ok' => true, 'new_status' => $status, 'status_text' => ($status === 3 ? '已通過' : '退件')], JSON_UNESCAPED_UNICODE);
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
  $sql  = "select  a.*, f.file_ID , f.file_name, u.u_ID as apply_user
              from applydata a 
              left join filedata f on a.file_ID = f.file_ID
              left join userdata u on a.apply_a_u_ID = u.u_ID
              order by a.apply_status asc, a.apply_created_d desc
              ";
  $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  $fileTypes = $conn->query("SELECT file_ID, file_name FROM filedata")
    ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  http_response_code(500);
  die("DB error: " . htmlspecialchars($e->getMessage()));
}
?>


<meta charset="UTF-8">
<title>申請審核列表</title>

<style>
  .fixed-thumb:hover {
    transform: scale(1.05);
    transition: transform 0.2s;
  }
</style>



<header>
  <h2>申請審核列表</h2>
</header>


<div class="page">
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
        <table class="table table-bordered table-hover table-sm align-middle mb-0 bg-white text-center" id="applyTable">
          <thead>
            <tr>
              <th>表單名稱</th>
              <th>備註</th>
              <th>申請人</th>
              <th>時間</th>
              <th>檔案</th>
              <th>狀態</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr
                data-fileid="<?= htmlspecialchars((string)($r['file_ID'] ?? ''), ENT_QUOTES) ?>".

                data-filename="<?= htmlspecialchars($r['apply_other'] ?? '', ENT_QUOTES) ?>"

                data-applicant="<?= htmlspecialchars($r['apply_user'] ?? '', ENT_QUOTES) ?>">

                <td><?= htmlspecialchars($r['file_name'] ?? '') ?></td>
                <td class="filename-cell"><?= htmlspecialchars($r['apply_other'] ?? '') ?></td>

                <td class="applicant-cell">
                <?=htmlspecialchars($r['apply_user']??($r['apply_a_u_ID']??''))?>
                </td>

                <td><?= htmlspecialchars($r['apply_created_d'] ?? '') ?></td>

                <td>
                  
                  <?php if (!empty($r['apply_url']) && preg_match('/\.(jpg|jpeg|png)$/i', $r['apply_url'])): ?>
                    <img src="<?= htmlspecialchars($r['apply_url']) ?>"
                      class="preview fixed-thumb"
                      style="width:100px;height:100px;object-fit:cover;border-radius:6px;cursor:pointer"
                      onclick="showModal(this.src)">
                  <?php elseif (!empty($r['apply_url'])): ?>
                    <a href="<?= htmlspecialchars($r['apply_url']) ?>" target="_blank">檔案</a>
                  <?php else: ?>
                    無
                  <?php endif; ?>
                </td>

                <td class="status-cell">
                  <?= ($r['apply_status'] == 1 ? '待審核' : ($r['apply_status'] == 2 ? '退件' : '已通過')) ?>
                </td>

                <td class="op-cell">
                  <?php if ((int)$r['apply_status'] === 1): ?>
                    <button class="btn btn-success" onclick="updateStatus(<?= (int)$r['apply_ID'] ?>,'approve',this)">通過</button>
                    <button class="btn btn-danger" onclick="updateStatus(<?= (int)$r['apply_ID'] ?>,'reject',this)">退件</button>
                    <?php else: ?>-<?php endif; ?>
                </td>
                
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>



<!-- 圖片放大 modal -->
<div id="imgModal" class="modal" onclick="closeModal()"><img id="modalImg"></div>


<script>
  // 圖片放大
  function showModal(src){ modalImg.src = src; imgModal.style.display = 'flex'; }
  function closeModal(){ imgModal.style.display = 'none'; }

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
      fetch('apply_preview.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `apply_ID=${encodeURIComponent(id)}&action=${encodeURIComponent(action)}&ajax=1`
      })
      .then(res => res.json())
      .then(data => {
        if(data.ok){
          tr.querySelector('.status-cell').innerText = data.status_text;
          tr.querySelector('.op-cell').innerText = '-';
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