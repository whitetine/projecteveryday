<?php
set_time_limit(600);
ini_set('max_execution_time', '600');
ini_set('memory_limit', '1024M');

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/pdo.php';
require_once __DIR__ . '/team_timeline_helper.php';
$ai_config = require __DIR__ . '/../includes/ai_config.php';

$pdo = $conn ?? null;
if (!$pdo) { echo json_encode(['ok'=>false,'msg'=>'PDO 連線不存在'], JSON_UNESCAPED_UNICODE); exit; }

// ---- 解析輸入 ----
$raw  = file_get_contents('php://input');
$json = json_decode($raw, true);
if (!is_array($json)) $json = [];

$do = $_GET['do'] ?? $_POST['do'] ?? ($json['do'] ?? '');

$my_uid = $_SESSION['u_ID'] ?? null;
if (!$my_uid) { echo json_encode(['ok'=>false,'msg'=>'請先登入系統'], JSON_UNESCAPED_UNICODE); exit; }

$m_ID = $_POST['m_ID'] ?? $_GET['id'] ?? ($json['m_ID'] ?? 0);
$m_ID = (int)$m_ID;
$request_team_ID = (int)($_POST['team_ID'] ?? $_GET['team_ID'] ?? ($json['team_ID'] ?? 0));

// ---- OpenAI / Python API 設定 ----
$openaiKey   = $ai_config['openai_api_key'] ?? '';
$openaiModel = $ai_config['openai_model'] ?? 'gpt-4o';
$pythonTranscribeUrl = rtrim($ai_config['python_transcribe_api_url'] ?? '', '/');

