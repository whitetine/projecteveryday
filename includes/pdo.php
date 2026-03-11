<?php
// 統一時區：PHP 與 MySQL 皆使用 Asia/Taipei，避免表單開放/截止時間比對錯誤
date_default_timezone_set('Asia/Taipei');

// 防止重複包含
if (!isset($pdo_included)) {
    $pdo_included = true;
    
    $conn=new PDO("mysql:host=localhost;dbname=projecteverydays","root","");
    // 現在密碼是預設的，如果有改資料庫密碼要記得改
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("SET NAMES utf8mb4");
    $conn->exec("SET time_zone = '+08:00'");
    
    if (!function_exists('query')) {
        function query($res){
            global $conn;
            return $conn->query($res);
        }
    }
    
    if (!function_exists('fetch')) {
        function fetch($res){
            return $res->fetch(2);
        }
    }
    
    if (!function_exists('fetchAll')) {
        function fetchAll($res){
            return $res->fetchAll(2);
        }
    }
    
    if (!function_exists('rowCount')) {
        function rowCount($res){
            return $res->rowCount();
        }
    }
}
?>

<?php
// 防止重複包含
if (!isset($pdo_included)) {
    $pdo_included = true;
    
    // ▼▼▼ 修改這裡 ▼▼▼
    
    // 1. 將 'sqlXXX.infinityfree.com' 換成後台顯示的 "MySQL Host Name"
    // 2. 將 'if0_xxxxxx_projecteverydays' 換成後台顯示的完整 "Database Name"
    $db_host = "sql204.infinityfree.com"; 
    $db_name = "if0_40826548_projecteverydays";
    
    // 3. 將 'if0_xxxxxx' 換成後台顯示的 "MySQL User Name"
    $db_user = "if0_40826548";
    
    // 4. 輸入您的 vPanel 密碼 (注意：不是登入 InfinityFree 官網的密碼，是 Hosting 帳號密碼)
    $db_pass = "YUjpAWpjzcS6";

    try {
        // 組合連線字串
        $conn = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
        
        // 設定錯誤模式
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 設定編碼 (防止中文亂碼) - 強烈建議加上這行
        $conn->exec("set names utf8mb4");
        // 與 PHP 一致使用 Asia/Taipei，讓 NOW() 與 date() 比對正確
        $conn->exec("SET time_zone = '+08:00'");
        
    } catch(PDOException $e) {
        // 如果連線失敗，顯示錯誤訊息
        die("資料庫連線失敗: " . $e->getMessage());
    }
    
    // ▲▲▲ 修改結束 ▲▲▲

    if (!function_exists('query')) {
        function query($res){
            global $conn;
            return $conn->query($res);
        }
    }
    
    if (!function_exists('fetch')) {
        function fetch($res){
            return $res->fetch(PDO::FETCH_BOTH); // 註：原始碼寫 fetch(2) 也是對的，對應 PDO::FETCH_BOTH
        }
    }
    
    if (!function_exists('fetchAll')) {
        function fetchAll($res){
            return $res->fetchAll(PDO::FETCH_BOTH);
        }
    }
    
    if (!function_exists('rowCount')) {
        function rowCount($res){
            return $res->rowCount();
        }
    }
}
require_once __DIR__ . '/document_submissions_columns.php';
?>
<?php
// 防止重複包含
// if (!isset($pdo_included)) {
//     $pdo_included = true;
    
//     $conn=new PDO("mysql:host=localhost;dbname=projecteverydays","root","");
//     // 現在密碼是預設的，如果有改資料庫密碼要記得改
//     $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
//     if (!function_exists('query')) {
//         function query($res){
//             global $conn;
//             return $conn->query($res);
//         }
//     }
    
//     if (!function_exists('fetch')) {
//         function fetch($res){
//             return $res->fetch(2);
//         }
//     }
    
//     if (!function_exists('fetchAll')) {
//         function fetchAll($res){
//             return $res->fetchAll(2);
//         }
//     }
    
//     if (!function_exists('rowCount')) {
//         function rowCount($res){
//             return $res->rowCount();
//         }
//     }
// }
?>