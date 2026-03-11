<?php
session_start();
require __DIR__ . '/../includes/pdo.php';
require_once __DIR__ . '/../includes/utils.php';
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

// document_form_supplements 已移除，統一改由 document_submissions.attach_path 取得。
// 為了相容舊連結，此頁只做轉址到新的附件下載端點。
$query = http_build_query(['doc_ID' => $doc_ID]);
header('Location: download_document_form_draft_attachment.php?' . $query, true, 302);
exit;
