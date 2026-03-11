<?php
session_start();
require '../includes/pdo.php';
date_default_timezone_set('Asia/Taipei');

// 開發輔助
ini_set('display_errors', '1');
error_reporting(E_ALL);

// function go($url) {
//     if (!headers_sent()) { header("Location: $url"); exit; }
//     echo "<script>location.href=".json_encode($url).";</script>";
//     echo "<meta http-equiv='refresh' content='0;url=".htmlspecialchars($url,ENT_QUOTES)."'>";
//     exit;
// }

function go_hash(string $hashPath) {
    // 目標：/專案根/main.php#<hashPath>
    // 假設 work_save.php 位在 /pages/ 底下，回上一層就是專案根
    $to = dirname($_SERVER['SCRIPT_NAME']); // /myproject/.../pages
    $root = rtrim($to, '/').'/..';          // /myproject/.../（上一層）
    $url = $root.'/main.php#'.ltrim($hashPath, '#');

    if (!headers_sent()) { header("Location: $url"); exit; }
    // 保險寫法：就算 header 已送出，也能跳轉
    echo "<script>top.location.href=".json_encode($url).";</script>";
    echo "<meta http-equiv='refresh' content='0;url=".htmlspecialchars($url,ENT_QUOTES)."'>";
    exit;
}


function dlog($m) {
    $logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . DIRECTORY_SEPARATOR . 'work_debug_' . date('Ymd') . '.log';
    @file_put_contents($logFile, date('c') . " | $m\n", FILE_APPEND);
}

// POST 過大偵測
if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH']>0 && empty($_POST) && empty($_FILES)) {
    echo "<div style='padding:1rem;font-family:system-ui'>
            <h3>送出失敗：表單內容過大</h3>
            <p>請把 php.ini 設為 <code>upload_max_filesize ≥ 60M</code>、
            <code>post_max_size ≥ 64M</code> 後重啟伺服器。</p>
          </div>";
    exit;
}

if (!isset($_SESSION['u_ID'])) { 
    echo "<script>alert('請先登入');location.href='login.php';</script>"; 
    exit; 
}

$TABLE   = 'workdata';
$u_ID    = $_SESSION['u_ID'];
$action  = $_POST['action'] ?? 'save';   // save / submit / remove_file
if ($action === 'draft') $action = 'save';

$work_ID = trim($_POST['work_ID'] ?? $_POST['work_id'] ?? '');

$titleProvided   = array_key_exists('work_title', $_POST);
$contentProvided = array_key_exists('work_content', $_POST);
$title   = $titleProvided   ? ($_POST['work_title']   ?? '') : null;
$content = $contentProvided ? ($_POST['work_content'] ?? '') : null;

dlog("enter | u=$u_ID | action=$action | work_ID=$work_ID | hasTitle=".intval($titleProvided)." | hasContent=".intval($contentProvided));

/* ===== 移除附件 ===== */
if ($action === 'remove_file') {
    if ($work_ID==='') go_hash("pages/work_form.php?msg=".rawurlencode("找不到要清空的紀錄"));

    $st = $conn->prepare("SELECT work_ID,u_ID,work_url,work_status FROM `$TABLE` WHERE work_ID=? AND u_ID=? LIMIT 1");
    $st->execute([$work_ID,$u_ID]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) go_hash("pages/work_form.php?msg=".rawurlencode("紀錄不存在"));
    if ((int)$row['work_status']===3) go_hash("pages/work_form.php?msg=".rawurlencode("已結案的紀錄不可移除檔案"));

    if (!empty($row['work_url'])) {
        $abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/','\\'], DIRECTORY_SEPARATOR, $row['work_url']);
        if (is_file($abs)) @unlink($abs);
    }
    $st = $conn->prepare("UPDATE `$TABLE` SET work_url=NULL, work_update_d=NOW() WHERE work_ID=? AND u_ID=?");
    $st->execute([$row['work_ID'],$u_ID]);
    go_hash("pages/work_form.php?msg=".rawurlencode("已移除現有檔案"));
}

