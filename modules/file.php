<?php
global $conn;
$p = $_POST;
$do = $_GET['do'] ?? '';

switch ($do) {
    // 舊 API（apply.php 用）：啟用檔案列表
case 'get_all_TemplatesFile':
    $rows = $conn->query("SELECT * FROM filedata WHERE file_status=1")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);   // ← 直接回陣列
    exit;

   case 'get_files':
      try {
        $rows = $conn->query("
            SELECT file_ID, file_name, file_url, file_status, is_top, file_update_d
            FROM filedata
            ORDER BY is_top DESC, file_ID DESC     -- ← 重點
        ")->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'資料讀取失敗：'.$e->getMessage()]);
        exit;
    }
    // 狀態/置頂切換
    case 'update_template':
        $req = read_json_body();
        $file_ID     = intval($req['file_ID']     ?? 0);
        $file_status = intval($req['file_status'] ?? 0); // 0/1
        $is_top      = intval($req['is_top']      ?? 0); // 0/1
        if ($file_ID <= 0) json_err('file_ID 無效');

        try {
            $stmt = $conn->prepare("
                UPDATE filedata
                SET file_status = ?, is_top = ?, file_update_d = NOW()
                WHERE file_ID = ?
            ");
            $stmt->execute([$file_status, $is_top, $file_ID]);
            json_ok();
        } catch (Throwable $e) {
            json_err('更新失敗：'.$e->getMessage());
        }
        break;

    // 上傳 PDF（相容 f_name 舊欄位）
    case 'upload_template':
        $file_name = trim($p['file_name'] ?? ($p['f_name'] ?? ''));
        if ($file_name === '') json_err('缺少表單名稱');
        if (empty($_FILES['file']['name'])) json_err('請選擇要上傳的檔案');

        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') json_err('只允許上傳 PDF');

        $dir = __DIR__ . '/../templates';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) json_err('伺服器目錄建立失敗');

        $saveName = 'tpl_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
        $savePath = $dir . '/' . $saveName;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $savePath)) {
            json_err('檔案儲存失敗（權限或磁碟空間不足）');
        }

        $file_url = 'templates/' . $saveName;

        try {
            $stmt = $conn->prepare("
                INSERT INTO filedata (file_name, file_url, file_status, is_top, file_update_d)
                VALUES (?, ?, 1, 0, NOW())
            ");
            $stmt->execute([$file_name, $file_url]);
            json_ok(['file_ID' => (int)$conn->lastInsertId(), 'file_url' => $file_url]);
        } catch (Throwable $e) {
            @unlink($savePath);
            json_err('資料寫入失敗：'.$e->getMessage());
        }
        break;

    // 只取啟用的檔案（apply.php / file.php）
case 'listActiveFiles':
      try {
        $rows = $conn->query("
            SELECT file_ID, file_name, file_url
            FROM filedata
            WHERE file_status = 1
            ORDER BY is_top DESC, file_ID DESC     -- ← 重點
        ")->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'資料讀取失敗：'.$e->getMessage()]);
        exit;
    }
}
