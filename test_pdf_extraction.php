<?php
/**
 * PDF 文字提取測試
 * 用於驗證 smalot/pdfparser 是否正常運作
 */

// 載入 Composer 自動載入器
require_once __DIR__ . '/vendor/autoload.php';

echo "<h1>PDF 文字提取測試</h1>";

// 檢查 PDF 解析庫是否可用
if (class_exists('\Smalot\PdfParser\Parser')) {
    echo "<p style='color: green;'>✅ PDF 解析庫已載入</p>";
    
    // 檢查是否有測試 PDF 檔案
    $testPdfPath = __DIR__ . '/templates';
    if (is_dir($testPdfPath)) {
        $pdfFiles = glob($testPdfPath . '/*.pdf');
        if (!empty($pdfFiles)) {
            $testFile = $pdfFiles[0];
            echo "<p>測試檔案: " . basename($testFile) . "</p>";
            
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($testFile);
                $text = $pdf->getText();
                
                if (!empty($text)) {
                    $textLength = strlen($text);
                    echo "<p style='color: green;'>✅ PDF 文字提取成功！</p>";
                    echo "<p>提取的文字長度: <strong>{$textLength}</strong> 字元</p>";
                    echo "<h3>提取的文字預覽（前 500 字元）：</h3>";
                    echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px; max-height: 300px; overflow-y: auto;'>";
                    echo htmlspecialchars(mb_substr($text, 0, 500));
                    echo "</pre>";
                } else {
                    echo "<p style='color: orange;'>⚠️ 提取的文字為空（可能是掃描版 PDF）</p>";
                }
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ 提取失敗: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        } else {
            echo "<p style='color: orange;'>⚠️ 未找到測試 PDF 檔案（請在 templates 目錄放置一個 PDF 檔案）</p>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ templates 目錄不存在</p>";
    }
} else {
    echo "<p style='color: red;'>❌ PDF 解析庫未載入</p>";
    echo "<p>請確認：</p>";
    echo "<ul>";
    echo "<li>vendor/autoload.php 是否存在</li>";
    echo "<li>smalot/pdfparser 是否已安裝（執行 composer install）</li>";
    echo "</ul>";
}

echo "<hr>";
echo "<p><a href='pages/admin_form_manage.php'>返回表單管理</a></p>";

