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
if ($doc_ID <= 0) {
    header('HTTP/1.1 400 Bad Request');
    exit('參數錯誤');
}

try {
    $row = null;

    // 科辦／預覽：指定 submission_id 時，直接取該筆的 sign_path
    if ($submission_id > 0) {
        try {
            $stmt = $conn->prepare("
                SELECT sign_path FROM document_submissions
                WHERE sub_ID = ? AND doc_ID = ? AND sign_path IS NOT NULL AND sign_path != ''
            ");
            $stmt->execute([$submission_id, $doc_ID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('download_document_form_sign.php (submission_id): ' . $e->getMessage());
        }
    }

    // 未指定 submission_id 或該筆無 sign_path：與暫存邏輯一致，取該表單+同組任一人之最新一筆（同組共用草稿）
    if (!$row || empty(trim($row['sign_path'] ?? ''))) {
        $signRow = null;
        $teamUserField = 'team_u_ID';
        $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
        $checkStmt->execute();
        if (!$checkStmt->fetch()) {
            $teamUserField = 'u_ID';
        }
        $stmtTeam = $conn->prepare("SELECT team_ID FROM teammember WHERE {$teamUserField} = ? AND (tm_status = 1 OR tm_status IS NULL) LIMIT 1");
        $stmtTeam->execute([$u_ID]);
        $teamRow = $stmtTeam->fetch(PDO::FETCH_ASSOC);
        if ($teamRow && !empty($teamRow['team_ID'])) {
            $stmtMem = $conn->prepare("SELECT {$teamUserField} FROM teammember WHERE team_ID = ?");
            $stmtMem->execute([$teamRow['team_ID']]);
            $memberIds = array_values(array_filter(array_map('trim', array_column($stmtMem->fetchAll(PDO::FETCH_ASSOC), $teamUserField))));
            if (!empty($memberIds)) {
                $ph = implode(',', array_fill(0, count($memberIds), '?'));
                $stmt = $conn->prepare("
                    SELECT sign_path
                    FROM document_submissions
                    WHERE doc_ID = ? AND dcsub_u_ID IN ($ph)
                      AND sign_path IS NOT NULL AND sign_path != ''
                    ORDER BY dcsub_updated_d DESC, sub_ID DESC
                    LIMIT 1
                ");
                $stmt->execute(array_merge([$doc_ID], $memberIds));
                $signRow = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
        if (!$signRow) {
            try {
                $stmt = $conn->prepare("
                    SELECT sign_path
                    FROM document_submissions
                    WHERE doc_ID = ? AND dcsub_u_ID = ?
                      AND sign_path IS NOT NULL AND sign_path != ''
                    ORDER BY dcsub_updated_d DESC, sub_ID DESC
                    LIMIT 1
                ");
                $stmt->execute([$doc_ID, $u_ID]);
                $signRow = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e1) {
                error_log('download_document_form_sign.php query error (simple): ' . $e1->getMessage());
                try {
                    $stmt = $conn->prepare("
                        SELECT sign_path FROM document_submissions
                        WHERE doc_ID = ? AND dcsub_u_ID = ? ORDER BY sub_ID DESC LIMIT 20
                    ");
                    $stmt->execute([$doc_ID, $u_ID]);
                    $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($allRows as $r) {
                        if (!empty($r['sign_path']) && trim($r['sign_path']) !== '') {
                            $signRow = $r;
                            break;
                        }
                    }
                } catch (Throwable $e2) {
                    error_log('download_document_form_sign.php query error (fallback): ' . $e2->getMessage());
                    throw $e2;
                }
            }
        }
        if ($signRow && !empty(trim($signRow['sign_path'] ?? ''))) {
            $row = $signRow;
        }
    }

    // 如果還是找不到，嘗試不限制使用者（可能是管理員上傳的）
    if (!$row || empty($row['sign_path'])) {
        try {
            $stmt = $conn->prepare("
                SELECT sign_path
                FROM document_submissions
                WHERE doc_ID = ? 
                  AND sign_path IS NOT NULL 
                  AND sign_path != ''
                ORDER BY sub_ID DESC
                LIMIT 1
            ");
            $stmt->execute([$doc_ID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['sign_path'])) {
                error_log("download_document_form_sign.php: 找到簽名 PDF（不限制使用者）");
            }
        } catch (Throwable $e3) {
            // 忽略錯誤，繼續使用之前的結果
            error_log('download_document_form_sign.php query error (no user limit): ' . $e3->getMessage());
        }
    }
    
    if (!$row || empty($row['sign_path'])) {
        // 記錄除錯資訊（不顯示給使用者）
        error_log("download_document_form_sign.php: 找不到簽名 PDF (doc_ID=$doc_ID, u_ID=$u_ID)");
        // 回傳 200 + HTML，避免 iframe 在 console 顯示 404，使用者仍看到同一段說明
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>簽名後 PDF</title></head><body style="font-family: sans-serif; padding: 2em; text-align: center; color: #666;"><p>—</p></body></html>';
        exit;
    }
    
    $signPath = trim($row['sign_path']);
    $pathNorm = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $signPath), '/\\');
    $fullPath = projectevery_full_path($signPath);
    // 務必顯示檔案：依序嘗試 projecteverydays、DOCUMENT_ROOT、專案根目錄
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
    
    if (!is_file($fullPath) || !is_readable($fullPath)) {
        // 檔案真的不存在時，不要讓畫面「反黑」，改成在 iframe 裡顯示友善提示
        error_log("download_document_form_sign.php: 檔案不存在或不可讀 (fullPath=$fullPath)");
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>簽名後 PDF</title></head>'
           . '<body style="font-family: sans-serif; padding: 2em; text-align: center; color: #666; background:#f8f9fa;">'
           . '<h3 style="margin-bottom:1em;">尚未取得簽名後 PDF</h3>'
           . '<p style="margin-bottom:0.5em;">目前查無可預覽的簽名檔案，或檔案已被移動。</p>'
           . '<p style="font-size:0.9em;color:#999;">可直接依左側簽名前 PDF 內容進行核對與審核。</p>'
           . '</body></html>';
        exit;
    }
    // 從檔案路徑提取檔名，或使用預設名稱
    $displayName = basename($signPath);
    if (empty($displayName) || $displayName === $signPath) {
        $displayName = 'sign.pdf';
    }
    // 確保是 PDF 檔案
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . rawurlencode($displayName) . '"');
    header('Cache-Control: private, max-age=3600');
    readfile($fullPath);
    exit;
} catch (Throwable $e) {
    // 不論發生什麼錯誤，都不要讓 iframe 變成黑畫面，僅在背景記錄 log
    error_log('download_document_form_sign.php error: ' . $e->getMessage());
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>簽名後 PDF</title></head>'
       . '<body style="font-family: sans-serif; padding: 2em; text-align: center; color: #666; background:#f8f9fa;">'
       . '<h3 style="margin-bottom:1em;">簽名檔載入失敗</h3>'
       . '<p style="margin-bottom:0.5em;">系統在讀取簽名後 PDF 時發生錯誤。</p>'
       . '<p style="font-size:0.9em;color:#999;">您仍可依左側簽名前 PDF 內容進行核對與審核。</p>'
       . '</body></html>';
    exit;
}
