<?php
session_start();
require __DIR__ . '/../includes/pdo.php';
date_default_timezone_set('Asia/Taipei');


function export_err($msg)
{
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: inline; filename="error.txt"');
        header('Cache-Control: no-cache');
    }
    die($msg);
}

$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

if (!$u_ID) {
    export_err('請先登入。');
}

// 允許學生和管理員使用（學生可以導出自己填寫的表單）
// if (!in_array($role_ID, [1, 2])) {
//     export_err('此功能僅限科辦／主任使用。');
// }

$document_id = (int)($_GET['document_id'] ?? $_GET['doc_ID'] ?? 0);
$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : '';

if ($document_id <= 0 || !in_array($format, ['docx', 'odt', 'pdf'])) {
    export_err('缺少參數：document_id 或 format（docx / odt / pdf）');
}

// 如果是 PDF 格式，重定向到 document_form_pdf.php
if ($format === 'pdf') {
    $params = ['document_id' => $document_id, 'download' => '1'];
    if (isset($_GET['form_answers']) && !empty($_GET['form_answers'])) {
        $params['form_answers'] = $_GET['form_answers'];
    }
    if (isset($_GET['apply_user'])) {
        $params['apply_user'] = $_GET['apply_user'];
    }
    if (isset($_GET['apply_other'])) {
        $params['apply_other'] = $_GET['apply_other'];
    }
    header('Location: document_form_pdf.php?' . http_build_query($params));
    exit;
}

// 獲取表單答案（如果有的話）
$form_answers = [];
if (isset($_GET['form_answers']) && !empty($_GET['form_answers'])) {
    $answersStr = urldecode($_GET['form_answers']);
    $decoded = json_decode($answersStr, true);
    if (is_array($decoded)) {
        $form_answers = $decoded;
    }
}

$stmt = $conn->prepare('SELECT * FROM document_forms WHERE doc_ID = ?');
$stmt->execute([$document_id]);
$form = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    export_err('找不到該表單');
}

$schema = null;
if (!empty($form['form_schema'])) {
    $schema = json_decode($form['form_schema'], true);
}
$remark = '';
$questions = [];
if (is_array($schema)) {
    if (isset($schema['_remark'])) {
        $remark = $schema['_remark'] ?? '';
        $questions = $schema['questions'] ?? [];
    } else {
        $questions = $schema;
    }
}
$questions = is_array($questions) ? $questions : [];

usort($questions, function ($a, $b) {
    return (int) ($a['order'] ?? 0) - (int) ($b['order'] ?? 0);
});

$doc_name = $form['doc_name'] ?? $form['document_name'] ?? '';
$doc_cat = $form['doc_des'] ?? $form['document_category'] ?? '';
$is_req = (int) ($form['is_required'] ?? 0);
$safe_name = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '_', $doc_name);
$date_str = date('Ymd');

// 西元 YYYY-MM-DD → 民國YYY年M月D日（僅顯示用，資料庫仍存西元）
function formatDateMinguo($ymd) {
    if (empty($ymd) || !is_string($ymd)) return '';
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $ymd, $m)) {
        $roc = (int)$m[1] - 1911;
        return '民國' . $roc . '年' . (int)$m[2] . '月' . (int)$m[3] . '日';
    }
    return $ymd;
}

// 將數字轉換為中文數字
function numberToChinese($num) {
    $chinese = ['', '一', '二', '三', '四', '五', '六', '七', '八', '九', '十'];
    if ($num <= 10) {
        return $chinese[$num];
    } elseif ($num < 20) {
        return '十' . ($num > 10 ? $chinese[$num % 10] : '');
    } elseif ($num < 100) {
        $tens = intval($num / 10);
        $ones = $num % 10;
        return $chinese[$tens] . '十' . ($ones > 0 ? $chinese[$ones] : '');
    }
    return (string)$num; // 超過100直接返回數字
}

