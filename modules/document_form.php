<?php

/**
 * 文件表單管理模組 (document_forms)
 * 處理文件表單的 CRUD 操作
 */

// 確保 session 已啟動
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $conn;

$docSubHasQr = function_exists('document_submissions_has_column') ? document_submissions_has_column($conn, 'qr_modified_at') : false;
$docSubHasSignD = function_exists('document_submissions_has_column') ? document_submissions_has_column($conn, 'sign_uploaded_d') : false;

// 處理 JSON 請求
$p = $_POST;
if (empty($p) && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $jsonInput = file_get_contents('php://input');
    if ($jsonInput) {
        $decoded = json_decode($jsonInput, true);
        if (is_array($decoded)) {
            $p = $decoded;
        }
    }
}

$do = $_GET['do'] ?? '';
// 取得目前登入使用者 ID
// 之前使用 (int) 轉型，只允許 >0 的整數，會讓像「t00123」這種字母開頭的帳號被當成 0 而失敗
// 這裡改成：只要 Session 裡有非空字串就視為有效 ID，保留原始型別給後續 SQL 綁定使用
$document_form_get_u_id = static function () {
    if (!isset($_SESSION['u_ID'])) {
        return null;
    }

    $raw = $_SESSION['u_ID'];

    // 去除前後空白後若為空字串，一樣視為未登入
    if (is_string($raw)) {
        $trimmed = trim($raw);
        return $trimmed === '' ? null : $trimmed;
    }

    // 其他型別直接回傳（例如純數字帳號以 int 儲存）
    return $raw ?: null;
};
$u_ID = $document_form_get_u_id();

/**
 * 驗證上傳 PDF 的版本與目前表單一致（讀取 PDF metadata Keywords：doc_ID|doc_updated_d）
 * @param string $fullPath 已儲存的 PDF 完整路徑
 * @param int $doc_ID 目前表單 ID
 * @param string|null $current_doc_updated_d 資料庫表單最後修改時間
 * @return string|null 錯誤訊息，null 表示通過或略過檢查
 */
$document_form_validate_pdf_version = static function (string $fullPath, int $doc_ID, $current_doc_updated_d): ?string {
    if (!is_readable($fullPath) || !filesize($fullPath)) {
        return null;
    }
    if (!class_exists('\Smalot\PdfParser\Parser')) {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($autoload)) {
            try {
                require_once $autoload;
            } catch (Throwable $e) {
                return null;
            }
        }
    }
    if (!class_exists('\Smalot\PdfParser\Parser')) {
        return null;
    }
    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($fullPath);
        $details = $pdf->getDetails();
        $keywords = isset($details['Keywords']) ? trim((string) $details['Keywords']) : '';
        if ($keywords === '') {
            return null;
        }
        if (!preg_match('/^(\d+)\|(.+)$/', $keywords, $m)) {
            return null;
        }
        $pdf_doc_id = (int) $m[1];
        $pdf_date = preg_replace('/\s+/', ' ', trim($m[2]));
        $current = $current_doc_updated_d ? preg_replace('/\s+/', ' ', trim((string) $current_doc_updated_d)) : '';
        if ($pdf_doc_id !== $doc_ID) {
            return '您上傳的 PDF 表單編號與目前表單不符，請確認後再上傳。';
        }
        if ($current === '') {
            return null;
        }
        $norm = static function ($s) {
            return substr(trim((string) $s), 0, 19);
        };
        $a = $norm($pdf_date);
        $b = $norm($current);
        if ($a !== $b) {
            return '您上傳的 PDF 表單版本與目前系統最新版本不一致，請重新下載最新表單、簽名後再上傳。';
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
};

// 計算 pdf_version_hash：sha256(doc_ID + u_ID + dcsu_updated_d)
$compute_pdf_version_hash = static function ($doc_ID, $u_ID, $dcsu_updated_d) {
    $s = (string) $doc_ID . (string) $u_ID . (string) ($dcsu_updated_d ?? '');
    return hash('sha256', $s);
};

// 僅允許上傳整份已簽名後的 PDF 檔
if (!defined('DOCUMENT_FORM_ALLOW_SIGN_PDF')) {
    define('DOCUMENT_FORM_ALLOW_SIGN_PDF', true);
}

/**
 * 2026-03 之後：不再使用簽名 PDF 內嵌的 Keywords / QR 進行版本核實。
 * 只保留函式簽名以相容呼叫端，永遠回傳 null（表示「不阻擋」），實際核實改由三軌文字人工或其他機制處理。
 * @return null 一律表示通過，不再回傳錯誤訊息
 */
$validate_sign_pdf_version = static function (string $pdfPath, int $doc_ID, $u_ID, PDO $conn): ?string {
    if (!is_readable($pdfPath) || !filesize($pdfPath)) {
        // 空檔或無法讀取仍視為錯誤，避免上傳空白檔案
        return '⚠️【簽名檔錯誤】檔案無法讀取，請確認已成功匯出並簽名後再上傳。';
    }
    return null;
};

// 從簽名圖檔解 QR，解析 DOCSUB|sub=xxx|hash=yyy，回傳 ['sub'=>x,'hash'=>y] 或 null
// 注意：此函數保留用於兼容舊資料，新系統僅使用PDF QR Code解析
$decode_sign_image_qr = static function (string $imagePath) {
    if (!is_file($imagePath) || !is_readable($imagePath)) return null;
    if (!class_exists('\Zxing\QrReader')) {
        $autoload = dirname(__DIR__, 1) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            try {
                require_once $autoload;
            } catch (Throwable $e) { /* 依賴未安裝時不阻擋 */
            }
        }
    }
    if (!class_exists('\Zxing\QrReader')) return null;
    try {
        $reader = new \Zxing\QrReader($imagePath, \Zxing\QrReader::SOURCE_TYPE_FILE);
        $text = $reader->text();
        if (empty($text)) return null;
        // 支援 DOCSUB|sub=ID|hash=HASH 或帶後綴 |group=...|students=...|date=...
        if (preg_match('/^DOCSUB\|sub=(\d+)\|hash=([a-f0-9]+)(?:\|.*)?$/i', trim($text), $m)) {
            return ['sub' => (int) $m[1], 'hash' => trim($m[2])];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
};

/**
 * 從 PDF 日期字串（D:YYYYMMDDHHmmSS...）解析為 YYYY-MM-DD HH:MM:SS
 */
$parse_pdf_date_string = static function (string $raw): ?string {
    $raw = trim($raw);
    // PDF 格式：D:YYYYMMDDHHmmSS 或 D:YYYYMMDDHHmmSS+08'00' 或 D:YYYYMMDDHHmmSSZ 等
    if (preg_match('/D:(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $raw, $m)) {
        return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
    }
    // 已是 YYYY-MM-DD HH:MM:SS 或 YYYY-MM-DD
    if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s+(\d{2}:\d{2}:\d{2}))?$/', $raw, $m)) {
        return isset($m[2]) && $m[2] !== '' ? $m[1] . ' ' . $m[2] : $m[1] . ' 00:00:00';
    }
    return null;
};

/**
 * 從簽名後 PDF 的 QR Code 只讀取 sub_ID（新格式 SUB:123 或 SUB:123|SIG:xxx，相容舊格式 DOCSUB|sub=123|...）
 * @param string $pdfPath PDF 檔案路徑
 * @return int|null sub_ID，讀不到或格式不符返回 null
 */
$extract_sub_id_from_pdf_qr = static function (string $pdfPath): ?int {
    if (!is_file($pdfPath) || !is_readable($pdfPath)) return null;
    if (!class_exists('\Zxing\QrReader')) {
        $autoload = dirname(__DIR__, 1) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            try {
                require_once $autoload;
            } catch (Throwable $e) { /* 依賴未安裝時不阻擋 */
            }
        }
    }
    if (!class_exists('\Zxing\QrReader')) return null;
    try {
        $reader = new \Zxing\QrReader($pdfPath, \Zxing\QrReader::SOURCE_TYPE_FILE);
        $text = $reader->text();
        if (empty($text)) return null;
        $text = trim($text);
        // 新格式：SUB:123 或 SUB:123|SIG:xxx
        if (preg_match('/^SUB:(\d+)(?:\|.*)?$/i', $text, $m)) {
            return (int)$m[1];
        }
        // 相容舊格式：DOCSUB|sub=123|...
        if (preg_match('/^DOCSUB\|sub=(\d+)(?:\|.*)?$/i', $text, $m)) {
            return (int)$m[1];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
};

/**
 * （舊版）依 QR 內的 sub_ID 查 DB 核實。
 * 2026-03 起已改用「表單版本（三軌文字）」人工比對，不再透過 QR 自動核實。
 * 為相容舊代碼，現統一回傳「未核實」狀態（verify_result=0）。
 * @return array{verify_result: int, qr_modified_at: ?string, dcsub_updated_d: ?string}
 */
$verify_sign_pdf_by_sub_id = static function (string $pdfPath, int $doc_ID, $u_ID, PDO $conn): array {
    return ['verify_result' => 0, 'qr_modified_at' => null, 'dcsub_updated_d' => null];
};

/**
 * 從簽名後 PDF 解析 QR Code 或 metadata，提取最後修改時間（舊邏輯，保留相容；新核實改為依 sub_ID 查 DB）
 * @param string $pdfPath PDF 檔案路徑
 * @return string|null 最後修改時間（格式：YYYY-MM-DD HH:MM:SS），失敗返回 null
 */
$extract_qr_modified_at_from_pdf = static function (string $pdfPath) use ($parse_pdf_date_string): ?string {
    if (!is_file($pdfPath) || !is_readable($pdfPath)) return null;

    // 方法1：從 PDF metadata 讀取 ModDate / CreationDate（多數 PDF 都有，優先使用）
    if (class_exists('\Smalot\PdfParser\Parser')) {
        try {
            $autoload = dirname(__DIR__, 1) . '/vendor/autoload.php';
            if (file_exists($autoload)) {
                try {
                    require_once $autoload;
                } catch (Throwable $e) { /* 依賴未安裝時不阻擋 */
                }
            }
            if (!class_exists('\Smalot\PdfParser\Parser')) { /* skip */
            } else {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($pdfPath);
                $details = $pdf->getDetails();
                foreach (['ModDate', 'ModificationDate', 'CreationDate', 'Creation Date'] as $key) {
                    if (!empty($details[$key])) {
                        $parsed = $parse_pdf_date_string(trim((string)$details[$key]));
                        if ($parsed !== null) return $parsed;
                    }
                }
            }
        } catch (Throwable $e) {
            // 繼續嘗試其他方法
        }
    }

    // 方法2：嘗試從 PDF 中提取 QR Code（使用 Zxing，部分環境對 PDF 支援有限）
    if (class_exists('\Zxing\QrReader')) {
        try {
            $autoload = dirname(__DIR__, 1) . '/vendor/autoload.php';
            if (file_exists($autoload)) {
                try {
                    require_once $autoload;
                } catch (Throwable $e) { /* 依賴未安裝時不阻擋 */
                }
            }

            $reader = new \Zxing\QrReader($pdfPath, \Zxing\QrReader::SOURCE_TYPE_FILE);
            $text = $reader->text();
            if (!empty($text)) {
                // 解析 DOCSUB|sub=ID|hash=HASH|group=...|students=...|date=YYYY-MM-DD HH:MM:SS
                if (preg_match('/\|date=([^\|]+)/i', trim($text), $m)) {
                    $dateStr = trim($m[1]);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}(\s+\d{2}:\d{2}:\d{2})?$/', $dateStr)) {
                        return strlen($dateStr) === 10 ? $dateStr . ' 00:00:00' : $dateStr;
                    }
                    $parsed = $parse_pdf_date_string($dateStr);
                    if ($parsed !== null) return $parsed;
                }
            }
        } catch (Throwable $e) {
            // 繼續
        }
    }

    return null;
};

// 解碼目標列表（學級、類組等）
$decodeTargetList = static function ($value): array {
    if (is_array($value)) {
        return array_values(array_unique(array_map('strval', $value)));
    }
    if ($value === null || $value === '') {
        return [];
    }
    $decoded = json_decode((string) $value, true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_unique(array_map('strval', $decoded)));
};

// 建立目標設定 payload
$buildTargetPayload = static function ($source) use ($decodeTargetList): array {
    $all = (int) ($source['doc_target_all'] ?? $source['target_all'] ?? 0);
    return [
        'doc_target_all' => $all ? 1 : 0,
        'doc_target_cohorts' => $decodeTargetList($source['doc_target_cohorts'] ?? $source['target_cohorts'] ?? []),
        'doc_target_grades' => $decodeTargetList($source['doc_target_grades'] ?? $source['target_grades'] ?? []),
        'doc_target_classes' => $decodeTargetList($source['doc_target_classes'] ?? $source['target_classes'] ?? []),
        'doc_target_groups' => $decodeTargetList($source['doc_target_groups'] ?? $source['target_groups'] ?? []),
    ];
};

// 檢查 document_forms 是否有 doc_des 欄位（若已移除，相關 SQL 不依賴該欄位）
$hasDocDesColumn = static function () use ($conn): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM document_forms LIKE 'doc_des'");
        $cache = $stmt && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
};

// 檢查 document_forms 是否有 is_required 欄位（若已移除，相關 SQL 不依賴該欄位）
$hasIsRequiredColumn = static function () use ($conn): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM document_forms LIKE 'is_required'");
        $cache = $stmt && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
};

// 確保 document_forms 有 doc_header 欄位（用於表單抬頭）
$ensureDocHeaderColumn = static function () use ($conn, $hasDocDesColumn): void {
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM document_forms LIKE 'doc_header'");
        if ($stmt && $stmt->rowCount() === 0) {
            $after = $hasDocDesColumn() ? ' AFTER doc_des' : ' AFTER doc_name';
            $conn->exec("ALTER TABLE document_forms ADD COLUMN doc_header TEXT NULL DEFAULT NULL" . $after);
        }
    } catch (Throwable $e) {
        error_log("ensureDocHeaderColumn: " . $e->getMessage());
    }
};

