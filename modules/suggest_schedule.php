<?php

/**
 * 建議表與時程表發送模組（全部邏輯集中於此檔）
 * 發佈時：1. 寫入系統通知（COHORT） 2. 寄送 Gmail 給當屆所有人（含 PDF 下載連結）
 * 由 pages/integrate_data.php 在 action=publish 時引入。
 * Gmail 寄送方式參考 modules/team_apply copy.php 的 GAS 寄信實作。
 */

global $conn;

// 透過 Google Apps Script (GAS) 發送 Gmail（專題申請已驗證可用的方式）
// 參考 modules/team_apply copy.php 的 sendStudentEmailGeneric 實作
if (!function_exists('sendMailViaGas')) {
    /**
     * 使用 Google Apps Script 發送郵件
     *
     * @param string $to      收件人 email
     * @param string $subject 主旨
     * @param string $message 內文（純文字）
     * @return array ['ok' => bool, 'msg' => string] 依 GAS 回傳 JSON 解析；連線失敗時為 ['ok'=>false,'msg'=>'無法連線到 GAS']
     */
    function sendMailViaGas(string $to, string $subject, string $message): array
    {
        if (trim($to) === '') {
            return ['ok' => false, 'msg' => '收件人為空'];
        }

        // 與 team_apply copy.php 相同的 Google Apps Script 端點
        $url = "https://script.google.com/macros/s/AKfycbyLLkHxyGhJkllgpztDzcXPcp_IKXL_GS2lnOGDegOAQplqQMVU0EA4LF4ZPDrrkfyb/exec";

        $data = [
            'to'      => $to,
            'subject' => $subject,
            'message' => $message,
        ];

        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query($data),
                'timeout' => 20,
            ],
        ];

        $ctx = stream_context_create($options);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            return ['ok' => false, 'msg' => '無法連線到 GAS'];
        }

        $decoded = json_decode($res, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'msg' => 'GAS 回傳非 JSON'];
        }
        return [
            'ok'  => !empty($decoded['ok']),
            'msg' => isset($decoded['msg']) ? (string) $decoded['msg'] : (isset($decoded['message']) ? (string) $decoded['message'] : ''),
        ];
    }
}

// 避免 PHP Notice/Warning 等輸出混入，導致前端收到非 JSON
@ini_set('display_errors', '0');
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

$u_ID = $_SESSION['u_ID'] ?? null;
$role_ID = $_SESSION['role_ID'] ?? null;

// 僅主任(1)、科辦(2)可發佈
if (!$u_ID || !in_array($role_ID, [1, 2])) {
    if (ob_get_level()) ob_end_clean();
    respond(["ok" => false, "msg" => "無權限：僅主任與科辦可發佈"]);
}

$format = trim($_POST['format'] ?? '');
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$cohort_ID = isset($_POST['cohort_ID']) ? (int)$_POST['cohort_ID'] : 0;
$title = trim($_POST['title'] ?? '');

// 目前先將科辦／主任操作時的屆別「寫死」為 110 級（cohort_ID = 3）
// 之後若要支援多屆別，可再改回由前端傳入或設定檔決定
if (in_array($role_ID, [1, 2], true)) {
    $cohort_ID = 3;
}

if (!$format || !$id || !$cohort_ID || $title === '') {
    if (ob_get_level()) ob_end_clean();
    respond(["ok" => false, "msg" => "請提供完整資料（資料類型、id、屆別、標題）"]);
}

