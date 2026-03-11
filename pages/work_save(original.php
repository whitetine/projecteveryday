<?php
session_start();
require '../includes/pdo.php';

date_default_timezone_set('Asia/Taipei');

//開發輔助
ini_set('display_errors','1'); error_reporting(E_ALL);
function go($url){
  if (!headers_sent()) { header("Location: $url"); exit; }
  echo "<script>location.href=".json_encode($url).";</script>";
  echo "<meta http-equiv='refresh' content='0;url=".htmlspecialchars($url,ENT_QUOTES)."'>";
  exit;
}
function dlog($m){
  $logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
  if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
  }
  $logFile = $logDir . DIRECTORY_SEPARATOR . 'work_debug_' . date('Ymd') . '.log';
  @file_put_contents($logFile, date('c') . " | $m\n", FILE_APPEND);
}
//POST過大偵測（避免看似「沒反應」
if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH']>0 && empty($_POST) && empty($_FILES)) {
  echo "<div style='padding:1rem;font-family:system-ui'>
          <h3>送出失敗：表單內容過大</h3>
          <p>請把 php.ini 設為 <code>upload_max_filesize ≥ 60M</code>、<code>post_max_size ≥ 64M</code> 後重啟伺服器。</p>
        </div>";
  exit;
}

if (!isset($_SESSION['u_ID'])) { echo "<script>alert('請先登入');location.href='login.php';</script>"; exit; }

$TABLE   = 'workdata';
$u_ID    = $_SESSION['u_ID'];
$action  = $_POST['action'] ?? 'save';      //draft/save/submit/remove_file
if ($action === 'draft') $action = 'save';

$work_ID = isset($_POST['work_ID']) ? trim($_POST['work_ID']) : '';
$titleProvided   = array_key_exists('work_title', $_POST);
$contentProvided = array_key_exists('work_content', $_POST);
$title   = $titleProvided   ? ($_POST['work_title']   ?? '') : null;
$content = $contentProvided ? ($_POST['work_content'] ?? '') : null;

dlog("enter | u=$u_ID | action=$action | work_ID=$work_ID | hasTitle=".intval($titleProvided)." | hasContent=".intval($contentProvided));

//移除現有檔案
if ($action === 'remove_file') {
  if ($work_ID==='') go("pages/work_form.php?msg=".urlencode("找不到要清空的紀錄"));
  $st = $conn->prepare("SELECT work_ID,u_ID,work_url,work_status FROM `$TABLE` WHERE work_ID=? AND u_ID=? LIMIT 1");
  $st->execute([$work_ID,$u_ID]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) go("pages/work_form.php?msg=".urlencode("紀錄不存在"));
  if ((int)$row['work_status']===3) go("pages/work_form.php?msg=".urlencode("已結案的紀錄不可移除檔案"));

  if (!empty($row['work_url'])) {
    $abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/','\\'], DIRECTORY_SEPARATOR, $row['work_url']);
    if (is_file($abs)) @unlink($abs);
  }
  $st = $conn->prepare("UPDATE `$TABLE` SET work_url=NULL, work_update_d=NOW() WHERE work_ID=? AND u_ID=?");
  $st->execute([$row['work_ID'],$u_ID]);
  go("pages/work_form.php?msg=".urlencode("已移除現有檔案"));
}

