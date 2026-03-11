<?php
/**
 * 測試 recognize_form_questions API
 * 用於查看實際的錯誤訊息
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

session_start();

echo "<h2>測試 recognize_form_questions API</h2>";
echo "<pre>";

// 模擬 GET 參數
$_GET['do'] = 'recognize_form_questions';

echo "1. Session 檢查\n";
echo "   u_ID: " . ($_SESSION['u_ID'] ?? '未設定') . "\n";
echo "   role_ID: " . ($_SESSION['role_ID'] ?? '未設定') . "\n";
echo "\n";

echo "2. 檢查 FILES\n";
echo "   \$_FILES: " . print_r($_FILES, true) . "\n";
echo "\n";

echo "3. 模擬 API 調用（會因為沒有文件而失敗）\n";
echo str_repeat("=", 50) . "\n";

// 捕獲輸出
ob_start();
try {
    // 先載入必要的檔案
    require __DIR__ . '/includes/pdo.php';
    require __DIR__ . '/includes/utils.php';
    
    // 模擬調用
    require __DIR__ . '/modules/form.php';
    
    $output = ob_get_clean();
    echo "輸出內容：\n";
    echo $output . "\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "發生異常：\n";
    echo "錯誤訊息: " . $e->getMessage() . "\n";
    echo "檔案: " . $e->getFile() . "\n";
    echo "行號: " . $e->getLine() . "\n";
    echo "堆疊追蹤:\n" . $e->getTraceAsString() . "\n";
}

echo "\n\n";
echo "4. 檢查錯誤日誌位置\n";
$errorLog = ini_get('error_log');
if ($errorLog) {
    echo "   PHP error_log: $errorLog\n";
} else {
    echo "   PHP error_log: 使用系統預設位置\n";
    echo "   常見位置：\n";
    echo "   - C:\\xampp\\apache\\logs\\error.log\n";
    echo "   - C:\\wamp\\logs\\php_error.log\n";
}
echo "\n";

echo "5. 建議\n";
echo "   請查看 PHP 錯誤日誌，尋找包含 'recognize_form_questions' 的錯誤訊息\n";
echo "   或查看瀏覽器控制台的 Network 標籤，查看實際的 API 回應\n";
echo "\n";

echo "</pre>";

