<?php
session_start();
require '../includes/pdo.php';
date_default_timezone_set("Asia/Taipei");

$role_ID = $_SESSION["role_ID"] ?? null;

$cohort_ID   = $_GET["cohort_ID"] ?? 0;
$tinforma_ID = isset($_GET["tinforma_ID"]) && $_GET["tinforma_ID"] !== '' ? (int)$_GET["tinforma_ID"] : null;
$title       = $_GET["title"] ?? '';

if (!$cohort_ID) {
    die("缺少參數：cohort_ID");
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

$teamUserField    = columnExists($conn, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
$userRoleUidField = columnExists($conn, 'userrolesdata', 'ur_u_ID') ? 'ur_u_ID' : 'u_ID';

/* =======================
   cohort name
======================= */
$stmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ?");
$stmt->execute([$cohort_ID]);
$cohort = $stmt->fetch(PDO::FETCH_ASSOC);
$cohort_name = $cohort['cohort_name'] ?? '未知屆別';

$scheduleInfo  = null;
$scheduleTitle = '專題期中審查報告時程表';

/* =======================
   找 tinforma_ID
======================= */
// 沒 tinforma_ID → 用 title 找
if (!$tinforma_ID && $title) {
    try {
        $checkStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_title'");
        $hasTitleField = $checkStmt->rowCount() > 0;

        if ($hasTitleField) {
            $stmt = $conn->prepare("
                SELECT tinforma_ID
                FROM timeinformadata
                WHERE tinforma_title = ?
                ORDER BY COALESCE(tinforma_update_d, tinforma_create_d) DESC
                LIMIT 1
            ");
            $stmt->execute([$title]);
        } else {
            $stmt = $conn->prepare("
                SELECT tinforma_ID
                FROM timeinformadata
                WHERE tinforma_content LIKE ? OR tinforma_content = ?
                ORDER BY tinforma_create_d DESC
                LIMIT 1
            ");
            $stmt->execute(["%" . $title . "%", $title]);
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) $tinforma_ID = $result['tinforma_ID'];
    } catch (Throwable $e) {}
}

// 還是沒有 tinforma_ID → 抓該屆最新
if (!$tinforma_ID) {
    $stmt = $conn->prepare("
        SELECT DISTINCT td.tinforma_ID
        FROM timedata td
        INNER JOIN teamdata t ON td.team_ID = t.team_ID
        WHERE t.cohort_ID = ?
        ORDER BY td.tinforma_ID DESC
        LIMIT 1
    ");
    $stmt->execute([$cohort_ID]);
    $tinformaResult = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tinformaResult && isset($tinformaResult['tinforma_ID'])) {
        $tinforma_ID = $tinformaResult['tinforma_ID'];
    }
}

/* =======================
   讀取時程表資訊
======================= */
if ($tinforma_ID) {
    try {
        $checkStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_title'");
        $hasTitleField = $checkStmt->rowCount() > 0;

        if ($hasTitleField) {
            $stmt = $conn->prepare("SELECT tinforma_ID, tinforma_content, tinforma_title FROM timeinformadata WHERE tinforma_ID = ?");
        } else {
            $stmt = $conn->prepare("SELECT tinforma_ID, tinforma_content FROM timeinformadata WHERE tinforma_ID = ?");
        }
    } catch (Throwable $e) {
        $stmt = $conn->prepare("SELECT tinforma_ID, tinforma_content FROM timeinformadata WHERE tinforma_ID = ?");
    }

    $stmt->execute([$tinforma_ID]);
    $scheduleInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($scheduleInfo && !empty($scheduleInfo['tinforma_title'])) {
        $scheduleTitle = $scheduleInfo['tinforma_title'];
    }
}

/* =======================
   解析特殊時間段（JSON）
======================= */
$specialTimes = [];
if ($scheduleInfo && !empty($scheduleInfo['tinforma_content'])) {
    $content = $scheduleInfo['tinforma_content'];

    if (substr(trim($content), 0, 1) === '[') {
        $jsonData = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {

            usort($jsonData, function($a, $b) {
                $sortA = $a['sortOrder'] ?? 9999;
                $sortB = $b['sortOrder'] ?? 9999;
                return $sortA - $sortB;
            });

            foreach ($jsonData as $item) {
                if (!isset($item['type'], $item['start'], $item['end'])) continue;
                if ($item['type'] === 'schedule_start') continue;

                $label = '';
                if ($item['type'] === 'preparation') $label = '場次預備';
                else if ($item['type'] === 'presentation_instruction') $label = '上台報告說明';
                else if ($item['type'] === 'lunch') $label = '午餐時間';
                else if ($item['type'] === 'break') $label = '休息時間';

                if ($label) {
                    $specialTimes[] = [
                        'label' => $label,
                        'start' => $item['start'],
                        'end' => $item['end'],
                        'sortOrder' => $item['sortOrder'] ?? null
                    ];
                }
            }
        }
    }
}

/* =======================
   取得時程資料
======================= */
$schedules = [];

if ($tinforma_ID) {
    $sql = "
        SELECT td.team_ID, td.time_start_d, td.time_end_d, td.sort_no, t.team_project_name
        FROM timedata td
        INNER JOIN teamdata t ON td.team_ID = t.team_ID
        WHERE t.cohort_ID = ?
          AND t.team_status = 1
          AND td.tinforma_ID = ?
        ORDER BY COALESCE(td.sort_no, 999999) ASC, td.time_start_d ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$cohort_ID, $tinforma_ID]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($schedules)) {
        $sql = "
            SELECT td.team_ID, td.time_start_d, td.time_end_d, td.sort_no, t.team_project_name
            FROM timedata td
            INNER JOIN teamdata t ON td.team_ID = t.team_ID
            WHERE t.cohort_ID = ?
              AND t.team_status = 1
            ORDER BY COALESCE(td.sort_no, 999999) ASC, td.time_start_d ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$cohort_ID]);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $sql = "
        SELECT td.team_ID, td.time_start_d, td.time_end_d, td.sort_no, t.team_project_name
        FROM timedata td
        INNER JOIN teamdata t ON td.team_ID = t.team_ID
        WHERE t.cohort_ID = ?
          AND t.team_status = 1
        ORDER BY COALESCE(td.sort_no, 999999) ASC, td.time_start_d ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$cohort_ID]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =======================
   scheduleMap + teams
======================= */
$scheduleMap = [];
$teams = [];
foreach ($schedules as $s) {
    $scheduleMap[$s['team_ID']] = $s;
    if (!isset($teams[$s['team_ID']])) {
        $teams[$s['team_ID']] = ['team_ID'=>$s['team_ID'], 'team_project_name'=>$s['team_project_name']];
    }
}
$teams = array_values($teams);

/* =======================
   teamData
======================= */
$teamData = [];
foreach ($teams as $team) {
    $team_ID = $team['team_ID'];

    // students (role 6)
    $sql = "SELECT 
                tm.{$teamUserField} AS u_ID,
                COALESCE(ud.u_name, tm.{$teamUserField}) AS u_name
            FROM teammember tm
            JOIN userrolesdata ur 
                  ON ur.{$userRoleUidField} = tm.{$teamUserField}
                 AND ur.role_ID = 6
                 AND ur.user_role_status = 1
            LEFT JOIN userdata ud ON ud.u_ID = tm.{$teamUserField}
            WHERE tm.team_ID = ?
              AND tm.tm_status = 1
            GROUP BY tm.{$teamUserField}, ud.u_name
            ORDER BY tm.{$teamUserField}";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$team_ID]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // teachers (role 4)
    $sql = "SELECT 
                tm.{$teamUserField} AS u_ID,
                COALESCE(ud.u_name, tm.{$teamUserField}) AS u_name
            FROM teammember tm
            JOIN userrolesdata ur 
                  ON ur.{$userRoleUidField} = tm.{$teamUserField}
                 AND ur.role_ID = 4
                 AND ur.user_role_status = 1
            LEFT JOIN userdata ud ON ud.u_ID = tm.{$teamUserField}
            WHERE tm.team_ID = ?
              AND tm.tm_status = 1
            GROUP BY tm.{$teamUserField}, ud.u_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$team_ID]);
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // time（三行格式：與 PDF 一致）
    $schedule  = $scheduleMap[$team_ID] ?? null;
    $timeRange = '-';
    $timeStart = null;
    $timeEnd   = null;

    if ($schedule && $schedule['time_start_d'] && $schedule['time_end_d']) {
        $start = new DateTime($schedule['time_start_d']);
        $end   = new DateTime($schedule['time_end_d']);
        $timeStart = $start->format('H:i');
        $timeEnd   = $end->format('H:i');

        // 三行格式：與 PDF 一致
        $timeRange = $timeStart . '<br>-<br>' . $timeEnd;
    }

    $sequence = $schedule['sort_no'] ?? null;
    if ($sequence === null) {
        $sequence = array_search($team_ID, array_column($teams, 'team_ID')) + 1;
    }

    $teacherNames = [];
    foreach ($teachers as $t) $teacherNames[] = $t['u_name'];
    $teacherName = !empty($teacherNames) ? implode('/', $teacherNames) : '未設定';

    $teamData[] = [
        'team_ID'       => $team_ID,
        'sequence'      => $sequence,
        'project_name'  => $team['team_project_name'] ?? '未設定',
        'students'      => $students,
        'teacher'       => $teacherName,
        'timeRange'     => $timeRange,
        'timeStart'     => $timeStart,
        'timeEnd'       => $timeEnd
    ];
}

