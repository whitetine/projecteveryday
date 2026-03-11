<?php
/**
 * 歷屆成果多檔案上傳 API
 * 用於科辦上傳歷屆成果的多個檔案
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

$u_ID = $_SESSION['u_ID'] ?? null;

// 獲取參數
$prosub_ID = isset($_POST['prosub_ID']) ? (int)$_POST['prosub_ID'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : 'upload';

try {
    if ($action === 'upload') {
        // ====== 上傳檔案 ======
        if (!$prosub_ID) {
            echo json_encode([
                "success" => false,
                "message" => "請指定成果ID"
            ]);
            exit;
        }

        // 查詢成果資料
        $stmt = $conn->prepare("
            SELECT 
                ps.prosub_ID,
                ps.team_ID,
                ps.content_json,
                t.cohort_ID
            FROM prosubdata ps
            INNER JOIN teamdata t ON ps.team_ID = t.team_ID
            WHERE ps.prosub_ID = ?
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

        // 獲取屆別年份（用於建立資料夾）
        $cohort_ID = $submission['cohort_ID'];
        $year = date('Y'); // 預設使用當前年份
        if ($cohort_ID) {
            $cohortStmt = $conn->prepare("SELECT year_label, cohort_name FROM cohortdata WHERE cohort_ID = ?");
            $cohortStmt->execute([$cohort_ID]);
            $cohort = $cohortStmt->fetch(PDO::FETCH_ASSOC);
            if ($cohort && $cohort['year_label']) {
                // 嘗試從 year_label 提取年份（例如 "110學年度" -> "110"）
                if (preg_match('/(\d{3})/', $cohort['year_label'], $matches)) {
                    $year = $matches[1];
                }
            }
        }

        $team_ID = $submission['team_ID'];

        // 解析現有的 content_json
        $contentJson = json_decode($submission['content_json'], true);
        if (!$contentJson || !is_array($contentJson)) {
            $contentJson = [];
        }

        // 確保 files 陣列存在
        if (!isset($contentJson['files']) || !is_array($contentJson['files'])) {
            $contentJson['files'] = [];
        }

        // 檢查是否有上傳的檔案
        if (!isset($_FILES['files']) || empty($_FILES['files']['name'])) {
            echo json_encode([
                "success" => false,
                "message" => "請選擇要上傳的檔案"
            ]);
            exit;
        }

        // 建立上傳資料夾：存儲到 Web root 外的 storage 目錄
        // Windows/XAMPP: C:/xampp/storage/history_files/
        // 或專案外層: ../storage/history_files/
        $storageBase = dirname(__DIR__, 2) . '/storage/history_files/';
        $uploadBaseDir = $storageBase . $year . '/' . $team_ID . '/';
        
        // 確保 storage 目錄存在
        if (!is_dir($storageBase) && !mkdir($storageBase, 0755, true) && !is_dir($storageBase)) {
            echo json_encode([
                "success" => false,
                "message" => "無法建立 storage 資料夾"
            ]);
            exit;
        }
        
        // 確保年份和團隊目錄存在
        if (!is_dir($uploadBaseDir) && !mkdir($uploadBaseDir, 0755, true) && !is_dir($uploadBaseDir)) {
            echo json_encode([
                "success" => false,
                "message" => "無法建立上傳資料夾"
            ]);
            exit;
        }

        // 處理多檔案上傳
        $uploadedFiles = [];
        $files = $_FILES['files'];
        $fileCount = is_array($files['name']) ? count($files['name']) : 1;

        for ($i = 0; $i < $fileCount; $i++) {
            $fileName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            $fileTmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $fileError = is_array($files['error']) ? $files['error'][$i] : $files['error'];
            $fileSize = is_array($files['size']) ? $files['size'][$i] : $files['size'];

            // 檢查上傳錯誤
            if ($fileError !== UPLOAD_ERR_OK) {
                continue;
            }

            // 檢查檔案大小（限制 100MB）
            $maxSize = 100 * 1024 * 1024; // 100MB
            if ($fileSize > $maxSize) {
                continue;
            }

            // 產生唯一檔案ID（使用不可預測的值，防止被猜到）
            $fid = bin2hex(random_bytes(16)); // 32 字符的 hex 字符串

            // 產生安全檔名
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $safeBase = preg_replace("/[^a-zA-Z0-9-_\.]/", "", pathinfo($fileName, PATHINFO_FILENAME));
            $storedName = $fid . '_' . $safeBase . '.' . $ext;

            // 移動檔案
            $targetPath = $uploadBaseDir . $storedName;
            if (!move_uploaded_file($fileTmp, $targetPath)) {
                continue;
            }

            // 獲取 MIME 類型
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $targetPath);
            finfo_close($finfo);

            // 建立檔案資訊
            // diskPath: 實體檔案完整路徑（不暴露給前端）
            $diskPath = $uploadBaseDir . $storedName;
            
            $fileInfo = [
                'fid' => $fid,
                'name' => $fileName,
                'stored' => $storedName,
                'diskPath' => $diskPath, // 實體路徑，僅後端使用，不返回前端
                'size' => $fileSize,
                'mime' => $mimeType ?: 'application/octet-stream',
                'uploaded_at' => date('Y-m-d H:i:s'),
                'allowDownload' => false, // 預設不允許下載
                'visible' => true
            ];
            
            // 返回給前端的檔案資訊（不包含 diskPath）
            $fileInfoForFrontend = [
                'fid' => $fid,
                'name' => $fileName,
                'stored' => $storedName,
                'size' => $fileSize,
                'mime' => $mimeType ?: 'application/octet-stream',
                'uploaded_at' => date('Y-m-d H:i:s'),
                'allowDownload' => false,
                'visible' => true
            ];

            // 添加到 files 陣列（包含 diskPath）
            $contentJson['files'][] = $fileInfo;
            // 返回給前端的資訊（不包含 diskPath）
            $uploadedFiles[] = $fileInfoForFrontend;
        }

        if (empty($uploadedFiles)) {
            echo json_encode([
                "success" => false,
                "message" => "沒有成功上傳任何檔案"
            ]);
            exit;
        }

        // 更新資料庫
        $updateStmt = $conn->prepare("
            UPDATE prosubdata
            SET content_json = ?,
                prosub_update_d = NOW()
            WHERE prosub_ID = ?
        ");
        
        $updateStmt->execute([
            json_encode($contentJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $prosub_ID
        ]);

        echo json_encode([
            "success" => true,
            "message" => "成功上傳 " . count($uploadedFiles) . " 個檔案",
            "files" => $uploadedFiles
        ]);

    } else {
        echo json_encode([
            "success" => false,
            "message" => "未知的操作"
        ]);
    }

} catch (PDOException $e) {
    error_log('Archive upload error: ' . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "資料庫錯誤"
    ]);
} catch (Exception $e) {
    error_log('Archive upload error: ' . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "伺服器錯誤: " . $e->getMessage()
    ]);
}

