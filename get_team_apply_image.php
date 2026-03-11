<?php
/**
 * 組隊申請表圖片代理 - 獨立入口，確保路徑正確
 * 使用方式：get_team_apply_image.php?tap_ID=123
 */
session_start();
require_once __DIR__ . '/includes/pdo.php';
require_once __DIR__ . '/config/path.php';

$tap_ID = (int)($_GET['tap_ID'] ?? 0);
if ($tap_ID <= 0) { http_response_code(400); exit; }
if (!isset($_SESSION['u_ID'])) { http_response_code(401); exit; }

$stmt = $conn->prepare("SELECT tap_url FROM teamapply WHERE tap_ID = ?");
$stmt->execute([$tap_ID]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['tap_url'])) { http_response_code(404); exit; }

$relPath = trim($row['tap_url']);
if (strpos($relPath, 'http') === 0) { header('Location: ' . $relPath); exit; }
$relPath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relPath), '/\\');
$fullPath = BASE_PATH . DIRECTORY_SEPARATOR . $relPath;

if (!file_exists($fullPath) || !is_readable($fullPath)) { http_response_code(404); exit; }

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'tiff' => 'image/tiff', 'tif' => 'image/tiff', 'ico' => 'image/x-icon', 'heic' => 'image/heic', 'avif' => 'image/avif', 'pdf' => 'application/pdf'];
if (isset($mimes[$ext])) { header('Content-Type: ' . $mimes[$ext]); }
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
exit;
