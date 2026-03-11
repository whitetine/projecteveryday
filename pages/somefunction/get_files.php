<?php
header('Content-Type: application/json; charset=utf-8');
require_once "../../includes/pdo.php";

try {
  $sql = "SELECT file_ID, file_name, file_url, file_status, is_top, file_update_d
          FROM filedata
          ORDER BY is_top DESC, file_update_d DESC";
  $stmt = $conn->query($sql);
  $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // 強制型別
  foreach ($files as &$f) {
    $f['file_ID']     = (int)$f['file_ID'];
    $f['file_status'] = (int)$f['file_status'];
    $f['is_top']      = (int)$f['is_top'];
  }

  echo json_encode($files, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
