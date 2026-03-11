<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
require __DIR__ . '/../includes/pdo.php';
require_once __DIR__ . '/../includes/utils.php';
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

/**
 * 西元日期時間字串轉為 Y-m-d H:i（與 document_form_pdf.php 的 footer 顯示一致）
 */
function formatDateTimeForPdf($dt)
{
    if (empty($dt) || !is_string($dt))
        return '';
    $t = strtotime($dt);
    if ($t === false)
        return $dt;
    return date('Y-m-d H:i', $t);
}

/**
 * 取得組別與學號清單（逗號分隔），供三軌資訊顯示。
 * 依據 submission 擁有者的團隊資料推算。
 */
function snapshot_fetch_team_info(PDO $conn, ?string $userId): array
{
    $result = ['group' => '', 'student_ids' => ''];
    if (!$userId) {
        return $result;
    }
    try {
        $teamUserField = 'team_u_ID';
        $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
        $checkStmt->execute();
        if (!$checkStmt->fetch()) {
            $teamUserField = 'u_ID';
        }

        // 先找出使用者所屬的有效團隊與組別
        $stmt = $conn->prepare("
            SELECT t.team_ID, t.group_ID
            FROM teammember tm
            INNER JOIN teamdata t ON tm.team_ID = t.team_ID
            WHERE tm.{$teamUserField} = ? AND tm.tm_status = 1 AND t.team_status = 1
            ORDER BY t.team_update_d DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $teamRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$teamRow) {
            return $result;
        }

        $group = isset($teamRow['group_ID']) ? trim((string) $teamRow['group_ID']) : '';

        // 學號欄位：有 u_account 就用學號欄；否則使用 u_ID
        $studentIdCol = 'u.u_ID';
        $orderByCol = 'u.u_ID';
        $checkAccount = $conn->prepare("SHOW COLUMNS FROM userdata LIKE 'u_account'");
        $checkAccount->execute();
        if ($checkAccount->fetch()) {
            $studentIdCol = 'u.u_account';
            $orderByCol = 'u.u_account';
        }

        $stmt = $conn->prepare("
            SELECT {$studentIdCol} as student_id
            FROM teammember tm
            INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
            INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
            WHERE tm.team_ID = ?
              AND tm.tm_status = 1 AND ur.role_ID = 6 AND ur.user_role_status = 1
            ORDER BY {$orderByCol}
        ");
        $stmt->execute([$teamRow['team_ID']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $studentIds = [];
        foreach ($rows as $r) {
            $sid = trim((string) ($r['student_id'] ?? ''));
            if ($sid !== '') {
                $studentIds[] = $sid;
            }
        }

        $result['group'] = $group;
        $result['student_ids'] = implode(',', $studentIds);
    } catch (Throwable $e) {
        // 任何錯誤都不阻擋主流程，維持預設空字串
    }
    return $result;
}

$u_ID = $_SESSION['u_ID'] ?? '';
if ($u_ID === '')
    json_err('請先登入', 'NO_LOGIN', 401);

$sub_ID = isset($_POST['sub_ID']) ? (int) $_POST['sub_ID'] : 0;
if ($sub_ID <= 0) {
    json_err('缺少或無效的 sub_ID', 'BAD_SUB_ID', 400);
}

// 1) 查 submission（要 snapshot_token、dcsub_updated_d；qr_modified_at 選用）
$selCols = 'sub_ID, doc_ID, dcsub_u_ID, dcsub_updated_d, dcsu_updated_d, dcsub_sub_d, snapshot_token';
if (function_exists('document_submissions_has_column') && document_submissions_has_column($conn, 'qr_modified_at')) {
    $selCols .= ', qr_modified_at';
}
$stmt = $conn->prepare("SELECT {$selCols} FROM document_submissions WHERE sub_ID = ? LIMIT 1");
$stmt->execute([$sub_ID]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    json_err('找不到 submission', 'NOT_FOUND', 404);
}

// doc_ID 一律以 DB 為準，避免前端傳錯
$doc_ID = (int) ($row['doc_ID'] ?? 0);
if ($doc_ID <= 0) {
    json_err('submission 缺少有效的 doc_ID', 'BAD_DOC_ID', 500);
}

// 2) 權限（至少要本人/同組才可鎖定）
// 這裡先做最小：本人才能鎖定
if ((string) $row['dcsub_u_ID'] !== (string) $u_ID) {
    json_err('無權限鎖定此文件', 'FORBIDDEN', 403);
}

// 2-1) 取得本筆 submission 的「最後提交時間」與三軌用的組別/學號資訊
$approved_d = isset($row['dcsub_sub_d']) ? trim((string) $row['dcsub_sub_d']) : '';
$owner_u_ID = (string) ($row['dcsub_u_ID'] ?? $u_ID);
$teamInfo = snapshot_fetch_team_info($conn, $owner_u_ID);
$group_no = $teamInfo['group'] ?? '';
$student_ids_str = $teamInfo['student_ids'] ?? '';

// snapshot_token 以「暫存時寫入的版本」為準：有則從 DB 讀取；無則以 sub_ID|doc_ID|隨機值 產生（不再依賴 qr_modified_at）
$db_snapshot_token = isset($row['snapshot_token']) ? trim((string) $row['snapshot_token']) : '';
$snapshot_token = $db_snapshot_token !== ''
    ? $db_snapshot_token
    : hash('sha256', $sub_ID . '|' . $doc_ID . '|' . bin2hex(random_bytes(16)));
// 快照時間僅用於 PDF 內顯示（若欄位已移除則省略）；original_pdf_hash 改用 snapshot_token
$snapshot_time = $row['dcsub_updated_d'] ?? $row['dcsu_updated_d'] ?? date('Y-m-d H:i:s');
$original_pdf_hash = hash('sha256', 'original|' . $sub_ID . '|' . $doc_ID . '|' . $snapshot_token);

// 4) 使用 mPDF 產生簽名前 PDF（僅作為 original_pdf_path 快照），不再產生 QR Code

// 嘗試載入 Composer autoload 與 mPDF 類別
$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!class_exists('\Mpdf\Mpdf')) {
    if (is_file($vendorAutoload)) {
        require_once $vendorAutoload;
    }
}
if (!class_exists('\Mpdf\Mpdf')) {
    json_err('伺服器尚未安裝 mPDF（請先在專案根目錄執行 composer install）', 'MPDF_NOT_INSTALLED', 500);
}

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 20,
        'margin_bottom' => 20,
    ]);
} catch (\Throwable $e) {
    json_err('初始化 mPDF 失敗：' . $e->getMessage(), 'MPDF_INIT_FAIL', 500);
}

