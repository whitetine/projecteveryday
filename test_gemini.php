<?php
$ai_config = require __DIR__ . '/includes/ai_config.php';

$apiKey = $ai_config['google_api_key'] ?? '';
if (!$apiKey) {
    die('Google API Key 尚未設定，請檢查 ai_config.php');
}

// 使用 config 裡的模型名稱，預設 gemini-2.5-flash
$model = urlencode($ai_config['google_model'] ?? 'gemini-2.5-flash');

// ⭐ 重點：v1beta
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

$payload = [
    'contents' => [[
        'parts' => [[
            'text' => '用一句簡短的中文介紹你自己。'
        ]]
    ]],
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    die("cURL Error: " . curl_error($ch));
}

curl_close($ch);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'http_code' => $httpCode,
    'response'  => json_decode($response, true),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
