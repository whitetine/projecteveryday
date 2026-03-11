# 安裝 Composer 依賴說明

## 方法 1：使用命令提示字元（CMD）

1. 開啟「命令提示字元」（CMD），不是 PowerShell
2. 切換到專案目錄：
   ```
   cd "C:\tell me why baby why\original\projecteveryday"
   ```
3. 執行 Composer 安裝：
   ```
   composer install
   ```

## 方法 2：如果 Composer 不在 PATH 中

### 如果使用 Composer 安裝程式（Composer-Setup.exe）：
Composer 通常安裝在：
- `C:\ProgramData\ComposerSetup\bin\composer.bat`
- 或 `C:\Users\你的使用者名稱\AppData\Roaming\Composer\vendor\bin\composer.bat`

使用完整路徑執行：
```
"C:\ProgramData\ComposerSetup\bin\composer.bat" install
```

### 如果使用 Composer PHAR 檔案：
如果下載的是 `composer.phar`，使用：
```
php composer.phar install
```

## 方法 3：手動下載依賴（如果 Composer 無法使用）

如果 Composer 無法正常運作，可以暫時跳過 PDF 解析功能。
表單管理的基本功能（新增、編輯、刪除表單）不需要 Composer 依賴。

## 安裝完成後

安裝完成後，應該會看到以下目錄：
- `vendor/smalot/pdfparser` - PDF 解析
- `vendor/khanamiryan/php-qrcode-detector-decoder` - 簽名圖檔 QR Code 解碼（用於版本驗證）
然後重新測試表單的「上傳格式並自動識別題目」功能。

## 驗證安裝

訪問以下 URL 檢查是否安裝成功：
```
http://localhost/original/projecteveryday/test_form_api.php
```

檢查「檢查 includes/utils.php」部分，應該不會有 Composer 相關的錯誤。

