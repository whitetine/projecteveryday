<?php
/**
 * 測試 PDF 上傳和識別功能
 * 模擬實際的上傳流程
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

session_start();

echo "<h2>PDF 上傳和識別功能測試</h2>";
echo "<pre>";

// 1. 檢查 Session
echo "1. Session 檢查\n";
echo "   u_ID: " . ($_SESSION['u_ID'] ?? '未設定') . "\n";
echo "   role_ID: " . ($_SESSION['role_ID'] ?? '未設定') . "\n";
echo "\n";

// 2. 檢查 Composer 依賴
echo "2. Composer 依賴檢查\n";
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    try {
        require_once __DIR__ . '/vendor/autoload.php';
        echo "   ✓ vendor/autoload.php 載入成功\n";
        
        if (class_exists('\Smalot\PdfParser\Parser')) {
            echo "   ✓ Smalot\\PdfParser\\Parser 類別存在\n";
        } else {
            echo "   ✗ Smalot\\PdfParser\\Parser 類別不存在\n";
        }
    } catch (Exception $e) {
        echo "   ✗ 載入失敗: " . $e->getMessage() . "\n";
    }
} else {
    echo "   ✗ vendor/autoload.php 不存在\n";
}
echo "\n";

// 3. 檢查是否有測試 PDF
echo "3. 檢查測試 PDF 檔案\n";
$testPdfPath = __DIR__ . '/templates';
if (is_dir($testPdfPath)) {
    $pdfFiles = glob($testPdfPath . '/*.pdf');
    if (count($pdfFiles) > 0) {
        echo "   找到 " . count($pdfFiles) . " 個 PDF 檔案\n";
        $testFile = $pdfFiles[0];
        echo "   測試檔案: " . basename($testFile) . "\n";
        echo "   檔案大小: " . filesize($testFile) . " bytes\n";
        
        // 4. 測試 PDF 解析
        echo "\n4. 測試 PDF 解析\n";
        try {
            require __DIR__ . '/includes/pdo.php';
            require __DIR__ . '/includes/utils.php';
            
            // 讀取檔案內容
            $fileContent = file_get_contents($testFile);
            echo "   檔案內容讀取成功，長度: " . strlen($fileContent) . " bytes\n";
            
            // 測試 extractTextFromPDF 函數
            // 需要載入 form.php 才能使用這個函數
            // 但我們可以直接測試 Parser
            if (class_exists('\Smalot\PdfParser\Parser')) {
                echo "\n   使用 smalot/pdfparser 測試...\n";
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($testFile);
                $text = $pdf->getText();
                
                if (!empty($text)) {
                    echo "   ✓ 文字提取成功！\n";
                    echo "   文字長度: " . strlen($text) . " 字元\n";
                    echo "   文字預覽（前300字元）:\n";
                    echo "   " . str_repeat("-", 60) . "\n";
                    echo "   " . mb_substr($text, 0, 300) . "\n";
                    echo "   " . str_repeat("-", 60) . "\n";
                } else {
                    echo "   ⚠ 文字提取結果為空\n";
                    try {
                        $pages = $pdf->getPages();
                        echo "   PDF 頁數: " . count($pages) . "\n";
                        echo "   ⚠ 這可能是掃描版 PDF（圖片），無法提取文字\n";
                        echo "   建議：使用文字型 PDF 或設定 AI API Key\n";
                    } catch (Exception $e) {
                        echo "   無法獲取頁數: " . $e->getMessage() . "\n";
                    }
                }
            }
        } catch (Exception $e) {
            echo "   ✗ 測試失敗: " . $e->getMessage() . "\n";
            echo "   錯誤類型: " . get_class($e) . "\n";
            echo "   錯誤堆疊:\n" . $e->getTraceAsString() . "\n";
        }
    } else {
        echo "   ⚠ templates 目錄中沒有 PDF 檔案\n";
        echo "   請上傳一個 PDF 檔案到 templates 目錄進行測試\n";
    }
} else {
    echo "   ⚠ templates 目錄不存在\n";
}

// 5. 檢查錯誤日誌位置
echo "\n5. 錯誤日誌位置\n";
$errorLog = ini_get('error_log');
if ($errorLog) {
    echo "   PHP error_log: $errorLog\n";
    if (file_exists($errorLog)) {
        echo "   ✓ 錯誤日誌檔案存在\n";
        echo "   檔案大小: " . filesize($errorLog) . " bytes\n";
        echo "   最後修改時間: " . date('Y-m-d H:i:s', filemtime($errorLog)) . "\n";
        
        // 讀取最後 20 行
        echo "\n   最後 20 行錯誤日誌:\n";
        echo "   " . str_repeat("-", 60) . "\n";
        $lines = file($errorLog);
        $lastLines = array_slice($lines, -20);
        foreach ($lastLines as $line) {
            if (stripos($line, 'recognize') !== false || 
                stripos($line, 'pdf') !== false || 
                stripos($line, 'form') !== false) {
                echo "   " . trim($line) . "\n";
            }
        }
        echo "   " . str_repeat("-", 60) . "\n";
    } else {
        echo "   ⚠ 錯誤日誌檔案不存在\n";
    }
} else {
    echo "   PHP error_log: 使用系統預設位置\n";
    echo "   常見位置：\n";
    echo "   - C:\\xampp\\apache\\logs\\error.log\n";
    echo "   - C:\\wamp\\logs\\php_error.log\n";
}

echo "\n測試完成！\n";
echo "</pre>";

