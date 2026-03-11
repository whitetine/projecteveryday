# 安裝 Composer 步驟

## 步驟 1：執行 Composer-Setup.exe

1. 找到您下載的 `Composer-Setup.exe` 檔案
2. **右鍵點擊** → 選擇「**以系統管理員身分執行**」
3. 安裝程式會自動：
   - 找到您的 PHP 安裝位置
   - 將 Composer 加入系統 PATH
   - 安裝 Composer

4. 安裝完成後，**重新開啟命令提示字元或 PowerShell**（重要！）

## 步驟 2：驗證安裝

開啟新的命令提示字元或 PowerShell，執行：

```bash
composer --version
```

如果顯示版本號（例如 `Composer version 2.x.x`），表示安裝成功！

## 步驟 3：安裝 PDF 解析庫

在專案目錄（`D:\GAY\projecteveryday\projecteveryday`）執行：

```bash
cd D:\GAY\projecteveryday\projecteveryday
composer install
```

或者如果 `composer.json` 中還沒有 smalot/pdfparser，執行：

```bash
composer require smalot/pdfparser
```

## 完成！

安裝完成後，系統就可以自動使用 PDF 解析庫提取文字了。

---

## 如果遇到問題

### 問題 1：找不到 PHP
- 確保已安裝 PHP
- 確保 PHP 已加入系統 PATH
- 可以在命令提示字元執行 `php --version` 測試

### 問題 2：Composer 命令找不到
- 重新開啟命令提示字元或 PowerShell
- 確認安裝時選擇了「加入 PATH」選項
- 或手動將 Composer 安裝目錄加入 PATH

### 問題 3：權限問題
- 以系統管理員身分執行命令提示字元
- 或確保對專案目錄有寫入權限

