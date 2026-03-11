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
$submission_id = (int)($_GET['submission_id'] ?? $_GET['sub_ID'] ?? 0);
if ($doc_ID <= 0 || $submission_id <= 0) {
    header('HTTP/1.1 400 Bad Request');
    exit('參數錯誤：需提供 document_id 與 submission_id');
}

/**
 * 依慣例在 uploads/document_form_supplements 或 uploads/original 下尋找該 sub_ID 的簽名前 PDF（最新一筆）。
 * 回傳完整路徑或 null。
 */
function find_original_pdf_by_convention(int $sub_ID): ?string {
    $baseNames = [
        rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\') . DIRECTORY_SEPARATOR . 'projecteverydays' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'document_form_supplements',
        rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'document_form_supplements',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'document_form_supplements',
        rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\') . DIRECTORY_SEPARATOR . 'projecteverydays' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'original',
        rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'original',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'original',
    ];
    $prefix = 'sub_' . $sub_ID . '_original_';
    $prefixAlt = 'sub_' . $sub_ID . '_';
    $foundPath = null;
    $foundMtime = 0;
    foreach ($baseNames as $dir) {
        if (!is_dir($dir)) continue;
        $d = @opendir($dir);
        if (!$d) continue;
        while (($f = readdir($d)) !== false) {
            if ($f === '.' || $f === '..') continue;
            if (stripos($f, '.pdf') !== strlen($f) - 4) continue;
            $full = $dir . DIRECTORY_SEPARATOR . $f;
            if (!is_file($full) || !is_readable($full)) continue;
            if (strpos($f, $prefix) === 0 || strpos($f, $prefixAlt) === 0) {
                $m = @filemtime($full);
                if ($m && $m > $foundMtime) {
                    $foundMtime = $m;
                    $foundPath = $full;
                }
            }
        }
        closedir($d);
    }
    return $foundPath;
}

function send_original_pdf_not_found_html(): void {
    header('Content-Type: text/html; charset=utf-8');
    header('HTTP/1.1 200 OK');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>簽名前 PDF</title></head><body style="font-family: sans-serif; padding: 2em; text-align: center; color: #666; background: #1a1a1a;"><p>簽名前 PDF 未儲存或檔案遺失</p></body></html>';
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT original_pdf_path FROM document_submissions
        WHERE sub_ID = ? AND doc_ID = ?
    ");
    $stmt->execute([$submission_id, $doc_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        send_original_pdf_not_found_html();
    }
    $filePath = trim((string)($row['original_pdf_path'] ?? ''));
    // 僅標記為已產生、無實體路徑時，改依慣例尋找檔案
    $isGeneratedOnly = ($filePath === '' || strtoupper($filePath) === 'GENERATED');

    $fullPath = null;
    if ($filePath !== '' && strtoupper($filePath) !== 'GENERATED') {
        $pathNorm = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath), '/\\');
        $fullPath = projectevery_full_path($filePath);
        if (!is_file($fullPath) || !is_readable($fullPath)) {
            if (!empty($_SERVER['DOCUMENT_ROOT'])) {
                $fallbackPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . DIRECTORY_SEPARATOR . $pathNorm;
                if (is_file($fallbackPath) && is_readable($fallbackPath)) {
                    $fullPath = $fallbackPath;
                }
            }
            if ((!is_file($fullPath) || !is_readable($fullPath)) && !empty(__DIR__)) {
                $scriptRoot = dirname(__DIR__);
                $fallbackPath = rtrim($scriptRoot, '/\\') . DIRECTORY_SEPARATOR . $pathNorm;
                if (is_file($fallbackPath) && is_readable($fallbackPath)) {
                    $fullPath = $fallbackPath;
                }
            }
        }
    }
    if ((!$fullPath || !is_file($fullPath) || !is_readable($fullPath)) && !$isGeneratedOnly) {
        $fullPath = null;
    }
    if (!$fullPath || !is_file($fullPath) || !is_readable($fullPath)) {
        $fullPath = find_original_pdf_by_convention($submission_id);
    }
    if (!$fullPath || !is_file($fullPath) || !is_readable($fullPath)) {
        // 有該筆 submission 但實體檔不存在（路徑遺失、未產生、或僅標記 GENERATED）：改導向表單預覽頁，以 dcsub_answers 即時顯示簽名前內容，避免審核時左側全黑
        $url = 'document_form_pdf.php?document_id=' . (int)$doc_ID . '&submission_id=' . (int)$submission_id . '&iframe_preview=1&original_only=1#zoom=70';
        header('Location: ' . $url, true, 302);
        exit;
    }
    $displayName = basename($filePath) ?: 'original.pdf';
    if ($filePath === '' || strtoupper($filePath) === 'GENERATED') {
        $displayName = basename($fullPath) ?: 'original.pdf';
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . rawurlencode($displayName) . '"');
    header('Cache-Control: private, max-age=3600');
    readfile($fullPath);
    exit;
} catch (Throwable $e) {
    error_log('download_document_form_original_pdf.php: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    exit('讀取失敗');
}
