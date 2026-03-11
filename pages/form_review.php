<?php
session_start();
require '../includes/pdo.php'; // 取得 $conn (PDO)

// 檢查權限（只有科辦和主任可以審核）
$u_ID = $_SESSION['u_ID'] ?? null;
if (!$u_ID) {
    die("請先登入");
}

// 檢查是否為科辦或主任 (role_ID=1, 2)
$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM userrolesdata 
    WHERE ur_u_ID = ? AND role_ID IN (1, 2) AND user_role_status = 1
");
$stmt->execute([$u_ID]);
if (!$stmt->fetchColumn()) {
    die("此功能僅限主任和科辦使用");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = $_POST['fs_ID'] ?? null;
    $action = $_POST['action'] ?? null;
    $isAjax = ($_POST['ajax'] ?? '') === '1';

    // 支援 approve（通過）、reject（退件）、pending（改回待審核）、delete（刪除）
    if ($id && in_array($action, ['approve', 'reject', 'pending', 'delete'], true)) {
        $remark = trim($_POST['remark'] ?? '');
        
        // 檢查表是否有審核欄位，如果沒有則使用現有欄位
        $stmt = $conn->query("SHOW COLUMNS FROM formsubdata LIKE 'fs_review_status'");
        $hasReviewStatus = $stmt->fetch() !== false;
        
        if ($action === 'delete') {
            // 刪除表單提交記錄
            $stmt = $conn->prepare("DELETE FROM formanswerdata WHERE fs_ID = ?");
            $stmt->execute([$id]);
            $stmt = $conn->prepare("DELETE FROM formsubdata WHERE fs_ID = ?");
            $stmt->execute([$id]);
            
            if ($isAjax) {
                echo json_encode(['ok' => true, 'message' => '已刪除'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header("Location: form_review.php");
            exit;
        }
        
        // approve=1（已通過/已結案）, reject=2（退件/異常）, pending=0（待審核）
        $status = ($action === 'approve') ? 1 : (($action === 'reject') ? 2 : 0);
        
        if ($hasReviewStatus) {
            // 使用審核欄位
            $stmt = $conn->prepare("
                UPDATE formsubdata 
                SET fs_review_status = ?, fs_reviewed_u_ID = ?, fs_reviewed_d = NOW(), fs_review_remark = ?
                WHERE fs_ID = ?
            ");
            $stmt->execute([$status, $u_ID, $remark, $id]);
        } else {
            // 使用備註欄位暫存審核狀態（JSON格式）
            $stmt = $conn->prepare("
                SELECT fs_remark FROM formsubdata WHERE fs_ID = ?
            ");
            $stmt->execute([$id]);
            $currentRemark = $stmt->fetchColumn();
            $remarkData = json_decode($currentRemark ?: '{}', true);
            if (!is_array($remarkData)) {
                $remarkData = [];
            }
            $remarkData['review_status'] = $status;
            $remarkData['reviewed_u_ID'] = $u_ID;
            $remarkData['reviewed_d'] = date('Y-m-d H:i:s');
            $remarkData['review_remark'] = $remark;
            
            $stmt = $conn->prepare("
                UPDATE formsubdata 
                SET fs_remark = ?
                WHERE fs_ID = ?
            ");
            $stmt->execute([json_encode($remarkData, JSON_UNESCAPED_UNICODE), $id]);
        }

        $statusText = ($status === 1) ? '已通過' : (($status === 2) ? '退件' : '待審核');
        
        if ($isAjax) {
            echo json_encode(['ok' => true, 'new_status' => $status, 'status_text' => $statusText], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header("Location: form_review.php");
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

    // 檢查表是否有審核欄位
    $stmt = $conn->query("SHOW COLUMNS FROM formsubdata LIKE 'fs_review_status'");
    $hasReviewStatus = $stmt->fetch() !== false;

    // 先獲取總數（只顯示已正式提交的表單，fs_status = 0）
    $countSql = "SELECT COUNT(*) 
                FROM formsubdata fs
                INNER JOIN formdata f ON fs.form_ID = f.form_ID
                LEFT JOIN userdata u ON fs.fs_u_ID = u.u_ID
                LEFT JOIN teamdata t ON fs.fs_team_ID = t.team_ID
                WHERE fs.fs_status = 0";
    $total = (int)$conn->query($countSql)->fetchColumn();
    
    // 計算總頁數
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages); // 確保頁碼不超過總頁數
    
    // 查詢資料（含分頁）
    if ($hasReviewStatus) {
        $sql = "SELECT 
                    fs.*, 
                    f.form_name, 
                    f.form_category,
                    u.u_name AS submit_user,
                    t.team_project_name,
                    t.team_ID,
                    reviewer.u_name AS reviewer_name,
                    fs.fs_review_status,
                    fs.fs_review_remark,
                    fs.fs_reviewed_d
                FROM formsubdata fs
                INNER JOIN formdata f ON fs.form_ID = f.form_ID
                LEFT JOIN userdata u ON fs.fs_u_ID = u.u_ID
                LEFT JOIN teamdata t ON fs.fs_team_ID = t.team_ID
                LEFT JOIN userdata reviewer ON fs.fs_reviewed_u_ID = reviewer.u_ID
                WHERE fs.fs_status = 0
                ORDER BY 
                    CASE WHEN fs.fs_review_status IS NULL OR fs.fs_review_status = 0 THEN 0 ELSE 1 END,
                    fs.fs_submitted_d DESC
                LIMIT " . intval($per) . " OFFSET " . intval($offset);
    } else {
        $sql = "SELECT 
                    fs.*, 
                    f.form_name, 
                    f.form_category,
                    u.u_name AS submit_user,
                    t.team_project_name,
                    t.team_ID
                FROM formsubdata fs
                INNER JOIN formdata f ON fs.form_ID = f.form_ID
                LEFT JOIN userdata u ON fs.fs_u_ID = u.u_ID
                LEFT JOIN teamdata t ON fs.fs_team_ID = t.team_ID
                WHERE fs.fs_status = 0
                ORDER BY fs.fs_submitted_d DESC
                LIMIT " . intval($per) . " OFFSET " . intval($offset);
    }
    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    
    // 處理沒有審核欄位的情況，從備註中解析審核狀態
    if (!$hasReviewStatus) {
        foreach ($rows as &$row) {
            $remark = $row['fs_remark'] ?? '';
            if ($remark) {
                $remarkData = json_decode($remark, true);
                if (is_array($remarkData) && isset($remarkData['review_status'])) {
                    $row['fs_review_status'] = $remarkData['review_status'];
                    $row['fs_review_remark'] = $remarkData['review_remark'] ?? '';
                    $row['fs_reviewed_d'] = $remarkData['reviewed_d'] ?? '';
                    $row['reviewer_name'] = '';
                    if (isset($remarkData['reviewed_u_ID'])) {
                        $stmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                        $stmt->execute([$remarkData['reviewed_u_ID']]);
                        $row['reviewer_name'] = $stmt->fetchColumn() ?: '';
                    }
                } else {
                    $row['fs_review_status'] = null;
                    $row['fs_review_remark'] = '';
                    $row['fs_reviewed_d'] = '';
                    $row['reviewer_name'] = '';
                }
            } else {
                $row['fs_review_status'] = null;
                $row['fs_review_remark'] = '';
                $row['fs_reviewed_d'] = '';
                $row['reviewer_name'] = '';
            }
        }
        unset($row);
    }

    $formTypes = $conn->query("SELECT form_ID, form_name FROM formdata WHERE form_status = 1 ORDER BY form_name")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    die("DB error: " . htmlspecialchars($e->getMessage()));
}
?>


<meta charset="UTF-8">
<title>表單審核列表</title>

<style>
  /* 整體美化 */
  .page {
    padding: 20px;
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    min-height: calc(100vh - 100px);
  }

  /* 卡片美化 */
  .card {
    border: none;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    background: #ffffff;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
  }

  .card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
  }

  .card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px 25px;
    border-bottom: none;
    font-weight: 600;
    font-size: 1.1rem;
  }

  .card-body {
    padding: 25px;
  }

  /* 篩選工具列美化 */
  .filters {
    padding: 15px 0;
  }

  .filters input,
  .filters select {
    border-radius: 10px;
    border: 2px solid #e0e0e0;
    padding: 10px 15px;
    transition: all 0.3s ease;
    font-size: 14px;
  }

  .filters input:focus,
  .filters select:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    outline: none;
  }

  /* 表格美化 */
  .table-responsive {
    border-radius: 12px;
    overflow: hidden;
  }

  #formTable {
    margin: 0;
    border-collapse: separate;
    border-spacing: 0;
  }

  #formTable thead {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
  }

  #formTable thead th {
    padding: 18px 15px;
    font-weight: 600;
    text-align: center;
    border: none;
    font-size: 14px;
    letter-spacing: 0.5px;
  }

  #formTable tbody tr {
    transition: all 0.3s ease;
    border-bottom: 1px solid #f0f0f0;
  }

  #formTable tbody tr:hover {
    background: linear-gradient(90deg, #f8f9ff 0%, #ffffff 100%);
    transform: scale(1.01);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
  }

  #formTable tbody td {
    padding: 18px 15px;
    vertical-align: middle;
    border: none;
    font-size: 14px;
  }

  /* 狀態標籤美化 */
  .status-cell {
    font-weight: 600;
  }

  .status-badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.3px;
  }

  .status-pending {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(245, 87, 108, 0.3);
  }

  .status-approved {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(79, 172, 254, 0.3);
  }

  .status-rejected {
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(250, 112, 154, 0.3);
  }

  /* 按鈕美化 */
  .view-btn {
    border-radius: 8px;
    padding: 8px 16px;
    font-weight: 500;
    transition: all 0.3s ease;
    border: 2px solid #667eea;
    color: #667eea;
    background: white;
  }

  .view-btn:hover {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
  }

  .btn-success {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    border: none;
    border-radius: 8px;
    padding: 8px 20px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(17, 153, 142, 0.3);
  }

  .btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(17, 153, 142, 0.4);
  }

  .btn-danger {
    background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
    border: none;
    border-radius: 8px;
    padding: 8px 20px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(235, 51, 73, 0.3);
  }

  .btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(235, 51, 73, 0.4);
  }

  /* 分頁器美化 */
  .pager-bar {
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%) !important;
    border-top: 2px solid #667eea !important;
    padding: 15px 10px !important;
    text-align: center !important;
    margin-top: 0 !important;
    display: block !important;
    width: 100% !important;
    box-sizing: border-box !important;
  }
  
  .pager-bar a,
  .pager-bar span {
    display: inline-block;
    padding: 8px 14px;
    margin: 0 4px;
    text-decoration: none;
    color: #667eea;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s ease;
    background: white;
    border: 2px solid #e0e0e0;
  }
  
  .pager-bar a:hover {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-color: #667eea;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
  }
  
  .pager-bar .active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    font-weight: 700;
    border-color: #667eea;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
  }
  
  .pager-bar .disabled {
    color: #ccc;
    background: #f5f5f5;
    border-color: #e0e0e0;
    pointer-events: none;
    opacity: 0.6;
  }

  /* Header 美化 */
  header h2 {
    color: #333;
    font-weight: 700;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 3px solid #667eea;
    display: inline-block;
  }

  /* 空狀態 */
  #formTable tbody:empty::after {
    content: "目前沒有待審核的表單";
    display: block;
    text-align: center;
    padding: 40px;
    color: #999;
    font-size: 16px;
  }

  /* 響應式設計 */
  @media (max-width: 768px) {
    .filters {
      flex-direction: column;
    }
    
    .filters input,
    .filters select {
      width: 100% !important;
      margin-bottom: 10px;
    }

    .table-responsive {
      overflow-x: auto;
    }

    #formTable {
      min-width: 800px;
    }
  }
