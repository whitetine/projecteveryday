<?php
/**
 * 學生端：獲取歷屆成果檔案列表
 * 條件：content_json.history_status = 1
 */

session_start();
require '../includes/pdo.php';

header('Content-Type: application/json; charset=utf-8');

// 檢查權限（學生、主任、科辦皆可使用：role_ID = 6,1,2）
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

if (!$u_ID) {
    echo json_encode([
        "success" => false,
        "message" => "請先登入"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 獲取篩選參數
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : 0;
    
    // 構建查詢條件
    $whereConditions = [
        // 使用 JSON_EXTRACT 檢查 content_json.history_status = 1
        // 使用 CAST 和 JSON_UNQUOTE 確保正確轉換為數字並比較
        "CAST(JSON_UNQUOTE(JSON_EXTRACT(ps.content_json, '$.history_status')) AS UNSIGNED) = 1",
        // prosub_status 至少要包含 3（不能寫死 1/2）
        "ps.prosub_status >= 3"
    ];
    $params = [];
    
    if ($cohort_ID > 0) {
        $whereConditions[] = 't.cohort_ID = ?';
        $params[] = $cohort_ID;
    }
    
    if ($search) {
        $whereConditions[] = 't.team_project_name LIKE ?';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // 查詢成果資料
    $sql = "
        SELECT 
            ps.prosub_ID,
            ps.team_ID,
            ps.prosub_other,
            ps.content_json,
            ps.prosub_status,
            ps.prosub_created_d,
            t.team_project_name,
            t.cohort_ID,
            t.group_ID,
            c.cohort_name,
            c.year_label,
            g.group_name
        FROM prosubdata ps
        INNER JOIN teamdata t ON ps.team_ID = t.team_ID
        LEFT JOIN cohortdata c ON t.cohort_ID = c.cohort_ID
        LEFT JOIN groupdata g ON t.group_ID = g.group_ID
        LEFT JOIN projectdata pd ON ps.pro_ID = pd.pro_ID
        WHERE {$whereClause}
          AND (pd.pro_status IS NULL OR pd.pro_status = 1)
        ORDER BY ps.prosub_created_d DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 處理每個成果的檔案資訊
    $result = [];
    foreach ($submissions as $sub) {
        $downloadableFiles = [];
        
        // 解析 prosub_other（JSON 陣列）
        $prosubOther = null;
        if (!empty($sub['prosub_other'])) {
            $prosubOther = json_decode($sub['prosub_other'], true);
        }
        
        // 解析 content_json（JSON 物件）
        $contentJson = null;
        if (!empty($sub['content_json'])) {
            $contentJson = json_decode($sub['content_json'], true);
        }
        
        // 從 prosub_other 讀取文件列表（只返回允許下載的檔案，allow_download = 1）
        // 🔹 【統一使用 allow_download 欄位】根據用戶要求，只返回 allow_download = 1 的檔案
        if (is_array($prosubOther)) {
            foreach ($prosubOther as $index => $file) {
                $filePath = '';
                $fileName = '';
                $uploadTime = '';
                $fileSize = 0;
                $fileType = '';
                $allow_download = 0; // 預設不開放
                
                if (is_string($file)) {
                    // 舊格式：字符串路徑（預設不開放，需要手動開啟）
                    // 舊格式不顯示，因為沒有 allow_download 欄位，預設為不開放
                    continue; // 跳過舊格式，不顯示
                } elseif (is_array($file)) {
                    // 新格式：包含多個字段
                    // 🔹 【統一使用 allow_download 欄位】根據用戶要求，優先使用 allow_download
                    $allow_download = 0;
                    if (isset($file['allow_download'])) {
                        $allow_download = (int)$file['allow_download'];
                    } elseif (isset($file['allow'])) {
                        // 兼容舊的 allow 欄位
                        $allow_download = (int)$file['allow'];
                    } elseif (isset($file['public'])) {
                        // 兼容舊的 public 欄位
                        $allow_download = (bool)$file['public'] ? 1 : 0;
                    }
                    // 如果都沒有，預設為 0（不開放）
                    
                    // 如果檔案不允許下載（allow_download != 1），跳過
                    if ($allow_download != 1) {
                        continue;
                    }
                    
                    if (isset($file['path'])) {
                        $filePath = $file['path'];
                    } elseif (isset($file['stored']) && isset($file['path'])) {
                        // 如果有 stored 和 path，組合完整路徑
                        $filePath = rtrim($file['path'], '/') . '/' . $file['stored'];
                    } elseif (isset($file['name'])) {
                        // 如果只有 name，嘗試從 name 構建路徑
                        $fileName = $file['name'];
                        if (preg_match('/^other_\d+_\d+_\d+_[a-f0-9]+\./', $fileName)) {
                            $filePath = 'uploads/project_other_files/' . $fileName;
                        } else {
                            continue; // 無法確定路徑，跳過
                        }
                    } else {
                        continue; // 無法識別的格式，跳過
                    }
                    
                    $fileName = $file['name'] ?? $file['original_name'] ?? basename($filePath);
                    $uploadTime = $file['uploaded_at'] ?? $file['upload_time'] ?? '';
                    $fileSize = isset($file['size']) ? (int)$file['size'] : 0;
                    $fileType = $file['type'] ?? $file['mime'] ?? '';
                }
                // 檔案類型：依存檔類型（副檔名）.pdf→成果書、.pptx/.ppt→PPT、.docx/.doc→Word
                $fileTypeKey = isset($file['file_type']) ? trim((string)$file['file_type']) : '';
                if ($fileTypeKey === '' && $filePath) {
                    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                    if ($ext === 'pdf') $fileTypeKey = 'report';
                    elseif (in_array($ext, ['pptx', 'ppt'], true)) $fileTypeKey = 'ppt';
                    elseif (in_array($ext, ['docx', 'doc'], true)) $fileTypeKey = 'word';
                }
                $fileTypeLabels = ['report' => '成果書', 'ppt' => 'PPT', 'word' => 'Word'];
                $fileTypeLabel = isset($fileTypeLabels[$fileTypeKey]) ? $fileTypeLabels[$fileTypeKey] : $fileTypeKey;
                
                // 只返回有有效路徑且允許下載的檔案（allow_download = 1）
                if ($filePath && $allow_download == 1) {
                    $downloadableFiles[] = [
                        'fid' => 'file_' . $index,
                        'name' => $fileName,
                        'path' => $filePath,
                        'type' => $fileType,
                        'size' => $fileSize,
                        'uploaded_at' => $uploadTime,
                        'file_type' => $fileTypeKey,
                        'file_type_label' => $fileTypeLabel
                    ];
                }
            }
        }
        
        // 如果 content_json 中有 files 陣列，也加入（備用，但也要檢查 public 狀態）
        if (is_array($contentJson) && isset($contentJson['files']) && is_array($contentJson['files'])) {
            $fileIndex = count($downloadableFiles);
            foreach ($contentJson['files'] as $file) {
                if (is_array($file) && isset($file['path'])) {
                    // 檢查 allow 欄位（優先使用 allow，兼容 public 和 allow_download）
                    $allow = isset($file['allow']) ? (int)$file['allow'] : 
                            (isset($file['public']) ? ((bool)$file['public'] ? 1 : 0) : 
                            (isset($file['allow_download']) ? ((bool)$file['allow_download'] ? 1 : 0) : 1));
                    
                    // 只返回允許下載的檔案（allow=1）
                    if ($allow == 1) {
                        $ftKey = isset($file['file_type']) ? trim((string)$file['file_type']) : '';
                        if ($ftKey === '' && isset($file['path'])) {
                            $ext = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
                            if ($ext === 'pdf') $ftKey = 'report';
                            elseif (in_array($ext, ['pptx', 'ppt'], true)) $ftKey = 'ppt';
                            elseif (in_array($ext, ['docx', 'doc'], true)) $ftKey = 'word';
                        }
                        $ftLabels = ['report' => '成果書', 'ppt' => 'PPT', 'word' => 'Word'];
                        $ftLabel = isset($ftLabels[$ftKey]) ? $ftLabels[$ftKey] : $ftKey;
                        $downloadableFiles[] = [
                            'fid' => 'file_' . $fileIndex,
                            'name' => $file['name'] ?? basename($file['path']),
                            'path' => $file['path'],
                            'type' => $file['type'] ?? $file['mime'] ?? '',
                            'size' => isset($file['size']) ? (int)$file['size'] : 0,
                            'uploaded_at' => $file['uploaded_at'] ?? '',
                            'file_type' => $ftKey,
                            'file_type_label' => $ftLabel
                        ];
                        $fileIndex++;
                    }
                }
            }
        }
        
        // 只有當有可下載檔案時才加入結果
        if (!empty($downloadableFiles)) {
            $result[] = [
                'prosub_ID' => (int)$sub['prosub_ID'],
                'team_ID' => (int)$sub['team_ID'],
                'team_name' => $sub['team_project_name'] ?? '未命名團隊',
                'cohort_name' => $sub['cohort_name'] ?? '',
                'cohort_ID' => (int)$sub['cohort_ID'],
                'year_label' => $sub['year_label'] ?? '',
                'group_name' => $sub['group_name'] ?? '',
                'group_ID' => (int)$sub['group_ID'],
                'created_at' => $sub['prosub_created_d'] ?? '',
                'prosub_other' => $prosubOther, // 返回解析後的陣列
                'content_json' => $contentJson, // 返回解析後的物件
                'files' => $downloadableFiles
            ];
        }
    }
    
    echo json_encode([
        "success" => true,
        "submissions" => $result
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log('Get archive results error: ' . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "資料庫錯誤"
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Get archive results error: ' . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "伺服器錯誤"
    ], JSON_UNESCAPED_UNICODE);
}

