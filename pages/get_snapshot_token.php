<?php
// 取得指定 submission 的 snapshot_token，供前端在簽名上傳前補齊核實所需識別碼使用
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../includes/pdo.php';
require_once __DIR__ . '/../includes/utils.php';
date_default_timezone_set('Asia/Taipei');

header('Content-Type: application/json; charset=utf-8');

function gst_json_ok(array $data = []): void
{
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function gst_json_err(string $msg, string $code = 'ERR', int $http = 400): void
{
    http_response_code($http);
    echo json_encode(['ok' => false, 'code' => $code, 'msg' => $msg, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$u_ID = $_SESSION['u_ID'] ?? null;
if (!$u_ID) {
    gst_json_err('請先登入', 'NOT_LOGGED_IN', 401);
}

$sub_ID = isset($_GET['sub_ID']) ? (int) $_GET['sub_ID'] : (isset($_POST['sub_ID']) ? (int) $_POST['sub_ID'] : 0);
if ($sub_ID <= 0) {
    gst_json_err('缺少或無效的 sub_ID', 'BAD_SUB_ID', 400);
}

try {
    $stmt = $conn->prepare("
        SELECT sub_ID, doc_ID, dcsub_u_ID, snapshot_token
        FROM document_submissions
        WHERE sub_ID = ?
        LIMIT 1
    ");
    $stmt->execute([$sub_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    gst_json_err('查詢提交記錄失敗：' . $e->getMessage(), 'DB_ERROR', 500);
}

if (!$row || (int) $row['sub_ID'] !== $sub_ID) {
    gst_json_err('找不到對應的提交記錄', 'SUBMISSION_NOT_FOUND', 404);
}

// 簡單權限檢查：只允許本人或同組組員取得（與 verify_sign.php 一致）
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

        if (
            !$myTeam || !$rowTeam ||
            (int) ($myTeam['team_ID'] ?? 0) !== (int) ($rowTeam['team_ID'] ?? 0)
        ) {
            gst_json_err('您沒有權限查看此提交記錄', 'FORBIDDEN', 403);
        }
    } catch (Throwable $e) {
        gst_json_err('您沒有權限查看此提交記錄', 'FORBIDDEN', 403);
    }
}

$token = isset($row['snapshot_token']) ? trim((string) $row['snapshot_token']) : '';

gst_json_ok([
    'sub_ID' => (int) $row['sub_ID'],
    'doc_ID' => (int) $row['doc_ID'],
    'snapshot_token' => $token,
]);