// 簽名前 PDF：顯示基本資訊，並在最後一頁以可選取文字列出 SNAPSHOT_TOKEN / SUB_ID
$html = '<html><head><meta charset="utf-8"></head><body>';
$html .= '<h2 style="text-align:center;">簽名前 PDF（伺服器快照）</h2>';
$html .= '<p>sub_ID：' . htmlspecialchars((string) $sub_ID, ENT_QUOTES, 'UTF-8') . '</p>';
$html .= '<p>doc_ID：' . htmlspecialchars((string) $doc_ID, ENT_QUOTES, 'UTF-8') . '</p>';

// 最後一頁：SNAPSHOT 驗證頁（全部為可選取文字）
$html .= '<pagebreak />';
$html .= '<h3 style="text-align:left; font-weight:bold;">SNAPSHOT 驗證頁</h3>';
$lastModified = formatDateTimeForPdf($snapshot_time);
$groupCodeText = $group_no !== '' ? $group_no : '—';
$studentIdsText = $student_ids_str !== '' ? $student_ids_str : '—';

// 版面格式：
// SNAPSHOT_TOKEN: {snapshot_token}
// ORIGINAL_PDF_HASH: {original_pdf_hash}
// 最後修改時間: {qr_modified_at}
// 組別編號: {group_code}
// 組員學號: {student_ids}
$html .= '<p>SNAPSHOT_TOKEN: ' . htmlspecialchars($snapshot_token, ENT_QUOTES, 'UTF-8') . '</p>';
$html .= '<p>ORIGINAL_PDF_HASH: ' . htmlspecialchars($original_pdf_hash, ENT_QUOTES, 'UTF-8') . '</p>';
$html .= '<p>最後修改時間: ' . htmlspecialchars($lastModified !== '' ? $lastModified : '—', ENT_QUOTES, 'UTF-8') . '</p>';
$html .= '<p>組別編號: ' . htmlspecialchars($groupCodeText, ENT_QUOTES, 'UTF-8') . '</p>';
$html .= '<p>組員學號: ' . htmlspecialchars($studentIdsText, ENT_QUOTES, 'UTF-8') . '</p>';
$html .= '</body></html>';

// 寫入 HTML 產生 PDF
try {
    $mpdf->WriteHTML($html);
    $pdfBytes = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
} catch (\Throwable $e) {
    json_err('產生簽名前PDF失敗（mPDF 錯誤）：' . $e->getMessage(), 'PDF_BUILD_FAIL', 500);
}

// 5) 存檔到 uploads/original/，使用唯一檔名：sub_{sub_ID}_original_{timestamp}.pdf
$timestamp = time();
$filename = 'sub_' . $sub_ID . '_original_' . $timestamp . '.pdf';
$relPath = 'uploads/original/' . $filename;
$absPath = projectevery_full_path($relPath);
$dir = dirname($absPath);
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
$bytesWritten = @file_put_contents($absPath, $pdfBytes);

if ($bytesWritten === false || $bytesWritten <= 0 || !is_file($absPath)) {
    json_err('產生簽名前PDF失敗（檔案無法寫入）', 'PDF_WRITE_FAIL', 500);
}

// 6) UPDATE DB：寫入 original_pdf_path / snapshot_token（qr_modified_at 改為選用，核實僅依 snapshot_token）
$hasQr = function_exists('document_submissions_has_column') ? document_submissions_has_column($conn, 'qr_modified_at') : false;
try {
    $colStmt = $conn->prepare("SHOW COLUMNS FROM document_submissions LIKE 'original_pdf_hash'");
    $colStmt->execute();
    $hasOriginalHashCol = $colStmt->fetch() !== false;
} catch (Throwable $e) {
    $hasOriginalHashCol = false;
}

$setParts = ['original_pdf_path = :p', 'snapshot_token = :k', 'verify_result = 0', 'verify_note = NULL'];
$params = [':p' => $relPath, ':k' => $snapshot_token, ':sid' => $sub_ID];
if ($hasQr) {
    $setParts[] = 'qr_modified_at = :t';
    $params[':t'] = $snapshot_time;
}
if ($hasOriginalHashCol) {
    $setParts[] = 'original_pdf_hash = :h';
    $params[':h'] = $original_pdf_hash;
}
$setClause = implode(', ', $setParts);
$up = $conn->prepare("UPDATE document_submissions SET {$setClause} WHERE sub_ID = :sid");
$up->execute($params);

if ($up->rowCount() === 0) {
    json_err('定版寫入失敗：sub_ID 不存在或未更新', 'UPDATE_FAILED', 500);
}

json_ok([
    'sub_ID' => $sub_ID,
    'original_pdf_path' => $relPath,
    'qr_modified_at' => $hasQr ? $snapshot_time : null,
    'snapshot_token' => $snapshot_token
]);