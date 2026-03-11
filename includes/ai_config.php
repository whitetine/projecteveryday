<?php
/**
 * includes/ai_config.php
 * AI 服務設定檔
 * - OCR.space：圖片轉文字
 * - OpenAI：語音轉文字 + GPT 統整
 * - (可選) Google Gemini：若你還要保留
 */

// 從環境變數或 .env 檔案讀取配置
function getEnvVar($key, $default = '') {
    $value = getenv($key);
    if ($value !== false && $value !== '') return $value;

    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!str_contains($line, '=')) continue;

            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);

            if ($k === $key) return $v;
        }
    }
    return $default;
}


$ai_config = [
    'provider' => getEnvVar('AI_PROVIDER', 'google'), // 預設 google

    // Google Gemini
    'google_api_key'    => getEnvVar('GOOGLE_API_KEY', ''),
    'google_model'      => getEnvVar('GOOGLE_MODEL', 'gemini-2.0-flash'),
    'google_timeout'    => (int)getEnvVar('GOOGLE_TIMEOUT', 60),
    'google_max_tokens' => (int)getEnvVar('GOOGLE_MAX_TOKENS', 2000),

    // OCR.space
    'ocr_space_api_key' => getEnvVar('OCR_SPACE_API_KEY', ''),
    'ocr_space_url'     => 'https://api.ocr.space/parse/image',

    // OpenAI（停用）
    'provider' => getEnvVar('AI_PROVIDER', 'openai'), // ✅ 預設 openai
    'openai_api_key' => getEnvVar('OPENAI_API_KEY', ''), // 你可留空
    'openai_model' => getEnvVar('OPENAI_MODEL', 'gpt-4o'),

    // Python WhisperX 說話者分離 API（本機）
    'python_transcribe_api_url' => getEnvVar('PYTHON_TRANSCRIBE_API_URL', 'http://127.0.0.1:8000'),

];

if (empty($ai_config['google_api_key'])) {
    error_log("警告：GOOGLE_API_KEY 未設定");
}
if (empty($ai_config['ocr_space_api_key'])) {
    error_log("警告：OCR_SPACE_API_KEY 未設定");
}

return $ai_config;
