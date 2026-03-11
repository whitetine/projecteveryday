<?php
session_start();
require '../includes/pdo.php';
date_default_timezone_set("Asia/Taipei");

$role_ID = $_SESSION["role_ID"] ?? null;
$download = isset($_GET["download"]) && $_GET["download"] == "1";

$cohort_ID = $_GET["cohort_ID"] ?? 0;
$tinforma_ID = isset($_GET["tinforma_ID"]) && $_GET["tinforma_ID"] !== '' ? (int)$_GET["tinforma_ID"] : null;
$title = $_GET["title"] ?? '';

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

$teamUserField = columnExists($conn, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
$userRoleUidField = columnExists($conn, 'userrolesdata', 'ur_u_ID') ? 'ur_u_ID' : 'u_ID';

$stmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ?");
$stmt->execute([$cohort_ID]);
$cohort = $stmt->fetch(PDO::FETCH_ASSOC);
$cohort_name = $cohort['cohort_name'] ?? '未知屆別';

$scheduleInfo = null;
$scheduleTitle = '專題期中審查報告時程表';

// 沒 tinforma_ID → 用 title 找
if (!$tinforma_ID && $title) {
    try {
        $checkStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_title'");
        $hasTitleField = $checkStmt->rowCount() > 0;

        if ($hasTitleField) {
            $stmt = $conn->prepare("SELECT tinforma_ID FROM timeinformadata WHERE tinforma_title = ? ORDER BY COALESCE(tinforma_update_d, tinforma_create_d) DESC LIMIT 1");
            $stmt->execute([$title]);
        } else {
            $stmt = $conn->prepare("SELECT tinforma_ID FROM timeinformadata WHERE tinforma_content LIKE ? OR tinforma_content = ? ORDER BY tinforma_create_d DESC LIMIT 1");
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

// 讀取時程表資訊
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

    if ($scheduleInfo && isset($scheduleInfo['tinforma_title']) && !empty($scheduleInfo['tinforma_title'])) {
        $scheduleTitle = $scheduleInfo['tinforma_title'];
    }
}

// 解析特殊時間段
$specialTimes = [];
if ($scheduleInfo && !empty($scheduleInfo['tinforma_content'])) {
    $content = $scheduleInfo['tinforma_content'];

    // JSON
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

// 取得時程資料
$schedules = [];
$usedFallback = false;

if ($tinforma_ID) {
    $sql = "
        SELECT td.team_ID, td.time_start_d, td.time_end_d, td.sort_no, t.team_project_name, t.group_ID
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
        $usedFallback = true;
        $sql = "
            SELECT td.team_ID, td.time_start_d, td.time_end_d, td.sort_no, t.team_project_name, t.group_ID
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
        SELECT td.team_ID, td.time_start_d, td.time_end_d, td.sort_no, t.team_project_name, t.group_ID
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

// scheduleMap + teams
$scheduleMap = [];
$teams = [];
foreach ($schedules as $s) {
    $scheduleMap[$s['team_ID']] = $s;
    if (!isset($teams[$s['team_ID']])) {
        $teams[$s['team_ID']] = ['team_ID'=>$s['team_ID'], 'team_project_name'=>$s['team_project_name'], 'group_ID'=>$s['group_ID'] ?? null];
    }
}
$teams = array_values($teams);

// teamData
$teamData = [];
foreach ($teams as $team) {
    $team_ID = $team['team_ID'];

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

    $schedule = $scheduleMap[$team_ID] ?? null;
    $timeRange = '-';
    $timeStart = null;
    $timeEnd = null;

    if ($schedule && $schedule['time_start_d'] && $schedule['time_end_d']) {
        $start = new DateTime($schedule['time_start_d']);
        $end = new DateTime($schedule['time_end_d']);
        $timeStart = $start->format('H:i');
        $timeEnd = $end->format('H:i');
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
        'team_ID'=>$team_ID,
        'sequence'=>$sequence,
        'project_name'=>$team['team_project_name'] ?? '未設定',
        'students'=>$students,
        'teacher'=>$teacherName,
        'timeRange'=>$timeRange,
        'timeStart'=>$timeStart,
        'timeEnd'=>$timeEnd,
        'group_ID'=>$team['group_ID'] ?? null
    ];
}

// sort - 先按類組排序（商務網站經營組 group_ID=2 在前，系統軟體開發組 group_ID=1 在後），再按原排序邏輯
usort($teamData, function($a, $b) {
    // 先按類組排序：group_ID=2（商務網站經營組）在前，group_ID=1（系統軟體開發組/資訊組）在後
    $groupA = $a['group_ID'] ?? 999;
    $groupB = $b['group_ID'] ?? 999;
    
    // 自定義排序：2（商務網站經營組）優先於 1（系統軟體開發組/資訊組），其他按數字順序
    $groupOrderA = ($groupA == 2) ? 0 : (($groupA == 1) ? 1 : $groupA + 10);
    $groupOrderB = ($groupB == 2) ? 0 : (($groupB == 1) ? 1 : $groupB + 10);
    
    if ($groupOrderA != $groupOrderB) {
        return $groupOrderA - $groupOrderB;
    }
    
    // 類組相同時，按原排序邏輯
    if ($a['sequence'] == $b['sequence']) {
        if ($a['timeStart'] && $b['timeStart']) return strcmp($a['timeStart'], $b['timeStart']);
        return $a['team_ID'] - $b['team_ID'];
    }
    return $a['sequence'] - $b['sequence'];
});

// rows
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

// merge (簡化：先特殊再時間)
$allRows = array_merge($specialRows, $teamRows);
usort($allRows, function($a, $b){
    return strcmp($a['timeStart'], $b['timeStart']);
});
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($scheduleTitle); ?> - <?php echo htmlspecialchars($cohort_name); ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <?php if ($download): ?>
  <style>
    body {
      visibility: hidden;
      position: absolute;
      left: -9999px;
      background: #fff;
    }
  </style>
  <?php endif; ?>

  <style>
    :root { --kai: "DFKai-SB","BiauKai","標楷體","Microsoft JhengHei",serif; }

    body{
  font-family: var(--kai);
  margin: 0;
  padding: 0;
  background: #e5e7eb; /* 灰底，像 PDF 頁面預覽 */
  color:#111;
}

    .download-controls{
      position: sticky;
      top: 10px;
      display:flex;
      justify-content:center;
      margin-bottom: 12px;
      z-index: 10;
    }
    .download-btn{
      padding:10px 20px;
      background:#0d6efd;
      color:#fff;
      border:0;
      border-radius:10px;
      font-size:16px;
      font-weight:700;
      cursor:pointer;
      box-shadow:0 10px 24px rgba(0,0,0,.12);
    }
    .download-btn:disabled{ opacity:.7; cursor:not-allowed; }

    #report{
  width: 210mm;          /* ✅ 直接用 A4 寬度 */
  margin: 16px auto;     /* 置中 */
  padding: 10mm;         /* ✅ 頁面邊界（像圖1） */
  background: #fff;      /* 白紙 */
  box-sizing: border-box;
  box-shadow: 0 10px 30px rgba(0,0,0,.15); /* 讓它更像 PDF 頁 */
}

    .title{
      text-align:center;
      font-weight:900;
      font-size: 22px;
      letter-spacing:.06em;
      margin: 6px 0 10px;
    }

    .table-wrapper{
  border:2px solid #000;
  width: 100%;         /* ✅ 填滿整張 A4 白紙內容區 */
  background:#fff;
}


    table{
      width:100%;
      border-collapse:collapse;
      table-layout:fixed;
      font-size: 14px;
    }

    th, td{
      border:1px solid #000;
      padding:4px 3px;
      text-align:center;
      vertical-align:middle;
    }

  /* ===== 表頭字體放大（像範例圖），表頭文字完整顯示 ===== */
thead th{
  background: #bfbfbf;
  color:#000;
  font-weight: 900;
  font-size: 18px;     /* ✅ 表頭字體變大 */
  padding: 8px 4px;    /* ✅ 表頭高度一起撐起來，比較穩重 */
  letter-spacing: 0.05em; /* 微間距，公文感 */
  white-space: normal;   /* 表頭不截斷，可換行完整顯示 */
  overflow: visible;
  word-wrap: break-word;
}


/* ===== 表格欄位比例（對齊你提供的圖片樣式） ===== */
.col-time{width:12%; min-width:4em}      /* 報告時間 */
.col-seq{width:6%; min-width:2.5em}     /* 組次 */
.col-id{width:14%; min-width:4em}       /* 學號 */
.col-name{width:12%; min-width:4em}     /* 姓名 */
.col-project{width:24%; min-width:5em}   /* ✅ 專題題目（縮小） */
.col-teacher{width:32%; min-width:5em}  /* ✅ 指導老師（變寬，像圖） */


    .time-cell{ line-height:1.3; }

    .special-time-row{ background:#fffacd !important; font-weight:700; }
    .special-time-row td{ background:#fffacd !important; }

    .no-data{ color:#666; font-style:italic; }

    /* ===== 專題題目欄：字較小、行距緊、自動換行（像圖片） ===== */
td.col-project,
td:nth-child(5) {
  font-size: 14px;        /* 比其他欄小一點 */
  line-height: 1.2;       /* 行距緊湊 */
  padding: 4px 4px;
  white-space: normal;    /* 允許換行 */
  word-break: break-word;
  text-align: center;
}

    @media print { .download-controls{ display:none; } }
  </style>
</head>

<body>
<?php if (!$download): ?>
  <div class="download-controls">
    <button class="download-btn" onclick="downloadPDF()">
      <i class="fa-solid fa-download"></i> 下載 PDF
    </button>
  </div>
<?php endif; ?>

<div id="report">
  <div class="title"><?php echo htmlspecialchars($scheduleTitle); ?></div>

  <div class="table-wrapper">
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
            <?php $sp = $row['data']; $spTime = $sp['start'].'-'.$sp['end']; ?>
            <tr class="special-time-row">
              <td class="time-cell"><?php echo htmlspecialchars($spTime); ?></td>
              <td colspan="5"><?php echo htmlspecialchars($sp['label']); ?></td>
            </tr>
          <?php else: ?>
            <?php
              $team = $row['data'];
              $students = $team['students'];
              $studentCount = count($students);
              $rowspan = max(1, $studentCount);
            ?>
            <?php foreach ($students as $i => $stu): ?>
              <tr>
                <?php if ($i === 0): ?>
                  <td class="time-cell" rowspan="<?php echo $rowspan; ?>"><?php echo $team['timeRange']; ?></td>
                  <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($team['sequence']); ?></td>
                  <td><?php echo htmlspecialchars($stu['u_ID']); ?></td>
                  <td><?php echo htmlspecialchars($stu['u_name']); ?></td>
                  <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($team['project_name']); ?></td>
                  <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($team['teacher']); ?></td>
                <?php else: ?>
                  <td><?php echo htmlspecialchars($stu['u_ID']); ?></td>
                  <td><?php echo htmlspecialchars($stu['u_name']); ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>

            <?php if ($studentCount === 0): ?>
              <tr>
                <td class="time-cell"><?php echo $team['timeRange']; ?></td>
                <td><?php echo htmlspecialchars($team['sequence']); ?></td>
                <td><span class="no-data">-</span></td>
                <td><span class="no-data">-</span></td>
                <td><?php echo htmlspecialchars($team['project_name']); ?></td>
                <td><?php echo htmlspecialchars($team['teacher']); ?></td>
              </tr>
            <?php endif; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
window.onload = function() {
  const element = document.getElementById('report');
  const cohortName = '<?php echo htmlspecialchars($cohort_name, ENT_QUOTES); ?>';
  const fileName = '專題期中審查報告時程表_' + cohortName + '_' + new Date().toISOString().slice(0,10) + '.pdf';

  function hasData(){
    const tbody = element.querySelector('tbody');
    if (!tbody) return true;
    const rows = tbody.querySelectorAll('tr');
    if (rows.length === 0) return false;
    if (rows.length === 1 && tbody.querySelector('.no-data')) return false;
    return true;
  }

  // ✅ 核心：把整個 report 畫成 canvas → 依比例塞進 A4 一頁（不裁切）
  async function exportOnePagePDF(){
    if (!hasData()){
      alert('沒有找到時程表資料，請確認參數是否正確。');
      return;
    }

    // 先用 html2pdf 把 html2canvas 的 canvas 拿出來
    const opt = {
      margin: 0,
      filename: fileName,
      image: { type: 'jpeg', quality: 0.98 },
      html2canvas: { scale: 2, useCORS: true, letterRendering: true, logging: false, scrollY: 0 },
      jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait', compress: true }
    };

    const worker = html2pdf().set(opt).from(element).toPdf();

    const pdf = await worker.get('pdf');
    const canvas = await worker.get('canvas');

    // A4 尺寸(mm)
    const pageW = 210;
    const pageH = 297;
    const margin = 10; // ✅ 你圖1那種外框留白
    const usableW = pageW - margin * 2;
    const usableH = pageH - margin * 2;

    // canvas 像素轉成 mm 的比例：用寬度當基準算縮放
    const imgW = usableW;
    const imgH = canvas.height * (imgW / canvas.width);

    // 若高度超過一頁，就改用高度當基準縮小（確保一頁完整）
    let finalW = imgW;
    let finalH = imgH;
    if (imgH > usableH) {
      finalH = usableH;
      finalW = canvas.width * (finalH / canvas.height);
    }

    // 轉 base64
    const imgData = canvas.toDataURL('image/jpeg', 0.98);

    // 清掉原本 worker 可能產生的頁面，重畫一次
    while (pdf.internal.getNumberOfPages() > 0) pdf.deletePage(1);
    pdf.addPage('a4', 'portrait');

    // 置中
    const x = (pageW - finalW) / 2;
    const y = margin;

    pdf.addImage(imgData, 'JPEG', x, y, finalW, finalH, undefined, 'FAST');

    pdf.save(fileName);
  }

  <?php if ($download): ?>
    setTimeout(async function(){
      // 下載模式解除隱藏
      document.body.style.visibility = 'visible';
      document.body.style.position = 'relative';
      document.body.style.left = '0';
      window.scrollTo(0,0);

      try{
        await exportOnePagePDF();
        setTimeout(()=>{ try{window.close();}catch(e){ document.body.style.display='none'; } }, 800);
      }catch(e){
        console.error(e);
        alert('PDF 生成失敗，請用 Ctrl+P 列印');
      }
    }, 600);
  <?php else: ?>
    window.downloadPDF = async function(){
      const btn = document.querySelector('.download-btn');
      if (btn){ btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 正在生成 PDF...'; }
      try{
        await exportOnePagePDF();
      }catch(e){
        console.error(e);
        alert('PDF 生成失敗，請用 Ctrl+P 列印');
      }finally{
        if (btn){ btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-download"></i> 下載 PDF'; }
      }
    }
  <?php endif; ?>
}
</script>
</body>
</html>
