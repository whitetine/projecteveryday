<?php
/**
 * 一次性清理 prosub_other JSON 字段
 * 
 * 功能：
 * 1. 移除重複的 path
 * 2. 統一格式，確保所有項目都有 original_name
 * 3. 將舊格式轉換為新格式
 * 
 * 使用方法：
 * 在瀏覽器訪問：http://localhost/scripts/clean_prosub_other.php
 * 或使用命令行：php scripts/clean_prosub_other.php
 */

// 載入資料庫連接
require_once __DIR__ . '/../includes/pdo.php';
require_once __DIR__ . '/../config/path.php';

try {
    
    // 查詢所有有 prosub_other 的記錄
    $stmt = $conn->query("SELECT prosub_ID, prosub_other FROM prosubdata WHERE prosub_other IS NOT NULL AND prosub_other != ''");
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalRecords = count($records);
    $cleanedCount = 0;
    $errors = [];
    
    echo "<h2>清理 prosub_other JSON 字段</h2>";
    echo "<p>找到 {$totalRecords} 筆記錄需要檢查</p>";
    echo "<hr>";
    
    foreach ($records as $record) {
        $prosub_ID = $record['prosub_ID'];
        $prosub_other = $record['prosub_other'];
        
        if (empty($prosub_other)) {
            continue;
        }
        
        $filesJson = json_decode($prosub_other, true);
        
        if (!is_array($filesJson)) {
            echo "<p style='color: orange;'>記錄 #{$prosub_ID}: JSON 格式錯誤，跳過</p>";
            continue;
        }
        
        $originalCount = count($filesJson);
        
        // 使用 path 作為唯一鍵進行去重和格式統一
        $cleanedFilesMap = [];
        
        foreach ($filesJson as $file) {
            // 處理不同格式
            $filePath = '';
            $originalName = '';
            
            if (is_string($file)) {
                // 舊格式：字符串路徑
                $filePath = $file;
                $originalName = basename($filePath);
            } elseif (is_array($file)) {
                // 新格式或部分格式
                $filePath = $file['path'] ?? '';
                $originalName = $file['original_name'] ?? $file['name'] ?? basename($filePath);
            }
            
            // 如果沒有 path，跳過
            if (empty($filePath)) {
                continue;
            }
            
            // 去重：如果 path 已存在，跳過（保留第一個）
            if (isset($cleanedFilesMap[$filePath])) {
                continue;
            }
            
            // 統一格式，確保有 original_name
            $cleanedFilesMap[$filePath] = [
                'original_name' => $originalName ?: basename($filePath),
                'name' => $originalName ?: basename($filePath),
                'path' => $filePath,
                'type' => is_array($file) ? ($file['type'] ?? '') : '',
                'uploaded_at' => is_array($file) ? ($file['uploaded_at'] ?? $file['upload_time'] ?? '') : '',
                'public' => is_array($file) ? (isset($file['public']) ? (bool)$file['public'] : true) : true
            ];
        }
        
        $cleanedFiles = array_values($cleanedFilesMap);
        $cleanedCount = count($cleanedFiles);
        
        // 如果沒有變化，跳過更新
        if ($originalCount === $cleanedCount && $originalCount > 0) {
            // 檢查格式是否統一（是否有 original_name）
            $needsUpdate = false;
            foreach ($filesJson as $file) {
                if (is_string($file) || (is_array($file) && !isset($file['original_name']))) {
                    $needsUpdate = true;
                    break;
                }
            }
            
            if (!$needsUpdate) {
                echo "<p style='color: gray;'>記錄 #{$prosub_ID}: 無需更新（{$originalCount} 個檔案，格式正確）</p>";
                continue;
            }
        }
        
        // 更新資料庫
        $newJson = !empty($cleanedFiles) ? json_encode($cleanedFiles, JSON_UNESCAPED_UNICODE) : null;
        
        try {
            $updateStmt = $conn->prepare("UPDATE prosubdata SET prosub_other = ? WHERE prosub_ID = ?");
            $updateStmt->execute([$newJson, $prosub_ID]);
            
            $status = $originalCount > $cleanedCount ? 'color: red;' : 'color: green;';
            echo "<p style='{$status}'>記錄 #{$prosub_ID}: 已清理（{$originalCount} → {$cleanedCount} 個檔案）</p>";
            $cleanedCount++;
        } catch (Exception $e) {
            $errorMsg = "記錄 #{$prosub_ID}: 更新失敗 - " . $e->getMessage();
            echo "<p style='color: red;'>{$errorMsg}</p>";
            $errors[] = $errorMsg;
        }
    }
    
    echo "<hr>";
    echo "<h3>清理完成</h3>";
    echo "<p>總共處理 {$totalRecords} 筆記錄，成功清理 {$cleanedCount} 筆</p>";
    
    if (!empty($errors)) {
        echo "<h4>錯誤列表：</h4>";
        echo "<ul>";
        foreach ($errors as $error) {
            echo "<li style='color: red;'>{$error}</li>";
        }
        echo "</ul>";
    }
    
    echo "<p><a href='../pages/project_upload.php?edit=1'>返回編輯頁面測試</a></p>";
    
} catch (Exception $e) {
    echo "<h3 style='color: red;'>錯誤</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}

