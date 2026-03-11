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
    // 查詢「最後修改時間」最新的一筆記錄，與核實結果的系統最後修改時間一致，三軌驗證顯示同一筆
    $row = null;
    try {
        // 與 get_document_form_compare_data API 一致：依最後修改時間取同一筆，三軌時間 = 核實結果時間
        $stmt = $conn->prepare("
            SELECT sub_ID, dcsub_answers
            FROM document_submissions
            WHERE doc_ID = ? AND dcsub_u_ID = ?
              AND (dcsub_status = 4 OR dcsub_status = 1 OR dcsub_status = 0)
            ORDER BY dcsub_updated_d DESC, sub_ID DESC
            LIMIT 1
        ");
        $stmt->execute([$doc_ID, $u_ID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e1) {
        try {
            $stmt = $conn->prepare("
                SELECT sub_ID, dcsub_answers
                FROM document_submissions
                WHERE doc_ID = ? AND dcsub_u_ID = ?
                  AND (dcsub_status = 4 OR dcsub_status = 1 OR dcsub_status = 0)
                ORDER BY dcsu_updated_d DESC, sub_ID DESC
                LIMIT 1
            ");
            $stmt->execute([$doc_ID, $u_ID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) {
            try {
                $stmt = $conn->prepare("
                    SELECT sub_ID, dcsub_answers
                    FROM document_submissions
                    WHERE doc_ID = ? AND dcsub_u_ID = ?
                    ORDER BY sub_ID DESC
                    LIMIT 1
                ");
                $stmt->execute([$doc_ID, $u_ID]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e3) {
                error_log('document_form_pdf_preview.php query error: ' . $e3->getMessage());
                throw $e3;
            }
        }
    }
    
    // 如果有草稿記錄，使用 submission_id；否則只傳 doc_ID
    $submission_id = $row ? (int)$row['sub_ID'] : 0;
    
    // 構建參數並包含 document_form_pdf.php（使用 include 而不是重定向，以便在 iframe 中正常顯示）
    // 確保不會觸發下載，而是顯示預覽
    $_GET['document_id'] = $doc_ID;
    unset($_GET['download']); // 確保不會下載
    if ($submission_id > 0) {
        $_GET['submission_id'] = $submission_id;
    }
    
    // 如果有草稿答案，也傳過去
    if ($row && !empty($row['dcsub_answers'])) {
        $_GET['form_answers'] = $row['dcsub_answers'];
    }
    
    // 簽名前 PDF 預覽（iframe）：不顯示表頭底線，版面更簡潔
    $_GET['iframe_preview'] = '1';
    // 強制只顯示「簽名前 PDF」生成預覽，不顯示簽名前後對比（避免左側出現 — 或錯誤版面）
    $_GET['original_only'] = '1';

    // 直接包含 document_form_pdf.php，讓它在當前頁面生成 PDF（預覽模式，不下載）
    include __DIR__ . '/document_form_pdf.php';
    exit;
} catch (Throwable $e) {
    error_log('document_form_pdf_preview.php error: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo '讀取失敗: ' . htmlspecialchars($e->getMessage());
    exit;
}

