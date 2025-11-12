<?php
global $conn;
$p = $_POST;
$do = $_GET['do'] ?? '';

switch ($do) {
    // 舊 API（apply.php 用）：啟用檔案列表
case 'get_all_TemplatesFile':
    $rows = $conn->query("SELECT * FROM filedata WHERE file_status=1")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);   // ← 直接回陣列
    exit;

   case 'get_files':
      try {
        $rows = $conn->query("
            SELECT file_ID, file_name, file_url, file_status, is_top, file_update_d
            FROM filedata
            ORDER BY is_top DESC, file_ID DESC     -- ← 重點
        ")->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'資料讀取失敗：'.$e->getMessage()]);
        exit;
    }
    // 狀態/置頂切換
    case 'update_template':
        $req = read_json_body();
        $file_ID     = intval($req['file_ID']     ?? 0);
        $file_status = intval($req['file_status'] ?? 0); // 0/1
        $is_top      = intval($req['is_top']      ?? 0); // 0/1
        if ($file_ID <= 0) json_err('file_ID 無效');

        try {
            $stmt = $conn->prepare("
                UPDATE filedata
                SET file_status = ?, is_top = ?, file_update_d = NOW()
                WHERE file_ID = ?
            ");
            $stmt->execute([$file_status, $is_top, $file_ID]);
            json_ok();
        } catch (Throwable $e) {
            json_err('更新失敗：'.$e->getMessage());
        }
        break;

    // 上傳 PDF（相容 f_name 舊欄位）
    case 'upload_template':
        $file_name = trim($p['file_name'] ?? ($p['f_name'] ?? ''));
        if ($file_name === '') json_err('缺少表單名稱');
        if (empty($_FILES['file']['name'])) json_err('請選擇要上傳的檔案');

        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') json_err('只允許上傳 PDF');

        $dir = __DIR__ . '/../templates';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) json_err('伺服器目錄建立失敗');

        $saveName = 'tpl_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
        $savePath = $dir . '/' . $saveName;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $savePath)) {
            json_err('檔案儲存失敗（權限或磁碟空間不足）');
        }

        $file_url = 'templates/' . $saveName;

        try {
            $stmt = $conn->prepare("
                INSERT INTO filedata (file_name, file_url, file_status, is_top, file_update_d)
                VALUES (?, ?, 1, 0, NOW())
            ");
            $stmt->execute([$file_name, $file_url]);
            json_ok(['file_ID' => (int)$conn->lastInsertId(), 'file_url' => $file_url]);
        } catch (Throwable $e) {
            @unlink($savePath);
            json_err('資料寫入失敗：'.$e->getMessage());
        }
        break;

    // 只取啟用的檔案（apply.php / file.php）- 根據學生資訊過濾
    case 'listActiveFiles':
        try {
            session_start();
            $u_ID = $_SESSION['u_ID'] ?? '';
            
            // 獲取學生的學籍資訊
            $studentInfo = null;
            if ($u_ID) {
                $stmt = $conn->prepare("
                    SELECT e.cohort_ID, e.class_ID, e.enroll_grade
                    FROM enrollmentdata e
                    WHERE e.enroll_u_ID = ? AND e.enroll_status = 1
                    ORDER BY e.enroll_created_d DESC
                    LIMIT 1
                ");
                $stmt->execute([$u_ID]);
                $studentInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // 獲取所有啟用的文件及其目標範圍
            $rows = $conn->query("
                SELECT 
                    f.file_ID, 
                    f.file_name, 
                    f.file_url,
                    f.is_required,
                    f.file_start_d,
                    f.file_end_d
                FROM filedata f
                WHERE f.file_status = 1
                ORDER BY f.is_top DESC, f.file_ID DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // 獲取每個文件的目標範圍並過濾
            $filteredRows = [];
            foreach ($rows as $row) {
                $file_ID = $row['file_ID'];
                $shouldShow = false;

                // 檢查時間限制
                $now = date('Y-m-d H:i:s');
                if (!empty($row['file_start_d']) && $row['file_start_d'] > $now) {
                    continue; // 尚未開放
                }
                if (!empty($row['file_end_d']) && $row['file_end_d'] < $now) {
                    continue; // 已過期
                }

                // 檢查目標範圍
                try {
                    $targets = $conn->query("
                        SELECT file_target_type, file_target_ID
                        FROM filetargetdata
                        WHERE file_ID = $file_ID
                    ")->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($targets)) {
                        // 如果沒有設定目標範圍，預設顯示給所有人
                        $shouldShow = true;
                    } else {
                        foreach ($targets as $target) {
                            if ($target['file_target_type'] === 'ALL') {
                                $shouldShow = true;
                                break;
                            } elseif ($studentInfo) {
                                if ($target['file_target_type'] === 'COHORT' && 
                                    $target['file_target_ID'] == $studentInfo['cohort_ID']) {
                                    $shouldShow = true;
                                    break;
                                } elseif ($target['file_target_type'] === 'GRADE' && 
                                         $target['file_target_ID'] == $studentInfo['enroll_grade']) {
                                    $shouldShow = true;
                                    break;
                                } elseif ($target['file_target_type'] === 'CLASS' && 
                                         $target['file_target_ID'] == $studentInfo['class_ID']) {
                                    $shouldShow = true;
                                    break;
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    // 如果表不存在，預設顯示
                    $shouldShow = true;
                }

                if ($shouldShow) {
                    $filteredRows[] = $row;
                }
            }

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($filteredRows, JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status'=>'error','message'=>'資料讀取失敗：'.$e->getMessage()]);
            exit;
        }

    // 獲取文件及其目標範圍
    case 'get_files_with_targets':
        try {
            // 先檢查 filedata 表是否有新欄位，如果沒有則使用預設值
            $rows = $conn->query("
                SELECT 
                    f.file_ID, 
                    f.file_name, 
                    f.file_url, 
                    f.file_status, 
                    f.is_top, 
                    f.file_update_d,
                    COALESCE(f.file_des, '') as file_des,
                    COALESCE(f.is_required, 0) as is_required,
                    f.file_start_d,
                    f.file_end_d
                FROM filedata f
                ORDER BY f.is_top DESC, f.file_ID DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // 獲取每個文件的目標範圍
            foreach ($rows as &$row) {
                $file_ID = $row['file_ID'];
                
                // 檢查是否有 filetargetdata 表
                try {
                    $targets = $conn->query("
                        SELECT file_target_type, file_target_ID
                        FROM filetargetdata
                        WHERE file_ID = $file_ID
                    ")->fetchAll(PDO::FETCH_ASSOC);
                    
                    $row['target_all'] = false;
                    $row['target_cohorts'] = [];
                    $row['target_grades'] = [];
                    $row['target_classes'] = [];
                    
                    foreach ($targets as $target) {
                        if ($target['file_target_type'] === 'ALL') {
                            $row['target_all'] = true;
                        } elseif ($target['file_target_type'] === 'COHORT') {
                            $row['target_cohorts'][] = $target['file_target_ID'];
                        } elseif ($target['file_target_type'] === 'GRADE') {
                            $row['target_grades'][] = $target['file_target_ID'];
                        } elseif ($target['file_target_type'] === 'CLASS') {
                            $row['target_classes'][] = $target['file_target_ID'];
                        }
                    }
                } catch (Exception $e) {
                    // 如果表不存在，使用預設值
                    $row['target_all'] = false;
                    $row['target_cohorts'] = [];
                    $row['target_grades'] = [];
                    $row['target_classes'] = [];
                }
            }
            unset($row);

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status'=>'error','message'=>'資料讀取失敗：'.$e->getMessage()]);
            exit;
        }

    // 上傳文件並設定目標範圍
    case 'upload_file_with_targets':
        $file_name = trim($p['file_name'] ?? '');
        $file_des = trim($p['file_des'] ?? '');
        $is_required = intval($p['is_required'] ?? 0);
        $file_start_d = !empty($p['file_start_d']) ? $p['file_start_d'] : null;
        $file_end_d = !empty($p['file_end_d']) ? $p['file_end_d'] : null;
        $target_all = intval($p['target_all'] ?? 0);
        $target_cohorts = json_decode($p['target_cohorts'] ?? '[]', true) ?: [];
        $target_grades = json_decode($p['target_grades'] ?? '[]', true) ?: [];
        $target_classes = json_decode($p['target_classes'] ?? '[]', true) ?: [];

        if ($file_name === '') json_err('缺少表單名稱');
        if (empty($_FILES['file']['name'])) json_err('請選擇要上傳的檔案');

        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') json_err('只允許上傳 PDF');

        $dir = __DIR__ . '/../templates';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) json_err('伺服器目錄建立失敗');

        $saveName = 'tpl_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
        $savePath = $dir . '/' . $saveName;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $savePath)) {
            json_err('檔案儲存失敗（權限或磁碟空間不足）');
        }

        $file_url = 'templates/' . $saveName;

        try {
            $conn->beginTransaction();

            // 檢查並添加新欄位（如果不存在）
            try {
                $conn->exec("ALTER TABLE filedata ADD COLUMN file_des TEXT");
            } catch (Exception $e) {
                // 欄位已存在，忽略錯誤
            }
            try {
                $conn->exec("ALTER TABLE filedata ADD COLUMN is_required INT DEFAULT 0");
            } catch (Exception $e) {
                // 欄位已存在，忽略錯誤
            }
            try {
                $conn->exec("ALTER TABLE filedata ADD COLUMN file_start_d DATETIME");
            } catch (Exception $e) {
                // 欄位已存在，忽略錯誤
            }
            try {
                $conn->exec("ALTER TABLE filedata ADD COLUMN file_end_d DATETIME");
            } catch (Exception $e) {
                // 欄位已存在，忽略錯誤
            }

            // 插入文件資料
            $stmt = $conn->prepare("
                INSERT INTO filedata (file_name, file_url, file_des, is_required, file_start_d, file_end_d, file_status, is_top, file_update_d)
                VALUES (?, ?, ?, ?, ?, ?, 1, 0, NOW())
            ");
            $stmt->execute([$file_name, $file_url, $file_des, $is_required, $file_start_d, $file_end_d]);
            $file_ID = (int)$conn->lastInsertId();

            // 創建 filetargetdata 表（如果不存在）
            try {
                $conn->exec("
                    CREATE TABLE IF NOT EXISTS filetargetdata (
                        file_ID INT NOT NULL,
                        file_target_type ENUM('ALL','COHORT','GRADE','CLASS','GROUP') NOT NULL,
                        file_target_ID VARCHAR(50) NOT NULL,
                        PRIMARY KEY (file_ID, file_target_type, file_target_ID),
                        FOREIGN KEY (file_ID) REFERENCES filedata(file_ID) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                ");
            } catch (Exception $e) {
                // 表已存在，忽略錯誤
            }

            // 插入目標範圍
            if ($target_all) {
                $stmt = $conn->prepare("
                    INSERT INTO filetargetdata (file_ID, file_target_type, file_target_ID)
                    VALUES (?, 'ALL', '1')
                    ON DUPLICATE KEY UPDATE file_target_ID = '1'
                ");
                $stmt->execute([$file_ID]);
            } else {
                foreach ($target_cohorts as $cohort_ID) {
                    $stmt = $conn->prepare("
                        INSERT INTO filetargetdata (file_ID, file_target_type, file_target_ID)
                        VALUES (?, 'COHORT', ?)
                        ON DUPLICATE KEY UPDATE file_target_ID = ?
                    ");
                    $stmt->execute([$file_ID, $cohort_ID, $cohort_ID]);
                }
                foreach ($target_grades as $grade) {
                    $stmt = $conn->prepare("
                        INSERT INTO filetargetdata (file_ID, file_target_type, file_target_ID)
                        VALUES (?, 'GRADE', ?)
                        ON DUPLICATE KEY UPDATE file_target_ID = ?
                    ");
                    $stmt->execute([$file_ID, $grade, $grade]);
                }
                foreach ($target_classes as $class_ID) {
                    $stmt = $conn->prepare("
                        INSERT INTO filetargetdata (file_ID, file_target_type, file_target_ID)
                        VALUES (?, 'CLASS', ?)
                        ON DUPLICATE KEY UPDATE file_target_ID = ?
                    ");
                    $stmt->execute([$file_ID, $class_ID, $class_ID]);
                }
            }

            $conn->commit();
            json_ok(['file_ID' => $file_ID, 'file_url' => $file_url]);
        } catch (Throwable $e) {
            $conn->rollBack();
            @unlink($savePath);
            json_err('資料寫入失敗：'.$e->getMessage());
        }
        break;

    // 更新文件及其目標範圍
    case 'update_file_with_targets':
        $file_ID = intval($p['file_ID'] ?? 0);
        if ($file_ID <= 0) json_err('file_ID 無效');

        $file_name = trim($p['file_name'] ?? '');
        $file_des = trim($p['file_des'] ?? '');
        $is_required = intval($p['is_required'] ?? 0);
        $file_start_d = !empty($p['file_start_d']) ? $p['file_start_d'] : null;
        $file_end_d = !empty($p['file_end_d']) ? $p['file_end_d'] : null;
        $target_all = intval($p['target_all'] ?? 0);
        $target_cohorts = json_decode($p['target_cohorts'] ?? '[]', true) ?: [];
        $target_grades = json_decode($p['target_grades'] ?? '[]', true) ?: [];
        $target_classes = json_decode($p['target_classes'] ?? '[]', true) ?: [];

        if ($file_name === '') json_err('缺少表單名稱');

        try {
            $conn->beginTransaction();

            // 更新文件資料
            $updateFields = ['file_name = ?', 'file_des = ?', 'is_required = ?', 'file_start_d = ?', 'file_end_d = ?', 'file_update_d = NOW()'];
            $updateValues = [$file_name, $file_des, $is_required, $file_start_d, $file_end_d];

            // 如果有新檔案，處理上傳
            if (!empty($_FILES['file']['name'])) {
                $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'pdf') {
                    $conn->rollBack();
                    json_err('只允許上傳 PDF');
                }

                $dir = __DIR__ . '/../templates';
                $saveName = 'tpl_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
                $savePath = $dir . '/' . $saveName;

                if (!move_uploaded_file($_FILES['file']['tmp_name'], $savePath)) {
                    $conn->rollBack();
                    json_err('檔案儲存失敗');
                }

                $file_url = 'templates/' . $saveName;
                $updateFields[] = 'file_url = ?';
                $updateValues[] = $file_url;
            }

            $updateValues[] = $file_ID;

            $stmt = $conn->prepare("
                UPDATE filedata
                SET " . implode(', ', $updateFields) . "
                WHERE file_ID = ?
            ");
            $stmt->execute($updateValues);

            // 刪除舊的目標範圍
            $stmt = $conn->prepare("DELETE FROM filetargetdata WHERE file_ID = ?");
            $stmt->execute([$file_ID]);

            // 插入新的目標範圍
            if ($target_all) {
                $stmt = $conn->prepare("
                    INSERT INTO filetargetdata (file_ID, file_target_type, file_target_ID)
                    VALUES (?, 'ALL', '1')
                ");
                $stmt->execute([$file_ID]);
            } else {
                foreach ($target_cohorts as $cohort_ID) {
                    $stmt = $conn->prepare("
                        INSERT INTO filetargetdata (file_ID, file_target_type, file_target_ID)
                        VALUES (?, 'COHORT', ?)
                    ");
                    $stmt->execute([$file_ID, $cohort_ID]);
                }
                foreach ($target_grades as $grade) {
                    $stmt = $conn->prepare("
                        INSERT INTO filetargetdata (file_ID, file_target_type, file_target_ID)
                        VALUES (?, 'GRADE', ?)
                    ");
                    $stmt->execute([$file_ID, $grade]);
                }
                foreach ($target_classes as $class_ID) {
                    $stmt = $conn->prepare("
                        INSERT INTO filetargetdata (file_ID, file_target_type, file_target_ID)
                        VALUES (?, 'CLASS', ?)
                    ");
                    $stmt->execute([$file_ID, $class_ID]);
                }
            }

            $conn->commit();
            json_ok();
        } catch (Throwable $e) {
            $conn->rollBack();
            json_err('更新失敗：'.$e->getMessage());
        }
        break;

    // 刪除文件
    case 'delete_file':
        $req = read_json_body();
        $file_ID = intval($req['file_ID'] ?? 0);
        if ($file_ID <= 0) json_err('file_ID 無效');

        try {
            $conn->beginTransaction();

            // 獲取文件路徑以便刪除檔案
            $stmt = $conn->prepare("SELECT file_url FROM filedata WHERE file_ID = ?");
            $stmt->execute([$file_ID]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);

            // 刪除資料庫記錄（CASCADE 會自動刪除 filetargetdata）
            $stmt = $conn->prepare("DELETE FROM filedata WHERE file_ID = ?");
            $stmt->execute([$file_ID]);

            // 刪除實體檔案
            if ($file && !empty($file['file_url'])) {
                $filePath = __DIR__ . '/../' . $file['file_url'];
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }

            $conn->commit();
            json_ok();
        } catch (Throwable $e) {
            $conn->rollBack();
            json_err('刪除失敗：'.$e->getMessage());
        }
        break;

    // 批量刪除文件
    case 'batch_delete_files':
        $req = read_json_body();
        $file_IDs = $req['file_IDs'] ?? [];
        if (!is_array($file_IDs) || empty($file_IDs)) json_err('請選擇要刪除的文件');

        $file_IDs = array_map('intval', $file_IDs);
        $placeholders = implode(',', array_fill(0, count($file_IDs), '?'));

        try {
            $conn->beginTransaction();

            // 獲取文件路徑
            $stmt = $conn->prepare("SELECT file_url FROM filedata WHERE file_ID IN ($placeholders)");
            $stmt->execute($file_IDs);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 刪除資料庫記錄
            $stmt = $conn->prepare("DELETE FROM filedata WHERE file_ID IN ($placeholders)");
            $stmt->execute($file_IDs);

            // 刪除實體檔案
            foreach ($files as $file) {
                if (!empty($file['file_url'])) {
                    $filePath = __DIR__ . '/../' . $file['file_url'];
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }
            }

            $conn->commit();
            json_ok(['deleted_count' => count($file_IDs)]);
        } catch (Throwable $e) {
            $conn->rollBack();
            json_err('批量刪除失敗：'.$e->getMessage());
        }
        break;
}
