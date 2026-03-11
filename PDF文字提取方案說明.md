# PDF 文字提取方案說明

## 問題：為什麼無法提取 PDF 文字？

目前系統嘗試使用三種方法提取 PDF 文字，按優先順序：

1. **系統工具 `pdftotext`**（需要安裝）
2. **PHP 庫 `smalot/pdfparser`**（推薦，隨專案部署）
3. **簡單文字提取**（僅適用於文字型 PDF）

## 兩種解決方案

### 方案 1：系統工具 `pdftotext`（不推薦）

**優點：**
- 提取效果好

**缺點：**
- ❌ **需要在每個伺服器上單獨安裝**
- ❌ Windows 需要下載 Poppler for Windows
- ❌ Linux 需要安裝 `poppler-utils` 套件
- ❌ 每次換伺服器都要重新安裝

**安裝方式：**
- **Windows**: 下載 [Poppler for Windows](https://github.com/oschwartz10612/poppler-windows/releases/)，解壓後將 `bin` 目錄加入系統 PATH
- **Linux**: `sudo apt-get install poppler-utils` 或 `sudo yum install poppler-utils`

---

### 方案 2：PHP 庫 `smalot/pdfparser`（**推薦**）

**優點：**
- ✅ **只需安裝一次，隨專案一起部署**
- ✅ 不需要在每個伺服器上單獨安裝系統工具
- ✅ 跨平台（Windows、Linux、Mac 都可用）
- ✅ 使用 Composer 管理，版本控制方便

**缺點：**
- 需要安裝 Composer（PHP 套件管理工具）

**安裝步驟：**

#### 步驟 1：安裝 Composer（如果還沒有）

**Windows:**
1. 下載 [Composer-Setup.exe](https://getcomposer.org/Composer-Setup.exe)
2. 執行安裝程式，它會自動找到 PHP
3. 安裝完成後，在命令提示字元輸入 `composer --version` 確認

**Linux/Mac:**
```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

#### 步驟 2：在專案根目錄安裝 PDF 解析庫

在專案根目錄（與 `api.php` 同層）執行：

```bash
composer require smalot/pdfparser
```

這會：
- 創建 `composer.json` 和 `composer.lock` 檔案
- 下載 `smalot/pdfparser` 到 `vendor/` 目錄
- 自動處理依賴關係

#### 步驟 3：在程式碼中載入 Composer 自動載入器

在 `modules/form.php` 開頭加入：

```php
// 載入 Composer 自動載入器（如果存在）
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
```

#### 步驟 4：部署到其他伺服器

當您要部署到新伺服器時，只需要：

1. 上傳整個專案（包含 `vendor/` 目錄）
2. 或者在新伺服器上執行 `composer install`（會根據 `composer.lock` 安裝相同版本）

**重要：** `vendor/` 目錄應該隨專案一起上傳，這樣就不需要在每個伺服器上重新安裝！

---

## 推薦方案：使用 PHP 庫

**為什麼推薦方案 2？**

1. **一次安裝，到處使用**：安裝後，`vendor/` 目錄會隨專案一起，不需要在每個伺服器上重新安裝
2. **版本控制**：`composer.lock` 確保所有環境使用相同版本
3. **跨平台**：Windows、Linux、Mac 都可用
4. **維護方便**：更新時只需執行 `composer update`

---

## 快速開始（推薦方案）

### 1. 安裝 Composer（如果還沒有）
- Windows: 下載 [Composer-Setup.exe](https://getcomposer.org/Composer-Setup.exe)
- 確認安裝：在命令提示字元輸入 `composer --version`

### 2. 在專案根目錄執行
```bash
composer require smalot/pdfparser
```

### 3. 確認安裝成功
檢查是否有 `vendor/` 目錄和 `composer.json` 檔案

### 4. 完成！
系統會自動使用 PHP 庫提取 PDF 文字，不需要額外設定

---

## 注意事項

1. **掃描版 PDF**：如果 PDF 是掃描的圖片（非文字型），兩種方案都無法提取文字，需要使用 AI 識別
2. **文字型 PDF**：Word 轉 PDF 通常是文字型，兩種方案都可以處理
3. **檔案大小**：建議 PDF 檔案小於 20MB

---

## 測試

安裝完成後，重新上傳 PDF 表單，系統應該能夠成功提取文字並識別題目。

