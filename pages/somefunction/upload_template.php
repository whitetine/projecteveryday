<?php
//1014update15:43
ob_clean();
// ini_set('display_errors', 1);
// 關閉錯誤訊息顯示
ini_set('display_errors', 0);
//-------------
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once "../../includes/pdo.php";// 這裡會給你 $conn (PDO)

try {
  // 1) 取表單欄位（文字 + 檔案）
  $f_name = $_POST['f_name'] ?? '';
  $file   = $_FILES['file'] ?? null;

  if ($f_name === '' || !$file || $file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => '缺少表單名稱或檔案錯誤']);
    exit;
  }

  // 2) 僅允許 PDF
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if ($ext !== 'pdf') {
    echo json_encode(['status' => 'error', 'message' => '只允許上傳 PDF 檔案']);
    exit;
  }

  //1014update16:08限制檔案大小
  if($file['size'] > 10485760){
    echo json_encode(['status' => 'error','messsage' =>'檔案過大,最大允許10MB']);
    exit;
  }


  // 3) 產生安全檔名
  $safeBase = preg_replace("/[^a-zA-Z0-9-_\.]/", "", pathinfo($file['name'], PATHINFO_FILENAME));
  $newName  = uniqid($safeBase . '_') . '.pdf';

  // 4) 上傳路徑（專案根的 /templates/）
  //    本檔案在 pages/somefunction/，退兩層到專案根
  $uploadDir  = dirname(__DIR__, 2) . '/templates/';
  $uploadPath = $uploadDir . $newName;
  $dbPath     = 'templates/' . $newName; // 存到 DB 的相對路徑

  if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
      echo json_encode(['status' => 'error', 'message' => '無法建立上傳資料夾']);
      exit;
    }
  }

  if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
    echo json_encode(['status' => 'error', 'message' => '檔案儲存失敗']);
    exit;
  }

  // 5) 寫入 DB（資料表 filedata）
  $sql = "INSERT INTO `filedata`
          (`file_name`, `file_url`, `file_other`, `is_top`, `file_update_d`, `file_status`)
        VALUES
          (?, ?, ?, ?, NOW(), ?)";
  $stmt = $conn->prepare($sql);
  $stmt->execute([
    $f_name,   // f_name
    $dbPath,   // file_url（相對路徑）
    'pdf',     // file_other（檔案格式）
    0,         // is_top：預設不置頂
    1          // file_status：預設啟用
  ]);

  echo json_encode([
    'status'  => 'success',
    'file_ID' => $conn->lastInsertId()
  ]);
} catch (Throwable $e) {
  echo json_encode(['status' => 'error', 'message' => '伺服器錯誤：' . $e->getMessage()]);
}