// 在交易外確保 document_form_targets 表存在（DDL 會隱性 COMMIT，不可在 transaction 內執行）
$ensureFormTargetsTable = static function () use ($conn): void {
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS document_form_targets (
                doc_ID int(11) NOT NULL,
                doc_target_type enum('ALL','COHORT','GRADE','CLASS','GROUP') NOT NULL,
                doc_target_ID varchar(50) NOT NULL,
                PRIMARY KEY (doc_ID, doc_target_type, doc_target_ID),
                FOREIGN KEY (doc_ID) REFERENCES document_forms(doc_ID) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (Throwable $e) {
        error_log("ensureFormTargetsTable: " . $e->getMessage());
    }
};

// 表單附件表（每表單一 PDF，匯出時合併在主 PDF 後方）
$ensureFormAttachmentsTable = static function () use ($conn): void {
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS document_form_attachments (
                doc_ID int(11) NOT NULL PRIMARY KEY,
                display_name varchar(255) NOT NULL DEFAULT '附件.pdf',
                file_path varchar(500) NOT NULL,
                created_d datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (doc_ID) REFERENCES document_forms(doc_ID) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (Throwable $e) {
        error_log("ensureFormAttachmentsTable: " . $e->getMessage());
    }
};

// 學生補充附件表（已淘汰）：改用 document_submissions.attach_path / original_pdf_path。
// 保留函式名稱以相容舊代碼，但不再建立或使用 document_form_supplements。
$ensureFormSupplementsTable = static function (): void {
    // no-op: legacy compatibility
};

// 同步目標設定到資料庫（呼叫前須已確保 document_form_targets 表存在，且須在 transaction 內呼叫）
$syncFormTargets = static function (int $docId, array $targets) use ($conn): void {
    // 先刪除該表單的所有舊目標記錄
    $stmt = $conn->prepare("DELETE FROM document_form_targets WHERE doc_ID = ?");
    $stmt->execute([$docId]);

    // 如果設定為全部可見，插入 ALL 記錄並返回
    if (!empty($targets['doc_target_all']) || $targets['doc_target_all'] === 1 || $targets['doc_target_all'] === '1') {
        try {
            $insert = $conn->prepare("
                INSERT INTO document_form_targets (doc_ID, doc_target_type, doc_target_ID)
                VALUES (?, 'ALL', '1')
            ");
            $insert->execute([$docId]);
        } catch (Throwable $e) {
            // 忽略重複鍵錯誤
        }
        return;
    }

    // 學級改為用 COHORT 儲存：將 doc_target_grades（year_label）轉成 cohort_ID 後一併寫入 COHORT
    $cohortIds = is_array($targets['doc_target_cohorts'] ?? null) ? array_values(array_unique($targets['doc_target_cohorts'])) : [];
    $gradeValues = is_array($targets['doc_target_grades'] ?? null) ? $targets['doc_target_grades'] : [];
    foreach ($gradeValues as $yearLabel) {
        if ($yearLabel === null || $yearLabel === '') {
            continue;
        }
        try {
            $stmt = $conn->prepare("SELECT cohort_ID FROM cohortdata WHERE year_label = ? AND (cohort_status = 1 OR cohort_status IS NULL) LIMIT 1");
            $stmt->execute([$yearLabel]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['cohort_ID'])) {
                $cid = (string) $row['cohort_ID'];
                if (!in_array($cid, $cohortIds, true)) {
                    $cohortIds[] = $cid;
                }
            }
        } catch (Throwable $e) {
            // 略過單筆查詢錯誤
        }
    }

    // 準備插入語句
    try {
        $insert = $conn->prepare("
            INSERT INTO document_form_targets (doc_ID, doc_target_type, doc_target_ID)
            VALUES (?, ?, ?)
        ");

        // 只寫入 COHORT、CLASS、GROUP（不再寫入 GRADE）
        $map = [
            'COHORT' => $cohortIds,
            'CLASS' => $targets['doc_target_classes'] ?? [],
            'GROUP' => $targets['doc_target_groups'] ?? [],
        ];

        foreach ($map as $type => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                try {
                    $insert->execute([$docId, $type, (string) $value]);
                } catch (Throwable $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                        error_log("Error syncing form target: " . $e->getMessage());
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log("Error syncing form targets: " . $e->getMessage());
    }
};

// 載入表單的目標設定（學級改為 COHORT，僅用 doc_target_cohorts 儲存；回傳 doc_target_grades 由 cohort 反查 year_label 供表單 UI 使用）
$loadFormTargets = static function (int $docId) use ($conn): array {
    $result = [
        'doc_target_all' => false,
        'doc_target_cohorts' => [],
        'doc_target_grades' => [],
        'doc_target_classes' => [],
        'doc_target_groups' => [],
    ];

    try {
        $stmt = $conn->prepare("
            SELECT doc_target_type, doc_target_ID
            FROM document_form_targets
            WHERE doc_ID = ?
        ");
        $stmt->execute([$docId]);
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($targets as $target) {
            $type = $target['doc_target_type'];
            $id = trim((string) $target['doc_target_ID']);
            if ($id === '') continue;

            switch ($type) {
                case 'ALL':
                    $result['doc_target_all'] = true;
                    break;
                case 'COHORT':
                    $result['doc_target_cohorts'][] = $id;
                    break;
                case 'GRADE':
                    // 舊資料：year_label 轉成 cohort_ID 納入 doc_target_cohorts，並保留 doc_target_grades 供顯示
                    $result['doc_target_grades'][] = $id;
                    try {
                        $c = $conn->prepare("SELECT cohort_ID FROM cohortdata WHERE year_label = ? LIMIT 1");
                        $c->execute([$id]);
                        $row = $c->fetch(PDO::FETCH_ASSOC);
                        if ($row && !empty($row['cohort_ID'])) {
                            $cid = (string) $row['cohort_ID'];
                            if (!in_array($cid, $result['doc_target_cohorts'], true)) {
                                $result['doc_target_cohorts'][] = $cid;
                            }
                        }
                    } catch (Throwable $e) { /* 略過 */
                    }
                    break;
                case 'CLASS':
                    $result['doc_target_classes'][] = $id;
                    break;
                case 'GROUP':
                    $result['doc_target_groups'][] = $id;
                    break;
            }
        }

        // 由 doc_target_cohorts 反查 year_label，供表單學級下拉與列表顯示
        if (!empty($result['doc_target_cohorts']) && empty($result['doc_target_grades'])) {
            $result['doc_target_grades'] = [];
            $placeholders = implode(',', array_fill(0, count($result['doc_target_cohorts']), '?'));
            $st = $conn->prepare("SELECT year_label FROM cohortdata WHERE cohort_ID IN ($placeholders)");
            $st->execute(array_values($result['doc_target_cohorts']));
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (isset($row['year_label']) && $row['year_label'] !== '') {
                    $result['doc_target_grades'][] = $row['year_label'];
                }
            }
        }
    } catch (PDOException $e) {
        // 如果表不存在，返回空設定（向後兼容）
    }

    return $result;
};

// 檢查是否為科辦或主任 (role_ID=1, 2)
function checkDocumentFormAdminPermission()
{
    global $conn, $document_form_get_u_id;

    if (!isset($conn)) {
        json_err('資料庫連線失敗', 'DB_ERROR', 500);
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 與前面共用的 helper 保持一致，避免因為帳號不是純數字而被當成 0
    $u_ID = is_callable($document_form_get_u_id)
        ? $document_form_get_u_id()
        : ($_SESSION['u_ID'] ?? null);

    if (!$u_ID) {
        json_err('請先登入', 'NOT_LOGGED_IN', 401);
    }

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM userrolesdata 
            WHERE ur_u_ID = ? AND role_ID IN (1, 2) AND user_role_status = 1
        ");
        $stmt->execute([$u_ID]);
        $count = $stmt->fetchColumn();

        if (!$count) {
            json_err('此功能僅限主任和科辦使用', 'NO_PERMISSION', 403);
        }
        return $u_ID;
    } catch (PDOException $e) {
        json_err('資料庫查詢失敗：' . $e->getMessage(), 'DB_ERROR', 500);
    } catch (Throwable $e) {
        json_err('權限檢查失敗：' . $e->getMessage());
    }
}

switch ($do) {
    // 獲取所有文件表單列表
    case 'get_document_forms':
        try {
            checkDocumentFormAdminPermission();

            // 動態決定欄位，避免在移除 doc_des / is_required 後查詢失敗
            $docColumns = ['doc_ID', 'doc_name'];
            if ($hasDocDesColumn()) {
                $docColumns[] = 'doc_des';
            }
            if ($hasIsRequiredColumn()) {
                $docColumns[] = 'is_required';
            }
            $docColumns = ['doc_ID', 'doc_name', 'form_schema'];
            $docColumns[] = 'doc_start_d';
            $docColumns[] = 'doc_end_d';
            $docColumns[] = 'doc_status';
            $docColumns[] = 'doc_u_ID';
            $docColumns[] = 'doc_created_d';
            $docColumns[] = 'doc_updated_d';
            $columnsSql = implode(', ', $docColumns);

            $sql = "
                SELECT {$columnsSql}
                FROM document_forms
                ORDER BY doc_created_d DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 若 doc_des / is_required 欄位已移除，補上預設值供前端顯示
            if (!$hasDocDesColumn() || !$hasIsRequiredColumn()) {
                foreach ($forms as &$form) {
                    if (!$hasDocDesColumn() && !isset($form['doc_des'])) {
                        $form['doc_des'] = '審查文件';
                    }
                    if (!$hasIsRequiredColumn() || !isset($form['is_required'])) {
                        $form['is_required'] = 0;
                    }
                }
                unset($form);
            }

            // 為每個表單載入目標設定並格式化顯示
            foreach ($forms as &$form) {
                $targets = $loadFormTargets((int)$form['doc_ID']);
                $form['doc_target_all'] = $targets['doc_target_all'];
                $form['doc_target_cohorts'] = $targets['doc_target_cohorts'];
                $form['doc_target_grades'] = $targets['doc_target_grades'];
                $form['doc_target_classes'] = $targets['doc_target_classes'];
                $form['doc_target_groups'] = $targets['doc_target_groups'];
                $form['exresultdata'] = 0;

                if (!empty($form['form_schema'])) {
                    $schema = json_decode($form['form_schema'], true);

                    if (is_array($schema) && isset($schema['exresultdata'])) {
                        $form['exresultdata'] = $schema['exresultdata'] ? 1 : 0;
                    }
                }
                // 格式化目標設定顯示文字
                $targetLabels = [];
                if ($targets['doc_target_all']) {
                    $targetLabels[] = '所有人';
                } else {
                    // 屆別（學級改為 COHORT）：以 doc_target_cohorts 查 cohort 名稱；若無則用 doc_target_grades（year_label）相容舊資料
                    $gradeLabels = [];
                    if (!empty($targets['doc_target_cohorts'])) {
                        $placeholders = implode(',', array_fill(0, count($targets['doc_target_cohorts']), '?'));
                        $stmt2 = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID IN ($placeholders) ORDER BY cohort_ID");
                        $stmt2->execute(array_values($targets['doc_target_cohorts']));
                        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                            if (!empty($row['cohort_name'])) {
                                $gradeLabels[] = $row['cohort_name'];
                            }
                        }
                    }
                    if (empty($gradeLabels) && !empty($targets['doc_target_grades'])) {
                        foreach ($targets['doc_target_grades'] as $grade) {
                            $stmt2 = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE year_label = ? AND cohort_status = 1 LIMIT 1");
                            $stmt2->execute([$grade]);
                            $cohort = $stmt2->fetch(PDO::FETCH_ASSOC);
                            if ($cohort) {
                                $gradeLabels[] = $cohort['cohort_name'];
                            } else {
                                $gradeLabels[] = $grade . '級';
                            }
                        }
                    }
                    if (!empty($gradeLabels)) {
                        $targetLabels[] = '屆別：' . implode('、', $gradeLabels);
                    }

                    // 載入班級名稱
                    if (!empty($targets['doc_target_classes'])) {
                        $classLabels = [];
                        foreach ($targets['doc_target_classes'] as $classId) {
                            $stmt2 = $conn->prepare("SELECT c_name FROM classdata WHERE c_ID = ? LIMIT 1");
                            $stmt2->execute([$classId]);
                            $class = $stmt2->fetch(PDO::FETCH_ASSOC);
                            if ($class) {
                                $classLabels[] = $class['c_name'] . '班';
                            } else {
                                $classLabels[] = '班級' . $classId;
                            }
                        }
                        if (!empty($classLabels)) {
                            $targetLabels[] = '班級：' . implode('、', $classLabels);
                        }
                    }

                    // 載入類組名稱
                    if (!empty($targets['doc_target_groups'])) {
                        $groupLabels = [];
                        foreach ($targets['doc_target_groups'] as $groupId) {
                            $stmt2 = $conn->prepare("SELECT group_name FROM groupdata WHERE group_ID = ? LIMIT 1");
                            $stmt2->execute([$groupId]);
                            $group = $stmt2->fetch(PDO::FETCH_ASSOC);
                            if ($group) {
                                $groupLabels[] = $group['group_name'];
                            } else {
                                $groupLabels[] = '類組' . $groupId;
                            }
                        }
                        if (!empty($groupLabels)) {
                            $targetLabels[] = '類組：' . implode('、', $groupLabels);
                        }
                    }

                    // 如果沒有任何目標設定，顯示「未設定」
                    if (empty($targetLabels)) {
                        $targetLabels[] = '未設定';
                    }
                }

                $form['target_display'] = implode(' | ', $targetLabels);
            }
            unset($form);

            json_ok(['forms' => $forms]);
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('獲取表單列表失敗：' . $e->getMessage());
        }
        break;

    // 獲取單個文件表單詳情
case 'get_document_form_detail':
    try {
        checkDocumentFormAdminPermission();

        $doc_ID = (int)($p['doc_ID'] ?? $p['document_id'] ?? $_GET['doc_ID'] ?? $_GET['document_id'] ?? 0);

        if ($doc_ID <= 0) {
            json_err('表單ID無效');
        }

        $stmt = $conn->prepare("
            SELECT * FROM document_forms WHERE doc_ID = ?
        ");
        $stmt->execute([$doc_ID]);
        $form = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$form) {
            json_err('找不到該表單');
        }

        $rawFormSchema = $form['form_schema'] ?? '';

        // 預設值
        $form['document_remark'] = '';
        $form['pdf_footer_timestamps'] = 1;
        $form['supplement_attachment_enabled'] = 1;
        $form['supplement_attachment_note'] = '';
        $form['exresultdata'] = false;
        $form['form_schema'] = [];

        // 解析 form_schema JSON
        if (!empty($rawFormSchema)) {
            $schema = json_decode($rawFormSchema, true);

            if (is_array($schema)) {
                // 新格式：物件裡面有 questions / exresultdata
                if (isset($schema['_remark']) || isset($schema['questions'])) {
                    $form['document_remark'] = $schema['_remark'] ?? '';
                    $form['pdf_footer_timestamps'] = isset($schema['pdf_footer_timestamps']) ? (int)$schema['pdf_footer_timestamps'] : 1;
                    $form['supplement_attachment_enabled'] = isset($schema['supplement_attachment_enabled']) ? (int)$schema['supplement_attachment_enabled'] : 1;
                    $form['supplement_attachment_note'] = $schema['supplement_attachment_note'] ?? '';
                    $form['exresultdata'] = isset($schema['exresultdata']) ? (bool)$schema['exresultdata'] : false;
                    $form['form_schema'] = $schema['questions'] ?? [];
                } else {
                    // 舊格式：直接就是 questions 陣列
                    $form['form_schema'] = $schema;
                }
            }
        }

        // 調試：檢查載入時的 rows 值
        if (is_array($form['form_schema'])) {
            foreach ($form['form_schema'] as $idx => $q) {
                if (isset($q['type']) && $q['type'] === 'textarea' && isset($q['rows'])) {
                    error_log("後端載入 rows: question[$idx] rows=" . $q['rows'] . " (type: " . gettype($q['rows']) . ")");
                }
            }
        }

        // 科辦編輯時需要還原 form_schema 為完整對象
        if (is_array($form['form_schema'])) {
            $form['form_schema'] = [
                '_remark' => $form['document_remark'],
                'pdf_footer_timestamps' => $form['pdf_footer_timestamps'],
                'supplement_attachment_enabled' => $form['supplement_attachment_enabled'],
                'supplement_attachment_note' => $form['supplement_attachment_note'],
                'exresultdata' => $form['exresultdata'],
                'questions' => $form['form_schema']
            ];
        }

        // 載入目標設定
        $targets = $loadFormTargets($doc_ID);
        $form['doc_target_all'] = $targets['doc_target_all'];
        $form['doc_target_cohorts'] = $targets['doc_target_cohorts'];
        $form['doc_target_grades'] = $targets['doc_target_grades'];
        $form['doc_target_classes'] = $targets['doc_target_classes'];
        $form['doc_target_groups'] = $targets['doc_target_groups'];

        // 若 doc_des / is_required 欄位已移除，補上預設值供前端顯示
        if (!isset($form['doc_des'])) {
            $form['doc_des'] = '審查文件';
        }
        if (!isset($form['is_required'])) {
            $form['is_required'] = 0;
        }

        json_ok(['form' => $form]);
    } catch (PDOException $e) {
        json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
    } catch (Throwable $e) {
        json_err('獲取表單詳情失敗：' . $e->getMessage());
    }
    break;

    // 儲存文件表單（新增或更新）
    case 'save_document_form':
        $lockKey = null;
        try {
            $u_ID = checkDocumentFormAdminPermission();
            $doc_ID = (int)($p['doc_ID'] ?? $p['document_id'] ?? 0);
            $doc_name = trim($p['doc_name'] ?? $p['document_name'] ?? '');
            $doc_des = trim($p['doc_des'] ?? $p['document_category'] ?? '');
            $doc_header = trim($p['doc_header'] ?? '');
            $is_required = isset($p['is_required']) ? (int)$p['is_required'] : 0;
            $doc_start_d = null;
            if (!empty($p['doc_start_d']) || !empty($p['open_datetime'])) {
                $raw = trim($p['doc_start_d'] ?? $p['open_datetime'] ?? '');
                $doc_start_d = str_replace('T', ' ', $raw);
                if (strlen($doc_start_d) === 16) {
                    $doc_start_d .= ':00';
                }
            }
            $doc_end_d = null;
            if (!empty($p['doc_end_d']) || !empty($p['close_datetime'])) {
                $raw = trim($p['doc_end_d'] ?? $p['close_datetime'] ?? '');
                $doc_end_d = str_replace('T', ' ', $raw);
                if (strlen($doc_end_d) === 16) {
                    $doc_end_d .= ':00';
                }
            }
            $doc_status = (int)($p['doc_status'] ?? $p['document_status'] ?? 1);
            $form_schema = isset($p['form_schema']) ? $p['form_schema'] : [];
            $form_schema['exresultdata'] = !empty($form_schema['exresultdata']);
            $is_exresultdata = !empty($form_schema['exresultdata']) ? 1 : 0;
            // 調試：檢查接收到的 rows 值
            if (is_array($form_schema)) {
                $questions = isset($form_schema['questions']) ? $form_schema['questions'] : $form_schema;
                foreach ($questions as $idx => $q) {
                    if (isset($q['type']) && $q['type'] === 'textarea' && isset($q['rows'])) {
                        error_log("後端接收 rows: question[$idx] rows=" . $q['rows'] . " (type: " . gettype($q['rows']) . ")");
                    }
                }
            }

            // 驗證必填欄位
            if (empty($doc_name)) {
                json_err('文件名稱不能為空');
            }

            // 驗證時間
            if ($doc_start_d && $doc_end_d) {
                if (strtotime($doc_start_d) > strtotime($doc_end_d)) {
                    json_err('開放時間不能晚於截止時間');
                }
            }

            // 將 form_schema 轉為 JSON
            $form_schema_json = json_encode($form_schema, JSON_UNESCAPED_UNICODE);

            // 調試：檢查 JSON 編碼後的內容
            if (is_array($form_schema)) {
                $questions = isset($form_schema['questions']) ? $form_schema['questions'] : $form_schema;
                foreach ($questions as $idx => $q) {
                    if (isset($q['type']) && $q['type'] === 'textarea' && isset($q['rows'])) {
                        error_log("後端儲存 JSON 前 rows: question[$idx] rows=" . $q['rows']);
                    }
                }
            }

            // 處理目標設定
            $targets = $buildTargetPayload($p);

            // 在交易前確保目標表存在、doc_header 欄位存在（DDL 會隱性 COMMIT，不可在 transaction 內執行）
            $ensureFormTargetsTable();
            $ensureDocHeaderColumn();

            $conn->beginTransaction();

            $isUpdate = $doc_ID > 0;
            $lockKey = null;
            // 新增時用 named lock 序列化請求，避免雙擊/併發造成兩筆資料
            if (!$isUpdate) {
                $lockKey = 'doc_form_save_' . (int) $u_ID;
                $lockStmt = $conn->query("SELECT GET_LOCK(" . $conn->quote($lockKey) . ", 5)");
                $gotLock = $lockStmt && (int) $lockStmt->fetchColumn() === 1;
                if (!$gotLock) {
                    $conn->rollBack();
                    json_err('請稍後再試');
                }
            }

            // 防重複：新增時若 10 秒內已有同名稱的記錄（同一使用者），視為重複送出，改為更新該筆
            if (!$isUpdate && !empty($doc_name)) {
                $dupSql = $hasDocDesColumn()
                    ? "WHERE doc_name = ? AND doc_des = ? AND doc_u_ID = ?"
                    : "WHERE doc_name = ? AND doc_u_ID = ?";
                $dupStmt = $conn->prepare("
                    SELECT doc_ID FROM document_forms
                    {$dupSql}
                    AND doc_created_d >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
                    ORDER BY doc_ID DESC LIMIT 1
                ");
                if ($hasDocDesColumn()) {
                    $dupStmt->execute([$doc_name, $doc_des, $u_ID]);
                } else {
                    $dupStmt->execute([$doc_name, $u_ID]);
                }
                $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);
                if ($dup) {
                    $doc_ID = (int)$dup['doc_ID'];
                    $isUpdate = true;
                }
            }

            $docDesSet = $hasDocDesColumn() ? 'doc_des = ?, doc_header = ?' : 'doc_header = ?';
            $docDesParams = $hasDocDesColumn() ? [$doc_name, $doc_des, $doc_header] : [$doc_name, $doc_header];

            if ($isUpdate) {
                $isReqSet = $hasIsRequiredColumn() ? 'is_required = ?, ' : '';
                $stmt = $conn->prepare("
                    UPDATE document_forms 
                    SET doc_name = ?, {$docDesSet},
                        {$isReqSet}doc_start_d = ?, doc_end_d = ?,
                        doc_status = ?, form_schema = ?, doc_updated_d = NOW()
                    WHERE doc_ID = ?
                ");
                $tailParams = [$doc_start_d, $doc_end_d, $doc_status, $form_schema_json, $doc_ID];
                $allParams = $hasIsRequiredColumn()
                    ? array_merge($docDesParams, [$is_required], $tailParams)
                    : array_merge($docDesParams, $tailParams);
                $stmt->execute($allParams);
            } else {
                $docDesCols = $hasDocDesColumn() ? 'doc_name, doc_des, doc_header,' : 'doc_name, doc_header,';
                $docDesVals = $hasDocDesColumn() ? '?, ?, ?,' : '?, ?,';
                $isReqCol = $hasIsRequiredColumn() ? 'is_required,' : '';
                $isReqVal = $hasIsRequiredColumn() ? '?, ' : '';
                $stmt = $conn->prepare("
                    INSERT INTO document_forms 
                    ({$docDesCols} {$isReqCol}doc_start_d, doc_end_d, doc_status, form_schema, doc_u_ID, doc_created_d, doc_updated_d)
                    VALUES ({$docDesVals} {$isReqVal}?, ?, ?, ?, ?, NOW(), NOW())
                ");
                if ($hasDocDesColumn() && $hasIsRequiredColumn()) {
                    $insertParams = [$doc_name, $doc_des, $doc_header, $is_required, $doc_start_d, $doc_end_d, $doc_status, $form_schema_json, $u_ID];
                } elseif ($hasDocDesColumn() && !$hasIsRequiredColumn()) {
                    $insertParams = [$doc_name, $doc_des, $doc_header, $doc_start_d, $doc_end_d, $doc_status, $form_schema_json, $u_ID];
                } elseif (!$hasDocDesColumn() && $hasIsRequiredColumn()) {
                    $insertParams = [$doc_name, $doc_header, $is_required, $doc_start_d, $doc_end_d, $doc_status, $form_schema_json, $u_ID];
                } else {
                    $insertParams = [$doc_name, $doc_header, $doc_start_d, $doc_end_d, $doc_status, $form_schema_json, $u_ID];
                }
                $stmt->execute($insertParams);
                $doc_ID = (int)$conn->lastInsertId();
            }

            // 同步目標設定
            $syncFormTargets($doc_ID, $targets);

            $conn->commit();
            if ($lockKey !== null) {
                try {
                    $conn->exec("SELECT RELEASE_LOCK(" . $conn->quote($lockKey) . ")");
                } catch (Throwable $e) {
                    // 忽略
                }
            }
            json_ok(['message' => $isUpdate ? '表單已更新' : '表單已建立', 'doc_ID' => $doc_ID, 'document_id' => $doc_ID]);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            if ($lockKey !== null) {
                try {
                    $conn->exec("SELECT RELEASE_LOCK(" . $conn->quote($lockKey) . ")");
                } catch (Throwable $e2) {
                    // 忽略
                }
            }
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            if ($lockKey !== null) {
                try {
                    $conn->exec("SELECT RELEASE_LOCK(" . $conn->quote($lockKey) . ")");
                } catch (Throwable $e2) {
                    // 忽略
                }
            }
            json_err('儲存表單失敗：' . $e->getMessage());
        }
        break;

    // 刪除文件表單
    case 'delete_document_form':
        try {
            checkDocumentFormAdminPermission();

            $doc_ID = (int)($p['doc_ID'] ?? $p['document_id'] ?? 0);

            if ($doc_ID <= 0) {
                json_err('表單ID無效');
            }

            $stmt = $conn->prepare("DELETE FROM document_forms WHERE doc_ID = ?");
            $stmt->execute([$doc_ID]);

            json_ok(['message' => '表單已刪除']);
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('刪除表單失敗：' . $e->getMessage());
        }
        break;

    // 切換表單狀態
    case 'toggle_document_form_status':
        try {
            checkDocumentFormAdminPermission();

            $doc_ID = (int)($p['doc_ID'] ?? $p['document_id'] ?? 0);

            if ($doc_ID <= 0) {
                json_err('表單ID無效');
            }

            $stmt = $conn->prepare("SELECT doc_status FROM document_forms WHERE doc_ID = ?");
            $stmt->execute([$doc_ID]);
            $form = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$form) {
                json_err('找不到該表單');
            }

            $new_status = $form['doc_status'] == 1 ? 0 : 1;

            $stmt = $conn->prepare("
                UPDATE document_forms 
                SET doc_status = ?, doc_updated_d = NOW()
                WHERE doc_ID = ?
            ");
            $stmt->execute([$new_status, $doc_ID]);

            json_ok(['message' => $new_status == 1 ? '表單已啟用' : '表單已停用', 'document_status' => $new_status]);
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('切換狀態失敗：' . $e->getMessage());
        }
        break;

    // 搜尋學生（學號或姓名，科辦用）
    case 'search_students':
        try {
            checkDocumentFormAdminPermission();

            $q = trim($_GET['q'] ?? '');
            if (mb_strlen($q) < 1) {
                json_ok(['students' => []]);
                break;
            }

            $sql = "
                SELECT u.u_ID, u.u_name
                FROM userdata u
                INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                WHERE ur.role_ID = 6 AND ur.user_role_status = 1
                  AND (u.u_status IS NULL OR u.u_status = 1)
                  AND (u.u_ID LIKE ? OR u.u_name LIKE ?)
                ORDER BY u.u_ID
                LIMIT 20
            ";
            $stmt = $conn->prepare($sql);
            $like = '%' . $q . '%';
            $stmt->execute([$like, $like]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_ok(['students' => $students]);
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('搜尋學生失敗：' . $e->getMessage());
        }
        break;

    // 取得指導老師列表（科辦用，全部老師）
    case 'get_teachers_list':
        try {
            checkDocumentFormAdminPermission();

            $sql = "
                SELECT DISTINCT u.u_ID, u.u_name
                FROM userdata u
                INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                WHERE ur.role_ID = 4 AND ur.user_role_status = 1
                  AND (u.u_status IS NULL OR u.u_status = 1)
                ORDER BY u.u_name
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_ok(['teachers' => $teachers]);
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('取得老師列表失敗：' . $e->getMessage());
        }
        break;

    // 取得學級列表（用於目標設定）- 只抓正在進行中的
    case 'get_grades_list':
        try {
            checkDocumentFormAdminPermission();

            // 從 cohortdata 表抓取正在進行中的屆別
            // 正在進行中 = cohort_status = 1 且 (cohort_end_d IS NULL 或 cohort_end_d >= 現在)
            $sql = "
                SELECT DISTINCT 
                    c.year_label as enroll_grade,
                    c.cohort_name,
                    c.cohort_ID
                FROM cohortdata c
                WHERE c.cohort_status = 1
                  AND (c.cohort_end_d IS NULL OR c.cohort_end_d >= CURDATE())
                ORDER BY c.year_label ASC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 轉換格式以符合前端期望
            $grades = [];
            foreach ($cohorts as $cohort) {
                $grades[] = [
                    'enroll_grade' => $cohort['enroll_grade'],
                    'cohort_name' => $cohort['cohort_name'],
                    'cohort_ID' => $cohort['cohort_ID']
                ];
            }

            json_ok(['grades' => $grades]);
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('取得學級列表失敗：' . $e->getMessage());
        }
        break;

    // 取得類組列表（用於目標設定）
    case 'get_groups_list':
        try {
            checkDocumentFormAdminPermission();

            $sql = "
                SELECT group_ID, group_name
                FROM groupdata
                WHERE group_status = 1
                ORDER BY group_ID ASC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_ok(['groups' => $groups]);
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('取得類組列表失敗：' . $e->getMessage());
        }
        break;

    // 取得班級列表（用於目標設定）- 只抓正在進行中的屆別的班級
    case 'get_classes_list':
        try {
            checkDocumentFormAdminPermission();

            $sql = "
                SELECT DISTINCT c.c_ID, c.c_name
                FROM classdata c
                INNER JOIN enrollmentdata e ON c.c_ID = e.class_ID
                INNER JOIN cohortdata co ON co.cohort_ID = e.cohort_ID
                WHERE e.enroll_status = 1
                  AND co.cohort_status = 1
                  AND (co.cohort_end_d IS NULL OR co.cohort_end_d >= CURDATE())
                ORDER BY c.c_ID ASC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_ok(['classes' => $classes]);
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('取得班級列表失敗：' . $e->getMessage());
        }
        break;

    // 取得屆別列表（科辦用）
    case 'get_cohorts_list':
        try {
            checkDocumentFormAdminPermission();

            $sql = "
                SELECT cohort_ID, cohort_name, year_label
                FROM cohortdata
                WHERE cohort_status = 1
                ORDER BY cohort_ID DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_ok(['cohorts' => $cohorts]);
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('取得屆別列表失敗：' . $e->getMessage());
        }
        break;

    // 獲取學生可用的開放表單列表（用於 apply_test.php）
    // 依科辦在 form_manage 設定的目標顯示：學級(GRADE/year_label)、類組(GROUP)、班級(CLASS)、屆別(COHORT)、全部(ALL)，邏輯由 document_form_targets 驅動，不寫死
    case 'get_available_document_forms':
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $u_ID = $document_form_get_u_id();
        if (!$u_ID) {
            json_err('請先登入', 'NOT_LOGGED_IN', 401);
        }

        $now = date('Y-m-d H:i:s');

        // 動態決定欄位，避免在移除 doc_des / is_required 後查詢失敗
        $availColumns = ['doc_ID', 'doc_name', 'form_schema'];
        if ($hasDocDesColumn()) {
            $availColumns[] = 'doc_des';
        }
        if ($hasIsRequiredColumn()) {
            $availColumns[] = 'is_required';
        }
        $availColumns[] = 'doc_start_d';
        $availColumns[] = 'doc_end_d';
        $availColumns[] = 'doc_status';
        $columnsSql = implode(', ', $availColumns);

        $sql = "
            SELECT 
                {$columnsSql}
            FROM document_forms
            WHERE doc_status = 1
              AND (doc_start_d IS NULL OR doc_start_d <= ?)
              AND (doc_end_d IS NULL OR doc_end_d >= ?)
            ORDER BY doc_ID DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$now, $now]);
        $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 若 doc_des / is_required 欄位已移除，補上預設值供前端使用
        if (!$hasDocDesColumn() || !$hasIsRequiredColumn()) {
            foreach ($forms as &$f) {
                if (!$hasDocDesColumn() && !isset($f['doc_des'])) {
                    $f['doc_des'] = '審查文件';
                }
                if (!$hasIsRequiredColumn() || !isset($f['is_required'])) {
                    $f['is_required'] = 0;
                }
            }
            unset($f);
        }

        // 解析 form_schema，補出 exresultdata 給前端使用
        foreach ($forms as &$f) {
            $f['exresultdata'] = false; // 預設 false

            if (!empty($f['form_schema'])) {
                $schema = json_decode($f['form_schema'], true);

                if (is_array($schema) && isset($schema['exresultdata'])) {
                    $f['exresultdata'] = filter_var($schema['exresultdata'], FILTER_VALIDATE_BOOLEAN);
                }
            }
        }
        unset($f);

        // 學生維度：學級(屆別 year_label)、班級、類組(從團隊取得)，用於與表單目標比對
        $studentInfo = null;
        try {
            // 獲取學級、班級資訊，並從 cohortdata 獲取 year_label（學級）
            $stmt = $conn->prepare("
                SELECT e.cohort_ID, e.class_ID, e.enroll_grade, c.year_label
                FROM enrollmentdata e
                LEFT JOIN cohortdata c ON e.cohort_ID = c.cohort_ID
                WHERE e.enroll_u_ID = ? AND e.enroll_status = 1
                ORDER BY e.enroll_created_d DESC
                LIMIT 1
            ");
            $stmt->execute([$u_ID]);
            $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($enrollment) {
                $studentInfo = [
                    'cohort_ID' => $enrollment['cohort_ID'],
                    'class_ID' => $enrollment['class_ID'],
                    'enroll_grade' => $enrollment['year_label'],
                    'year_label' => $enrollment['year_label'],
                ];

                // 獲取類組資訊（從團隊資料中獲取）
                $teamUserField = 'team_u_ID';
                $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $checkStmt->execute();
                if (!$checkStmt->fetch()) {
                    $teamUserField = 'u_ID';
                }

                $teamStmt = $conn->prepare("
                    SELECT td.group_ID
                    FROM teammember tm
                    INNER JOIN teamdata td ON tm.team_ID = td.team_ID
                    WHERE tm.{$teamUserField} = ? 
                      AND td.team_status = 1
                      AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
                    ORDER BY td.team_update_d DESC
                    LIMIT 1
                ");
                $teamStmt->execute([$u_ID]);
                $team = $teamStmt->fetch(PDO::FETCH_ASSOC);
                if ($team && !empty($team['group_ID'])) {
                    $studentInfo['group_ID'] = (string) $team['group_ID'];
                }
            }
        } catch (Throwable $e) {
            error_log('獲取學生資訊失敗: ' . $e->getMessage());
        }

        // 依 document_form_targets 過濾：表單有設定的維度（學級/類組/班級/屆別）須全部符合才顯示
        $visibleForms = [];
        foreach ($forms as $form) {
            $doc_ID = (int)$form['doc_ID'];
            try {
                $targetStmt = $conn->prepare("
                    SELECT doc_target_type, doc_target_ID
                    FROM document_form_targets
                    WHERE doc_ID = ?
                ");
                $targetStmt->execute([$doc_ID]);
                $targets = $targetStmt->fetchAll(PDO::FETCH_ASSOC);

                $targetAll = false;
                $targetCohorts = [];
                $targetGrades = [];
                $targetClasses = [];
                $targetGroups = [];
                foreach ($targets as $target) {
                    $type = $target['doc_target_type'];
                    $id = trim((string) $target['doc_target_ID']);
                    switch ($type) {
                        case 'ALL':
                            $targetAll = true;
                            break;
                        case 'COHORT':
                            $targetCohorts[] = $id;
                            break;
                        case 'GRADE':
                            try {
                                $gStmt = $conn->prepare("SELECT cohort_ID FROM cohortdata WHERE year_label = ? LIMIT 1");
                                $gStmt->execute([$id]);
                                $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
                                if ($gRow && !empty($gRow['cohort_ID'])) {
                                    $targetCohorts[] = (string) $gRow['cohort_ID'];
                                }
                            } catch (Throwable $e) {
                            }
                            break;
                        case 'CLASS':
                            $targetClasses[] = $id;
                            break;
                        case 'GROUP':
                            $targetGroups[] = $id;
                            break;
                    }
                }

                if (empty($targets)) {
                    $visibleForms[] = $form;
                    continue;
                }
                if ($targetAll) {
                    $visibleForms[] = $form;
                    continue;
                }
                if (!$studentInfo) {
                    continue;
                }

                if (!empty($targetGroups)) {
                    if (!isset($studentInfo['group_ID']) || (string)$studentInfo['group_ID'] === '') {
                        continue;
                    }
                    $userGroupId = trim((string) $studentInfo['group_ID']);
                    $groupMatched = false;
                    foreach ($targetGroups as $targetGroupId) {
                        $targetGroupIdStr = trim((string) $targetGroupId);
                        if ($userGroupId === $targetGroupIdStr || (int)$userGroupId === (int)$targetGroupIdStr) {
                            $groupMatched = true;
                            break;
                        }
                    }
                    if (!$groupMatched) {
                        continue;
                    }
                }

                if (!empty($targetCohorts)) {
                    if (!isset($studentInfo['cohort_ID']) || $studentInfo['cohort_ID'] === null || $studentInfo['cohort_ID'] === '') {
                        continue;
                    }
                    $studentCohortId = (string) $studentInfo['cohort_ID'];
                    if (!in_array($studentCohortId, $targetCohorts, true)) {
                        continue;
                    }
                }

                if (!empty($targetClasses)) {
                    if (!isset($studentInfo['class_ID']) || $studentInfo['class_ID'] === null || $studentInfo['class_ID'] === '') {
                        continue;
                    }
                    if (!in_array((string) $studentInfo['class_ID'], $targetClasses, true)) {
                        continue;
                    }
                }

                $visibleForms[] = $form;
            } catch (PDOException $e) {
                $visibleForms[] = $form;
            }
        }

        json_ok(['forms' => $visibleForms]);
    } catch (PDOException $e) {
        json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
    } catch (Throwable $e) {
        json_err('獲取開放表單列表失敗：' . $e->getMessage());
    }
    break;

    // 獲取表單詳情（學生用，不需要管理員權限）
    case 'get_document_form_detail_student':
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $u_ID = $document_form_get_u_id();
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }

            $doc_ID = (int)($p['doc_ID'] ?? $p['document_id'] ?? $_GET['doc_ID'] ?? $_GET['document_id'] ?? 0);

            if ($doc_ID <= 0) {
                json_err('表單ID無效');
            }

            $stmt = $conn->prepare("
                SELECT * FROM document_forms WHERE doc_ID = ? AND doc_status = 1
            ");
            $stmt->execute([$doc_ID]);
            $form = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$form) {
                json_err('找不到該表單或表單未啟用');
            }

            // 若 doc_des / is_required 欄位已移除，補上預設值供前端顯示
            if (!isset($form['doc_des'])) {
                $form['doc_des'] = '審查文件';
            }
            if (!isset($form['is_required'])) {
                $form['is_required'] = 0;
            }

            $now = date('Y-m-d H:i:s');
            if (!empty($form['doc_start_d']) && $form['doc_start_d'] > $now) {
                json_err('表單尚未開放');
            }
            if (!empty($form['doc_end_d']) && $form['doc_end_d'] < $now) {
                json_err('表單已過期');
            }

            // 檢查目標設定：驗證學生是否有權限查看此表單
            try {
                // 獲取學生資訊
                $stmt = $conn->prepare("
                    SELECT cohort_ID, class_ID, enroll_grade
                    FROM enrollmentdata
                    WHERE enroll_u_ID = ? AND enroll_status = 1
                    ORDER BY enroll_created_d DESC
                    LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

                $studentInfo = null;
                if ($enrollment) {
                    $studentInfo = [
                        'cohort_ID' => $enrollment['cohort_ID'],
                        'class_ID' => $enrollment['class_ID'],
                        'enroll_grade' => $enrollment['enroll_grade'],
                    ];

                    // 獲取類組資訊
                    $teamUserField = 'team_u_ID';
                    $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                    $checkStmt->execute();
                    if (!$checkStmt->fetch()) {
                        $teamUserField = 'u_ID';
                    }

                    $teamStmt = $conn->prepare("
                        SELECT td.group_ID
                        FROM teammember tm
                        INNER JOIN teamdata td ON tm.team_ID = td.team_ID
                        WHERE tm.{$teamUserField} = ? 
                          AND td.team_status = 1
                          AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
                        ORDER BY td.team_update_d DESC
                        LIMIT 1
                    ");
                    $teamStmt->execute([$u_ID]);
                    $team = $teamStmt->fetch(PDO::FETCH_ASSOC);
                    if ($team && !empty($team['group_ID'])) {
                        $studentInfo['group_ID'] = (string) $team['group_ID'];
                    }
                }

                // 獲取表單的目標設定
                $targetStmt = $conn->prepare("
                    SELECT doc_target_type, doc_target_ID
                    FROM document_form_targets
                    WHERE doc_ID = ?
                ");
                $targetStmt->execute([$doc_ID]);
                $targets = $targetStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($targets)) {
                    $targetAll = false;
                    $targetCohorts = [];
                    $targetGrades = [];
                    $targetClasses = [];
                    $targetGroups = [];

                    foreach ($targets as $target) {
                        $type = $target['doc_target_type'];
                        $id = trim((string) $target['doc_target_ID']);
                        switch ($type) {
                            case 'ALL':
                                $targetAll = true;
                                break;
                            case 'COHORT':
                                $targetCohorts[] = $id;
                                break;
                            case 'GRADE':
                                try {
                                    $gStmt = $conn->prepare("SELECT cohort_ID FROM cohortdata WHERE year_label = ? LIMIT 1");
                                    $gStmt->execute([$id]);
                                    $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
                                    if ($gRow && !empty($gRow['cohort_ID'])) {
                                        $targetCohorts[] = (string) $gRow['cohort_ID'];
                                    }
                                } catch (Throwable $e) { /* 略過 */
                                }
                                break;
                            case 'CLASS':
                                $targetClasses[] = $id;
                                break;
                            case 'GROUP':
                                $targetGroups[] = $id;
                                break;
                        }
                    }

                    if (!$targetAll) {
                        if (!$studentInfo) {
                            json_err('您沒有權限查看此表單');
                        }

                        if (!empty($targetGroups)) {
                            if (!isset($studentInfo['group_ID']) || $studentInfo['group_ID'] === null || $studentInfo['group_ID'] === '') {
                                json_err('您沒有權限查看此表單');
                            }
                            $userGroupId = trim((string) $studentInfo['group_ID']);
                            if ($userGroupId === '') {
                                json_err('您沒有權限查看此表單');
                            }
                            $groupMatched = false;
                            foreach ($targetGroups as $targetGroupId) {
                                $targetGroupIdStr = trim((string) $targetGroupId);
                                if ($userGroupId === $targetGroupIdStr || (int)$userGroupId === (int)$targetGroupIdStr) {
                                    $groupMatched = true;
                                    break;
                                }
                            }
                            if (!$groupMatched) {
                                json_err('您沒有權限查看此表單');
                            }
                        }

                        // 屆別（學級改為 COHORT）：以 cohort_ID 比對
                        if (!empty($targetCohorts)) {
                            if (!isset($studentInfo['cohort_ID']) || $studentInfo['cohort_ID'] === null || $studentInfo['cohort_ID'] === '') {
                                json_err('您沒有權限查看此表單。此表單僅限特定屆別的學生查看。');
                            }
                            if (!in_array((string) $studentInfo['cohort_ID'], $targetCohorts, true)) {
                                json_err('您沒有權限查看此表單。此表單僅限特定屆別的學生查看。');
                            }
                        }

                        if (!empty($targetClasses)) {
                            if (!isset($studentInfo['class_ID']) || $studentInfo['class_ID'] === null || $studentInfo['class_ID'] === '') {
                                json_err('您沒有權限查看此表單');
                            }
                            if (!in_array((string) $studentInfo['class_ID'], $targetClasses, true)) {
                                json_err('您沒有權限查看此表單');
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                // 如果表不存在，允許查看（向後兼容）
            }

            // 解析 form_schema JSON（與科辦端一致：抽出 _remark、pdf_footer_timestamps、補充附件設定，供學生端依規則顯示）
            if (!empty($form['form_schema'])) {
                $decodedSchema = json_decode($form['form_schema'], true);

                if (is_array($decodedSchema) && (isset($decodedSchema['_remark']) || isset($decodedSchema['questions']))) {
                    $form['document_remark'] = $decodedSchema['_remark'] ?? '';
                    $form['pdf_footer_timestamps'] = isset($decodedSchema['pdf_footer_timestamps']) ? (int)$decodedSchema['pdf_footer_timestamps'] : 1;
                    $form['supplement_attachment_enabled'] = isset($decodedSchema['supplement_attachment_enabled']) ? (int)$decodedSchema['supplement_attachment_enabled'] : 1;
                    $form['supplement_attachment_note'] = $decodedSchema['supplement_attachment_note'] ?? '';
                    $form['exresultdata'] = isset($decodedSchema['exresultdata']) ? (int)(!!$decodedSchema['exresultdata']) : 0;

                    // questions 才另外抽出來
                    $form['form_schema'] = $decodedSchema['questions'] ?? [];
                } elseif (is_array($decodedSchema)) {
                    $form['form_schema'] = $decodedSchema;
                    $form['exresultdata'] = 0;
                } else {
                    $form['form_schema'] = [];
                    $form['exresultdata'] = 0;
                }

                // 調試：檢查載入時的 rows 值
                if (is_array($form['form_schema'])) {
                    foreach ($form['form_schema'] as $idx => $q) {
                        if (isset($q['type']) && $q['type'] === 'textarea' && isset($q['rows'])) {
                            error_log("後端載入 rows: question[$idx] rows=" . $q['rows'] . " (type: " . gettype($q['rows']) . ")");
                        }
                    }
                }
            } else {
                $form['form_schema'] = [];
                $form['exresultdata'] = 0;
            }
            if (!isset($form['pdf_footer_timestamps'])) {
                $form['pdf_footer_timestamps'] = 1;
            }
            if (!isset($form['supplement_attachment_enabled'])) {
                $form['supplement_attachment_enabled'] = 1;
            }
            if (!isset($form['supplement_attachment_note'])) {
                $form['supplement_attachment_note'] = '';
            }

            // 學生端：一併回傳專題基本資料（專題題目、組員、指導老師）供自動帶入，不需學生操作
            $project_data = ['has_team' => false, 'project_title' => '', 'students' => [], 'advisor' => ''];
            try {
                $teamUserField = 'team_u_ID';
                $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $checkStmt->execute();
                if (!$checkStmt->fetch()) {
                    $teamUserField = 'u_ID';
                }
                $stmt = $conn->prepare("
                    SELECT t.team_ID, t.team_project_name
                    FROM teammember tm
                    INNER JOIN teamdata t ON tm.team_ID = t.team_ID
                    WHERE tm.{$teamUserField} = ? AND tm.tm_status = 1 AND t.team_status = 1
                    ORDER BY t.team_update_d DESC
                    LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $teamRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($teamRow) {
                    $project_data['has_team'] = true;
                    $project_data['project_title'] = $teamRow['team_project_name'] ?? '';
                    // 專題生：依實際資料庫抓取（userdata 有 u_account 用學號欄位，無則用 u_ID 當學號）
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
                    $project_data['students'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                    $project_data['advisor'] = $advisorName;
                }
            } catch (Throwable $e) {
                // 忽略，project_data 保持預設
            }

            // 檢查提交狀態：同組任一人已繳交即視為已繳交（一份表單一組只能繳交一次，繳交後全組唯讀）
            $submission_status = null;
            $submitted_at = null;
            try {
                if (!empty($teamRow['team_ID'])) {
                    $team_ID = (int)$teamRow['team_ID'];
                    $stmtSub = $conn->prepare("
                        SELECT ds.dcsub_status, ds.dcsub_sub_d
                        FROM document_submissions ds
                        INNER JOIN teammember tm ON tm.{$teamUserField} = ds.dcsub_u_ID AND tm.team_ID = ?
                        WHERE ds.doc_ID = ? AND ds.dcsub_status = 1
                        ORDER BY ds.dcsub_sub_d DESC
                        LIMIT 1
                    ");
                    $stmtSub->execute([$team_ID, $doc_ID]);
                    $subRow = $stmtSub->fetch(PDO::FETCH_ASSOC);
                } else {
                    $stmtSub = $conn->prepare("
                        SELECT dcsub_status, dcsub_sub_d FROM document_submissions
                        WHERE doc_ID = ? AND dcsub_u_ID = ? AND dcsub_status = 1
                        ORDER BY dcsub_sub_d DESC LIMIT 1
                    ");
                    $stmtSub->execute([$doc_ID, $u_ID]);
                    $subRow = $stmtSub->fetch(PDO::FETCH_ASSOC);
                }
                if ($subRow && (int)($subRow['dcsub_status'] ?? 0) === 1) {
                    $submission_status = 'submitted';
                    $submitted_at = !empty($subRow['dcsub_sub_d']) ? $subRow['dcsub_sub_d'] : null;
                }
            } catch (Throwable $e) {
                // 忽略錯誤
            }

            json_ok(['form' => $form, 'project_data' => $project_data, 'submission_status' => $submission_status, 'submitted_at' => $submitted_at]);
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('獲取表單詳情失敗：' . $e->getMessage());
        }
        break;

    // 獲取專題資料（學生用，用於自動填入學號和指導老師）
    case 'get_project_data_for_student':
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $u_ID = $document_form_get_u_id();
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }

            if (!isset($conn)) {
                json_ok(['has_team' => false, 'students' => [], 'advisor' => '']);
                break;
            }

            // 檢查 teammember 表使用哪個欄位名稱
            $teamUserField = 'team_u_ID';
            try {
                $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $checkStmt->execute();
                if (!$checkStmt->fetch()) {
                    $teamUserField = 'u_ID';
                }
            } catch (PDOException $e) {
                // 表不存在或無法查詢時，回傳無團隊，避免 500
                json_ok(['has_team' => false, 'students' => [], 'advisor' => '']);
                break;
            }

            // 獲取用戶所屬的團隊
            $stmt = $conn->prepare("
                SELECT 
                    t.team_ID,
                    t.team_project_name,
                    t.cohort_ID
                FROM teammember tm
                INNER JOIN teamdata t ON tm.team_ID = t.team_ID
                WHERE tm.{$teamUserField} = ? AND tm.tm_status = 1 AND t.team_status = 1
                ORDER BY t.team_update_d DESC
                LIMIT 1
            ");
            $stmt->execute([$u_ID]);
            $teamData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$teamData) {
                json_ok([
                    'has_team' => false,
                    'students' => [],
                    'advisor' => ''
                ]);
                break;
            }

            $team_ID = $teamData['team_ID'];
            $cohort_ID = $teamData['cohort_ID'];

            // 專題生：依實際資料庫（userdata 有 u_account 用學號欄位，無則用 u_ID 當學號）
            $studentIdCol = 'u.u_ID';
            $orderByCol = 'u.u_ID';
            try {
                $checkAccount = $conn->prepare("SHOW COLUMNS FROM userdata LIKE 'u_account'");
                $checkAccount->execute();
                if ($checkAccount->fetch()) {
                    $studentIdCol = 'u.u_account';
                    $orderByCol = 'u.u_account';
                }
            } catch (Throwable $e) {
                // 保持 u_ID
            }
            $stmt = $conn->prepare("
                SELECT 
                    u.u_ID,
                    u.u_name,
                    {$studentIdCol} as student_id
                FROM teammember tm
                INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
                INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                WHERE tm.team_ID = ? 
                  AND tm.tm_status = 1
                  AND ur.role_ID = 6 
                  AND ur.user_role_status = 1
                ORDER BY {$orderByCol}
            ");
            $stmt->execute([$team_ID]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 獲取指導老師
            $advisorName = '';
            try {
                // 先檢查 teamdata 是否有 advisor 欄位
                $checkAdvisorStmt = $conn->prepare("SHOW COLUMNS FROM teamdata LIKE 'advisor%'");
                $checkAdvisorStmt->execute();
                $hasAdvisorField = $checkAdvisorStmt->fetch() !== false;

                if ($hasAdvisorField) {
                    $stmt = $conn->prepare("SELECT advisor FROM teamdata WHERE team_ID = ?");
                    $stmt->execute([$team_ID]);
                    $advisorResult = $stmt->fetch(PDO::FETCH_ASSOC);
                    $advisor_ID = $advisorResult['advisor'] ?? null;

                    if ($advisor_ID) {
                        $stmt = $conn->prepare("SELECT u_name FROM userdata WHERE u_ID = ?");
                        $stmt->execute([$advisor_ID]);
                        $advisorUser = $stmt->fetch(PDO::FETCH_ASSOC);
                        $advisorName = $advisorUser['u_name'] ?? '';
                    }
                } else {
                    $stmt = $conn->prepare("
                        SELECT u.u_name
                        FROM teammember tm
                        INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
                        INNER JOIN userrolesdata ur ON u.u_ID = ur.ur_u_ID
                        WHERE tm.team_ID = ? 
                          AND tm.tm_status = 1
                          AND ur.role_ID = 4 
                          AND ur.user_role_status = 1
                        LIMIT 1
                    ");
                    $stmt->execute([$team_ID]);
                    $advisorResult = $stmt->fetch(PDO::FETCH_ASSOC);
                    $advisorName = $advisorResult['u_name'] ?? '';
                }
            } catch (PDOException $e) {
                // 指導老師查詢失敗不影響主流程，留空即可
            }

            json_ok([
                'has_team' => true,
                'team_ID' => $team_ID,
                'students' => $students,
                'advisor' => $advisorName
            ]);
        } catch (PDOException $e) {
            error_log('get_project_data_for_student DB: ' . $e->getMessage());
            json_ok(['has_team' => false, 'students' => [], 'advisor' => '']);
        } catch (Throwable $e) {
            error_log('get_project_data_for_student: ' . $e->getMessage());
            json_ok(['has_team' => false, 'students' => [], 'advisor' => '']);
        }
        break;

    // 提交文件表單（學生用）
    case 'submit_document_form':
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $u_ID = $document_form_get_u_id();
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }

            // 檢查是否已提交：本人或同組任一人已繳交即阻擋（一份表單一組只能繳交一次）
            $doc_ID = (int)($p['doc_ID'] ?? $p['document_id'] ?? 0);
            if ($doc_ID > 0) {
                try {
                    $teamUserFieldSubmit = 'team_u_ID';
                    $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                    $checkStmt->execute();
                    if (!$checkStmt->fetch()) {
                        $teamUserFieldSubmit = 'u_ID';
                    }
                    $stmtTeam = $conn->prepare("SELECT team_ID FROM teammember WHERE {$teamUserFieldSubmit} = ? AND (tm_status = 1 OR tm_status IS NULL) ORDER BY team_ID DESC LIMIT 1");
                    $stmtTeam->execute([$u_ID]);
                    $myTeam = $stmtTeam->fetch(PDO::FETCH_ASSOC);
                    if ($myTeam && !empty($myTeam['team_ID'])) {
                        $stmtCheck = $conn->prepare("
                            SELECT 1 FROM document_submissions ds
                            INNER JOIN teammember tm ON tm.{$teamUserFieldSubmit} = ds.dcsub_u_ID AND tm.team_ID = ?
                            WHERE ds.doc_ID = ? AND ds.dcsub_status = 1
                            LIMIT 1
                        ");
                        $stmtCheck->execute([$myTeam['team_ID'], $doc_ID]);
                        if ($stmtCheck->fetch()) {
                            json_err('同組已有組員繳交此文件，無法重複繳交。', 'ALREADY_SUBMITTED', 403);
                        }
                    } else {
                        $stmtCheck = $conn->prepare("SELECT dcsub_status FROM document_submissions WHERE doc_ID = ? AND dcsub_u_ID = ? AND dcsub_status = 1 LIMIT 1");
                        $stmtCheck->execute([$doc_ID, $u_ID]);
                        if ($stmtCheck->fetch()) {
                            json_err('此表單已提交，無法再次修改', 'ALREADY_SUBMITTED', 403);
                        }
                    }
                } catch (Throwable $e) {
                    // 忽略錯誤，繼續處理
                }
            }

            $doc_ID = (int)($p['doc_ID'] ?? $p['document_id'] ?? 0);
            $apply_user = trim($p['apply_user'] ?? '');
            $apply_other = trim($p['apply_other'] ?? '');
            $form_answers = isset($p['form_answers']) ? json_decode($p['form_answers'], true) : [];

            if ($doc_ID <= 0) {
                json_err('表單ID無效');
            }

            $stmt = $conn->prepare("
                SELECT * FROM document_forms 
                WHERE doc_ID = ? AND doc_status = 1
            ");
            $stmt->execute([$doc_ID]);
            $form = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$form) {
                json_err('找不到該表單或表單未啟用');
            }

            // 防呆：時間檢查
            $now = date('Y-m-d H:i:s');
            if (!empty($form['doc_start_d']) && $form['doc_start_d'] > $now) {
                json_err('表單尚未開放，開放時間：' . $form['doc_start_d']);
            }
            if (!empty($form['doc_end_d']) && $form['doc_end_d'] < $now) {
                json_err('表單已過期，截止時間：' . $form['doc_end_d']);
            }

            // 防呆：目標設定檢查（再次驗證權限）
            try {
                // 獲取學生資訊
                $stmt = $conn->prepare("
                    SELECT cohort_ID, class_ID, enroll_grade
                    FROM enrollmentdata
                    WHERE enroll_u_ID = ? AND enroll_status = 1
                    ORDER BY enroll_created_d DESC
                    LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

                $studentInfo = null;
                if ($enrollment) {
                    $studentInfo = [
                        'cohort_ID' => $enrollment['cohort_ID'],
                        'class_ID' => $enrollment['class_ID'],
                        'enroll_grade' => $enrollment['enroll_grade'],
                    ];

                    // 獲取類組資訊
                    $teamUserField = 'team_u_ID';
                    $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                    $checkStmt->execute();
                    if (!$checkStmt->fetch()) {
                        $teamUserField = 'u_ID';
                    }

                    $teamStmt = $conn->prepare("
                        SELECT td.group_ID
                        FROM teammember tm
                        INNER JOIN teamdata td ON tm.team_ID = td.team_ID
                        WHERE tm.{$teamUserField} = ? 
                          AND td.team_status = 1
                          AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
                        ORDER BY td.team_update_d DESC
                        LIMIT 1
                    ");
                    $teamStmt->execute([$u_ID]);
                    $team = $teamStmt->fetch(PDO::FETCH_ASSOC);
                    if ($team && !empty($team['group_ID'])) {
                        $studentInfo['group_ID'] = (string) $team['group_ID'];
                    }
                }

                // 獲取表單的目標設定
                $targetStmt = $conn->prepare("
                    SELECT doc_target_type, doc_target_ID
                    FROM document_form_targets
                    WHERE doc_ID = ?
                ");
                $targetStmt->execute([$doc_ID]);
                $targets = $targetStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($targets)) {
                    $targetAll = false;
                    $targetCohorts = [];
                    $targetGrades = [];
                    $targetClasses = [];
                    $targetGroups = [];

                    foreach ($targets as $target) {
                        $type = $target['doc_target_type'];
                        $id = trim((string) $target['doc_target_ID']);
                        switch ($type) {
                            case 'ALL':
                                $targetAll = true;
                                break;
                            case 'COHORT':
                                $targetCohorts[] = $id;
                                break;
                            case 'GRADE':
                                try {
                                    $gStmt = $conn->prepare("SELECT cohort_ID FROM cohortdata WHERE year_label = ? LIMIT 1");
                                    $gStmt->execute([$id]);
                                    $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
                                    if ($gRow && !empty($gRow['cohort_ID'])) {
                                        $targetCohorts[] = (string) $gRow['cohort_ID'];
                                    }
                                } catch (Throwable $e) { /* 略過 */
                                }
                                break;
                            case 'CLASS':
                                $targetClasses[] = $id;
                                break;
                            case 'GROUP':
                                $targetGroups[] = $id;
                                break;
                        }
                    }

                    if (!$targetAll) {
                        if (!$studentInfo) {
                            json_err('您沒有權限提交此表單。請確認您已加入專題團隊。');
                        }

                        if (!empty($targetGroups)) {
                            if (!isset($studentInfo['group_ID']) || $studentInfo['group_ID'] === null || $studentInfo['group_ID'] === '') {
                                json_err('您沒有權限提交此表單。此表單僅限特定類組的學生提交。');
                            }
                            $userGroupId = trim((string) $studentInfo['group_ID']);
                            if ($userGroupId === '') {
                                json_err('您沒有權限提交此表單。此表單僅限特定類組的學生提交。');
                            }
                            $groupMatched = false;
                            foreach ($targetGroups as $targetGroupId) {
                                $targetGroupIdStr = trim((string) $targetGroupId);
                                if ($userGroupId === $targetGroupIdStr || (int)$userGroupId === (int)$targetGroupIdStr) {
                                    $groupMatched = true;
                                    break;
                                }
                            }
                            if (!$groupMatched) {
                                json_err('您沒有權限提交此表單。此表單僅限特定類組的學生提交。');
                            }
                        }

                        // 屆別（學級改為 COHORT）：以 cohort_ID 比對
                        if (!empty($targetCohorts)) {
                            if (!isset($studentInfo['cohort_ID']) || $studentInfo['cohort_ID'] === null || $studentInfo['cohort_ID'] === '') {
                                json_err('您沒有權限提交此表單。此表單僅限特定屆別的學生提交。');
                            }
                            if (!in_array((string) $studentInfo['cohort_ID'], $targetCohorts, true)) {
                                json_err('您沒有權限提交此表單。此表單僅限特定屆別的學生提交。');
                            }
                        }

                        if (!empty($targetClasses)) {
                            if (!isset($studentInfo['class_ID']) || $studentInfo['class_ID'] === null || $studentInfo['class_ID'] === '') {
                                json_err('您沒有權限提交此表單。此表單僅限特定班級的學生提交。');
                            }
                            if (!in_array((string) $studentInfo['class_ID'], $targetClasses, true)) {
                                json_err('您沒有權限提交此表單。此表單僅限特定班級的學生提交。');
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                // 如果表不存在，允許提交（向後兼容）
            }

            // 補充附件（PDF）：僅允許 1 份，儲存後匯出 PDF 時自動合併於文件最後頁
            if (!empty($_FILES['supplement_pdf']) && $_FILES['supplement_pdf']['error'] === UPLOAD_ERR_OK) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['supplement_pdf']['tmp_name']);
                if ($mime !== 'application/pdf' && strpos($mime, 'pdf') === false) {
                    json_err('附件僅允許 PDF 檔案');
                }
                $ext = strtolower(pathinfo($_FILES['supplement_pdf']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'pdf') {
                    json_err('附件僅允許 PDF 檔案');
                }
                $uploadDir = projectevery_full_path('uploads/document_submissions');
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $safeName = 'doc_' . $doc_ID . '_u_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $u_ID) . '.pdf';
                $file_path = 'uploads/document_submissions/' . $safeName;
                $fullPath = projectevery_full_path($file_path);
                if (!move_uploaded_file($_FILES['supplement_pdf']['tmp_name'], $fullPath)) {
                    json_err('附件儲存失敗');
                }
                $versionErr = $document_form_validate_pdf_version($fullPath, $doc_ID, $form['doc_updated_d'] ?? null);
                if ($versionErr !== null) {
                    @unlink($fullPath);
                    json_err($versionErr);
                }
                $attach_name = $_FILES['supplement_pdf']['name'] ?: 'supplement.pdf';
                $attach_path = $file_path;
            } else {
                $attach_name = null;
                $attach_path = null;
            }

            $sign_name = null;
            $sign_path = null;
            $sign_uploaded_d = null;
            $original_pdf_path = null;
            $qr_modified_at = null;
            $verify_result = null;
            $verify_note = null;
            $sign_version_mismatch_msg = '⚠️【簽名版本不符】此簽名 PDF 並非對應目前「最後修改版本」的文件。請重新下載最新版本、完成簽名後再上傳。';
            if (!empty($_FILES['sign_pdf']) && $_FILES['sign_pdf']['error'] === UPLOAD_ERR_OK) {
                error_log('save_document_form_draft: $_FILES[sign_pdf] name=' . ($_FILES['sign_pdf']['name'] ?? '') . ', error=' . ($_FILES['sign_pdf']['error'] ?? '') . ', size=' . ($_FILES['sign_pdf']['size'] ?? ''));
                $tmpPath = $_FILES['sign_pdf']['tmp_name'];
                $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpPath);
                $ext = strtolower(pathinfo($_FILES['sign_pdf']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'pdf' || (strpos($mime, 'pdf') === false && $mime !== 'application/octet-stream')) {
                    json_err('簽名檔僅允許 PDF 格式');
                }

                $stmtDraft = $conn->prepare("SELECT sub_ID, dcsub_updated_d, original_pdf_path, snapshot_token FROM document_submissions WHERE doc_ID = ? AND dcsub_u_ID = ? AND (dcsub_status = 4 OR dcsub_status = 1) ORDER BY dcsub_updated_d DESC, sub_ID DESC LIMIT 1");
                $stmtDraft->execute([$doc_ID, $u_ID]);
                $draftRow = $stmtDraft->fetch(PDO::FETCH_ASSOC);
                $current_sub_id_for_sign = $draftRow ? (int)$draftRow['sub_ID'] : null;

                // 預設未核實；後續會依 snapshot_token 立即核實並改寫 $verify_result / $verify_note（同一 if/else 決定）
                $verify_result = 0;
                $verify_note = '無法核實';
                $qr_modified_at = null;

                if ($draftRow && !empty($draftRow['original_pdf_path'])) {
                    $original_pdf_path = $draftRow['original_pdf_path'];
                }

                $versionErr = $validate_sign_pdf_version($tmpPath, $doc_ID, $u_ID, $conn);
                if ($versionErr !== null) {
                    json_err($versionErr);
                }

                $uploadDir = projectevery_full_path('uploads/document_form_signs');
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $safeName = 'doc_' . $doc_ID . '_u_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $u_ID) . '_' . time() . '.pdf';
                $file_path = 'uploads/document_form_signs/' . $safeName;
                $fullPath = projectevery_full_path($file_path);
                if (!move_uploaded_file($tmpPath, $fullPath)) {
                    json_err('簽名 PDF 儲存失敗');
                }
                $sign_name = $_FILES['sign_pdf']['name'] ?: 'sign.pdf';
                $sign_path = $file_path;
                $sign_uploaded_d = date('Y-m-d H:i:s');
                $sign_version_verified = true;

                // 依 snapshot_token 立即核實，與 verify_sign.php 規則一致
                if ($current_sub_id_for_sign && (function_exists('verify_sign_extract_pdf_text') || is_file(dirname(__DIR__) . '/includes/verify_sign_ai_compare.php'))) {
                    if (!function_exists('verify_sign_extract_pdf_text')) {
                        require_once dirname(__DIR__) . '/includes/verify_sign_ai_compare.php';
                    }
                    try {
                        $dbSnapshotToken = isset($draftRow['snapshot_token']) ? strtolower(trim((string)$draftRow['snapshot_token'])) : '';
                        $uploadedSnapshotToken = null;
                        if ($dbSnapshotToken !== '') {
                            $pdfText = verify_sign_extract_pdf_text($fullPath, 24000);
                            if ($pdfText !== '' && preg_match('/SNAPSHOT_TOKEN\s*[=:]\s*([a-f0-9]{64})/i', $pdfText, $m)) {
                                $uploadedSnapshotToken = strtolower(trim($m[1]));
                            }
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
                    } catch (Throwable $e) {
                        error_log('save_document_form（提交）簽名立即核實失敗: ' . $e->getMessage());
                    }
                }

                // 立即更新到同一筆 sub_ID 記錄
                try {
                    $target_sub_id = $current_sub_id_for_sign;
                    if (!$target_sub_id) {
                        // 如果沒有獲取到sub_ID，嘗試查找
                        $stmtUpdateSign = $conn->prepare("
                            SELECT sub_ID FROM document_submissions
                            WHERE doc_ID = ? AND dcsub_u_ID = ?
                            ORDER BY sub_ID DESC LIMIT 1
                        ");
                        $stmtUpdateSign->execute([$doc_ID, $u_ID]);
                        $subIdRow = $stmtUpdateSign->fetch(PDO::FETCH_ASSOC);
                        if ($subIdRow && !empty($subIdRow['sub_ID'])) {
                            $target_sub_id = (int)$subIdRow['sub_ID'];
                        }
                    }
                    if ($target_sub_id) {
                        $setParts = ['sign_path = COALESCE(?, sign_path)', 'verify_result = ?', 'verify_note = ?'];
                        $execParams = [$sign_path, $verify_result, $verify_note, $target_sub_id];
                        if ($docSubHasSignD) {
                            $setParts[] = 'sign_uploaded_d = NOW()';
                        }
                        if ($docSubHasQr) {
                            $setParts[] = 'qr_modified_at = ?';
                            array_splice($execParams, 1, 0, [$qr_modified_at]);
                        }
                        $stmtUpdate = $conn->prepare("UPDATE document_submissions SET " . implode(', ', $setParts) . " WHERE sub_ID = ?");
                        $stmtUpdate->execute($execParams);
                        if ($stmtUpdate->rowCount() > 0) {
                            error_log('成功更新簽名PDF到sub_ID: ' . $target_sub_id);
                        } else {
                            error_log('更新簽名PDF到sub_ID失敗: 沒有影響任何記錄, sub_ID=' . $target_sub_id);
                        }
                    } else {
                        error_log('無法找到sub_ID來更新簽名PDF');
                    }
                } catch (Throwable $e) {
                    // 如果更新失敗，記錄錯誤但不阻擋流程（後續會在暫存/提交時更新）
                    error_log('更新簽名PDF到sub_ID失敗: ' . $e->getMessage());
                }
            }

            // 若無新上傳附件，檢查暫存是否有附件（供 PDF 合併）
            if ($attach_path === null) {
                try {
                    $stmtDraft = $conn->prepare("
                        SELECT attach_name, attach_path FROM document_submissions
                        WHERE doc_ID = ? AND dcsub_u_ID = ? AND (dcsub_status = 4 OR dcsub_status = 0)
                        LIMIT 1
                    ");
                    $stmtDraft->execute([$doc_ID, $u_ID]);
                    $draftRow = $stmtDraft->fetch(PDO::FETCH_ASSOC);
                    if ($draftRow && !empty($draftRow['attach_path'])) {
                        $attach_name = $draftRow['attach_name'] ?? 'supplement.pdf';
                        $attach_path = $draftRow['attach_path'];
                    }
                } catch (Throwable $e) {
                    // 忽略，繼續提交
                }
            }

            // 若無新上傳簽名圖檔，從暫存取得
            if ($sign_path === null) {
                try {
                    $stmtSign = $conn->prepare("
                        SELECT sign_name, sign_path FROM document_submissions
                        WHERE doc_ID = ? AND dcsub_u_ID = ? AND (dcsub_status = 4 OR dcsub_status = 0) LIMIT 1
                    ");
                    $stmtSign->execute([$doc_ID, $u_ID]);
                    $signRow = $stmtSign->fetch(PDO::FETCH_ASSOC);
                    if ($signRow && !empty($signRow['sign_path'])) {
                        $sign_name = $signRow['sign_name'] ?? 'sign.jpg';
                        $sign_path = $signRow['sign_path'];
                    }
                } catch (Throwable $e) {
                }
            }

            // 寫入或更新 document_submissions（提交記錄，dcsub_status='submitted' 表示已提交）
            $dcsub_answers = json_encode(is_array($form_answers) ? $form_answers : [], JSON_UNESCAPED_UNICODE);
            $attach_name = $attach_name ?? '';
            $attach_path = $attach_path ?? '';
            $sign_name = $sign_name ?? '';
            $sign_path = $sign_path ?? '';

            // 同組共用一筆：先查同組任一人之草稿/已提交記錄，取該筆 sub_ID
            $submitMemberIds = [$u_ID];
            try {
                $stmtTeamSub = $conn->prepare("SELECT team_ID FROM teammember WHERE {$teamUserFieldSubmit} = ? AND (tm_status = 1 OR tm_status IS NULL) LIMIT 1");
                $stmtTeamSub->execute([$u_ID]);
                $teamRowSub = $stmtTeamSub->fetch(PDO::FETCH_ASSOC);
                if ($teamRowSub && !empty($teamRowSub['team_ID'])) {
                    $stmtMemSub = $conn->prepare("SELECT {$teamUserFieldSubmit} FROM teammember WHERE team_ID = ?");
                    $stmtMemSub->execute([$teamRowSub['team_ID']]);
                    $submitMemberIds = array_values(array_filter(array_map('trim', array_column($stmtMemSub->fetchAll(PDO::FETCH_ASSOC), $teamUserFieldSubmit))));
                    if (empty($submitMemberIds)) {
                        $submitMemberIds = [$u_ID];
                    }
                }
            } catch (Throwable $e) {
                $submitMemberIds = [$u_ID];
            }
            $current_sub_id = null;
            try {
                $ph = implode(',', array_fill(0, count($submitMemberIds), '?'));
                $stmtGetSubId = $conn->prepare("
                    SELECT sub_ID FROM document_submissions
                    WHERE doc_ID = ? AND dcsub_u_ID IN ($ph) AND (dcsub_status = 4 OR dcsub_status = 0 OR dcsub_status = 1)
                    ORDER BY dcsub_updated_d DESC, sub_ID DESC LIMIT 1
                ");
                $stmtGetSubId->execute(array_merge([$doc_ID], $submitMemberIds));
                $subIdRow = $stmtGetSubId->fetch(PDO::FETCH_ASSOC);
                if ($subIdRow) {
                    $current_sub_id = (int)$subIdRow['sub_ID'];
                }
            } catch (Throwable $e) {
            }

            // 一組只一筆：取同組最新一筆並 UPDATE 為 submitted，無則 INSERT
            try {
                try {
                    $ph = implode(',', array_fill(0, count($submitMemberIds), '?'));
                    $stmtExist = $conn->prepare("
                        SELECT sub_ID, attach_name, attach_path, sign_name, sign_path, original_pdf_path FROM document_submissions
                        WHERE doc_ID = ? AND dcsub_u_ID IN ($ph)
                        ORDER BY sub_ID DESC LIMIT 1
                    ");
                } catch (Throwable $e) {
                    $stmtExist = $conn->prepare("
                        SELECT sub_ID, attach_name, attach_path FROM document_submissions
                        WHERE doc_ID = ? AND dcsub_u_ID = ?
                        ORDER BY sub_ID DESC LIMIT 1
                    ");
                }
                if (count($submitMemberIds) > 1) {
                    $stmtExist->execute(array_merge([$doc_ID], $submitMemberIds));
                } else {
                    $stmtExist->execute([$doc_ID, $u_ID]);
                }
                $existing = $stmtExist->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    // 確保使用同一筆sub_ID（如果之前沒有獲取到，使用現有記錄的sub_ID）
                    if ($current_sub_id === null) {
                        $current_sub_id = (int)$existing['sub_ID'];
                    }

                    if ($attach_name === '' && !empty($existing['attach_name'])) {
                        $attach_name = $existing['attach_name'];
                        $attach_path = $existing['attach_path'] ?? '';
                    }
                    if ($sign_name === '' && isset($existing['sign_path']) && !empty($existing['sign_path'])) {
                        $sign_name = $existing['sign_name'] ?? 'sign.pdf';
                        $sign_path = $existing['sign_path'];
                    }
                    // 如果沒有original_pdf_path，從現有記錄獲取
                    if ($original_pdf_path === null && isset($existing['original_pdf_path']) && !empty($existing['original_pdf_path'])) {
                        $original_pdf_path = $existing['original_pdf_path'];
                    }
                    try {
                        $upParts = ['dcsub_answers = ?', 'attach_name = ?', 'attach_path = ?', 'sign_name = ?', 'sign_path = ?', 'original_pdf_path = ?', 'verify_result = ?', 'dcsub_status = 1', 'dcsub_sub_d = NOW()', 'dcsub_updated_d = NOW()'];
                        $upParams = [$dcsub_answers, $attach_name, $attach_path, $sign_name, $sign_path, $original_pdf_path, $verify_result, $current_sub_id];
                        if ($docSubHasSignD) {
                            $upParts[] = 'sign_uploaded_d = ?';
                            array_splice($upParams, 6, 0, [$sign_uploaded_d]);
                        }
                        if ($docSubHasQr) {
                            $upParts[] = 'qr_modified_at = ?';
                            array_splice($upParams, 7, 0, [$qr_modified_at]);
                        }
                        $conn->prepare("UPDATE document_submissions SET " . implode(', ', $upParts) . " WHERE sub_ID = ?")->execute($upParams);
                    } catch (Throwable $e) {
                        try {
                            $upParts = ['dcsub_answers = ?', 'attach_name = ?', 'attach_path = ?', 'sign_name = ?', 'sign_path = ?', 'original_pdf_path = ?', 'verify_result = ?', 'dcsub_status = 1', 'dcsub_sub_d = NOW()', 'dcsu_updated_d = NOW()'];
                            $upParams = [$dcsub_answers, $attach_name, $attach_path, $sign_name, $sign_path, $original_pdf_path, $verify_result, $current_sub_id];
                            if ($docSubHasSignD) {
                                $upParts[] = 'sign_uploaded_d = ?';
                                array_splice($upParams, 6, 0, [$sign_uploaded_d]);
                            }
                            if ($docSubHasQr) {
                                $upParts[] = 'qr_modified_at = ?';
                                array_splice($upParams, 7, 0, [$qr_modified_at]);
                            }
                            $conn->prepare("UPDATE document_submissions SET " . implode(', ', $upParts) . " WHERE sub_ID = ?")->execute($upParams);
                        } catch (Throwable $e2) {
                            $conn->prepare("UPDATE document_submissions SET dcsub_answers = ?, attach_name = ?, attach_path = ?, sign_name = ?, sign_path = ?, dcsub_status = 1, dcsub_sub_d = NOW(), dcsu_updated_d = NOW() WHERE sub_ID = ?")->execute([$dcsub_answers, $attach_name, $attach_path, $sign_name, $sign_path, $current_sub_id]);
                        }
                    }
                } else {
                    $ic = ['doc_ID', 'dcsub_u_ID', 'dcsub_status', 'dcsub_answers', 'attach_name', 'attach_path', 'sign_name', 'sign_path', 'original_pdf_path', 'verify_result', 'dcsub_created_d', 'dcsub_sub_d', 'dcsub_updated_d'];
                    $iv = [$doc_ID, $u_ID, 1, $dcsub_answers, $attach_name, $attach_path, $sign_name, $sign_path, $original_pdf_path, $verify_result];
                    if ($docSubHasSignD) {
                        array_splice($ic, 9, 0, ['sign_uploaded_d']);
                        array_splice($iv, 8, 0, [$sign_uploaded_d]);
                    }
                    if ($docSubHasQr) {
                        array_splice($ic, 10, 0, ['qr_modified_at']);
                        array_splice($iv, 9, 0, [$qr_modified_at]);
                    }
                    $ph = implode(',', array_fill(0, count($iv), '?')) . ', NOW(), NOW(), NOW()';
                    try {
                        $conn->prepare("INSERT INTO document_submissions (" . implode(',', $ic) . ") VALUES ({$ph})")->execute($iv);
                    } catch (Throwable $e) {
                        $ic[array_search('dcsub_updated_d', $ic)] = 'dcsu_updated_d';
                        $conn->prepare("INSERT INTO document_submissions (" . implode(',', $ic) . ") VALUES ({$ph})")->execute($iv);
                    }
                }
            } catch (Throwable $e) {
                // 一組一份文件只會有一筆：不直接 INSERT，先查是否已有記錄，有則 UPDATE 避免重複
                $fallbackExisting = null;
                try {
                    $stmtFb = $conn->prepare("SELECT sub_ID FROM document_submissions WHERE doc_ID = ? AND dcsub_u_ID = ? ORDER BY sub_ID DESC LIMIT 1");
                    $stmtFb->execute([$doc_ID, $u_ID]);
                    $fallbackExisting = $stmtFb->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $e0) {
                }
                if ($fallbackExisting && !empty($fallbackExisting['sub_ID'])) {
                    $fid = (int)$fallbackExisting['sub_ID'];
                    try {
                        $conn->prepare("
                            UPDATE document_submissions SET dcsub_answers = ?, attach_name = ?, attach_path = ?, dcsub_status = 1, dcsub_sub_d = NOW(), dcsub_updated_d = NOW() WHERE sub_ID = ?
                        ")->execute([$dcsub_answers, $attach_name, $attach_path, $fid]);
                    } catch (Throwable $e2) {
                        try {
                            $conn->prepare("
                                UPDATE document_submissions SET dcsub_answers = ?, attach_name = ?, attach_path = ?, dcsub_status = 1, dcsub_sub_d = NOW(), dcsu_updated_d = NOW() WHERE sub_ID = ?
                            ")->execute([$dcsub_answers, $attach_name, $attach_path, $fid]);
                        } catch (Throwable $e3) {
                            error_log('submit_document_form document_submissions fallback update: ' . $e3->getMessage());
                        }
                    }
                    $existing = $existing ?? $fallbackExisting;
                } else {
                    try {
                        $conn->prepare("
                            INSERT INTO document_submissions (doc_ID, dcsub_u_ID, dcsub_status, dcsub_answers, attach_name, attach_path, dcsub_created_d, dcsub_sub_d, dcsub_updated_d)
                            VALUES (?, ?, 1, ?, ?, ?, NOW(), NOW(), NOW())
                        ")->execute([$doc_ID, $u_ID, $dcsub_answers, $attach_name, $attach_path]);
                    } catch (Throwable $e2) {
                        try {
                            $conn->prepare("
                                INSERT INTO document_submissions (doc_ID, dcsub_u_ID, dcsub_status, dcsub_answers, attach_name, attach_path, dcsub_created_d, dcsub_sub_d, dcsu_updated_d)
                                VALUES (?, ?, 1, ?, ?, ?, NOW(), NOW(), NOW())
                            ")->execute([$doc_ID, $u_ID, $dcsub_answers, $attach_name, $attach_path]);
                        } catch (Throwable $e3) {
                            error_log('submit_document_form document_submissions: ' . $e3->getMessage());
                        }
                    }
                }
            }
            $subIdForHash = null;
            if (!empty($existing['sub_ID'])) $subIdForHash = (int)$existing['sub_ID'];
            elseif (isset($conn)) $subIdForHash = (int)$conn->lastInsertId();
            if ($subIdForHash) {
                $dcsuVal = null;
                try {
                    $stmtDcsu = $conn->prepare("SELECT dcsub_updated_d FROM document_submissions WHERE sub_ID = ?");
                    $stmtDcsu->execute([$subIdForHash]);
                    $rowDcsu = $stmtDcsu->fetch(PDO::FETCH_ASSOC);
                    $dcsuVal = $rowDcsu['dcsub_updated_d'] ?? null;
                } catch (Throwable $e) {
                    $stmtDcsu = $conn->prepare("SELECT dcsu_updated_d FROM document_submissions WHERE sub_ID = ?");
                    $stmtDcsu->execute([$subIdForHash]);
                    $rowDcsu = $stmtDcsu->fetch(PDO::FETCH_ASSOC);
                    $dcsuVal = $rowDcsu['dcsu_updated_d'] ?? null;
                }
                $pdf_hash = $compute_pdf_version_hash($doc_ID, $u_ID, $dcsuVal);
                try {
                    $conn->prepare("UPDATE document_submissions SET pdf_version_hash = ? WHERE sub_ID = ?")->execute([$pdf_hash, $subIdForHash]);
                } catch (Throwable $e) {
                }
                try {
                    $bfParts = ['verify_result = COALESCE(verify_result, 0)'];
                    $bfParams = [$subIdForHash];
                    if ($docSubHasQr) {
                        array_unshift($bfParts, 'qr_modified_at = COALESCE(qr_modified_at, NOW())');
                    }
                    $conn->prepare("UPDATE document_submissions SET " . implode(', ', $bfParts) . " WHERE sub_ID = ?")->execute($bfParams);
                } catch (Throwable $e) {
                }
            }

            // 強制將最新一筆紀錄狀態改為 submitted
            // 若前面因為欄位相容性等原因沒有成功更新 dcsub_status，
            // 這裡仍會保證至少有一筆該學生在此表單的紀錄被標記為 submitted。
            try {
                $conn->prepare("
                    UPDATE document_submissions
                    SET dcsub_status = 1,
                        dcsub_sub_d = COALESCE(dcsub_sub_d, NOW())
                    WHERE doc_ID = ? AND dcsub_u_ID = ?
                    ORDER BY dcsub_updated_d DESC
                    LIMIT 1
                ")->execute([$doc_ID, $u_ID]);
            } catch (Throwable $e) {
                // 若舊版資料表使用 dcsu_updated_d，改用該欄位排序
                try {
                    $conn->prepare("
                        UPDATE document_submissions
                        SET dcsub_status = 1,
                            dcsub_sub_d = COALESCE(dcsub_sub_d, NOW())
                        WHERE doc_ID = ? AND dcsub_u_ID = ?
                        ORDER BY dcsu_updated_d DESC
                        LIMIT 1
                    ")->execute([$doc_ID, $u_ID]);
                } catch (Throwable $e2) {
                    // 最後仍失敗就記錄 log，但不阻擋整體流程
                    error_log('submit_document_form final status update failed: ' . $e2->getMessage());
                }
            }

            // 正式提交時間：以資料庫 dcsub_sub_d 為準
            $submitted_at = date('Y-m-d H:i:s');
            if ($subIdForHash) {
                try {
                    $stmtSubD = $conn->prepare("SELECT dcsub_sub_d FROM document_submissions WHERE sub_ID = ?");
                    $stmtSubD->execute([$subIdForHash]);
                    $rowSubD = $stmtSubD->fetch(PDO::FETCH_ASSOC);
                    if (!empty($rowSubD['dcsub_sub_d'])) {
                        $submitted_at = $rowSubD['dcsub_sub_d'];
                    }
                } catch (Throwable $e) {
                }
            }
            json_ok(['message' => '表單已成功提交', 'doc_ID' => $doc_ID, 'document_id' => $doc_ID, 'submitted_at' => $submitted_at]);
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('提交表單失敗：' . $e->getMessage());
        }
        break;

    // 暫存文件表單（含補充附件 PDF 寫入 document_submissions.attach_name、attach_path）
    // 如果上傳了 original_pdf 文件，則同時保存原始PDF
    case 'save_document_form_draft':
        // 檢查是否有 original_pdf 文件上傳（用於保存原始PDF）
        // 如果只有 original_pdf 上傳而沒有其他表單數據，則視為保存原始PDF請求
        if (!empty($_FILES['original_pdf']) && $_FILES['original_pdf']['error'] === UPLOAD_ERR_OK) {
            $hasFormData = !empty($p['dcsub_answers']) || !empty($_FILES['supplement_pdf']) || !empty($_FILES['sign_pdf']);
            if (!$hasFormData) {
                // 只有 original_pdf，重定向到 save_original_pdf 處理
                // 通過重新設置 $do 讓 switch 繼續匹配 save_original_pdf case
                $do = 'save_original_pdf';
                // 注意：需要確保 save_original_pdf case 在後面，否則會無法匹配
            }
            // 如果有表單數據，繼續正常流程（original_pdf 會在暫存時一併處理）
        }
        // 若僅上傳 original_pdf，轉到 save_original_pdf 處理並回傳 JSON
        if ($do === 'save_original_pdf') {
            goto run_save_original_pdf;
        }
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $u_ID = $document_form_get_u_id();
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }
            $doc_ID = (int)($p['doc_ID'] ?? $p['document_id'] ?? 0);

            // 檢查是否已提交：本人或同組任一人已繳交則阻擋暫存（一份表單一組只能繳交一次）
            if ($doc_ID > 0) {
                try {
                    $teamUserFieldDraft = 'team_u_ID';
                    $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                    $checkStmt->execute();
                    if (!$checkStmt->fetch()) {
                        $teamUserFieldDraft = 'u_ID';
                    }
                    $stmtTeam = $conn->prepare("SELECT team_ID FROM teammember WHERE {$teamUserFieldDraft} = ? AND (tm_status = 1 OR tm_status IS NULL) ORDER BY team_ID DESC LIMIT 1");
                    $stmtTeam->execute([$u_ID]);
                    $myTeam = $stmtTeam->fetch(PDO::FETCH_ASSOC);
                    if ($myTeam && !empty($myTeam['team_ID'])) {
                        $stmtCheck = $conn->prepare("
                            SELECT 1 FROM document_submissions ds
                            INNER JOIN teammember tm ON tm.{$teamUserFieldDraft} = ds.dcsub_u_ID AND tm.team_ID = ?
                            WHERE ds.doc_ID = ? AND ds.dcsub_status = 1
                            LIMIT 1
                        ");
                        $stmtCheck->execute([$myTeam['team_ID'], $doc_ID]);
                        if ($stmtCheck->fetch()) {
                            json_err('同組已有組員繳交此文件，無法再編輯或暫存。', 'ALREADY_SUBMITTED', 403);
                        }
                    } else {
                        $stmtCheck = $conn->prepare("SELECT dcsub_status FROM document_submissions WHERE doc_ID = ? AND dcsub_u_ID = ? AND dcsub_status = 1 LIMIT 1");
                        $stmtCheck->execute([$doc_ID, $u_ID]);
                        if ($stmtCheck->fetch()) {
                            json_err('此表單已提交，無法再次修改', 'ALREADY_SUBMITTED', 403);
                        }
                    }
                } catch (Throwable $e) {
                    // 忽略錯誤，繼續處理
                }
            }
            $apply_user = trim($p['apply_user'] ?? '');
            $apply_other = trim($p['apply_other'] ?? '');
            $form_answers = isset($p['form_answers']) ? (is_string($p['form_answers']) ? json_decode($p['form_answers'], true) : $p['form_answers']) : [];
            if (!is_array($form_answers)) {
                $form_answers = [];
            }
            if ($doc_ID <= 0) {
                json_err('表單ID無效');
            }
            $stmt = $conn->prepare("SELECT doc_ID, doc_updated_d FROM document_forms WHERE doc_ID = ? AND doc_status = 1");
            $stmt->execute([$doc_ID]);
            $formDraft = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$formDraft) {
                json_err('找不到該表單或表單未啟用');
            }
            $attach_name = null;
            $attach_path = null;
            if (!empty($_FILES['supplement_pdf']) && $_FILES['supplement_pdf']['error'] === UPLOAD_ERR_OK) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['supplement_pdf']['tmp_name']);
                if ($mime !== 'application/pdf' && strpos($mime, 'pdf') === false) {
                    json_err('附件僅允許 PDF 檔案');
                }
                $ext = strtolower(pathinfo($_FILES['supplement_pdf']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'pdf') {
                    json_err('附件僅允許 PDF 檔案');
                }
                $uploadDir = projectevery_full_path('uploads/document_submissions');
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $safeName = 'doc_' . $doc_ID . '_u_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $u_ID) . '.pdf';
                $file_path = 'uploads/document_submissions/' . $safeName;
                $fullPath = projectevery_full_path($file_path);
                if (!move_uploaded_file($_FILES['supplement_pdf']['tmp_name'], $fullPath)) {
                    json_err('附件儲存失敗');
                }
                // 附件 PDF 為「補充說明」用，不強制表單版本檢查，避免一般 PDF 被拒存
                $attach_name = $_FILES['supplement_pdf']['name'] ?: 'supplement.pdf';
                $attach_path = $file_path;
            }
            $clear_attachment = isset($p['clear_attachment']) && (string)$p['clear_attachment'] !== '' && (string)$p['clear_attachment'] !== '0';
            if ($clear_attachment) {
                $attach_name = '';
                $attach_path = '';
            }
            $sign_name = null;
            $sign_path = null;
            $sign_uploaded_d = null;
            $original_pdf_path = null;
            $qr_modified_at = null;
            $verify_result = null;
            $verify_note = null;
            $draft_sign_version_verified = false;
            $sign_version_mismatch_msg = '⚠️【簽名版本不符】此簽名 PDF 並非對應目前「最後修改版本」的文件。請重新下載最新版本、完成簽名後再上傳。';
            // 僅在有新上傳簽名檔時才更新 sign_path；無新檔時禁止清空
            $has_new_sign_file = false;
            if (!empty($_FILES['sign_pdf']) && $_FILES['sign_pdf']['error'] === UPLOAD_ERR_OK && (!isset($_FILES['sign_pdf']['size']) || (int)$_FILES['sign_pdf']['size'] > 0)) {
                error_log('save_document_form_draft: $_FILES[sign_pdf] name=' . ($_FILES['sign_pdf']['name'] ?? '') . ', error=' . ($_FILES['sign_pdf']['error'] ?? '') . ', size=' . ($_FILES['sign_pdf']['size'] ?? ''));
                $tmpPath = $_FILES['sign_pdf']['tmp_name'];
                $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpPath);
                $ext = strtolower(pathinfo($_FILES['sign_pdf']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'pdf' || (strpos($mime, 'pdf') === false && $mime !== 'application/octet-stream')) {
                    json_err('簽名檔僅允許 PDF 格式');
                }

                $stmtDraft = $conn->prepare("SELECT sub_ID, dcsub_updated_d, original_pdf_path FROM document_submissions WHERE doc_ID = ? AND dcsub_u_ID = ? AND (dcsub_status = 4 OR dcsub_status = 1) ORDER BY dcsub_updated_d DESC, sub_ID DESC LIMIT 1");
                $stmtDraft->execute([$doc_ID, $u_ID]);
                $draftRow = $stmtDraft->fetch(PDO::FETCH_ASSOC);
                $current_sub_id_for_sign = $draftRow ? (int)$draftRow['sub_ID'] : null;

                // 預設未核實；上傳成功後會依 snapshot_token 立即核實並改寫 $verify_result / $verify_note（同一 if/else 決定）
                $verify_result = 0;
                $verify_note = '無法核實';
                $qr_modified_at = null;

                if ($draftRow && !empty($draftRow['original_pdf_path'])) {
                    $original_pdf_path = $draftRow['original_pdf_path'];
                }

                $versionErr = $validate_sign_pdf_version($tmpPath, $doc_ID, $u_ID, $conn);
                if ($versionErr !== null) {
                    json_err($versionErr);
                }
                $uploadDir = projectevery_full_path('uploads/document_form_signs');
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $safeName = 'doc_' . $doc_ID . '_u_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $u_ID) . '_' . time() . '.pdf';
                $file_path = 'uploads/document_form_signs/' . $safeName;
                $fullPath = projectevery_full_path($file_path);
                if (!move_uploaded_file($tmpPath, $fullPath)) {
                    json_err('簽名 PDF 儲存失敗');
                }
                error_log('save_document_form_draft: move_uploaded_file 成功, sign_path=' . $file_path);
                $sign_name = $_FILES['sign_pdf']['name'] ?: 'sign.pdf';
                $sign_path = $file_path;
                $sign_uploaded_d = date('Y-m-d H:i:s');
                $draft_sign_version_verified = true;
                $has_new_sign_file = true;

                // 上傳後立即依 snapshot_token 核實，verify_result 與 verify_note 同一 if/else 決定，再一併寫入 DB
                if ($current_sub_id_for_sign && (function_exists('verify_sign_extract_pdf_text') || is_file(dirname(__DIR__) . '/includes/verify_sign_ai_compare.php'))) {
                    if (!function_exists('verify_sign_extract_pdf_text')) {
                        require_once dirname(__DIR__) . '/includes/verify_sign_ai_compare.php';
                    }
                    try {
                        $stmtTok = $conn->prepare("SELECT snapshot_token FROM document_submissions WHERE sub_ID = ? LIMIT 1");
                        $stmtTok->execute([$current_sub_id_for_sign]);
                        $rowTok = $stmtTok->fetch(PDO::FETCH_ASSOC);
                        $dbSnapshotToken = $rowTok && isset($rowTok['snapshot_token']) ? trim((string)$rowTok['snapshot_token']) : '';
                        $uploadedSnapshotToken = null;
                        if ($dbSnapshotToken !== '') {
                            $pdfText = verify_sign_extract_pdf_text($fullPath, 24000);
                            if ($pdfText !== '' && preg_match('/SNAPSHOT_TOKEN\s*[=:]\s*([a-f0-9]{64})/i', $pdfText, $m)) {
                                $uploadedSnapshotToken = strtolower(trim($m[1]));
                            }
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
                    } catch (Throwable $e) {
                        error_log('save_document_form_draft 上傳後立即核實: ' . $e->getMessage());
                    }
                }

                try {
                    $target_sub_id = $current_sub_id_for_sign;
                    if (!$target_sub_id) {
                        $stmtUpdateSign = $conn->prepare("
                            SELECT sub_ID FROM document_submissions
                            WHERE doc_ID = ? AND dcsub_u_ID = ? AND (dcsub_status = 4 OR dcsub_status = 1)
                            ORDER BY dcsub_updated_d DESC, sub_ID DESC LIMIT 1
                        ");
                        $stmtUpdateSign->execute([$doc_ID, $u_ID]);
                        $subIdRow = $stmtUpdateSign->fetch(PDO::FETCH_ASSOC);
                        if ($subIdRow && !empty($subIdRow['sub_ID'])) {
                            $target_sub_id = (int)$subIdRow['sub_ID'];
                        }
                    }
                    if ($target_sub_id) {
                        $upParts = ['sign_name = COALESCE(NULLIF(?, \'\'), sign_name)', 'sign_path = COALESCE(NULLIF(?, \'\'), sign_path)', 'verify_result = ?', 'verify_note = ?', 'dcsub_updated_d = NOW()'];
                        $upPars = [$sign_name, $sign_path, $verify_result, $verify_note, $target_sub_id];
                        if ($docSubHasSignD) {
                            $upParts[] = 'sign_uploaded_d = NOW()';
                        }
                        if ($docSubHasQr) {
                            $upParts[] = 'qr_modified_at = ?';
                            array_splice($upPars, 2, 0, [$qr_modified_at]);
                        }
                        $stmtUpdate = $conn->prepare("UPDATE document_submissions SET " . implode(', ', $upParts) . " WHERE sub_ID = ?");
                        $stmtUpdate->execute($upPars);
                        $rc = $stmtUpdate->rowCount();
                        error_log('save_document_form_draft: 簽名 PDF 立即更新 rowCount=' . $rc . ', sub_ID=' . $target_sub_id);
                        if ($rc === 0) {
                            error_log('save_document_form_draft: 立即更新未影響任何記錄 (sub_ID=' . $target_sub_id . ')');
                        }
                    } else {
                        error_log('save_document_form_draft: 無法取得 sub_ID，稍後主流程會寫入');
                    }
                } catch (Throwable $e) {
                    error_log('save_document_form_draft 簽名立即更新失敗: ' . $e->getMessage());
                }
            }
            $clear_sign = isset($p['clear_sign']) && (string)$p['clear_sign'] !== '' && (string)$p['clear_sign'] !== '0';
            if ($clear_sign) {
                $sign_name = '';
                $sign_path = '';
                $has_new_sign_file = true;
            }
            // 題型「圖檔上傳（學生上傳）」：儲存 question_image_{order} 至 uploads/document_form_question_images，並寫入 form_answers
            $question_image_paths = [];
            $allowed_image_ext = ['jpg', 'jpeg', 'png', 'webp'];
            $allowed_image_mime = ['image/jpeg', 'image/png', 'image/webp'];
            $max_question_image_bytes = 5 * 1024 * 1024; // 5MB
            $uploadDirQ = projectevery_full_path('uploads/document_form_question_images');
            if (!is_dir($uploadDirQ)) {
                mkdir($uploadDirQ, 0755, true);
            }
            foreach ($_FILES as $key => $file) {
                if (!preg_match('/^question_image_(\d+)$/', $key, $m) || empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
                    continue;
                }
                $order = $m[1];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_image_ext, true) || !in_array($mime, $allowed_image_mime, true)) {
                    json_err('題目圖檔僅允許 jpg / png / webp');
                }
                if ($file['size'] > $max_question_image_bytes) {
                    json_err('題目圖檔單檔請在 5MB 以內');
                }
                $safeName = 'doc_' . $doc_ID . '_u_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $u_ID) . '_q_' . $order . '_' . time() . '.' . $ext;
                $file_path_q = 'uploads/document_form_question_images/' . $safeName;
                $fullPathQ = projectevery_full_path($file_path_q);
                if (!move_uploaded_file($file['tmp_name'], $fullPathQ)) {
                    json_err('題目圖檔儲存失敗');
                }
                $form_answers['q_' . $order] = $file_path_q;
                $question_image_paths[$order] = $file_path_q;
            }
            $dcsub_answers = json_encode($form_answers, JSON_UNESCAPED_UNICODE);
            $draftStatus = 4;

            // 同組共用一筆草稿：先查同組任一人是否已有記錄，有則 UPDATE 該筆，無則 INSERT（用當前使用者 dcsub_u_ID）
            $current_sub_id = null;
            $existing = null;
            $draftMemberIds = [$u_ID];
            try {
                $stmtTeamDraft = $conn->prepare("SELECT team_ID FROM teammember WHERE {$teamUserFieldDraft} = ? AND (tm_status = 1 OR tm_status IS NULL) ORDER BY team_ID DESC LIMIT 1");
                $stmtTeamDraft->execute([$u_ID]);
                $teamRowDraft = $stmtTeamDraft->fetch(PDO::FETCH_ASSOC);
                if ($teamRowDraft && !empty($teamRowDraft['team_ID'])) {
                    $stmtMem = $conn->prepare("SELECT {$teamUserFieldDraft} FROM teammember WHERE team_ID = ?");
                    $stmtMem->execute([$teamRowDraft['team_ID']]);
                    $draftMemberIds = array_values(array_filter(array_map('trim', array_column($stmtMem->fetchAll(PDO::FETCH_ASSOC), $teamUserFieldDraft))));
                    if (empty($draftMemberIds)) {
                        $draftMemberIds = [$u_ID];
                    }
                }
                $placeholders = implode(',', array_fill(0, count($draftMemberIds), '?'));
                $stmt = $conn->prepare("
                    SELECT sub_ID, dcsub_answers, attach_name, attach_path, sign_name, sign_path, original_pdf_path
                    FROM document_submissions
                    WHERE doc_ID = ? AND dcsub_u_ID IN ($placeholders)
                    ORDER BY sub_ID DESC LIMIT 1
                ");
                $stmt->execute(array_merge([$doc_ID], $draftMemberIds));
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $current_sub_id = (int)$existing['sub_ID'];
                }
            } catch (Throwable $e) {
                try {
                    $stmt = $conn->prepare("
                        SELECT sub_ID, dcsub_answers, attach_path, sign_name, sign_path, original_pdf_path
                        FROM document_submissions WHERE doc_ID = ? AND dcsub_u_ID = ? ORDER BY sub_ID DESC LIMIT 1
                    ");
                    $stmt->execute([$doc_ID, $u_ID]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($existing) {
                        $existing['attach_name'] = $existing['attach_name'] ?? '';
                        $current_sub_id = (int)$existing['sub_ID'];
                    }
                } catch (Throwable $e2) {
                    $stmt = $conn->prepare("
                        SELECT sub_ID, dcsub_answers, attach_path FROM document_submissions
                        WHERE doc_ID = ? AND dcsub_u_ID = ? ORDER BY sub_ID DESC LIMIT 1
                    ");
                    $stmt->execute([$doc_ID, $u_ID]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($existing) {
                        $existing['attach_name'] = $existing['attach_name'] ?? '';
                        $existing['sign_name'] = $existing['sign_path'] = '';
                        $existing['original_pdf_path'] = null;
                        $current_sub_id = (int)$existing['sub_ID'];
                    }
                }
            }
            $affected_sub_id = null;
            if ($existing) {
                $affected_sub_id = (int) $existing['sub_ID'];
                if ($current_sub_id === null) {
                    $current_sub_id = (int)$existing['sub_ID'];
                }

                if ($attach_name === null) {
                    $attach_name = $existing['attach_name'] ?? '';
                    $attach_path = $existing['attach_path'] ?? '';
                }
                // 沒有新上傳簽名檔時，一律保留既有 sign_path / sign_name，禁止清空
                if (!$has_new_sign_file) {
                    $sign_name = $existing['sign_name'] ?? '';
                    $sign_path = isset($existing['sign_path']) && trim((string)$existing['sign_path']) !== '' ? trim($existing['sign_path']) : '';
                }
                // 如果沒有original_pdf_path，從現有記錄獲取
                if ($original_pdf_path === null && isset($existing['original_pdf_path']) && !empty($existing['original_pdf_path'])) {
                    $original_pdf_path = $existing['original_pdf_path'];
                }
                $existingAnswers = isset($existing['dcsub_answers']) ? trim((string)$existing['dcsub_answers']) : '';
                $isContentSame = ($dcsub_answers === $existingAnswers) && ($attach_name === ($existing['attach_name'] ?? '')) && ($attach_path === ($existing['attach_path'] ?? ''));
                $onlyAddingSign = $isContentSame && $sign_name !== null && $sign_path !== null;
                $updateDcsu = !$onlyAddingSign;
                if ($original_pdf_path === null && isset($existing['original_pdf_path']) && !empty($existing['original_pdf_path'])) {
                    $original_pdf_path = $existing['original_pdf_path'];
                }
                // [二] 沒新上傳簽名檔時禁止清空 sign_path：傳 NULL 保留原值；clear_sign 時傳空字串清空
                if (!empty($clear_sign)) {
                    $sign_path_val = '';
                    $sign_uploaded_d_val = null;
                    $sign_name_val = '';
                } else {
                    $sign_path_val = $has_new_sign_file && trim((string)($sign_path ?? '')) !== '' ? $sign_path : null;
                    $sign_uploaded_d_val = $has_new_sign_file ? $sign_uploaded_d : null;
                    $sign_name_val = $has_new_sign_file ? ($sign_name ?? '') : null;
                }
                try {
                    $signUploadPart = $docSubHasSignD ? 'sign_uploaded_d = COALESCE(?, sign_uploaded_d), ' : '';
                    $signUploadPar = $docSubHasSignD ? [$sign_uploaded_d_val] : [];
                    if ($updateDcsu) {
                        // 內容變更：更新 dcsub_updated_d；暫存時確保狀態為 4（已提交 1 則不覆蓋）
                        $execParams = array_merge([$dcsub_answers, $attach_name, $attach_path, $sign_name_val, $sign_path_val], $signUploadPar, [$original_pdf_path, $verify_result, $verify_note, $existing['sub_ID']]);
                        $stmt = $conn->prepare("
                            UPDATE document_submissions SET
                                dcsub_answers = ?, attach_name = ?, attach_path = ?,
                                sign_name = COALESCE(?, sign_name),
                                sign_path = COALESCE(?, sign_path),
                                {$signUploadPart}original_pdf_path = ?, verify_result = COALESCE(?, verify_result), verify_note = COALESCE(?, verify_note),
                                dcsub_status = IF(dcsub_status = 1, 1, 4),
                                dcsub_updated_d = NOW()
                            WHERE sub_ID = ?
                        ");
                        $stmt->execute($execParams);
                    } else {
                        // 僅簽名上傳：只更新 sign 相關欄位，verify_result 與 verify_note 一併寫回
                        $execParams = array_merge([$sign_name_val, $sign_path_val], $signUploadPar, [$verify_result, $verify_note, $existing['sub_ID']]);
                        $stmt = $conn->prepare("
                            UPDATE document_submissions SET
                                sign_name = COALESCE(?, sign_name),
                                sign_path = COALESCE(?, sign_path),
                                {$signUploadPart}verify_result = COALESCE(?, verify_result), verify_note = COALESCE(?, verify_note)
                            WHERE sub_ID = ?
                        ");
                        $stmt->execute($execParams);
                    }
                    error_log('save_document_form_draft: 主流程 UPDATE rowCount=' . $stmt->rowCount() . ', sub_ID=' . $existing['sub_ID']);
                } catch (Throwable $e) {
                    if (!empty($onlyAddingSign)) {
                        $execParams = array_merge([$sign_name_val, $sign_path_val], $signUploadPar, [$verify_result, $verify_note, $existing['sub_ID']]);
                        $stmt = $conn->prepare("
                            UPDATE document_submissions SET
                                sign_name = COALESCE(?, sign_name),
                                sign_path = COALESCE(?, sign_path),
                                {$signUploadPart}verify_result = COALESCE(?, verify_result), verify_note = COALESCE(?, verify_note)
                            WHERE sub_ID = ?
                        ");
                        $stmt->execute($execParams);
                        error_log('save_document_form_draft: fallback updateSignOnly rowCount=' . $stmt->rowCount());
                    } else {
                        try {
                            $execParams = array_merge([$dcsub_answers, $attach_name, $attach_path, $sign_name_val, $sign_path_val], $signUploadPar, [$existing['sub_ID']]);
                            $stmt = $conn->prepare("
                                UPDATE document_submissions SET
                                    dcsub_answers = ?, attach_name = ?, attach_path = ?,
                                    sign_name = COALESCE(?, sign_name),
                                    sign_path = COALESCE(?, sign_path),
                                    {$signUploadPart}dcsub_status = IF(dcsub_status = 1, 1, 4),
                                    dcsub_updated_d = NOW()
                                WHERE sub_ID = ?
                            ");
                            $stmt->execute($execParams);
                            error_log('save_document_form_draft: fallback UPDATE rowCount=' . $stmt->rowCount());
                        } catch (Throwable $e2) {
                            try {
                                $stmt = $conn->prepare("
                                    UPDATE document_submissions SET
                                        dcsub_answers = ?, attach_name = ?, attach_path = ?,
                                        dcsub_status = IF(dcsub_status = 1, 1, 4),
                                        dcsub_updated_d = NOW()
                                    WHERE sub_ID = ?
                                ");
                                $stmt->execute([$dcsub_answers, $attach_name, $attach_path, $existing['sub_ID']]);
                                error_log('save_document_form_draft: 最簡 UPDATE rowCount=' . $stmt->rowCount());
                            } catch (Throwable $e3) {
                                $stmt = $conn->prepare("
                                    UPDATE document_submissions SET dcsub_answers = ?, attach_path = ?, dcsub_updated_d = NOW() WHERE sub_ID = ?
                                ");
                                $stmt->execute([$dcsub_answers, $attach_path ?? '', $existing['sub_ID']]);
                                error_log('save_document_form_draft: 僅 attach_path UPDATE rowCount=' . $stmt->rowCount());
                            }
                        }
                    }
                }
            } else {
                $ins_name = ($attach_name !== null) ? $attach_name : '';
                $ins_path = ($attach_path !== null) ? $attach_path : '';
                $ins_sign_name = ($sign_name !== null) ? $sign_name : '';
                $ins_sign_path = ($sign_path !== null) ? $sign_path : '';
                try {
                    $insCols = ['doc_ID', 'dcsub_u_ID', 'dcsub_status', 'dcsub_answers', 'attach_name', 'attach_path', 'sign_name', 'sign_path', 'original_pdf_path', 'verify_result', 'dcsub_created_d', 'dcsub_updated_d'];
                    $insVals = [$doc_ID, $u_ID, $draftStatus, $dcsub_answers, $ins_name, $ins_path, $ins_sign_name, $ins_sign_path, $original_pdf_path, $verify_result];
                    if ($docSubHasSignD) {
                        $insCols = array_merge(array_slice($insCols, 0, 8), ['sign_uploaded_d'], array_slice($insCols, 8));
                        array_splice($insVals, 8, 0, [$sign_uploaded_d]);
                    }
                    if ($docSubHasQr) {
                        $idx = array_search('verify_result', $insCols);
                        $insCols = array_merge(array_slice($insCols, 0, $idx), ['qr_modified_at'], array_slice($insCols, $idx));
                        array_splice($insVals, $idx, 0, [$qr_modified_at]);
                    }
                    $qCount = count($insVals);
                    $placeholders = implode(', ', array_fill(0, $qCount, '?')) . ', NOW(), NOW()';
                    $stmt = $conn->prepare("INSERT INTO document_submissions (" . implode(', ', $insCols) . ") VALUES (" . $placeholders . ")");
                    $stmt->execute($insVals);
                } catch (Throwable $e) {
                    try {
                        $stmt = $conn->prepare("
                            INSERT INTO document_submissions (doc_ID, dcsub_u_ID, dcsub_status, dcsub_answers, attach_name, attach_path, sign_name, sign_path, dcsub_created_d, dcsub_updated_d)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ");
                        $stmt->execute([$doc_ID, $u_ID, $draftStatus, $dcsub_answers, $ins_name, $ins_path, $ins_sign_name, $ins_sign_path]);
                    } catch (Throwable $e2) {
                        try {
                            $stmt = $conn->prepare("
                                INSERT INTO document_submissions (doc_ID, dcsub_u_ID, dcsub_status, dcsub_answers, attach_name, attach_path, dcsub_created_d, dcsub_updated_d)
                                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                            ");
                            $stmt->execute([$doc_ID, $u_ID, $draftStatus, $dcsub_answers, $ins_name, $ins_path]);
                        } catch (Throwable $e3) {
                            // 若資料表尚無 attach_name：僅寫入 attach_path，確保附件 PDF 能存住
                            $stmt = $conn->prepare("
                                INSERT INTO document_submissions (doc_ID, dcsub_u_ID, dcsub_status, dcsub_answers, attach_path, dcsub_created_d, dcsub_updated_d)
                                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                            ");
                            $stmt->execute([$doc_ID, $u_ID, $draftStatus, $dcsub_answers, $ins_path]);
                        }
                    }
                }
                $affected_sub_id = $conn->lastInsertId() ? (int) $conn->lastInsertId() : null;
            }
            $dcsu_updated_d = null;
            if ($affected_sub_id) {
                if (!empty($onlyAddingSign) && isset($existing)) {
                    $stmtGet = $conn->prepare("SELECT dcsub_updated_d FROM document_submissions WHERE sub_ID = ?");
                    $stmtGet->execute([$affected_sub_id]);
                    $rowGet = $stmtGet->fetch(PDO::FETCH_ASSOC);
                    $dcsu_updated_d = $rowGet['dcsub_updated_d'] ?? null;
                } else {
                    $conn->prepare("UPDATE document_submissions SET dcsub_updated_d = NOW() WHERE sub_ID = ?")->execute([$affected_sub_id]);
                    $stmtGet = $conn->prepare("SELECT dcsub_updated_d FROM document_submissions WHERE sub_ID = ?");
                    $stmtGet->execute([$affected_sub_id]);
                    $rowGet = $stmtGet->fetch(PDO::FETCH_ASSOC);
                    $dcsu_updated_d = $rowGet['dcsub_updated_d'] ?? null;
                    $pdf_hash = $compute_pdf_version_hash($doc_ID, $u_ID, $dcsu_updated_d);
                    try {
                        $conn->prepare("UPDATE document_submissions SET pdf_version_hash = ? WHERE sub_ID = ?")->execute([$pdf_hash, $affected_sub_id]);
                    } catch (Throwable $e) {
                    }
                }
            }
            // 暫存且有任何欄位或附件變更時：重新產生 snapshot_token（sub_ID + 表單內容 JSON + 附件清單 + dcsub_updated_d），使之後上傳舊簽名 PDF 會核實為不一致
            if ($affected_sub_id && (!$existing || !empty($updateDcsu))) {
                $ts = $dcsu_updated_d ?? date('Y-m-d H:i:s');
                $attach_list = trim((string)($attach_path ?? '')) . '|' . trim((string)($attach_name ?? ''));
                $snapshot_token_new = hash('sha256', $affected_sub_id . '|' . $dcsub_answers . '|' . $attach_list . '|' . $ts);
                try {
                    $conn->prepare("UPDATE document_submissions SET snapshot_token = ? WHERE sub_ID = ?")->execute([$snapshot_token_new, $affected_sub_id]);
                } catch (Throwable $e) {
                    error_log('save_document_form_draft snapshot_token: ' . $e->getMessage());
                }
            }
            if (trim((string)($sign_path ?? '')) !== '' && $affected_sub_id) {
                try {
                    $signPath = trim($sign_path); // 相對路徑 uploads/document_form_signs/xxx.pdf
                    $setClause = "sign_path = COALESCE(?, sign_path)";
                    if ($docSubHasSignD) $setClause .= ", sign_uploaded_d = NOW()";
                    $stmtSign = $conn->prepare("UPDATE document_submissions SET $setClause WHERE sub_ID = ?");
                    $stmtSign->execute([$signPath, (int)$affected_sub_id]);
                    error_log('save_document_form_draft: 明確寫入 sign_path rowCount=' . $stmtSign->rowCount() . ', sub_ID=' . $affected_sub_id);
                } catch (Throwable $e) {
                    error_log('save_document_form_draft 寫入 sign_path 失敗: ' . $e->getMessage());
                }
            }
            // 暫存且本次有上傳簽名檔時：立即核實（與 verify_sign.php 相同邏輯），不需等刷新頁面
            $draft_verified_this_request = false;
            if ($affected_sub_id && !empty($has_new_sign_file) && trim((string)($sign_path ?? '')) !== '') {
                $signFullPath = projectevery_full_path(trim($sign_path));
                if (function_exists('verify_sign_extract_pdf_text') || is_file(dirname(__DIR__) . '/includes/verify_sign_ai_compare.php')) {
                    if (!function_exists('verify_sign_extract_pdf_text')) {
                        require_once dirname(__DIR__) . '/includes/verify_sign_ai_compare.php';
                    }
                    $draft_verify_result = 0;
                    $draft_verify_note = '無法核實';
                    try {
                        $stmtDbTok = $conn->prepare("SELECT snapshot_token FROM document_submissions WHERE sub_ID = ? LIMIT 1");
                        $stmtDbTok->execute([$affected_sub_id]);
                        $rowTok = $stmtDbTok->fetch(PDO::FETCH_ASSOC);
                        $dbSnapshotToken = $rowTok && isset($rowTok['snapshot_token']) ? trim((string)$rowTok['snapshot_token']) : '';
                        $uploadedSnapshotToken = null;
                        if ($dbSnapshotToken !== '') {
                            $pdfText = verify_sign_extract_pdf_text($signFullPath, 24000);
                            if ($pdfText !== '' && preg_match('/SNAPSHOT_TOKEN\s*[=:]\s*([a-f0-9]{64})/i', $pdfText, $m)) {
                                $uploadedSnapshotToken = strtolower(trim($m[1]));
                            }
                        }
                        if ($dbSnapshotToken === '' || $uploadedSnapshotToken === null || $uploadedSnapshotToken === '') {
                            $draft_verify_result = 0;
                            $draft_verify_note = '無法核實';
                        } elseif (hash_equals($dbSnapshotToken, $uploadedSnapshotToken)) {
                            $draft_verify_result = 1;
                            $draft_verify_note = '核實結果一致';
                        } else {
                            $draft_verify_result = 2;
                            $draft_verify_note = '核實結果不一致';
                        }
                        $conn->prepare("UPDATE document_submissions SET verify_result = ?, verify_note = ? WHERE sub_ID = ?")->execute([$draft_verify_result, $draft_verify_note, $affected_sub_id]);
                        $draft_verified_this_request = true;
                    } catch (Throwable $e) {
                        error_log('save_document_form_draft 立即核實簽名檔: ' . $e->getMessage());
                    }
                }
            }
            // 暫存後補齊 qr_modified_at；若 original_pdf_path 仍為空則寫入 'GENERATED'，避免一直為 NULL
            if ($affected_sub_id) {
                try {
                    if ($docSubHasQr) {
                        $conn->prepare("UPDATE document_submissions SET qr_modified_at = NOW() WHERE sub_ID = ? AND qr_modified_at IS NULL")->execute([$affected_sub_id]);
                    }
                } catch (Throwable $e) {
                    error_log('save_document_form_draft backfill qr_modified_at sub_ID=' . $affected_sub_id . ': ' . $e->getMessage());
                }
                try {
                    $conn->prepare("UPDATE document_submissions SET original_pdf_path = 'GENERATED' WHERE sub_ID = ? AND (original_pdf_path IS NULL OR original_pdf_path = '')")->execute([$affected_sub_id]);
                } catch (Throwable $e) {
                    error_log('save_document_form_draft backfill original_pdf_path sub_ID=' . $affected_sub_id . ': ' . $e->getMessage());
                }
            }
            $okPayload = ['message' => '已暫存', 'doc_ID' => $doc_ID, 'dcsu_updated_d' => $dcsu_updated_d];
            if ($affected_sub_id) {
                $okPayload['sub_ID'] = $affected_sub_id;
            }
            // 以 DB 的 sign_path 為唯一真實來源，回傳給前端同步 draftSignName / draftSignUrl
            if ($affected_sub_id) {
                try {
                    $stmtFinal = $conn->prepare("SELECT sign_name, sign_path FROM document_submissions WHERE sub_ID = ?");
                    $stmtFinal->execute([$affected_sub_id]);
                    $rowFinal = $stmtFinal->fetch(PDO::FETCH_ASSOC);
                    if ($rowFinal && !empty(trim((string)($rowFinal['sign_path'] ?? '')))) {
                        $okPayload['sign_path'] = trim($rowFinal['sign_path']);
                        $sn = trim((string)($rowFinal['sign_name'] ?? ''));
                        $okPayload['sign_name'] = $sn !== '' ? $sn : basename($rowFinal['sign_path']);
                    }
                } catch (Throwable $e) {
                }
            }
            if (!empty($question_image_paths)) {
                $okPayload['question_image_paths'] = $question_image_paths;
            }
            if (!empty($draft_sign_version_verified)) {
                $okPayload['version_verified'] = true;
                $okPayload['version_message'] = '簽名 PDF 已上傳，可送出申請。';
            }
            if (!empty($draft_verified_this_request)) {
                $okPayload['verified_this_request'] = true;
            }
            // 回傳 compare_data 與 version_message，讓前端暫存後不必 F5 即可顯示快照、上傳簽名檔
            if ($affected_sub_id) {
                try {
                    $selCols = ['original_pdf_path', 'sign_path', 'snapshot_token', 'verify_result', 'verify_note', 'dcsub_updated_d'];
                    if ($docSubHasSignD) $selCols[] = 'sign_uploaded_d';
                    if ($docSubHasQr) $selCols[] = 'qr_modified_at';
                    $stmtC = $conn->prepare("SELECT " . implode(', ', $selCols) . " FROM document_submissions WHERE sub_ID = ?");
                    $stmtC->execute([$affected_sub_id]);
                    $rowC = $stmtC->fetch(PDO::FETCH_ASSOC);
                    if ($rowC) {
                        $qrAt = isset($rowC['qr_modified_at']) && trim((string)$rowC['qr_modified_at']) !== '' ? trim($rowC['qr_modified_at']) : null;
                        $snapTok = isset($rowC['snapshot_token']) && trim((string)$rowC['snapshot_token']) !== '' ? trim($rowC['snapshot_token']) : null;
                        $okPayload['compare_data'] = [
                            'original_pdf_path' => $rowC['original_pdf_path'] ?? null,
                            'sign_path' => $rowC['sign_path'] ?? null,
                            'snapshot_token' => $snapTok,
                            'qr_modified_at' => $qrAt,
                            'system_last_modified' => $rowC['dcsub_updated_d'] ?? null,
                            'verify_result' => isset($rowC['verify_result']) && $rowC['verify_result'] !== null ? (int)$rowC['verify_result'] : null,
                            'verify_note' => isset($rowC['verify_note']) ? trim((string)$rowC['verify_note']) : null,
                            'submission_id' => $affected_sub_id
                        ];
                        $hasSnapshot = (trim((string)($rowC['original_pdf_path'] ?? '')) !== '' && $snapTok !== null);
                        if ($hasSnapshot && !isset($okPayload['version_message'])) {
                            $okPayload['version_message'] = trim((string)($rowC['sign_path'] ?? '')) !== '' ? '簽名PDF檔已保存，可送出申請。' : '已建立簽名前版本，可上傳簽名檔。';
                            $okPayload['version_verified'] = true;
                        }
                    }
                } catch (Throwable $e) {
                    error_log('save_document_form_draft compare_data sub_ID=' . $affected_sub_id . ': ' . $e->getMessage());
                }
            }
            json_ok($okPayload);
        } catch (PDOException $e) {
            // 向後相容：部分舊版資料庫沒有 document_submissions 的新欄位
            //（例如 attach_name、sign_path、dcsu_updated_d 等），
            // 會丟出 Unknown column ... 錯誤；此時改用「極簡模式」只寫入 dcsub_answers，
            // 不再依賴任何新欄位，至少讓「暫存」功能可以正常使用。
            $msg = $e->getMessage();
            if (strpos($msg, 'Unknown column') !== false) {
                try {
                    if (session_status() === PHP_SESSION_NONE) {
                        session_start();
                    }
                    $u_ID = $document_form_get_u_id();
                    if (!$u_ID) {
                        json_err('請先登入', 'NOT_LOGGED_IN', 401);
                    }
                    $doc_ID = (int)($p['doc_ID'] ?? $p['document_id'] ?? 0);
                    if ($doc_ID <= 0) {
                        json_err('表單ID無效');
                    }
                    // 最小化暫存：僅寫入 dcsub_answers，不使用 attach_name/attach_path 等欄位
                    $apply_user = trim($p['apply_user'] ?? '');
                    $apply_other = trim($p['apply_other'] ?? '');
                    $form_answers = isset($p['form_answers'])
                        ? (is_string($p['form_answers']) ? json_decode($p['form_answers'], true) : $p['form_answers'])
                        : [];
                    if (!is_array($form_answers)) {
                        $form_answers = [];
                    }
                    // 仍將申請人資訊一併放入答案中，避免資料遺失
                    $form_answers['_apply_user'] = $apply_user;
                    $form_answers['_apply_other'] = $apply_other;
                    $dcsub_answers = json_encode($form_answers, JSON_UNESCAPED_UNICODE);

                    // 嘗試更新既有暫存記錄（僅依 sub_ID 及最基本欄位，不使用 dcsu_updated_d 排序）
                    $stmt = $conn->prepare("
                        SELECT sub_ID FROM document_submissions
                        WHERE doc_ID = ? AND dcsub_u_ID = ?
                        ORDER BY sub_ID DESC LIMIT 1
                    ");
                    $stmt->execute([$doc_ID, $u_ID]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($row && !empty($row['sub_ID'])) {
                        $sub_ID = (int)$row['sub_ID'];
                        $stmtUp = $conn->prepare("
                            UPDATE document_submissions
                            SET dcsub_answers = ?, dcsub_status = IF(dcsub_status = 1, 1, 4)
                            WHERE sub_ID = ?
                        ");
                        $stmtUp->execute([$dcsub_answers, $sub_ID]);
                    } else {
                        $stmtIns = $conn->prepare("
                            INSERT INTO document_submissions (doc_ID, dcsub_u_ID, dcsub_status, dcsub_answers)
                            VALUES (?, ?, 4, ?)
                        ");
                        $stmtIns->execute([$doc_ID, $u_ID, $dcsub_answers]);
                    }

                    $now = date('Y-m-d H:i:s');
                    json_ok([
                        'message' => '已暫存（簡化模式）',
                        'doc_ID' => $doc_ID,
                        'dcsu_updated_d' => $now,
                    ]);
                } catch (Throwable $e2) {
                    json_err('資料庫錯誤：' . $e2->getMessage(), 'DB_ERROR', 500);
                }
            } else {
                json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
            }
        } catch (Throwable $e) {
            json_err('暫存失敗：' . $e->getMessage());
        }
        break;

    // 取得暫存（用於刷新後還原，含 attach_name、attach_path）
    // 若 action=compare，轉到 get_document_form_compare_data 並回傳 JSON
    case 'get_document_form_draft':
        if (isset($_GET['action']) && $_GET['action'] === 'compare') {
            goto run_get_document_form_compare_data;
        }
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $u_ID = $document_form_get_u_id();
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }
            $doc_ID = (int)($_GET['doc_ID'] ?? $_GET['document_id'] ?? 0);
            if ($doc_ID <= 0) {
                json_ok(['draft' => null]);
                break;
            }
            // 兩段式查詢：先只取「最新一筆 sub_ID」（同組共用草稿：任一同組成員的記錄皆可），再 SELECT * 取該筆完整資料
            $row = null;
            $sub_id = null;
            try {
                $teamUserFieldDraftGet = 'team_u_ID';
                $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $checkStmt->execute();
                if (!$checkStmt->fetch()) {
                    $teamUserFieldDraftGet = 'u_ID';
                }
                $stmtTeam = $conn->prepare("SELECT team_ID FROM teammember WHERE {$teamUserFieldDraftGet} = ? AND (tm_status = 1 OR tm_status IS NULL) ORDER BY team_ID DESC LIMIT 1");
                $stmtTeam->execute([$u_ID]);
                $teamRowDraft = $stmtTeam->fetch(PDO::FETCH_ASSOC);
                if ($teamRowDraft && !empty($teamRowDraft['team_ID'])) {
                    $stmtMembers = $conn->prepare("SELECT {$teamUserFieldDraftGet} FROM teammember WHERE team_ID = ?");
                    $stmtMembers->execute([$teamRowDraft['team_ID']]);
                    $memberIds = array_column($stmtMembers->fetchAll(PDO::FETCH_ASSOC), $teamUserFieldDraftGet);
                    $memberIds = array_values(array_filter(array_map('trim', $memberIds)));
                    if (!empty($memberIds)) {
                        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
                        $stmtId = $conn->prepare("
                            SELECT sub_ID FROM document_submissions
                            WHERE doc_ID = ? AND dcsub_u_ID IN ($placeholders)
                              AND (dcsub_status = 4 OR dcsub_status = 1 OR dcsub_status = 0 OR dcsub_status IS NULL)
                            ORDER BY sub_ID DESC
                            LIMIT 1
                        ");
                        $stmtId->execute(array_merge([$doc_ID], $memberIds));
                        $idRow = $stmtId->fetch(PDO::FETCH_ASSOC);
                        if ($idRow && !empty($idRow['sub_ID'])) {
                            $sub_id = (int)$idRow['sub_ID'];
                        }
                    }
                }
                if (!$sub_id) {
                    $stmtId = $conn->prepare("
                        SELECT sub_ID FROM document_submissions
                        WHERE doc_ID = ? AND dcsub_u_ID = ?
                          AND (dcsub_status = 4 OR dcsub_status = 1 OR dcsub_status = 0 OR dcsub_status IS NULL)
                        ORDER BY sub_ID DESC
                        LIMIT 1
                    ");
                    $stmtId->execute([$doc_ID, $u_ID]);
                    $idRow = $stmtId->fetch(PDO::FETCH_ASSOC);
                    if ($idRow && !empty($idRow['sub_ID'])) {
                        $sub_id = (int)$idRow['sub_ID'];
                    }
                }
            } catch (Throwable $e) {
                // 忽略
            }
            if ($sub_id) {
                try {
                    $stmt = $conn->prepare("SELECT * FROM document_submissions WHERE sub_ID = ? LIMIT 1");
                    $stmt->execute([$sub_id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    $row = null;
                }
            }
            if (!$row || empty($row['sub_ID'])) {
                json_ok(['draft' => null]);
                break;
            }
            // 若 qr_modified_at 為空則補寫；original_pdf_path 不再寫 'GENERATED'，僅由實際上傳/產生 PDF 時寫入真實路徑
            $needBackfill = $docSubHasQr && ((($row['qr_modified_at'] ?? null) === null || (string)($row['qr_modified_at'] ?? '') === ''));
            if ($needBackfill && $sub_id > 0) {
                try {
                    if ($docSubHasQr) {
                        $conn->prepare("UPDATE document_submissions SET qr_modified_at = NOW() WHERE sub_ID = ? AND qr_modified_at IS NULL")->execute([$sub_id]);
                    }
                } catch (Throwable $e) {
                    error_log('get_draft backfill qr_modified_at sub_ID=' . $sub_id . ': ' . $e->getMessage());
                }
                $stmt->execute([$sub_id]);
                $refetched = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($refetched) $row = $refetched;
            }
            // 從完整列取出 sign_path（SELECT * 已含所有欄位，無需再補查）
            if (!isset($row['sign_path']) || (string)$row['sign_path'] === '') {
                $row['sign_path'] = null;
            }
            if (!isset($row['sign_name'])) {
                $row['sign_name'] = null;
            }
            $answers = [];
            if (!empty($row['dcsub_answers'])) {
                $decoded = json_decode($row['dcsub_answers'], true);
                if (is_array($decoded)) {
                    $answers = $decoded;
                }
            }
            // 最後修改時間：以暫存記錄為準 → 更新時間(dcsub_updated_d/dcsu_updated_d) 優先，無則用建立時間(dcsub_created_d)
            $lastUpdated = trim((string)($row['dcsub_updated_d'] ?? $row['dcsu_updated_d'] ?? $row['dcsub_created_d'] ?? ''));
            if ($lastUpdated === '' && !empty($row['sub_ID'])) {
                try {
                    $stmtTime = $conn->prepare("SELECT dcsub_updated_d, dcsu_updated_d, dcsub_created_d FROM document_submissions WHERE sub_ID = ? LIMIT 1");
                    $stmtTime->execute([(int)$row['sub_ID']]);
                    $timeRow = $stmtTime->fetch(PDO::FETCH_ASSOC);
                    if ($timeRow) {
                        $lastUpdated = trim((string)($timeRow['dcsub_updated_d'] ?? $timeRow['dcsu_updated_d'] ?? $timeRow['dcsub_created_d'] ?? ''));
                    }
                } catch (Throwable $e) {
                    // 表可能無部分欄位，忽略
                }
            }
            $draftData = [
                'sub_ID' => (int)($row['sub_ID'] ?? 0),
                'form_answers' => $answers,
                'attach_name' => $row['attach_name'] ?? '',
                'attach_path' => $row['attach_path'] ?? '',
                'dcsu_updated_d' => $lastUpdated
            ];
            // 簽名檔：有值才寫入，確保 F5 後前端能顯示「目前已暫存：xxx」
            $signPathVal = isset($row['sign_path']) ? trim((string)$row['sign_path']) : '';
            $signNameVal = isset($row['sign_name']) ? trim((string)$row['sign_name']) : '';
            if ($signPathVal !== '') {
                $draftData['sign_path'] = $signPathVal;
                $draftData['sign_name'] = $signNameVal !== '' ? $signNameVal : basename($signPathVal);
            } else {
                $draftData['sign_path'] = '';
                $draftData['sign_name'] = $signNameVal;
            }
            if (isset($row['sign_uploaded_d'])) $draftData['sign_uploaded_d'] = $row['sign_uploaded_d'] ?? null;
            if (isset($row['original_pdf_path'])) $draftData['original_pdf_path'] = $row['original_pdf_path'] ?? null;
            if (isset($row['qr_modified_at'])) $draftData['qr_modified_at'] = $row['qr_modified_at'] ?? null;
            if (isset($row['verify_result'])) $draftData['verify_result'] = $row['verify_result'] !== null ? (int)$row['verify_result'] : null;

            $draftData['version_verified'] = false;
            $draftData['version_message'] = '';
            if (!empty($row['sign_path'])) {
                $fullSignPath = projectevery_full_path($row['sign_path']);
                if (file_exists($fullSignPath)) {
                    $signExt = strtolower(pathinfo($row['sign_path'], PATHINFO_EXTENSION));
                    if ($signExt === 'pdf' && defined('DOCUMENT_FORM_ALLOW_SIGN_PDF') && DOCUMENT_FORM_ALLOW_SIGN_PDF) {
                        try {
                            $versionErr = $validate_sign_pdf_version($fullSignPath, $doc_ID, $u_ID, $conn);
                            $draftData['version_verified'] = ($versionErr === null);
                            $draftData['version_message'] = $versionErr ?? '簽名PDF檔已保存，可送出申請。';
                        } catch (Throwable $e) {
                            $draftData['version_message'] = '簽名 PDF 版本驗證失敗：' . $e->getMessage();
                        }
                    } else {
                        // PDF格式，已在上面的if中处理
                        $draftData['version_message'] = '簽名 PDF 已上傳，可送出申請。';
                    }
                } else {
                    $draftData['version_message'] = '簽名檔遺失，請重新上傳。';
                }
            }
            json_ok(['draft' => $draftData]);
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('取得暫存失敗：' . $e->getMessage());
        }
        break;

    case 'get_document_form_compare_data':
        run_get_document_form_compare_data:
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $u_ID = $document_form_get_u_id();
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }
            $doc_ID = (int)($_GET['doc_ID'] ?? $_GET['document_id'] ?? 0);
            $sub_ID_param = (int)($_GET['submission_id'] ?? $_GET['sub_ID'] ?? 0);

            // 科辦端：若有 submission_id，直接依 sub_ID 查詢該筆申請的核實資料（與學生端同一套比對）
            if ($sub_ID_param > 0) {
                try {
                    $cmpCols = ['original_pdf_path', 'sign_path', 'verify_result', 'verify_note', 'dcsub_updated_d'];
                    if ($docSubHasSignD) $cmpCols[] = 'sign_uploaded_d';
                    if ($docSubHasQr) $cmpCols[] = 'qr_modified_at';
                    $stmt = $conn->prepare("SELECT " . implode(',', $cmpCols) . " FROM document_submissions WHERE sub_ID = ?");
                    $stmt->execute([$sub_ID_param]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$row) {
                        $stmt = $conn->prepare("
                            SELECT sign_path, dcsub_updated_d, verify_result, verify_note
                            FROM document_submissions
                            WHERE sub_ID = ?
                        ");
                        $stmt->execute([$sub_ID_param]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    $emptyCompare = [
                        'original_pdf_path' => null,
                        'sign_path' => null,
                        'qr_modified_at' => null,
                        'system_last_modified' => null,
                        'verify_result' => null,
                        'verify_note' => null,
                        'submission_id' => null
                    ];
                    if (!$row) {
                        json_ok(['compare_data' => $emptyCompare]);
                        break;
                    }
                    $qrModifiedAt = isset($row['qr_modified_at']) && trim((string)$row['qr_modified_at']) !== '' ? trim($row['qr_modified_at']) : null;
                    json_ok(['compare_data' => [
                        'original_pdf_path' => $row['original_pdf_path'] ?? null,
                        'sign_path' => $row['sign_path'] ?? null,
                        'qr_modified_at' => $qrModifiedAt,
                        'system_last_modified' => $row['dcsub_updated_d'] ?? $row['dcsu_updated_d'] ?? null,
                        'verify_result' => isset($row['verify_result']) && $row['verify_result'] !== null ? (int)$row['verify_result'] : null,
                        'verify_note' => isset($row['verify_note']) ? trim((string)$row['verify_note']) : null,
                        'submission_id' => $sub_ID_param
                    ]]);
                } catch (Throwable $e) {
                    error_log('取得對比資料錯誤(submission_id): ' . $e->getMessage());
                    json_ok(['compare_data' => [
                        'original_pdf_path' => null,
                        'sign_path' => null,
                        'qr_modified_at' => null,
                        'system_last_modified' => null,
                        'verify_result' => null,
                        'verify_note' => null,
                        'submission_id' => null
                    ]]);
                }
                break;
            }

            if ($doc_ID <= 0) {
                json_err('表單ID無效');
            }
            try {
                // 學生端：先獲取 sub_ID，然後查詢同一筆記錄的所有欄位
                $stmtGetSubId = $conn->prepare("
                    SELECT sub_ID FROM document_submissions
                    WHERE doc_ID = ? AND dcsub_u_ID = ? AND (dcsub_status = 4 OR dcsub_status = 1)
                    ORDER BY dcsub_updated_d DESC, sub_ID DESC LIMIT 1
                ");
                $stmtGetSubId->execute([$doc_ID, $u_ID]);
                $subIdRow = $stmtGetSubId->fetch(PDO::FETCH_ASSOC);

                if (!$subIdRow || empty($subIdRow['sub_ID'])) {
                    json_ok(['compare_data' => [
                        'original_pdf_path' => null,
                        'sign_path' => null,
                        'qr_modified_at' => null,
                        'system_last_modified' => null,
                        'verify_result' => null,
                        'verify_note' => null,
                        'submission_id' => null
                    ]]);
                    break;
                }

                $student_sub_id = (int)$subIdRow['sub_ID'];
                // 查詢同一筆sub_ID的所有欄位（verify_note 可能不存在）
                $row = null;
                $stdCols = ['original_pdf_path', 'sign_path', 'verify_result', 'dcsub_updated_d'];
                if ($docSubHasSignD) $stdCols[] = 'sign_uploaded_d';
                if ($docSubHasQr) $stdCols[] = 'qr_modified_at';
                foreach (
                    [
                        "SELECT " . implode(',', array_merge($stdCols, ['verify_note'])) . " FROM document_submissions WHERE sub_ID = ?",
                        "SELECT " . implode(',', $stdCols) . " FROM document_submissions WHERE sub_ID = ?",
                        "SELECT sign_path, dcsub_updated_d, verify_result FROM document_submissions WHERE sub_ID = ?"
                    ] as $sql
                ) {
                    try {
                        $stmt = $conn->prepare($sql);
                        $stmt->execute([$student_sub_id]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            if (!isset($row['verify_note'])) $row['verify_note'] = null;
                            if (!isset($row['original_pdf_path'])) $row['original_pdf_path'] = null;
                            if (!isset($row['qr_modified_at'])) $row['qr_modified_at'] = null;
                            break;
                        }
                    } catch (Throwable $e) {
                        continue;
                    }
                }

                if (!$row) {
                    json_ok(['compare_data' => [
                        'original_pdf_path' => null,
                        'sign_path' => null,
                        'qr_modified_at' => null,
                        'system_last_modified' => null,
                        'verify_result' => null,
                        'verify_note' => null,
                        'submission_id' => null
                    ]]);
                    break;
                }

                // 核實改為僅依 DB：qr_modified_at 為原始 PDF 版本時間，不再從簽名 PDF 解析
                $qrModifiedAt = isset($row['qr_modified_at']) && trim((string)$row['qr_modified_at']) !== '' ? trim($row['qr_modified_at']) : null;

                json_ok(['compare_data' => [
                    'original_pdf_path' => $row['original_pdf_path'] ?? null,
                    'sign_path' => $row['sign_path'] ?? null,
                    'qr_modified_at' => $qrModifiedAt,
                    'system_last_modified' => $row['dcsub_updated_d'] ?? $row['dcsu_updated_d'] ?? null,
                    'verify_result' => isset($row['verify_result']) && $row['verify_result'] !== null ? (int)$row['verify_result'] : null,
                    'verify_note' => isset($row['verify_note']) ? trim((string)$row['verify_note']) : null,
                    'submission_id' => $student_sub_id
                ]]);
            } catch (Throwable $e) {
                error_log('取得對比資料錯誤: ' . $e->getMessage());
                json_ok(['compare_data' => [
                    'original_pdf_path' => null,
                    'sign_path' => null,
                    'qr_modified_at' => null,
                    'system_last_modified' => null,
                    'verify_result' => null,
                    'verify_note' => null,
                    'submission_id' => null
                ]]);
            }
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('取得對比資料失敗：' . $e->getMessage());
        }
        break;

    // 保存原始PDF路徑（預覽PDF時調用）- 已廢棄，使用 save_document_form_draft 處理
    case 'save_original_pdf_path':
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $u_ID = $document_form_get_u_id();
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }
            $doc_ID = (int)($p['doc_ID'] ?? $p['document_id'] ?? 0);
            $original_pdf_path = trim($p['original_pdf_path'] ?? '');
            if ($doc_ID <= 0) {
                json_err('表單ID無效');
            }
            if (empty($original_pdf_path)) {
                json_err('原始PDF路徑無效');
            }
            // 檢查是否已提交，已提交則硬性阻擋
            try {
                $stmtCheck = $conn->prepare("SELECT dcsub_status FROM document_submissions WHERE doc_ID = ? AND dcsub_u_ID = ? AND dcsub_status = 1 LIMIT 1");
                $stmtCheck->execute([$doc_ID, $u_ID]);
                if ($stmtCheck->fetch()) {
                    json_err('此表單已提交，無法再次修改', 'ALREADY_SUBMITTED', 403);
                }
            } catch (Throwable $e) {
                // 忽略錯誤，繼續處理
            }
            // 更新或插入original_pdf_path
            try {
                $stmt = $conn->prepare("
                    SELECT sub_ID FROM document_submissions
                    WHERE doc_ID = ? AND dcsub_u_ID = ?
                    ORDER BY sub_ID DESC LIMIT 1
                ");
                $stmt->execute([$doc_ID, $u_ID]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existing && !empty($existing['sub_ID'])) {
                    try {
                        $updateStmt = $conn->prepare("
                            UPDATE document_submissions SET original_pdf_path = ?, dcsu_updated_d = NOW() WHERE sub_ID = ?
                        ");
                        $updateStmt->execute([$original_pdf_path, (int)$existing['sub_ID']]);
                        if ($updateStmt->rowCount() > 0) {
                            error_log('成功更新original_pdf_path (save_original_pdf_path), sub_ID: ' . $existing['sub_ID']);
                        }
                    } catch (Throwable $e) {
                        error_log('更新original_pdf_path失敗 (save_original_pdf_path): ' . $e->getMessage());
                        json_err('更新失敗：' . $e->getMessage());
                    }
                } else {
                    // 如果沒有暫存記錄，創建一個
                    try {
                        $insertStmt = $conn->prepare("
                            INSERT INTO document_submissions (doc_ID, dcsub_u_ID, dcsub_status, dcsub_answers, original_pdf_path, dcsub_created_d, dcsu_updated_d)
                            VALUES (?, ?, 4, '{}', ?, NOW(), NOW())
                        ");
                        $insertStmt->execute([$doc_ID, $u_ID, $original_pdf_path]);
                        error_log('創建新記錄並設置original_pdf_path (save_original_pdf_path), sub_ID: ' . $conn->lastInsertId());
                    } catch (Throwable $e) {
                        error_log('創建記錄失敗 (save_original_pdf_path): ' . $e->getMessage());
                        json_err('保存失敗：' . $e->getMessage());
                    }
                }
                json_ok(['message' => '原始PDF路徑已保存']);
            } catch (Throwable $e) {
                error_log('保存失敗 (save_original_pdf_path): ' . $e->getMessage());
                json_err('保存失敗：' . $e->getMessage());
            }
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('保存原始PDF路徑失敗：' . $e->getMessage());
        }
        break;

    // 保存原始PDF（預覽/產生PDF時調用）
    // 可經 save_document_form_draft 僅上傳 original_pdf 時 goto 至此，或 do=save_original_pdf 呼叫
    case 'save_original_pdf':
        run_save_original_pdf:
        if (ob_get_level() > 0) {
            ob_clean();
        }
        ob_start();
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $u_ID = $document_form_get_u_id();
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }
            $doc_ID = (int)($p['doc_ID'] ?? $p['document_id'] ?? 0);
            $sub_ID = (int)($p['sub_ID'] ?? $p['submission_id'] ?? 0);
            if ($doc_ID <= 0) {
                json_err('表單ID無效');
            }

            // 檢查是否已提交，已提交則硬性阻擋
            if ($sub_ID > 0) {
                try {
                    $stmtCheck = $conn->prepare("SELECT dcsub_status FROM document_submissions WHERE sub_ID = ? AND dcsub_u_ID = ? AND dcsub_status = 1");
                    $stmtCheck->execute([$sub_ID, $u_ID]);
                    if ($stmtCheck->fetch()) {
                        json_err('此表單已提交，無法再次修改', 'ALREADY_SUBMITTED', 403);
                    }
                } catch (Throwable $e) {
                    // 忽略錯誤，繼續處理
                }
            }

            // 接收上傳的PDF文件
            if (empty($_FILES['original_pdf']) || $_FILES['original_pdf']['error'] !== UPLOAD_ERR_OK) {
                json_err('請上傳PDF文件');
            }

            $tmpPath = $_FILES['original_pdf']['tmp_name'];
            $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpPath);
            $ext = strtolower(pathinfo($_FILES['original_pdf']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf' || (strpos($mime, 'pdf') === false && $mime !== 'application/octet-stream')) {
                json_err('僅允許PDF格式');
            }

            // 保存PDF文件
            $uploadDir = projectevery_full_path('uploads/document_form_original_pdfs');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $safeName = 'doc_' . $doc_ID . '_u_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $u_ID) . '_' . time() . '.pdf';
            $file_path = 'uploads/document_form_original_pdfs/' . $safeName;
            $fullPath = projectevery_full_path($file_path);

            if (!move_uploaded_file($tmpPath, $fullPath)) {
                json_err('PDF儲存失敗');
            }

            // 先產出表單主體 PDF（已存於 $fullPath = main）。若 DB 有 attach_path 且檔案存在，合併成 merged 再寫入 original_pdf_path
            $attach_path = null;
            $resolve_sub_ID = $sub_ID;
            if ($sub_ID > 0) {
                $stmtAtt = $conn->prepare("SELECT attach_path FROM document_submissions WHERE sub_ID = ? AND dcsub_u_ID = ?");
                $stmtAtt->execute([$sub_ID, $u_ID]);
                $arow = $stmtAtt->fetch(PDO::FETCH_ASSOC);
                $attach_path = isset($arow['attach_path']) ? trim((string)$arow['attach_path']) : '';
            } else {
                $stmtAtt = $conn->prepare("SELECT sub_ID, attach_path FROM document_submissions WHERE doc_ID = ? AND dcsub_u_ID = ? ORDER BY sub_ID DESC LIMIT 1");
                $stmtAtt->execute([$doc_ID, $u_ID]);
                $arow = $stmtAtt->fetch(PDO::FETCH_ASSOC);
                if ($arow) {
                    $resolve_sub_ID = (int)($arow['sub_ID'] ?? 0);
                    $attach_path = isset($arow['attach_path']) ? trim((string)$arow['attach_path']) : '';
                }
            }
            $attach_full = ($attach_path !== '' && $attach_path !== null) ? projectevery_full_path($attach_path) : '';
            if ($attach_full !== '' && is_file($attach_full) && is_readable($attach_full)) {
                $mergedName = 'merged_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
                $merged_relative = 'uploads/document_form_original_pdfs/' . $mergedName;
                $merged_full = projectevery_full_path($merged_relative);
                $qpdf_ok = false;
                foreach (['qpdf', 'qpdf.exe'] as $qpdf_cmd) {
                    $cmd = sprintf(
                        '%s --empty --pages %s %s -- %s',
                        escapeshellcmd($qpdf_cmd),
                        escapeshellarg($fullPath),
                        escapeshellarg($attach_full),
                        escapeshellarg($merged_full)
                    );
                    exec($cmd . ' 2>&1', $out, $code);
                    if ($code === 0 && is_file($merged_full) && is_readable($merged_full)) {
                        $qpdf_ok = true;
                        break;
                    }
                }
                if ($qpdf_ok) {
                    @unlink($fullPath);
                    $file_path = $merged_relative;
                    $fullPath = $merged_full;
                } else {
                    error_log('save_original_pdf: qpdf 合併失敗，使用表單主體 PDF');
                }
            }

            // 僅在 original_pdf_path 為 NULL 時寫入；UPDATE 條件含 sub_ID + dcsub_u_ID 以對應正式資料表
            try {
                if ($sub_ID > 0) {
                    $stmt = $conn->prepare("
                        UPDATE document_submissions SET original_pdf_path = ?, dcsub_updated_d = NOW()
                        WHERE sub_ID = ? AND dcsub_u_ID = ? AND (original_pdf_path IS NULL OR original_pdf_path = '')
                    ");
                    $stmt->execute([$file_path, $sub_ID, $u_ID]);
                    if ($stmt->rowCount() === 0) {
                        $stmtGet = $conn->prepare("SELECT sub_ID FROM document_submissions WHERE sub_ID = ? AND dcsub_u_ID = ?");
                        $stmtGet->execute([$sub_ID, $u_ID]);
                        if ($stmtGet->fetch()) {
                            json_ok(['message' => '原始PDF已存在', 'original_pdf_path' => $file_path, 'sub_ID' => $sub_ID]);
                        } else {
                            json_err('找不到對應的記錄或無權限');
                        }
                    } else {
                        error_log('成功更新original_pdf_path到sub_ID: ' . $sub_ID);
                        json_ok(['message' => '原始PDF已保存', 'original_pdf_path' => $file_path, 'sub_ID' => $sub_ID]);
                    }
                } else {
                    // 一組一份文件只會有一筆：依 (doc_ID, dcsub_u_ID) 取唯一一筆，不依 status 過濾以免漏掉而重複 INSERT
                    $stmt = $conn->prepare("
                        SELECT sub_ID, original_pdf_path FROM document_submissions
                        WHERE doc_ID = ? AND dcsub_u_ID = ?
                        ORDER BY sub_ID DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$doc_ID, $u_ID]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($existing && !empty($existing['sub_ID'])) {
                        $currentPath = isset($existing['original_pdf_path']) ? trim((string)$existing['original_pdf_path']) : '';
                        if ($currentPath === '' || $currentPath === null) {
                            $updateStmt = $conn->prepare("
                                UPDATE document_submissions SET original_pdf_path = ?, dcsub_updated_d = NOW()
                                WHERE sub_ID = ? AND dcsub_u_ID = ?
                            ");
                            $updateStmt->execute([$file_path, (int)$existing['sub_ID'], $u_ID]);
                            if ($updateStmt->rowCount() > 0) {
                                error_log('成功更新original_pdf_path到sub_ID: ' . $existing['sub_ID']);
                            }
                            json_ok(['message' => '原始PDF已保存', 'original_pdf_path' => $file_path, 'sub_ID' => (int)$existing['sub_ID']]);
                        } else {
                            json_ok(['message' => '原始PDF已存在', 'original_pdf_path' => $existing['original_pdf_path'], 'sub_ID' => (int)$existing['sub_ID']]);
                        }
                    } else {
                        $insertStmt = $conn->prepare("
                            INSERT INTO document_submissions (doc_ID, dcsub_u_ID, dcsub_status, dcsub_answers, original_pdf_path, dcsub_created_d, dcsub_updated_d)
                            VALUES (?, ?, 4, '{}', ?, NOW(), NOW())
                        ");
                        $insertStmt->execute([$doc_ID, $u_ID, $file_path]);
                        $new_sub_id = $conn->lastInsertId();
                        error_log('創建新記錄並設置original_pdf_path, sub_ID: ' . $new_sub_id);
                        json_ok(['message' => '原始PDF已保存', 'original_pdf_path' => $file_path, 'sub_ID' => (int)$new_sub_id]);
                    }
                }
            } catch (Throwable $e) {
                error_log('save_original_pdf: ' . $e->getMessage());
                json_err('保存失敗：' . $e->getMessage());
            }
        } catch (PDOException $e) {
            json_err('資料庫錯誤：' . $e->getMessage(), 'DB_ERROR', 500);
        } catch (Throwable $e) {
            json_err('保存原始PDF失敗：' . $e->getMessage());
        }
        break;

    // 取得表單附件 - 科辦用
    case 'get_document_form_attachment':
        try {
            checkDocumentFormAdminPermission();
            $ensureFormAttachmentsTable();
            $doc_ID = (int)($_GET['doc_ID'] ?? $_GET['document_id'] ?? 0);
            if ($doc_ID <= 0) {
                json_err('表單ID無效');
            }
            $stmt = $conn->prepare("SELECT doc_ID, display_name, file_path, created_d FROM document_form_attachments WHERE doc_ID = ?");
            $stmt->execute([$doc_ID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                json_ok(['attachment' => null]);
                break;
            }
            json_ok(['attachment' => $row]);
        } catch (Throwable $e) {
            json_err('取得附件失敗：' . $e->getMessage());
        }
        break;

    // 上傳表單附件 - 科辦用，僅允許 PDF
    case 'upload_document_form_attachment':
        try {
            checkDocumentFormAdminPermission();
            $ensureFormAttachmentsTable();
            $doc_ID = (int)($p['doc_ID'] ?? $p['document_id'] ?? $_POST['doc_ID'] ?? 0);
            $display_name = trim($p['display_name'] ?? $_POST['display_name'] ?? '');
            if ($doc_ID <= 0) {
                json_err('表單ID無效');
            }
            if ($display_name === '') {
                $display_name = '附件.pdf';
            }
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                json_err('請選擇 PDF 檔案上傳');
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['file']['tmp_name']);
            if ($mime !== 'application/pdf' && strpos($mime, 'pdf') === false) {
                json_err('僅允許上傳 PDF 檔案');
            }
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                json_err('僅允許上傳 PDF 檔案');
            }
            $uploadDir = projectevery_full_path('uploads/document_form_attachments');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $safeName = 'doc_' . $doc_ID . '_' . date('YmdHis') . '.pdf';
            $file_path = 'uploads/document_form_attachments/' . $safeName;
            $fullPath = projectevery_full_path($file_path);
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $fullPath)) {
                json_err('儲存檔案失敗');
            }
            $stmt = $conn->prepare("SELECT doc_ID FROM document_form_attachments WHERE doc_ID = ?");
            $stmt->execute([$doc_ID]);
            if ($stmt->fetch()) {
                $oldStmt = $conn->prepare("SELECT file_path FROM document_form_attachments WHERE doc_ID = ?");
                $oldStmt->execute([$doc_ID]);
                $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
                if ($old && file_exists(projectevery_full_path($old['file_path']))) {
                    @unlink(projectevery_full_path($old['file_path']));
                }
                $stmt = $conn->prepare("UPDATE document_form_attachments SET display_name = ?, file_path = ?, created_d = NOW() WHERE doc_ID = ?");
                $stmt->execute([$display_name, $file_path, $doc_ID]);
            } else {
                $stmt = $conn->prepare("INSERT INTO document_form_attachments (doc_ID, display_name, file_path, created_d) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$doc_ID, $display_name, $file_path]);
            }
            json_ok(['message' => '附件已上傳', 'display_name' => $display_name, 'file_path' => $file_path]);
        } catch (Throwable $e) {
            json_err('上傳附件失敗：' . $e->getMessage());
        }
        break;

    default:
        json_err('未知的操作：' . $do);
        break;
}
