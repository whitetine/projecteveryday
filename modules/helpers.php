<?php
// api/helpers.php
declare(strict_types=1);
session_start();
require __DIR__ . '/../includes/pdo.php';

date_default_timezone_set('Asia/Taipei');

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fmtDt(?string $dt): string { return $dt ? date('Y-m-d H:i', strtotime($dt)) : ''; }
function toDate(?string $s, string $def): string {
  $t = strtotime($s ?? '');
  return $t ? date('Y-m-d', $t) : $def;
}
function dayEnd(?string $d): string {
  return date('Y-m-d 23:59:59', strtotime($d ?: date('Y-m-d')));
}
function requireLogin(): string {
  if (!isset($_SESSION['u_ID']) || !$_SESSION['u_ID']) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'msg'=>'請先登入']);
    exit;
  }
  return $_SESSION['u_ID'];
}
function jsonOut($data, int $status=200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}
