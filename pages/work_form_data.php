<?php
session_start();
require '../includes/pdo.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['u_ID'])) {
    echo json_encode(['success' => false, 'msg' => '尚未登入']);
    exit;
}

date_default_timezone_set('Asia/Taipei');
$u_id  = $_SESSION['u_ID'];
$TABLE = 'workdata';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function response($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($action === 'get') {
        // 將前幾天未送出的暫存改為結案
        $st = $conn->prepare("UPDATE `$TABLE` 
                              SET work_status = 3 
                              WHERE work_u_ID = ? AND work_status = 1 AND DATE(work_update_d) < CURDATE()");
        $st->execute([$u_id]);

        // 取得今日資料
        $st = $conn->prepare("SELECT * FROM `$TABLE` 
                              WHERE work_u_ID = ? AND DATE(work_update_d) = CURDATE()
                              ORDER BY work_ID DESC LIMIT 1");
        $st->execute([$u_id]);
        $today = $st->fetch(PDO::FETCH_ASSOC);
        $readOnly = $today && intval($today['work_status']) === 3;

        response([
            'success' => true,
            'work' => $today ?: [],
            'readOnly' => $readOnly
        ]);
    }

    if ($action === 'save' || $action === 'submit') {
        $work_id = $_POST['work_id'] ?? null;
        $title = trim($_POST['work_title'] ?? '');
        $content = trim($_POST['work_content'] ?? '');
        $status = ($action === 'submit') ? 3 : 1;

        if (empty($title) || empty($content)) {
            response(['success' => false, 'msg' => '標題與內容不得為空']);
        }

        if ($work_id) {
            // 更新現有記錄
            $st = $conn->prepare("UPDATE `$TABLE`
                                  SET work_title=?, work_content=?, work_status=?, work_update_d=NOW()
                                  WHERE work_ID=? AND work_u_ID=?");
            $st->execute([$title, $content, $status, $work_id, $u_id]);
            
            if ($st->rowCount() === 0) {
                response(['success' => false, 'msg' => '更新失敗：找不到對應的記錄或無權限']);
            }
        } else {
            // 插入新記錄
            $st = $conn->prepare("INSERT INTO `$TABLE`
                                  (work_u_ID, work_title, work_content, work_status, work_update_d)
                                  VALUES (?, ?, ?, ?, NOW())");
            $st->execute([$u_id, $title, $content, $status]);
            
            if ($st->rowCount() === 0) {
                response(['success' => false, 'msg' => '插入失敗：無法建立新記錄']);
            }
        }

        response([
            'success' => true,
            'msg' => $status == 3 ? '已正式送出並結案' : '已暫存成功',
            'reload' => true
        ]);
    }

    response(['success' => false, 'msg' => '未知操作']);
} catch (PDOException $e) {
    error_log('Database error in work_form_data.php: ' . $e->getMessage());
    response(['success' => false, 'msg' => '資料庫錯誤：' . $e->getMessage()]);
} catch (Exception $e) {
    error_log('Error in work_form_data.php: ' . $e->getMessage());
    response(['success' => false, 'msg' => '伺服器錯誤：' . $e->getMessage()]);
}
