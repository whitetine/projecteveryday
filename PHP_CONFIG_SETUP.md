# PHP 配置調整說明

## 問題
當上傳的檔案總大小超過 PHP 的 `post_max_size` 限制時，會出現以下錯誤：
```
POST Content-Length of 48866580 bytes exceeds the limit of 41943040 bytes
```

## 解決方案

### XAMPP 環境

1. **找到 `php.ini` 文件**：
   - 通常位於 `C:\xampp\php\php.ini`
   - 或使用 `check_php_config.php` 查看實際路徑

2. **編輯 `php.ini` 文件**，找到以下設定並修改：
   ```ini
   ; 修改前（預設值）
   upload_max_filesize = 40M
   post_max_size = 40M
   memory_limit = 128M
   max_execution_time = 30
   
   ; 修改後（建議值）
   upload_max_filesize = 100M
   post_max_size = 100M
   memory_limit = 256M
   max_execution_time = 300
   ```

3. **完全重啟 Apache**：
   - 打開 XAMPP Control Panel
   - 點擊 Apache 的 **"Stop"** 按鈕
   - **等待完全停止**（狀態顯示為 "Stopped"）
   - 點擊 **"Start"** 按鈕
   - 等待啟動完成（狀態顯示為 "Running"）

4. **驗證設定是否生效**：
   - 在瀏覽器中訪問：`http://localhost/check_php_config.php`
   - 檢查所有項目是否顯示為 **✓ 通過**
   - 如有項目顯示為 **✗ 失敗**，請確認：
     - php.ini 文件是否正確修改
     - Apache 是否完全重啟
     - 是否修改了正確的 php.ini 文件（XAMPP 可能有多個 php.ini）

### 快速檢查工具

專案已提供 `check_php_config.php` 檢查工具：
- 訪問 `http://localhost/check_php_config.php` 查看配置狀態
- 會顯示所有相關設定的當前值和建議值
- 如有問題會提供明確的提示

### 注意事項

- **`post_max_size` 必須大於或等於 `upload_max_filesize`**
- **`memory_limit` 建議設為 256M 或更大**，以處理大檔案
- **`max_execution_time` 建議設為 300 秒**，避免上傳大檔案時超時
- **修改後必須完全重啟 Apache 才能生效**（不能只是重新載入）
- 如果修改後仍無效，請確認是否修改了正確的 php.ini 文件

