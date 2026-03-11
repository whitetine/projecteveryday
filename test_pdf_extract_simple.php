<?php
/**
 * 簡單測試 PDF 文字提取
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>PDF 文字提取測試</h2>";
echo "<pre>";

// 1. 檢查 Composer 依賴
echo "1. 檢查 Composer 依賴\n";
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    try {
        require_once __DIR__ . '/vendor/autoload.php';
        echo "   ✓ vendor/autoload.php 載入成功\n";
    } catch (Exception $e) {
        echo "   ✗ vendor/autoload.php 載入失敗: " . $e->getMessage() . "\n";
        exit;
    }
} else {
    echo "   ✗ vendor/autoload.php 不存在\n";
    exit;
}

// 2. 檢查 smalot/pdfparser
echo "\n2. 檢查 smalot/pdfparser\n";
if (class_exists('\Smalot\PdfParser\Parser')) {
    echo "   ✓ Smalot\\PdfParser\\Parser 類別存在\n";
    
    try {
        $parser = new \Smalot\PdfParser\Parser();
        echo "   ✓ Parser 實例化成功\n";
    } catch (Exception $e) {
        echo "   ✗ Parser 實例化失敗: " . $e->getMessage() . "\n";
        exit;
    }
} else {
    echo "   ✗ Smalot\\PdfParser\\Parser 類別不存在\n";
    exit;
}

// 3. 檢查是否有測試 PDF 檔案
echo "\n3. 檢查測試 PDF 檔案\n";
$testPdfPath = __DIR__ . '/templates';
if (is_dir($testPdfPath)) {
    $pdfFiles = glob($testPdfPath . '/*.pdf');
    if (count($pdfFiles) > 0) {
        echo "   找到 " . count($pdfFiles) . " 個 PDF 檔案\n";
        $testFile = $pdfFiles[0];
        echo "   測試檔案: " . basename($testFile) . "\n";
        echo "   檔案大小: " . filesize($testFile) . " bytes\n";
        
        // 4. 嘗試提取文字
        echo "\n4. 嘗試提取文字\n";
        try {
            $pdf = $parser->parseFile($testFile);
            $text = $pdf->getText();
            
            if (!empty($text)) {
                echo "   ✓ 文字提取成功！\n";
                echo "   文字長度: " . strlen($text) . " 字元\n";
                echo "   文字預覽（前500字元）:\n";
                echo "   " . str_repeat("-", 60) . "\n";
                echo "   " . mb_substr($text, 0, 500) . "\n";
                echo "   " . str_repeat("-", 60) . "\n";
            } else {
                echo "   ⚠ 文字提取結果為空\n";
                try {
                    $pages = $pdf->getPages();
                    echo "   PDF 頁數: " . count($pages) . "\n";
                    if (count($pages) > 0) {
                        echo "   ⚠ 這可能是掃描版 PDF（圖片），無法提取文字\n";
                    }
                } catch (Exception $e) {
                    echo "   無法獲取頁數: " . $e->getMessage() . "\n";
                }
            }
        } catch (Exception $e) {
            echo "   ✗ 提取失敗: " . $e->getMessage() . "\n";
            echo "   錯誤類型: " . get_class($e) . "\n";
        }
    } else {
        echo "   ⚠ templates 目錄中沒有 PDF 檔案\n";
        echo "   請上傳一個 PDF 檔案到 templates 目錄進行測試\n";
    }
} else {
    echo "   ⚠ templates 目錄不存在\n";
}

echo "\n測試完成！\n";
echo "</pre>";

