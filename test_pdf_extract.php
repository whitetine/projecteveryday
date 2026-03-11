<?php
/**
 * PDF 文字提取測試腳本
 * 用於診斷 PDF 文字提取問題
 */

// 載入 Composer 自動載入器
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    die("錯誤：找不到 vendor/autoload.php，請先執行 composer install\n");
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF 文字提取測試</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>PDF 文字提取測試</h1>
    
    <?php
    // 檢查是否上傳了檔案
    if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['pdf_file'];
        $fileContent = file_get_contents($file['tmp_name']);
        $fileSize = $file['size'];
        
        echo "<h2>檔案資訊</h2>";
        echo "<p>檔案名稱: " . htmlspecialchars($file['name']) . "</p>";
        echo "<p>檔案大小: " . number_format($fileSize) . " bytes (" . round($fileSize / 1024, 2) . " KB)</p>";
        echo "<p>檔案類型: " . htmlspecialchars($file['type']) . "</p>";
        
        echo "<h2>文字提取測試</h2>";
        
        $text = '';
        $methods = [];
        
        // 方法 1: pdftotext
        if (function_exists('shell_exec')) {
            $tempFile = tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';
            file_put_contents($tempFile, $fileContent);
            
            $whichCmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? 'where' : 'which';
            $pdftotextPath = @shell_exec("$whichCmd pdftotext 2>&1");
            
            if (!empty($pdftotextPath) && strpos($pdftotextPath, 'not found') === false) {
                // 嘗試使用 UTF-8 編碼
                $cmd = "pdftotext -layout -enc UTF-8 \"$tempFile\" - 2>&1";
                $output = @shell_exec($cmd);
                if (!empty($output)) {
                    // 嘗試檢測和轉換編碼
                    if (!mb_check_encoding($output, 'UTF-8')) {
                        // 如果不是 UTF-8，嘗試轉換
                        $output = mb_convert_encoding($output, 'UTF-8', 'auto');
                    }
                    $text = $output;
                    $methods[] = ['name' => 'pdftotext', 'success' => true, 'length' => strlen($text), 'preview' => mb_substr($text, 0, 200)];
                } else {
                    $methods[] = ['name' => 'pdftotext', 'success' => false, 'error' => '執行成功但無輸出'];
                }
            } else {
                $methods[] = ['name' => 'pdftotext', 'success' => false, 'error' => '未安裝（Windows 環境通常沒有）'];
            }
            
            // 方法 2: smalot/pdfparser（優先使用，因為更可靠）
            if (class_exists('\Smalot\PdfParser\Parser')) {
                try {
                    $parser = new \Smalot\PdfParser\Parser();
                    $pdf = $parser->parseFile($tempFile);
                    $parsedText = $pdf->getText();
                    if (!empty($parsedText)) {
                        // 如果 smalot/pdfparser 提取的文字更多，使用它
                        if (empty($text) || strlen($parsedText) > strlen($text)) {
                            $text = $parsedText;
                        }
                        $methods[] = ['name' => 'smalot/pdfparser', 'success' => true, 'length' => strlen($parsedText), 'preview' => mb_substr($parsedText, 0, 200)];
                    } else {
                        $methods[] = ['name' => 'smalot/pdfparser', 'success' => false, 'error' => '解析成功但無文字內容'];
                    }
                } catch (Exception $e) {
                    $methods[] = ['name' => 'smalot/pdfparser', 'success' => false, 'error' => $e->getMessage()];
                }
            } else {
                if (empty($text)) {
                    $methods[] = ['name' => 'smalot/pdfparser', 'success' => false, 'error' => '未安裝（請執行 composer install）'];
                }
            }
            
            // 方法 3: 簡單提取
            if (empty($text)) {
                preg_match_all('/\((.*?)\)/s', $fileContent, $matches);
                if (!empty($matches[1])) {
                    $text = implode(' ', $matches[1]);
                    $text = preg_replace('/[^\x20-\x7E\x{4E00}-\x{9FFF}]/u', ' ', $text);
                    $text = preg_replace('/\s+/', ' ', $text);
                    if (strlen($text) > 50) {
                        $methods[] = ['name' => '簡單提取', 'success' => true, 'length' => strlen($text)];
                    } else {
                        $methods[] = ['name' => '簡單提取', 'success' => false, 'error' => '提取的文字太少'];
                    }
                } else {
                    $methods[] = ['name' => '簡單提取', 'success' => false, 'error' => '無法找到文字內容'];
                }
            }
            
            @unlink($tempFile);
        }
        
        // 顯示結果
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>方法</th><th>狀態</th><th>結果</th></tr>";
        foreach ($methods as $method) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($method['name']) . "</td>";
            if ($method['success']) {
                echo "<td class='success'>✅ 成功</td>";
                $preview = isset($method['preview']) ? htmlspecialchars($method['preview']) : '';
                echo "<td>提取了 " . number_format($method['length']) . " 字元";
                if (!empty($preview)) {
                    echo "<br><small style='color: #666;'>預覽: " . $preview . "</small>";
                }
                echo "</td>";
            } else {
                echo "<td class='error'>❌ 失敗</td>";
                echo "<td>" . htmlspecialchars($method['error'] ?? '未知錯誤') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
        
        if (!empty($text)) {
            echo "<h2>提取的文字內容（前 1000 字元）</h2>";
            echo "<pre>" . htmlspecialchars(mb_substr($text, 0, 1000)) . "</pre>";
            
            // 測試識別題目
            echo "<h2>題目識別測試</h2>";
            
            // 載入必要的依賴（在載入 form.php 之前）
            require_once __DIR__ . '/includes/utils.php';
            
            // 定義一個測試用的 json_err 函數（不會真的輸出 JSON，只拋出異常）
            if (!function_exists('json_err')) {
                function json_err($msg, $code = 'ERROR', $status = 400) {
                    throw new Exception($msg);
                }
            }
            
            // 定義一個測試用的 json_ok 函數
            if (!function_exists('json_ok')) {
                function json_ok($data = [], $status = 200) {
                    return $data;
                }
            }
            
            // 模擬必要的全局變數（避免 form.php 中的錯誤）
            if (!isset($GLOBALS['conn'])) {
                $GLOBALS['conn'] = null; // 測試環境不需要資料庫連線
            }
            
            // 定義一個簡單的 testGoogleGeminiConnection 函數（避免載入時出錯）
            if (!function_exists('testGoogleGeminiConnection')) {
                function testGoogleGeminiConnection($apiKey, $model = 'gemini-1.5-flash-latest') {
                    return ['success' => false, 'message' => '測試環境不支援 API 連線測試'];
                }
            }
            
            // 定義 getGeminiApiVersion 函數
            if (!function_exists('getGeminiApiVersion')) {
                function getGeminiApiVersion($model) {
                    return 'v1beta';
                }
            }
            
            // 只載入識別相關的函數（不執行 switch case）
            // 使用 output buffering 來避免執行 switch case
            ob_start();
            
            // 設定變數以避免執行 switch case
            $originalDo = $_GET['do'] ?? '';
            $_GET['do'] = 'test_mode'; // 設定一個不存在的 do
            $do = 'test_mode';
            
            // 定義必要的 session 變數（避免權限檢查錯誤）
            if (!isset($_SESSION)) {
                session_start();
            }
            $_SESSION['u_ID'] = 'test_user';
            
            // 載入 form.php（但不會執行任何 case，因為 do 不存在）
            try {
                require_once __DIR__ . '/modules/form.php';
            } catch (Exception $e) {
                ob_end_clean();
                echo "<p class='error'>❌ 載入 form.php 時發生錯誤: " . htmlspecialchars($e->getMessage()) . "</p>";
                $questions = [];
            }
            
            ob_end_clean();
            
            // 恢復原始 $_GET
            $_GET['do'] = $originalDo;
            
            // 模擬識別流程
            if (function_exists('recognizeQuestionsBasic')) {
                try {
                    $questions = recognizeQuestionsBasic($text, 'text');
                } catch (Exception $e) {
                    echo "<p class='error'>❌ 識別過程發生錯誤: " . htmlspecialchars($e->getMessage()) . "</p>";
                    $questions = [];
                }
            } else {
                echo "<p class='error'>❌ 無法載入識別函數 recognizeQuestionsBasic</p>";
                echo "<p>請檢查 modules/form.php 是否正確載入</p>";
                $questions = [];
            }
            if (!empty($questions)) {
                echo "<p class='success'>✅ 找到 " . count($questions) . " 個題目</p>";
                echo "<ul>";
                foreach ($questions as $q) {
                    echo "<li>" . htmlspecialchars($q['title']) . " (類型: " . htmlspecialchars($q['type']) . ")</li>";
                }
                echo "</ul>";
            } else {
                echo "<p class='error'>❌ 無法識別題目</p>";
                echo "<p>可能原因：題目格式不符合識別規則</p>";
            }
        } else {
            echo "<p class='error'>❌ 所有方法都無法提取文字</p>";
            echo "<p>這可能是掃描版 PDF，需要使用 AI OCR 功能</p>";
        }
    } else {
        ?>
        <form method="POST" enctype="multipart/form-data">
            <p>請上傳一個 PDF 檔案進行測試：</p>
            <input type="file" name="pdf_file" accept=".pdf" required>
            <button type="submit">測試</button>
        </form>
        <?php
    }
    ?>
</body>
</html>

