<?php
/**
 * 歷屆成果檔案下載驗證（安全版本）
 * 必須通過完整權限檢查才能下載檔案
 * 檔案存儲在 Web root 外的 storage 目錄
 */

session_start();
require 'includes/pdo.php';
require 'config/path.php'; // 引入 BASE_PATH 常量

// 1. 驗證用戶是否登入
if (!isset($_SESSION['u_ID'])) {
    http_response_code(401);
    die('請先登入');
}

// 2. 獲取參數（只接受 prosub_ID 和 fid，不接受 path 參數）
$prosub_ID = isset($_GET['prosub_ID']) ? (int)$_GET['prosub_ID'] : 0;
$fid = isset($_GET['fid']) ? trim($_GET['fid']) : '';

// 驗證參數
if (!$prosub_ID || !$fid) {
    http_response_code(400);
    die('參數錯誤');
}

// 驗證 fid 格式（支持 file_0, file_1 格式或 32 字符的 hex 字符串）
if (!preg_match('/^(file_\d+|[a-f0-9]{32})$/i', $fid)) {
    http_response_code(400);
    die('無效的檔案ID');
}

try {
    // 查詢成果資料
    $stmt = $conn->prepare("
        SELECT 
            ps.prosub_ID,
            ps.team_ID,
            ps.prosub_other,
            ps.prosub_status,
            pd.pro_status
        FROM prosubdata ps
        LEFT JOIN projectdata pd ON ps.pro_ID = pd.pro_ID
        WHERE ps.prosub_ID = ?
    ");
    $stmt->execute([$prosub_ID]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$submission) {
        http_response_code(404);
        die('成果不存在');
    }

    // 檢查成果狀態（必須是已審核通過的狀態）
    // prosub_status: 1=已審核通過, 3=已上架
    if (!in_array($submission['prosub_status'], [1, 3])) {
        http_response_code(403);
        die('此成果尚未審核通過');
    }

    // 檢查專題狀態
    if ($submission['pro_status'] !== null && $submission['pro_status'] != 1) {
        http_response_code(403);
        die('專題已停用');
    }

    // 從 prosub_other 讀取文件列表
    $targetFile = null;
    $fileIndex = null;
    
    // 如果 fid 是 file_0, file_1 格式，提取索引
    if (preg_match('/^file_(\d+)$/', $fid, $matches)) {
        $fileIndex = (int)$matches[1];
    }
    
    if ($submission['prosub_other']) {
        $otherFilesJson = json_decode($submission['prosub_other'], true);
        if (is_array($otherFilesJson)) {
            foreach ($otherFilesJson as $index => $file) {
                // 檢查是否匹配（通過索引或 fid）
                $matches = false;
                if ($fileIndex !== null && $index === $fileIndex) {
                    $matches = true;
                } elseif (is_array($file) && isset($file['fid']) && $file['fid'] === $fid) {
                    $matches = true;
                }
                
                if ($matches) {
                    // 處理新舊格式
                    if (is_string($file)) {
                        // 舊格式：字符串路徑（預設不開放，需要手動開啟）
                        $targetFile = [
                            'path' => $file,
                            'allow_download' => 0
                        ];
                    } elseif (is_array($file)) {
                        // 新格式：可能包含多種字段
                        if (isset($file['path'])) {
                            // 有 path 字段，直接使用
                            $targetFile = $file;
                        } elseif (isset($file['stored']) && isset($file['path'])) {
                            // 如果有 stored 和 path，組合完整路徑
                            $targetFile = $file;
                            $targetFile['path'] = rtrim($file['path'], '/') . '/' . $file['stored'];
                        } elseif (isset($file['name'])) {
                            // 如果只有 name，嘗試從 name 構建路徑
                            $fileName = $file['name'];
                            if (preg_match('/^other_\d+_\d+_\d+_[a-f0-9]+\./', $fileName)) {
                                // 格式：other_{team_ID}_{timestamp}_{index}_{uniqid}.{ext}
                                $targetFile = $file;
                                $targetFile['path'] = 'uploads/project_other_files/' . $fileName;
                            } else {
                                // 無法確定路徑，跳過
                                continue;
                            }
                        } else {
                            // 無法識別的格式，跳過
                            continue;
                        }
                    }
                    break;
                }
            }
        }
    }

    if (!$targetFile || !isset($targetFile['path'])) {
        http_response_code(404);
        die('檔案不存在');
    }

    // 🔹 【檢查下載權限】科辦在歷屆成果管理設為「不開放」的檔案類型（如 Word）不得下載
    // 與 get_archive_results 一致：只允許 allow_download = 1 的檔案
    $allowDownload = 0;
    if (isset($targetFile['allow_download'])) {
        $allowDownload = (int)$targetFile['allow_download'];
    } elseif (isset($targetFile['allowDownload'])) {
        $allowDownload = (int)$targetFile['allowDownload'];
    } elseif (isset($targetFile['allow'])) {
        $allowDownload = (int)$targetFile['allow'];
    } elseif (isset($targetFile['public']) && $targetFile['public']) {
        $allowDownload = 1;
    }
    
    if ($allowDownload != 1) {
        http_response_code(403);
        die('此檔案未開放，無法下載');
    }
    
    // 從 path 獲取相對路徑（資料庫存的是相對於專案根目錄的相對路徑）
    $relativePath = $targetFile['path'];
    
    if (empty($relativePath)) {
        http_response_code(404);
        die('檔案路徑為空');
    }
    
    // 清理相對路徑（移除開頭的 / 和 \，統一使用正斜線）
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/\\');
    
    // 安全檢查：確保路徑在 uploads/ 目錄內（防止路徑穿越）
    if (strpos($relativePath, 'uploads/') !== 0) {
        http_response_code(403);
        die('檔案路徑必須在 uploads/ 目錄內');
    }
    
    // 使用 BASE_PATH + 相對路徑組成實體路徑
    $absCandidate = BASE_PATH . '/' . $relativePath;
    
    // 先檢查檔案是否存在（避免 realpath 造成誤判）
    if (!file_exists($absCandidate)) {
        http_response_code(404);
        // 開發環境顯示詳細錯誤
        $errorMsg = '檔案不存在於伺服器';
        if (isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1'])) {
            $errorMsg .= "\nBASE_PATH=" . BASE_PATH;
            $errorMsg .= "\n相對路徑=" . $relativePath;
            $errorMsg .= "\n嘗試路徑=" . $absCandidate;
        }
        die($errorMsg);
    }
    
    // 檔案存在後才使用 realpath 標準化路徑
    $fullPath = realpath($absCandidate);
    if ($fullPath === false) {
        // 如果 realpath 失敗但檔案存在，使用原始路徑
        $fullPath = $absCandidate;
    }
    
    // 再次確認檔案必須位於 uploads/ 目錄內（防止路徑穿越）
    $uploadsPath = realpath(BASE_PATH . '/uploads');
    if ($uploadsPath === false) {
        http_response_code(500);
        die('uploads 目錄不存在');
    }
    
    // 標準化路徑進行比較
    $normalizedFullPath = str_replace('\\', '/', $fullPath);
    $normalizedUploadsPath = str_replace('\\', '/', $uploadsPath);
    
    if (strpos($normalizedFullPath, $normalizedUploadsPath) !== 0) {
        http_response_code(403);
        die('檔案路徑必須在 uploads/ 目錄內');
    }
    
    // 檢查檔案是否存在且為檔案（非目錄）
    if (!file_exists($fullPath)) {
        http_response_code(404);
        // 開發環境顯示詳細錯誤，生產環境只顯示簡單訊息
        $errorMsg = '檔案不存在於伺服器';
        if (isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1'])) {
            $errorMsg .= "\n相對路徑: " . $relativePath . "\n實體路徑: " . $fullPath;
        }
        die($errorMsg);
    }
    
    if (!is_file($fullPath)) {
        http_response_code(404);
        die('路徑不是檔案');
    }

    // 獲取檔案資訊
    $fileName = isset($targetFile['name']) ? $targetFile['name'] : basename($relativePath);
    $fileSize = filesize($fullPath);
    $mimeType = isset($targetFile['mime']) ? $targetFile['mime'] : 'application/octet-stream';

    // 如果沒有指定 MIME 類型，嘗試從檔案推斷
    if ($mimeType === 'application/octet-stream') {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $fullPath);
        finfo_close($finfo);
        if ($detectedMime) {
            $mimeType = $detectedMime;
        }
    }

    // 記錄下載（可選）
    // 這裡可以記錄下載日誌，例如記錄到資料庫

    // 設定 HTTP 標頭（安全下載）
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . addslashes($fileName) . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    // 清空輸出緩衝區
    if (ob_get_level()) {
        ob_clean();
    }
    flush();

    // 使用 readfile() 串流輸出，避免記憶體問題
    if (readfile($fullPath) === false) {
        http_response_code(500);
        die('讀取檔案失敗');
    }

    exit;

} catch (PDOException $e) {
    error_log('Download error: ' . $e->getMessage());
    http_response_code(500);
    die('伺服器錯誤');
} catch (Exception $e) {
    error_log('Download error: ' . $e->getMessage());
    http_response_code(500);
    die('伺服器錯誤');
}

