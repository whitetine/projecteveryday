<?php
/**
 * 直接測試 API 端點
 * 用於查看實際的錯誤訊息
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h2>直接測試 API 端點</h2>";
echo "<pre>";

// 模擬 GET 參數
$_GET['do'] = 'get_forms';

echo "測試 1: get_forms\n";
echo str_repeat("=", 50) . "\n";

// 捕獲輸出
ob_start();
try {
    require __DIR__ . '/api.php';
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

// 測試 get_form_flows
$_GET['do'] = 'get_form_flows';

echo "測試 2: get_form_flows\n";
echo str_repeat("=", 50) . "\n";

ob_start();
try {
    require __DIR__ . '/api.php';
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

echo "</pre>";

