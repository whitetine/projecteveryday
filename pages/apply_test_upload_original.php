<?php
/**
 * API：上傳簽名前 PDF（original_pdf）並寫入 original_pdf_path / qr_modified_at。
 * 由前端以 POST + sub_ID, doc_ID, original_pdf 呼叫。
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/../includes/pdo.php';
require_once __DIR__ . '/../includes/snapshot_config.php';
date_default_timezone_set('Asia/Taipei');
header('Content-Type: application/json; charset=utf-8');

function json_ok($data = [])
{
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($msg, $code = 'ERR', $http = 400)
{
    http_response_code($http);
    echo json_encode(['ok' => false, 'code' => $code, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$u_ID = $_SESSION['u_ID'] ?? '';
if ($u_ID === '')
    json_err('請先登入', 'NO_LOGIN', 401);

$sub_ID = (int) ($_POST['sub_ID'] ?? 0);
$doc_ID = (int) ($_POST['doc_ID'] ?? 0);
if ($doc_ID <= 0)
    json_err('缺少 doc_ID', 'BAD_PARAMS', 400);

// 若前端未傳 sub_ID 或為 0，依 doc_ID + 登入者查詢目前草稿/最新一筆的 sub_ID（與 get_draft 一致）
if ($sub_ID <= 0) {
    $stmtResolve = $conn->prepare("
      SELECT sub_ID, dcsub_updated_d, dcsu_updated_d
      FROM document_submissions
      WHERE doc_ID = ? AND dcsub_u_ID = ? AND (dcsub_status = 4 OR dcsub_status = 1)
      ORDER BY COALESCE(dcsub_updated_d, dcsu_updated_d) DESC, sub_ID DESC
      LIMIT 1
    ");
    $stmtResolve->execute([$doc_ID, $u_ID]);
    $resolveRow = $stmtResolve->fetch(PDO::FETCH_ASSOC);
    if ($resolveRow && !empty($resolveRow['sub_ID'])) {
        $sub_ID = (int) $resolveRow['sub_ID'];
    } else {
        json_err('請先暫存表單後再產生／上傳簽名前 PDF', 'NO_DRAFT', 400);
    }
}

$stmt = $conn->prepare("
  SELECT sub_ID, doc_ID, dcsub_u_ID,
         dcsub_updated_d, dcsu_updated_d
  FROM document_submissions
  WHERE sub_ID = ? AND doc_ID = ?
  LIMIT 1
");
$stmt->execute([$sub_ID, $doc_ID]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row)
    json_err('找不到 submission（sub_ID/doc_ID 不匹配）', 'NOT_FOUND', 404);

if ((string) $row['dcsub_u_ID'] !== (string) $u_ID) {
    json_err('無權限鎖定此文件', 'FORBIDDEN', 403);
}

if (!isset($_FILES['original_pdf']) || $_FILES['original_pdf']['error'] !== UPLOAD_ERR_OK) {
    json_err('缺少簽名前PDF檔案（original_pdf）', 'NO_FILE', 400);
}
$f = $_FILES['original_pdf'];
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
if ($ext !== 'pdf')
    json_err('只允許上傳 PDF', 'BAD_FILE', 400);

// 存檔至 uploads/original/sub_{sub_ID}_original.pdf（每次產生簽名前 PDF 都寫入並更新 DB）
$dir = __DIR__ . '/../uploads/original';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
$filename = 'sub_' . $sub_ID . '_original.pdf';
$absPath = $dir . '/' . $filename;

if (!move_uploaded_file($f['tmp_name'], $absPath) || !is_file($absPath)) {
    json_err('存檔失敗', 'SAVE_FAIL', 500);
}

$relPath = 'uploads/original/' . $filename;

// 以 snapshot_token 為準：前端必傳；未傳則依 sub_ID|doc_ID|隨機值產生（不再依賴 qr_modified_at）
$snapshot_token = trim((string) ($_POST['snapshot_token'] ?? ''));
if ($snapshot_token === '') {
    $snapshot_token = hash('sha256', (string) $sub_ID . '|' . $doc_ID . '|' . bin2hex(random_bytes(16)));
}

$hasQr = function_exists('document_submissions_has_column') ? document_submissions_has_column($conn, 'qr_modified_at') : false;
if ($hasQr) {
    $up = $conn->prepare("UPDATE document_submissions SET original_pdf_path = ?, qr_modified_at = ?, snapshot_token = ?, verify_result = 0 WHERE sub_ID = ? AND doc_ID = ?");
    $up->execute([$relPath, date('Y-m-d H:i:s'), $snapshot_token, $sub_ID, $doc_ID]);
} else {
    $up = $conn->prepare("UPDATE document_submissions SET original_pdf_path = ?, snapshot_token = ?, verify_result = 0 WHERE sub_ID = ? AND doc_ID = ?");
    $up->execute([$relPath, $snapshot_token, $sub_ID, $doc_ID]);
}

json_ok([
    'original_pdf_path' => $relPath,
    'snapshot_token' => $snapshot_token,
    'sub_ID' => $sub_ID,
    'rows_affected' => $up->rowCount()
]);
