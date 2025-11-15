<?php
header('Content-Type: application/json; charset=utf-8');

session_start();
require '../includes/pdo.php';
date_default_timezone_set('Asia/Taipei');

if (!isset($_SESSION['u_ID'])) {
  echo json_encode(['ok'=>false, 'msg'=>'未登入']);
  exit;
}

$u_ID  = $_SESSION['u_ID'];
$TABLE = 'workdata';

try {
  // 關掉前幾天仍為暫存(1) 的紀錄
  $st = $conn->prepare("UPDATE `$TABLE`
                        SET work_status = 3
                        WHERE u_ID = ? AND work_status = 1 AND DATE(work_created_d) < CURDATE()");
  $st->execute([$u_ID]);

  // 取今日最新一筆
  $st = $conn->prepare("SELECT *
                        FROM `$TABLE`
                        WHERE u_ID = ? AND DATE(work_created_d) = CURDATE()
                        ORDER BY work_ID DESC LIMIT 1");
  $st->execute([$u_ID]);
  $today = $st->fetch(PDO::FETCH_ASSOC);

  $readOnly = $today && intval($today['work_status']) === 3;

  $res = [
    'ok'        => true,
    'msg'       => $_GET['msg'] ?? '',
    'readOnly'  => $readOnly,
    'today'     => $today ? [
      'work_ID'      => (int)$today['work_ID'],
      'work_title'   => $today['work_title'] ?? '',
      'work_content' => $today['work_content'] ?? '',
      'work_url'     => $today['work_url'] ?? ''
    ] : null,
  ];
  echo json_encode($res, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false, 'msg'=>'讀取失敗：'.$e->getMessage()]);
}
