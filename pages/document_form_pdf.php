<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/../includes/pdo.php';
date_default_timezone_set('Asia/Taipei');

/**
 * 將 document_submissions 的 snapshot_token 補齊（核實僅依 token，不再依 qr_modified_at）。
 */
function _backfill_pdf_version_fields(PDO $conn, int $subId): void
{
    if ($subId <= 0)
        return;
    try {
        $stmt = $conn->prepare("SELECT doc_ID, snapshot_token FROM document_submissions WHERE sub_ID = ? LIMIT 1");
        $stmt->execute([$subId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) ($row['doc_ID'] ?? 0) <= 0) {
            return;
        }
        $docId = (int) $row['doc_ID'];
        $currentTok = trim((string) ($row['snapshot_token'] ?? ''));
        if ($currentTok === '') {
            $newTok = hash('sha256', $subId . '|' . $docId . '|' . bin2hex(random_bytes(16)));
            $conn->prepare("UPDATE document_submissions SET snapshot_token = ? WHERE sub_ID = ?")->execute([$newTok, $subId]);
        }
    } catch (Throwable $e) {
        error_log('backfill snapshot_token sub_ID=' . $subId . ': ' . $e->getMessage());
    }
}

$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;
$is_office = in_array((int) $role_ID, [1, 2]);
if (!$u_ID) {
    die('請先登入');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_doc_id = (int) ($_POST['document_id'] ?? $_POST['doc_ID'] ?? 0);
    if ($post_doc_id > 0) {
        $subId = (int) ($_POST['submission_id'] ?? 0);
        $prgKey = '_doc_pdf_prg_' . $post_doc_id . '_' . $subId;
        $_SESSION[$prgKey] = [
            'document_id' => $post_doc_id,
            'form_answers' => $_POST['form_answers'] ?? '',
            'apply_user' => $_POST['apply_user'] ?? '',
            'apply_other' => $_POST['apply_other'] ?? '',
        ];
        $prg_params = ['document_id' => $post_doc_id];
        if ($subId > 0) {
            $prg_params['submission_id'] = $subId;
        }
        if (!empty($_GET['download']) || !empty($_POST['download'])) {
            $prg_params['download'] = '1';
        }
        if (!empty($_GET['export']) || !empty($_POST['export'])) {
            $prg_params['export'] = '1';
        }
        $redirect_uri = strtok($_SERVER['REQUEST_URI'] ?? '/pages/document_form_pdf.php', '?');
        header('Location: ' . $redirect_uri . '?' . http_build_query($prg_params));
        exit;
    }
}

$document_id = (int) ($_GET['document_id'] ?? $_GET['doc_ID'] ?? $_POST['document_id'] ?? 0);
$submission_id_get = (int) ($_GET['submission_id'] ?? $_POST['submission_id'] ?? 0);
$submission_id = $submission_id_get;
$download = isset($_GET['download']) && $_GET['download'] === '1';
$export_mode = isset($_GET['export']) && $_GET['export'] === '1';

if ($document_id <= 0) {
    die('缺少參數：document_id');
}

// PDF 資料來源一律依 sub_ID 從資料庫讀取，不依賴 $_POST / $_SESSION，避免第二次暫存後導出內容消失
$form_answers = [];
$form_answers_from_db = false;
if ($submission_id_get > 0) {
    try {
        $stmtSub = $conn->prepare("SELECT sub_ID, dcsub_answers FROM document_submissions WHERE sub_ID = ? AND doc_ID = ?");
        $stmtSub->execute([$submission_id_get, $document_id]);
        $rowSub = $stmtSub->fetch(PDO::FETCH_ASSOC);
        if ($rowSub && !empty(trim((string)($rowSub['dcsub_answers'] ?? '')))) {
            $decoded = json_decode($rowSub['dcsub_answers'], true);
            if (is_array($decoded)) {
                $form_answers = $decoded;
                $form_answers_from_db = true;
            }
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('document_form_pdf: fetch dcsub_answers sub_ID=' . $submission_id_get . ' ' . $e->getMessage());
        }
    }
}
if (!$form_answers_from_db) {
    $rawAnswers = null;
    $prgKey = '_doc_pdf_prg_' . $document_id . '_' . $submission_id_get;
    if (!empty($_SESSION[$prgKey]) && ((int)($_SESSION[$prgKey]['document_id'] ?? 0) === $document_id)) {
        $prg = $_SESSION[$prgKey];
        unset($_SESSION[$prgKey]);
        $rawAnswers = $prg['form_answers'] ?? '';
    }
    if ($rawAnswers === null) {
        $rawAnswers = $_POST['form_answers'] ?? $_GET['form_answers'] ?? null;
    }
    if (!empty($rawAnswers)) {
        $answersStr = is_string($rawAnswers) ? $rawAnswers : (string) $rawAnswers;
        if (isset($_GET['form_answers'])) {
            $answersStr = urldecode($answersStr);
        }
        $decoded = json_decode($answersStr, true);
        if (is_array($decoded)) {
            $form_answers = $decoded;
        }
    }
}

$stmt = $conn->prepare('SELECT * FROM document_forms WHERE doc_ID = ?');
$stmt->execute([$document_id]);
$form = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    die('找不到該表單');
}

$owner_u_ID = $u_ID;