</style>



<header>
  <h2>表單審核列表</h2>
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
            placeholder="🔍 搜尋表單名稱、提交人或團隊..." />

          <select id="statusFilter" class="form-select flex-shrink-0" style="width:10%;">
            <option value="all">全部狀態</option>
            <option>待審核</option>
            <option>已通過</option>
            <option>退件</option>
          </select>

          <select id="typeFilter" class="form-select flex-shrink-0" style="width:16%;">
            <option value="all">全部表單類型</option>
            <?php foreach ($formTypes as $f): ?>
              <option value="<?= htmlspecialchars($f['form_ID'], ENT_QUOTES) ?>">
                <?= htmlspecialchars($f['form_name']) ?>
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
        <table class="table table-sm align-middle mb-0 text-center table-clean" id="formTable">
          <thead>
            <tr>
              <th>表單名稱</th>
              <th>提交人</th>
              <th>團隊</th>
              <th>提交時間</th>
              <th>查看</th>
              <th>狀態</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): 
              $reviewStatus = $r['fs_review_status'] ?? null;
              // 狀態判斷：null/0 = 待審核，1 = 已通過（已結案），2 = 退件（異常）
              if ($reviewStatus === null || $reviewStatus === 0 || $reviewStatus === '0') {
                $statusText = '待審核';
              } elseif ($reviewStatus === 2 || $reviewStatus === '2') {
                $statusText = '退件';
              } elseif ($reviewStatus === 1 || $reviewStatus === '1') {
                $statusText = '已通過';
              } else {
                $statusText = '待審核';
              }
            ?>
              <tr
                data-formid="<?= htmlspecialchars((string)($r['form_ID'] ?? ''), ENT_QUOTES) ?>"
                data-submituser="<?= htmlspecialchars($r['submit_user'] ?? '', ENT_QUOTES) ?>"
                data-team="<?= htmlspecialchars($r['team_project_name'] ?? '', ENT_QUOTES) ?>">

                <td style="text-align: left; max-width: 300px;">
                  <strong style="color: #333;"><?= htmlspecialchars($r['form_name'] ?? '') ?></strong>
                </td>
                <td>
                  <i class="fa fa-user text-primary"></i> <?= htmlspecialchars($r['submit_user'] ?? '') ?>
                </td>
                <td>
                  <i class="fa fa-users text-info"></i> <?= htmlspecialchars($r['team_project_name'] ?? '無') ?>
                </td>
                <td>
                  <i class="fa fa-clock text-secondary"></i> <?= htmlspecialchars($r['fs_submitted_d'] ?? '') ?>
                </td>

                <td>
                  <a href="pages/form_export.php?fs_ID=<?= (int)$r['fs_ID'] ?>" 
                     target="_blank" 
                     class="btn btn-sm btn-outline-primary view-btn">
                    <i class="fa fa-eye"></i> 查看
                  </a>
                </td>

                <td class="status-cell">
                  <?php
                    $statusClass = 'status-pending';
                    if ($statusText === '已通過') {
                        $statusClass = 'status-approved';
                    } elseif ($statusText === '退件') {
                        $statusClass = 'status-rejected';
                    }
                  ?>
                  <span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span>
                  <?php if ($reviewStatus !== null && $reviewStatus !== 0 && !empty($r['reviewer_name'])): ?>
                    <br><small class="text-muted" style="margin-top: 5px; display: block;">審核人：<?= htmlspecialchars($r['reviewer_name']) ?></small>
                  <?php endif; ?>
                </td>

                <td class="op-cell">
                  <div class="d-flex gap-2 justify-content-center flex-wrap">
                    <?php 
                    $reviewStatusValue = $reviewStatus;
                    if ($reviewStatusValue === null || $reviewStatusValue === 0 || $reviewStatusValue === '0'): 
                    ?>
                      <!-- 待審核：顯示通過和退件按鈕 -->
                      <button class="btn btn-success btn-sm" onclick="updateStatus(<?= (int)$r['fs_ID'] ?>,'approve',this)">
                        <i class="fa fa-check"></i> 通過
                      </button>
                      <button class="btn btn-danger btn-sm" onclick="updateStatus(<?= (int)$r['fs_ID'] ?>,'reject',this)">
                        <i class="fa fa-times"></i> 退件
                      </button>
                    <?php elseif ($reviewStatusValue === 2 || $reviewStatusValue === '2'): ?>
                      <!-- 退件：可以改回待審核或改為通過 -->
                      <button class="btn btn-warning btn-sm" onclick="updateStatus(<?= (int)$r['fs_ID'] ?>,'pending',this)" title="改回待審核">
                        <i class="fa fa-undo"></i> 收回退件
                      </button>
                      <button class="btn btn-success btn-sm" onclick="updateStatus(<?= (int)$r['fs_ID'] ?>,'approve',this)" title="改為通過">
                        <i class="fa fa-check"></i> 改為通過
                      </button>
                      <button class="btn btn-outline-danger btn-sm" onclick="deleteSubmission(<?= (int)$r['fs_ID'] ?>, this)" title="刪除此提交記錄">
                        <i class="fa fa-trash"></i> 刪除
                      </button>
                    <?php elseif ($reviewStatusValue === 1 || $reviewStatusValue === '1'): ?>
                      <!-- 已通過：可以改回待審核或改為退件 -->
                      <button class="btn btn-warning btn-sm" onclick="updateStatus(<?= (int)$r['fs_ID'] ?>,'pending',this)" title="改回待審核">
                        <i class="fa fa-undo"></i> 改回待審核
                      </button>
                      <button class="btn btn-danger btn-sm" onclick="updateStatus(<?= (int)$r['fs_ID'] ?>,'reject',this)" title="改為退件">
                        <i class="fa fa-times"></i> 改為退件
                      </button>
                      <button class="btn btn-outline-danger btn-sm" onclick="deleteSubmission(<?= (int)$r['fs_ID'] ?>, this)" title="刪除此提交記錄">
                        <i class="fa fa-trash"></i> 刪除
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
                
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
      <!-- 分頁器 -->
      <?php if ($total > 0): ?>
      <div class="pager-bar" id="formPagerBar">
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


