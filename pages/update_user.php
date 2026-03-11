<?php
require '../../includes/pdo.php';
session_start();

$mode          = $_POST['mode'] ?? 'update';
$u_ID_original = $_POST['u_ID_original'] ?? '';
$u_ID          = $_POST['u_ID'] ?? '';
$u_name        = $_POST['u_name'] ?? '';
$u_gmail       = $_POST['u_gmail'] ?? '';
$role_ID       = $_POST['role_ID'] ?? 6;
$u_status      = $_POST['u_status'] ?? 1;

if ($mode === 'create' || empty($u_ID_original)) {
    // 新增
    $stmt = $conn->prepare("
        INSERT INTO userdata (u_ID, u_name, u_gmail, role_ID, u_status, u_created_d)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$u_ID, $u_name, $u_gmail, $role_ID, $u_status]);

} else {
    // 更新
    $stmt = $conn->prepare("
        UPDATE userdata
        SET u_name = ?, u_gmail = ?, role_ID = ?, u_status = ?
        WHERE u_ID = ?
    ");
    $stmt->execute([$u_name, $u_gmail, $role_ID, $u_status, $u_ID_original]);
}

header("Location: ../../main.php#pages/admin_usermanage.php?updated=1");
exit;
