<?php
/**
 * 簽名前預覽：依 submission_id 載入該筆的 dcsub_answers 並輸出表單內容
 * 供科辦在審核列表的對比視窗左側使用
 */
session_start();
require __DIR__ . '/../includes/pdo.php';
date_default_timezone_set('Asia/Taipei');

$u_ID = $_SESSION['u_ID'] ?? null;
if (!$u_ID) {
    header('HTTP/1.1 403 Forbidden');
    exit('請先登入');
}

$doc_ID = (int)($_GET['doc_ID'] ?? $_GET['document_id'] ?? 0);
$submission_id = (int)($_GET['submission_id'] ?? $_GET['sub_ID'] ?? 0);
if ($doc_ID <= 0 || $submission_id <= 0) {
    header('HTTP/1.1 400 Bad Request');
    exit('參數錯誤：需提供 doc_ID 與 submission_id');
}

try {
    $stmt = $conn->prepare("
        SELECT sub_ID, doc_ID, dcsub_u_ID, dcsub_answers
        FROM document_submissions
        WHERE sub_ID = ? AND doc_ID = ?
    ");
    $stmt->execute([$submission_id, $doc_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>找不到該筆申請</title></head><body style="font-family:sans-serif;padding:2em;text-align:center;color:#666;"><p>找不到該筆申請記錄。</p></body></html>';
        exit;
    }

    $_GET['document_id'] = $doc_ID;
    $_GET['submission_id'] = $submission_id;
    $_GET['embed'] = '1';
    if (!empty($row['dcsub_answers'])) {
        $_GET['form_answers'] = $row['dcsub_answers'];
    }
    unset($_GET['download']);

    include __DIR__ . '/document_form_pdf.php';
    exit;
} catch (Throwable $e) {
    error_log('document_form_filled_preview.php: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo '讀取失敗: ' . htmlspecialchars($e->getMessage());
    exit;
}
