<?php
// 統一 JSON 成功
function json_ok(array $data = [], int $status = 200) {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}
// 統一 JSON 失敗（仍回 200，避免前端視為「連線錯誤」）
function json_err(string $msg, string $code = 'ERROR', int $status = 400) {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'code'=>$code,'msg'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

// 安全取得原始 JSON
function read_json_body(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// 關閉錯誤輸出（避免把 JSON 打壞；若要看錯誤，請看伺服器 log）
if (!headers_sent()) {
    ini_set('display_errors', '0');
}
