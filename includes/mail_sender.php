<?php
/**
 * 郵件發送函數（使用 PHPMailer）
 * 
 * 使用前請確保：
 * 1. 已安裝 PHPMailer（見 PHPMailer_安裝說明.md）
 * 2. 已設定 includes/mail_config.php
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// 檢查 PHPMailer 是否存在
$phpmailer_paths = [
    __DIR__ . '/../PHPMailer/src/PHPMailer.php',
    __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
    __DIR__ . '/PHPMailer/src/PHPMailer.php'
];

$phpmailer_found = false;
foreach ($phpmailer_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        require_once dirname($path) . '/SMTP.php';
        require_once dirname($path) . '/Exception.php';
        $phpmailer_found = true;
        break;
    }
}

if (!$phpmailer_found) {
    // 如果找不到 PHPMailer，定義一個錯誤函數
    function sendPasswordEmail($to, $account, $password) {
        return [
            'success' => false,
            'error' => 'PHPMailer 未安裝。請參考 PHPMailer_安裝說明.md 進行安裝。'
        ];
    }
} else {
    // 載入郵件設定
    if (file_exists(__DIR__ . '/mail_config.php')) {
        require_once __DIR__ . '/mail_config.php';
    } else {
        // 如果沒有設定檔，使用預設值（需要手動設定）
        if (!defined('GMAIL_USER')) define('GMAIL_USER', '');
        if (!defined('GMAIL_APP_PASSWORD')) define('GMAIL_APP_PASSWORD', '');
        if (!defined('GMAIL_FROM_NAME')) define('GMAIL_FROM_NAME', '專題日總彙系統');
        if (!defined('SMTP_HOST')) define('SMTP_HOST', 'smtp.gmail.com');
        if (!defined('SMTP_PORT')) define('SMTP_PORT', 587);
        if (!defined('SMTP_SECURE')) define('SMTP_SECURE', 'tls');
        if (!defined('MAIL_DEBUG')) define('MAIL_DEBUG', false);
    }

    /**
     * 發送密碼重設郵件
     * 
     * @param string $to 收件人 email
     * @param string $account 帳號
     * @param string $password 密碼
     * @return array ['success' => bool, 'error' => string]
     */
    function sendPasswordEmail($to, $account, $password) {
        // 檢查設定
        if (empty(GMAIL_USER) || empty(GMAIL_APP_PASSWORD)) {
            return [
                'success' => false,
                'error' => '郵件設定未完成。請編輯 includes/mail_config.php 設定 Gmail 帳號和應用程式密碼。'
            ];
        }

        try {
            $mail = new PHPMailer(true);

            // 伺服器設定
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = GMAIL_USER;
            $mail->Password = GMAIL_APP_PASSWORD;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';

            // 除錯模式
            if (MAIL_DEBUG) {
                $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            } else {
                $mail->SMTPDebug = 0;
            }

            // 發送者
            $mail->setFrom(GMAIL_USER, GMAIL_FROM_NAME);
            
            // 收件人
            $mail->addAddress($to);

            // 郵件內容
            $mail->isHTML(false); // 使用純文字格式
            $mail->Subject = '您的密碼查詢 - 專題日總彙';
            $mail->Body = "親愛的用戶，您好：\n\n";
            $mail->Body .= "您已申請查詢密碼，以下是您的帳號資訊：\n\n";
            $mail->Body .= "帳號：{$account}\n";
            $mail->Body .= "密碼：{$password}\n\n";
            $mail->Body .= "請妥善保管您的密碼，勿將此資訊告知他人。\n\n";
            $mail->Body .= "此為系統自動發送，請勿直接回覆此郵件。\n";
            $mail->Body .= "如有疑問，請聯絡系統管理員。\n\n";
            $mail->Body .= "---\n";
            $mail->Body .= "專題日總彙系統";

            // 發送郵件
            $mail->send();
            
            return [
                'success' => true,
                'error' => ''
            ];

        } catch (Exception $e) {
            // 記錄錯誤
            error_log("PHPMailer Error: " . $mail->ErrorInfo);
            
            return [
                'success' => false,
                'error' => '郵件發送失敗：' . $mail->ErrorInfo
            ];
        }
    }
}

