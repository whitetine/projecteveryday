<?php
/**
 * 檢查 Composer 依賴是否正確安裝
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Composer 依賴檢查</h2>";
echo "<pre>";

// 1. 檢查 vendor/autoload.php
echo "1. 檢查 vendor/autoload.php\n";
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "   ✓ vendor/autoload.php 存在\n";
    
    try {
        require_once __DIR__ . '/vendor/autoload.php';
        echo "   ✓ 自動載入器載入成功\n";
    } catch (Throwable $e) {
        echo "   ✗ 自動載入器載入失敗: " . $e->getMessage() . "\n";
        exit;
    }
} else {
    echo "   ✗ vendor/autoload.php 不存在\n";
    exit;
}
echo "\n";

// 2. 檢查 smalot/pdfparser
echo "2. 檢查 smalot/pdfparser\n";
if (class_exists('\Smalot\PdfParser\Parser')) {
    echo "   ✓ Smalot\\PdfParser\\Parser 類別存在\n";
    
    try {
        $parser = new \Smalot\PdfParser\Parser();
        echo "   ✓ Parser 實例化成功\n";
    } catch (Exception $e) {
        echo "   ✗ Parser 實例化失敗: " . $e->getMessage() . "\n";
    }
} else {
    echo "   ✗ Smalot\\PdfParser\\Parser 類別不存在\n";
    echo "   請執行: composer install\n";
}
echo "\n";

// 3. 檢查 symfony/polyfill-mbstring
echo "3. 檢查 symfony/polyfill-mbstring\n";
if (function_exists('mb_strlen')) {
    echo "   ✓ mbstring 函數可用\n";
} else {
    echo "   ⚠ mbstring 函數不可用（可能需要 PHP 擴展）\n";
}
echo "\n";

// 4. 檢查 vendor 目錄結構
echo "4. 檢查 vendor 目錄結構\n";
$vendorDirs = ['smalot', 'symfony', 'composer'];
foreach ($vendorDirs as $dir) {
    $path = __DIR__ . '/vendor/' . $dir;
    if (is_dir($path)) {
        echo "   ✓ vendor/$dir/ 存在\n";
    } else {
        echo "   ✗ vendor/$dir/ 不存在\n";
    }
}
echo "\n";

echo "檢查完成！\n";
echo "</pre>";