// ======================================================
// Helpers
// ======================================================
function jexit($arr, int $code = 200) {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function hasColumn(PDO $pdo, string $table, string $col): bool {
  $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
  $stmt->execute([$col]);
  return (bool)$stmt->fetch();
}

function hasTable(PDO $pdo, string $table): bool {
  $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
  $stmt->execute([$table]);
  return (bool)$stmt->fetchColumn();
}

function loadAttendanceStateFromMeeting(PDO $pdo, int $m_ID): array {
  $stmt = $pdo->prepare("SELECT m_check FROM meetingdata WHERE m_ID=? LIMIT 1");
  $stmt->execute([$m_ID]);
  $raw = (string)($stmt->fetchColumn() ?? '');
  if ($raw === '') {
    return [
      'status_map' => [],
      'attendance_rate' => null,
      'locked' => false,
      'locked_by' => null,
      'locked_at' => null
    ];
  }

  $arr = json_decode($raw, true);
  if (!is_array($arr)) {
    return [
      'status_map' => [],
      'attendance_rate' => null,
      'locked' => false,
      'locked_by' => null,
      'locked_at' => null
    ];
  }

  $statusMap = [];
  // 新格式
  if (isset($arr['status_map']) && is_array($arr['status_map'])) {
    $statusMap = $arr['status_map'];
  } elseif (isset($arr['attendance']) && is_array($arr['attendance'])) {
    // 相容舊命名
    $statusMap = $arr['attendance'];
  } else {
    // 相容最舊格式（整包就是 uid=>ok/no）
    foreach ($arr as $k => $v) {
      if (in_array($v, ['ok', 'no'], true)) $statusMap[(string)$k] = $v;
    }
  }

  return [
    'status_map' => $statusMap,
    'attendance_rate' => isset($arr['attendance_rate']) && $arr['attendance_rate'] !== null ? (int)$arr['attendance_rate'] : null,
    'locked' => !empty($arr['locked']),
    'locked_by' => isset($arr['locked_by']) ? (string)$arr['locked_by'] : null,
    'locked_at' => isset($arr['locked_at']) ? (string)$arr['locked_at'] : null,
    'reopened' => !empty($arr['reopened']),
    'reopened_by' => isset($arr['reopened_by']) ? (string)$arr['reopened_by'] : null
  ];
}

function saveAttendanceStateToMeeting(PDO $pdo, int $m_ID, array $state): void {
  $statusMap = is_array($state['status_map'] ?? null) ? $state['status_map'] : [];
  $ok = 0;
  $no = 0;
  foreach ($statusMap as $v) {
    if ($v === 'ok') $ok++;
    if ($v === 'no') $no++;
  }
  $total = $ok + $no;
  $attendanceRate = $total > 0 ? (int)round(($ok / $total) * 100) : null;

  $payload = [
    'status_map' => $statusMap,
    'attendance_rate' => $attendanceRate,
    'locked' => !empty($state['locked']),
    'locked_by' => $state['locked_by'] ?? null,
    'locked_at' => $state['locked_at'] ?? null,
    'reopened' => !empty($state['reopened']),
    'reopened_by' => $state['reopened_by'] ?? null
  ];
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  $upd = $pdo->prepare("UPDATE meetingdata SET m_check=? WHERE m_ID=?");
  $upd->execute([$json, $m_ID]);

  // 會議層級出席率更新後，順便重算整個團隊的累積出席率，寫入 teamdata.team_attend
  recomputeTeamAttendance($pdo, $m_ID);
}

/**
 * 依照「開會次數 × 每次出席人次」計算團隊累積出席率：
 * rate = (所有會議中出席次數總和 ÷ (出席+未到次數總和)) × 100（四捨五入）
 * 結果寫入 teamdata.team_attend（若資料表與欄位存在）。
 */
function recomputeTeamAttendance(PDO $pdo, int $m_ID): void {
  // 安全檢查：若沒有 teamdata 或 team_attend 欄位就略過
  if (!hasTable($pdo, 'teamdata') || !hasColumn($pdo, 'teamdata', 'team_attend')) {
    return;
  }

  // 找出此會議所屬的 team_ID
  $stmt = $pdo->prepare("SELECT m_team_ID FROM meetingdata WHERE m_ID=? LIMIT 1");
  $stmt->execute([$m_ID]);
  $team_ID = (int)($stmt->fetchColumn() ?: 0);
  if ($team_ID <= 0) return;

  // 把這個團隊所有有效會議的 m_check 撈出來，一起算累積出席率
  $stmt = $pdo->prepare("
    SELECT m_check
    FROM meetingdata
    WHERE m_team_ID = ?
      AND (m_status IS NULL OR m_status = 1)
  ");
  $stmt->execute([$team_ID]);

  $okTotal = 0;
  $noTotal = 0;

  while (($raw = $stmt->fetchColumn()) !== false) {
    $raw = (string)($raw ?? '');
    if ($raw === '') continue;

    $arr = json_decode($raw, true);
    if (!is_array($arr)) continue;

    // 與前端/舊版相容的 status_map 解析
    $statusMap = [];
    if (isset($arr['status_map']) && is_array($arr['status_map'])) {
      $statusMap = $arr['status_map'];
    } elseif (isset($arr['attendance']) && is_array($arr['attendance'])) {
      $statusMap = $arr['attendance'];
    } else {
      foreach ($arr as $k => $v) {
        if (in_array($v, ['ok', 'no'], true)) {
          $statusMap[(string)$k] = $v;
        }
      }
    }

    foreach ($statusMap as $v) {
      if ($v === 'ok')      $okTotal++;
      elseif ($v === 'no')  $noTotal++;
    }
  }

  $total = $okTotal + $noTotal;
  $rate = $total > 0 ? (int)round(($okTotal / $total) * 100) : null;

  // team_attend 是 VARCHAR，所以這裡存百分比數字（例如 85），沒有資料就存 NULL
  $upd = $pdo->prepare("UPDATE teamdata SET team_attend = ? WHERE team_ID = ?");
  $upd->execute([$rate !== null ? (string)$rate : null, $team_ID]);
}

function loadAttendanceMapFromMeeting(PDO $pdo, int $m_ID): array {
  $state = loadAttendanceStateFromMeeting($pdo, $m_ID);
  return $state['status_map'] ?? [];
}

function saveAttendanceMapToMeeting(PDO $pdo, int $m_ID, array $map): void {
  $state = loadAttendanceStateFromMeeting($pdo, $m_ID);
  $state['status_map'] = $map;
  saveAttendanceStateToMeeting($pdo, $m_ID, $state);
}

function isMeetingLocked(PDO $pdo, int $m_ID): bool {
  $state = loadAttendanceStateFromMeeting($pdo, $m_ID);
  return !empty($state['locked']);
}

/** 檢查使用者是否可編輯該會議（確認後需指導老師開放修改，且僅該組別+指導老師可編輯） */
function canUserEditMeeting(PDO $pdo, int $m_ID, string $user_ID): bool {
  $state = loadAttendanceStateFromMeeting($pdo, $m_ID);
  if (empty($state['locked'])) return true; // 未確認，所有人可編輯
  if (!empty($state['reopened'])) {
    $col = hasColumn($pdo, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
    $stmt = $pdo->prepare("SELECT m_team_ID FROM meetingdata WHERE m_ID=? LIMIT 1");
    $stmt->execute([$m_ID]);
    $team_ID = (int)($stmt->fetchColumn() ?: 0);
    if ($team_ID <= 0) return false;
    $chk = $pdo->prepare("SELECT 1 FROM teammember WHERE team_ID=? AND {$col}=? AND (tm_status IS NULL OR tm_status=1) LIMIT 1");
    $chk->execute([$team_ID, $user_ID]);
    if ($chk->fetchColumn()) return true; // 組別成員
    $urCol = hasColumn($pdo, 'userrolesdata', 'ur_u_ID') ? 'ur_u_ID' : 'u_ID';
    $tchk = $pdo->prepare("SELECT 1 FROM teammember tm JOIN userrolesdata ur ON ur.{$urCol}=tm.{$col} AND ur.role_ID=4 AND ur.user_role_status=1 WHERE tm.team_ID=? AND tm.{$col}=? AND (tm.tm_status IS NULL OR tm.tm_status=1) LIMIT 1");
    $tchk->execute([$team_ID, $user_ID]);
    return (bool)$tchk->fetchColumn(); // 指導老師
  }
  return false;
}

function isTeacherRole(): bool {
  return ((int)($_SESSION['role_ID'] ?? 0) === 4);
}

function buildTeamAttendanceRows(PDO $pdo, int $team_ID, int $m_ID, string $col, array $fallbackMap = []): array {
  if (hasTable($pdo, 'meeting_attendance')) {
    $stmt = $pdo->prepare("
      SELECT tm.{$col} AS uid,
             COALESCE(u.u_name, tm.{$col}) AS u_name,
             COALESCE(
               NULLIF(TRIM(COALESCE(u.u_img, '')), ''),
               NULLIF(TRIM(COALESCE(u.u_profile, '')), '')
             ) AS u_img,
             COALESCE(a.self_check, 'no') AS self_check
      FROM teammember tm
      LEFT JOIN userdata u ON u.u_ID = tm.{$col}
      LEFT JOIN meeting_attendance a ON a.m_ID = ? AND a.u_ID = tm.{$col}
      WHERE tm.team_ID = ?
        AND (tm.tm_status IS NULL OR tm.tm_status=1)
      ORDER BY tm.{$col}
    ");
    $stmt->execute([$m_ID, $team_ID]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  $stmt = $pdo->prepare("
    SELECT tm.{$col} AS uid,
           COALESCE(u.u_name, tm.{$col}) AS u_name,
           COALESCE(
             NULLIF(TRIM(COALESCE(u.u_img, '')), ''),
             NULLIF(TRIM(COALESCE(u.u_profile, '')), '')
           ) AS u_img
    FROM teammember tm
    LEFT JOIN userdata u ON u.u_ID = tm.{$col}
    WHERE tm.team_ID = ?
      AND (tm.tm_status IS NULL OR tm.tm_status=1)
    ORDER BY tm.{$col}
  ");
  $stmt->execute([$team_ID]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as &$row) {
    $uid = (string)($row['uid'] ?? '');
    $row['self_check'] = (string)($fallbackMap[$uid] ?? 'no');
  }
  unset($row);
  return $rows;
}

function getMyTeamId(PDO $pdo, string $u_ID): ?int {
  global $request_team_ID;

  // meeting.php 若有帶 team_ID，優先使用（同組共享同一頁）
  if ($request_team_ID > 0) {
    $chk = $pdo->prepare("SELECT team_ID FROM teamdata WHERE team_ID = ? LIMIT 1");
    $chk->execute([$request_team_ID]);
    $tid = $chk->fetchColumn();
    if ($tid) return (int)$tid;
  }

  $col = hasColumn($pdo, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';

  $stmt = $pdo->prepare("
    SELECT team_ID
    FROM teammember
    WHERE {$col} = ?
      AND (tm_status IS NULL OR tm_status = 1)
    ORDER BY team_ID DESC
    LIMIT 1
  ");
  $stmt->execute([$u_ID]);
  $team_ID = $stmt->fetchColumn();
  return $team_ID ? (int)$team_ID : null;
}

/** 取得團隊最新會議 ID，若無則回傳 null（不自動建立） */
function getLatestMeetingId(PDO $pdo, int $team_ID): ?int {
  $stmt = $pdo->prepare("
    SELECT m_ID FROM meetingdata
    WHERE m_team_ID = ?
    ORDER BY m_ID DESC LIMIT 1
  ");
  $stmt->execute([$team_ID]);
  $mid = $stmt->fetchColumn();
  return $mid ? (int)$mid : null;
}

function ensureTodayMeeting(PDO $pdo, int $team_ID): int {
  $stmt = $pdo->prepare("
    SELECT m_ID
    FROM meetingdata
    WHERE m_team_ID = ?
      AND DATE(m_created_d) = CURDATE()
    ORDER BY m_ID DESC
    LIMIT 1
  ");
  $stmt->execute([$team_ID]);
  $mid = $stmt->fetchColumn();
  if ($mid) return (int)$mid;

  $title = '會議紀錄 ' . date('Y-m-d');
  $ins = $pdo->prepare("
    INSERT INTO meetingdata (m_title, m_team_ID, m_created_d, m_status)
    VALUES (?, ?, NOW(), 1)
  ");
  $ins->execute([$title, $team_ID]);
  return (int)$pdo->lastInsertId();
}

function assertMeetingOwner(PDO $pdo, int $m_ID, int $team_ID): array {
  $hasEndD = hasColumn($pdo, 'meetingdata', 'm_end_d');
  $hasStartD = hasColumn($pdo, 'meetingdata', 'm_start_d');
  $cols = 'm_ID, m_title, m_team_ID, m_check, m_summary, m_point, m_created_d, m_status';
  if ($hasStartD) $cols .= ', m_start_d';
  if ($hasEndD) $cols .= ', m_end_d';

  // 先嘗試指定 m_ID（同組）
  if ($m_ID > 0) {
    $m = $pdo->prepare("SELECT {$cols} FROM meetingdata WHERE m_ID = ? AND m_team_ID = ? LIMIT 1");
    $m->execute([$m_ID, $team_ID]);
    $row = $m->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
  }

  // 不再拋「無權限」錯誤：若指定 m_ID 不可用，回退到本組最新會議
  $latest = $pdo->prepare("SELECT {$cols} FROM meetingdata WHERE m_team_ID = ? ORDER BY m_ID DESC LIMIT 1");
  $latest->execute([$team_ID]);
  $row = $latest->fetch(PDO::FETCH_ASSOC);
  if ($row) return $row;

  throw new Exception('尚無會議，請至會議列表點擊「新增會議」建立');
}

/** 依 m_ID 取得會議，並驗證使用者有權限（在 teammember 中） */
function getMeetingByMidWithAccess(PDO $pdo, int $m_ID, string $u_ID): ?array {
  $hasEndD = hasColumn($pdo, 'meetingdata', 'm_end_d');
  $hasStartD = hasColumn($pdo, 'meetingdata', 'm_start_d');
  $cols = 'm_ID, m_title, m_team_ID, m_check, m_summary, m_point, m_created_d, m_status';
  if ($hasStartD) $cols .= ', m_start_d';
  if ($hasEndD) $cols .= ', m_end_d';

  $stmt = $pdo->prepare("SELECT {$cols} FROM meetingdata WHERE m_ID = ? LIMIT 1");
  $stmt->execute([$m_ID]);
  $meeting = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$meeting) return null;

  $team_ID = (int)($meeting['m_team_ID'] ?? 0);
  if ($team_ID <= 0) return null;

  $col = hasColumn($pdo, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
  $chk = $pdo->prepare("SELECT 1 FROM teammember WHERE team_ID = ? AND {$col} = ? AND (tm_status = 1 OR tm_status IS NULL) LIMIT 1");
  $chk->execute([$team_ID, $u_ID]);
  if (!$chk->fetchColumn()) return null;

  return $meeting;
}

function storeUploadedFile(string $tmpPath, string $origName, string $subDir): array {
  // subDir 只允許這三種，避免被塞奇怪路徑
  $allow = ['pic', 'rec', 'txt'];
  if (!in_array($subDir, $allow, true)) {
    throw new Exception('不允許的上傳目錄');
  }

  $baseDir = __DIR__ . '/../uploads/meeting/' . $subDir;
  if (!is_dir($baseDir)) mkdir($baseDir, 0777, true);

  $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
  if ($ext === '') $ext = 'bin';

  $safe = preg_replace('/[^a-zA-Z0-9_\.\-]+/', '_', $origName);
  $fname = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
  $destAbs = $baseDir . '/' . $fname;

  if (!move_uploaded_file($tmpPath, $destAbs)) {
    if (!@copy($tmpPath, $destAbs)) throw new Exception('檔案儲存失敗');
  }

  // 相對路徑（給前端 / DB 存）
  $rel = 'uploads/meeting/' . $subDir . '/' . $fname;
  return [$rel, $fname, $ext];
}

function readTextFile(string $absPath, int $maxBytes = 200000): string {
  $raw = file_get_contents($absPath, false, null, 0, $maxBytes);
  if ($raw === false) return '';

  // UTF-8 BOM
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

  // 若不是 UTF-8，嘗試轉
  if (!mb_check_encoding($raw, 'UTF-8')) {
    $raw = mb_convert_encoding($raw, 'UTF-8', 'BIG5,CP950,UTF-8,ISO-8859-1');
  }
  return trim($raw);
}

// ======================================================
// OpenAI: Whisper / Summary / Vision
// ======================================================
function openaiTranscribeAudio(string $apiKey, string $filePath, string $originalFilename = ''): string {
  if (!$apiKey) throw new Exception('OpenAI API Key 未設定');

  $url = 'https://api.openai.com/v1/audio/transcriptions';

  $ext = 'webm';
  if ($originalFilename !== '') {
    $info = pathinfo($originalFilename);
    if (!empty($info['extension'])) {
      $inExt = strtolower($info['extension']);
      $allowed = ['flac','m4a','mp3','mp4','mpeg','mpga','oga','ogg','wav','webm'];
      if (in_array($inExt, $allowed, true)) $ext = $inExt;
    }
  }

  $mime = mime_content_type($filePath) ?: 'application/octet-stream';
  $safeName = "upload.{$ext}";
  $cFile = curl_file_create($filePath, $mime, $safeName);

  $postData = [
    'file' => $cFile,
    'model' => 'whisper-1',
    'language' => 'zh',
    'response_format' => 'json'
  ];

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer {$apiKey}"],
    CURLOPT_POSTFIELDS => $postData,
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);

  if ($response === false) throw new Exception("OpenAI Whisper 連線失敗: {$error}");
  $data = json_decode($response, true);

  if ($httpCode >= 400) {
    $msg = $data['error']['message'] ?? $response;
    throw new Exception("OpenAI Whisper API 錯誤 ({$httpCode}): {$msg}");
  }

  return trim($data['text'] ?? '');
}
  
/** 呼叫本機 Python WhisperX API 做說話者分離轉錄 */
function pythonTranscribeDiarize(string $apiUrl, string $filePath, string $originalFilename = ''): array {
  $url = $apiUrl . '/transcribe_diarize';
  if ($url === '/transcribe_diarize') return ['success' => false, 'error' => 'Python API URL 未設定'];

  $cFile = curl_file_create($filePath, mime_content_type($filePath) ?: 'application/octet-stream', $originalFilename ?: 'audio.webm');
  $postData = ['file' => $cFile];

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_TIMEOUT => 600,
    CURLOPT_CONNECTTIMEOUT => 10,
  ]);

  file_put_contents(__DIR__ . '/debug_whisper.txt', "開始呼叫 Python transcribe_diarize: {$url}\n送出檔案: " . ($originalFilename ?: $filePath) . "\n", FILE_APPEND);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);
  curl_close($ch);

  file_put_contents(
    __DIR__ . '/debug_whisper.txt',
    "HTTP Code: {$httpCode}\nResponse: " . ($response !== false ? substr($response, 0, 2000) : '(false)') . "\nCurl Error: {$curlError}\n-------------------\n",
    FILE_APPEND
  );

  if ($response === false) {
    return ['success' => false, 'error' => 'Python API 連線失敗: ' . $curlError];
  }
  $data = json_decode($response, true);
  if (!is_array($data)) return ['success' => false, 'error' => 'Python API 回傳格式錯誤'];

  if (!empty($data['success']) && isset($data['segments'])) {
    return [
      'success' => true,
      'segments' => $data['segments'] ?? [],
      'full_text' => $data['full_text'] ?? '',
      'raw_full_text' => $data['raw_full_text'] ?? '',
      'speakers' => (int)($data['speakers'] ?? 0),
    ];
  }
  $errMsg = $data['error'] ?? 'Python API 處理失敗';
  error_log("WhisperX API 失敗: {$errMsg} (HTTP {$httpCode})");
  return ['success' => false, 'error' => $errMsg];
}

function openaiSummarizeText(string $apiKey, string $model, string $text): array {
  if (!$apiKey) throw new Exception('OpenAI API Key 未設定');

  $url = 'https://api.openai.com/v1/chat/completions';
  $prompt = "請將以下會議內容整理成兩部分，請使用繁體中文。\n\n" .
    "請嚴格依照以下格式輸出，不要省略標題：\n\n" .
    "【AI統整重點】\n" .
    "（段落式摘要，2-4句話概括會議主要重點與結論）\n\n" .
    "【AI統整條列式】\n" .
    "• 條列重點一\n" .
    "• 條列重點二\n" .
    "• 條列重點三\n" .
    "（每行一個條列，以 • 開頭，明確列出 5-10 個具體重點）\n\n" .
    "---\n會議內容：\n{$text}";

  $payload = [
    'model' => $model,
    'messages' => [
      ['role'=>'system','content'=>'你是一個專業的會議記錄助手，擅長將逐字稿整理成結構清晰的摘要與條列式重點。請嚴格依照指定格式輸出。'],
      ['role'=>'user','content'=>$prompt]
    ],
    'temperature' => 0.5
  ];

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer {$apiKey}",
      "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);

  if ($response === false) throw new Exception("OpenAI Chat 連線失敗: {$error}");
  $data = json_decode($response, true);

  if ($httpCode >= 400) {
    $msg = $data['error']['message'] ?? $response;
    throw new Exception("OpenAI Chat API 錯誤 ({$httpCode}): {$msg}");
  }

  $content = trim($data['choices'][0]['message']['content'] ?? '');
  $summary = '';
  $points = '';

  if (preg_match('/【AI統整重點】\s*(.*?)(?=【AI統整條列式】|$)/s', $content, $m)) {
    $summary = trim($m[1]);
  }
  if (preg_match('/【AI統整條列式】\s*(.*?)$/s', $content, $m)) {
    $points = trim($m[1]);
  }
  if (!$summary && !$points) {
    $summary = $content;
  }

  return ['summary' => $summary, 'points' => $points];
}

function openaiVisionImage(string $apiKey, string $filePath): string {
  if (!$apiKey) throw new Exception('OpenAI API Key 未設定');

  $url = 'https://api.openai.com/v1/chat/completions';
  $imageData = base64_encode(file_get_contents($filePath));
  $mime = mime_content_type($filePath) ?: 'image/png';
  $dataUri = "data:{$mime};base64,{$imageData}";

  $prompt = "請逐字辨識圖片中的文字內容（繁體中文），不要加開場白。";

  $payload = [
    'model' => 'gpt-4o',
    'messages' => [[
      'role' => 'user',
      'content' => [
        ['type'=>'text','text'=>$prompt],
        ['type'=>'image_url','image_url'=>['url'=>$dataUri,'detail'=>'high']]
      ]
    ]],
    'max_tokens' => 2000
  ];

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer {$apiKey}",
      "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
  ]);

  $response = curl_exec($ch);
  $error = curl_error($ch);
  curl_close($ch);

  if ($response === false) throw new Exception("OpenAI Vision 連線失敗: {$error}");
  $data = json_decode($response, true);
  if (isset($data['error'])) throw new Exception("OpenAI API 錯誤: " . ($data['error']['message'] ?? '未知錯誤'));

  return trim($data['choices'][0]['message']['content'] ?? '');
}

// ======================================================
// Main Switch
// ======================================================
try {
  switch ($do) {

    // 刪除單筆紀錄（text/image/audio/note）
    case 'meeting_delete_record': {
      $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $mid = $m_ID ?: ($json['m_ID'] ?? 0);
      $mid = (int)$mid;
      $mr_ID = (int)($json['mr_ID'] ?? 0);
      if (!$mid || !$mr_ID) jexit(['ok'=>false,'msg'=>'缺少 m_ID 或 mr_ID']);
      assertMeetingOwner($pdo, $mid, $team_ID);
      if (!canUserEditMeeting($pdo, $mid, (string)$my_uid)) {
        jexit(['ok'=>false,'msg'=>'此會議已確認，無法編輯。請由指導老師按下「開放修改」後再編輯。']);
      }

      $del = $pdo->prepare("DELETE FROM meetingrecordsdata WHERE mr_ID=? AND m_ID=?");
      $del->execute([$mr_ID, $mid]);
      if ($del->rowCount() === 0) jexit(['ok'=>false,'msg'=>'找不到該筆紀錄或已刪除']);
      jexit(['ok'=>true,'m_ID'=>$mid,'mr_ID'=>$mr_ID,'msg'=>'已刪除']);
    }

    // 清除指定類型內容（儲存時一併執行，用於 summary 或整類清除）
    case 'meeting_clear_kind': {
      $team_ID = $request_team_ID ?: getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) {
        $midTmp = (int)($m_ID ?: ($json['m_ID'] ?? 0));
        if ($midTmp > 0) {
          $stmt = $pdo->prepare("SELECT m_team_ID FROM meetingdata WHERE m_ID=? LIMIT 1");
          $stmt->execute([$midTmp]);
          $tid = $stmt->fetchColumn();
          $team_ID = $tid ? (int)$tid : null;
        }
      }
      if (!$team_ID) $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $mid = $m_ID ?: ($json['m_ID'] ?? 0);
      $mid = (int)$mid;
      if (!$mid) jexit(['ok'=>false,'msg'=>'缺少 m_ID']);
      assertMeetingOwner($pdo, $mid, $team_ID);
      if (!canUserEditMeeting($pdo, $mid, (string)$my_uid)) {
        jexit(['ok'=>false,'msg'=>'此會議已確認，無法編輯。請由指導老師按下「開放修改」後再編輯。']);
      }

      $kind = trim((string)($json['kind'] ?? ''));
      $allowed = ['note', 'text', 'image', 'audio', 'summary'];
      if (!in_array($kind, $allowed, true)) jexit(['ok'=>false,'msg'=>'無效的 kind']);

      if ($kind === 'summary') {
        $pdo->prepare("UPDATE meetingdata SET m_summary='', m_point='' WHERE m_ID=?")->execute([$mid]);
      } else {
        $pdo->prepare("DELETE FROM meetingrecordsdata WHERE m_ID=? AND mr_type=?")->execute([$mid, $kind]);
      }
      jexit(['ok'=>true,'m_ID'=>$mid,'msg'=>'已清除']);
    }

    // 刪除會議
    case 'delete_meeting': {
      $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $mid = (int)($m_ID ?: $json['m_ID'] ?? 0);
      if (!$mid) jexit(['ok'=>false,'msg'=>'請指定要刪除的會議']);

      $chk = $pdo->prepare("SELECT m_ID FROM meetingdata WHERE m_ID=? AND m_team_ID=? LIMIT 1");
      $chk->execute([$mid, $team_ID]);
      if (!$chk->fetchColumn()) jexit(['ok'=>false,'msg'=>'找不到該會議或無權限刪除']);

      if (!canUserEditMeeting($pdo, $mid, (string)$my_uid)) {
        jexit(['ok'=>false,'msg'=>'此會議已確認，無法刪除。請由指導老師按下「開放修改」後再操作。']);
      }

      $pdo->prepare("DELETE FROM meetingrecordsdata WHERE m_ID=?")->execute([$mid]);
      $pdo->prepare("DELETE FROM meetingdata WHERE m_ID=?")->execute([$mid]);
      jexit(['ok'=>true,'msg'=>'已刪除會議']);
    }

    // 手動建立會議（meeting_list.php 的「新增會議」）
    case 'create_meeting': {
      $team_ID = $request_team_ID ?: getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $title = trim((string)($json['title'] ?? $json['m_title'] ?? ''));
      if ($title === '') jexit(['ok'=>false,'msg'=>'請輸入會議標題']);

      $m_date = trim((string)($json['m_date'] ?? ''));
      $startD = null;
      if ($m_date !== '') {
        $parsed = date_parse($m_date);
        if ($parsed['error_count'] === 0 && $parsed['year'] && $parsed['month'] && $parsed['day']) {
          $startD = sprintf('%04d-%02d-%02d 00:00:00', $parsed['year'], $parsed['month'], $parsed['day']);
        }
      }
      if ($startD === null) {
        $startD = date('Y-m-d H:i:s');
      }

      $ins = $pdo->prepare("
        INSERT INTO meetingdata (m_title, m_team_ID, m_created_d, m_start_d, m_status)
        VALUES (?, ?, NOW(), ?, 1)
      ");
      $createdD = date('Y-m-d H:i:s');
      $ins->execute([$title, $team_ID, $startD]);
      $newMid = (int)$pdo->lastInsertId();

      // 寫入時間軸：新增會議
      team_timeline_add_event(
        $pdo,
        $team_ID,
        '會議',
        '新增會議',
        $title,
        '新增會議：' . $title,
        '',
        'meetingdata',
        $newMid,
        'meeting',
        $startD,
        (string)$my_uid
      );

      jexit(['ok'=>true,'m_ID'=>$newMid,'m_start_d'=>$startD,'m_created_d'=>$createdD]);
    }

    // 輕量檢查：取得該組別最新會議 ID（供前端輪詢，偵測是否有新會議）
    case 'meeting_check_new': {
      $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $latest = getLatestMeetingId($pdo, $team_ID);
      jexit(['ok'=>true,'latest_m_ID'=>$latest ? (int)$latest : 0]);
    }

    // 取得最新的 meeting + note + summary（不自動建立，需由使用者點「新增會議」）
    case 'meeting_load': {
      $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $mid = $m_ID ?: getLatestMeetingId($pdo, $team_ID);
      if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議，請至會議列表點擊「新增會議」建立']);

      $meeting = assertMeetingOwner($pdo, $mid, $team_ID);

      // 最新 note（含編輯人、編輯時間）
      $n = $pdo->prepare("
        SELECT r.mr_ID, r.mr_content, r.mr_created_d, r.mr_user_ID,
               u.u_name AS editor_name
        FROM meetingrecordsdata r
        LEFT JOIN userdata u ON u.u_ID = r.mr_user_ID
        WHERE r.m_ID=? AND r.mr_type='note' AND (r.mr_status IS NULL OR r.mr_status=1)
        ORDER BY r.mr_ID DESC
        LIMIT 1
      ");
      $n->execute([$mid]);
      $note = $n->fetch(PDO::FETCH_ASSOC);
      $attState = loadAttendanceStateFromMeeting($pdo, $mid);
      $locked = !empty($attState['locked']);
      $can_edit = canUserEditMeeting($pdo, $mid, (string)$my_uid);

      $noteEditor = $note ? ($note['editor_name'] ?? null) : null;
      $noteUpdated = $note && !empty($note['mr_created_d']) ? date('Y/m/d H:i', strtotime($note['mr_created_d'])) : null;

      jexit([
        'ok' => true,
        'm_ID' => (int)$meeting['m_ID'],
        'm_title' => $meeting['m_title'] ?? '',
        'm_start_d' => $meeting['m_start_d'] ?? null,
        'm_created_d' => $meeting['m_created_d'] ?? null,
        'm_end_d' => $meeting['m_end_d'] ?? null,
        'summary' => $meeting['m_summary'] ?? '',
        'note' => $note['mr_content'] ?? '',
        'note_mr_ID' => $note['mr_ID'] ?? null,
        'note_editor' => $noteEditor,
        'note_updated_d' => $noteUpdated,
        'locked' => $locked,
        'reopened' => !empty($attState['reopened']),
        'can_edit' => $can_edit,
        'locked_by' => $attState['locked_by'] ?? null,
        'locked_at' => $attState['locked_at'] ?? null
      ]);
    }

    // ✅ 儲存/更新 note（手寫紀錄）
    case 'meeting_save':
    case 'meeting_note_save': {
      $team_ID = $request_team_ID ?: getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID && $m_ID > 0) {
        $stmt = $pdo->prepare("SELECT m_team_ID FROM meetingdata WHERE m_ID=? LIMIT 1");
        $stmt->execute([$m_ID]);
        $tid = $stmt->fetchColumn();
        $team_ID = $tid ? (int)$tid : null;
      }
      if (!$team_ID) $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $mid = $m_ID ?: getLatestMeetingId($pdo, $team_ID);
      if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議，請至會議列表點擊「新增會議」建立']);
      assertMeetingOwner($pdo, $mid, $team_ID);
      if (!canUserEditMeeting($pdo, $mid, (string)$my_uid)) {
        jexit(['ok'=>false,'msg'=>'此會議已確認，無法編輯。請由指導老師按下「開放修改」後再編輯。']);
      }

      $content = (string)($json['content'] ?? '');
      if (trim($content) === '') jexit(['ok'=>false,'msg'=>'筆記內容不可為空']);

      $mr_ID = (int)($json['mr_ID'] ?? 0);
      $mr_name = trim((string)($json['mr_name'] ?? ''));
      if ($mr_name === '') $mr_name = '手寫筆記';

      if ($mr_ID) {
        $sets = "mr_content=?, mr_name=?, mr_created_d=NOW()";
        if (hasColumn($pdo, 'meetingrecordsdata', 'data_status')) $sets .= ", data_status=3";
        $u = $pdo->prepare("
          UPDATE meetingrecordsdata
          SET {$sets}
          WHERE mr_ID=? AND m_ID=? AND mr_user_ID=? AND mr_type='note'
        ");
        $u->execute([$content, substr($mr_name, 0, 50), $mr_ID, $mid, $my_uid]);
        if ($u->rowCount() > 0) {
          jexit(['ok'=>true,'m_ID'=>$mid,'mr_ID'=>$mr_ID,'mode'=>'update']);
        }
      }

      // 同一使用者的手寫紀錄：優先更新既有 note，沒有才新增
      $latestMine = $pdo->prepare("
        SELECT mr_ID
        FROM meetingrecordsdata
        WHERE m_ID=? AND mr_user_ID=? AND mr_type='note'
          AND (mr_status IS NULL OR mr_status=1)
        ORDER BY mr_ID DESC
        LIMIT 1
      ");
      $latestMine->execute([$mid, $my_uid]);
      $myLatestMrId = (int)($latestMine->fetchColumn() ?: 0);

      if ($myLatestMrId > 0) {
        $sets2 = "mr_content=?, mr_name=?, mr_created_d=NOW()";
        if (hasColumn($pdo, 'meetingrecordsdata', 'data_status')) $sets2 .= ", data_status=3";
        $u2 = $pdo->prepare("
          UPDATE meetingrecordsdata
          SET {$sets2}
          WHERE mr_ID=? AND m_ID=? AND mr_user_ID=? AND mr_type='note'
        ");
        $u2->execute([$content, substr($mr_name, 0, 50), $myLatestMrId, $mid, $my_uid]);
        jexit(['ok'=>true,'m_ID'=>$mid,'mr_ID'=>$myLatestMrId,'mode'=>'update']);
      }

      $ins = $pdo->prepare("
        INSERT INTO meetingrecordsdata (m_ID, mr_user_ID, mr_type, mr_name, mr_content, mr_status, mr_created_d)
        VALUES (?, ?, 'note', ?, ?, 1, NOW())
      ");
      $ins->execute([$mid, $my_uid, substr($mr_name, 0, 50), $content]);
      jexit(['ok'=>true,'m_ID'=>$mid,'mr_ID'=>(int)$pdo->lastInsertId(),'mode'=>'insert']);
    }

    // ✅ 更新 meeting 基本資料：標題/開始/結束
    case 'meeting_update_meta': {
      $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $mid = $m_ID ?: getLatestMeetingId($pdo, $team_ID);
      if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議，請至會議列表點擊「新增會議」建立']);
      assertMeetingOwner($pdo, $mid, $team_ID);
      if (!canUserEditMeeting($pdo, $mid, (string)$my_uid)) {
        jexit(['ok'=>false,'msg'=>'此會議已確認，無法編輯。請由指導老師按下「開放修改」後再編輯。']);
      }

      $title = trim((string)($json['m_title'] ?? ''));
      $start = trim((string)($json['m_start_d'] ?? '')); // 'YYYY-MM-DD HH:MM:SS' or ''
      $end   = trim((string)($json['m_end_d'] ?? ''));

      // 不強迫都要填，有給才更新
      $sets = [];
      $params = [];

      if ($title !== '') { $sets[] = "m_title=?"; $params[] = $title; }
      if ($start !== '') { $sets[] = "m_start_d=?"; $params[] = $start; }
      if ($end   !== '') { $sets[] = "m_end_d=?";   $params[] = $end;   }

      if (!$sets) jexit(['ok'=>false,'msg'=>'沒有提供可更新欄位']);

      $params[] = $mid;
      $sql = "UPDATE meetingdata SET " . implode(',', $sets) . " WHERE m_ID=?";
      $st = $pdo->prepare($sql);
      $st->execute($params);

      jexit(['ok'=>true,'m_ID'=>$mid]);
    }

    // 左側歷史清單（meeting_list.php 使用 get_meeting_list）
    case 'meeting_list':
    case 'get_meeting_list': {
      $team_ID = ($request_team_ID > 0) ? $request_team_ID : getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $kw   = trim((string)($_GET['kw'] ?? ''));
      $from = trim((string)($_GET['from'] ?? ''));
      $to   = trim((string)($_GET['to'] ?? ''));

      $where = "m.m_team_ID = ? AND (m.m_status IS NULL OR m.m_status = 1)";
      $params = [$team_ID];

      if ($kw !== '') { $where .= " AND m.m_title LIKE ?"; $params[] = "%{$kw}%"; }
      if ($from !== '') { $where .= " AND DATE(COALESCE(m.m_start_d, m.m_created_d)) >= ?"; $params[] = $from; }
      if ($to !== '') { $where .= " AND DATE(COALESCE(m.m_start_d, m.m_created_d)) <= ?"; $params[] = $to; }

      $stmt = $pdo->prepare("
        SELECT m.m_ID, m.m_title, m.m_summary, m.m_check,
               DATE_FORMAT(COALESCE(m.m_start_d, m.m_created_d),'%Y/%m/%d') AS m_date,
               DATE_FORMAT(COALESCE(m.m_start_d, m.m_created_d),'%H:%i') AS m_start_time,
               DATE_FORMAT(COALESCE(m.m_start_d, m.m_created_d),'%Y/%m/%d %H:%i') AS m_created_display,
               COALESCE(u_creator.u_name, '—') AS m_creator_name
        FROM meetingdata m
        LEFT JOIN (
          SELECT r1.m_ID, r1.mr_user_ID
          FROM meetingrecordsdata r1
          INNER JOIN (
            SELECT m_ID, MIN(mr_ID) AS min_mr_id
            FROM meetingrecordsdata
            GROUP BY m_ID
          ) r2 ON r1.m_ID = r2.m_ID AND r1.mr_ID = r2.min_mr_id
        ) first_rec ON first_rec.m_ID = m.m_ID
        LEFT JOIN userdata u_creator ON u_creator.u_ID = first_rec.mr_user_ID
        WHERE {$where}
        ORDER BY m.m_ID DESC
        LIMIT 200
      ");
      $stmt->execute($params);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

      $total = count($rows);
      $col = hasColumn($pdo, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
      $teamNameStmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(team_project_name),''), CONCAT('組別 ', team_ID)) FROM teamdata WHERE team_ID = ?");
      $teamNameStmt->execute([$team_ID]);
      $team_name = $teamNameStmt->fetchColumn() ?: '未知團隊';

      $memberStmt = $pdo->prepare("
        SELECT tm.{$col} AS uid, COALESCE(NULLIF(TRIM(u.u_name),''), tm.{$col}) AS u_name
        FROM teammember tm
        LEFT JOIN userdata u ON u.u_ID = tm.{$col}
        WHERE tm.team_ID = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)
        ORDER BY u_name
      ");
      $memberStmt->execute([$team_ID]);
      $allMembers = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

      foreach ($rows as $i => &$r) {
        $r['meeting_number'] = $total - $i;
        $raw = (string)($r['m_check'] ?? '');
        $attendanceRate = null;
        $statusMap = [];
        if ($raw !== '') {
          $decoded = json_decode($raw, true);
          if (is_array($decoded)) {
            if (isset($decoded['attendance_rate']) && $decoded['attendance_rate'] !== null) {
              $attendanceRate = (int)$decoded['attendance_rate'];
            }
            if (isset($decoded['status_map']) && is_array($decoded['status_map'])) {
              $statusMap = $decoded['status_map'];
            } elseif (empty($statusMap)) {
              foreach ($decoded as $k => $v) {
                if (in_array($v, ['ok', 'no'], true)) $statusMap[(string)$k] = $v;
              }
            }
            if ($attendanceRate === null && !empty($statusMap)) {
              $ok = $no = 0;
              foreach ($statusMap as $v) {
                if ($v === 'ok') $ok++;
                elseif ($v === 'no') $no++;
              }
              $tot = $ok + $no;
              $attendanceRate = $tot > 0 ? (int)round(($ok / $tot) * 100) : null;
            }
          }
        }
        $r['attendance_rate'] = $attendanceRate;
        $memberAttendance = [];
        foreach ($allMembers as $m) {
          $uid = (string)($m['uid'] ?? '');
          $status = $statusMap[$uid] ?? null;
          $memberAttendance[] = [
            'u_name' => $m['u_name'] ?? $uid,
            'status' => $status,
            'display' => $status === 'ok' ? '出席' : ($status === 'no' ? '未到' : '—')
          ];
        }
        $r['member_attendance'] = $memberAttendance;
        unset($r['m_check']);
      }
      unset($r);

      jexit(['ok'=>true,'list'=>$rows,'team_ID'=>$team_ID,'team_name'=>$team_name]);
    }

    // ✅ 檢查 WhisperX Python API 是否可連線（除錯用）
    case 'check_whisperx': {
      $url = $pythonTranscribeUrl ? rtrim($pythonTranscribeUrl, '/') . '/health' : '';
      $reachable = false;
      $error = '';
      if ($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
          CURLOPT_URL => $url,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_TIMEOUT => 5,
          CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $reachable = ($resp !== false && $code === 200);
        if (!$reachable) $error = $err ?: "HTTP {$code}";
      } else {
        $error = 'PYTHON_TRANSCRIBE_API_URL 未設定';
      }
      jexit([
        'ok' => true,
        'reachable' => $reachable,
        'url' => $url ?: null,
        'error' => $error ?: null,
      ]);
    }

    // ✅ 右側檔案清單（note/summary/text/image/audio）
    case 'meeting_files': {
      $mid = $m_ID;
      if (!$mid) jexit(['ok'=>false,'msg'=>'缺少 m_ID']);

      $meeting = getMeetingByMidWithAccess($pdo, $mid, (string)$my_uid);
      if (!$meeting) jexit(['ok'=>false,'msg'=>'找不到會議或無權限存取']);

      $files = [];

      // note：取所有手寫紀錄（支援多成員，每人可有一筆）
      $n = $pdo->prepare("
        SELECT r.mr_ID, r.mr_name, r.mr_content, r.mr_created_d, r.mr_user_ID,
               u.u_name AS uploader_name
        FROM meetingrecordsdata r
        LEFT JOIN userdata u ON u.u_ID = r.mr_user_ID
        WHERE r.m_ID=? AND r.mr_type='note' AND (r.mr_status IS NULL OR r.mr_status=1)
        ORDER BY r.mr_ID ASC
      ");
      $n->execute([$mid]);
      while ($note = $n->fetch(PDO::FETCH_ASSOC)) {
        if (trim((string)($note['mr_content'] ?? '')) === '') continue;
        $files[] = [
          'kind' => 'note',
          'id' => (int)$note['mr_ID'],
          'label' => '手寫筆記',
          'name' => $note['mr_name'] ?? null,
          'created_d' => date('Y/m/d H:i', strtotime($note['mr_created_d'] ?? 'now')),
          'content' => $note['mr_content'] ?? '',
          'file_path' => null,
          'uploader_id' => $note['mr_user_ID'] ?? null,
          'uploader_name' => $note['uploader_name'] ?? null
        ];
      }

      // summary：meetingdata.m_summary + m_point
      $hasSummary = trim((string)($meeting['m_summary'] ?? '')) !== '';
      $hasPoints = trim((string)($meeting['m_point'] ?? '')) !== '';
      if ($hasSummary || $hasPoints) {
        $files[] = [
          'kind' => 'summary',
          'id' => null,
          'label' => 'AI 統整',
          'name' => null,
          'created_d' => date('Y/m/d H:i', strtotime($meeting['m_created_d'] ?? 'now')),
          'content' => $meeting['m_summary'] ?? '',
          'content_points' => $meeting['m_point'] ?? '',
          'file_path' => null,
          'uploader_id' => null,
          'uploader_name' => '系統'
        ];
      }

      // 其他 records：排除 note，避免重複（含 meetingrecordsdata 的 mr_segments_json、mr_speaker_count）
      $cols = 'r.mr_ID, r.mr_type, r.mr_name, r.mr_file_path, r.mr_content, r.mr_created_d, r.mr_user_ID';
      if (hasColumn($pdo, 'meetingrecordsdata', 'mr_segments_json')) $cols .= ', r.mr_segments_json';
      if (hasColumn($pdo, 'meetingrecordsdata', 'mr_speaker_count')) $cols .= ', r.mr_speaker_count';
      $r = $pdo->prepare("
        SELECT {$cols}, u.u_name AS uploader_name
        FROM meetingrecordsdata r
        LEFT JOIN userdata u ON u.u_ID = r.mr_user_ID
        WHERE r.m_ID = ?
          AND r.mr_type <> 'note'
          AND (r.mr_status IS NULL OR r.mr_status = 1)
        ORDER BY r.mr_ID ASC
      ");
      $r->execute([$mid]);
      $recs = $r->fetchAll(PDO::FETCH_ASSOC);

      foreach ($recs as $row) {
        $kind = $row['mr_type'] ?? 'file';
        $label = match ($kind) {
          'text'  => '文字檔',
          'image' => '圖片檔轉文字',
          'audio' => '語音轉文字',
          default => '附件'
        };

        $mrId = (int)$row['mr_ID'];
        $fileItem = [
          'kind' => $kind,
          'id' => $mrId,
          'label' => $label,
          'name' => $row['mr_name'] ?? null,
          'created_d' => date('Y/m/d H:i', strtotime($row['mr_created_d'] ?? 'now')),
          'content' => $row['mr_content'] ?? '',
          'file_path' => $row['mr_file_path'] ?? null,
          'uploader_id' => $row['mr_user_ID'] ?? null,
          'uploader_name' => $row['uploader_name'] ?? null
        ];
        if ($kind === 'audio' && hasColumn($pdo, 'meetingrecordsdata', 'mr_segments_json')) {
          $segments = json_decode($row['mr_segments_json'] ?? '[]', true);
          if (is_array($segments) && !empty($segments)) {
            $fileItem['segments'] = $segments;
            $fileItem['speaker_count'] = (int)($row['mr_speaker_count'] ?? 0);
          }
        }
        $files[] = $fileItem;
      }

      jexit([
        'ok'=>true,
        'm_ID'=>$mid,
        'm_title'=>$meeting['m_title'],
        'm_start_d'=>$meeting['m_start_d'] ?? null,
        'm_created_d'=>$meeting['m_created_d'] ?? null,
        'files'=>$files
      ]);
    }

    // ✅ 上傳文字檔（txt/md/csv）=> mr_type='text'
    case 'meeting_upload_text': {
      if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jexit(['ok'=>false,'msg'=>'上傳失敗或無檔案']);
      }

      $mid = $m_ID;
      if ($mid) {
        $meeting = getMeetingByMidWithAccess($pdo, $mid, (string)$my_uid);
        if (!$meeting) jexit(['ok'=>false,'msg'=>'找不到會議或無權限存取']);
      } else {
        $team_ID = getMyTeamId($pdo, (string)$my_uid);
        if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);
        $mid = getLatestMeetingId($pdo, $team_ID);
        if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議，請至會議列表點擊「新增會議」建立']);
        assertMeetingOwner($pdo, $mid, $team_ID);
      }

      $file = $_FILES['file'];
      $origName = $file['name'] ?? 'text.txt';
      $tmpPath = $file['tmp_name'];
      $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

      $allow = ['txt','md','csv'];
      if (!in_array($ext, $allow, true)) jexit(['ok'=>false,'msg'=>'不支援的文字檔格式（只允許 txt/md/csv）']);

      // 存檔
    [$relPath, $savedName, $savedExt] = storeUploadedFile($tmpPath, $origName, 'txt');
    $abs = __DIR__ . '/../' . $relPath;

      $text = readTextFile($abs);
      if ($text === '') $text = '（文字檔內容為空或無法讀取）';

      $ins = $pdo->prepare("
        INSERT INTO meetingrecordsdata (m_ID, mr_user_ID, mr_type, mr_file_path, mr_name, mr_content, mr_status, mr_created_d)
        VALUES (?, ?, 'text', ?, ?, ?, 1, NOW())
      ");
      $ins->execute([$mid, $my_uid, $relPath, $origName, $text]);

      jexit(['ok'=>true,'m_ID'=>$mid,'mr_ID'=>(int)$pdo->lastInsertId(),'content'=>$text,'file_path'=>$relPath]);
    }

    // ✅ 圖片 OCR => mr_type='image'
    case 'meeting_ocr_image': {
      if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jexit(['ok'=>false,'msg'=>'上傳失敗或無檔案']);
      }

      $mid = $m_ID;
      if ($mid) {
        $meeting = getMeetingByMidWithAccess($pdo, $mid, (string)$my_uid);
        if (!$meeting) jexit(['ok'=>false,'msg'=>'找不到會議或無權限存取']);
      } else {
        $team_ID = getMyTeamId($pdo, (string)$my_uid);
        if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);
        $mid = getLatestMeetingId($pdo, $team_ID);
        if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議，請至會議列表點擊「新增會議」建立']);
        assertMeetingOwner($pdo, $mid, $team_ID);
      }
      if (!canUserEditMeeting($pdo, $mid, (string)$my_uid)) {
        jexit(['ok'=>false,'msg'=>'此會議已確認，無法編輯。請由指導老師按下「開放修改」後再編輯。']);
      }

      if (!$openaiKey) jexit(['ok'=>false,'msg'=>'OpenAI API 尚未設定，請在 includes/ai_config.php 設定 openai_api_key，或設定環境變數 OPENAI_API_KEY']);

      $file = $_FILES['file'];
      $origName = $file['name'] ?? 'image';
      $tmpPath = $file['tmp_name'];

      // 存檔
      [$relPath, $savedName, $savedExt] = storeUploadedFile($tmpPath, $origName, 'pic');

      $abs = __DIR__ . '/../' . $relPath;

      $contentText = openaiVisionImage($openaiKey, $abs);
      if (trim($contentText) === '') $contentText = '（未辨識到文字）';

      $ins = $pdo->prepare("
        INSERT INTO meetingrecordsdata (m_ID, mr_user_ID, mr_type, mr_file_path, mr_name, mr_content, mr_status, mr_created_d)
        VALUES (?, ?, 'image', ?, ?, ?, 1, NOW())
      ");
      $ins->execute([$mid, $my_uid, $relPath, $origName, $contentText]);

      jexit(['ok'=>true,'m_ID'=>$mid,'mr_ID'=>(int)$pdo->lastInsertId(),'content'=>$contentText,'file_path'=>$relPath]);
    }

    // ✅ 音檔轉錄 => mr_type='audio'（可選 diarize=1 使用 WhisperX 說話者分離）
    case 'meeting_transcribe_audio': {
      if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jexit(['ok'=>false,'msg'=>'上傳失敗或無檔案']);
      }

      $mid = $m_ID;
      if ($mid) {
        $meeting = getMeetingByMidWithAccess($pdo, $mid, (string)$my_uid);
        if (!$meeting) jexit(['ok'=>false,'msg'=>'找不到會議或無權限存取']);
      } else {
        $team_ID = getMyTeamId($pdo, (string)$my_uid);
        if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);
        $mid = getLatestMeetingId($pdo, $team_ID);
        if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議，請至會議列表點擊「新增會議」建立']);
        assertMeetingOwner($pdo, $mid, $team_ID);
      }
      if (!canUserEditMeeting($pdo, $mid, (string)$my_uid)) {
        jexit(['ok'=>false,'msg'=>'此會議已確認，無法編輯。請由指導老師按下「開放修改」後再編輯。']);
      }

      $useDiarize = !empty($_POST['diarize']) || !empty($json['diarize']);
      $file = $_FILES['file'];
      $origName = $file['name'] ?? 'audio.webm';
      $tmpPath = $file['tmp_name'];

      [$relPath, $savedName, $savedExt] = storeUploadedFile($tmpPath, $origName, 'rec');
      $abs = __DIR__ . '/../' . $relPath;

      $contentText = '';
      $segments = [];
      $diarized = false;

      $diarizeFallbackHint = '';
      $pyErrorDetail = '';
      if ($useDiarize && $pythonTranscribeUrl) {
        $pyResult = pythonTranscribeDiarize($pythonTranscribeUrl, $abs, $origName);
        if (!empty($pyResult['success']) && trim($pyResult['full_text'] ?? '') !== '') {
          $contentText = $pyResult['full_text'];
          $segments = $pyResult['segments'] ?? [];
          $diarized = true;
        } else {
          $pyErrorDetail = !empty($pyResult['error']) ? '（錯誤：' . $pyResult['error'] . '）' : '';
          $diarizeFallbackHint = '（WhisperX 未啟用或連線失敗，已改用一般轉錄。' . $pyErrorDetail . ' 若要說話者分離與自動標點，請先啟動 Python API：在專案目錄執行「python_api\\啟動WhisperX.bat」或「cd python_api && uvicorn app:app --host 127.0.0.1 --port 8000」）';
        }
      } elseif ($useDiarize && !$pythonTranscribeUrl) {
        $diarizeFallbackHint = '（請在 .env 設定 PYTHON_TRANSCRIBE_API_URL=http://127.0.0.1:8000 並啟動 Python API）';
      }

      if ($contentText === '') {
        if ($openaiKey) {
          $contentText = openaiTranscribeAudio($openaiKey, $abs, $origName);
          if ($diarizeFallbackHint) $contentText = $diarizeFallbackHint . "\n\n" . $contentText;
        } else {
          $contentText = $diarizeFallbackHint ?: '（請設定 OpenAI API Key 或啟動本機 Python WhisperX API：cd python_api && uvicorn app:app）';
        }
      }
      if (trim($contentText) === '') $contentText = '（錄音無內容或無法辨識）';

      $ins = $pdo->prepare("
        INSERT INTO meetingrecordsdata (m_ID, mr_user_ID, mr_type, mr_file_path, mr_name, mr_content, mr_status, mr_created_d)
        VALUES (?, ?, 'audio', ?, ?, ?, 1, NOW())
      ");
      $ins->execute([$mid, $my_uid, $relPath, $origName, $contentText]);
      $mr_ID = (int)$pdo->lastInsertId();

      if ($diarized && hasColumn($pdo, 'meetingrecordsdata', 'mr_segments_json')) {
        $segmentsJson = json_encode($segments, JSON_UNESCAPED_UNICODE);
        $speakerCount = count(array_unique(array_column($segments, 'speaker')));
        $upd = $pdo->prepare("UPDATE meetingrecordsdata SET mr_segments_json=?, mr_speaker_count=? WHERE mr_ID=?");
        $upd->execute([$segmentsJson, $speakerCount, $mr_ID]);
      }

      $out = ['ok'=>true,'m_ID'=>$mid,'mr_ID'=>$mr_ID,'content'=>$contentText,'file_path'=>$relPath,'type'=>'audio'];
      if ($diarized && !empty($segments)) $out['segments'] = $segments;
      jexit($out);
    }

    // ✅ AI 統整（存 meetingdata.m_summary）- 統整內容工作區所有資料：打字紀錄、文字檔、圖片 OCR、語音轉錄、既有 AI 摘要
    case 'meeting_summarize': {
      $useServerContent = !empty($json['use_server_content']);
      $text = (string)($json['text'] ?? '');

      $mid = $m_ID;
      if ($mid) {
        $meeting = getMeetingByMidWithAccess($pdo, $mid, (string)$my_uid);
        if (!$meeting) jexit(['ok'=>false,'msg'=>'找不到會議或無權限存取']);
      } else {
        $team_ID = getMyTeamId($pdo, (string)$my_uid);
        if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);
        $mid = getLatestMeetingId($pdo, $team_ID);
        if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議，請至會議列表點擊「新增會議」建立']);
        assertMeetingOwner($pdo, $mid, $team_ID);
      }
      if (!canUserEditMeeting($pdo, $mid, (string)$my_uid)) {
        jexit(['ok'=>false,'msg'=>'此會議已確認，無法編輯。請由指導老師按下「開放修改」後再編輯。']);
      }

      if (!$openaiKey) jexit(['ok'=>false,'msg'=>'OpenAI API 尚未設定，請在 includes/ai_config.php 設定 openai_api_key，或設定環境變數 OPENAI_API_KEY']);

      // 若要求從伺服器取得內容，或前端未傳入文字，則從 DB 彙整所有工作區資料
      if ($useServerContent || trim($text) === '') {
        $parts = [];
        $meeting = getMeetingByMidWithAccess($pdo, $mid, (string)$my_uid);
        if (!$meeting) jexit(['ok'=>false,'msg'=>'找不到會議或無權限存取']);
        // 1. 打字紀錄 (note)
        $n = $pdo->prepare("SELECT mr_content FROM meetingrecordsdata WHERE m_ID=? AND mr_type='note' AND (mr_status IS NULL OR mr_status=1)");
        $n->execute([$mid]);
        while ($row = $n->fetch(PDO::FETCH_ASSOC)) {
          $c = trim((string)($row['mr_content'] ?? ''));
          if ($c !== '') $parts[] = $c;
        }
        // 2. 文字檔、圖片 OCR、語音轉錄 (text, image, audio)
        $r = $pdo->prepare("SELECT mr_type, mr_content, mr_name FROM meetingrecordsdata WHERE m_ID=? AND mr_type IN ('text','image','audio') AND (mr_status IS NULL OR mr_status=1) ORDER BY mr_ID ASC");
        $r->execute([$mid]);
        while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
          $c = trim((string)($row['mr_content'] ?? ''));
          if ($c !== '') {
            $label = match ($row['mr_type'] ?? '') {
              'text' => '文字檔',
              'image' => '圖片 OCR',
              'audio' => '語音轉錄',
              default => ''
            };
            $name = trim((string)($row['mr_name'] ?? ''));
            $parts[] = ($label && $name ? "【{$label}：{$name}】\n" : '') . $c;
          }
        }
        // 3. 既有 AI 摘要（重新統整時納入）
        $mSummary = trim((string)($meeting['m_summary'] ?? ''));
        $mPoint = trim((string)($meeting['m_point'] ?? ''));
        if ($mSummary !== '' || $mPoint !== '') {
          $parts[] = "【既有 AI 統整】\n" . ($mSummary ?: '') . ($mSummary && $mPoint ? "\n\n" : '') . ($mPoint ?: '');
        }
        $text = implode("\n\n", $parts);
      }
      if (trim($text) === '') jexit(['ok'=>false,'msg'=>'沒有內容可統整']);
      $result = openaiSummarizeText($openaiKey, $openaiModel, $text);
      $summary = $result['summary'] ?? '';
      $points = $result['points'] ?? '';

      $upd = $pdo->prepare("UPDATE meetingdata SET m_summary=?, m_point=? WHERE m_ID=?");
      $upd->execute([$summary, $points, $mid]);

      jexit(['ok'=>true,'m_ID'=>$mid,'summary'=>$summary,'points'=>$points,'tasks_count'=>0]);
    }

    // ✅ 手動儲存 AI 統整內容（編輯後儲存，並釋放 AI_status）
    case 'meeting_save_summary': {
      $mid = $m_ID;
      if ($mid) {
        $meeting = getMeetingByMidWithAccess($pdo, $mid, (string)$my_uid);
        if (!$meeting) jexit(['ok'=>false,'msg'=>'找不到會議或無權限存取']);
      } else {
        $team_ID = getMyTeamId($pdo, (string)$my_uid);
        if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);
        $mid = getLatestMeetingId($pdo, $team_ID);
        if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議']);
        assertMeetingOwner($pdo, $mid, $team_ID);
      }
      if (!canUserEditMeeting($pdo, $mid, (string)$my_uid)) jexit(['ok'=>false,'msg'=>'此會議已確認，無法編輯。請由指導老師按下「開放修改」後再編輯。']);

      $summary = trim((string)($json['summary'] ?? ''));
      $points = trim((string)($json['points'] ?? ''));
      $sets = ['m_summary=?', 'm_point=?'];
      $params = [$summary, $points];
      if (hasColumn($pdo, 'meetingdata', 'AI_status')) {
        $sets[] = 'AI_status=3';
      }
      $params[] = $mid;
      $sql = "UPDATE meetingdata SET " . implode(', ', $sets) . " WHERE m_ID=?";
      $pdo->prepare($sql)->execute($params);
      jexit(['ok'=>true,'m_ID'=>$mid,'msg'=>'已儲存']);
    }
    break;

    // ✅ 儲存 text/image/audio 紀錄內容（編輯完成後呼叫，設定 data_status=3）
    case 'meeting_save_record': {
      $mid = $m_ID ?: getLatestMeetingId($pdo, getMyTeamId($pdo, (string)$my_uid) ?: 0);
      if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議']);
      $meeting = getMeetingByMidWithAccess($pdo, $mid, (string)$my_uid);
      if (!$meeting) jexit(['ok'=>false,'msg'=>'找不到會議或無權限存取']);
      if (!canUserEditMeeting($pdo, $mid, (string)$my_uid)) jexit(['ok'=>false,'msg'=>'此會議已確認，無法編輯。請由指導老師按下「開放修改」後再編輯。']);

      $mr_ID = (int)($json['mr_ID'] ?? 0);
      $content = (string)($json['content'] ?? '');
      if (!$mr_ID) jexit(['ok'=>false,'msg'=>'缺少 mr_ID']);

      $chk = $pdo->prepare("SELECT mr_type FROM meetingrecordsdata WHERE mr_ID=? AND m_ID=? AND mr_type IN ('text','image','audio') LIMIT 1");
      $chk->execute([$mr_ID, $mid]);
      $row = $chk->fetch(PDO::FETCH_ASSOC);
      if (!$row) jexit(['ok'=>false,'msg'=>'找不到該筆紀錄']);

      $sets = "mr_content=?, mr_created_d=NOW()";
      if (hasColumn($pdo, 'meetingrecordsdata', 'data_status')) $sets .= ", data_status=3";
      $upd = $pdo->prepare("UPDATE meetingrecordsdata SET {$sets} WHERE mr_ID=? AND m_ID=?");
      $upd->execute([$content, $mr_ID, $mid]);
      if ($upd->rowCount() < 1) jexit(['ok'=>false,'msg'=>'更新失敗']);
      jexit(['ok'=>true,'m_ID'=>$mid,'mr_ID'=>$mr_ID,'msg'=>'已儲存']);
    }
    break;

    // ✅ 將 AI 統整條列式（m_point）新增為待辦事項
    case 'meeting_add_points_to_tasks': {
      $mid = $m_ID;
      if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議，請至會議列表點擊「新增會議」建立']);
      $meeting = getMeetingByMidWithAccess($pdo, $mid, (string)$my_uid);
      if (!$meeting) jexit(['ok'=>false,'msg'=>'找不到會議或無權限存取']);

      // 若前端傳入 points 陣列，優先使用；否則從 m_point 解析
      $lines = [];
      $reqPoints = $json['points'] ?? null;
      if (is_array($reqPoints) && !empty($reqPoints)) {
        $lines = array_values(array_filter(array_map('trim', $reqPoints), function ($s) { return $s !== ''; }));
      } else {
        $m_point = trim((string)($meeting['m_point'] ?? ''));
        if ($m_point === '') jexit(['ok'=>false,'msg'=>'尚無 AI 統整條列式內容，請先完成 AI 摘要']);
        $lines = preg_split('/[\r\n]+/u', $m_point, -1, PREG_SPLIT_NO_EMPTY);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, function ($s) { return $s !== ''; });
      }
      if (empty($lines)) jexit(['ok'=>false,'msg'=>'尚無有效的條列式項目']);

      $cohort_ID = (int)($_SESSION['cohort_ID'] ?? 0);
      $uid = $pdo->quote((string)$my_uid);
      $team = (int)$meeting['m_team_ID'];
      $has_meeting_col = hasColumn($pdo, 'taskdata', 'task_meeting_ID');
      $has_rd = hasColumn($pdo, 'taskdata', 'rd_ID');
      $has_req = hasColumn($pdo, 'taskdata', 'req_ID');

      $inserted = 0;
      foreach ($lines as $line) {
        $title = mb_substr($line, 0, 150);
        $title_esc = $pdo->quote($title);
        $desc_esc = $pdo->quote(mb_strlen($line) > 200 ? mb_substr($line, 0, 200) : $line);

        $cols = 'task_team_ID, task_u_ID, task_cohort_ID, ms_ID, task_title, task_desc, task_start_d, task_end_d, task_done_u_ID, task_done_d, task_status, task_priority, task_created_d';
        $vals = "{$team}, {$uid}, {$cohort_ID}, NULL, {$title_esc}, {$desc_esc}, NULL, NULL, NULL, NULL, 0, 0, NOW()";
        if ($has_rd) { $cols .= ', rd_ID'; $vals .= ', NULL'; }
        if ($has_req && !$has_rd) { $cols .= ', req_ID'; $vals .= ', NULL'; }
        if ($has_meeting_col) { $cols .= ', task_meeting_ID'; $vals .= ', ' . (int)$mid; }

        $sql = "INSERT INTO taskdata ({$cols}) VALUES ({$vals})";
        try {
          $pdo->exec($sql);
          $inserted++;
        } catch (PDOException $e) {
          // 略過單筆錯誤，繼續下一筆
        }
      }

      jexit(['ok'=>true,'m_ID'=>$mid,'tasks_count'=>$inserted,'msg'=>"已新增 {$inserted} 筆待辦事項"]);
    }
    break;

case 'meeting_update_meta': {
  $team_ID = getMyTeamId($pdo, $my_uid);
  if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

  $mid = $m_ID ?: getLatestMeetingId($pdo, $team_ID);
  if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議，請至會議列表點擊「新增會議」建立']);
  $meeting = assertMeetingOwner($pdo, $mid, $team_ID);
  if (!canUserEditMeeting($pdo, $mid, (string)$my_uid)) {
    jexit(['ok'=>false,'msg'=>'此會議已確認，無法編輯。請由指導老師按下「開放修改」後再編輯。']);
  }

  $title = trim($json['m_title'] ?? '');
  if ($title === '') jexit(['ok'=>false,'msg'=>'標題不可為空']);

  $stmt = $pdo->prepare("UPDATE meetingdata SET m_title=? WHERE m_ID=?");
  $stmt->execute([$title, $mid]);

  jexit(['ok'=>true, 'm_ID'=>$mid, 'm_title'=>$title]);
}

    // ✅ 指導老師確認此次會議（鎖定）
    case 'meeting_confirm': {
      if (!isTeacherRole()) {
        jexit(['ok'=>false,'msg'=>'只有指導老師可以確認此次會議']);
      }

      $team_ID = $request_team_ID ?: getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID && $m_ID > 0) {
        $stmt = $pdo->prepare("SELECT m_team_ID FROM meetingdata WHERE m_ID=? LIMIT 1");
        $stmt->execute([$m_ID]);
        $tid = $stmt->fetchColumn();
        $team_ID = $tid ? (int)$tid : null;
      }
      if (!$team_ID) $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $mid = $m_ID ?: getLatestMeetingId($pdo, $team_ID);
      if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議，請至會議列表點擊「新增會議」建立']);
      assertMeetingOwner($pdo, $mid, $team_ID);

      // 檢查是否有人正在編輯（data_status=1 或 AI_status=1），排除當前使用者與過期鎖定
      $someoneEditing = false;
      if (hasColumn($pdo, 'meetingrecordsdata', 'data_status')) {
        $sql = "SELECT mr_user_ID FROM meetingrecordsdata WHERE m_ID=? AND data_status=1";
        if (hasColumn($pdo, 'meetingrecordsdata', 'data_status_at')) {
          $sql .= " AND (data_status_at IS NULL OR data_status_at > NOW() - INTERVAL 5 MINUTE)";
        }
        $sql .= " LIMIT 1";
        $chk = $pdo->prepare($sql);
        $chk->execute([$mid]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if ($row && trim((string)($row['mr_user_ID'] ?? '')) !== trim((string)$my_uid)) $someoneEditing = true;
      }
      if (!$someoneEditing && hasColumn($pdo, 'meetingdata', 'AI_status')) {
        $chk = $pdo->prepare("SELECT AI_status FROM meetingdata WHERE m_ID=? LIMIT 1");
        $chk->execute([$mid]);
        if ((int)($chk->fetchColumn() ?? 3) === 1) $someoneEditing = true;
      }
      if ($someoneEditing) {
        jexit(['ok'=>false,'msg'=>'目前有人正在編輯會議內容，請等待對方完成後再確認會議']);
      }

      $state = loadAttendanceStateFromMeeting($pdo, $mid);
      $state['locked'] = true;
      $state['locked_by'] = (string)$my_uid;
      $state['locked_at'] = date('Y-m-d H:i:s');
      saveAttendanceStateToMeeting($pdo, $mid, $state);

      if (hasColumn($pdo, 'meetingdata', 'meeting_status')) {
        $pdo->prepare("UPDATE meetingdata SET meeting_status=3 WHERE m_ID=?")->execute([$mid]);
      }

      // 讀取會議標題，供時間軸顯示
      $mt = $pdo->prepare("SELECT m_title, m_team_ID, COALESCE(m_start_d, m_created_d) AS evt_d FROM meetingdata WHERE m_ID=? LIMIT 1");
      $mt->execute([$mid]);
      $row = $mt->fetch(PDO::FETCH_ASSOC) ?: [];
      $mTitle = trim((string)($row['m_title'] ?? '會議'));
      $mTeam  = (int)($row['m_team_ID'] ?? $team_ID);
      $evtD   = $row['evt_d'] ?? date('Y-m-d H:i:s');

      // 寫入時間軸：確認會議
      team_timeline_add_event(
        $pdo,
        $mTeam,
        '會議',
        '確認會議',
        $mTitle,
        '確認會議：' . $mTitle,
        '會議已由指導老師確認並鎖定出席與內容。',
        'meetingdata',
        $mid,
        'meeting',
        $evtD,
        (string)$my_uid
      );

      jexit([
        'ok'=>true,
        'm_ID'=>$mid,
        'locked'=>true,
        'msg'=>'已確認此次會議，簽到與會議內容已鎖定'
      ]);
    }

    // ✅ 指導老師開放修改（已確認會議後，僅指導老師可呼叫，讓組員與老師可再編輯）
    case 'meeting_reopen': {
      if (!isTeacherRole()) {
        jexit(['ok'=>false,'msg'=>'只有指導老師可以開放修改']);
      }

      $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $mid = $m_ID ?: getLatestMeetingId($pdo, $team_ID);
      if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議']);
      assertMeetingOwner($pdo, $mid, $team_ID);

      $state = loadAttendanceStateFromMeeting($pdo, $mid);
      if (empty($state['locked'])) {
        jexit(['ok'=>false,'msg'=>'此會議尚未確認，無需開放修改']);
      }

      $state['reopened'] = true;
      $state['reopened_by'] = (string)$my_uid;
      saveAttendanceStateToMeeting($pdo, $mid, $state);

      jexit([
        'ok'=>true,
        'm_ID'=>$mid,
        'reopened'=>true,
        'msg'=>'已開放修改，組員與指導老師可再編輯會議內容'
      ]);
    }

    // ✅ 簽到（共享狀態：設定自己為參與）
    case 'check_in': {
      $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $mid = $m_ID ?: getLatestMeetingId($pdo, $team_ID);
      if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議，請至會議列表點擊「新增會議」建立']);
      assertMeetingOwner($pdo, $mid, $team_ID);
      if (!canUserEditMeeting($pdo, $mid, (string)$my_uid)) {
        jexit(['ok'=>false,'msg'=>'此會議已確認，無法編輯。請由指導老師按下「開放修改」後再變更出席。']);
      }

      $col = hasColumn($pdo, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';

      $members = $pdo->prepare("SELECT {$col} FROM teammember WHERE team_ID=? AND (tm_status IS NULL OR tm_status=1)");
      $members->execute([$team_ID]);
      $list = $members->fetchAll(PDO::FETCH_COLUMN);

      $state = loadAttendanceStateFromMeeting($pdo, $mid);
      $map = is_array($state['status_map'] ?? null) ? $state['status_map'] : [];
      foreach ($list as $uid) {
        if (!isset($map[(string)$uid])) $map[(string)$uid] = 'no';
      }
      $map[(string)$my_uid] = 'ok';
      $state['status_map'] = $map;
      saveAttendanceStateToMeeting($pdo, $mid, $state);
      $rows = buildTeamAttendanceRows($pdo, $team_ID, $mid, $col, $map);

      jexit([
        'ok'=>true,
        'm_ID'=>$mid,
        'list'=>$rows,
        'locked'=>false
      ]);
    }

    // ✅ 共享設定出席狀態（參與 / 未到）
    case 'set_attendance': {
      $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $mid = $m_ID ?: getLatestMeetingId($pdo, $team_ID);
      if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議，請至會議列表點擊「新增會議」建立']);
      assertMeetingOwner($pdo, $mid, $team_ID);
      if (!canUserEditMeeting($pdo, $mid, (string)$my_uid)) {
        jexit(['ok'=>false,'msg'=>'此會議已確認，無法編輯。請由指導老師按下「開放修改」後再變更出席。']);
      }

      $targetUid = trim((string)($_POST['target_u_ID'] ?? $_GET['target_u_ID'] ?? ($json['target_u_ID'] ?? '')));
      $status = trim((string)($_POST['status'] ?? $_GET['status'] ?? ($json['status'] ?? '')));
      if ($targetUid === '') jexit(['ok'=>false,'msg'=>'缺少 target_u_ID']);
      if (!in_array($status, ['ok', 'no'], true)) jexit(['ok'=>false,'msg'=>'status 只允許 ok/no']);

      $col = hasColumn($pdo, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
      $chk = $pdo->prepare("SELECT 1 FROM teammember WHERE team_ID=? AND {$col}=? AND (tm_status IS NULL OR tm_status=1) LIMIT 1");
      $chk->execute([$team_ID, $targetUid]);
      if (!$chk->fetchColumn()) jexit(['ok'=>false,'msg'=>'該成員不在本組']);

      $state = loadAttendanceStateFromMeeting($pdo, $mid);
      $map = is_array($state['status_map'] ?? null) ? $state['status_map'] : [];
      $map[(string)$targetUid] = $status;
      $state['status_map'] = $map;
      saveAttendanceStateToMeeting($pdo, $mid, $state);

      jexit(['ok'=>true, 'm_ID'=>$mid, 'target_u_ID'=>$targetUid, 'status'=>$status]);
    }

    // ✅ 編輯鎖定：進入/離開編輯時設定 data_status 或 AI_status（1=編輯中, 3=完成）
    case 'meeting_edit_lock': {
      $team_ID = $request_team_ID ?: getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID && $m_ID > 0) {
        $stmt = $pdo->prepare("SELECT m_team_ID FROM meetingdata WHERE m_ID=? LIMIT 1");
        $stmt->execute([$m_ID]);
        $tid = $stmt->fetchColumn();
        $team_ID = $tid ? (int)$tid : null;
      }
      if (!$team_ID) $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $mid = $m_ID ?: getLatestMeetingId($pdo, $team_ID);
      if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議']);
      assertMeetingOwner($pdo, $mid, $team_ID);
      if (!canUserEditMeeting($pdo, $mid, (string)$my_uid)) jexit(['ok'=>false,'msg'=>'此會議已確認，無法編輯。請由指導老師按下「開放修改」後再編輯。']);

      $lock = (int)($json['lock'] ?? 0);
      $mr_ID = (int)($json['mr_ID'] ?? 0);
      $kind = trim((string)($json['kind'] ?? ''));

      $hasDataStatus = hasColumn($pdo, 'meetingrecordsdata', 'data_status');
      $hasAiStatus = hasColumn($pdo, 'meetingdata', 'AI_status');

      if ($kind === 'summary') {
        if (!$hasAiStatus) jexit(['ok'=>true,'m_ID'=>$mid]);
        $statusVal = ($lock === 1) ? 1 : 3;
        $chk = $pdo->prepare("SELECT AI_status FROM meetingdata WHERE m_ID=? LIMIT 1");
        $chk->execute([$mid]);
        $cur = (int)($chk->fetchColumn() ?? 3);
        if ($lock === 1 && $cur === 1) jexit(['ok'=>false,'msg'=>'AI 統整內容正在被其他人編輯中，請稍後再試']);
        $pdo->prepare("UPDATE meetingdata SET AI_status=? WHERE m_ID=?")->execute([$statusVal, $mid]);
        jexit(['ok'=>true,'m_ID'=>$mid]);
      }

      if ($mr_ID > 0 && $hasDataStatus) {
        $statusVal = ($lock === 1) ? 1 : 3;
        $hasStatusAt = hasColumn($pdo, 'meetingrecordsdata', 'data_status_at');
        $chkCols = "data_status" . ($hasStatusAt ? ", data_status_at" : "");
        $chk = $pdo->prepare("SELECT {$chkCols} FROM meetingrecordsdata WHERE mr_ID=? AND m_ID=? LIMIT 1");
        $chk->execute([$mr_ID, $mid]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) jexit(['ok'=>false,'msg'=>'找不到該筆紀錄']);
        $cur = (int)($row['data_status'] ?? 3);
        if ($lock === 1 && $cur === 1) {
          if ($hasStatusAt && !empty($row['data_status_at'])) {
            $ageStmt = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, data_status_at, NOW()) FROM meetingrecordsdata WHERE mr_ID=? AND m_ID=? LIMIT 1");
            $ageStmt->execute([$mr_ID, $mid]);
            $age = (int)($ageStmt->fetchColumn() ?: 0);
            if ($age >= 5) $cur = 3;
          }
          if ($cur === 1) jexit(['ok'=>false,'msg'=>'該檔案正在被其他人編輯中，請稍後再試']);
        }
        $sets = "data_status=?";
        if ($hasStatusAt) {
          $sets .= ", data_status_at=" . ($lock === 1 ? "NOW()" : "NULL");
        }
        $pdo->prepare("UPDATE meetingrecordsdata SET {$sets} WHERE mr_ID=? AND m_ID=?")->execute([$statusVal, $mr_ID, $mid]);
        jexit(['ok'=>true,'m_ID'=>$mid,'mr_ID'=>$mr_ID]);
      }

      jexit(['ok'=>true,'m_ID'=>$mid]);
    }

    // ✅ 清除當前使用者的過期編輯鎖定（進入會議時呼叫，避免殘留 data_status=1）
    case 'meeting_clear_my_locks': {
      $team_ID = $request_team_ID ?: getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID && $m_ID > 0) {
        $stmt = $pdo->prepare("SELECT m_team_ID FROM meetingdata WHERE m_ID=? LIMIT 1");
        $stmt->execute([$m_ID]);
        $tid = $stmt->fetchColumn();
        $team_ID = $tid ? (int)$tid : null;
      }
      if (!$team_ID) $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);
      $mid = $m_ID ?: getLatestMeetingId($pdo, $team_ID);
      if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議']);
      assertMeetingOwner($pdo, $mid, $team_ID);
      if (hasColumn($pdo, 'meetingrecordsdata', 'data_status')) {
        $upd = $pdo->prepare("UPDATE meetingrecordsdata SET data_status=3 WHERE m_ID=? AND mr_user_ID=? AND data_status=1");
        $upd->execute([$mid, $my_uid]);
      }
      jexit(['ok'=>true,'cleared'=>true]);
    }

    // ✅ 輪詢：取得會議編輯狀態（是否有人編輯中、是否已確認）
    case 'meeting_edit_status': {
      $team_ID = $request_team_ID ?: getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID && $m_ID > 0) {
        $stmt = $pdo->prepare("SELECT m_team_ID FROM meetingdata WHERE m_ID=? LIMIT 1");
        $stmt->execute([$m_ID]);
        $tid = $stmt->fetchColumn();
        $team_ID = $tid ? (int)$tid : null;
      }
      if (!$team_ID) $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $mid = $m_ID ?: getLatestMeetingId($pdo, $team_ID);
      if (!$mid) jexit(['ok'=>false,'msg'=>'尚無會議']);

      $meeting = assertMeetingOwner($pdo, $mid, $team_ID);
      $meeting_confirmed = isMeetingLocked($pdo, $mid);
      $can_edit = canUserEditMeeting($pdo, $mid, (string)$my_uid);
      $attState = loadAttendanceStateFromMeeting($pdo, $mid);
      $reopened = !empty($attState['reopened'] ?? null);

      $record_editing = null;
      $ai_editing = false;

      if (hasColumn($pdo, 'meetingrecordsdata', 'data_status')) {
        $sql = "SELECT mr_ID, mr_user_ID FROM meetingrecordsdata WHERE m_ID=? AND data_status=1";
        if (hasColumn($pdo, 'meetingrecordsdata', 'data_status_at')) {
          $sql .= " AND (data_status_at IS NULL OR data_status_at > NOW() - INTERVAL 5 MINUTE)";
        }
        $sql .= " LIMIT 1";
        $r = $pdo->prepare($sql);
        $r->execute([$mid]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          $byUser = trim((string)($row['mr_user_ID'] ?? ''));
          if ($byUser !== trim((string)$my_uid)) {
            $record_editing = ['mr_ID'=>(int)$row['mr_ID'], 'by_user'=>$byUser];
          }
        }
      }

      if (hasColumn($pdo, 'meetingdata', 'AI_status')) {
        $a = $pdo->prepare("SELECT AI_status FROM meetingdata WHERE m_ID=? LIMIT 1");
        $a->execute([$mid]);
        $aiVal = (int)($a->fetchColumn() ?? 3);
        $ai_editing = ($aiVal === 1);
      }

      jexit([
        'ok'=>true,
        'm_ID'=>$mid,
        'meeting_confirmed'=>$meeting_confirmed,
        'can_edit'=>$can_edit,
        'reopened'=>$reopened,
        'record_editing'=>$record_editing,
        'ai_editing'=>$ai_editing,
        'someone_editing'=>($record_editing !== null || $ai_editing)
      ]);
    }

    case 'get_attendance': {
      $team_ID = getMyTeamId($pdo, (string)$my_uid);
      if (!$team_ID) jexit(['ok'=>false,'msg'=>'找不到你的組別']);

      $mid = $m_ID ?: getLatestMeetingId($pdo, $team_ID);

      // 尚無會議時，仍回傳組員名單（全部顯示為未出席），讓使用者可看到自己的組員
      if (!$mid) {
        $col = hasColumn($pdo, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
        $rows = buildTeamAttendanceRows($pdo, $team_ID, 0, $col, []);
        jexit([
          'ok'=>true,
          'm_ID'=>null,
          'list'=>$rows,
          'locked'=>true,
          'reopened'=>false,
          'can_edit'=>false,
          'locked_by'=>null,
          'locked_at'=>null,
          'no_meeting'=>true
        ]);
      }

      assertMeetingOwner($pdo, $mid, $team_ID);

      $col = hasColumn($pdo, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
      $state = loadAttendanceStateFromMeeting($pdo, $mid);
      $map = is_array($state['status_map'] ?? null) ? $state['status_map'] : [];
      $rows = buildTeamAttendanceRows($pdo, $team_ID, $mid, $col, $map);
      $locked = !empty($state['locked']);
      $can_edit = canUserEditMeeting($pdo, $mid, (string)$my_uid);

      jexit([
        'ok'=>true,
        'm_ID'=>$mid,
        'list'=>$rows,
        'locked'=>$locked,
        'reopened'=>!empty($state['reopened']),
        'can_edit'=>$can_edit,
        'locked_by'=>$state['locked_by'] ?? null,
        'locked_at'=>$state['locked_at'] ?? null
      ]);
    }

    default:
      jexit(['ok'=>false,'msg'=>'未知的操作: '.$do]);
  }

} catch (Exception $e) {
  jexit(['ok'=>false,'msg'=>$e->getMessage()]);
}