try {
    $stmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ? AND cohort_status = 1");
    $stmt->execute([$cohort_ID]);
    $cohort = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cohort) {
        if (ob_get_level()) ob_end_clean();
        respond(["ok" => false, "msg" => "找不到指定的屆別或屆別已停用"]);
    }
    $cohort_name = $cohort['cohort_name'];

    if ($format === '時程表') {
        // 連結改為「匯出 PDF」頁（download=1 會自動觸發下載），與 integrate 頁「匯出 PDF」一致
        $viewUrl = "pages/schedule_export.php?cohort_ID=" . $cohort_ID . "&tinforma_ID=" . $id . "&download=1";
        $msgTitle = "時程表通知：{$title}";
        $msgContent = "{$cohort_name} 的時程表「{$title}」已發布。";
    } elseif ($format === '審查建議表' || $format === '初審建議表') {
        // 建議表：suggest_export 頁面載入後會自動產生並下載 PDF，等同「匯出 PDF」
        $viewUrl = "pages/suggest_export.php?cohort_ID=" . $cohort_ID . "&group_ID=all&title=" . rawurlencode($title);
        $msgTitle = "建議表通知：{$title}";
        $msgContent = "{$cohort_name} 的建議表「{$title}」已發布。";
    } else {
        if (ob_get_level()) ob_end_clean();
        respond(["ok" => false, "msg" => "不支援的資料類型"]);
    }

    $urlData = [['type' => 'link', 'url' => $viewUrl, 'label' => '下載 PDF']];
    $msg_url = json_encode($urlData, JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("
        INSERT INTO msgdata
        (msg_title, msg_content, msg_url, msg_type, msg_a_u_ID, msg_status, msg_start_d, msg_created_d)
        VALUES (?, ?, ?, 'SYSTEM_NOTICE', ?, 1, NOW(), NOW())
    ");
    $stmt->execute([$msgTitle, $msgContent, $msg_url, $u_ID]);
    $msg_ID = $conn->lastInsertId();
    if (!$msg_ID) {
        if (ob_get_level()) ob_end_clean();
        respond(["ok" => false, "msg" => "建立通知失敗"]);
    }

    $stmt = $conn->prepare("
        INSERT INTO msgtargetdata (msg_ID, msg_target_type, msg_target_ID)
        VALUES (?, 'COHORT', ?)
    ");
    $stmt->execute([$msg_ID, $cohort_ID]);






    // ---------- 把建議加入到便利貼裡面----------
    if ($format === '審查建議表' || $format === '初審建議表') {
        try {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

            $params = http_build_query([
                'do' => 'system_new_task',
                'msg_ID' => $msg_ID,
                'cohort_ID' => $cohort_ID,
                'format' => $format,
                'sf_ID' => $id,
                'title' => $title,
                'publish_u_ID' => $u_ID
            ]);
            $callUrl = $scheme . '://' . $host . '/modules/T_req%26task.php?' . $params;

            $context = stream_context_create([
                'http' => [
                    'method'  => 'GET',
                    'timeout' => 10,
                ]
            ]);

            $taskResult = @file_get_contents($callUrl, false, $context);

            if ($taskResult === false) {
                error_log('system_new_task 呼叫失敗：無法連線 ' . $callUrl);
            } else {
                error_log('system_new_task result: ' . $taskResult);
            }
        } catch (Throwable $e) {
            error_log('system_new_task 呼叫失敗：' . $e->getMessage());
        }
    }





    // ---------- 寄送 Gmail（含頁面查看連結，全部寫在此檔內）----------
    $mailSent = 0;
    $mailFail = 0;
    $failList = [];
    $recipients = [];
    $stmt = $conn->prepare("
        SELECT DISTINCT u.u_ID, u.u_name, u.u_gmail
        FROM userdata u
        INNER JOIN enrollmentdata e ON e.enroll_u_ID = u.u_ID AND e.cohort_ID = ? AND e.enroll_status = 1
        WHERE u.u_status = 1 AND u.u_gmail IS NOT NULL AND TRIM(u.u_gmail) != ''
    ");
    $stmt->execute([$cohort_ID]);
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $fullViewUrl = $baseUrl . '/' . ltrim($viewUrl, '/');

    foreach ($recipients as $r) {
        $email = trim($r['u_gmail']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $body = "您好，{$r['u_name']}：\n\n請至以下連結下載 PDF：\n" . $fullViewUrl . "\n\n---\n此為系統自動發送，請勿直接回覆。\n專題日總彙系統";

        $result = sendMailViaGas($email, $msgTitle, $body);
        if ($result['ok']) {
            $mailSent++;
        } else {
            $mailFail++;
            $failList[] = [
                'u_ID'   => $r['u_ID'],
                'name'   => $r['u_name'],
                'email'  => $email,
                'reason' => $result['msg'] ?? '未知錯誤',
            ];
        }
        usleep(300000); // 約 0.3 秒節流，避免 Gmail/GAS 被限制
    }

    $msg = "已發佈並通知 {$cohort_name} 所有人";
    if ($mailSent > 0) {
        $msg .= "，已寄送 {$mailSent} 封 Gmail（含 PDF 下載連結）";
        if ($mailFail > 0) $msg .= "（{$mailFail} 封失敗）";
        $msg .= "。";
    } else {
        $msg .= "。";
        if (count($recipients) > 0) {
            $msg .= "";
        } else {
            $msg .= " 當屆成員若未填寫 Gmail 則不會收到郵件。";
        }
    }

    if (ob_get_level()) ob_end_clean();
    respond([
        "ok" => true,
        "msg" => $msg,
        "msg_ID" => $msg_ID,
        "mail_sent" => $mailSent,
        "mail_fail" => $mailFail,
        "fail_list" => $failList,
    ]);
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    error_log('suggest_schedule publish: ' . $e->getMessage());
    respond(["ok" => false, "msg" => "發佈失敗：" . $e->getMessage()]);
}
