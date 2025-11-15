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
  <title>個人檔案</title>
  <!-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"> -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><!--密碼眼睛 -->

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .avatar-img {
      width: 150px;
      height: 150px;
      object-fit: cover;
      border-radius: 50%;
    }
  </style>
</head>

<body class="p-5">
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
?>
<div class="mb-3 text-center">
    <img id="avatarPreview" src="<?= $img ?>" class="avatar-img border">
</div>



    <form id="profileForm" method="post" action="../api.php?do=update_profile" enctype="multipart/form-data">
      <input type="hidden" name="u_ID" value="<?= $data['u_ID'] ?>">

      <div class="mb-3">
        <label class="form-label">帳號</label>
        <input class="form-control" type="text" value="<?= $data['u_ID'] ?>" readonly>
      </div>

      <div class="mb-3">
        <label class="form-label">姓名</label>
        <input class="form-control" type="text" value="<?= $data['u_name'] ?>" readonly>
      </div>

      <div class="mb-3">
        <label class="form-label">信箱</label>
        <input class="form-control" type="text" name="u_gmail" id="gmailInput" value="<?= $data['u_gmail'] ?>" readonly>

      </div>

      <div class="mb-3">
        <label class="form-label">自我介紹</label>
        <textarea name="profile" class="form-control" id="profileText" rows="4" readonly><?= $data['u_profile'] ?></textarea>
      </div>

      <div class="mb-3 d-none" id="avatarUpload">
        <label class="form-label">上傳頭貼</label>
        <input type="file" class="form-control" name="u_img" accept="image/*" onchange="previewAvatar(event)">
        <input type="hidden" name="clear_avatar" id="clear_avatar" value="0">
        <button type="button" class="btn btn-outline-danger" id="btnClearAvatar">清除頭貼</button>
      </div>

      <div id="profileBtns">
        <button type="button" class="btn btn-primary" onclick="enableEdit()">修改資料</button>
        <button type="button" class="btn btn-warning" onclick="showPwdForm()">修改密碼</button>
      </div>

      <div class="d-none" id="editBtns">
        <button type="submit" class="btn btn-success">儲存資料</button>
        <button type="button" class="btn btn-secondary" onclick="cancelEdit()">取消</button>
      </div>
    </form>

    <form id="pwdForm" class="mt-5 d-none" method="post" action="../api.php?do=update_password">
      <input type="hidden" name="u_ID" value="<?= $data['u_ID'] ?>">
      <h4>變更密碼</h4>
      <div class="mb-2">
        <div class="mb-2">
          <label>目前密碼</label>
          <div class="input-group">
            <input type="password" name="old_password" id="oldPassword" class="form-control" required>
            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('oldPassword', this)">
              <i class="fa-solid fa-eye"></i>
            </button>
          </div>
        </div>

        <div class="mb-2">
          <label>新密碼</label>
          <div class="input-group">
            <input type="password" name="new_password" id="newPassword" class="form-control" required
              value="<?= htmlspecialchars($_GET['np'] ?? '') ?>">

            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('newPassword', this)">
              <i class="fa-solid fa-eye"></i>
            </button>
          </div>
        </div>

      </div>
      <div class="mb-2">
        <label>確認新密碼</label>
        <div class="input-group">
          <input type="password" name="confirm_password" id="confirmPassword" class="form-control" required>
          <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirmPassword', this)">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>
      </div>
      <div>
        <button type="submit" class="btn btn-success">儲存密碼</button>
        <button type="button" class="btn btn-secondary" onclick="cancelPwd()">取消</button>
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
        document.getElementById('avatarPreview').src = reader.result; // 預覽圖片
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

    // ✅ 清除頭貼：用正確的 input id 與預設圖路徑
    document.getElementById('btnClearAvatar').addEventListener('click', function(e) {
      e.preventDefault();
      document.getElementById('clear_avatar').value = '1';
      const fi = document.getElementById('avatarInput');
      if (fi) fi.value = '';
      const img = document.getElementById('avatarPreview');
      if (img) img.src = '../headshot/default.jpg'; // 用和上面預覽一致的相對路徑
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