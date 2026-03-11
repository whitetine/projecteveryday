<?php
global $conn;
$p = $_POST;
$do = $_GET['do'] ?? '';
//c9 
$normalizeDateTime = static function ($value) {
    if ($value === null || $value === '') {
        return null;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }
    return $value;
};

$decodeTargetList = static function ($value): array {
    // 如果已經是陣列，直接返回（去重並轉為字串）
    if (is_array($value)) {
        return array_values(array_unique(array_map('strval', $value)));
    }
    // 如果是 null 或空字串，返回空陣列
    if ($value === null || $value === '') {
        return [];
    }
    // 嘗試解析 JSON 字串
    $decoded = json_decode((string) $value, true);
    // 如果解析失敗或結果不是陣列，返回空陣列
    if (!is_array($decoded)) {
        return [];
    }
    // 返回去重並轉為字串的陣列
    return array_values(array_unique(array_map('strval', $decoded)));
};

$buildTargetPayload = static function ($source, bool $defaultAll = false) use ($decodeTargetList): array {
    $all = (int) ($source['doc_target_all'] ?? $source['target_all'] ?? $source['doc_target_ALL'] ?? 0);
    $payload = [
        'doc_target_all' => $all ? 1 : 0,
        'doc_target_cohorts' => $decodeTargetList($source['doc_target_cohorts'] ?? $source['target_cohorts'] ?? []),
        'doc_target_grades' => $decodeTargetList($source['doc_target_grades'] ?? $source['target_grades'] ?? []),
        'doc_target_classes' => $decodeTargetList($source['doc_target_classes'] ?? $source['target_classes'] ?? []),
        'doc_target_groups' => $decodeTargetList($source['doc_target_groups'] ?? $source['target_groups'] ?? []),
    ];

    if (
        $defaultAll
        && !$payload['doc_target_all']
        && !$payload['doc_target_cohorts']
        && !$payload['doc_target_grades']
        && !$payload['doc_target_classes']
        && !$payload['doc_target_groups']
    ) {
        $payload['doc_target_all'] = 1;
    }

    return $payload;
};

$resolveUploadField = static function () {
    // 檢查 doc_file 欄位
    if (isset($_FILES['doc_file']) && 
        !empty($_FILES['doc_file']['name']) && 
        $_FILES['doc_file']['error'] === UPLOAD_ERR_OK &&
        $_FILES['doc_file']['size'] > 0) {
        return $_FILES['doc_file'];
    }
    // 檢查 file 欄位
    if (isset($_FILES['file']) && 
        !empty($_FILES['file']['name']) && 
        $_FILES['file']['error'] === UPLOAD_ERR_OK &&
        $_FILES['file']['size'] > 0) {
        return $_FILES['file'];
    }
    return null;
};

