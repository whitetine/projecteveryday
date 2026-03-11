<?php
// 即時簽名上傳 + 核實 API（已不再顯示「版本碼」字樣）
// 接收: sub_ID, sign_pdf (multipart/form-data)
// 更新: document_submissions.sign_path, verify_result, verify_note（若欄位存在則寫入 sign_uploaded_d）
// 規則：
//   1. 定版時產生 snapshot_token，寫入 DB，並在簽名前 PDF 最後一頁以文字形式印出 SNAPSHOT_TOKEN=... / SUB_ID=...
//   2. 上傳簽名 PDF 時，不再使用前端傳入的 snapshot_token 作為核實依據
//   3. 僅從簽名 PDF 文字中解析 SNAPSHOT_TOKEN=XXXXXXXX（64位元十六進位），與 DB 的 snapshot_token 比對是否一致
//   4. 可選加強：若能成功取得簽名前 PDF 與簽名後 PDF 的頁數，頁數不同則視為不一致
//   5. 每次上傳都重新覆蓋 verify_result / verify_note，任何錯誤一律回傳 application/json，不輸出 HTML（避免 Unexpected token '<'）

// 關閉 PHP 預設錯誤輸出（改由 JSON handler 處理），並啟用輸出緩衝
ini_set('display_errors', '0');
ini_set('html_errors', '0');
if (ob_get_level() === 0) {
    ob_start();
}

// 本檔在 utils 載入前用的本地錯誤輸出（最後仍回 JSON）
if (!function_exists('json_err_local')) {
    function json_err_local(string $msg, string $code = 'ERROR', int $status = 500): void
    {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code($status);
        echo json_encode([
            'ok' => false,
            'status' => 'error',
            'code' => $code,
            'msg' => $msg,
            'message' => $msg,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // 把所有未捕捉的 PHP 錯誤轉成 JSON
    json_err_local("PHP 錯誤 ($errno): $errstr @ $errfile:$errline", 'PHP_ERROR', 500);
});

set_exception_handler(function (Throwable $e) {
    json_err_local('伺服器例外：' . $e->getMessage(), 'PHP_EXCEPTION', 500);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        json_err_local('伺服器錯誤：' . $error['message'], 'PHP_SHUTDOWN', 500);
    }
});

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../includes/pdo.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/snapshot_config.php';
require_once __DIR__ . '/../includes/verify_sign_ai_compare.php';
date_default_timezone_set('Asia/Taipei');

header('Content-Type: application/json; charset=utf-8');

// 僅允許登入使用者呼叫
$u_ID = $_SESSION['u_ID'] ?? null;
if (!$u_ID) {
    json_err('請先登入', 'NOT_LOGGED_IN', 401);
}

// 僅接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('僅接受 POST 請求', 'METHOD_NOT_ALLOWED', 405);
}

// 取得 sub_ID
$sub_ID = isset($_POST['sub_ID']) ? (int) $_POST['sub_ID'] : 0;
if ($sub_ID <= 0) {
    json_err('缺少或無效的 sub_ID', 'INVALID_SUB_ID', 400);
}

// 檢查是否有簽名檔
if (empty($_FILES['sign_pdf']) || $_FILES['sign_pdf']['error'] !== UPLOAD_ERR_OK) {
    json_err('請上傳簽名 PDF 檔', 'NO_FILE', 400);
}