/* =======================
   sort
======================= */
usort($teamData, function($a, $b) {
    if ($a['sequence'] == $b['sequence']) {
        if ($a['timeStart'] && $b['timeStart']) return strcmp($a['timeStart'], $b['timeStart']);
        return $a['team_ID'] - $b['team_ID'];
    }
    return $a['sequence'] - $b['sequence'];
});

/* =======================
   rows merge
======================= */
$specialRows = [];
foreach ($specialTimes as $idx => $sp) {
    $specialRows[] = [
        'type'=>'special',
        'data'=>$sp,
        'timeStart'=>$sp['start'],
        'sortOrder'=>$sp['sortOrder'] ?? $idx
    ];
}
usort($specialRows, fn($a,$b)=>$a['sortOrder']-$b['sortOrder']);

$teamRows = [];
foreach ($teamData as $t) {
    $teamRows[] = ['type'=>'team','data'=>$t,'timeStart'=>$t['timeStart'] ?? '99:99'];
}

$allRows = array_merge($specialRows, $teamRows);
usort($allRows, fn($a,$b)=>strcmp($a['timeStart'], $b['timeStart']));

/* =======================
   Word download headers
======================= */
$fileName = '專題期中審查報告時程表_' . $cohort_name . '_' . date('Ymd') . '.doc';
header('Content-Type: application/msword; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');

