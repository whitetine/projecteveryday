<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require '../includes/pdo.php';
date_default_timezone_set('Asia/Taipei');

if (!isset($_SESSION['u_ID'])) {
  echo json_encode(['ok'=>false,'msg'=>'未登入']); exit;
}
$u_ID  = $_SESSION['u_ID'];
$TABLE = 'workdata';

function d($s,$def){ $t=strtotime($s??''); return $t?date('Y-m-d',$t):$def; }
function dayEnd($d){ return date('Y-m-d 23:59:59', strtotime($d?:date('Y-m-d'))); }

$from  = d($_GET['from'] ?? null, date('Y-m-01'));
$to    = d($_GET['to']   ?? null, date('Y-m-d'));
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 10;
$offset = ($page - 1) * $per;

/* 保險：把前幾天仍為暫存(1) 的紀錄結案(3) */
$st = $conn->prepare("UPDATE `$TABLE` SET work_status=3
                      WHERE u_ID=? AND work_status=1 AND DATE(work_created_d)<CURDATE()");
$st->execute([$u_ID]);

/* 今日暫存（如有就顯示在最上方） */
$st = $conn->prepare("SELECT work_ID,work_title,work_content,work_url,work_created_d
                      FROM `$TABLE`
                      WHERE u_ID=? AND work_status=1
                      ORDER BY work_created_d DESC LIMIT 1");
$st->execute([$u_ID]);
$draft = $st->fetch(PDO::FETCH_ASSOC);

/* 計數（只列出已送出=3） */
$st = $conn->prepare("SELECT COUNT(*)
                      FROM `$TABLE`
                      WHERE u_ID=? AND work_status=3
                        AND work_created_d BETWEEN ? AND ?");
$st->execute([$u_ID, $from.' 00:00:00', dayEnd($to)]);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total/$per));
if ($page > $pages) $page = $pages;

/* 分頁資料 */
$sql = "SELECT work_ID,work_title,work_content,work_url,work_created_d
        FROM `$TABLE`
        WHERE u_ID=? AND work_status=3
          AND work_created_d BETWEEN ? AND ?
        ORDER BY work_created_d DESC
        LIMIT ? OFFSET ?";
$st = $conn->prepare($sql);
$st->bindValue(1, $u_ID, PDO::PARAM_STR);
$st->bindValue(2, $from.' 00:00:00', PDO::PARAM_STR);
$st->bindValue(3, dayEnd($to),       PDO::PARAM_STR);
$st->bindValue(4, (int)$per,         PDO::PARAM_INT);
$st->bindValue(5, (int)$offset,      PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  'ok'    => true,
  'page'  => $page,
  'pages' => $pages,
  'draft' => $draft ?: null,
  'rows'  => $rows,
], JSON_UNESCAPED_UNICODE);
