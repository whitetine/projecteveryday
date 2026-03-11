<?php
/**
 * 測試 Google Gemini API 連線
 * 用於診斷 API Key 是否正確
 */

require __DIR__ . '/includes/ai_config.php';

$config = include __DIR__ . '/includes/ai_config.php';
$apiKey = $config['google_api_key'] ?? '';
$model = $config['google_model'] ?? 'gemini-1.5-flash';

echo "=== Google Gemini API 測試 ===\n\n";
echo "API Key: " . substr($apiKey, 0, 10) . "... (長度: " . strlen($apiKey) . ")\n";
echo "模型: {$model}\n\n";

if (empty($apiKey)) {
    echo "❌ 錯誤：API Key 未設定！\n";
    exit(1);
}

// 簡單的文字測試
$data = [
    'contents' => [
        [
            'parts' => [
                ['text' => '請回答：1+1等於多少？']
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.3,
        'maxOutputTokens' => 100
    ]
];

$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

echo "發送測試請求...\n";
echo "URL: " . str_replace($apiKey, '***', $url) . "\n\n";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP 狀態碼: {$httpCode}\n";

if ($curlError) {
    echo "❌ CURL 錯誤: {$curlError}\n";
    exit(1);
}

if ($httpCode === 200) {
    $result = json_decode($response, true);
    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        $answer = $result['candidates'][0]['content']['parts'][0]['text'];
        echo "✅ API 連線成功！\n";
        echo "回應: {$answer}\n";
    } else {
        echo "⚠️ API 回應格式異常\n";
        echo "回應內容: " . substr($response, 0, 500) . "\n";
    }
} else {
    echo "❌ API 請求失敗\n";
    echo "回應內容:\n";
    $errorData = json_decode($response, true);
    if ($errorData) {
        echo json_encode($errorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo substr($response, 0, 500) . "\n";
    }
    exit(1);
}

echo "\n✅ 測試完成！\n";