$saveUploadedPdf = static function (array $fileField): array {
    $ext = strtolower(pathinfo($fileField['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        json_err('僅允許 PDF');
    }

    $dir = __DIR__ . '/../uploads/doc/';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        json_err('無法建立檔案目錄');
    }

    $saveName = 'doc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '.pdf';
    $savePath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $saveName;

    if (!move_uploaded_file($fileField['tmp_name'], $savePath)) {
        json_err('PDF 儲存失敗');
    }

    return [
        'relative' => 'uploads/doc/' . $saveName,
        'absolute' => $savePath,
    ];
};

$deletePhysicalFile = static function (?string $relativePath): void {
    if (!$relativePath) {
        return;
    }
    $root = realpath(__DIR__ . '/..');
    $fullPath = $root ? $root . '/' . ltrim($relativePath, '/') : __DIR__ . '/../' . ltrim($relativePath, '/');
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
};

$ensureTargetTable = static function () use ($conn): void {
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS doctargetdata (
                doc_ID INT NOT NULL,
                doc_target_type ENUM('ALL','COHORT','GRADE','CLASS','TEAM','USER','GROUP') NOT NULL,
                doc_target_ID VARCHAR(50) NOT NULL,
                PRIMARY KEY (doc_ID, doc_target_type, doc_target_ID),
                FOREIGN KEY (doc_ID) REFERENCES docdata(doc_ID) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (Throwable $e) {
        // ignore
    }
};

$hydrateTargets = static function (array &$rows) use ($conn, $ensureTargetTable): void {
    if (!$rows) {
        return;
    }
    $docIds = array_column($rows, 'doc_ID');
    if (!$docIds) {
        return;
    }

    $ensureTargetTable();
    $placeholders = implode(',', array_fill(0, count($docIds), '?'));
    $stmt = $conn->prepare("
        SELECT doc_ID, doc_target_type, doc_target_ID
        FROM doctargetdata
        WHERE doc_ID IN ($placeholders)
    ");
    try {
        $stmt->execute($docIds);
    } catch (Throwable $e) {
        return;
    }

    $map = [];
    while ($target = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[$target['doc_ID']][] = $target;
    }

    foreach ($rows as &$row) {
        $row['doc_target_all'] = false;
        $row['doc_target_cohorts'] = [];
        $row['doc_target_grades'] = [];
        $row['doc_target_classes'] = [];
        $row['doc_target_groups'] = [];

        $targets = $map[$row['doc_ID']] ?? [];
        foreach ($targets as $target) {
            switch ($target['doc_target_type']) {
                case 'ALL':
                    $row['doc_target_all'] = true;
                    break;
                case 'COHORT':
                    $row['doc_target_cohorts'][] = $target['doc_target_ID'];
                    break;
                case 'GRADE':
                    $row['doc_target_grades'][] = $target['doc_target_ID'];
                    break;
                case 'CLASS':
                    $row['doc_target_classes'][] = $target['doc_target_ID'];
                    break;
                case 'GROUP':
                    $row['doc_target_groups'][] = $target['doc_target_ID'];
                    break;
            }
        }
    }
    unset($row);
};

$fetchDocs = static function (bool $onlyActive = false, bool $withTargets = false) use ($conn, $hydrateTargets): array {
    $sql = "
        SELECT
            doc_ID,
            doc_name,
            doc_des,
            doc_type,
            doc_example,
            is_top,
            is_required,
            doc_start_d,
            doc_end_d,
            doc_status,
            doc_u_ID,
            doc_created_d
        FROM docdata
    ";
    if ($onlyActive) {
        $sql .= " WHERE doc_status = 1";
    }
    $sql .= " ORDER BY is_top DESC, doc_ID DESC";

    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['doc_ID'] = (int) $row['doc_ID'];
        $row['is_top'] = (int) ($row['is_top'] ?? 0);
        $row['is_required'] = (int) ($row['is_required'] ?? 0);
        $row['doc_status'] = (int) ($row['doc_status'] ?? 0);
    }
    unset($row);

    if ($withTargets) {
        $hydrateTargets($rows);
    }

    return $rows;
};

$syncTargets = static function (int $docId, array $targets) use ($conn): void {
    // 先刪除該文件的所有舊目標記錄
    $stmt = $conn->prepare("DELETE FROM doctargetdata WHERE doc_ID = ?");
    $stmt->execute([$docId]);

    // 如果設定為全部可見，插入 ALL 記錄並返回
    if (!empty($targets['doc_target_all']) || $targets['doc_target_all'] === 1 || $targets['doc_target_all'] === '1') {
        $insert = $conn->prepare("
            INSERT INTO doctargetdata (doc_ID, doc_target_type, doc_target_ID)
            VALUES (?, 'ALL', '1')
        ");
        $insert->execute([$docId]);
        return;
    }

    // 準備插入語句
    $insert = $conn->prepare("
        INSERT INTO doctargetdata (doc_ID, doc_target_type, doc_target_ID)
        VALUES (?, ?, ?)
    ");

    // 映射目標類型到目標值陣列
    $map = [
        'COHORT' => $targets['doc_target_cohorts'] ?? [],
        'GRADE' => $targets['doc_target_grades'] ?? [],
        'CLASS' => $targets['doc_target_classes'] ?? [],
        'GROUP' => $targets['doc_target_groups'] ?? [],
    ];

    // 插入所有目標記錄
    foreach ($map as $type => $values) {
        // 確保 values 是陣列
        if (!is_array($values)) {
            continue;
        }
        foreach ($values as $value) {
            // 確保值不為空
            if ($value === null || $value === '') {
                continue;
            }
            try {
                $insert->execute([$docId, $type, (string) $value]);
            } catch (Throwable $e) {
                // 如果遇到重複鍵錯誤（不應該發生，因為我們已經刪除了舊記錄），記錄錯誤但不中斷
                // 其他錯誤應該被拋出，讓上層處理
                if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                    throw $e;
                }
            }
        }
    }
};

$readStudentInfo = static function (?string $u_ID) use ($conn): ?array {
    if (!$u_ID) {
        return null;
    }
    // 先獲取學級、班級、年級資訊
    $stmt = $conn->prepare("
        SELECT cohort_ID, class_ID, enroll_grade
        FROM enrollmentdata
        WHERE enroll_u_ID = ? AND enroll_status = 1
        ORDER BY enroll_created_d DESC
        LIMIT 1
    ");
    $stmt->execute([$u_ID]);
    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$enrollment) {
        return null;
    }
    
    // 獲取用戶的組別（從團隊資料中獲取）
    $group_ID = null;
    try {
        // 檢查 teammember 表結構（兼容兩種版本）
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
            $group_ID = (string) $team['group_ID'];
        }
    } catch (Throwable $e) {
        // 如果查詢失敗，group_ID 保持為 null
    }
    
    $result = [
        'cohort_ID' => $enrollment['cohort_ID'],
        'class_ID' => $enrollment['class_ID'],
        'enroll_grade' => $enrollment['enroll_grade'],
    ];
    
    if ($group_ID !== null) {
        $result['group_ID'] = $group_ID;
    }
    
    return $result;
};

$handleUploadDoc = static function (bool $defaultAllTargets = false) use ($conn, $normalizeDateTime, $buildTargetPayload, $resolveUploadField, $saveUploadedPdf, $deletePhysicalFile, $syncTargets, $ensureTargetTable) {
    $p = $_POST;
    $doc_name = trim($p['doc_name'] ?? $p['file_name'] ?? $p['f_name'] ?? '');
    if ($doc_name === '') {
        json_err('缺少表單名稱');
    }

    $fileField = $resolveUploadField();
    if (!$fileField || empty($fileField['name'])) {
        json_err('請選擇 PDF');
    }
    $saved = $saveUploadedPdf($fileField);
    $doc_example = $saved['relative'];

    $doc_des = trim($p['doc_des'] ?? $p['file_des'] ?? '');
    $is_required = (int) ($p['is_required'] ?? $p['doc_is_required'] ?? 0) ? 1 : 0;
    $doc_start_d = $normalizeDateTime($p['doc_start_d'] ?? $p['file_start_d'] ?? null);
    $doc_end_d = $normalizeDateTime($p['doc_end_d'] ?? $p['file_end_d'] ?? null);
    $targets = $buildTargetPayload($p, $defaultAllTargets);

    try {
        $ensureTargetTable();
        $conn->beginTransaction();
        $stmt = $conn->prepare("
            INSERT INTO docdata
            (doc_name, doc_des, doc_type, doc_example, is_top, is_required,
             doc_start_d, doc_end_d, doc_status, doc_u_ID, doc_created_d)
            VALUES (?, ?, 'pdf', ?, 0, ?, ?, ?, 1, ?, NOW())
        ");
        $stmt->execute([
            $doc_name,
            $doc_des,
            $doc_example,
            $is_required,
            $doc_start_d,
            $doc_end_d,
            $_SESSION['u_ID'] ?? null,
        ]);
        $docId = (int) $conn->lastInsertId();

        $syncTargets($docId, $targets);
        $conn->commit();

        json_ok([
            'doc_ID' => $docId,
            'doc_example' => $doc_example,
        ]);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $deletePhysicalFile($doc_example);
        json_err('資料寫入失敗：' . $e->getMessage());
    }
};

$handleUpdateDoc = static function (int $docId) use ($conn, $normalizeDateTime, $buildTargetPayload, $resolveUploadField, $saveUploadedPdf, $deletePhysicalFile, $syncTargets, $ensureTargetTable) {
    $p = $_POST;
    $doc_name = trim($p['doc_name'] ?? $p['file_name'] ?? '');
    if ($doc_name === '') {
        json_err('缺少表單名稱');
    }

    $doc_des = trim($p['doc_des'] ?? $p['file_des'] ?? '');
    $is_required = (int) ($p['is_required'] ?? $p['doc_is_required'] ?? 0) ? 1 : 0;
    $doc_start_d = $normalizeDateTime($p['doc_start_d'] ?? $p['file_start_d'] ?? null);
    $doc_end_d = $normalizeDateTime($p['doc_end_d'] ?? $p['file_end_d'] ?? null);
    $targets = $buildTargetPayload($p);

    $fileField = $resolveUploadField();
    $newFile = null;
    $oldFile = null;

    try {
        $ensureTargetTable();
        $conn->beginTransaction();
        $stmt = $conn->prepare("SELECT doc_example FROM docdata WHERE doc_ID = ?");
        $stmt->execute([$docId]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$found) {
            $conn->rollBack();
            json_err('找不到文件');
        }
        $oldFile = $found['doc_example'] ?? null;

        $update = [
            'doc_name = ?',
            'doc_des = ?',
            'is_required = ?',
            'doc_start_d = ?',
            'doc_end_d = ?',
            'doc_status = 1',
        ];
        $values = [
            $doc_name,
            $doc_des,
            $is_required,
            $doc_start_d,
            $doc_end_d,
        ];

        // 只有當真正有上傳新文件時才替換（檢查文件是否存在、無錯誤、有大小）
        if ($fileField && 
            !empty($fileField['name']) && 
            isset($fileField['error']) && 
            $fileField['error'] === UPLOAD_ERR_OK &&
            isset($fileField['size']) && 
            $fileField['size'] > 0 &&
            isset($fileField['tmp_name']) &&
            is_uploaded_file($fileField['tmp_name'])) {
            $saved = $saveUploadedPdf($fileField);
            $newFile = $saved['relative'];
            $update[] = 'doc_example = ?';
            $values[] = $newFile;
        }
        // 如果沒有上傳新文件，保留原本的檔案（不更新 doc_example）

        $values[] = $docId;
        $stmt = $conn->prepare("
            UPDATE docdata
            SET " . implode(', ', $update) . "
            WHERE doc_ID = ?
        ");
        $stmt->execute($values);

        $syncTargets($docId, $targets);
        $conn->commit();

        if ($newFile && $oldFile && $newFile !== $oldFile) {
            $deletePhysicalFile($oldFile);
        }
        json_ok();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        if ($newFile) {
            $deletePhysicalFile($newFile);
        }
        json_err('更新失敗：' . $e->getMessage());
    }
};

switch ($do) {
    case 'get_all_TemplatesFile':
        $rows = $fetchDocs(true, false);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;

    case 'get_files':
        $rows = $fetchDocs(false, false);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;

    case 'get_files_with_targets':
        $rows = $fetchDocs(false, true);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;

    case 'listActiveFiles':
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $docs = $fetchDocs(true, true);
        $studentInfo = $readStudentInfo($_SESSION['u_ID'] ?? null);
        $now = date('Y-m-d H:i:s');
        $visible = [];

        foreach ($docs as $doc) {
            if (!empty($doc['doc_start_d']) && $doc['doc_start_d'] > $now) {
                continue;
            }
            if (!empty($doc['doc_end_d']) && $doc['doc_end_d'] < $now) {
                continue;
            }

            $show = false;
            $hasGroupTarget = !empty($doc['doc_target_groups']) && is_array($doc['doc_target_groups']) && count($doc['doc_target_groups']) > 0;
            
            // 優先檢查：如果文件設定為全部可見，所有人都能看到（不受其他條件限制）
            // 檢查 doc_target_all 的值（可能是 boolean true 或字符串 '1'）
            if (!empty($doc['doc_target_all']) || $doc['doc_target_all'] === true || $doc['doc_target_all'] === 1 || $doc['doc_target_all'] === '1') {
                $show = true;
            } 
            // 如果文件沒有任何目標設定（所有目標都是空的），則顯示（向後兼容）
            elseif (
                empty($doc['doc_target_all']) &&
                empty($doc['doc_target_cohorts']) &&
                empty($doc['doc_target_grades']) &&
                empty($doc['doc_target_classes']) &&
                empty($doc['doc_target_groups'])
            ) {
                $show = true;
            } 
            // 如果有學生資訊，檢查是否符合文件的目標設定
            else {
                // 如果沒有學生資訊，且文件設定了其他目標（不是全部可見），不顯示
                if (!$studentInfo) {
                    continue;
                }
                $groupMatched = false;
                
                // 優先檢查類組：如果文件指定了類組，必須匹配類組才能顯示
                if ($hasGroupTarget) {
                    // 如果用戶沒有組別資訊，不顯示
                    if (!isset($studentInfo['group_ID']) || $studentInfo['group_ID'] === null || $studentInfo['group_ID'] === '') {
                        continue; // 跳過這個文件，繼續下一個
                    } 
                    
                    // 標準化用戶組別ID（確保是字符串）
                    $userGroupId = trim((string) $studentInfo['group_ID']);
                    if ($userGroupId === '') {
                        continue; // 跳過這個文件，繼續下一個
                    }
                    
                    // 檢查用戶的組別是否在文件的目標組別中（嚴格匹配）
                    foreach ($doc['doc_target_groups'] as $targetGroupId) {
                        // 標準化目標組別ID（確保是字符串）
                        $targetGroupIdStr = trim((string) $targetGroupId);
                        // 嚴格匹配（同時比較字符串和整數格式，確保類型一致）
                        if ($userGroupId === $targetGroupIdStr || (int)$userGroupId === (int)$targetGroupIdStr) {
                            $groupMatched = true;
                            break;
                        }
                    }
                    
                    if (!$groupMatched) {
                        // 如果文件指定了類組但用戶的類組不匹配，不顯示
                        continue; // 跳過這個文件，繼續下一個
                    }
                }
                
                // 類組檢查已通過或沒有指定類組，繼續檢查其他條件
                {
                    $requiresCohortMatch = !empty($doc['doc_target_cohorts']) && is_array($doc['doc_target_cohorts']) && count($doc['doc_target_cohorts']) > 0;
                    if ($requiresCohortMatch) {
                        if (!isset($studentInfo['cohort_ID']) || $studentInfo['cohort_ID'] === null || $studentInfo['cohort_ID'] === '') {
                            continue;
                        }
                        if (!in_array((string) $studentInfo['cohort_ID'], $doc['doc_target_cohorts'], true)) {
                            continue;
                        }
                    }
                    // 類組已經在最前面檢查過了，如果到這裡說明類組已匹配或沒有指定類組
                    $hasOtherTarget = false;
                    $otherMatched = false;
                    
                    // 檢查年級
                    if (!empty($doc['doc_target_grades']) && is_array($doc['doc_target_grades']) && count($doc['doc_target_grades']) > 0) {
                        $hasOtherTarget = true;
                        if (in_array((string) $studentInfo['enroll_grade'], $doc['doc_target_grades'], true)) {
                            $otherMatched = true;
                        }
                    }
                    
                    // 檢查班級
                    if (!empty($doc['doc_target_classes']) && is_array($doc['doc_target_classes']) && count($doc['doc_target_classes']) > 0) {
                        $hasOtherTarget = true;
                        if (in_array((string) $studentInfo['class_ID'], $doc['doc_target_classes'], true)) {
                            $otherMatched = true;
                        }
                    }
                    
                    // 判斷是否顯示
                    if ($hasGroupTarget) {
                        // 如果文件指定了類組，類組已經匹配（不匹配的已經被 continue 跳過）
                        // 如果同時指定了其他條件，其他條件也必須至少匹配一個
                        if ($hasOtherTarget) {
                            // 類組已匹配，檢查其他條件
                            $show = $otherMatched;
                        } else {
                            // 如果只指定了類組，類組已匹配就顯示
                            $show = true;
                        }
                    } else {
                        // 如果文件沒有指定類組，檢查其他條件
                        if ($hasOtherTarget) {
                            $show = $otherMatched;
                        } else {
                            // 如果沒有指定任何目標，則顯示（向後兼容）
                            $show = true;
                        }
                    }
                }
            }

            if ($show) {
                $visible[] = $doc;
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($visible, JSON_UNESCAPED_UNICODE);
        exit;

    case 'upload_template':
        $handleUploadDoc(true);
        break;

    case 'upload_file_with_targets':
        $handleUploadDoc(false);
        break;

    case 'update_file_with_targets':
        $doc_ID = (int) ($p['doc_ID'] ?? $p['file_ID'] ?? 0);
        if ($doc_ID <= 0) {
            json_err('doc_ID 無效');
        }
        $handleUpdateDoc($doc_ID);
        break;

    case 'update_template':
        $req = read_json_body();
        $doc_ID = (int) ($req['doc_ID'] ?? $req['file_ID'] ?? 0);
        if ($doc_ID <= 0) {
            json_err('doc_ID 無效');
        }
        $doc_status = $req['doc_status'] ?? $req['file_status'] ?? null;
        $is_top = $req['is_top'] ?? null;

        if ($doc_status === null && $is_top === null) {
            json_err('缺少更新欄位');
        }

        try {
            $stmt = $conn->prepare("
                UPDATE docdata
                SET doc_status = COALESCE(?, doc_status),
                    is_top = COALESCE(?, is_top)
                WHERE doc_ID = ?
            ");
            $stmt->execute([
                $doc_status !== null ? (int) $doc_status : null,
                $is_top !== null ? (int) $is_top : null,
                $doc_ID,
            ]);
            json_ok();
        } catch (Throwable $e) {
            json_err('更新失敗：' . $e->getMessage());
        }
        break;

    case 'delete_file':
        $payload = read_json_body();
        $doc_ID = (int) ($payload['doc_ID'] ?? $payload['file_ID'] ?? 0);
        if ($doc_ID <= 0) {
            json_err('doc_ID 無效');
        }

        $ensureTargetTable();
        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("SELECT doc_example FROM docdata WHERE doc_ID = ?");
            $stmt->execute([$doc_ID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // 先刪目標資料避免 FK 擋
            try {
                $stmt = $conn->prepare("DELETE FROM doctargetdata WHERE doc_ID = ?");
                $stmt->execute([$doc_ID]);
            } catch (Throwable $e) {
                // 若表不存在則忽略
            }

            $stmt = $conn->prepare("DELETE FROM docdata WHERE doc_ID = ?");
            $stmt->execute([$doc_ID]);
            $conn->commit();

            if ($row) {
                $deletePhysicalFile($row['doc_example'] ?? null);
            }
            json_ok();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('刪除失敗：' . $e->getMessage());
        }
        break;

    case 'batch_delete_files':
        $payload = read_json_body();
        $ids = $payload['doc_IDs'] ?? $payload['file_IDs'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, static fn($id) => $id > 0);
        if (!$ids) {
            json_err('沒有指定文件');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $ensureTargetTable();
        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("SELECT doc_example FROM docdata WHERE doc_ID IN ($placeholders)");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            try {
                $stmt = $conn->prepare("DELETE FROM doctargetdata WHERE doc_ID IN ($placeholders)");
                $stmt->execute($ids);
            } catch (Throwable $e) {
                // ignore when table missing
            }

            $stmt = $conn->prepare("DELETE FROM docdata WHERE doc_ID IN ($placeholders)");
            $stmt->execute($ids);
            $conn->commit();

            foreach ($rows as $row) {
                $deletePhysicalFile($row['doc_example'] ?? null);
            }
            json_ok(['deleted' => $ids]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('刪除失敗：' . $e->getMessage());
        }
        break;

    case 'check_exist':
        // 簡潔檢查：該 doc_ID 是否已有同組成員提交過
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $doc_ID = isset($_GET['doc_ID']) ? (int)$_GET['doc_ID'] : (isset($p['doc_ID']) ? (int)$p['doc_ID'] : 0);
        $u_ID = $_SESSION['u_ID'] ?? '';
        
        if (empty($doc_ID) || empty($u_ID)) {
            json_err('缺少必要參數');
        }
        
        try {
            // 獲取用戶的團隊ID
            $team_ID = null;
            $teamUserField = 'team_u_ID';
            $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
            $checkStmt->execute();
            if (!$checkStmt->fetch()) {
                $teamUserField = 'u_ID';
            }
            
            $teamStmt = $conn->prepare("
                SELECT t.team_ID
                FROM teammember tm
                INNER JOIN teamdata t ON tm.team_ID = t.team_ID
                WHERE tm.{$teamUserField} = ? 
                  AND t.team_status = 1
                  AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
                ORDER BY t.team_update_d DESC
                LIMIT 1
            ");
            $teamStmt->execute([$u_ID]);
            $teamRow = $teamStmt->fetch(PDO::FETCH_ASSOC);
            if ($teamRow && !empty($teamRow['team_ID'])) {
                $team_ID = (int)$teamRow['team_ID'];
            }
            
            // 獲取團隊成員列表（包括當前用戶）
            $teamMemberIds = [];
            if ($team_ID !== null) {
                $memberStmt = $conn->prepare("
                    SELECT {$teamUserField} as u_ID
                    FROM teammember
                    WHERE team_ID = ? AND (tm_status = 1 OR tm_status IS NULL)
                ");
                $memberStmt->execute([$team_ID]);
                $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
                $teamMemberIds = array_column($members, 'u_ID');
                if (!in_array($u_ID, $teamMemberIds)) {
                    $teamMemberIds[] = $u_ID;
                }
            } else {
                $teamMemberIds = [$u_ID];
            }
            
            // 檢查是否已有提交記錄
            $exists = false;
            if (!empty($teamMemberIds)) {
                $memberPlaceholders = implode(',', array_fill(0, count($teamMemberIds), '?'));
                
                if ($team_ID !== null) {
                    $checkStmt = $conn->prepare("
                        SELECT 1
                        FROM docsubdata
                        WHERE doc_ID = ?
                          AND (
                              dcsub_team_ID = ?
                              OR (dcsub_team_ID IS NULL AND dcsub_u_ID IN ($memberPlaceholders))
                          )
                        LIMIT 1
                    ");
                    $params = array_merge([$doc_ID, $team_ID], $teamMemberIds);
                } else {
                    $checkStmt = $conn->prepare("
                        SELECT 1
                        FROM docsubdata
                        WHERE doc_ID = ? AND dcsub_u_ID = ?
                        LIMIT 1
                    ");
                    $params = [$doc_ID, $u_ID];
                }
                
                $checkStmt->execute($params);
                $exists = $checkStmt->fetch() !== false;
            }
            
            // 返回簡潔結果
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'exists' => $exists
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            json_err('檢查失敗：' . $e->getMessage());
        }
        exit;

    case 'checkDocSubmission':
        // 檢查同組是否已提交指定文件
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $doc_ID = isset($_GET['doc_ID']) ? (int)$_GET['doc_ID'] : (isset($p['doc_ID']) ? (int)$p['doc_ID'] : 0);
        $u_ID = $_SESSION['u_ID'] ?? '';
        
        if (empty($doc_ID) || empty($u_ID)) {
            json_err('缺少必要參數');
        }
        
        try {
            // 獲取用戶的團隊ID
            $team_ID = null;
            $teamUserField = 'team_u_ID';
            $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
            $checkStmt->execute();
            if (!$checkStmt->fetch()) {
                $teamUserField = 'u_ID';
            }
            
            $teamStmt = $conn->prepare("
                SELECT t.team_ID
                FROM teammember tm
                INNER JOIN teamdata t ON tm.team_ID = t.team_ID
                WHERE tm.{$teamUserField} = ? 
                  AND t.team_status = 1
                  AND (tm.tm_status = 1 OR tm.tm_status IS NULL)
                ORDER BY t.team_update_d DESC
                LIMIT 1
            ");
            $teamStmt->execute([$u_ID]);
            $teamRow = $teamStmt->fetch(PDO::FETCH_ASSOC);
            if ($teamRow && !empty($teamRow['team_ID'])) {
                $team_ID = (int)$teamRow['team_ID'];
            }
            
            // 獲取團隊成員列表（包括當前用戶）
            $teamMemberIds = [];
            if ($team_ID !== null) {
                $memberStmt = $conn->prepare("
                    SELECT {$teamUserField} as u_ID
                    FROM teammember
                    WHERE team_ID = ? AND (tm_status = 1 OR tm_status IS NULL)
                ");
                $memberStmt->execute([$team_ID]);
                $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
                $teamMemberIds = array_column($members, 'u_ID');
                if (!in_array($u_ID, $teamMemberIds)) {
                    $teamMemberIds[] = $u_ID;
                }
            } else {
                $teamMemberIds = [$u_ID];
            }
            
            // 檢查是否已有提交記錄
            $existingRecord = null;
            if (!empty($teamMemberIds)) {
                $memberPlaceholders = implode(',', array_fill(0, count($teamMemberIds), '?'));
                
                if ($team_ID !== null) {
                    $checkStmt = $conn->prepare("
                        SELECT ds.sub_ID, ds.dcsub_url, ds.dcsub_sub_d, ds.dcsub_u_ID, u.u_name as uploader_name
                        FROM docsubdata ds
                        LEFT JOIN userdata u ON ds.dcsub_u_ID = u.u_ID
                        WHERE ds.doc_ID = ?
                          AND (
                              ds.dcsub_team_ID = ?
                              OR (ds.dcsub_team_ID IS NULL AND ds.dcsub_u_ID IN ($memberPlaceholders))
                          )
                        ORDER BY ds.dcsub_sub_d DESC
                        LIMIT 1
                    ");
                    $params = array_merge([$doc_ID, $team_ID], $teamMemberIds);
                } else {
                    $checkStmt = $conn->prepare("
                        SELECT ds.sub_ID, ds.dcsub_url, ds.dcsub_sub_d, ds.dcsub_u_ID, u.u_name as uploader_name
                        FROM docsubdata ds
                        LEFT JOIN userdata u ON ds.dcsub_u_ID = u.u_ID
                        WHERE ds.doc_ID = ? AND ds.dcsub_u_ID = ?
                        ORDER BY ds.dcsub_sub_d DESC
                        LIMIT 1
                    ");
                    $params = [$doc_ID, $u_ID];
                }
                
                $checkStmt->execute($params);
                $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
            }
            
            // 返回結果
            if ($existingRecord) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => false,
                    'code' => 'DUPLICATE_SUBMIT',
                    'message' => '此文件本組已繳交',
                    'submitted' => true,
                    'uploader_name' => $existingRecord['uploader_name'] ?? '未知',
                    'upload_time' => $existingRecord['dcsub_sub_d']
                ], JSON_UNESCAPED_UNICODE);
            } else {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'submitted' => false
                ], JSON_UNESCAPED_UNICODE);
            }
        } catch (Throwable $e) {
            json_err('檢查失敗：' . $e->getMessage());
        }
        exit;

    default:
        json_err('Unknown action: ' . ($do ?: ''));
}

