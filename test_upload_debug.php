<?php
/**
 * 測試文件上傳和 API 調用
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

echo "<h2>文件上傳和 API 調用測試</h2>";
echo "<pre>";

// 1. 檢查 Session
echo "1. Session 檢查\n";
echo "   Session ID: " . session_id() . "\n";
echo "   u_ID: " . ($_SESSION['u_ID'] ?? '未設定') . "\n";
echo "   role_ID: " . ($_SESSION['role_ID'] ?? '未設定') . "\n";
echo "\n";

// 2. 檢查 PHP 上傳設定
echo "2. PHP 上傳設定\n";
echo "   upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "   post_max_size: " . ini_get('post_max_size') . "\n";
echo "   max_file_uploads: " . ini_get('max_file_uploads') . "\n";
echo "   file_uploads: " . (ini_get('file_uploads') ? '啟用' : '停用') . "\n";
echo "\n";

// 3. 檢查資料庫連線
echo "3. 資料庫連線\n";
try {
    require __DIR__ . '/includes/pdo.php';
    global $conn;
    if (isset($conn)) {
        echo "   ✓ 資料庫連線成功\n";
    } else {
        echo "   ✗ 資料庫連線失敗\n";
    }
} catch (Exception $e) {
    echo "   ✗ 資料庫連線錯誤: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. 檢查權限
echo "4. 權限檢查\n";
if (isset($_SESSION['u_ID'])) {
    try {
        $u_ID = $_SESSION['u_ID'];
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM userrolesdata 
            WHERE ur_u_ID = ? AND role_ID IN (1, 2) AND user_role_status = 1
        ");
        $stmt->execute([$u_ID]);
        $count = $stmt->fetchColumn();
        echo "   u_ID: $u_ID\n";
        echo "   符合權限的記錄數: $count\n";
        if ($count > 0) {
            echo "   ✓ 有管理員權限\n";
        } else {
            echo "   ✗ 沒有管理員權限（需要 role_ID = 1 或 2）\n";
        }
    } catch (Exception $e) {
        echo "   ✗ 權限查詢錯誤: " . $e->getMessage() . "\n";
    }
} else {
    echo "   ✗ Session 中沒有 u_ID，請先登入\n";
}
echo "\n";

// 5. 檢查 Composer 依賴
echo "5. Composer 依賴檢查\n";
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    try {
        require_once __DIR__ . '/vendor/autoload.php';
        if (class_exists('\Smalot\PdfParser\Parser')) {
            echo "   ✓ smalot/pdfparser 已安裝\n";
        } else {
            echo "   ✗ smalot/pdfparser 未正確安裝\n";
        }
    } catch (Exception $e) {
        echo "   ⚠ Composer 自動載入失敗: " . $e->getMessage() . "\n";
        echo "   （這不會影響基本功能，但會影響 PDF 解析）\n";
    }
} else {
    echo "   ⚠ vendor/autoload.php 不存在\n";
    echo "   （這不會影響基本功能，但會影響 PDF 解析）\n";
}
echo "\n";

// 6. 測試 API 端點（模擬）
echo "6. API 端點測試\n";
echo "   請在瀏覽器中訪問以下 URL 測試：\n";
echo "   - http://localhost/original/projecteveryday/api.php?do=get_forms\n";
echo "   - http://localhost/original/projecteveryday/api.php?do=get_form_flows\n";
echo "\n";

// 7. 檢查錯誤日誌位置
echo "7. 錯誤日誌位置\n";
$errorLog = ini_get('error_log');
if ($errorLog) {
    echo "   PHP error_log: $errorLog\n";
} else {
    echo "   PHP error_log: 使用系統預設位置\n";
}
echo "   常見位置：\n";
echo "   - C:\\xampp\\apache\\logs\\error.log\n";
echo "   - C:\\wamp\\logs\\php_error.log\n";
echo "\n";

echo "診斷完成！\n";
echo "</pre>";

