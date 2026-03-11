<?php
session_start();
require "../includes/pdo.php";
date_default_timezone_set("Asia/Taipei");

/* ==========================================
   匯出歷次建議：該屆所有團隊，每團隊一頁，每頁為該團隊在該屆的所有建議
   權限：已登入即可（與 suggest_export 一致）
========================================= */
$role_ID = $_SESSION["role_ID"] ?? null;
if (!isset($role_ID)) {
    die("請先登入");
}

$cohort_ID = (int)($_GET["cohort_ID"] ?? 0);
if (!$cohort_ID) {
    die("缺少參數：請選擇屆別");
}

function columnExists(PDO $conn, string $table, string $column): bool {
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$teamUserField = columnExists($conn, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
$userRoleUidField = columnExists($conn, 'userrolesdata', 'ur_u_ID') ? 'ur_u_ID' : 'u_ID';

// 屆別名稱
$stmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ?");
$stmt->execute([$cohort_ID]);
$cohort = $stmt->fetch(PDO::FETCH_ASSOC);
$cohort_name = $cohort['cohort_name'] ?? (string)$cohort_ID;

// 該屆所有團隊
$stmt = $conn->prepare("
    SELECT t.team_ID, t.team_project_name
    FROM teamdata t
    WHERE t.cohort_ID = ? AND t.team_status = 1
    ORDER BY t.group_ID, t.team_ID
");
$stmt->execute([$cohort_ID]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

$teamPages = [];
foreach ($teams as $team) {
    $team_ID = (int)$team['team_ID'];
    $project_name = $team['team_project_name'] ?? '';

    // 團隊成員（學生）
    $sql = "SELECT 
                tm.{$teamUserField} AS u_ID,
                COALESCE(ud.u_name, tm.{$teamUserField}) AS u_name
            FROM teammember tm
            JOIN userrolesdata ur 
                  ON ur.{$userRoleUidField} = tm.{$teamUserField}
                 AND ur.role_ID = 6
                 AND ur.user_role_status = 1
            LEFT JOIN userdata ud ON ud.u_ID = tm.{$teamUserField}
            WHERE tm.team_ID = ? AND tm.tm_status = 1
            GROUP BY tm.{$teamUserField}, ud.u_name
            ORDER BY tm.{$teamUserField}";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$team_ID]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 該團隊在該屆的「審查建議表」建議（只印 review，不印 topic 初審建議表）
    $sql = "SELECT s.suggest_ID, s.suggest_comment, s.suggest_status, sf.sf_name, sf.sf_type
            FROM suggest s
            LEFT JOIN suggestfrom sf ON s.sf_ID = sf.sf_ID
            WHERE s.team_ID = ? AND s.suggest_status IN (1, 2, 3, 4)
              AND (sf.sf_type = 'review' OR sf.sf_type IS NULL)
            ORDER BY COALESCE(sf.sf_name,''), s.suggest_d DESC, s.suggest_ID DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$team_ID]);
    $suggests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $suggestRows = [];
    foreach ($suggests as $row) {
        $status_code = (int)($row['suggest_status'] ?? 0);
        $is_topic = (isset($row['sf_type']) && $row['sf_type'] === 'topic');
        $status_text = '—';
        if ($status_code == 1) $status_text = $is_topic ? '修改' : '修改後通過';
        elseif ($status_code == 2) $status_text = '不通過';
        elseif ($status_code == 3) $status_text = '通過';
        elseif ($status_code == 4) $status_text = $is_topic ? '待確認' : '修改後複評';

        $suggestRows[] = [
            'title' => $row['sf_name'] ?? '（未命名）',
            'status' => $status_text,
            'status_code' => $status_code,
            'comment' => $row['suggest_comment'] ?? '',
        ];
    }

    $teamPages[] = [
        'project_name' => $project_name,
        'members' => $members,
        'suggestions' => $suggestRows,
    ];
}

$page_title = '歷次建議_' . $cohort_name;
$is_word_export = (isset($_GET['format']) && $_GET['format'] === 'word');

if ($is_word_export) {
    $fileName = '歷次建議_' . $cohort_name . '_' . date('Ymd') . '.doc';
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
}
?>
<?php if ($is_word_export): ?>
<html xmlns:o='urn:schemas-microsoft-com:office:office'
      xmlns:w='urn:schemas-microsoft-com:office:word'
      xmlns='http://www.w3.org/TR/REC-html40'>
<head>
<meta charset="UTF-8">
<meta name="ProgId" content="Word.Document">
<title><?php echo htmlspecialchars($page_title); ?></title>
<!--[if gte mso 9]><xml>
<w:WordDocument>
  <w:View>Print</w:View>
  <w:Zoom>100</w:Zoom>
</w:WordDocument>
</xml><![endif]-->
<style>
@page { size: A4 landscape; margin: 1.5cm; }
body { font-family: "DFKai-SB","BiauKai","標楷體","Microsoft JhengHei",serif; color: #111; margin: 0; padding: 15px; font-size: 14pt; }
.doc-title { text-align: center; font-weight: 700; font-size: 22pt; margin-bottom: 20px; }
.team-page { margin-bottom: 0; padding: 15px 0; }
.team-header { margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2pt solid #333; }
.team-project-name { font-size: 20pt; font-weight: 700; margin-bottom: 6px; }
.team-members { font-size: 15pt; color: #444; }
.suggestions-table { width: 100%; border-collapse: collapse; font-size: 15pt; margin-top: 10px; }
.suggestions-table th, .suggestions-table td { border: 1pt solid #000; padding: 8px 10px; text-align: left; vertical-align: top; }
.suggestions-table th { background: #e0e8f0; font-weight: 700; text-align: center; }
.suggestions-table .col-title { width: 28%; }
.suggestions-table .col-comment { width: 72%; line-height: 1.5; }
.no-suggest { color: #666; font-style: italic; }
.no-suggestions-msg { color: #888; font-style: italic; padding: 15px; }
.teacher-comment-block { margin-top: 18px; }
.teacher-comment-block .label { font-size: 15pt; font-weight: 700; margin-bottom: 6px; display: block; }
.teacher-comment-block .blank-area { min-height: 80px; border: 1pt solid #000; padding: 10px; background: #fafafa; }
.score-block { margin-top: 14px; font-size: 16pt; font-weight: 700; }
.score-block .score-line { display: inline-block; min-width: 120px; border-bottom: 2pt solid #000; padding: 4px 8px 2px; vertical-align: middle; }
.page-break-before { page-break-before: always; mso-page-break-before: always; }
</style>
</head>
<body>
<div class="doc-title"><?php echo htmlspecialchars($cohort_name); ?> 歷次建議匯出</div>
<?php 
$first = true;
foreach ($teamPages as $page): 
    if (!$first) {
        echo '<p class="page-break-before" style="page-break-before: always; mso-page-break-before: always; margin: 0; padding: 0;">&nbsp;</p>';
    }
    $first = false;
?>
<div class="team-page" style="page-break-after: always; page-break-inside: avoid;">
    <div class="team-header">
        <div class="team-project-name"><?php echo htmlspecialchars($page['project_name'] ?: '（未填專題名稱）'); ?></div>
        <div class="team-members">組員：<?php
            $names = array_map(function($m) {
                return htmlspecialchars(($m['u_ID'] ?? '') . ' ' . ($m['u_name'] ?? ''));
            }, $page['members']);
            echo implode('、', $names);
            if (empty($names)) echo '—';
        ?></div>
    </div>
    <?php if (!empty($page['suggestions'])): ?>
    <table class="suggestions-table">
        <thead><tr><th class="col-title">標題</th><th class="col-comment">審查意見</th></tr></thead>
        <tbody>
        <?php foreach ($page['suggestions'] as $s): ?>
        <tr>
            <td><?php echo htmlspecialchars($s['title']); ?></td>
            <td class="col-comment"><?php
                if (trim($s['comment']) !== '') {
                    $txt = preg_replace('/(\d+)\.\s*/', '$1. ', $s['comment']);
                    echo nl2br(htmlspecialchars($txt));
                } else {
                    echo '<span class="no-suggest">（無審查意見）</span>';
                }
            ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="no-suggestions-msg">本團隊尚無任何建議紀錄。</div>
    <?php endif; ?>
    <div class="teacher-comment-block">
        <span class="label">老師建議（書寫區）：</span>
        <div class="blank-area"></div>
    </div>
    <div class="score-block">
        <span class="label">評分：</span>
        <span class="score-line">&nbsp;</span>
    </div>
</div>
<?php endforeach; ?>
<?php if (empty($teamPages)): ?>
<p style="color:#888;">該屆別尚無團隊資料。</p>
<?php endif; ?>
</body>
</html>
<?php exit; endif; ?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <style>
    :root { --kai: "DFKai-SB", "BiauKai", "標楷體", "Microsoft JhengHei", serif; }

    @media print {
        @page { size: A4 landscape; margin: 1.5cm; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        /* 一頁只顯示一個團隊：每個團隊區塊後強制換頁 */
        .team-page {
            page-break-after: always;
            break-after: page;
            page-break-inside: avoid;
        }
        .team-page:last-child {
            page-break-after: auto;
            break-after: auto;
        }
    }

    body {
        font-family: var(--kai);
        color: #111;
        margin: 0;
        padding: 15px;
        background: #fff;
    }

    .doc-title {
        text-align: center;
        font-weight: 700;
        font-size: 22px;
        margin-bottom: 20px;
    }

    .team-page {
        margin-bottom: 30px;
        padding: 15px 0;
        border-bottom: 1px dashed #ccc;
    }

    .team-header {
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 2px solid #333;
    }

    .team-project-name {
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 6px;
    }

    .team-members {
        font-size: 15px;
        color: #444;
    }

    .suggestions-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 15px;
        margin-top: 10px;
    }

    .suggestions-table th,
    .suggestions-table td {
        border: 1px solid #000;
        padding: 8px 10px;
        text-align: left;
        vertical-align: top;
    }

    .suggestions-table th {
        background: #e0e8f0;
        font-weight: 700;
        text-align: center;
    }

    .suggestions-table .col-title { width: 28%; }
    .suggestions-table .col-comment { width: 72%; line-height: 1.5; }

    .no-suggest { color: #666; font-style: italic; }
    .no-suggestions-msg { color: #888; font-style: italic; padding: 15px; }

    /* 老師建議書寫區（印出後手寫） */
    .teacher-comment-block { margin-top: 18px; }
    .teacher-comment-block .label {
        font-size: 15px; font-weight: 700; margin-bottom: 6px; display: block;
    }
    .teacher-comment-block .blank-area {
        min-height: 80px;
        border: 1px solid #000;
        padding: 10px;
        background: #fafafa;
    }

    /* 評分框 */
    .score-block {
        margin-top: 14px;
        font-size: 16px;
        font-weight: 700;
    }
    .score-block .label { margin-right: 8px; }
    .score-block .score-line {
        display: inline-block;
        min-width: 120px;
        border-bottom: 2px solid #000;
        padding: 4px 8px 2px;
        vertical-align: middle;
    }
    </style>
</head>
<body>
    <div class="doc-title"><?php echo htmlspecialchars($cohort_name); ?> 歷次建議匯出</div>

    <?php foreach ($teamPages as $page): ?>
    <div class="team-page">
        <div class="team-header">
            <div class="team-project-name"><?php echo htmlspecialchars($page['project_name'] ?: '（未填專題名稱）'); ?></div>
            <div class="team-members">
                組員：<?php
                $names = array_map(function($m) {
                    return htmlspecialchars(($m['u_ID'] ?? '') . ' ' . ($m['u_name'] ?? ''));
                }, $page['members']);
                echo implode('、', $names);
                if (empty($names)) echo '—';
                ?>
            </div>
        </div>

        <?php if (!empty($page['suggestions'])): ?>
        <table class="suggestions-table">
            <thead>
                <tr>
                    <th class="col-title">標題</th>
                    <th class="col-comment">審查意見</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($page['suggestions'] as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['title']); ?></td>
                    <td class="col-comment"><?php
                        if (trim($s['comment']) !== '') {
                            $txt = preg_replace('/(\d+)\.\s*/', '$1. ', $s['comment']);
                            echo nl2br(htmlspecialchars($txt));
                        } else {
                            echo '<span class="no-suggest">（無審查意見）</span>';
                        }
                    ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="no-suggestions-msg">本團隊尚無任何建議紀錄。</div>
        <?php endif; ?>

        <div class="teacher-comment-block">
            <span class="label">老師建議（書寫區）：</span>
            <div class="blank-area"></div>
        </div>
        <div class="score-block">
            <span class="label">評分：</span>
            <span class="score-line">&nbsp;</span>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($teamPages)): ?>
    <p style="color:#888;">該屆別尚無團隊資料。</p>
    <?php endif; ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
    window.onload = function() {
        var fileName = '歷次建議_<?php echo htmlspecialchars($cohort_name, ENT_QUOTES); ?>_' + new Date().toISOString().slice(0, 10) + '.pdf';
        var opt = {
            margin: [10, 10, 10, 10],
            filename: fileName,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true, letterRendering: true, logging: false },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
        };
        html2pdf().set(opt).from(document.body).save().then(function() {}).catch(function(err) {
            console.error(err);
            alert('PDF 生成失敗，請使用瀏覽器列印（Ctrl+P）另存為 PDF');
        });
    };
    </script>
</body>
</html>
