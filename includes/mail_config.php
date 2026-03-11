<?php
/**
 * Gmail 郵件設定檔
 * 
 * 使用前請先設定以下項目：
 * 1. GMAIL_USER: 您的 Gmail 帳號（完整 email）
 * 2. GMAIL_APP_PASSWORD: Gmail 應用程式密碼（不是 Gmail 登入密碼）
 * 
 * 如何取得 Gmail 應用程式密碼：
 * 1. 前往 https://myaccount.google.com/
 * 2. 點擊「安全性」
 * 3. 在「登入 Google」區塊中，啟用「兩步驟驗證」（如果尚未啟用）
 * 4. 在「兩步驟驗證」下方，點擊「應用程式密碼」
 * 5. 選擇「郵件」和「其他（自訂名稱）」，輸入名稱（如：專題日總彙）
 * 6. 複製產生的 16 位元密碼（格式：xxxx xxxx xxxx xxxx，使用時請移除空格）
 */

// Gmail 帳號設定
define('GMAIL_USER', 'your-email@gmail.com');  // 請替換為您的 Gmail 帳號
define('GMAIL_APP_PASSWORD', 'your-app-password');  // 請替換為您的 Gmail 應用程式密碼

// 發送者名稱
define('GMAIL_FROM_NAME', '專題日總彙系統');

// SMTP 設定（Gmail）
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');  // 使用 TLS

// 除錯模式（設為 true 可看到詳細錯誤訊息）
define('MAIL_DEBUG', false);

