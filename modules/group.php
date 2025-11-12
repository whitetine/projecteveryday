<?php
global $conn;
$p = $_POST;
$do = $_GET['do'] ?? '';

switch ($do) {
    case 'add_group': // 新增類組 → 原樣 redirect
        $group_name = $p['group_name'] ?? '';
        $stmt = $conn->prepare("INSERT INTO groupdata (group_name, group_status, group_created_d) VALUES (?, 1, NOW())");
        $stmt->execute([$group_name]);
        header("Location: main.php#pages/group_manage.php");
        exit;

    case 'toggle_group': // 切換狀態 → 原樣 redirect
        $group_ID = $p['group_ID'] ?? 0;
        if (!$group_ID) {
            header("Location: main.php#pages/group_manage.php?toast=error");
            exit;
        }

        $stmt = $conn->prepare("SELECT group_name, group_status FROM groupdata WHERE group_ID = ?");
        $stmt->execute([$group_ID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            header("Location: main.php#pages/group_manage.php?toast=error");
            exit;
        }

        $new = $row['group_status'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE groupdata SET group_status = ? WHERE group_ID = ?");
        $stmt->execute([$new, $group_ID]);

        $state = $new ? 'enabled' : 'disabled';
        header("Location: main.php#pages/group_manage.php?toast={$state}&name=" . urlencode($row['group_name']));
        exit;
}
