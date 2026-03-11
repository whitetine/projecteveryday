<?php
/**
 * 測試 admin_usermanage_api.php
 * 用於直接測試 API 是否正常運作
 */

session_start();
require __DIR__ . '/includes/pdo.php';
require __DIR__ . '/includes/utils.php';

// 模擬登入狀態（測試用）
$_SESSION['u_ID'] = 'test';
$_SESSION['role_ID'] = 1; // 管理員角色
$_SESSION['role_name'] = '主任';

// 設置 GET 參數
$_GET['do'] = 'get_user_manage_data';

// 暫時開啟錯誤顯示
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "<h2>測試 admin_usermanage_api.php</h2>";
echo "<pre>";

try {
    // 直接包含 API 檔案
    ob_start();
    include __DIR__ . '/modules/admin_usermanage_api.php';
    $output = ob_get_clean();
    
    if ($output) {
        echo "輸出內容:\n";
        echo htmlspecialchars($output);
    } else {
        echo "沒有輸出（可能已經 exit）\n";
    }
} catch (Exception $e) {
    echo "捕獲到 Exception:\n";
    echo "訊息: " . $e->getMessage() . "\n";
    echo "檔案: " . $e->getFile() . "\n";
    echo "行號: " . $e->getLine() . "\n";
    echo "堆疊追蹤:\n" . $e->getTraceAsString() . "\n";
} catch (Error $e) {
    echo "捕獲到 Error:\n";
    echo "訊息: " . $e->getMessage() . "\n";
    echo "檔案: " . $e->getFile() . "\n";
    echo "行號: " . $e->getLine() . "\n";
    echo "堆疊追蹤:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";

