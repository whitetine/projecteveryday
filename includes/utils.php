<?php
/**
 * 將相對路徑（如 uploads/xxx.pdf）轉成專案實體完整路徑。
 * 專案根目錄 = DOCUMENT_ROOT + projecteverydays，避免路徑少了 projecteverydays 導致 file_exists 失敗。
 * @param string $path 相對路徑（可含 / 或 \）
 * @return string 完整實體路徑
 */
function projectevery_full_path(string $path): string
{
    $root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\') . DIRECTORY_SEPARATOR . 'projecteverydays' . DIRECTORY_SEPARATOR;
    $path = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), '/\\');
    return $root . $path;
}

function json_ok(array $data = [], int $status = 200)
{
    // 清除輸出緩衝區中的任何內容（包括可能的警告）
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    // 確保 header 已設置（如果還沒設置）
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($status);
    echo json_encode(array_merge(['ok' => true, 'status' => 'ok'], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $msg, string $code = 'ERROR', int $status = 200)
{
    // 清除輸出緩衝區中的任何內容（包括可能的警告）
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    // 確保 header 已設置（如果還沒設置）
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($status);
    echo json_encode([
        'ok'     => false,
        'status' => 'error',
        'code'   => $code,
        'msg'    => $msg,
        'message' => $msg  // 兼容性：同時提供 msg 和 message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function currentCohortIdOrDieJson(): ?int
{
    $GLOBAL_ROLES = [0,1,2,5];
    $role = (int)($_SESSION['role_ID'] ?? -1);

    if (in_array($role, $GLOBAL_ROLES, true)) return null;

    $cid = $_SESSION['cohort_ID'] ?? null;
    if (!$cid) {
        json_err('未選擇屆別，請重新登入', 'NO_COHORT', 401);
    }
    return (int)$cid;
}



register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'  => false,
            'msg' => '伺服器錯誤：' . $error['message']
        ], JSON_UNESCAPED_UNICODE);
    }
});
