<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once "../../includes/pdo.php";// 這裡會給你 $conn (PDO)

try {
    //讀取表單資料
    $file_ID     = $_POST['file_ID'] ?? '';
    $apply_user_input = $_POST['apply_user'] ?? '';
    $apply_other = $_POST['apply_other'] ?? '';
    $file        = $_FILES['apply_image'] ?? null;
    // 支援兩種方式：mode 參數或 overwrite 參數
    $mode = $_POST['mode'] ?? '';
    $overwrite = ($mode === 'overwrite') || (isset($_POST['overwrite']) && $_POST['overwrite'] === '1'); // 是否覆蓋標誌

    // 🔹 優先使用 session 中的 u_ID，確保是有效的用戶ID
    $apply_user = $_SESSION['u_ID'] ?? '';
    
    // 如果 session 沒有 u_ID，嘗試從 POST 取得
    if (empty($apply_user) && !empty($apply_user_input)) {
        // 檢查是否為有效的 u_ID（查詢 userdata 表）
        $checkStmt = $conn->prepare("SELECT u_ID FROM userdata WHERE u_ID = ? LIMIT 1");
        $checkStmt->execute([$apply_user_input]);
        $userRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if ($userRow) {
            $apply_user = $apply_user_input;
        } else {
            // 如果不是有效的ID，嘗試用名稱查詢
            $checkStmt = $conn->prepare("SELECT u_ID FROM userdata WHERE u_name = ? LIMIT 1");
            $checkStmt->execute([$apply_user_input]);
            $userRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if ($userRow) {
                $apply_user = $userRow['u_ID'];
            }
        }
    }

    if (empty($file_ID) || empty($apply_user)) {
        echo json_encode(["status" => "error", "message" => "請完整填寫欄位，或請先登入"]);
        exit;
    }

    // 🔹 獲取用戶的團隊ID
    $team_ID = null;
    try {
        // 檢查 teammember 表使用哪個欄位名稱
        $teamUserField = 'team_u_ID';
        $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
        $checkStmt->execute();
        if (!$checkStmt->fetch()) {
            $teamUserField = 'u_ID';
        }
        
        // 獲取用戶所屬的團隊
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
        $teamStmt->execute([$apply_user]);
        $teamRow = $teamStmt->fetch(PDO::FETCH_ASSOC);
        if ($teamRow && !empty($teamRow['team_ID'])) {
            $team_ID = (int)$teamRow['team_ID'];
        }
    } catch (Throwable $e) {
        // 如果查詢失敗，team_ID 保持為 null
        error_log("獲取團隊ID失敗: " . $e->getMessage());
    }

    // 檢查檔案是否上傳
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(["status" => "error", "message" => "請上傳圖檔"]);
        exit;
    }

    //檢查檔名
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowedExt)) {
        echo json_encode(["status" => "error", "message" => "僅允許上傳 PNG、JPG 圖檔"]);
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowedMime = ['image/jpeg', 'image/png'];
    if (!in_array($mime, $allowedMime)) {
        echo json_encode(["status" => "error", "message" => "檔案格式不正確"]);
        exit;
    }

    // 🔹 檢查是否已有相同 doc_ID 和團隊的上傳記錄
    // 重要：只有當該文件類型已被同組組員提交過時，才會提示防呆
    // 如果是新文件（還沒有人填過），$existingRecord 會是 null，不會跳提示，直接正常上傳
    // 注意：需要處理 dcsub_team_ID 為 NULL 的情況（舊資料），通過 dcsub_u_ID 匹配團隊成員
    // 同時也要檢查用戶自己之前上傳的記錄
    $existingRecord = null;
    
    // 先獲取團隊成員列表（包括當前用戶）
    $teamMemberIds = [];
    if ($team_ID !== null) {
        try {
            // 檢查 teammember 表使用哪個欄位名稱
            $teamUserField = 'team_u_ID';
            $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
            $checkStmt->execute();
            if (!$checkStmt->fetch()) {
                $teamUserField = 'u_ID';
            }
            
            $memberStmt = $conn->prepare("
                SELECT {$teamUserField} as u_ID
                FROM teammember
                WHERE team_ID = ? AND (tm_status = 1 OR tm_status IS NULL)
            ");
            $memberStmt->execute([$team_ID]);
            $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
            $teamMemberIds = array_column($members, 'u_ID');
            // 確保當前用戶也在列表中（即使查詢失敗也能檢測到自己的記錄）
            if (!in_array($apply_user, $teamMemberIds)) {
                $teamMemberIds[] = $apply_user;
            }
        } catch (Throwable $e) {
            error_log("獲取團隊成員失敗: " . $e->getMessage());
            // 如果查詢失敗，至少包含當前用戶
            $teamMemberIds = [$apply_user];
        }
    } else {
        // 如果沒有團隊，至少檢查用戶自己之前上傳的記錄
        $teamMemberIds = [$apply_user];
    }
    
    // 查詢條件：匹配 dcsub_team_ID 或通過 dcsub_u_ID 匹配團隊成員（包括用戶自己）
    if (!empty($teamMemberIds)) {
        $memberPlaceholders = implode(',', array_fill(0, count($teamMemberIds), '?'));
        
        if ($team_ID !== null) {
            // 有團隊：檢查團隊ID匹配或成員ID匹配
            $checkExistingStmt = $conn->prepare("
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
            $params = array_merge([$file_ID, $team_ID], $teamMemberIds);
        } else {
            // 沒有團隊：只檢查用戶自己之前上傳的記錄
            $checkExistingStmt = $conn->prepare("
                SELECT ds.sub_ID, ds.dcsub_url, ds.dcsub_sub_d, ds.dcsub_u_ID, u.u_name as uploader_name
                FROM docsubdata ds
                LEFT JOIN userdata u ON ds.dcsub_u_ID = u.u_ID
                WHERE ds.doc_ID = ? AND ds.dcsub_u_ID = ?
                ORDER BY ds.dcsub_sub_d DESC
                LIMIT 1
            ");
            $params = [$file_ID, $apply_user];
        }
        
        $checkExistingStmt->execute($params);
        $existingRecord = $checkExistingStmt->fetch(PDO::FETCH_ASSOC);
        
        // 調試：記錄查詢結果
        if ($existingRecord) {
            error_log("找到重複記錄 - doc_ID: {$file_ID}, team_ID: " . ($team_ID ?? 'NULL') . ", sub_ID: {$existingRecord['sub_ID']}, uploader: " . ($existingRecord['uploader_name'] ?? '未知') . ", uploader_u_ID: {$existingRecord['dcsub_u_ID']}");
        } else {
            error_log("未找到重複記錄 - doc_ID: {$file_ID}, team_ID: " . ($team_ID ?? 'NULL') . ", teamMemberCount: " . count($teamMemberIds) . ", apply_user: {$apply_user}");
        }
    }

    // 🔹 最後防線：如果已有記錄且未確認覆蓋，返回錯誤（符合前端要求的格式）
    // 注意：只有當 $existingRecord 不為 null 時（即找到記錄）才會進入此判斷
    // 如果沒有找到記錄（新文件），會跳過此判斷，繼續執行後面的插入新記錄邏輯
    if ($existingRecord && !$overwrite) {
        // 返回符合前端要求的格式
        echo json_encode([
            "ok" => false,
            "code" => "DUPLICATE_SUBMIT",
            "message" => "此文件本組已繳交",
            "status" => "duplicate",  // 保留舊格式以兼容舊代碼
            "existing" => [
                "sub_ID" => $existingRecord['sub_ID'],
                "upload_time" => $existingRecord['dcsub_sub_d'],
                "uploader_name" => $existingRecord['uploader_name'] ?? '未知',
                "file_url" => $existingRecord['dcsub_url']
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 建立上傳資料夾
    $uploadDir = dirname( __DIR__, 2) . '/uploads/images/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        echo json_encode(["status" => "error", "message" => "無法建立上傳資料夾"]);
        exit;
    }


    // 儲存檔案
    $newName = uniqid('img_') . '.' . $ext;
    $savePath = $uploadDir . $newName;
    $dbPath   = 'uploads/images/' . $newName; 

    if (!move_uploaded_file($file['tmp_name'], $savePath)) {
        echo json_encode(["status" => "error", "message" => "檔案儲存失敗"]);
        exit;
    }

    // 🔹 如果已有記錄且確認覆蓋，則更新現有記錄並刪除舊檔案
    if ($existingRecord && $overwrite) {
        // 刪除舊檔案
        $oldFilePath = dirname(__DIR__, 2) . '/' . $existingRecord['dcsub_url'];
        if (file_exists($oldFilePath) && is_file($oldFilePath)) {
            @unlink($oldFilePath);
        }
        
        // 更新現有記錄
        $sql = "
            UPDATE docsubdata
            SET dcsub_u_ID = ?,
                dcsub_comment = ?,
                dcsub_url = ?,
                dcsub_sub_d = NOW(),
                dcsub_status = 0
            WHERE sub_ID = ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$apply_user, $apply_other, $dbPath, $existingRecord['sub_ID']]);
        
        echo json_encode([
            "status"   => "success",
            "message"  => "文件已成功覆蓋！",
            "apply_ID" => $existingRecord['sub_ID']
        ]);
    } else {
        // 🔹 插入新記錄到 docsubdata 表
        // 此處處理兩種情況：
        // 1. 新文件（$existingRecord 為 null）：正常插入新記錄，不跳提示
        // 2. 已有記錄但未確認覆蓋：理論上不會執行到這裡（因為前面已經 exit）
        
        // 🔹 最後防線：在插入前再次檢查（防止並發提交）
        if (!$overwrite) {
            $finalCheckStmt = null;
            if ($team_ID !== null && !empty($teamMemberIds)) {
                $memberPlaceholders = implode(',', array_fill(0, count($teamMemberIds), '?'));
                $finalCheckStmt = $conn->prepare("
                    SELECT sub_ID
                    FROM docsubdata
                    WHERE doc_ID = ?
                      AND (
                          dcsub_team_ID = ?
                          OR (dcsub_team_ID IS NULL AND dcsub_u_ID IN ($memberPlaceholders))
                      )
                    LIMIT 1
                ");
                $finalParams = array_merge([$file_ID, $team_ID], $teamMemberIds);
                $finalCheckStmt->execute($finalParams);
            } else {
                $finalCheckStmt = $conn->prepare("
                    SELECT sub_ID
                    FROM docsubdata
                    WHERE doc_ID = ? AND dcsub_u_ID = ?
                    LIMIT 1
                ");
                $finalCheckStmt->execute([$file_ID, $apply_user]);
            }
            
            $finalCheck = $finalCheckStmt->fetch(PDO::FETCH_ASSOC);
            if ($finalCheck) {
                // 發現重複，返回錯誤（刪除已上傳的檔案）
                @unlink($savePath);
                echo json_encode([
                    "ok" => false,
                    "code" => "DUPLICATE_SUBMIT",
                    "message" => "此文件本組已繳交"
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        
        $sql = "
            INSERT INTO docsubdata
              (doc_ID, dcsub_team_ID, dcsub_u_ID, dcsub_comment, dcsub_url, dcsub_sub_d, dc_approved_u_ID, dcsub_approved_d, dcsub_remark, dcsub_status)
            VALUES
              (?, ?, ?, ?, ?, NOW(), NULL, NULL, NULL, 0)
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$file_ID, $team_ID, $apply_user, $apply_other, $dbPath]);
        echo json_encode([
            "status"   => "success",
            "message"  => "申請已成功送出！",
            "apply_ID" => $conn->lastInsertId() // 方便前端使用
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => '伺服器錯誤：' . $e->getMessage()]);
}