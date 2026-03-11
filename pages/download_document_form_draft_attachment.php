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

try {
    $row = null;
    // 同組共用草稿：先查同組任一人之記錄，無則再查本人
    try {
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
                    SELECT attach_name, attach_path
                    FROM document_submissions
                    WHERE doc_ID = ? AND dcsub_u_ID IN ($ph)
                      AND (dcsub_status = 'draft' OR dcsub_status = 0 OR dcsub_status = 4 OR dcsub_status = 1)
                    ORDER BY sub_ID DESC
                    LIMIT 1
                ");
                $stmt->execute(array_merge([$doc_ID], $memberIds));
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    } catch (Throwable $e) {
        $row = null;
    }
    if (!$row) {
        try {
            $stmt = $conn->prepare("
                SELECT attach_name, attach_path
                FROM document_submissions
                WHERE doc_ID = ? AND dcsub_u_ID = ?
                  AND (dcsub_status = 'draft' OR dcsub_status = 0 OR dcsub_status = 4 OR dcsub_status = 1)
                ORDER BY sub_ID DESC
                LIMIT 1
            ");
            $stmt->execute([$doc_ID, $u_ID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $stmt = $conn->prepare("
                SELECT attach_path
                FROM document_submissions
                WHERE doc_ID = ? AND dcsub_u_ID = ?
                  AND (dcsub_status = 'draft' OR dcsub_status = 0 OR dcsub_status = 4 OR dcsub_status = 1)
                ORDER BY sub_ID DESC
                LIMIT 1
            ");
            $stmt->execute([$doc_ID, $u_ID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    if (!$row || empty($row['attach_path'])) {
        header('HTTP/1.1 404 Not Found');
        exit('無此附件');
    }
    $fullPath = projectevery_full_path($row['attach_path']);
    if (!is_file($fullPath) || !is_readable($fullPath)) {
        header('HTTP/1.1 404 Not Found');
        exit('檔案不存在');
    }
    $displayName = !empty($row['attach_name']) ? $row['attach_name'] : (basename($row['attach_path']) ?: '附件.pdf');
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
