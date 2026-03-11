# AI 題目識別功能說明（使用 Google Gemini API - 免費）

## 功能概述

系統已整合 **Google Gemini API** 進行智能題目識別，可以自動從上傳的 PDF 或圖片中識別表單題目，並判斷題目類型。

**完全免費**：Google Gemini API 提供免費額度，無需信用卡！

## 設定步驟

### 1. 取得 Google Gemini API Key（免費）

1. 前往 [Google AI Studio](https://aistudio.google.com/app/apikey)
2. 使用 Google 帳號登入
3. 點擊「Create API Key」
4. 選擇或建立 Google Cloud 專案（免費，無需信用卡）
5. 複製產生的 API Key（格式：`AIza...`）

### 2. 設定 API Key

有兩種方式設定 API Key：

#### 方式一：環境變數（推薦）

在伺服器環境變數中設定：
```bash
export GOOGLE_API_KEY="AIza-your-api-key-here"
```

#### 方式二：配置檔案

編輯 `includes/ai_config.php`，填入您的 API Key：
```php
$ai_config = [
    'google_api_key' => 'AIza-your-api-key-here',
    // ...
];
```

## 使用方式

1. 進入「表單管理」頁面
2. 點擊「上傳格式並自動識別」按鈕
3. 上傳表單的 PDF 或圖片檔案
4. 系統會自動識別題目並顯示結果
5. 點擊「套用到表單」將識別結果套用到表單中

## 識別能力

### 支援的檔案格式
- PDF（文字版或掃描版）
- PNG 圖片
- JPG/JPEG 圖片

### 識別的題目類型
- **short_text**: 短文字（如姓名、電話等）
- **long_text**: 長文字（如說明、描述等）
- **number**: 數字
- **date**: 日期
- **select/radio**: 單選題（有選項）
- **checkbox**: 複選題（有選項，可多選）

### 自動判斷
- 題目是否為必填
- 題目的選項（如果有）

## 免費額度說明

Google Gemini API 提供**完全免費**的額度：
- **每分鐘 15 次請求**（RPM）
- **每天 1,500 次請求**（RPD）
- **無需信用卡**
- **永久免費**（在免費額度內）

對於一般使用來說，這個額度已經非常充足！

## 備用方案

如果沒有設定 API Key 或 API 請求失敗，系統會自動使用基本規則識別：
- 尋找以數字開頭的行（如 "1."、"一、" 等）
- 尋找問號結尾的句子
- 根據關鍵字判斷題目類型

## 注意事項

1. **API Key 安全**：請勿將 API Key 提交到版本控制系統（如 Git）
2. **免費額度限制**：每分鐘最多 15 次請求，每天最多 1,500 次請求
3. **檔案大小**：建議上傳的檔案不要太大，以免超過 API 限制
4. **識別準確度**：AI 識別並非 100% 準確，建議人工檢查並調整

## 疑難排解

### 問題：識別結果不準確
- 確保上傳的檔案清晰可讀
- 嘗試使用更高解析度的圖片
- 檢查表單格式是否標準

### 問題：API 請求失敗
- 檢查 API Key 是否正確設定
- 確認網路連線正常
- 檢查是否超過免費額度限制（每分鐘 15 次，每天 1,500 次）
- 確認 Google Cloud 專案狀態正常

### 問題：無法識別題目
- 確認檔案格式是否支援
- 檢查檔案是否損壞
- 嘗試使用基本規則識別（不設定 API Key）

## 技術細節

### 使用的模型
- **Gemini 1.5 Flash**：快速且免費，支援文字和圖片識別

### API 端點
- Google Gemini API：`https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`

### 程式碼位置
- 主要邏輯：`modules/form.php` 中的 `recognizeQuestionsFromFile()` 和 `recognizeWithGoogleGemini()` 函數
- 配置檔案：`includes/ai_config.php`