if (in_array($format, ['docx', 'odt'])) {
    /* ========== WORD：參考 schedule_export_word.php，輸出 HTML as .doc ========== */
    $filename = $safe_name . '_' . $date_str . '.doc';

    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');

    ?>
    <html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word'
        xmlns='http://www.w3.org/TR/REC-html40'>

    <head>
        <meta charset="UTF-8">
        <meta name="ProgId" content="Word.Document">
        <meta name="Generator" content="Microsoft Word">
        <meta name="Originator" content="Microsoft Word">
        <!--[if gte mso 9]><xml>
<w:WordDocument>
  <w:View>Print</w:View>
  <w:Zoom>100</w:Zoom>
  <w:DoNotOptimizeForBrowser/>
</w:WordDocument>
</xml><![endif]-->
        <style>
        <?php
        $export_css_path = dirname(__DIR__) . '/css/document_form_export.css';
        if (is_file($export_css_path) && is_readable($export_css_path)) {
            echo trim(file_get_contents($export_css_path));
        }
        ?>
        </style>
    </head>

    <body>
        <div class="doc-header">
            <div class="title"><?= htmlspecialchars($doc_name) ?></div>
            <?php if ($doc_cat): ?>
                <div class="form-meta">文件分類：<?= htmlspecialchars($doc_cat) ?></div>
            <?php endif; ?>
            <?php if ($is_req): ?>
                <div class="form-meta">必繳文件</div>
            <?php endif; ?>
            <?php if ($remark !== ''): ?>
                <div class="form-remark">備註：<?= nl2br(htmlspecialchars($remark)) ?></div>
            <?php endif; ?>
        </div>

        <?php foreach ($questions as $i => $q):
            $title = $q['title'] ?? '';
            $type = $q['type'] ?? 'text';
            $required = !empty($q['required']);
            $opts = $q['options'] ?? [];
            $is_sa = ($type === 'students_advisor');
            $is_textarea = ($type === 'textarea');
            $is_table = ($type === 'table');
            $is_date = ($type === 'date');
            $rows = (int)($q['rows'] ?? 5);
            if ($is_textarea && !empty($q['textarea_display']) && $q['textarea_display'] === 'large' && $rows < 20) {
                $rows = 20; // 大型敘述區匯出時至少 20 行空白
            }
            $students = $q['students'] ?? [];
            $advisor = $q['advisor'] ?? '';
            $advisor_field_type = $q['advisor_field_type'] ?? 'single';
            $num = $i + 1;
            
            // 獲取填寫的答案
            $answerKey = 'q_' . ($q['order'] ?? $i);
            $answer = $form_answers[$answerKey] ?? '';
            ?>
            <div class="question-block">
                <div class="question-title"><?= numberToChinese($num) ?>、<?= htmlspecialchars($title) ?><?= (mb_strlen(trim($title)) > 0 && mb_substr(trim($title), -1) !== '：' && mb_substr(trim($title), -1) !== ':') ? '：' : '' ?></div>
                <?php if ($is_sa): ?>
                    <div class="sa-label" style="margin-top: 8pt;">專題生：</div>
                    <table class="sa-table">
                        <thead>
                            <tr>
                                <th style="width: 30%;">學號</th>
                                <th style="width: 70%;">姓名</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $studentRows = is_array($students) && count($students) > 0 ? $students : [['student_id' => '', 'name' => '']];
                            // 確保至少有 4-6 列
                            while (count($studentRows) < 4) {
                                $studentRows[] = ['student_id' => '', 'name' => ''];
                            }
                            if (count($studentRows) > 6) {
                                $studentRows = array_slice($studentRows, 0, 6);
                            }
                            foreach ($studentRows as $idx => $s):
                                $hasData = !empty($s['student_id']) || !empty($s['name']);
                            ?>
                            <tr>
                                <td><?= $hasData ? '<strong>' . htmlspecialchars($s['student_id'] ?? '') . '</strong>' : '' ?></td>
                                <td><?= $hasData ? '<strong>' . htmlspecialchars($s['name'] ?? '') . '</strong>' : '' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="sa-label" style="margin-top: 12pt;">指導老師：</div>
                    <?php if ($advisor_field_type === 'signature'): ?>
                        <div class="signature-block">
                            <div>姓名：<?php if (!empty($advisor)): ?><strong><?= htmlspecialchars($advisor) ?></strong><?php endif; ?></div>
                            <?php if (empty($advisor)): ?><div class="sig-line"></div><?php endif; ?>
                            <div style="margin-top: 8pt;">評分：</div>
                            <div class="sig-line"></div>
                        </div>
                    <?php else: ?>
                        <?php if (!empty($advisor)): ?>
                            <div style="margin-top: 6pt;"><strong><?= htmlspecialchars($advisor) ?> 老師</strong></div>
                        <?php else: ?>
                            <div class="question-line" style="margin-top: 6pt;"></div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php elseif ($is_textarea): ?>
                    <?php $is_large = !empty($q['textarea_display']) && $q['textarea_display'] === 'large'; ?>
                    <div class="writing-area <?= $is_large ? '' : 'normal' ?>">
                        <?php for ($r = 0; $r < $rows; $r++): ?>
                            <div class="question-line-multi"></div>
                        <?php endfor; ?>
                    </div>
                <?php elseif ($is_date): ?>
                    <?php if (!empty($answer)): ?>
                        <div style="border-bottom: 2pt solid #000; padding-bottom: 0; line-height: 1.2;"><strong><?= htmlspecialchars(formatDateMinguo($answer)) ?></strong></div>
                    <?php else: ?>
                        <div class="question-line"></div>
                    <?php endif; ?>
                <?php elseif ($is_table): ?>
                    <div class="writing-area normal" style="margin-top: 6pt;">
                        <?php for ($tr = 0; $tr < 12; $tr++): ?>
                            <div class="question-line-multi"></div>
                        <?php endfor; ?>
                    </div>
                <?php elseif (in_array($type, ['radio', 'checkbox']) && count($opts) > 0): ?>
                    <ul class="question-options">
                        <?php foreach ($opts as $o): ?>
                            <li><?= htmlspecialchars($o) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="question-line" style="margin-top: 6pt;"></div>
                <?php else: ?>
                    <?php if (!empty($answer)): ?>
                        <div style="border-bottom: 2pt solid #000; padding-bottom: 0; line-height: 1.2;"><strong><?= htmlspecialchars($answer) ?></strong></div>
                    <?php else: ?>
                        <div class="question-line"></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </body>

    </html>
    <?php
    exit;
}