//清掉前幾天暫存
$st = $conn->prepare("UPDATE `$TABLE` SET work_status=3
                      WHERE u_ID=? AND work_status=1 AND DATE(work_created_d)<CURDATE()");
$st->execute([$u_ID]);

//找本次要處理的紀錄（先 work_id，否則取今天)
$todayRow = null;
if ($work_ID!=='') {
  $st = $conn->prepare("SELECT * FROM `$TABLE` WHERE work_ID=? AND u_ID=? LIMIT 1");
  $st->execute([$work_ID,$u_ID]);
  $todayRow = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$todayRow) {
  $st = $conn->prepare("SELECT * FROM `$TABLE`
                        WHERE u_ID=? AND DATE(work_created_d)=CURDATE()
                        ORDER BY work_ID DESC LIMIT 1");
  $st->execute([$u_ID]);
  $todayRow = $st->fetch(PDO::FETCH_ASSOC);
}
dlog("foundToday=".($todayRow?('#'.$todayRow['work_ID'].' st='.$todayRow['work_status']):'none'));

//今天已結案就擋 
if ($todayRow && (int)$todayRow['work_status']===3) {
  go("pages/work_form.php?msg=".urlencode("今日已結案，無法再修改"));
}

//檔案上傳（可選）
$MAX_SIZE  = 50 * 1024 * 1024;
$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'file';
$uploadWeb = 'file';
if (!is_dir($uploadDir)) { @mkdir($uploadDir,0775,true); }

$newFilePathForDB = null;
$deleteOldPath    = null;
$destPath         = null;

if (isset($_FILES['work_file']) && $_FILES['work_file']['error'] !== UPLOAD_ERR_NO_FILE) {
  $f = $_FILES['work_file'];
  if ($f['error'] !== UPLOAD_ERR_OK) go("pages/work_form.php?msg=".urlencode("上傳錯誤代碼：".$f['error']));
  if ($f['size']  > $MAX_SIZE)      go("pages/work_form.php?msg=".urlencode("檔案超過 50MB，請壓縮或改用雲端連結"));
  if (!is_uploaded_file($f['tmp_name'])) go("pages/work_form.php?msg=".urlencode("非法上傳，已拒絕"));

  $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
  $ext = $ext?('.'.$ext):'';
  $safeUid = preg_replace('/[^A-Za-z0-9_\-]/','',$u_ID);
  $newName = date('Ymd_His').'_'.$safeUid.'_'.bin2hex(random_bytes(4)).$ext;

  $destPath = $uploadDir.DIRECTORY_SEPARATOR.$newName;
  if (!move_uploaded_file($f['tmp_name'],$destPath)) go("pages/work_form.php?msg=".urlencode("檔案搬移失敗"));
  @chmod($destPath,0644);
  $newFilePathForDB = $uploadWeb.'/'.$newName;

  //DB 成功後才刪舊檔（覆蓋舊檔的情境）
  if ($todayRow && !empty($todayRow['work_url'])) {
    $oldAbs = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/','\\'], DIRECTORY_SEPARATOR, $todayRow['work_url']);
    if (is_file($oldAbs)) $deleteOldPath = $oldAbs;
  }
}

try{
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $conn->beginTransaction();

  if ($todayRow) {
    // 更新既有
    $titleToSet   = $titleProvided   ? $title   : $todayRow['work_title'];
    $contentToSet = $contentProvided ? $content : $todayRow['work_content'];

    if ($action==='submit') {
      if ($newFilePathForDB) {
        $sql = "UPDATE `$TABLE` SET work_title=?, work_content=?, work_url=?, work_status=3, work_update_d=NOW()
                WHERE work_ID=? AND u_ID=?";
        $params = [$titleToSet,$contentToSet,$newFilePathForDB,$todayRow['work_ID'],$u_ID];
      } else {
        $sql = "UPDATE `$TABLE` SET work_title=?, work_content=?, work_status=3, work_update_d=NOW()
                WHERE work_ID=? AND u_ID=?";
        $params = [$titleToSet,$contentToSet,$todayRow['work_ID'],$u_ID];
      }
    } else { // save
      if ($newFilePathForDB) {
        $sql = "UPDATE `$TABLE` SET work_title=?, work_content=?, work_url=?, work_status=1, work_update_d=NOW()
                WHERE work_ID=? AND u_ID=?";
        $params = [$titleToSet,$contentToSet,$newFilePathForDB,$todayRow['work_ID'],$u_ID];
      } else {
        $sql = "UPDATE `$TABLE` SET work_title=?, work_content=?, work_status=1, work_update_d=NOW()
                WHERE work_ID=? AND u_ID=?";
        $params = [$titleToSet,$contentToSet,$todayRow['work_ID'],$u_ID];
      }
    }
    $st = $conn->prepare($sql); $st->execute($params);

  } else {
    //新增第一筆（今天）
    $status = ($action==='submit') ? 3 : 1;
    $sql = "INSERT INTO `$TABLE` (u_ID, work_title, work_content, work_url, work_status, work_created_d, work_update_d)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
    $st  = $conn->prepare($sql);
    $st->execute([$u_ID, $title ?? '', $content ?? '', $newFilePathForDB, $status]);
  }

  $conn->commit();

  //成功後才刪舊檔
  if ($deleteOldPath && is_file($deleteOldPath)) { @unlink($deleteOldPath); }

  //導向：暫存→草稿列表；送出→回表單
  $msg = ($action==='submit') ? "已正式送出並結案" : "已暫存";
  if ($action==='save') {
    go("work_draft.php?msg=".urlencode($msg));
  } else {
    go("pages/work_form.php?msg=".urlencode($msg));
  }

} catch(Throwable $e){
  if ($conn->inTransaction()) $conn->rollBack();
  if ($destPath && is_file($destPath)) @unlink($destPath);
  dlog("EXCEPTION: ".$e->getMessage());
  echo "<pre style='white-space:pre-wrap'>儲存失敗：".$e->getMessage()."</pre>";
  exit;
}
