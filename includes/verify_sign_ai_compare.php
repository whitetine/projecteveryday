<?php
/**
 * 簽名前／簽名後 PDF 以 AI 或文字比對相似度（≥85% 視為一致）
 * 需在 .env 設定 OPENAI_API_KEY 才會呼叫 OpenAI
 */

/**
 * 從 PDF 擷取文字（Smalot → pdftotext → raw 備援）
 */
function verify_sign_extract_pdf_text(string $fullPath, int $maxChars = 12000): string {
    if (!is_file($fullPath) || !is_readable($fullPath)) return '';
    $text = '';
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (file_exists($autoload)) { try { require_once $autoload; } catch (Throwable $e) { } }
    if (class_exists('\Smalot\PdfParser\Parser')) {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($fullPath);
            $text = $pdf->getText();
            $text = is_string($text) ? preg_replace('/\s+/u', ' ', trim($text)) : '';
        } catch (Throwable $e) { $text = ''; }
    }
    if (strlen($text) < 30 && function_exists('shell_exec')) {
        $whichCmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? 'where' : 'which';
        $whichOut = @shell_exec("$whichCmd pdftotext 2>&1");
        if (!empty($whichOut) && strpos($whichOut, 'not found') === false) {
            $out = @shell_exec('pdftotext -layout -enc UTF-8 ' . escapeshellarg($fullPath) . ' - 2>&1');
            if (!empty($out) && strlen($out) > 20) {
                if (mb_check_encoding($out, 'UTF-8')) {
                    $text = preg_replace('/\s+/u', ' ', trim($out));
                } else {
                    $converted = @mb_convert_encoding($out, 'UTF-8', 'ISO-8859-1');
                    $text = preg_replace('/\s+/u', ' ', trim($converted !== false ? $converted : $out));
                }
            }
        }
    }
    if (strlen($text) < 30) {
        $raw = @file_get_contents($fullPath);
        if ($raw && strlen($raw) > 100 && preg_match_all('/[\x{4E00}-\x{9FFF}]{2,}/u', $raw, $m)) {
            $text = implode(' ', array_slice($m[0], 0, 500));
        }
    }
    if ($text !== '' && mb_strlen($text) > $maxChars) $text = mb_substr($text, 0, $maxChars);
    return $text;
}

/**
 * OpenAI 比對兩份文件文字相似度 0–100
 */
function verify_sign_ai_similarity(string $textBefore, string $textAfter, string $apiKey, string $model = 'gpt-4o-mini'): ?int {
    if ($apiKey === '') return null;
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => '你是文件比對助手。比較兩份文件（簽名前 vs 簽名後），簽名後可能多了簽名或註記。請只回傳一個 0–100 的整數代表內容相似度，不要其他文字。'],
            ['role' => 'user', 'content' => "【簽名前】\n" . $textBefore . "\n\n【簽名後】\n" . $textAfter]
        ],
        'temperature' => 0.2,
        'max_tokens' => 10
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $httpCode >= 400) return null;
    $data = json_decode($response, true);
    $content = trim($data['choices'][0]['message']['content'] ?? '');
    if (preg_match('/\b(\d{1,3})\b/', $content, $m)) {
        $n = (int)$m[1];
        if ($n >= 0 && $n <= 100) return $n;
    }
    return null;
}

/**
 * PHP similar_text 備援（無論文字長度，一律回傳 0–100）
 */
function verify_sign_text_similarity_fallback(string $textBefore, string $textAfter): int {
    $t1 = trim($textBefore);
    $t2 = trim($textAfter);
    if ($t1 === '' && $t2 === '') return 0;
    $percent = 0.0;
    similar_text($t1, $t2, $percent);
    return (int)round($percent);
}
