<?php
session_start();
require __DIR__ . '/../includes/pdo.php';
date_default_timezone_set('Asia/Taipei');

$u_ID = $_SESSION['u_ID'] ?? null;
if (!$u_ID) {
    header('HTTP/1.1 403 Forbidden');
    exit('請先登入');
}

$doc_ID = (int)($_GET['doc_ID'] ?? $_GET['document_id'] ?? 0);
if ($doc_ID <= 0) {
    header('HTTP/1.1 400 Bad Request');
    exit('參數錯誤');
}

try {
    $stmt = $conn->prepare("SELECT display_name, file_path FROM document_form_attachments WHERE doc_ID = ?");
    $stmt->execute([$doc_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['file_path'])) {
        header('HTTP/1.1 404 Not Found');
        exit('無此附件');
    }
    $fullPath = dirname(__DIR__, 2) . '/' . $row['file_path'];
    if (!is_file($fullPath) || !is_readable($fullPath)) {
        header('HTTP/1.1 404 Not Found');
        exit('檔案不存在');
    }
    $displayName = $row['display_name'] ?: '附件.pdf';
    if (strpos($displayName, '.pdf') === false) {
        $displayName .= '.pdf';
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . rawurlencode($displayName) . '"');
    header('Cache-Control: private, max-age=3600');
    readfile($fullPath);
    exit;
} catch (Throwable $e) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('讀取失敗');
}
