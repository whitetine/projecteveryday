# PHPMailer 安裝說明

## 步驟 1：下載 PHPMailer

### 方法一：使用 Composer（推薦）
```bash
composer require phpmailer/phpmailer
```

### 方法二：手動下載
1. 前往 https://github.com/PHPMailer/PHPMailer/releases
2. 下載最新版本的 ZIP 檔案
3. 解壓縮後，將以下資料夾複製到專案根目錄：
   - `PHPMailer/` 資料夾（包含所有 PHP 檔案）

或者直接下載：
```bash
# 在專案根目錄執行
git clone https://github.com/PHPMailer/PHPMailer.git
```

## 步驟 2：設定 Gmail 應用程式密碼

1. 前往 https://myaccount.google.com/
2. 點擊左側選單的「安全性」
3. 在「登入 Google」區塊中：
   - 如果尚未啟用「兩步驟驗證」，請先啟用
   - 啟用後，在下方找到「應用程式密碼」
4. 點擊「應用程式密碼」
5. 選擇應用程式：「郵件」
6. 選擇裝置：「其他（自訂名稱）」，輸入名稱（如：專題日總彙）
7. 點擊「產生」
8. 複製產生的 16 位元密碼（格式：`xxxx xxxx xxxx xxxx`，使用時請移除空格）

## 步驟 3：設定 mail_config.php

編輯 `includes/mail_config.php`，填入：
- `GMAIL_USER`: 您的 Gmail 完整 email（例如：yourname@gmail.com）
- `GMAIL_APP_PASSWORD`: 步驟 2 取得的應用程式密碼

## 步驟 4：測試

訪問 `test_email.php` 測試郵件發送功能。

