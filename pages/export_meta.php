<?php
/**
 * 匯出 PDF 中繼：依 sub_ID 回傳主 PDF 頁面 URL、附件 PDF 下載 URL、檔名。
 * 前端依此決定：無附件時直接開主 PDF；有附件時用 pdf-lib 合併後下載。
 */
session_start();
require __DIR__ . '/../includes/pdo.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Taipei');

$u_ID = $_SESSION['u_ID'] ?? null;
if (!$u_ID) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => '請先登入']);
    exit;
}

$sub_ID = (int)($_GET['sub_ID'] ?? $_GET['submission_id'] ?? 0);
if ($sub_ID <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '缺少 sub_ID']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT doc_ID, attach_path
        FROM document_submissions
        WHERE sub_ID = ? AND dcsub_u_ID = ?
        LIMIT 1
    ");
    $stmt->execute([$sub_ID, $u_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => '找不到該筆申請']);
        exit;
    }

    $doc_ID = (int)$row['doc_ID'];
    $attach_path = isset($row['attach_path']) ? trim((string)$row['attach_path']) : '';

    $stmtDoc = $conn->prepare("SELECT doc_name FROM document_forms WHERE doc_ID = ? LIMIT 1");
    $stmtDoc->execute([$doc_ID]);
    $docRow = $stmtDoc->fetch(PDO::FETCH_ASSOC);
    $doc_name = $docRow ? (str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '_', $docRow['doc_name'] ?? 'document')) : 'document';
    $filename = $doc_name . '_' . date('Ymd') . '.pdf';

    // 相對路徑：由前端依所在目錄補齊（在 pages/ 下則 base 為 ''）
    $main_pdf_url = 'document_form_pdf.php?document_id=' . $doc_ID . '&submission_id=' . $sub_ID . '&export=1';
    $attach_pdf_url = '';
    if ($attach_path !== '') {
        $attach_pdf_url = 'download_document_form_draft_attachment.php?doc_ID=' . $doc_ID;
    }

    echo json_encode([
        'ok' => true,
        'main_pdf_url' => $main_pdf_url,
        'attach_pdf_url' => $attach_pdf_url,
        'filename' => $filename,
        'doc_ID' => $doc_ID,
        'sub_ID' => $sub_ID
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => '伺服器錯誤']);
}