<script>
  // 搜尋＋篩選
  function filterTable(){
    const kw = document.getElementById('searchBox').value.trim().toLowerCase();
    const st = document.getElementById('statusFilter').value;
    const tp = document.getElementById('typeFilter').value;

    document.querySelectorAll('#formTable tbody tr').forEach(tr => {
      const statusCell = tr.querySelector('.status-cell');
      const statusText = statusCell?.querySelector('.status-badge')?.innerText.trim() || statusCell?.innerText.trim() || '';
      const formId     = (tr.dataset.formid || '').trim();
      const submitUser = (tr.dataset.submituser || '').toLowerCase();
      const team      = (tr.dataset.team || '').toLowerCase();
      const formName  = tr.cells[0]?.innerText.trim().toLowerCase() || '';

      const matchKw = !kw || submitUser.includes(kw) || team.includes(kw) || formName.includes(kw);
      const matchSt = (st === 'all') || (statusText.includes(st));
      const matchTp = (tp === 'all') || (formId === tp);

      tr.style.display = (matchKw && matchSt && matchTp) ? '' : 'none';
    });
  }
  ['searchBox','statusFilter','typeFilter'].forEach(id =>
    document.getElementById(id).addEventListener('input', filterTable)
  );
  window.addEventListener('DOMContentLoaded', filterTable);

  // 使用 window 物件避免重複聲明
  if (typeof window.FORM_REVIEW_ENDPOINT === 'undefined') {
    window.FORM_REVIEW_ENDPOINT = location.pathname.includes('/pages/')
      ? 'form_review.php'
      : 'pages/form_review.php';
  }
  const FORM_REVIEW_ENDPOINT = window.FORM_REVIEW_ENDPOINT;

  // 更新審核狀態：AJAX 更新
  function updateStatus(id, action, btn){
    const tr = btn.closest('tr');
    const formName = tr.cells[0].innerText.trim();
    
    let actionText = '';
    let icon = 'question';
    if (action === 'approve') {
      actionText = '通過';
      icon = 'question';
    } else if (action === 'reject') {
      actionText = '退件';
      icon = 'warning';
    } else if (action === 'pending') {
      actionText = '改回待審核';
      icon = 'info';
    }
    
    // 詢問審核備註（可選）
    Swal.fire({
      title: '確認操作',
      text: `確定將「${formName}」${actionText}？`,
      icon: icon,
      input: 'textarea',
      inputLabel: '審核備註（選填）',
      inputPlaceholder: '請輸入審核備註...',
      inputAttributes: {
        'aria-label': '審核備註'
      },
      showCancelButton: true,
      confirmButtonText: '確定',
      cancelButtonText: '取消',
      reverseButtons: true,
      inputValidator: (value) => {
        // 備註為可選，不驗證
        return null;
      }
    }).then(r=>{
      if(!r.isConfirmed) return;
      
      const remark = r.value || '';
      
      const formData = new FormData();
      formData.append('fs_ID', id);
      formData.append('action', action);
      formData.append('remark', remark);
      formData.append('ajax', '1');
      
      fetch(FORM_REVIEW_ENDPOINT, {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if(data.ok){
          const statusCell = tr.querySelector('.status-cell');
          let statusClass = 'status-pending';
          if (data.new_status === 1) {
            statusClass = 'status-approved';
          } else if (data.new_status === 2) {
            statusClass = 'status-rejected';
          }
          statusCell.innerHTML = `<span class="status-badge ${statusClass}">${data.status_text}</span>`;
          
          // 重新載入頁面以更新操作按鈕
          location.reload();
        }else{
          Swal.fire('失敗','更新失敗','error');
        }
      })
      .catch(()=> Swal.fire('錯誤','無法連線','error'));
    });
  }

  // 刪除表單提交記錄
  function deleteSubmission(id, btn){
    const tr = btn.closest('tr');
    const formName = tr.cells[0].innerText.trim();
    
    Swal.fire({
      title: '確認刪除',
      text: `確定要刪除「${formName}」的提交記錄嗎？此操作無法復原！`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: '確定刪除',
      cancelButtonText: '取消',
      confirmButtonColor: '#dc3545',
      reverseButtons: true
    }).then(r=>{
      if(!r.isConfirmed) return;
      
      const formData = new FormData();
      formData.append('fs_ID', id);
      formData.append('action', 'delete');
      formData.append('ajax', '1');
      
      fetch(FORM_REVIEW_ENDPOINT, {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if(data.ok){
          Swal.fire('成功', '已刪除', 'success').then(() => {
            location.reload();
          });
        }else{
          Swal.fire('失敗','刪除失敗','error');
        }
      })
      .catch(()=> Swal.fire('錯誤','無法連線','error'));
    });
  }

  // 讓「待審核」在最上，次序：待審核→已通過→退件；同狀態依時間 DESC
  function reorderTable(){
    const tbody = document.querySelector('#formTable tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a,b) => {
      const order = {'待審核':0, '已通過':1, '退件':2};
      const statusCellA = a.querySelector('.status-cell');
      const statusCellB = b.querySelector('.status-cell');
      const sa = statusCellA?.querySelector('.status-badge')?.innerText.trim() || statusCellA?.innerText.trim().split('\n')[0] || '';
      const sb = statusCellB?.querySelector('.status-badge')?.innerText.trim() || statusCellB?.innerText.trim().split('\n')[0] || '';
      if (order[sa] !== order[sb]) return order[sa] - order[sb];
      // 時間欄是第 4 欄（index 3）
      const ta = new Date(a.cells[3].innerText);
      const tb = new Date(b.cells[3].innerText);
      return tb - ta; // 新→舊
    });
    rows.forEach(r => tbody.appendChild(r));
  }
</script> 