/* ===== 清掉前幾天暫存 ===== */
$st = $conn->prepare("
    UPDATE `$TABLE` SET work_status=3
    WHERE u_ID=? AND work_status=1 AND DATE(work_created_d)<CURDATE()
");
$st->execute([$u_ID]);

/* ===== 找今日紀錄 ===== */
$todayRow = null;
if ($work_ID!=='') {
    $st = $conn->prepare("SELECT * FROM `$TABLE` WHERE work_ID=? AND u_ID=? LIMIT 1");
    $st->execute([$work_ID,$u_ID]);
    $todayRow = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$todayRow) {
    $st = $conn->prepare("
        SELECT * FROM `$TABLE`
        WHERE u_ID=? AND DATE(work_created_d)=CURDATE()
        ORDER BY work_ID DESC LIMIT 1
    ");
    $st->execute([$u_ID]);
    $todayRow = $st->fetch(PDO::FETCH_ASSOC);
}
dlog("foundToday=".($todayRow?('#'.$todayRow['work_ID'].' st='.$todayRow['work_status']):'none'));

// 今日已結案就擋
if ($todayRow && (int)$todayRow['work_status']===3) {
    go_hash('pages/work_form.php?msg='.rawurlencode('今日已結案，無法再修改'));
}


/* ===== 檔案上傳處理 ===== */
$MAX_SIZE  = 50 * 1024 * 1024;
$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'file';
$uploadWeb = 'file';
if (!is_dir($uploadDir)) { @mkdir($uploadDir,0775,true); }

$newFilePathForDB = null;
$deleteOldPath    = null;
$destPath         = null;

if (isset($_FILES['work_file']) && $_FILES['work_file']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['work_file'];
    if ($f['error'] !== UPLOAD_ERR_OK) go_hash("pages/work_form.php?msg=".rawurlencode("上傳錯誤代碼：".$f['error']));
    if ($f['size']  > $MAX_SIZE)      go_hash("pages/work_form.php?msg=".rawurlencode("檔案超過 50MB，請壓縮或改用雲端連結"));
    if (!is_uploaded_file($f['tmp_name'])) go_hash("pages/work_form.php?msg=".rawurlencode("非法上傳，已拒絕"));

    $ext     = pathinfo($f['name'], PATHINFO_EXTENSION);
    $ext     = $ext ? ('.'.$ext) : '';
    $safeUid = preg_replace('/[^A-Za-z0-9_\-]/','',$u_ID);
    $newName = date('Ymd_His').'_'.$safeUid.'_'.bin2hex(random_bytes(4)).$ext;

    $destPath = $uploadDir.DIRECTORY_SEPARATOR.$newName;
    if (!move_uploaded_file($f['tmp_name'],$destPath)) go_hash("pages/work_form.php?msg=".rawurlencode("檔案搬移失敗"));
    @chmod($destPath,0644);
    $newFilePathForDB = $uploadWeb.'/'.$newName;

    // 成功後刪舊檔
    if ($todayRow && !empty($todayRow['work_url'])) {
        $oldAbs = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/','\\'], DIRECTORY_SEPARATOR, $todayRow['work_url']);
        if (is_file($oldAbs)) $deleteOldPath = $oldAbs;
    }
}

/* ===== DB 儲存邏輯 ===== */
try {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->beginTransaction();

    if ($todayRow) {
        // 更新
        $titleToSet   = $titleProvided   ? $title   : $todayRow['work_title'];
        $contentToSet = $contentProvided ? $content : $todayRow['work_content'];

        if ($action==='submit') {
            $sql = $newFilePathForDB
                ? "UPDATE `$TABLE` SET work_title=?, work_content=?, work_url=?, work_status=3, work_update_d=NOW() WHERE work_ID=? AND u_ID=?"
                : "UPDATE `$TABLE` SET work_title=?, work_content=?, work_status=3, work_update_d=NOW() WHERE work_ID=? AND u_ID=?";
            $params = $newFilePathForDB
                ? [$titleToSet,$contentToSet,$newFilePathForDB,$todayRow['work_ID'],$u_ID]
                : [$titleToSet,$contentToSet,$todayRow['work_ID'],$u_ID];
        } else { // save
            $sql = $newFilePathForDB
                ? "UPDATE `$TABLE` SET work_title=?, work_content=?, work_url=?, work_status=1, work_update_d=NOW() WHERE work_ID=? AND u_ID=?"
                : "UPDATE `$TABLE` SET work_title=?, work_content=?, work_status=1, work_update_d=NOW() WHERE work_ID=? AND u_ID=?";
            $params = $newFilePathForDB
                ? [$titleToSet,$contentToSet,$newFilePathForDB,$todayRow['work_ID'],$u_ID]
                : [$titleToSet,$contentToSet,$todayRow['work_ID'],$u_ID];
        }
        $st = $conn->prepare($sql); 
        $st->execute($params);

    } else {
        // 新增
        $status = ($action==='submit') ? 3 : 1;
        $sql = "INSERT INTO `$TABLE` 
                (u_ID, work_title, work_content, work_url, work_status, work_created_d, work_update_d)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
        $st = $conn->prepare($sql);
        $st->execute([$u_ID, $title ?? '', $content ?? '', $newFilePathForDB, $status]);
    }

    $conn->commit();

    // 成功後刪舊檔
    if ($deleteOldPath && is_file($deleteOldPath)) { @unlink($deleteOldPath); }

    // 導向
    $msg = ($action==='submit') ? "已正式送出並結案" : "已暫存";
    if ($action==='save') {
        go_hash("pages/work_draft.php?msg=".urlencode($msg));
    } else {
        go_hash("pages/work_form.php?msg=".urlencode($msg));
    }   

} catch(Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    if ($destPath && is_file($destPath)) @unlink($destPath);
    dlog("EXCEPTION: ".$e->getMessage());
    echo "<pre style='white-space:pre-wrap'>儲存失敗：".$e->getMessage()."</pre>";
    exit;
}
