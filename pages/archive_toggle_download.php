<?php
/**
 * 切換歷屆成果檔案下載權限 API
 */

session_start();
require '../includes/pdo.php';

header('Content-Type: application/json; charset=utf-8');

// 檢查權限（主任 role_ID = 1 和 科辦 role_ID = 2）
$role_ID = $_SESSION['role_ID'] ?? null;
if (!isset($role_ID) || !in_array($role_ID, [1, 2])) {
    echo json_encode([
        "success" => false,
        "message" => "無權限"
    ]);
    exit;
}

    // 獲取參數
    $prosub_ID = isset($_POST['prosub_ID']) ? (int)$_POST['prosub_ID'] : 0;
    $fid = isset($_POST['fid']) ? trim($_POST['fid']) : '';
    $public = isset($_POST['public']) ? filter_var($_POST['public'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
    
    // 兼容舊的 allowDownload 參數
    if ($public === null && isset($_POST['allowDownload'])) {
        $public = filter_var($_POST['allowDownload'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    // 驗證參數
    if (!$prosub_ID || !$fid) {
        echo json_encode([
            "success" => false,
            "message" => "參數錯誤"
        ]);
        exit;
    }

    // 驗證值
    if ($public === null) {
        echo json_encode([
            "success" => false,
            "message" => "無效的值"
        ]);
        exit;
    }

try {
    // 查詢成果資料
    $stmt = $conn->prepare("
        SELECT prosub_other
        FROM prosubdata
        WHERE prosub_ID = ?
    ");
    $stmt->execute([$prosub_ID]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$submission) {
        echo json_encode([
            "success" => false,
            "message" => "成果不存在"
        ]);
        exit;
    }

    // 解析 prosub_other
    $otherFilesJson = json_decode($submission['prosub_other'], true);
    if (!is_array($otherFilesJson)) {
        $otherFilesJson = [];
    }

    // 查找並更新對應的檔案（根據 fid 或索引）
    $fileFound = false;
    $fidIndex = null;
    
    // 如果 fid 是 file_0, file_1 格式，提取索引
    if (preg_match('/^file_(\d+)$/', $fid, $matches)) {
        $fidIndex = (int)$matches[1];
    }
    
    foreach ($otherFilesJson as $index => &$file) {
        // 檢查是否匹配（通過索引或 fid）
        $matches = false;
        if ($fidIndex !== null && $index === $fidIndex) {
            $matches = true;
        } elseif (is_array($file) && isset($file['fid']) && $file['fid'] === $fid) {
            $matches = true;
        } elseif (is_string($file) && $fidIndex !== null && $index === $fidIndex) {
            // 舊格式字符串，通過索引匹配
            $matches = true;
        } elseif (is_array($file) && isset($file['path']) && $file['path'] === $fid) {
            // 兼容：如果 fid 是路徑（舊版本可能傳入路徑）
            $matches = true;
        }
        
        if ($matches) {
            // 確保是新格式（包含 name, path, type, uploaded_at, public）
            if (is_string($file)) {
                // 舊格式：轉換為新格式
                $file = [
                    'name' => basename($file),
                    'path' => $file,
                    'type' => '',
                    'uploaded_at' => '',
                    'public' => true
                ];
            } elseif (is_array($file)) {
                // 確保包含所有必要欄位
                if (!isset($file['name'])) {
                    $file['name'] = $file['original_name'] ?? basename($file['path'] ?? '');
                }
                if (!isset($file['type'])) {
                    $file['type'] = '';
                }
                if (!isset($file['uploaded_at'])) {
                    $file['uploaded_at'] = $file['upload_time'] ?? '';
                }
            }
            
            // 更新 public（兼容 allow_download）
            $file['public'] = (bool)$public;
            // 移除舊的 allow_download 欄位（如果存在）
            if (isset($file['allow_download'])) {
                unset($file['allow_download']);
            }
            
            $fileFound = true;
            break;
        }
    }

    if (!$fileFound) {
        echo json_encode([
            "success" => false,
            "message" => "檔案不存在"
        ]);
        exit;
    }

    // 更新資料庫
    $updateStmt = $conn->prepare("
        UPDATE prosubdata
        SET prosub_other = ?,
            prosub_update_d = NOW()
        WHERE prosub_ID = ?
    ");
    
    $updateStmt->execute([
        json_encode($otherFilesJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $prosub_ID
    ]);

    $statusText = $public ? '已開放' : '已關閉';
    
    echo json_encode([
        "success" => true,
        "message" => "學生下載權限 {$statusText}"
    ]);

} catch (PDOException $e) {
    error_log('Toggle download error: ' . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "資料庫錯誤"
    ]);
} catch (Exception $e) {
    error_log('Toggle download error: ' . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "伺服器錯誤"
    ]);
}

