<?php
// 單筆新增帳號 API

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/pdo.php';
require_once __DIR__ . '/../includes/utils.php';

// 只有主任 / 科辦可以用
$role_ID = $_SESSION['role_ID'] ?? 0;
if (!in_array($role_ID, [1, 2])) {
    json_err('此頁面僅限主任和科辦使用');
}

// 讀取前端送來的 JSON
$data = read_json_body();

$u_acc   = trim($data['u_acc']   ?? '');  // 前端輸入的帳號
$u_name  = trim($data['u_name']  ?? '');
$u_email = trim($data['u_email'] ?? '');  // 之後塞到 u_gmail
$roleNew = $data['role_ID']      ?? null;
$uStatus = $data['u_status']     ?? 1;
$cohort  = $data['cohort_ID']    ?? null;
$classID = $data['class_ID']     ?? null;

// 必填檢查
if ($u_acc === '' || $u_name === '') {
    json_err('帳號與姓名為必填欄位');
}

// ✅ 這裡要用「資料表真的有的欄位」：u_ID
$stmt = $conn->prepare("SELECT COUNT(*) FROM userdata WHERE u_ID = ?");
$stmt->execute([$u_acc]);
if ($stmt->fetchColumn() > 0) {
    json_err('此帳號已存在，請確認後再試');
}

// 預設密碼 = 帳號
$defaultPwHash = password_hash($u_acc, PASSWORD_DEFAULT);

// ✅ 同樣這裡也改成 u_ID, u_gmail
$sql = "INSERT INTO userdata 
            (u_ID, u_password, u_name, u_gmail, u_status, u_update_d)
        VALUES
            (:id, :pw, :name, :gmail, :status, NOW())";

$stmtIns = $conn->prepare($sql);
$ok = $stmtIns->execute([
    ':id'    => $u_acc,          // 把帳號當成 u_ID
    ':pw'    => $defaultPwHash,
    ':name'  => $u_name,
    ':gmail' => $u_email,
    ':status'=> $uStatus ?: 1,
]);

if (!$ok) {
    json_err('資料庫錯誤，新增失敗');
}

// TODO：如果你之後要順便建立 userrolesdata / enrollmentdata，可以在這裡再寫 SQL

json_ok(['msg' => '新增帳號成功']);
