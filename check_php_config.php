<?php
/**
 * PHP 配置檢查腳本
 * 用於檢查上傳相關的 PHP 設定是否正確
 * 
 * 使用方法：
 * 1. 將此文件放在網站根目錄
 * 2. 在瀏覽器中訪問：http://localhost/check_php_config.php
 * 3. 檢查所有項目是否為綠色（符合要求）
 * 4. 如有紅色項目，請按照提示修改 php.ini
 */

// 設置錯誤報告
error_reporting(E_ALL);
ini_set('display_errors', 1);

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP 配置檢查</title>
    <style>
        body {
            font-family: 'Microsoft JhengHei', Arial, sans-serif;
            max-width: 900px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
        }
        .check-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            background: #f8f9fa;
        }
        .check-item.pass {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        .check-item.fail {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
        }
        .check-item.warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        .check-label {
            font-weight: 600;
            color: #333;
        }
        .check-value {
            color: #666;
            font-family: monospace;
        }
        .check-status {
            font-weight: bold;
            padding: 5px 10px;
            border-radius: 3px;
        }
        .status-pass {
            background: #28a745;
            color: white;
        }
        .status-fail {
            background: #dc3545;
            color: white;
        }
        .status-warning {
            background: #ffc107;
            color: #333;
        }
        .instructions {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .instructions h3 {
            margin-top: 0;
            color: #1976D2;
        }
        .instructions ol {
            margin: 10px 0;
            padding-left: 20px;
        }
        .instructions li {
            margin: 8px 0;
            line-height: 1.6;
        }
        .phpinfo-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        .phpinfo-link:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 PHP 配置檢查</h1>
        
        <div class="instructions">
            <h3>📋 檢查說明</h3>
            <p>此頁面會檢查 PHP 上傳相關設定。所有項目應顯示為 <span class="status-pass">✓ 通過</span>。</p>
            <p>如有項目顯示為 <span class="status-fail">✗ 失敗</span>，請按照以下步驟修改：</p>
            <ol>
                <li>找到 XAMPP 的 php.ini 文件（通常在 <code>C:\xampp\php\php.ini</code>）</li>
                <li>使用文字編輯器打開 php.ini</li>
                <li>搜尋對應的設定項目並修改數值</li>
                <li><strong>完全重啟 Apache</strong>（在 XAMPP Control Panel 中停止後再啟動）</li>
                <li>重新載入此頁面確認設定已生效</li>
            </ol>
        </div>

        <?php
        // 輔助函數：將位元組轉換為可讀格式
        function formatBytes($bytes, $precision = 2) {
            $units = array('B', 'KB', 'MB', 'GB', 'TB');
            $bytes = max($bytes, 0);
            $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
            $pow = min($pow, count($units) - 1);
            $bytes /= pow(1024, $pow);
            return round($bytes, $precision) . ' ' . $units[$pow];
        }
        
        // 輔助函數：檢查設定值
        function checkSetting($name, $current, $minValue, $unit = 'bytes') {
            $currentBytes = $current;
            $minBytes = $minValue;
            
            if ($unit === 'M') {
                $currentBytes = $current * 1024 * 1024;
                $minBytes = $minValue * 1024 * 1024;
            } elseif ($unit === 'seconds') {
                $currentBytes = $current;
                $minBytes = $minValue;
            }
            
            $pass = $currentBytes >= $minBytes;
            $displayValue = $unit === 'M' ? $current . 'M' : ($unit === 'seconds' ? $current . ' 秒' : formatBytes($currentBytes));
            $minDisplay = $unit === 'M' ? $minValue . 'M' : ($unit === 'seconds' ? $minValue . ' 秒' : formatBytes($minBytes));
            
            return [
                'pass' => $pass,
                'current' => $displayValue,
                'min' => $minDisplay,
                'name' => $name
            ];
        }
        
        // 檢查各項設定
        $checks = [];
        
        // upload_max_filesize
        $uploadMax = ini_get('upload_max_filesize');
        $uploadMaxBytes = return_bytes($uploadMax);
        $checks[] = checkSetting('upload_max_filesize', $uploadMaxBytes, 100 * 1024 * 1024);
        
        // post_max_size
        $postMax = ini_get('post_max_size');
        $postMaxBytes = return_bytes($postMax);
        $checks[] = checkSetting('post_max_size', $postMaxBytes, 100 * 1024 * 1024);
        
        // memory_limit
        $memoryLimit = ini_get('memory_limit');
        $memoryLimitBytes = return_bytes($memoryLimit);
        $checks[] = checkSetting('memory_limit', $memoryLimitBytes, 256 * 1024 * 1024);
        
        // max_execution_time
        $maxExecTime = ini_get('max_execution_time');
        $checks[] = checkSetting('max_execution_time', (int)$maxExecTime, 300, 'seconds');
        
        // 輔助函數：將 PHP 設定值轉換為位元組
        function return_bytes($val) {
            $val = trim($val);
            $last = strtolower($val[strlen($val)-1]);
            $val = (int)$val;
            switch($last) {
                case 'g':
                    $val *= 1024;
                case 'm':
                    $val *= 1024;
                case 'k':
                    $val *= 1024;
            }
            return $val;
        }
        
        // 顯示檢查結果
        $allPass = true;
        foreach ($checks as $check) {
            $status = $check['pass'] ? 'pass' : 'fail';
            $statusText = $check['pass'] ? '✓ 通過' : '✗ 失敗';
            $statusClass = $check['pass'] ? 'status-pass' : 'status-fail';
            
            if (!$check['pass']) {
                $allPass = false;
            }
            
            echo '<div class="check-item ' . $status . '">';
            echo '<div>';
            echo '<div class="check-label">' . htmlspecialchars($check['name']) . '</div>';
            echo '<div class="check-value">目前：' . htmlspecialchars($check['current']) . ' | 建議：≥ ' . htmlspecialchars($check['min']) . '</div>';
            echo '</div>';
            echo '<span class="check-status ' . $statusClass . '">' . $statusText . '</span>';
            echo '</div>';
        }
        
        // 顯示 php.ini 路徑
        $phpIniPath = php_ini_loaded_file();
        echo '<div class="check-item ' . ($phpIniPath ? 'pass' : 'fail') . '">';
        echo '<div>';
        echo '<div class="check-label">php.ini 文件路徑</div>';
        echo '<div class="check-value">' . htmlspecialchars($phpIniPath ?: '未找到') . '</div>';
        echo '</div>';
        echo '<span class="check-status ' . ($phpIniPath ? 'status-pass' : 'status-fail') . '">' . ($phpIniPath ? '✓ 已載入' : '✗ 未找到') . '</span>';
        echo '</div>';
        
        // 總結
        echo '<div style="margin-top: 30px; padding: 20px; background: ' . ($allPass ? '#d4edda' : '#f8d7da') . '; border-radius: 5px; text-align: center;">';
        if ($allPass) {
            echo '<h2 style="color: #28a745; margin: 0;">✓ 所有檢查通過！</h2>';
            echo '<p style="margin: 10px 0 0 0; color: #155724;">PHP 配置符合要求，可以正常上傳檔案。</p>';
        } else {
            echo '<h2 style="color: #dc3545; margin: 0;">✗ 部分檢查未通過</h2>';
            echo '<p style="margin: 10px 0 0 0; color: #721c24;">請按照上方提示修改 php.ini 並重啟 Apache。</p>';
        }
        echo '</div>';
        
        // 提供 phpinfo 連結
        echo '<div style="text-align: center; margin-top: 20px;">';
        echo '<a href="phpinfo.php" class="phpinfo-link">查看完整 phpinfo()</a>';
        echo '</div>';
        ?>
    </div>
</body>
</html>

