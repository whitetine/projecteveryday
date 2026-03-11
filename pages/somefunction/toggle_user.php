<?php
require_once "../../includes/pdo.php";

$acc = $_GET['acc'] ?? '';
$status = $_GET['status'] ?? null;

if (!empty($acc) && ($status === '0' || $status === '1')) {
    $stmt = $conn->prepare("UPDATE userdata SET u_status = ? WHERE u_ID = ?");
    $stmt->execute([$status, $acc]);

    header("Location: ../../main.php#pages/admin_usermanage.php?success=" . ($status === '1' ? 'enable' : 'disable'));
exit;

}
?>
