<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/pdo.php';

function json_out($ok, $msg='', $data=null){
  echo json_encode(['ok'=>$ok, 'msg'=>$msg, 'data'=>$data], JSON_UNESCAPED_UNICODE);
  exit;
}

// 你可以在這裡做權限檢查（科辦/主任才可）
$role_ID = $_SESSION['role_ID'] ?? 0;
// if ($role_ID != 2) json_out(false, '無權限');

$do = $_GET['do'] ?? '';

if ($do === 'get_teamapply_fields') {
  $cohort_ID = $_GET['cohort_ID'] ?? null;

  // cohort_ID 若空：同時回傳 NULL（共用）+ 指定屆別
  if ($cohort_ID === null || $cohort_ID === '') {
    $stmt = $conn->prepare("SELECT * FROM teamapply_field WHERE cohort_ID IS NULL ORDER BY field_order, field_ID");
    $stmt->execute();
  } else {
    $stmt = $conn->prepare("
      SELECT * FROM teamapply_field
      WHERE cohort_ID IS NULL OR cohort_ID = ?
      ORDER BY COALESCE(cohort_ID, -1), field_order, field_ID
    ");
    $stmt->execute([$cohort_ID]);
  }

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // JSON 欄位回傳成字串（前端 textarea 好用）
  foreach ($rows as &$r) {
    if (isset($r['options_json']) && $r['options_json'] !== null && $r['options_json'] !== '') {
      $r['options_json'] = is_string($r['options_json']) ? $r['options_json'] : json_encode($r['options_json'], JSON_UNESCAPED_UNICODE);
    }
    if (isset($r['rules_json']) && $r['rules_json'] !== null && $r['rules_json'] !== '') {
      $r['rules_json'] = is_string($r['rules_json']) ? $r['rules_json'] : json_encode($r['rules_json'], JSON_UNESCAPED_UNICODE);
    }
  }
  json_out(true, '', $rows);
}

if ($do === 'get_teamapply_field_one') {
  $field_ID = (int)($_GET['field_ID'] ?? 0);
  if (!$field_ID) json_out(false, 'field_ID 缺失');

  $stmt = $conn->prepare("SELECT * FROM teamapply_field WHERE field_ID=? LIMIT 1");
  $stmt->execute([$field_ID]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_out(false, '找不到欄位');

  if ($row['options_json'] !== null && $row['options_json'] !== '') {
    $row['options_json'] = is_string($row['options_json']) ? $row['options_json'] : json_encode($row['options_json'], JSON_UNESCAPED_UNICODE);
  }
  if ($row['rules_json'] !== null && $row['rules_json'] !== '') {
    $row['rules_json'] = is_string($row['rules_json']) ? $row['rules_json'] : json_encode($row['rules_json'], JSON_UNESCAPED_UNICODE);
  }

  json_out(true, '', $row);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];

if ($do === 'save_teamapply_field') {
  $field_ID    = $body['field_ID'] ?? null;
  $cohort_ID   = $body['cohort_ID'] ?? null;
  $field_key   = trim($body['field_key'] ?? '');
  $field_label = trim($body['field_label'] ?? '');
  $field_type  = trim($body['field_type'] ?? 'text');
  $field_order = (int)($body['field_order'] ?? 0);
  $is_enabled  = (int)($body['is_enabled'] ?? 1);
  $is_required = (int)($body['is_required'] ?? 0);
  $placeholder = $body['placeholder'] ?? null;
  $help_text   = $body['help_text'] ?? null;
  $options_json = $body['options_json'] ?? null;
  $rules_json   = $body['rules_json'] ?? null;

  if ($field_key === '' || $field_label === '') json_out(false, 'field_key / field_label 不能空');

  // 驗 JSON（若有填）
  foreach (['options_json'=>$options_json, 'rules_json'=>$rules_json] as $k=>$v) {
    if ($v !== null && trim($v) !== '') {
      json_decode($v, true);
      if (json_last_error() !== JSON_ERROR_NONE) json_out(false, $k.' JSON 格式錯誤');
    } else {
      $$k = null;
    }
  }

  try {
    if ($field_ID) {
      // 更新（不允許改 key，避免已存在答案對不上）
      $stmt = $conn->prepare("
        UPDATE teamapply_field
        SET field_label=?, field_type=?, field_order=?, is_enabled=?, is_required=?,
            placeholder=?, help_text=?, options_json=?, rules_json=?
        WHERE field_ID=?
      ");
      $stmt->execute([$field_label,$field_type,$field_order,$is_enabled,$is_required,$placeholder,$help_text,$options_json,$rules_json,$field_ID]);
      json_out(true, '已更新');
    } else {
      // 新增
      $stmt = $conn->prepare("
        INSERT INTO teamapply_field
          (cohort_ID, field_key, field_label, field_type, field_order, is_enabled, is_required, placeholder, help_text, options_json, rules_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
      ");
      $stmt->execute([$cohort_ID,$field_key,$field_label,$field_type,$field_order,$is_enabled,$is_required,$placeholder,$help_text,$options_json,$rules_json]);
      json_out(true, '已新增');
    }
  } catch (PDOException $e) {
    // 常見：uq_cohort_key 重複
    json_out(false, '儲存失敗：可能是 field_key 重複或資料有誤');
  }
}

if ($do === 'toggle_teamapply_field') {
  $field_ID = (int)($body['field_ID'] ?? 0);
  $mode = $body['mode'] ?? '';
  if (!$field_ID) json_out(false, 'field_ID 缺失');

  if ($mode === 'enabled') {
    $conn->prepare("UPDATE teamapply_field SET is_enabled = 1 - is_enabled WHERE field_ID=?")->execute([$field_ID]);
    json_out(true, 'ok');
  }
  if ($mode === 'required') {
    $conn->prepare("UPDATE teamapply_field SET is_required = 1 - is_required WHERE field_ID=?")->execute([$field_ID]);
    json_out(true, 'ok');
  }
  json_out(false, 'mode 不正確');
}

if ($do === 'set_teamapply_field_order') {
  $field_ID = (int)($body['field_ID'] ?? 0);
  $field_order = (int)($body['field_order'] ?? 0);
  if (!$field_ID) json_out(false, 'field_ID 缺失');
  $conn->prepare("UPDATE teamapply_field SET field_order=? WHERE field_ID=?")->execute([$field_order,$field_ID]);
  json_out(true, 'ok');
}

if ($do === 'delete_teamapply_field') {
  $field_ID = (int)($body['field_ID'] ?? 0);
  if (!$field_ID) json_out(false, 'field_ID 缺失');

  // 擋：若答案表已有使用紀錄，不允許刪（避免資料斷裂）
  // 你如果答案表叫 teamapply_answer，這裡就用那張
  $stmt = $conn->prepare("SELECT COUNT(*) FROM teamapply_answer WHERE field_ID=?");
  $stmt->execute([$field_ID]);
  $cnt = (int)$stmt->fetchColumn();
  if ($cnt > 0) json_out(false, '已有申請填寫過此欄位，禁止刪除（可改用停用）');

  $conn->prepare("DELETE FROM teamapply_field WHERE field_ID=?")->execute([$field_ID]);
  json_out(true, 'ok');
}

json_out(false, '未知 do');
