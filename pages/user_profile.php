<?php
session_start();
require '../includes/pdo.php';

// if (!isset($_SESSION['acc'])) {
//     header("Location: index.php");
//     exit;
// }


$u_ID = $_SESSION['u_ID'];

$stmt = $conn->prepare("SELECT * FROM userdata WHERE u_ID = ?");
$stmt->execute([$u_ID]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

// if (!$data) {
//     echo "<h3>查無此帳號資料</h3>";
//     exit;
// }

?>
<!DOCTYPE html>
<html lang="zh-TW">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>個人檔案</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../css/user_profile.css?v=<?= time() ?>">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>
  <div class="container">
        <div class="card mb-4">
           <div class="card-header">
    <h2 class="mb-0">個人檔案</h2>
    </div>

    <div class="card-body">
<?php
$img = !empty($data['u_img']) 
    ? "headshot/" . $data['u_img'] 
    : "https://cdn-icons-png.flaticon.com/512/1144/1144760.png";
$hasImage = !empty($data['u_img']);
?>
<div class="mb-3 text-center">
    <img id="avatarPreview" src="<?= $img ?>" class="avatar-img border" 
         style="width: 80px; height: 80px; border-radius: 50%; border: 3px solid #667eea; object-fit: cover; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);"
         data-has-image="<?= $hasImage ? '1' : '0' ?>">
</div>



    <form id="profileForm" method="post" action="" enctype="multipart/form-data">
      <input type="hidden" name="u_ID" value="<?= $data['u_ID'] ?>">

      <div class="mb-3">
        <label class="form-label">
          <i class="fa-solid fa-user me-2" style="color: #667eea;"></i>帳號
        </label>
        <input class="form-control" type="text" value="<?= $data['u_ID'] ?>" readonly>
      </div>

      <div class="mb-3">
        <label class="form-label">
          <i class="fa-solid fa-id-card me-2" style="color: #667eea;"></i>姓名
        </label>
        <input class="form-control" type="text" value="<?= $data['u_name'] ?>" readonly>
      </div>

      <div class="mb-3">
        <label class="form-label">
          <i class="fa-solid fa-envelope me-2" style="color: #667eea;"></i>信箱
        </label>
        <input class="form-control" type="text" name="u_gmail" id="gmailInput" value="<?= $data['u_gmail'] ?>" readonly>
      </div>

      <div class="mb-3">
        <label class="form-label">
          <i class="fa-solid fa-comment me-2" style="color: #667eea;"></i>自我介紹
        </label>
        <textarea name="u_profile" class="form-control" id="profileText" rows="4" readonly><?= $data['u_profile'] ?></textarea>
      </div>

      <div class="mb-3 d-none" id="avatarUpload">
        <label class="form-label">
          <i class="fa-solid fa-image me-2" style="color: #667eea;"></i>上傳頭貼
        </label>
        <input type="file" class="form-control" name="u_img" accept="image/*" onchange="previewAvatar(event)">
        <input type="hidden" name="clear_avatar" id="clear_avatar" value="0">
        <button type="button" class="btn btn-outline-danger mt-2" id="btnClearAvatar">
          <i class="fa-solid fa-trash me-2"></i>清除頭貼
        </button>
      </div>

      <div id="profileBtns">
        <button type="button" class="btn btn-primary" onclick="enableEdit()">
          <i class="fa-solid fa-pen-to-square me-2"></i>修改資料
        </button>
        <button type="button" class="btn btn-warning" onclick="showPwdForm()">
          <i class="fa-solid fa-lock me-2"></i>修改密碼
        </button>
      </div>

      <div class="d-none" id="editBtns">
        <button type="submit" class="btn btn-success">
          <i class="fa-solid fa-check me-2"></i>儲存資料
        </button>
        <button type="button" class="btn btn-secondary" onclick="cancelEdit()">
          <i class="fa-solid fa-times me-2"></i>取消
        </button>
      </div>
    </form>

    <form id="pwdForm" class="mt-5 d-none" method="post" action="">
      <input type="hidden" name="u_ID" value="<?= $data['u_ID'] ?>">
      <h4>
        <i class="fa-solid fa-lock me-2"></i>變更密碼
      </h4>
      
      <div class="mb-3">
        <label class="form-label">
          <i class="fa-solid fa-key me-2" style="color: #667eea;"></i>目前密碼
        </label>
        <div class="input-group">
          <input type="password" name="old_password" id="oldPassword" class="form-control" required placeholder="輸入目前密碼">
          <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('oldPassword', this)">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">
          <i class="fa-solid fa-key me-2" style="color: #667eea;"></i>新密碼
        </label>
        <div class="input-group">
          <input type="password" name="new_password" id="newPassword" class="form-control" required 
            placeholder="輸入新密碼"
            value="<?= htmlspecialchars($_GET['np'] ?? '') ?>">
          <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('newPassword', this)">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">
          <i class="fa-solid fa-key me-2" style="color: #667eea;"></i>確認新密碼
        </label>
        <div class="input-group">
          <input type="password" name="confirm_password" id="confirmPassword" class="form-control" required placeholder="再次輸入新密碼">
          <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirmPassword', this)">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>
      </div>

      <div id="pwdBtns">
        <button type="submit" class="btn btn-success">
          <i class="fa-solid fa-check me-2"></i>儲存密碼
        </button>
        <button type="button" class="btn btn-secondary" onclick="cancelPwd()">
          <i class="fa-solid fa-times me-2"></i>取消
        </button>
      </div>
    </form>
  </div>
</div>
</div>
  <script>
    function enableEdit() { //點擊「修改資料」時執行：開啟編輯模式
      document.getElementById('profileText').removeAttribute('readonly'); // 開啟自我介紹輸入框
      document.getElementById('avatarUpload').classList.remove('d-none'); // 顯示上傳頭貼的欄位
      document.getElementById('profileBtns').classList.add('d-none'); // 隱藏「修改資料」「修改密碼」兩顆按鈕
      document.getElementById('editBtns').classList.remove('d-none'); // 顯示「儲存資料」「取消」兩顆按鈕
      document.getElementById('gmailInput').removeAttribute('readonly'); // 開啟信箱欄位
    }

    function cancelEdit() { //點擊「取消」編輯時執行：關閉編輯模式還原畫面
      document.getElementById('profileText').setAttribute('readonly', true); // 自我介紹唯讀 
      document.getElementById('avatarUpload').classList.add('d-none'); // 隱藏上傳頭貼欄位
      document.getElementById('editBtns').classList.add('d-none'); // 隱藏「儲存」「取消」
      document.getElementById('profileBtns').classList.remove('d-none'); // 顯示「修改資料」「修改密碼」
      document.getElementById('gmailInput').setAttribute('readonly', true); // 信箱欄位變回唯讀
    }

    function showPwdForm() { // 點擊「修改密碼」時執行：顯示密碼修改表單
      document.getElementById('pwdForm').classList.remove('d-none'); // 顯示密碼表單
      document.getElementById('profileBtns').classList.add('d-none'); // 隱藏按鈕
    }

    function cancelPwd() { //點擊密碼修改「取消」時執行：隱藏密碼表單
      document.getElementById('pwdForm').classList.add('d-none'); // 隱藏密碼表單
      document.getElementById('profileBtns').classList.remove('d-none'); // 顯示主按鈕
    }


    function previewAvatar(event) { //頭貼選擇圖片時即時預覽
      const reader = new FileReader();
      reader.onload = function() {
        const img = document.getElementById('avatarPreview');
        img.src = reader.result; // 預覽圖片
        img.setAttribute('data-has-image', '1'); // 標記為有圖片
      }
      reader.readAsDataURL(event.target.files[0]); // 讀取上傳的圖片
    }

    function togglePassword(inputId, btn) {
      const input = document.getElementById(inputId);
      const icon = btn.querySelector('i');

      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
      } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
      }
    }

    // ✅ 設置表單 action（動態計算正確的路徑）
    (function() {
      // 從當前 URL 推斷基礎路徑
      const pathname = window.location.pathname;
      let apiPath;
      
      if (pathname.includes('/original/')) {
        // 如果 URL 包含 /original/，使用絕對路徑
        apiPath = '/original/api.php';
      } else {
        // 否則使用相對路徑（從 pages/ 目錄到根目錄）
        apiPath = '../api.php';
      }
      
      // 設置個人資料表單的 action
      const profileForm = document.getElementById('profileForm');
      if (profileForm) {
        profileForm.action = apiPath + '?do=update_profile';
      }
      
      // 設置密碼表單的 action
      const pwdForm = document.getElementById('pwdForm');
      if (pwdForm) {
        pwdForm.action = apiPath + '?do=update_password';
      }
    })();

    // ✅ 清除頭貼按鈕功能
    document.getElementById('btnClearAvatar').addEventListener('click', function(e) {
      e.preventDefault();
      const img = document.getElementById('avatarPreview');
      const clearAvatarInput = document.getElementById('clear_avatar');
      const fileInput = document.querySelector('input[name="u_img"]');
      
      // 設定清除標籤
      clearAvatarInput.value = '1';
      
      // 清除文件輸入
      if (fileInput) fileInput.value = '';
      
      // 將圖片改為 icon（預設頭貼）
      img.src = 'https://cdn-icons-png.flaticon.com/512/1144/1144760.png';
      img.setAttribute('data-has-image', '0');
      
      // 顯示提示訊息
      Swal.fire({
        icon: 'success',
        title: '已清除頭貼',
        text: '保存資料後將恢復為預設頭貼',
        timer: 2000,
        showConfirmButton: false
      });
    });
  </script>

  <?php if (isset($_GET['error'])): ?>
    <script>
      window.addEventListener('DOMContentLoaded', () => {
        showPwdForm();
        let msg = "";
        switch ("<?= $_GET['error'] ?>") {
          case "empty":
            msg = "請填寫所有欄位";
            break;
          case "mismatch":
            msg = "新密碼與確認密碼不一致";
            break;
          default:
            msg = "未知錯誤";
        }
        Swal.fire({
          icon: 'error',
          title: '錯誤',
          text: msg
        });
      });
    </script>
  <?php endif; ?>
</body>

</html>