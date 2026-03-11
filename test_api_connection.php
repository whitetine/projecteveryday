<?php
/**
 * Google Gemini API 連線測試頁面
 * 用於診斷 API 連線問題
 */

session_start();
if (!isset($_SESSION['u_ID'])) {
    die('請先登入');
}

require __DIR__ . '/includes/ai_config.php';
require __DIR__ . '/includes/utils.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API 連線測試</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        .info {
            background: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin: 20px 0;
        }
        .success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 20px 0;
        }
        .error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin: 20px 0;
        }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 20px;
        }
        .btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Google Gemini API 連線測試</h1>
        
        <?php
        $config = include __DIR__ . '/includes/ai_config.php';
        $apiKey = $config['google_api_key'] ?? '';
        $model = $config['google_model'] ?? 'gemini-1.5-flash';
        
        echo '<div class="info">';
        echo '<strong>配置資訊：</strong><br>';
        echo 'API Key 前綴: ' . substr($apiKey, 0, 10) . '...<br>';
        echo 'API Key 長度: ' . strlen($apiKey) . ' 字元<br>';
        echo '模型: ' . $model . '<br>';
        echo '</div>';
        
        if (empty($apiKey)) {
            echo '<div class="error">';
            echo '<strong>❌ 錯誤：</strong> API Key 未設定！<br>';
            echo '請檢查 includes/ai_config.php 檔案。';
            echo '</div>';
            exit;
        }
        
        echo '<div class="info">';
        echo '<strong>正在測試連線...</strong>';
        echo '</div>';
        
        // 執行測試
        $testData = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => '測試']
                    ]
                ]
            ],
            'generationConfig' => [
                'maxOutputTokens' => 10
            ]
        ];
        
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($testData),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);
        
        echo '<h2>測試結果</h2>';
        
        if ($curlError) {
            echo '<div class="error">';
            echo '<strong>❌ CURL 錯誤：</strong><br>';
            echo htmlspecialchars($curlError);
            echo '</div>';
        } elseif ($httpCode === 200) {
            $result = json_decode($response, true);
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                echo '<div class="success">';
                echo '<strong>✅ API 連線成功！</strong><br>';
                echo '回應內容: ' . htmlspecialchars($result['candidates'][0]['content']['parts'][0]['text']);
                echo '</div>';
            } else {
                echo '<div class="error">';
                echo '<strong>⚠️ API 回應格式異常</strong><br>';
                echo '<pre>' . htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                echo '</div>';
            }
        } else {
            echo '<div class="error">';
            echo '<strong>❌ API 請求失敗</strong><br>';
            echo 'HTTP 狀態碼: ' . $httpCode . '<br><br>';
            
            $errorData = json_decode($response, true);
            if ($errorData && isset($errorData['error'])) {
                echo '<strong>錯誤詳情：</strong><br>';
                echo '<pre>' . htmlspecialchars(json_encode($errorData['error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
            } else {
                echo '<strong>回應內容：</strong><br>';
                echo '<pre>' . htmlspecialchars(substr($response, 0, 1000)) . '</pre>';
            }
            echo '</div>';
        }
        
        echo '<h2>詳細資訊</h2>';
        echo '<div class="info">';
        echo '<strong>請求 URL：</strong><br>';
        echo '<code>' . htmlspecialchars(str_replace($apiKey, '***', $url)) . '</code><br><br>';
        echo '<strong>HTTP 狀態碼：</strong> ' . $httpCode . '<br>';
        echo '<strong>總時間：</strong> ' . ($curlInfo['total_time'] ?? 0) . ' 秒<br>';
        echo '</div>';
        ?>
        
        <a href="main.php#pages/admin_form_manage.php" class="btn">返回表單管理</a>
    </div>
</body>
</html>

