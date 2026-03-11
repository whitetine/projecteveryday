<?php
// includes/node_api_client.php
// PHP( InfinityFree ) -> Node API (Render/Localhost)

if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * 呼叫 Node API
 * @param string $method GET/POST/PUT/DELETE
 * @param string $path   例如 /health, /auth/login
 * @param array|null $data 送出的 JSON body
 * @return array 解析後的 JSON
 */
function node_api(string $method, string $path, ?array $data = null): array
{
    // 1) 先用本機測試：http://localhost:3000
    // 2) 上線後換成 Render：https://xxx.onrender.com
    $base = getenv('NODE_API_BASE') ?: 'http://localhost:3000';

    $apiKey = getenv('NODE_API_KEY') ?: 'uknimprojecteverydayapitoken1';

    $url = rtrim($base, '/') . '/' . ltrim($path, '/');

    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
    ];

    // 如果你 Node 有做 JWT 保護，就把 token 帶上
    if (!empty($_SESSION['node_token'])) {
        $headers[] = 'Authorization: Bearer ' . $_SESSION['node_token'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'msg' => 'Node API curl error: ' . $err];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($raw, true);

    if (!is_array($json)) {
        return ['ok' => false, 'msg' => "Node API not JSON (HTTP $httpCode): " . mb_substr($raw, 0, 200)];
    }

    // 可選：把 httpCode 附上方便 debug
    $json['_http'] = $httpCode;
    return $json;
}
