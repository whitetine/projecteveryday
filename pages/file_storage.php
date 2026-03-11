<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');
//c9 
session_start();
require '../includes/pdo.php';

if (!function_exists('json_ok')) {
  function json_ok(array $data = [], int $status = 200)
  {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

if (!function_exists('json_err')) {
  function json_err(string $msg, string $code = 'ERROR', int $status = 400)
  {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'code' => $code, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

if (!function_exists('read_json_body')) {
  function read_json_body(): array
  {
    static $cache = null;
    if ($cache !== null) {
      return $cache;
    }
    $raw = $GLOBALS['__FILE_STORAGE_RAW_BODY'] ?? null;
    if ($raw === null) {
      $raw = file_get_contents('php://input') ?: '';
      $GLOBALS['__FILE_STORAGE_RAW_BODY'] = $raw;
    }
    $data = json_decode($raw, true);
    $cache = is_array($data) ? $data : [];
    return $cache;
  }
}

if (!headers_sent()) {
  ini_set('display_errors', '0');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$do = $_GET['do'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$aliasMap = [
  'listActive' => 'listActiveFiles',
  'update' => 'update_template',
  'delete' => 'batch_delete_files',
  'upload' => 'upload_file_with_targets',
];

if ($do === '' && $action !== '') {
  $do = $aliasMap[$action] ?? $do;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$rawConsumed = false;
if ($do === '' && $method === 'POST' && stripos($contentType, 'application/json') !== false) {
  $raw = file_get_contents('php://input') ?: '';
  $GLOBALS['__FILE_STORAGE_RAW_BODY'] = $raw;
  $rawConsumed = true;
  $payload = json_decode($raw, true) ?: [];
  if (!empty($payload['action']) && isset($aliasMap[$payload['action']])) {
    $do = $aliasMap[$payload['action']];
  }
}

if (!$rawConsumed && !array_key_exists('__FILE_STORAGE_RAW_BODY', $GLOBALS)) {
  $GLOBALS['__FILE_STORAGE_RAW_BODY'] = null;
}

if ($do === '' && $method === 'GET') {
  $do = 'get_files_with_targets';
}

if ($do === '') {
  json_err('Unknown action');
}

$_GET['do'] = $do;

require '../modules/file.php';