?>
<html xmlns:o='urn:schemas-microsoft-com:office:office'
      xmlns:w='urn:schemas-microsoft-com:office:word'
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
@page{
  size: A4;
  margin: 10mm; /* ✅ 跟你的 PDF 一樣是 10mm */
}

body{
  font-family: "DFKai-SB","BiauKai","標楷體","Microsoft JhengHei",serif;
  font-size: 14pt;
  margin: 0;
  padding: 0;
  color:#000;
}

.title{
  text-align:center;
  font-weight:900;
  font-size:22pt;
  letter-spacing:0.06em;
  margin: 6pt 0 10pt;
}

/* ✅ 外框粗線（像你的圖） */
.wrapper{
  border: 2pt solid #000;
  padding: 0;
}

/* ✅ 表格 */
table{
  width:100%;
  border-collapse:collapse;
  table-layout:fixed;
  font-size:14pt;
}

th, td{
  border:1pt solid #000;
  padding:4pt 3pt;
  text-align:center;
  vertical-align:middle;
  word-wrap:break-word;
  white-space:normal;
}

/* ✅ 表頭灰底 + 18pt */
thead th{
  background:#bfbfbf;
  mso-shading:#bfbfbf;
  font-weight:900;
  font-size:18pt;
  padding:8pt 4pt;
  letter-spacing:0.05em;
}

