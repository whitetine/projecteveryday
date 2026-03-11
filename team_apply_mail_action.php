<?php
// 一鍵審核連結：供 Gmail 按鈕呼叫
session_start();
require_once __DIR__ . '/includes/pdo.php';
require_once __DIR__ . '/modules/team_apply.php';

$tap_ID = (int)($_GET['tap_ID'] ?? 0);
$action = $_GET['action'] ?? '';
$token  = $_GET['token'] ?? '';

function render_result($ok, $msg) {
    ?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8">
  <title>專題指導申請單 - 郵件審核</title>
  <link rel="stylesheet" href="css/login.css?v=<?= time() ?>">
  <style>
    body { font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif; background:#f3f4f6; margin:0; padding:0; }
    .wrap { max-width:480px; margin:40px auto; background:#fff; border-radius:16px; padding:24px 28px; box-shadow:0 10px 30px rgba(15,23,42,.15); text-align:center; }
    .title { font-size:24px; font-weight:800; margin-bottom:8px; color:#111827; }
    .msg { font-size:16px; color:#374151; margin-bottom:16px; white-space:pre-line; }
    .ok { color:#16a34a; }
    .err { color:#b91c1c; }
    .btn { display:inline-block; margin-top:10px; padding:10px 18px; border-radius:999px; background:#111827; color:#fff; text-decoration:none; font-weight:600; font-size:15px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="title">專題指導申請單</div>
    <div class="msg <?= $ok ? 'ok' : 'err' ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
    <a class="btn" href="main.php#pages/team_apply_review.php">回到審核頁</a>
  </div>
</body>
</html>
<?php
    exit;
}

if ($tap_ID <= 0 || !in_array($action, ['approve','reject'], true)) {
    render_result(false, '連結參數不正確，請回系統重新操作。');
}

$secret = getenv('TEAM_APPLY_MAIL_SECRET') ?: 'change_this_team_apply_secret';
$expected = hash_hmac('sha256', "{$action}|{$tap_ID}", $secret);
if (!hash_equals($expected, $token)) {
    render_result(false, '此連結已失效或驗證失敗。請回系統重新操作。');
}

try {
    // 直接沿用 team_apply_review_api 的審核邏輯，以避免重複建立 team
    require_once __DIR__ . '/modules/team_apply_review_api.php';
} catch (Throwable $e) {
    render_result(false, '系統錯誤：' . $e->getMessage());
}

