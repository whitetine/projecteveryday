<?php
require '../includes/pdo.php';

$u_ID = $_GET['u_ID'] ?? '';
if (!$u_ID) {
  echo '<div class="alert alert-danger">缺少參數</div>';
  exit;
}

$sql = "SELECT u.*, r.role_ID, r.role_name, s.status_ID, s.status_name, 
               c.c_ID as class_ID, c.c_name as class_name,
               e.cohort_ID, ch.cohort_name, e.enroll_grade
        FROM userdata u
        LEFT JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID AND ur.user_role_status = 1 
        LEFT JOIN roledata r ON ur.role_ID = r.role_ID
        LEFT JOIN statusdata s ON s.status_ID = u.u_status
        LEFT JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID AND e.enroll_status = 1
        LEFT JOIN classdata c ON c.c_ID = e.class_ID
        LEFT JOIN cohortdata ch ON ch.cohort_ID = e.cohort_ID
        WHERE u.u_ID = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$u_ID]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
  echo '<div class="alert alert-warning">找不到該使用者</div>';
  exit;
}

$roles    = $conn->query("SELECT * FROM roledata")->fetchAll(PDO::FETCH_ASSOC);
$statuses = $conn->query("SELECT * FROM statusdata")->fetchAll(PDO::FETCH_ASSOC);
$classes  = $conn->query("SELECT * FROM classdata")->fetchAll(PDO::FETCH_ASSOC);
$cohorts  = $conn->query("SELECT * FROM cohortdata ORDER BY cohort_ID DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="css/admin_edituser.css?v=<?= time() ?>">

<div class="edit-user-container">
  <div class="page-header">
    <h1 class="page-title">
      <i class="fa-solid fa-user-pen me-2" style="color: #ffc107;"></i>編輯使用者
    </h1>
  </div>

  <div class="edit-card">
    <form id="editForm" enctype="multipart/form-data">
      <input type="hidden" name="u_ID" value="<?= htmlspecialchars($user['u_ID']) ?>">

      <div class="row g-4">
        <!-- 頭像區塊 -->
        <div class="col-12 col-md-4">
          <div class="avatar-section">
            <img id="avatarPreview"
              src="<?= !empty($user['u_img']) ? 'headshot/' . htmlspecialchars($user['u_img']) : 'https://cdn-icons-png.flaticon.com/512/1144/1144760.png' ?>"
              alt="用戶頭像" 
              class="avatar-preview"
              onerror="this.src='https://cdn-icons-png.flaticon.com/512/1144/1144760.png'"
              style="width: 180px; height: 180px; object-fit: cover; border-radius: 50%; border: 5px solid #fff; box-shadow: 0 8px 24px rgba(0,0,0,0.15);">
            
            <div class="avatar-upload">
              <input type="file" name="avatar" id="avatarInput" accept="image/*" class="form-control">
              <small>建議 1:1 圖片，JPG/PNG/WebP，最大 5MB</small>
            </div>
            
            <input type="hidden" name="clear_avatar" id="clear_avatar" value="0">
            <button type="button" class="btn btn-outline-danger btn-clear-avatar" id="btnClearAvatar">
              <i class="fa-solid fa-trash me-2"></i>清除頭貼
            </button>
          </div>
        </div>

        <!-- 基本資料區塊 -->
        <div class="col-12 col-md-8">
          <div class="form-section">
            <div class="row g-3">
              <div class="col-12 col-sm-6">
                <label class="form-label-enhanced">
                  <i class="fa-solid fa-id-card"></i>學號/ID
                </label>
                <input type="text" 
                       class="form-control form-control-enhanced" 
                       value="<?= htmlspecialchars($user['u_ID']) ?>" 
                       readonly>
                <small class="text-muted">此欄位無法修改</small>
              </div>

              <div class="col-12 col-sm-6">
                <label class="form-label-enhanced">
                  <i class="fa-solid fa-user"></i>姓名
                </label>
                <input type="text" 
                       name="name" 
                       class="form-control form-control-enhanced" 
                       value="<?= htmlspecialchars($user['u_name']) ?>"
                       required>
              </div>

              <div class="col-12 col-sm-6">
                <label class="form-label-enhanced">
                  <i class="fa-solid fa-lock"></i>密碼
                </label>
                <input type="password" 
                       name="password" 
                       class="form-control form-control-enhanced" 
                       id="pwd" 
                       placeholder="留空表示不更改密碼"
                       autocomplete="new-password">
                <small class="text-muted">留空則不修改密碼</small>
              </div>

              <div class="col-12 col-sm-6">
                <label class="form-label-enhanced">
                  <i class="fa-solid fa-envelope"></i>信箱
                </label>
                <input type="email" 
                       name="gmail" 
                       class="form-control form-control-enhanced" 
                       value="<?= htmlspecialchars($user['u_gmail']) ?>"
                       placeholder="example@email.com">
              </div>

              <div class="col-12">
                <label class="form-label-enhanced">
                  <i class="fa-solid fa-info-circle"></i>自我介紹
                </label>
                <textarea name="profile" 
                          rows="4" 
                          class="form-control form-control-enhanced"
                          placeholder="輸入自我介紹..."><?= htmlspecialchars($user['u_profile']) ?></textarea>
              </div>

              <div class="col-12 col-sm-4">
                <label class="form-label-enhanced">
                  <i class="fa-solid fa-calendar-alt"></i>學級
                </label>
                <select name="cohort_id" class="form-select form-select-enhanced">
                  <option value="">無學級</option>
                  <?php foreach ($cohorts as $ch): ?>
                    <option value="<?= $ch['cohort_ID'] ?>" <?= ($ch['cohort_ID'] == $user['cohort_ID']) ? 'selected' : ''; ?>>
                      <?= htmlspecialchars($ch['cohort_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-12 col-sm-4">
                <label class="form-label-enhanced">
                  <i class="fa-solid fa-graduation-cap"></i>目前班級
                </label>
                <div class="d-flex gap-2">
                  <select name="class_id" class="form-select form-select-enhanced flex-grow-1">
                    <option value="">無班級</option>
                    <?php foreach ($classes as $c): ?>
                      <option value="<?= $c['c_ID'] ?>" <?= ($c['c_ID'] == $user['class_ID']) ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($c['c_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <select name="grade" class="form-select form-select-enhanced" style="min-width: 100px;">
                    <option value="">年級</option>
                    <?php for ($g = 1; $g <= 6; $g++): ?>
                      <option value="<?= $g ?>" <?= ($g == $user['enroll_grade']) ? 'selected' : ''; ?>>
                        <?= $g ?>年級
                      </option>
                    <?php endfor; ?>
                  </select>
                </div>
              </div>

              <div class="col-12 col-sm-4">
                <label class="form-label-enhanced">
                  <i class="fa-solid fa-user-tag"></i>角色
                </label>
                <select name="role_id" class="form-select form-select-enhanced" required>
                  <option value="">請選擇角色</option>
                  <?php foreach ($roles as $r): ?>
                    <option value="<?= $r['role_ID'] ?>" <?= ($r['role_ID'] == $user['role_ID']) ? 'selected' : ''; ?>>
                      <?= htmlspecialchars($r['role_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-12 col-sm-4">
                <label class="form-label-enhanced">
                  <i class="fa-solid fa-toggle-on"></i>狀態
                </label>
                <select name="status_id" class="form-select form-select-enhanced" required>
                  <?php 
                  // 状态显示映射（仅限账号相关）
                  $statusMap = [
                      0 => '休學',
                      1 => '就讀中',
                      2 => '錯誤',
                      3 => '畢業'
                  ];
                  foreach ($statuses as $s): 
                    // 只显示 0-3，不显示 4
                    if ($s['status_ID'] == 4) continue;
                    $displayName = isset($statusMap[$s['status_ID']]) ? $statusMap[$s['status_ID']] : $s['status_name'];
                  ?>
                    <option value="<?= $s['status_ID'] ?>" <?= ($s['status_ID'] == $user['u_status']) ? 'selected' : ''; ?>>
                      <?= htmlspecialchars($displayName) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="form-actions">
        <button type="button" class="btn btn-action-edit btn-cancel-edit" id="btnCancel">
          <i class="fa-solid fa-times me-2"></i>取消
        </button>
        <button type="submit" class="btn btn-action-edit btn-save-edit" id="btnSave">
          <i class="fa-solid fa-check me-2"></i>完成修改
        </button>
      </div>
    </form>
  </div>
</div>

<script src="js/admin_edituser.js?v=<?= time() ?>"></script>