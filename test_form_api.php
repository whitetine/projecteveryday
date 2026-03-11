<?php
/**
 * 表單 API 診斷腳本
 * 用於檢查 api.php?do=get_forms 和 get_form_flows 的問題
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>表單 API 診斷</h2>";
echo "<pre>";

// 1. 檢查 session
echo "1. 檢查 Session...\n";
session_start();
echo "   Session ID: " . session_id() . "\n";
echo "   u_ID: " . ($_SESSION['u_ID'] ?? '未設定') . "\n";
echo "   role_ID: " . ($_SESSION['role_ID'] ?? '未設定') . "\n";
echo "\n";

// 2. 檢查資料庫連線
echo "2. 檢查資料庫連線...\n";
try {
    require __DIR__ . '/includes/pdo.php';
    global $conn;
    
    if (isset($conn)) {
        echo "   ✓ 資料庫連線成功\n";
        
        // 測試查詢
        $test = $conn->query("SELECT 1");
        echo "   ✓ 資料庫查詢正常\n";
    } else {
        echo "   ✗ 資料庫連線失敗：\$conn 未設定\n";
        exit;
    }
} catch (Exception $e) {
    echo "   ✗ 資料庫連線錯誤：" . $e->getMessage() . "\n";
    exit;
}
echo "\n";

// 3. 檢查必要的資料表
echo "3. 檢查資料表...\n";
$tables = ['formdata', 'formflowdata', 'userrolesdata', 'roledata'];
foreach ($tables as $table) {
    try {
        $stmt = $conn->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "   ✓ 資料表 '$table' 存在\n";
        } else {
            echo "   ✗ 資料表 '$table' 不存在\n";
        }
    } catch (Exception $e) {
        echo "   ✗ 檢查資料表 '$table' 時出錯：" . $e->getMessage() . "\n";
    }
}
echo "\n";

// 4. 檢查 formdata 表結構
echo "4. 檢查 formdata 表結構...\n";
try {
    $stmt = $conn->query("DESCRIBE formdata");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "   欄位列表：\n";
    foreach ($columns as $col) {
        echo "     - {$col['Field']} ({$col['Type']})\n";
    }
} catch (Exception $e) {
    echo "   ✗ 無法讀取 formdata 表結構：" . $e->getMessage() . "\n";
}
echo "\n";

// 5. 檢查 formflowdata 表結構
echo "5. 檢查 formflowdata 表結構...\n";
try {
    $stmt = $conn->query("DESCRIBE formflowdata");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "   欄位列表：\n";
    foreach ($columns as $col) {
        echo "     - {$col['Field']} ({$col['Type']})\n";
    }
} catch (Exception $e) {
    echo "   ✗ 無法讀取 formflowdata 表結構：" . $e->getMessage() . "\n";
}
echo "\n";

// 6. 測試 get_forms 查詢
echo "6. 測試 get_forms 查詢...\n";
try {
    $sql = "
        SELECT 
            form_ID, form_name, form_des, form_category, form_status,
            form_start_d, form_end_d, form_created_d, form_updated_d,
            form_created_u_ID, form_updated_u_ID
        FROM formdata
        ORDER BY form_created_d DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "   ✓ 查詢成功，找到 " . count($forms) . " 筆記錄\n";
} catch (Exception $e) {
    echo "   ✗ 查詢失敗：" . $e->getMessage() . "\n";
}
echo "\n";

// 7. 測試 get_form_flows 查詢
echo "7. 測試 get_form_flows 查詢...\n";
try {
    $sql = "
        SELECT 
            ff.ff_ID,
            ff.ff_order,
            ff.form_ID,
            ff.ff_name,
            ff.ff_enabled,
            f.form_name
        FROM formflowdata ff
        LEFT JOIN formdata f ON ff.form_ID = f.form_ID
        ORDER BY ff.ff_order ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $flows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "   ✓ 查詢成功，找到 " . count($flows) . " 筆記錄\n";
} catch (Exception $e) {
    echo "   ✗ 查詢失敗：" . $e->getMessage() . "\n";
}
echo "\n";

// 8. 檢查權限查詢
echo "8. 測試權限查詢...\n";
try {
    $u_ID = $_SESSION['u_ID'] ?? null;
    if ($u_ID) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM userrolesdata 
            WHERE ur_u_ID = ? AND role_ID IN (1, 2) AND user_role_status = 1
        ");
        $stmt->execute([$u_ID]);
        $count = $stmt->fetchColumn();
        echo "   ✓ 權限查詢成功，找到 $count 筆符合的記錄\n";
    } else {
        echo "   ⚠ Session 中沒有 u_ID，無法測試權限查詢\n";
    }
} catch (Exception $e) {
    echo "   ✗ 權限查詢失敗：" . $e->getMessage() . "\n";
}
echo "\n";

// 9. 檢查 includes/utils.php
echo "9. 檢查 includes/utils.php...\n";
if (file_exists(__DIR__ . '/includes/utils.php')) {
    require_once __DIR__ . '/includes/utils.php';
    echo "   ✓ utils.php 載入成功\n";
    echo "   ✓ json_ok 函數存在：" . (function_exists('json_ok') ? '是' : '否') . "\n";
    echo "   ✓ json_err 函數存在：" . (function_exists('json_err') ? '是' : '否') . "\n";
} else {
    echo "   ✗ utils.php 不存在\n";
}
echo "\n";

// 10. 測試實際 API 呼叫
echo "10. 測試實際 API 呼叫...\n";
echo "    請訪問以下 URL 查看實際錯誤：\n";
echo "    - http://localhost/original/projecteveryday/api.php?do=get_forms\n";
echo "    - http://localhost/original/projecteveryday/api.php?do=get_form_flows\n";
echo "\n";

echo "診斷完成！\n";
echo "</pre>";

