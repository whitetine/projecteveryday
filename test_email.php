<?php
/**
 * 測試郵件發送功能
 * 用於診斷忘記密碼功能中的 Gmail 發送問題
 */

require_once "includes/mail_sender.php";

echo "<h2>郵件發送功能診斷</h2>";
echo "<hr>";

// 測試 0: 檢查 PHPMailer
echo "<h3>0. PHPMailer 檢查</h3>";
$phpmailer_paths = [
    __DIR__ . '/PHPMailer/src/PHPMailer.php',
    __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php'
];

$phpmailer_found = false;
foreach ($phpmailer_paths as $path) {
    if (file_exists($path)) {
        echo "✓ PHPMailer 已安裝：{$path}<br>";
        $phpmailer_found = true;
        break;
    }
}

if (!$phpmailer_found) {
    echo "✗ PHPMailer 未找到。請參考 PHPMailer_安裝說明.md 進行安裝。<br>";
}
echo "<hr>";

// 測試 1: 檢查 PHP 設定
echo "<h3>1. PHP 設定檢查</h3>";
echo "allow_url_fopen: " . (ini_get('allow_url_fopen') ? '✓ 已啟用' : '✗ 未啟用') . "<br>";
echo "PHP Version: " . phpversion() . "<br>";
echo "<hr>";

// 測試 2: 檢查郵件設定
echo "<h3>2. 郵件設定檢查</h3>";
if (file_exists(__DIR__ . '/includes/mail_config.php')) {
    require_once __DIR__ . '/includes/mail_config.php';
    echo "✓ 設定檔已載入<br>";
    
    if (defined('GMAIL_USER') && !empty(GMAIL_USER) && GMAIL_USER !== 'your-email@gmail.com') {
        echo "✓ Gmail 帳號已設定: " . htmlspecialchars(GMAIL_USER) . "<br>";
    } else {
        echo "✗ Gmail 帳號未設定或使用預設值<br>";
    }
    
    if (defined('GMAIL_APP_PASSWORD') && !empty(GMAIL_APP_PASSWORD) && GMAIL_APP_PASSWORD !== 'your-app-password') {
        echo "✓ Gmail 應用程式密碼已設定（已隱藏）<br>";
    } else {
        echo "✗ Gmail 應用程式密碼未設定或使用預設值<br>";
    }
} else {
    echo "✗ 設定檔不存在：includes/mail_config.php<br>";
}
echo "<hr>";

// 測試 3: 測試 PHPMailer 發送（如果設定完成）
echo "<h3>3. PHPMailer 發送測試</h3>";
if ($phpmailer_found && function_exists('sendPasswordEmail')) {
    if (isset($_GET['test_send']) && isset($_GET['test_email'])) {
        $testEmail = filter_var($_GET['test_email'], FILTER_VALIDATE_EMAIL);
        if ($testEmail) {
            echo "正在發送測試郵件到: " . htmlspecialchars($testEmail) . "<br>";
            $result = sendPasswordEmail($testEmail, 'TEST_ACCOUNT', 'TEST_PASSWORD');
            if ($result['success']) {
                echo "<div style='color: green;'>✓ 郵件發送成功！請檢查您的信箱。</div><br>";
            } else {
                echo "<div style='color: red;'>✗ 郵件發送失敗：" . htmlspecialchars($result['error']) . "</div><br>";
            }
        } else {
            echo "<div style='color: red;'>✗ 無效的 email 地址</div><br>";
        }
    } else {
        echo "要測試郵件發送，請在 URL 後加上：?test_send=1&test_email=your-email@gmail.com<br>";
        echo "<form method='GET' style='margin-top: 10px;'>";
        echo "<input type='hidden' name='test_send' value='1'>";
        echo "<input type='email' name='test_email' placeholder='輸入測試 email' required>";
        echo "<button type='submit'>發送測試郵件</button>";
        echo "</form>";
    }
} else {
    echo "✗ 無法測試（PHPMailer 未安裝或函數不可用）<br>";
}
echo "<hr>";

// 測試 4: 檢查資料庫中的 email 欄位
echo "<h3>4. 資料庫 Email 欄位檢查</h3>";
try {
    include "includes/pdo.php";
    
    // 檢查是否有用戶沒有設定 email
    $stmt = $conn->query("SELECT COUNT(*) as count FROM userdata WHERE u_gmail IS NULL OR u_gmail = ''");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "未設定 email 的用戶數: " . $result['count'] . "<br>";
    
    // 顯示前 5 個用戶的 email（用於測試）
    $stmt = $conn->query("SELECT u_ID, u_name, u_gmail FROM userdata LIMIT 5");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>帳號</th><th>姓名</th><th>Email</th></tr>";
    foreach ($users as $user) {
        $email = $user['u_gmail'] ?: '<span style="color:red;">未設定</span>';
        echo "<tr><td>{$user['u_ID']}</td><td>{$user['u_name']}</td><td>$email</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "✗ 資料庫連線錯誤: " . htmlspecialchars($e->getMessage()) . "<br>";
}
echo "<hr>";

// 測試 5: 建議解決方案
echo "<h3>5. 可能的問題與解決方案</h3>";
echo "<ul>";
echo "<li><strong>PHPMailer 未安裝：</strong> 請參考 PHPMailer_安裝說明.md 進行安裝</li>";
echo "<li><strong>Gmail 設定未完成：</strong> 請編輯 includes/mail_config.php，設定 GMAIL_USER 和 GMAIL_APP_PASSWORD</li>";
echo "<li><strong>Gmail 應用程式密碼：</strong> 需要到 Google 帳號設定中產生應用程式密碼（不是 Gmail 登入密碼）</li>";
echo "<li><strong>兩步驟驗證未啟用：</strong> Gmail 需要啟用兩步驟驗證才能產生應用程式密碼</li>";
echo "<li><strong>用戶未設定 email：</strong> 確保資料庫中的 u_gmail 欄位有值</li>";
echo "<li><strong>防火牆/網路限制：</strong> 檢查伺服器是否可以連接到 smtp.gmail.com:587</li>";
echo "</ul>";

echo "<hr>";
echo "<p><a href='forgot_password2.php'>返回忘記密碼頁面</a></p>";
?>