/* ✅ 欄寬比例（與 PDF 一致） */
.col-time{ width: 12%; }
.col-seq{ width: 6%; }
.col-id{ width: 14%; }
.col-name{ width: 12%; }
.col-project{ width: 24%; }
.col-teacher{ width: 32%; }

/* ✅ 時間欄：三行格式置中 */
.time-cell{
  line-height: 1.3;
  vertical-align: middle;
  text-align: center;
}

/* ✅ 特殊列淡黃（整列） */
.special-time-row td{
  background:#fffacd;
  mso-shading:#fffacd;
  font-weight:700;
  text-align:center;
  vertical-align:middle;
}

/* ✅ 專題題目欄：行距緊一點（與 PDF 一致） */
.project-cell,
td.col-project,
td:nth-child(5) {
  font-size: 14pt;
  line-height: 1.2;
  padding: 4pt 4pt;
  white-space: normal;
  word-break: break-word;
  text-align: center;
}

/* ✅ 沒資料 */
.no-data{
  color:#666;
  font-style:italic;
}
</style>
</head>

<body>
  <div class="title"><?php echo htmlspecialchars($scheduleTitle); ?></div>

  <div class="wrapper">
    <table>
      <thead>
        <tr>
          <th class="col-time">報告時間</th>
          <th class="col-seq">組次</th>
          <th class="col-id">學號</th>
          <th class="col-name">姓名</th>
          <th class="col-project">專題題目</th>
          <th class="col-teacher">指導老師</th>
        </tr>
      </thead>

      <tbody>
      <?php if (empty($allRows)): ?>
        <tr><td colspan="6" class="no-data">目前沒有資料</td></tr>
      <?php else: ?>
        <?php foreach ($allRows as $row): ?>

          <?php if ($row['type'] === 'special'): ?>
            <?php
              $sp = $row['data'];
              $spTime = htmlspecialchars($sp['start'].'-'.$sp['end']);
            ?>
            <tr class="special-time-row">
              <td class="time-cell"><?php echo $spTime; ?></td>
              <td colspan="5"><?php echo htmlspecialchars($sp['label']); ?></td>
            </tr>

          <?php else: ?>
            <?php
              $team = $row['data'];
              $students = $team['students'];
              $studentCount = count($students);
              $rowspan = max(1, $studentCount);
            ?>

            <?php if ($studentCount > 0): ?>
              <?php foreach ($students as $i => $stu): ?>
                <tr>
                  <?php if ($i === 0): ?>
                    <td class="time-cell" rowspan="<?php echo $rowspan; ?>"><?php echo $team['timeRange']; ?></td>
                    <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($team['sequence']); ?></td>
                    <td><?php echo htmlspecialchars($stu['u_ID']); ?></td>
                    <td><?php echo htmlspecialchars($stu['u_name']); ?></td>
                    <td class="project-cell" rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($team['project_name']); ?></td>
                    <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($team['teacher']); ?></td>
                  <?php else: ?>
                    <td><?php echo htmlspecialchars($stu['u_ID']); ?></td>
                    <td><?php echo htmlspecialchars($stu['u_name']); ?></td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td class="time-cell"><?php echo $team['timeRange']; ?></td>
                <td><?php echo htmlspecialchars($team['sequence']); ?></td>
                <td><span class="no-data">-</span></td>
                <td><span class="no-data">-</span></td>
                <td class="project-cell"><?php echo htmlspecialchars($team['project_name']); ?></td>
                <td><?php echo htmlspecialchars($team['teacher']); ?></td>
              </tr>
            <?php endif; ?>

          <?php endif; ?>

        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
