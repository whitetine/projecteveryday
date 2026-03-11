<?php
session_start();

// 清除所有 session 變數
$_SESSION = array();

// 如果使用 cookie 來保存 session ID，也要清除 cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// 銷毀 session
session_destroy();

// 重定向到首頁
header('Location: index.php');
exit;
?>