function fetch_project_data(PDO $conn, ?string $userId): array
{
    $data = ['has_team' => false, 'project_title' => '', 'students' => [], 'advisor' => '', 'group_ID' => null, 'team_ID' => null];
    if (!$userId) {
        return $data;
    }
    try {
        $teamUserField = 'team_u_ID';
        $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
        $checkStmt->execute();
        if (!$checkStmt->fetch()) {
            $teamUserField = 'u_ID';
        }
        $stmt = $conn->prepare("
            SELECT t.team_ID, t.team_project_name, t.group_ID
            FROM teammember tm
            INNER JOIN teamdata t ON tm.team_ID = t.team_ID
            WHERE tm.{$teamUserField} = ? AND tm.tm_status = 1 AND t.team_status = 1
            ORDER BY t.team_update_d DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $teamRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$teamRow) {
            return $data;
        }
        $data['has_team'] = true;
        $data['project_title'] = $teamRow['team_project_name'] ?? '';
        $data['team_ID'] = $teamRow['team_ID'] ?? null;
        $data['group_ID'] = $teamRow['group_ID'] ?? null;

        $studentIdCol = 'u.u_ID';
        $orderByCol = 'u.u_ID';
        $checkAccount = $conn->prepare("SHOW COLUMNS FROM userdata LIKE 'u_account'");
        $checkAccount->execute();
        if ($checkAccount->fetch()) {
            $studentIdCol = 'u.u_account';
            $orderByCol = 'u.u_account';
        }
        $stmt = $conn->prepare("
            SELECT u.u_ID, u.u_name, {$studentIdCol} as student_id
            FROM teammember tm
            INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
            INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
            WHERE tm.team_ID = ?
              AND tm.tm_status = 1 AND ur.role_ID = 6 AND ur.user_role_status = 1
            ORDER BY {$orderByCol}
        ");
        $stmt->execute([$teamRow['team_ID']]);
        $data['students'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $advisorName = '';
        $checkAdvisorStmt = $conn->prepare("SHOW COLUMNS FROM teamdata LIKE 'advisor%'");
        $checkAdvisorStmt->execute();
        if ($checkAdvisorStmt->fetch()) {
            $stmt = $conn->prepare("SELECT advisor FROM teamdata WHERE team_ID = ?");
            $stmt->execute([$teamRow['team_ID']]);
            $ar = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($ar['advisor'])) {
                $stmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                $stmt->execute([$ar['advisor']]);
                $au = $stmt->fetch(PDO::FETCH_ASSOC);
                $advisorName = $au['u_name'] ?? '';
            }
        } else {
            $stmt = $conn->prepare("
                SELECT u.u_name FROM teammember tm
                INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
                INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                WHERE tm.team_ID = ? AND tm.tm_status = 1 AND ur.role_ID = 4 AND ur.user_role_status = 1
                LIMIT 1
            ");
            $stmt->execute([$teamRow['team_ID']]);
            $au = $stmt->fetch(PDO::FETCH_ASSOC);
            $advisorName = $au['u_name'] ?? '';
        }
        $data['advisor'] = $advisorName;
    } catch (Throwable $e) {
        // 保持預設
    }
    return $data;
}

$schema = null;
if (!empty($form['form_schema'])) {
    $schema = json_decode($form['form_schema'], true);
}
$remark = '';
$questions = [];
if (is_array($schema)) {
    if (isset($schema['_remark'])) {
        $remark = $schema['_remark'] ?? '';
        $questions = $schema['questions'] ?? [];
    } else {
        $questions = $schema;
    }
}
$questions = is_array($questions) ? $questions : [];

// 依 order 排序
usort($questions, function ($a, $b) {
    return (int) ($a['order'] ?? 0) - (int) ($b['order'] ?? 0);
});

$doc_name = $form['doc_name'] ?? $form['document_name'] ?? '';
$doc_header = trim($form['doc_header'] ?? '');

// 頁尾：最後修改時間、最後提交時間（僅當 pdf_footer_timestamps 為 1 時顯示）
$pdf_footer_timestamps = 1;
if (is_array($schema) && isset($schema['pdf_footer_timestamps'])) {
    $pdf_footer_timestamps = (int) $schema['pdf_footer_timestamps'];
}
$footer_updated_d = $form['doc_updated_d'] ?? '';
$footer_sub_d = '';
$sub_ID_for_qr = null;
$pdf_version_hash_for_qr = '';
// submission_id 已於上方與 document_id 一併解析
// 學生（role_ID=6）帶著 submission_id 進入此頁時，自動下載 PDF
if (!$download && $submission_id > 0 && (int) ($role_ID ?? 0) === 6) {
    $download = true;
}
$sub_sign_path = '';
$sub_original_pdf_path = '';
if ($submission_id > 0) {
    $sub = null;
    try {
        $stmtSub = $conn->prepare("
            SELECT sub_ID, dcsub_u_ID, dcsub_answers, dcsub_updated_d, dcsu_updated_d, dcsub_sub_d, pdf_version_hash,
                   sign_path, original_pdf_path, attach_path
            FROM document_submissions
            WHERE sub_ID = ? AND doc_ID = ?
        ");
        $stmtSub->execute([$submission_id, $document_id]);
        $sub = $stmtSub->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        try {
            $stmtSub = $conn->prepare("
                SELECT sub_ID, dcsub_u_ID, dcsub_answers, dcsu_updated_d, dcsub_sub_d, pdf_version_hash, sign_path, original_pdf_path, attach_path
                FROM document_submissions WHERE sub_ID = ? AND doc_ID = ?
            ");
            $stmtSub->execute([$submission_id, $document_id]);
            $sub = $stmtSub->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) {
            try {
                $stmtSub = $conn->prepare("SELECT * FROM document_submissions WHERE sub_ID = ? AND doc_ID = ?");
                $stmtSub->execute([$submission_id, $document_id]);
                $sub = $stmtSub->fetch(PDO::FETCH_ASSOC);
                if ($sub !== false) {
                    $sub = array_merge($sub, [
                        'dcsub_answers' => $sub['dcsub_answers'] ?? $sub['icsub_answers'] ?? null,
                        'dcsub_updated_d' => $sub['dcsub_updated_d'] ?? $sub['icsub_updated_d'] ?? null,
                        'dcsub_sub_d' => $sub['dcsub_sub_d'] ?? $sub['icsub_sub_d'] ?? null,
                        'dcsub_u_ID' => $sub['dcsub_u_ID'] ?? $sub['icsub_u_ID'] ?? null,
                    ]);
                }
            } catch (Throwable $e3) {
            }
        }
    }
    if ($sub) {
        $footer_updated_d = trim((string) ($sub['dcsub_updated_d'] ?? $sub['dcsu_updated_d'] ?? $sub['icsub_updated_d'] ?? $form['doc_updated_d'] ?? ''));
        $footer_sub_d = trim((string) ($sub['dcsub_sub_d'] ?? $sub['icsub_sub_d'] ?? ''));
        $sub_ID_for_qr = (int) ($sub['sub_ID'] ?? $submission_id);
        $pdf_version_hash_for_qr = trim((string) ($sub['pdf_version_hash'] ?? ''));
        $sub_sign_path = trim((string) ($sub['sign_path'] ?? ''));
        $sub_original_pdf_path = trim((string) ($sub['original_pdf_path'] ?? ''));
        $sub_owner = $sub['dcsub_u_ID'] ?? $sub['icsub_u_ID'] ?? null;
        if ($sub_owner !== null && $sub_owner !== '') {
            $owner_u_ID = (string) $sub_owner;
        }
        $raw_answers = $sub['dcsub_answers'] ?? $sub['icsub_answers'] ?? null;
        if ($raw_answers !== null && trim((string) $raw_answers) !== '') {
            $decoded = json_decode($raw_answers, true);
            if (is_array($decoded)) {
                $form_answers = $decoded;
            }
        }
    }
}
$show_signed_pdf_preview = ($submission_id > 0 && $sub_sign_path !== '');
$original_only = (isset($_GET['original_only']) && $_GET['original_only'] === '1');
if ($original_only || $export_mode) {
    $show_signed_pdf_preview = false;
}
if ($sub_ID_for_qr === null && $owner_u_ID) {
    try {
        $stmtDraft = $conn->prepare("
            SELECT sub_ID, dcsub_answers, dcsub_updated_d, dcsu_updated_d, pdf_version_hash
            FROM document_submissions
            WHERE doc_ID = ? AND dcsub_u_ID = ? AND (dcsub_status = 4 OR dcsub_status = 1 OR dcsub_status = 0)
            ORDER BY COALESCE(dcsub_updated_d, dcsu_updated_d) DESC, sub_ID DESC LIMIT 1
        ");
        $stmtDraft->execute([$document_id, $owner_u_ID]);
        $draftRow = $stmtDraft->fetch(PDO::FETCH_ASSOC);
        if ($draftRow) {
            $draftUpdated = trim((string) ($draftRow['dcsub_updated_d'] ?? $draftRow['dcsu_updated_d'] ?? ''));
            if ($draftUpdated !== '')
                $footer_updated_d = $draftUpdated;
            $sub_ID_for_qr = (int) ($draftRow['sub_ID'] ?? 0);
            $pdf_version_hash_for_qr = trim((string) ($draftRow['pdf_version_hash'] ?? ''));
            if (empty($form_answers) && !empty($draftRow['dcsub_answers'])) {
                $decoded = json_decode($draftRow['dcsub_answers'], true);
                if (is_array($decoded)) {
                    $form_answers = $decoded;
                }
            }
        }
    } catch (Throwable $e) {
        try {
            $stmtDraft = $conn->prepare("
                SELECT sub_ID, dcsub_answers, dcsu_updated_d FROM document_submissions
                WHERE doc_ID = ? AND dcsub_u_ID = ? AND (dcsub_status = 4 OR dcsub_status = 1 OR dcsub_status = 0)
                ORDER BY dcsu_updated_d DESC LIMIT 1
            ");
            $stmtDraft->execute([$document_id, $owner_u_ID]);
            $draftRow = $stmtDraft->fetch(PDO::FETCH_ASSOC);
            if ($draftRow) {
                $draftUpdated = trim((string) ($draftRow['dcsu_updated_d'] ?? ''));
                if ($draftUpdated !== '')
                    $footer_updated_d = $draftUpdated;
                $sub_ID_for_qr = (int) ($draftRow['sub_ID'] ?? 0);
                if (empty($form_answers) && !empty($draftRow['dcsub_answers'])) {
                    $decoded = json_decode($draftRow['dcsub_answers'], true);
                    if (is_array($decoded)) {
                        $form_answers = $decoded;
                    }
                }
            }
        } catch (Throwable $e2) {
        }
    }
}
if ($sub_ID_for_qr) {
    _backfill_pdf_version_fields($conn, $sub_ID_for_qr);
    if ($pdf_version_hash_for_qr === '' && $owner_u_ID && $footer_updated_d) {
        $pdf_version_hash_for_qr = hash('sha256', (string) $document_id . (string) $owner_u_ID . (string) $footer_updated_d);
    }
    $version_payload = 'SUB:' . $sub_ID_for_qr;
    if ($pdf_version_hash_for_qr !== '') {
        $version_payload .= '|SIG:' . $pdf_version_hash_for_qr;
    }
} else {
    $version_payload = (string) $document_id . '|' . (string) ($footer_updated_d ?: ($form['doc_updated_d'] ?? ''));
}

// 有 submission 時帶出 snapshot_token，讓前端導出 PDF 在最後一頁印出 SNAPSHOT_TOKEN
$pdf_snapshot_token_for_footer = '';
$qr_modified_at_for_pdf = '';
if ($sub_ID_for_qr) {
    try {
        $stmtTok = $conn->prepare("SELECT snapshot_token FROM document_submissions WHERE sub_ID = ? LIMIT 1");
        $stmtTok->execute([$sub_ID_for_qr]);
        $rowTok = $stmtTok->fetch(PDO::FETCH_ASSOC);
        if ($rowTok && isset($rowTok['snapshot_token']) && trim((string) $rowTok['snapshot_token']) !== '') {
            $pdf_snapshot_token_for_footer = trim((string) $rowTok['snapshot_token']);
        } else {
            $tok_new = hash('sha256', (string) $sub_ID_for_qr . '|' . (int) $document_id . '|' . bin2hex(random_bytes(16)));
            $hasQr = function_exists('document_submissions_has_column') ? document_submissions_has_column($conn, 'qr_modified_at') : false;
            if ($hasQr) {
                $conn->prepare("UPDATE document_submissions SET qr_modified_at = NOW(), snapshot_token = ? WHERE sub_ID = ?")->execute([$tok_new, $sub_ID_for_qr]);
            } else {
                $conn->prepare("UPDATE document_submissions SET snapshot_token = ? WHERE sub_ID = ?")->execute([$tok_new, $sub_ID_for_qr]);
            }
            $pdf_snapshot_token_for_footer = $tok_new;
        }
    } catch (Throwable $e) {
        // ignore
    }
}

// 規則 1：PDF 三軌最後修改時間必須 = DB 最新 dcsub_updated_d，產 PDF時先 SELECT 最新再印上
if ($owner_u_ID) {
    try {
        $stmtLatest = $conn->prepare("
            SELECT dcsub_updated_d FROM document_submissions
            WHERE doc_ID = ? AND dcsub_u_ID = ?
            ORDER BY dcsub_updated_d DESC LIMIT 1
        ");
        $stmtLatest->execute([$document_id, $owner_u_ID]);
        $rowLatest = $stmtLatest->fetch(PDO::FETCH_ASSOC);
        if ($rowLatest && trim((string) ($rowLatest['dcsub_updated_d'] ?? '')) !== '') {
            $footer_updated_d = trim((string) $rowLatest['dcsub_updated_d']);
        }
    } catch (Throwable $e) {
        try {
            $stmtLatest = $conn->prepare("
                SELECT dcsu_updated_d FROM document_submissions
                WHERE doc_ID = ? AND dcsub_u_ID = ?
                ORDER BY dcsu_updated_d DESC LIMIT 1
            ");
            $stmtLatest->execute([$document_id, $owner_u_ID]);
            $rowLatest = $stmtLatest->fetch(PDO::FETCH_ASSOC);
            if ($rowLatest && trim((string) ($rowLatest['dcsu_updated_d'] ?? '')) !== '') {
                $footer_updated_d = trim((string) $rowLatest['dcsu_updated_d']);
            }
        } catch (Throwable $e2) {
        }
    }
}

$project_data = fetch_project_data($conn, $owner_u_ID);

// 科辦／依 submission 檢視時：若 fetch 不到專題團隊資料，改從該筆 dcsub_answers 補齊專題基本資料，避免簽名前預覽顯示「尚未完成專題資料設定」
if ($submission_id > 0 && !empty($form_answers) && empty($project_data['has_team'])) {
    $studentsFromAnswers = [];
    $advisorFromAnswers = '';
    $projectTitleFromAnswers = '';
    foreach ($form_answers as $key => $val) {
        if ($val === '' || $val === null)
            continue;
        $val = trim((string) $val);
        if ($val === '')
            continue;
        if (preg_match('/^q_(\d+)_student_(\d+)_id$/', $key, $m)) {
            $idx = (int) $m[2];
            if (!isset($studentsFromAnswers[$idx]))
                $studentsFromAnswers[$idx] = ['student_id' => '', 'u_name' => '', 'name' => ''];
            $studentsFromAnswers[$idx]['student_id'] = $val;
        } elseif (preg_match('/^q_(\d+)_student_(\d+)_name$/', $key, $m)) {
            $idx = (int) $m[2];
            if (!isset($studentsFromAnswers[$idx]))
                $studentsFromAnswers[$idx] = ['student_id' => '', 'u_name' => '', 'name' => ''];
            $studentsFromAnswers[$idx]['u_name'] = $val;
            $studentsFromAnswers[$idx]['name'] = $val;
        } elseif (preg_match('/^q_\d+_advisor$/', $key)) {
            if ($advisorFromAnswers === '')
                $advisorFromAnswers = $val;
        } elseif (strpos($key, 'project_title') !== false || strpos($key, '專題題目') !== false) {
            if ($projectTitleFromAnswers === '')
                $projectTitleFromAnswers = $val;
        }
    }
    ksort($studentsFromAnswers, SORT_NUMERIC);
    $studentsFromAnswers = array_values(array_filter($studentsFromAnswers, function ($s) {
        return trim($s['student_id'] ?? '') !== '' || trim($s['u_name'] ?? $s['name'] ?? '') !== '';
    }));
    if ($projectTitleFromAnswers === '' && isset($form_answers['q_0']) && is_string($form_answers['q_0'])) {
        $t = trim($form_answers['q_0']);
        if ($t !== '' && strlen($t) < 500)
            $projectTitleFromAnswers = $t;
    }
    if ($projectTitleFromAnswers === '' && isset($form_answers['q_1']) && is_string($form_answers['q_1'])) {
        $t = trim($form_answers['q_1']);
        if ($t !== '' && strlen($t) < 500)
            $projectTitleFromAnswers = $t;
    }
    if ($projectTitleFromAnswers !== '' || $advisorFromAnswers !== '' || count($studentsFromAnswers) > 0) {
        $project_data['has_team'] = true;
        if ($projectTitleFromAnswers !== '')
            $project_data['project_title'] = $projectTitleFromAnswers;
        if ($advisorFromAnswers !== '')
            $project_data['advisor'] = $advisorFromAnswers;
        if (count($studentsFromAnswers) > 0)
            $project_data['students'] = $studentsFromAnswers;
    }
}

// 構建 QR Code 內容：組別、學號、最後修改日期
$qr_group = '';
$qr_student_ids = '';
$qr_update_date = $footer_updated_d ?: ($form['doc_updated_d'] ?? '');

// 獲取組別和學號資訊
if ($project_data['has_team']) {
    // 組別（group_ID）
    if (!empty($project_data['group_ID'])) {
        $qr_group = (string) $project_data['group_ID'];
    }

    // 學號（所有組員的學號，用逗號分隔）
    $student_ids = [];
    if (!empty($project_data['students']) && is_array($project_data['students'])) {
        foreach ($project_data['students'] as $student) {
            $student_id = trim($student['student_id'] ?? $student['u_ID'] ?? '');
            if (!empty($student_id)) {
                $student_ids[] = $student_id;
            }
        }
    }
    if (!empty($student_ids)) {
        $qr_student_ids = implode(',', $student_ids);
    }
}

// QR 僅放 SUB:sub_ID（可選 |SIG:hash），不再帶 group/students/date；核實時以 DB 為準
if (!$sub_ID_for_qr) {
    $version_payload = 'DOC|doc=' . (string) $document_id;
    if ($qr_group !== '')
        $version_payload .= '|group=' . $qr_group;
    if ($qr_student_ids !== '')
        $version_payload .= '|students=' . $qr_student_ids;
    if ($qr_update_date !== '')
        $version_payload .= '|date=' . $qr_update_date;
}

function formatDateTimeForPdf($dt)
{
    if (empty($dt) || !is_string($dt))
        return '';
    $t = strtotime($dt);
    if ($t === false)
        return $dt;
    return date('Y-m-d H:i', $t);
}

// 西元日期時間 → 民國YYY年M月D日上午/下午H:MM（與申請頁系統資訊一致，供 PDF 頁尾顯示）
function formatDateTimeMinguoForPdf($dt)
{
    if (empty($dt) || !is_string($dt))
        return '';
    $t = strtotime($dt);
    if ($t === false)
        return $dt;
    $y = (int) date('Y', $t);
    $roc = $y - 1911;
    $month = (int) date('n', $t);
    $day = (int) date('j', $t);
    $h = (int) date('G', $t);
    $min = (int) date('i', $t);
    $ampm = $h < 12 ? '上午' : '下午';
    $h12 = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
    $minStr = str_pad((string) $min, 2, '0', STR_PAD_LEFT);
    return '民國' . $roc . '年' . $month . '月' . $day . '日' . $ampm . $h12 . ':' . $minStr;
}

// 附件處理：若已有學生補充附件，則不顯示表單預設附件，避免重複
if (!isset($supplement_pdf_url)) {
    $supplement_pdf_url = '';
}
$attachment_pdf_url = '';
if ($supplement_pdf_url === '') {
    try {
        $stmtAtt = $conn->prepare("SELECT file_path FROM document_form_attachments WHERE doc_ID = ?");
        $stmtAtt->execute([$document_id]);
        $att = $stmtAtt->fetch(PDO::FETCH_ASSOC);
        if ($att && !empty($att['file_path'])) {
            $attachment_pdf_url = 'download_document_form_attachment.php?doc_ID=' . (int) $document_id;
        }
    } catch (Throwable $e) {
    }
}

// 學生補充附件 URL（匯出／簽名前 PDF 皆自動合併於文件最後頁，僅 1 份 PDF），改由 document_submissions.attach_path 取得
$supplement_pdf_url = '';
if ($owner_u_ID) {
    // 1) 先看目前這筆 submission 是否已有 attach_path
    if (isset($sub) && !empty(trim((string) ($sub['attach_path'] ?? '')))) {
        $supplement_pdf_url = 'download_document_form_draft_attachment.php?doc_ID=' . (int) $document_id;
    }
    // 2) 若沒有，找同組／本人最新一筆有附件的 submission
    if ($supplement_pdf_url === '') {
        try {
            $stmtDraftAtt = $conn->prepare("SELECT attach_path FROM document_submissions WHERE doc_ID = ? AND dcsub_u_ID = ? AND (dcsub_status = 'draft' OR dcsub_status = 'submitted' OR dcsub_status = 0 OR dcsub_status = 1 OR dcsub_status = 4) AND attach_path IS NOT NULL AND attach_path != '' ORDER BY dcsub_updated_d DESC, sub_ID DESC LIMIT 1");
            $stmtDraftAtt->execute([$document_id, $owner_u_ID]);
            $draftAtt = $stmtDraftAtt->fetch(PDO::FETCH_ASSOC);
            if ($draftAtt && !empty(trim((string) $draftAtt['attach_path']))) {
                $supplement_pdf_url = 'download_document_form_draft_attachment.php?doc_ID=' . (int) $document_id;
            }
        } catch (Throwable $e) {
        }
    }
}
// 簽名前 PDF 預覽（iframe）：若尚未取得補充附件，依當前 submission 再查一次，確保預覽含「最後一頁」附件
if ($supplement_pdf_url === '' && $submission_id > 0) {
    try {
        $stmtSubAtt = $conn->prepare("SELECT attach_path FROM document_submissions WHERE sub_ID = ? AND doc_ID = ? LIMIT 1");
        $stmtSubAtt->execute([$submission_id, $document_id]);
        $rowSubAtt = $stmtSubAtt->fetch(PDO::FETCH_ASSOC);
        if ($rowSubAtt && !empty(trim((string) ($rowSubAtt['attach_path'] ?? '')))) {
            $supplement_pdf_url = 'download_document_form_draft_attachment.php?doc_ID=' . (int) $document_id;
        }
    } catch (Throwable $e) {
    }
}

// 將數字轉換為中文數字
// 西元 YYYY-MM-DD → 民國YYY年M月D日（僅顯示用，資料庫仍存西元）
function formatDateMinguo($ymd)
{
    if (empty($ymd) || !is_string($ymd))
        return '';
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $ymd, $m)) {
        $roc = (int) $m[1] - 1911;
        return '民國' . $roc . '年' . (int) $m[2] . '月' . (int) $m[3] . '日';
    }
    return $ymd;
}

function numberToChinese($num)
{
    $num = (int) $num;
    $chinese = ['', '一', '二', '三', '四', '五', '六', '七', '八', '九', '十'];
    if ($num <= 10 && $num >= 0) {
        return $chinese[$num];
    } elseif ($num < 20) {
        return '十' . ($num > 10 ? $chinese[$num % 10] : '');
    } elseif ($num < 100) {
        $tens = intval($num / 10);
        $ones = $num % 10;
        return $chinese[$tens] . '十' . ($ones > 0 ? $chinese[$ones] : '');
    }
    return (string) $num; // 超過100直接返回數字
}
$embed_mode = (isset($_GET['embed']) && $_GET['embed'] === '1');
$iframe_preview = (isset($_GET['iframe_preview']) && $_GET['iframe_preview'] === '1');
$body_class = trim(($embed_mode ? 'embed-doc-view' : '') . ($iframe_preview ? ' iframe-preview' : ''));
?>
<!DOCTYPE html>
<html lang="zh-TW">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($iframe_preview): ?>
        <meta name="color-scheme" content="light">
    <?php endif; ?>
    <title><?= htmlspecialchars($doc_name) ?> - PDF</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/document_form_pdf.css">
</head>
<body<?= $body_class !== '' ? ' class="' . htmlspecialchars($body_class) . '"' : '' ?>>

    <?php if (!$embed_mode): ?>
        <?php if ($show_signed_pdf_preview): ?>
            <div class="no-print pdf-actions">
                <a href="download_document_form_sign.php?doc_ID=<?= (int) $document_id ?>&submission_id=<?= (int) $submission_id ?>"
                    target="_blank" class="btn-export" download><i class="fa-solid fa-file-pdf me-1"></i> 下載簽名後 PDF</a>
            </div>
            <!-- 科辦／預覽：主內容 = 填寫完且簽完名的 PDF -->
            <div class="no-print signed-pdf-preview-wrap">
                <div class="preview-section-title"><i class="fa-solid fa-file-signature me-2"></i>預覽：填寫完且簽完名的 PDF</div>
                <iframe class="signed-main-iframe" title="簽名後 PDF"
                    src="download_document_form_sign.php?doc_ID=<?= (int) $document_id ?>&submission_id=<?= (int) $submission_id ?>#toolbar=0"></iframe>
                <div class="compare-section-title"><i class="fa-solid fa-code-compare me-2"></i>簽名前後對比</div>
                <div class="compare-pdf-container">
                    <div class="compare-pdf-item">
                        <h4>簽名前的 PDF（original_pdf_path）</h4>
                        <div class="compare-pdf-viewer">
                            <?php if ($sub_original_pdf_path !== ''): ?>
                                <iframe title="簽名前 PDF"
                                    src="download_document_form_original_pdf.php?doc_ID=<?= (int) $document_id ?>&submission_id=<?= (int) $submission_id ?>#toolbar=0"></iframe>
                            <?php else: ?>
                                <div class="compare-pdf-placeholder">—</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="compare-pdf-item">
                        <h4>簽名後的 PDF（sign_path）</h4>
                        <div class="compare-pdf-viewer">
                            <iframe title="簽名後 PDF"
                                src="download_document_form_sign.php?doc_ID=<?= (int) $document_id ?>&submission_id=<?= (int) $submission_id ?>#toolbar=0"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$embed_mode): ?>
        <!-- 預覽模式：顯示 PDF 內嵌預覽（有簽名後 PDF 時不顯示此區） -->
        <div id="pdfPreviewWrap" class="pdf-preview-wrap" style="display: none;">
            <iframe id="pdfPreviewFrame" title="PDF 預覽" style="width: 100%; height: 100%; border: none;"></iframe>
        </div>
        <div id="pdfGenerating" class="pdf-generating" style="display: none;">
            <i class="fa-solid fa-spinner fa-spin fa-2x"></i>
            <p>正在產生 PDF 預覽...</p>
        </div>
    <?php endif; ?>

    <div class="form-container" id="pdfContent" <?= (!$embed_mode && $show_signed_pdf_preview) ? ' style="display:none;"' : '' ?>>
        <?php if ($doc_name !== ''): ?>
            <div class="form-doc-name"><?= htmlspecialchars($doc_name) ?></div>
        <?php endif; ?>
        <?php if ($doc_header !== ''): ?>
            <?php
            // 在「XX科專題」前自動換行，如：資管科專題初審申請單
            $doc_header_display = preg_replace('/\s+([^\s]*科專題[^\s]*)/u', "\n$1", $doc_header);
            ?>
            <div class="form-header form-header-title">
                <?= nl2br(htmlspecialchars($doc_header_display)) ?>
            </div>
        <?php endif; ?>

        <!-- 專題基本資料（系統自動帶入，與圖1／學生端填寫頁一致，匯出 PDF 時顯示於最上方） -->
        <div class="pdf-project-basic">
            <div class="pdf-project-basic-title">專題基本資料</div>
            <?php if (!empty($project_data['has_team'])): ?>
                <div class="pdf-project-basic-row">
                    <span class="pdf-project-basic-label">專題題目</span>
                    <div class="pdf-project-basic-value"><?= htmlspecialchars($project_data['project_title'] ?? '（未填寫）') ?>
                    </div>
                </div>
                <div class="pdf-project-basic-row">
                    <span class="pdf-project-basic-label">專題生（學號、姓名）</span>
                    <div class="pdf-project-basic-value">
                        <?php
                        $students = $project_data['students'] ?? [];
                        if (!empty($students)) {
                            $parts = [];
                            foreach ($students as $s) {
                                $parts[] = htmlspecialchars(trim(($s['student_id'] ?? $s['u_ID'] ?? '') . ' ' . ($s['u_name'] ?? $s['name'] ?? '')));
                            }
                            echo implode('、', $parts);
                        } else {
                            echo '（尚無組員）';
                        }
                        ?>
                    </div>
                </div>
                <div class="pdf-project-basic-row">
                    <span class="pdf-project-basic-label">指導老師</span>
                    <div class="pdf-project-basic-value"><?= htmlspecialchars($project_data['advisor'] ?? '（未設定）') ?></div>
                </div>
            <?php else: ?>
                <div class="pdf-project-basic-value">尚未完成專題資料設定。</div>
            <?php endif; ?>
        </div>

        <?php if ($remark !== ''): ?>
            <div class="form-remark">
                <strong>備註：</strong><?= nl2br(htmlspecialchars($remark)) ?>
            </div>
        <?php endif; ?>

        <?php $displayIndex = 0;
        foreach ($questions as $i => $q):
            $title = $q['title'] ?? '';
            $type = $q['type'] ?? 'text';
            $special_field = $q['special_field'] ?? '';
            // 專題基本資料已於最上方統一顯示，跳過表單中的專題基本資料區塊題目
            if ($type === 'project_basic_block' || $special_field === 'project_basic') {
                continue;
            }
            // 專題生+指導老師題型已停用，表單頂部固定顯示，不再渲染為題目
            if ($type === 'students_advisor') {
                continue;
            }
            // 補充附件（附件（補充說明））改由 mergeSupplement 附加於 PDF 最後，不再於 HTML 中渲染，避免產生大量空白頁
            if (strpos($title, '附件') !== false && strpos($title, '補充說明') !== false) {
                continue;
            }
            $displayIndex++;
            $required = !empty($q['required']);
            $opts = $q['options'] ?? [];
            $is_sa = ($type === 'students_advisor');
            $is_textarea = ($type === 'textarea');
            $is_numbered_textarea = ($type === 'numbered_textarea');
            $is_table = ($type === 'table');
            $is_date = ($type === 'date');
            $rows = (int) ($q['rows'] ?? 5);
            if ($is_textarea && !empty($q['textarea_display']) && $q['textarea_display'] === 'large' && $rows < 20) {
                $rows = 20; // 大型敘述區匯出時至少 20 行空白
            }
            $students = $q['students'] ?? [];
            $advisor = $q['advisor'] ?? '';
            $advisor_field_type = $q['advisor_field_type'] ?? 'single';

            // 獲取填寫的答案
            $answerKey = 'q_' . ($q['order'] ?? $i);
            $answer = $form_answers[$answerKey] ?? '';
            $is_signature_title = (strpos($title, '指導老師評分與簽名') !== false);
            $is_review_score_title = (strpos($title, '審查小組評分') !== false);
            ?>
            <div
                class="question-block<?= ($is_textarea || $is_numbered_textarea || $is_table) ? ' question-block-longtext' : '' ?><?= $is_signature_title ? ' question-block-signature' : '' ?><?= $is_review_score_title ? ' question-block-review-score' : '' ?>">
                <div class="question-title">
                    <?= numberToChinese($displayIndex) ?>、 <?= htmlspecialchars($title) ?>
                </div>

                <?php if ($is_sa): ?>
                    <div class="students-advisor-pdf">
                        <div class="sa-label" style="margin-top: 8px;">專題生：</div>
                        <div class="sa-table-fixed-wrap">
                            <table class="sa-table">
                                <thead>
                                    <tr>
                                        <th style="width: 30%;">學號</th>
                                        <th style="width: 70%;">姓名</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // 從 form_answers 匯出專題生：key 格式為 q_N_student_0_id / q_N_student_0_name
                                    $studentRows = [];
                                    foreach ($form_answers as $key => $val) {
                                        if (strpos($key, $answerKey . '_student_') !== 0) {
                                            continue;
                                        }
                                        $parts = explode('_', $key);
                                        if (count($parts) < 5) {
                                            continue;
                                        }
                                        $idx = $parts[count($parts) - 2];
                                        $field = end($parts);
                                        if (!isset($studentRows[$idx])) {
                                            $studentRows[$idx] = ['student_id' => '', 'name' => ''];
                                        }
                                        if ($field === 'id') {
                                            $studentRows[$idx]['student_id'] = $val;
                                        } elseif ($field === 'name') {
                                            $studentRows[$idx]['name'] = $val;
                                        }
                                    }
                                    ksort($studentRows, SORT_NUMERIC);
                                    $studentRows = array_values($studentRows);
                                    if (empty($studentRows)) {
                                        $studentRows = is_array($students) && count($students) > 0 ? $students : [['student_id' => '', 'name' => '']];
                                    }
                                    while (count($studentRows) < 4) {
                                        $studentRows[] = ['student_id' => '', 'name' => ''];
                                    }
                                    if (count($studentRows) > 6) {
                                        $studentRows = array_slice($studentRows, 0, 6);
                                    }
                                    foreach ($studentRows as $idx => $s):
                                        $hasData = !empty($s['student_id']) || !empty($s['name']);
                                        ?>
                                        <tr>
                                            <td><?= $hasData ? '<strong>' . htmlspecialchars($s['student_id'] ?? '') . '</strong>' : '' ?>
                                            </td>
                                            <td><?= $hasData ? '<strong>' . htmlspecialchars($s['name'] ?? '') . '</strong>' : '' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="sa-label" style="margin-top: 12px;">指導老師：</div>
                        <?php
                        $advisorAnswer = $form_answers[$answerKey . '_advisor'] ?? $advisor;
                        ?>
                        <?php if ($advisor_field_type === 'signature'): ?>
                            <div class="signature-block">
                                <span>姓名：</span>
                                <?php if (!empty($advisorAnswer)): ?>
                                    <strong><?= htmlspecialchars($advisorAnswer) ?></strong>
                                <?php else: ?>
                                    <span class="signature-line"></span>
                                <?php endif; ?>
                                <span style="margin-left: 20px;">評分：</span>
                                <span class="signature-line"></span>
                            </div>
                        <?php else: ?>
                            <div class="advisor-field-fixed">
                                <?php if (!empty($advisorAnswer)): ?>
                                    <strong><?= htmlspecialchars($advisorAnswer) ?> 老師</strong>
                                <?php else: ?>
                                    <span class="question-line"></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($type === 'sub_questions'): ?>
                    <?php
                    $subAnswerRaw = $form_answers[$answerKey] ?? '';
                    if (($subAnswerRaw === '' || $subAnswerRaw === null) && !empty($q['subs']) && is_array($q['subs'])) {
                        $parts = [];
                        foreach (array_keys($q['subs']) as $si) {
                            $k = $answerKey . '_sub_' . $si;
                            if (isset($form_answers[$k]) && (is_string($form_answers[$k]) || is_numeric($form_answers[$k]))) {
                                $parts[] = trim((string) $form_answers[$k]);
                            }
                        }
                        $subAnswerRaw = implode("\n", $parts);
                    }
                    $subLines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $subAnswerRaw))));
                    ?>
                    <div class="sub-questions-outer-frame">
                        <?php if (!empty($subLines)): ?>
                            <div class="numbered-list-content" style="min-height: <?= max(6 * 22, 80) ?>px;">
                                <?php foreach ($subLines as $idx => $line): ?>
                                    <div class="numbered-list-item"><?= ($idx + 1) ?>. <?= htmlspecialchars($line) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <?php for ($r = 0; $r < max(6, (int) ($rows ?? 6)); $r++): ?>
                                <div class="question-line-multi"></div>
                            <?php endfor; ?>
                        <?php endif; ?>
                    </div>
                <?php elseif ($type === 'numbered_textarea'): ?>
                    <?php
                    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $answer))));
                    ?>
                    <?php if (!empty($lines)): ?>
                        <div class="numbered-list-content" style="min-height: <?= max(6 * 22, 80) ?>px;">
                            <?php foreach ($lines as $idx => $line): ?>
                                <div class="numbered-list-item"><?= ($idx + 1) ?>. <?= htmlspecialchars($line) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <?php for ($r = 0; $r < max(6, $rows); $r++): ?>
                            <div class="question-line-multi"></div>
                        <?php endfor; ?>
                    <?php endif; ?>
                <?php elseif ($is_textarea): ?>
                    <?php if (!empty($answer)): ?>
                        <div class="question-textarea-content" style="min-height: <?= max($rows * 20, 80) ?>px;">
                            <?= nl2br(htmlspecialchars($answer)) ?>
                        </div>
                    <?php else: ?>
                        <?php for ($r = 0; $r < $rows; $r++): ?>
                            <div class="question-line-multi"></div>
                        <?php endfor; ?>
                    <?php endif; ?>
                <?php elseif ($is_date): ?>
                    <?php if (!empty($answer)): ?>
                        <div class="question-answer-line"><strong><?= htmlspecialchars(formatDateMinguo($answer)) ?></strong></div>
                    <?php else: ?>
                        <div class="question-line"></div>
                    <?php endif; ?>
                <?php elseif (!$is_textarea && $is_signature_title): ?>
                    <div class="question-block-signature-inner">
                        <?php if (!empty($answer)): ?>
                            <strong><?= htmlspecialchars($answer) ?></strong>
                        <?php else: ?>
                            <span class="question-line"></span>
                        <?php endif; ?>
                    </div>
                <?php elseif (!$is_textarea && $is_review_score_title): ?>
                    <div class="question-block-review-inner">
                        <?php if (!empty($answer)): ?>
                            <strong><?= htmlspecialchars($answer) ?></strong>
                        <?php else: ?>
                            <span class="question-line"></span>
                        <?php endif; ?>
                    </div>
                <?php elseif ($is_table): ?>
                    <?php if (!empty($answer)): ?>
                        <div class="question-table-wrap"><?= $answer ?></div>
                    <?php else: ?>
                        <div class="question-table-placeholder">（表格填寫區域）</div>
                    <?php endif; ?>
                <?php elseif (in_array($type, ['radio', 'checkbox']) && count($opts) > 0): ?>
                    <?php if (!empty($answer)): ?>
                        <div class="question-options-answer">
                            <?php
                            $selectedOptions = is_array($answer) ? $answer : [$answer];
                            foreach ($selectedOptions as $selected):
                                if (in_array($selected, $opts)):
                                    ?>
                                    <div>☑ <?= htmlspecialchars($selected) ?></div>
                                <?php endif; endforeach; ?>
                        </div>
                    <?php else: ?>
                        <ul class="question-options">
                            <?php foreach ($opts as $o): ?>
                                <li><?= htmlspecialchars($o) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="question-line"></div>
                    <?php endif; ?>
                <?php elseif ($type === 'image_upload'): ?>
                    <?php if (!empty($answer)): ?>
                        <?php
                        $imgPath = trim((string) $answer);
                        // 若為相對路徑（例如 uploads/... 或 projecteverydays/...），自動補上 ../ 以供本頁載入
                        if (strpos($imgPath, 'http://') !== 0 && strpos($imgPath, 'https://') !== 0 && strpos($imgPath, '../') !== 0) {
                            $imgSrc = '../' . ltrim($imgPath, '/');
                        } else {
                            $imgSrc = $imgPath;
                        }
                        ?>
                        <div class="question-table-wrap">
                            <img src="<?= htmlspecialchars($imgSrc) ?>" alt="題目圖片"
                                style="max-width: 100%; max-height: 300px; object-fit: contain; border: 1px solid #000; padding: 4px; background: #fff;">
                        </div>
                    <?php else: ?>
                        <div class="question-line"></div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (!empty($answer)): ?>
                        <div class="question-answer-line"><strong><?= htmlspecialchars($answer) ?></strong></div>
                    <?php else: ?>
                        <div class="question-line"></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php
        // 規則：HTML 中的 footer 僅供分頁顯示或 Ctrl+P 使用。
        // 在 JS 產生 PDF 時，應隱藏此區塊，改由 JS 在最後一頁產生標準的三軌驗證。
        if ($pdf_footer_timestamps && !$export_mode && !$is_office):
            ?>
            <div class="pdf-footer-info no-pdf-duplicate no-print-pdf">
                <!-- 內文版本字串：供核實時從 PDF 文字/二進位萃取，提高辨識率（不依賴 metadata） -->
                <?php if ($sub_ID_for_qr && $version_payload !== ''): ?>
                    <span class="pdf-version-embed" aria-hidden="true"
                        style="font-size: 6pt; color: #e0e0e0; display: inline-block; width: 100%;"><?= htmlspecialchars($version_payload) ?></span>
                <?php endif; ?>
                <div class="pdf-version-row">
                    <div class="pdf-version-text">
                        <strong>表單版本（三軌驗證）</strong><br>
                        最後修改時間：<?= htmlspecialchars(formatDateTimeMinguoForPdf($footer_updated_d)) ?: '—' ?> &nbsp;|&nbsp;
                        最後提交時間：<?= $footer_sub_d ? htmlspecialchars(formatDateTimeMinguoForPdf($footer_sub_d)) : '尚未提交' ?><br>
                        組別：<?= $qr_group !== '' ? htmlspecialchars($qr_group) : '—' ?> &nbsp;|&nbsp;
                        學號：<?= $qr_student_ids !== '' ? htmlspecialchars($qr_student_ids) : '—' ?>
        </div>
    </div>
            </div>
        <?php endif; ?>

        <?php
        // 補充附件不放在 HTML 內（避免 iframe 產生大量空白頁），改由 document_form_pdf.js 的 mergeSupplement() 直接附加於 PDF 最後一頁
        ?>
    </div>

    <?php if ($embed_mode): ?>
        </body>

    </html>
    <?php exit; endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