// 查 DB：確認 submission 存在、權限、取得 snapshot_token / original_pdf_path / original_pdf_hash（若欄位存在）
try {
    $stmt = $conn->prepare("
        SELECT sub_ID,
               doc_ID,
               dcsub_u_ID,
               snapshot_token,
               original_pdf_path,
               original_pdf_hash
        FROM document_submissions
        WHERE sub_ID = ?
        LIMIT 1
    ");
    $stmt->execute([$sub_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // 若發生 Unknown column 'original_pdf_hash'，表示 DB 尚未加上該欄位，改用不含此欄位的查詢以維持相容
    try {
        $stmt = $conn->prepare("
            SELECT sub_ID,
                   doc_ID,
                   dcsub_u_ID,
                   snapshot_token,
                   original_pdf_path
            FROM document_submissions
            WHERE sub_ID = ?
            LIMIT 1
        ");
        $stmt->execute([$sub_ID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        json_err('查詢提交記錄失敗：' . $e2->getMessage(), 'DB_ERROR', 500);
    }
}

if (!$row || (int) $row['sub_ID'] !== $sub_ID) {
    json_err('找不到對應的提交記錄', 'SUBMISSION_NOT_FOUND', 404);
}

// 允許本人或同組組員操作（同組共用草稿）
if ((string) $row['dcsub_u_ID'] !== (string) $u_ID) {
    try {
        $teamUserField = 'team_u_ID';
        $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
        $checkStmt->execute();
        if (!$checkStmt->fetch()) {
            $teamUserField = 'u_ID';
        }
        $stmtMyTeam = $conn->prepare("SELECT team_ID FROM teammember WHERE {$teamUserField} = ? AND (tm_status = 1 OR tm_status IS NULL) LIMIT 1");
        $stmtMyTeam->execute([$u_ID]);
        $myTeam = $stmtMyTeam->fetch(PDO::FETCH_ASSOC);
        $stmtRowTeam = $conn->prepare("SELECT team_ID FROM teammember WHERE {$teamUserField} = ? AND (tm_status = 1 OR tm_status IS NULL) LIMIT 1");
        $stmtRowTeam->execute([$row['dcsub_u_ID']]);
        $rowTeam = $stmtRowTeam->fetch(PDO::FETCH_ASSOC);
        if (!$myTeam || !$rowTeam || (int) ($myTeam['team_ID'] ?? 0) !== (int) ($rowTeam['team_ID'] ?? 0)) {
            json_err('您沒有權限操作此提交記錄', 'FORBIDDEN', 403);
        }
    } catch (Throwable $e) {
        json_err('您沒有權限操作此提交記錄', 'FORBIDDEN', 403);
    }
}

// 驗證與儲存簽名檔（僅負責檔案格式與保存，不做內容解析）
$file = $_FILES['sign_pdf'];
$tmpPath = $file['tmp_name'];
$ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

if ($ext !== 'pdf') {
    json_err('簽名檔僅允許 PDF 格式', 'INVALID_EXT', 400);
}

// MIME 檢查（若伺服器未安裝 fileinfo 擴充，則略過 MIME 驗證避免發生致命錯誤）
$mime = null;
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath) ?: null;
    if ($mime !== null && $mime !== 'application/pdf' && strpos($mime, 'pdf') === false) {
        json_err('簽名檔僅允許 PDF 格式', 'INVALID_MIME', 400);
    }
}

// 儲存到 uploads/sign/ 目錄
$uploadDir = projectevery_full_path('uploads/sign');
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$safeName = 'sub_' . $sub_ID . '_u_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $u_ID) . '_' . time() . '.pdf';
$relativePath = 'uploads/sign/' . $safeName;
$fullPath = projectevery_full_path($relativePath);

if (!move_uploaded_file($tmpPath, $fullPath)) {
    json_err('簽名 PDF 儲存失敗', 'SAVE_FAILED', 500);
}

// 1) 先儲存 sign_path（sign_uploaded_d 僅在欄位存在時寫入）
try {
    $setClause = 'sign_path = ?';
    if (function_exists('document_submissions_has_column') && document_submissions_has_column($conn, 'sign_uploaded_d')) {
        $setClause .= ', sign_uploaded_d = NOW()';
    }
    $stmtUpInit = $conn->prepare("UPDATE document_submissions SET {$setClause} WHERE sub_ID = ?");
    $stmtUpInit->execute([$relativePath, $sub_ID]);
} catch (Throwable $e) {
    json_err('更新簽名資訊失敗：' . $e->getMessage(), 'DB_UPDATE_FAILED', 500);
}

// 2) 核實僅依 SNAPSHOT_TOKEN：verify_result 與 verify_note 由同一段 if/else 決定，一起寫回
//    DB 端與 PDF 端的 token 一律轉成小寫後比對
$dbSnapshotToken = isset($row['snapshot_token']) ? strtolower(trim((string) $row['snapshot_token'])) : '';
$originalRelPath = isset($row['original_pdf_path']) ? trim((string) $row['original_pdf_path']) : '';

$uploadedSnapshotToken = null;
$pdfText = '';
try {
    $pdfText = verify_sign_extract_pdf_text($fullPath, 24000);
} catch (Throwable $e) {
    $pdfText = '';
}
if ($pdfText !== '' && preg_match('/SNAPSHOT_TOKEN\s*[=:]\s*([a-f0-9]{64})/i', $pdfText, $m)) {
    $uploadedSnapshotToken = strtolower(trim($m[1]));
}

if ($dbSnapshotToken === '' || $uploadedSnapshotToken === null || $uploadedSnapshotToken === '') {
    $verify_result = 0;
    $verify_note = '無法核實';
} elseif (hash_equals($dbSnapshotToken, $uploadedSnapshotToken)) {
    $verify_result = 1;
    $verify_note = '核實結果一致';
} else {
    $verify_result = 2;
    $verify_note = '核實結果不一致';
}

$pdfToken = $uploadedSnapshotToken;

// 紀錄更新前的核實結果（除錯用）
$verify_result_before_update = $verify_result;
$verify_note_before_update = $verify_note;
$verify_result_after_update = null;
$verify_note_after_update = null;

error_log('[verify_sign] before update sub_ID=' . $sub_ID
    . ' dbSnapshotToken=' . $dbSnapshotToken
    . ' uploadedSnapshotToken=' . ($uploadedSnapshotToken ?? 'NULL')
    . ' verify_result_before=' . $verify_result_before_update
    . ' verify_note_before=' . $verify_note_before_update);

// 4) 寫回核實結果（每次上傳都重新覆蓋 verify_result / verify_note）
try {
    $stmtUp = $conn->prepare("
        UPDATE document_submissions
        SET verify_result = ?,
            verify_note   = ?
        WHERE sub_ID = ?
    ");
    $stmtUp->execute([$verify_result, $verify_note, $sub_ID]);
} catch (Throwable $e) {
    json_err('更新簽名核實結果失敗：' . $e->getMessage(), 'DB_UPDATE_FAILED', 500);
}

// 4b) 重新從 DB 讀回 verify_result / verify_note，確認是否被正確寫入或被其他流程改寫
try {
    $stmtCheck = $conn->prepare("
        SELECT verify_result, verify_note
        FROM document_submissions
        WHERE sub_ID = ?
        LIMIT 1
    ");
    $stmtCheck->execute([$sub_ID]);
    $rowCheck = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if ($rowCheck) {
        $verify_result_after_update = $rowCheck['verify_result'];
        $verify_note_after_update = $rowCheck['verify_note'];
    }
    error_log('[verify_sign] after update sub_ID=' . $sub_ID
        . ' verify_result_after=' . ($verify_result_after_update === null ? 'NULL' : $verify_result_after_update)
        . ' verify_note_after=' . ($verify_note_after_update === null ? 'NULL' : $verify_note_after_update));
} catch (Throwable $eCheck) {
    error_log('[verify_sign] check after update failed sub_ID=' . $sub_ID . ' msg=' . $eCheck->getMessage());
}

// 5) 回傳 JSON 給前端（前端依 verify_result 顯示 Swal 提示）
echo json_encode([
    'ok' => true,
    'verify_result' => $verify_result,
    'verify_note' => $verify_note,
    'snapshot_token_db' => $dbSnapshotToken,
    'snapshot_token_pdf' => $pdfToken,
    'verify_result_before_update' => $verify_result_before_update,
    'verify_note_before_update' => $verify_note_before_update,
    'verify_result_after_update' => $verify_result_after_update,
    'verify_note_after_update' => $verify_note_after_update,
    'api_file' => __FILE__
], JSON_UNESCAPED_UNICODE);
exit;

