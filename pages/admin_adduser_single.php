<?php
session_start();
require '../includes/pdo.php';

// 權限檢查：主任 / 科辦才可以新增
$role_ID = $_SESSION['role_ID'] ?? 0;
if (!in_array($role_ID, [1, 2])) {
    echo "<script>alert('此頁面僅限主任和科辦使用');location.href='../main.php';</script>";
    exit;
}

$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 取得表單資料
    $u_acc      = trim($_POST['u_acc'] ?? '');
    $u_name     = trim($_POST['u_name'] ?? '');
    $u_email    = trim($_POST['u_email'] ?? '');
    $role_new   = (int)($_POST['role_ID'] ?? 0);
    $u_status   = (int)($_POST['u_status'] ?? 1);
    $cohort     = trim($_POST['cohort'] ?? '');
    $class_name = trim($_POST['class_name'] ?? '');

    if ($u_acc === '' || $u_name === '') {
        $errorMsg = '帳號與姓名為必填欄位';
    } else {
        // 檢查帳號是否重複
        $stmt = $conn->prepare("SELECT COUNT(*) FROM userdata WHERE u_acc = ?");
        $stmt->execute([$u_acc]);
        if ($stmt->fetchColumn() > 0) {
            $errorMsg = '此帳號已存在，請確認後再試';
        } else {
            // 預設密碼：先用帳號，之後可要求修改
            // 如果你現在還沒用 password_hash，可以改成明碼存，但不建議
            $defaultPwHash = password_hash($u_acc, PASSWORD_DEFAULT);

            $sql = "INSERT INTO userdata 
                        (u_acc, u_pw, u_name, u_email, role_ID, u_status, cohort, class_name, u_created_d)
                    VALUES
                        (:acc, :pw, :name, :email, :role, :status, :cohort, :class_name, NOW())";

            $stmtIns = $conn->prepare($sql);
            $ok = $stmtIns->execute([
                ':acc'        => $u_acc,
                ':pw'         => $defaultPwHash,
                ':name'       => $u_name,
                ':email'      => $u_email,
                ':role'       => $role_new,
                ':status'     => $u_status,
                ':cohort'     => $cohort,
                ':class_name' => $class_name
            ]);

            if ($ok) {
                $successMsg = '新增帳號成功';
            } else {
                $errorMsg = '資料庫錯誤，新增失敗';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>單次新增帳號</title>
    <link rel="stylesheet" href="../css/bootstrap.min.css">
</head>
<body class="p-4">
    <h2 class="mb-4">單次新增帳號</h2>

    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="mb-3">
            <label class="form-label">登入帳號</label>
            <input type="text" name="u_acc" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">姓名</label>
            <input type="text" name="u_name" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="u_email" class="form-control">
        </div>
        <div class="mb-3">
            <label class="form-label">角色</label>
            <select name="role_ID" class="form-select">
                <option value="3">老師</option>
                <option value="4" selected>學生</option>
                <!-- 依你的 userrolesdata 調整 -->
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">狀態</label>
            <select name="u_status" class="form-select">
                <option value="1" selected>專題進行中</option>
                <option value="3">專題已通過</option>
                <option value="2">專題未通過</option>
                <option value="0">休學</option>
                <option value="5">暑修中</option>
                <option value="6">暑修通過</option>
                <option value="7">寒修中</option>
                <option value="8">寒修通過</option>
            </select>
        </div>
        <div class="row">
            <div class="mb-3 col-6">
                <label class="form-label">屆別</label>
                <input type="text" name="cohort" class="form-control" placeholder="例如 113">
            </div>
            <div class="mb-3 col-6">
                <label class="form-label">班級</label>
                <input type="text" name="class_name" class="form-control" placeholder="例如 資管三甲">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">確認新增</button>
        <a href="admin_usermanage.php" class="btn btn-secondary">回帳號管理</a>
    </form>
</body>
</html>