<script>
    window.DOC_PDF_CONFIG = {
        fileName: <?= json_encode(str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '_', $doc_name) . '_' . date('Ymd') . '.pdf', JSON_UNESCAPED_UNICODE) ?>,
        download: <?= $download ? 'true' : 'false' ?>,
        exportMode: <?= $export_mode ? 'true' : 'false' ?>,
        showSignedPdfPreview: <?= $show_signed_pdf_preview ? 'true' : 'false' ?>,
        attachmentPdfUrl: <?= json_encode($attachment_pdf_url, JSON_UNESCAPED_UNICODE) ?>,
        supplementPdfUrl: <?= json_encode($supplement_pdf_url ?? '', JSON_UNESCAPED_UNICODE) ?>,
        versionPayload: <?= json_encode($version_payload, JSON_UNESCAPED_UNICODE) ?>,
        // 有 submission 時帶入 SNAPSHOT_TOKEN，前端 addVersionFooterPage 會印在最後一頁，上傳簽名檔後核實可正確比對
        versionFooterText: <?php
        // 科辦端預覽不顯示三軌驗證 technical footer 頁面
        if (!$is_office && $pdf_snapshot_token_for_footer !== '') {
            echo json_encode('SNAPSHOT_TOKEN: ' . $pdf_snapshot_token_for_footer . "\nSUB_ID: " . ($sub_ID_for_qr ?? ''), JSON_UNESCAPED_UNICODE);
        } else {
            echo '""';
        }
        ?>,
        snapshotToken: <?= (!$is_office && $pdf_snapshot_token_for_footer !== '') ? json_encode($pdf_snapshot_token_for_footer, JSON_UNESCAPED_UNICODE) : '""' ?>,
        documentId: <?= json_encode($document_id, JSON_UNESCAPED_UNICODE) ?>,
        submissionId: <?= json_encode($sub_ID_for_qr ?? 0, JSON_UNESCAPED_UNICODE) ?>,
        iframePreview: <?= $iframe_preview ? 'true' : 'false' ?>
    };
</script>
<script src="../js/document_form_pdf.js"></script>
</body>

</html>